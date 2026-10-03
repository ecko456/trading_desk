<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Fatální chyba PHP (došla paměť, vypršel čas) by jinak vrátila prázdnou stránku a aplikace
// by nevěděla, co se stalo. Chyba se dál zapisuje do logu serveru.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) || headers_sent()) {
        return;
    }
    $message = (string)$error['message'];
    $text = str_contains($message, 'Allowed memory size')
        ? 'Serveru při zpracování došla paměť. Zkus menší soubor nebo kratší období.'
        : (str_contains($message, 'Maximum execution time')
            ? 'Server požadavek nestihl dokončit v časovém limitu. Zkus menší soubor nebo kratší období.'
            : 'Na serveru nastala chyba, požadavek se nedokončil. Podrobnosti jsou v logu serveru.');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $text], JSON_UNESCAPED_UNICODE);
});

$action = (string)($_GET['action'] ?? 'health');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    require_same_origin();
}
// Když je požadavek větší než post_max_size, PHP zahodí celé tělo i soubory a bez
// téhle kontroly by přišla matoucí hláška, že soubor chybí.
if ($method === 'POST' && $_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
    json_response(['error' => 'Soubory jsou dohromady větší, než server dovolí (' . ini_get('post_max_size') . '). Nahraj je po menších částech.'], 413);
}

try {
    if ($action === 'health' && $method === 'GET') {
        $integrity = app_db()->query('PRAGMA quick_check')->fetchColumn();
        json_response([
            'ok' => true,
            'app' => 'Trading Desk',
            'version' => APP_VERSION,
            'base' => APP_BASE,
            'database' => (string)$integrity,
            'encryption' => vault_available(),
            'time' => utc_now(),
        ]);
    }

    /* ---------- bez přihlášení */

    if ($action === 'auth_state' && $method === 'GET') {
        json_response(auth_state());
    }

    if ($action === 'login' && $method === 'POST') {
        $data = request_json();
        json_response(['user' => perform_login((string)($data['login'] ?? ''), (string)($data['secret'] ?? ''))]);
    }

    if ($action === 'register' && $method === 'POST') {
        json_response(perform_registration(request_json()), 201);
    }

    if ($action === 'setup' && $method === 'POST') {
        json_response(perform_setup(request_json()), 201);
    }

    if ($action === 'logout' && $method === 'POST') {
        end_current_session();
        json_response(['ok' => true]);
    }

    $user = require_user();

    /* ---------- profil */

    if ($action === 'me' && $method === 'GET') {
        $pending = is_admin($user) ? (int)(app_fetch_one("SELECT COUNT(*) AS total FROM users WHERE status = 'pending'")['total'] ?? 0) : 0;
        json_response(['user' => public_user($user), 'wall_unseen' => wall_unseen_count($user), 'pending_users' => $pending]);
    }

    if ($action === 'profile' && $method === 'POST') {
        json_response(['user' => update_profile($user, request_json())]);
    }

    if ($action === 'password' && $method === 'POST') {
        $data = request_json();
        json_response(['user' => change_password($user, (string)($data['current'] ?? ''), (string)($data['next'] ?? ''))]);
    }

    if ($action === 'encryption' && $method === 'POST') {
        $accessKey = enable_encryption($user, (string)(request_json()['current'] ?? ''));
        json_response(['access_key' => $accessKey, 'user' => public_user(current_user())]);
    }

    if ($action === 'access_key' && $method === 'POST') {
        json_response(['access_key' => rotate_access_key($user, (string)(request_json()['current'] ?? ''))]);
    }

    if ($action === 'sessions' && $method === 'DELETE') {
        revoke_sessions((int)$user['id'], current_session_id());
        json_response(['ok' => true]);
    }

    /* ---------- nástěnka */

    if ($action === 'wall' && $method === 'GET') {
        json_response(wall_feed($user, $_GET));
    }

    if ($action === 'wall_post' && $method === 'GET') {
        json_response(wall_post($user, (int)($_GET['id'] ?? 0), true));
    }

    if ($action === 'wall_post' && $method === 'POST') {
        json_response(wall_create_note($user, (string)($_POST['body'] ?? ''), uploaded_images('images', 6)), 201);
    }

    if ($action === 'wall_post' && $method === 'DELETE') {
        wall_delete_post($user, (int)($_GET['id'] ?? 0));
        json_response(['ok' => true]);
    }

    if ($action === 'share' && $method === 'POST') {
        $data = request_json();
        json_response(wall_share($user, (string)($data['kind'] ?? ''), (int)($data['id'] ?? 0), (array)($data['options'] ?? [])), 201);
    }

    if ($action === 'share' && $method === 'DELETE') {
        wall_unshare($user, (string)($_GET['kind'] ?? ''), (int)($_GET['id'] ?? 0));
        json_response(['ok' => true]);
    }

    if ($action === 'shares' && $method === 'GET') {
        json_response(['items' => wall_my_shares($user)]);
    }

    if ($action === 'comment' && $method === 'POST') {
        $data = request_json();
        json_response(wall_add_comment($user, (int)($data['post_id'] ?? 0), (string)($data['body'] ?? '')), 201);
    }

    if ($action === 'comment' && $method === 'DELETE') {
        json_response(wall_delete_comment($user, (int)($_GET['id'] ?? 0)));
    }

    if ($action === 'react' && $method === 'POST') {
        $data = request_json();
        $kind = isset($data['kind']) && $data['kind'] !== null ? (string)$data['kind'] : null;
        json_response(wall_react($user, (int)($data['post_id'] ?? 0), $kind));
    }

    if ($action === 'members' && $method === 'GET') {
        json_response(['items' => wall_members()]);
    }

    /* ---------- správa */

    if ($action === 'admin_users' && $method === 'GET') {
        $admin = require_admin();
        json_response(['items' => admin_users(), 'registration_open' => registration_open(), 'market_keeper' => market_data_keeper_card($admin)]);
    }

    if ($action === 'admin_user' && $method === 'POST') {
        $admin = require_admin();
        $data = request_json();
        json_response(admin_user_action($admin, (int)($data['id'] ?? 0), (string)($data['op'] ?? '')));
    }

    if ($action === 'admin_settings' && $method === 'POST') {
        require_admin();
        $data = request_json();
        save_setting('registration_open', !empty($data['registration_open']) ? '1' : '0');
        json_response(['registration_open' => registration_open()]);
    }

    if ($action === 'admin_ctrader' && $method === 'GET') {
        require_admin();
        json_response(ctrader_admin_state());
    }

    if ($action === 'admin_ctrader' && $method === 'POST') {
        require_admin();
        json_response(ctrader_save_settings(request_json()));
    }

    /* ---------- přizpůsobené prostředí */

    if ($action === 'workspace' && $method === 'GET') {
        json_response(['prefs' => workspace(), 'fields' => custom_fields(), 'registry' => workspace_registry()]);
    }

    if ($action === 'workspace' && $method === 'POST') {
        json_response(['prefs' => save_workspace(request_json())]);
    }

    if ($action === 'custom_field' && $method === 'POST') {
        json_response(['field' => save_custom_field(request_json()), 'fields' => custom_fields()]);
    }

    if ($action === 'custom_field_move' && $method === 'POST') {
        $data = request_json();
        move_custom_field((int)($data['id'] ?? 0), (int)($data['delta'] ?? 0));
        json_response(['fields' => custom_fields()]);
    }

    if ($action === 'custom_field' && $method === 'DELETE') {
        delete_custom_field((int)($_GET['id'] ?? 0));
        json_response(['fields' => custom_fields()]);
    }

    if ($action === 'custom_field_stats' && $method === 'GET') {
        json_response(['items' => custom_field_statistics()]);
    }

    /* ---------- deník přihlášeného uživatele */

    if ($action === 'plans' && $method === 'GET') {
        $market = strtoupper(trim((string)($_GET['market'] ?? '')));
        $limit = min(250, max(1, (int)($_GET['limit'] ?? 100)));
        $where = $market !== '' ? 'WHERE p.market = ?' : '';
        $params = $market !== '' ? [$market] : [];
        $sql = "SELECT p.*, (SELECT COUNT(*) FROM screenshots s WHERE s.plan_id = p.id) AS screenshot_count, (SELECT COUNT(*) FROM trades t WHERE t.plan_id = p.id) AS trade_count, (SELECT COUNT(*) FROM zones z WHERE z.plan_id = p.id) AS zone_count, (SELECT COUNT(*) FROM plan_refs r WHERE r.plan_id = p.id AND r.status = 'open') AS open_ref_count, COALESCE((SELECT SUM(t.result_r) FROM trades t WHERE t.plan_id = p.id), 0) AS total_r FROM plans p $where ORDER BY p.plan_date DESC, p.id DESC LIMIT $limit";
        json_response(['items' => fetch_all($sql, $params)]);
    }

    if ($action === 'plan' && $method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $plan = $id > 0 ? plan_payload($id) : null;
        $plan === null ? json_response(['error' => 'Náhled nebyl nalezen.'], 404) : json_response($plan);
    }

    if ($action === 'weekly_context' && $method === 'GET') {
        $date = trim((string)($_GET['date'] ?? ''));
        $market = trim((string)($_GET['market'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $market === '') {
            json_response(['error' => 'Chybí datum nebo trh.'], 400);
        }
        json_response(weekly_context(['plan_type' => 'daily', 'plan_date' => $date, 'market' => $market, 'session' => (string)($_GET['session'] ?? '')]));
    }

    if ($action === 'plan' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(save_plan(request_json()));
    }

    if ($action === 'plan' && $method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $screenshots = fetch_all('SELECT * FROM screenshots WHERE plan_id = ?', [$id]);
        hs_lock_delete($id);
        $statement = db()->prepare('DELETE FROM plans WHERE id = ?');
        $statement->execute([$id]);
        foreach ($screenshots as $screenshot) {
            delete_screenshot_file($screenshot);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'trades' && $method === 'GET') {
        $market = strtoupper(trim((string)($_GET['market'] ?? '')));
        $limit = min(1000, max(1, (int)($_GET['limit'] ?? 250)));
        $where = $market !== '' ? 'WHERE t.market = ?' : '';
        $params = $market !== '' ? [$market] : [];
        $sql = "SELECT t.*, COUNT(s.id) AS screenshot_count FROM trades t LEFT JOIN screenshots s ON s.trade_id = t.id $where GROUP BY t.id ORDER BY t.trade_date DESC, t.id DESC LIMIT $limit";
        json_response(['items' => fetch_all($sql, $params)]);
    }

    if ($action === 'trade' && $method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $trade = fetch_one('SELECT * FROM trades WHERE id = ?', [$id]);
        if ($trade === null) {
            json_response(['error' => 'Obchod nebyl nalezen.'], 404);
        }
        $trade = hs_trade_times($trade);
        $trade['screenshots'] = fetch_all('SELECT id, plan_id, trade_id, role, original_name, mime_type, size_bytes, caption, created_at FROM screenshots WHERE trade_id = ? ORDER BY created_at, id', [$id]);
        json_response($trade);
    }

    if ($action === 'trade' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(save_trade(request_json()));
    }

    if ($action === 'trade' && $method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $screenshots = fetch_all('SELECT * FROM screenshots WHERE trade_id = ?', [$id]);
        db()->prepare('DELETE FROM trades WHERE id = ?')->execute([$id]);
        db()->prepare('UPDATE ideas SET trade_id = NULL WHERE trade_id = ?')->execute([$id]);
        foreach ($screenshots as $screenshot) {
            delete_screenshot_file($screenshot);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'strategies' && $method === 'GET') {
        // cover_id = první obrázek strategie pro náhled ve sloupci Strategií (rowid drží pořadí nahrání i v rámci vteřiny).
        $sql = 'SELECT s.*, (SELECT COUNT(*) FROM screenshots sc WHERE sc.strategy_id = s.id) AS screenshot_count, (SELECT sc.id FROM screenshots sc WHERE sc.strategy_id = s.id ORDER BY sc.created_at, sc.rowid LIMIT 1) AS cover_id, (SELECT COUNT(*) FROM trades t WHERE t.strategy_id = s.id) AS trade_count FROM strategies s ORDER BY s.name COLLATE NOCASE';
        json_response(['items' => fetch_all($sql)]);
    }

    if ($action === 'strategy' && $method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $strategy = $id > 0 ? strategy_payload($id) : null;
        $strategy === null ? json_response(['error' => 'Strategie nebyla nalezena.'], 404) : json_response($strategy);
    }

    if ($action === 'strategy' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(save_strategy(request_json()));
    }

    if ($action === 'strategy' && $method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $screenshots = fetch_all('SELECT * FROM screenshots WHERE strategy_id = ?', [$id]);
        db()->prepare('DELETE FROM strategies WHERE id = ?')->execute([$id]);
        foreach ($screenshots as $screenshot) {
            delete_screenshot_file($screenshot);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'strategy_stats' && $method === 'GET') {
        json_response(['items' => strategy_statistics(), 'chart' => strategy_series()]);
    }

    /* ---------- Hindsight: svíčky ES a náhledy v jednom grafu */

    if ($action === 'hindsight_range' && $method === 'GET') {
        json_response(hs_range() + ['prefs' => hs_prefs(), 'admin' => is_admin($user), 'keeper' => market_data_keeper_card($user)]);
    }

    if ($action === 'hindsight_bars' && $method === 'GET') {
        [$from, $to] = hs_request_range();
        json_response(hs_bars(hs_trade_day_start($from), hs_trade_day_start($to) + 86400 - 1) + ['from' => $from, 'to' => $to]);
    }

    if ($action === 'hindsight_annotations' && $method === 'GET') {
        [$from, $to] = hs_request_range();
        json_response(hs_annotations($from, $to));
    }

    if ($action === 'hindsight_zone' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(hs_save_zone(request_json()));
    }

    if ($action === 'hindsight_zone' && $method === 'DELETE') {
        hs_delete_zone((int)($_GET['id'] ?? 0));
        json_response(['ok' => true]);
    }

    if ($action === 'hindsight_idea' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(hs_save_idea(request_json()));
    }

    if ($action === 'hindsight_idea' && $method === 'DELETE') {
        hs_delete_idea((int)($_GET['id'] ?? 0));
        json_response(['ok' => true]);
    }

    if ($action === 'hindsight_trade_times' && $method === 'POST') {
        json_response(hs_save_trade_times(request_json()));
    }

    if ($action === 'hindsight_versions' && $method === 'GET') {
        json_response(['date' => hs_date($_GET['date'] ?? ''), 'versions' => hs_versions(hs_date($_GET['date'] ?? ''))]);
    }

    if ($action === 'hindsight_bias' && $method === 'POST') {
        json_response(hs_save_bias(request_json()));
    }

    if ($action === 'hindsight_prefs' && $method === 'POST') {
        json_response(['prefs' => hs_save_prefs(request_json())]);
    }

    // Svíčky jsou společné pro všechny, nahrává a maže je jen správce dat grafu
    // (jeden člověk, ne každý správce; viz market_data_keeper_id).
    if ($action === 'hindsight_import' && $method === 'POST') {
        require_market_data_keeper();
        $file = $_FILES['file'] ?? null;
        $uploadError = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            json_response(['error' => 'Soubor je větší, než server dovolí (' . ini_get('upload_max_filesize') . '). Vyexportuj kratší období, nebo 5m svíčky místo 1m.'], 413);
        }
        if ($uploadError === UPLOAD_ERR_PARTIAL) {
            json_response(['error' => 'Soubor se nahrál jen zčásti. Zkus to znovu.'], 422);
        }
        if (!is_array($file) || $uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            json_response(['error' => 'Vyber soubor CSV se svíčkami.'], 422);
        }
        if ((int)$file['size'] > HS_MAX_IMPORT_BYTES) {
            json_response(['error' => 'Soubor je větší než ' . (HS_MAX_IMPORT_BYTES / 1024 / 1024) . ' MB. Vyexportuj kratší období, nebo 5m svíčky místo 1m.'], 413);
        }
        $dateOrder = (string)($_POST['date_order'] ?? 'auto');
        json_response(hs_import_bars((string)$file['tmp_name'], (string)($_POST['contract'] ?? ''), (string)($_POST['tz'] ?? 'Europe/Prague'), 'CSV ' . mb_substr((string)($file['name'] ?? ''), 0, 60), in_array($dateOrder, ['ydm', 'ymd', 'dmy', 'mdy'], true) ? $dateOrder : 'auto'), 201);
    }

    if ($action === 'hindsight_demo' && $method === 'POST') {
        require_market_data_keeper();
        json_response(hs_generate_demo(), 201);
    }

    if ($action === 'hindsight_contract' && $method === 'DELETE') {
        require_market_data_keeper();
        json_response(['removed' => hs_delete_contract((string)($_GET['contract'] ?? ''))]);
    }

    if ($action === 'calendar' && $method === 'GET') {
        json_response(calendar_month((string)($_GET['month'] ?? '')));
    }

    if ($action === 'calendar_event' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(save_calendar_event(request_json()), 201);
    }

    if ($action === 'calendar_event' && $method === 'DELETE') {
        db()->prepare('DELETE FROM calendar_events WHERE id = ?')->execute([(int)($_GET['id'] ?? 0)]);
        json_response(['ok' => true]);
    }

    if ($action === 'discipline' && $method === 'GET') {
        json_response(discipline_overview());
    }

    if ($action === 'discipline_day' && $method === 'GET') {
        $date = trim((string)($_GET['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(['error' => 'Chybí platné datum.'], 400);
        }
        json_response(discipline_analysis($date));
    }

    if ($action === 'psych_questions' && $method === 'GET') {
        $profile = psych_profile_payload();
        json_response(['items' => psych_questions($profile['dimensions'] ?? null, personality_focus_areas()), 'has_profile' => $profile !== null]);
    }

    if ($action === 'personality' && $method === 'GET') {
        json_response(['personality' => personality_payload(), 'catalog' => personality_catalog()]);
    }

    if ($action === 'personality' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(['personality' => save_personality(request_json())]);
    }

    if ($action === 'psych_profile' && $method === 'GET') {
        json_response([
            'profile' => psych_profile_payload(),
            'questions' => psych_profile_questions(),
            'dimensions' => psych_dimensions(),
            'default_rules' => default_psych_rules(),
        ]);
    }

    if ($action === 'psych_profile' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(save_psych_profile(request_json()), 201);
    }

    if ($action === 'psych_calibration' && $method === 'GET') {
        json_response(psych_calibration());
    }

    if ($action === 'psych_thresholds' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(['thresholds' => save_psych_thresholds((array)(request_json()['thresholds'] ?? []))]);
    }

    if ($action === 'psych_rules' && in_array($method, ['POST', 'PUT'], true)) {
        $existing = psych_profile_payload();
        if ($existing === null) {
            json_response(['error' => 'Nejdřív vyplň vstupní profil.'], 422);
        }
        json_response(save_psych_profile(['answers' => $existing['answers'], 'rules' => (array)(request_json()['rules'] ?? [])]));
    }

    if ($action === 'psych_checks' && $method === 'GET') {
        json_response(['items' => fetch_all('SELECT * FROM psych_checks ORDER BY check_date DESC, id DESC LIMIT 60')]);
    }

    if ($action === 'psych_check' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(save_psych_check(request_json()), 201);
    }

    if ($action === 'accounts' && $method === 'GET') {
        $items = array_map(static fn(array $account): array => account_overview($account), fetch_all('SELECT * FROM accounts ORDER BY name COLLATE NOCASE'));
        json_response(['items' => $items, 'interval_days' => AUDIT_INTERVAL_DAYS, 'warning_days' => AUDIT_WARNING_DAYS]);
    }

    if ($action === 'account' && $method === 'POST') {
        json_response(save_account(request_json()), 201);
    }

    if ($action === 'account' && $method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $usage = fetch_one('SELECT (SELECT COUNT(*) FROM trades WHERE account_id = ?) AS trades, (SELECT COUNT(*) FROM account_audits WHERE account_id = ?) AS audits', [$id, $id]) ?? [];
        if ((int)($usage['trades'] ?? 0) > 0 || (int)($usage['audits'] ?? 0) > 0) {
            json_response(['error' => 'Účet už má navázané obchody nebo audity, a proto ho nelze smazat.'], 409);
        }
        db()->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        json_response(['ok' => true]);
    }

    if ($action === 'audits' && $method === 'GET') {
        $accountId = nullable_int($_GET['account_id'] ?? null);
        $where = $accountId !== null ? 'WHERE a.account_id = ?' : '';
        $params = $accountId !== null ? [$accountId] : [];
        $sql = "SELECT a.*, ac.name AS account_name, ac.currency AS currency, (SELECT COUNT(*) FROM screenshots s WHERE s.audit_id = a.id) AS screenshot_count FROM account_audits a JOIN accounts ac ON ac.id = a.account_id $where ORDER BY a.audit_date DESC, a.id DESC LIMIT 120";
        json_response(['items' => fetch_all($sql, $params)]);
    }

    if ($action === 'audit' && $method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $audit = fetch_one('SELECT * FROM account_audits WHERE id = ?', [$id]);
        if ($audit === null) {
            json_response(['error' => 'Audit nebyl nalezen.'], 404);
        }
        $audit['screenshots'] = fetch_all('SELECT id, audit_id, role, original_name, mime_type, size_bytes, caption, created_at FROM screenshots WHERE audit_id = ? ORDER BY created_at, id', [$id]);
        json_response($audit);
    }

    if ($action === 'audit' && in_array($method, ['POST', 'PUT'], true)) {
        json_response(save_audit(request_json()), 201);
    }

    /* ---------- obchodní plán */

    if ($action === 'trading_plan' && $method === 'GET') {
        json_response(tradeplan_state());
    }

    if ($action === 'trading_plan' && $method === 'POST') {
        json_response(tradeplan_save(request_json()));
    }

    if ($action === 'trading_plan' && $method === 'DELETE') {
        json_response(tradeplan_delete((int)($_GET['id'] ?? 0)));
    }

    if ($action === 'trading_plan_version' && $method === 'POST') {
        json_response(tradeplan_new_version((int)(request_json()['id'] ?? 0)), 201);
    }

    if ($action === 'trading_plan_template' && $method === 'GET') {
        json_response(['data' => tradeplan_template()]);
    }

    /* ---------- napojení na cTrader */

    if ($action === 'broker_state' && $method === 'GET') {
        json_response(broker_state());
    }

    if ($action === 'broker_account' && $method === 'POST') {
        json_response(broker_link_account(request_json()));
    }

    if ($action === 'broker_account' && $method === 'DELETE') {
        broker_remove_account((int)($_GET['id'] ?? 0));
        json_response(broker_state());
    }

    if ($action === 'broker_sync' && $method === 'POST') {
        $data = request_json();
        json_response(broker_sync(isset($data['id']) ? (int)$data['id'] : null));
    }

    if ($action === 'broker_audit' && $method === 'POST') {
        json_response(broker_audit_now((int)(request_json()['id'] ?? 0)), 201);
    }

    if ($action === 'upload' && $method === 'POST') {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            json_response(['error' => 'Nebyl vybrán soubor.'], 422);
        }
        $upload = $_FILES['file'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_response(['error' => upload_error_message((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE))], 422);
        }
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
            json_response(['error' => 'Screenshot musí mít maximálně 20 MB.'], 422);
        }
        $temporary = (string)$upload['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($temporary);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        $planId = nullable_int($_POST['plan_id'] ?? null);
        $tradeId = nullable_int($_POST['trade_id'] ?? null);
        $strategyId = nullable_int($_POST['strategy_id'] ?? null);
        $auditId = nullable_int($_POST['audit_id'] ?? null);
        if (upload_is_svg($mime, (string)($upload['name'] ?? ''))) {
            $mime = 'image/svg+xml';
            // SVG (schémata setupů) jen ke strategii; uloží se vyčištěná kopie bez skriptů a odkazů ven.
            if ($strategyId === null || $planId !== null || $tradeId !== null || $auditId !== null) {
                json_response(['error' => 'SVG jde nahrát jen jako obrázek strategie. Ke grafům použij PNG, JPEG nebo WebP.'], 422);
            }
            try {
                $clean = sanitize_svg((string)file_get_contents($temporary));
            } catch (InvalidArgumentException $error) {
                json_response(['error' => $error->getMessage()], 422);
            }
            if (file_put_contents($temporary, $clean) === false) {
                json_response(['error' => 'SVG obrázek se nepodařilo zpracovat.'], 500);
            }
            $size = strlen($clean);
        } elseif (!isset($extensions[$mime])) {
            json_response(['error' => 'Povolené jsou PNG, JPEG a WebP obrázky, u strategií i SVG.'], 422);
        } elseif (@getimagesize($temporary) === false) {
            json_response(['error' => 'Soubor není platný obrázek.'], 422);
        }

        if ($planId === null && $tradeId === null && $strategyId === null && $auditId === null) {
            json_response(['error' => 'Screenshot musí patřit k náhledu, obchodu, strategii nebo auditu.'], 422);
        }
        foreach (['plans' => $planId, 'trades' => $tradeId, 'strategies' => $strategyId, 'account_audits' => $auditId] as $table => $ownerId) {
            if ($ownerId !== null && fetch_one("SELECT id FROM $table WHERE id = ?", [$ownerId]) === null) {
                json_response(['error' => 'Položka, ke které screenshot patří, už neexistuje. Obnov stránku.'], 422);
            }
        }
        $role = substr(preg_replace('/[^a-z_]/', '', strtolower((string)($_POST['role'] ?? ''))) ?? '', 0, 20) ?: 'plan';
        $caption = mb_substr(mb_scrub(trim((string)($_POST['caption'] ?? '')), 'UTF-8'), 0, 300, 'UTF-8');
        // Název souboru pochází z počítače uživatele; neplatné UTF-8 by rozbilo JSON odpovědi.
        $originalName = mb_substr(mb_scrub(basename((string)($upload['name'] ?? '')), 'UTF-8'), 0, 200, 'UTF-8') ?: 'screenshot';
        $id = bin2hex(random_bytes(16));
        $fileName = $id . '.' . $extensions[$mime];
        $destination = upload_dir() . DIRECTORY_SEPARATOR . $fileName;
        current_journal()->storeUpload($temporary, $fileName);

        try {
            $statement = db()->prepare('INSERT INTO screenshots (id, plan_id, trade_id, strategy_id, audit_id, role, file_name, original_name, mime_type, size_bytes, caption, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([
                $id, $planId, $tradeId, $strategyId, $auditId, $role, $fileName,
                $originalName, $mime, $size,
                $caption, utc_now()
            ]);
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        }
        json_response(['id' => $id, 'url' => 'file.php?id=' . rawurlencode($id)], 201);
    }

    if ($action === 'upload' && $method === 'DELETE') {
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
        $screenshot = fetch_one('SELECT * FROM screenshots WHERE id = ?', [$id]);
        if ($screenshot === null) {
            json_response(['error' => 'Screenshot nebyl nalezen.'], 404);
        }
        db()->prepare('DELETE FROM screenshots WHERE id = ?')->execute([$id]);
        delete_screenshot_file($screenshot);
        json_response(['ok' => true]);
    }

    if ($action === 'stats' && $method === 'GET') {
        $market = strtoupper(trim((string)($_GET['market'] ?? '')));
        $where = $market !== '' ? 'WHERE market = ?' : '';
        $params = $market !== '' ? [$market] : [];
        $summary = fetch_one("SELECT COUNT(*) AS trades, SUM(CASE WHEN result_r > 0 THEN 1 ELSE 0 END) AS wins, SUM(CASE WHEN result_r < 0 THEN 1 ELSE 0 END) AS losses, COALESCE(SUM(result_r), 0) AS total_r, COALESCE(AVG(result_r), 0) AS avg_r, COALESCE(SUM(result_usd), 0) AS total_usd, COALESCE(SUM(CASE WHEN result_r > 0 THEN result_r ELSE 0 END), 0) AS gross_win_r, ABS(COALESCE(SUM(CASE WHEN result_r < 0 THEN result_r ELSE 0 END), 0)) AS gross_loss_r, SUM(CASE WHEN followed_plan IS NOT NULL THEN 1 ELSE 0 END) AS rated_plan, SUM(CASE WHEN followed_plan = 1 THEN 1 ELSE 0 END) AS followed FROM trades $where", $params) ?? [];
        $trades = (int)($summary['trades'] ?? 0);
        $wins = (int)($summary['wins'] ?? 0);
        $summary['win_rate'] = $trades > 0 ? ($wins / $trades) * 100 : 0;
        $grossLoss = (float)($summary['gross_loss_r'] ?? 0);
        $summary['profit_factor'] = $grossLoss > 0 ? (float)$summary['gross_win_r'] / $grossLoss : null;
        $rated = (int)($summary['rated_plan'] ?? 0);
        $summary['plan_adherence'] = $rated > 0 ? ((int)$summary['followed'] / $rated) * 100 : null;
        $series = fetch_all("SELECT id, trade_date, market, strategy, direction, result_r, result_usd FROM trades $where ORDER BY trade_date, id", $params);
        json_response(['summary' => $summary, 'series' => $series]);
    }

    if ($action === 'export' && $method === 'GET') {
        json_response([
            'version' => 1,
            'exported_at' => utc_now(),
            'plans' => fetch_all('SELECT * FROM plans ORDER BY plan_date, id'),
            'zones' => fetch_all('SELECT * FROM zones ORDER BY plan_id, sort_order, id'),
            'levels' => fetch_all('SELECT * FROM levels ORDER BY plan_id, sort_order, id'),
            'ideas' => fetch_all('SELECT * FROM ideas ORDER BY plan_id, sort_order, id'),
            'plan_refs' => fetch_all('SELECT * FROM plan_refs ORDER BY plan_id, sort_order, id'),
            'plan_dn_levels' => fetch_all('SELECT * FROM plan_dn_levels ORDER BY plan_id, sort_order, id'),
            'custom_fields' => fetch_all('SELECT * FROM custom_fields ORDER BY scope, sort_order, id'),
            'workspace' => workspace(),
            'strategies' => fetch_all('SELECT * FROM strategies ORDER BY name COLLATE NOCASE'),
            'accounts' => fetch_all('SELECT * FROM accounts ORDER BY name COLLATE NOCASE'),
            'calendar_events' => fetch_all('SELECT * FROM calendar_events ORDER BY event_date, id'),
            'psych_checks' => fetch_all('SELECT * FROM psych_checks ORDER BY check_date, id'),
            'psych_profile' => fetch_all('SELECT * FROM psych_profile'),
            'account_audits' => fetch_all('SELECT * FROM account_audits ORDER BY audit_date, id'),
            'trades' => fetch_all('SELECT * FROM trades ORDER BY trade_date, id'),
            'trading_plans' => fetch_all('SELECT * FROM trading_plans ORDER BY version, id'),
            'psych_personality' => fetch_all('SELECT * FROM psych_personality'),
            'screenshots' => fetch_all('SELECT id, plan_id, trade_id, strategy_id, audit_id, role, original_name, mime_type, size_bytes, caption, created_at FROM screenshots ORDER BY created_at, id'),
        ]);
    }

    json_response(['error' => 'Neznámá API operace.'], 404);
} catch (InvalidArgumentException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->__toString());
    json_response(['error' => 'Server operaci nedokončil.', 'detail' => getenv('TRADING_DEBUG') === '1' ? $error->getMessage() : null], 500);
}
