<?php
/**
 * proses_kelola_hari_libur.php
 * ---------------------------------------------------------------------
 * Mengisi tabel `hari_libur` (tanggal merah nasional/cuti bersama) —
 * dipakai hitung_hari_kerja_efektif() dan is_hari_kerja() di helpers.php,
 * yang jadi basis perhitungan tunjangan makan (proses_hitung_tukin.php)
 * dan pengecualian alpa otomatis (cron_tandai_alpa.php).
 *
 * Sampel 2026 di tabel hari_libur (db_simpeg_h.sql) BELUM LENGKAP
 * (8 cuti bersama belum semua terverifikasi sumbernya) — menu ini yang
 * dipakai admin untuk melengkapi/mengoreksi, dan untuk mengisi tahun-
 * tahun berikutnya (tanggal merah beda tiap tahun, tidak bisa hardcode
 * sekali di database).
 *
 * Dua aksi lewat field hidden `aksi`:
 *   aksi=tambah -> INSERT (atau REPLACE kalau tanggal sudah ada — supaya
 *                  admin bisa koreksi keterangan/jenis tanpa hapus dulu)
 *   aksi=hapus  -> DELETE satu tanggal
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php?view=kalender');
    exit;
}
csrf_verify_or_die('admin.php?view=kalender');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['kasubbag', 'admin'], true)) {
    header('Location: login.php?error=akses_ditolak');
    exit;
}

$aksi = ($_POST['aksi'] ?? 'tambah') === 'hapus' ? 'hapus' : 'tambah';

if ($aksi === 'hapus') {
    $tanggal = $_POST['tanggal'] ?? '';
    if ($tanggal !== '' && strtotime($tanggal) !== false) {
        $stmt = mysqli_prepare($koneksi, "DELETE FROM hari_libur WHERE tanggal = ?");
        mysqli_stmt_bind_param($stmt, 's', $tanggal);
        mysqli_stmt_execute($stmt);
    }
    header('Location: admin.php?view=kalender&libur=terhapus');
    exit;
}

// ===== aksi=tambah =====
$tanggal    = $_POST['tanggal'] ?? '';
$keterangan = trim($_POST['keterangan'] ?? '');
$jenis      = ($_POST['jenis'] ?? 'nasional') === 'cuti_bersama' ? 'cuti_bersama' : 'nasional';

if ($tanggal === '' || strtotime($tanggal) === false || $keterangan === '') {
    header('Location: admin.php?view=kalender&libur=gagal_kosong');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// REPLACE, bukan INSERT murni — supaya menambah tanggal yang sudah ada
// otomatis jadi "koreksi" (update keterangan/jenis), bukan gagal karena
// primary key bentrok. Ini yang membuat menu ini bisa dipakai untuk
// melengkapi sampel 2026 yang belum lengkap tanpa hapus manual dulu.
$stmt = mysqli_prepare($koneksi, "
    REPLACE INTO hari_libur (tanggal, keterangan, jenis, dibuat_oleh)
    VALUES (?, ?, ?, ?)
");
mysqli_stmt_bind_param($stmt, 'sssi', $tanggal, $keterangan, $jenis, $user_id);
mysqli_stmt_execute($stmt);

header('Location: admin.php?view=kalender&libur=berhasil');
exit;
