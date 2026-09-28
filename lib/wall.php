<?php
declare(strict_types=1);

/*
 * Společná nástěnka.
 *
 * Sdílí se snímek, ne živý odkaz: náhled, obchod nebo strategie se v okamžiku
 * sdílení zkopíruje do společné databáze i s vybranými grafy. Šifrovaný deník
 * tak zůstane šifrovaný a na nástěnce je jen to, co autor výslovně zveřejnil.
 * Opětovné sdílení snímek aktualizuje a komentáře zůstanou.
 */

const WALL_KINDS = ['plan', 'trade', 'strategy'];
const WALL_REACTIONS = ['like', 'fire', 'target', 'think'];
const WALL_PAGE_SIZE = 12;
const WALL_MAX_MEDIA = 8;
const WALL_NOTE_MAX = 4000;
const WALL_COMMENT_MAX = 2000;

const PLAN_SHARE_FIELDS = [
    'plan_type', 'plan_date', 'market', 'session', 'status', 'bias', 'bias_description', 'bias_confirm', 'bias_invalidation',
    'pa_monthly', 'pa_weekly', 'pa_daily', 'pa_monthly_note', 'pa_weekly_note', 'pa_daily_note',
    'mp_weekly', 'mp_daily', 'mp_weekly_note', 'mp_daily_note', 'profile_shape',
    'ref_high', 'ref_vah', 'ref_poc', 'ref_val', 'ref_low', 'ref_close',
    'value_area', 'vpoc', 'auction', 'weekly_position', 'previous_close',
    'globex_open', 'eu_open', 'ny_open', 'open_type', 'initial_balance', 'single_print', 'tail',
    'important_news', 'no_trade_conditions',
    'dn_dma_3x3', 'dn_dma_7x5', 'dn_dma_25x5', 'dn_thrust', 'dn_patterns', 'dn_notes',
];
const ZONE_SHARE_FIELDS = ['name', 'direction', 'price_low', 'price_high', 'priority', 'source', 'invalidation', 'trigger', 'stop_loss', 'tp1', 'tp2', 'rr', 'status', 'long_entry', 'long_skip', 'short_entry', 'short_skip', 'va_context'];
const LEVEL_SHARE_FIELDS = ['name', 'price', 'kind', 'source', 'line_style', 'note'];
const REF_SHARE_FIELDS = ['kind', 'price_low', 'price_high', 'status', 'note'];
const IDEA_SHARE_FIELDS = ['name', 'direction', 'zone_name', 'trigger', 'entry_price', 'stop_loss', 'tp1', 'tp2', 'final_tp', 'rr', 'status', 'notes'];

function pick_fields(array $row, array $fields): array
{
    $picked = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $picked[$field] = $row[$field];
        }
    }
    return $picked;
}

function clean_text(mixed $value, int $max): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string)$value));
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return mb_substr($text, 0, $max, 'UTF-8');
}

/**
 * Snímek položky z deníku přihlášeného uživatele.
 *
 * @return array{title: string, market: ?string, snapshot: array, screenshots: array}
 */
function build_share_snapshot(string $kind, int $sourceId, array $options): array
{
    $withCharts = (bool)($options['charts'] ?? true);
    if ($kind === 'plan') {
        $plan = plan_payload($sourceId);
        if ($plan === null) {
            throw new InvalidArgumentException('Náhled nebyl nalezen.');
        }
        $snapshot = pick_fields($plan, PLAN_SHARE_FIELDS);
        if ((bool)($options['notes'] ?? false)) {
            $snapshot['general_notes'] = $plan['general_notes'] ?? '';
        }
        $context = $plan['weekly_context'] ?? [];
        $snapshot['weekly_context'] = pick_fields($context, ['source', 'week_start', 'vah', 'val', 'poc', 'high', 'low', 'pa_monthly', 'pa_weekly', 'mp_weekly']);
        $snapshot['zones'] = array_map(static fn(array $row): array => pick_fields($row, ZONE_SHARE_FIELDS), $plan['zones'] ?? []);
        $snapshot['levels'] = array_map(static fn(array $row): array => pick_fields($row, LEVEL_SHARE_FIELDS), $plan['levels'] ?? []);
        $snapshot['refs'] = array_map(static fn(array $row): array => pick_fields($row, REF_SHARE_FIELDS), $plan['refs'] ?? []);
        $snapshot['ideas'] = array_map(static fn(array $row): array => pick_fields($row, IDEA_SHARE_FIELDS), $plan['ideas'] ?? []);
        if (($plan['dinapoli']['levels'] ?? []) !== []) {
            $snapshot['dn_levels'] = array_map(static fn(array $row): array => pick_fields($row, ['timeframe', 'kind', 'status', 'price', 'note']), $plan['dn_levels'] ?? []);
            $snapshot['dinapoli'] = $plan['dinapoli'];
        }
        if ((bool)($options['custom'] ?? false) && ($plan['custom_readable'] ?? []) !== []) {
            $snapshot['custom_readable'] = $plan['custom_readable'];
        }
        $label = (string)$plan['plan_type'] === 'weekly' ? 'Týdenní náhled' : 'Denní náhled';
        $screenshots = $withCharts ? fetch_all('SELECT * FROM screenshots WHERE plan_id = ? ORDER BY created_at, id LIMIT ' . WALL_MAX_MEDIA, [$sourceId]) : [];
        return ['title' => $label . ' ' . $plan['market'] . ' ' . $plan['plan_date'], 'market' => (string)$plan['market'], 'snapshot' => $snapshot, 'screenshots' => $screenshots];
    }

    if ($kind === 'trade') {
        $trade = fetch_one('SELECT * FROM trades WHERE id = ?', [$sourceId]);
        if ($trade === null) {
            throw new InvalidArgumentException('Obchod nebyl nalezen.');
        }
        $snapshot = pick_fields($trade, ['trade_date', 'market', 'session', 'strategy', 'direction', 'entry_price', 'exit_price', 'stop_loss', 'target_price', 'result_r', 'followed_plan', 'execution_rating']);
        if ((bool)($options['money'] ?? false)) {
            $snapshot += pick_fields($trade, ['risk_amount', 'result_usd', 'fees', 'quantity']);
        }
        if ((bool)($options['notes'] ?? false)) {
            $snapshot += pick_fields($trade, ['emotion', 'mistake', 'notes']);
        }
        if ((bool)($options['custom'] ?? false)) {
            $readable = custom_values_readable('trade', (string)($trade['custom'] ?? '{}'));
            if ($readable !== []) {
                $snapshot['custom_readable'] = $readable;
            }
        }
        $screenshots = $withCharts ? fetch_all('SELECT * FROM screenshots WHERE trade_id = ? ORDER BY created_at, id LIMIT ' . WALL_MAX_MEDIA, [$sourceId]) : [];
        return ['title' => 'Obchod ' . $trade['market'] . ' ' . $trade['trade_date'], 'market' => (string)$trade['market'], 'snapshot' => $snapshot, 'screenshots' => $screenshots];
    }

    if ($kind === 'strategy') {
        $strategy = strategy_payload($sourceId);
        if ($strategy === null) {
            throw new InvalidArgumentException('Strategie nebyla nalezena.');
        }
        $snapshot = pick_fields($strategy, ['name', 'timeframe', 'style', 'notes']);
        if ((bool)($options['stats'] ?? true)) {
            foreach (strategy_statistics() as $row) {
                if ((int)$row['strategy_id'] === $sourceId) {
                    $snapshot['stats'] = pick_fields($row, ['trades', 'total_r', 'expectancy_r', 'profit_factor', 'plan_adherence', 'best_r', 'worst_r', 'last_trade']);
                }
            }
        }
        $screenshots = $withCharts ? fetch_all('SELECT * FROM screenshots WHERE strategy_id = ? ORDER BY created_at, id LIMIT ' . WALL_MAX_MEDIA, [$sourceId]) : [];
        return ['title' => 'Strategie ' . $strategy['name'], 'market' => null, 'snapshot' => $snapshot, 'screenshots' => $screenshots];
    }

    throw new InvalidArgumentException('Tohle se sdílet nedá.');
}

function media_extension(string $mime): string
{
    return ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? 'bin';
}

function add_post_media(int $postId, string $contents, string $mime, string $caption, int $order): void
{
    ensure_directory(wall_media_dir());
    $id = bin2hex(random_bytes(16));
    $fileName = $id . '.' . media_extension($mime);
    write_file_atomic(wall_media_dir() . DIRECTORY_SEPARATOR . $fileName, $contents);
    app_execute('INSERT INTO post_media (id, post_id, file_name, mime_type, caption, sort_order) VALUES (?, ?, ?, ?, ?, ?)', [$id, $postId, $fileName, $mime, mb_substr($caption, 0, 200, 'UTF-8'), $order]);
}

function remove_post_media(int $postId): void
{
    foreach (app_fetch_all('SELECT file_name FROM post_media WHERE post_id = ?', [$postId]) as $row) {
        @unlink(wall_media_dir() . DIRECTORY_SEPARATOR . basename((string)$row['file_name']));
    }
    app_execute('DELETE FROM post_media WHERE post_id = ?', [$postId]);
}

/** Sdílí položku z deníku, nebo aktualizuje už sdílený snímek. */
function wall_share(array $user, string $kind, int $sourceId, array $options): array
{
    if (!in_array($kind, WALL_KINDS, true) || $sourceId <= 0) {
        throw new InvalidArgumentException('Tohle se sdílet nedá.');
    }
    $built = build_share_snapshot($kind, $sourceId, $options);
    $body = clean_text($options['note'] ?? '', WALL_NOTE_MAX);
    $encoded = json_encode($built['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $now = utc_now();
    $pdo = app_db();
    $pdo->beginTransaction();
    try {
        $existing = app_fetch_one('SELECT id FROM posts WHERE user_id = ? AND kind = ? AND source_id = ?', [(int)$user['id'], $kind, $sourceId]);
        if ($existing !== null) {
            $postId = (int)$existing['id'];
            app_execute('UPDATE posts SET title = ?, market = ?, body = ?, snapshot = ?, updated_at = ? WHERE id = ?', [$built['title'], $built['market'], $body, $encoded, $now, $postId]);
            remove_post_media($postId);
        } else {
            app_execute('INSERT INTO posts (user_id, kind, source_id, title, market, body, snapshot, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [(int)$user['id'], $kind, $sourceId, $built['title'], $built['market'], $body, $encoded, $now, $now]);
            $postId = (int)$pdo->lastInsertId();
        }
        $order = 0;
        foreach ($built['screenshots'] as $screenshot) {
            $contents = current_journal()->readUpload((string)$screenshot['file_name']);
            if ($contents !== null) {
                add_post_media($postId, $contents, (string)$screenshot['mime_type'], (string)($screenshot['caption'] ?? ''), $order++);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return wall_post($user, $postId, true);
}

function wall_unshare(array $user, string $kind, int $sourceId): void
{
    $post = app_fetch_one('SELECT id FROM posts WHERE user_id = ? AND kind = ? AND source_id = ?', [(int)$user['id'], $kind, $sourceId]);
    if ($post !== null) {
        wall_delete_post($user, (int)$post['id']);
    }
}

/** Validuje nahrané obrázky stejně přísně jako screenshoty deníku. */
function uploaded_images(string $field, int $max): array
{
    $files = $_FILES[$field] ?? null;
    if (!is_array($files) || !isset($files['tmp_name'])) {
        return [];
    }
    $normalized = [];
    foreach ((array)$files['tmp_name'] as $index => $temporary) {
        $error = (int)((array)$files['error'])[$index];
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(upload_error_message($error));
        }
        $size = (int)((array)$files['size'])[$index];
        if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Obrázek může mít nejvýš 20 MB.');
        }
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$temporary);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || @getimagesize((string)$temporary) === false) {
            throw new InvalidArgumentException('Povolené jsou pouze PNG, JPEG a WebP obrázky.');
        }
        $normalized[] = ['path' => (string)$temporary, 'mime' => $mime];
        if (count($normalized) > $max) {
            throw new InvalidArgumentException("Najednou jde přidat nejvýš $max obrázků.");
        }
    }
    return $normalized;
}

function wall_create_note(array $user, string $body, array $images): array
{
    $body = clean_text($body, WALL_NOTE_MAX);
    if ($body === '' && $images === []) {
        throw new InvalidArgumentException('Napiš text příspěvku nebo přidej obrázek.');
    }
    $now = utc_now();
    $title = mb_substr(preg_replace('/\s+/u', ' ', $body) ?: 'Příspěvek', 0, 80, 'UTF-8');
    app_execute('INSERT INTO posts (user_id, kind, source_id, title, market, body, snapshot, created_at, updated_at) VALUES (?, ?, NULL, ?, NULL, ?, ?, ?, ?)', [(int)$user['id'], 'note', $title, $body, '{}', $now, $now]);
    $postId = (int)app_db()->lastInsertId();
    foreach ($images as $order => $image) {
        add_post_media($postId, (string)file_get_contents($image['path']), $image['mime'], '', (int)$order);
    }
    return wall_post($user, $postId, true);
}

function can_moderate_post(array $user, array $post): bool
{
    return (int)$post['user_id'] === (int)$user['id'] || is_admin($user);
}

function wall_delete_post(array $user, int $postId): void
{
    $post = app_fetch_one('SELECT * FROM posts WHERE id = ?', [$postId]);
    if ($post === null) {
        throw new InvalidArgumentException('Příspěvek už neexistuje.');
    }
    if (!can_moderate_post($user, $post)) {
        json_response(['error' => 'Cizí příspěvek může smazat jen správce.'], 403);
    }
    remove_post_media($postId);
    app_execute('DELETE FROM posts WHERE id = ?', [$postId]);
}

function wall_add_comment(array $user, int $postId, string $body): array
{
    $body = clean_text($body, WALL_COMMENT_MAX);
    if ($body === '') {
        throw new InvalidArgumentException('Komentář je prázdný.');
    }
    if (app_fetch_one('SELECT id FROM posts WHERE id = ?', [$postId]) === null) {
        throw new InvalidArgumentException('Příspěvek už neexistuje.');
    }
    app_execute('INSERT INTO comments (post_id, user_id, body, created_at) VALUES (?, ?, ?, ?)', [$postId, (int)$user['id'], $body, utc_now()]);
    return wall_post($user, $postId, true);
}

function wall_delete_comment(array $user, int $commentId): array
{
    $comment = app_fetch_one('SELECT c.*, p.user_id AS post_author FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.id = ?', [$commentId]);
    if ($comment === null) {
        throw new InvalidArgumentException('Komentář už neexistuje.');
    }
    $allowed = (int)$comment['user_id'] === (int)$user['id'] || (int)$comment['post_author'] === (int)$user['id'] || is_admin($user);
    if (!$allowed) {
        json_response(['error' => 'Tento komentář smazat nemůžeš.'], 403);
    }
    app_execute('DELETE FROM comments WHERE id = ?', [$commentId]);
    return wall_post($user, (int)$comment['post_id'], true);
}

function wall_react(array $user, int $postId, ?string $kind): array
{
    if (app_fetch_one('SELECT id FROM posts WHERE id = ?', [$postId]) === null) {
        throw new InvalidArgumentException('Příspěvek už neexistuje.');
    }
    if ($kind === null || $kind === '') {
        app_execute('DELETE FROM reactions WHERE post_id = ? AND user_id = ?', [$postId, (int)$user['id']]);
    } else {
        if (!in_array($kind, WALL_REACTIONS, true)) {
            throw new InvalidArgumentException('Neznámá reakce.');
        }
        app_execute('INSERT INTO reactions (post_id, user_id, kind, created_at) VALUES (?, ?, ?, ?) ON CONFLICT(post_id, user_id) DO UPDATE SET kind = excluded.kind, created_at = excluded.created_at', [$postId, (int)$user['id'], $kind, utc_now()]);
    }
    return wall_post($user, $postId, false);
}

/** Poskládá příspěvky pro prohlížeč: autor, grafy, reakce, komentáře a oprávnění. */
function hydrate_posts(array $user, array $posts, bool $allComments): array
{
    if ($posts === []) {
        return [];
    }
    $ids = array_map(static fn(array $post): int => (int)$post['id'], $posts);
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $media = [];
    foreach (app_fetch_all("SELECT id, post_id, mime_type, caption FROM post_media WHERE post_id IN ($marks) ORDER BY sort_order, id", $ids) as $row) {
        $media[(int)$row['post_id']][] = ['id' => $row['id'], 'url' => 'file.php?media=' . rawurlencode((string)$row['id']), 'caption' => (string)$row['caption']];
    }

    $reactions = [];
    foreach (app_fetch_all("SELECT post_id, kind, COUNT(*) AS total FROM reactions WHERE post_id IN ($marks) GROUP BY post_id, kind", $ids) as $row) {
        $reactions[(int)$row['post_id']][(string)$row['kind']] = (int)$row['total'];
    }
    $mine = [];
    foreach (app_fetch_all("SELECT post_id, kind FROM reactions WHERE user_id = ? AND post_id IN ($marks)", [(int)$user['id'], ...$ids]) as $row) {
        $mine[(int)$row['post_id']] = (string)$row['kind'];
    }
    $reactors = [];
    foreach (app_fetch_all("SELECT r.post_id, u.display_name FROM reactions r JOIN users u ON u.id = r.user_id WHERE r.post_id IN ($marks) ORDER BY r.created_at DESC", $ids) as $row) {
        if (count($reactors[(int)$row['post_id']] ?? []) < 12) {
            $reactors[(int)$row['post_id']][] = (string)$row['display_name'];
        }
    }

    $counts = [];
    foreach (app_fetch_all("SELECT post_id, COUNT(*) AS total FROM comments WHERE post_id IN ($marks) GROUP BY post_id", $ids) as $row) {
        $counts[(int)$row['post_id']] = (int)$row['total'];
    }
    $limit = $allComments ? '' : 'WHERE ranked.position <= 2';
    $comments = [];
    $rows = app_fetch_all(<<<SQL
SELECT * FROM (
    SELECT c.*, u.login, u.display_name, u.avatar_hue, u.role,
           ROW_NUMBER() OVER (PARTITION BY c.post_id ORDER BY c.id DESC) AS position
    FROM comments c JOIN users u ON u.id = c.user_id
    WHERE c.post_id IN ($marks)
) ranked
$limit
ORDER BY ranked.post_id, ranked.id
SQL, $ids);
    $authors = [];
    foreach ($posts as $post) {
        $authors[(int)$post['id']] = (int)$post['user_id'];
    }
    foreach ($rows as $row) {
        $postId = (int)$row['post_id'];
        $comments[$postId][] = [
            'id' => (int)$row['id'],
            'body' => (string)$row['body'],
            'created_at' => (string)$row['created_at'],
            'author' => author_card(['id' => $row['user_id'], 'login' => $row['login'], 'display_name' => $row['display_name'], 'avatar_hue' => $row['avatar_hue'], 'role' => $row['role']]),
            'can_delete' => (int)$row['user_id'] === (int)$user['id'] || $authors[$postId] === (int)$user['id'] || is_admin($user),
        ];
    }

    return array_map(static function (array $post) use ($user, $media, $reactions, $mine, $reactors, $counts, $comments): array {
        $id = (int)$post['id'];
        return [
            'id' => $id,
            'kind' => (string)$post['kind'],
            'source_id' => $post['source_id'] === null ? null : (int)$post['source_id'],
            'title' => (string)$post['title'],
            'market' => $post['market'],
            'body' => (string)($post['body'] ?? ''),
            'snapshot' => json_decode((string)$post['snapshot'], true) ?: new stdClass(),
            'created_at' => (string)$post['created_at'],
            'updated_at' => (string)$post['updated_at'],
            'author' => author_card(['id' => $post['user_id'], 'login' => $post['login'], 'display_name' => $post['display_name'], 'avatar_hue' => $post['avatar_hue'], 'role' => $post['role']]),
            'media' => $media[$id] ?? [],
            'reactions' => ['counts' => (object)($reactions[$id] ?? []), 'mine' => $mine[$id] ?? null, 'people' => $reactors[$id] ?? []],
            'comment_count' => $counts[$id] ?? 0,
            'comments' => $comments[$id] ?? [],
            'mine' => (int)$post['user_id'] === (int)$user['id'],
            'can_delete' => can_moderate_post($user, $post),
        ];
    }, $posts);
}

const POST_SELECT = 'SELECT p.*, u.login, u.display_name, u.avatar_hue, u.role FROM posts p JOIN users u ON u.id = p.user_id';

function wall_post(array $user, int $postId, bool $allComments): array
{
    $post = app_fetch_one(POST_SELECT . ' WHERE p.id = ?', [$postId]);
    if ($post === null) {
        throw new InvalidArgumentException('Příspěvek už neexistuje.');
    }
    return hydrate_posts($user, [$post], $allComments)[0];
}

function wall_feed(array $user, array $filters): array
{
    $where = [];
    $params = [];
    $before = (int)($filters['before'] ?? 0);
    if ($before > 0) {
        $where[] = 'p.id < ?';
        $params[] = $before;
    }
    $kind = (string)($filters['kind'] ?? '');
    if (in_array($kind, [...WALL_KINDS, 'note'], true)) {
        $where[] = 'p.kind = ?';
        $params[] = $kind;
    }
    $author = (int)($filters['author'] ?? 0);
    if ($author > 0) {
        $where[] = 'p.user_id = ?';
        $params[] = $author;
    }
    $market = strtoupper(trim((string)($filters['market'] ?? '')));
    if ($market !== '') {
        $where[] = 'p.market = ?';
        $params[] = $market;
    }
    $sql = POST_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY p.id DESC LIMIT ' . (WALL_PAGE_SIZE + 1);
    $rows = app_fetch_all($sql, $params);
    $hasMore = count($rows) > WALL_PAGE_SIZE;
    $rows = array_slice($rows, 0, WALL_PAGE_SIZE);
    if ($before === 0) {
        app_execute('UPDATE users SET wall_seen_at = ? WHERE id = ?', [utc_now(), (int)$user['id']]);
    }
    return ['items' => hydrate_posts($user, $rows, false), 'has_more' => $hasMore];
}

function wall_unseen_count(array $user): int
{
    $seen = (string)($user['wall_seen_at'] ?? '');
    $row = app_fetch_one('SELECT COUNT(*) AS total FROM posts WHERE user_id <> ? AND created_at > ?', [(int)$user['id'], $seen === '' ? (string)$user['created_at'] : $seen]);
    return (int)($row['total'] ?? 0);
}

function wall_my_shares(array $user): array
{
    return array_map(static fn(array $row): array => [
        'kind' => (string)$row['kind'],
        'source_id' => (int)$row['source_id'],
        'post_id' => (int)$row['id'],
        'updated_at' => (string)$row['updated_at'],
    ], app_fetch_all('SELECT id, kind, source_id, updated_at FROM posts WHERE user_id = ? AND source_id IS NOT NULL', [(int)$user['id']]));
}

function wall_members(): array
{
    return array_map(static fn(array $row): array => author_card($row) + ['posts' => (int)$row['posts']], app_fetch_all(
        "SELECT u.*, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS posts FROM users u WHERE u.status = 'active' ORDER BY u.display_name COLLATE NOCASE"
    ));
}

/** Smaže uživatele se vším: deník, screenshoty, příspěvky, komentáře a relace. */
function purge_user(int $userId): void
{
    foreach (app_fetch_all('SELECT id FROM posts WHERE user_id = ?', [$userId]) as $post) {
        remove_post_media((int)$post['id']);
    }
    app_execute('DELETE FROM users WHERE id = ?', [$userId]);
    delete_directory(user_storage_dir($userId));
}
