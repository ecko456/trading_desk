<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

security_headers();
set_time_limit(90);

function pdf_error(string $message, int $status = 500): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($id)) {
    pdf_error('Chybí platné ID denního náhledu.', 400);
}

$plan = plan_payload($id);
if ($plan === null) {
    pdf_error('Denní náhled nebyl nalezen.', 404);
}

$uploadRoot = realpath(upload_dir());
$screenshots = [];
if ($uploadRoot !== false) {
    $rows = fetch_all(
        'SELECT file_name, original_name, caption, mime_type, created_at FROM screenshots WHERE plan_id = ? ORDER BY created_at, id LIMIT 12',
        [$id]
    );
    $prefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    foreach ($rows as $row) {
        $fileName = basename((string)$row['file_name']);
        $realPath = realpath($prefix . $fileName);
        if ($realPath === false || !is_file($realPath) || strncmp($realPath, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $screenshots[] = [
            'path' => $realPath,
            'original_name' => (string)$row['original_name'],
            'caption' => (string)($row['caption'] ?? ''),
            'mime_type' => (string)$row['mime_type'],
            'created_at' => (string)$row['created_at'],
        ];
    }
}

$payload = [
    'plan' => $plan,
    'zones' => array_slice((array)($plan['zones'] ?? []), 0, 100),
    'levels' => array_slice((array)($plan['levels'] ?? []), 0, 100),
    'ideas' => array_slice((array)($plan['ideas'] ?? []), 0, 100),
    'refs' => array_slice((array)($plan['refs'] ?? []), 0, 100),
    'trades' => array_slice((array)($plan['trades'] ?? []), 0, 100),
    'screenshots' => $screenshots,
    'generated_at' => utc_now(),
];

$jsonPath = tempnam(sys_get_temp_dir(), 'trading-plan-');
if ($jsonPath === false) {
    pdf_error('Server nemohl připravit dočasný soubor pro PDF.');
}
$pdfPath = $jsonPath . '.pdf';
register_shutdown_function(static function () use ($jsonPath, $pdfPath): void {
    @unlink($jsonPath);
    @unlink($pdfPath);
});

$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($jsonPath, $encoded, LOCK_EX) === false) {
    pdf_error('Server nemohl zapsat podklady pro PDF.');
}

$python = resolve_python();
$generator = __DIR__ . DIRECTORY_SEPARATOR . 'export_plan.py';
if ($python === null) {
    pdf_error('PDF export potřebuje Python 3 s knihovnami reportlab a pillow. Postup najdeš v souboru INSTALL.md v sekci "PDF export". Zbytek aplikace funguje i bez něj.');
}
if (!is_file($generator) || !function_exists('proc_open')) {
    pdf_error('PDF převodník není na serveru dostupný.');
}

$pipes = [];
$process = proc_open(
    [$python, $generator, '--input', $jsonPath, '--output', $pdfPath],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    __DIR__,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($process)) {
    pdf_error('PDF převodník se nepodařilo spustit.');
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0 || !is_file($pdfPath) || filesize($pdfPath) === 0) {
    error_log('Trading PDF export failed: ' . trim((string)$stderr . "\n" . (string)$stdout));
    pdf_error('PDF se nepodařilo vytvořit. Python byl nalezen, ale chybí mu knihovny reportlab a pillow. Postup najdeš v souboru INSTALL.md v sekci "PDF export".');
}

$market = preg_replace('/[^A-Z0-9._-]/i', '', (string)$plan['market']) ?: 'market';
$date = preg_replace('/[^0-9-]/', '', (string)$plan['plan_date']) ?: date('Y-m-d');
$fileName = "trading-plan-{$date}-{$market}.pdf";

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . (string)filesize($pdfPath));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
readfile($pdfPath);
exit;
