<?php
declare(strict_types=1);

// Router pro vestavěný PHP server na Windows a macOS.
// Apache si pravidla bere z .htaccess, vestavěný server ne, takže neveřejné
// adresáře musíme zavřít tady. Na Ubuntu se tenhle soubor nepoužívá.

$path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));

if (preg_match('#(^|/)(data|tests|deploy|lib|bin)(/|$)#i', $path) || preg_match('#(^|/)\.#', $path)
    || preg_match('#\.(md|txt|zip|py|sh|bat|command|ini|json|sqlite3|sealed|lock|log|bak)$#i', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nenalezeno.';
    return true;
}

return false;
