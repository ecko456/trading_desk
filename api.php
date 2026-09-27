<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$action = (string)($_GET['action'] ?? 'health');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    require_same_origin();
}

try {
    if ($action === 'health' && $method === 'GET') {
        $integrity = fetch_one('PRAGMA integrity_check');
        json_response([
            'ok' => true,
            'app' => 'Trading Journal',
            'version' => APP_VERSION,
            'base' => APP_BASE,
            'database' => array_values($integrity ?? ['ok'])[0] ?? 'ok',
            'time' => utc_now(),
        ]);
    }

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
        foreach ($screenshots as $screenshot) {
            delete_screenshot_file($screenshot);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'strategies' && $method === 'GET') {
        $sql = 'SELECT s.*, (SELECT COUNT(*) FROM screenshots sc WHERE sc.strategy_id = s.id) AS screenshot_count, (SELECT COUNT(*) FROM trades t WHERE t.strategy_id = s.id) AS trade_count FROM strategies s ORDER BY s.name COLLATE NOCASE';
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
        json_response(['items' => psych_questions($profile['dimensions'] ?? null), 'has_profile' => $profile !== null]);
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

    if ($action === 'upload' && $method === 'POST') {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            json_response(['error' => 'Nebyl vybrán soubor.'], 422);
        }
        $upload = $_FILES['file'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_response(['error' => 'Nahrání souboru selhalo.'], 422);
        }
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
            json_response(['error' => 'Screenshot musí mít maximálně 20 MB.'], 422);
        }
        $temporary = (string)$upload['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($temporary);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            json_response(['error' => 'Povolené jsou pouze PNG, JPEG a WebP obrázky.'], 422);
        }
        if (@getimagesize($temporary) === false) {
            json_response(['error' => 'Soubor není platný obrázek.'], 422);
        }

        $planId = nullable_int($_POST['plan_id'] ?? null);
        $tradeId = nullable_int($_POST['trade_id'] ?? null);
        $strategyId = nullable_int($_POST['strategy_id'] ?? null);
        $auditId = nullable_int($_POST['audit_id'] ?? null);
        if ($planId === null && $tradeId === null && $strategyId === null && $auditId === null) {
            json_response(['error' => 'Screenshot musí patřit k náhledu, obchodu, strategii nebo auditu.'], 422);
        }
        $id = bin2hex(random_bytes(16));
        $fileName = $id . '.' . $extensions[$mime];
        $destination = upload_dir() . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($temporary, $destination)) {
            json_response(['error' => 'Screenshot nelze uložit na disk.'], 500);
        }
        @chmod($destination, 0660);

        try {
            $statement = db()->prepare('INSERT INTO screenshots (id, plan_id, trade_id, strategy_id, audit_id, role, file_name, original_name, mime_type, size_bytes, caption, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([
                $id, $planId, $tradeId, $strategyId, $auditId, (string)($_POST['role'] ?? 'plan'), $fileName,
                basename((string)($upload['name'] ?? 'screenshot')), $mime, $size,
                (string)($_POST['caption'] ?? ''), utc_now()
            ]);
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        }
        json_response(['id' => $id, 'url' => APP_BASE . '/file.php?id=' . rawurlencode($id)], 201);
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
            'strategies' => fetch_all('SELECT * FROM strategies ORDER BY name COLLATE NOCASE'),
            'accounts' => fetch_all('SELECT * FROM accounts ORDER BY name COLLATE NOCASE'),
            'calendar_events' => fetch_all('SELECT * FROM calendar_events ORDER BY event_date, id'),
            'psych_checks' => fetch_all('SELECT * FROM psych_checks ORDER BY check_date, id'),
            'psych_profile' => fetch_all('SELECT * FROM psych_profile'),
            'account_audits' => fetch_all('SELECT * FROM account_audits ORDER BY audit_date, id'),
            'trades' => fetch_all('SELECT * FROM trades ORDER BY trade_date, id'),
            'screenshots' => fetch_all('SELECT id, plan_id, trade_id, strategy_id, audit_id, role, original_name, mime_type, size_bytes, caption, created_at FROM screenshots ORDER BY created_at, id'),
        ]);
    }

    json_response(['error' => 'Neznámá API operace.'], 404);
} catch (Throwable $error) {
    error_log($error->__toString());
    json_response(['error' => 'Server operaci nedokončil.', 'detail' => getenv('TRADING_DEBUG') === '1' ? $error->getMessage() : null], 500);
}
