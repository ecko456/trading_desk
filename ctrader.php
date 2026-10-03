<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/*
 * Napojení účtů cTraderu (OAuth).
 *
 * ?start=1  přesměruje na přihlášení cTrader ID; do krátce platné cookie si uloží náhodný
 *           kód svázaný s přihlášeným členem.
 * návrat    cTrader vrátí ?code=…; kód se vymění za přístupový klíč a účty se uloží do
 *           deníku člena. Bez cookie z vlastního startu (nebo s jiným state) se návrat
 *           odmítne, takže cizí stránka nemůže členovi podstrčit cizí účet.
 */

const CTRADER_STATE_COOKIE = 'td_ctrader';

function ctrader_back(string $result, array $extra = []): never
{
    try {
        journal_flush();
    } catch (Throwable $error) {
        error_log('cTrader: deník se nepodařilo uložit: ' . $error->getMessage());
        [$result, $extra] = ['error', ['code' => 'SAVE']];
    }
    header('Cache-Control: no-store');
    header('Location: ./?' . http_build_query(['ctrader' => $result] + $extra));
    exit;
}

function ctrader_state_cookie(string $value, int $expires): void
{
    setcookie(CTRADER_STATE_COOKIE, $value, [
        'expires' => $expires,
        'path' => app_path(),
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

security_headers();
$user = current_user();
if ($user === null) {
    header('Location: ./');
    exit;
}
if (!ctrader_configured()) {
    ctrader_back('off');
}

if (isset($_GET['start'])) {
    $state = bin2hex(random_bytes(24));
    ctrader_state_cookie($state . '.' . (int)$user['id'], time() + 900);
    header('Cache-Control: no-store');
    header('Location: ' . ctrader_authorize_url($state));
    exit;
}

$cookie = (string)($_COOKIE[CTRADER_STATE_COOKIE] ?? '');
ctrader_state_cookie('', time() - 3600);
[$expected, $owner] = array_pad(explode('.', $cookie, 2), 2, '');
$returned = (string)($_GET['state'] ?? '');
// cTrader state v dokumentaci nezmiňuje; když ho vrátí, musí sedět.
if ($expected === '' || (int)$owner !== (int)$user['id'] || ($returned !== '' && !hash_equals($expected, $returned))) {
    ctrader_back('state');
}
$code = (string)($_GET['code'] ?? '');
if ($code === '' || isset($_GET['error'])) {
    ctrader_back('denied');
}
if (!preg_match('/^[A-Za-z0-9_.\-~]{4,512}$/', $code)) {
    ctrader_back('error');
}

try {
    $token = ctrader_exchange_code($code);
    $accounts = (new CtraderApi())->accounts($token['access_token']);
    if ($accounts === []) {
        ctrader_back('empty');
    }
    $count = broker_save_connection($token, $accounts);
    ctrader_back('connected', ['n' => $count]);
} catch (CtraderError $error) {
    error_log('cTrader: ' . $error->errorCode . ' ' . $error->getMessage());
    ctrader_back('error', ['code' => substr(preg_replace('/[^A-Z_a-z]/', '', $error->errorCode) ?? '', 0, 40)]);
}
