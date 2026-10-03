<?php
declare(strict_types=1);

// Kdo smí nahrávat a mazat svíčky v Hindsightu (správce dat grafu). Bez parametru
// vypíše současného, s přihlašovacím jménem nastaví nového. Spouští se na serveru
// pod stejným uživatelem jako web, např.:
//   sudo -u www-data TRADING_DATA_DIR=/var/lib/trading-journal php /var/www/trading-journal/bin/hindsight-keeper.php
//   sudo -u www-data TRADING_DATA_DIR=/var/lib/trading-journal php /var/www/trading-journal/bin/hindsight-keeper.php martin

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/lib/vault.php';
require dirname(__DIR__) . '/lib/accounts.php';

function utc_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

$login = trim((string)($argv[1] ?? ''));
try {
    if ($login !== '') {
        $user = set_market_data_keeper($login);
        fwrite(STDOUT, "Svíčky v Hindsightu teď nahrává jen {$user['display_name']} ({$user['login']}).\n");
        exit(0);
    }
    $keeper = market_data_keeper();
    if ($keeper === null) {
        fwrite(STDOUT, "Správce dat grafu zatím není, aplikace nemá žádného správce.\n");
    } elseif (!is_admin($keeper)) {
        fwrite(STDOUT, "Správce dat grafu {$keeper['display_name']} ({$keeper['login']}) už není aktivní správce, svíčky teď nenahrává nikdo.\n");
    } else {
        fwrite(STDOUT, "Svíčky v Hindsightu nahrává jen {$keeper['display_name']} ({$keeper['login']}).\n");
    }
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
