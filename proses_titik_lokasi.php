<?php
/**
 * proses_titik_lokasi.php
 * ---------------------------------------------------------------------
 * CRUD untuk tabel `titik_lokasi_tugas` (dipakai proses_cekin.php untuk
 * geofence). Satu file menangani dua aksi lewat field hidden `aksi`:
 *   aksi=tambah -> INSERT titik baru
 *   aksi=edit   -> UPDATE titik yang sudah ada (termasuk mengubah
 *                  status jadi 'nonaktif' kalau titik sudah tidak dipakai
 *                  — TIDAK ada hapus permanen, supaya riwayat absensi
 *                  yang sudah mereferensikan titik_lokasi_id ini tetap aman)
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

$aksi         = ($_POST['aksi'] ?? 'tambah') === 'edit' ? 'edit' : 'tambah';
$nama_lokasi  = trim($_POST['nama_lokasi'] ?? '');
$alamat       = trim($_POST['alamat'] ?? '') ?: null;
$latitude     = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null;
$longitude    = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null;
$radius_meter = isset($_POST['radius_meter']) && $_POST['radius_meter'] !== '' ? (int) $_POST['radius_meter'] : 150;
$status       = in_array($_POST['status'] ?? '', ['aktif', 'musiman', 'nonaktif'], true) ? $_POST['status'] : 'aktif';

if ($nama_lokasi === '' || $latitude === null || $longitude === null) {
    header('Location: admin.php?view=geofence&titik=gagal_kosong');
    exit;
}
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    header('Location: admin.php?view=geofence&titik=gagal_koordinat');
    exit;
}

if ($aksi === 'tambah') {
    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO titik_lokasi_tugas (nama_lokasi, alamat, latitude, longitude, radius_meter, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, 'ssddis', $nama_lokasi, $alamat, $latitude, $longitude, $radius_meter, $status);
    mysqli_stmt_execute($stmt);
    header('Location: admin.php?view=geofence&titik=berhasil');
    exit;
}

// ===== aksi=edit =====
$titik_id = isset($_POST['titik_id']) ? (int) $_POST['titik_id'] : 0;
if ($titik_id <= 0) {
    header('Location: admin.php?view=geofence&titik=gagal');
    exit;
}

$stmt = mysqli_prepare($koneksi, "
    UPDATE titik_lokasi_tugas
    SET nama_lokasi = ?, alamat = ?, latitude = ?, longitude = ?, radius_meter = ?, status = ?
    WHERE id = ?
");
mysqli_stmt_bind_param($stmt, 'ssddisi', $nama_lokasi, $alamat, $latitude, $longitude, $radius_meter, $status, $titik_id);
mysqli_stmt_execute($stmt);

header('Location: admin.php?view=geofence&titik=berhasil_ubah');
exit;
