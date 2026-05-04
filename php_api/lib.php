<?php
/**
 * Helper bersama untuk REST API (index.php) dan halaman uploader (upload.php).
 * Tidak ada side-effect saat di-include — hanya definisi fungsi.
 */
declare(strict_types=1);

if (!defined('PDF_SIGN_LIB_LOADED')) {
    define('PDF_SIGN_LIB_LOADED', true);

    /**
     * Bangun command-line untuk memanggil sign_pdf.py dengan argumen yang aman.
     */
    function pdfsign_build_command(
        array $config,
        string $input,
        string $output,
        string $reason,
        string $location,
        string $contact,
        string $name,
        array $box,
        string $tsa,
        bool $visible
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
        if (!$visible) {
            $parts[] = '--invisible';
        }
        return implode(' ', $parts);
    }

    /**
     * Jalankan perintah shell, capture stdout/stderr, dengan timeout opsional.
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    function pdfsign_run_command(string $cmd, int $timeout = 0): array
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

    function pdfsign_parse_box(string $s): ?array
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

    function pdfsign_clean_text(string $value, string $default): string
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return mb_substr((string)$value, 0, 200);
    }

    function pdfsign_ensure_work_dir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0700, true) && is_writable($dir);
    }

    function pdfsign_upload_error_message(int $code): string
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

    /**
     * Validasi struktur file upload + magic bytes %PDF-.
     * @return array{ok:bool, error?:string, message?:string, code?:int}
     */
    function pdfsign_validate_upload(array $upload, int $maxBytes): array
    {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            return [
                'ok' => false,
                'error' => 'upload_failed',
                'message' => pdfsign_upload_error_message((int)$upload['error']),
                'code' => 400,
            ];
        }
        if ((int)$upload['size'] > $maxBytes) {
            return ['ok' => false, 'error' => 'file_too_large', 'message' => 'File melebihi batas ukuran.', 'code' => 413];
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            return ['ok' => false, 'error' => 'invalid_upload', 'message' => 'Upload tidak valid.', 'code' => 400];
        }
        $fp = @fopen($upload['tmp_name'], 'rb');
        $magic = $fp ? (string)fread($fp, 5) : '';
        if ($fp) {
            fclose($fp);
        }
        if ($magic !== '%PDF-') {
            return ['ok' => false, 'error' => 'not_a_pdf', 'message' => 'File yang di-upload bukan PDF.', 'code' => 415];
        }
        return ['ok' => true];
    }

    /**
     * Tanda tangani PDF yang baru di-upload.
     * @param array $upload  entry dari $_FILES
     * @param array $params  ['reason','location','contact','name','box','tsa','visible']
     * @return array{ok:bool, output_path?:string, download_name?:string,
     *               exit_code?:int, stdout?:string, stderr?:string,
     *               error?:string, message?:string, code?:int}
     */
    function pdfsign_sign_uploaded(array $config, array $upload, array $params): array
    {
        $maxBytes = (int)$config['max_upload_mb'] * 1024 * 1024;
        $check = pdfsign_validate_upload($upload, $maxBytes);
        if (!$check['ok']) {
            return $check;
        }
        if (!pdfsign_ensure_work_dir($config['work_dir'])) {
            return ['ok' => false, 'error' => 'work_dir_unwritable', 'message' => 'Direktori kerja tidak bisa ditulisi.', 'code' => 500];
        }

        $token      = bin2hex(random_bytes(8));
        $inputPath  = $config['work_dir'] . '/in_'  . $token . '.pdf';
        $outputPath = $config['work_dir'] . '/out_' . $token . '.pdf';

        if (!@move_uploaded_file($upload['tmp_name'], $inputPath)) {
            return ['ok' => false, 'error' => 'cannot_save_upload', 'message' => 'Gagal menyimpan file upload.', 'code' => 500];
        }

        $cmd = pdfsign_build_command(
            $config,
            $inputPath,
            $outputPath,
            (string)$params['reason'],
            (string)$params['location'],
            (string)$params['contact'],
            (string)$params['name'],
            (array)$params['box'],
            (string)$params['tsa'],
            (bool)$params['visible']
        );
        [$exit, $stdout, $stderr] = pdfsign_run_command($cmd, (int)$config['sign_timeout']);

        @unlink($inputPath);

        if ($exit !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            return [
                'ok' => false,
                'error' => 'sign_failed',
                'message' => 'Proses signing gagal.',
                'exit_code' => $exit,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'code' => 500,
            ];
        }

        $origBase = pathinfo((string)$upload['name'], PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', $origBase !== '' ? $origBase : 'document');

        return [
            'ok' => true,
            'output_path' => $outputPath,
            'download_name' => $safeBase . '.signed.pdf',
            'exit_code' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Ambil parameter signing dari $_POST dengan default dari config.
     * @return array{ok:bool, params?:array, error?:string, message?:string, code?:int}
     */
    function pdfsign_collect_params(array $config, array $post): array
    {
        $reason   = pdfsign_clean_text((string)($post['reason']   ?? ''), $config['default_reason']);
        $location = pdfsign_clean_text((string)($post['location'] ?? ''), $config['default_location']);
        $contact  = pdfsign_clean_text((string)($post['contact']  ?? ''), $config['default_contact']);
        $name     = pdfsign_clean_text((string)($post['name']     ?? ''), $config['default_name']);
        $boxStr   = pdfsign_clean_text((string)($post['box']      ?? ''), $config['default_box']);
        $box      = pdfsign_parse_box($boxStr);
        if ($box === null) {
            return ['ok' => false, 'error' => 'invalid_box', 'message' => 'Format box harus x1,y1,x2,y2 (numerik).', 'code' => 400];
        }
        if (array_key_exists('tsa', $post)) {
            $tsa = trim((string)$post['tsa']);
        } else {
            $tsa = (string)$config['tsa_url'];
        }
        if ($tsa !== '' && !preg_match('#^https?://#i', $tsa)) {
            return ['ok' => false, 'error' => 'invalid_tsa', 'message' => 'TSA harus URL http(s).', 'code' => 400];
        }
        $visible = filter_var($post['visible'] ?? '0', FILTER_VALIDATE_BOOLEAN);
        return [
            'ok' => true,
            'params' => compact('reason', 'location', 'contact', 'name', 'box', 'tsa', 'visible'),
        ];
    }
}
