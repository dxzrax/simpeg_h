<?php
/**
 * proses_hitung_tukin.php
 * ---------------------------------------------------------------------
 * Dipicu tombol "Hitung Tukin" di menu "Tunjangan Kinerja" (admin.php).
 * Logika perhitungannya sudah dipindah ke hitung_tukin_periode() di
 * helpers.php — Single Source of Truth yang dipakai bersama dengan
 * cron_hitung_tukin_draft.php (otomasi malam hari untuk periode draft).
 * File ini sekarang HANYA menjembatani: sesi & CSRF -> panggil fungsi
 * -> redirect. Aturan bisnis lengkap didokumentasikan di docblock
 * hitung_tukin_periode() di helpers.php, bukan di sini lagi.
 *
 * Pola file: terima -> validasi -> proses -> redirect (sama seperti
 * proses_login.php / proses_cekin.php / proses_verifikasi.php).
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';
require 'helpers.php'; // hitung_tukin_periode()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php?view=tukin');
    exit;
}
csrf_verify_or_die('admin.php?view=tukin');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['kasubbag', 'admin'], true)) {
    header('Location: login.php?error=akses_ditolak');
    exit;
}

$bulan = isset($_POST['bulan']) ? (int) $_POST['bulan'] : (int) date('n');
$tahun = isset($_POST['tahun']) ? (int) $_POST['tahun'] : (int) date('Y');

$hasil = hitung_tukin_periode($koneksi, $bulan, $tahun, (int) $_SESSION['user_id']);

// Petakan status dari helper ke query string yang sudah dikenal admin.php
// ('hitung=berhasil' / 'hitung=sudah_final' / 'hitung=gagal') — toast
// yang sudah ada di admin.php tidak perlu diubah sama sekali.
$peta_status = [
    'berhasil'    => 'berhasil',
    'sudah_final' => 'sudah_final',
];
$status_url = $peta_status[$hasil['status']] ?? 'gagal';

header("Location: admin.php?view=tukin&hitung=$status_url&bulan=$bulan&tahun=$tahun");
exit;
