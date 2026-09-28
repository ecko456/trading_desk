<?php
declare(strict_types=1);

/*
 * Přizpůsobené prostředí tradera.
 *
 * Každý člen si volí metodiku (Market Profile, DiNapoli, obojí), které prvky
 * náhledu a zápisu obchodu chce vidět, vlastní pole, trhy a moduly. Nastavení
 * leží v jeho deníku, takže u šifrovaného deníku je šifrované spolu s ním.
 * Registr prvků je jediný zdroj pravdy pro server, šablonu i prohlížeč.
 */

const WORKSPACE_METHODS = ['mp', 'dn', 'both'];

/** Prvky náhledu: klíč => [název, vysvětlení, skupina, metodika]. Metodika core = patří všem. */
const PLAN_ELEMENTS = [
    'bias.pa' => ['Bias z price action', 'Monthly, Weekly a Daily struktura: HH/HL, BOS, range.', 'Bias', 'core'],
    'bias.mp' => ['Bias z Market / Volume Profile', 'Kam migruje value a kde je přijatá cena.', 'Bias', 'mp'],
    'profile.shape' => ['Tvar profilu', 'P, b, D, B nebo trendový profil.', 'Market Profile', 'mp'],
    'profile.values' => ['Hodnoty profilu', 'High, VAH, POC, VAL, Low a Close a přenos do levelů.', 'Market Profile', 'mp'],
    'profile.close' => ['Close vůči value', 'Kde trh zavřel vůči objemu.', 'Market Profile', 'mp'],
    'profile.auction' => ['Migrace a stav aukce', 'Migrace value a POC, aukce, single prints, excess.', 'Market Profile', 'mp'],
    'refs' => ['Reference na dojetí', 'Poor high a low, naked POC, single prints, gap, LVN.', 'Market Profile', 'mp'],
    'dn.trend' => ['Trend podle DMA', 'Poloha ceny vůči 3x3, 7x5 a 25x5 a thrust.', 'DiNapoli', 'dn'],
    'dn.swings' => ['DiNapoli levely', 'F3, F5, F7 a expanze COP, OP, XOP s timeframem a stavem naked / revisited; shoda a konfluence podle tolerance z nastavení.', 'DiNapoli', 'dn'],
    'dn.patterns' => ['DiNapoli vzory', 'Double Repo, Single Penetration, Railroad Tracks, Failure, Bread & Butter, Minesweeper.', 'DiNapoli', 'dn'],
    'open.va' => ['Otevření vůči value', 'Globex, EU a RTH open vůči value area.', 'Otevření', 'mp'],
    'open.type' => ['Typ otevření', 'Open Drive, Test Drive, Rejection Reverse, Open Auction.', 'Otevření', 'mp'],
    'open.ib' => ['Initial Balance', 'Velikost a charakter první hodiny.', 'Otevření', 'mp'],
    'timing' => ['Časová osa session', 'Globex, EU, RTH a IB s odpočtem do otevření.', 'Časová osa', 'core'],
    'zones.context' => ['Zóny vůči týdenní value', 'Štítek „ve VAL“, „nad VAH“ a blok týdenní value.', 'Zóny a levely', 'mp'],
    'zones.conditions' => ['Podmínky vstupu u zóny', 'Co se musí splnit a kdy obchod neberu, pro každý směr.', 'Zóny a levely', 'core'],
    'zones.tokens' => ['Zdroj a konfluence zóny', 'Štítky zdroje: VAH, POC, F5, COP, vlastní…', 'Zóny a levely', 'core'],
    'zones.targets' => ['SL, TP a RR u zóny', 'Invalidace, stop loss, TP1, TP2 a minimální RR.', 'Zóny a levely', 'core'],
    'levels' => ['Klíčové levely', 'Horizontální úrovně s cenou a stylem čáry.', 'Zóny a levely', 'core'],
    'ideas' => ['Scénáře obchodů', 'Potenciální obchody s entry, SL a cíli.', 'Scénáře a grafy', 'core'],
    'charts' => ['Screenshoty grafu', 'Obrázky připojené k náhledu.', 'Scénáře a grafy', 'core'],
    'side.conclusion' => ['Pracovní závěr', 'Automatické shrnutí z vyplněných polí.', 'Pravý panel', 'core'],
    'side.map' => ['Mapa ceny', 'Všechny ceny náhledu na jedné svislé ose.', 'Pravý panel', 'core'],
    'side.risk' => ['Rizika a poznámky', 'Red news, no-trade podmínky a poznámky.', 'Pravý panel', 'core'],
];

/** Prvky zápisu obchodu. Datum, trh, směr, ceny, risk a výsledek zůstávají vždy. */
const TRADE_ELEMENTS = [
    'trade.account' => ['Obchodní účet', 'Přiřazení k účtu pro Money audit.'],
    'trade.session' => ['Typ obchodu', 'Intraday nebo Hybrid Intraday.'],
    'trade.strategy' => ['Strategie / setup', 'Pro srovnání výkonnosti setupů.'],
    'trade.target' => ['Plánovaný TP', 'Cíl, se kterým jsi do obchodu šel.'],
    'trade.fees' => ['Poplatky', 'Když je skryješ, použije se výchozí hodnota z nastavení.'],
    'trade.followed' => ['Dodržen plán', 'Základ rozboru chyb a disciplíny.'],
    'trade.rating' => ['Hodnocení exekuce', 'Stupnice 1 až 5.'],
    'trade.emotions' => ['Emoce', 'Zaškrtávací stavy pro rozbor spouštěčů.'],
    'trade.mistake' => ['Chyba / odchylka', 'Co se nepovedlo.'],
    'trade.notes' => ['Poznámka a poučení', 'Volný text k obchodu.'],
    'trade.screenshots' => ['Screenshoty obchodu', 'Graf vstupu a výstupu.'],
];

/** Moduly aplikace, které jde skrýt. Přehled, náhled, deník, nastavení a záloha zůstávají. */
const MODULES = [
    'wall' => ['Nástěnka', 'Sdílení s komunitou, komentáře a reakce.'],
    'archive' => ['Historie náhledů', 'Seznam všech denních a týdenních náhledů.'],
    'calendar' => ['Kalendář', 'Měsíc v kostce, red news a svátky.'],
    'hindsight' => ['Hindsight', 'Rok 5m grafů ES s tvými zónami, biasem a news na jedné ose.'],
    'strategies' => ['Strategie', 'Srovnání setupů a rozbor vlastních polí.'],
    'psyche' => ['Psychika', 'Vstupní profil, rychlý test a rozbor chyb.'],
    'accounts' => ['Účty a Money audit', 'Obchodní účty a měsíční kontrola evidence.'],
];

const DEFAULT_MARKETS = [
    ['symbol' => 'ES', 'point_value' => 50.0, 'rth' => '09:30'],
    ['symbol' => 'MES', 'point_value' => 5.0, 'rth' => '09:30'],
    ['symbol' => 'NQ', 'point_value' => 20.0, 'rth' => '09:30'],
    ['symbol' => 'MNQ', 'point_value' => 2.0, 'rth' => '09:30'],
    ['symbol' => 'GC', 'point_value' => 100.0, 'rth' => '08:20'],
    ['symbol' => 'MGC', 'point_value' => 10.0, 'rth' => '08:20'],
    ['symbol' => 'CL', 'point_value' => 1000.0, 'rth' => '09:00'],
    ['symbol' => 'MCL', 'point_value' => 100.0, 'rth' => '09:00'],
    ['symbol' => '6E', 'point_value' => 125000.0, 'rth' => '08:20'],
];

const CUSTOM_FIELD_KINDS = [
    'bool' => 'Ano / ne',
    'select' => 'Výběr z možností',
    'rating' => 'Hodnocení 1 až 5',
    'number' => 'Číslo',
    'text' => 'Krátký text',
    'textarea' => 'Delší text',
];

const DN_PATTERNS = ['Double Repo', 'Single Penetration', 'Railroad Tracks', 'Failure', 'Bread & Butter', 'Minesweeper A', 'Minesweeper B', 'Fib Node'];
/** Typy DiNapoli levelů: retracementy a cíle expanze. */
const DN_LEVEL_KINDS = ['F3' => 'retracement', 'F5' => 'retracement', 'F7' => 'retracement', 'COP' => 'expansion', 'OP' => 'expansion', 'XOP' => 'expansion'];
const DN_MAX_LEVELS = 80;
const DN_DEFAULT_TOLERANCE = 5.0;
/** MN = měsíční, Q = čtvrtletní graf; DiNapoli je používá pro hlavní levely. */
const DN_DEFAULT_TIMEFRAMES = ['M5', 'M15', 'M30', 'H1', 'H4', 'D1', 'W1', 'MN', 'Q'];
/** Verze nastavení; 2 přidala měsíční a čtvrtletní timeframe. */
const WORKSPACE_VERSION = 2;

function dn_default_timeframes(): array
{
    return array_map(static fn(string $tf): array => ['tf' => $tf, 'confluence' => DN_DEFAULT_TOLERANCE, 'agreement' => DN_DEFAULT_TOLERANCE], DN_DEFAULT_TIMEFRAMES);
}

function method_hidden_plan(string $method): array
{
    $hidden = [];
    foreach (PLAN_ELEMENTS as $key => [, , , $tag]) {
        if (($method === 'mp' && $tag === 'dn') || ($method === 'dn' && $tag === 'mp')) {
            $hidden[] = $key;
        }
    }
    return $hidden;
}

function default_workspace(): array
{
    return [
        'version' => WORKSPACE_VERSION,
        'method' => 'both',
        'onboarded' => false,
        'hidden' => ['plan' => [], 'trade' => [], 'modules' => []],
        'tokens_extra' => [],
        'markets' => DEFAULT_MARKETS,
        'defaults' => ['market' => '', 'session' => 'Intraday', 'risk' => null, 'fees' => null, 'account_id' => null],
        'dn' => ['timeframes' => dn_default_timeframes()],
    ];
}

/** Očistí nastavení od neznámých klíčů a nesmyslných hodnot. */
function normalize_workspace(array $input): array
{
    $base = default_workspace();
    $method = in_array($input['method'] ?? null, WORKSPACE_METHODS, true) ? $input['method'] : $base['method'];
    $pick = static function (mixed $list, array $allowed): array {
        $keys = array_values(array_unique(array_filter(array_map('strval', is_array($list) ? $list : []), static fn(string $key): bool => array_key_exists($key, $allowed))));
        sort($keys);
        return $keys;
    };
    $hidden = is_array($input['hidden'] ?? null) ? $input['hidden'] : [];

    $tokens = [];
    foreach (is_array($input['tokens_extra'] ?? null) ? $input['tokens_extra'] : [] as $token) {
        $token = trim(preg_replace('/[,\s]+/u', ' ', (string)$token) ?? '');
        if ($token !== '' && mb_strlen($token, 'UTF-8') <= 24 && !in_array($token, $tokens, true)) {
            $tokens[] = $token;
        }
    }

    $markets = [];
    foreach (is_array($input['markets'] ?? null) ? $input['markets'] : [] as $market) {
        if (!is_array($market)) {
            continue;
        }
        $symbol = strtoupper(trim(preg_replace('/[^A-Za-z0-9._!-]/', '', (string)($market['symbol'] ?? '')) ?? ''));
        if ($symbol === '' || strlen($symbol) > 12 || isset($markets[$symbol])) {
            continue;
        }
        $point = nullable_float($market['point_value'] ?? null);
        $rth = (string)($market['rth'] ?? '');
        $markets[$symbol] = [
            'symbol' => $symbol,
            'point_value' => $point !== null && $point > 0 ? $point : null,
            'rth' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $rth) ? $rth : '09:30',
        ];
    }
    $markets = array_values($markets);
    if ($markets === [] && !array_key_exists('markets', $input)) {
        $markets = DEFAULT_MARKETS;
    }

    $defaults = is_array($input['defaults'] ?? null) ? $input['defaults'] : [];
    $session = (string)($defaults['session'] ?? 'Intraday');
    $risk = nullable_float($defaults['risk'] ?? null);
    $fees = nullable_float($defaults['fees'] ?? null);
    // Tolerance shody a konfluence pro každý timeframe zvlášť.
    $timeframes = [];
    foreach (is_array($input['dn']['timeframes'] ?? null) ? $input['dn']['timeframes'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tf = trim(preg_replace('/[^\p{L}\p{N} ._-]/u', '', (string)($row['tf'] ?? '')) ?? '');
        if ($tf === '' || mb_strlen($tf, 'UTF-8') > 12 || isset($timeframes[mb_strtolower($tf, 'UTF-8')])) {
            continue;
        }
        $tolerance = static function (mixed $value): float {
            $number = nullable_float($value);
            return $number !== null && $number >= 0 && $number <= 1000000 ? $number : DN_DEFAULT_TOLERANCE;
        };
        $timeframes[mb_strtolower($tf, 'UTF-8')] = ['tf' => $tf, 'confluence' => $tolerance($row['confluence'] ?? null), 'agreement' => $tolerance($row['agreement'] ?? null)];
    }
    // Seznam uložený ve starší verzi dostane měsíční a čtvrtletní timeframe navíc.
    if ($timeframes !== [] && (int)($input['version'] ?? 1) < 2) {
        foreach (['MN', 'Q'] as $tf) {
            $timeframes[mb_strtolower($tf, 'UTF-8')] ??= ['tf' => $tf, 'confluence' => DN_DEFAULT_TOLERANCE, 'agreement' => DN_DEFAULT_TOLERANCE];
        }
    }
    $timeframes = array_slice(array_values($timeframes), 0, 20);

    return [
        'version' => WORKSPACE_VERSION,
        'method' => $method,
        'onboarded' => (bool)($input['onboarded'] ?? false),
        'hidden' => [
            'plan' => $pick($hidden['plan'] ?? [], PLAN_ELEMENTS),
            'trade' => $pick($hidden['trade'] ?? [], TRADE_ELEMENTS),
            'modules' => $pick($hidden['modules'] ?? [], MODULES),
        ],
        'tokens_extra' => array_slice($tokens, 0, 30),
        'markets' => array_slice($markets, 0, 60),
        'defaults' => [
            'market' => strtoupper(substr(trim((string)($defaults['market'] ?? '')), 0, 12)),
            'session' => in_array($session, ['Intraday', 'Hybrid Intraday'], true) ? $session : 'Intraday',
            'risk' => $risk !== null && $risk > 0 ? $risk : null,
            'fees' => $fees !== null && $fees >= 0 ? $fees : null,
            'account_id' => nullable_int($defaults['account_id'] ?? null),
        ],
        'dn' => ['timeframes' => $timeframes !== [] ? $timeframes : dn_default_timeframes()],
    ];
}

function ensure_workspace_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS preferences (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS custom_fields (
    id INTEGER PRIMARY KEY,
    scope TEXT NOT NULL CHECK(scope IN ('trade', 'plan')),
    label TEXT NOT NULL,
    kind TEXT NOT NULL CHECK(kind IN ('bool', 'select', 'rating', 'number', 'text', 'textarea')),
    options TEXT NOT NULL DEFAULT '[]',
    help TEXT,
    in_table INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    archived INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_custom_fields_scope ON custom_fields(scope, archived, sort_order);
SQL);
    $hasLevels = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'plan_dn_levels'")->fetchColumn() !== false;
    if (!$hasLevels) {
        // Tabulka i převod starých swingů proběhnou naráz, nebo vůbec.
        $pdo->beginTransaction();
        $pdo->exec(<<<'SQL'
CREATE TABLE plan_dn_levels (
    id INTEGER PRIMARY KEY,
    plan_id INTEGER NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    timeframe TEXT NOT NULL DEFAULT '',
    kind TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'naked',
    price REAL,
    note TEXT
);
CREATE INDEX idx_dn_levels_plan ON plan_dn_levels(plan_id, sort_order);
SQL);
        try {
            migrate_dn_swings($pdo);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
    foreach (['custom' => "TEXT NOT NULL DEFAULT '{}'"] as $column => $type) {
        foreach (['trades', 'plans'] as $table) {
            if (!in_array($column, table_columns($pdo, $table), true)) {
                add_column($pdo, $table, $column, $type);
            }
        }
    }
    $planColumns = table_columns($pdo, 'plans');
    foreach (['dn_dma_3x3' => 'TEXT', 'dn_dma_7x5' => 'TEXT', 'dn_dma_25x5' => 'TEXT', 'dn_thrust' => 'TEXT', 'dn_patterns' => 'TEXT', 'dn_notes' => 'TEXT', 'dn_tolerance' => 'REAL'] as $column => $type) {
        if (!in_array($column, $planColumns, true)) {
            add_column($pdo, 'plans', $column, $type);
        }
    }
}

function workspace(): array
{
    static $cache = [];
    $user = current_user();
    $key = $user === null ? 0 : (int)$user['id'];
    if (!isset($cache[$key])) {
        $row = fetch_one("SELECT value FROM preferences WHERE key = 'workspace'");
        $stored = $row === null ? null : json_decode((string)$row['value'], true);
        $cache[$key] = is_array($stored) ? normalize_workspace($stored) : default_workspace();
    }
    return $cache[$key];
}

function save_workspace(array $input): array
{
    $clean = normalize_workspace($input);
    $statement = db()->prepare("INSERT INTO preferences (key, value) VALUES ('workspace', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $statement->execute([json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    return $clean;
}

function is_hidden(string $group, string $key): bool
{
    return in_array($key, workspace()['hidden'][$group] ?? [], true);
}

/** Atributy prvku náhledu nebo obchodu pro šablonu: značka a případné skrytí. */
function el(string $key): string
{
    $group = str_starts_with($key, 'trade.') ? 'trade' : 'plan';
    return ' data-el="' . htmlspecialchars($key, ENT_QUOTES) . '"' . (is_hidden($group, $key) ? ' hidden' : '');
}

function module_attr(string $module): string
{
    return ' data-module="' . htmlspecialchars($module, ENT_QUOTES) . '"' . (is_hidden('modules', $module) ? ' hidden' : '');
}

/** Hodnota bodu z trhů tradera; neznámý trh vrátí null a počítá se jen z risku. */
function workspace_point_value(string $market): ?float
{
    $symbol = strtoupper(trim($market));
    foreach (workspace()['markets'] as $item) {
        if ($item['symbol'] === $symbol) {
            return $item['point_value'];
        }
    }
    return market_point_value($symbol);
}

function workspace_registry(): array
{
    $plan = [];
    foreach (PLAN_ELEMENTS as $key => [$label, $hint, $group, $tag]) {
        $plan[] = ['key' => $key, 'label' => $label, 'hint' => $hint, 'group' => $group, 'method' => $tag];
    }
    $trade = [];
    foreach (TRADE_ELEMENTS as $key => [$label, $hint]) {
        $trade[] = ['key' => $key, 'label' => $label, 'hint' => $hint];
    }
    $modules = [];
    foreach (MODULES as $key => [$label, $hint]) {
        $modules[] = ['key' => $key, 'label' => $label, 'hint' => $hint];
    }
    return [
        'plan' => $plan,
        'trade' => $trade,
        'modules' => $modules,
        'presets' => ['mp' => method_hidden_plan('mp'), 'dn' => method_hidden_plan('dn'), 'both' => []],
        'kinds' => CUSTOM_FIELD_KINDS,
        'dn_patterns' => DN_PATTERNS,
        'dn_level_kinds' => DN_LEVEL_KINDS,
        'dn_default_timeframes' => dn_default_timeframes(),
        'default_markets' => DEFAULT_MARKETS,
    ];
}

/* ---------------------------------------------------------------- vlastní pole */

function custom_field_row(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'scope' => (string)$row['scope'],
        'label' => (string)$row['label'],
        'kind' => (string)$row['kind'],
        'options' => json_decode((string)$row['options'], true) ?: [],
        'help' => (string)($row['help'] ?? ''),
        'in_table' => (bool)$row['in_table'],
        'sort_order' => (int)$row['sort_order'],
        'archived' => (bool)$row['archived'],
    ];
}

function custom_fields(?string $scope = null, bool $withArchived = true): array
{
    $where = [];
    $params = [];
    if ($scope !== null) {
        $where[] = 'scope = ?';
        $params[] = $scope;
    }
    if (!$withArchived) {
        $where[] = 'archived = 0';
    }
    $sql = 'SELECT * FROM custom_fields' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY scope, archived, sort_order, id';
    return array_map('custom_field_row', fetch_all($sql, $params));
}

function save_custom_field(array $data): array
{
    $scope = (string)($data['scope'] ?? '');
    $kind = (string)($data['kind'] ?? '');
    if (!in_array($scope, ['trade', 'plan'], true)) {
        throw new InvalidArgumentException('Pole musí patřit k obchodu nebo k náhledu.');
    }
    $label = trim(preg_replace('/\s+/u', ' ', (string)($data['label'] ?? '')) ?? '');
    if ($label === '' || mb_strlen($label, 'UTF-8') > 60) {
        throw new InvalidArgumentException('Název pole musí mít 1 až 60 znaků.');
    }
    $id = nullable_int($data['id'] ?? null);
    $existing = $id === null ? null : fetch_one('SELECT * FROM custom_fields WHERE id = ?', [$id]);
    if ($id !== null && $existing === null) {
        throw new InvalidArgumentException('Pole už neexistuje.');
    }
    // Typ existujícího pole se nemění, aby se nerozbily uložené hodnoty.
    if ($existing !== null) {
        $kind = (string)$existing['kind'];
        $scope = (string)$existing['scope'];
    }
    if (!array_key_exists($kind, CUSTOM_FIELD_KINDS)) {
        throw new InvalidArgumentException('Neznámý typ pole.');
    }
    $options = [];
    if ($kind === 'select') {
        foreach (is_array($data['options'] ?? null) ? $data['options'] : preg_split('/\r?\n|,/', (string)($data['options'] ?? '')) as $option) {
            $option = trim((string)$option);
            if ($option !== '' && mb_strlen($option, 'UTF-8') <= 40 && !in_array($option, $options, true)) {
                $options[] = $option;
            }
        }
        if (count($options) < 2) {
            throw new InvalidArgumentException('Výběr potřebuje aspoň dvě možnosti.');
        }
        $options = array_slice($options, 0, 20);
    }
    $help = mb_substr(trim((string)($data['help'] ?? '')), 0, 160, 'UTF-8');
    $inTable = !empty($data['in_table']) && $scope === 'trade' && $kind !== 'textarea' ? 1 : 0;
    $archived = !empty($data['archived']) ? 1 : 0;

    if ($existing === null) {
        $order = (int)(fetch_one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next FROM custom_fields WHERE scope = ?', [$scope])['next'] ?? 1);
        db()->prepare('INSERT INTO custom_fields (scope, label, kind, options, help, in_table, sort_order, archived, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$scope, $label, $kind, json_encode($options, JSON_UNESCAPED_UNICODE), $help, $inTable, $order, utc_now()]);
        $id = (int)db()->lastInsertId();
    } else {
        db()->prepare('UPDATE custom_fields SET label = ?, options = ?, help = ?, in_table = ?, archived = ? WHERE id = ?')
            ->execute([$label, json_encode($options, JSON_UNESCAPED_UNICODE), $help, $inTable, $archived, $id]);
    }
    return custom_field_row(fetch_one('SELECT * FROM custom_fields WHERE id = ?', [$id]));
}

function move_custom_field(int $id, int $delta): void
{
    $field = fetch_one('SELECT * FROM custom_fields WHERE id = ?', [$id]);
    if ($field === null) {
        throw new InvalidArgumentException('Pole už neexistuje.');
    }
    $siblings = array_map(static fn(array $row): int => (int)$row['id'], fetch_all('SELECT id FROM custom_fields WHERE scope = ? AND archived = ? ORDER BY sort_order, id', [$field['scope'], $field['archived']]));
    $index = array_search($id, $siblings, true);
    $target = $index + ($delta < 0 ? -1 : 1);
    if ($index === false || $target < 0 || $target >= count($siblings)) {
        return;
    }
    [$siblings[$index], $siblings[$target]] = [$siblings[$target], $siblings[$index]];
    $statement = db()->prepare('UPDATE custom_fields SET sort_order = ? WHERE id = ?');
    foreach ($siblings as $order => $siblingId) {
        $statement->execute([$order + 1, $siblingId]);
    }
}

function delete_custom_field(int $id): void
{
    db()->prepare('DELETE FROM custom_fields WHERE id = ?')->execute([$id]);
}

/**
 * Ověří hodnoty vlastních polí a spojí je s uloženými. Hodnoty archivovaných
 * polí zůstanou, i když je formulář už neposílá.
 */
function merge_custom_values(string $scope, mixed $incoming, string $stored): string
{
    $values = json_decode($stored, true);
    $values = is_array($values) ? $values : [];
    $incoming = is_array($incoming) ? $incoming : [];
    foreach (custom_fields($scope, false) as $field) {
        $key = (string)$field['id'];
        if (!array_key_exists($key, $incoming)) {
            continue;
        }
        $raw = $incoming[$key];
        $value = null;
        switch ($field['kind']) {
            case 'bool':
                $value = $raw === true || $raw === 1 || $raw === '1' || $raw === 'yes' ? true : ($raw === false || $raw === 0 || $raw === '0' || $raw === 'no' ? false : null);
                break;
            case 'number':
                $value = nullable_float($raw);
                break;
            case 'rating':
                $number = nullable_int($raw);
                $value = $number !== null && $number >= 1 && $number <= 5 ? $number : null;
                break;
            case 'select':
                $value = in_array((string)$raw, $field['options'], true) ? (string)$raw : null;
                break;
            case 'text':
                $value = mb_substr(trim((string)$raw), 0, 300, 'UTF-8');
                break;
            case 'textarea':
                $value = mb_substr(trim((string)$raw), 0, 4000, 'UTF-8');
                break;
        }
        if ($value === null || $value === '') {
            unset($values[$key]);
        } else {
            $values[$key] = $value;
        }
    }
    return json_encode((object)$values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** Popisky a hodnoty vlastních polí pro PDF a sdílení (jen vyplněná pole). */
function custom_values_readable(string $scope, string $stored): array
{
    $values = json_decode($stored, true);
    if (!is_array($values) || $values === []) {
        return [];
    }
    $rows = [];
    foreach (custom_fields($scope) as $field) {
        $key = (string)$field['id'];
        if (!array_key_exists($key, $values) || $field['archived']) {
            continue;
        }
        $value = $values[$key];
        $text = match ($field['kind']) {
            'bool' => $value ? 'Ano' : 'Ne',
            'rating' => $value . ' / 5',
            'number' => rtrim(rtrim(number_format((float)$value, 4, ',', ' '), '0'), ','),
            default => (string)$value,
        };
        $rows[] = ['label' => $field['label'], 'value' => $text, 'kind' => $field['kind']];
    }
    return $rows;
}

/**
 * Výkonnost podle hodnot vlastních polí obchodu. Win rate se záměrně nepočítá,
 * stejně jako jinde v aplikaci: rozhoduje průměrné R a profit factor.
 */
function custom_field_statistics(): array
{
    $trades = fetch_all('SELECT result_r, followed_plan, custom FROM trades WHERE result_r IS NOT NULL');
    $result = [];
    foreach (custom_fields('trade', false) as $field) {
        if (!in_array($field['kind'], ['bool', 'select', 'rating', 'number'], true)) {
            continue;
        }
        $key = (string)$field['id'];
        $samples = [];
        foreach ($trades as $trade) {
            $values = json_decode((string)($trade['custom'] ?? '{}'), true);
            if (is_array($values) && array_key_exists($key, $values)) {
                $samples[] = ['value' => $values[$key], 'r' => (float)$trade['result_r'], 'followed' => $trade['followed_plan']];
            }
        }
        $buckets = [];
        if ($field['kind'] === 'number') {
            $numbers = array_map(static fn(array $sample): float => (float)$sample['value'], $samples);
            sort($numbers);
            $median = $numbers === [] ? null : $numbers[intdiv(count($numbers) - 1, 2)];
            foreach ($samples as $sample) {
                $label = (float)$sample['value'] <= $median ? '≤ ' . rtrim(rtrim(number_format((float)$median, 2, ',', ' '), '0'), ',') : '> ' . rtrim(rtrim(number_format((float)$median, 2, ',', ' '), '0'), ',');
                $buckets[$label][] = $sample;
            }
        } else {
            foreach ($samples as $sample) {
                $label = match ($field['kind']) {
                    'bool' => $sample['value'] ? 'Ano' : 'Ne',
                    'rating' => $sample['value'] . ' / 5',
                    default => (string)$sample['value'],
                };
                $buckets[$label][] = $sample;
            }
            $order = match ($field['kind']) {
                'bool' => ['Ano', 'Ne'],
                'rating' => ['1 / 5', '2 / 5', '3 / 5', '4 / 5', '5 / 5'],
                default => $field['options'],
            };
            $buckets = array_replace(array_intersect_key(array_fill_keys($order, []), $buckets), $buckets);
        }
        $rows = [];
        foreach ($buckets as $label => $items) {
            $rs = array_column($items, 'r');
            $wins = array_sum(array_filter($rs, static fn(float $r): bool => $r > 0));
            $losses = abs(array_sum(array_filter($rs, static fn(float $r): bool => $r < 0)));
            $judged = array_values(array_filter($items, static fn(array $item): bool => $item['followed'] !== null));
            $followed = count(array_filter($judged, static fn(array $item): bool => (int)$item['followed'] === 1));
            $rows[] = [
                'value' => (string)$label,
                'trades' => count($items),
                'total_r' => round(array_sum($rs), 2),
                'avg_r' => round(array_sum($rs) / count($items), 2),
                'profit_factor' => $losses > 0 ? round($wins / $losses, 2) : null,
                'plan_adherence' => $judged === [] ? null : round($followed / count($judged) * 100),
            ];
        }
        $result[] = ['field' => $field, 'samples' => count($samples), 'rows' => $rows];
    }
    return $result;
}

/* ---------------------------------------------------------------- DiNapoli */

/** Tolerance v bodech pro timeframe a druh shody; neznámý timeframe dostane výchozích 5 bodů. */
function dn_tolerance(array $timeframes, string $timeframe, string $type): float
{
    foreach ($timeframes as $row) {
        if (is_array($row) && (string)($row['tf'] ?? '') === $timeframe) {
            $value = nullable_float($row[$type] ?? null);
            return $value !== null && $value >= 0 ? $value : DN_DEFAULT_TOLERANCE;
        }
    }
    return DN_DEFAULT_TOLERANCE;
}

/** Platné levely: známý typ a cena. index ukazuje na pořadí ve vstupu. */
function dn_clean_levels(array $rows): array
{
    $levels = [];
    foreach (array_values($rows) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = strtoupper(trim((string)($row['kind'] ?? '')));
        $price = nullable_float($row['price'] ?? null);
        if (!isset(DN_LEVEL_KINDS[$kind]) || $price === null) {
            continue;
        }
        $levels[] = [
            'index' => $index,
            'timeframe' => trim((string)($row['timeframe'] ?? '')),
            'kind' => $kind,
            'group' => DN_LEVEL_KINDS[$kind],
            'status' => ($row['status'] ?? '') === 'revisited' ? 'revisited' : 'naked',
            'price' => $price,
            'note' => trim((string)($row['note'] ?? '')),
        ];
    }
    return $levels;
}

/**
 * Kde se levely kryjí. Porovnávají se jen levely stejného timeframu:
 * konfluence = dva F5 do tolerance konfluence, shoda = expanze u retracementu
 * do tolerance shody. Víc levelů spojených přes sebe tvoří jedno pásmo.
 */
function dn_analysis(array $rows, array $timeframes): array
{
    $levels = dn_clean_levels($rows);
    $byTimeframe = [];
    foreach ($levels as $position => $level) {
        $byTimeframe[$level['timeframe']][] = $position;
    }

    $clusters = [];
    foreach ($byTimeframe as $timeframe => $positions) {
        $timeframe = (string)$timeframe;
        foreach (['confluence', 'agreement'] as $type) {
            $tolerance = dn_tolerance($timeframes, $timeframe, $type);
            $parent = array_combine($positions, $positions);
            $find = static function (int $x) use (&$parent): int {
                while ($parent[$x] !== $x) {
                    $parent[$x] = $parent[$parent[$x]];
                    $x = $parent[$x];
                }
                return $x;
            };
            $linked = [];
            $count = count($positions);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $levels[$positions[$i]];
                    $b = $levels[$positions[$j]];
                    $qualifies = $type === 'confluence' ? $a['kind'] === 'F5' && $b['kind'] === 'F5' : $a['group'] !== $b['group'];
                    $near = abs($a['price'] - $b['price']) <= $tolerance + 1e-9 * max(1.0, abs($a['price']), abs($b['price']));
                    if (!$qualifies || !$near) {
                        continue;
                    }
                    $parent[$find($positions[$i])] = $find($positions[$j]);
                    $linked[$positions[$i]] = true;
                    $linked[$positions[$j]] = true;
                }
            }
            $groups = [];
            foreach ($positions as $position) {
                if (isset($linked[$position])) {
                    $groups[$find($position)][] = $levels[$position];
                }
            }
            foreach ($groups as $members) {
                usort($members, static fn(array $x, array $y): int => [$x['price'], $x['index']] <=> [$y['price'], $y['index']]);
                $prices = array_column($members, 'price');
                $clusters[] = [
                    'type' => $type,
                    'timeframe' => $timeframe,
                    'low' => min($prices),
                    'high' => max($prices),
                    'tolerance' => $tolerance,
                    'revisited' => in_array('revisited', array_column($members, 'status'), true),
                    'members' => array_map(static fn(array $m): array => ['index' => $m['index'], 'kind' => $m['kind'], 'status' => $m['status'], 'price' => $m['price']], $members),
                ];
            }
        }
    }

    usort($clusters, static fn(array $x, array $y): int => [$y['high'], $y['low']] <=> [$x['high'], $x['low']]
        ?: strcmp($x['type'], $y['type'])
        ?: strcmp($x['timeframe'], $y['timeframe'])
        ?: $x['members'][0]['index'] <=> $y['members'][0]['index']);
    return ['levels' => $levels, 'clusters' => $clusters];
}

/**
 * Úrovně jednoho swingu z dřívější verze (A → B, C). Slouží jen k převodu
 * uložených swingů na levely, aby se po aktualizaci nic neztratilo.
 */
function dn_legacy_swing_levels(array $swing): array
{
    $a = nullable_float($swing['price_a'] ?? null);
    $b = nullable_float($swing['price_b'] ?? null);
    $c = nullable_float($swing['price_c'] ?? null);
    if ($a === null || $b === null || $a === $b) {
        return [];
    }
    $range = $b - $a;
    $levels = [];
    foreach (['F3' => 0.382, 'F5' => 0.618] as $kind => $ratio) {
        $levels[] = ['kind' => $kind, 'price' => round($b - $ratio * $range, 6)];
    }
    if ($c !== null) {
        foreach (['COP' => 0.618, 'OP' => 1.0, 'XOP' => 1.618] as $kind => $ratio) {
            $levels[] = ['kind' => $kind, 'price' => round($c + $ratio * $range, 6)];
        }
    }
    return $levels;
}

function migrate_dn_swings(PDO $pdo): void
{
    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'plan_dn_swings'")->fetchColumn();
    if ($exists === false) {
        return;
    }
    $insert = $pdo->prepare('INSERT INTO plan_dn_levels (plan_id, sort_order, timeframe, kind, status, price, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $order = [];
    $swings = $pdo->query('SELECT s.* FROM plan_dn_swings s JOIN plans p ON p.id = s.plan_id ORDER BY s.plan_id, s.sort_order, s.id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($swings as $swing) {
        $planId = (int)$swing['plan_id'];
        $label = trim((string)($swing['label'] ?? ''));
        $note = trim(($label !== '' ? $label . ' · ' : '') . 'swing ' . $swing['price_a'] . ' → ' . $swing['price_b']);
        foreach (dn_legacy_swing_levels($swing) as $level) {
            $insert->execute([$planId, $order[$planId] = ($order[$planId] ?? -1) + 1, '', $level['kind'], 'naked', $level['price'], mb_substr($note, 0, 300, 'UTF-8')]);
        }
    }
}

function save_plan_extras(PDO $pdo, int $planId, array $data, string $storedCustom): void
{
    // Starší otevřená stránka levely neposílá; bez klíče se uložené levely nemažou.
    if (array_key_exists('dn_levels', $data)) {
        $pdo->prepare('DELETE FROM plan_dn_levels WHERE plan_id = ?')->execute([$planId]);
        $statement = $pdo->prepare('INSERT INTO plan_dn_levels (plan_id, sort_order, timeframe, kind, status, price, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $order = 0;
        foreach (array_slice(array_values((array)$data['dn_levels']), 0, DN_MAX_LEVELS) as $level) {
            if (!is_array($level)) {
                continue;
            }
            $kind = strtoupper(trim((string)($level['kind'] ?? '')));
            $price = nullable_float($level['price'] ?? null);
            $note = mb_substr(trim((string)($level['note'] ?? '')), 0, 300, 'UTF-8');
            if (!isset(DN_LEVEL_KINDS[$kind]) || ($price === null && $note === '')) {
                continue;
            }
            $statement->execute([
                $planId, $order++, mb_substr(trim((string)($level['timeframe'] ?? '')), 0, 12, 'UTF-8'), $kind,
                ($level['status'] ?? '') === 'revisited' ? 'revisited' : 'naked', $price, $note,
            ]);
        }
    }
    $patterns = array_values(array_intersect(DN_PATTERNS, array_map('trim', explode(',', (string)($data['dn_patterns'] ?? '')))));
    $side = static fn(mixed $value): string => in_array($value, ['above', 'below'], true) ? $value : '';
    $pdo->prepare('UPDATE plans SET dn_dma_3x3 = ?, dn_dma_7x5 = ?, dn_dma_25x5 = ?, dn_thrust = ?, dn_patterns = ?, dn_notes = ?, custom = ? WHERE id = ?')->execute([
        $side($data['dn_dma_3x3'] ?? ''), $side($data['dn_dma_7x5'] ?? ''), $side($data['dn_dma_25x5'] ?? ''),
        in_array($data['dn_thrust'] ?? '', ['up', 'down'], true) ? $data['dn_thrust'] : '',
        implode(', ', $patterns), mb_substr((string)($data['dn_notes'] ?? ''), 0, 4000, 'UTF-8'),
        merge_custom_values('plan', $data['custom'] ?? [], $storedCustom), $planId,
    ]);
}

function plan_extras(array $plan): array
{
    $levels = fetch_all('SELECT timeframe, kind, status, price, note FROM plan_dn_levels WHERE plan_id = ? ORDER BY sort_order, id', [(int)$plan['id']]);
    return [
        'dn_levels' => $levels,
        'dinapoli' => dn_analysis($levels, workspace()['dn']['timeframes']),
        'custom' => json_decode((string)($plan['custom'] ?? '{}'), true) ?: new stdClass(),
        'custom_readable' => custom_values_readable('plan', (string)($plan['custom'] ?? '{}')),
    ];
}
