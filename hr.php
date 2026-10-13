<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Příjem tepu ze Zkratky v iPhonu (Apple Watch). Bez přihlášení: přístup dává jen tajný token
// v odkazu (Psychika → Tep a obchody → Apple Watch). Vzorky se zapečetí veřejným klíčem člena
// a zařadí se do deníku, až ho člen otevře. Odpověď je krátký text pro oznámení ve Zkratce.

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Content-Type: text/plain; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo "Tep se sem posílá metodou POST ze Zkratky. Návod je v Trading Desku: Psychika, Tep a obchody.\n";
    exit;
}

try {
    // Zkratka posílá tělo jako soubor nebo text; formulář s polem „data“ funguje taky.
    $body = isset($_POST['data']) && is_string($_POST['data'])
        ? $_POST['data']
        : (string)file_get_contents('php://input', false, null, 0, HEART_MAX_UPLOAD_BYTES + 1);
    if (strlen($body) > HEART_MAX_UPLOAD_BYTES) {
        http_response_code(413);
        echo "Dat je víc než 2 MB. Ve Zkratce zkrať období (třeba posledních 6 hodin).\n";
        exit;
    }
    [$status, $message] = heart_inbox_receive((string)($_GET['t'] ?? ''), $body);
    http_response_code($status);
    echo $message, "\n";
} catch (Throwable $error) {
    error_log('Trading Desk hr.php: ' . $error->getMessage());
    http_response_code(500);
    echo "Server tep nepřijal. Zkus to prosím znovu.\n";
}
