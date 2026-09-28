<?php
/**
 * proses_ajukan_izin.php
 * ---------------------------------------------------------------------
 * Menerima submit dari form "Izin / Cuti / Dinas Luar" di pegawai.php.
 * Polanya identik dengan proses_login.php — jauh lebih sederhana
 * dibanding proses_cekin.php karena tidak ada foto, GPS, atau heuristik
 * apa pun. Cocok dipakai sebagai "contoh kedua" kalau proses_cekin.php
 * kemarin terasa terlalu banyak sekaligus.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';

// 1. Hanya terima kiriman form (POST), tolak akses langsung
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pegawai.php');
    exit;
}
csrf_verify_or_die('pegawai.php');
if (!isset($_SESSION['pegawai_id'])) {
    header('Location: login.php?error=sesi_habis');
    exit;
}

$pegawai_id = (int) $_SESSION['pegawai_id'];

// 2. Ambil & validasi input
//    'jenis' HARUS salah satu dari 3 nilai ini — kalau tidak, berarti
//    ada yang mengutak-atik form-nya (atau bug di sisi HTML), jadi ditolak.
$jenis_diizinkan = ['izin_sakit', 'cuti_tahunan', 'dinas_luar'];

$jenis      = $_POST['jenis'] ?? '';
$tanggal    = $_POST['tanggal'] ?? '';
$keterangan = trim($_POST['keterangan'] ?? '');

if (!in_array($jenis, $jenis_diizinkan, true) || $tanggal === '') {
    header('Location: pegawai.php?pengajuan=gagal#izin');
    exit;
}

if ($keterangan === '') {
    $keterangan = '-';
}

// 3. Simpan ke tabel `pengajuan` — status selalu mulai dari 'menunggu',
//    baru admin/Kasubbag TU yang bisa mengubahnya lewat proses_verifikasi.php
$stmt = mysqli_prepare($koneksi, "
    INSERT INTO pengajuan (pegawai_id, jenis, tanggal, keterangan, status)
    VALUES (?, ?, ?, ?, 'menunggu')
");
mysqli_stmt_bind_param($stmt, 'isss', $pegawai_id, $jenis, $tanggal, $keterangan);
mysqli_stmt_execute($stmt);

// 4. (Opsional tapi bagus) catat notifikasi untuk pegawai itu sendiri,
//    supaya konsisten dengan halaman Notifikasi yang sudah ada
$judul = 'Pengajuan terkirim';
$deskripsi = 'Menunggu persetujuan atasan';
$stmt2 = mysqli_prepare($koneksi, "
    INSERT INTO notifikasi (pegawai_id, judul, deskripsi, jenis)
    VALUES (?, ?, ?, 'warn')
");
mysqli_stmt_bind_param($stmt2, 'iss', $pegawai_id, $judul, $deskripsi);
mysqli_stmt_execute($stmt2);

// 5. Redirect balik ke halaman Izin — pegawai.php membaca ?pengajuan=...
header('Location: pegawai.php?pengajuan=berhasil#izin');
exit;
