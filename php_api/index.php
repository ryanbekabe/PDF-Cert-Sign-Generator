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
require_once __DIR__ . '/lib.php';

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
        'work_dir'    => pdfsign_ensure_work_dir($config['work_dir']),
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

    $format = strtolower((string)($_POST['format'] ?? 'binary'));
    if (!in_array($format, ['binary', 'json'], true)) {
        send_json(['error' => 'invalid_format', 'allowed' => ['binary', 'json']], 400);
    }

    $collected = pdfsign_collect_params($config, $_POST);
    if (!$collected['ok']) {
        send_json(
            ['error' => $collected['error'], 'message' => $collected['message']],
            (int)($collected['code'] ?? 400)
        );
    }
    $params = $collected['params'];

    $result = pdfsign_sign_uploaded($config, $_FILES['pdf'], $params);
    if (!$result['ok']) {
        $payload = ['error' => $result['error'], 'message' => $result['message'] ?? null];
        if (isset($result['exit_code'])) {
            $payload['return_code'] = $result['exit_code'];
            $payload['stdout']      = $result['stdout'] ?? '';
            $payload['stderr']      = $result['stderr'] ?? '';
        }
        send_json($payload, (int)($result['code'] ?? 500));
    }

    try {
        $outputPath   = $result['output_path'];
        $downloadName = $result['download_name'];

        if ($format === 'json') {
            $bytes = (string)file_get_contents($outputPath);
            send_json([
                'status'     => 'signed',
                'filename'   => $downloadName,
                'size'       => strlen($bytes),
                'tsa'        => $params['tsa'],
                'reason'     => $params['reason'],
                'location'   => $params['location'],
                'name'       => $params['name'],
                'pdf_base64' => base64_encode($bytes),
            ]);
        } else {
            header('Content-Type: application/pdf');
            header('Content-Length: ' . filesize($outputPath));
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            if ($params['tsa'] !== '') {
                header('X-Sign-TSA: ' . $params['tsa']);
            }
            readfile($outputPath);
        }
    } finally {
        @unlink($result['output_path']);
    }
}

// --- Helpers spesifik API (selain itu di lib.php) -----------------------

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
