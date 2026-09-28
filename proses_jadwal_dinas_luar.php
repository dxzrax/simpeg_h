<?php
/**
 * proses_jadwal_dinas_luar.php
 * ---------------------------------------------------------------------
 * Mengisi tabel `jadwal_dinas_luar` — sebelumnya tabel ini SUDAH dipakai
 * logikanya di proses_cekin.php (KF-27, mencocokkan cek-in dengan jadwal
 * dinas luar hari itu), tapi belum ada satu pun cara mengisinya lewat
 * UI. Kasubbag TU input jadwal di sini SEBELUM pegawai berangkat, supaya
 * saat pegawai cek-in di titik dinas luar, proses_cekin.php otomatis
 * menandai status_jadwal = 'sesuai' alih-alih 'tidak_ada_jadwal'.
 *
 * Dua aksi lewat field hidden `aksi`:
 *   aksi=tambah -> INSERT jadwal baru
 *   aksi=hapus  -> DELETE jadwal (jadwal murni rencana ke depan, jadi
 *                  hapus permanen di sini aman — beda dengan data
 *                  histori seperti absensi/tukin yang tidak boleh hilang)
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php?view=geofence');
    exit;
}
csrf_verify_or_die('admin.php?view=geofence');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['kasubbag', 'admin'], true)) {
    header('Location: login.php?error=akses_ditolak');
    exit;
}

$aksi = ($_POST['aksi'] ?? 'tambah') === 'hapus' ? 'hapus' : 'tambah';

if ($aksi === 'hapus') {
    $jadwal_id = isset($_POST['jadwal_id']) ? (int) $_POST['jadwal_id'] : 0;
    if ($jadwal_id > 0) {
        $stmt = mysqli_prepare($koneksi, "DELETE FROM jadwal_dinas_luar WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $jadwal_id);
        mysqli_stmt_execute($stmt);
    }
    header('Location: admin.php?view=geofence&jadwal=terhapus');
    exit;
}

// ===== aksi=tambah =====
$pegawai_id      = isset($_POST['pegawai_id']) ? (int) $_POST['pegawai_id'] : 0;
$titik_lokasi_id = isset($_POST['titik_lokasi_id']) ? (int) $_POST['titik_lokasi_id'] : 0;
$tanggal_mulai   = $_POST['tanggal_mulai'] ?? '';
$tanggal_selesai = $_POST['tanggal_selesai'] ?? '';
$jam_mulai       = trim($_POST['jam_mulai'] ?? '') ?: null;
$jam_selesai     = trim($_POST['jam_selesai'] ?? '') ?: null;
$keterangan      = trim($_POST['keterangan'] ?? '') ?: null;

if ($pegawai_id <= 0 || $titik_lokasi_id <= 0 || $tanggal_mulai === '' || $tanggal_selesai === '') {
    header('Location: admin.php?view=geofence&jadwal=gagal_kosong');
    exit;
}
if (strtotime($tanggal_selesai) < strtotime($tanggal_mulai)) {
    header('Location: admin.php?view=geofence&jadwal=gagal_tanggal');
    exit;
}

$stmt = mysqli_prepare($koneksi, "
    INSERT INTO jadwal_dinas_luar
        (pegawai_id, titik_lokasi_id, tanggal_mulai, tanggal_selesai, jam_mulai, jam_selesai, keterangan)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
mysqli_stmt_bind_param(
    $stmt, 'iisssss',
    $pegawai_id, $titik_lokasi_id, $tanggal_mulai, $tanggal_selesai, $jam_mulai, $jam_selesai, $keterangan
);
mysqli_stmt_execute($stmt);

header('Location: admin.php?view=geofence&jadwal=berhasil');
exit;
