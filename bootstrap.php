<?php
declare(strict_types=1);

const APP_BASE = '/trading';
const APP_VERSION = '17';
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
const AUDIT_INTERVAL_DAYS = 30;
const AUDIT_WARNING_DAYS = 3;

require_once __DIR__ . '/lib/vault.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/wall.php';
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/hindsight.php';

/** Adresář deníku přihlášeného uživatele. */
function data_dir(): string
{
    return current_journal()->directory();
}

function upload_dir(): string
{
    return current_journal()->directory() . DIRECTORY_SEPARATOR . 'uploads';
}

function ensure_storage(): void
{
    ensure_directory(storage_root());
}

/** Databáze deníku přihlášeného uživatele; každý má vlastní soubor. */
function db(): PDO
{
    return current_journal()->pdo();
}

function initialize_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS plans (
    id INTEGER PRIMARY KEY,
    plan_type TEXT NOT NULL DEFAULT 'daily',
    plan_date TEXT NOT NULL,
    market TEXT NOT NULL,
    session TEXT NOT NULL DEFAULT 'intraday',
    status TEXT NOT NULL DEFAULT 'draft',
    bias TEXT NOT NULL DEFAULT 'neutral',
    bias_description TEXT,
    bias_confirm TEXT,
    bias_invalidation TEXT,
    pa_monthly TEXT,
    pa_weekly TEXT,
    pa_daily TEXT,
    pa_monthly_note TEXT,
    pa_weekly_note TEXT,
    pa_daily_note TEXT,
    mp_weekly TEXT,
    mp_daily TEXT,
    mp_weekly_note TEXT,
    mp_daily_note TEXT,
    profile_shape TEXT,
    ref_high REAL,
    ref_vah REAL,
    ref_poc REAL,
    ref_val REAL,
    ref_low REAL,
    ref_close REAL,
    value_area TEXT,
    vpoc TEXT,
    auction TEXT,
    weekly_position TEXT,
    previous_close TEXT,
    globex_open TEXT,
    eu_open TEXT,
    ny_open TEXT,
    open_type TEXT,
    initial_balance TEXT,
    single_print TEXT,
    tail TEXT,
    important_news TEXT,
    no_trade_conditions TEXT,
    general_notes TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(plan_type, plan_date, market, session)
);

CREATE TABLE IF NOT EXISTS plan_refs (
    id INTEGER PRIMARY KEY,
    plan_id INTEGER NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    kind TEXT NOT NULL DEFAULT 'other',
    price_low REAL,
    price_high REAL,
    status TEXT NOT NULL DEFAULT 'open',
    note TEXT
);

CREATE TABLE IF NOT EXISTS zones (
    id INTEGER PRIMARY KEY,
    plan_id INTEGER NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    name TEXT,
    direction TEXT,
    price_low REAL,
    price_high REAL,
    priority TEXT,
    source TEXT,
    invalidation TEXT,
    trigger TEXT,
    stop_loss REAL,
    tp1 REAL,
    tp2 REAL,
    rr REAL,
    status TEXT NOT NULL DEFAULT 'planned',
    long_entry TEXT,
    long_skip TEXT,
    short_entry TEXT,
    short_skip TEXT,
    CHECK(price_low IS NULL OR price_high IS NULL OR price_low <= price_high)
);

CREATE TABLE IF NOT EXISTS ideas (
    id INTEGER PRIMARY KEY,
    plan_id INTEGER NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    name TEXT,
    direction TEXT,
    zone_name TEXT,
    trigger TEXT,
    entry_price REAL,
    stop_loss REAL,
    tp1 REAL,
    tp2 REAL,
    final_tp REAL,
    rr REAL,
    status TEXT NOT NULL DEFAULT 'waiting',
    notes TEXT
);

CREATE TABLE IF NOT EXISTS levels (
    id INTEGER PRIMARY KEY,
    plan_id INTEGER NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    name TEXT,
    price REAL,
    kind TEXT,
    source TEXT,
    line_style TEXT,
    note TEXT
);

CREATE TABLE IF NOT EXISTS calendar_events (
    id INTEGER PRIMARY KEY,
    event_date TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'news',
    title TEXT NOT NULL,
    time_label TEXT,
    impact TEXT,
    notes TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS psych_profile (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    answers TEXT NOT NULL,
    dimensions TEXT NOT NULL,
    rules TEXT NOT NULL,
    thresholds TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS psych_checks (
    id INTEGER PRIMARY KEY,
    check_date TEXT NOT NULL,
    score INTEGER NOT NULL,
    max_score INTEGER NOT NULL,
    band TEXT NOT NULL,
    answers TEXT NOT NULL,
    verdict TEXT,
    warnings TEXT,
    notes TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS strategies (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    timeframe TEXT,
    style TEXT,
    notes TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    broker TEXT,
    currency TEXT NOT NULL DEFAULT 'USD',
    starting_balance REAL NOT NULL,
    daily_risk REAL,
    opened_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    CHECK(starting_balance >= 0)
);

CREATE TABLE IF NOT EXISTS account_audits (
    id INTEGER PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    audit_date TEXT NOT NULL,
    reported_balance REAL NOT NULL,
    expected_balance REAL NOT NULL,
    difference REAL NOT NULL,
    tolerance REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'ok',
    trades_count INTEGER NOT NULL DEFAULT 0,
    missing_trades REAL,
    month_r REAL,
    month_usd REAL,
    plan_adherence REAL,
    execution_rating REAL,
    verdict TEXT,
    notes TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS trades (
    id INTEGER PRIMARY KEY,
    plan_id INTEGER REFERENCES plans(id) ON DELETE SET NULL,
    trade_date TEXT NOT NULL,
    market TEXT NOT NULL,
    session TEXT,
    strategy TEXT,
    strategy_id INTEGER REFERENCES strategies(id) ON DELETE SET NULL,
    account_id INTEGER REFERENCES accounts(id) ON DELETE SET NULL,
    direction TEXT NOT NULL,
    entry_price REAL,
    exit_price REAL,
    quantity REAL NOT NULL DEFAULT 1,
    risk_amount REAL,
    point_value REAL,
    stop_loss REAL,
    target_price REAL,
    fees REAL NOT NULL DEFAULT 0,
    result_r REAL,
    result_usd REAL,
    followed_plan INTEGER,
    execution_rating INTEGER,
    emotion TEXT,
    mistake TEXT,
    notes TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS screenshots (
    id TEXT PRIMARY KEY,
    plan_id INTEGER REFERENCES plans(id) ON DELETE CASCADE,
    trade_id INTEGER REFERENCES trades(id) ON DELETE CASCADE,
    strategy_id INTEGER REFERENCES strategies(id) ON DELETE CASCADE,
    audit_id INTEGER REFERENCES account_audits(id) ON DELETE CASCADE,
    role TEXT NOT NULL DEFAULT 'plan',
    file_name TEXT NOT NULL UNIQUE,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    caption TEXT,
    created_at TEXT NOT NULL,
    CHECK(plan_id IS NOT NULL OR trade_id IS NOT NULL OR strategy_id IS NOT NULL OR audit_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_plans_date_market ON plans(plan_date DESC, market);
CREATE INDEX IF NOT EXISTS idx_zones_plan_sort ON zones(plan_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_ideas_plan_sort ON ideas(plan_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_levels_plan_sort ON levels(plan_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_plan_refs_plan_sort ON plan_refs(plan_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_trades_date_market ON trades(trade_date DESC, market);
CREATE INDEX IF NOT EXISTS idx_trades_plan ON trades(plan_id);
CREATE INDEX IF NOT EXISTS idx_audits_account ON account_audits(account_id, audit_date DESC);
CREATE INDEX IF NOT EXISTS idx_calendar_date ON calendar_events(event_date);
CREATE INDEX IF NOT EXISTS idx_psych_date ON psych_checks(check_date DESC);
CREATE INDEX IF NOT EXISTS idx_screenshots_plan ON screenshots(plan_id, created_at);
CREATE INDEX IF NOT EXISTS idx_screenshots_trade ON screenshots(trade_id, created_at);
SQL);
    $pdo->exec('PRAGMA optimize');
}

function table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $row) {
        $columns[] = (string)$row['name'];
    }
    return $columns;
}

function migrate_schema(PDO $pdo): void
{
    // Týdenní náhled mění unikátní klíč plánu (typ + datum + trh + typ obchodu).
    // SQLite neumí změnit omezení tabulky, proto se jednorázově přestaví.
    if (!in_array('plan_type', table_columns($pdo, 'plans'), true)) {
        rebuild_plans($pdo);
    }
    $planColumns = table_columns($pdo, 'plans');
    foreach (PLAN_EXTRA_COLUMNS as $column => $type) {
        if (!in_array($column, $planColumns, true)) {
            add_column($pdo, 'plans', $column, $type);
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_plans_type_date ON plans(plan_type, plan_date DESC)');

    $zoneColumns = table_columns($pdo, 'zones');
    // valid_to: '' = zóna platí jen den náhledu, 'open' = dokud ji neukončím, datum = do data (Hindsight).
    foreach (['long_entry', 'long_skip', 'short_entry', 'short_skip', 'valid_to', 'zone_type', 'note'] as $column) {
        if (!in_array($column, $zoneColumns, true)) {
            add_column($pdo, 'zones', $column, 'TEXT');
        }
    }

    // Hindsight: potenciální obchod má čas vstupu v grafu, výsledek (nevzatý, propáslý,
    // vzatý) a odkaz na realizovaný obchod.
    $ideaColumns = table_columns($pdo, 'ideas');
    foreach (['entry_ts' => 'INTEGER', 'outcome' => 'TEXT', 'trade_id' => 'INTEGER'] as $column => $type) {
        if (!in_array($column, $ideaColumns, true)) {
            add_column($pdo, 'ideas', $column, $type);
        }
    }

    $tradeColumns = table_columns($pdo, 'trades');
    // Hindsight: časy vstupu a výstupu (UTC) a identifikátor z importu proti duplicitám.
    foreach (['entry_ts' => 'INTEGER', 'exit_ts' => 'INTEGER', 'external_id' => 'TEXT'] as $column => $type) {
        if (!in_array($column, $tradeColumns, true)) {
            add_column($pdo, 'trades', $column, $type);
        }
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_trades_external ON trades(external_id) WHERE external_id IS NOT NULL');
    $addedStrategyLink = !in_array('strategy_id', $tradeColumns, true);
    if ($addedStrategyLink) {
        $pdo->exec('ALTER TABLE trades ADD COLUMN strategy_id INTEGER REFERENCES strategies(id) ON DELETE SET NULL');
    }
    $addedRisk = !in_array('risk_amount', $tradeColumns, true);
    if ($addedRisk) {
        $pdo->exec('ALTER TABLE trades ADD COLUMN risk_amount REAL');
    }
    if (!in_array('account_id', $tradeColumns, true)) {
        $pdo->exec('ALTER TABLE trades ADD COLUMN account_id INTEGER REFERENCES accounts(id) ON DELETE SET NULL');
    }
    $addedPointValue = !in_array('point_value', $tradeColumns, true);
    if ($addedPointValue) {
        $pdo->exec('ALTER TABLE trades ADD COLUMN point_value REAL');
    }

    if (!in_array('thresholds', table_columns($pdo, 'psych_profile'), true)) {
        $pdo->exec("ALTER TABLE psych_profile ADD COLUMN thresholds TEXT NOT NULL DEFAULT '{}'");
    }

    $screenshotColumns = table_columns($pdo, 'screenshots');
    if (!in_array('strategy_id', $screenshotColumns, true) || !in_array('audit_id', $screenshotColumns, true)) {
        rebuild_screenshots($pdo);
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trades_strategy ON trades(strategy_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trades_account ON trades(account_id, trade_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_screenshots_strategy ON screenshots(strategy_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_screenshots_audit ON screenshots(audit_id, created_at)');

    if ($addedStrategyLink) {
        backfill_strategies($pdo);
    }
    if ($addedRisk) {
        backfill_risk($pdo);
    }
    if ($addedPointValue) {
        backfill_point_value($pdo);
    }

    // Jednorázový úklid prázdných řádků, které vznikaly předvyplněným prvním řádkem.
    $userVersion = (int)($pdo->query('PRAGMA user_version')->fetchColumn() ?: 0);
    if ($userVersion < 1) {
        remove_blank_plan_rows($pdo);
        $pdo->exec('PRAGMA user_version = 1');
        $userVersion = 1;
    }
    // Zóny teď mají podmínky zvlášť pro long a short. Dosavadní obecný trigger se
    // u jednosměrných zón přesune do podmínek vstupu daného směru; nic se nemaže.
    if ($userVersion < 2) {
        $pdo->exec("UPDATE zones SET long_entry = \"trigger\", \"trigger\" = '' WHERE direction = 'long' AND COALESCE(TRIM(\"trigger\"), '') <> '' AND COALESCE(TRIM(long_entry), '') = ''");
        $pdo->exec("UPDATE zones SET short_entry = \"trigger\", \"trigger\" = '' WHERE direction = 'short' AND COALESCE(TRIM(\"trigger\"), '') <> '' AND COALESCE(TRIM(short_entry), '') = ''");
        $pdo->exec('PRAGMA user_version = 2');
    }
    ensure_workspace_schema($pdo);
}

const PLAN_EXTRA_COLUMNS = [
    'pa_monthly' => 'TEXT',
    'pa_weekly' => 'TEXT',
    'pa_daily' => 'TEXT',
    'pa_monthly_note' => 'TEXT',
    'pa_weekly_note' => 'TEXT',
    'pa_daily_note' => 'TEXT',
    'mp_weekly' => 'TEXT',
    'mp_daily' => 'TEXT',
    'mp_weekly_note' => 'TEXT',
    'mp_daily_note' => 'TEXT',
    'profile_shape' => 'TEXT',
    'ref_high' => 'REAL',
    'ref_vah' => 'REAL',
    'ref_poc' => 'REAL',
    'ref_val' => 'REAL',
    'ref_low' => 'REAL',
    'ref_close' => 'REAL',
    'globex_open' => 'TEXT',
    'open_type' => 'TEXT',
];

/**
 * Přidá sloupec. Po aktualizaci přijde najednou několik požadavků z přehledu,
 * takže sloupec mohl mezitím přidat jiný z nich; to není chyba.
 */
function add_column(PDO $pdo, string $table, string $column, string $type): void
{
    try {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
    } catch (PDOException $error) {
        if (!str_contains($error->getMessage(), 'duplicate column')) {
            throw $error;
        }
    }
}

function rebuild_plans(PDO $pdo): void
{
    $extra = implode(",\n    ", array_map(static fn(string $column, string $type): string => "$column $type", array_keys(PLAN_EXTRA_COLUMNS), PLAN_EXTRA_COLUMNS));
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        // Zámek pro zápis hned na začátku, ať přestavbu udělá jen jeden souběžný požadavek.
        $pdo->exec('BEGIN IMMEDIATE');
        if (in_array('plan_type', table_columns($pdo, 'plans'), true)) {
            $pdo->exec('COMMIT');
            return;
        }
        $pdo->exec(<<<SQL
CREATE TABLE plans_migrated (
    id INTEGER PRIMARY KEY,
    plan_type TEXT NOT NULL DEFAULT 'daily',
    plan_date TEXT NOT NULL,
    market TEXT NOT NULL,
    session TEXT NOT NULL DEFAULT 'intraday',
    status TEXT NOT NULL DEFAULT 'draft',
    bias TEXT NOT NULL DEFAULT 'neutral',
    bias_description TEXT,
    bias_confirm TEXT,
    bias_invalidation TEXT,
    value_area TEXT,
    vpoc TEXT,
    auction TEXT,
    weekly_position TEXT,
    previous_close TEXT,
    eu_open TEXT,
    ny_open TEXT,
    initial_balance TEXT,
    single_print TEXT,
    tail TEXT,
    important_news TEXT,
    no_trade_conditions TEXT,
    general_notes TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    $extra,
    UNIQUE(plan_type, plan_date, market, session)
)
SQL);
        $old = table_columns($pdo, 'plans');
        $common = array_values(array_intersect(table_columns($pdo, 'plans_migrated'), $old));
        $list = implode(', ', array_map(static fn(string $column): string => '"' . $column . '"', $common));
        $before = (int)$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn();
        $pdo->exec("INSERT INTO plans_migrated ($list) SELECT $list FROM plans");
        $after = (int)$pdo->query('SELECT COUNT(*) FROM plans_migrated')->fetchColumn();
        if ($before !== $after) {
            throw new RuntimeException('Přestavba tabulky náhledů nepřenesla všechny řádky.');
        }
        $pdo->exec('DROP TABLE plans');
        $pdo->exec('ALTER TABLE plans_migrated RENAME TO plans');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_plans_date_market ON plans(plan_date DESC, market)');
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Transakce už neběží.
        }
        throw $error;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function remove_blank_plan_rows(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
DELETE FROM zones
WHERE COALESCE(TRIM(name), '') = ''
  AND price_low IS NULL AND price_high IS NULL
  AND COALESCE(TRIM(source), '') = ''
  AND COALESCE(TRIM(invalidation), '') = ''
  AND COALESCE(TRIM("trigger"), '') = ''
  AND stop_loss IS NULL AND tp1 IS NULL AND tp2 IS NULL AND rr IS NULL
SQL);
    $pdo->exec(<<<'SQL'
DELETE FROM ideas
WHERE COALESCE(TRIM(name), '') = ''
  AND COALESCE(TRIM(zone_name), '') = ''
  AND COALESCE(TRIM("trigger"), '') = ''
  AND entry_price IS NULL AND stop_loss IS NULL
  AND tp1 IS NULL AND tp2 IS NULL AND final_tp IS NULL AND rr IS NULL
  AND COALESCE(TRIM(notes), '') = ''
SQL);
}

function backfill_point_value(PDO $pdo): void
{
    $markets = $pdo->query('SELECT DISTINCT market FROM trades WHERE market IS NOT NULL')->fetchAll();
    $update = $pdo->prepare('UPDATE trades SET point_value = ? WHERE market = ? AND point_value IS NULL');
    foreach ($markets as $row) {
        $update->execute([market_multiplier((string)$row['market']), (string)$row['market']]);
    }
}

function rebuild_screenshots(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->beginTransaction();
        $pdo->exec(<<<'SQL'
CREATE TABLE screenshots_migrated (
    id TEXT PRIMARY KEY,
    plan_id INTEGER REFERENCES plans(id) ON DELETE CASCADE,
    trade_id INTEGER REFERENCES trades(id) ON DELETE CASCADE,
    strategy_id INTEGER REFERENCES strategies(id) ON DELETE CASCADE,
    audit_id INTEGER REFERENCES account_audits(id) ON DELETE CASCADE,
    role TEXT NOT NULL DEFAULT 'plan',
    file_name TEXT NOT NULL UNIQUE,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    caption TEXT,
    created_at TEXT NOT NULL,
    CHECK(plan_id IS NOT NULL OR trade_id IS NOT NULL OR strategy_id IS NOT NULL OR audit_id IS NOT NULL)
)
SQL);
        $columns = table_columns($pdo, 'screenshots');
        $strategySource = in_array('strategy_id', $columns, true) ? 'strategy_id' : 'NULL';
        $auditSource = in_array('audit_id', $columns, true) ? 'audit_id' : 'NULL';
        $pdo->exec("INSERT INTO screenshots_migrated (id, plan_id, trade_id, strategy_id, audit_id, role, file_name, original_name, mime_type, size_bytes, caption, created_at) SELECT id, plan_id, trade_id, $strategySource, $auditSource, role, file_name, original_name, mime_type, size_bytes, caption, created_at FROM screenshots");
        $pdo->exec('DROP TABLE screenshots');
        $pdo->exec('ALTER TABLE screenshots_migrated RENAME TO screenshots');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_screenshots_plan ON screenshots(plan_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_screenshots_trade ON screenshots(trade_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_screenshots_strategy ON screenshots(strategy_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_screenshots_audit ON screenshots(audit_id, created_at)');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function backfill_strategies(PDO $pdo): void
{
    $names = $pdo->query("SELECT DISTINCT TRIM(strategy) AS name FROM trades WHERE strategy IS NOT NULL AND TRIM(strategy) <> ''")->fetchAll();
    if ($names === []) {
        return;
    }
    $now = utc_now();
    $insert = $pdo->prepare('INSERT OR IGNORE INTO strategies (name, timeframe, style, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($names as $row) {
        $insert->execute([(string)$row['name'], '', '', '', $now, $now]);
    }
    $pdo->exec("UPDATE trades SET strategy_id = (SELECT s.id FROM strategies s WHERE s.name = TRIM(trades.strategy)) WHERE strategy_id IS NULL AND strategy IS NOT NULL AND TRIM(strategy) <> ''");
}

function backfill_risk(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id, market, entry_price, stop_loss, quantity FROM trades WHERE risk_amount IS NULL AND entry_price IS NOT NULL AND stop_loss IS NOT NULL')->fetchAll();
    if ($rows === []) {
        return;
    }
    $update = $pdo->prepare('UPDATE trades SET risk_amount = ? WHERE id = ?');
    foreach ($rows as $row) {
        $distance = abs((float)$row['entry_price'] - (float)$row['stop_loss']);
        $risk = $distance * market_multiplier((string)$row['market']) * max(0.0, (float)$row['quantity']);
        if ($risk > 0) {
            $update->execute([$risk, (int)$row['id']]);
        }
    }
}

function resolve_python(): ?string
{
    $configured = getenv('TRADING_PYTHON');
    if (is_string($configured) && $configured !== '' && is_file($configured)) {
        return $configured;
    }

    $windows = PHP_OS_FAMILY === 'Windows';
    $names = $windows ? ['python3.exe', 'python.exe'] : ['python3', 'python'];
    $directories = $windows ? [] : ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin'];
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
        $directories[] = $directory;
    }

    foreach ($directories as $directory) {
        $directory = trim($directory);
        if ($directory === '') {
            continue;
        }
        foreach ($names as $name) {
            $candidate = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && ($windows || is_executable($candidate))) {
                return $candidate;
            }
        }
    }
    return null;
}

function normalize_session(string $value): string
{
    $trimmed = trim($value);
    $lower = mb_strtolower($trimmed, 'UTF-8');
    if ($lower === 'intraday') {
        return 'intraday';
    }
    if (in_array($lower, ['hybrid intraday', 'hybrid_intraday', 'swing'], true)) {
        return 'hybrid_intraday';
    }
    return $trimmed;
}

function utc_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function asset_url(string $relativePath): string
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $stamp = is_file($path) ? (int)filemtime($path) : 0;
    return $relativePath . '?v=' . ($stamp > 0 ? (string)$stamp : APP_VERSION);
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'self'");
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

/**
 * Zapisovat smí jen stránka aplikace. Na serveru s heslem by jinak cizí web mohl
 * v prohlížeči přihlášeného uživatele potichu měnit data (CSRF). Prohlížeče posílají
 * Sec-Fetch-Site, starší jen Origin; požadavek bez obou není z prohlížeče.
 */
function require_same_origin(): void
{
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '') {
        if ($site !== 'same-origin') {
            json_response(['error' => 'Požadavek z cizí stránky byl odmítnut.'], 403);
        }
        return;
    }

    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $host = (string)parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    $authority = strtolower($host . ($port !== null ? ':' . $port : ''));
    $allowed = array_filter(array_map('strtolower', [
        (string)($_SERVER['HTTP_HOST'] ?? ''),
        (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''),
    ]));
    if ($host === '' || !in_array($authority, $allowed, true)) {
        json_response(['error' => 'Požadavek z cizí stránky byl odmítnut.'], 403);
    }
}

function json_response(mixed $payload, int $status = 200): never
{
    // Šifrovaný deník se zapečetí dřív, než klient dostane odpověď.
    try {
        journal_flush();
    } catch (Throwable $error) {
        error_log('Trading journal flush failed: ' . $error->__toString());
        $payload = ['error' => 'Deník se nepodařilo bezpečně uložit. Změna se neprovedla.'];
        $status = 500;
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

const MAX_JSON_BYTES = 8 * 1024 * 1024;

function request_json(): array
{
    // Deník posílá jen text; větší tělo by jen zbytečně zabralo paměť serveru.
    $raw = file_get_contents('php://input', false, null, 0, MAX_JSON_BYTES + 1);
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > MAX_JSON_BYTES) {
        json_response(['error' => 'Požadavek je příliš velký.'], 413);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response(['error' => 'Neplatný JSON požadavek.'], 400);
    }
    return $decoded;
}

function value(array $data, string $key, mixed $default = null): mixed
{
    return array_key_exists($key, $data) ? $data[$key] : $default;
}

function row_has_content(array $row, array $keys): bool
{
    foreach ($keys as $key) {
        $value = value($row, $key);
        if ($value !== null && trim((string)$value) !== '') {
            return true;
        }
    }
    return false;
}

function nullable_float(mixed $value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}

function nullable_int(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (int)$value : null;
}

function fetch_all(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * Týdenní kontext pro náhled: value minulého týdne a týdenní bias. Týdenní náhled
 * ho má ve vlastním profilu, denní si ho bere z týdenního náhledu stejného týdne a trhu.
 */
function weekly_context(array $plan): array
{
    $weekStart = week_start((string)$plan['plan_date']);
    $source = null;
    $origin = 'missing';
    if ((string)($plan['plan_type'] ?? 'daily') === 'weekly') {
        $source = $plan;
        $origin = 'self';
    } else {
        $source = find_weekly_plan($weekStart, (string)$plan['market'], (string)($plan['session'] ?? ''));
        $origin = $source === null ? 'missing' : 'weekly';
    }
    $pick = static fn(string $key): mixed => $source[$key] ?? null;
    return [
        'source' => $origin,
        'plan_id' => $source === null ? null : (int)$source['id'],
        'week_start' => $weekStart,
        'vah' => nullable_float($pick('ref_vah')),
        'val' => nullable_float($pick('ref_val')),
        'poc' => nullable_float($pick('ref_poc')),
        'high' => nullable_float($pick('ref_high')),
        'low' => nullable_float($pick('ref_low')),
        'pa_monthly' => (string)($pick('pa_monthly') ?? ''),
        'pa_weekly' => (string)($pick('pa_weekly') ?? ''),
        'mp_weekly' => (string)($pick('mp_weekly') ?? ''),
        'pa_weekly_note' => (string)($pick('pa_weekly_note') ?? ''),
        'mp_weekly_note' => (string)($pick('mp_weekly_note') ?? ''),
    ];
}

function find_weekly_plan(string $weekStart, string $market, string $session = ''): ?array
{
    return fetch_one(
        "SELECT * FROM plans WHERE plan_type = 'weekly' AND plan_date = ? AND market = ? ORDER BY (session = ?) DESC, updated_at DESC, id DESC LIMIT 1",
        [week_start($weekStart), strtoupper(trim($market)), normalize_session($session)]
    );
}

/**
 * Kde zóna leží vůči value minulého týdne. "V oblasti" znamená do vzdálenosti šířky
 * zóny, nejméně však 5 % šířky value, aby se hodnocení neměnilo po jednom ticku.
 * Stejná pravidla používá app.js (vaContext), ať obrazovka i PDF říkají totéž.
 */
function zone_value_context(array $zone, ?array $context): ?array
{
    $low = nullable_float($zone['price_low'] ?? null);
    $high = nullable_float($zone['price_high'] ?? null);
    $vah = $context['vah'] ?? null;
    $val = $context['val'] ?? null;
    if ($low === null && $high === null) {
        return null;
    }
    if ($vah === null || $val === null || $vah <= $val) {
        return null;
    }
    $low ??= $high;
    $high ??= $low;
    if ($low > $high) {
        [$low, $high] = [$high, $low];
    }
    $near = max($high - $low, ($vah - $val) * 0.05);
    $suffix = 'předchozího týdne';
    if ($low <= $val && $high >= $vah) {
        return ['key' => 'span', 'text' => "Přes celou value $suffix"];
    }
    if ($low <= $val && $high >= $val) {
        return ['key' => 'at_val', 'text' => "Ve VAL $suffix"];
    }
    if ($low <= $vah && $high >= $vah) {
        return ['key' => 'at_vah', 'text' => "Ve VAH $suffix"];
    }
    if ($high < $val) {
        return $val - $high <= $near
            ? ['key' => 'near_val_below', 'text' => "V oblasti VAL $suffix, těsně pod ní"]
            : ['key' => 'below_val', 'text' => "Pod VAL $suffix"];
    }
    if ($low > $vah) {
        return $low - $vah <= $near
            ? ['key' => 'near_vah_above', 'text' => "V oblasti VAH $suffix, těsně nad ní"]
            : ['key' => 'above_vah', 'text' => "Nad VAH $suffix"];
    }
    if ($low - $val <= $near) {
        return ['key' => 'near_val_inside', 'text' => "V oblasti VAL $suffix, těsně nad ní uvnitř value"];
    }
    if ($vah - $high <= $near) {
        return ['key' => 'near_vah_inside', 'text' => "V oblasti VAH $suffix, těsně pod ní uvnitř value"];
    }
    $poc = $context['poc'] ?? null;
    $detail = $poc === null ? '' : (($low + $high) / 2 >= $poc ? ', nad POC' : ', pod POC');
    return ['key' => 'inside', 'text' => "Uvnitř value $suffix$detail"];
}

function plan_payload(int $id): ?array
{
    $plan = fetch_one('SELECT * FROM plans WHERE id = ?', [$id]);
    if ($plan === null) {
        return null;
    }
    $plan['weekly_context'] = weekly_context($plan);
    $plan['zones'] = array_map(static function (array $zone) use ($plan): array {
        $zone['va_context'] = zone_value_context($zone, $plan['weekly_context']);
        return $zone;
    }, fetch_all('SELECT * FROM zones WHERE plan_id = ? ORDER BY sort_order, id', [$id]));
    $plan['levels'] = fetch_all('SELECT * FROM levels WHERE plan_id = ? ORDER BY sort_order, id', [$id]);
    $plan['ideas'] = fetch_all('SELECT * FROM ideas WHERE plan_id = ? ORDER BY sort_order, id', [$id]);
    $plan['refs'] = fetch_all('SELECT * FROM plan_refs WHERE plan_id = ? ORDER BY sort_order, id', [$id]);
    $plan['screenshots'] = fetch_all('SELECT id, plan_id, trade_id, role, original_name, mime_type, size_bytes, caption, created_at FROM screenshots WHERE plan_id = ? ORDER BY created_at, id', [$id]);
    $plan['trades'] = fetch_all('SELECT * FROM trades WHERE plan_id = ? ORDER BY trade_date DESC, id DESC', [$id]);
    return array_merge($plan, plan_extras($plan));
}

const REF_KINDS = ['single_print', 'poor_high', 'poor_low', 'naked_poc', 'gap', 'excess', 'lvn', 'other'];
const PROFILE_SHAPES = ['p', 'b', 'd', 'double', 'trend'];

/** Týdenní náhled patří vždy k pondělí daného týdne. */
function week_start(string $date): string
{
    $day = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    $weekday = (int)$day->format('N');
    return $day->modify('-' . ($weekday - 1) . ' days')->format('Y-m-d');
}

function normalize_plan_type(mixed $value): string
{
    return (string)$value === 'weekly' ? 'weekly' : 'daily';
}

function enum_value(mixed $value, array $allowed): string
{
    $text = trim((string)($value ?? ''));
    return in_array($text, $allowed, true) ? $text : '';
}

/** Platnost zóny z náhledu: '' (jen ten den), 'open' (dokud ji neukončím) nebo datum ne dřív než náhled. */
function plan_zone_valid_to(mixed $value, string $planDate): string
{
    $text = trim((string)($value ?? ''));
    if ($text === 'open') {
        return 'open';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) && $text >= $planDate) {
        return $text;
    }
    return '';
}

function save_plan(array $data): array
{
    $date = trim((string)value($data, 'plan_date', ''));
    $market = strtoupper(trim((string)value($data, 'market', '')));
    $session = normalize_session((string)value($data, 'session', 'intraday')) ?: 'intraday';
    $type = normalize_plan_type(value($data, 'plan_type', 'daily'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $market === '') {
        json_response(['error' => 'Datum a trh jsou povinné.'], 422);
    }
    if ($type === 'weekly') {
        $date = week_start($date);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $id = nullable_int(value($data, 'id'));
        $clash = fetch_one('SELECT id FROM plans WHERE plan_type = ? AND plan_date = ? AND market = ? AND session = ?', [$type, $date, $market, $session]);
        if ($id === null) {
            $id = $clash === null ? null : (int)$clash['id'];
        } elseif ($clash !== null && (int)$clash['id'] !== $id) {
            $pdo->rollBack();
            json_response(['error' => sprintf('%s náhled pro %s, %s a stejný typ obchodu už existuje. Otevři ho v Historii náhledů.', $type === 'weekly' ? 'Týdenní' : 'Denní', $date, $market)], 409);
        }

        $pa = ['long', 'short', 'balance'];
        $fields = [
            'plan_type' => $type,
            'plan_date' => $date,
            'market' => $market,
            'session' => $session,
            'status' => (string)value($data, 'status', 'draft'),
            'bias' => (string)value($data, 'bias', 'neutral'),
            'bias_description' => (string)value($data, 'bias_description', ''),
            'bias_confirm' => (string)value($data, 'bias_confirm', ''),
            'bias_invalidation' => (string)value($data, 'bias_invalidation', ''),
            'pa_monthly' => enum_value(value($data, 'pa_monthly'), $pa),
            'pa_weekly' => enum_value(value($data, 'pa_weekly'), $pa),
            'pa_daily' => enum_value(value($data, 'pa_daily'), $pa),
            'pa_monthly_note' => (string)value($data, 'pa_monthly_note', ''),
            'pa_weekly_note' => (string)value($data, 'pa_weekly_note', ''),
            'pa_daily_note' => (string)value($data, 'pa_daily_note', ''),
            'mp_weekly' => enum_value(value($data, 'mp_weekly'), $pa),
            'mp_daily' => enum_value(value($data, 'mp_daily'), $pa),
            'mp_weekly_note' => (string)value($data, 'mp_weekly_note', ''),
            'mp_daily_note' => (string)value($data, 'mp_daily_note', ''),
            'profile_shape' => enum_value(value($data, 'profile_shape'), PROFILE_SHAPES),
            'ref_high' => nullable_float(value($data, 'ref_high')),
            'ref_vah' => nullable_float(value($data, 'ref_vah')),
            'ref_poc' => nullable_float(value($data, 'ref_poc')),
            'ref_val' => nullable_float(value($data, 'ref_val')),
            'ref_low' => nullable_float(value($data, 'ref_low')),
            'ref_close' => nullable_float(value($data, 'ref_close')),
            'globex_open' => (string)value($data, 'globex_open', ''),
            'open_type' => (string)value($data, 'open_type', ''),
            'value_area' => (string)value($data, 'value_area', ''),
            'vpoc' => (string)value($data, 'vpoc', ''),
            'auction' => (string)value($data, 'auction', ''),
            'weekly_position' => (string)value($data, 'weekly_position', ''),
            'previous_close' => (string)value($data, 'previous_close', ''),
            'eu_open' => (string)value($data, 'eu_open', ''),
            'ny_open' => (string)value($data, 'ny_open', ''),
            'initial_balance' => (string)value($data, 'initial_balance', ''),
            'single_print' => (string)value($data, 'single_print', ''),
            'tail' => (string)value($data, 'tail', ''),
            'important_news' => (string)value($data, 'important_news', ''),
            'no_trade_conditions' => (string)value($data, 'no_trade_conditions', ''),
            'general_notes' => (string)value($data, 'general_notes', ''),
        ];

        if ($id === null) {
            $fields['created_at'] = utc_now();
            $fields['updated_at'] = utc_now();
            $columns = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $statement = $pdo->prepare("INSERT INTO plans ($columns) VALUES ($placeholders)");
            $statement->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
        } else {
            $fields['updated_at'] = utc_now();
            $sets = implode(', ', array_map(static fn(string $column): string => "$column = ?", array_keys($fields)));
            $statement = $pdo->prepare("UPDATE plans SET $sets WHERE id = ?");
            $statement->execute([...array_values($fields), $id]);
        }

        $pdo->prepare('DELETE FROM zones WHERE plan_id = ?')->execute([$id]);
        $zoneSql = 'INSERT INTO zones (plan_id, sort_order, name, direction, price_low, price_high, priority, source, invalidation, trigger, stop_loss, tp1, tp2, rr, status, long_entry, long_skip, short_entry, short_skip, valid_to, zone_type, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $zoneStatement = $pdo->prepare($zoneSql);
        foreach ((array)value($data, 'zones', []) as $index => $zone) {
            if (!is_array($zone) || !row_has_content($zone, ['name', 'price_low', 'price_high', 'source', 'invalidation', 'trigger', 'stop_loss', 'tp1', 'tp2', 'rr', 'long_entry', 'long_skip', 'short_entry', 'short_skip', 'note'])) {
                continue;
            }
            $low = nullable_float(value($zone, 'price_low'));
            $high = nullable_float(value($zone, 'price_high'));
            if ($low !== null && $high !== null && $low > $high) {
                [$low, $high] = [$high, $low];
            }
            $zoneStatement->execute([
                $id, $index, value($zone, 'name', ''), value($zone, 'direction', ''), $low, $high,
                value($zone, 'priority', ''), value($zone, 'source', ''), value($zone, 'invalidation', ''),
                value($zone, 'trigger', ''), nullable_float(value($zone, 'stop_loss')), nullable_float(value($zone, 'tp1')),
                nullable_float(value($zone, 'tp2')), nullable_float(value($zone, 'rr')), value($zone, 'status', 'planned'),
                (string)value($zone, 'long_entry', ''), (string)value($zone, 'long_skip', ''),
                (string)value($zone, 'short_entry', ''), (string)value($zone, 'short_skip', ''),
                plan_zone_valid_to(value($zone, 'valid_to', ''), $date), enum_value(value($zone, 'zone_type'), HS_ZONE_TYPES),
                mb_substr((string)value($zone, 'note', ''), 0, 1000),
            ]);
        }

        $pdo->prepare('DELETE FROM levels WHERE plan_id = ?')->execute([$id]);
        $levelStatement = $pdo->prepare('INSERT INTO levels (plan_id, sort_order, name, price, kind, source, line_style, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ((array)value($data, 'levels', []) as $index => $level) {
            if (!is_array($level)) {
                continue;
            }
            if (!row_has_content($level, ['name', 'price', 'source', 'note'])) {
                continue;
            }
            $price = nullable_float(value($level, 'price'));
            $name = trim((string)value($level, 'name', ''));
            $levelStatement->execute([
                $id, $index, $name, $price, value($level, 'kind', ''),
                value($level, 'source', ''), value($level, 'line_style', 'solid'), value($level, 'note', ''),
            ]);
        }

        $pdo->prepare('DELETE FROM ideas WHERE plan_id = ?')->execute([$id]);
        $ideaSql = 'INSERT INTO ideas (plan_id, sort_order, name, direction, zone_name, trigger, entry_price, stop_loss, tp1, tp2, final_tp, rr, status, notes, entry_ts, outcome, trade_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $ideaStatement = $pdo->prepare($ideaSql);
        foreach ((array)value($data, 'ideas', []) as $index => $idea) {
            if (!is_array($idea) || !row_has_content($idea, ['name', 'zone_name', 'trigger', 'entry_price', 'stop_loss', 'tp1', 'tp2', 'final_tp', 'rr', 'notes'])) {
                continue;
            }
            $ideaStatement->execute([
                $id, $index, value($idea, 'name', ''), value($idea, 'direction', ''), value($idea, 'zone_name', ''),
                value($idea, 'trigger', ''), nullable_float(value($idea, 'entry_price')), nullable_float(value($idea, 'stop_loss')),
                nullable_float(value($idea, 'tp1')), nullable_float(value($idea, 'tp2')), nullable_float(value($idea, 'final_tp')),
                nullable_float(value($idea, 'rr')), value($idea, 'status', 'waiting'), value($idea, 'notes', ''),
                hs_entry_ts_in_day(value($idea, 'entry_ts'), $date), enum_value(value($idea, 'outcome'), HS_OUTCOMES), nullable_int(value($idea, 'trade_id')),
            ]);
        }

        // Reference z Market Profile: single prints, poor high/low, naked POC a podobně.
        $pdo->prepare('DELETE FROM plan_refs WHERE plan_id = ?')->execute([$id]);
        $refStatement = $pdo->prepare('INSERT INTO plan_refs (plan_id, sort_order, kind, price_low, price_high, status, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ((array)value($data, 'refs', []) as $index => $ref) {
            if (!is_array($ref) || !row_has_content($ref, ['price_low', 'price_high', 'note'])) {
                continue;
            }
            $low = nullable_float(value($ref, 'price_low'));
            $high = nullable_float(value($ref, 'price_high'));
            if ($low === null && $high !== null) {
                [$low, $high] = [$high, null];
            }
            if ($low !== null && $high !== null && $low > $high) {
                [$low, $high] = [$high, $low];
            }
            $kind = enum_value(value($ref, 'kind'), REF_KINDS) ?: 'other';
            $status = (string)value($ref, 'status', 'open') === 'filled' ? 'filled' : 'open';
            $refStatement->execute([$id, $index, $kind, $low, $high, $status, trim((string)value($ref, 'note', ''))]);
        }

        // DiNapoli levely, trend a vlastní pole náhledu.
        $storedCustom = (string)(fetch_one('SELECT custom FROM plans WHERE id = ?', [$id])['custom'] ?? '{}');
        save_plan_extras($pdo, $id, $data, $storedCustom);

        $pdo->commit();
        return plan_payload($id) ?? [];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function market_point_value(string $market): ?float
{
    $points = ['ES' => 50.0, 'MES' => 5.0, 'NQ' => 20.0, 'MNQ' => 2.0, 'GC' => 100.0, 'MGC' => 10.0, 'CL' => 1000.0, 'MCL' => 100.0, '6E' => 125000.0];
    return $points[strtoupper(trim($market))] ?? null;
}

function market_multiplier(string $market): float
{
    return market_point_value($market) ?? 1.0;
}

function calculate_trade(array $data): array
{
    $entry = nullable_float(value($data, 'entry_price'));
    $exit = nullable_float(value($data, 'exit_price'));
    $stop = nullable_float(value($data, 'stop_loss'));
    $risk = nullable_float(value($data, 'risk_amount'));
    $fees = nullable_float(value($data, 'fees')) ?? 0.0;
    $direction = strtolower((string)value($data, 'direction', 'long')) === 'short' ? -1.0 : 1.0;
    $pointValue = workspace_point_value((string)value($data, 'market', '')) ?? nullable_float(value($data, 'point_value'));

    $distance = $entry !== null && $stop !== null ? abs($entry - $stop) : null;
    $quantity = nullable_float(value($data, 'quantity'));

    // Riziko na jeden bod pohybu ceny. Drží celou pozici dohromady, takže se
    // hodnota bodu z výpočtu P&L i R vykrátí a nemusí se vůbec zadávat.
    if ($risk !== null && $risk > 0 && $distance !== null && $distance > 0) {
        $riskPerPoint = $risk / $distance;
        $quantity = $pointValue !== null && $pointValue > 0 ? $riskPerPoint / $pointValue : $quantity;
    } else {
        $quantity = $quantity !== null && $quantity > 0 ? $quantity : 1.0;
        $riskPerPoint = $quantity * ($pointValue ?? 1.0);
    }

    $resultUsd = nullable_float(value($data, 'result_usd'));
    $resultR = nullable_float(value($data, 'result_r'));
    if ($resultUsd === null && $entry !== null && $exit !== null) {
        $resultUsd = (($exit - $entry) * $direction * $riskPerPoint) - $fees;
    }
    if ($resultR === null && $resultUsd !== null) {
        $reference = $risk !== null && $risk > 0
            ? $risk
            : ($distance !== null ? $distance * $riskPerPoint : null);
        $resultR = $reference !== null && $reference > 0 ? $resultUsd / $reference : null;
    }
    $resolvedPointValue = $pointValue;
    $quantity = $quantity !== null && $quantity > 0 ? $quantity : 1.0;
    $resultUsd = $resultUsd === null || !is_finite($resultUsd) ? null : round($resultUsd, 2);
    $resultR = $resultR === null || !is_finite($resultR) ? null : round($resultR, 2);
    return [$resultR, $resultUsd, is_finite($quantity) ? round($quantity, 4) : 1.0, $resolvedPointValue];
}

function strategy_payload(int $id): ?array
{
    $strategy = fetch_one('SELECT * FROM strategies WHERE id = ?', [$id]);
    if ($strategy === null) {
        return null;
    }
    $strategy['screenshots'] = fetch_all('SELECT id, strategy_id, role, original_name, mime_type, size_bytes, caption, created_at FROM screenshots WHERE strategy_id = ? ORDER BY created_at, id', [$id]);
    $strategy['trade_count'] = (int)(fetch_one('SELECT COUNT(*) AS total FROM trades WHERE strategy_id = ?', [$id])['total'] ?? 0);
    return $strategy;
}

function save_strategy(array $data): array
{
    $name = trim((string)value($data, 'name', ''));
    if ($name === '') {
        json_response(['error' => 'Název strategie je povinný.'], 422);
    }
    $style = trim((string)value($data, 'style', ''));
    if (!in_array($style, ['trend', 'reversal', 'both'], true)) {
        $style = '';
    }
    $fields = [
        'name' => $name,
        'timeframe' => trim((string)value($data, 'timeframe', '')),
        'style' => $style,
        'notes' => (string)value($data, 'notes', ''),
    ];

    $pdo = db();
    $id = nullable_int(value($data, 'id'));
    $duplicate = fetch_one('SELECT id FROM strategies WHERE name = ? COLLATE NOCASE', [$name]);
    if ($duplicate !== null && ($id === null || (int)$duplicate['id'] !== $id)) {
        json_response(['error' => 'Strategie s tímto názvem už existuje.'], 409);
    }

    if ($id === null) {
        $statement = $pdo->prepare('INSERT INTO strategies (name, timeframe, style, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([...array_values($fields), utc_now(), utc_now()]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $statement = $pdo->prepare('UPDATE strategies SET name = ?, timeframe = ?, style = ?, notes = ?, updated_at = ? WHERE id = ?');
        $statement->execute([...array_values($fields), utc_now(), $id]);
        $pdo->prepare('UPDATE trades SET strategy = ? WHERE strategy_id = ?')->execute([$name, $id]);
    }
    return strategy_payload($id) ?? [];
}

function strategy_statistics(): array
{
    $sql = <<<'SQL'
SELECT
    COALESCE(s.id, 0) AS strategy_id,
    COALESCE(s.name, NULLIF(TRIM(t.strategy), ''), 'Bez strategie') AS name,
    s.timeframe AS timeframe,
    s.style AS style,
    COUNT(*) AS trades,
    COUNT(t.result_r) AS rated_trades,
    COALESCE(SUM(t.result_r), 0) AS total_r,
    COALESCE(SUM(t.result_usd), 0) AS total_usd,
    COALESCE(SUM(CASE WHEN t.result_r > 0 THEN t.result_r ELSE 0 END), 0) AS gross_win_r,
    ABS(COALESCE(SUM(CASE WHEN t.result_r < 0 THEN t.result_r ELSE 0 END), 0)) AS gross_loss_r,
    MAX(t.result_r) AS best_r,
    MIN(t.result_r) AS worst_r,
    SUM(CASE WHEN t.followed_plan = 1 THEN 1 ELSE 0 END) AS followed,
    COUNT(t.followed_plan) AS judged,
    COUNT(t.execution_rating) AS rated_execution,
    COALESCE(AVG(t.execution_rating), 0) AS avg_execution,
    MAX(t.trade_date) AS last_trade
FROM trades t
LEFT JOIN strategies s ON s.id = t.strategy_id
GROUP BY COALESCE(s.id, 0), COALESCE(s.name, NULLIF(TRIM(t.strategy), ''), 'Bez strategie')
SQL;

    $rows = fetch_all($sql);
    foreach ($rows as &$row) {
        $rated = (int)$row['rated_trades'];
        $row['expectancy_r'] = $rated > 0 ? (float)$row['total_r'] / $rated : null;
        $grossLoss = (float)$row['gross_loss_r'];
        $row['profit_factor'] = $grossLoss > 0 ? (float)$row['gross_win_r'] / $grossLoss : null;
        $judged = (int)$row['judged'];
        $row['plan_adherence'] = $judged > 0 ? ((int)$row['followed'] / $judged) * 100 : null;
        $row['avg_execution'] = (int)$row['rated_execution'] > 0 ? (float)$row['avg_execution'] : null;
    }
    unset($row);

    usort($rows, static fn(array $a, array $b): int => (float)$b['total_r'] <=> (float)$a['total_r']);
    return $rows;
}

function strategy_series(int $maxDates = 400): array
{
    $rows = fetch_all(<<<'SQL'
SELECT
    t.trade_date AS trade_date,
    COALESCE(s.id, 0) AS strategy_id,
    COALESCE(s.name, NULLIF(TRIM(t.strategy), ''), 'Bez strategie') AS name,
    COALESCE(SUM(t.result_r), 0) AS day_r
FROM trades t
LEFT JOIN strategies s ON s.id = t.strategy_id
WHERE t.result_r IS NOT NULL
GROUP BY t.trade_date, COALESCE(s.id, 0), COALESCE(s.name, NULLIF(TRIM(t.strategy), ''), 'Bez strategie')
ORDER BY t.trade_date
SQL);

    if ($rows === []) {
        return ['dates' => [], 'series' => []];
    }

    $dates = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['trade_date'], $rows)));
    sort($dates);
    if (count($dates) > $maxDates) {
        $dates = array_slice($dates, -$maxDates);
    }
    $dateIndex = array_flip($dates);

    $byStrategy = [];
    foreach ($rows as $row) {
        $name = (string)$row['name'];
        $date = (string)$row['trade_date'];
        if (!isset($dateIndex[$date])) {
            continue;
        }
        if (!isset($byStrategy[$name])) {
            $byStrategy[$name] = ['id' => (int)$row['strategy_id'], 'name' => $name, 'daily' => []];
        }
        $byStrategy[$name]['daily'][$date] = ((float)($byStrategy[$name]['daily'][$date] ?? 0)) + (float)$row['day_r'];
    }

    $series = [];
    foreach ($byStrategy as $strategy) {
        $running = 0.0;
        $started = false;
        $points = [];
        foreach ($dates as $date) {
            if (isset($strategy['daily'][$date])) {
                $running += $strategy['daily'][$date];
                $started = true;
            }
            // Než strategie poprvé obchodovala, čára neexistuje. Pak se hodnota drží.
            $points[] = $started ? round($running, 4) : null;
        }
        $series[] = ['id' => $strategy['id'], 'name' => $strategy['name'], 'points' => $points, 'total_r' => round($running, 2)];
    }

    usort($series, static fn(array $a, array $b): int => $b['total_r'] <=> $a['total_r']);
    return ['dates' => $dates, 'series' => $series];
}

function calendar_month(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = gmdate('Y-m');
    }

    $days = [];
    $collect = static function (string $date) use (&$days): array {
        if (!isset($days[$date])) {
            $days[$date] = [
                'date' => $date,
                'trades' => 0,
                'total_r' => 0.0,
                'total_usd' => 0.0,
                'broken_rules' => 0,
                'plan_id' => null,
                'plan_market' => null,
                'events' => [],
                'psych_band' => null,
            ];
        }
        return $days[$date];
    };

    foreach (fetch_all(
        'SELECT trade_date, COUNT(*) AS trades, COALESCE(SUM(result_r), 0) AS total_r, COALESCE(SUM(result_usd), 0) AS total_usd, SUM(CASE WHEN followed_plan = 0 OR execution_rating >= 4 THEN 1 ELSE 0 END) AS broken FROM trades WHERE substr(trade_date, 1, 7) = ? GROUP BY trade_date',
        [$month]
    ) as $row) {
        $date = (string)$row['trade_date'];
        $collect($date);
        $days[$date]['trades'] = (int)$row['trades'];
        $days[$date]['total_r'] = (float)$row['total_r'];
        $days[$date]['total_usd'] = (float)$row['total_usd'];
        $days[$date]['broken_rules'] = (int)$row['broken'];
    }

    // Denní náhled má přednost; týdenní se ukazuje u pondělí, ke kterému patří.
    foreach (fetch_all("SELECT id, plan_date, market, plan_type FROM plans WHERE substr(plan_date, 1, 7) = ? ORDER BY plan_type = 'daily', id", [$month]) as $row) {
        $date = (string)$row['plan_date'];
        $collect($date);
        if ((string)$row['plan_type'] === 'weekly') {
            $days[$date]['weekly_plan_id'] = (int)$row['id'];
        }
        $days[$date]['plan_id'] = (int)$row['id'];
        $days[$date]['plan_type'] = (string)$row['plan_type'];
        $days[$date]['plan_market'] = (string)$row['market'];
    }

    foreach (fetch_all('SELECT * FROM calendar_events WHERE substr(event_date, 1, 7) = ? ORDER BY time_label, id', [$month]) as $row) {
        $date = (string)$row['event_date'];
        $collect($date);
        $days[$date]['events'][] = $row;
    }

    foreach (fetch_all('SELECT check_date, band FROM psych_checks WHERE substr(check_date, 1, 7) = ? ORDER BY id', [$month]) as $row) {
        $date = (string)$row['check_date'];
        $collect($date);
        $days[$date]['psych_band'] = (string)$row['band'];
    }

    ksort($days);
    $summary = fetch_one(
        'SELECT COUNT(*) AS trades, COUNT(DISTINCT trade_date) AS trading_days, COALESCE(SUM(result_r), 0) AS total_r, COALESCE(SUM(result_usd), 0) AS total_usd FROM trades WHERE substr(trade_date, 1, 7) = ?',
        [$month]
    ) ?? [];

    return ['month' => $month, 'days' => array_values($days), 'summary' => $summary];
}

function save_calendar_event(array $data): array
{
    $date = trim((string)value($data, 'event_date', ''));
    $title = trim((string)value($data, 'title', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $title === '') {
        json_response(['error' => 'Datum a název události jsou povinné.'], 422);
    }
    $kind = (string)value($data, 'kind', 'news');
    if (!in_array($kind, ['news', 'holiday', 'note'], true)) {
        $kind = 'news';
    }
    $impact = (string)value($data, 'impact', '');
    if (!in_array($impact, ['high', 'medium', 'low', ''], true)) {
        $impact = '';
    }

    $pdo = db();
    $statement = $pdo->prepare('INSERT INTO calendar_events (event_date, kind, title, time_label, impact, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([
        $date, $kind, $title, trim((string)value($data, 'time_label', '')),
        $impact, (string)value($data, 'notes', ''), utc_now(),
    ]);
    return fetch_one('SELECT * FROM calendar_events WHERE id = ?', [(int)$pdo->lastInsertId()]) ?? [];
}

function format_money(float $value): string
{
    return ($value < 0 ? '-$' : '$') . number_format(abs($value), 2, ',', ' ');
}

function format_r(float $value): string
{
    return number_format($value, 2, ',', ' ') . 'R';
}

function trades_word(int $count): string
{
    return $count === 1 ? 'obchodu' : 'obchodů';
}

function audit_tolerance(array $account): float
{
    $dailyRisk = (float)($account['daily_risk'] ?? 0);
    $balance = (float)($account['starting_balance'] ?? 0);
    $base = $dailyRisk > 0 ? $dailyRisk * 0.4 : $balance * 0.002;
    return max(20.0, round($base, 2));
}

function days_between(string $from, string $to): int
{
    try {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
    } catch (Throwable) {
        return 0;
    }
    return (int)$start->diff($end)->format('%r%a');
}

function account_overview(array $account): array
{
    if ($account === []) {
        return [];
    }
    $id = (int)$account['id'];
    $openedAt = (string)$account['opened_at'];
    $totals = fetch_one(
        'SELECT COUNT(*) AS trades, COALESCE(SUM(result_usd), 0) AS net_usd, COALESCE(SUM(result_r), 0) AS net_r, COALESCE(SUM(fees), 0) AS fees FROM trades WHERE account_id = ? AND trade_date >= ?',
        [$id, $openedAt]
    ) ?? [];
    $last = fetch_one('SELECT id, audit_date, status, difference, verdict, created_at FROM account_audits WHERE account_id = ? ORDER BY audit_date DESC, id DESC LIMIT 1', [$id]);

    $today = gmdate('Y-m-d');
    $registeredAt = substr((string)$account['created_at'], 0, 10);
    $reference = $last === null ? $registeredAt : (string)$last['audit_date'];
    $daysSince = max(0, days_between($reference, $today));

    $account['trades_count'] = (int)($totals['trades'] ?? 0);
    $account['net_usd'] = (float)($totals['net_usd'] ?? 0);
    $account['net_r'] = (float)($totals['net_r'] ?? 0);
    $account['fees_total'] = (float)($totals['fees'] ?? 0);
    $account['expected_balance'] = (float)$account['starting_balance'] + $account['net_usd'];
    $account['tolerance'] = audit_tolerance($account);
    $account['last_audit'] = $last;
    $account['audited_before'] = $last !== null;
    $account['days_since_audit'] = $daysSince;
    $account['days_to_audit'] = max(0, AUDIT_INTERVAL_DAYS - $daysSince);
    $account['audit_due'] = $daysSince >= AUDIT_INTERVAL_DAYS;
    $account['audit_soon'] = !$account['audit_due'] && $account['days_to_audit'] <= AUDIT_WARNING_DAYS;
    $account['next_audit_date'] = date('Y-m-d', strtotime($reference . ' +' . AUDIT_INTERVAL_DAYS . ' days') ?: time());
    return $account;
}

function account_audit_result(array $account, string $auditDate, float $reportedBalance): array
{
    $id = (int)$account['id'];
    $openedAt = (string)$account['opened_at'];
    $stats = fetch_one(
        'SELECT COUNT(*) AS trades, COALESCE(SUM(result_usd), 0) AS net_usd, COALESCE(SUM(result_r), 0) AS net_r, COALESCE(SUM(fees), 0) AS fees, COALESCE(AVG(NULLIF(risk_amount, 0)), 0) AS avg_risk, SUM(CASE WHEN followed_plan = 1 THEN 1 ELSE 0 END) AS followed, COUNT(followed_plan) AS judged, COUNT(execution_rating) AS rated, COALESCE(AVG(execution_rating), 0) AS avg_rating, SUM(CASE WHEN result_usd IS NULL THEN 1 ELSE 0 END) AS open_trades FROM trades WHERE account_id = ? AND trade_date >= ? AND trade_date <= ?',
        [$id, $openedAt, $auditDate]
    ) ?? [];
    $monthStats = fetch_one(
        'SELECT COUNT(*) AS trades, COALESCE(SUM(result_r), 0) AS month_r, COALESCE(SUM(result_usd), 0) AS month_usd FROM trades WHERE account_id = ? AND substr(trade_date, 1, 7) = ?',
        [$id, substr($auditDate, 0, 7)]
    ) ?? [];

    $expected = (float)$account['starting_balance'] + (float)($stats['net_usd'] ?? 0);
    $difference = $reportedBalance - $expected;
    $tolerance = audit_tolerance($account);
    $status = abs($difference) <= $tolerance ? 'ok' : 'mismatch';

    $averageRisk = (float)($stats['avg_risk'] ?? 0);
    if ($averageRisk <= 0) {
        $averageRisk = (float)($account['daily_risk'] ?? 0);
    }
    $missing = $averageRisk > 0 ? $difference / $averageRisk : null;

    $judged = (int)($stats['judged'] ?? 0);
    $adherence = $judged > 0 ? ((int)($stats['followed'] ?? 0) / $judged) * 100 : null;
    $rated = (int)($stats['rated'] ?? 0);
    $executionRating = $rated > 0 ? (float)$stats['avg_rating'] : null;
    $tradesCount = (int)($stats['trades'] ?? 0);
    $openTrades = (int)($stats['open_trades'] ?? 0);
    $monthR = (float)($monthStats['month_r'] ?? 0);
    $monthUsd = (float)($monthStats['month_usd'] ?? 0);

    $parts = [];
    if ($status === 'ok') {
        $parts[] = sprintf(
            'Evidence obchodů sedí. Rozdíl mezi nahlášeným a očekávaným zůstatkem je %s, tedy uvnitř tolerance %s.',
            format_money($difference),
            format_money($tolerance)
        );
    } else {
        $direction = $difference < 0
            ? 'Reálný zůstatek je nižší, než co vychází z deníku, takže nejspíš chybí ztrátový obchod nebo nezapsané poplatky.'
            : 'Reálný zůstatek je vyšší, než co vychází z deníku, takže nejspíš chybí ziskový obchod nebo vklad.';
        $scale = $missing !== null ? sprintf(' Odchylka odpovídá zhruba %s průměrného risku.', format_r(abs($missing))) : '';
        $parts[] = sprintf(
            'Evidence obchodů nesedí. Rozdíl %s překračuje toleranci %s. %s%s',
            format_money($difference),
            format_money($tolerance),
            $direction,
            $scale
        );
    }
    if ($openTrades > 0) {
        $parts[] = sprintf('Pozor, u %d %s chybí vyplněný výsledek, takže do kontroly zůstatku nevstupují.', $openTrades, trades_word($openTrades));
    }
    $parts[] = $adherence === null
        ? 'Dodržení plánu nelze vyhodnotit, u obchodů chybí odpověď na otázku, jestli byl plán dodržen.'
        : sprintf('Plán byl dodržen u %s %% hodnocených obchodů.', number_format($adherence, 0, ',', ' '));
    if ($executionRating !== null) {
        $parts[] = sprintf('Průměrné hodnocení exekuce je %s z 5, kde 1 znamená vše splněno podle plánu.', number_format($executionRating, 1, ',', ' '));
    }
    $monthTrades = (int)($monthStats['trades'] ?? 0);
    $parts[] = sprintf(
        'Výsledek měsíce %s je %s (%s) z %d %s.',
        substr($auditDate, 0, 7),
        format_r($monthR),
        format_money($monthUsd),
        $monthTrades,
        trades_word($monthTrades)
    );

    return [
        'expected_balance' => $expected,
        'reported_balance' => $reportedBalance,
        'difference' => $difference,
        'tolerance' => $tolerance,
        'status' => $status,
        'trades_count' => $tradesCount,
        'open_trades' => $openTrades,
        'missing_trades' => $missing,
        'month_r' => $monthR,
        'month_usd' => $monthUsd,
        'plan_adherence' => $adherence,
        'execution_rating' => $executionRating,
        'verdict' => implode(' ', $parts),
    ];
}

function save_account(array $data): array
{
    $name = trim((string)value($data, 'name', ''));
    $balance = nullable_float(value($data, 'starting_balance'));
    if ($name === '' || $balance === null || $balance < 0) {
        json_response(['error' => 'Název účtu a aktuální stav konta jsou povinné.'], 422);
    }
    if (fetch_one('SELECT id FROM accounts WHERE name = ? COLLATE NOCASE', [$name]) !== null) {
        json_response(['error' => 'Účet s tímto názvem už existuje.'], 409);
    }
    $openedAt = trim((string)value($data, 'opened_at', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $openedAt)) {
        $openedAt = gmdate('Y-m-d');
    }
    $currency = strtoupper(trim((string)value($data, 'currency', 'USD')));
    $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';

    $pdo = db();
    $statement = $pdo->prepare('INSERT INTO accounts (name, broker, currency, starting_balance, daily_risk, opened_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([
        $name,
        trim((string)value($data, 'broker', '')),
        $currency,
        $balance,
        nullable_float(value($data, 'daily_risk')),
        $openedAt,
        utc_now(),
    ]);
    return account_overview(fetch_one('SELECT * FROM accounts WHERE id = ?', [(int)$pdo->lastInsertId()]) ?? []);
}

function save_audit(array $data): array
{
    $accountId = nullable_int(value($data, 'account_id'));
    $reported = nullable_float(value($data, 'reported_balance'));
    if ($accountId === null || $reported === null) {
        json_response(['error' => 'Účet a aktuální stav konta jsou povinné.'], 422);
    }
    $account = fetch_one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if ($account === null) {
        json_response(['error' => 'Účet nebyl nalezen.'], 404);
    }
    $auditDate = trim((string)value($data, 'audit_date', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDate)) {
        $auditDate = gmdate('Y-m-d');
    }

    $result = account_audit_result($account, $auditDate, $reported);
    $pdo = db();
    $statement = $pdo->prepare('INSERT INTO account_audits (account_id, audit_date, reported_balance, expected_balance, difference, tolerance, status, trades_count, missing_trades, month_r, month_usd, plan_adherence, execution_rating, verdict, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([
        $accountId, $auditDate, $reported, $result['expected_balance'], $result['difference'], $result['tolerance'],
        $result['status'], $result['trades_count'], $result['missing_trades'], $result['month_r'], $result['month_usd'],
        $result['plan_adherence'], $result['execution_rating'], $result['verdict'], (string)value($data, 'notes', ''), utc_now(),
    ]);
    $audit = fetch_one('SELECT * FROM account_audits WHERE id = ?', [(int)$pdo->lastInsertId()]) ?? [];
    $audit['open_trades'] = $result['open_trades'];
    $audit['account'] = account_overview($account);
    return $audit;
}

function save_trade(array $data): array
{
    $date = trim((string)value($data, 'trade_date', ''));
    $market = strtoupper(trim((string)value($data, 'market', '')));
    $direction = strtolower(trim((string)value($data, 'direction', '')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $market === '' || !in_array($direction, ['long', 'short'], true)) {
        json_response(['error' => 'Datum, trh a směr obchodu jsou povinné.'], 422);
    }

    [$resultR, $resultUsd, $quantity, $pointValue] = calculate_trade($data);

    $strategyId = nullable_int(value($data, 'strategy_id'));
    $strategyName = trim((string)value($data, 'strategy', ''));
    if ($strategyId !== null) {
        $strategy = fetch_one('SELECT name FROM strategies WHERE id = ?', [$strategyId]);
        if ($strategy === null) {
            $strategyId = null;
        } else {
            $strategyName = (string)$strategy['name'];
        }
    }

    $fields = [
        'plan_id' => nullable_int(value($data, 'plan_id')),
        'trade_date' => $date,
        'market' => $market,
        'session' => normalize_session((string)value($data, 'session', '')),
        'strategy' => $strategyName,
        'strategy_id' => $strategyId,
        'account_id' => nullable_int(value($data, 'account_id')),
        'direction' => $direction,
        'entry_price' => nullable_float(value($data, 'entry_price')),
        'exit_price' => nullable_float(value($data, 'exit_price')),
        'quantity' => $quantity,
        'risk_amount' => nullable_float(value($data, 'risk_amount')),
        'point_value' => $pointValue,
        'stop_loss' => nullable_float(value($data, 'stop_loss')),
        'target_price' => nullable_float(value($data, 'target_price')),
        'fees' => nullable_float(value($data, 'fees')) ?? 0.0,
        'result_r' => $resultR,
        'result_usd' => $resultUsd,
        'followed_plan' => nullable_int(value($data, 'followed_plan')),
        'execution_rating' => nullable_int(value($data, 'execution_rating')),
        'emotion' => (string)value($data, 'emotion', ''),
        'mistake' => (string)value($data, 'mistake', ''),
        'notes' => (string)value($data, 'notes', ''),
    ];

    $pdo = db();
    $id = nullable_int(value($data, 'id'));
    $stored = $id === null ? '{}' : (string)(fetch_one('SELECT custom FROM trades WHERE id = ?', [$id])['custom'] ?? '{}');
    $fields['custom'] = merge_custom_values('trade', value($data, 'custom', []), $stored);
    // Časy vstupu a výstupu (pražský čas „HH:MM“ v obchodním dni); bez nich zůstanou uložené.
    foreach (['entry' => 'entry_ts', 'exit' => 'exit_ts'] as $prefix => $column) {
        if (array_key_exists($prefix . '_time', $data)) {
            $fields[$column] = hs_trade_time_field($id, $column, $date, (string)($data[$prefix . '_time'] ?? ''));
        }
    }
    if ($id === null) {
        $fields['created_at'] = utc_now();
        $fields['updated_at'] = utc_now();
        $columns = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $statement = $pdo->prepare("INSERT INTO trades ($columns) VALUES ($placeholders)");
        $statement->execute(array_values($fields));
        $id = (int)$pdo->lastInsertId();
    } else {
        $fields['updated_at'] = utc_now();
        $sets = implode(', ', array_map(static fn(string $column): string => "$column = ?", array_keys($fields)));
        $statement = $pdo->prepare("UPDATE trades SET $sets WHERE id = ?");
        $statement->execute([...array_values($fields), $id]);
    }
    return hs_trade_times(fetch_one('SELECT * FROM trades WHERE id = ?', [$id]) ?? []);
}

const EMOTION_LABELS = [
    'klid' => 'Klidný a soustředěný',
    'disciplinovana_trpelivost' => 'Disciplinovaná trpělivost',
    'lehka_nervozita' => 'Lehká nervozita',
    'netrpelivost' => 'Netrpělivost, nucený vstup',
    'nuda' => 'Nuda a potřeba akce',
    'vahavost' => 'Váhavost a pochybnosti o systému',
    'strach_ze_ztraty' => 'Strach ze ztráty',
    'fomo' => 'Strach z promeškání (FOMO)',
    'chamtivost' => 'Chamtivost, držení přes plán',
    'prehnane_sebevedomi' => 'Přehnané sebevědomí po sérii zisků',
    'revenge' => 'Frustrace a snaha o revenge trade',
    'externi_vlivy' => 'Rozrušený z externích vlivů',
    'unava' => 'Únava a nesoustředěnost',
    'uleva' => 'Úleva po uzavření pozice',
];

function emotion_label(string $key): string
{
    return EMOTION_LABELS[$key] ?? $key;
}

function trade_broke_rules(array $trade): bool
{
    $followed = $trade['followed_plan'];
    $rating = $trade['execution_rating'];
    return ($followed !== null && (int)$followed === 0) || ($rating !== null && (int)$rating >= 4);
}

function discipline_overview(): array
{
    $days = fetch_all(
        'SELECT trade_date, COUNT(*) AS trades, SUM(CASE WHEN followed_plan = 0 OR execution_rating >= 4 THEN 1 ELSE 0 END) AS broken, COALESCE(SUM(result_r), 0) AS total_r FROM trades GROUP BY trade_date HAVING broken > 0 ORDER BY trade_date DESC LIMIT 60'
    );

    $clean = fetch_one('SELECT COUNT(*) AS trades, COALESCE(AVG(result_r), 0) AS avg_r FROM trades WHERE followed_plan = 1 AND (execution_rating IS NULL OR execution_rating <= 3)') ?? [];
    $broken = fetch_one('SELECT COUNT(*) AS trades, COALESCE(AVG(result_r), 0) AS avg_r FROM trades WHERE followed_plan = 0 OR execution_rating >= 4') ?? [];

    $emotions = ['broken' => [], 'clean' => []];
    foreach (fetch_all("SELECT emotion, followed_plan, execution_rating FROM trades WHERE emotion IS NOT NULL AND TRIM(emotion) <> ''") as $row) {
        $bucket = trade_broke_rules($row) ? 'broken' : 'clean';
        foreach (explode(',', (string)$row['emotion']) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $emotions[$bucket][$token] = ($emotions[$bucket][$token] ?? 0) + 1;
        }
    }
    arsort($emotions['broken']);
    arsort($emotions['clean']);

    $topEmotions = [];
    foreach (array_slice($emotions['broken'], 0, 6, true) as $key => $count) {
        $topEmotions[] = [
            'key' => $key,
            'label' => emotion_label($key),
            'broken' => $count,
            'clean' => $emotions['clean'][$key] ?? 0,
        ];
    }

    return [
        'days' => $days,
        'clean_trades' => (int)($clean['trades'] ?? 0),
        'clean_avg_r' => (int)($clean['trades'] ?? 0) > 0 ? (float)$clean['avg_r'] : null,
        'broken_trades' => (int)($broken['trades'] ?? 0),
        'broken_avg_r' => (int)($broken['trades'] ?? 0) > 0 ? (float)$broken['avg_r'] : null,
        'emotions' => $topEmotions,
    ];
}

function discipline_analysis(string $date): array
{
    $trades = fetch_all('SELECT * FROM trades WHERE trade_date = ? ORDER BY id', [$date]);
    if ($trades === []) {
        json_response(['error' => 'Pro tento den nejsou zapsané žádné obchody.'], 404);
    }

    $offenders = array_values(array_filter($trades, 'trade_broke_rules'));
    $plan = fetch_one("SELECT id, market, plan_type FROM plans WHERE plan_type = 'daily' AND plan_date = ? ORDER BY id LIMIT 1", [$date])
        ?? fetch_one("SELECT id, market, plan_type FROM plans WHERE plan_type = 'weekly' AND plan_date <= ? AND plan_date > date(?, '-7 days') ORDER BY plan_date DESC, id LIMIT 1", [$date, $date]);
    $check = fetch_one('SELECT * FROM psych_checks WHERE check_date = ? ORDER BY id DESC LIMIT 1', [$date]);

    // Průměr risku se počítá z obchodů před daným obchodem, tedy i z těch dřívějších
    // téhož dne. Eskalace pozice se totiž nejčastěji děje uvnitř jedné session.
    $priorRisks = [];
    foreach (fetch_all('SELECT risk_amount FROM trades WHERE risk_amount IS NOT NULL AND risk_amount > 0 AND trade_date < ?', [$date]) as $row) {
        $priorRisks[] = (float)$row['risk_amount'];
    }

    $patterns = [];
    $questions = [];

    if ($plan === null) {
        $patterns[] = ['title' => 'Obchodoval jsi bez náhledu', 'detail' => 'K tomuhle dni není uložená žádná příprava, ani denní, ani týdenní. Bez předem daných zón a scénářů se rozhoduješ až v reálném čase, což je přesně situace, ve které se pravidla porušují nejsnáz.'];
        $questions[] = 'Co ti ten den zabránilo udělat přípravu, a šlo to ovlivnit?';
    } elseif ((string)$plan['plan_type'] === 'weekly') {
        $patterns[] = ['title' => 'Jen týdenní náhled, bez denní přípravy', 'detail' => 'Týdenní zóny jsi měl, ale ráno jsi je nepřevedl na konkrétní den: otevření, Initial Balance a scénáře pro danou session chyběly.'];
        $questions[] = 'Věděl jsi ráno, které z týdenních zón jsou pro dnešek ve hře?';
    }

    $runningR = 0.0;
    $lossBefore = false;
    foreach ($trades as $trade) {
        $isOffender = trade_broke_rules($trade);
        $result = $trade['result_r'] === null ? null : (float)$trade['result_r'];

        if ($isOffender && $lossBefore) {
            $patterns[] = ['title' => 'Porušení přišlo až po ztrátě', 'detail' => 'Obchod, u kterého jsi nedodržel pravidla, následoval po ztrátovém obchodu téhož dne. Tohle je klasický vzorec snahy o okamžitou nápravu.'];
            $questions[] = 'Co přesně sis říkal v okamžiku, kdy jsi po ztrátě otevíral další pozici?';
        }
        if ($isOffender && $runningR <= -1.0) {
            $patterns[] = ['title' => 'Obchodoval jsi po vyčerpání denního rizika', 'detail' => sprintf('Před tímto obchodem jsi byl na %s za den. Pokračování po dosažení denního limitu je rozhodnutí, které se dělá hůř než jeho dodržení.', format_r($runningR))];
            $questions[] = 'Máš denní stop jasně napsaný, nebo se rozhoduješ až v situaci?';
        }
        $risk = $trade['risk_amount'] === null ? null : (float)$trade['risk_amount'];
        $averageRisk = $priorRisks === [] ? null : array_sum($priorRisks) / count($priorRisks);
        if ($isOffender && $risk !== null && $averageRisk !== null && $risk > $averageRisk * 1.25) {
            $patterns[] = ['title' => 'Zvýšil jsi risk oproti svému průměru', 'detail' => sprintf('Risk %s je výrazně nad tvým obvyklým %s. Zvětšení pozice u obchodu mimo pravidla je kombinace, která dělá z malé chyby velkou.', format_money($risk), format_money($averageRisk))];
            $questions[] = 'Bylo zvětšení pozice součástí systému, nebo reakce na předchozí vývoj dne?';
        }
        if ($risk !== null && $risk > 0) {
            $priorRisks[] = $risk;
        }
        if ($isOffender && $trade['strategy_id'] === null) {
            $patterns[] = ['title' => 'Obchod nepatřil k žádné strategii', 'detail' => 'U tohoto obchodu není vybraný setup. Obchody mimo definované strategie nejdou vyhodnotit ani zlepšovat.'];
        }

        if ($result !== null) {
            $runningR += $result;
            $lossBefore = $result < 0;
        }
    }

    $emotionCounts = [];
    foreach ($offenders as $trade) {
        foreach (explode(',', (string)($trade['emotion'] ?? '')) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $emotionCounts[$token] = ($emotionCounts[$token] ?? 0) + 1;
            }
        }
    }
    if ($emotionCounts !== []) {
        arsort($emotionCounts);
        $names = array_map('emotion_label', array_keys($emotionCounts));
        $patterns[] = ['title' => 'Zaznamenané emoce u porušení', 'detail' => 'U obchodů mimo pravidla sis ten den označil: ' . implode(', ', $names) . '.'];
        $questions[] = 'Kdy přesně ten stav začal? Byl tady už před otevřením grafu, nebo ho spustil až vývoj trhu?';
    }

    if ($check !== null && in_array((string)$check['band'], ['amber', 'red'], true)) {
        $patterns[] = [
            'title' => 'Ráno jsi měl varovný signál',
            'detail' => sprintf('Rychlý test ten den skončil na %s se skóre %d. Test tedy zafungoval, jen na něj nedošlo.', $check['band'] === 'red' ? 'červené' : 'oranžové', (int)$check['score']),
        ];
        $questions[] = 'Co by muselo být jinak, abys výsledek testu ten den opravdu respektoval?';
    }

    $recentBroken = (int)(fetch_one(
        "SELECT COUNT(DISTINCT trade_date) AS days FROM trades WHERE (followed_plan = 0 OR execution_rating >= 4) AND trade_date >= date(?, '-30 day') AND trade_date <= ?",
        [$date, $date]
    )['days'] ?? 0);
    if ($recentBroken >= 3) {
        $patterns[] = ['title' => 'Není to ojedinělý den', 'detail' => sprintf('Za posledních 30 dní je takových dnů %d. Opakování znamená, že nejde o výpadek, ale o vzorec, který má svůj spouštěč.', $recentBroken)];
        $questions[] = 'Co mají ty dny společného? Den v týdnu, denní doba, typ trhu, nebo co se dělo mimo trading?';
    }

    $questions[] = 'Kdybys ten den měl prožít znovu, v jakém konkrétním okamžiku bys udělal něco jinak?';
    $questions[] = 'Jaké jedno pravidlo by tuhle chybu příště zastavilo, aniž bys musel spoléhat na sebekázeň v ostré situaci?';

    return [
        'date' => $date,
        'trades' => $trades,
        'offenders' => count($offenders),
        'day_r' => $runningR,
        'plan' => $plan,
        'psych_check' => $check,
        'patterns' => $patterns,
        'questions' => array_values(array_unique($questions)),
    ];
}

/**
 * Dimenze vstupního profilu. Nejsou převzaté z žádného konkrétního dotazníku,
 * protože veřejně ověřený nástroj s normami pro tradery neexistuje. Jsou to
 * konstrukty, které se v odborné literatuře opakovaně popisují, a u každého je
 * uvedené, odkud myšlenka pochází. Vyhodnocení proti vlastním obchodům
 * (profile_reality_check) je tou částí, která je opřená o data, ne o odhad.
 */
function psych_dimensions(): array
{
    return [
        'need' => [
            'label' => 'Tlak na výsledek',
            'source' => 'Ari Kiev popisuje obchodování z potřeby a rozdíl mezi hrou na výhru a hrou na neprohru.',
            'weak' => 'Když trh musí zaplatit konkrétní částku, přestáváš obchodovat setupy a začínáš obchodovat potřebu. Nejnebezpečnější dny jsou ty, kdy něco potřebuješ.',
            'strong' => 'Nemáš tendenci si na jednotlivý den nebo obchod věšet konkrétní částku. To je největší ochrana před nucenými vstupy.',
        ],
        'revenge' => [
            'label' => 'Reakce na ztrátu',
            'source' => 'Averze ke ztrátě podle Kahnemana a Tverského, v tradingu rozvedená Markem Douglasem.',
            'weak' => 'Ztráta u tebe nezůstane uzavřená a nese se do dalšího obchodu. Tady vzniká většina velkých jednodenních propadů.',
            'strong' => 'Ztrátu dokážeš uzavřít a další rozhodnutí děláš nezávisle na ní.',
        ],
        'cutting' => [
            'label' => 'Práce se ziskem a ztrátou',
            'source' => 'Disposition effect, tedy doložená tendence vybírat zisky brzy a držet ztráty dlouho.',
            'weak' => 'Zisky zavíráš dřív, než dojdou na cíl, a ztrátám dáváš víc prostoru, než měly mít. Výsledkem je dobrá úspěšnost a špatné R.',
            'strong' => 'Zisky necháváš doběhnout na cíl a ztráty zavíráš tam, kde jsi je naplánoval.',
        ],
        'consistency' => [
            'label' => 'Držení jednoho systému',
            'source' => 'Mark Douglas o konzistenci a o potřebě jistoty u jednotlivého obchodu.',
            'weak' => 'Po sérii ztrát máš tendenci systém měnit nebo improvizovat. Tím se ale znemožní vyhodnotit, jestli původní systém vůbec fungoval.',
            'strong' => 'Systému se držíš i v horší sérii, což je podmínka toho, aby se dal statisticky vyhodnotit.',
        ],
        'process' => [
            'label' => 'Příprava a rutina',
            'source' => 'Brett Steenbarger staví výkonnost na dodržení procesu a na systematickém sebepozorování.',
            'weak' => 'Rozhoduješ se až v reálném čase, kde je rozhodování nejdražší. Bez přípravy nemáš proti čemu poměřit, jestli byl vstup podle plánu.',
            'strong' => 'Chodíš k trhu připravený, takže v ostré situaci jen vykonáváš, místo abys teprve hledal.',
        ],
        'physical' => [
            'label' => 'Citlivost na fyzický stav',
            'source' => 'John Coates měřil traderům na parketu hormony a doložil, jak fyzický stav mění ochotu riskovat.',
            'weak' => 'Tvůj úsudek se výrazně mění se spánkem a únavou. To je dobrá zpráva, protože je to jediná položka, se kterou se dá snadno něco udělat.',
            'strong' => 'Výkon ti kolísá se spánkem a únavou méně než u většiny lidí.',
        ],
        'attention' => [
            'label' => 'Prostředí a pozornost',
            'source' => 'Steenbarger a obecný výzkum pozornosti: přerušovaná pozornost zhoršuje včasnost rozhodnutí.',
            'weak' => 'Obchoduješ v prostředí, které tě přerušuje. Typicky vidíš setup pozdě a vstupuješ do už rozjetého pohybu.',
            'strong' => 'Umíš si udělat nerušený blok, což je podmínka pro včasné rozpoznání setupu.',
        ],
        'overconfidence' => [
            'label' => 'Chování po sérii zisků',
            'source' => 'Coates popisuje růst ochoty riskovat po sérii úspěchů, Kiev totéž na úrovni chování.',
            'weak' => 'Po sérii zisků zvyšuješ velikost pozice nebo polevuješ v kritériích. Série pak končí jedním obchodem, který sebere víc, než dala celá.',
            'strong' => 'Po sérii zisků držíš stejnou velikost pozice i stejná kritéria.',
        ],
    ];
}

function psych_profile_questions(): array
{
    return [
        ['key' => 'need_1', 'dimension' => 'need', 'question' => 'Máš představu, kolik chceš vydělat za měsíc?', 'options' => ['Ne, sleduju jen dodržení procesu', 'Spíš orientačně', 'Ano a průběžně to kontroluju', 'Ano a mám na tom postavený rozpočet']],
        ['key' => 'need_2', 'dimension' => 'need', 'question' => 'Co uděláš, když jsi v polovině měsíce výrazně pod plánem?', 'options' => ['Nic, obchoduju dál stejně', 'Trochu to řeším, ale nezměním nic', 'Začnu hledat víc příležitostí', 'Zvýším velikost pozice, abych to dohnal']],
        ['key' => 'revenge_1', 'dimension' => 'revenge', 'question' => 'Jak dlouho ti trvá, než se po ztrátě vrátíš do klidu?', 'options' => ['Hned, je to jen další obchod', 'Pár minut', 'Zbytek dne to se mnou je', 'Nesu si to i do dalšího dne']],
        ['key' => 'revenge_2', 'dimension' => 'revenge', 'question' => 'Co se stane s tvým dalším obchodem po ztrátě?', 'options' => ['Nic, je stejný jako každý jiný', 'Jsem opatrnější', 'Hledám ho aktivněji než obvykle', 'Chci ztrátu rychle vrátit']],
        ['key' => 'cutting_1', 'dimension' => 'cutting', 'question' => 'Jak často zavíráš zisk dřív, než dojde na plánovaný cíl?', 'options' => ['Skoro nikdy', 'Občas', 'Často', 'Skoro vždycky']],
        ['key' => 'cutting_2', 'dimension' => 'cutting', 'question' => 'Co děláš, když jde obchod proti tobě ke stop lossu?', 'options' => ['Nechám stop pracovat', 'Sleduju to, ale nesahám na to', 'Někdy stop posunu', 'Často stop posunu nebo zruším']],
        ['key' => 'consistency_1', 'dimension' => 'consistency', 'question' => 'Kolik setupů obchoduješ?', 'options' => ['Jeden až dva, mám je popsané', 'Několik, mám je popsané', 'Několik, ale nemám je přesně definované', 'Podle situace, mění se to']],
        ['key' => 'consistency_2', 'dimension' => 'consistency', 'question' => 'Co uděláš po třech ztrátových dnech za sebou?', 'options' => ['Nic, systém se nemění', 'Zmenším pozici, systém nechám', 'Začnu hledat, co vylepšit', 'Zkusím obchodovat jinak']],
        ['key' => 'process_1', 'dimension' => 'process', 'question' => 'Jak často si děláš přípravu před obchodním dnem?', 'options' => ['Každý den, vždy stejně', 'Většinu dnů', 'Když mám čas', 'Málokdy, rozhoduju se podle situace']],
        ['key' => 'process_2', 'dimension' => 'process', 'question' => 'Máš předem napsané, kdy dnes obchodovat nebudeš?', 'options' => ['Ano, mám to jako pravidlo', 'Většinou to vím', 'Řeším to až v situaci', 'Neřeším to']],
        ['key' => 'physical_1', 'dimension' => 'physical', 'question' => 'Poznáš na svém obchodování, že jsi špatně spal?', 'options' => ['Ne, výkon mi nekolísá', 'Trochu', 'Ano, výrazně', 'Ano a většinou to zjistím až podle výsledku']],
        ['key' => 'physical_2', 'dimension' => 'physical', 'question' => 'Obchoduješ i když jsi nemocný nebo vyčerpaný?', 'options' => ['Ne, ten den vynechám', 'Zmenším pozici', 'Obchoduju normálně', 'Obchoduju a ani o tom nepřemýšlím']],
        ['key' => 'attention_1', 'dimension' => 'attention', 'question' => 'Jak vypadá tvoje obchodní prostředí?', 'options' => ['Nerušený blok jen na trading', 'Většinou klid', 'Souběžně dělám i něco jiného', 'Prakticky pořád něco běží vedle']],
        ['key' => 'attention_2', 'dimension' => 'attention', 'question' => 'Jak často ti uteče vstup, protože ses koukal jinam?', 'options' => ['Skoro nikdy', 'Občas', 'Často', 'Pravidelně']],
        ['key' => 'overconfidence_1', 'dimension' => 'overconfidence', 'question' => 'Co uděláš po třech ziscích za sebou?', 'options' => ['Nic, pozice zůstává stejná', 'Jsem obezřetnější', 'Cítím se jistěji a jdu do víc obchodů', 'Zvýším velikost pozice']],
        ['key' => 'overconfidence_2', 'dimension' => 'overconfidence', 'question' => 'Kdy naposledy přišla tvoje největší ztráta?', 'options' => ['Nesouvisí to se sérií', 'Po sérii ztrát', 'Po dobrém dni', 'Po nejlepší sérii, jakou jsem měl']],
    ];
}

function evaluate_psych_profile(array $answers): array
{
    $dimensions = psych_dimensions();
    $totals = [];
    $counts = [];

    foreach (psych_profile_questions() as $question) {
        $raw = $answers[$question['key']] ?? null;
        $value = is_numeric($raw) ? max(0, min(3, (int)$raw)) : 1;
        $dimension = $question['dimension'];
        $totals[$dimension] = ($totals[$dimension] ?? 0) + $value;
        $counts[$dimension] = ($counts[$dimension] ?? 0) + 1;
    }

    $result = [];
    foreach ($dimensions as $key => $meta) {
        $max = ($counts[$key] ?? 0) * 3;
        $score = $totals[$key] ?? 0;
        $share = $max > 0 ? $score / $max : 0.0;
        $level = $share >= 0.6 ? 'weak' : ($share <= 0.25 ? 'strong' : 'neutral');
        $result[$key] = [
            'key' => $key,
            'label' => $meta['label'],
            'source' => $meta['source'],
            'score' => $score,
            'max' => $max,
            'share' => round($share * 100),
            'level' => $level,
            'detail' => $level === 'strong' ? $meta['strong'] : $meta['weak'],
        ];
    }

    uasort($result, static fn(array $a, array $b): int => $b['share'] <=> $a['share']);
    return $result;
}

/**
 * Porovná, co o sobě člověk tvrdí, s tím, co je vidět v jeho vlastních obchodech.
 * Tohle je jediná část profilu opřená o data, ne o sebehodnocení.
 */
function profile_reality_check(array $dimensions): array
{
    $findings = [];
    $totalTrades = (int)(fetch_one('SELECT COUNT(*) AS n FROM trades WHERE result_r IS NOT NULL')['n'] ?? 0);
    if ($totalTrades < 10) {
        return ['trades' => $totalTrades, 'findings' => [], 'note' => 'Na porovnání proti datům je potřeba aspoň deset uzavřených obchodů. Zatím jich je ' . $totalTrades . '.'];
    }

    // Reakce na ztrátu: kolik porušení pravidel přišlo po ztrátě téhož dne.
    $afterLoss = 0;
    $broken = 0;
    $previousByDate = [];
    foreach (fetch_all('SELECT trade_date, result_r, followed_plan, execution_rating FROM trades ORDER BY trade_date, id') as $row) {
        $date = (string)$row['trade_date'];
        if (trade_broke_rules($row)) {
            $broken++;
            if (($previousByDate[$date] ?? 0) < 0) {
                $afterLoss++;
            }
        }
        if ($row['result_r'] !== null) {
            $previousByDate[$date] = (float)$row['result_r'];
        }
    }
    if ($broken >= 3) {
        $share = (int)round(($afterLoss / $broken) * 100);
        $findings[] = [
            'dimension' => 'revenge',
            'claim' => $dimensions['revenge']['level'] ?? 'neutral',
            'measured' => $share >= 50 ? 'weak' : 'strong',
            'text' => sprintf('%d %% tvých porušení pravidel přišlo po ztrátovém obchodu téhož dne (%d z %d).', $share, $afterLoss, $broken),
        ];
    }

    // Práce se ziskem a ztrátou: průměrný zisk proti průměrné ztrátě v R.
    $sizes = fetch_one('SELECT COALESCE(AVG(CASE WHEN result_r > 0 THEN result_r END), 0) AS win, ABS(COALESCE(AVG(CASE WHEN result_r < 0 THEN result_r END), 0)) AS loss, COUNT(CASE WHEN result_r > 0 THEN 1 END) AS wins, COUNT(CASE WHEN result_r < 0 THEN 1 END) AS losses FROM trades WHERE result_r IS NOT NULL') ?? [];
    if ((int)($sizes['wins'] ?? 0) >= 3 && (int)($sizes['losses'] ?? 0) >= 3) {
        $win = (float)$sizes['win'];
        $loss = (float)$sizes['loss'];
        $ratio = $loss > 0 ? $win / $loss : 0;
        $findings[] = [
            'dimension' => 'cutting',
            'claim' => $dimensions['cutting']['level'] ?? 'neutral',
            'measured' => $ratio < 1 ? 'weak' : 'strong',
            'text' => sprintf('Průměrný zisk je %s, průměrná ztráta %s. Poměr %s.', format_r($win), format_r($loss), number_format($ratio, 2, ',', ' ')),
        ];
    }

    // Příprava: kolik obchodních dnů proběhlo bez uloženého náhledu.
    $days = fetch_all('SELECT DISTINCT trade_date FROM trades');
    if (count($days) >= 5) {
        $withoutPlan = 0;
        foreach ($days as $row) {
            $tradeDate = (string)$row['trade_date'];
            $covered = fetch_one(
                "SELECT id FROM plans WHERE (plan_type = 'daily' AND plan_date = ?) OR (plan_type = 'weekly' AND plan_date <= ? AND plan_date > date(?, '-7 days')) LIMIT 1",
                [$tradeDate, $tradeDate, $tradeDate]
            );
            if ($covered === null) {
                $withoutPlan++;
            }
        }
        $share = (int)round(($withoutPlan / count($days)) * 100);
        $findings[] = [
            'dimension' => 'process',
            'claim' => $dimensions['process']['level'] ?? 'neutral',
            'measured' => $share >= 40 ? 'weak' : 'strong',
            'text' => sprintf('%d %% obchodních dnů proběhlo bez uloženého náhledu, denního ani týdenního (%d z %d).', $share, $withoutPlan, count($days)),
        ];
    }

    // Chování po sérii zisků: risk po ziskovém dni proti risku po ztrátovém dni.
    $afterWin = [];
    $afterLossRisk = [];
    $dayResults = [];
    foreach (fetch_all('SELECT trade_date, COALESCE(SUM(result_r), 0) AS day_r FROM trades WHERE result_r IS NOT NULL GROUP BY trade_date ORDER BY trade_date') as $row) {
        $dayResults[(string)$row['trade_date']] = (float)$row['day_r'];
    }
    $dates = array_keys($dayResults);
    foreach ($dates as $index => $date) {
        if ($index === 0) {
            continue;
        }
        $previous = $dayResults[$dates[$index - 1]];
        $risk = fetch_one('SELECT COALESCE(AVG(NULLIF(risk_amount, 0)), 0) AS r FROM trades WHERE trade_date = ?', [$date])['r'] ?? 0;
        if ((float)$risk <= 0) {
            continue;
        }
        if ($previous > 0) {
            $afterWin[] = (float)$risk;
        } elseif ($previous < 0) {
            $afterLossRisk[] = (float)$risk;
        }
    }
    if (count($afterWin) >= 3 && count($afterLossRisk) >= 3) {
        $winAvg = array_sum($afterWin) / count($afterWin);
        $lossAvg = array_sum($afterLossRisk) / count($afterLossRisk);
        $growth = $lossAvg > 0 ? (($winAvg / $lossAvg) - 1) * 100 : 0;
        $findings[] = [
            'dimension' => 'overconfidence',
            'claim' => $dimensions['overconfidence']['level'] ?? 'neutral',
            'measured' => $growth >= 20 ? 'weak' : 'strong',
            'text' => sprintf('Po ziskovém dni obchoduješ s riskem %s, po ztrátovém %s. Rozdíl %s %%.', format_money($winAvg), format_money($lossAvg), number_format($growth, 0, ',', ' ')),
        ];
    }

    foreach ($findings as &$finding) {
        $finding['agrees'] = $finding['claim'] === $finding['measured'] || $finding['claim'] === 'neutral';
    }
    unset($finding);

    return ['trades' => $totalTrades, 'findings' => $findings, 'note' => ''];
}

function default_psych_rules(): array
{
    return [
        'green' => ['no_trading' => false, 'max_trades' => 0, 'risk_percent' => 100, 'only_a_setups' => false, 'single_account' => false, 'stop_after_break' => true, 'no_news' => false, 'note' => ''],
        'amber' => ['no_trading' => false, 'max_trades' => 2, 'risk_percent' => 50, 'only_a_setups' => true, 'single_account' => true, 'stop_after_break' => true, 'no_news' => true, 'note' => ''],
        'red' => ['no_trading' => true, 'max_trades' => 0, 'risk_percent' => 0, 'only_a_setups' => true, 'single_account' => true, 'stop_after_break' => true, 'no_news' => true, 'note' => ''],
    ];
}

function normalize_psych_rules(array $rules): array
{
    $defaults = default_psych_rules();
    $clean = [];
    foreach ($defaults as $band => $fields) {
        $given = is_array($rules[$band] ?? null) ? $rules[$band] : [];
        $clean[$band] = [
            'no_trading' => (bool)($given['no_trading'] ?? $fields['no_trading']),
            'max_trades' => max(0, min(50, (int)($given['max_trades'] ?? $fields['max_trades']))),
            'risk_percent' => max(0, min(100, (int)($given['risk_percent'] ?? $fields['risk_percent']))),
            'only_a_setups' => (bool)($given['only_a_setups'] ?? $fields['only_a_setups']),
            'single_account' => (bool)($given['single_account'] ?? $fields['single_account']),
            'stop_after_break' => (bool)($given['stop_after_break'] ?? $fields['stop_after_break']),
            'no_news' => (bool)($given['no_news'] ?? $fields['no_news']),
            'note' => trim((string)($given['note'] ?? '')),
        ];
    }
    return $clean;
}

function rules_to_lines(array $rules): array
{
    if ($rules['no_trading']) {
        $lines = ['Dnes neobchoduješ. Tohle pravidlo sis nastavil sám.'];
        if ($rules['note'] !== '') {
            $lines[] = $rules['note'];
        }
        return $lines;
    }

    $lines = [];
    if ($rules['risk_percent'] < 100) {
        $lines[] = sprintf('Risk na obchod maximálně %d %% obvyklé velikosti.', $rules['risk_percent']);
    }
    if ($rules['max_trades'] > 0) {
        $lines[] = sprintf('Nejvýš %d %s za den.', $rules['max_trades'], $rules['max_trades'] === 1 ? 'obchod' : 'obchody');
    }
    if ($rules['only_a_setups']) {
        $lines[] = 'Jen A+ setupy. Nic, co musíš obhajovat.';
    }
    if ($rules['single_account']) {
        $lines[] = 'Jen jeden účet.';
    }
    if ($rules['no_news']) {
        $lines[] = 'Žádné obchody kolem red news.';
    }
    if ($rules['stop_after_break']) {
        $lines[] = 'Po prvním porušení pravidel končíš pro dnešek.';
    }
    if ($rules['note'] !== '') {
        $lines[] = $rules['note'];
    }
    return $lines === [] ? ['Obchoduješ podle plánu bez dalších omezení.'] : $lines;
}

function psych_profile_payload(): ?array
{
    $row = fetch_one('SELECT * FROM psych_profile WHERE id = 1');
    if ($row === null) {
        return null;
    }
    $dimensions = json_decode((string)$row['dimensions'], true) ?: [];
    return [
        'answers' => json_decode((string)$row['answers'], true) ?: [],
        'dimensions' => $dimensions,
        'rules' => normalize_psych_rules(json_decode((string)$row['rules'], true) ?: []),
        'thresholds' => normalize_psych_thresholds(json_decode((string)($row['thresholds'] ?? '{}'), true) ?: []),
        'reality' => profile_reality_check($dimensions),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function save_psych_profile(array $data): array
{
    $answers = (array)value($data, 'answers', []);
    $dimensions = evaluate_psych_profile($answers);
    $rules = normalize_psych_rules((array)value($data, 'rules', []));

    $pdo = db();
    $existing = fetch_one('SELECT id, created_at FROM psych_profile WHERE id = 1');
    if ($existing === null) {
        $pdo->prepare('INSERT INTO psych_profile (id, answers, dimensions, rules, created_at, updated_at) VALUES (1, ?, ?, ?, ?, ?)')
            ->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), json_encode($dimensions, JSON_UNESCAPED_UNICODE), json_encode($rules, JSON_UNESCAPED_UNICODE), utc_now(), utc_now()]);
    } else {
        $pdo->prepare('UPDATE psych_profile SET answers = ?, dimensions = ?, rules = ?, updated_at = ? WHERE id = 1')
            ->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), json_encode($dimensions, JSON_UNESCAPED_UNICODE), json_encode($rules, JSON_UNESCAPED_UNICODE), utc_now()]);
    }
    return psych_profile_payload() ?? [];
}

function psych_questions(?array $profileDimensions = null): array
{
    $base = [
        ['key' => 'sleep', 'dimension' => 'physical', 'question' => 'Jak jsi dnes spal?', 'options' => ['Vyspaný, kolem sedmi hodin a víc', 'O něco méně, ale cítím se dobře', 'Málo, cítím to', 'Skoro jsem nespal'], 'warning' => 'Po nedostatku spánku se zhoršuje trpělivost. Typicky zmeškáš vstup a pak ho doháníš za horší cenu.'],
        ['key' => 'body', 'dimension' => 'physical', 'question' => 'Jak jsi na tom fyzicky?', 'options' => ['Fit', 'Mírná únava', 'Nemocný nebo po alkoholu', 'Vyčerpaný'], 'warning' => 'Tělo v útlumu zkracuje pozornost. Drž se jen setupů, které poznáš na první pohled.'],
        ['key' => 'stress', 'dimension' => 'attention', 'question' => 'Co se děje mimo trading?', 'options' => ['Klid', 'Drobnosti, nic zásadního', 'Výrazný stres', 'Akutní problém, který mě zaměstnává'], 'warning' => 'Vnější stres se v grafu projeví jako netrpělivost. Potřeba mít něco pod kontrolou se přelije do nucených vstupů.'],
        ['key' => 'pressure', 'dimension' => 'need', 'question' => 'Cítíš finanční tlak na dnešní výsledek?', 'options' => ['Žádný', 'Mírný', 'Potřebuju dnes vydělat', 'Musím dnes dohnat ztrátu'], 'warning' => 'Když trh musí zaplatit konkrétní částku, přestáváš obchodovat setupy a začínáš obchodovat potřebu.'],
        ['key' => 'revenge', 'dimension' => 'revenge', 'question' => 'Jak jsi uzavřel poslední session?', 'options' => ['V klidu, uzavřená věc', 'Mírná nespokojenost', 'Frustrace, vracím se k tomu', 'Mám chuť to vrátit'], 'warning' => 'Chuť vrátit ztrátu je nejsilnější prediktor porušení pravidel. Dnešní trh s tou včerejší ztrátou nemá nic společného.'],
        ['key' => 'preparation', 'dimension' => 'process', 'question' => 'Máš hotovou přípravu na dnešek?', 'options' => ['Náhled, zóny i levely hotové', 'Připravené částečně', 'Jen jsem se zběžně podíval', 'Nic'], 'warning' => 'Bez předem daných úrovní budeš rozhodovat až v reálném čase, tedy přesně tam, kde je rozhodování nejdražší.'],
        ['key' => 'focus', 'dimension' => 'attention', 'question' => 'Máš dnes prostor se soustředit?', 'options' => ['Nerušený blok času', 'Občasné vyrušení', 'Souběžně práce nebo telefon', 'Prakticky žádný klid'], 'warning' => 'Přerušovaná pozornost vede k tomu, že vidíš setup pozdě a vstupuješ do už rozjetého pohybu.'],
        ['key' => 'confidence', 'dimension' => 'consistency', 'question' => 'Jak sedíš ve svém systému?', 'options' => ['Klidná jistota', 'Mírné pochyby', 'Pochybuju, jestli systém funguje', 'Chci dnes zkusit něco nového'], 'warning' => 'Improvizace uprostřed série ztrát míchá dva systémy dohromady a znemožní vyhodnotit oba.'],
    ];

    // Cílené otázky navíc pro oblasti, které vyšly z profilu jako slabé.
    $targeted = [
        'cutting' => ['key' => 'cutting_today', 'dimension' => 'cutting', 'question' => 'Máš pro dnešek jasně dané, kde zisk vybereš?', 'options' => ['Ano, cíle mám napsané', 'Rámcově', 'Rozhodnu se podle situace', 'Nevím, uvidím podle pocitu'], 'warning' => 'Bez předem daného cíle rozhoduješ o výběru zisku v momentě největší nejistoty. Tvůj profil ukazuje sklon zavírat brzy.'],
        'overconfidence' => ['key' => 'streak_today', 'dimension' => 'overconfidence', 'question' => 'Jak sis vedl v posledních dnech?', 'options' => ['Běžně', 'Mírně v plusu', 'Dobrá série', 'Nejlepší série za dlouhou dobu'], 'warning' => 'Po dobré sérii roste ochota riskovat. Tvůj profil ukazuje, že tady vzniká tvoje největší ztráta.'],
        'revenge' => ['key' => 'yesterday_loss', 'dimension' => 'revenge', 'question' => 'Vracíš se dnes v hlavě k nějakému konkrétnímu obchodu?', 'options' => ['Ne', 'Občas mi problikne', 'Ano, myslím na něj', 'Ano a chci to napravit'], 'warning' => 'Nedokončená ztráta se u tebe podle profilu nese dál. Dnešní trh s ní ale nemá nic společného.'],
        'need' => ['key' => 'target_today', 'dimension' => 'need', 'question' => 'Máš v hlavě částku, kterou chceš dnes udělat?', 'options' => ['Ne', 'Spíš orientačně', 'Ano, konkrétní číslo', 'Ano a potřebuju ho'], 'warning' => 'Konkrétní cílová částka na den je podle tvého profilu tvoje riziková oblast. Cíl patří na proces, ne na výsledek.'],
    ];

    $weak = [];
    foreach ((array)$profileDimensions as $key => $dimension) {
        if (($dimension['level'] ?? '') === 'weak') {
            $weak[] = $key;
        }
    }

    $questions = [];
    foreach ($base as $question) {
        $question['weight'] = in_array($question['dimension'], $weak, true) ? 1.5 : 1.0;
        $questions[] = $question;
    }

    $added = 0;
    foreach ($weak as $key) {
        if ($added >= 2 || !isset($targeted[$key])) {
            continue;
        }
        $question = $targeted[$key];
        $question['weight'] = 1.5;
        $questions[] = $question;
        $added++;
    }

    return $questions;
}

const PSYCH_THRESHOLD_AMBER = 22;
const PSYCH_THRESHOLD_RED = 46;
const PSYCH_CALIBRATION_MIN_DAYS = 12;

function normalize_psych_thresholds(array $thresholds): array
{
    $amber = (int)($thresholds['amber'] ?? PSYCH_THRESHOLD_AMBER);
    $red = (int)($thresholds['red'] ?? PSYCH_THRESHOLD_RED);
    $amber = max(5, min(80, $amber));
    $red = max($amber + 1, min(95, $red));
    return ['amber' => $amber, 'red' => $red];
}

function pearson(array $x, array $y): ?float
{
    $n = count($x);
    if ($n < 3 || $n !== count($y)) {
        return null;
    }
    $meanX = array_sum($x) / $n;
    $meanY = array_sum($y) / $n;
    $top = 0.0;
    $varX = 0.0;
    $varY = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $x[$i] - $meanX;
        $dy = $y[$i] - $meanY;
        $top += $dx * $dy;
        $varX += $dx * $dx;
        $varY += $dy * $dy;
    }
    if ($varX <= 0 || $varY <= 0) {
        return null;
    }
    return round($top / sqrt($varX * $varY), 3);
}

/**
 * Hledá hranici, která nejlépe odděluje dobré dny od špatných. Youdenovo J je
 * citlivost plus specificita minus jedna, tedy jednoduchá míra toho, o kolik je
 * dělení lepší než náhoda.
 */
function best_cutoff(array $samples, int $from, int $to): ?array
{
    $positives = array_filter($samples, static fn(array $s): bool => $s['bad']);
    $negatives = array_filter($samples, static fn(array $s): bool => !$s['bad']);
    if (count($positives) < 3 || count($negatives) < 3) {
        return null;
    }

    $best = null;
    for ($cutoff = $from; $cutoff <= $to; $cutoff++) {
        $tp = $fn = $tn = $fp = 0;
        foreach ($samples as $sample) {
            $flagged = $sample['share'] >= $cutoff;
            if ($sample['bad']) {
                $flagged ? $tp++ : $fn++;
            } else {
                $flagged ? $fp++ : $tn++;
            }
        }
        $sensitivity = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $specificity = ($tn + $fp) > 0 ? $tn / ($tn + $fp) : 0.0;
        $j = $sensitivity + $specificity - 1;
        if ($best === null || $j > $best['j']) {
            $best = ['cutoff' => $cutoff, 'j' => $j, 'sensitivity' => $sensitivity, 'specificity' => $specificity];
        }
    }
    return $best;
}

function psych_calibration(): array
{
    $current = normalize_psych_thresholds((psych_profile_payload()['thresholds'] ?? []));
    $rows = fetch_all(<<<'SQL'
SELECT c.check_date AS date, c.score AS score, c.max_score AS max_score, c.band AS band,
       COALESCE(t.trades, 0) AS trades, t.day_r AS day_r, COALESCE(t.broken, 0) AS broken
FROM (SELECT check_date, MAX(id) AS id FROM psych_checks GROUP BY check_date) latest
JOIN psych_checks c ON c.id = latest.id
LEFT JOIN (
    SELECT trade_date, COUNT(*) AS trades, SUM(result_r) AS day_r,
           SUM(CASE WHEN followed_plan = 0 OR execution_rating >= 4 THEN 1 ELSE 0 END) AS broken
    FROM trades GROUP BY trade_date
) t ON t.trade_date = c.check_date
ORDER BY c.check_date
SQL);

    $samples = [];
    $bands = ['green' => [], 'amber' => [], 'red' => []];
    foreach ($rows as $row) {
        $max = (int)$row['max_score'];
        if ($max <= 0) {
            continue;
        }
        $share = ((int)$row['score'] / $max) * 100;
        $band = (string)$row['band'];
        if (isset($bands[$band])) {
            $bands[$band][] = ['traded' => (int)$row['trades'] > 0, 'day_r' => $row['day_r'] === null ? null : (float)$row['day_r'], 'broken' => (int)$row['broken'] > 0];
        }
        if ((int)$row['trades'] > 0) {
            $samples[] = [
                'share' => $share,
                'day_r' => (float)($row['day_r'] ?? 0),
                'broken' => (int)$row['broken'] > 0,
                'bad' => (int)$row['broken'] > 0 || (float)($row['day_r'] ?? 0) <= -1.0,
            ];
        }
    }

    $bandStats = [];
    foreach ($bands as $band => $items) {
        $traded = array_values(array_filter($items, static fn(array $i): bool => $i['traded']));
        $results = array_values(array_filter(array_column($traded, 'day_r'), static fn($v): bool => $v !== null));
        $brokenDays = count(array_filter($traded, static fn(array $i): bool => $i['broken']));
        $bandStats[$band] = [
            'days' => count($items),
            'traded_days' => count($traded),
            'avg_r' => $results === [] ? null : round(array_sum($results) / count($results), 2),
            'broken_days' => $brokenDays,
            'broken_share' => count($traded) > 0 ? (int)round(($brokenDays / count($traded)) * 100) : null,
        ];
    }

    $result = [
        'checks' => count($rows),
        'traded_days' => count($samples),
        'min_days' => PSYCH_CALIBRATION_MIN_DAYS,
        'bands' => $bandStats,
        'current' => $current,
        'suggested' => null,
        'correlation_r' => null,
        'correlation_broken' => null,
        'note' => '',
    ];

    if (count($samples) < PSYCH_CALIBRATION_MIN_DAYS) {
        $result['note'] = sprintf('Na kalibraci je potřeba aspoň %d dnů, kdy jsi udělal test a zároveň obchodoval. Zatím jich je %d.', PSYCH_CALIBRATION_MIN_DAYS, count($samples));
        return $result;
    }

    $shares = array_column($samples, 'share');
    $result['correlation_r'] = pearson($shares, array_column($samples, 'day_r'));
    $result['correlation_broken'] = pearson($shares, array_map(static fn(array $s): float => $s['broken'] ? 1.0 : 0.0, $samples));

    $amberSamples = array_map(static fn(array $s): array => ['share' => $s['share'], 'bad' => $s['broken']], $samples);
    $amber = best_cutoff($amberSamples, 5, 70);
    $red = best_cutoff($samples, ($amber['cutoff'] ?? 5) + 1, 90);

    if ($amber !== null && $amber['j'] > 0.1) {
        $result['suggested'] = normalize_psych_thresholds([
            'amber' => $amber['cutoff'],
            'red' => $red !== null && $red['j'] > 0.1 ? $red['cutoff'] : max($amber['cutoff'] + 1, $current['red']),
        ]);
        $result['quality'] = ['amber_j' => round($amber['j'], 2), 'red_j' => $red === null ? null : round($red['j'], 2)];
        $result['note'] = sprintf(
            'Kalibrace vychází z %d dnů. Čím víc jich bude, tím spolehlivější návrh. Pod padesát dnů ber výsledek jako orientační.',
            count($samples)
        );
    } else {
        $result['note'] = 'Z tvých dat zatím nejde najít hranici, která by dobře oddělila dobré dny od špatných. Buď je dat málo, nebo skóre testu s výsledkem dne zatím nesouvisí.';
    }

    return $result;
}

function save_psych_thresholds(array $thresholds): array
{
    $existing = psych_profile_payload();
    if ($existing === null) {
        json_response(['error' => 'Nejdřív vyplň vstupní profil.'], 422);
    }
    $clean = normalize_psych_thresholds($thresholds);
    db()->prepare('UPDATE psych_profile SET thresholds = ?, updated_at = ? WHERE id = 1')
        ->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), utc_now()]);
    return $clean;
}

function evaluate_psych(array $answers, ?array $profileDimensions = null, ?array $rules = null, ?array $thresholds = null): array
{
    $questions = psych_questions($profileDimensions);
    $score = 0.0;
    $max = 0.0;
    $warnings = [];
    $priorityWarnings = [];
    $normalized = [];
    $skipped = [];
    $weakSpotHit = false;

    foreach ($questions as $question) {
        $weight = (float)($question['weight'] ?? 1.0);
        $max += 3 * $weight;

        $raw = $answers[$question['key']] ?? null;
        if (!is_numeric($raw)) {
            // Nezodpovězená otázka se počítá jako mírná, ne jako bezproblémová.
            // Jinak by se dal test obejít tím, že se nechá vypršet čas.
            $value = 1;
            $skipped[] = $question['key'];
        } else {
            $value = max(0, min(3, (int)$raw));
        }

        $normalized[$question['key']] = $value;
        $score += $value * $weight;

        if ($value >= 2) {
            // Varování z tvých slabých oblastí jdou nahoru.
            if ($weight > 1.0) {
                $priorityWarnings[] = $question['warning'];
            } else {
                $warnings[] = $question['warning'];
            }
        }
        // Stačí zhoršená odpověď ve slabé oblasti. Tam, kde to má člověk podle
        // profilu doložené jako své selhání, nemá smysl čekat na krajní hodnotu.
        if ($value >= 2 && $weight > 1.0) {
            $weakSpotHit = true;
        }
    }

    $warnings = array_merge($priorityWarnings, $warnings);
    if (count($skipped) >= 2) {
        $warnings[] = sprintf('Nestihl jsi odpovědět na %d z %d otázek. Nerozhodnost u jednoduchých otázek na vlastní stav bývá sama o sobě signálem roztěkanosti.', count($skipped), count($questions));
    }

    // Podíl místo pevných hranic, aby výsledek nezávisel na počtu a váze otázek.
    // Hranice jdou zkalibrovat na vlastních datech, jinak platí výchozí.
    $limits = normalize_psych_thresholds($thresholds ?? []);
    $share = $max > 0 ? $score / $max : 0.0;
    $percent = $share * 100;
    $band = $percent < $limits['amber'] ? 'green' : ($percent < $limits['red'] ? 'amber' : 'red');

    if (($normalized['revenge'] ?? 0) === 3 || ($normalized['pressure'] ?? 0) === 3) {
        $band = 'red';
    }
    if ($weakSpotHit && $band === 'green') {
        $band = 'amber';
        $warnings[] = 'Zhoršená odpověď padla přesně do oblasti, kterou máš z profilu jako rizikovou. Proto oranžová, i když je celkové skóre nízké.';
    }

    $verdict = match ($band) {
        'green' => 'Stav je v pořádku. Obchoduj podle plánu a podle pravidel, která sis nastavil.',
        'amber' => 'Stav není ideální. Platí omezení, která sis pro tenhle případ sám nadefinoval.',
        default => 'Dnes jsi v nastavení, po kterém se nejčastěji porušují pravidla. Platí tvoje pravidla pro červenou.',
    };

    $rules = $rules === null ? default_psych_rules() : normalize_psych_rules($rules);
    return [
        'score' => (int)round($score),
        'max_score' => (int)round($max),
        'share' => round($share * 100),
        'band' => $band,
        'verdict' => $verdict,
        'warnings' => $warnings,
        'answers' => $normalized,
        'skipped' => $skipped,
        'rules' => $rules[$band],
        'rules_lines' => rules_to_lines($rules[$band]),
        'thresholds' => $limits,
    ];
}

function save_psych_check(array $data): array
{
    $date = trim((string)value($data, 'check_date', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = gmdate('Y-m-d');
    }
    $answers = (array)value($data, 'answers', []);
    $profile = psych_profile_payload();
    $result = evaluate_psych($answers, $profile['dimensions'] ?? null, $profile['rules'] ?? null, $profile['thresholds'] ?? null);

    $pdo = db();
    $statement = $pdo->prepare('INSERT INTO psych_checks (check_date, score, max_score, band, answers, verdict, warnings, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([
        $date, $result['score'], $result['max_score'], $result['band'],
        json_encode($result['answers'], JSON_UNESCAPED_UNICODE),
        $result['verdict'],
        json_encode($result['warnings'], JSON_UNESCAPED_UNICODE),
        (string)value($data, 'notes', ''),
        utc_now(),
    ]);
    $saved = fetch_one('SELECT * FROM psych_checks WHERE id = ?', [(int)$pdo->lastInsertId()]) ?? [];
    $saved['warnings_list'] = $result['warnings'];
    $saved['rules_lines'] = $result['rules_lines'];
    $saved['share'] = $result['share'];
    return $saved;
}

/** Srozumitelná hláška pro chybu nahrávání; nejčastěji jde o limit velikosti na serveru. */
function upload_error_message(int $error): string
{
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return 'Obrázek je větší, než server dovolí (' . ini_get('upload_max_filesize') . '). Zmenši ho nebo ulož jako JPEG.';
    }
    return $error === UPLOAD_ERR_PARTIAL ? 'Obrázek se nahrál jen zčásti. Zkus to znovu.' : 'Nahrání obrázku selhalo.';
}

function delete_screenshot_file(array $screenshot): void
{
    $path = upload_dir() . DIRECTORY_SEPARATOR . basename((string)$screenshot['file_name']);
    if (is_file($path)) {
        @unlink($path);
    }
}

security_headers();
