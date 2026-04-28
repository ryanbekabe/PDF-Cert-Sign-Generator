<?php
/**
 * REST API PHP untuk PDF Cert Sign Generator.
 *
 * Endpoint:
 *   GET  /            -> info API (JSON)
 *   GET  /health      -> health check
 *   POST /sign        -> upload PDF (multipart field "pdf"), balikan PDF tertandatangani
 *
 * Field POST opsional pada /sign:
 *   pdf       (file)  : wajib, file PDF yang akan ditandatangani
 *   reason    (text)  : alasan penandatanganan
 *   location  (text)  : lokasi
 *   contact   (text)  : kontak
 *   name      (text)  : nama penanda tangan
 *   tsa       (text)  : URL TSA, kirim string kosong untuk menonaktifkan TSA
 *   box       (text)  : "x1,y1,x2,y2"  -- kotak signature visible
 *   format    (text)  : "binary" (default) atau "json" (PDF di-base64-kan)
 */
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

// --- Built-in PHP server: serve static files apa adanya -----------------
if (PHP_SAPI === 'cli-server') {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($reqPath !== '/' && $reqPath !== '/index.php') {
        $candidate = __DIR__ . $reqPath;
        if (is_file($candidate)) {
            return false;
        }
    }
}

apply_cors($config);

// --- Routing ------------------------------------------------------------
// Mendukung 3 bentuk URL agar tetap jalan dengan / tanpa rewrite Apache:
//   1) /php_api/sign            (mod_rewrite aktif)
//   2) /php_api/index.php/sign  (PathInfo, tanpa rewrite)
//   3) /php_api/index.php?p=/sign (query string, fallback terakhir)
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . ltrim($path, '/');
$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
foreach ([$script, $scriptDir] as $prefix) {
    if ($prefix !== '' && $prefix !== '/' && strpos($path, $prefix) === 0) {
        $path = substr($path, strlen($prefix));
        break;
    }
}
$path = '/' . ltrim((string)$path, '/');
if ($path === '/' && !empty($_GET['p'])) {
    $path = '/' . ltrim((string)$_GET['p'], '/');
}
if ($path === '') {
    $path = '/';
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($method === 'GET' && $path === '/') {
        send_json([
            'name'      => 'PDF Cert Sign Generator API',
            'version'   => '1.0.0',
            'endpoints' => [
                'GET /'        => 'Info API ini',
                'GET /health'  => 'Cek kesiapan service',
                'POST /sign'   => 'Tanda tangani PDF (multipart, field "pdf")',
            ],
            'example_curl' => 'curl -F "pdf=@input.pdf" -o signed.pdf '
                . full_url(),
        ]);
    } elseif ($method === 'GET' && $path === '/health') {
        handle_health($config);
    } elseif ($method === 'POST' && $path === '/sign') {
        require_api_key($config);
        handle_sign($config);
    } else {
        send_json(['error' => 'not_found', 'path' => $path, 'method' => $method], 404);
    }
} catch (Throwable $e) {
    error_log('[sign-api] ' . $e->getMessage());
    send_json(['error' => 'internal', 'message' => $e->getMessage()], 500);
}

// --- Handlers -----------------------------------------------------------

function handle_health(array $config): void
{
    $checks = [
        'python_bin'  => is_file($config['python_bin']) && is_executable($config['python_bin']),
        'sign_script' => is_file($config['sign_script']),
        'cert_path'   => is_file($config['cert_path']),
        'work_dir'    => ensure_work_dir($config['work_dir']),
    ];
    $ok = !in_array(false, $checks, true);
    send_json([
        'status' => $ok ? 'ok' : 'degraded',
        'checks' => $checks,
        'tsa'    => $config['tsa_url'],
    ], $ok ? 200 : 503);
}

function handle_sign(array $config): void
{
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] === UPLOAD_ERR_NO_FILE) {
        send_json(['error' => 'missing_field', 'message' => 'multipart field "pdf" wajib ada'], 400);
    }

    $upload = $_FILES['pdf'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        send_json([
            'error'     => 'upload_failed',
            'php_error' => $upload['error'],
            'hint'      => upload_error_message((int)$upload['error']),
        ], 400);
    }
    $maxBytes = (int)$config['max_upload_mb'] * 1024 * 1024;
    if ((int)$upload['size'] > $maxBytes) {
        send_json([
            'error'    => 'file_too_large',
            'limit_mb' => $config['max_upload_mb'],
        ], 413);
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        send_json(['error' => 'invalid_upload'], 400);
    }

    // Verifikasi magic byte PDF (%PDF-)
    $fp    = @fopen($upload['tmp_name'], 'rb');
    $magic = $fp ? (string)fread($fp, 5) : '';
    if ($fp) {
        fclose($fp);
    }
    if ($magic !== '%PDF-') {
        send_json(['error' => 'not_a_pdf'], 415);
    }

    // Sanitisasi field opsional
    $reason   = clean_text($_POST['reason']   ?? '', $config['default_reason']);
    $location = clean_text($_POST['location'] ?? '', $config['default_location']);
    $contact  = clean_text($_POST['contact']  ?? '', $config['default_contact']);
    $name     = clean_text($_POST['name']     ?? '', $config['default_name']);
    $boxStr   = clean_text($_POST['box']      ?? '', $config['default_box']);
    $box      = parse_box($boxStr);
    if ($box === null) {
        send_json(['error' => 'invalid_box', 'expected' => 'x1,y1,x2,y2 (numerik)'], 400);
    }

    // TSA: kalau klien kirim 'tsa' (termasuk string kosong) gunakan nilai itu;
    // kalau tidak kirim sama sekali, pakai default config.
    if (array_key_exists('tsa', $_POST)) {
        $tsa = trim((string)$_POST['tsa']);
    } else {
        $tsa = (string)$config['tsa_url'];
    }
    if ($tsa !== '' && !preg_match('#^https?://#i', $tsa)) {
        send_json(['error' => 'invalid_tsa', 'message' => 'TSA harus URL http(s)'], 400);
    }

    $format = strtolower((string)($_POST['format'] ?? 'binary'));
    if (!in_array($format, ['binary', 'json'], true)) {
        send_json(['error' => 'invalid_format', 'allowed' => ['binary', 'json']], 400);
    }

    if (!ensure_work_dir($config['work_dir'])) {
        send_json(['error' => 'work_dir_unwritable', 'path' => $config['work_dir']], 500);
    }

    $token      = bin2hex(random_bytes(8));
    $inputPath  = $config['work_dir'] . '/in_'  . $token . '.pdf';
    $outputPath = $config['work_dir'] . '/out_' . $token . '.pdf';

    if (!@move_uploaded_file($upload['tmp_name'], $inputPath)) {
        send_json(['error' => 'cannot_save_upload'], 500);
    }

    try {
        $cmd = build_sign_command($config, $inputPath, $outputPath, $reason, $location, $contact, $name, $box, $tsa);
        [$exitCode, $stdout, $stderr] = run_command($cmd, (int)$config['sign_timeout']);

        if ($exitCode !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
            send_json([
                'error'       => 'sign_failed',
                'return_code' => $exitCode,
                'stdout'      => $stdout,
                'stderr'      => $stderr,
            ], 500);
        }

        $origBase = pathinfo((string)$upload['name'], PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', $origBase !== '' ? $origBase : 'document');
        $downloadName = $safeBase . '.signed.pdf';

        if ($format === 'json') {
            $bytes = (string)file_get_contents($outputPath);
            send_json([
                'status'     => 'signed',
                'filename'   => $downloadName,
                'size'       => strlen($bytes),
                'tsa'        => $tsa,
                'reason'     => $reason,
                'location'   => $location,
                'name'       => $name,
                'pdf_base64' => base64_encode($bytes),
            ]);
        } else {
            header('Content-Type: application/pdf');
            header('Content-Length: ' . filesize($outputPath));
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            if ($tsa !== '') {
                header('X-Sign-TSA: ' . $tsa);
            }
            readfile($outputPath);
        }
    } finally {
        @unlink($inputPath);
        @unlink($outputPath);
    }
}

// --- Helpers ------------------------------------------------------------

function build_sign_command(
    array $config,
    string $input,
    string $output,
    string $reason,
    string $location,
    string $contact,
    string $name,
    array $box,
    string $tsa
): string {
    $parts = [
        escapeshellarg($config['python_bin']),
        escapeshellarg($config['sign_script']),
        '-i', escapeshellarg($input),
        '-o', escapeshellarg($output),
        '-c', escapeshellarg($config['cert_path']),
        '-p', escapeshellarg((string)$config['cert_password']),
        '--reason',   escapeshellarg($reason),
        '--location', escapeshellarg($location),
        '--contact',  escapeshellarg($contact),
        '--name',     escapeshellarg($name),
        '--box',
        escapeshellarg((string)$box[0]),
        escapeshellarg((string)$box[1]),
        escapeshellarg((string)$box[2]),
        escapeshellarg((string)$box[3]),
    ];
    if ($tsa !== '') {
        $parts[] = '--tsa';
        $parts[] = escapeshellarg($tsa);
    }
    return implode(' ', $parts);
}

/**
 * Menjalankan perintah shell dengan timeout opsional.
 * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
 */
function run_command(string $cmd, int $timeout = 0): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [-1, '', 'proc_open gagal'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start  = microtime(true);
    $exit   = -1;

    while (true) {
        $status = proc_get_status($proc);
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        if (!$status['running']) {
            // Tangkap exit code di pemanggilan pertama setelah proses berakhir;
            // panggilan berikutnya (atau proc_close) akan return -1.
            $exit = (int)$status['exitcode'];
            break;
        }
        if ($timeout > 0 && (microtime(true) - $start) > $timeout) {
            proc_terminate($proc, 9);
            $stderr .= "\n[timed out after {$timeout}s]";
            break;
        }
        usleep(50_000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return [$exit, $stdout, $stderr];
}

function parse_box(string $s): ?array
{
    $parts = array_map('trim', explode(',', $s));
    if (count($parts) !== 4) {
        return null;
    }
    $out = [];
    foreach ($parts as $p) {
        if (!is_numeric($p)) {
            return null;
        }
        $out[] = (float)$p;
    }
    return $out;
}

function clean_text(string $value, string $default): string
{
    $value = trim($value);
    if ($value === '') {
        return $default;
    }
    // Buang karakter kontrol yang bisa membingungkan PDF metadata.
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    return mb_substr((string)$value, 0, 200);
}

function ensure_work_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    return @mkdir($dir, 0700, true) && is_writable($dir);
}

function require_api_key(array $config): void
{
    $keys = $config['api_keys'] ?? [];
    if (empty($keys)) {
        return;
    }
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!is_string($key) || !in_array($key, $keys, true)) {
        send_json(['error' => 'unauthorized'], 401);
    }
}

function apply_cors(array $config): void
{
    $origin = (string)($config['cors_origin'] ?? '');
    if ($origin === '') {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Max-Age: 600');
}

function upload_error_message(int $code): string
{
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'melebihi upload_max_filesize di php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'melebihi MAX_FILE_SIZE di form',
        UPLOAD_ERR_PARTIAL    => 'upload tidak selesai',
        UPLOAD_ERR_NO_FILE    => 'tidak ada file ter-upload',
        UPLOAD_ERR_NO_TMP_DIR => 'direktori tmp tidak tersedia',
        UPLOAD_ERR_CANT_WRITE => 'gagal menulis ke disk',
        UPLOAD_ERR_EXTENSION  => 'upload diblokir extension PHP',
    ];
    return $map[$code] ?? 'unknown';
}

function full_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base   = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return $scheme . '://' . $host . $base . '/sign';
}

function send_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
