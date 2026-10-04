<?php
declare(strict_types=1);

/*
 * Odkaz pro ATAS: indikátor v ATAS si přes tajný odkaz stáhne zóny z denního náhledu
 * a podle směru zóny ví, jestli na ní hledat longy, shorty, nebo obojí.
 *
 * ATAS se do deníku přihlásit neumí a šifrovaný deník server bez člena neotevře. Proto
 * se zóny ukládají jako snímek do sdílené app.sqlite3 (podobně jako sdílení na nástěnku)
 * a snímek se obnoví při každém uložení náhledu nebo zóny. Snímek nese jen zóny
 * a pár údajů o dni, nic dalšího z deníku.
 *
 * Token zná jen člen: v deníku je uložený celý (u šifrovaného deníku zašifrovaný),
 * v app.sqlite3 jen jeho otisk SHA-256. Jeden odkaz na trh, vždy s posledním denním
 * náhledem toho trhu, takže se do ATAS vkládá jen jednou.
 */

const ATAS_FEED_FORMAT = 1;
const ATAS_TEXT_MAX = 240;

// Tabulka atas_feeds je ve schématu app.sqlite3 (initialize_app_schema v lib/accounts.php).

function ensure_atas_journal_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS atas_links (
    market TEXT PRIMARY KEY,
    token TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);
}

function atas_market(mixed $value): string
{
    $market = strtoupper(trim(is_scalar($value) ? (string)$value : ''));
    if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,15}$/', $market)) {
        throw new InvalidArgumentException('Zadej trh náhledu, třeba ES.');
    }
    return $market;
}

function atas_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/* ---------------------------------------------------------------- snímek zón */

/**
 * Zóny pro poslední denní náhled trhu: zóny toho dne, zóny starších náhledů, které
 * pořád platí (do data nebo „dokud je neukončím“), a volitelně zóny týdenního náhledu.
 */
function atas_snapshot(string $market, bool $includeWeekly): array
{
    $plan = fetch_one("SELECT id, plan_date, bias FROM plans WHERE plan_type = 'daily' AND market = ? ORDER BY plan_date DESC, id DESC LIMIT 1", [$market]);
    $snapshot = [
        'format' => ATAS_FEED_FORMAT,
        'market' => $market,
        'date' => $plan['plan_date'] ?? null,
        'start' => null,
        'bias' => (string)($plan['bias'] ?? ''),
        'generated_at' => utc_now(),
        'zones' => [],
    ];
    if ($plan === null) {
        return $snapshot;
    }
    $date = (string)$plan['plan_date'];
    $snapshot['start'] = hs_trade_day_start($date);

    $rows = fetch_all(
        "SELECT z.*, p.plan_date, p.id AS source_plan FROM zones z JOIN plans p ON p.id = z.plan_id
         WHERE p.plan_type = 'daily' AND p.market = ? AND p.plan_date <= ? AND z.price_low IS NOT NULL AND z.price_high IS NOT NULL
           AND ((COALESCE(z.valid_to, '') = '' AND p.plan_date = ?) OR z.valid_to = 'open'
             OR (z.valid_to GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' AND z.valid_to >= ?))
         ORDER BY p.plan_date DESC, z.sort_order",
        [$market, $date, $date, $date]
    );
    $zones = [];
    foreach ($rows as $row) {
        $zones[] = atas_zone($row, (int)$row['source_plan'] === (int)$plan['id'] ? 'daily' : 'carry');
    }
    if ($includeWeekly) {
        $weekly = find_weekly_plan(week_start($date), $market);
        if ($weekly !== null) {
            foreach (fetch_all('SELECT * FROM zones WHERE plan_id = ? AND price_low IS NOT NULL AND price_high IS NOT NULL ORDER BY sort_order', [(int)$weekly['id']]) as $row) {
                $zones[] = atas_zone($row + ['plan_date' => $weekly['plan_date']], 'weekly');
            }
        }
    }
    $snapshot['zones'] = $zones;
    return $snapshot;
}

function atas_zone(array $row, string $scope): array
{
    $direction = in_array($row['direction'] ?? '', ['long', 'short', 'both'], true) ? (string)$row['direction'] : '';
    return [
        'id' => (int)$row['id'],
        'name' => atas_clean($row['name'] ?? ''),
        'low' => (float)$row['price_low'],
        'high' => (float)$row['price_high'],
        'direction' => $direction,
        'priority' => atas_clean($row['priority'] ?? ''),
        'type' => function_exists('hs_zone_type') ? hs_zone_type($row) : '',
        'scope' => $scope,
        'valid_from' => (string)($row['plan_date'] ?? ''),
        'valid_to' => (string)($row['valid_to'] ?? ''),
        'long_entry' => $direction === 'short' ? '' : atas_clean($row['long_entry'] ?? ''),
        'short_entry' => $direction === 'long' ? '' : atas_clean($row['short_entry'] ?? ''),
    ];
}

/** Text do jednoho pole: bez středníků, tabulátorů a konců řádků, rozumně dlouhý. */
function atas_clean(mixed $value): string
{
    $text = trim((string)preg_replace('/[;\t\r\n\x00-\x1F\x7F]+/u', ' ', mb_scrub((string)$value, 'UTF-8')));
    return mb_substr((string)preg_replace('/\s{2,}/u', ' ', $text), 0, ATAS_TEXT_MAX, 'UTF-8');
}

function atas_number(float $value): string
{
    return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
}

/**
 * Textový formát pro indikátor: řádky „klíč;hodnota“, zóny jako
 * zone;id;název;od;do;směr;priorita;rozsah;typ;platí do;vstup long;vstup short.
 */
function atas_feed_text(array $snapshot): string
{
    $lines = [
        '# Trading Desk: zóny z denního náhledu pro ATAS',
        '# zone;id;název;od;do;směr (long/short/both);priorita;rozsah (daily/carry/weekly);typ;platí do;vstup long;vstup short',
        'format;' . ATAS_FEED_FORMAT,
        'market;' . atas_clean($snapshot['market'] ?? ''),
        'date;' . ($snapshot['date'] ?? ''),
        'start;' . ($snapshot['start'] ?? ''),
        'bias;' . atas_clean($snapshot['bias'] ?? ''),
        'updated;' . ($snapshot['generated_at'] ?? ''),
    ];
    foreach ($snapshot['zones'] ?? [] as $zone) {
        $lines[] = implode(';', [
            'zone', (int)$zone['id'], atas_clean($zone['name']), atas_number((float)$zone['low']), atas_number((float)$zone['high']),
            $zone['direction'], atas_clean($zone['priority']), $zone['scope'], atas_clean($zone['type']), atas_clean($zone['valid_to']),
            atas_clean($zone['long_entry']), atas_clean($zone['short_entry']),
        ]);
    }
    return implode("\n", $lines) . "\n";
}

/* ---------------------------------------------------------------- odkaz člena */

function atas_feed_row(string $market): ?array
{
    $user = current_user();
    return $user === null ? null : app_fetch_one('SELECT * FROM atas_feeds WHERE user_id = ? AND market = ?', [(int)$user['id'], $market]);
}

/** Stav odkazu pro dialog: token (z deníku), co odkaz teď vrací a kdy ho ATAS naposledy četl. */
function atas_link_state(string $market): array
{
    $feed = atas_feed_row($market);
    $local = fetch_one('SELECT token FROM atas_links WHERE market = ?', [$market]);
    $preview = atas_snapshot($market, $feed === null ? true : (bool)$feed['include_weekly']);
    $summary = ['date' => $preview['date'], 'zones' => count($preview['zones'])];
    if ($feed === null || $local === null || !hash_equals((string)$feed['token_hash'], atas_token_hash((string)$local['token']))) {
        return ['market' => $market, 'link' => null, 'preview' => $summary];
    }
    return [
        'market' => $market,
        'link' => [
            'token' => (string)$local['token'],
            'include_weekly' => (bool)$feed['include_weekly'],
            'created_at' => (string)$feed['created_at'],
            'updated_at' => (string)$feed['updated_at'],
            'read_at' => $feed['read_at'],
        ],
        'preview' => $summary,
    ];
}

/** Založí odkaz, změní nastavení, nebo vydá nový token (starý přestane platit). */
function atas_save_link(array $data): array
{
    $user = require_user();
    $market = atas_market($data['market'] ?? '');
    $includeWeekly = !array_key_exists('include_weekly', $data) || !empty($data['include_weekly']);
    $feed = atas_feed_row($market);
    $local = fetch_one('SELECT token FROM atas_links WHERE market = ?', [$market]);
    $valid = $feed !== null && $local !== null && hash_equals((string)$feed['token_hash'], atas_token_hash((string)$local['token']));
    $token = $valid && empty($data['regenerate']) ? (string)$local['token'] : rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $snapshot = json_encode(atas_snapshot($market, $includeWeekly), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $now = utc_now();

    if ($feed === null) {
        app_execute('INSERT INTO atas_feeds (user_id, market, token_hash, include_weekly, snapshot, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int)$user['id'], $market, atas_token_hash($token), $includeWeekly ? 1 : 0, $snapshot, $now, $now]);
    } else {
        $reset = atas_token_hash($token) !== (string)$feed['token_hash'];
        app_execute('UPDATE atas_feeds SET token_hash = ?, include_weekly = ?, snapshot = ?, updated_at = ?' . ($reset ? ', created_at = ?, read_at = NULL' : '') . ' WHERE id = ?',
            $reset ? [atas_token_hash($token), $includeWeekly ? 1 : 0, $snapshot, $now, $now, (int)$feed['id']] : [atas_token_hash($token), $includeWeekly ? 1 : 0, $snapshot, $now, (int)$feed['id']]);
    }
    db()->prepare('INSERT INTO atas_links (market, token, created_at) VALUES (?, ?, ?) ON CONFLICT(market) DO UPDATE SET token = excluded.token, created_at = excluded.created_at')
        ->execute([$market, $token, $now]);
    return atas_link_state($market);
}

function atas_delete_link(string $market): array
{
    $user = require_user();
    $market = atas_market($market);
    app_execute('DELETE FROM atas_feeds WHERE user_id = ? AND market = ?', [(int)$user['id'], $market]);
    db()->prepare('DELETE FROM atas_links WHERE market = ?')->execute([$market]);
    return atas_link_state($market);
}

/**
 * Obnoví snímky všech odkazů přihlášeného člena. Volá se po uložení náhledu nebo
 * zóny; chyba tady nesmí shodit samotné uložení, odkaz se pak obnoví příště.
 */
function atas_refresh_feeds(): void
{
    try {
        $user = current_user();
        if ($user === null) {
            return;
        }
        foreach (app_fetch_all('SELECT id, market, include_weekly FROM atas_feeds WHERE user_id = ?', [(int)$user['id']]) as $feed) {
            $snapshot = json_encode(atas_snapshot((string)$feed['market'], (bool)$feed['include_weekly']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            app_execute('UPDATE atas_feeds SET snapshot = ?, updated_at = ? WHERE id = ?', [$snapshot, utc_now(), (int)$feed['id']]);
        }
    } catch (Throwable $error) {
        error_log('Trading Desk: odkaz pro ATAS se neobnovil: ' . $error->getMessage());
    }
}

/* ---------------------------------------------------------------- veřejné čtení (atas.php) */

/** Snímek podle tokenu, jen pro aktivní účet. Zapíše, kdy ATAS naposledy četl (nejvýš jednou za minutu). */
function atas_feed_by_token(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
        return null;
    }
    $feed = app_fetch_one("SELECT f.* FROM atas_feeds f JOIN users u ON u.id = f.user_id WHERE f.token_hash = ? AND u.status = 'active'", [atas_token_hash($token)]);
    if ($feed === null) {
        return null;
    }
    $readAt = $feed['read_at'] !== null ? strtotime((string)$feed['read_at']) : false;
    if ($readAt === false || time() - $readAt >= 60) {
        app_execute('UPDATE atas_feeds SET read_at = ? WHERE id = ?', [utc_now(), (int)$feed['id']]);
    }
    return json_decode((string)$feed['snapshot'], true) ?: null;
}
