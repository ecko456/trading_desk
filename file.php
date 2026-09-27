<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
$screenshot = $id !== '' ? fetch_one('SELECT * FROM screenshots WHERE id = ?', [$id]) : null;
if ($screenshot === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Screenshot nebyl nalezen.';
    exit;
}

$path = upload_dir() . DIRECTORY_SEPARATOR . basename((string)$screenshot['file_name']);
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Soubor screenshotu chybí.';
    exit;
}

header('Content-Type: ' . $screenshot['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string)$screenshot['original_name']) . '"');
header('Cache-Control: private, max-age=86400');
readfile($path);
