<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Zóny pro indikátor v ATAS. Bez přihlášení: přístup dává jen tajný token v odkazu,
// který si člen zkopíroval v náhledu (Sdílet → Odkaz pro ATAS). Vrací snímek zón
// posledního denního náhledu trhu jako text (výchozí) nebo JSON (?format=json).
// POST na stejný odkaz přijme nástřel náhledu z indikátoru (lib/atas-draft.php).

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $body = $length > ATAS_DRAFT_MAX_BYTES ? '' : (string)file_get_contents('php://input', false, null, 0, ATAS_DRAFT_MAX_BYTES + 1);
    if ($length > ATAS_DRAFT_MAX_BYTES || strlen($body) > ATAS_DRAFT_MAX_BYTES) {
        http_response_code(413);
        echo json_encode(['error' => 'Nástřel je moc velký.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $result = atas_draft_receive((string)($_GET['t'] ?? ''), $body);
        if ($result === null) {
            http_response_code(404);
            $result = ['error' => 'Odkaz neplatí. Vytvoř nový v Trading Desku: náhled, Sdílet, Odkaz pro ATAS.'];
        }
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        $result = ['error' => $error->getMessage()];
    } catch (RuntimeException $error) {
        http_response_code(429);
        $result = ['error' => $error->getMessage()];
    } catch (Throwable $error) {
        error_log('Trading Desk: nástřel z ATAS se neuložil: ' . $error->getMessage());
        http_response_code(500);
        $result = ['error' => 'Server nástřel neuložil.'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
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
