<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

security_headers();
set_time_limit(90);

/*
 * PDF exporty přes Python reportlab:
 *   ?id=…            denní nebo týdenní náhled (export_plan.py)
 *   ?trading_plan=…  obchodní plán (export_trading_plan.py)
 * Vzhled obou je v pdf_kit.py.
 */

function pdf_error(string $message, int $status = 500): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

/** Cesta k obrázku pro převodník. Šifrované se odemknou do dočasného souboru v RAM. */
function pdf_image_path(JournalStore $journal, string $fileName, array &$temporary): ?string
{
    $fileName = basename($fileName);
    if ($journal->encrypted()) {
        $contents = $journal->readUpload($fileName);
        if ($contents === null) {
            return null;
        }
        $path = vault_temp_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(10)) . '-' . $fileName;
        file_put_contents($path, $contents);
        @chmod($path, 0600);
        $temporary[] = $path;
        return $path;
    }
    $path = $journal->uploadPath($fileName);
    return is_file($path) ? $path : null;
}

function pdf_render(string $generator, array $payload, string $downloadName, JournalStore $journal): never
{
    $jsonPath = tempnam(vault_temp_dir(), 'trading-pdf-');
    if ($jsonPath === false) {
        pdf_error('Server nemohl připravit dočasný soubor pro PDF.');
    }
    $pdfPath = $jsonPath . '.pdf';
    register_shutdown_function(static function () use ($jsonPath, $pdfPath): void {
        @unlink($jsonPath);
        @unlink($pdfPath);
    });

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    if (file_put_contents($jsonPath, $encoded, LOCK_EX) === false) {
        pdf_error('Server nemohl zapsat podklady pro PDF.');
    }

    // Deník už není potřeba; uvolní se zámek, ať ostatní požadavky nečekají na PDF.
    $journal->close();

    $python = resolve_python();
    $script = __DIR__ . DIRECTORY_SEPARATOR . $generator;
    if ($python === null) {
        pdf_error('PDF export potřebuje Python 3 s knihovnami reportlab a pillow. Postup najdeš v souboru INSTALL.md v sekci "PDF export". Zbytek aplikace funguje i bez něj.');
    }
    if (!is_file($script) || !function_exists('proc_open')) {
        pdf_error('PDF převodník není na serveru dostupný.');
    }

    $pipes = [];
    $process = proc_open(
        [$python, $script, '--input', $jsonPath, '--output', $pdfPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
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

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string)filesize($pdfPath));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    readfile($pdfPath);
    exit;
}

if (current_user() === null) {
    pdf_error('Nejdřív se přihlas.', 401);
}

$journal = current_journal();
$temporaryImages = [];
register_shutdown_function(static function () use (&$temporaryImages): void {
    foreach ($temporaryImages as $path) {
        @unlink($path);
    }
});

if (isset($_GET['trading_plan'])) {
    $planId = filter_input(INPUT_GET, 'trading_plan', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $payload = is_int($planId) ? tradeplan_pdf_payload($planId) : null;
    if ($payload === null) {
        pdf_error('Obchodní plán nebyl nalezen.', 404);
    }
    // Náhledový obrázek strategie: SVG převodník neumí, takže se ukáže iniciála.
    foreach ($payload['strategies'] as &$strategy) {
        $cover = $strategy['cover'] ?? null;
        $strategy['cover'] = null;
        if (is_array($cover) && in_array($cover['mime_type'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $strategy['cover'] = pdf_image_path($journal, (string)$cover['file_name'], $temporaryImages);
        }
    }
    unset($strategy);
    $version = (int)$payload['plan']['version'];
    pdf_render('export_trading_plan.py', $payload, "obchodni-plan-v{$version}.pdf", $journal);
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($id)) {
    pdf_error('Chybí platné ID denního náhledu.', 400);
}
$plan = plan_payload($id);
if ($plan === null) {
    pdf_error('Denní náhled nebyl nalezen.', 404);
}

$screenshots = [];
$rows = fetch_all(
    'SELECT file_name, original_name, caption, mime_type, created_at FROM screenshots WHERE plan_id = ? ORDER BY created_at, id LIMIT 12',
    [$id]
);
foreach ($rows as $row) {
    $path = pdf_image_path($journal, (string)$row['file_name'], $temporaryImages);
    if ($path === null) {
        continue;
    }
    $screenshots[] = [
        'path' => $path,
        'original_name' => (string)$row['original_name'],
        'caption' => (string)($row['caption'] ?? ''),
        'mime_type' => (string)$row['mime_type'],
        'created_at' => (string)$row['created_at'],
    ];
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

$market = preg_replace('/[^A-Z0-9._-]/i', '', (string)$plan['market']) ?: 'market';
$date = preg_replace('/[^0-9-]/', '', (string)$plan['plan_date']) ?: date('Y-m-d');
pdf_render('export_plan.py', $payload, "trading-plan-{$date}-{$market}.pdf", $journal);
