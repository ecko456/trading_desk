<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function file_not_found(string $message, int $status = 404): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function send_image(string $contents, string $mime, string $name, string $cache): never
{
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($contents));
    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
    header('Cache-Control: ' . $cache);
    echo $contents;
    exit;
}

if (current_user() === null) {
    file_not_found('Nejdřív se přihlas.', 401);
}

// Obrázek z nástěnky vidí každý přihlášený člen.
$media = preg_replace('/[^a-f0-9]/', '', (string)($_GET['media'] ?? ''));
if ($media !== '') {
    $row = app_fetch_one('SELECT * FROM post_media WHERE id = ?', [$media]);
    $path = $row === null ? '' : wall_media_dir() . DIRECTORY_SEPARATOR . basename((string)$row['file_name']);
    if ($row === null || !is_file($path)) {
        file_not_found('Obrázek nebyl nalezen.');
    }
    send_image((string)file_get_contents($path), (string)$row['mime_type'], (string)$row['file_name'], 'private, max-age=86400');
}

// Screenshot z vlastního deníku; cizí deník se sem vůbec neotevře.
$id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
$screenshot = $id !== '' ? fetch_one('SELECT * FROM screenshots WHERE id = ?', [$id]) : null;
if ($screenshot === null) {
    file_not_found('Screenshot nebyl nalezen.');
}
$contents = current_journal()->readUpload((string)$screenshot['file_name']);
if ($contents === null) {
    file_not_found('Soubor screenshotu chybí.');
}
send_image($contents, (string)$screenshot['mime_type'], (string)$screenshot['original_name'], current_journal()->encrypted() ? 'private, no-store' : 'private, max-age=86400');
