<?php
declare(strict_types=1);

// Vypíše jednorázový kód pro založení prvního správce.
// Spouští se na serveru pod stejným uživatelem jako web, např.:
//   sudo -u www-data TRADING_DATA_DIR=/var/lib/trading-journal php bin/setup-token.php

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

if (!setup_required()) {
    fwrite(STDOUT, "Správce už existuje, kód není potřeba.\n");
    exit(0);
}
fwrite(STDOUT, setup_token() . "\n");
