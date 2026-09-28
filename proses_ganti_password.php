<?php
/**
 * proses_ganti_password.php
 * ---------------------------------------------------------------------
 * Pegawai mengganti kata sandi akunnya sendiri lewat menu Pengaturan.
 * Wajib memasukkan kata sandi LAMA yang benar (diverifikasi dengan
 * password_verify()) sebelum kata sandi baru disimpan — supaya orang
 * yang kebetulan menemukan sesi login masih terbuka tidak bisa
 * mengambil alih akun hanya dengan mengetik kata sandi baru.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pegawai.php');
    exit;
}
csrf_verify_or_die('pegawai.php');
if (!isset($_SESSION['pegawai_id'])) {
    header('Location: login.php?error=sesi_habis');
    exit;
}

$pegawai_id          = (int) $_SESSION['pegawai_id'];
$password_lama       = $_POST['password_lama'] ?? '';
$password_baru       = $_POST['password_baru'] ?? '';
$password_konfirmasi = $_POST['password_konfirmasi'] ?? '';

if ($password_baru !== $password_konfirmasi) {
    header('Location: pegawai.php?sandi=gagal_konfirmasi#pengaturan');
    exit;
}
if (strlen($password_baru) < 6) {
    header('Location: pegawai.php?sandi=gagal_pendek#pengaturan');
    exit;
}

$stmt = mysqli_prepare($koneksi, "SELECT password FROM users WHERE pegawai_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$user || !password_verify($password_lama, $user['password'])) {
    header('Location: pegawai.php?sandi=gagal_salah#pengaturan');
    exit;
}

$hash_baru = password_hash($password_baru, PASSWORD_BCRYPT);
$stmt = mysqli_prepare($koneksi, "UPDATE users SET password = ? WHERE pegawai_id = ?");
mysqli_stmt_bind_param($stmt, 'si', $hash_baru, $pegawai_id);
mysqli_stmt_execute($stmt);

header('Location: pegawai.php?sandi=berhasil#pengaturan');
exit;
