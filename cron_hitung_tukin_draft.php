<?php
/**
 * cron_hitung_tukin_draft.php
 * ---------------------------------------------------------------------
 * Menghitung ULANG tukin secara OTOMATIS, TAPI HANYA untuk periode yang
 * SAAT INI berstatus 'draft' — periode yang sudah 'final' tidak disentuh
 * sama sekali, dan periode yang BELUM PERNAH dihitung sama sekali juga
 * tidak ikut (tidak ada baris draft untuk diperbarui). Tujuannya:
 * Kasubbag TU/Admin buka dashboard, angka draft sudah ter-update sendiri
 * dari data absensi & verifikasi terbaru — tanpa klik "Hitung Tukin"
 * setiap hari.
 *
 * Finalisasi (kunci ke 'final') tetap 100% manual lewat
 * proses_finalisasi_tukin.php — sengaja TIDAK diotomatisasi, karena
 * mengunci angka jadi dasar SPJ tanpa ada manusia yang menyetujui adalah
 * risiko yang tidak sepadan dengan kenyamanannya.
 *
 * Dijadwalkan jalan tiap malam (mis. 23:00) lewat Windows Task Scheduler,
 * pola sama persis dengan cron_tandai_alpa.php:
 *
 * CLI (Task Scheduler):
 *      Program   : C:\laragon\bin\php\php-x.x.x\php.exe
 *      Arguments : "C:\laragon\www\simpeg-h\cron_hitung_tukin_draft.php"
 *
 * HTTP (tes manual lewat browser, WAJIB pakai key):
 *      http://localhost/simpeg-h/cron_hitung_tukin_draft.php?key=577f8aa675ef3fcd065619cce6e071293d552a990a274107
 * ---------------------------------------------------------------------
 */

require 'koneksi.php';
require 'helpers.php'; // hitung_tukin_periode()

define('KUNCI_RAHASIA_CRON', '577f8aa675ef3fcd065619cce6e071293d552a990a274107'); // sama dgn cron_tandai_alpa.php — ganti keduanya kalau dipakai di server sungguhan

$lewat_cli = (php_sapi_name() === 'cli');

if (!$lewat_cli) {
    $key_diberikan = $_GET['key'] ?? '';
    if (!hash_equals(KUNCI_RAHASIA_CRON, $key_diberikan)) {
        http_response_code(403);
        die('Akses ditolak.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// "Operator" tercatat di log_perhitungan_tukin untuk baris yang dipicu
// cron ini — dicari dinamis (akun admin pertama), bukan di-hardcode
// id-nya, supaya tidak bergantung urutan seed data.
$admin_row = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"));
if (!$admin_row) {
    echo "GAGAL: tidak ada akun admin sama sekali di tabel users — cron dibatalkan.\n";
    exit(1);
}
$user_id_sistem = (int) $admin_row['id'];

// Ambil semua periode yang SAAT INI berstatus draft — inilah yang
// dimaksud "hanya memproses periode yang statusnya masih draft".
$daftar_periode_draft = mysqli_query($koneksi, "
    SELECT DISTINCT periode_bulan, periode_tahun
    FROM tukin
    WHERE status = 'draft'
    ORDER BY periode_tahun, periode_bulan
");

$total_periode  = 0;
$total_berhasil = 0;
$total_gagal    = 0;

echo "=== cron_hitung_tukin_draft.php - mulai " . date('Y-m-d H:i:s') . " ===\n";

while ($p = mysqli_fetch_assoc($daftar_periode_draft)) {
    $bulan = (int) $p['periode_bulan'];
    $tahun = (int) $p['periode_tahun'];
    $total_periode++;

    $hasil = hitung_tukin_periode($koneksi, $bulan, $tahun, $user_id_sistem);

    if ($hasil['sukses']) {
        $total_berhasil++;
        echo "OK   {$bulan}/{$tahun} - {$hasil['pesan']}\n";
    } else {
        $total_gagal++;
        echo "SKIP {$bulan}/{$tahun} - {$hasil['pesan']}\n";
    }
}

if ($total_periode === 0) {
    echo "Tidak ada periode berstatus draft yang perlu diperbarui.\n";
}

echo "=== Selesai - {$total_berhasil} berhasil, {$total_gagal} gagal/dilewati, dari {$total_periode} periode draft ===\n";
