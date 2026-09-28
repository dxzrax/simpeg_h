<?php
/**
 * proses_atur_notifikasi.php
 * ---------------------------------------------------------------------
 * Dipanggil lewat fetch() dari toggle di layar Pengaturan (pegawai.php)
 * — BUKAN submit form biasa, supaya toggle terasa instan (klik -> nyala/
 * mati saat itu juga) tanpa reload halaman. Mengembalikan JSON, bukan
 * redirect, karena dipanggil lewat JS bukan navigasi browser.
 *
 * CATATAN JUJUR (lihat kolom notif_wa_aktif/notif_email_aktif/notif_pengingat_cekin_aktif di db_simpeg_h.sql):
 * jenis 'wa' dan 'email' di sini BENAR-BENAR TERSIMPAN ke database,
 * tapi sistem belum punya pengirim WhatsApp/Email sungguhan di mana
 * pun — jadi menyalakan/mematikannya belum berpengaruh ke pengiriman
 * apa pun, cuma preferensinya yang sekarang jujur diingat lintas sesi.
 * jenis 'pengingat_cekin' sudah siap dipakai kalau nanti dibuat cron
 * pengingat (di luar cakupan file ini).
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'pesan' => 'Metode tidak diizinkan']);
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'pesan' => 'Sesi form sudah kedaluwarsa, muat ulang halaman']);
    exit;
}

if (!isset($_SESSION['pegawai_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'pesan' => 'Sesi login sudah berakhir']);
    exit;
}

// Peta jenis (dikirim dari JS) -> nama kolom asli — sengaja TIDAK menerima
// nama kolom langsung dari $_POST supaya JS tidak bisa menyuruh endpoint
// ini menulis ke kolom lain yang tidak dimaksudkan.
$peta_kolom = [
    'wa'              => 'notif_wa_aktif',
    'email'           => 'notif_email_aktif',
    'pengingat_cekin' => 'notif_pengingat_cekin_aktif',
];
$jenis = $_POST['jenis'] ?? '';
$aktif = ($_POST['aktif'] ?? '') === '1' ? 1 : 0;

if (!isset($peta_kolom[$jenis])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'pesan' => 'Jenis notifikasi tidak dikenali']);
    exit;
}

$kolom      = $peta_kolom[$jenis]; // aman — hasil lookup dari daftar tetap di atas, bukan input mentah
$pegawai_id = (int) $_SESSION['pegawai_id'];

$stmt = mysqli_prepare($koneksi, "UPDATE pegawai SET `$kolom` = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $aktif, $pegawai_id);
$berhasil = mysqli_stmt_execute($stmt);

echo json_encode(['ok' => $berhasil]);
exit;
