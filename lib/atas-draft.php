<?php
declare(strict_types=1);

/*
 * Nástřel náhledu z ATAS: indikátor „Trading Desk: nástřel náhledu“ v ATAS spočítá z grafu
 * profil posledního dne nebo týdne (High, Low, Close, POC, VAH, VAL, VWAP, IB, single
 * prints, tails, poor high/low, naked POC, LVN, overnight) a pošle ho přes stejný tajný
 * odkaz jako zóny (atas.php?t=TOKEN, metoda POST). Uloží se jako surová data do sdílené
 * app.sqlite3 (stejně jako snímek zón, nic z deníku v něm není). Do náhledu se převede až
 * v aplikaci, kde ho člen zkontroluje a uloží sám (atas_draft_plan). Stejný soubor jde
 * nahrát i ručně, když ATAS nemá spojení na server.
 */

const ATAS_DRAFT_FORMAT = 'td-atas-draft';
const ATAS_DRAFT_VERSION = 1;
const ATAS_DRAFT_MAX_BYTES = 131072;
const ATAS_DRAFT_KEEP = 10;
const ATAS_DRAFT_PER_HOUR = 60;
const ATAS_DRAFT_LIST_MAX = 20;
const ATAS_DRAFT_KINDS = ['daily', 'weekly'];

// Tabulka atas_drafts je ve schématu app.sqlite3 (initialize_app_schema v lib/accounts.php).

/* ---------------------------------------------------------------- čištění dat z ATAS */

function atas_draft_num(mixed $value): ?float
{
    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) && abs($number) < 1e8 ? $number : null;
}

function atas_draft_date(mixed $value): ?string
{
    $text = is_string($value) ? trim($value) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    return $text;
}

/** Pásmo cen {low, high}; prohozené meze srovná, nesmyslné zahodí. */
function atas_draft_band(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    $low = atas_draft_num($value['low'] ?? null);
    $high = atas_draft_num($value['high'] ?? null);
    if ($low === null || $high === null) {
        return null;
    }
    return $low <= $high ? ['low' => $low, 'high' => $high] : ['low' => $high, 'high' => $low];
}

/** Seznam pásem nebo cen s volitelným datem (naked POC). */
function atas_draft_list_clean(mixed $value, string $shape, int $max): array
{
    $out = [];
    foreach (is_array($value) ? array_slice(array_values($value), 0, $max) : [] as $item) {
        if ($shape === 'band') {
            $band = atas_draft_band($item);
            if ($band !== null) {
                $out[] = $band;
            }
            continue;
        }
        $price = atas_draft_num(is_array($item) ? ($item['price'] ?? null) : $item);
        if ($price === null) {
            continue;
        }
        $row = ['price' => $price];
        $date = is_array($item) ? atas_draft_date($item['date'] ?? null) : null;
        if ($date !== null) {
            $row['date'] = $date;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Data z indikátoru projdou whitelistem: jen známá pole, čísla konečná, data jako
 * YYYY-MM-DD, seznamy omezené. Bez High, Low a Close (nebo bez data) nástřel nedává smysl.
 */
function atas_draft_clean(mixed $raw): array
{
    if (!is_array($raw) || ($raw['format'] ?? '') !== ATAS_DRAFT_FORMAT) {
        throw new InvalidArgumentException('Tohle nejsou data nástřelu z ATAS (chybí format "td-atas-draft").');
    }
    if ((int)($raw['version'] ?? 0) !== ATAS_DRAFT_VERSION) {
        throw new InvalidArgumentException('Nástřel je z jiné verze indikátoru. Stáhni si v Trading Desku aktuální indikátor.');
    }
    $kind = in_array($raw['kind'] ?? '', ATAS_DRAFT_KINDS, true) ? (string)$raw['kind'] : '';
    $period = is_array($raw['period'] ?? null) ? $raw['period'] : [];
    $from = atas_draft_date($period['from'] ?? null);
    $to = atas_draft_date($period['to'] ?? null) ?? $from;
    $d = [
        'format' => ATAS_DRAFT_FORMAT,
        'version' => ATAS_DRAFT_VERSION,
        'kind' => $kind,
        'symbol' => mb_substr(preg_replace('/[^A-Za-z0-9 ._:\/=-]/', '', (string)($raw['symbol'] ?? '')) ?? '', 0, 32),
        'market' => '',
        'tick' => atas_draft_num($raw['tick'] ?? null),
        'profile' => ($raw['profile'] ?? '') === 'eth' ? 'eth' : 'rth',
        'generated_at' => is_string($raw['generated_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?Z$/', $raw['generated_at']) ? $raw['generated_at'] : null,
        'source' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($raw['source'] ?? '')) ?? ''), 0, 80),
        'period' => ['from' => $from, 'to' => $to],
        'next' => atas_draft_date($raw['next'] ?? null),
    ];
    try {
        $d['market'] = atas_market($raw['market'] ?? '');
    } catch (InvalidArgumentException) {
        $d['market'] = '';
    }
    foreach (['open', 'high', 'low', 'close', 'volume', 'poc', 'vah', 'val', 'vwap'] as $key) {
        $d[$key] = atas_draft_num($raw[$key] ?? null);
    }
    if ($kind === '' || $from === null || $d['high'] === null || $d['low'] === null || $d['close'] === null) {
        throw new InvalidArgumentException('V nástřelu chybí typ, datum nebo High, Low a Close.');
    }
    if ($d['low'] > $d['high']) {
        [$d['low'], $d['high']] = [$d['high'], $d['low']];
    }
    $d['shape'] = in_array($raw['shape'] ?? '', PROFILE_SHAPES, true) ? (string)$raw['shape'] : '';
    $ib = is_array($raw['ib'] ?? null) ? $raw['ib'] : [];
    $ibBand = atas_draft_band($ib);
    $d['ib'] = $ibBand === null ? null : $ibBand + [
        'open' => atas_draft_num($ib['open'] ?? null),
        'close' => atas_draft_num($ib['close'] ?? null),
        'minutes' => max(1, min(240, (int)($ib['minutes'] ?? 60))),
        'avg_range' => atas_draft_num($ib['avg_range'] ?? null),
    ];
    $d['ib_ext'] = in_array($raw['ib_ext'] ?? '', ['up', 'down', 'both', 'none'], true) ? (string)$raw['ib_ext'] : '';
    $prev = is_array($raw['prev'] ?? null) ? $raw['prev'] : [];
    $d['prev'] = [];
    foreach (['high', 'low', 'poc', 'vah', 'val'] as $key) {
        $d['prev'][$key] = atas_draft_num($prev[$key] ?? null);
    }
    $d['single_prints'] = atas_draft_list_clean($raw['single_prints'] ?? [], 'band', 20);
    $tails = is_array($raw['tails'] ?? null) ? $raw['tails'] : [];
    $d['tails'] = ['buying' => atas_draft_band($tails['buying'] ?? null), 'selling' => atas_draft_band($tails['selling'] ?? null)];
    $d['poor_high'] = !empty($raw['poor_high']);
    $d['poor_low'] = !empty($raw['poor_low']);
    $d['naked_pocs'] = atas_draft_list_clean($raw['naked_pocs'] ?? [], 'price', 20);
    $d['lvn'] = atas_draft_list_clean($raw['lvn'] ?? [], 'price', 12);
    $d['hvn'] = atas_draft_list_clean($raw['hvn'] ?? [], 'price', 12);
    $on = is_array($raw['overnight'] ?? null) ? $raw['overnight'] : null;
    $onBand = $on === null ? null : atas_draft_band($on);
    $d['overnight'] = $onBand === null ? null : $onBand + ['poc' => atas_draft_num($on['poc'] ?? null)];
    return $d;
}

/* ---------------------------------------------------------------- příjem z ATAS (atas.php POST) */

/**
 * Uloží nástřel poslaný indikátorem. Token je stejný jako u zón (jeden odkaz na trh).
 * Vrací krátké potvrzení pro indikátor; chyby jako InvalidArgumentException (422),
 * neplatný odkaz null (404), moc požadavků RuntimeException (429).
 */
function atas_draft_receive(string $token, string $body): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
        return null;
    }
    $feed = app_fetch_one("SELECT f.id, f.user_id, f.market FROM atas_feeds f JOIN users u ON u.id = f.user_id WHERE f.token_hash = ? AND u.status = 'active'", [atas_token_hash($token)]);
    if ($feed === null) {
        return null;
    }
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
    $recent = (int)(app_fetch_one('SELECT COUNT(*) AS n FROM atas_drafts WHERE user_id = ? AND received_at >= ?', [(int)$feed['user_id'], $since])['n'] ?? 0);
    if ($recent >= ATAS_DRAFT_PER_HOUR) {
        throw new RuntimeException('Za poslední hodinu přišlo moc nástřelů. Zkus to později.');
    }
    try {
        $raw = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Data nástřelu nejsou platný JSON.');
    }
    $draft = atas_draft_clean($raw);
    $market = (string)$feed['market'];
    $now = utc_now();
    app_execute('INSERT INTO atas_drafts (user_id, market, kind, period_from, period_to, payload, received_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        (int)$feed['user_id'], $market, $draft['kind'], $draft['period']['from'], $draft['period']['to'],
        json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $now,
    ]);
    // drží se jen posledních pár nástřelů na trh a typ
    app_execute('DELETE FROM atas_drafts WHERE user_id = ? AND market = ? AND kind = ? AND id NOT IN (SELECT id FROM atas_drafts WHERE user_id = ? AND market = ? AND kind = ? ORDER BY received_at DESC, id DESC LIMIT ?)',
        [(int)$feed['user_id'], $market, $draft['kind'], (int)$feed['user_id'], $market, $draft['kind'], ATAS_DRAFT_KEEP]);
    return ['ok' => true, 'market' => $market, 'kind' => $draft['kind'], 'period' => $draft['period'], 'received_at' => $now];
}

/* ---------------------------------------------------------------- přihlášený člen */

function atas_draft_summary(array $row): array
{
    $d = json_decode((string)$row['payload'], true) ?: [];
    return [
        'id' => (int)$row['id'],
        'market' => (string)$row['market'],
        'kind' => (string)$row['kind'],
        'period' => ['from' => $row['period_from'], 'to' => $row['period_to']],
        'next' => $d['next'] ?? null,
        'received_at' => (string)$row['received_at'],
        'symbol' => $d['symbol'] ?? '',
        'profile' => $d['profile'] ?? 'rth',
        'high' => $d['high'] ?? null,
        'low' => $d['low'] ?? null,
        'close' => $d['close'] ?? null,
        'poc' => $d['poc'] ?? null,
    ];
}

function atas_draft_list(): array
{
    $user = require_user();
    $rows = app_fetch_all('SELECT * FROM atas_drafts WHERE user_id = ? ORDER BY received_at DESC, id DESC LIMIT ' . ATAS_DRAFT_LIST_MAX, [(int)$user['id']]);
    return ['items' => array_map('atas_draft_summary', $rows)];
}

function atas_draft_delete(int $id): array
{
    $user = require_user();
    app_execute('DELETE FROM atas_drafts WHERE id = ? AND user_id = ?', [$id, (int)$user['id']]);
    return atas_draft_list();
}

/** Uložený nástřel jako předvyplněný náhled (nic se tím neuloží do deníku). */
function atas_draft_open(int $id): array
{
    $user = require_user();
    $row = app_fetch_one('SELECT * FROM atas_drafts WHERE id = ? AND user_id = ?', [$id, (int)$user['id']]);
    if ($row === null) {
        json_response(['error' => 'Nástřel už není k dispozici.'], 404);
    }
    $draft = json_decode((string)$row['payload'], true);
    return atas_draft_plan(atas_draft_clean($draft), (string)$row['market']) + ['draft' => atas_draft_summary($row)];
}

/** Ručně nahraný soubor z indikátoru: jen převod, nic se neukládá. */
function atas_draft_from_file(array $data): array
{
    require_user();
    $raw = $data['draft'] ?? null;
    if (is_string($raw)) {
        if (strlen($raw) > ATAS_DRAFT_MAX_BYTES) {
            throw new InvalidArgumentException('Soubor je moc velký, nástřel z ATAS má jen pár kilobajtů.');
        }
        try {
            $raw = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Soubor není platný JSON z indikátoru.');
        }
    }
    $draft = atas_draft_clean($raw);
    $market = $draft['market'];
    if (!empty($data['market'])) {
        $market = atas_market($data['market']);
    }
    if ($market === '') {
        throw new InvalidArgumentException('V souboru chybí trh. Vyber ho v náhledu a nahraj soubor znovu.');
    }
    return atas_draft_plan($draft, $market);
}

/* ---------------------------------------------------------------- převod na náhled */

function atas_draft_price(?float $value): string
{
    if ($value === null) {
        return '—';
    }
    $text = number_format($value, 2, ',', "\u{00A0}");
    return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
}

function atas_draft_day(?string $iso): string
{
    if ($iso === null) {
        return '';
    }
    [$y, $m, $d] = array_map('intval', explode('-', $iso));
    return sprintf('%d. %d. %d', $d, $m, $y);
}

/** Další obchodní den (bez víkendu; svátky burzy indikátor ani server neznají). */
function atas_draft_next_day(string $iso): string
{
    $date = new DateTimeImmutable($iso . ' 12:00:00', new DateTimeZone('UTC'));
    do {
        $date = $date->modify('+1 day');
    } while ((int)$date->format('N') >= 6);
    return $date->format('Y-m-d');
}

/** Poloha Close proti value (stejné pravidlo jako deriveCloseLocation v app.js). */
function atas_draft_close_location(array $d): string
{
    [$close, $vah, $val, $poc] = [$d['close'], $d['vah'], $d['val'], $d['poc']];
    if ($close === null || $vah === null || $val === null || $vah <= $val) {
        return '';
    }
    if ($close > $vah) {
        return 'above';
    }
    if ($close < $val) {
        return 'below';
    }
    if ($poc === null) {
        return '';
    }
    if (abs($close - $poc) <= ($vah - $val) * 0.05) {
        return 'poc';
    }
    return $close > $poc ? 'upper' : 'lower';
}

/**
 * Převede nástřel na hodnoty formuláře náhledu: profil (High, VAH, POC, VAL, Low, Close),
 * tvar, migraci value a POC, IB, single prints, tail, reference na dojetí a levely navíc
 * (VWAP, IB, overnight). Vrací i id existujícího náhledu stejného dne, trhu a typu, aby ho
 * aplikace mohla jen doplnit.
 */
function atas_draft_plan(array $d, string $market): array
{
    $weekly = $d['kind'] === 'weekly';
    $periodTo = $d['period']['to'] ?? $d['period']['from'];
    $next = $d['next'] ?? null;
    if ($next === null || $next <= $periodTo) {
        $next = atas_draft_next_day($periodTo);
    }
    $date = $weekly ? week_start($next) : $next;
    $tick = $d['tick'] !== null && $d['tick'] > 0 ? $d['tick'] : 0.25;
    $prev = $d['prev'];

    $plan = [
        'plan_type' => $weekly ? 'weekly' : 'daily',
        'plan_date' => $date,
        'market' => $market,
        'status' => 'draft',
        'ref_high' => $d['high'],
        'ref_vah' => $d['vah'],
        'ref_poc' => $d['poc'],
        'ref_val' => $d['val'],
        'ref_low' => $d['low'],
        'ref_close' => $d['close'],
        'profile_shape' => $d['shape'],
        'previous_close' => atas_draft_close_location($d),
    ];

    // migrace value a POC proti předchozímu období
    if ($d['vah'] !== null && $d['val'] !== null && $prev['vah'] !== null && $prev['val'] !== null) {
        $plan['value_area'] = $d['vah'] > $prev['vah'] && $d['val'] > $prev['val'] ? 'rising'
            : ($d['vah'] < $prev['vah'] && $d['val'] < $prev['val'] ? 'falling' : 'overlap');
    }
    if ($d['poc'] !== null && $prev['poc'] !== null) {
        $diff = $d['poc'] - $prev['poc'];
        $plan['vpoc'] = abs($diff) <= 2 * $tick + 1e-9 ? 'stable' : ($diff > 0 ? 'rising' : 'falling');
    }
    // „Cena vůči týdenní VA“ je jen v denním náhledu: Close dne proti value z týdenního
    // náhledu stejného týdne (bez týdenního náhledu zůstane prázdné)
    if (!$weekly && $d['close'] !== null) {
        $week = fetch_one(
            "SELECT ref_vah, ref_val FROM plans WHERE plan_type = 'weekly' AND plan_date = ? AND market = ? AND ref_vah IS NOT NULL AND ref_val IS NOT NULL ORDER BY id LIMIT 1",
            [week_start($date), $market]
        );
        if ($week !== null && (float)$week['ref_vah'] > (float)$week['ref_val']) {
            $plan['weekly_position'] = $d['close'] > (float)$week['ref_vah'] ? 'above' : ($d['close'] < (float)$week['ref_val'] ? 'below' : 'inside');
        }
    }
    // aukce: trend nebo posunutá value = imbalance, vyvážený profil s překrytou value = balance
    if ($d['shape'] === 'trend' || ($plan['value_area'] ?? '') === 'rising' || ($plan['value_area'] ?? '') === 'falling') {
        $plan['auction'] = 'imbalance';
    } elseif ($d['shape'] === 'd' && ($plan['value_area'] ?? '') === 'overlap') {
        $plan['auction'] = 'balance';
    }

    // IB minulé seance: malá / běžná / velká proti průměru posledních dní; velká s otevřením
    // a zavřením IB na opačných koncích = drive, jinak rotace. Jen do poznámky: pole Initial
    // Balance v kroku Otevření patří ke dni náhledu a vyplní se až po jeho první hodině.
    $ib = $d['ib'];
    $ibClass = '';
    if (!$weekly && $ib !== null) {
        $range = $ib['high'] - $ib['low'];
        $avg = $ib['avg_range'];
        if ($avg !== null && $avg > 0) {
            if ($range < 0.7 * $avg) {
                $ibClass = 'malá';
            } elseif ($range > 1.3 * $avg) {
                $third = $range / 3;
                $drive = $ib['open'] !== null && $ib['close'] !== null && $range > 0
                    && (($ib['open'] <= $ib['low'] + $third && $ib['close'] >= $ib['high'] - $third)
                        || ($ib['open'] >= $ib['high'] - $third && $ib['close'] <= $ib['low'] + $third));
                $ibClass = $drive ? 'velká, drive' : 'velká, rotace';
            } else {
                $ibClass = 'běžná';
            }
            $ibClass .= ' proti průměru ' . atas_draft_price($avg) . ' b';
        }
    }

    // single prints a tail: nákupní pod POC / dole, prodejní nad POC / nahoře (vybere se větší)
    $width = static fn(array $band): float => $band['high'] - $band['low'];
    if ($d['single_prints'] !== [] && $d['poc'] !== null) {
        $largest = $d['single_prints'][0];
        foreach ($d['single_prints'] as $band) {
            if ($width($band) > $width($largest)) {
                $largest = $band;
            }
        }
        $plan['single_print'] = $largest['high'] <= $d['poc'] ? 'buy' : 'sell';
    }
    [$buying, $selling] = [$d['tails']['buying'], $d['tails']['selling']];
    if ($buying !== null || $selling !== null) {
        $plan['tail'] = $selling === null || ($buying !== null && $width($buying) >= $width($selling)) ? 'buy' : 'sell';
    }

    // reference na dojetí
    $refs = [];
    $label = $weekly ? 'týden' : 'den';
    foreach ($d['single_prints'] as $band) {
        $refs[] = ['kind' => 'single_print', 'price_low' => $band['low'], 'price_high' => $band['high'], 'status' => 'open', 'note' => "Single prints ($label " . atas_draft_day($periodTo) . ', ATAS)'];
    }
    if ($d['poor_high']) {
        $refs[] = ['kind' => 'poor_high', 'price_low' => $d['high'], 'price_high' => null, 'status' => 'open', 'note' => "Poor high ($label " . atas_draft_day($periodTo) . ', ATAS)'];
    }
    if ($d['poor_low']) {
        $refs[] = ['kind' => 'poor_low', 'price_low' => $d['low'], 'price_high' => null, 'status' => 'open', 'note' => "Poor low ($label " . atas_draft_day($periodTo) . ', ATAS)'];
    }
    foreach (['buying' => 'Kupní tail', 'selling' => 'Prodejní tail'] as $key => $text) {
        if ($d['tails'][$key] !== null) {
            $refs[] = ['kind' => 'excess', 'price_low' => $d['tails'][$key]['low'], 'price_high' => $d['tails'][$key]['high'], 'status' => 'open', 'note' => "$text ($label " . atas_draft_day($periodTo) . ', ATAS)'];
        }
    }
    foreach ($d['naked_pocs'] as $npoc) {
        $refs[] = ['kind' => 'naked_poc', 'price_low' => $npoc['price'], 'price_high' => null, 'status' => 'open', 'note' => 'Naked POC' . (isset($npoc['date']) ? ' z ' . atas_draft_day($npoc['date']) : '') . ' (ATAS)'];
    }
    foreach ($d['lvn'] as $lvn) {
        $refs[] = ['kind' => 'lvn', 'price_low' => $lvn['price'], 'price_high' => null, 'status' => 'open', 'note' => "LVN ($label " . atas_draft_day($periodTo) . ', ATAS)'];
    }

    // levely navíc (profil je v polích High, VAH, POC, VAL, Low)
    $levels = [];
    $level = static function (string $name, ?float $price, string $kind, string $style, string $note) use (&$levels): void {
        if ($price !== null) {
            $levels[] = ['name' => $name, 'price' => $price, 'kind' => $kind, 'line_style' => $style, 'source' => 'ATAS', 'note' => $note];
        }
    };
    $prefix = $weekly ? 'PW' : 'PD';
    $level("$prefix VWAP", $d['vwap'], 'pivot', 'dashed', $weekly ? 'VWAP minulého týdne' : 'VWAP minulé seance');
    if ($ib !== null && !$weekly) {
        $level('IBH', $ib['high'], 'resistance', 'dotted', "Initial balance ({$ib['minutes']} min) minulé seance");
        $level('IBL', $ib['low'], 'support', 'dotted', "Initial balance ({$ib['minutes']} min) minulé seance");
    }
    if ($d['overnight'] !== null) {
        $level('ONH', $d['overnight']['high'], 'resistance', 'dashed', 'Overnight high');
        $level('ONL', $d['overnight']['low'], 'support', 'dashed', 'Overnight low');
        $level('ON POC', $d['overnight']['poc'], 'pivot', 'dotted', 'POC overnightu');
    }
    foreach ($d['hvn'] as $hvn) {
        $level('HVN', $hvn['price'], 'pivot', 'dotted', 'High volume node (ATAS)');
    }

    $ibText = $ib === null || $weekly ? '' : sprintf(' IB %s–%s%s.', atas_draft_price($ib['low']), atas_draft_price($ib['high']), $ibClass === '' ? '' : " ($ibClass)");
    $plan['general_notes'] = sprintf(
        'Nástřel z ATAS: %s profil %s %s%s (O %s, H %s, L %s, C %s; VAH %s, POC %s, VAL %s).%s',
        $weekly ? 'týdenní' : 'denní',
        $d['profile'] === 'eth' ? 'ETH' : 'RTH',
        $weekly ? atas_draft_day($d['period']['from']) . ' – ' . atas_draft_day($periodTo) : atas_draft_day($periodTo),
        $d['symbol'] !== '' ? ', ' . $d['symbol'] : '',
        atas_draft_price($d['open']), atas_draft_price($d['high']), atas_draft_price($d['low']), atas_draft_price($d['close']),
        atas_draft_price($d['vah']), atas_draft_price($d['poc']), atas_draft_price($d['val']),
        $ibText
    );
    $plan['levels'] = $levels;
    $plan['refs'] = $refs;

    $existing = fetch_one('SELECT id FROM plans WHERE plan_type = ? AND plan_date = ? AND market = ? ORDER BY id LIMIT 1', [$plan['plan_type'], $date, $market]);
    return ['plan' => $plan, 'existing_id' => $existing === null ? null : (int)$existing['id']];
}
