<?php
declare(strict_types=1);

// Vypíše kód pro první spuštění (jen dokud neexistuje žádná kartička).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/lib/odmeny.php';

if (!odm_setup_required()) {
    fwrite(STDOUT, "Aplikace už je nastavená. Přihlas se kartičkou.\n");
    exit(0);
}
fwrite(STDOUT, odm_setup_token() . "\n");
