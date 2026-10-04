<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Zóny pro indikátor v ATAS. Bez přihlášení: přístup dává jen tajný token v odkazu,
// který si člen zkopíroval v náhledu (Sdílet → Odkaz pro ATAS). Vrací snímek zón
// posledního denního náhledu trhu jako text (výchozí) nebo JSON (?format=json).

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$snapshot = atas_feed_by_token((string)($_GET['t'] ?? ''));
if ($snapshot === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "# Odkaz neplatí. Vytvoř nový v Trading Desku: náhled, Sdílet, Odkaz pro ATAS.\n";
    exit;
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo atas_feed_text($snapshot);
