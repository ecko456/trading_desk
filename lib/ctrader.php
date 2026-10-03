<?php
declare(strict_types=1);

/*
 * cTrader Open API (Spotware), jen pro čtení.
 *
 * Správce zaregistruje aplikaci na openapi.ctrader.com a do Správy vloží Client ID, Secret
 * a adresu pro návrat (ctrader.php). Každý člen pak své účty napojí přihlášením přes
 * cTrader ID s oprávněním „accounts“: aplikace vidí zůstatek, pozice a historii, ale
 * obchodovat neumí. Přístupové klíče členů leží v jejich deníku (u šifrovaného deníku
 * zašifrované), Secret aplikace v app.sqlite3 v datovém adresáři.
 *
 * Server s cTraderem mluví přes WebSocket s JSON zprávami (port 5036). Zprávy a jejich
 * čísla odpovídají oficiálním proto souborům spotware/openapi-proto-messages.
 * Proměnné prostředí TRADING_CTRADER_* přesměrují spojení jinam (jen pro testy).
 */

const CT_AUTH_URL = 'https://id.ctrader.com/my/settings/openapi/grantingaccess/';
const CT_TOKEN_URL = 'https://openapi.ctrader.com/apps/token';
const CT_HOSTS = ['live' => 'live.ctraderapi.com', 'demo' => 'demo.ctraderapi.com'];
const CT_PORT = 5036;
const CT_SCOPE = 'accounts';
const CT_TIMEOUT = 20.0;
const CT_MAX_MESSAGE = 32 * 1024 * 1024;
/** Platnost přístupu je 30 dní; obnovuje se s rezervou. */
const CT_REFRESH_BEFORE = 5 * 86400;
/** Historické dotazy smí být nejvýš 5 za vteřinu na spojení. */
const CT_HISTORY_PAUSE_US = 220000;

const CT_COMMON_ERROR = 50;
const CT_HEARTBEAT = 51;
const CT_APP_AUTH_REQ = 2100;
const CT_APP_AUTH_RES = 2101;
const CT_ACCOUNT_AUTH_REQ = 2102;
const CT_ACCOUNT_AUTH_RES = 2103;
const CT_ASSET_LIST_REQ = 2112;
const CT_ASSET_LIST_RES = 2113;
const CT_SYMBOLS_LIST_REQ = 2114;
const CT_SYMBOLS_LIST_RES = 2115;
const CT_SYMBOL_BY_ID_REQ = 2116;
const CT_SYMBOL_BY_ID_RES = 2117;
const CT_TRADER_REQ = 2121;
const CT_TRADER_RES = 2122;
const CT_RECONCILE_REQ = 2124;
const CT_RECONCILE_RES = 2125;
const CT_DEAL_LIST_REQ = 2133;
const CT_DEAL_LIST_RES = 2134;
const CT_ERROR_RES = 2142;
const CT_CASH_FLOW_REQ = 2143;
const CT_CASH_FLOW_RES = 2144;
const CT_ACCOUNTS_BY_TOKEN_REQ = 2149;
const CT_ACCOUNTS_BY_TOKEN_RES = 2150;
const CT_ORDER_LIST_REQ = 2175;
const CT_ORDER_LIST_RES = 2176;
const CT_DEALS_BY_POSITION_REQ = 2179;
const CT_DEALS_BY_POSITION_RES = 2180;
const CT_UNREALIZED_PNL_REQ = 2187;
const CT_UNREALIZED_PNL_RES = 2188;

/** Pohyby zůstatku, které ho snižují (výběry, poplatky); ostatní ho zvyšují. */
const CT_WITHDRAW_TYPES = [1, 4, 6, 10, 12, 13, 14, 16, 17, 18, 20, 22, 28, 30, 32, 34, 35, 37];

final class CtraderError extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = '', public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}

/* ---------------------------------------------------------------- nastavení aplikace */

function ctrader_settings(): array
{
    return [
        'client_id' => setting('ctrader_client_id'),
        'client_secret' => setting('ctrader_client_secret'),
        'redirect_uri' => setting('ctrader_redirect_uri'),
    ];
}

function ctrader_configured(): bool
{
    $settings = ctrader_settings();
    return $settings['client_id'] !== '' && $settings['client_secret'] !== '' && $settings['redirect_uri'] !== '';
}

/** Adresa pro návrat, kterou správce zapíše do aplikace na openapi.ctrader.com. */
function ctrader_suggested_redirect_uri(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return (request_is_https() ? 'https' : 'http') . '://' . $host . app_path() . 'ctrader.php';
}

function ctrader_admin_state(): array
{
    $settings = ctrader_settings();
    return [
        'configured' => ctrader_configured(),
        'client_id' => $settings['client_id'],
        'has_secret' => $settings['client_secret'] !== '',
        'redirect_uri' => $settings['redirect_uri'] !== '' ? $settings['redirect_uri'] : ctrader_suggested_redirect_uri(),
        'suggested_redirect_uri' => ctrader_suggested_redirect_uri(),
    ];
}

function ctrader_save_settings(array $data): array
{
    if (!empty($data['clear'])) {
        foreach (['ctrader_client_id', 'ctrader_client_secret', 'ctrader_redirect_uri'] as $key) {
            save_setting($key, '');
        }
        return ctrader_admin_state();
    }
    $clientId = trim((string)($data['client_id'] ?? ''));
    $secret = trim((string)($data['client_secret'] ?? ''));
    $redirect = trim((string)($data['redirect_uri'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.\-]{4,200}$/', $clientId)) {
        json_response(['error' => 'Client ID zkopíruj z aplikace na openapi.ctrader.com (písmena, číslice, podtržítka).'], 422);
    }
    if ($secret !== '' && !preg_match('/^[A-Za-z0-9_.\-]{4,200}$/', $secret)) {
        json_response(['error' => 'Secret zkopíruj z aplikace na openapi.ctrader.com celý a bez mezer.'], 422);
    }
    if ($secret === '' && setting('ctrader_client_secret') === '') {
        json_response(['error' => 'Vlož i Secret aplikace.'], 422);
    }
    $scheme = strtolower((string)parse_url($redirect, PHP_URL_SCHEME));
    $host = strtolower((string)parse_url($redirect, PHP_URL_HOST));
    $local = in_array($host, ['localhost', '127.0.0.1'], true);
    if (!filter_var($redirect, FILTER_VALIDATE_URL) || !($scheme === 'https' || ($scheme === 'http' && $local)) || !str_ends_with((string)parse_url($redirect, PHP_URL_PATH), '/ctrader.php')) {
        json_response(['error' => 'Adresa pro návrat musí být https://…/ctrader.php tvého Trading Desku.'], 422);
    }
    save_setting('ctrader_client_id', $clientId);
    if ($secret !== '') {
        save_setting('ctrader_client_secret', $secret);
    }
    save_setting('ctrader_redirect_uri', $redirect);
    return ctrader_admin_state();
}

/* ---------------------------------------------------------------- adresy a OAuth */

function ctrader_endpoint(string $kind): string
{
    $override = match ($kind) {
        'auth' => getenv('TRADING_CTRADER_AUTH_URL'),
        'token' => getenv('TRADING_CTRADER_TOKEN_URL'),
        default => getenv('TRADING_CTRADER_SOCKET'),
    };
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return match ($kind) {
        'auth' => CT_AUTH_URL,
        'token' => CT_TOKEN_URL,
        'live' => 'tls://' . CT_HOSTS['live'] . ':' . CT_PORT,
        default => 'tls://' . CT_HOSTS['demo'] . ':' . CT_PORT,
    };
}

function ctrader_authorize_url(string $state): string
{
    $settings = ctrader_settings();
    // cTrader vrátí state beze změny; podle něj se ověří, že návrat patří k tomuto přihlášení.
    return ctrader_endpoint('auth') . '?' . http_build_query([
        'client_id' => $settings['client_id'],
        'redirect_uri' => $settings['redirect_uri'],
        'scope' => CT_SCOPE,
        'product' => 'web',
        'state' => $state,
    ]);
}

function ctrader_exchange_code(string $code): array
{
    $settings = ctrader_settings();
    return ctrader_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $settings['redirect_uri'],
        'client_id' => $settings['client_id'],
        'client_secret' => $settings['client_secret'],
    ]);
}

function ctrader_refresh_token(string $refreshToken): array
{
    $settings = ctrader_settings();
    return ctrader_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $settings['client_id'],
        'client_secret' => $settings['client_secret'],
    ]);
}

function ctrader_token_request(array $query): array
{
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => CT_TIMEOUT, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents(ctrader_endpoint('token') . '?' . http_build_query($query), false, $context);
    if ($body === false) {
        throw new CtraderError('cTrader teď neodpovídá. Zkus to za chvíli.', 'NETWORK');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new CtraderError('cTrader vrátil nečitelnou odpověď.', 'BAD_RESPONSE');
    }
    $access = $data['accessToken'] ?? $data['access_token'] ?? null;
    $refresh = $data['refreshToken'] ?? $data['refresh_token'] ?? null;
    if (!is_string($access) || $access === '' || !is_string($refresh) || $refresh === '') {
        $code = (string)($data['errorCode'] ?? $data['error'] ?? 'TOKEN_ERROR');
        throw new CtraderError(ctrader_error_text($code, (string)($data['description'] ?? $data['error_description'] ?? '')), $code);
    }
    $expires = (int)($data['expiresIn'] ?? $data['expires_in'] ?? 2628000);
    return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_at' => time() + max(60, $expires)];
}

function ctrader_error_text(string $code, string $description = ''): string
{
    return match ($code) {
        'CH_CLIENT_AUTH_FAILURE', 'CH_CLIENT_NOT_AUTHENTICATED', 'invalid_client', 'INVALID_CLIENT' =>
            'Client ID nebo Secret aplikace cTrader nesedí, nebo ji Spotware ještě neschválil. Zkontroluj nastavení ve Správě.',
        'CH_ACCESS_TOKEN_INVALID', 'OA_AUTH_TOKEN_EXPIRED', 'ACCESS_DENIED', 'invalid_grant', 'INVALID_GRANT' =>
            'Přístup k cTraderu vypršel nebo byl zrušen. Napoj účet znovu tlačítkem Napojit cTrader.',
        'ACCOUNT_NOT_AUTHORIZED' => 'cTrader tenhle účet pro aplikaci nepovolil. Napoj ho znovu a účet při přihlášení zaškrtni.',
        'BLOCKED_PAYLOAD_TYPE' => 'cTrader dočasně omezil počet dotazů. Zkus synchronizaci za minutu.',
        'CONNECTIONS_LIMIT_EXCEEDED' => 'cTrader má teď otevřeno moc spojení z této aplikace. Zkus to za chvíli.',
        'CHANNEL_IS_BLOCKED' => 'Broker pro tenhle účet přístup přes API zablokoval.',
        'SERVER_IS_UNDER_MAINTENANCE' => 'cTrader má údržbu. Zkus to později.',
        default => 'cTrader vrátil chybu ' . $code . ($description !== '' ? ': ' . $description : '') . '.',
    };
}

/* ---------------------------------------------------------------- převody hodnot */

/** Celé číslo z JSON (int64 může přijít i jako text). */
function ct_int(mixed $value): int
{
    return is_numeric($value) ? (int)$value : 0;
}

/** Peníze jsou v celých jednotkách krát 10^moneyDigits. */
function ct_money(mixed $value, mixed $digits, int $default = 2): float
{
    $exponent = is_numeric($digits) ? (int)$digits : $default;
    return (is_numeric($value) ? (float)$value : 0.0) / (10 ** max(0, min(12, $exponent)));
}

/** BUY / SELL přijde jako číslo (1, 2) nebo jako název. */
function ct_side(mixed $value): string
{
    return in_array($value, [2, '2', 'SELL'], true) ? 'short' : 'long';
}

function ct_deal_filled(array $deal): bool
{
    $status = $deal['dealStatus'] ?? 2;
    return in_array($status, [2, 3, '2', '3', 'FILLED', 'PARTIALLY_FILLED'], true);
}

function ct_cash_delta(array $operation): float
{
    $type = $operation['operationType'] ?? 0;
    $withdraw = in_array(is_numeric($type) ? (int)$type : -1, CT_WITHDRAW_TYPES, true)
        || (is_string($type) && str_contains($type, 'WITHDRAW'));
    $amount = abs(ct_money($operation['delta'] ?? 0, $operation['moneyDigits'] ?? null));
    return $withdraw ? -$amount : $amount;
}

/* ---------------------------------------------------------------- WebSocket */

/** Minimální klient WebSocketu (RFC 6455) pro JSON zprávy cTraderu. */
final class CtraderSocket
{
    /** @var resource */
    private $stream;
    private string $buffer = '';
    private int $counter = 0;

    public function __construct(string $target, private readonly float $timeout = CT_TIMEOUT)
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client($target, $errorNumber, $errorText, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new CtraderError('Nepodařilo se připojit k cTraderu. Zkus to za chvíli.', 'NETWORK');
        }
        $this->stream = $stream;
        $this->handshake($target);
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, "\x88\x80" . random_bytes(4));
            @fclose($this->stream);
        }
    }

    private function handshake(string $target): void
    {
        $host = (string)parse_url($target, PHP_URL_HOST);
        $port = (int)parse_url($target, PHP_URL_PORT);
        $key = base64_encode(random_bytes(16));
        $this->write("GET / HTTP/1.1\r\nHost: $host:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $deadline = microtime(true) + $this->timeout;
        while (!str_contains($this->buffer, "\r\n\r\n")) {
            if (strlen($this->buffer) > 16384) {
                throw new CtraderError('cTrader odpověděl neplatnou hlavičkou.', 'HANDSHAKE');
            }
            $this->fill($deadline);
        }
        [$head, $rest] = explode("\r\n\r\n", $this->buffer, 2);
        $this->buffer = $rest;
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('#^HTTP/1\.[01] 101#', $head) || !preg_match('/^Sec-WebSocket-Accept:\s*' . preg_quote($accept, '/') . '\s*$/mi', $head)) {
            throw new CtraderError('cTrader odmítl spojení.', 'HANDSHAKE');
        }
    }

    /** Pošle požadavek a počká na odpověď se stejným clientMsgId; události mezitím přeskočí. */
    public function request(int $type, array $payload, int $expect): array
    {
        $id = 'td' . (++$this->counter);
        $json = json_encode(['clientMsgId' => $id, 'payloadType' => $type, 'payload' => (object)$payload], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->sendFrame(0x1, $json);
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $message = $this->receive($deadline);
            $messageType = ct_int($message['payloadType'] ?? 0);
            $messageId = $message['clientMsgId'] ?? null;
            if ($messageType === CT_HEARTBEAT) {
                continue;
            }
            if ($messageType === CT_ERROR_RES || $messageType === CT_COMMON_ERROR) {
                if ($messageId === null || $messageId === $id) {
                    $error = is_array($message['payload'] ?? null) ? $message['payload'] : [];
                    $code = (string)($error['errorCode'] ?? 'ERROR');
                    throw new CtraderError(ctrader_error_text($code, (string)($error['description'] ?? '')), $code, ct_int($error['retryAfter'] ?? 0));
                }
                continue;
            }
            if ($messageId !== $id) {
                continue;
            }
            if ($messageType !== $expect) {
                throw new CtraderError('cTrader odpověděl neočekávanou zprávou (' . $messageType . ').', 'UNEXPECTED');
            }
            return is_array($message['payload'] ?? null) ? $message['payload'] : [];
        }
    }

    private function sendFrame(int $opcode, string $data): void
    {
        $length = strlen($data);
        $head = chr(0x80 | $opcode);
        if ($length < 126) {
            $head .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $head .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $head .= chr(0x80 | 127) . pack('J', $length);
        }
        // Rámce od klienta musí být maskované.
        $mask = random_bytes(4);
        $this->write($head . $mask . ($data ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length)));
    }

    private function receive(float $deadline): array
    {
        $message = '';
        while (true) {
            $head = $this->read(2, $deadline);
            $final = (ord($head[0]) & 0x80) !== 0;
            $opcode = ord($head[0]) & 0x0F;
            $masked = (ord($head[1]) & 0x80) !== 0;
            $length = ord($head[1]) & 0x7F;
            if ($length === 126) {
                $length = (int)unpack('n', $this->read(2, $deadline))[1];
            } elseif ($length === 127) {
                $length = (int)unpack('J', $this->read(8, $deadline))[1];
            }
            if ($length < 0 || $length + strlen($message) > CT_MAX_MESSAGE) {
                throw new CtraderError('Odpověď cTraderu je příliš velká.', 'TOO_LARGE');
            }
            $mask = $masked ? $this->read(4, $deadline) : '';
            $data = $length > 0 ? $this->read($length, $deadline) : '';
            if ($masked) {
                $data ^= substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
            }
            if ($opcode === 0x8) {
                throw new CtraderError('cTrader ukončil spojení. Zkus to za chvíli.', 'CLOSED');
            }
            if ($opcode === 0x9) {
                $this->sendFrame(0xA, $data);
                continue;
            }
            if ($opcode === 0xA) {
                continue;
            }
            $message .= $data;
            if ($final) {
                break;
            }
        }
        $decoded = json_decode($message, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($decoded)) {
            throw new CtraderError('cTrader poslal nečitelnou zprávu.', 'BAD_RESPONSE');
        }
        return $decoded;
    }

    private function read(int $bytes, float $deadline): string
    {
        while (strlen($this->buffer) < $bytes) {
            $this->fill($deadline);
        }
        $chunk = substr($this->buffer, 0, $bytes);
        $this->buffer = (string)substr($this->buffer, $bytes);
        return $chunk;
    }

    private function fill(float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new CtraderError('cTrader neodpověděl včas. Zkus to za chvíli.', 'TIMEOUT');
        }
        stream_set_timeout($this->stream, (int)floor($remaining), (int)(($remaining - floor($remaining)) * 1000000));
        $chunk = fread($this->stream, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->stream))) {
            throw new CtraderError('Spojení s cTraderem se přerušilo.', 'CLOSED');
        }
        if ($chunk === '' && (stream_get_meta_data($this->stream)['timed_out'] ?? false)) {
            throw new CtraderError('cTrader neodpověděl včas. Zkus to za chvíli.', 'TIMEOUT');
        }
        $this->buffer .= $chunk;
    }

    private function write(string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($this->stream, $data);
            if ($written === false || $written === 0) {
                throw new CtraderError('Spojení s cTraderem se přerušilo.', 'CLOSED');
            }
            $data = substr($data, $written);
        }
    }
}

/* ---------------------------------------------------------------- dotazy */

/** Spojení na live a demo server s přihlášenou aplikací a účty. */
final class CtraderApi
{
    /** @var array<string, CtraderSocket> */
    private array $sockets = [];
    /** @var array<string, bool> */
    private array $authorized = [];
    private float $lastHistory = 0.0;

    public function socket(bool $live): CtraderSocket
    {
        $kind = $live ? 'live' : 'demo';
        if (!isset($this->sockets[$kind])) {
            $settings = ctrader_settings();
            $socket = new CtraderSocket(ctrader_endpoint($kind));
            $socket->request(CT_APP_AUTH_REQ, ['clientId' => $settings['client_id'], 'clientSecret' => $settings['client_secret']], CT_APP_AUTH_RES);
            $this->sockets[$kind] = $socket;
        }
        return $this->sockets[$kind];
    }

    /** Účty, ke kterým přístupový klíč dává přístup. */
    public function accounts(string $accessToken): array
    {
        $result = $this->socket(true)->request(CT_ACCOUNTS_BY_TOKEN_REQ, ['accessToken' => $accessToken], CT_ACCOUNTS_BY_TOKEN_RES);
        $accounts = [];
        foreach ((array)($result['ctidTraderAccount'] ?? []) as $row) {
            if (!is_array($row) || ct_int($row['ctidTraderAccountId'] ?? 0) <= 0) {
                continue;
            }
            $accounts[] = [
                'external_id' => (string)ct_int($row['ctidTraderAccountId']),
                'is_live' => !empty($row['isLive']),
                'login' => isset($row['traderLogin']) ? (string)ct_int($row['traderLogin']) : '',
                'broker_name' => mb_substr(trim((string)($row['brokerTitleShort'] ?? '')), 0, 80, 'UTF-8'),
            ];
        }
        return $accounts;
    }

    public function authorize(bool $live, string $accountId, string $accessToken): void
    {
        if (isset($this->authorized[$accountId])) {
            return;
        }
        $this->socket($live)->request(CT_ACCOUNT_AUTH_REQ, ['ctidTraderAccountId' => (int)$accountId, 'accessToken' => $accessToken], CT_ACCOUNT_AUTH_RES);
        $this->authorized[$accountId] = true;
    }

    public function call(bool $live, int $type, array $payload, int $expect): array
    {
        return $this->socket($live)->request($type, $payload, $expect);
    }

    /** Historický dotaz s brzdou kvůli limitu cTraderu; při zablokování jednou počká. */
    public function history(bool $live, int $type, array $payload, int $expect): array
    {
        $wait = CT_HISTORY_PAUSE_US - (int)((microtime(true) - $this->lastHistory) * 1000000);
        if ($wait > 0) {
            usleep($wait);
        }
        try {
            return $this->call($live, $type, $payload, $expect);
        } catch (CtraderError $error) {
            if ($error->errorCode !== 'BLOCKED_PAYLOAD_TYPE' || $error->retryAfter > 10) {
                throw $error;
            }
            sleep(max(1, $error->retryAfter));
            return $this->call($live, $type, $payload, $expect);
        } finally {
            $this->lastHistory = microtime(true);
        }
    }
}
