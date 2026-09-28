<?php
/**
 * proses_input_manual_fingerprint.php
 * ---------------------------------------------------------------------
 * LATAR BELAKANG (hasil wawancara BPTD, 31 Juli 2026): mesin fingerprint
 * kantor sudah lawas — berbasis SD card kecil, mereknya sendiri sudah
 * tidak diketahui, dan TIDAK ADA software export. Selama ini admin
 * mencocokkan data mesin dengan catatan kertas secara manual. Rencana
 * awal skripsi ini (parser otomatis membaca file export mesin) TIDAK
 * REALISTIS untuk kondisi mesin ini — tidak ada file terstruktur yang
 * bisa diparse karena tidak ada software yang menghasilkannya.
 *
 * File ini menggantikan rencana parser itu dengan form input manual:
 * admin tetap melakukan pencocokan manual seperti sekarang (SD card vs
 * kertas), tapi hasilnya diketik ke sini alih-alih ke buku/kertas —
 * tetap tersimpan digital, langsung terhubung ke perhitungan tukin,
 * tanpa perlu perangkat keras baru.
 *
 * Status kehadiran (tepat_waktu/terlambat) DIHITUNG OTOMATIS dari jam
 * masuk yang diinput, pakai aturan jam kerja yang sama dengan cek-in
 * GPS (helpers.php: jam_masuk_standar(), TOLERANSI_STANDAR_MENIT) — jadi
 * potongan tetap konsisten apa pun sumber datanya (fingerprint/GPS).
 * Tidak ada jalur alasan_terlambat di sini (beda dengan proses_cekin.php)
 * karena ini diinput admin sendiri, bukan pegawai yang mengklaim alasan.
 *
 * INSERT ... ON DUPLICATE KEY UPDATE dipakai (bukan INSERT murni) karena
 * baris untuk pegawai+tanggal ini mungkin sudah ada — mis. sudah
 * ditandai 'alpa' otomatis oleh cron_tandai_alpa.php semalam sebelum
 * admin sempat mencocokkan data mesin keesokan harinya.
 *
 * PENGAMAN: kalau baris pegawai+tanggal itu SUDAH ADA dengan sumber
 * BUKAN fingerprint (mis. pegawai sudah cek-in GPS karena dinas luar),
 * proses ini DITOLAK dulu kecuali admin mencentang "konfirmasi_timpa" —
 * supaya foto & koordinat GPS yang sudah ada tidak tertimpa diam-diam
 * gara-gara admin salah pilih pegawai/tanggal di form.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';
require 'helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php?view=kantor');
    exit;
}
csrf_verify_or_die('admin.php?view=kantor');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['kasubbag', 'admin'], true)) {
    header('Location: login.php?error=akses_ditolak');
    exit;
}

$pegawai_id = isset($_POST['pegawai_id']) ? (int) $_POST['pegawai_id'] : 0;
$tanggal    = $_POST['tanggal'] ?? '';
$jam_masuk  = trim($_POST['jam_masuk'] ?? '') ?: null;
$jam_pulang = trim($_POST['jam_pulang'] ?? '') ?: null;
$konfirmasi_timpa = ($_POST['konfirmasi_timpa'] ?? '') === '1';

if ($pegawai_id <= 0 || $tanggal === '' || strtotime($tanggal) === false || $jam_masuk === null) {
    header('Location: admin.php?view=kantor&manual=gagal_kosong');
    exit;
}

// -----------------------------------------------------------------
// Cek dulu apakah pegawai ini SUDAH punya baris absensi di tanggal ini
// dengan sumber BUKAN fingerprint (mis. sudah cek-in GPS karena dinas
// luar). Kalau iya dan admin belum konfirmasi, TOLAK dulu — supaya
// tidak menimpa foto/koordinat GPS yang sudah ada tanpa sadar. Kasus
// ini paling mungkin terjadi kalau admin salah pilih pegawai/tanggal.
// -----------------------------------------------------------------
$stmt = mysqli_prepare($koneksi, "SELECT sumber_absen FROM absensi WHERE pegawai_id = ? AND tanggal = ?");
mysqli_stmt_bind_param($stmt, 'is', $pegawai_id, $tanggal);
mysqli_stmt_execute($stmt);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($existing && $existing['sumber_absen'] !== 'fingerprint' && !$konfirmasi_timpa) {
    header('Location: admin.php?view=kantor&manual=konflik_sumber&pegawai_id=' . $pegawai_id . '&tanggal=' . $tanggal);
    exit;
}

// Status kehadiran dihitung dari jam masuk yang diinput, aturan sama
// persis dengan proses_cekin.php supaya potongan konsisten lintas sumber.
$jam_masuk_standar_hari_ini = jam_masuk_standar($tanggal);
$menit_terlambat = max(0, (int) round((strtotime($jam_masuk) - strtotime($jam_masuk_standar_hari_ini)) / 60));
$status_kehadiran = ($menit_terlambat > TOLERANSI_STANDAR_MENIT) ? 'terlambat' : 'tepat_waktu';

$user_id = (int) $_SESSION['user_id'];
$catatan = 'Diinput manual oleh admin — mesin fingerprint kantor tidak punya software export, dicocokkan dari SD card & catatan kertas.';

$stmt = mysqli_prepare($koneksi, "
    INSERT INTO absensi
        (pegawai_id, tanggal, jam_masuk, jam_pulang, sumber_absen,
         status_radius, status_kehadiran, menit_terlambat,
         status_sinkronisasi, waktu_dibuat_perangkat, waktu_diterima_server,
         status_verifikasi, diverifikasi_oleh, waktu_verifikasi, catatan_verifikasi)
    VALUES
        (?, ?, ?, ?, 'fingerprint',
         'tidak_berlaku', ?, ?,
         'online', NULL, NOW(),
         'otomatis_sah', ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE
        jam_masuk = VALUES(jam_masuk),
        jam_pulang = VALUES(jam_pulang),
        sumber_absen = 'fingerprint',
        status_kehadiran = VALUES(status_kehadiran),
        menit_terlambat = VALUES(menit_terlambat),
        status_verifikasi = 'otomatis_sah',
        diverifikasi_oleh = VALUES(diverifikasi_oleh),
        waktu_verifikasi = NOW(),
        catatan_verifikasi = VALUES(catatan_verifikasi)
");
mysqli_stmt_bind_param(
    $stmt, 'issssiis',
    $pegawai_id, $tanggal, $jam_masuk, $jam_pulang, $status_kehadiran, $menit_terlambat, $user_id, $catatan
);
mysqli_stmt_execute($stmt);

header('Location: admin.php?view=kantor&manual=berhasil');
exit;
