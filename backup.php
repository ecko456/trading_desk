<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Záloha obsahuje jen deník přihlášeného uživatele. U šifrovaného deníku je ZIP
// odemčený, aby šel otevřít i bez aplikace; soubory vznikají v soukromém
// dočasném adresáři a hned po odeslání se mažou.
$temporary = [];
register_shutdown_function(static function () use (&$temporary): void {
    foreach ($temporary as $path) {
        @unlink($path);
    }
});

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Nejdřív se přihlas.';
        exit;
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Na serveru chybí PHP rozšíření zip.');
    }
    $journal = current_journal();
    $stamp = gmdate('Ymd-His');
    $directory = vault_temp_dir();
    $temporaryDb = $directory . DIRECTORY_SEPARATOR . 'backup-' . bin2hex(random_bytes(8)) . '.sqlite3';
    $temporary[] = $temporaryDb;
    $journal->snapshotTo($temporaryDb);
    // ZIP je odemčený; přístupové klíče k cTraderu do něj nepatří (po obnovení se účty napojí znovu).
    $copy = new PDO('sqlite:' . $temporaryDb);
    if ($copy->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'broker_connections'")->fetchColumn() !== false) {
        $copy->exec("UPDATE broker_connections SET access_token = '', refresh_token = '', expires_at = 0");
    }
    $copy = null;

    $temporaryZip = $directory . DIRECTORY_SEPARATOR . 'backup-' . bin2hex(random_bytes(8)) . '.zip';
    $temporary[] = $temporaryZip;
    $zip = new ZipArchive();
    if ($zip->open($temporaryZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Záložní ZIP nelze vytvořit.');
    }
    $zip->addFile($temporaryDb, 'trading.sqlite3');
    foreach (fetch_all('SELECT file_name FROM screenshots ORDER BY created_at, id') as $row) {
        $fileName = basename((string)$row['file_name']);
        if ($journal->encrypted()) {
            $contents = $journal->readUpload($fileName);
            if ($contents === null) {
                continue;
            }
            $plainCopy = $directory . DIRECTORY_SEPARATOR . 'backup-' . bin2hex(random_bytes(8)) . '-' . $fileName;
            file_put_contents($plainCopy, $contents);
            $temporary[] = $plainCopy;
            $zip->addFile($plainCopy, 'uploads/' . $fileName);
        } elseif (is_file($journal->uploadPath($fileName))) {
            $zip->addFile($journal->uploadPath($fileName), 'uploads/' . $fileName);
        }
    }
    $manifest = json_encode([
        'version' => 2,
        'created_at' => utc_now(),
        'user' => (string)$user['login'],
        'encrypted_account' => (bool)$user['encrypted'],
        'note' => 'Obsah ZIPu je odemčený. Ulož ho na bezpečné místo.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $zip->addFromString('manifest.json', $manifest ?: '{}');
    $zip->close();
    $journal->close();

    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($temporaryZip));
    header('Content-Disposition: attachment; filename="trading-backup-' . preg_replace('/[^a-z0-9._-]/', '', (string)$user['login']) . '-' . $stamp . '.zip"');
    header('Cache-Control: no-store');
    readfile($temporaryZip);
} catch (Throwable $error) {
    error_log($error->__toString());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Zálohu se nepodařilo vytvořit. ' . (getenv('TRADING_DEBUG') === '1' ? $error->getMessage() : '');
}
