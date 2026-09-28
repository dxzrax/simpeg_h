<?php
/**
 * proses_atur_potongan.php
 * ---------------------------------------------------------------------
 * Mengubah nominal di tabel `aturan_potongan` lewat modal "Atur Aturan
 * Potongan" di menu Tunjangan Kinerja. Jenis aturan (R-01 s.d. R-09)
 * SUDAH TETAP (ditentukan skema, dipakai langsung oleh nama jenisnya di
 * proses_hitung_tukin.php) — file ini HANYA mengubah nominalnya, bukan
 * menambah/menghapus jenis aturan baru.
 *
 * Form mengirim SEMUA baris aturan sekaligus (id_aturan[] & nominal[]
 * sejajar indexnya) supaya admin bisa ubah beberapa nominal dalam satu
 * kali submit.
 *
 * PENTING: perubahan di sini TIDAK otomatis menghitung ulang tukin yang
 * sudah ada — admin perlu klik "Hitung Tukin Bulan Ini" lagi (atau
 * tunggu cron_hitung_tukin_draft.php malam berikutnya) supaya nominal
 * baru diterapkan ke periode draft yang berjalan.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';
require 'helpers.php'; // catat_audit()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php?view=tukin');
    exit;
}
csrf_verify_or_die('admin.php?view=tukin');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['kasubbag', 'admin'], true)) {
    header('Location: login.php?error=akses_ditolak');
    exit;
}

$aturan_id = $_POST['aturan_id'] ?? [];
$nominal   = $_POST['nominal'] ?? [];

if (!is_array($aturan_id) || !is_array($nominal) || count($aturan_id) !== count($nominal) || empty($aturan_id)) {
    header('Location: admin.php?view=tukin&aturan=gagal');
    exit;
}

mysqli_begin_transaction($koneksi);
try {
    $stmt_baca   = mysqli_prepare($koneksi, "SELECT * FROM aturan_potongan WHERE id = ?");
    $stmt_update = mysqli_prepare($koneksi, "UPDATE aturan_potongan SET nominal_potongan = ? WHERE id = ?");

    foreach ($aturan_id as $i => $id) {
        $id_int  = (int) $id;
        $nom_val = isset($nominal[$i]) ? (float) $nominal[$i] : null;
        if ($id_int <= 0 || $nom_val === null || $nom_val < 0) {
            continue; // lewati baris yang tidak valid, jangan gagalkan semuanya
        }

        mysqli_stmt_bind_param($stmt_baca, 'i', $id_int);
        mysqli_stmt_execute($stmt_baca);
        $sebelum = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_baca));
        if (!$sebelum) {
            continue; // id tidak ditemukan, lewati
        }

        // Lewati kalau nominalnya memang tidak berubah — form selalu kirim
        // SEMUA baris aturan sekaligus, bukan cuma yang benar-benar diedit,
        // jadi audit_log jangan sampai kebanjiran entri "tidak ada perubahan"
        $nominal_lama = number_format((float) $sebelum['nominal_potongan'], 2, '.', '');
        $nominal_baru = number_format($nom_val, 2, '.', '');
        if ($nominal_lama === $nominal_baru) {
            continue;
        }

        mysqli_stmt_bind_param($stmt_update, 'di', $nom_val, $id_int);
        mysqli_stmt_execute($stmt_update);

        mysqli_stmt_bind_param($stmt_baca, 'i', $id_int);
        mysqli_stmt_execute($stmt_baca);
        $sesudah = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_baca));

        catat_audit($koneksi, 'aturan_potongan', $id_int, 'ubah', $sebelum, $sesudah, (int) $_SESSION['user_id']);
    }
    mysqli_commit($koneksi);
    header('Location: admin.php?view=tukin&aturan=berhasil');
    exit;
} catch (\Throwable $e) {
    mysqli_rollback($koneksi);
    error_log('proses_atur_potongan.php gagal: ' . $e->getMessage());
    header('Location: admin.php?view=tukin&aturan=gagal');
    exit;
}
