<?php
/**
 * proses_aktivasi.php
 * ---------------------------------------------------------------------
 * Membuat akun `users` untuk pegawai yang datanya sudah didaftarkan
 * Admin/Kasubbag TU tapi belum bisa login (KF-30). Setelah berhasil,
 * kode_aktivasi di baris pegawai itu di-NULL-kan — sekali pakai, tidak
 * bisa dipakai ulang oleh siapa pun yang belakangan tahu kodenya.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: aktivasi.php');
    exit;
}
csrf_verify_or_die('aktivasi.php');

// 1. Ambil & bersihkan input
$nip           = trim($_POST['nip'] ?? '');
$kode_aktivasi = trim($_POST['kode_aktivasi'] ?? '');
$username      = trim($_POST['username'] ?? '');
$password      = $_POST['password'] ?? '';
$konfirmasi    = $_POST['konfirmasi'] ?? '';

// 2. Validasi wajib isi
if ($nip === '' || $kode_aktivasi === '' || $username === '' || $password === '' || $konfirmasi === '') {
    header('Location: aktivasi.php?error=kosong');
    exit;
}
if (strlen($password) < 6) {
    header('Location: aktivasi.php?error=password_pendek');
    exit;
}
if ($password !== $konfirmasi) {
    header('Location: aktivasi.php?error=password_beda');
    exit;
}

// 3. Cocokkan NIP + kode aktivasi. Diambil dulu lewat NIP saja, lalu
//    dibandingkan pakai hash_equals() (timing-safe) — bukan langsung
//    "WHERE nip=? AND kode_aktivasi=?" di SQL — supaya waktu respons
//    tidak membocorkan info soal kode yang benar sedikit demi sedikit.
$stmt = mysqli_prepare($koneksi, "SELECT id, kode_aktivasi FROM pegawai WHERE nip = ? AND kode_aktivasi IS NOT NULL");
mysqli_stmt_bind_param($stmt, 's', $nip);
mysqli_stmt_execute($stmt);
$pegawai = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$pegawai || !hash_equals((string) $pegawai['kode_aktivasi'], $kode_aktivasi)) {
    // Pesan sengaja digeneralisasi — tidak dibedakan "NIP salah" vs "kode
    // salah" vs "sudah pernah aktivasi", supaya tidak membantu orang lain
    // menebak NIP pegawai mana yang valid.
    header('Location: aktivasi.php?error=tidak_cocok');
    exit;
}

// 4. Username belum dipakai pegawai lain
$stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE username = ?");
mysqli_stmt_bind_param($stmt, 's', $username);
mysqli_stmt_execute($stmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
    header('Location: aktivasi.php?error=username_dipakai');
    exit;
}

// 5. Transaksi: buat akun users + kunci kode_aktivasi supaya sekali pakai
mysqli_begin_transaction($koneksi);
try {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = mysqli_prepare($koneksi, "INSERT INTO users (pegawai_id, username, password, role) VALUES (?, ?, ?, 'pegawai')");
    mysqli_stmt_bind_param($stmt, 'iss', $pegawai['id'], $username, $hash);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($koneksi, "UPDATE pegawai SET kode_aktivasi = NULL WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $pegawai['id']);
    mysqli_stmt_execute($stmt);

    mysqli_commit($koneksi);
    header('Location: login.php?aktivasi=berhasil');
    exit;

} catch (\Throwable $e) {
    mysqli_rollback($koneksi);
    error_log('proses_aktivasi.php gagal: ' . $e->getMessage());
    header('Location: aktivasi.php?error=server');
    exit;
}
