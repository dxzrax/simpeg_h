<?php
/**
 * config.example.php
 * -----------------------------------------------------------------------
 * Contoh berkas konfigurasi SIMPEG-H.
 * Salin file ini menjadi `config.php` dan sesuaikan nilainya.
 * Pastikan `config.php` tidak di-commit ke repositori Git publik.
 * -----------------------------------------------------------------------
 */

// =====================================================================
// 🔑 FONNTE (WhatsApp Gateway)
// =====================================================================
define('FONNTE_API_KEY', 'GANTI_DENGAN_API_KEY_FONNTE_ANDA');

// =====================================================================
// 📧 EMAIL (SMTP)
// =====================================================================
define('EMAIL_HOST',        'smtp.gmail.com');
define('EMAIL_PORT',        587);
define('EMAIL_USERNAME',    'email.anda@gmail.com');
define('EMAIL_PASSWORD',    'GANTI_DENGAN_APP_PASSWORD_16_DIGIT');
define('EMAIL_FROM',        'email.anda@gmail.com');
define('EMAIL_FROM_NAME',   'SIMPEG-H BPTD Lampung');
define('EMAIL_ENCRYPTION',  'tls');

// =====================================================================
// 🌐 URL Aplikasi
// =====================================================================
define('APP_URL', 'http://localhost/simpeg_h');

// =====================================================================
// ⚙️ NOTIF GATEWAY
// =====================================================================
define('NOTIF_WA_ENABLED',    false); // Set false untuk development lokal
define('NOTIF_EMAIL_ENABLED', false); // Set false untuk development lokal
define('NOTIF_LOG_ENABLED',   true);

// =====================================================================
// 📟 ZKTECO INTEGRATION & FINGERPRINT ENDPOINT (W1)
// =====================================================================
// Kunci rahasia bersama untuk daemon Python (header X-Daemon-Key)
// Buat acak baru dengan: php -r "echo bin2hex(random_bytes(24));"
define('ZKTECO_API_KEY', 'GANTI_DENGAN_RANDOM_SECRET_KEY_MIN_32_CHAR');

// Daftar IP/CIDR yang diizinkan mengakses api_fingerprint_zkteco.php
define('ZKTECO_ALLOWED_IPS', ['127.0.0.1', '::1']);

// Wajib HTTPS untuk endpoint daemon
define('ZKTECO_WAJIB_HTTPS', false);

// Minimal selisih (menit) antara tap masuk pertama dan tap pulang
define('ZK_MIN_MENIT_MASUK_PULANG', 60);

// Toleransi waktu scan di masa depan (detik)
define('ZK_TOLERANSI_MASA_DEPAN_DETIK', 600);
