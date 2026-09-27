<?php
declare(strict_types=1);

/*
 * Účty, relace a úložiště deníků.
 *
 * Sdílená databáze app.sqlite3 drží uživatele, relace, nástěnku a nastavení.
 * Každý uživatel má vlastní adresář users/<id>/ s vlastní SQLite databází deníku
 * a screenshoty, takže data dvou uživatelů se nikdy nepotkají v jednom dotazu.
 */

const SESSION_COOKIE = 'td_session';
const SESSION_DAYS = 14;
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_FAILURES_PER_LOGIN = 8;
const LOGIN_MAX_FAILURES_PER_IP = 25;
define('REGISTER_MAX_PER_IP_HOUR', max(1, (int)(getenv('TRADING_REGISTER_PER_HOUR') ?: 5)));
const PASSWORD_MIN_LENGTH = 10;

function storage_root(): string
{
    $configured = getenv('TRADING_DATA_DIR');
    return $configured !== false && $configured !== ''
        ? rtrim($configured, DIRECTORY_SEPARATOR)
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
}

function ensure_directory(string $directory, int $mode = 0770): void
{
    if (!is_dir($directory) && !mkdir($directory, $mode, true) && !is_dir($directory)) {
        throw new RuntimeException('Nelze vytvořit datový adresář: ' . $directory);
    }
}

function user_storage_dir(int $userId): string
{
    return storage_root() . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $userId;
}

function wall_media_dir(): string
{
    return storage_root() . DIRECTORY_SEPARATOR . 'wall';
}

/* ---------------------------------------------------------------- app DB */

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    ensure_directory(storage_root());
    $pdo = new PDO('sqlite:' . storage_root() . DIRECTORY_SEPARATOR . 'app.sqlite3');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    initialize_app_schema($pdo);
    return $pdo;
}

function initialize_app_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    login TEXT NOT NULL UNIQUE COLLATE NOCASE,
    display_name TEXT NOT NULL,
    email TEXT,
    role TEXT NOT NULL DEFAULT 'member' CHECK(role IN ('member', 'admin')),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'active', 'blocked')),
    secret_hash TEXT NOT NULL,
    encrypted INTEGER NOT NULL DEFAULT 0,
    kdf_salt BLOB,
    wrapped_key BLOB,
    avatar_hue INTEGER NOT NULL DEFAULT 42,
    must_change_secret INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    approved_at TEXT,
    approved_by INTEGER,
    last_login_at TEXT,
    wall_seen_at TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    wrapped_key BLOB,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    user_agent TEXT,
    ip TEXT
);

CREATE TABLE IF NOT EXISTS auth_attempts (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL,
    ip TEXT NOT NULL,
    login TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind TEXT NOT NULL CHECK(kind IN ('plan', 'trade', 'strategy', 'note')),
    source_id INTEGER,
    title TEXT NOT NULL,
    market TEXT,
    body TEXT,
    snapshot TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS post_media (
    id TEXT PRIMARY KEY,
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    file_name TEXT NOT NULL UNIQUE,
    mime_type TEXT NOT NULL,
    caption TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY,
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS reactions (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (post_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_attempts_lookup ON auth_attempts(kind, created_at);
CREATE INDEX IF NOT EXISTS idx_posts_feed ON posts(id DESC);
CREATE INDEX IF NOT EXISTS idx_posts_author ON posts(user_id, id DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_posts_source ON posts(user_id, kind, source_id) WHERE source_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_comments_post ON comments(post_id, id);
CREATE INDEX IF NOT EXISTS idx_media_post ON post_media(post_id, sort_order);
SQL);
}

function app_fetch_all(string $sql, array $params = []): array
{
    $statement = app_db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function app_fetch_one(string $sql, array $params = []): ?array
{
    $statement = app_db()->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function app_execute(string $sql, array $params = []): int
{
    $statement = app_db()->prepare($sql);
    $statement->execute($params);
    return $statement->rowCount();
}

function setting(string $key, string $default = ''): string
{
    $row = app_fetch_one('SELECT value FROM settings WHERE key = ?', [$key]);
    return $row === null ? $default : (string)$row['value'];
}

function save_setting(string $key, string $value): void
{
    app_execute('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
}

function registration_open(): bool
{
    return setting('registration_open', '1') === '1';
}

/* ---------------------------------------------------------------- uživatelé */

function find_user(int $id): ?array
{
    return app_fetch_one('SELECT * FROM users WHERE id = ?', [$id]);
}

function find_user_by_login(string $login): ?array
{
    return app_fetch_one('SELECT * FROM users WHERE login = ?', [strtolower(trim($login))]);
}

/** Údaje o uživateli, které smí vidět prohlížeč. Nikdy ne hash ani klíče. */
function public_user(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'login' => (string)$user['login'],
        'display_name' => (string)$user['display_name'],
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)$user['role'],
        'status' => (string)$user['status'],
        'encrypted' => (bool)$user['encrypted'],
        'avatar_hue' => (int)$user['avatar_hue'],
        'must_change_secret' => (bool)$user['must_change_secret'],
        'created_at' => (string)$user['created_at'],
    ];
}

function author_card(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'login' => (string)$user['login'],
        'display_name' => (string)$user['display_name'],
        'avatar_hue' => (int)$user['avatar_hue'],
        'role' => (string)$user['role'],
    ];
}

function is_admin(?array $user): bool
{
    return $user !== null && $user['role'] === 'admin' && $user['status'] === 'active';
}

function validate_registration(array $data): array
{
    $login = strtolower(trim((string)($data['login'] ?? '')));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $login)) {
        throw new InvalidArgumentException('Přihlašovací jméno musí mít 3 až 32 znaků: písmena bez diakritiky, číslice, tečka, podtržítko nebo pomlčka.');
    }
    $displayName = trim(preg_replace('/\s+/u', ' ', (string)($data['display_name'] ?? '')) ?? '');
    if (mb_strlen($displayName, 'UTF-8') < 2 || mb_strlen($displayName, 'UTF-8') > 60) {
        throw new InvalidArgumentException('Zobrazované jméno musí mít 2 až 60 znaků.');
    }
    $email = trim((string)($data['email'] ?? ''));
    if ($email !== '' && (strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        throw new InvalidArgumentException('E-mail nemá platný tvar.');
    }
    $mode = (string)($data['secret_mode'] ?? 'password') === 'key' ? 'key' : 'password';
    $password = (string)($data['password'] ?? '');
    if ($mode === 'password') {
        validate_password($password, $login);
    }
    if (find_user_by_login($login) !== null) {
        throw new InvalidArgumentException('Toto přihlašovací jméno už někdo používá.');
    }
    return ['login' => $login, 'display_name' => $displayName, 'email' => $email, 'mode' => $mode, 'password' => $password];
}

function validate_password(string $password, string $login = ''): void
{
    if (mb_strlen($password, 'UTF-8') < PASSWORD_MIN_LENGTH) {
        throw new InvalidArgumentException('Heslo musí mít aspoň ' . PASSWORD_MIN_LENGTH . ' znaků.');
    }
    if (strlen($password) > 200) {
        throw new InvalidArgumentException('Heslo je příliš dlouhé.');
    }
    if ($login !== '' && strcasecmp($password, $login) === 0) {
        throw new InvalidArgumentException('Heslo nesmí být stejné jako přihlašovací jméno.');
    }
}

/**
 * Založí uživatele. U šifrovaného účtu vrátí přístupový klíč, který se ukáže
 * jen jednou a server si ho nikde neuloží.
 *
 * @return array{0: array, 1: ?string}
 */
function create_user(array $clean, string $status, string $role): array
{
    $now = utc_now();
    $accessKey = null;
    $salt = null;
    $wrapped = null;
    if ($clean['mode'] === 'key') {
        vault_require();
        $accessKey = generate_access_key();
        $secretHash = password_hash(normalize_access_key($accessKey), PASSWORD_DEFAULT);
    } else {
        $secretHash = password_hash($clean['password'], PASSWORD_DEFAULT);
    }
    $hue = random_int(0, 359);
    app_execute(
        'INSERT INTO users (login, display_name, email, role, status, secret_hash, encrypted, avatar_hue, created_at, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$clean['login'], $clean['display_name'], $clean['email'] ?: null, $role, $status, $secretHash, $accessKey !== null ? 1 : 0, $hue, $now, $status === 'active' ? $now : null]
    );
    $id = (int)app_db()->lastInsertId();
    if ($accessKey !== null) {
        $dataKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $salt = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
        $wrapped = vault_seal($dataKey, access_key_kek($accessKey, $salt), 'dek|user:' . $id);
        $statement = app_db()->prepare('UPDATE users SET kdf_salt = ?, wrapped_key = ? WHERE id = ?');
        $statement->bindValue(1, $salt, PDO::PARAM_LOB);
        $statement->bindValue(2, $wrapped, PDO::PARAM_LOB);
        $statement->bindValue(3, $id, PDO::PARAM_INT);
        $statement->execute();
    }
    return [find_user($id), $accessKey];
}

function verify_user_secret(array $user, string $secret): bool
{
    $candidate = (bool)$user['encrypted'] ? normalize_access_key($secret) : $secret;
    return password_verify($candidate, (string)$user['secret_hash']);
}

/** Datový klíč šifrovaného uživatele odemčený jeho přístupovým klíčem. */
function unwrap_user_key(array $user, string $accessKey): string
{
    return vault_open((string)$user['wrapped_key'], access_key_kek($accessKey, (string)$user['kdf_salt']), 'dek|user:' . (int)$user['id']);
}

function delete_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($directory);
}

function directory_size(string $directory): int
{
    if (!is_dir($directory)) {
        return 0;
    }
    $total = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $total += (int)$file->getSize();
        }
    }
    return $total;
}

/* ---------------------------------------------------------------- relace */

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function request_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    return ($https !== '' && $https !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Cesta cookie = adresář aplikace (/trading/ na Apache, / u vestavěného serveru). */
function app_path(): string
{
    $directory = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    return rtrim($directory, '/') . '/';
}

function set_session_cookie(string $value, int $expires): void
{
    setcookie(SESSION_COOKIE, $value, [
        'expires' => $expires,
        'path' => app_path(),
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function session_id_for(string $token): string
{
    return hash('sha256', $token);
}

function start_session(array $user, ?string $dataKey): void
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $id = session_id_for($token);
    $now = time();
    $wrapped = $dataKey !== null ? vault_seal($dataKey, session_kek($token), 'session|' . $id) : null;
    $statement = app_db()->prepare('INSERT INTO sessions (id, user_id, wrapped_key, created_at, last_seen_at, expires_at, user_agent, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->bindValue(1, $id);
    $statement->bindValue(2, (int)$user['id'], PDO::PARAM_INT);
    $statement->bindValue(3, $wrapped, $wrapped === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $statement->bindValue(4, gmdate('Y-m-d\TH:i:s\Z', $now));
    $statement->bindValue(5, gmdate('Y-m-d\TH:i:s\Z', $now));
    $statement->bindValue(6, gmdate('Y-m-d\TH:i:s\Z', $now + SESSION_DAYS * 86400));
    $statement->bindValue(7, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250));
    $statement->bindValue(8, client_ip());
    $statement->execute();
    app_execute('UPDATE users SET last_login_at = ? WHERE id = ?', [utc_now(), (int)$user['id']]);
    set_session_cookie($token, $now + SESSION_DAYS * 86400);
    $GLOBALS['td_auth'] = ['user' => find_user((int)$user['id']), 'key' => $dataKey, 'session' => $id, 'token' => $token];
}

/** Přihlásí uživatele z cookie. Volá se líně při prvním dotazu na current_user(). */
function authenticate_request(): void
{
    $GLOBALS['td_auth'] = ['user' => null, 'key' => null, 'session' => null, 'token' => null];
    $token = (string)($_COOKIE[SESSION_COOKIE] ?? '');
    if ($token === '' || strlen($token) > 100) {
        return;
    }
    $id = session_id_for($token);
    $session = app_fetch_one('SELECT * FROM sessions WHERE id = ?', [$id]);
    if ($session === null || strtotime((string)$session['expires_at']) < time()) {
        if ($session !== null) {
            app_execute('DELETE FROM sessions WHERE id = ?', [$id]);
        }
        return;
    }
    $user = find_user((int)$session['user_id']);
    if ($user === null || $user['status'] !== 'active') {
        app_execute('DELETE FROM sessions WHERE id = ?', [$id]);
        return;
    }
    $dataKey = null;
    if ((bool)$user['encrypted']) {
        try {
            $dataKey = vault_open((string)$session['wrapped_key'], session_kek($token), 'session|' . $id);
        } catch (Throwable) {
            app_execute('DELETE FROM sessions WHERE id = ?', [$id]);
            return;
        }
    }
    // Prodloužení relace nejvýš jednou za pět minut, ať se při každém požadavku nezapisuje.
    if (strtotime((string)$session['last_seen_at']) < time() - 300) {
        $expires = time() + SESSION_DAYS * 86400;
        app_execute('UPDATE sessions SET last_seen_at = ?, expires_at = ? WHERE id = ?', [utc_now(), gmdate('Y-m-d\TH:i:s\Z', $expires), $id]);
        set_session_cookie($token, $expires);
    }
    $GLOBALS['td_auth'] = ['user' => $user, 'key' => $dataKey, 'session' => $id, 'token' => $token];
}

function current_user(): ?array
{
    if (!isset($GLOBALS['td_auth'])) {
        authenticate_request();
    }
    return $GLOBALS['td_auth']['user'];
}

function current_user_key(): ?string
{
    current_user();
    return $GLOBALS['td_auth']['key'];
}

function current_session_id(): ?string
{
    current_user();
    return $GLOBALS['td_auth']['session'];
}

function require_user(): array
{
    $user = current_user();
    if ($user === null) {
        json_response(['error' => 'Přihlášení vypršelo. Přihlas se prosím znovu.', 'auth' => true], 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if (!is_admin($user)) {
        json_response(['error' => 'Tohle může jen správce.'], 403);
    }
    return $user;
}

function end_current_session(): void
{
    $id = current_session_id();
    if ($id !== null) {
        app_execute('DELETE FROM sessions WHERE id = ?', [$id]);
    }
    set_session_cookie('', time() - 3600);
    $GLOBALS['td_auth'] = ['user' => null, 'key' => null, 'session' => null, 'token' => null];
}

function revoke_sessions(int $userId, ?string $exceptSession = null): void
{
    if ($exceptSession !== null) {
        app_execute('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$userId, $exceptSession]);
        return;
    }
    app_execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
}

/* ---------------------------------------------------------------- ochrana proti hádání */

function record_attempt(string $kind, ?string $login = null): void
{
    app_execute('INSERT INTO auth_attempts (kind, ip, login, created_at) VALUES (?, ?, ?, ?)', [$kind, client_ip(), $login, utc_now()]);
    if (random_int(1, 50) === 1) {
        app_execute('DELETE FROM auth_attempts WHERE created_at < ?', [gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
    }
}

function count_attempts(string $kind, int $minutes, ?string $login = null): int
{
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $minutes * 60);
    if ($login !== null) {
        $row = app_fetch_one('SELECT COUNT(*) AS total FROM auth_attempts WHERE kind = ? AND login = ? AND created_at >= ?', [$kind, $login, $since]);
    } else {
        $row = app_fetch_one('SELECT COUNT(*) AS total FROM auth_attempts WHERE kind = ? AND ip = ? AND created_at >= ?', [$kind, client_ip(), $since]);
    }
    return (int)($row['total'] ?? 0);
}

function throttle_login(string $login): void
{
    if (count_attempts('login_fail', LOGIN_WINDOW_MINUTES) >= LOGIN_MAX_FAILURES_PER_IP
        || count_attempts('login_fail', LOGIN_WINDOW_MINUTES, $login) >= LOGIN_MAX_FAILURES_PER_LOGIN) {
        json_response(['error' => 'Příliš mnoho neúspěšných pokusů. Zkus to znovu za čtvrt hodiny.'], 429);
    }
}

/* ---------------------------------------------------------------- první správce */

function setup_required(): bool
{
    return app_fetch_one("SELECT id FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1") === null;
}

function setup_token_path(): string
{
    return storage_root() . DIRECTORY_SEPARATOR . 'setup-token.txt';
}

/** Jednorázový kód pro založení správce. Leží jen na disku serveru. */
function setup_token(): string
{
    $path = setup_token_path();
    if (is_file($path)) {
        $token = trim((string)file_get_contents($path));
        if ($token !== '') {
            return $token;
        }
    }
    ensure_directory(storage_root());
    $token = implode('-', str_split(strtoupper(bin2hex(random_bytes(6))), 4));
    write_file_atomic($path, $token . "\n", 0600);
    return $token;
}

/** Deník z doby před účty (data/trading.sqlite3) převezme první správce. */
function legacy_journal_path(): string
{
    return storage_root() . DIRECTORY_SEPARATOR . 'trading.sqlite3';
}

function adopt_legacy_journal(int $userId): bool
{
    $legacy = legacy_journal_path();
    if (!is_file($legacy)) {
        return false;
    }
    $target = user_storage_dir($userId);
    ensure_directory($target . DIRECTORY_SEPARATOR . 'uploads');
    $pdo = new PDO('sqlite:' . $legacy);
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo = null;
    if (!rename($legacy, $target . DIRECTORY_SEPARATOR . 'trading.sqlite3')) {
        throw new RuntimeException('Stávající deník se nepodařilo přesunout.');
    }
    foreach (['-wal', '-shm'] as $suffix) {
        @unlink($legacy . $suffix);
    }
    $legacyUploads = storage_root() . DIRECTORY_SEPARATOR . 'uploads';
    foreach (glob($legacyUploads . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file) && basename($file) !== '.gitkeep') {
            rename($file, $target . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($file));
        }
    }
    return true;
}

/* ---------------------------------------------------------------- úložiště deníku */

/**
 * Deník přihlášeného uživatele. Nešifrovaný je obyčejná SQLite databáze.
 * Šifrovaný se na dobu požadavku odemkne do RAM, po zápisu se znovu zapečetí
 * a odemčená kopie se smaže. Souběžné požadavky téhož uživatele čekají na zámek.
 */
final class JournalStore
{
    private ?PDO $pdo = null;
    private mixed $lock = null;
    private ?string $temporary = null;
    private string $sealedHash = '';

    public function __construct(private readonly int $userId, private readonly ?string $dataKey)
    {
    }

    public function directory(): string
    {
        return user_storage_dir($this->userId);
    }

    public function encrypted(): bool
    {
        return $this->dataKey !== null;
    }

    public function sealedPath(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . 'trading.sqlite3.sealed';
    }

    public function plainPath(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . 'trading.sqlite3';
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        ensure_directory($this->directory() . DIRECTORY_SEPARATOR . 'uploads');
        if (!$this->encrypted()) {
            $pdo = new PDO('sqlite:' . $this->plainPath());
            $this->configure($pdo, 'WAL', 'NORMAL');
            return $this->pdo = $pdo;
        }

        $this->acquireLock();
        $plain = '';
        if (is_file($this->sealedPath())) {
            $plain = vault_open((string)file_get_contents($this->sealedPath()), $this->dataKey, 'journal|user:' . $this->userId);
        }
        $this->temporary = vault_temp_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12)) . '.sqlite3';
        $handle = fopen($this->temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Deník nejde dočasně odemknout.');
        }
        @chmod($this->temporary, 0600);
        fwrite($handle, $plain);
        fclose($handle);
        $this->sealedHash = $plain === '' ? '' : hash('sha256', $plain);
        unset($plain);
        register_shutdown_function([$this, 'close']);

        $pdo = new PDO('sqlite:' . $this->temporary);
        $this->configure($pdo, 'MEMORY', 'OFF');
        return $this->pdo = $pdo;
    }

    private function configure(PDO $pdo, string $journalMode, string $synchronous): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = ' . $journalMode);
        $pdo->exec('PRAGMA synchronous = ' . $synchronous);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        initialize_schema($pdo);
        migrate_schema($pdo);
    }

    private function acquireLock(): void
    {
        if ($this->lock !== null) {
            return;
        }
        $this->lock = fopen($this->directory() . DIRECTORY_SEPARATOR . 'journal.lock', 'c');
        if ($this->lock === false || !flock($this->lock, LOCK_EX)) {
            throw new RuntimeException('Deník je právě zamčený jiným požadavkem.');
        }
    }

    /** Zapečetí změny na disk. Volá se před odesláním odpovědi, aby úspěch znamenal uloženo. */
    public function flush(): void
    {
        if (!$this->encrypted() || $this->temporary === null || !($this->pdo instanceof PDO)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $plain = (string)file_get_contents($this->temporary);
        $hash = hash('sha256', $plain);
        if ($hash !== $this->sealedHash) {
            write_file_atomic($this->sealedPath(), vault_seal($plain, $this->dataKey, 'journal|user:' . $this->userId));
            $this->sealedHash = $hash;
        }
    }

    public function close(): void
    {
        try {
            $this->flush();
        } catch (Throwable $error) {
            error_log('Trading journal seal failed: ' . $error->getMessage());
        }
        $this->pdo = null;
        if ($this->temporary !== null) {
            @unlink($this->temporary);
            @unlink($this->temporary . '-journal');
            $this->temporary = null;
        }
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
    }

    /** Uloží soubor do uploads; u šifrovaného deníku zapečetěný. */
    public function storeUpload(string $sourcePath, string $fileName): void
    {
        $destination = $this->directory() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($fileName);
        ensure_directory(dirname($destination));
        if ($this->encrypted()) {
            $contents = file_get_contents($sourcePath);
            if ($contents === false) {
                throw new RuntimeException('Nahraný soubor nejde přečíst.');
            }
            write_file_atomic($destination, vault_seal($contents, $this->dataKey, 'upload|user:' . $this->userId . '|' . basename($fileName)));
            @unlink($sourcePath);
            return;
        }
        if (is_uploaded_file($sourcePath) ? !move_uploaded_file($sourcePath, $destination) : !rename($sourcePath, $destination)) {
            throw new RuntimeException('Screenshot nelze uložit na disk.');
        }
        @chmod($destination, 0660);
    }

    public function readUpload(string $fileName): ?string
    {
        $path = $this->directory() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($fileName);
        if (!is_file($path)) {
            return null;
        }
        $contents = (string)file_get_contents($path);
        if (vault_is_sealed($contents)) {
            if (!$this->encrypted()) {
                return null;
            }
            return vault_open($contents, $this->dataKey, 'upload|user:' . $this->userId . '|' . basename($fileName));
        }
        return $contents;
    }

    public function uploadPath(string $fileName): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($fileName);
    }

    /** Konzistentní kopie databáze do dočasného souboru (pro zálohu). */
    public function snapshotTo(string $path): void
    {
        $pdo = $this->pdo();
        $this->flush();
        $pdo->exec('VACUUM INTO ' . $pdo->quote($path));
    }
}

function current_journal(): JournalStore
{
    static $store = null;
    static $owner = null;
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Deník je dostupný jen přihlášenému uživateli.');
    }
    if (!($store instanceof JournalStore) || $owner !== (int)$user['id']) {
        $store = new JournalStore((int)$user['id'], (bool)$user['encrypted'] ? current_user_key() : null);
        $owner = (int)$user['id'];
        $GLOBALS['td_journal'] = $store;
    }
    return $store;
}

function journal_flush(): void
{
    $store = $GLOBALS['td_journal'] ?? null;
    if ($store instanceof JournalStore) {
        $store->flush();
    }
}

/**
 * Převede nešifrovaný deník uživatele na šifrovaný: databázi i všechny screenshoty.
 * Běží pod zámkem deníku, takže se nepotká s jiným požadavkem.
 */
function seal_user_storage(int $userId, string $dataKey): void
{
    $directory = user_storage_dir($userId);
    ensure_directory($directory . DIRECTORY_SEPARATOR . 'uploads');
    $lock = fopen($directory . DIRECTORY_SEPARATOR . 'journal.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Deník je právě zamčený jiným požadavkem.');
    }
    try {
        $plainPath = $directory . DIRECTORY_SEPARATOR . 'trading.sqlite3';
        if (is_file($plainPath)) {
            $pdo = new PDO('sqlite:' . $plainPath);
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $pdo->exec('PRAGMA journal_mode = DELETE');
            $pdo = null;
            $plain = (string)file_get_contents($plainPath);
            write_file_atomic($directory . DIRECTORY_SEPARATOR . 'trading.sqlite3.sealed', vault_seal($plain, $dataKey, 'journal|user:' . $userId));
            unset($plain);
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($plainPath . $suffix);
            }
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (!is_file($file) || str_contains(basename($file), '.tmp-')) {
                continue;
            }
            $contents = (string)file_get_contents($file);
            if (!vault_is_sealed($contents)) {
                write_file_atomic($file, vault_seal($contents, $dataKey, 'upload|user:' . $userId . '|' . basename($file)));
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/* ---------------------------------------------------------------- přihlášení a registrace */

function perform_login(string $login, string $secret): array
{
    $login = strtolower(trim($login));
    throttle_login($login);
    $user = $login === '' ? null : find_user_by_login($login);
    // Stejná práce i pro neexistující účet, ať délka odpovědi neprozradí, kdo je registrovaný.
    $valid = $user !== null
        ? verify_user_secret($user, $secret)
        : password_verify($secret, '$2y$12$3imvx.mqLEYzyaqeT/fRFObY54FjRxk/wLvFv0qMKuEmUacZN9Lee');
    if (!$valid || $user === null) {
        record_attempt('login_fail', $login);
        json_response(['error' => 'Nesprávné jméno, heslo nebo přístupový klíč.'], 401);
    }
    if ($user['status'] === 'pending') {
        json_response(['error' => 'Registrace čeká na schválení správcem. Jakmile ji schválí, můžeš se přihlásit.', 'pending' => true], 403);
    }
    if ($user['status'] === 'blocked') {
        json_response(['error' => 'Účet je zablokovaný. Obrať se na správce.'], 403);
    }
    $dataKey = (bool)$user['encrypted'] ? unwrap_user_key($user, $secret) : null;
    start_session($user, $dataKey);
    return public_user(current_user());
}

function perform_registration(array $data): array
{
    if (!registration_open()) {
        json_response(['error' => 'Registrace jsou teď uzavřené.'], 403);
    }
    if (count_attempts('register', 60) >= REGISTER_MAX_PER_IP_HOUR) {
        json_response(['error' => 'Z této adresy přišlo příliš mnoho registrací. Zkus to později.'], 429);
    }
    $clean = validate_registration($data);
    record_attempt('register', $clean['login']);
    [$user, $accessKey] = create_user($clean, 'pending', 'member');
    return ['user' => public_user($user), 'access_key' => $accessKey];
}

/** Založí prvního správce. Vyžaduje jednorázový kód z disku serveru. */
function perform_setup(array $data): array
{
    if (!setup_required()) {
        json_response(['error' => 'Správce už existuje.'], 409);
    }
    throttle_login('#setup');
    $token = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($data['token'] ?? '')) ?? '');
    $expected = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', setup_token()) ?? '');
    if ($token === '' || !hash_equals($expected, $token)) {
        record_attempt('login_fail', '#setup');
        json_response(['error' => 'Kód pro založení správce nesedí.'], 401);
    }
    $clean = validate_registration($data);
    [$user, $accessKey] = create_user($clean, 'active', 'admin');
    $adopted = adopt_legacy_journal((int)$user['id']);
    $dataKey = $accessKey !== null ? unwrap_user_key($user, $accessKey) : null;
    if ($dataKey !== null && $adopted) {
        seal_user_storage((int)$user['id'], $dataKey);
    }
    @unlink(setup_token_path());
    start_session($user, $dataKey);
    return ['user' => public_user(current_user()), 'access_key' => $accessKey, 'adopted' => $adopted];
}

function auth_state(): array
{
    $user = current_user();
    $setupRequired = setup_required();
    if ($setupRequired) {
        // Kód vznikne na disku serveru; do prohlížeče se nikdy neposílá.
        setup_token();
    }
    return [
        'setup_required' => $setupRequired,
        'registration_open' => registration_open(),
        'encryption_available' => vault_available(),
        'user' => $user === null ? null : public_user($user),
    ];
}

/* ---------------------------------------------------------------- profil */

function update_profile(array $user, array $data): array
{
    $displayName = trim(preg_replace('/\s+/u', ' ', (string)($data['display_name'] ?? '')) ?? '');
    if (mb_strlen($displayName, 'UTF-8') < 2 || mb_strlen($displayName, 'UTF-8') > 60) {
        throw new InvalidArgumentException('Zobrazované jméno musí mít 2 až 60 znaků.');
    }
    $email = trim((string)($data['email'] ?? ''));
    if ($email !== '' && (strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        throw new InvalidArgumentException('E-mail nemá platný tvar.');
    }
    $hue = isset($data['avatar_hue']) ? max(0, min(359, (int)$data['avatar_hue'])) : (int)$user['avatar_hue'];
    app_execute('UPDATE users SET display_name = ?, email = ?, avatar_hue = ? WHERE id = ?', [$displayName, $email ?: null, $hue, (int)$user['id']]);
    return public_user(find_user((int)$user['id']));
}

function change_password(array $user, string $current, string $next): array
{
    if ((bool)$user['encrypted']) {
        throw new InvalidArgumentException('Šifrovaný účet nemá heslo, přihlašuje se přístupovým klíčem.');
    }
    if (!verify_user_secret($user, $current)) {
        json_response(['error' => 'Současné heslo nesedí.'], 403);
    }
    validate_password($next, (string)$user['login']);
    app_execute('UPDATE users SET secret_hash = ?, must_change_secret = 0 WHERE id = ?', [password_hash($next, PASSWORD_DEFAULT), (int)$user['id']]);
    revoke_sessions((int)$user['id'], current_session_id());
    return public_user(find_user((int)$user['id']));
}

/** Zapne šifrování existujícího deníku. Heslo nahradí přístupový klíč. */
function enable_encryption(array $user, string $current): string
{
    vault_require();
    if ((bool)$user['encrypted']) {
        throw new InvalidArgumentException('Deník už je šifrovaný.');
    }
    if (!verify_user_secret($user, $current)) {
        json_response(['error' => 'Současné heslo nesedí.'], 403);
    }
    $accessKey = generate_access_key();
    $dataKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $salt = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
    $wrapped = vault_seal($dataKey, access_key_kek($accessKey, $salt), 'dek|user:' . (int)$user['id']);
    seal_user_storage((int)$user['id'], $dataKey);
    $statement = app_db()->prepare('UPDATE users SET encrypted = 1, secret_hash = ?, kdf_salt = ?, wrapped_key = ?, must_change_secret = 0 WHERE id = ?');
    $statement->bindValue(1, password_hash(normalize_access_key($accessKey), PASSWORD_DEFAULT));
    $statement->bindValue(2, $salt, PDO::PARAM_LOB);
    $statement->bindValue(3, $wrapped, PDO::PARAM_LOB);
    $statement->bindValue(4, (int)$user['id'], PDO::PARAM_INT);
    $statement->execute();
    revoke_sessions((int)$user['id']);
    start_session(find_user((int)$user['id']), $dataKey);
    return $accessKey;
}

/** Vydá nový přístupový klíč. Data se nepřešifrovávají, jen se znovu zabalí datový klíč. */
function rotate_access_key(array $user, string $currentKey): string
{
    if (!(bool)$user['encrypted']) {
        throw new InvalidArgumentException('Účet nemá šifrování.');
    }
    if (!verify_user_secret($user, $currentKey)) {
        json_response(['error' => 'Současný přístupový klíč nesedí.'], 403);
    }
    $dataKey = unwrap_user_key($user, $currentKey);
    $accessKey = generate_access_key();
    $salt = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
    $wrapped = vault_seal($dataKey, access_key_kek($accessKey, $salt), 'dek|user:' . (int)$user['id']);
    $statement = app_db()->prepare('UPDATE users SET secret_hash = ?, kdf_salt = ?, wrapped_key = ? WHERE id = ?');
    $statement->bindValue(1, password_hash(normalize_access_key($accessKey), PASSWORD_DEFAULT));
    $statement->bindValue(2, $salt, PDO::PARAM_LOB);
    $statement->bindValue(3, $wrapped, PDO::PARAM_LOB);
    $statement->bindValue(4, (int)$user['id'], PDO::PARAM_INT);
    $statement->execute();
    revoke_sessions((int)$user['id'], current_session_id());
    return $accessKey;
}

/* ---------------------------------------------------------------- správa */

function admin_users(): array
{
    $rows = app_fetch_all(<<<'SQL'
SELECT u.*,
       (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS posts,
       (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comments,
       (SELECT MAX(s.last_seen_at) FROM sessions s WHERE s.user_id = u.id) AS last_seen_at,
       a.display_name AS approved_by_name
FROM users u
LEFT JOIN users a ON a.id = u.approved_by
ORDER BY CASE u.status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, u.created_at DESC
SQL);
    return array_map(static fn(array $row): array => public_user($row) + [
        'approved_at' => $row['approved_at'],
        'approved_by' => $row['approved_by_name'],
        'last_login_at' => $row['last_login_at'],
        'last_seen_at' => $row['last_seen_at'],
        'posts' => (int)$row['posts'],
        'comments' => (int)$row['comments'],
        'storage_bytes' => directory_size(user_storage_dir((int)$row['id'])),
    ], $rows);
}

function active_admin_count(): int
{
    return (int)(app_fetch_one("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND status = 'active'")['total'] ?? 0);
}

/** Jedna správcovská operace nad účtem. Vrací případné dočasné heslo. */
function admin_user_action(array $admin, int $userId, string $operation): array
{
    $target = find_user($userId);
    if ($target === null) {
        throw new InvalidArgumentException('Uživatel neexistuje.');
    }
    $self = (int)$target['id'] === (int)$admin['id'];
    $lastAdmin = is_admin($target) && active_admin_count() <= 1;
    $result = ['ok' => true];

    switch ($operation) {
        case 'approve':
            if ($target['status'] !== 'pending') {
                throw new InvalidArgumentException('Tahle registrace už je vyřízená.');
            }
            app_execute("UPDATE users SET status = 'active', approved_at = ?, approved_by = ? WHERE id = ?", [utc_now(), (int)$admin['id'], $userId]);
            break;
        case 'reject':
            if ($target['status'] !== 'pending') {
                throw new InvalidArgumentException('Zamítnout jde jen čekající registraci.');
            }
            purge_user($userId);
            break;
        case 'block':
            if ($self || $lastAdmin) {
                throw new InvalidArgumentException($self ? 'Sám sebe zablokovat nemůžeš.' : 'Posledního správce nejde zablokovat.');
            }
            app_execute("UPDATE users SET status = 'blocked' WHERE id = ?", [$userId]);
            revoke_sessions($userId);
            break;
        case 'unblock':
            app_execute("UPDATE users SET status = 'active' WHERE id = ? AND status = 'blocked'", [$userId]);
            break;
        case 'promote':
            if ($target['status'] !== 'active') {
                throw new InvalidArgumentException('Správcem může být jen aktivní účet.');
            }
            app_execute("UPDATE users SET role = 'admin' WHERE id = ?", [$userId]);
            break;
        case 'demote':
            if ($lastAdmin) {
                throw new InvalidArgumentException('Aplikace musí mít aspoň jednoho správce.');
            }
            app_execute("UPDATE users SET role = 'member' WHERE id = ?", [$userId]);
            break;
        case 'reset_password':
            if ((bool)$target['encrypted']) {
                throw new InvalidArgumentException('Šifrovanému účtu nejde heslo obnovit: přístupový klíč zná jen jeho majitel a bez něj data nikdo neotevře.');
            }
            $temporary = implode('-', str_split(substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 15), 5));
            app_execute('UPDATE users SET secret_hash = ?, must_change_secret = 1 WHERE id = ?', [password_hash($temporary, PASSWORD_DEFAULT), $userId]);
            revoke_sessions($userId);
            $result['temporary_password'] = $temporary;
            break;
        case 'delete':
            if ($self || $lastAdmin) {
                throw new InvalidArgumentException($self ? 'Vlastní účet tady smazat nemůžeš.' : 'Posledního správce nejde smazat.');
            }
            purge_user($userId);
            break;
        default:
            throw new InvalidArgumentException('Neznámá operace.');
    }
    return $result;
}
