<?php
declare(strict_types=1);

/*
 * Tep během obchodování (Trading mode).
 *
 * Seance začíná přepnutím „Trading mode“ nahoře: rychlý test, dvě minuty klidového
 * měření (z něj je dnešní klidový tep) a pak průběžné sledování. Tep přichází dvěma cestami:
 *
 *  - živě z prohlížeče: standardní Bluetooth snímač tepu (hrudní pás, nebo aplikace, která
 *    přeposílá tep z Apple Watch), klient posílá průměry po 5 s (heart_samples);
 *  - zpětně ze Zkratky v iPhonu: po cvičení na Apple Watch pošle vzorky ze Zdraví na tajný
 *    odkaz hr.php. Šifrovaný deník server bez člena neotevře, proto se vzorky zapečetí
 *    veřejným klíčem člena (sodium sealed box) do schránky v app.sqlite3. Soukromý klíč je
 *    jen v deníku; při dalším otevření deníku se schránka rozbalí a vzorky se zařadí.
 *
 * Vyhodnocení páruje obchody (čas vstupu entry_ts) s tepem v posledních dvou minutách před
 * vstupem a porovnává chybové obchody (plán nedodržen nebo hodnocení 4–5) s čistými.
 * Upozornění během seance se zapnou až po HEART_LEARN_TRADES ohodnocených obchodech s tepem.
 */

const HEART_LEARN_TRADES = 15;
const HEART_PRE_SECONDS = 120;
const HEART_MIN_BPM = 25;
const HEART_MAX_BPM = 250;
const HEART_MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
const HEART_MAX_SAMPLES = 50000;
const HEART_MAX_LIVE_SAMPLES = 2000;
const HEART_CLUSTER_GAP = 1200;
const HEART_SESSION_SLACK = 600;
const HEART_INBOX_MAX_ITEMS = 60;
const HEART_INBOX_PER_HOUR = 30;
const HEART_STALE_SECONDS = 16 * 3600;
const HEART_SOURCES = ['ble', 'watch', 'none', 'import'];
const HEART_ALERT_KINDS = ['high', 'spike', 'hrv'];
const HEART_ALERT_ACTIONS = ['', 'breath', 'dismiss'];

// Tabulky hr_links a hr_inbox jsou ve schématu app.sqlite3 (initialize_app_schema v lib/accounts.php).

function ensure_heart_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS hr_sessions (
    id INTEGER PRIMARY KEY,
    started_ts INTEGER NOT NULL,
    ended_ts INTEGER,
    source TEXT NOT NULL DEFAULT 'none',
    device TEXT,
    check_id INTEGER,
    baseline_from INTEGER,
    baseline_to INTEGER,
    baseline_bpm REAL,
    baseline_rmssd REAL,
    baseline_kind TEXT,
    note TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
-- Tep po 5 s: b10 = tep × 10, r10 = RMSSD × 10 (ms), celá čísla kvůli velikosti deníku.
CREATE TABLE IF NOT EXISTS hr_samples (
    session_id INTEGER NOT NULL REFERENCES hr_sessions(id) ON DELETE CASCADE,
    ts INTEGER NOT NULL,
    b10 INTEGER NOT NULL,
    r10 INTEGER,
    PRIMARY KEY (session_id, ts)
) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS hr_alerts (
    id INTEGER PRIMARY KEY,
    session_id INTEGER NOT NULL REFERENCES hr_sessions(id) ON DELETE CASCADE,
    ts INTEGER NOT NULL,
    kind TEXT NOT NULL,
    delta REAL,
    bpm REAL,
    action TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
-- Odkaz pro Zkratku: token a klíčový pár, kterým se otevírá zapečetěná schránka.
CREATE TABLE IF NOT EXISTS hr_link (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    token TEXT NOT NULL,
    keypair TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_hr_sessions_start ON hr_sessions(started_ts DESC);
CREATE INDEX IF NOT EXISTS idx_hr_alerts_session ON hr_alerts(session_id, ts);
SQL);
}

function heart_zone(): DateTimeZone
{
    static $zone = null;
    return $zone ??= new DateTimeZone('Europe/Prague');
}

function heart_local(int $ts, string $format = 'j. n. Y H:i'): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(heart_zone())->format($format);
}

/* ---------------------------------------------------------------- čtení vzorků */

/**
 * Čas ze Zkratky nebo z CSV: ISO 8601 (doporučený), unixový čas v s nebo ms, české
 * „13. 10. 2026 14:02“ i se slovem „v“, americké „10/13/2026, 2:02 PM“. Bez zóny = Praha.
 */
function heart_parse_time(string $value): ?int
{
    $text = trim($value, " \t\"'\u{00A0}");
    if ($text === '') {
        return null;
    }
    if (preg_match('/^\d{10}(\.\d+)?$/', $text)) {
        return (int)$text;
    }
    if (preg_match('/^\d{13}$/', $text)) {
        return intdiv((int)$text, 1000);
    }
    $text = (string)preg_replace('/\s+(v|ve|at|um)\s+/iu', ' ', $text);
    $text = (string)preg_replace('/\b(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\b/', '$3-$2-$1', $text);
    $text = str_replace([',', "\u{202F}", "\u{00A0}"], [' ', ' ', ' '], $text);
    $text = (string)preg_replace('/\s{2,}/', ' ', trim($text));
    if (!preg_match('/\d{4}|\d{1,2}\/\d{1,2}\/\d{2,4}/', $text) || !preg_match('/\d{1,2}:\d{2}/', $text)) {
        return null;
    }
    try {
        $time = new DateTimeImmutable($text, heart_zone());
    } catch (Throwable) {
        return null;
    }
    $year = (int)$time->format('Y');
    return $year >= 2000 && $year <= 2100 ? $time->getTimestamp() : null;
}

function heart_parse_bpm(string $value): ?float
{
    if (!preg_match('/^\s*"?(\d{2,3}(?:[.,]\d+)?)\s*(?:"|\s*[^\d\s;,].*)?$/u', $value, $match)) {
        return null;
    }
    $bpm = (float)str_replace(',', '.', $match[1]);
    return $bpm >= HEART_MIN_BPM && $bpm <= HEART_MAX_BPM ? $bpm : null;
}

/** Vzorky z JSON (Zkratka se slovníkem, export aplikací): hledá seznamy objektů s časem a hodnotou. */
function heart_json_samples(mixed $node, array &$out, int $depth = 0): void
{
    if ($depth > 6 || !is_array($node) || count($out) > HEART_MAX_SAMPLES) {
        return;
    }
    if (array_is_list($node)) {
        foreach ($node as $item) {
            if (is_array($item) && array_is_list($item) && count($item) >= 2 && is_scalar($item[0]) && is_scalar($item[1])) {
                $ts = is_numeric($item[0]) && $item[0] > 1e9 ? (int)($item[0] > 1e12 ? $item[0] / 1000 : $item[0]) : heart_parse_time((string)$item[0]);
                $bpm = heart_parse_bpm((string)$item[1]);
                if ($ts !== null && $bpm !== null) {
                    $out[] = [$ts, $bpm];
                }
                continue;
            }
            if (is_array($item) && !array_is_list($item)) {
                $time = null;
                foreach (['ts', 'timestamp', 'date', 'startDate', 'start', 'time', 'datum'] as $key) {
                    if (isset($item[$key]) && is_scalar($item[$key])) {
                        $time = is_numeric($item[$key]) && $item[$key] > 1e9 ? (int)($item[$key] > 1e12 ? $item[$key] / 1000 : $item[$key]) : heart_parse_time((string)$item[$key]);
                        break;
                    }
                }
                $bpm = null;
                foreach (['bpm', 'value', 'qty', 'Avg', 'avg', 'hodnota', 'heartRate'] as $key) {
                    if (isset($item[$key]) && is_scalar($item[$key])) {
                        $bpm = heart_parse_bpm((string)$item[$key]);
                        break;
                    }
                }
                if ($time !== null && $bpm !== null) {
                    $out[] = [$time, $bpm];
                    continue;
                }
            }
            heart_json_samples($item, $out, $depth + 1);
        }
        return;
    }
    foreach ($node as $child) {
        heart_json_samples($child, $out, $depth + 1);
    }
}

/**
 * Text se vzorky: řádek „čas;tep“ (středník, tabulátor, nebo čárka), hlavička a jiné řádky
 * se přeskočí. Vrací ['samples' => [[ts, bpm]...] seřazené, 'skipped' => počet nepoužitých řádků].
 */
function heart_parse_text(string $text): array
{
    $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $samples = [];
    $skipped = 0;
    $trimmed = ltrim($text);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            heart_json_samples($decoded, $samples);
            return ['samples' => heart_clean_samples($samples), 'skipped' => 0];
        }
    }
    foreach (preg_split('/\r\n|\r|\n|\x{2028}/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (count($samples) > HEART_MAX_SAMPLES) {
            break;
        }
        $pair = heart_parse_line($line);
        if ($pair === null) {
            $skipped++;
            continue;
        }
        $samples[] = $pair;
    }
    return ['samples' => heart_clean_samples($samples), 'skipped' => $skipped];
}

function heart_parse_line(string $line): ?array
{
    $fields = preg_match('/[;\t]/', $line) ? preg_split('/\s*[;\t]\s*/', $line) : preg_split('/\s*,\s*/', $line);
    $fields = array_map(static fn(string $field): string => trim($field, " \"'"), $fields ?: []);
    foreach ($fields as $index => $field) {
        $ts = heart_parse_time($field);
        if ($ts === null) {
            continue;
        }
        foreach (array_slice($fields, $index + 1) as $candidate) {
            $bpm = heart_parse_bpm($candidate);
            if ($bpm !== null) {
                return [$ts, $bpm];
            }
        }
        foreach (array_slice($fields, 0, $index) as $candidate) {
            $bpm = heart_parse_bpm($candidate);
            if ($bpm !== null) {
                return [$ts, $bpm];
            }
        }
        return null;
    }
    // Čárky uvnitř data („Oct 13, 2026, 2:02 PM,72“): číslo za poslední čárkou.
    if (preg_match('/^(.*\S)\s*[,;]\s*"?([0-9]{2,3}(?:\.[0-9]+)?)"?\s*$/', $line, $match)) {
        $ts = heart_parse_time($match[1]);
        $bpm = heart_parse_bpm($match[2]);
        if ($ts !== null && $bpm !== null) {
            return [$ts, $bpm];
        }
    }
    return null;
}

/** Seřadí, sloučí vzorky ve stejné sekundě a zahodí nesmysly. */
function heart_clean_samples(array $samples, ?int $now = null): array
{
    $now ??= time();
    $byTs = [];
    foreach ($samples as $sample) {
        if (!is_array($sample) || count($sample) < 2) {
            continue;
        }
        $ts = (int)$sample[0];
        $bpm = (float)$sample[1];
        if ($ts < $now - 400 * 86400 || $ts > $now + 3600 || $bpm < HEART_MIN_BPM || $bpm > HEART_MAX_BPM) {
            continue;
        }
        $byTs[$ts][] = $bpm;
    }
    ksort($byTs);
    $clean = [];
    foreach ($byTs as $ts => $values) {
        $clean[] = [$ts, round(array_sum($values) / count($values), 1)];
    }
    return array_slice($clean, 0, HEART_MAX_SAMPLES);
}

/* ---------------------------------------------------------------- seance */

function heart_session_row(int $id): array
{
    $row = fetch_one('SELECT * FROM hr_sessions WHERE id = ?', [$id]);
    if ($row === null) {
        throw new InvalidArgumentException('Seance už neexistuje.');
    }
    return $row;
}

function heart_active_session(): ?array
{
    $row = fetch_one('SELECT * FROM hr_sessions WHERE ended_ts IS NULL ORDER BY started_ts DESC LIMIT 1');
    if ($row === null) {
        return null;
    }
    // Zapomenutá seance (prohlížeč zavřený bez vypnutí) se ukončí posledním vzorkem.
    $last = (int)(fetch_one('SELECT MAX(ts) AS ts FROM hr_samples WHERE session_id = ?', [(int)$row['id']])['ts'] ?? 0);
    if (time() - max((int)$row['started_ts'], $last) > HEART_STALE_SECONDS) {
        heart_close_session((int)$row['id'], max((int)$row['started_ts'], $last));
        return null;
    }
    return $row;
}

function heart_start_session(array $data): array
{
    $source = (string)value($data, 'source', 'none');
    if (!in_array($source, HEART_SOURCES, true) || $source === 'import') {
        throw new InvalidArgumentException('Neznámý zdroj tepu.');
    }
    $device = mb_substr(trim((string)value($data, 'device', '')), 0, 80, 'UTF-8');
    $checkId = value($data, 'check_id');
    $checkId = is_numeric($checkId) && fetch_one('SELECT id FROM psych_checks WHERE id = ?', [(int)$checkId]) !== null ? (int)$checkId : null;
    $active = heart_active_session();
    if ($active !== null) {
        $last = (int)(fetch_one('SELECT MAX(ts) AS ts FROM hr_samples WHERE session_id = ?', [(int)$active['id']])['ts'] ?? 0);
        heart_close_session((int)$active['id'], max((int)$active['started_ts'], $last, time() - 1));
    }
    $now = utc_now();
    $pdo = db();
    $pdo->prepare('INSERT INTO hr_sessions (started_ts, source, device, check_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([time(), $source, $device === '' ? null : $device, $checkId, $now, $now]);
    return heart_session_summary(heart_session_row((int)$pdo->lastInsertId()));
}

/** Klidové měření: okno seance, ze kterého se počítá dnešní klidový tep. */
function heart_set_baseline(int $id, int $from, int $to): array
{
    $session = heart_session_row($id);
    $start = (int)$session['started_ts'];
    if ($to <= $from || $from < $start - 60 || $to > time() + 60 || $to - $from > 900) {
        throw new InvalidArgumentException('Klidové měření má neplatný čas.');
    }
    db()->prepare('UPDATE hr_sessions SET baseline_from = ?, baseline_to = ?, updated_at = ? WHERE id = ?')->execute([$from, $to, utc_now(), $id]);
    heart_compute_baseline($id);
    return heart_session_summary(heart_session_row($id));
}

function heart_set_source(int $id, string $source, string $device): array
{
    if (!in_array($source, ['ble', 'watch', 'none'], true)) {
        throw new InvalidArgumentException('Neznámý zdroj tepu.');
    }
    heart_session_row($id);
    db()->prepare('UPDATE hr_sessions SET source = ?, device = ?, updated_at = ? WHERE id = ?')
        ->execute([$source, mb_substr(trim($device), 0, 80, 'UTF-8') ?: null, utc_now(), $id]);
    return heart_session_summary(heart_session_row($id));
}

function heart_close_session(int $id, ?int $at = null): void
{
    db()->prepare('UPDATE hr_sessions SET ended_ts = ?, updated_at = ? WHERE id = ? AND ended_ts IS NULL')->execute([$at ?? time(), utc_now(), $id]);
    heart_compute_baseline($id);
}

function heart_end_session(int $id): array
{
    $session = heart_session_row($id);
    if ($session['ended_ts'] === null) {
        heart_close_session($id, max((int)$session['started_ts'], time()));
    }
    return heart_session_summary(heart_session_row($id));
}

function heart_delete_session(int $id): void
{
    heart_session_row($id);
    db()->prepare('DELETE FROM hr_sessions WHERE id = ?')->execute([$id]);
}

function heart_median(array $values): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);
    return $count % 2 ? (float)$values[$middle] : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
}

function heart_percentile(array $values, float $p): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values);
    $index = ($p / 100) * (count($values) - 1);
    $low = (int)floor($index);
    $high = (int)ceil($index);
    return (float)$values[$low] + ((float)$values[$high] - (float)$values[$low]) * ($index - $low);
}

/**
 * Klidový tep seance: medián z klidového měření (aspoň 6 vzorků), jinak 10. percentil celé
 * seance (nejklidnější část). Stejně klidová variabilita (RMSSD), když ji snímač posílá.
 */
function heart_compute_baseline(int $id): void
{
    $session = fetch_one('SELECT * FROM hr_sessions WHERE id = ?', [$id]);
    if ($session === null) {
        return;
    }
    $bpm = null;
    $rmssd = null;
    $kind = null;
    if ($session['baseline_from'] !== null && $session['baseline_to'] !== null) {
        $rows = fetch_all('SELECT b10, r10 FROM hr_samples WHERE session_id = ? AND ts BETWEEN ? AND ?', [$id, (int)$session['baseline_from'], (int)$session['baseline_to']]);
        if (count($rows) >= 6) {
            $bpm = heart_median(array_map(static fn(array $row): float => $row['b10'] / 10, $rows));
            $rr = array_values(array_filter(array_map(static fn(array $row): ?float => $row['r10'] === null ? null : $row['r10'] / 10, $rows), static fn($value): bool => $value !== null));
            $rmssd = count($rr) >= 6 ? heart_median($rr) : null;
            $kind = 'measured';
        }
    }
    if ($bpm === null) {
        $values = array_map(static fn(array $row): float => $row['b10'] / 10, fetch_all('SELECT b10 FROM hr_samples WHERE session_id = ?', [$id]));
        if (count($values) >= 12) {
            $bpm = heart_percentile($values, 10);
            $rr = array_map(static fn(array $row): float => $row['r10'] / 10, fetch_all('SELECT r10 FROM hr_samples WHERE session_id = ? AND r10 IS NOT NULL', [$id]));
            $rmssd = count($rr) >= 12 ? heart_percentile($rr, 90) : null;
            $kind = 'auto';
        }
    }
    db()->prepare('UPDATE hr_sessions SET baseline_bpm = ?, baseline_rmssd = ?, baseline_kind = ?, updated_at = ? WHERE id = ?')
        ->execute([$bpm === null ? null : round($bpm, 1), $rmssd === null ? null : round($rmssd, 1), $kind, utc_now(), $id]);
}

/** Živé vzorky z prohlížeče: [[ts, tep, rmssd|null], …] po 5 s. */
function heart_add_samples(int $id, array $samples): array
{
    $session = heart_session_row($id);
    if (count($samples) > HEART_MAX_LIVE_SAMPLES) {
        throw new InvalidArgumentException('Příliš mnoho vzorků najednou.');
    }
    $from = (int)$session['started_ts'] - HEART_SESSION_SLACK;
    $to = ($session['ended_ts'] === null ? time() : (int)$session['ended_ts']) + 60;
    $pdo = db();
    $statement = $pdo->prepare('INSERT OR REPLACE INTO hr_samples (session_id, ts, b10, r10) VALUES (?, ?, ?, ?)');
    $stored = 0;
    $pdo->beginTransaction();
    try {
        foreach ($samples as $sample) {
            if (!is_array($sample) || !isset($sample[0], $sample[1]) || !is_numeric($sample[0]) || !is_numeric($sample[1])) {
                continue;
            }
            $ts = (int)$sample[0];
            $bpm = (float)$sample[1];
            $rmssd = isset($sample[2]) && is_numeric($sample[2]) ? (float)$sample[2] : null;
            if ($ts < $from || $ts > $to || $bpm < HEART_MIN_BPM || $bpm > HEART_MAX_BPM) {
                continue;
            }
            if ($rmssd !== null && ($rmssd <= 0 || $rmssd > 400)) {
                $rmssd = null;
            }
            $statement->execute([$id, $ts, (int)round($bpm * 10), $rmssd === null ? null : (int)round($rmssd * 10)]);
            $stored++;
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    if ($session['baseline_bpm'] === null && $session['baseline_from'] !== null && time() >= (int)$session['baseline_to']) {
        heart_compute_baseline($id);
    }
    return ['stored' => $stored];
}

function heart_add_alert(array $data): array
{
    $actionValue = (string)value($data, 'action', '');
    if (!in_array($actionValue, HEART_ALERT_ACTIONS, true)) {
        throw new InvalidArgumentException('Neznámá reakce na upozornění.');
    }
    if (is_numeric($data['id'] ?? null)) {
        db()->prepare('UPDATE hr_alerts SET action = ? WHERE id = ?')->execute([$actionValue, (int)$data['id']]);
        return ['id' => (int)$data['id']];
    }
    $id = (int)value($data, 'session_id', 0);
    heart_session_row($id);
    $kind = (string)value($data, 'kind', '');
    if (!in_array($kind, HEART_ALERT_KINDS, true)) {
        throw new InvalidArgumentException('Neznámý druh upozornění.');
    }
    $ts = (int)value($data, 'ts', time());
    $pdo = db();
    $pdo->prepare('INSERT INTO hr_alerts (session_id, ts, kind, delta, bpm, action, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $ts, $kind, is_numeric($data['delta'] ?? null) ? round((float)$data['delta'], 1) : null, is_numeric($data['bpm'] ?? null) ? round((float)$data['bpm'], 1) : null, $actionValue, utc_now()]);
    return ['id' => (int)$pdo->lastInsertId()];
}

/**
 * Vzorky ze Zkratky nebo ze souboru: rozdělí se na úseky (mezera nad 20 min) a každý úsek
 * se přiřadí k seanci, se kterou se časem překrývá; jinak vznikne nová seance „Apple Watch“.
 */
function heart_store_samples(array $samples, string $source): array
{
    $samples = heart_clean_samples($samples);
    if ($samples === []) {
        return ['stored' => 0, 'sessions' => []];
    }
    $clusters = [];
    $current = [];
    foreach ($samples as $sample) {
        if ($current !== [] && $sample[0] - $current[count($current) - 1][0] > HEART_CLUSTER_GAP) {
            $clusters[] = $current;
            $current = [];
        }
        $current[] = $sample;
    }
    $clusters[] = $current;

    $pdo = db();
    $insert = $pdo->prepare('INSERT OR REPLACE INTO hr_samples (session_id, ts, b10, r10) VALUES (?, ?, ?, (SELECT r10 FROM hr_samples WHERE session_id = ? AND ts = ?))');
    $touched = [];
    $stored = 0;
    foreach ($clusters as $cluster) {
        $first = (int)$cluster[0][0];
        $last = (int)$cluster[count($cluster) - 1][0];
        // Parametry jdou do SQLite jako text: porovnává se proto přímo sloupec, ne výpočet.
        $session = fetch_one(
            'SELECT * FROM hr_sessions WHERE started_ts <= ? AND (ended_ts IS NULL OR ended_ts >= ?) ORDER BY ABS(started_ts - ?) LIMIT 1',
            [$last + HEART_SESSION_SLACK, $first - HEART_SESSION_SLACK, $first]
        );
        if ($session === null) {
            $now = utc_now();
            $pdo->prepare('INSERT INTO hr_sessions (started_ts, ended_ts, source, device, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$first, $last, $source === 'watch' ? 'watch' : 'import', $source === 'watch' ? 'Apple Watch' : null, $source === 'watch' ? 'Tep ze Zkratky' : 'Tep ze souboru', $now, $now]);
            $sessionId = (int)$pdo->lastInsertId();
        } else {
            $sessionId = (int)$session['id'];
            if ($session['source'] === 'none' && $source === 'watch') {
                $pdo->prepare("UPDATE hr_sessions SET source = 'watch', device = COALESCE(device, 'Apple Watch'), updated_at = ? WHERE id = ?")->execute([utc_now(), $sessionId]);
            }
            // Seance jen z importu nemá vlastní začátek a konec: roztáhne se podle vzorků.
            if (in_array($session['source'], ['watch', 'import'], true) && $session['check_id'] === null && $session['baseline_from'] === null) {
                $pdo->prepare('UPDATE hr_sessions SET started_ts = MIN(started_ts, ?), ended_ts = MAX(COALESCE(ended_ts, 0), ?), updated_at = ? WHERE id = ?')
                    ->execute([$first, $last, utc_now(), $sessionId]);
            }
        }
        $pdo->beginTransaction();
        try {
            foreach ($cluster as [$ts, $bpm]) {
                $insert->execute([$sessionId, (int)$ts, (int)round($bpm * 10), $sessionId, (int)$ts]);
                $stored++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        $touched[$sessionId] = true;
    }
    foreach (array_keys($touched) as $sessionId) {
        heart_compute_baseline($sessionId);
    }
    return ['stored' => $stored, 'sessions' => array_keys($touched)];
}

/* ---------------------------------------------------------------- odkaz pro Zkratku */

function heart_token_hash(string $token): string
{
    return hash('sha256', 'hr|' . $token);
}

function heart_link_state(): array
{
    $user = current_user();
    $local = fetch_one('SELECT token, created_at FROM hr_link WHERE id = 1');
    $remote = $user === null ? null : app_fetch_one('SELECT * FROM hr_links WHERE user_id = ?', [(int)$user['id']]);
    if ($local === null || $remote === null || !hash_equals((string)$remote['token_hash'], heart_token_hash((string)$local['token']))) {
        return ['available' => vault_available(), 'link' => null];
    }
    return [
        'available' => vault_available(),
        'link' => [
            'token' => (string)$local['token'],
            'created_at' => (string)$remote['created_at'],
            'received_at' => $remote['received_at'],
        ],
    ];
}

/** Nový odkaz (a klíčový pár). Starý přestane platit a nezpracované vzorky pro něj se zahodí. */
function heart_save_link(): array
{
    vault_require();
    $user = require_user();
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $keypair = sodium_crypto_box_keypair();
    $now = utc_now();
    db()->prepare('INSERT INTO hr_link (id, token, keypair, created_at) VALUES (1, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET token = excluded.token, keypair = excluded.keypair, created_at = excluded.created_at')
        ->execute([$token, base64_encode($keypair), $now]);
    app_execute('DELETE FROM hr_inbox WHERE user_id = ?', [(int)$user['id']]);
    app_execute('INSERT INTO hr_links (user_id, token_hash, public_key, created_at) VALUES (?, ?, ?, ?)
        ON CONFLICT(user_id) DO UPDATE SET token_hash = excluded.token_hash, public_key = excluded.public_key, created_at = excluded.created_at, received_at = NULL',
        [(int)$user['id'], heart_token_hash($token), base64_encode(sodium_crypto_box_publickey($keypair)), $now]);
    return heart_link_state();
}

function heart_delete_link(): array
{
    $user = require_user();
    app_execute('DELETE FROM hr_links WHERE user_id = ?', [(int)$user['id']]);
    app_execute('DELETE FROM hr_inbox WHERE user_id = ?', [(int)$user['id']]);
    db()->exec('DELETE FROM hr_link');
    return heart_link_state();
}

/**
 * Příjem ze Zkratky (hr.php, bez přihlášení). Vzorky se ověří, zapečetí veřejným klíčem
 * člena a uloží do schránky; přečte je až jeho deník. Vrací [status, text pro Zkratku].
 */
function heart_inbox_receive(string $token, string $body): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
        record_attempt('hr_link');
        return [404, 'Odkaz neplatí. Vytvoř nový v Trading Desku: Psychika, Tep a obchody, Apple Watch.'];
    }
    if (count_attempts('hr_link', 15) >= 30) {
        return [429, 'Příliš mnoho pokusů s neplatným odkazem. Zkus to za čtvrt hodiny.'];
    }
    $link = app_fetch_one("SELECT l.* FROM hr_links l JOIN users u ON u.id = l.user_id WHERE l.token_hash = ? AND u.status = 'active'", [heart_token_hash($token)]);
    if ($link === null) {
        record_attempt('hr_link');
        return [404, 'Odkaz neplatí. Vytvoř nový v Trading Desku: Psychika, Tep a obchody, Apple Watch.'];
    }
    if (!vault_available()) {
        return [503, 'Server neumí šifrovat (chybí PHP sodium), tep teď nepřijme.'];
    }
    $userId = (int)$link['user_id'];
    $recent = (int)(app_fetch_one("SELECT COUNT(*) AS n FROM hr_inbox WHERE user_id = ? AND received_at >= ?", [$userId, gmdate('Y-m-d\TH:i:s\Z', time() - 3600)])['n'] ?? 0);
    $waiting = (int)(app_fetch_one('SELECT COUNT(*) AS n FROM hr_inbox WHERE user_id = ?', [$userId])['n'] ?? 0);
    if ($recent >= HEART_INBOX_PER_HOUR || $waiting >= HEART_INBOX_MAX_ITEMS) {
        return [429, 'Čeká tu už hodně nezpracovaných odeslání. Otevři Trading Desk, ať se načtou, a pošli tep znovu.'];
    }
    $parsed = heart_parse_text($body);
    $samples = $parsed['samples'];
    if ($samples === []) {
        return [422, 'V odeslaných datech není žádný tep. Zkratka má posílat řádky „čas;tep“, čas ve formátu ISO 8601.'];
    }
    $payload = json_encode(['samples' => $samples, 'received' => time()], JSON_THROW_ON_ERROR);
    app_execute('INSERT INTO hr_inbox (user_id, sealed, samples, received_at) VALUES (?, ?, ?, ?)',
        [$userId, base64_encode(sodium_crypto_box_seal($payload, (string)base64_decode((string)$link['public_key'], true))), count($samples), utc_now()]);
    app_execute('UPDATE hr_links SET received_at = ? WHERE user_id = ?', [utc_now(), $userId]);
    $first = $samples[0][0];
    $last = $samples[count($samples) - 1][0];
    return [200, sprintf('Přijato %s hodnot tepu (%s až %s). Zařadí se, až otevřeš Trading Desk.',
        number_format(count($samples), 0, ',', ' '), heart_local($first, 'j. n. H:i'), heart_local($last, 'H:i'))];
}

/** Rozbalí schránku přihlášeného člena do deníku. Bez platného klíče se schránka zahodí. */
function heart_import_inbox(): array
{
    $user = current_user();
    if ($user === null || !vault_available()) {
        return ['stored' => 0, 'items' => 0];
    }
    $items = app_fetch_all('SELECT id, sealed FROM hr_inbox WHERE user_id = ? ORDER BY id', [(int)$user['id']]);
    if ($items === []) {
        return ['stored' => 0, 'items' => 0];
    }
    $local = fetch_one('SELECT keypair FROM hr_link WHERE id = 1');
    $stored = 0;
    foreach ($items as $item) {
        $sealed = base64_decode((string)$item['sealed'], true);
        $keypair = $local === null ? false : base64_decode((string)$local['keypair'], true);
        $plain = $sealed === false || $keypair === false ? false : sodium_crypto_box_seal_open($sealed, $keypair);
        if ($plain !== false) {
            $data = json_decode($plain, true);
            $stored += heart_store_samples(is_array($data['samples'] ?? null) ? $data['samples'] : [], 'watch')['stored'];
        }
        app_execute('DELETE FROM hr_inbox WHERE id = ?', [(int)$item['id']]);
    }
    return ['stored' => $stored, 'items' => count($items)];
}

/* ---------------------------------------------------------------- vyhodnocení */

function heart_session_summary(array $session): array
{
    $id = (int)$session['id'];
    $stats = fetch_one('SELECT COUNT(*) AS n, MIN(ts) AS first, MAX(ts) AS last, AVG(b10) AS avg, MAX(b10) AS max, MIN(b10) AS min, AVG(r10) AS rmssd FROM hr_samples WHERE session_id = ?', [$id]) ?? [];
    $start = (int)$session['started_ts'];
    $end = $session['ended_ts'] !== null ? (int)$session['ended_ts'] : max(time(), (int)($stats['last'] ?? 0));
    $trades = fetch_one(
        'SELECT COUNT(*) AS n, SUM(CASE WHEN followed_plan = 0 OR execution_rating >= 4 THEN 1 ELSE 0 END) AS errors FROM trades WHERE entry_ts BETWEEN ? AND ?',
        [$start - 300, $end + 300]
    ) ?? [];
    $alerts = (int)(fetch_one('SELECT COUNT(*) AS n FROM hr_alerts WHERE session_id = ?', [$id])['n'] ?? 0);
    return [
        'id' => $id,
        'started_ts' => $start,
        'ended_ts' => $session['ended_ts'] === null ? null : (int)$session['ended_ts'],
        'active' => $session['ended_ts'] === null,
        'date' => heart_local($start, 'Y-m-d'),
        'label' => heart_local($start),
        'minutes' => (int)round(($end - $start) / 60),
        'source' => (string)$session['source'],
        'device' => $session['device'],
        'check_id' => $session['check_id'] === null ? null : (int)$session['check_id'],
        'note' => $session['note'],
        'baseline_from' => $session['baseline_from'] === null ? null : (int)$session['baseline_from'],
        'baseline_to' => $session['baseline_to'] === null ? null : (int)$session['baseline_to'],
        'baseline_bpm' => $session['baseline_bpm'] === null ? null : (float)$session['baseline_bpm'],
        'baseline_rmssd' => $session['baseline_rmssd'] === null ? null : (float)$session['baseline_rmssd'],
        'baseline_kind' => $session['baseline_kind'],
        'samples' => (int)($stats['n'] ?? 0),
        'avg_bpm' => isset($stats['avg']) ? round((float)$stats['avg'] / 10, 1) : null,
        'max_bpm' => isset($stats['max']) ? round((float)$stats['max'] / 10, 1) : null,
        'min_bpm' => isset($stats['min']) ? round((float)$stats['min'] / 10, 1) : null,
        'first_ts' => isset($stats['first']) ? (int)$stats['first'] : null,
        'last_ts' => isset($stats['last']) ? (int)$stats['last'] : null,
        'trades' => (int)($trades['n'] ?? 0),
        'errors' => (int)($trades['errors'] ?? 0),
        'alerts' => $alerts,
    ];
}

function heart_sessions(int $limit = 30): array
{
    return array_map('heart_session_summary', fetch_all('SELECT * FROM hr_sessions ORDER BY started_ts DESC LIMIT ' . max(1, min(200, $limit))));
}

function heart_trade_error(array $trade): ?bool
{
    $followed = $trade['followed_plan'];
    $rating = $trade['execution_rating'];
    if ($followed === null && $rating === null) {
        return null;
    }
    return ($followed !== null && (int)$followed === 0) || ($rating !== null && (int)$rating >= 4);
}

/**
 * Obchody s tepem: tep v posledních dvou minutách před vstupem proti klidovému tepu seance.
 * Řídké vzorky (Apple Watch mimo cvičení) se dohledají v širším okně pěti minut.
 */
function heart_trade_points(): array
{
    $sessions = fetch_all('SELECT id, started_ts, ended_ts, baseline_bpm, baseline_rmssd FROM hr_sessions WHERE baseline_bpm IS NOT NULL ORDER BY started_ts');
    if ($sessions === []) {
        return [];
    }
    $trades = fetch_all('SELECT id, trade_date, market, direction, entry_ts, exit_ts, result_r, result_usd, followed_plan, execution_rating, mistake FROM trades WHERE entry_ts IS NOT NULL ORDER BY entry_ts');
    $window = db()->prepare('SELECT b10, r10 FROM hr_samples WHERE session_id = ? AND ts BETWEEN ? AND ?');
    $points = [];
    $previous = null;
    foreach ($trades as $trade) {
        $entry = (int)$trade['entry_ts'];
        $match = null;
        foreach ($sessions as $session) {
            $end = $session['ended_ts'] === null ? time() : (int)$session['ended_ts'];
            if ($entry >= (int)$session['started_ts'] - 300 && $entry <= $end + 300) {
                $match = $session;
                break;
            }
        }
        $afterLoss = $previous !== null && $previous['result_r'] !== null && (float)$previous['result_r'] < 0
            && $previous['exit_ts'] !== null && $entry - (int)$previous['exit_ts'] <= 1800 && $entry >= (int)$previous['exit_ts'];
        $previous = $trade;
        if ($match === null) {
            continue;
        }
        $rows = [];
        foreach ([[HEART_PRE_SECONDS, 0], [300, 30]] as [$before, $after]) {
            $window->execute([(int)$match['id'], $entry - $before, $entry + $after]);
            $rows = $window->fetchAll();
            if (count($rows) >= 2) {
                break;
            }
        }
        if (count($rows) < 2) {
            continue;
        }
        $pre = array_sum(array_map(static fn(array $row): int => (int)$row['b10'], $rows)) / count($rows) / 10;
        $rr = array_values(array_filter(array_map(static fn(array $row): ?float => $row['r10'] === null ? null : $row['r10'] / 10, $rows), static fn($v): bool => $v !== null));
        $peak = null;
        if ($trade['exit_ts'] !== null && (int)$trade['exit_ts'] > $entry) {
            $value = fetch_one('SELECT MAX(b10) AS m FROM hr_samples WHERE session_id = ? AND ts BETWEEN ? AND ?', [(int)$match['id'], $entry, (int)$trade['exit_ts']])['m'] ?? null;
            $peak = $value === null ? null : round($value / 10 - (float)$match['baseline_bpm'], 1);
        }
        $points[] = [
            'trade_id' => (int)$trade['id'],
            'session_id' => (int)$match['id'],
            'date' => (string)$trade['trade_date'],
            'market' => (string)$trade['market'],
            'direction' => (string)$trade['direction'],
            'entry_ts' => $entry,
            'exit_ts' => $trade['exit_ts'] === null ? null : (int)$trade['exit_ts'],
            'result_r' => $trade['result_r'] === null ? null : (float)$trade['result_r'],
            'error' => heart_trade_error($trade),
            'mistake' => trim((string)($trade['mistake'] ?? '')),
            'pre_bpm' => round($pre, 1),
            'delta' => round($pre - (float)$match['baseline_bpm'], 1),
            'peak_delta' => $peak,
            'rmssd_ratio' => $rr !== [] && $match['baseline_rmssd'] !== null && (float)$match['baseline_rmssd'] > 0
                ? round((array_sum($rr) / count($rr)) / (float)$match['baseline_rmssd'], 2) : null,
            'after_loss' => $afterLoss,
        ];
    }
    return $points;
}

/** Jednostranný Fisherův test: jak pravděpodobné je tolik chyb nad hranicí náhodou. */
function heart_fisher_greater(int $a, int $b, int $c, int $d): float
{
    $n = $a + $b + $c + $d;
    static $logs = [0.0];
    for ($i = count($logs); $i <= $n; $i++) {
        $logs[$i] = $logs[$i - 1] + log($i);
    }
    $row1 = $a + $b;
    $col1 = $a + $c;
    $p = 0.0;
    for ($x = $a; $x <= min($row1, $col1); $x++) {
        $y = $row1 - $x;
        $z = $col1 - $x;
        $w = $n - $row1 - $z;
        if ($y < 0 || $z < 0 || $w < 0) {
            continue;
        }
        $p += exp($logs[$row1] + $logs[$n - $row1] + $logs[$col1] + $logs[$n - $col1] - $logs[$n] - $logs[$x] - $logs[$y] - $logs[$z] - $logs[$w]);
    }
    return min(1.0, $p);
}

function heart_group(array $points): array
{
    $errors = count(array_filter($points, static fn(array $point): bool => $point['error'] === true));
    return ['n' => count($points), 'errors' => $errors, 'rate' => $points === [] ? null : round($errors / count($points), 3)];
}

/**
 * Osobní model: od kolika tepů nad klidem u tebe přibývá chyb. Hledá hranici s největším
 * rozdílem chybovosti (aspoň 3 obchody na každé straně) a ověří ji Fisherovým testem.
 * Bez souvislosti platí hranice 80. percentilu tvých vstupů (neobvykle vysoký tep).
 */
function heart_model(?array $points = null): array
{
    $points ??= heart_trade_points();
    $labeled = array_values(array_filter($points, static fn(array $point): bool => $point['error'] !== null));
    $n = count($labeled);
    $model = [
        'needed' => HEART_LEARN_TRADES,
        'trades' => $n,
        'unlabeled' => count($points) - $n,
        'ready' => $n >= HEART_LEARN_TRADES,
        'errors' => count(array_filter($labeled, static fn(array $point): bool => $point['error'] === true)),
        'threshold' => null,
        'basis' => null,
        'strength' => 'none',
        'p' => null,
        'above' => null,
        'below' => null,
        'buckets' => [],
        'after_loss' => null,
        'hrv_ratio' => null,
        'sustain' => 90,
        'cooldown' => 600,
        'text' => '',
    ];
    $bounds = [[-INF, 0, 'pod klidem'], [0, 5, '0 až +5'], [5, 10, '+5 až +10'], [10, 15, '+10 až +15'], [15, 20, '+15 až +20'], [20, INF, 'nad +20']];
    foreach ($bounds as [$low, $high, $label]) {
        $group = heart_group(array_values(array_filter($labeled, static fn(array $p): bool => $p['delta'] >= $low && $p['delta'] < $high)));
        $model['buckets'][] = ['label' => $label] + $group;
    }
    if (!$model['ready']) {
        $left = HEART_LEARN_TRADES - $n;
        $model['text'] = sprintf('Učím se: %d z %d obchodů s tepem. Upozornění se zapnou po dalších %d.', $n, HEART_LEARN_TRADES, $left);
        return $model;
    }

    $deltas = array_map(static fn(array $p): float => (float)$p['delta'], $labeled);
    $candidates = array_values(array_unique(array_map(static fn(float $d): int => (int)round($d), $deltas)));
    sort($candidates);
    $best = null;
    foreach ($candidates as $index => $cut) {
        $above = array_values(array_filter($labeled, static fn(array $p): bool => $p['delta'] >= $cut));
        $below = array_values(array_filter($labeled, static fn(array $p): bool => $p['delta'] < $cut));
        if (count($above) < 3 || count($below) < 3) {
            continue;
        }
        $ga = heart_group($above);
        $gb = heart_group($below);
        $score = $ga['rate'] - $gb['rate'];
        if ($best === null || $score > $best['score'] + 1e-9) {
            // Hranice je uprostřed mezi nejbližší hodnotou pod ní a jí samotnou, ať upozornění
            // přijde dřív, než tep dosáhne úrovně, při které už chyby byly.
            $previous = $index > 0 ? $candidates[$index - 1] : $cut;
            $best = ['cut' => (int)round(($previous + $cut) / 2), 'score' => $score, 'above' => $ga, 'below' => $gb];
        }
    }
    if ($best !== null && $best['score'] > 0 && $model['errors'] >= 2) {
        $a = $best['above']['errors'];
        $b = $best['above']['n'] - $a;
        $c = $best['below']['errors'];
        $d = $best['below']['n'] - $c;
        $p = heart_fisher_greater($a, $b, $c, $d);
        $model['threshold'] = max(6, $best['cut']);
        $model['basis'] = 'errors';
        $model['p'] = round($p, 4);
        $model['above'] = $best['above'];
        $model['below'] = $best['below'];
        $model['strength'] = $p < 0.01 ? 'strong' : ($p < 0.05 ? 'medium' : ($p < 0.2 ? 'weak' : 'none'));
        $model['text'] = sprintf('Nad +%d tepů od klidu jsi chyboval v %d z %d obchodů (%d %%), pod tím v %d z %d (%d %%).',
            $model['threshold'], $a, $a + $b, (int)round(100 * $a / max(1, $a + $b)), $c, $c + $d, (int)round(100 * $c / max(1, $c + $d)));
    }
    if ($model['threshold'] === null || $model['strength'] === 'none') {
        $model['threshold'] = (int)max(8, round((float)heart_percentile($deltas, 80)));
        $model['basis'] = 'unusual';
        $model['text'] = ($model['text'] !== '' ? $model['text'] . ' ' : '')
            . sprintf('Souvislost tepu s chybami se zatím neprokázala, upozorním tě proto na neobvykle vysoký tep: nad +%d (výš než u 80 %% tvých vstupů).', $model['threshold']);
    }
    $loss = array_values(array_filter($labeled, static fn(array $p): bool => $p['after_loss']));
    if (count($loss) >= 3) {
        $model['after_loss'] = heart_group($loss) + ['avg_delta' => round(array_sum(array_map(static fn(array $p): float => (float)$p['delta'], $loss)) / count($loss), 1)];
    }
    $ratios = array_values(array_filter(array_map(static fn(array $p): ?float => $p['rmssd_ratio'], $labeled), static fn($v): bool => $v !== null));
    if (count($ratios) >= HEART_LEARN_TRADES) {
        $model['hrv_ratio'] = round(min(0.85, max(0.4, (float)heart_percentile($ratios, 20))), 2);
    }
    return $model;
}

/** Úseky seance, kdy tep držel nad hranicí aspoň dvě minuty: kandidáti na dýchání. */
function heart_high_segments(array $samples, float $baseline, float $threshold): array
{
    $segments = [];
    $start = null;
    $last = null;
    $peak = 0.0;
    foreach ($samples as [$ts, $bpm]) {
        $over = $bpm - $baseline >= $threshold;
        if ($over && $start !== null && $ts - $last > 60) {
            if ($last - $start >= 120) {
                $segments[] = ['from' => $start, 'to' => $last, 'peak' => round($peak, 1)];
            }
            $start = null;
        }
        if ($over) {
            $start ??= $ts;
            $last = $ts;
            $peak = max($start === $ts ? 0.0 : $peak, $bpm - $baseline);
        } elseif ($start !== null) {
            if ($last - $start >= 120) {
                $segments[] = ['from' => $start, 'to' => $last, 'peak' => round($peak, 1)];
            }
            $start = null;
            $peak = 0.0;
        }
    }
    if ($start !== null && $last - $start >= 120) {
        $segments[] = ['from' => $start, 'to' => $last, 'peak' => round($peak, 1)];
    }
    return $segments;
}

function heart_session_detail(int $id): array
{
    $session = heart_session_row($id);
    $summary = heart_session_summary($session);
    $samples = array_map(static fn(array $row): array => [(int)$row['ts'], $row['b10'] / 10, $row['r10'] === null ? null : $row['r10'] / 10],
        fetch_all('SELECT ts, b10, r10 FROM hr_samples WHERE session_id = ? ORDER BY ts', [$id]));
    $points = heart_trade_points();
    $model = heart_model($points);
    $end = $summary['ended_ts'] ?? max(time(), (int)($summary['last_ts'] ?? 0));
    $byTrade = [];
    foreach ($points as $point) {
        $byTrade[$point['trade_id']] = $point;
    }
    $trades = [];
    foreach (fetch_all('SELECT id, market, direction, entry_ts, exit_ts, result_r, result_usd, followed_plan, execution_rating, mistake FROM trades WHERE entry_ts BETWEEN ? AND ? ORDER BY entry_ts', [$summary['started_ts'] - 300, $end + 300]) as $trade) {
        $point = $byTrade[(int)$trade['id']] ?? null;
        $trades[] = [
            'id' => (int)$trade['id'],
            'market' => (string)$trade['market'],
            'direction' => (string)$trade['direction'],
            'entry_ts' => (int)$trade['entry_ts'],
            'exit_ts' => $trade['exit_ts'] === null ? null : (int)$trade['exit_ts'],
            'result_r' => $trade['result_r'] === null ? null : (float)$trade['result_r'],
            'error' => heart_trade_error($trade),
            'mistake' => trim((string)($trade['mistake'] ?? '')),
            'pre_bpm' => $point['pre_bpm'] ?? null,
            'delta' => $point['delta'] ?? null,
            'peak_delta' => $point['peak_delta'] ?? null,
        ];
    }
    $missingTimes = (int)(fetch_one('SELECT COUNT(*) AS n FROM trades WHERE entry_ts IS NULL AND trade_date = ?', [$summary['date']])['n'] ?? 0);
    $alerts = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'], 'ts' => (int)$row['ts'], 'kind' => (string)$row['kind'],
        'delta' => $row['delta'] === null ? null : (float)$row['delta'], 'bpm' => $row['bpm'] === null ? null : (float)$row['bpm'], 'action' => (string)$row['action'],
    ], fetch_all('SELECT * FROM hr_alerts WHERE session_id = ? ORDER BY ts', [$id]));
    $threshold = (float)($model['threshold'] ?? 10);
    $segments = $summary['baseline_bpm'] === null ? [] : heart_high_segments($samples, (float)$summary['baseline_bpm'], $threshold);
    $above = 0;
    if ($summary['baseline_bpm'] !== null) {
        foreach ($samples as [, $bpm]) {
            if ($bpm - $summary['baseline_bpm'] >= $threshold) {
                $above++;
            }
        }
    }
    return [
        'session' => $summary,
        'samples' => $samples,
        'trades' => $trades,
        'missing_times' => $missingTimes,
        'alerts' => $alerts,
        'segments' => $segments,
        'threshold' => $threshold,
        'threshold_basis' => $model['ready'] ? $model['basis'] : 'default',
        'above_share' => $samples === [] ? null : round($above / count($samples), 3),
        'check' => $summary['check_id'] === null ? null : fetch_one('SELECT id, check_date, band, score, max_score FROM psych_checks WHERE id = ?', [$summary['check_id']]),
    ];
}

/** Vše pro lištu a Psychiku: běžící seance, odkaz, model a poslední seance. */
function heart_state(): array
{
    $imported = heart_import_inbox();
    $active = heart_active_session();
    $points = heart_trade_points();
    return [
        'imported' => $imported,
        'active' => $active === null ? null : heart_session_summary($active),
        'link' => heart_link_state(),
        'model' => heart_model($points),
        'points' => array_map(static fn(array $p): array => [
            'trade_id' => $p['trade_id'], 'date' => $p['date'], 'market' => $p['market'], 'delta' => $p['delta'], 'error' => $p['error'],
            'result_r' => $p['result_r'], 'after_loss' => $p['after_loss'], 'session_id' => $p['session_id'],
        ], $points),
        'sessions' => heart_sessions(),
        'today_check' => fetch_one('SELECT id, check_date, band, score, max_score FROM psych_checks WHERE check_date = ? ORDER BY id DESC LIMIT 1', [heart_today()]),
    ];
}

function heart_today(): string
{
    return (new DateTimeImmutable('now', heart_zone()))->format('Y-m-d');
}
