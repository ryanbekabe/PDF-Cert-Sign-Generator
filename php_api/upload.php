<?php
/**
 * Halaman uploader PDF.
 *  - GET  upload.php : tampilkan form upload + opsi
 *  - POST upload.php : tandatangani file yang di-upload, kirim PDF hasil sebagai
 *                      download attachment (browser otomatis menyimpannya).
 *
 * Memakai pdfsign_* dari lib.php sehingga logic signing sama persis dengan
 * REST API di index.php.
 */
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

if (PHP_SAPI === 'cli-server') {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($reqPath !== '/' && $reqPath !== '/upload.php') {
        $candidate = __DIR__ . $reqPath;
        if (is_file($candidate)) {
            return false;
        }
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    process_upload($config);
    exit;
}

render_form($config, [], []);

// ------------------------------------------------------------------------

function process_upload(array $config): void
{
    // Auth opsional: kalau api_keys diisi di config, klien wajib kirim key
    if (!empty($config['api_keys'])) {
        $key = (string)($_POST['api_key'] ?? '');
        if (!in_array($key, $config['api_keys'], true)) {
            render_form($config, ['unauthorized' => 'API key salah atau belum diisi.'], $_POST, 401);
            return;
        }
    }

    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] === UPLOAD_ERR_NO_FILE) {
        render_form($config, ['pdf' => 'File PDF wajib dipilih.'], $_POST, 400);
        return;
    }

    $collected = pdfsign_collect_params($config, $_POST);
    if (!$collected['ok']) {
        render_form(
            $config,
            [($collected['error'] ?? 'invalid') => $collected['message'] ?? 'Parameter tidak valid.'],
            $_POST,
            (int)($collected['code'] ?? 400)
        );
        return;
    }

    $result = pdfsign_sign_uploaded($config, $_FILES['pdf'], $collected['params']);
    if (!$result['ok']) {
        $errs = ['_global' => $result['message'] ?? 'Gagal menandatangani PDF.'];
        if (!empty($result['stderr'])) {
            $errs['_stderr'] = $result['stderr'];
        }
        render_form($config, $errs, $_POST, (int)($result['code'] ?? 500));
        return;
    }

    $outputPath   = $result['output_path'];
    $downloadName = $result['download_name'];
    try {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($outputPath));
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        if (!empty($collected['params']['tsa'])) {
            header('X-Sign-TSA: ' . $collected['params']['tsa']);
        }
        readfile($outputPath);
    } finally {
        @unlink($outputPath);
    }
}

function render_form(array $config, array $errors = [], array $old = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');

    $needAuth = !empty($config['api_keys']);
    $f = static function (string $key, string $default = '') use ($old): string {
        return htmlspecialchars((string)($old[$key] ?? $default), ENT_QUOTES, 'UTF-8');
    };
    $defaults = [
        'reason'   => $config['default_reason'],
        'location' => $config['default_location'],
        'contact'  => $config['default_contact'],
        'name'     => $config['default_name'],
        'tsa'      => $config['tsa_url'],
    ];
    $globalError = $errors['_global']  ?? '';
    $stderr      = $errors['_stderr']  ?? '';
    $unauthMsg   = $errors['unauthorized'] ?? '';
    $pdfError    = $errors['pdf']      ?? '';
    $boxError    = $errors['invalid_box'] ?? '';
    $tsaError    = $errors['invalid_tsa'] ?? '';
    $signError   = $errors['sign_failed'] ?? '';
    $sizeError   = $errors['file_too_large'] ?? '';

    $maxMb = (int)$config['max_upload_mb'];
    ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PDF Signer Uploader</title>
    <style>
        :root {
            --fg: #1f2937; --muted: #6b7280; --bg: #f9fafb;
            --primary: #2563eb; --primary-hover: #1d4ed8;
            --error-bg: #fee2e2; --error-fg: #991b1b;
            --success-bg: #dcfce7; --success-fg: #166534;
            --border: #d1d5db;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg); color: var(--fg);
            max-width: 720px; margin: 2rem auto; padding: 0 1rem;
            line-height: 1.5;
        }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
        .subtitle { color: var(--muted); margin: 0 0 1.5rem; }
        .card {
            background: white; border: 1px solid var(--border); border-radius: 8px;
            padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }
        label { display: block; margin-top: 1rem; font-weight: 600; font-size: .9rem; }
        input[type=text], input[type=file], input[type=password] {
            width: 100%; padding: .55rem .7rem; font-size: 1rem;
            border: 1px solid var(--border); border-radius: 4px; margin-top: .25rem;
            background: white;
        }
        input[type=text]:focus, input[type=file]:focus {
            outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary);
        }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 540px) { .row { grid-template-columns: 1fr; } }
        .checkbox-row { margin-top: 1rem; display: flex; align-items: center; gap: .5rem; font-weight: normal; }
        .checkbox-row input { width: auto; margin: 0; }
        button {
            margin-top: 1.5rem; padding: .7rem 1.5rem; font-size: 1rem;
            background: var(--primary); color: white; border: 0;
            border-radius: 4px; cursor: pointer; font-weight: 600;
        }
        button:hover { background: var(--primary-hover); }
        .alert { padding: .8rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .alert-error { background: var(--error-bg); color: var(--error-fg); }
        .field-error { color: var(--error-fg); font-size: .85rem; margin-top: .25rem; }
        details { margin-top: 1rem; }
        summary { cursor: pointer; color: var(--muted); font-size: .9rem; }
        details[open] summary { margin-bottom: .5rem; }
        pre {
            background: #f3f4f6; padding: .8rem; border-radius: 4px;
            overflow: auto; font-size: .8rem; max-height: 200px;
            white-space: pre-wrap; word-break: break-word;
        }
        small.muted { color: var(--muted); font-size: .8rem; }
        .file-info { color: var(--muted); font-size: .85rem; margin-top: .25rem; }
    </style>
</head>
<body>
    <h1>PDF Signer Uploader</h1>
    <p class="subtitle">
        Upload PDF, server akan menandatangani dengan sertifikat lalu otomatis
        men-download hasilnya.
    </p>

    <div class="card">
        <?php if ($globalError !== ''): ?>
            <div class="alert alert-error">
                <strong>Gagal:</strong> <?= htmlspecialchars($globalError, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($stderr !== ''): ?>
                    <details><summary>Detail teknis (stderr)</summary><pre><?= htmlspecialchars($stderr, ENT_QUOTES, 'UTF-8') ?></pre></details>
                <?php endif; ?>
            </div>
        <?php elseif ($unauthMsg !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($unauthMsg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxMb * 1024 * 1024 ?>">

            <label>File PDF
                <input type="file" name="pdf" accept="application/pdf,.pdf" required>
            </label>
            <div class="file-info">Maksimum <?= $maxMb ?> MB.</div>
            <?php if ($pdfError): ?><div class="field-error"><?= htmlspecialchars($pdfError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($sizeError): ?><div class="field-error"><?= htmlspecialchars($sizeError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <div class="row">
                <div>
                    <label>Reason
                        <input type="text" name="reason" value="<?= $f('reason', $defaults['reason']) ?>">
                    </label>
                </div>
                <div>
                    <label>Location
                        <input type="text" name="location" value="<?= $f('location', $defaults['location']) ?>">
                    </label>
                </div>
            </div>
            <div class="row">
                <div>
                    <label>Signer name
                        <input type="text" name="name" value="<?= $f('name', $defaults['name']) ?>">
                    </label>
                </div>
                <div>
                    <label>Contact
                        <input type="text" name="contact" value="<?= $f('contact', $defaults['contact']) ?>">
                    </label>
                </div>
            </div>

            <details>
                <summary>Opsi lanjutan (TSA, kotak signature visible)</summary>

                <label>TSA URL <small class="muted">(kosongkan untuk tanpa TSA)</small>
                    <input type="text" name="tsa" value="<?= $f('tsa', $defaults['tsa']) ?>" placeholder="http://192.168.88.203:8080/">
                </label>
                <?php if ($tsaError): ?><div class="field-error"><?= htmlspecialchars($tsaError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                <label class="checkbox-row">
                    <input type="checkbox" name="visible" value="1" <?= !empty($old['visible']) ? 'checked' : '' ?>>
                    Tampilkan widget tanda tangan visible (default: invisible)
                </label>

                <label>Box visible signature <small class="muted">(x1,y1,x2,y2 — hanya berlaku jika visible aktif)</small>
                    <input type="text" name="box" value="<?= $f('box', $config['default_box']) ?>" placeholder="50,50,250,120">
                </label>
                <?php if ($boxError): ?><div class="field-error"><?= htmlspecialchars($boxError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </details>

            <?php if ($needAuth): ?>
                <label>API Key
                    <input type="password" name="api_key" required>
                </label>
            <?php endif; ?>

            <button type="submit">Upload &amp; Sign</button>
        </form>
    </div>

    <p style="margin-top:1.5rem"><small class="muted">
        Endpoint REST API: <code>POST /sign</code> — lihat
        <a href="./">root</a> untuk dokumentasi.
    </small></p>
</body>
</html>
    <?php
}
