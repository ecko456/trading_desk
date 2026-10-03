<?php
declare(strict_types=1);

/*
 * Obchodní plán: dlouhodobá pravidla tradera (na rozdíl od denního náhledu, který řeší
 * jeden den). Struktura: 1 cíle a styl, 2 trhy a čas, 3 účty a risk, 4 jak stavím bias,
 * 5 jak stavím zóny, 6 strategie a kdy je obchodovat, 7 den tradera, 8 psychika,
 * 9 review, 10 závazek.
 *
 * Plán je v deníku člena (u šifrovaného deníku zašifrovaný) jako JSON s verzemi:
 * rozpracovaná a platná verze se upravují, nová verze archivuje předchozí. Každé pole
 * se při uložení přečistí podle seznamu povolených klíčů, výčtů a délek.
 */

const TP_TEXT_MAX = 3000;
const TP_STATUSES = ['draft' => 'Rozpracovaný', 'active' => 'Platný', 'archived' => 'Archivovaný'];
const TP_STYLES = ['intraday' => 'Intraday', 'hybrid' => 'Hybrid Intraday', 'swing' => 'Swing'];
const TP_ACCOUNT_ROLES = ['eval' => 'Prop: challenge', 'funded' => 'Prop: funded', 'personal' => 'Vlastní kapitál', 'demo' => 'Demo / simulace'];
const TP_DRAWDOWN = ['static' => 'Statický', 'trailing' => 'Trailing intraday', 'eod' => 'Trailing EOD'];
const TP_WINDOW_KINDS = ['trade' => 'Obchoduji', 'watch' => 'Jen sleduji', 'off' => 'Neobchoduji'];
const TP_CONTEXTS = ['any' => 'Trend i balance', 'trend' => 'Jen trendový den', 'balance' => 'Jen balance a rotace'];
const TP_BIAS_RULES = ['with' => 'Jen ve směru biasu', 'any' => 'I proti biasu, s potvrzením', 'against' => 'Proti biasu (reversal)'];
const TP_ZONE_PRIORITY = ['A' => 'Jen zóny A', 'AB' => 'Zóny A a B', 'any' => 'Jakákoli zóna z náhledu'];
const TP_ZONE_SOURCES = [
    'vah_val' => 'VAH / VAL', 'poc' => 'POC', 'naked_poc' => 'Naked POC', 'single_prints' => 'Single prints',
    'poor_hl' => 'Poor high / low', 'excess' => 'Excess / tail', 'ib' => 'IB high / low', 'prior_hl' => 'High / low předchozího dne',
    'lvn_hvn' => 'LVN / HVN', 'dinapoli' => 'DiNapoli F5 / F7', 'levels' => 'Klíčové levely (S/R)',
];
const TP_TIMEFRAMES = ['monthly' => 'Monthly', 'weekly' => 'Weekly', 'daily' => 'Daily', 'h4' => 'H4', 'h1' => 'H1', 'm30' => 'M30'];
const TP_DAYS = [1 => 'Po', 2 => 'Út', 3 => 'St', 4 => 'Čt', 5 => 'Pá', 6 => 'So', 7 => 'Ne'];

function ensure_tradeplan_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS trading_plans (
    id INTEGER PRIMARY KEY,
    version INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    title TEXT NOT NULL,
    valid_from TEXT,
    data TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_trading_plans_version ON trading_plans(version DESC);
SQL);
}

/* ---------------------------------------------------------------- čištění vstupu */

function tp_text(mixed $value, int $max = TP_TEXT_MAX): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = mb_scrub(str_replace(["\r\n", "\r"], "\n", (string)$value), 'UTF-8');
    $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    return mb_substr(trim($text), 0, $max, 'UTF-8');
}

function tp_number(mixed $value, float $min = 0, float $max = 1e9): ?float
{
    if ($value === null || $value === '' || !is_numeric(is_string($value) ? str_replace(',', '.', trim($value)) : $value)) {
        return null;
    }
    $number = (float)(is_string($value) ? str_replace(',', '.', trim($value)) : $value);
    return is_finite($number) && $number >= $min && $number <= $max ? round($number, 4) : null;
}

function tp_int(mixed $value, int $min = 0, int $max = 1000): ?int
{
    $number = tp_number($value, $min, $max);
    return $number === null ? null : (int)round($number);
}

function tp_enum(mixed $value, array $allowed, string $default): string
{
    return is_string($value) && array_key_exists($value, $allowed) ? $value : $default;
}

function tp_enum_list(mixed $value, array $allowed): array
{
    if (!is_array($value)) {
        return [];
    }
    $picked = array_filter($value, static fn($item): bool => is_string($item) && array_key_exists($item, $allowed));
    // Pořadí podle číselníku, ať je výstup vždy stejný.
    return array_values(array_filter(array_keys($allowed), static fn(string $key): bool => in_array($key, $picked, true)));
}

function tp_time(mixed $value): string
{
    return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($value)) ? trim($value) : '';
}

function tp_date(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : null;
}

function tp_section(array $data, string $key): array
{
    return is_array($data[$key] ?? null) ? $data[$key] : [];
}

function tp_texts(array $source, array $keys): array
{
    $result = [];
    foreach ($keys as $key) {
        $result[$key] = tp_text($source[$key] ?? '');
    }
    return $result;
}

/** Plán po přečištění: jen známé klíče, výčty z číselníků, existující účty a strategie. */
function tradeplan_normalize(array $data): array
{
    $accountIds = array_map('intval', array_column(fetch_all('SELECT id FROM accounts'), 'id'));
    $strategyIds = array_map('intval', array_column(fetch_all('SELECT id FROM strategies'), 'id'));

    $markets = [];
    foreach ((array)($data['markets'] ?? []) as $market) {
        if (!is_string($market)) {
            continue;
        }
        $symbol = strtoupper(tp_text($market, 16));
        if (preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,15}$/', $symbol) && !in_array($symbol, $markets, true)) {
            $markets[] = $symbol;
        }
    }

    $windows = [];
    foreach (array_slice((array)($data['windows'] ?? []), 0, 12) as $window) {
        if (!is_array($window)) {
            continue;
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($window['days'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        sort($days);
        $item = [
            'name' => tp_text($window['name'] ?? '', 60),
            'from' => tp_time($window['from'] ?? ''),
            'to' => tp_time($window['to'] ?? ''),
            'days' => $days,
            'kind' => tp_enum($window['kind'] ?? '', TP_WINDOW_KINDS, 'trade'),
            'note' => tp_text($window['note'] ?? '', 200),
        ];
        if ($item['name'] !== '' || $item['from'] !== '' || $item['to'] !== '') {
            $windows[] = $item;
        }
    }

    $accounts = [];
    foreach (array_slice((array)($data['accounts'] ?? []), 0, 20) as $account) {
        $id = is_array($account) ? (int)($account['account_id'] ?? 0) : 0;
        if (!in_array($id, $accountIds, true) || in_array($id, array_column($accounts, 'account_id'), true)) {
            continue;
        }
        $accounts[] = [
            'account_id' => $id,
            'role' => tp_enum($account['role'] ?? '', TP_ACCOUNT_ROLES, 'personal'),
            'risk_per_trade' => tp_number($account['risk_per_trade'] ?? null),
            'max_daily_loss' => tp_number($account['max_daily_loss'] ?? null),
            'max_trades_day' => tp_int($account['max_trades_day'] ?? null, 0, 100),
            'max_drawdown' => tp_number($account['max_drawdown'] ?? null),
            'drawdown_type' => tp_enum($account['drawdown_type'] ?? '', TP_DRAWDOWN, 'static'),
            'profit_target' => tp_number($account['profit_target'] ?? null),
            'rules' => tp_text($account['rules'] ?? '', 1500),
        ];
    }

    $strategies = [];
    foreach (array_slice((array)($data['strategies'] ?? []), 0, 40) as $strategy) {
        $id = is_array($strategy) ? (int)($strategy['strategy_id'] ?? 0) : 0;
        if (!in_array($id, $strategyIds, true) || in_array($id, array_column($strategies, 'strategy_id'), true)) {
            continue;
        }
        $allowed = array_values(array_intersect(array_map('intval', (array)($strategy['accounts'] ?? [])), array_column($accounts, 'account_id')));
        $strategies[] = [
            'strategy_id' => $id,
            'context' => tp_enum($strategy['context'] ?? '', TP_CONTEXTS, 'any'),
            'bias_rule' => tp_enum($strategy['bias_rule'] ?? '', TP_BIAS_RULES, 'with'),
            'zone_priority' => tp_enum($strategy['zone_priority'] ?? '', TP_ZONE_PRIORITY, 'AB'),
            'accounts' => $allowed,
            'min_rr' => tp_number($strategy['min_rr'] ?? null, 0, 50),
            'max_attempts' => tp_int($strategy['max_attempts'] ?? null, 0, 20),
        ] + tp_texts($strategy, ['when', 'conditions', 'entry', 'stop', 'targets', 'management', 'skip']);
    }

    $risk = tp_section($data, 'risk');
    $bias = tp_section($data, 'bias');
    $zones = tp_section($data, 'zones');
    $commitment = tp_section($data, 'commitment');
    $review = tp_section($data, 'review');

    return [
        'style' => tp_enum($data['style'] ?? '', TP_STYLES, 'intraday'),
        'markets' => array_slice($markets, 0, 12),
        'windows' => $windows,
        'accounts' => $accounts,
        'strategies' => $strategies,
        'risk' => [
            'daily_stop_r' => tp_number($risk['daily_stop_r'] ?? null, 0, 100),
            'weekly_stop_r' => tp_number($risk['weekly_stop_r'] ?? null, 0, 500),
            'max_losses_row' => tp_int($risk['max_losses_row'] ?? null, 0, 50),
            'max_trades_day' => tp_int($risk['max_trades_day'] ?? null, 0, 100),
        ] + tp_texts($risk, ['sizing', 'scale_down', 'scale_up']),
        'bias' => ['timeframes' => tp_enum_list($bias['timeframes'] ?? [], TP_TIMEFRAMES)] + tp_texts($bias, ['process', 'long_when', 'short_when', 'balance_when', 'invalidation']),
        'zones' => [
            'sources' => tp_enum_list($zones['sources'] ?? [], TP_ZONE_SOURCES),
            'max_width' => tp_number($zones['max_width'] ?? null, 0, 100000),
        ] + tp_texts($zones, ['rules', 'priority_a', 'priority_b', 'priority_c', 'validity', 'invalidation']),
        'routine' => tp_texts(tp_section($data, 'routine'), ['before', 'during', 'after']),
        'psychology' => tp_texts(tp_section($data, 'psychology'), ['bad_day', 'triggers', 'stop_rules']),
        'review' => ['next_on' => tp_date($review['next_on'] ?? null)] + tp_texts($review, ['daily', 'weekly', 'monthly', 'metrics', 'change_rules']),
        'commitment' => [
            'statement' => tp_text($commitment['statement'] ?? ''),
            'signature' => tp_text($commitment['signature'] ?? '', 80),
            'signed_on' => tp_date($commitment['signed_on'] ?? null),
        ],
    ] + tp_texts($data, ['mission', 'goals_process', 'goals_outcome', 'time_budget', 'markets_note', 'news_rule', 'no_trade_days']);
}

/* ---------------------------------------------------------------- uložení a verze */

function tradeplan_public(array $row): array
{
    $data = json_decode((string)$row['data'], true);
    return [
        'id' => (int)$row['id'],
        'version' => (int)$row['version'],
        'status' => (string)$row['status'],
        'title' => (string)$row['title'],
        'valid_from' => $row['valid_from'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'data' => tradeplan_normalize(is_array($data) ? $data : []),
    ];
}

function tradeplan_versions(): array
{
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'version' => (int)$row['version'],
        'status' => (string)$row['status'],
        'title' => (string)$row['title'],
        'valid_from' => $row['valid_from'],
        'updated_at' => $row['updated_at'],
    ], fetch_all('SELECT id, version, status, title, valid_from, updated_at FROM trading_plans ORDER BY version DESC, id DESC'));
}

/** Upravuje se poslední verze, která není archivovaná. */
function tradeplan_current(): ?array
{
    $row = fetch_one("SELECT * FROM trading_plans WHERE status <> 'archived' ORDER BY version DESC, id DESC LIMIT 1")
        ?? fetch_one('SELECT * FROM trading_plans ORDER BY version DESC, id DESC LIMIT 1');
    return $row === null ? null : tradeplan_public($row);
}

function tradeplan_state(): array
{
    return [
        'plan' => tradeplan_current(),
        'versions' => tradeplan_versions(),
        'options' => [
            'statuses' => TP_STATUSES,
            'styles' => TP_STYLES,
            'account_roles' => TP_ACCOUNT_ROLES,
            'drawdown' => TP_DRAWDOWN,
            'window_kinds' => TP_WINDOW_KINDS,
            'contexts' => TP_CONTEXTS,
            'bias_rules' => TP_BIAS_RULES,
            'zone_priority' => TP_ZONE_PRIORITY,
            'zone_sources' => TP_ZONE_SOURCES,
            'timeframes' => TP_TIMEFRAMES,
            'days' => TP_DAYS,
        ],
    ];
}

function tradeplan_save(array $input): array
{
    $title = tp_text($input['title'] ?? '', 120) ?: 'Obchodní plán';
    $status = tp_enum($input['status'] ?? '', ['draft' => 1, 'active' => 1], 'draft');
    $validFrom = tp_date($input['valid_from'] ?? null);
    $data = json_encode(tradeplan_normalize(is_array($input['data'] ?? null) ? $input['data'] : []), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $id = (int)($input['id'] ?? 0);
    $pdo = db();
    if ($id > 0) {
        $row = fetch_one('SELECT * FROM trading_plans WHERE id = ?', [$id]);
        if ($row === null) {
            json_response(['error' => 'Obchodní plán nebyl nalezen. Načti stránku znovu.'], 404);
        }
        if ($row['status'] === 'archived') {
            json_response(['error' => 'Archivovanou verzi plánu nelze měnit. Uprav aktuální verzi.'], 409);
        }
        $pdo->prepare('UPDATE trading_plans SET title = ?, status = ?, valid_from = ?, data = ?, updated_at = ? WHERE id = ?')
            ->execute([$title, $status, $validFrom, $data, utc_now(), $id]);
    } else {
        $version = (int)(fetch_one('SELECT COALESCE(MAX(version), 0) AS version FROM trading_plans')['version'] ?? 0) + 1;
        $pdo->prepare('INSERT INTO trading_plans (version, status, title, valid_from, data, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$version, $status, $title, $validFrom, $data, utc_now(), utc_now()]);
    }
    return tradeplan_state();
}

/** Nová verze: dosavadní se archivuje (zůstane v historii i pro PDF) a obsah se zkopíruje. */
function tradeplan_new_version(int $id): array
{
    $row = fetch_one('SELECT * FROM trading_plans WHERE id = ?', [$id]);
    if ($row === null) {
        json_response(['error' => 'Obchodní plán nebyl nalezen.'], 404);
    }
    $pdo = db();
    $version = (int)(fetch_one('SELECT COALESCE(MAX(version), 0) AS version FROM trading_plans')['version'] ?? 0) + 1;
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE trading_plans SET status = 'archived', updated_at = ? WHERE status <> 'archived'")->execute([utc_now()]);
    $pdo->prepare("INSERT INTO trading_plans (version, status, title, valid_from, data, created_at, updated_at) VALUES (?, 'draft', ?, ?, ?, ?, ?)")
        ->execute([$version, $row['title'], gmdate('Y-m-d'), $row['data'], utc_now(), utc_now()]);
    $pdo->commit();
    return tradeplan_state();
}

function tradeplan_delete(int $id): array
{
    $row = fetch_one('SELECT status FROM trading_plans WHERE id = ?', [$id]);
    if ($row === null) {
        json_response(['error' => 'Verze plánu nebyla nalezena.'], 404);
    }
    if ($row['status'] !== 'archived') {
        json_response(['error' => 'Smazat jde jen archivovanou verzi.'], 409);
    }
    db()->prepare('DELETE FROM trading_plans WHERE id = ?')->execute([$id]);
    return tradeplan_state();
}

/* ---------------------------------------------------------------- vzorový plán */

/**
 * Návrh obchodního plánu pro trading podle Market a Volume Profile. Doplní se jen do
 * prázdných polí (to dělá klient), takže nic vyplněného nepřepíše. Účty a strategie
 * vezme z deníku.
 */
function tradeplan_template(): array
{
    $user = current_user();
    $markets = array_slice(array_column(workspace()['markets'] ?? [], 'symbol'), 0, 1) ?: ['ES'];
    $weekdays = [1, 2, 3, 4, 5];
    $accounts = [];
    foreach (fetch_all('SELECT * FROM accounts ORDER BY name COLLATE NOCASE') as $account) {
        $label = strtolower($account['name'] . ' ' . $account['broker']);
        $prop = (bool)preg_match('/ftmo|topstep|apex|prop|challenge|eval|funded|the5ers|e8|fundednext|myfunded/', $label);
        $daily = $account['daily_risk'] !== null ? (float)$account['daily_risk'] : null;
        $accounts[] = [
            'account_id' => (int)$account['id'],
            'role' => $prop ? (str_contains($label, 'funded') ? 'funded' : 'eval') : 'personal',
            'risk_per_trade' => $daily !== null ? round($daily / 2, 2) : null,
            'max_daily_loss' => $daily,
            'max_trades_day' => 3,
            'max_drawdown' => null,
            'drawdown_type' => $prop ? 'trailing' : 'static',
            'profit_target' => null,
            'rules' => $prop ? 'Pravidla prop firmy mají přednost: denní a celkový limit ztráty, minimální počet obchodních dní, pravidlo konzistence a zákaz držení přes news, pokud ho firma má.' : '',
        ];
    }
    $strategies = [];
    foreach (fetch_all('SELECT * FROM strategies ORDER BY name COLLATE NOCASE') as $strategy) {
        $trend = ($strategy['style'] ?? '') === 'trend';
        $reversal = ($strategy['style'] ?? '') === 'reversal';
        $strategies[] = [
            'strategy_id' => (int)$strategy['id'],
            'context' => $trend ? 'trend' : ($reversal ? 'balance' : 'any'),
            'bias_rule' => $reversal ? 'any' : 'with',
            'zone_priority' => 'AB',
            'accounts' => [],
            'min_rr' => 2,
            'max_attempts' => 2,
            'when' => 'Otevření NY (RTH), první dvě hodiny.',
            'conditions' => $trend
                ? 'Pracovní bias a vyšší timeframe ukazují stejný směr. Cena se vrací do zóny A nebo B ve směru trendu a value migruje ve směru obchodu.'
                : ($reversal
                    ? 'Cena dojela na hranu range nebo do zóny proti biasu a ukazuje selhání iniciativy (poor high/low, excess, návrat do value).'
                    : 'Cena je v zóně A nebo B z denního náhledu a setup odpovídá pracovnímu biasu nebo hraně range.'),
            'entry' => 'Vstup až po potvrzení v zóně podle pravidel setupu, ne limitním příkazem naslepo.',
            'stop' => 'Za hranou zóny nebo za strukturou, která setup ruší. Stop je v platformě hned po vstupu.',
            'targets' => 'TP1 na nejbližší referenci (POC, protější hrana value), zbytek na další referenci z náhledu.',
            'management' => 'Po TP1 posun stopu na break-even. Do ztrátové pozice nepřidávám.',
            'skip' => 'Red news do 15 minut, zóna už byla dvakrát otestovaná, dvě ztráty dne, RR pod minimem.',
        ];
    }

    return [
        'style' => 'intraday',
        'mission' => 'Trading vedu jako podnikání: výsledek je důsledek dodrženého procesu. Obchoduji jen to, co je popsané v tomto plánu, a každý obchod zapíšu do deníku.',
        'goals_process' => "Před každou seancí mám hotový denní náhled: bias, zóny a red news.\nKaždý obchod má před vstupem stop loss a risk podle plánu.\nPlán dodržím aspoň u 90 % obchodů.\nKaždý obchod zapíšu do deníku do konce dne i se screenshotem.\nV neděli udělám týdenní review a týdenní náhled.",
        'goals_outcome' => "Kladná expectancy v R za každý měsíc.\nMoney audit sedí každý měsíc.\nDrawdown účtu zůstává pod limitem prop firmy.",
        'time_budget' => 'Příprava 45 minut před otevřením RTH, obchodování nejvýš 2 hodiny po otevření, 15 minut zápis do deníku.',
        'markets' => $markets,
        'markets_note' => 'Další trh přidám až po 50 obchodech s kladnou expectancy na hlavním trhu.',
        'windows' => [
            ['name' => 'Příprava a náhled', 'from' => '14:30', 'to' => '15:25', 'days' => $weekdays, 'kind' => 'watch', 'note' => 'Náhled, red news, test psychiky'],
            ['name' => 'Otevření NY (RTH)', 'from' => '15:30', 'to' => '17:30', 'days' => $weekdays, 'kind' => 'trade', 'note' => 'Hlavní okno pro setupy z plánu'],
            ['name' => 'Oběd v New Yorku', 'from' => '18:00', 'to' => '19:30', 'days' => $weekdays, 'kind' => 'off', 'note' => 'Nízká likvidita, neobchoduji'],
            ['name' => 'Odpoledne NY', 'from' => '19:30', 'to' => '21:30', 'days' => $weekdays, 'kind' => 'watch', 'note' => 'Jen zóny A a jen ve směru biasu'],
        ],
        'news_rule' => 'Red news (CPI, FOMC, NFP, ISM): 5 minut před a 15 minut po zveřejnění nevstupuji. Otevřený obchod mám před news chráněný stopem.',
        'no_trade_days' => 'Den FOMC do zveřejnění, NFP do 15:00, svátky a zkrácené seance v USA, den rollu kontraktu.',
        'accounts' => $accounts,
        'risk' => [
            'daily_stop_r' => 2,
            'weekly_stop_r' => 5,
            'max_losses_row' => 2,
            'max_trades_day' => 3,
            'sizing' => 'Počet kontraktů = risk na obchod / (|vstup − stop loss| × hodnota bodu). Zaokrouhluji dolů, nikdy nahoru.',
            'scale_down' => 'Po ztrátě 5 R za týden nebo při drawdownu 8 % snížím risk na polovinu, dokud nevydělám zpět 3 R.',
            'scale_up' => 'Risk zvýším nejdřív po 40 obchodech s kladnou expectancy a dodržením plánu nad 90 %, a to nejvýš o 25 %.',
        ],
        'bias' => [
            'timeframes' => ['monthly', 'weekly', 'daily'],
            'process' => "Monthly a Weekly price action: struktura HH/HL nebo LH/LL, BOS, range.\nWeekly profil: kam migruje value a POC, přijetí nad VAH nebo pod VAL.\nDaily profil: tvar (P, b, D), close vůči value, single prints, poor high a poor low.\nPracovní bias je směr, na kterém se shodne vyšší timeframe a profil. Když se neshodnou, je bias balance.",
            'long_when' => 'Weekly PA dělá HH/HL a value migruje výš, nebo trh přijal ceny nad VAH předchozího týdne.',
            'short_when' => 'Weekly PA dělá LH/LL a value migruje níž, nebo trh přijal ceny pod VAL předchozího týdne.',
            'balance_when' => 'Timeframy si odporují, value se překrývá a trh rotuje uvnitř týdenní value. Obchoduji jen hrany range.',
            'invalidation' => 'Akceptace na opačné straně klíčové zóny (dvě 30min periody), nebo selhání iniciativy: návrat do value po breakoutu.',
        ],
        'zones' => [
            'sources' => ['vah_val', 'poc', 'naked_poc', 'single_prints', 'poor_hl', 'dinapoli', 'levels'],
            'max_width' => 8,
            'rules' => "Zóna je oblast, ne čára: kreslím ji od reference k nejbližší protější hraně, třeba od VAL po spodek single prints.\nZónu stavím jen tam, kde se potkají aspoň dvě reference (konfluence).\nKaždá zóna má směr (long, short nebo obojí) a předem napsané podmínky vstupu i to, kdy obchod neberu.",
            'priority_a' => 'Konfluence tří a více referencí, ve směru pracovního biasu, na hraně týdenní value.',
            'priority_b' => 'Dvě reference, ve směru biasu nebo na hraně range při balance.',
            'priority_c' => 'Jedna reference nebo proti biasu. Jen sleduji, neobchoduji.',
            'validity' => 'Denní zóna platí do zasažení a reakce, týdenní do konce týdne.',
            'invalidation' => 'Akceptace přes zónu (uzavření 30min periody za hranou a pokračování) zónu ruší.',
        ],
        'strategies' => $strategies,
        'routine' => [
            'before' => "Rychlý test psychiky v aplikaci.\nRed news a svátky v kalendáři.\nDenní náhled: bias, zóny, reference, scénáře.\nRisk na obchod a denní limit účtu.\nAlerty na zóny A.",
            'during' => "Obchoduji jen zóny z náhledu a jen v obchodním okně.\nStop loss je v platformě hned po vstupu.\nPo dvou ztrátách nebo po −2 R končím.\nStop nikdy neposouvám dál od vstupu.",
            'after' => "Všechny obchody v deníku se screenshoty.\nU každého obchodu dodržení plánu a hodnocení exekuce.\nJedna věc, kterou zítra udělám lépe.",
        ],
        'psychology' => [
            'bad_day' => "Po špatném spánku nebo nemoci obchoduji poloviční risk, nebo vůbec.\nKdyž mám v testu psychiky červenou, ten den jen sleduji.",
            'triggers' => "FOMO po ujetém pohybu → počkám na další zónu, nenaskakuji\nZtráta → 10 minut pauza mimo grafy\nTři zisky v řadě → stejný risk, nezvyšuji\nVztek po stopu → konec dne",
            'stop_rules' => "Denní limit −2 R nebo dvě ztráty v řadě.\nPorušené pravidlo plánu znamená konec dne bez ohledu na výsledek.",
        ],
        'review' => [
            'next_on' => null,
            'daily' => 'Zápis do deníku, dodržení plánu, screenshot vstupu a výstupu.',
            'weekly' => 'Neděle: výsledek v R, expectancy, chyby z rozboru, co fungovalo. Příprava týdenního náhledu.',
            'monthly' => 'Money audit, výkonnost strategií, rozhodnutí o risku na další měsíc.',
            'metrics' => "Expectancy v R\nProfit factor\nDodržení plánu v %\nPrůměrné hodnocení exekuce\nPočet porušených pravidel",
            'change_rules' => 'Plán měním jen o víkendu a jen na základě dat z deníku (aspoň 20 obchodů). Každá změna je nová verze plánu.',
        ],
        'commitment' => [
            'statement' => 'Zavazuji se obchodovat jen podle tohoto plánu. Když pravidlo poruším, zapíšu to do deníku a ten den končím.',
            'signature' => (string)($user['display_name'] ?? ''),
            'signed_on' => gmdate('Y-m-d'),
        ],
    ];
}

/* ---------------------------------------------------------------- PDF */

/** Podklady pro PDF: plán a k němu účty, strategie (s výkonností) a jejich náhledové obrázky. */
function tradeplan_pdf_payload(int $id): ?array
{
    $row = fetch_one('SELECT * FROM trading_plans WHERE id = ?', [$id]);
    if ($row === null) {
        return null;
    }
    $plan = tradeplan_public($row);
    $accounts = [];
    foreach (fetch_all('SELECT * FROM accounts') as $account) {
        $accounts[(int)$account['id']] = [
            'name' => (string)$account['name'],
            'broker' => (string)($account['broker'] ?? ''),
            'currency' => (string)$account['currency'],
            'starting_balance' => (float)$account['starting_balance'],
            'daily_risk' => $account['daily_risk'] !== null ? (float)$account['daily_risk'] : null,
            'ctrader' => fetch_one('SELECT 1 FROM broker_accounts WHERE account_id = ?', [(int)$account['id']]) !== null,
        ];
    }
    $stats = [];
    foreach (strategy_statistics() as $item) {
        $stats[(int)$item['strategy_id']] = [
            'trades' => (int)$item['trades'],
            'total_r' => (float)$item['total_r'],
            'expectancy_r' => $item['expectancy_r'],
            'profit_factor' => $item['profit_factor'],
            'plan_adherence' => $item['plan_adherence'],
        ];
    }
    $strategies = [];
    foreach (fetch_all('SELECT * FROM strategies') as $strategy) {
        $cover = fetch_one('SELECT file_name, mime_type FROM screenshots WHERE strategy_id = ? ORDER BY created_at, rowid LIMIT 1', [(int)$strategy['id']]);
        $strategies[(int)$strategy['id']] = [
            'name' => (string)$strategy['name'],
            'timeframe' => (string)($strategy['timeframe'] ?? ''),
            'style' => (string)($strategy['style'] ?? ''),
            'notes' => (string)($strategy['notes'] ?? ''),
            'cover' => $cover,
            'stats' => $stats[(int)$strategy['id']] ?? null,
        ];
    }
    $user = current_user();
    return [
        'plan' => $plan,
        'accounts' => $accounts,
        'strategies' => $strategies,
        'options' => tradeplan_state()['options'],
        'trader' => (string)($user['display_name'] ?? ''),
        'generated_at' => utc_now(),
    ];
}
