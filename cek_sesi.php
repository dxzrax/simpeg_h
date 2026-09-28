<?php
/**
 * cek_sesi.php
 * ---------------------------------------------------------------------
 * Sertakan file ini di BARIS PALING ATAS setiap halaman yang butuh
 * login (admin.php, pegawai.php) — sebelum <!DOCTYPE html> apa pun:
 *
 *   <?php
 *   require 'cek_sesi.php';
 *   cek_role(['kasubbag', 'admin']);   // opsional, lihat catatan di bawah
 *   ?>
 *   <!DOCTYPE html>
 *   ...
 *
 * File ini yang memanggil session_start() — JANGAN panggil
 * session_start() lagi di admin.php/pegawai.php, cukup sekali di sini.
 * ---------------------------------------------------------------------
 */

session_start();
require 'csrf.php';

// 1. Belum login sama sekali -> tendang ke halaman login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?error=sesi_habis');
    exit;
}

/**
 * 2. (Opsional) Batasi halaman ini hanya untuk peran tertentu.
 *    Contoh di admin.php:   cek_role(['kasubbag', 'admin']);
 *    Contoh di pegawai.php: cek_role(['pegawai']);
 *
 *    Tanpa memanggil cek_role() sama sekali, halaman hanya mensyaratkan
 *    "sudah login" tanpa peduli perannya apa.
 */
function cek_role(array $role_diizinkan) {
    if (!in_array($_SESSION['role'], $role_diizinkan, true)) {
        header('Location: login.php?error=akses_ditolak');
        exit;
    }
}
