<?php
declare(strict_types=1);

/*
 * Hindsight: souvislá časová osa 5m svíček ES s náhledy (zóny, bias), news a obchody.
 *
 * Svíčky jsou tržní data, ne osobní deník, proto leží ve sdílené databázi
 * market.sqlite3 (i u šifrovaných deníků by se jinak celý deník rozšifrovával
 * při každém posunu grafu). Nahrává je správce. Zóny, bias a obchody jsou
 * v deníku každého tradera: Hindsight čte a zapisuje stejné zóny a bias jako
 * denní náhled, takže se nic nezadává dvakrát.
 *
 * Časy svíček jsou v UTC (unix sekundy, začátek svíčky). Seance, obchodní den
 * a zobrazení v Europe/Prague počítá až prohlížeč.
 */

const HS_SYMBOLS = ['ES'];
const HS_PLAN_MARKETS = ['ES', 'MES'];
const HS_BAR_SECONDS = 300;
const HS_ZONE_TYPES = ['support', 'resistance', 'vpoc', 'other'];
const HS_MAX_RANGE_DAYS = 400;
/** Výsledek potenciálního obchodu: nevzatý, propáslý, vzatý. */
const HS_OUTCOMES = ['skipped', 'missed', 'taken'];
const HS_MONTHS = ['F' => 1, 'G' => 2, 'H' => 3, 'J' => 4, 'K' => 5, 'M' => 6, 'N' => 7, 'Q' => 8, 'U' => 9, 'V' => 10, 'X' => 11, 'Z' => 12];

function hs_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    ensure_directory(storage_root());
    $pdo = new PDO('sqlite:' . storage_root() . DIRECTORY_SEPARATOR . 'market.sqlite3');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bars (
    contract TEXT NOT NULL,
    ts INTEGER NOT NULL,
    open REAL NOT NULL,
    high REAL NOT NULL,
    low REAL NOT NULL,
    close REAL NOT NULL,
    volume REAL NOT NULL DEFAULT 0,
    PRIMARY KEY (contract, ts)
) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS contracts (
    contract TEXT PRIMARY KEY,
    symbol TEXT NOT NULL,
    expiry TEXT,
    demo INTEGER NOT NULL DEFAULT 0,
    source TEXT,
    imported_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_bars_ts ON bars(ts);
SQL);
    return $pdo;
}

/* ---------------------------------------------------------------- kontrakty a roll */

/** Třetí pátek měsíce expirace (ES: březen, červen, září, prosinec). */
function hs_third_friday(int $year, int $month): string
{
    $first = (int)gmdate('N', gmmktime(0, 0, 0, $month, 1, $year));
    $day = 1 + ((5 - $first + 7) % 7) + 14;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * Expirace z názvu kontraktu: ESZ6, ESZ26, ES 12-26, ESZ2026. Neznámý tvar
 * (už spojená data od dodavatele) = bez expirace, bez rollu.
 */
function hs_contract_expiry(string $contract): ?string
{
    $name = strtoupper(preg_replace('/\s+/', ' ', trim($contract)) ?? '');
    $year = null;
    $month = null;
    if (preg_match('/^M?ES\s?([FGHJKMNQUVXZ])\s?(\d{1,4})$/', $name, $m)) {
        $month = HS_MONTHS[$m[1]];
        $year = (int)$m[2];
    } elseif (preg_match('/^M?ES\s?(\d{1,2})[-\/.](\d{2,4})$/', $name, $m)) {
        $month = (int)$m[1];
        $year = (int)$m[2];
    }
    if ($month === null || $month < 1 || $month > 12) {
        return null;
    }
    if ($year < 10) {
        // Jednociferný rok: nejbližší budoucí nebo nedávný (ESZ6 = 2026).
        $decade = (int)(gmdate('Y') / 10) * 10;
        $year = $decade + $year;
        if ($year > (int)gmdate('Y') + 5) {
            $year -= 10;
        }
    } elseif ($year < 100) {
        $year += 2000;
    }
    return hs_third_friday($year, $month);
}

/** Posun kalendářního data o dny, nezávisle na časovém pásmu serveru. */
function hs_add_days(string $date, int $days): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify(sprintf('%+d days', $days))->format('Y-m-d');
}

/** Den rollu: čtvrtek osm dní před expirací (zvyk CME u ES). */
function hs_roll_date(string $expiry): string
{
    return hs_add_days($expiry, -8);
}

/** Začátek obchodního dne (předchozí den 18:00 v New Yorku) v UTC. */
function hs_trade_day_start(string $date): int
{
    $zone = new DateTimeZone('America/New_York');
    $start = new DateTimeImmutable($date . ' 18:00:00', $zone);
    return $start->modify('-1 day')->getTimestamp();
}

/**
 * Čtvrtletní kontrakt ES (H, M, U, Z) a jeho období: od rollu předchozího kontraktu
 * do vlastního rollu. Svíčky mimo toto období patří jinému kontraktu.
 * last_date = poslední obchodní den kontraktu (den před rollem).
 */
function hs_contract_period(int $year, int $month): array
{
    $expiry = hs_third_friday($year, $month);
    $previous = hs_third_friday($month === 3 ? $year - 1 : $year, $month === 3 ? 12 : $month - 3);
    $fromDate = hs_roll_date($previous);
    $rollDate = hs_roll_date($expiry);
    return [
        'contract' => 'ES' . array_search($month, HS_MONTHS, true) . ($year % 10),
        'expiry' => $expiry,
        'from_date' => $fromDate,
        'last_date' => hs_add_days($rollDate, -1),
        'roll_date' => $rollDate,
        'from_ts' => hs_trade_day_start($fromDate),
        'to_ts' => hs_trade_day_start($rollDate),
    ];
}

/** Kontrakt z názvu (ESZ6, ESZ26, ES 12-26, ESZ2026) v jednotném tvaru ESZ6, nebo null. */
function hs_contract_info(string $name): ?array
{
    $expiry = hs_contract_expiry($name);
    if ($expiry === null || !preg_match('/^\s*ES/i', $name)) {
        return null;
    }
    $month = (int)substr($expiry, 5, 2);
    return $month % 3 === 0 ? hs_contract_period((int)substr($expiry, 0, 4), $month) : null;
}

/** Kontrakt, který byl aktivní v okamžiku ts. */
function hs_contract_at(int $ts): array
{
    $year = (int)gmdate('Y', $ts);
    $month = (int)(ceil((int)gmdate('n', $ts) / 3) * 3);
    $period = hs_contract_period($year, $month);
    for ($guard = 0; $guard < 4 && $ts >= $period['to_ts']; $guard++) {
        [$year, $month] = $month === 12 ? [$year + 1, 3] : [$year, $month + 3];
        $period = hs_contract_period($year, $month);
    }
    for ($guard = 0; $guard < 4 && $ts < $period['from_ts']; $guard++) {
        [$year, $month] = $month === 3 ? [$year - 1, 12] : [$year, $month - 3];
        $period = hs_contract_period($year, $month);
    }
    return $period;
}

/** Nabídka kontraktů pro import: aktuální, čtyři předchozí a následující. */
function hs_contract_options(?int $now = null): array
{
    $current = hs_contract_at($now ?? time());
    $year = (int)substr($current['expiry'], 0, 4);
    $month = (int)substr($current['expiry'], 5, 2);
    $options = [];
    for ($step = 1; $step >= -4; $step--) {
        $index = $year * 12 + ($month - 1) + $step * 3;
        $period = hs_contract_period(intdiv($index, 12), $index % 12 + 1);
        $period['current'] = $step === 0;
        $options[] = $period;
    }
    return $options;
}

/** Kontrakty symbolu seřazené podle expirace; u každého okamžik, kdy se přechází na další. */
function hs_contracts(string $symbol = 'ES'): array
{
    $rows = hs_db()->prepare('SELECT c.*, (SELECT MIN(ts) FROM bars b WHERE b.contract = c.contract) AS first_ts, (SELECT MAX(ts) FROM bars b WHERE b.contract = c.contract) AS last_ts, (SELECT COUNT(*) FROM bars b WHERE b.contract = c.contract) AS bars FROM contracts c WHERE c.symbol = ? ORDER BY c.expiry IS NULL, c.expiry, c.contract');
    $rows->execute([$symbol]);
    $list = [];
    foreach ($rows->fetchAll() as $row) {
        $expiry = $row['expiry'] !== null ? (string)$row['expiry'] : null;
        $period = $expiry !== null ? hs_contract_info((string)$row['contract']) : null;
        $list[] = [
            'contract' => (string)$row['contract'],
            'expiry' => $expiry,
            'from_date' => $period['from_date'] ?? null,
            'last_date' => $period['last_date'] ?? null,
            'roll_date' => $expiry !== null ? hs_roll_date($expiry) : null,
            'roll_ts' => $expiry !== null ? hs_trade_day_start(hs_roll_date($expiry)) : null,
            'demo' => (bool)$row['demo'],
            'source' => (string)($row['source'] ?? ''),
            'imported_at' => (string)$row['imported_at'],
            'first_ts' => $row['first_ts'] !== null ? (int)$row['first_ts'] : null,
            'last_ts' => $row['last_ts'] !== null ? (int)$row['last_ts'] : null,
            'bars' => (int)$row['bars'],
        ];
    }
    return $list;
}

/**
 * Neupravený spojitý kontrakt: v každém okamžiku svíčka aktivního kontraktu,
 * přechod na další kontrakt v den rollu. Ceny se neposouvají (žádný back-adjust),
 * takže zóny a obchody sedí na svíčkách konkrétního kontraktu.
 */
function hs_bars(int $from, int $to, string $symbol = 'ES'): array
{
    $contracts = hs_chart_contracts($symbol);
    if ($contracts === []) {
        return ['bars' => [], 'rolls' => [], 'contracts' => []];
    }
    $index = [];
    foreach ($contracts as $position => $contract) {
        $index[$contract['contract']] = $position;
    }
    $names = array_keys($index);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $statement = hs_db()->prepare("SELECT contract, ts, open, high, low, close, volume FROM bars WHERE ts >= ? AND ts <= ? AND contract IN ($placeholders) ORDER BY ts");
    $statement->execute([$from, $to, ...$names]);

    // Aktivní kontrakt v čase ts: první podle expirace, jehož roll ještě nenastal.
    $activeAt = static function (int $ts) use ($contracts): ?string {
        foreach ($contracts as $contract) {
            if ($contract['roll_ts'] === null || $contract['roll_ts'] > $ts) {
                return $contract['contract'];
            }
        }
        return $contracts[count($contracts) - 1]['contract'];
    };

    $bars = [];
    $pickedBy = [];
    $current = null;
    $currentTs = null;
    $flush = static function () use (&$bars, &$current, &$pickedBy): void {
        if ($current !== null) {
            $bars[] = $current['bar'];
            $pickedBy[] = $current['contract'];
        }
    };
    foreach ($statement as $row) {
        $ts = (int)$row['ts'];
        $bar = [$ts, (float)$row['open'], (float)$row['high'], (float)$row['low'], (float)$row['close'], (float)$row['volume']];
        if ($ts !== $currentTs) {
            $flush();
            $current = ['bar' => $bar, 'contract' => (string)$row['contract']];
            $currentTs = $ts;
            continue;
        }
        // Stejný čas ve dvou kontraktech (kolem rollu): vyhrává aktivní.
        if ((string)$row['contract'] === $activeAt($ts)) {
            $current = ['bar' => $bar, 'contract' => (string)$row['contract']];
        }
    }
    $flush();

    $used = [];
    $rolls = [];
    foreach ($pickedBy as $position => $contract) {
        $used[$contract] = true;
        if ($position > 0 && $pickedBy[$position - 1] !== $contract) {
            $rolls[] = ['ts' => $bars[$position][0], 'from' => $pickedBy[$position - 1], 'to' => $contract];
        }
    }
    return [
        'bars' => $bars,
        'rolls' => $rolls,
        'contracts' => array_values(array_filter($contracts, static fn(array $c): bool => isset($used[$c['contract']]))),
    ];
}

/** Kontrakty do grafu: jakmile jsou nahraná skutečná data, ukázka se nepoužije. */
function hs_chart_contracts(string $symbol = 'ES'): array
{
    $contracts = hs_contracts($symbol);
    $real = array_values(array_filter($contracts, static fn(array $c): bool => !$c['demo']));
    return $real !== [] ? $real : $contracts;
}

function hs_range(string $symbol = 'ES'): array
{
    $contracts = hs_chart_contracts($symbol);
    $first = null;
    $last = null;
    foreach ($contracts as $contract) {
        if ($contract['first_ts'] !== null) {
            $first = $first === null ? $contract['first_ts'] : min($first, $contract['first_ts']);
            $last = $last === null ? $contract['last_ts'] : max($last, $contract['last_ts']);
        }
    }
    return [
        'first_ts' => $first,
        'last_ts' => $last,
        'contracts' => hs_contracts($symbol),
        'demo' => array_reduce($contracts, static fn(bool $carry, array $c): bool => $carry || $c['demo'], false),
        'contract_options' => hs_contract_options(),
    ];
}

/* ---------------------------------------------------------------- import CSV */

function hs_parse_number(string $raw, string $delimiter): ?float
{
    $value = trim($raw);
    $value = str_replace(["\u{00A0}", ' ', "'"], '', $value);
    if ($value === '') {
        return null;
    }
    if (str_contains($value, ',') && $delimiter !== ',') {
        // Desetinná čárka (a případně tečka jako oddělovač tisíců).
        if (str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }
        $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (float)$value : null;
}

/**
 * Datum a čas v mnoha tvarech: 2026-09-25 14:30, 25.09.2026 14:30:00, 09/25/2026 2:30 PM,
 * 20260925 143000, unix sekundy nebo milisekundy. $dayFirst rozhodne u lomítek,
 * $yearDayMonth u roku napřed: ATAS exportuje „2026-17-08“ (rok-den-měsíc).
 */
function hs_parse_datetime(string $text, DateTimeZone $zone, bool $dayFirst, bool $yearDayMonth = false): ?int
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^\d{10}(\d{3})?$/', $text)) {
        $number = (int)$text;
        return strlen($text) === 13 ? intdiv($number, 1000) : $number;
    }
    if (!preg_match('/^(\d{1,4})[.\/\-](\d{1,2})[.\/\-](\d{1,4})[T\s,]+(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?\s*(AM|PM)?/i', $text, $m)
        && !preg_match('/^(\d{4})(\d{2})(\d{2})[T\s]*(\d{2})(\d{2})(\d{2})?/', $text, $m)) {
        return null;
    }
    [$a, $b, $c] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    if (strlen($m[1]) === 4) {
        [$year, $month, $day] = $yearDayMonth ? [$a, $c, $b] : [$a, $b, $c];
    } elseif (str_contains($text, '.') && strpos($text, '.') < 3) {
        [$day, $month, $year] = [$a, $b, $c];
    } elseif ($dayFirst) {
        [$day, $month, $year] = [$a, $b, $c];
    } else {
        [$month, $day, $year] = [$a, $b, $c];
    }
    if ($year < 100) {
        $year += 2000;
    }
    $hour = (int)$m[4];
    $minute = (int)$m[5];
    $second = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
    $meridiem = strtoupper($m[7] ?? '');
    if ($meridiem === 'PM' && $hour < 12) {
        $hour += 12;
    } elseif ($meridiem === 'AM' && $hour === 12) {
        $hour = 0;
    }
    if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
        return null;
    }
    $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), $zone);
    return $moment instanceof DateTimeImmutable ? $moment->getTimestamp() : null;
}

/** Sloupce podle hlavičky (anglicky i česky); bez hlavičky podle počtu sloupců. */
function hs_detect_columns(array $header): ?array
{
    $map = [];
    foreach ($header as $index => $cell) {
        $name = strtolower(trim((string)preg_replace('/[^a-z]+/i', ' ', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$cell) ?: (string)$cell)));
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $has = static fn(array $words): bool => in_array($name, $words, true);
        if ($has(['datetime', 'date time', 'timestamp', 'time stamp', 'bar time', 'open time', 'cas a datum', 'datum a cas'])) {
            $map['datetime'] ??= $index;
        } elseif ($has(['date', 'datum', 'day', 'den'])) {
            $map['date'] ??= $index;
        } elseif ($has(['time', 'cas', 'hour'])) {
            $map['time'] ??= $index;
        } elseif ($has(['open', 'o', 'otevreni', 'open price'])) {
            $map['open'] ??= $index;
        } elseif ($has(['high', 'h', 'max', 'maximum', 'high price'])) {
            $map['high'] ??= $index;
        } elseif ($has(['low', 'l', 'min', 'minimum', 'low price'])) {
            $map['low'] ??= $index;
        } elseif ($has(['close', 'c', 'last', 'zavreni', 'close price'])) {
            $map['close'] ??= $index;
        } elseif ($has(['volume', 'vol', 'v', 'objem', 'total volume'])) {
            $map['volume'] ??= $index;
        }
    }
    if (isset($map['open'], $map['high'], $map['low'], $map['close']) && (isset($map['datetime']) || isset($map['date']))) {
        return $map;
    }
    return null;
}

function hs_row_stamp(array $row, array $map): string
{
    return isset($map['datetime'])
        ? trim((string)($row[$map['datetime']] ?? ''))
        : trim((string)($row[$map['date']] ?? '') . ' ' . (string)($row[$map['time'] ?? -1] ?? ''));
}

/** Řádky CSV jeden po druhém od začátku souboru (bez prázdných řádků, bez BOM). */
function hs_csv_rows($handle, string $delimiter, bool $skipHeader): Generator
{
    rewind($handle);
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) {
            $first = false;
            if ($skipHeader) {
                continue;
            }
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        }
        $line = trim($line);
        if ($line !== '') {
            yield str_getcsv($line, $delimiter, '"', '');
        }
    }
}

/**
 * Den a měsíc v datu jdou přečíst oběma způsoby: true = prohodit (den je první z dvojice),
 * false = neprohazovat, null = nejde poznat. Správné čtení nemá svíčky v budoucnu
 * a sousední řádky nedělí měsíce.
 */
function hs_date_order(array $stamps, callable $parse): ?bool
{
    $score = static function (bool $swap) use ($stamps, $parse): array {
        $invalid = 0;
        $future = 0;
        $maxGap = 0;
        $previous = null;
        $limit = time() + 2 * 86400;
        foreach ($stamps as $stamp) {
            $ts = $parse($stamp, $swap);
            if ($ts === null) {
                $invalid++;
                continue;
            }
            if ($ts > $limit) {
                $future++;
            }
            if ($previous !== null) {
                $maxGap = max($maxGap, abs($ts - $previous));
            }
            $previous = $ts;
        }
        return [$invalid, $future, $maxGap];
    };
    $plain = $score(false);
    $swapped = $score(true);
    if ($plain === $swapped) {
        return null;
    }
    return $swapped < $plain;
}

/**
 * Načte CSV se svíčkami (1m až 5m) a uloží je jako 5m: menší svíčky se sloučí.
 * Stejný čas stejného kontraktu se přepíše (opakovaný import nevytvoří duplicity).
 */
function hs_import_bars(string $path, string $contract, string $timeZone, string $source = 'csv', string $dateOrder = 'auto'): array
{
    $period = strlen($contract) <= 20 ? hs_contract_info($contract) : null;
    if ($period === null) {
        json_response(['error' => 'Vyber kontrakt ES, ke kterému soubor patří (třeba ESZ6 = prosinec 2026).'], 422);
    }
    $contract = $period['contract'];
    if (!in_array($timeZone, ['Europe/Prague', 'America/New_York', 'UTC', 'America/Chicago'], true)) {
        json_response(['error' => 'Neznámé časové pásmo souboru.'], 422);
    }
    $zone = new DateTimeZone($timeZone);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        json_response(['error' => 'Soubor nejde otevřít.'], 422);
    }
    $firstLine = (string)fgets($handle);
    $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
    $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delimiter = (string)array_key_first($counts);
    $header = str_getcsv(trim($firstLine), $delimiter, '"', '');
    $map = hs_detect_columns($header);
    $hasHeader = $map !== null;
    if ($map === null) {
        // Bez hlavičky: datum;čas;O;H;L;C;V nebo datumčas;O;H;L;C;V.
        $count = count($header);
        $map = $count >= 7 ? ['date' => 0, 'time' => 1, 'open' => 2, 'high' => 3, 'low' => 4, 'close' => 5, 'volume' => 6]
            : ($count >= 5 ? ['datetime' => 0, 'open' => 1, 'high' => 2, 'low' => 3, 'close' => 4, 'volume' => $count >= 6 ? 5 : null] : null);
        if ($map === null) {
            fclose($handle);
            json_response(['error' => 'V souboru jsem nenašel sloupce datum, open, high, low, close.'], 422);
        }
    }

    // 1. průchod: pořadí dne a měsíce. Jisté je jen číslo nad 12; jinak se na vzorku
    // porovnají obě čtení (správné nemá svíčky v budoucnu ani měsíční skoky).
    // Soubor se čte po řádcích dvakrát, celý se do paměti nenačítá.
    $step = max(1, (int)ceil((int)filesize($path) / 40 / 20000));
    $stamps = [];
    $sample = '';
    $forced = ['year' => null, 'slash' => null];
    $index = 0;
    foreach (hs_csv_rows($handle, $delimiter, $hasHeader) as $row) {
        $stamp = hs_row_stamp($row, $map);
        if ($sample === '' && preg_match('/^\d/', $stamp)) {
            $sample = $stamp;
        }
        if ($index++ % $step === 0) {
            $stamps[] = $stamp;
        }
        foreach (['year' => '/^\d{4}[-.\/](\d{1,2})[-.\/](\d{1,2})/', 'slash' => '/^(\d{1,2})\/(\d{1,2})\//'] as $kind => $pattern) {
            if ($forced[$kind] === null && preg_match($pattern, $stamp, $m)) {
                $forced[$kind] = (int)$m[1] > 12 ? true : ((int)$m[2] > 12 ? false : null);
            }
        }
    }
    $unclear = ['error' => 'Z dat nejde poznat, jestli je v datu napřed měsíc, nebo den. Vyber formát data a nahraj soubor znovu.', 'date_order' => true];
    $dayFirst = false;
    $yearDayMonth = false;
    if (preg_match('/^\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2}/', $sample)) {
        $choice = match ($dateOrder) {
            'ydm' => true,
            'ymd' => false,
            default => $forced['year'] ?? hs_date_order($stamps, static fn(string $stamp, bool $swap): ?int => hs_parse_datetime($stamp, $zone, false, $swap)),
        };
        if ($choice === null) {
            fclose($handle);
            json_response($unclear, 422);
        }
        $yearDayMonth = $choice;
    } elseif (preg_match('/^\d{1,2}\/\d{1,2}\//', $sample)) {
        $choice = match ($dateOrder) {
            'dmy' => true,
            'mdy' => false,
            default => $forced['slash'] ?? hs_date_order($stamps, static fn(string $stamp, bool $swap): ?int => hs_parse_datetime($stamp, $zone, $swap)),
        };
        if ($choice === null) {
            fclose($handle);
            json_response($unclear, 422);
        }
        $dayFirst = $choice;
    }
    unset($stamps);

    // 2. průchod: svíčky do 5m.
    $buckets = [];
    $skipped = 0;
    $read = 0;
    foreach (hs_csv_rows($handle, $delimiter, $hasHeader) as $row) {
        $ts = hs_parse_datetime(hs_row_stamp($row, $map), $zone, $dayFirst, $yearDayMonth);
        $open = hs_parse_number((string)($row[$map['open']] ?? ''), $delimiter);
        $high = hs_parse_number((string)($row[$map['high']] ?? ''), $delimiter);
        $low = hs_parse_number((string)($row[$map['low']] ?? ''), $delimiter);
        $close = hs_parse_number((string)($row[$map['close']] ?? ''), $delimiter);
        $volume = isset($map['volume']) && $map['volume'] !== null ? hs_parse_number((string)($row[$map['volume']] ?? ''), $delimiter) : 0.0;
        if ($ts === null || $open === null || $high === null || $low === null || $close === null || $high < $low || $open <= 0) {
            $skipped++;
            continue;
        }
        $read++;
        $bucket = intdiv($ts, HS_BAR_SECONDS) * HS_BAR_SECONDS;
        if (!isset($buckets[$bucket])) {
            $buckets[$bucket] = [$ts, $open, $high, $low, $close, (float)($volume ?? 0), $ts];
            continue;
        }
        $current = &$buckets[$bucket];
        if ($ts < $current[0]) {
            $current[0] = $ts;
            $current[1] = $open;
        }
        if ($ts >= $current[6]) {
            $current[6] = $ts;
            $current[4] = $close;
        }
        $current[2] = max($current[2], $high);
        $current[3] = min($current[3], $low);
        $current[5] += (float)($volume ?? 0);
        unset($current);
    }
    fclose($handle);
    if ($buckets === []) {
        json_response(['error' => 'V souboru není žádná použitelná svíčka. Zkontroluj formát data a čísel.', 'skipped' => $skipped], 422);
    }
    ksort($buckets);

    // Jen období vybraného kontraktu. Spojitý export (continuous) má před rollem
    // svíčky starého kontraktu, často i s posunutými cenami, ty se neukládají.
    $outside = [];
    foreach (array_keys($buckets) as $bucket) {
        if ($bucket < $period['from_ts'] || $bucket >= $period['to_ts']) {
            $other = hs_contract_at($bucket)['contract'];
            $outside[$other] = ($outside[$other] ?? 0) + 1;
            unset($buckets[$bucket]);
        }
    }
    $outsideList = [];
    foreach ($outside as $name => $count) {
        $outsideList[] = ['contract' => $name, 'bars' => $count] + array_intersect_key((array)hs_contract_info($name), ['from_date' => 1, 'last_date' => 1]);
    }
    usort($outsideList, static fn(array $a, array $b): int => strcmp((string)($a['from_date'] ?? ''), (string)($b['from_date'] ?? '')));
    if ($buckets === []) {
        json_response([
            'error' => sprintf('V souboru není žádná svíčka z období kontraktu %s (%s až %s). Vybral jsi správný kontrakt?', $contract, hs_czech_date($period['from_date']), hs_czech_date($period['last_date'])),
            'outside' => $outsideList,
        ], 422);
    }

    $pdo = hs_db();
    $pdo->beginTransaction();
    $existing = $pdo->prepare('SELECT COUNT(*) FROM bars WHERE contract = ? AND ts BETWEEN ? AND ?');
    $existing->execute([$contract, array_key_first($buckets), array_key_last($buckets)]);
    $before = (int)$existing->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO bars (contract, ts, open, high, low, close, volume) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(contract, ts) DO UPDATE SET open = excluded.open, high = excluded.high, low = excluded.low, close = excluded.close, volume = excluded.volume');
    foreach ($buckets as $bucket => $bar) {
        $insert->execute([$contract, $bucket, $bar[1], $bar[2], $bar[3], $bar[4], $bar[5]]);
    }
    $pdo->prepare('INSERT INTO contracts (contract, symbol, expiry, demo, source, imported_at) VALUES (?, ?, ?, 0, ?, ?) ON CONFLICT(contract) DO UPDATE SET expiry = excluded.expiry, source = excluded.source, imported_at = excluded.imported_at')
        ->execute([$contract, 'ES', $period['expiry'], $source, utc_now()]);
    $pdo->commit();
    $existing->execute([$contract, array_key_first($buckets), array_key_last($buckets)]);
    $after = (int)$existing->fetchColumn();
    return [
        'contract' => $contract,
        'rows' => $read,
        'skipped' => $skipped,
        'bars' => count($buckets),
        'new' => $after - $before,
        'updated' => count($buckets) - ($after - $before),
        'from_ts' => array_key_first($buckets),
        'to_ts' => array_key_last($buckets),
        'expiry' => $period['expiry'],
        'period' => ['from_date' => $period['from_date'], 'last_date' => $period['last_date']],
        'outside' => $outsideList,
    ];
}

function hs_czech_date(string $date): string
{
    return (int)substr($date, 8, 2) . '. ' . (int)substr($date, 5, 2) . '. ' . substr($date, 0, 4);
}

/**
 * Ukázková (vymyšlená) data, ať jde modul vyzkoušet bez exportu z ATAS.
 * Kontrakt UKAZKA jde jedním kliknutím smazat.
 */
function hs_generate_demo(int $days = 70): array
{
    $pdo = hs_db();
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM bars WHERE contract = 'UKAZKA'");
    $pdo->prepare("INSERT INTO contracts (contract, symbol, expiry, demo, source, imported_at) VALUES ('UKAZKA', 'ES', NULL, 1, 'ukázka', ?) ON CONFLICT(contract) DO UPDATE SET imported_at = excluded.imported_at")->execute([utc_now()]);
    $insert = $pdo->prepare('INSERT INTO bars (contract, ts, open, high, low, close, volume) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ny = new DateTimeZone('America/New_York');
    mt_srand(20260928);
    $price = 5650.0;
    $date = new DateTimeImmutable('today', $ny);
    $tradeDates = [];
    while (count($tradeDates) < $days) {
        if ((int)$date->format('N') <= 5) {
            $tradeDates[] = $date->format('Y-m-d');
        }
        $date = $date->modify('-1 day');
    }
    sort($tradeDates);
    $count = 0;
    foreach ($tradeDates as $tradeDate) {
        $start = hs_trade_day_start($tradeDate);
        $drift = (mt_rand(-100, 100) / 100) * 0.35;
        for ($ts = $start; $ts < $start + 23 * 3600; $ts += HS_BAR_SECONDS) {
            if ($ts > time()) {
                break;
            }
            $local = (new DateTimeImmutable('@' . $ts))->setTimezone($ny);
            $minutes = (int)$local->format('G') * 60 + (int)$local->format('i');
            $rth = $minutes >= 570 && $minutes < 960;
            $sigma = $rth ? ($minutes < 630 ? 3.2 : 1.9) : 0.9;
            $open = $price;
            $close = round(($open + $drift + (mt_rand(-1000, 1000) / 1000) * $sigma * 1.6) * 4) / 4;
            $high = round((max($open, $close) + mt_rand(0, 100) / 100 * $sigma) * 4) / 4;
            $low = round((min($open, $close) - mt_rand(0, 100) / 100 * $sigma) * 4) / 4;
            $volume = (float)(($rth ? 9000 : 1500) + mt_rand(0, $rth ? 9000 : 2500));
            $insert->execute(['UKAZKA', $ts, $open, $high, $low, $close, $volume]);
            $price = $close;
            $count++;
        }
    }
    $pdo->commit();
    return ['contract' => 'UKAZKA', 'bars' => $count, 'days' => count($tradeDates)];
}

function hs_delete_contract(string $contract): int
{
    $pdo = hs_db();
    $pdo->beginTransaction();
    $statement = $pdo->prepare('DELETE FROM bars WHERE contract = ?');
    $statement->execute([$contract]);
    $removed = $statement->rowCount();
    $pdo->prepare('DELETE FROM contracts WHERE contract = ?')->execute([$contract]);
    $pdo->commit();
    return $removed;
}

/* ---------------------------------------------------------------- náhledy z deníku */

function hs_date(mixed $value, string $what = 'Datum'): string
{
    $text = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) || !checkdate((int)substr($text, 5, 2), (int)substr($text, 8, 2), (int)substr($text, 0, 4))) {
        json_response(['error' => $what . ' musí být ve tvaru RRRR-MM-DD.'], 422);
    }
    return $text;
}

/** Čas news z kalendáře (text „14:30“ v pražském čase) převedený na UTC. */
function hs_event_ts(string $date, string $label): ?int
{
    if (!preg_match('/(\d{1,2})[:.](\d{2})/', $label, $m) || (int)$m[1] > 23 || (int)$m[2] > 59) {
        return null;
    }
    $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', sprintf('%s %02d:%02d', $date, (int)$m[1], (int)$m[2]), new DateTimeZone('Europe/Prague'));
    return $moment instanceof DateTimeImmutable ? $moment->getTimestamp() : null;
}

/** Zóny, bias, red news a souhrn obchodů pro rozsah dnů jedním voláním. */
function hs_annotations(string $from, string $to): array
{
    $markets = implode(',', array_fill(0, count(HS_PLAN_MARKETS), '?'));
    $days = [];
    foreach (fetch_all("SELECT id, plan_date, market, bias, bias_description, updated_at FROM plans WHERE plan_type = 'daily' AND market IN ($markets) AND plan_date BETWEEN ? AND ? ORDER BY market = 'ES' DESC, session = 'intraday' DESC, id", [...HS_PLAN_MARKETS, $from, $to]) as $row) {
        $date = (string)$row['plan_date'];
        if (isset($days[$date])) {
            continue;
        }
        $days[$date] = ['date' => $date, 'plan_id' => (int)$row['id'], 'bias' => (string)$row['bias'], 'bias_note' => (string)($row['bias_description'] ?? ''), 'updated_at' => (string)$row['updated_at']];
    }
    foreach (fetch_all('SELECT trade_date, COUNT(*) AS trades, COALESCE(SUM(result_usd), 0) AS usd, COALESCE(SUM(result_r), 0) AS r FROM trades WHERE trade_date BETWEEN ? AND ? GROUP BY trade_date', [$from, $to]) as $row) {
        $date = (string)$row['trade_date'];
        $days[$date] ??= ['date' => $date, 'plan_id' => null, 'bias' => '', 'bias_note' => ''];
        $days[$date]['trades'] = (int)$row['trades'];
        $days[$date]['pnl'] = round((float)$row['usd'], 2);
        $days[$date]['r'] = round((float)$row['r'], 2);
    }

    // Zóna platí jen svůj den (starší náhledy), do data, nebo dokud se neukončí („open“).
    $zones = [];
    foreach (fetch_all("SELECT z.*, p.plan_date FROM zones z JOIN plans p ON p.id = z.plan_id WHERE p.plan_type = 'daily' AND p.market IN ($markets) AND p.plan_date <= ? AND (z.price_low IS NOT NULL AND z.price_high IS NOT NULL) AND ((COALESCE(z.valid_to, '') = '' AND p.plan_date >= ?) OR z.valid_to = 'open' OR (z.valid_to GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' AND z.valid_to >= ?)) ORDER BY p.plan_date, z.sort_order", [...HS_PLAN_MARKETS, $to, $from, $from]) as $row) {
        $zones[] = [
            'id' => (int)$row['id'],
            'plan_id' => (int)$row['plan_id'],
            'valid_from' => (string)$row['plan_date'],
            'valid_to' => (string)($row['valid_to'] ?? ''),
            'price_low' => (float)$row['price_low'],
            'price_high' => (float)$row['price_high'],
            'type' => hs_zone_type($row),
            'name' => (string)($row['name'] ?? ''),
            'note' => (string)($row['note'] ?? ''),
            'direction' => (string)($row['direction'] ?? ''),
            'priority' => (string)($row['priority'] ?? ''),
        ];
    }

    $news = [];
    foreach (fetch_all("SELECT id, event_date, title, time_label, impact FROM calendar_events WHERE kind = 'news' AND impact IN ('high', '') AND event_date BETWEEN ? AND ? ORDER BY event_date, time_label", [$from, $to]) as $row) {
        $ts = hs_event_ts((string)$row['event_date'], (string)($row['time_label'] ?? ''));
        if ($ts !== null) {
            $news[] = ['id' => (int)$row['id'], 'ts' => $ts, 'title' => (string)$row['title'], 'date' => (string)$row['event_date'], 'time' => (string)$row['time_label']];
        }
    }
    return ['from' => $from, 'to' => $to, 'days' => array_values($days), 'zones' => $zones, 'news' => $news, 'ideas' => hs_ideas($from, $to), 'trades' => hs_trades($from, $to)];
}

/** Potenciální obchody = scénáře denního náhledu ES/MES se vstupem, stopem a cílem. */
function hs_ideas(string $from, string $to): array
{
    $markets = implode(',', array_fill(0, count(HS_PLAN_MARKETS), '?'));
    $ideas = [];
    foreach (fetch_all("SELECT i.*, p.plan_date, t.id AS linked_trade FROM ideas i JOIN plans p ON p.id = i.plan_id LEFT JOIN trades t ON t.id = i.trade_id WHERE p.plan_type = 'daily' AND p.market IN ($markets) AND p.plan_date BETWEEN ? AND ? AND i.direction IN ('long', 'short') AND i.entry_price IS NOT NULL AND i.stop_loss IS NOT NULL AND COALESCE(i.tp1, i.tp2, i.final_tp) IS NOT NULL ORDER BY p.plan_date, i.entry_ts, i.sort_order", [...HS_PLAN_MARKETS, $from, $to]) as $row) {
        $ideas[] = [
            'id' => (int)$row['id'],
            'plan_id' => (int)$row['plan_id'],
            'date' => (string)$row['plan_date'],
            'direction' => (string)$row['direction'],
            'name' => (string)($row['name'] ?? ''),
            'notes' => (string)($row['notes'] ?? ''),
            'entry' => (float)$row['entry_price'],
            'stop' => (float)$row['stop_loss'],
            'target' => (float)($row['tp1'] ?? $row['tp2'] ?? $row['final_tp']),
            'tp2' => $row['tp2'] !== null ? (float)$row['tp2'] : null,
            'final_tp' => $row['final_tp'] !== null ? (float)$row['final_tp'] : null,
            'entry_ts' => $row['entry_ts'] !== null ? (int)$row['entry_ts'] : null,
            'outcome' => in_array((string)($row['outcome'] ?? ''), HS_OUTCOMES, true) ? (string)$row['outcome'] : '',
            'trade_id' => $row['linked_trade'] !== null ? (int)$row['linked_trade'] : null,
            'status' => (string)($row['status'] ?? ''),
        ];
    }
    return $ideas;
}

/** Realizované obchody ES/MES z deníku (s časy, pokud je mají). */
function hs_trades(string $from, string $to): array
{
    $markets = implode(',', array_fill(0, count(HS_PLAN_MARKETS), '?'));
    $trades = [];
    foreach (fetch_all("SELECT id, trade_date, market, direction, entry_price, exit_price, stop_loss, target_price, quantity, result_r, result_usd, entry_ts, exit_ts, strategy FROM trades WHERE market IN ($markets) AND trade_date BETWEEN ? AND ? ORDER BY trade_date, entry_ts, id", [...HS_PLAN_MARKETS, $from, $to]) as $row) {
        $trades[] = hs_trade_times([
            'id' => (int)$row['id'],
            'date' => (string)$row['trade_date'],
            'market' => (string)$row['market'],
            'direction' => (string)$row['direction'],
            'entry' => $row['entry_price'] !== null ? (float)$row['entry_price'] : null,
            'exit' => $row['exit_price'] !== null ? (float)$row['exit_price'] : null,
            'stop' => $row['stop_loss'] !== null ? (float)$row['stop_loss'] : null,
            'target' => $row['target_price'] !== null ? (float)$row['target_price'] : null,
            'quantity' => (float)$row['quantity'],
            'result_r' => $row['result_r'] !== null ? round((float)$row['result_r'], 2) : null,
            'result_usd' => $row['result_usd'] !== null ? round((float)$row['result_usd'], 2) : null,
            'entry_ts' => $row['entry_ts'] !== null ? (int)$row['entry_ts'] : null,
            'exit_ts' => $row['exit_ts'] !== null ? (int)$row['exit_ts'] : null,
            'strategy' => (string)($row['strategy'] ?? ''),
        ]);
    }
    return $trades;
}

/* ---------------------------------------------------------------- časy obchodů */

/** Okamžik v UTC sekundách z grafu; mimo rozumný rozsah = žádný. */
function hs_entry_ts(mixed $value): ?int
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    $ts = (int)$value;
    return $ts >= 946684800 && $ts <= time() + 400 * 86400 ? $ts : null;
}

/** Čas vstupu scénáře, jen když patří do obchodního dne náhledu (jinak se zahodí). */
function hs_entry_ts_in_day(mixed $value, string $date): ?int
{
    $ts = hs_entry_ts($value);
    if ($ts === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    return $ts >= hs_trade_day_start($date) && $ts < hs_trade_day_start(hs_add_days($date, 1)) ? $ts : null;
}

/**
 * Čas „HH:MM“ v pražském čase v obchodním dni ES na UTC. Obchodní den začíná
 * v 18:00 New York den předem, v Praze tedy o půlnoci, v týdnech s rozdílným
 * letním časem už ve 23:00 předchozího dne: taková večerní hodina patří k němu.
 */
function hs_trade_time_ts(string $date, string $time): ?int
{
    if (!preg_match('/^(\d{1,2})[:.](\d{2})$/', trim($time), $m) || (int)$m[1] > 23 || (int)$m[2] > 59) {
        return null;
    }
    $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', sprintf('%s %02d:%02d', $date, (int)$m[1], (int)$m[2]), new DateTimeZone('Europe/Prague'));
    if (!$moment instanceof DateTimeImmutable) {
        return null;
    }
    $ts = $moment->getTimestamp();
    $dayEnd = hs_trade_day_start(hs_add_days($date, 1)) - 3600;
    if ($ts >= $dayEnd) {
        $evening = $moment->modify('-1 day')->getTimestamp();
        if ($evening >= hs_trade_day_start($date)) {
            return $evening;
        }
    }
    return $ts;
}

/** UTC → „HH:MM“ v pražském čase. */
function hs_ts_time(?int $ts): string
{
    return $ts === null ? '' : (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Prague'))->format('H:i');
}

/** Čas z formuláře obchodu; nezměněný čas nechá přesný okamžik (třeba z importu) beze změny. */
function hs_trade_time_field(?int $id, string $column, string $date, string $time): ?int
{
    $time = trim($time);
    if ($time === '') {
        return null;
    }
    if ($id !== null) {
        $stored = fetch_one("SELECT trade_date, $column AS ts FROM trades WHERE id = ?", [$id]);
        if ($stored !== null && $stored['ts'] !== null && (string)$stored['trade_date'] === $date && hs_ts_time((int)$stored['ts']) === $time) {
            return (int)$stored['ts'];
        }
    }
    $ts = hs_trade_time_ts($date, $time);
    if ($ts === null) {
        json_response(['error' => 'Čas obchodu zadej jako HH:MM (pražský čas).'], 422);
    }
    return $ts;
}

/** K obchodu doplní časy vstupu a výstupu jako „HH:MM“ (pro formulář). */
function hs_trade_times(array $trade): array
{
    if ($trade === []) {
        return $trade;
    }
    $trade['entry_time'] = hs_ts_time(isset($trade['entry_ts']) && $trade['entry_ts'] !== null ? (int)$trade['entry_ts'] : null);
    $trade['exit_time'] = hs_ts_time(isset($trade['exit_ts']) && $trade['exit_ts'] !== null ? (int)$trade['exit_ts'] : null);
    return $trade;
}

/** Časy obchodu zadané v Hindsightu. */
function hs_save_trade_times(array $data): array
{
    $id = (int)($data['id'] ?? 0);
    $trade = fetch_one('SELECT id, trade_date FROM trades WHERE id = ?', [$id]);
    if ($trade === null) {
        json_response(['error' => 'Obchod už neexistuje, načti graf znovu.'], 404);
    }
    $date = (string)$trade['trade_date'];
    $entry = hs_trade_time_field($id, 'entry_ts', $date, (string)($data['entry_time'] ?? ''));
    $exit = hs_trade_time_field($id, 'exit_ts', $date, (string)($data['exit_time'] ?? ''));
    if ($entry !== null && $exit !== null && $exit < $entry) {
        json_response(['error' => 'Výstup nemůže být dřív než vstup.'], 422);
    }
    db()->prepare('UPDATE trades SET entry_ts = ?, exit_ts = ?, updated_at = ? WHERE id = ?')->execute([$entry, $exit, utc_now(), $id]);
    return hs_trade_times(['id' => $id, 'entry_ts' => $entry, 'exit_ts' => $exit]);
}

/* ---------------------------------------------------------------- potenciální obchody */

/** Scénář z denního náhledu ES/MES; jiné scénáře Hindsight nemění. */
function hs_idea_row(int $id): ?array
{
    $markets = implode(',', array_fill(0, count(HS_PLAN_MARKETS), '?'));
    return fetch_one("SELECT i.id, i.status, p.plan_date FROM ideas i JOIN plans p ON p.id = i.plan_id WHERE i.id = ? AND p.plan_type = 'daily' AND p.market IN ($markets)", [$id, ...HS_PLAN_MARKETS]);
}

function hs_idea_fields(array $data, string $date, string $status): array
{
    $direction = (string)($data['direction'] ?? '');
    if (!in_array($direction, ['long', 'short'], true)) {
        json_response(['error' => 'Potenciální obchod je long, nebo short.'], 422);
    }
    $entry = nullable_float($data['entry'] ?? null);
    $stop = nullable_float($data['stop'] ?? null);
    $target = nullable_float($data['target'] ?? null);
    if ($entry === null || $stop === null || $target === null || $entry <= 0 || $stop <= 0 || $target <= 0) {
        json_response(['error' => 'Zadej vstup, stop loss i cíl.'], 422);
    }
    $valid = $direction === 'long' ? $stop < $entry && $entry < $target : $target < $entry && $entry < $stop;
    if (!$valid) {
        json_response(['error' => $direction === 'long' ? 'U longu musí být stop loss pod vstupem a cíl nad ním.' : 'U shortu musí být stop loss nad vstupem a cíl pod ním.'], 422);
    }
    $entryTs = hs_entry_ts($data['entry_ts'] ?? null);
    if ($entryTs !== null && ($entryTs < hs_trade_day_start($date) || $entryTs >= hs_trade_day_start(hs_add_days($date, 1)))) {
        json_response(['error' => 'Čas vstupu nepatří do obchodního dne ' . hs_czech_date($date) . '.'], 422);
    }
    $outcome = in_array((string)($data['outcome'] ?? ''), HS_OUTCOMES, true) ? (string)$data['outcome'] : '';
    $tradeId = nullable_int($data['trade_id'] ?? null);
    if ($tradeId !== null && fetch_one('SELECT id FROM trades WHERE id = ?', [$tradeId]) === null) {
        json_response(['error' => 'Propojený obchod v deníku neexistuje.'], 422);
    }
    return [
        'direction' => $direction,
        'entry_price' => $entry,
        'stop_loss' => $stop,
        'tp1' => $target,
        'rr' => round(abs($target - $entry) / abs($entry - $stop), 2),
        'name' => mb_substr(trim((string)($data['name'] ?? '')), 0, 80),
        'notes' => mb_substr(trim((string)($data['notes'] ?? '')), 0, 1000),
        'entry_ts' => $entryTs,
        'outcome' => $outcome,
        'trade_id' => $tradeId,
        // Vzatý obchod je v náhledu „Realizovaný“; jinak zůstane stav z náhledu.
        'status' => $outcome === 'taken' ? 'executed' : ($status === 'executed' || $status === '' ? 'waiting' : $status),
    ];
}

function hs_save_idea(array $data): array
{
    $id = (int)($data['id'] ?? 0);
    if ($id > 0) {
        $row = hs_idea_row($id);
        if ($row === null) {
            json_response(['error' => 'Potenciální obchod už neexistuje, načti graf znovu.'], 404);
        }
        $fields = hs_idea_fields($data, (string)$row['plan_date'], (string)$row['status']);
        // Úprava mění jen poslaná pole; co v požadavku chybí, zůstane uložené.
        foreach (['entry_ts' => 'entry_ts', 'name' => 'name', 'notes' => 'notes', 'trade_id' => 'trade_id', 'outcome' => 'outcome', 'status' => 'outcome'] as $column => $key) {
            if (!array_key_exists($key, $data)) {
                unset($fields[$column]);
            }
        }
        $sets = implode(', ', array_map(static fn(string $column): string => "$column = ?", array_keys($fields)));
        db()->prepare("UPDATE ideas SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
        db()->prepare('UPDATE plans SET updated_at = ? WHERE id = (SELECT plan_id FROM ideas WHERE id = ?)')->execute([utc_now(), $id]);
        return ['id' => $id];
    }
    $date = hs_date($data['date'] ?? '');
    $fields = hs_idea_fields($data, $date, '');
    $planId = hs_plan_for($date);
    $order = (int)(fetch_one('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM ideas WHERE plan_id = ?', [$planId])['next'] ?? 0);
    $columns = ['plan_id', 'sort_order', ...array_keys($fields)];
    db()->prepare('INSERT INTO ideas (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
        ->execute([$planId, $order, ...array_values($fields)]);
    $newId = (int)db()->lastInsertId();
    db()->prepare('UPDATE plans SET updated_at = ? WHERE id = ?')->execute([utc_now(), $planId]);
    return ['id' => $newId, 'plan_id' => $planId];
}

function hs_delete_idea(int $id): void
{
    if (hs_idea_row($id) === null) {
        json_response(['error' => 'Potenciální obchod už neexistuje, načti graf znovu.'], 404);
    }
    db()->prepare('DELETE FROM ideas WHERE id = ?')->execute([$id]);
}

function hs_zone_type(array $row): string
{
    $type = (string)($row['zone_type'] ?? '');
    if (in_array($type, HS_ZONE_TYPES, true)) {
        return $type;
    }
    return match ((string)($row['direction'] ?? '')) {
        'long' => 'support',
        'short' => 'resistance',
        default => 'other',
    };
}

/** Denní náhled ES pro datum; když ještě není, založí se prázdný (koncept). */
function hs_plan_for(string $date): int
{
    $markets = implode(',', array_fill(0, count(HS_PLAN_MARKETS), '?'));
    $plan = fetch_one("SELECT id FROM plans WHERE plan_type = 'daily' AND plan_date = ? AND market IN ($markets) ORDER BY market = 'ES' DESC, session = 'intraday' DESC, id LIMIT 1", [$date, ...HS_PLAN_MARKETS]);
    if ($plan !== null) {
        return (int)$plan['id'];
    }
    $now = utc_now();
    // Bias zůstane nezadaný (''), dokud ho trader nezvolí; náhled ho ukáže jako Balance.
    db()->prepare("INSERT INTO plans (plan_type, plan_date, market, session, status, bias, created_at, updated_at) VALUES ('daily', ?, 'ES', 'intraday', 'draft', '', ?, ?)")->execute([$date, $now, $now]);
    return (int)db()->lastInsertId();
}

function hs_valid_to(mixed $value, string $from): string
{
    $text = trim((string)($value ?? ''));
    if ($text === '' || $text === 'open') {
        return $text;
    }
    $date = hs_date($text, 'Platnost do');
    if ($date < $from) {
        json_response(['error' => 'Zóna nemůže končit dřív, než začne platit.'], 422);
    }
    return $date;
}

function hs_zone_fields(array $data, string $from): array
{
    $low = nullable_float($data['price_low'] ?? null);
    $high = nullable_float($data['price_high'] ?? null);
    if ($low === null || $high === null || $low <= 0 || $high <= 0) {
        json_response(['error' => 'Zóna potřebuje spodní i horní cenu.'], 422);
    }
    if ($low > $high) {
        [$low, $high] = [$high, $low];
    }
    $type = in_array((string)($data['type'] ?? ''), HS_ZONE_TYPES, true) ? (string)$data['type'] : 'other';
    return [
        'price_low' => $low,
        'price_high' => $high,
        'zone_type' => $type,
        'direction' => match ($type) { 'support' => 'long', 'resistance' => 'short', default => 'both' },
        'name' => mb_substr(trim((string)($data['name'] ?? '')), 0, 80),
        'note' => mb_substr(trim((string)($data['note'] ?? '')), 0, 1000),
        'valid_to' => hs_valid_to($data['valid_to'] ?? '', $from),
    ];
}

function hs_save_zone(array $data): array
{
    $id = (int)($data['id'] ?? 0);
    if ($id > 0) {
        $row = hs_zone_row($id);
        if ($row === null) {
            json_response(['error' => 'Zóna už neexistuje, načti graf znovu.'], 404);
        }
        $fields = hs_zone_fields($data, (string)$row['plan_date']);
        if (trim((string)($row['direction'] ?? '')) !== '') {
            // Směr obchodu (a podmínky vstupu) nastavený v náhledu zůstane beze změny.
            unset($fields['direction']);
        }
        $sets = implode(', ', array_map(static fn(string $column): string => "$column = ?", array_keys($fields)));
        db()->prepare("UPDATE zones SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
        db()->prepare('UPDATE plans SET updated_at = ? WHERE id = (SELECT plan_id FROM zones WHERE id = ?)')->execute([utc_now(), $id]);
        return ['id' => $id];
    }
    $date = hs_date($data['date'] ?? '');
    $fields = hs_zone_fields($data, $date);
    $planId = hs_plan_for($date);
    $order = (int)(fetch_one('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM zones WHERE plan_id = ?', [$planId])['next'] ?? 0);
    $columns = ['plan_id', 'sort_order', 'status', ...array_keys($fields)];
    $values = [$planId, $order, 'planned', ...array_values($fields)];
    db()->prepare('INSERT INTO zones (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute($values);
    $newId = (int)db()->lastInsertId();
    db()->prepare('UPDATE plans SET updated_at = ? WHERE id = ?')->execute([utc_now(), $planId]);
    return ['id' => $newId, 'plan_id' => $planId];
}

/** Zóna z denního náhledu ES/MES; jiné zóny (týdenní náhled, jiné trhy) Hindsight nemění. */
function hs_zone_row(int $id): ?array
{
    $markets = implode(',', array_fill(0, count(HS_PLAN_MARKETS), '?'));
    return fetch_one("SELECT z.id, z.direction, p.plan_date FROM zones z JOIN plans p ON p.id = z.plan_id WHERE z.id = ? AND p.plan_type = 'daily' AND p.market IN ($markets)", [$id, ...HS_PLAN_MARKETS]);
}

function hs_delete_zone(int $id): void
{
    if (hs_zone_row($id) === null) {
        json_response(['error' => 'Zóna už neexistuje, načti graf znovu.'], 404);
    }
    db()->prepare('DELETE FROM zones WHERE id = ?')->execute([$id]);
}

function hs_save_bias(array $data): array
{
    $date = hs_date($data['date'] ?? '');
    $bias = (string)($data['bias'] ?? '');
    if (!in_array($bias, ['long', 'short', 'neutral'], true)) {
        json_response(['error' => 'Bias je long, short nebo neutral.'], 422);
    }
    $planId = hs_plan_for($date);
    db()->prepare('UPDATE plans SET bias = ?, bias_description = ?, updated_at = ? WHERE id = ?')
        ->execute([$bias, mb_substr(trim((string)($data['note'] ?? '')), 0, 1000), utc_now(), $planId]);
    return ['plan_id' => $planId, 'date' => $date, 'bias' => $bias];
}

/* ---------------------------------------------------------------- osobní nastavení grafu */

const HS_LAYERS = ['sessions', 'news', 'zones', 'bias', 'ideas', 'trades', 'volume'];

function hs_prefs(): array
{
    db()->exec('CREATE TABLE IF NOT EXISTS hindsight_prefs (id INTEGER PRIMARY KEY CHECK (id = 1), data TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $row = fetch_one('SELECT data FROM hindsight_prefs WHERE id = 1');
    $data = $row !== null ? json_decode((string)$row['data'], true) : null;
    return hs_clean_prefs(is_array($data) ? $data : []);
}

function hs_clean_prefs(array $data): array
{
    $layers = [];
    foreach (HS_LAYERS as $layer) {
        $layers[$layer] = !is_array($data['layers'] ?? null) || !array_key_exists($layer, $data['layers']) ? true : (bool)$data['layers'][$layer];
    }
    return ['layers' => $layers, 'snap' => (bool)($data['snap'] ?? false)];
}

function hs_save_prefs(array $data): array
{
    $clean = hs_clean_prefs($data);
    hs_prefs();
    db()->prepare('INSERT INTO hindsight_prefs (id, data, updated_at) VALUES (1, ?, ?) ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at')
        ->execute([json_encode($clean), utc_now()]);
    return $clean;
}

/** Rozsah dnů z dotazu (RRRR-MM-DD), nejvýš HS_MAX_RANGE_DAYS. */
function hs_request_range(): array
{
    $from = hs_date($_GET['from'] ?? '', 'Začátek');
    $to = hs_date($_GET['to'] ?? '', 'Konec');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > HS_MAX_RANGE_DAYS) {
        json_response(['error' => 'Najednou jde načíst nejvýš ' . HS_MAX_RANGE_DAYS . ' dní.'], 422);
    }
    return [$from, $to];
}
