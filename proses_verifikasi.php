<?php
/**
 * proses_verifikasi.php
 * ---------------------------------------------------------------------
 * Menerima keputusan Kasubbag TU/admin (Setuju / Tolak) untuk baris
 * absensi yang berstatus 'perlu_verifikasi' — yaitu cek-in yang di luar
 * radius geofence, ada indikasi lokasi palsu, ATAU terlambat 30-60 menit
 * dengan alasan yang diajukan pegawai (lihat proses_cekin.php untuk
 * ketiga kondisi yang men-trigger status ini).
 *
 * Pola-nya sama seperti file proses lain: terima -> validasi -> proses
 * -> redirect. Yang membedakan cuma: file ini dipanggil dari admin.php
 * (bukan pegawai.php), jadi peran yang dicek juga peran admin/kasubbag.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';
require_once 'NotificationGateway.php'; // [POIN 1] Gateway WA + Email

// Periode & tab yang sedang dibuka di admin.php (dikirim lewat hidden input
// di form Setuju/Tolak) — dipakai supaya redirect kembali ke tab & bulan
// yang sama, bukan balik ke Dashboard begitu saja.
$bulan_kembali = isset($_POST['bulan']) ? (int) $_POST['bulan'] : (int) date('n');
$tahun_kembali = isset($_POST['tahun']) ? (int) $_POST['tahun'] : (int) date('Y');
$query_kembali = 'view=kecurigaan&bulan=' . $bulan_kembali . '&tahun=' . $tahun_kembali;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}
csrf_verify_or_die('admin.php?' . $query_kembali);
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['kasubbag', 'admin'], true)) {
    header('Location: login.php?error=akses_ditolak');
    exit;
}

$admin_user_id = (int) $_SESSION['user_id'];
$absensi_id    = isset($_POST['absensi_id']) ? (int) $_POST['absensi_id'] : 0;
$keputusan     = $_POST['keputusan'] ?? ''; // 'setuju' atau 'tolak' — dikirim dari 2 tombol berbeda di admin.php
$catatan       = trim($_POST['catatan'] ?? '');

if ($absensi_id <= 0 || !in_array($keputusan, ['setuju', 'tolak'], true)) {
    header('Location: admin.php?verifikasi=gagal&' . $query_kembali);
    exit;
}

// 1. Pastikan baris ini memang MASIH menunggu verifikasi — mencegah
//    dua admin memproses baris yang sama secara bersamaan, atau
//    seseorang mengklik tombol dua kali.
$stmt = mysqli_prepare($koneksi, "SELECT status_verifikasi FROM absensi WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $absensi_id);
mysqli_stmt_execute($stmt);
$absen = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$absen || $absen['status_verifikasi'] !== 'perlu_verifikasi') {
    header('Location: admin.php?verifikasi=sudah_diproses&' . $query_kembali);
    exit;
}

// 2. Tentukan status baru & catatan default (kalau admin tidak mengisi catatan sendiri)
$status_baru = ($keputusan === 'setuju') ? 'disetujui' : 'ditolak';

if ($catatan === '') {
    $catatan = ($keputusan === 'setuju')
        ? 'Disetujui sebagai kehadiran sah oleh Kasubbag TU'
        : 'Ditolak — dianggap tidak sah oleh Kasubbag TU';
}

// 1b. Ambil pegawai_id & tanggal dari baris absensi (dipakai notifikasi di bawah)
$stmt2 = mysqli_prepare($koneksi, "SELECT pegawai_id, tanggal FROM absensi WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt2, 'i', $absensi_id);
mysqli_stmt_execute($stmt2);
$absen_detail = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));

// 3. Simpan keputusan — inilah yang membuat baris ini muncul di
//    Audit Trail Tukin dengan catatan verifikasi yang lengkap
$stmt = mysqli_prepare($koneksi, "
    UPDATE absensi
    SET status_verifikasi = ?, diverifikasi_oleh = ?, waktu_verifikasi = NOW(), catatan_verifikasi = ?
    WHERE id = ?
");
mysqli_stmt_bind_param($stmt, 'siss', $status_baru, $admin_user_id, $catatan, $absensi_id);
mysqli_stmt_execute($stmt);

// ── [POIN 1] KIRIM NOTIFIKASI EKSTERNAL (WA + Email) ──────────────
// Fire-and-forget: error gateway TIDAK mempengaruhi redirect di bawah.
if ($absen_detail) {
    try {
        $gw = NotificationGateway::fromDb($koneksi, (int) $absen_detail['pegawai_id']);
        if ($gw !== null) {
            $tgl_notif = date('d/m/Y', strtotime($absen_detail['tanggal']));
            $gw->kirimVerifikasiAbsensi($tgl_notif, $status_baru, $catatan);
        }
    } catch (\Throwable $gw_err) {
        error_log('NotificationGateway error (proses_verifikasi): ' . $gw_err->getMessage());
    }
}
// ─────────────────────────────────────────────────────────────────

header('Location: admin.php?verifikasi=berhasil&' . $query_kembali);
exit;

/**
 * =======================================================================
 * CATATAN:
 *
 * File ini SENGAJA belum otomatis menghitung potongan tukin saat
 * ditolak — perhitungan tukin sebaiknya jadi tugas satu file khusus
 * (proses_hitung_tukin.php) yang jalan sebagai rekap akhir bulan,
 * supaya logikanya tidak tercecer di banyak file berbeda.
 * =======================================================================
 */
