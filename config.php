<?php
/**
 * config.php
 * -----------------------------------------------------------------------
 * Konfigurasi global simpeg_h — kredensial API gateway eksternal disimpan
 * di sini, TERPISAH dari koneksi.php, supaya mudah dikelola dan tidak
 * bocor ke version control (tambahkan file ini ke .gitignore).
 *
 * ISI YANG PERLU DIUBAH SEBELUM PRODUCTION:
 *   1. FONNTE_API_KEY   → API key dari https://fonnte.com (gratis + berbayar)
 *   2. EMAIL_*          → kredensial SMTP Gmail/Mailtrap/Brevo, dll.
 *   3. APP_URL          → URL publik sistem (untuk link di badan notif email)
 *
 * CARA MENDAPATKAN FONNTE API KEY:
 *   1. Daftar di https://fonnte.com → tambah device (sambungkan WA via QR)
 *   2. Salin API key dari Dashboard → Device Settings
 *   3. Tempel di konstanta FONNTE_API_KEY di bawah
 *
 * CARA MENDAPATKAN SMTP (Gmail):
 *   1. Aktifkan "2-Step Verification" di akun Gmail
 *   2. Buat "App Password" khusus (bukan password Google biasa!)
 *   3. Gunakan sebagai EMAIL_PASSWORD di bawah
 * -----------------------------------------------------------------------
 */

// =====================================================================
// 🔑 FONNTE (WhatsApp Gateway — paling mudah & murah untuk skripsi/UMKM)
// =====================================================================
define('FONNTE_API_KEY',   'GANTI_DENGAN_API_KEY_FONNTE_KAMU'); // ← isi ini

// Format nomor pengirim: Fonnte otomatis pakai nomor yang tersambung di device
// Pastikan nomor target di `pegawai.nomor_hp` format 08xx / 628xx / +628xx

// =====================================================================
// 📧 EMAIL (SMTP) — gunakan Gmail App Password ATAU Mailtrap untuk testing
// =====================================================================
define('EMAIL_HOST',     'smtp.gmail.com');       // atau 'sandbox.smtp.mailtrap.io' untuk testing
define('EMAIL_PORT',     587);                    // 587 untuk TLS; 465 untuk SSL
define('EMAIL_USERNAME', 'email.simpeg.h@gmail.com'); // ← isi email pengirim
define('EMAIL_PASSWORD', 'GANTI_DENGAN_APP_PASSWORD'); // ← isi App Password (bukan password biasa!)
define('EMAIL_FROM',     'email.simpeg.h@gmail.com');
define('EMAIL_FROM_NAME','SIMPEG-H BPTD Lampung');
define('EMAIL_ENCRYPTION','tls');                 // 'tls' atau 'ssl'

// =====================================================================
// 🌐 URL Aplikasi (dipakai di badan email untuk link deep-link)
// =====================================================================
define('APP_URL', 'http://localhost/simpeg_h'); // ← ubah ke URL server saat deploy

// =====================================================================
// ⚙️ NOTIF GATEWAY — aktifkan/nonaktifkan tanpa ubah kode gateway
// =====================================================================
// Set ke false saat testing supaya WA/email tidak terkirim ke nomor asli
define('NOTIF_WA_ENABLED',    true);  // ← set false saat development lokal
define('NOTIF_EMAIL_ENABLED', true);  // ← set false saat development lokal

// Log setiap percobaan kirim notifikasi ke error_log PHP (berguna debug)
define('NOTIF_LOG_ENABLED', true);

// =====================================================================
// 📟 ZKTECO INTEGRATION & FINGERPRINT ENDPOINT (W1)
// =====================================================================
// Kunci API bersama untuk autentikasi daemon Python / klien mesin (header X-Daemon-Key)
define('ZKTECO_API_KEY', 'simpeg_h_zkteco_secret_key_demo_2026');

// Daftar IP atau CIDR yang diizinkan mengakses api_fingerprint_zkteco.php
// Kosongkan array [] jika ingin mengizinkan dari mana saja (mis. saat di belakang VPN/reverse proxy)
define('ZKTECO_ALLOWED_IPS', ['127.0.0.1', '::1', 'localhost']);

// Wajib HTTPS untuk endpoint daemon (set false untuk development lokal Laragon)
define('ZKTECO_WAJIB_HTTPS', false);

// Minimal jeda waktu (menit) antara tap masuk pertama dan tap berikutnya agar diakui sebagai jam_pulang
define('ZK_MIN_MENIT_MASUK_PULANG', 60);

// Toleransi waktu scan di masa depan (detik) untuk mengantisipasi perbedaan jam mesin vs server
define('ZK_TOLERANSI_MASA_DEPAN_DETIK', 600);

