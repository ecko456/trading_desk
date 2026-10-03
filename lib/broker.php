<?php
declare(strict_types=1);

/*
 * Účty u brokera (zatím cTrader) napojené na deník.
 *
 * Synchronizace stáhne zůstatek, otevřené pozice, obchody (deals), příkazy a pohyby na
 * účtu. Obchody a pohyby se ukládají do deníku (broker_deals, broker_cash), aby šly
 * znovu přepočítat a nemusely se stahovat dvakrát. Každá uzavřená pozice se jednou
 * zapíše jako obchod deníku; smazaný obchod se znovu nevrací (broker_positions).
 *
 * Money audit napojeného účtu bere zůstatek přímo z cTraderu. Očekávaný stav deníku se
 * u něj opraví o vklady a výběry od začátku importu a o výsledek částečně uzavřených
 * pozic, které ještě nejsou obchodem v deníku (broker_balance_adjustment).
 */

const BROKER_HISTORY_DAYS = 365;
const BROKER_WINDOW_MS = 7 * 86400 * 1000;
const BROKER_MIN_WINDOW_MS = 3600 * 1000;
const BROKER_OVERLAP_MS = 3600 * 1000;
/** Kolik pozic otevřených před staženým obdobím se dohledá při jedné synchronizaci. */
const BROKER_MAX_LOOKUPS = 40;

function ensure_broker_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS broker_connections (
    id INTEGER PRIMARY KEY,
    provider TEXT NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS broker_accounts (
    id INTEGER PRIMARY KEY,
    connection_id INTEGER NOT NULL REFERENCES broker_connections(id) ON DELETE CASCADE,
    provider TEXT NOT NULL,
    external_id TEXT NOT NULL,
    is_live INTEGER NOT NULL DEFAULT 0,
    login TEXT,
    broker_name TEXT,
    account_id INTEGER REFERENCES accounts(id) ON DELETE SET NULL,
    import_from TEXT,
    currency TEXT,
    balance REAL,
    equity REAL,
    positions TEXT NOT NULL DEFAULT '[]',
    cash_flow REAL NOT NULL DEFAULT 0,
    open_realized REAL NOT NULL DEFAULT 0,
    synced_until INTEGER,
    last_sync_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    UNIQUE(provider, external_id)
);

CREATE TABLE IF NOT EXISTS broker_deals (
    broker_account_id INTEGER NOT NULL REFERENCES broker_accounts(id) ON DELETE CASCADE,
    deal_id TEXT NOT NULL,
    position_id TEXT NOT NULL,
    executed_ms INTEGER NOT NULL,
    closing INTEGER NOT NULL DEFAULT 0,
    data TEXT NOT NULL,
    PRIMARY KEY (broker_account_id, deal_id)
);

CREATE INDEX IF NOT EXISTS idx_broker_deals_position ON broker_deals(broker_account_id, position_id);

CREATE TABLE IF NOT EXISTS broker_cash (
    broker_account_id INTEGER NOT NULL REFERENCES broker_accounts(id) ON DELETE CASCADE,
    operation_id TEXT NOT NULL,
    executed_ms INTEGER NOT NULL,
    delta REAL NOT NULL,
    balance REAL,
    balance_version INTEGER,
    PRIMARY KEY (broker_account_id, operation_id)
);

CREATE TABLE IF NOT EXISTS broker_positions (
    broker_account_id INTEGER NOT NULL REFERENCES broker_accounts(id) ON DELETE CASCADE,
    position_id TEXT NOT NULL,
    stop_loss REAL,
    take_profit REAL,
    is_open INTEGER NOT NULL DEFAULT 0,
    trade_id INTEGER,
    imported_at TEXT,
    PRIMARY KEY (broker_account_id, position_id)
);
SQL);
    if (!in_array('external_ref', table_columns($pdo, 'trades'), true)) {
        add_column($pdo, 'trades', 'external_ref', 'TEXT');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trades_external ON trades(external_ref)');
    if (!in_array('source', table_columns($pdo, 'account_audits'), true)) {
        add_column($pdo, 'account_audits', 'source', 'TEXT');
    }
}

/* ---------------------------------------------------------------- stav pro klienta */

function broker_state(): array
{
    $rows = fetch_all('SELECT b.*, a.name AS account_name FROM broker_accounts b LEFT JOIN accounts a ON a.id = b.account_id ORDER BY b.is_live DESC, b.broker_name COLLATE NOCASE, b.login');
    return [
        'configured' => ctrader_configured(),
        'connect_url' => 'ctrader.php?start=1',
        'history_days' => BROKER_HISTORY_DAYS,
        'accounts' => array_map('broker_account_public', $rows),
    ];
}

/** Účet bez přístupových klíčů (ty z deníku nikdy neodcházejí). */
function broker_account_public(array $row): array
{
    $positions = json_decode((string)($row['positions'] ?? '[]'), true);
    $imported = fetch_one('SELECT COUNT(*) AS total FROM broker_positions WHERE broker_account_id = ? AND imported_at IS NOT NULL', [(int)$row['id']]);
    return [
        'id' => (int)$row['id'],
        'provider' => (string)$row['provider'],
        'external_id' => (string)$row['external_id'],
        'is_live' => (bool)$row['is_live'],
        'login' => (string)($row['login'] ?? ''),
        'broker_name' => (string)($row['broker_name'] ?? ''),
        'account_id' => $row['account_id'] !== null ? (int)$row['account_id'] : null,
        'account_name' => $row['account_name'] ?? null,
        'import_from' => $row['import_from'],
        'currency' => $row['currency'],
        'balance' => $row['balance'] !== null ? (float)$row['balance'] : null,
        'equity' => $row['equity'] !== null ? (float)$row['equity'] : null,
        'positions' => is_array($positions) ? $positions : [],
        'cash_flow' => (float)$row['cash_flow'],
        'open_realized' => (float)$row['open_realized'],
        'last_sync_at' => $row['last_sync_at'],
        'last_error' => $row['last_error'],
        'imported' => (int)($imported['total'] ?? 0),
    ];
}

/** Oprava očekávaného zůstatku napojeného účtu (vklady, výběry, rozpracované pozice). */
function broker_balance_adjustment(int $accountId): array
{
    $row = fetch_one('SELECT COALESCE(SUM(cash_flow), 0) AS cash, COALESCE(SUM(open_realized), 0) AS open, COUNT(*) AS linked FROM broker_accounts WHERE account_id = ?', [$accountId]) ?? [];
    $cash = round((float)($row['cash'] ?? 0), 2);
    $open = round((float)($row['open'] ?? 0), 2);
    return ['linked' => (int)($row['linked'] ?? 0) > 0, 'cash' => $cash, 'open' => $open, 'total' => round($cash + $open, 2)];
}

/** Údaje z brokera ke kartě účtu v deníku. */
function broker_account_summary(int $accountId): ?array
{
    $row = fetch_one('SELECT * FROM broker_accounts WHERE account_id = ? ORDER BY id LIMIT 1', [$accountId]);
    if ($row === null) {
        return null;
    }
    return [
        'provider' => (string)$row['provider'],
        'login' => (string)($row['login'] ?? ''),
        'is_live' => (bool)$row['is_live'],
        'balance' => $row['balance'] !== null ? (float)$row['balance'] : null,
        'equity' => $row['equity'] !== null ? (float)$row['equity'] : null,
        'last_sync_at' => $row['last_sync_at'],
    ];
}

/* ---------------------------------------------------------------- napojení */

/** Uloží přístup z přihlášení do cTraderu a účty, které k němu patří. */
function broker_save_connection(array $token, array $accounts): int
{
    $pdo = db();
    $now = utc_now();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO broker_connections (provider, access_token, refresh_token, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['ctrader', $token['access_token'], $token['refresh_token'], (int)$token['expires_at'], $now, $now]);
        $connectionId = (int)$pdo->lastInsertId();
        foreach ($accounts as $account) {
            $existing = fetch_one('SELECT id FROM broker_accounts WHERE provider = ? AND external_id = ?', ['ctrader', $account['external_id']]);
            if ($existing !== null) {
                // Znovu napojený účet si nechá propojení s deníkem i stažená data.
                $pdo->prepare('UPDATE broker_accounts SET connection_id = ?, is_live = ?, login = ?, broker_name = ?, last_error = NULL WHERE id = ?')
                    ->execute([$connectionId, $account['is_live'] ? 1 : 0, $account['login'], $account['broker_name'], (int)$existing['id']]);
            } else {
                $pdo->prepare('INSERT INTO broker_accounts (connection_id, provider, external_id, is_live, login, broker_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$connectionId, 'ctrader', $account['external_id'], $account['is_live'] ? 1 : 0, $account['login'], $account['broker_name'], $now]);
            }
        }
        $pdo->exec('DELETE FROM broker_connections WHERE id NOT IN (SELECT connection_id FROM broker_accounts)');
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    return count($accounts);
}

/** Odebere účet z napojení; obchody, které už jsou v deníku, zůstanou. */
function broker_remove_account(int $id): void
{
    $row = fetch_one('SELECT id FROM broker_accounts WHERE id = ?', [$id]);
    if ($row === null) {
        json_response(['error' => 'Napojený účet nebyl nalezen.'], 404);
    }
    db()->prepare('DELETE FROM broker_accounts WHERE id = ?')->execute([$id]);
    db()->exec('DELETE FROM broker_connections WHERE id NOT IN (SELECT connection_id FROM broker_accounts)');
}

/** Propojí účet cTraderu s účtem v deníku (stávajícím, nebo novým) a hned ho stáhne. */
function broker_link_account(array $data): array
{
    $id = (int)($data['id'] ?? 0);
    $row = fetch_one('SELECT * FROM broker_accounts WHERE id = ?', [$id]);
    if ($row === null) {
        json_response(['error' => 'Napojený účet nebyl nalezen.'], 404);
    }
    $target = $data['account_id'] ?? null;
    if ($target !== null && $target !== '' && !ctrader_configured()) {
        json_response(['error' => 'Napojení na cTrader zatím není nastavené ve Správě.'], 409);
    }
    if ($target === null || $target === '') {
        db()->prepare('UPDATE broker_accounts SET account_id = NULL, open_realized = 0, cash_flow = 0 WHERE id = ?')->execute([$id]);
        return ['state' => broker_state(), 'imported' => 0, 'audits' => 0];
    }
    $today = gmdate('Y-m-d');
    $earliest = gmdate('Y-m-d', time() - BROKER_HISTORY_DAYS * 86400);
    $importFrom = trim((string)($data['import_from'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $importFrom) || $importFrom > $today) {
        $importFrom = $today;
    }
    if ($importFrom < $earliest) {
        json_response(['error' => 'Obchody jde stáhnout nejvýš ' . BROKER_HISTORY_DAYS . ' dní zpátky (od ' . $earliest . ').'], 422);
    }
    $create = $target === 'new';
    if (!$create) {
        $accountId = (int)$target;
        if (fetch_one('SELECT id FROM accounts WHERE id = ?', [$accountId]) === null) {
            json_response(['error' => 'Účet v deníku nebyl nalezen.'], 404);
        }
        if (fetch_one('SELECT id FROM broker_accounts WHERE account_id = ? AND id <> ?', [$accountId, $id]) !== null) {
            json_response(['error' => 'Tenhle účet v deníku už je propojený s jiným účtem cTraderu.'], 409);
        }
    }
    // Nové období se stáhne znovu; už zapsané obchody se díky broker_positions nezdvojí.
    db()->prepare('UPDATE broker_accounts SET account_id = ?, import_from = ?, synced_until = NULL, last_error = NULL WHERE id = ?')
        ->execute([$create ? null : (int)$target, $importFrom, $id]);
    $row = fetch_one('SELECT * FROM broker_accounts WHERE id = ?', [$id]) ?? [];
    $api = new CtraderApi();
    try {
        $result = broker_sync_account($api, $row, $create);
    } catch (CtraderError $error) {
        db()->prepare('UPDATE broker_accounts SET last_error = ? WHERE id = ?')->execute([$error->getMessage(), $id]);
        json_response(['error' => $error->getMessage(), 'state' => broker_state()], 502);
    }
    return ['state' => broker_state(), 'imported' => $result['imported'], 'audits' => $result['audit'] ? 1 : 0];
}

/* ---------------------------------------------------------------- synchronizace */

function broker_sync(?int $id = null): array
{
    if (!ctrader_configured()) {
        json_response(['error' => 'Napojení na cTrader zatím není nastavené ve Správě.'], 409);
    }
    @set_time_limit(300);
    $rows = $id !== null
        ? fetch_all('SELECT * FROM broker_accounts WHERE id = ? AND account_id IS NOT NULL', [$id])
        : fetch_all('SELECT * FROM broker_accounts WHERE account_id IS NOT NULL ORDER BY id');
    $api = new CtraderApi();
    $summary = ['imported' => 0, 'audits' => 0, 'errors' => []];
    foreach ($rows as $row) {
        try {
            $result = broker_sync_account($api, $row, false);
            $summary['imported'] += $result['imported'];
            $summary['audits'] += $result['audit'] ? 1 : 0;
        } catch (CtraderError $error) {
            db()->prepare('UPDATE broker_accounts SET last_error = ? WHERE id = ?')->execute([$error->getMessage(), (int)$row['id']]);
            $summary['errors'][] = trim(($row['broker_name'] ?: 'cTrader') . ' ' . $row['login']) . ': ' . $error->getMessage();
        }
    }
    $summary['state'] = broker_state();
    return $summary;
}

/** Platný přístupový klíč napojení; před vypršením ho obnoví. */
function broker_access_token(int $connectionId, bool $force = false): string
{
    $connection = fetch_one('SELECT * FROM broker_connections WHERE id = ?', [$connectionId]);
    if ($connection === null || (string)$connection['refresh_token'] === '') {
        throw new CtraderError('Přístup k cTraderu chybí. Napoj účet znovu tlačítkem Napojit cTrader.', 'CH_ACCESS_TOKEN_INVALID');
    }
    if (!$force && (int)$connection['expires_at'] - time() > CT_REFRESH_BEFORE) {
        return (string)$connection['access_token'];
    }
    $token = ctrader_refresh_token((string)$connection['refresh_token']);
    db()->prepare('UPDATE broker_connections SET access_token = ?, refresh_token = ?, expires_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$token['access_token'], $token['refresh_token'], $token['expires_at'], utc_now(), $connectionId]);
    return $token['access_token'];
}

function broker_date_ms(string $date): int
{
    return (int)(new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->format('U') * 1000;
}

/** Obchodní den okamžiku: začíná v 18:00 New York předchozího dne (jako v Hindsightu). */
function broker_trade_day(int $ms): string
{
    $moment = (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('America/New_York'));
    return $moment->modify('+6 hours')->format('Y-m-d');
}

function broker_sync_account(CtraderApi $api, array $row, bool $createAccount): array
{
    $brokerId = (int)$row['id'];
    $live = (bool)$row['is_live'];
    $ctid = (int)$row['external_id'];
    $token = broker_access_token((int)$row['connection_id']);
    try {
        $api->authorize($live, (string)$ctid, $token);
    } catch (CtraderError $error) {
        if (!in_array($error->errorCode, ['CH_ACCESS_TOKEN_INVALID', 'OA_AUTH_TOKEN_EXPIRED'], true)) {
            throw $error;
        }
        $api->authorize($live, (string)$ctid, broker_access_token((int)$row['connection_id'], true));
    }
    $account = ['ctidTraderAccountId' => $ctid];

    $trader = (array)($api->call($live, CT_TRADER_REQ, $account, CT_TRADER_RES)['trader'] ?? []);
    $digits = $trader['moneyDigits'] ?? 2;
    $balance = round(ct_money($trader['balance'] ?? 0, $digits), 2);
    $currency = (string)($row['currency'] ?? '');
    if ($currency === '') {
        $currency = broker_asset_name($api, $live, $ctid, ct_int($trader['depositAssetId'] ?? 0));
    }

    // Historie po týdnech; hotové období se zapíše hned, ať se při přerušení nestahuje znovu.
    $importMs = broker_date_ms((string)($row['import_from'] ?: gmdate('Y-m-d')));
    $fromMs = $row['synced_until'] !== null ? max($importMs, (int)$row['synced_until'] - BROKER_OVERLAP_MS) : $importMs;
    $nowMs = (int)floor(microtime(true) * 1000);
    for ($start = $fromMs; $start < $nowMs; $start += BROKER_WINDOW_MS) {
        $end = min($nowMs, $start + BROKER_WINDOW_MS);
        broker_fetch_deals($api, $live, $ctid, $brokerId, $start, $end);
        broker_fetch_orders($api, $live, $ctid, $brokerId, $start, $end);
        broker_fetch_cash($api, $live, $ctid, $brokerId, $start, $end);
        db()->prepare('UPDATE broker_accounts SET synced_until = ? WHERE id = ?')->execute([$end, $brokerId]);
    }

    // Otevřené pozice: stop loss se zapamatuje, dokud je vidět (pro výpočet R při uzavření).
    $reconcile = $api->call($live, CT_RECONCILE_REQ, $account, CT_RECONCILE_RES);
    $open = [];
    foreach ((array)($reconcile['position'] ?? []) as $position) {
        if (!is_array($position) || ct_int($position['positionId'] ?? 0) <= 0) {
            continue;
        }
        $open[(string)ct_int($position['positionId'])] = $position;
    }
    db()->prepare('UPDATE broker_positions SET is_open = 0 WHERE broker_account_id = ?')->execute([$brokerId]);
    foreach ($open as $positionId => $position) {
        $trade = (array)($position['tradeData'] ?? []);
        $stop = broker_valid_stop(ct_side($trade['tradeSide'] ?? 1), (float)($position['price'] ?? 0), $position['stopLoss'] ?? null);
        broker_remember_position($brokerId, (string)$positionId, $stop, isset($position['takeProfit']) ? (float)$position['takeProfit'] : null, true);
    }
    $pnl = $api->call($live, CT_UNREALIZED_PNL_REQ, $account, CT_UNREALIZED_PNL_RES);
    $unrealized = [];
    foreach ((array)($pnl['positionUnrealizedPnL'] ?? []) as $item) {
        if (is_array($item)) {
            $unrealized[(string)ct_int($item['positionId'] ?? 0)] = ct_money($item['netUnrealizedPnL'] ?? 0, $pnl['moneyDigits'] ?? $digits);
        }
    }
    $equity = round($balance + array_sum($unrealized), 2);

    $candidates = broker_import_candidates($brokerId, $importMs);
    $lookups = 0;
    foreach ($candidates as $positionId) {
        if ($lookups >= BROKER_MAX_LOOKUPS) {
            break;
        }
        if (fetch_one('SELECT 1 FROM broker_deals WHERE broker_account_id = ? AND position_id = ? AND closing = 0', [$brokerId, $positionId]) === null) {
            broker_fetch_position_deals($api, $live, $ctid, $brokerId, $positionId);
            $lookups++;
        }
    }
    $symbolIds = [];
    foreach ($open as $position) {
        $symbolIds[] = ct_int($position['tradeData']['symbolId'] ?? 0);
    }
    foreach (fetch_all('SELECT DISTINCT data FROM broker_deals WHERE broker_account_id = ? AND position_id IN (' . broker_placeholders($candidates) . ')', [$brokerId, ...$candidates]) as $deal) {
        $symbolIds[] = ct_int(json_decode((string)$deal['data'], true)['symbolId'] ?? 0);
    }
    $symbols = broker_symbols($api, $live, $ctid, $symbolIds);

    $accountId = $row['account_id'] !== null ? (int)$row['account_id'] : null;
    if ($createAccount) {
        $accountId = broker_create_journal_account($row, $brokerId, $balance, $currency, $importMs);
    }
    if ($accountId === null) {
        throw new CtraderError('Účet cTraderu není propojený s účtem v deníku.', 'NOT_LINKED');
    }
    $imported = broker_import_positions($brokerId, $row, $accountId, $candidates, $symbols, $open);
    if ($createAccount) {
        // Pozice otevřené před začátkem importu mají datum obchodu dřív; účet je musí počítat.
        db()->prepare('UPDATE accounts SET opened_at = MIN(opened_at, COALESCE((SELECT MIN(trade_date) FROM trades WHERE account_id = ?), opened_at)) WHERE id = ?')->execute([$accountId, $accountId]);
    }

    $cash = fetch_one('SELECT COALESCE(SUM(delta), 0) AS total FROM broker_cash WHERE broker_account_id = ? AND executed_ms >= ?', [$brokerId, $importMs]);
    $openRealized = 0.0;
    if ($open !== []) {
        $versions = broker_balance_versions($brokerId);
        foreach (fetch_all('SELECT data FROM broker_deals WHERE broker_account_id = ? AND closing = 1 AND executed_ms >= ? AND position_id IN (' . broker_placeholders(array_keys($open)) . ')', [$brokerId, $importMs, ...array_map('strval', array_keys($open))]) as $deal) {
            $openRealized += broker_deal_net(json_decode((string)$deal['data'], true) ?: [], $versions);
        }
    }
    $positions = [];
    foreach ($open as $positionId => $position) {
        $trade = (array)($position['tradeData'] ?? []);
        $symbol = $symbols[ct_int($trade['symbolId'] ?? 0)] ?? null;
        $positions[] = [
            'id' => (string)$positionId,
            'symbol' => $symbol['name'] ?? ('#' . ct_int($trade['symbolId'] ?? 0)),
            'direction' => ct_side($trade['tradeSide'] ?? 1),
            'volume' => broker_volume(ct_int($trade['volume'] ?? 0), $symbol),
            'unit' => isset($symbol['lot']) ? 'lot' : 'ks',
            'entry' => isset($position['price']) ? (float)$position['price'] : null,
            'stop_loss' => isset($position['stopLoss']) ? (float)$position['stopLoss'] : null,
            'take_profit' => isset($position['takeProfit']) ? (float)$position['takeProfit'] : null,
            'pnl' => isset($unrealized[$positionId]) ? round($unrealized[$positionId], 2) : null,
            'opened_at' => isset($trade['openTimestamp']) ? gmdate('Y-m-d\TH:i:s\Z', intdiv(ct_int($trade['openTimestamp']), 1000)) : null,
        ];
    }
    db()->prepare('UPDATE broker_accounts SET account_id = ?, currency = ?, balance = ?, equity = ?, positions = ?, cash_flow = ?, open_realized = ?, last_sync_at = ?, last_error = NULL WHERE id = ?')
        ->execute([$accountId, $currency, $balance, $equity, json_encode($positions, JSON_UNESCAPED_UNICODE), round((float)($cash['total'] ?? 0), 2), round($openRealized, 2), utc_now(), $brokerId]);

    $audit = broker_auto_audit($accountId, $balance, $row, false);
    return ['imported' => $imported, 'audit' => $audit !== null, 'balance' => $balance];
}

function broker_placeholders(array $values): string
{
    return $values === [] ? 'NULL' : implode(', ', array_fill(0, count($values), '?'));
}

function broker_asset_name(CtraderApi $api, bool $live, int $ctid, int $assetId): string
{
    $assets = $api->call($live, CT_ASSET_LIST_REQ, ['ctidTraderAccountId' => $ctid], CT_ASSET_LIST_RES);
    foreach ((array)($assets['asset'] ?? []) as $asset) {
        if (is_array($asset) && ct_int($asset['assetId'] ?? 0) === $assetId) {
            $name = strtoupper(trim((string)($asset['name'] ?? '')));
            return preg_match('/^[A-Z]{3}$/', $name) ? $name : 'USD';
        }
    }
    return 'USD';
}

/** Obchody za období; při příliš mnoha výsledcích období rozpůlí. */
function broker_fetch_deals(CtraderApi $api, bool $live, int $ctid, int $brokerId, int $from, int $to): void
{
    $result = $api->history($live, CT_DEAL_LIST_REQ, ['ctidTraderAccountId' => $ctid, 'fromTimestamp' => $from, 'toTimestamp' => $to, 'maxRows' => 1000], CT_DEAL_LIST_RES);
    if (!empty($result['hasMore']) && $to - $from > BROKER_MIN_WINDOW_MS) {
        $middle = $from + intdiv($to - $from, 2);
        broker_fetch_deals($api, $live, $ctid, $brokerId, $from, $middle);
        broker_fetch_deals($api, $live, $ctid, $brokerId, $middle, $to);
        return;
    }
    broker_store_deals($brokerId, (array)($result['deal'] ?? []));
}

function broker_fetch_position_deals(CtraderApi $api, bool $live, int $ctid, int $brokerId, string $positionId): void
{
    $close = fetch_one('SELECT MIN(executed_ms) AS first, MAX(executed_ms) AS last FROM broker_deals WHERE broker_account_id = ? AND position_id = ?', [$brokerId, $positionId]) ?? [];
    $result = $api->history($live, CT_DEALS_BY_POSITION_REQ, [
        'ctidTraderAccountId' => $ctid,
        'positionId' => (int)$positionId,
        'fromTimestamp' => max(0, (int)($close['first'] ?? 0) - 90 * 86400 * 1000),
        'toTimestamp' => (int)($close['last'] ?? 0) + 1000,
    ], CT_DEALS_BY_POSITION_RES);
    broker_store_deals($brokerId, (array)($result['deal'] ?? []));
}

function broker_store_deals(int $brokerId, array $deals): void
{
    $statement = db()->prepare('INSERT INTO broker_deals (broker_account_id, deal_id, position_id, executed_ms, closing, data) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(broker_account_id, deal_id) DO UPDATE SET executed_ms = excluded.executed_ms, closing = excluded.closing, data = excluded.data');
    foreach ($deals as $deal) {
        if (!is_array($deal) || !ct_deal_filled($deal) || ct_int($deal['dealId'] ?? 0) <= 0 || ct_int($deal['positionId'] ?? 0) <= 0) {
            continue;
        }
        $statement->execute([
            $brokerId,
            (string)ct_int($deal['dealId']),
            (string)ct_int($deal['positionId']),
            ct_int($deal['executionTimestamp'] ?? $deal['createTimestamp'] ?? 0),
            is_array($deal['closePositionDetail'] ?? null) ? 1 : 0,
            json_encode($deal, JSON_UNESCAPED_UNICODE),
        ]);
    }
}

/** Z příkazů se vezme stop loss a take profit zadaný při vstupu (pro risk a R). */
function broker_fetch_orders(CtraderApi $api, bool $live, int $ctid, int $brokerId, int $from, int $to): void
{
    $result = $api->history($live, CT_ORDER_LIST_REQ, ['ctidTraderAccountId' => $ctid, 'fromTimestamp' => $from, 'toTimestamp' => $to], CT_ORDER_LIST_RES);
    $orders = array_filter((array)($result['order'] ?? []), 'is_array');
    // Nejdřív příkazy vstupu (jejich stop je původní risk), až potom samostatné příkazy SL/TP.
    $protective = static fn(array $order): int => in_array($order['orderType'] ?? 1, [4, '4', 'STOP_LOSS_TAKE_PROFIT'], true) ? 1 : 0;
    usort($orders, static fn(array $a, array $b): int => [$protective($a), ct_int($a['utcLastUpdateTimestamp'] ?? 0)] <=> [$protective($b), ct_int($b['utcLastUpdateTimestamp'] ?? 0)]);
    foreach ($orders as $order) {
        $positionId = ct_int($order['positionId'] ?? 0);
        if ($positionId <= 0 || !empty($order['closingOrder'])) {
            continue;
        }
        $type = $order['orderType'] ?? 1;
        $direction = ct_side($order['tradeData']['tradeSide'] ?? 1);
        $price = isset($order['executionPrice']) ? (float)$order['executionPrice'] : 0.0;
        if (in_array($type, [4, '4', 'STOP_LOSS_TAKE_PROFIT'], true)) {
            // Samostatný příkaz SL/TP patří k pozici; jeho směr je opačný než směr pozice.
            $stop = isset($order['stopPrice']) ? (float)$order['stopPrice'] : null;
            broker_remember_position($brokerId, (string)$positionId, $stop, null, null, true);
            continue;
        }
        $stop = isset($order['stopLoss']) ? (float)$order['stopLoss'] : null;
        $target = isset($order['takeProfit']) ? (float)$order['takeProfit'] : null;
        if ($stop === null && isset($order['relativeStopLoss']) && $price > 0) {
            $distance = ct_int($order['relativeStopLoss']) / 100000;
            $stop = $direction === 'long' ? $price - $distance : $price + $distance;
        }
        if ($target === null && isset($order['relativeTakeProfit']) && $price > 0) {
            $distance = ct_int($order['relativeTakeProfit']) / 100000;
            $target = $direction === 'long' ? $price + $distance : $price - $distance;
        }
        if ($price > 0) {
            $stop = broker_valid_stop($direction, $price, $stop);
        }
        broker_remember_position($brokerId, (string)$positionId, $stop, $target, null);
    }
}

function broker_fetch_cash(CtraderApi $api, bool $live, int $ctid, int $brokerId, int $from, int $to): void
{
    $result = $api->history($live, CT_CASH_FLOW_REQ, ['ctidTraderAccountId' => $ctid, 'fromTimestamp' => $from, 'toTimestamp' => $to], CT_CASH_FLOW_RES);
    $statement = db()->prepare('INSERT INTO broker_cash (broker_account_id, operation_id, executed_ms, delta, balance, balance_version) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(broker_account_id, operation_id) DO NOTHING');
    foreach ((array)($result['depositWithdraw'] ?? []) as $operation) {
        if (!is_array($operation) || ct_int($operation['balanceHistoryId'] ?? 0) <= 0) {
            continue;
        }
        $statement->execute([
            $brokerId,
            (string)ct_int($operation['balanceHistoryId']),
            ct_int($operation['changeBalanceTimestamp'] ?? 0),
            round(ct_cash_delta($operation), 2),
            isset($operation['balance']) ? ct_money($operation['balance'], $operation['moneyDigits'] ?? null) : null,
            isset($operation['balanceVersion']) ? ct_int($operation['balanceVersion']) : null,
        ]);
    }
}

/** Stop loss má smysl jen na ztrátové straně vstupu; posunutý do zisku se pro risk nepoužije. */
function broker_valid_stop(string $direction, float $entry, mixed $stop): ?float
{
    if (!is_numeric($stop) || (float)$stop <= 0 || $entry <= 0) {
        return null;
    }
    $stop = (float)$stop;
    return ($direction === 'long' && $stop < $entry) || ($direction === 'short' && $stop > $entry) ? $stop : null;
}

/** První známý stop loss pozice se nepřepisuje: R se počítá z původního risku. */
function broker_remember_position(int $brokerId, string $positionId, ?float $stop, ?float $target, ?bool $open, bool $fallback = false): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO broker_positions (broker_account_id, position_id) VALUES (?, ?) ON CONFLICT(broker_account_id, position_id) DO NOTHING')->execute([$brokerId, $positionId]);
    if ($stop !== null) {
        // Samostatný příkaz SL/TP je jen náhrada, když pozice vlastní stop z příkazu nemá.
        $pdo->prepare('UPDATE broker_positions SET stop_loss = ? WHERE broker_account_id = ? AND position_id = ? AND stop_loss IS NULL')->execute([$stop, $brokerId, $positionId]);
    }
    if ($target !== null && !$fallback) {
        $pdo->prepare('UPDATE broker_positions SET take_profit = COALESCE(take_profit, ?) WHERE broker_account_id = ? AND position_id = ?')->execute([$target, $brokerId, $positionId]);
    }
    if ($open !== null) {
        $pdo->prepare('UPDATE broker_positions SET is_open = ? WHERE broker_account_id = ? AND position_id = ?')->execute([$open ? 1 : 0, $brokerId, $positionId]);
    }
}

/** Názvy symbolů a velikost lotu. */
function broker_symbols(CtraderApi $api, bool $live, int $ctid, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $symbols = [];
    $list = $api->call($live, CT_SYMBOLS_LIST_REQ, ['ctidTraderAccountId' => $ctid, 'includeArchivedSymbols' => true], CT_SYMBOLS_LIST_RES);
    foreach ([...(array)($list['symbol'] ?? []), ...(array)($list['archivedSymbol'] ?? [])] as $symbol) {
        if (is_array($symbol) && in_array(ct_int($symbol['symbolId'] ?? 0), $ids, true)) {
            $name = strtoupper(trim((string)($symbol['symbolName'] ?? $symbol['name'] ?? '')));
            $symbols[ct_int($symbol['symbolId'])] = ['name' => mb_substr(str_replace(['/', ' '], '', $name), 0, 30, 'UTF-8')];
        }
    }
    $details = $api->call($live, CT_SYMBOL_BY_ID_REQ, ['ctidTraderAccountId' => $ctid, 'symbolId' => $ids], CT_SYMBOL_BY_ID_RES);
    foreach ((array)($details['symbol'] ?? []) as $symbol) {
        if (!is_array($symbol)) {
            continue;
        }
        $id = ct_int($symbol['symbolId'] ?? 0);
        $symbols[$id] = ($symbols[$id] ?? ['name' => '#' . $id]) + [
            'lot' => ct_int($symbol['lotSize'] ?? 0) > 0 ? ct_int($symbol['lotSize']) : null,
            'digits' => isset($symbol['digits']) ? ct_int($symbol['digits']) : null,
        ];
        if ($symbols[$id]['lot'] === null) {
            unset($symbols[$id]['lot']);
        }
    }
    return $symbols;
}

/** Objem v centech jednotek → loty (když je velikost lotu známá), jinak kusy. */
function broker_volume(int $cents, ?array $symbol): float
{
    $lot = (int)($symbol['lot'] ?? 0);
    return round($lot > 0 ? $cents / $lot : $cents / 100, 4);
}

/** Pozice s uzavírajícím obchodem od začátku importu, které ještě nejsou v deníku. */
function broker_import_candidates(int $brokerId, int $importMs): array
{
    $rows = fetch_all(
        'SELECT d.position_id FROM broker_deals d LEFT JOIN broker_positions p ON p.broker_account_id = d.broker_account_id AND p.position_id = d.position_id
         WHERE d.broker_account_id = ? AND d.closing = 1 AND COALESCE(p.is_open, 0) = 0 AND p.imported_at IS NULL
         GROUP BY d.position_id HAVING MAX(d.executed_ms) >= CAST(? AS INTEGER) ORDER BY MAX(d.executed_ms)',
        [$brokerId, $importMs]
    );
    return array_map(static fn(array $row): string => (string)$row['position_id'], $rows);
}

/** Zůstatek po každé změně (verze zůstatku) z uzavřených obchodů a pohybů na účtu. */
function broker_balance_versions(int $brokerId): array
{
    $versions = [];
    foreach (fetch_all('SELECT data FROM broker_deals WHERE broker_account_id = ? AND closing = 1', [$brokerId]) as $row) {
        $detail = (array)(json_decode((string)$row['data'], true)['closePositionDetail'] ?? []);
        if (isset($detail['balanceVersion'], $detail['balance'])) {
            $versions[ct_int($detail['balanceVersion'])] = ct_money($detail['balance'], $detail['moneyDigits'] ?? null);
        }
    }
    foreach (fetch_all('SELECT balance, balance_version FROM broker_cash WHERE broker_account_id = ? AND balance_version IS NOT NULL AND balance IS NOT NULL', [$brokerId]) as $row) {
        $versions[(int)$row['balance_version']] = (float)$row['balance'];
    }
    return $versions;
}

/**
 * Čistý výsledek uzavírajícího obchodu. Když je známý zůstatek těsně před ním (předchozí
 * verze zůstatku), je to přesně rozdíl zůstatků. Jinak hrubý zisk + swap + komise
 * + poplatek za převod měny.
 */
function broker_deal_net(array $deal, array $versions): float
{
    $detail = (array)($deal['closePositionDetail'] ?? []);
    $digits = $detail['moneyDigits'] ?? $deal['moneyDigits'] ?? null;
    $version = ct_int($detail['balanceVersion'] ?? 0);
    if ($version > 0 && isset($versions[$version], $versions[$version - 1])) {
        return round($versions[$version] - $versions[$version - 1], 2);
    }
    return round(
        ct_money($detail['grossProfit'] ?? 0, $digits) + ct_money($detail['swap'] ?? 0, $digits)
        + ct_money($detail['commission'] ?? 0, $digits) + ct_money($detail['pnlConversionFee'] ?? 0, $digits),
        2
    );
}

/** Složí z obchodů jedné pozice řádek deníku. */
function broker_position_trade(array $deals, array $versions, array $symbols, ?array $memory): ?array
{
    $opening = array_values(array_filter($deals, static fn(array $deal): bool => !is_array($deal['closePositionDetail'] ?? null)));
    $closing = array_values(array_filter($deals, static fn(array $deal): bool => is_array($deal['closePositionDetail'] ?? null)));
    if ($closing === []) {
        return null;
    }
    $byTime = static fn(array $a, array $b): int => ct_int($a['executionTimestamp'] ?? 0) <=> ct_int($b['executionTimestamp'] ?? 0);
    usort($opening, $byTime);
    usort($closing, $byTime);
    $direction = $opening !== []
        ? ct_side($opening[0]['tradeSide'] ?? 1)
        : (ct_side($closing[0]['tradeSide'] ?? 1) === 'long' ? 'short' : 'long');

    $volume = 0;
    $weighted = 0.0;
    $gross = 0.0;
    $net = 0.0;
    $units = 0.0;
    $rate = null;
    foreach ($closing as $deal) {
        $detail = $deal['closePositionDetail'];
        $closed = ct_int($detail['closedVolume'] ?? $deal['filledVolume'] ?? $deal['volume'] ?? 0);
        $volume += $closed;
        $weighted += (float)($deal['executionPrice'] ?? 0) * $closed;
        $gross += ct_money($detail['grossProfit'] ?? 0, $detail['moneyDigits'] ?? $deal['moneyDigits'] ?? null);
        $net += broker_deal_net($deal, $versions);
        $units += $closed / 100;
        if (isset($detail['quoteToDepositConversionRate']) && (float)$detail['quoteToDepositConversionRate'] > 0) {
            $rate = (float)$detail['quoteToDepositConversionRate'];
        }
    }
    $last = end($closing);
    $symbolId = ct_int($last['symbolId'] ?? 0);
    $symbol = $symbols[$symbolId] ?? null;
    $digits = isset($symbol['digits']) ? (int)$symbol['digits'] : 6;
    $entry = round((float)($last['closePositionDetail']['entryPrice'] ?? 0), $digits);
    $exit = $volume > 0 ? round($weighted / $volume, $digits) : (float)($last['executionPrice'] ?? 0);

    // Kolik stojí pohyb ceny o 1 v měně účtu: z realizovaného zisku, u nulového pohybu z kurzu.
    $perPoint = abs($exit - $entry) > 1e-12 ? abs($gross) / abs($exit - $entry) : ($rate !== null ? $units * $rate : 0.0);
    $stop = broker_valid_stop($direction, $entry, $memory['stop_loss'] ?? null);
    $risk = $stop !== null && $perPoint > 0 ? round(abs($entry - $stop) * $perPoint, 2) : null;
    $openMs = $opening !== [] ? ct_int($opening[0]['executionTimestamp'] ?? 0) : null;
    $closeMs = ct_int($last['executionTimestamp'] ?? 0);

    return [
        'trade_date' => broker_trade_day($openMs ?? $closeMs),
        'market' => $symbol['name'] ?? ('#' . $symbolId),
        'direction' => $direction,
        'entry_price' => $entry,
        'exit_price' => $exit,
        'quantity' => broker_volume($volume, $symbol),
        'stop_loss' => $risk !== null ? $stop : null,
        'target_price' => isset($memory['take_profit']) ? (float)$memory['take_profit'] : null,
        'risk_amount' => $risk !== null && $risk > 0 ? $risk : null,
        'fees' => round($gross - $net, 2),
        'result_usd' => round($net, 2),
        'entry_ts' => $openMs !== null ? intdiv($openMs, 1000) : null,
        'exit_ts' => intdiv($closeMs, 1000),
    ];
}

function broker_import_positions(int $brokerId, array $row, int $accountId, array $candidates, array $symbols, array $open): int
{
    if ($candidates === []) {
        return 0;
    }
    $versions = broker_balance_versions($brokerId);
    $imported = 0;
    foreach ($candidates as $positionId) {
        if (isset($open[$positionId])) {
            continue;
        }
        $reference = 'ctrader:' . $row['external_id'] . ':' . $positionId;
        $memory = fetch_one('SELECT * FROM broker_positions WHERE broker_account_id = ? AND position_id = ?', [$brokerId, $positionId]);
        $existing = fetch_one('SELECT id FROM trades WHERE external_ref = ?', [$reference]);
        if ($existing === null) {
            $deals = array_map(static fn(array $deal): array => json_decode((string)$deal['data'], true) ?: [], fetch_all('SELECT data FROM broker_deals WHERE broker_account_id = ? AND position_id = ? ORDER BY executed_ms', [$brokerId, $positionId]));
            $trade = broker_position_trade($deals, $versions, $symbols, $memory);
            if ($trade === null) {
                continue;
            }
            $saved = save_trade([
                'trade_date' => $trade['trade_date'],
                'market' => $trade['market'],
                'direction' => $trade['direction'],
                'account_id' => $accountId,
                'entry_price' => $trade['entry_price'],
                'exit_price' => $trade['exit_price'],
                'quantity' => $trade['quantity'],
                'stop_loss' => $trade['stop_loss'],
                'target_price' => $trade['target_price'],
                'risk_amount' => $trade['risk_amount'],
                'fees' => $trade['fees'],
                'result_usd' => $trade['result_usd'],
                // Bez čísla účtu: poznámka se může sdílet na nástěnku.
                'notes' => 'Z cTraderu, pozice ' . $positionId,
            ]);
            db()->prepare('UPDATE trades SET entry_ts = ?, exit_ts = ?, external_ref = ? WHERE id = ?')
                ->execute([$trade['entry_ts'], $trade['exit_ts'], $reference, (int)$saved['id']]);
            $tradeId = (int)$saved['id'];
            $imported++;
        } else {
            $tradeId = (int)$existing['id'];
        }
        db()->prepare('INSERT INTO broker_positions (broker_account_id, position_id, trade_id, imported_at) VALUES (?, ?, ?, ?) ON CONFLICT(broker_account_id, position_id) DO UPDATE SET trade_id = excluded.trade_id, imported_at = excluded.imported_at')
            ->execute([$brokerId, $positionId, $tradeId, utc_now()]);
    }
    return $imported;
}

/**
 * Nový účet v deníku pro účet cTraderu. Vstupní stav je zůstatek na začátku importu:
 * dnešní zůstatek bez výsledků a pohybů, které přišly potom.
 */
function broker_create_journal_account(array $row, int $brokerId, float $balance, string $currency, int $importMs): int
{
    $versions = broker_balance_versions($brokerId);
    $later = 0.0;
    foreach (fetch_all('SELECT data FROM broker_deals WHERE broker_account_id = ? AND closing = 1 AND executed_ms >= ?', [$brokerId, $importMs]) as $deal) {
        $later += broker_deal_net(json_decode((string)$deal['data'], true) ?: [], $versions);
    }
    $cash = fetch_one('SELECT COALESCE(SUM(delta), 0) AS total FROM broker_cash WHERE broker_account_id = ? AND executed_ms >= ?', [$brokerId, $importMs]);
    $later += (float)($cash['total'] ?? 0);
    $starting = max(0.0, round($balance - $later, 2));

    $base = trim(($row['broker_name'] ?: 'cTrader') . ' ' . ($row['login'] ?: $row['external_id'])) . ((bool)$row['is_live'] ? '' : ' demo');
    $name = $base;
    for ($suffix = 2; fetch_one('SELECT id FROM accounts WHERE name = ? COLLATE NOCASE', [$name]) !== null; $suffix++) {
        $name = $base . ' (' . $suffix . ')';
    }
    $pdo = db();
    $pdo->prepare('INSERT INTO accounts (name, broker, currency, starting_balance, daily_risk, opened_at, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?)')
        ->execute([$name, $row['broker_name'] ?: 'cTrader', $currency, $starting, gmdate('Y-m-d', intdiv($importMs, 1000)), utc_now()]);
    $accountId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE broker_accounts SET account_id = ? WHERE id = ?')->execute([$accountId, $brokerId]);
    return $accountId;
}

/** Money audit podle zůstatku z cTraderu, když je na řadě (nebo na požádání). */
function broker_auto_audit(int $accountId, float $balance, array $row, bool $force): ?array
{
    $account = fetch_one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if ($account === null) {
        return null;
    }
    if (!$force && !account_overview($account)['audit_due']) {
        return null;
    }
    $audit = save_audit([
        'account_id' => $accountId,
        'reported_balance' => $balance,
        'audit_date' => gmdate('Y-m-d'),
        'notes' => sprintf('Automaticky z cTraderu (účet %s), bez screenshotu.', $row['login'] ?: $row['external_id']),
    ]);
    db()->prepare("UPDATE account_audits SET source = 'ctrader' WHERE id = ?")->execute([(int)$audit['id']]);
    $audit['source'] = 'ctrader';
    return $audit;
}

/** Audit hned teď: nejdřív čerstvá synchronizace, pak zůstatek z cTraderu. */
function broker_audit_now(int $id): array
{
    $row = fetch_one('SELECT * FROM broker_accounts WHERE id = ? AND account_id IS NOT NULL', [$id]);
    if ($row === null) {
        json_response(['error' => 'Účet cTraderu nejdřív propoj s účtem v deníku.'], 409);
    }
    if (!ctrader_configured()) {
        json_response(['error' => 'Napojení na cTrader zatím není nastavené ve Správě.'], 409);
    }
    @set_time_limit(300);
    try {
        $result = broker_sync_account(new CtraderApi(), $row, false);
    } catch (CtraderError $error) {
        db()->prepare('UPDATE broker_accounts SET last_error = ? WHERE id = ?')->execute([$error->getMessage(), $id]);
        json_response(['error' => $error->getMessage(), 'state' => broker_state()], 502);
    }
    $fresh = fetch_one('SELECT * FROM broker_accounts WHERE id = ?', [$id]) ?? $row;
    $audit = $result['audit'] ? fetch_one("SELECT * FROM account_audits WHERE account_id = ? AND source = 'ctrader' ORDER BY id DESC LIMIT 1", [(int)$fresh['account_id']]) : broker_auto_audit((int)$fresh['account_id'], (float)$result['balance'], $fresh, true);
    return ['audit' => $audit, 'imported' => $result['imported'], 'state' => broker_state()];
}
