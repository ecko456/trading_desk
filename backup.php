<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Na serveru chybí PHP rozšíření zip.');
    }
    ensure_storage();
    $stamp = gmdate('Ymd-His');
    $temporaryDb = data_dir() . DIRECTORY_SEPARATOR . 'backup-' . $stamp . '.sqlite3';
    $pdo = db();
    $pdo->exec('VACUUM INTO ' . $pdo->quote($temporaryDb));

    $temporaryZip = data_dir() . DIRECTORY_SEPARATOR . 'trading-backup-' . $stamp . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($temporaryZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Záložní ZIP nelze vytvořit.');
    }
    $zip->addFile($temporaryDb, 'trading.sqlite3');
    foreach (fetch_all('SELECT file_name FROM screenshots ORDER BY created_at, id') as $row) {
        $file = upload_dir() . DIRECTORY_SEPARATOR . basename((string)$row['file_name']);
        if (is_file($file)) {
            $zip->addFile($file, 'uploads/' . basename($file));
        }
    }
    $manifest = json_encode(['version' => 1, 'created_at' => utc_now(), 'base_path' => APP_BASE], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $zip->addFromString('manifest.json', $manifest ?: '{}');
    $zip->close();
    @unlink($temporaryDb);

    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($temporaryZip));
    header('Content-Disposition: attachment; filename="trading-backup-' . $stamp . '.zip"');
    header('Cache-Control: no-store');
    readfile($temporaryZip);
    @unlink($temporaryZip);
} catch (Throwable $error) {
    error_log($error->__toString());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Zálohu se nepodařilo vytvořit. ' . (getenv('TRADING_DEBUG') === '1' ? $error->getMessage() : '');
}
