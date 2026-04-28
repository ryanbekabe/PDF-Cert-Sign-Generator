<?php
/**
 * Konfigurasi REST API PDF signer.
 *
 * Catatan keamanan: file ini berisi password sertifikat. Pastikan
 * permission-nya 600 dan tidak bisa diakses publik via web.
 *   chmod 600 config.php
 */
return [
    // Path executable Python yang punya endesive + cryptography terpasang.
    // Pakai venv kalau dependency-nya di sana, contoh:
    //   '/home/rsi/gitproject/PDF-Cert-Sign-Generator/venv/bin/python'
    'python_bin'    => '/usr/bin/python3',

    // Lokasi script penandatangan dan sertifikat (default: di root project).
    'sign_script'   => dirname(__DIR__) . '/sign_pdf.py',
    'cert_path'     => dirname(__DIR__) . '/cert.p12',
    'cert_password' => 'password',

    // URL TSA default. Kosongkan ('') kalau tidak mau pakai TSA.
    'tsa_url'       => 'http://192.168.88.203:8080/',

    // Direktori kerja untuk file sementara (input/output PDF).
    // Akan dibuat otomatis bila belum ada.
    'work_dir'      => __DIR__ . '/tmp',

    // Batas ukuran upload (MB).
    'max_upload_mb' => 50,

    // API key sederhana. Kosongkan array bila tidak butuh auth.
    // Jika diisi, klien wajib kirim header  X-API-Key: <salah-satu-nilai>
    'api_keys'      => [
        // 'ganti-dengan-token-rahasia-anda',
    ],

    // Aktifkan CORS bila API dipanggil dari frontend di origin lain.
    // Set ke '*' agar semua origin diizinkan, atau spesifik origin.
    // Kosongkan ('') untuk menonaktifkan.
    'cors_origin'   => '',

    // Default field tanda tangan (bisa di-override per request).
    'default_reason'   => 'Document approval',
    'default_location' => 'Indonesia',
    'default_contact'  => 'signer@example.com',
    'default_name'     => 'PDF Signer',

    // Default kotak tanda tangan visible: x1,y1,x2,y2.
    'default_box'      => '50,50,250,120',

    // Timeout proses Python (detik). 0 = tanpa batas.
    'sign_timeout'  => 120,
];
