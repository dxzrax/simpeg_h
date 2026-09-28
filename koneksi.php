<?php
/**
 * koneksi.php
 * ---------------------------------------------------------------------
 * Satu-satunya file yang menyimpan kredensial database.
 * Semua file proses_*.php lain cukup menulis:  require 'koneksi.php';
 * di baris paling atas — jangan tulis ulang kredensial di file lain.
 *
 * Skema database yang dipakai: db_simpeg_h.sql (sudah kamu buat sebelumnya)
 * ---------------------------------------------------------------------
 */

// ===== SESUAIKAN 4 BARIS INI DENGAN LARAGON KAMU =====
$db_host = 'localhost';   // Laragon default: localhost
$db_user = 'root';        // Laragon default: root
$db_pass = '';            // Laragon default: kosong (tanpa password)
$db_name = 'db_simpeg_h'; // nama database dari db_simpeg_h.sql
// ======================================================

/**
 * PENTING — INI YANG MEMBUAT try/catch DI proses_*.php SUNGGUHAN BEKERJA
 * ---------------------------------------------------------------------
 * Secara default, fungsi mysqli_* TIDAK melempar exception kalau
 * mysqli.report_mode belum diatur eksplisit (perilaku ini bisa beda-beda
 * tergantung versi PHP & pengaturan php.ini bawaan Laragon). Kalau tidak
 * diatur di sini, saat mysqli_stmt_execute() gagal (mis. FK constraint
 * dilanggar, kolom yang belum ada, dsb), fungsi itu HANYA mengembalikan
 * false secara diam-diam — blok catch (\Throwable $e) di proses_tambah_
 * pegawai.php / proses_edit_pegawai.php / proses_hitung_tukin.php /
 * proses_atur_potongan.php TIDAK PERNAH TERPICU, padahal transaksinya
 * sudah setengah jalan. Inilah "try/catch yang bocor" itu: mysqli_commit()
 * tetap terpanggil di akhir try meski salah satu INSERT/UPDATE di
 * tengahnya diam-diam gagal -> data pegawai "yatim" tanpa akun, atau
 * baris tukin yang rincian potongannya tidak lengkap, tanpa ada tanda
 * kegagalan sama sekali.
 *
 * Baris ini memaksa mysqli MELEMPAR mysqli_sql_exception (yang otomatis
 * tertangkap oleh catch (\Throwable $e)) setiap kali sebuah query/prepare/
 * execute gagal — apa pun versi PHP atau isi php.ini di komputer ini.
 */
// Pastikan zona waktu PHP konsisten WIB (Asia/Jakarta) di seluruh aplikasi (Audit B8)
date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// mysqli_connect() sendiri ikut melempar exception kalau baris di atas
// aktif, jadi koneksi awal juga perlu try/catch supaya tetap tampil
// pesan yang ramah (bukan halaman fatal error PHP mentah ke pengguna).
try {
    $koneksi = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    // Wajib di-set supaya nama dengan karakter khusus (ó, é, dsb) & emoji tidak rusak
    mysqli_set_charset($koneksi, 'utf8mb4');
    // Sinkronkan zona waktu sesi MySQL ke WIB (+07:00) agar NOW() dan CURRENT_TIMESTAMP konsisten
    mysqli_query($koneksi, "SET time_zone = '+07:00'");
} catch (\mysqli_sql_exception $e) {
    // Untuk skripsi/demo, log ke error_log server supaya masih bisa
    // dilihat saat debugging, tapi pesan ke pengguna tetap generik.
    error_log('Koneksi database gagal: ' . $e->getMessage());
    die('Koneksi database gagal. Pastikan MySQL/MariaDB di Laragon sudah dijalankan, lalu muat ulang halaman ini.');
}
