<?php
require 'cek_sesi.php';
cek_role(['pegawai']);
require 'koneksi.php';
require 'helpers.php';

$pegawai_id = (int) $_SESSION['pegawai_id'];
$csrf_gagal = ($_GET['error'] ?? '') === 'csrf';

// ===== Data induk pegawai yang sedang login =====
$stmt = mysqli_prepare($koneksi, "
    SELECT p.*, s.nama_seksi, t.nama_lokasi, t.radius_meter
    FROM pegawai p
    LEFT JOIN seksi s ON s.id = p.seksi_id
    LEFT JOIN titik_lokasi_tugas t ON t.id = p.titik_lokasi_id
    WHERE p.id = ?
");
mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
mysqli_stmt_execute($stmt);
$data_pegawai = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$inisial = strtoupper(substr($data_pegawai['nama'], 0, 1) . (strpos($data_pegawai['nama'], ' ') !== false ? substr(strrchr($data_pegawai['nama'], ' '), 1, 1) : ''));

$nama_bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$nama_hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$tanggal_hari_ini_lengkap = $nama_hari[(int) date('w')] . ', ' . date('d') . ' ' . $nama_bulan[(int) date('n')] . ' ' . date('Y');
$bulan_filter = isset($_GET['bulan']) ? max(1, min(12, (int) $_GET['bulan'])) : (int) date('n');
$tahun_filter = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');

// ===== Status hari ini (untuk kartu Beranda) =====
$hari_ini = date('Y-m-d');
$stmt = mysqli_prepare($koneksi, "SELECT jam_masuk, jam_pulang FROM absensi WHERE pegawai_id = ? AND tanggal = ?");
mysqli_stmt_bind_param($stmt, 'is', $pegawai_id, $hari_ini);
mysqli_stmt_execute($stmt);
$absen_hari_ini = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// ===== Statistik ringkas bulan berjalan (Beranda) =====
$stmt = mysqli_prepare($koneksi, "
    SELECT COUNT(*) AS hadir
    FROM absensi
    WHERE pegawai_id = ? AND MONTH(tanggal) = ? AND YEAR(tanggal) = ? AND status_kehadiran IN ('tepat_waktu', 'terlambat')
");
mysqli_stmt_bind_param($stmt, 'iii', $pegawai_id, $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$jml_hadir_bulan_ini = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['hadir'];

$stmt = mysqli_prepare($koneksi, "
    SELECT COUNT(*) AS jml FROM pengajuan
    WHERE pegawai_id = ? AND jenis = 'cuti_tahunan' AND status = 'disetujui' AND YEAR(tanggal) = ?
");
mysqli_stmt_bind_param($stmt, 'ii', $pegawai_id, $tahun_filter);
mysqli_stmt_execute($stmt);
$cuti_terpakai_tahun_ini = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['jml'];

// ===== Tukin periode terpilih + rincian potongan (audit trail milik sendiri) =====
$stmt = mysqli_prepare($koneksi, "SELECT * FROM tukin WHERE pegawai_id = ? AND periode_bulan = ? AND periode_tahun = ?");
mysqli_stmt_bind_param($stmt, 'iii', $pegawai_id, $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$tukin_periode_ini = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$rincian_tukin = [];
if ($tukin_periode_ini) {
    $stmt = mysqli_prepare($koneksi, "
        SELECT td.nominal_potongan, ap.nama_aturan, ap.kode_aturan
        FROM tukin_detail td JOIN aturan_potongan ap ON ap.id = td.aturan_id
        WHERE td.tukin_id = ?
    ");
    mysqli_stmt_bind_param($stmt, 'i', $tukin_periode_ini['id']);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) { $rincian_tukin[] = $row; }
}
$tk_persen = ($tukin_periode_ini && (int) $tukin_periode_ini['hari_kerja_efektif'] > 0)
    ? round($jml_hadir_bulan_ini / (int) $tukin_periode_ini['hari_kerja_efektif'] * 100)
    : null;

// Riwayat slip tukin (6 periode terakhir sebelum periode terpilih)
$stmt = mysqli_prepare($koneksi, "
    SELECT * FROM tukin
    WHERE pegawai_id = ? AND (periode_tahun < ? OR (periode_tahun = ? AND periode_bulan < ?))
    ORDER BY periode_tahun DESC, periode_bulan DESC LIMIT 6
");
mysqli_stmt_bind_param($stmt, 'iiii', $pegawai_id, $tahun_filter, $tahun_filter, $bulan_filter);
mysqli_stmt_execute($stmt);
$riwayat_slip = mysqli_stmt_get_result($stmt);

// ===== Riwayat Absensi bulan terpilih — gabungan absensi + izin/cuti disetujui =====
$riwayat_gabungan = [];
$stmt = mysqli_prepare($koneksi, "
    SELECT tanggal, jam_masuk, jam_pulang, status_radius, status_kehadiran, latitude, longitude, status_sinkronisasi, sumber_absen
    FROM absensi WHERE pegawai_id = ? AND MONTH(tanggal) = ? AND YEAR(tanggal) = ?
");
mysqli_stmt_bind_param($stmt, 'iii', $pegawai_id, $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) { $row['_tipe'] = 'absen'; $riwayat_gabungan[] = $row; }

$stmt = mysqli_prepare($koneksi, "
    SELECT tanggal, jenis, keterangan FROM pengajuan
    WHERE pegawai_id = ? AND status = 'disetujui' AND jenis != 'dinas_luar' AND MONTH(tanggal) = ? AND YEAR(tanggal) = ?
");
mysqli_stmt_bind_param($stmt, 'iii', $pegawai_id, $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) { $row['_tipe'] = 'izin'; $riwayat_gabungan[] = $row; }

usort($riwayat_gabungan, fn($a, $b) => strcmp($b['tanggal'], $a['tanggal']));

// ===== Notifikasi (terbaru dulu) =====
$stmt = mysqli_prepare($koneksi, "SELECT * FROM notifikasi WHERE pegawai_id = ? ORDER BY created_at DESC LIMIT 20");
mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
mysqli_stmt_execute($stmt);
$daftar_notifikasi = mysqli_stmt_get_result($stmt);

// Hitung notifikasi belum dibaca untuk badge
$stmt_unread = mysqli_prepare($koneksi, "SELECT COUNT(*) AS jml FROM notifikasi WHERE pegawai_id = ? AND is_read = 0");
mysqli_stmt_bind_param($stmt_unread, 'i', $pegawai_id);
mysqli_stmt_execute($stmt_unread);
$jml_notif_belum_dibaca = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_unread))['jml'];

// Tandai SEMUA notifikasi sebagai sudah dibaca jika halaman notifikasi dibuka
if (($_GET['screen'] ?? '') === 'notifikasi') {
    mysqli_query($koneksi, "UPDATE notifikasi SET is_read = 1 WHERE pegawai_id = $pegawai_id AND is_read = 0");
    $jml_notif_belum_dibaca = 0;
}

$stmt = mysqli_prepare($koneksi, "SELECT judul, deskripsi, jenis, created_at FROM notifikasi WHERE pegawai_id = ? ORDER BY created_at DESC LIMIT 2");
mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
mysqli_stmt_execute($stmt);
$notifikasi_terbaru = mysqli_stmt_get_result($stmt);

// ===== Kuota cuti tahunan =====
$KUOTA_CUTI_PER_TAHUN = 12; // hari
$stmt_cuti_total = mysqli_prepare($koneksi, "
    SELECT COALESCE(SUM(
        CASE WHEN pg.tanggal_selesai IS NOT NULL
             THEN DATEDIFF(pg.tanggal_selesai, pg.tanggal) + 1
             ELSE 1 END
    ), 0) AS total_hari
    FROM pengajuan pg
    WHERE pg.pegawai_id = ? AND pg.jenis = 'cuti_tahunan' AND pg.status = 'disetujui' AND YEAR(pg.tanggal) = ?
");
mysqli_stmt_bind_param($stmt_cuti_total, 'ii', $pegawai_id, $tahun_filter);
mysqli_stmt_execute($stmt_cuti_total);
$cuti_terpakai_hari = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cuti_total))['total_hari'];
$sisa_cuti = max(0, $KUOTA_CUTI_PER_TAHUN - $cuti_terpakai_hari);

// ===== Riwayat Pengajuan (semua, terbaru dulu) + pesan hasil submit =====
$stmt = mysqli_prepare($koneksi, "SELECT * FROM pengajuan WHERE pegawai_id = ? ORDER BY tanggal_pengajuan DESC");
mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
mysqli_stmt_execute($stmt);
$daftar_pengajuan = mysqli_stmt_get_result($stmt);

$pesan_pengajuan_map = [
    'berhasil'     => ['ok',  'Pengajuan Terkirim', 'Menunggu persetujuan atasan'],
    'gagal'        => ['bad', 'Pengajuan Gagal',    'Jenis pengajuan atau tanggal tidak valid'],
];
$pesan_pengajuan = $pesan_pengajuan_map[$_GET['pengajuan'] ?? ''] ?? null;

/**
 * Pesan hasil cek-in/cek-out dikirim lewat query string oleh
 * proses_cekin.php (mis. pegawai.php?cekin=berhasil) — dibaca di sini
 * lalu ditampilkan lewat toast oleh JS di bagian bawah halaman.
 */
$pesan_cekin_map = [
    'berhasil'                  => ['ok',   'Cek-in Berhasil',        'Dalam radius — otomatis sah'],
    'berhasil_perlu_verifikasi' => ['warn', 'Cek-in Terkirim',        'Menunggu verifikasi atasan'],
    'cekout_berhasil'           => ['ok',   'Cek-out Berhasil',       'Jam pulang tercatat'],
    'sudah_lengkap'             => ['warn', 'Sudah Lengkap',          'Kamu sudah cek-in & cek-out hari ini'],
    'gagal_lokasi'              => ['bad',  'Gagal Cek-in',           'Lokasi GPS tidak terdeteksi — aktifkan izin lokasi'],
    'gagal_foto_wajib'          => ['bad',  'Gagal Cek-in',           'Foto wajib diambil sebelum cek-in'],
    'gagal_format_foto'         => ['bad',  'Gagal Cek-in',           'Format foto harus JPG/JPEG/PNG'],
    'gagal_ukuran_foto'         => ['bad',  'Gagal Cek-in',           'Ukuran foto maksimal 3MB'],
    'gagal_isi_foto'            => ['bad',  'Gagal Cek-in',           'Berkas yang dikirim bukan foto yang valid (JPG/PNG). Ambil ulang foto.'],
];
$kode_cekin  = $_GET['cekin'] ?? '';
$pesan_cekin = $pesan_cekin_map[$kode_cekin] ?? null;

$pesan_sandi_map = [
    'berhasil'        => ['ok',  'Kata Sandi Diubah',   'Gunakan kata sandi baru mulai login berikutnya'],
    'gagal_salah'      => ['bad', 'Kata Sandi Lama Salah', 'Periksa kembali kata sandi lama kamu'],
    'gagal_konfirmasi' => ['bad', 'Konfirmasi Tidak Cocok', 'Kata sandi baru & konfirmasinya harus sama'],
    'gagal_pendek'     => ['bad', 'Kata Sandi Terlalu Pendek', 'Minimal 6 karakter'],
];
$pesan_sandi = $pesan_sandi_map[$_GET['sandi'] ?? ''] ?? null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#0F2438">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="icons/icon-192.png">
<link rel="apple-touch-icon" href="icons/icon-192.png">
<title>SIMPEG-H | Absen Lapangan</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
:root{
  --navy-900:#0F2438; --navy-800:#15304A; --navy-700:#1B3A5C;
  --blue-500:#2C6E9B; --blue-100:#E4EEF5;
  --teal-500:#1E8F82; --teal-600:#177566; --teal-100:#E1F1EE;
  --amber-500:#D89B3C; --amber-100:#FBF0DE;
  --red-500:#C24B4B; --red-100:#FBEAEA;
  --green-500:#2F9464; --green-100:#E7F4EE;
  --bg:#F3F5F6; --surface:#FFFFFF;
  --ink-900:#14202B; --ink-600:#48545E; --ink-400:#8894A0; --line:#E6EAEC;
  --font-display:'Space Grotesk',sans-serif;
  --font-body:'Plus Jakarta Sans',sans-serif;
  --font-mono:'IBM Plex Mono',monospace;
  --safe-b: env(safe-area-inset-bottom, 0px);
  --safe-t: env(safe-area-inset-top, 0px);
}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;}
html,body{height:100%;overscroll-behavior-y:none;}
body{
  font-family:var(--font-body);background:var(--bg);color:var(--ink-900);
  -webkit-font-smoothing:antialiased;
  max-width:480px;margin:0 auto;position:relative;
  min-height:100vh;
}
button{font-family:inherit;border:none;background:none;}
a{text-decoration:none;color:inherit;}

/* ===== TOP APP BAR ===== */
.appbar{
  position:sticky;top:0;z-index:20;background:var(--navy-900);color:#fff;
  padding:14px 18px 16px;
}
.appbar-row{display:flex;justify-content:space-between;align-items:center;}
.appbar .greet{font-family:var(--font-display);font-weight:700;font-size:18px;}
.appbar .sub{font-size:11.5px;color:#8CA3B8;margin-top:2px;}
.appbar .icon-btn{width:38px;height:38px;border-radius:10px;background:#1A3350;display:flex;align-items:center;justify-content:center;font-size:16px;position:relative;}
.appbar .icon-btn .dot{position:absolute;top:7px;right:7px;width:7px;height:7px;border-radius:50%;background:var(--red-500);border:1.5px solid var(--navy-900);}

/* ===== PAGE HEADER (non-beranda) ===== */
.page-head{position:sticky;top:0;z-index:20;background:var(--navy-900);color:#fff;padding:calc(var(--safe-t) + 14px) 18px 16px;}
.page-head h1{font-family:var(--font-display);font-size:18px;font-weight:700;}
.page-head p{font-size:11.5px;color:#8CA3B8;margin-top:3px;}

/* ===== CONTENT ===== */
main{padding:16px 16px calc(90px + var(--safe-b));min-height:calc(100vh - 80px);}
.screen{display:none;}
.screen.active{display:block;animation:fadeIn .18s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

.scope-chip{
  display:flex;align-items:center;gap:8px;background:var(--green-100);color:#1E6B47;
  border-radius:10px;padding:9px 12px;font-size:11px;margin-bottom:14px;
}
.scope-chip .d{width:16px;height:16px;border-radius:50%;background:var(--green-500);color:#fff;display:flex;align-items:center;justify-content:center;font-size:9px;flex-shrink:0;}

/* checkin status hero */
.status-hero{
  background:linear-gradient(150deg,var(--navy-900),var(--navy-700));
  color:#fff;border-radius:18px;padding:20px 20px 22px;margin-bottom:16px;
  box-shadow:0 12px 28px rgba(15,36,56,.22);
}
.status-hero .lbl{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:#9FCBEA;font-weight:600;}
.status-hero .st{font-family:var(--font-display);font-size:21px;font-weight:700;margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.status-hero .dotpulse{width:9px;height:9px;border-radius:50%;background:var(--amber-500);box-shadow:0 0 0 4px rgba(216,155,60,.3);}
.status-hero .meta{font-size:11.5px;color:#B9C7D4;margin-top:8px;font-family:var(--font-mono);}
.status-hero .cta{
  margin-top:16px;width:100%;background:var(--teal-500);color:#fff;border-radius:13px;
  padding:15px;text-align:center;font-family:var(--font-display);font-weight:700;font-size:15px;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.status-hero .cta:active{background:var(--teal-600);}

/* quick stats horizontal scroll */
.hscroll{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px;margin-bottom:16px;-webkit-overflow-scrolling:touch;}
.hscroll::-webkit-scrollbar{display:none;}
.stat-pill{
  flex:0 0 auto;min-width:118px;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:13px 15px;
}
.stat-pill .lbl{font-size:10px;color:var(--ink-400);font-weight:600;text-transform:uppercase;letter-spacing:.3px;}
.stat-pill .val{font-family:var(--font-display);font-size:19px;font-weight:700;margin-top:4px;}
.stat-pill .val span{font-size:11px;color:var(--ink-400);font-weight:600;}

.section-title{display:flex;justify-content:space-between;align-items:center;margin:18px 0 10px;}
.section-title h3{font-family:var(--font-display);font-size:14.5px;font-weight:700;}
.section-title a{font-size:11.5px;color:var(--teal-500);font-weight:600;}

.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:14px 15px;margin-bottom:10px;}
.notif-row{display:flex;gap:11px;align-items:flex-start;}
.notif-ic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.notif-ic.ok{background:var(--green-100);} .notif-ic.warn{background:var(--amber-100);} .notif-ic.info{background:var(--blue-100);}
.notif-row .t{font-size:12.5px;font-weight:700;}
.notif-row .d{font-size:11.5px;color:var(--ink-600);margin-top:2px;line-height:1.4;}
.notif-row .time{font-size:10px;color:var(--ink-400);margin-top:5px;font-family:var(--font-mono);}

/* ===== ABSEN SCREEN ===== */
.map-mobile{
  height:230px;border-radius:16px;position:relative;overflow:hidden;margin-bottom:14px;
  background:repeating-linear-gradient(0deg,#DCE9E6 0 1px,transparent 1px 26px),repeating-linear-gradient(90deg,#DCE9E6 0 1px,transparent 1px 26px),#EAF3F1;
  border:1px solid var(--line);
}
.map-mobile .locbar{position:absolute;top:0;left:0;right:0;background:rgba(30,143,130,.94);color:#fff;padding:11px 14px;}
.map-mobile .locbar .t1{font-size:10px;opacity:.85;}
.map-mobile .locbar .t2{font-family:var(--font-display);font-weight:700;font-size:14px;margin-top:2px;}
.geofence-ring2{position:absolute;top:58%;left:50%;width:150px;height:150px;border-radius:50%;border:2px dashed var(--teal-500);background:rgba(30,143,130,.1);transform:translate(-50%,-50%);}
.geofence-ring2::after{content:'';position:absolute;top:50%;left:50%;width:10px;height:10px;border-radius:50%;background:var(--teal-500);transform:translate(-50%,-50%);box-shadow:0 0 0 6px rgba(30,143,130,.25);}
.pin-you2{position:absolute;top:52%;left:58%;width:9px;height:9px;border-radius:50%;background:var(--red-500);box-shadow:0 0 0 6px rgba(194,75,75,.22);}

.geo-status-mobile{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.geo-status-mobile .l .t{font-weight:700;font-size:13.5px;}
.geo-status-mobile .l .c{font-family:var(--font-mono);font-size:10.5px;color:var(--ink-600);margin-top:3px;}
.badge{font-size:10px;font-weight:700;padding:4px 10px;border-radius:20px;font-family:var(--font-mono);white-space:nowrap;}
.badge.ok{background:var(--green-100);color:var(--green-500);}
.badge.warn{background:var(--amber-100);color:#A9761C;}
.badge.bad{background:var(--red-100);color:var(--red-500);}

.photo-upload{border:1.6px dashed var(--ink-400);border-radius:14px;padding:18px;text-align:center;margin-bottom:14px;color:var(--ink-600);}
.photo-upload .ic{font-size:22px;margin-bottom:6px;}
.photo-upload .t{font-size:12.5px;font-weight:600;}
.photo-upload .s{font-size:10.5px;color:var(--ink-400);margin-top:2px;}

/* ===== POIN 2: LIVENESS DETECTION — Camera + Video UI ===== */
/* Kotak kamera utama — sekarang menampung <video> real-time */
.camera-mandatory{
  border-radius:16px;background:var(--navy-900);position:relative;overflow:hidden;
  height:230px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;
  cursor:pointer;
}
.btn-refresh-loc{
  width:100%;background:none;border:1.5px dashed var(--line);border-radius:10px;
  padding:9px;font-family:var(--font-body);font-size:11.5px;font-weight:600;color:var(--ink-600);
  margin:8px 0 14px;cursor:pointer;
}
.camera-mandatory .cam-ic{font-size:34px;opacity:.55;}
.camera-mandatory .cam-overlay{
  position:absolute;left:10px;right:10px;bottom:10px;background:rgba(0,0,0,.55);border-radius:8px;
  padding:7px 10px;font-family:var(--font-mono);font-size:10px;color:#fff;line-height:1.5;
}
/* Video stream kamera depan (selfie) */
#livenessVideo{
  width:100%;height:100%;object-fit:cover;position:absolute;inset:0;border-radius:16px;
  transform:scaleX(-1); /* mirror selfie — lebih natural */
  display:none;
}
/* Canvas overlay EAR debug di atas video */
#livenessCanvas{
  position:absolute;inset:0;width:100%;height:100%;
  transform:scaleX(-1);pointer-events:none;
}
/* Foto hasil tangkap (setelah liveness lulus) */
#fotoPreview{
  display:none;width:100%;height:100%;object-fit:cover;
  position:absolute;inset:0;border-radius:16px;
}
/* Panel status liveness di bawah video */
#livenessPanel{
  background:var(--navy-800);border-radius:12px;padding:10px 14px;
  margin-bottom:12px;display:none; /* ditampilkan saat kamera aktif */
}
#livenessStatus{
  font-family:var(--font-mono);font-size:11.5px;color:#cbd5e1;
  text-align:center;min-height:18px;
}
#livenessStatus[data-mode="ok"]    { color:#86efac; }
#livenessStatus[data-mode="lulus"] { color:#4ade80; font-weight:700; }
#livenessStatus[data-mode="error"] { color:#fca5a5; }
/* Progress kedipan */
.blink-progress{
  display:flex;gap:6px;justify-content:center;margin-top:8px;
}
.blink-dot{
  width:10px;height:10px;border-radius:50%;background:var(--ink-400);transition:.3s;
}
.blink-dot.aktif{ background:#4ade80; box-shadow:0 0 6px #22c55e; }
/* Tombol fallback (foto manual jika kamera depan tidak ada/gagal) */
#livenesFallbackBtn{
  display:none;width:100%;margin-top:8px;
  background:var(--amber-500);color:#fff;border-radius:10px;padding:9px;
  font-size:12.5px;font-weight:600;font-family:var(--font-body);cursor:pointer;
}
/* Tombol aktifkan kamera / coba lagi */
#livenessStartBtn{
  position:absolute;inset:0;display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:8px;cursor:pointer;
  background:transparent;
}
#livenessStartBtn .start-ic{ font-size:38px;opacity:.65; }
#livenessStartBtn .start-label{
  font-size:12px;font-weight:600;color:#cbd5e1;font-family:var(--font-body);
  background:rgba(255,255,255,.1);padding:5px 14px;border-radius:8px;
}
/* Badge liveness di sudut foto preview setelah lulus */
#livenessBadge{
  display:none;position:absolute;top:8px;right:8px;
  background:rgba(34,197,94,.9);color:#fff;border-radius:20px;
  padding:3px 10px;font-size:11px;font-weight:700;font-family:var(--font-mono);
}
.schedule-row{display:flex;justify-content:space-between;align-items:center;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:12.5px;margin-bottom:12px;}
.schedule-row .k{color:var(--ink-600);}
.offline-toggle{display:flex;justify-content:space-between;align-items:center;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 14px;font-size:11.5px;color:var(--ink-600);margin-bottom:12px;}
.offline-switch{width:38px;height:22px;border-radius:20px;background:var(--line);position:relative;flex-shrink:0;transition:.2s;}
.offline-switch::after{content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.2s;}
.offline-switch.on{background:var(--amber-500);}
.offline-switch.on::after{left:18px;}
.offline-banner{display:none;align-items:center;gap:8px;background:var(--amber-100);color:#7A5A19;border-radius:10px;padding:10px 13px;font-size:11.5px;font-weight:600;margin-bottom:12px;}
.offline-banner.show{display:flex;}
.sync-tag{font-size:9.5px;font-family:var(--font-mono);color:#A9761C;background:var(--amber-100);padding:2px 7px;border-radius:8px;margin-top:4px;display:inline-block;}

.big-cta{
  width:100%;background:var(--teal-500);color:#fff;border-radius:16px;padding:18px;
  font-family:var(--font-display);font-weight:700;font-size:16px;text-align:center;
  box-shadow:0 10px 24px rgba(30,143,130,.32);
}
.big-cta .sub{font-family:var(--font-body);font-weight:500;font-size:11px;opacity:.9;margin-top:3px;}
.big-cta:active{transform:scale(.98);}

/* ===== RIWAYAT ===== */
.filter-row{display:flex;gap:8px;overflow-x:auto;margin-bottom:14px;-webkit-overflow-scrolling:touch;}
.filter-row::-webkit-scrollbar{display:none;}
.chip{flex:0 0 auto;padding:8px 14px;border-radius:20px;font-size:11.5px;font-weight:600;background:var(--surface);border:1px solid var(--line);color:var(--ink-600);}
.chip.sel{background:var(--navy-700);color:#fff;border-color:var(--navy-700);}
.hist-item{display:flex;justify-content:space-between;align-items:center;padding:13px 0;border-bottom:1px solid var(--line);}
.hist-item:last-child{border-bottom:none;}
.hist-item .day{width:44px;text-align:center;flex-shrink:0;}
.hist-item .day .n{font-family:var(--font-display);font-weight:700;font-size:17px;}
.hist-item .day .m{font-size:9.5px;color:var(--ink-400);text-transform:uppercase;}
.hist-item .mid{flex:1;padding:0 10px;}
.hist-item .mid .t{font-size:12.5px;font-weight:600;}
.hist-item .mid .c{font-size:10.5px;color:var(--ink-400);font-family:var(--font-mono);margin-top:2px;}

/* ===== TUKIN ===== */
.slip-mobile{background:linear-gradient(150deg,var(--navy-900),var(--navy-800));color:#fff;border-radius:16px;padding:20px;margin-bottom:16px;}
.slip-mobile .p{font-size:11px;color:#8CA3B8;}
.slip-mobile .amt{font-family:var(--font-display);font-size:28px;font-weight:700;margin-top:6px;}
.slip-mobile .n{font-size:11.5px;color:#B9C7D4;margin-top:4px;}
.slip-row{display:flex;justify-content:space-between;padding:11px 0;border-bottom:1px dashed var(--line);font-size:12.5px;}
.slip-row:last-child{border-bottom:none;}

/* ===== MENU ===== */
.menu-item{display:flex;align-items:center;gap:13px;padding:15px 4px;border-bottom:1px solid var(--line);}
.menu-item:last-child{border-bottom:none;}
.menu-item .ic{width:38px;height:38px;border-radius:10px;background:var(--blue-100);color:var(--blue-500);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.menu-item .t{font-size:13.5px;font-weight:600;flex:1;}
.menu-item .chev{color:var(--ink-400);font-size:14px;}
.profile-mini{display:flex;align-items:center;gap:13px;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:16px;}
.profile-mini .av{width:52px;height:52px;border-radius:50%;background:var(--amber-500);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:700;font-size:18px;color:var(--navy-900);flex-shrink:0;}
.profile-mini .n{font-family:var(--font-display);font-weight:700;font-size:15px;}
.profile-mini .r{font-size:11.5px;color:var(--ink-600);margin-top:2px;}

/* ===== BOTTOM NAV ===== */
.bottomnav{
  position:fixed;left:50%;transform:translateX(-50%);bottom:0;width:100%;max-width:480px;
  background:var(--surface);border-top:1px solid var(--line);
  display:flex;justify-content:space-around;align-items:center;
  padding:8px 4px calc(8px + var(--safe-b));
  z-index:30;box-shadow:0 -4px 20px rgba(15,36,56,.06);
}
.nav-btn{display:flex;flex-direction:column;align-items:center;gap:3px;color:var(--ink-400);flex:1;padding:6px 2px;position:relative;}
.nav-btn .ic{font-size:19px;}
.nav-btn .lb{font-size:9.5px;font-weight:600;}
.nav-btn.active{color:var(--teal-500);}
.nav-btn .nbadge{position:absolute;top:2px;right:22%;width:7px;height:7px;border-radius:50%;background:var(--red-500);}
.nav-fab{position:relative;top:-16px;}
.nav-fab .ic{width:52px;height:52px;border-radius:50%;background:var(--teal-500);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 8px 20px rgba(30,143,130,.4);}
.nav-fab.active .ic{background:var(--teal-600);}
.nav-fab .lb{color:var(--ink-600);margin-top:2px;}

/* toast (checkin success) */
.toast{
  position:fixed;left:50%;bottom:96px;transform:translateX(-50%) translateY(20px);
  max-width:420px;width:88%;background:var(--navy-900);color:#fff;border-radius:14px;padding:14px 16px;
  display:flex;align-items:center;gap:11px;opacity:0;pointer-events:none;transition:.28s ease;z-index:50;
}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.toast .ic{width:32px;height:32px;border-radius:50%;background:var(--green-500);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
/* ===== SUB-HALAMAN (Izin, Notifikasi, Profil, Pengaturan) ===== */
.ph-row{display:flex;align-items:center;gap:12px;}
.back-btn{width:34px;height:34px;border-radius:10px;background:#1A3350;color:#fff;font-size:17px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.back-btn:active{background:#22456b;}

.form-field{margin-bottom:13px;}
.form-field label{display:block;font-size:11.5px;font-weight:600;color:var(--ink-600);margin-bottom:6px;}
.form-field select,.form-field input[type=date],.form-field input[type=text],.form-field textarea{
  width:100%;padding:10px 12px;border:1.5px solid var(--line);border-radius:10px;
  font-family:var(--font-body);font-size:13px;background:var(--bg);outline:none;color:var(--ink-900);
}
.form-field select:focus,.form-field input:focus,.form-field textarea:focus{border-color:var(--teal-500);background:#fff;}
.form-field textarea{resize:vertical;min-height:68px;}
.btn-submit{width:100%;background:var(--navy-900);color:#fff;padding:13px;border-radius:12px;font-weight:700;font-size:13.5px;text-align:center;margin-top:2px;}
.btn-submit:active{background:var(--navy-700);}

.pengajuan-item{display:flex;justify-content:space-between;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--line);gap:8px;}
.pengajuan-item:last-child{border-bottom:none;}
.pengajuan-item .jt{font-size:12.5px;font-weight:700;}
.pengajuan-item .jd{font-size:10.5px;color:var(--ink-400);font-family:var(--font-mono);margin-top:2px;}
.pengajuan-item .jk{font-size:11.5px;color:var(--ink-600);margin-top:3px;}

.profile-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.profile-field{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:10px 12px;}
.profile-field.full{grid-column:1 / -1;}
.profile-field .k{font-size:9.5px;text-transform:uppercase;color:var(--ink-400);font-weight:600;letter-spacing:.3px;}
.profile-field .v{font-size:12.5px;font-weight:600;margin-top:4px;word-break:break-word;}
.profile-mini .edit-btn{margin-left:auto;font-size:11px;font-weight:700;color:var(--teal-500);background:var(--teal-100);padding:7px 12px;border-radius:8px;flex-shrink:0;}

.settings-row{display:flex;justify-content:space-between;align-items:center;padding:13px 0;border-bottom:1px solid var(--line);gap:10px;}
.settings-row:last-child{border-bottom:none;}
.settings-row .t{font-size:12.5px;font-weight:600;}
.settings-row .s{font-size:10.5px;color:var(--ink-400);margin-top:2px;}
.btn-mini{font-size:11px;font-weight:700;color:var(--teal-500);background:var(--teal-100);padding:7px 12px;border-radius:8px;flex-shrink:0;}
.switch{width:38px;height:22px;border-radius:20px;background:var(--line);position:relative;flex-shrink:0;transition:.2s;cursor:pointer;}
.switch::after{content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.2s;}
.switch.on{background:var(--teal-500);}
.switch.on::after{left:18px;}
.settings-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--ink-400);font-weight:700;margin:18px 0 8px;}
.settings-label:first-child{margin-top:0;}
</style>
</head>
<body>

<!-- ================= BERANDA ================= -->
<section class="screen active" id="s-beranda">
  <div class="appbar">
    <div class="appbar-row">
      <div>
        <div class="greet">Halo, <?php echo htmlspecialchars(explode(' ', $data_pegawai['nama'])[0]); ?> 👋</div>
        <div class="sub"><?php echo $tanggal_hari_ini_lengkap . ($data_pegawai['nama_seksi'] ? ' · Seksi ' . htmlspecialchars($data_pegawai['nama_seksi']) : ''); ?></div>
      </div>
      <button class="icon-btn" onclick="tandaiNotifDibacaDanBuka()" id="btnNotifBell">🔔<?php if ($jml_notif_belum_dibaca > 0): ?><span class="dot" id="dotNotifBell"></span><?php endif; ?></button>
    </div>
  </div>

  <?php if ($csrf_gagal): ?>
  <div class="card" style="border-left:4px solid var(--red-500);margin:12px 15px 0"><b>Sesi form kedaluwarsa</b> — silakan ulangi aksinya.</div>
  <?php endif; ?>

  <main>
    <div class="scope-chip"><span class="d">✓</span>Data yang tampil hanya milikmu sendiri</div>

    <div class="status-hero">
      <div class="lbl">Status Hari Ini</div>
      <div class="st"><span class="dotpulse"></span><?php echo ($absen_hari_ini && $absen_hari_ini['jam_masuk']) ? (($absen_hari_ini['jam_pulang']) ? 'Sudah Cek-out' : 'Sudah Cek-in') : 'Belum Cek-in'; ?></div>
      <div class="meta">📍 <?php echo htmlspecialchars($data_pegawai['nama_lokasi'] ?? 'Belum ada titik tugas terdaftar'); ?><?php echo $data_pegawai['radius_meter'] ? ' · radius ' . (int) $data_pegawai['radius_meter'] . ' m' : ''; ?></div>
      <?php if (!($absen_hari_ini && $absen_hari_ini['jam_masuk'] && $absen_hari_ini['jam_pulang'])): ?>
      <button class="cta" onclick="goTo('absen')">📍 <?php echo ($absen_hari_ini && $absen_hari_ini['jam_masuk']) ? 'Cek-out Sekarang' : 'Cek-in Sekarang'; ?></button>
      <?php endif; ?>
    </div>

    <div class="hscroll">
      <div class="stat-pill"><div class="lbl">Hadir Bulan Ini</div><div class="val"><?php echo $jml_hadir_bulan_ini; ?><span><?php echo $tukin_periode_ini ? '/' . (int) $tukin_periode_ini['hari_kerja_efektif'] . ' hr' : ' hr'; ?></span></div></div>
      <div class="stat-pill"><div class="lbl">Tukin Periode Ini</div><div class="val"><?php echo $tukin_periode_ini ? number_format($tukin_periode_ini['tukin_diterima'] / 1000000, 2) . '<span>jt</span>' : '<span style="font-size:12px">Belum dihitung</span>'; ?></div></div>
      <div class="stat-pill"><div class="lbl">Cuti Terpakai <?php echo $tahun_filter; ?></div><div class="val"><?php echo $cuti_terpakai_tahun_ini; ?><span>hari</span></div></div>
      <div class="stat-pill"><div class="lbl">Tk. Kehadiran</div><div class="val"><?php echo $tk_persen !== null ? $tk_persen : '—'; ?><span><?php echo $tk_persen !== null ? '%' : ''; ?></span></div></div>
    </div>

    <div class="section-title"><h3>Notifikasi Terbaru</h3><a onclick="goTo('notifikasi')">Lihat Semua</a></div>
    <?php if (mysqli_num_rows($notifikasi_terbaru) === 0): ?>
    <div class="card"><div style="padding:14px;color:var(--ink-400);font-size:12.5px;text-align:center">Belum ada notifikasi.</div></div>
    <?php endif; ?>
    <?php while ($n = mysqli_fetch_assoc($notifikasi_terbaru)):
        $ic = ['ok' => '✓', 'warn' => '!', 'info' => 'i'][$n['jenis']] ?? 'i';
    ?>
    <div class="card">
      <div class="notif-row"><div class="notif-ic <?php echo $n['jenis']; ?>"><?php echo $ic; ?></div><div><div class="t"><?php echo htmlspecialchars($n['judul']); ?></div><div class="d"><?php echo htmlspecialchars($n['deskripsi'] ?? ''); ?></div><div class="time"><?php echo date('d M, H:i', strtotime($n['created_at'])); ?></div></div></div>
    </div>
    <?php endwhile; ?>
  </main>
</section>

<!-- ================= ABSEN ================= -->
<section class="screen" id="s-absen">
  <div class="page-head"><h1>Cek-in / Cek-out</h1><p>Verifikasi lokasi GPS sebelum absen tercatat</p></div>
  <main>
    <div class="offline-toggle">
      <span>Paksa mode offline (untuk area sinyal lemah)</span>
      <div class="offline-switch" id="offlineSwitch" onclick="toggleOffline()"></div>
    </div>
    <div class="offline-banner" id="offlineBanner">📶 Tidak ada koneksi internet — foto &amp; data akan tersimpan lokal di HP ini dan otomatis terkirim begitu sinyal kembali</div>

    <div class="card" id="antrianOfflineCard" style="display:none;margin-bottom:12px;padding:12px 14px;border-left:4px solid var(--amber-500)">
      <b id="antrianOfflineTeks">0 cek-in menunggu disinkronkan</b>
      <div style="font-size:11.5px;color:var(--ink-400);margin:2px 0 8px">Tersimpan di HP ini, belum terkirim ke server</div>
      <button type="button" class="btn-refresh-loc" style="margin:0" onclick="sinkronkanAntrianManual()">⟲ Sinkronkan Sekarang</button>
    </div>

    <form id="formCekin" action="proses_cekin.php" method="POST" enctype="multipart/form-data">
      <?php echo csrf_field(); ?>
      <!-- Field tersembunyi ini diisi otomatis oleh JS sebelum form dikirim -->
      <input type="hidden" name="latitude" id="inpLat">
      <input type="hidden" name="longitude" id="inpLng">
      <input type="hidden" name="akurasi_gps" id="inpAkurasi">
      <input type="hidden" name="mode" id="inpMode" value="online">
      <input type="hidden" name="waktu_device" id="inpWaktu">

      <div class="map-mobile" id="mapCekin" style="height:230px;border-radius:16px;overflow:hidden;margin-bottom:14px;border:1px solid var(--line);background:#e8f0fe;"></div>
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"><\/script>

      <div class="geo-status-mobile">
        <div class="l"><div class="t">Status Lokasi</div><div class="c" id="lokasiText">Mencari lokasi GPS…</div></div>
        <span class="badge warn" id="lokasiBadge">Mencari…</span>
      </div>
      <button type="button" class="btn-refresh-loc" onclick="ambilLokasi()">🔄 Perbarui Lokasi GPS</button>

      <!-- ── [POIN 2] LIVENESS DETECTION — Kotak Kamera + Video ──────── -->
      <!-- Kotak ini sekarang menampung: video stream kamera depan + canvas
           overlay EAR debug + foto hasil tangkap + tombol mulai kamera.
           Input file foto disembunyikan dan diisi secara programatik oleh
           liveness.js setelah blink terdeteksi + overlay GPS dibakar. -->
      <div class="camera-mandatory" id="cameraBox">
        <!-- Video kamera depan (selfie, ditampilkan saat deteksi aktif) -->
        <video id="livenessVideo" autoplay muted playsinline></video>
        <!-- Canvas overlay EAR landmark debug (di atas video, mirror juga) -->
        <canvas id="livenessCanvas"></canvas>
        <!-- Foto preview setelah liveness lulus + overlay GPS dibakar -->
        <img id="fotoPreview" alt="Foto cek-in">
        <!-- Badge "LIVENESS ✓" di sudut foto -->
        <span id="livenessBadge">LIVENESS ✓</span>
        <!-- Tombol/area klik untuk mulai kamera (layar awal sebelum kamera aktif) -->
        <button type="button" id="livenessStartBtn" onclick="livenessAktifkan()">
          <span class="start-ic">👁</span>
          <span class="start-label">Ketuk untuk Verifikasi Wajah</span>
        </button>
        <!-- Overlay teks GPS (saat video aktif, menampilkan lokasi saat ini) -->
        <div class="cam-overlay" id="camOverlay" style="display:none"></div>
      </div>

      <!-- Panel status + progress blink (muncul saat kamera aktif) -->
      <div id="livenessPanel">
        <div id="livenessStatus" data-mode="neutral">⏳ Memulai kamera…</div>
        <div class="blink-progress" id="blinkProgress">
          <!-- Dot progress diisi oleh JS sesuai BLINK_REQUIRED -->
        </div>
      </div>

      <!-- Tombol fallback: ganti ke foto manual jika kamera depan gagal -->
      <button type="button" id="livenesFallbackBtn" onclick="livenessFallback()">
        📷 Ambil Foto Manual (tanpa verifikasi wajah)
      </button>

      <!-- Input file foto — diisi programatik oleh liveness.js, tersembunyi -->
      <input type="file" name="foto" id="inpFoto" accept="image/*"
             style="display:none" onchange="previewFoto(this)">
      <!-- Input hidden penanda liveness lulus (dikirim ke proses_cekin.php) -->
      <input type="hidden" name="liveness_passed" id="inpLivenessPassed" value="0">

      <div id="alasanTerlambatBox" style="display:none;background:var(--amber-50,#fffbeb);border:1px solid var(--amber-200,#fde68a);border-radius:12px;padding:12px;margin:10px 0">
        <div id="alasanTerlambatHint" style="font-size:12px;color:var(--ink-600);margin-bottom:6px"></div>
        <textarea name="alasan_terlambat" id="inpAlasanTerlambat" rows="2" maxlength="255" placeholder="Tulis alasan keterlambatan (opsional, ditinjau Kasubbag TU)" style="width:100%;border:1px solid var(--line);border-radius:8px;padding:8px;font-size:13px;font-family:inherit"></textarea>
      </div>

      <div class="schedule-row"><span class="k">Jadwal Dinas Luar</span><span class="badge" style="background:var(--line);color:var(--ink-600)">Dicek otomatis oleh sistem</span></div>

      <button type="submit" class="big-cta" id="checkinBtn">Cek-in Sekarang<div class="sub" id="checkinBtnSub"><?php echo htmlspecialchars($data_pegawai['nama']) . ' · Seksi ' . htmlspecialchars($data_pegawai['nama_seksi'] ?? '—'); ?></div></button>
    </form>
  </main>
</section>

<!-- ================= RIWAYAT ================= -->
<section class="screen" id="s-riwayat">
  <div class="page-head"><h1>Riwayat Absensi</h1><p>Rekap kehadiran milikmu sendiri</p></div>
  <main>
    <div class="filter-row">
      <?php for ($i = 0; $i < 3; $i++):
          $b = $bulan_filter - $i; $t = $tahun_filter;
          if ($b <= 0) { $b += 12; $t -= 1; }
      ?>
      <a href="pegawai.php?bulan=<?php echo $b; ?>&tahun=<?php echo $t; ?>#riwayat" class="chip <?php echo ($b === $bulan_filter && $t === $tahun_filter) ? 'sel' : ''; ?>" style="text-decoration:none" onclick="goTo('riwayat')"><?php echo $nama_bulan[$b] . ' ' . $t; ?></a>
      <?php endfor; ?>
    </div>
    <div class="card">
      <?php if (empty($riwayat_gabungan)): ?>
      <div style="padding:16px;text-align:center;color:var(--ink-400);font-size:12.5px">Belum ada riwayat pada periode ini.</div>
      <?php endif; ?>
      <?php foreach ($riwayat_gabungan as $rw):
          $tgl = (int) date('d', strtotime($rw['tanggal']));
          $bln3 = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][(int) date('n', strtotime($rw['tanggal']))];
          if ($rw['_tipe'] === 'izin'):
              $label_jenis = ['izin_sakit' => 'Izin Sakit', 'cuti_tahunan' => 'Cuti Tahunan'][$rw['jenis']] ?? $rw['jenis'];
      ?>
      <div class="hist-item"><div class="day"><div class="n"><?php echo $tgl; ?></div><div class="m"><?php echo $bln3; ?></div></div><div class="mid"><div class="t"><?php echo htmlspecialchars($label_jenis); ?></div><div class="c">Disetujui<?php echo $rw['keterangan'] ? ' · ' . htmlspecialchars($rw['keterangan']) : ''; ?></div></div><span class="badge warn">Cuti</span></div>
      <?php else:
          $jam = ($rw['jam_masuk'] ? substr($rw['jam_masuk'], 0, 5) : '—') . ' – ' . ($rw['jam_pulang'] ? substr($rw['jam_pulang'], 0, 5) : '—');
          $label_hadir = $rw['status_kehadiran'] === 'terlambat' ? 'Terlambat' : 'Hadir';
          $badge_class = $rw['status_radius'] === 'luar_radius' ? 'bad' : 'ok';
          $badge_txt = $rw['status_radius'] === 'luar_radius' ? 'Luar Radius' : ($rw['sumber_absen'] === 'geolocation' ? 'Dlm Radius' : 'Fingerprint');
      ?>
      <div class="hist-item"><div class="day"><div class="n"><?php echo $tgl; ?></div><div class="m"><?php echo $bln3; ?></div></div><div class="mid"><div class="t"><?php echo $label_hadir; ?> · <?php echo $jam; ?></div><div class="c"><?php echo ($rw['latitude'] !== null) ? round($rw['latitude'], 3) . ', ' . round($rw['longitude'], 3) : '—'; ?></div><?php if ($rw['status_sinkronisasi'] === 'tersinkron'): ?><div class="sync-tag">⟳ Disinkronkan (offline)</div><?php endif; ?></div><span class="badge <?php echo $badge_class; ?>"><?php echo $badge_txt; ?></span></div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </main>
</section>

<!-- ================= TUKIN ================= -->
<section class="screen" id="s-tukin">
  <div class="page-head"><h1>Slip Tunjangan Kinerja</h1><p>Periode <?php echo $nama_bulan[$bulan_filter] . ' ' . $tahun_filter; ?></p></div>
  <main>
    <?php if (!$tukin_periode_ini): ?>
    <div class="card" style="padding:20px;text-align:center;color:var(--ink-400);font-size:12.5px">Slip periode ini belum tersedia — menunggu Kasubbag TU menghitung tukin bulan ini.</div>
    <?php else: ?>
    <div class="slip-mobile">
      <div class="p">Total Diterima</div>
      <div class="amt">Rp <?php echo number_format($tukin_periode_ini['tukin_diterima'], 0, ',', '.'); ?></div>
      <div class="n"><?php echo htmlspecialchars($data_pegawai['nama']); ?> · NIP <?php echo htmlspecialchars($data_pegawai['nip']); ?></div>
    </div>
    <div class="card">
      <div class="slip-row"><span>Tukin Pokok</span><span>Rp <?php echo number_format($tukin_periode_ini['tukin_pokok'], 0, ',', '.'); ?></span></div>
      <div class="slip-row"><span>Hari Kerja Efektif</span><span><?php echo (int) $tukin_periode_ini['hari_kerja_efektif']; ?> hari</span></div>
      <?php if (empty($rincian_tukin)): ?>
      <div class="slip-row"><span>Potongan</span><span>Tidak ada</span></div>
      <?php else: foreach ($rincian_tukin as $rt): ?>
      <div class="slip-row"><span><?php echo htmlspecialchars($rt['nama_aturan']); ?></span><span>Rp <?php echo number_format($rt['nominal_potongan'], 0, ',', '.'); ?></span></div>
      <?php endforeach; endif; ?>
      <div class="slip-row" style="font-weight:700;border-top:1px solid var(--line);padding-top:10px;margin-top:4px"><span>Status</span><span><?php echo $tukin_periode_ini['status'] === 'final' ? 'Final' : 'Draft (belum dikunci)'; ?></span></div>
    </div>
    <?php endif; ?>

    <div class="section-title"><h3>Riwayat Slip</h3></div>
    <div class="card">
      <?php if (mysqli_num_rows($riwayat_slip) === 0): ?>
      <div style="padding:16px;text-align:center;color:var(--ink-400);font-size:12.5px">Belum ada riwayat slip periode sebelumnya.</div>
      <?php endif; ?>
      <?php while ($rs = mysqli_fetch_assoc($riwayat_slip)):
          $badge_slip = ((float) $rs['total_potongan'] > 0) ? 'warn' : 'ok';
      ?>
      <div class="hist-item"><div class="mid"><div class="t"><?php echo $nama_bulan[(int) $rs['periode_bulan']] . ' ' . $rs['periode_tahun']; ?></div><div class="c"><?php echo ((float) $rs['total_potongan'] > 0) ? 'Potongan Rp ' . number_format($rs['total_potongan'], 0, ',', '.') : 'Tanpa potongan'; ?></div></div><span class="badge <?php echo $badge_slip; ?>"><?php echo number_format($rs['tukin_diterima'], 0, ',', '.'); ?></span></div>
      <?php endwhile; ?>
    </div>
  </main>
</section>

<!-- ================= MENU ================= -->
<section class="screen" id="s-menu">
  <div class="page-head"><h1>Menu Saya</h1><p>Akun, pengajuan, dan pengaturan</p></div>
  <main>
    <div class="profile-mini">
      <div class="av"><?php echo htmlspecialchars($inisial); ?></div>
      <div><div class="n"><?php echo htmlspecialchars($data_pegawai['nama']); ?></div><div class="r"><?php echo htmlspecialchars($data_pegawai['jabatan'] ?? '—'); ?> · Metode: <?php echo $data_pegawai['metode_absen'] === 'geolocation' ? 'Geolocation' : 'Fingerprint'; ?></div></div>
    </div>

    <div class="card" style="padding:4px 15px">
      <div class="menu-item" onclick="goTo('izin')" style="cursor:pointer"><div class="ic">📝</div><div class="t">Izin / Cuti / Dinas Luar</div><div class="chev">›</div></div>
      <div class="menu-item" onclick="goTo('notifikasi')" style="cursor:pointer"><div class="ic">🔔</div><div class="t">Notifikasi</div><div class="chev">›</div></div>
      <div class="menu-item" onclick="goTo('profil')" style="cursor:pointer"><div class="ic">👤</div><div class="t">Profil Saya</div><div class="chev">›</div></div>
      <div class="menu-item" onclick="goTo('pengaturan')" style="cursor:pointer"><div class="ic">⚙️</div><div class="t">Pengaturan</div><div class="chev">›</div></div>
      <div class="menu-item" onclick="alert('Hubungi Kasubbag TU di kantor BPTD Kelas II Lampung untuk bantuan.')" style="cursor:pointer"><div class="ic">💬</div><div class="t">Bantuan</div><div class="chev">›</div></div>
    </div>

    <div class="card" style="text-align:center;color:var(--red-500);font-weight:600;font-size:13px;padding:14px;cursor:pointer" onclick="doLogout()">Keluar Akun</div>
  </main>
</section>

<!-- ================= IZIN / CUTI / DINAS LUAR ================= -->
<section class="screen" id="s-izin">
  <div class="page-head">
    <div class="ph-row">
      <button class="back-btn" onclick="goTo('menu')">‹</button>
      <div><h1>Izin / Cuti / Dinas Luar</h1><p>Ajukan langsung, pantau status persetujuan atasan</p></div>
    </div>
  </div>
  <main>
    <?php if ($pesan_pengajuan): ?>
    <div class="card" style="border-left:4px solid var(--<?php echo $pesan_pengajuan[0] === 'ok' ? 'green' : 'red'; ?>-500);padding:12px 14px;margin-bottom:12px">
      <b><?php echo htmlspecialchars($pesan_pengajuan[1]); ?></b><br><span style="font-size:12px;color:var(--ink-600)"><?php echo htmlspecialchars($pesan_pengajuan[2]); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($csrf_gagal): ?>
    <div class="card" style="border-left:4px solid var(--red-500);padding:12px 14px;margin-bottom:12px"><b>Sesi form kedaluwarsa</b> — silakan ulangi pengajuan.</div>
    <?php endif; ?>
    <div class="section-title" style="margin-top:0"><h3>Ajukan Baru</h3></div>
    <div class="card">
      <form method="POST" action="proses_ajukan_izin.php">
        <?php echo csrf_field(); ?>
        <div class="form-field">
          <label for="jenisPengajuan">Jenis Pengajuan</label>
          <select id="jenisPengajuan" name="jenis">
            <option value="dinas_luar">Dinas Luar</option>
            <option value="cuti_tahunan">Cuti Tahunan</option>
            <option value="izin_sakit">Izin Sakit</option>
          </select>
        </div>
        <div class="form-field">
          <label for="tanggalPengajuan">Tanggal Mulai</label>
          <input type="date" id="tanggalPengajuan" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-field" id="tanggalSelesaiBox" style="display:none">
          <label for="tanggalSelesaiPengajuan">Tanggal Selesai <span style="font-size:10px;color:var(--ink-400)">(opsional, biarkan kosong untuk 1 hari)</span></label>
          <input type="date" id="tanggalSelesaiPengajuan" name="tanggal_selesai" min="<?php echo date('Y-m-d'); ?>">
        </div>
        <?php if ($sisa_cuti <= 3): ?>
        <div id="kutotiCutiInfo" style="font-size:11.5px;padding:8px 10px;border-radius:8px;margin-bottom:10px;background:<?php echo $sisa_cuti <= 0 ? 'var(--red-100)' : 'var(--amber-100)'; ?>;color:<?php echo $sisa_cuti <= 0 ? 'var(--red-500)' : '#7A5A19'; ?>">
          <?php if ($sisa_cuti <= 0): ?>⛔ Kuota cuti tahunan <?php echo $tahun_filter; ?> sudah habis (<?php echo $cuti_terpakai_hari; ?>/<?php echo $KUOTA_CUTI_PER_TAHUN; ?> hari terpakai).
          <?php else: ?>⚠ Sisa kuota cuti <?php echo $tahun_filter; ?>: <b><?php echo $sisa_cuti; ?> hari</b> (dari <?php echo $KUOTA_CUTI_PER_TAHUN; ?> hari).
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="form-field">
          <label for="keteranganPengajuan">Keterangan</label>
          <textarea id="keteranganPengajuan" name="keterangan" placeholder="Contoh: Rampcheck di Terminal Rajabasa"></textarea>
        </div>
        <button type="submit" class="btn-submit">Kirim Pengajuan</button>
      </form>
    </div>

    <div class="section-title"><h3>Riwayat Pengajuan</h3></div>
    <div class="card" id="riwayatPengajuan">
      <?php if (mysqli_num_rows($daftar_pengajuan) === 0): ?>
      <div style="padding:16px;text-align:center;color:var(--ink-400);font-size:12.5px">Belum ada pengajuan.</div>
      <?php endif; ?>
      <?php while ($pj = mysqli_fetch_assoc($daftar_pengajuan)):
          $label_jenis = ['izin_sakit' => 'Izin Sakit', 'cuti_tahunan' => 'Cuti Tahunan', 'dinas_luar' => 'Dinas Luar'][$pj['jenis']] ?? $pj['jenis'];
          $badge_pj = ['menunggu' => 'warn', 'disetujui' => 'ok', 'ditolak' => 'bad'][$pj['status']];
          $label_status = ['menunggu' => 'Menunggu Persetujuan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'][$pj['status']];
      ?>
      <div class="pengajuan-item">
        <div><div class="jt"><?php echo htmlspecialchars($label_jenis); ?></div><div class="jd"><?php echo tgl_id($pj['tanggal']); ?></div><div class="jk"><?php echo htmlspecialchars($pj['keterangan'] ?? '—'); ?></div></div>
        <span class="badge <?php echo $badge_pj; ?>"><?php echo $label_status; ?></span>
      </div>
      <?php endwhile; ?>
    </div>
  </main>
</section>

<!-- ================= NOTIFIKASI ================= -->
<section class="screen" id="s-notifikasi">
  <div class="page-head">
    <div class="ph-row">
      <button class="back-btn" onclick="goTo('menu')">‹</button>
      <div><h1>Notifikasi</h1><p>Pengingat keterlambatan, status pengajuan, dan info tukin</p></div>
    </div>
  </div>
  <main>
    <?php if (mysqli_num_rows($daftar_notifikasi) === 0): ?>
    <div class="card"><div style="padding:16px;text-align:center;color:var(--ink-400);font-size:12.5px">Belum ada notifikasi.</div></div>
    <?php endif; ?>
    <?php while ($n = mysqli_fetch_assoc($daftar_notifikasi)):
        $ic = ['ok' => '✓', 'warn' => '!', 'info' => 'i'][$n['jenis']] ?? 'i';
        $selisih_jam = (time() - strtotime($n['created_at'])) / 3600;
        $waktu_tampil = $selisih_jam < 24 ? max(1, (int) round($selisih_jam)) . ' jam lalu' : tgl_id($n['created_at']);
        $is_unread = !$n['is_read'];
    ?>
    <div class="card" style="<?php echo $is_unread ? 'border-left:3px solid var(--teal-500);' : ''; ?>">
      <div class="notif-row"><div class="notif-ic <?php echo $n['jenis']; ?>"><?php echo $ic; ?></div><div><div class="t"><?php echo htmlspecialchars($n['judul']); ?><?php if ($is_unread): ?> <span style="font-size:9px;background:var(--teal-500);color:#fff;padding:1px 6px;border-radius:8px;vertical-align:middle">BARU</span><?php endif; ?></div><div class="d"><?php echo htmlspecialchars($n['deskripsi'] ?? ''); ?></div><div class="time"><?php echo $waktu_tampil; ?></div></div></div>
    </div>
    <?php endwhile; ?>
    <?php if ($jml_notif_belum_dibaca > 0): ?>
    <div style="text-align:center;padding:12px">
      <a href="pegawai.php?screen=notifikasi" style="font-size:11.5px;color:var(--teal-500);font-weight:600">Tandai semua sudah dibaca →</a>
    </div>
    <?php endif; ?>
  </main>
</section>

<!-- ================= PROFIL SAYA ================= -->
<section class="screen" id="s-profil">
  <div class="page-head">
    <div class="ph-row">
      <button class="back-btn" onclick="goTo('menu')">‹</button>
      <div><h1>Profil Saya</h1><p>Data kepegawaian — hubungi Kasubbag TU untuk perubahan data ini</p></div>
    </div>
  </div>
  <main>
    <div class="profile-mini">
      <div class="av"><?php echo htmlspecialchars($inisial); ?></div>
      <div><div class="n"><?php echo htmlspecialchars($data_pegawai['nama']); ?></div><div class="r"><?php echo htmlspecialchars($data_pegawai['jabatan'] ?? '—'); ?> · Kelas Jabatan <?php echo htmlspecialchars($data_pegawai['kelas_jabatan'] ?? '—'); ?></div></div>
      <button class="edit-btn" onclick="alert('Hubungi Kasubbag TU untuk mengubah foto profil.')">Ubah Foto</button>
    </div>

    <div class="profile-field-grid">
      <div class="profile-field"><div class="k">NIP</div><div class="v"><?php echo htmlspecialchars($data_pegawai['nip']); ?></div></div>
      <div class="profile-field"><div class="k">Seksi</div><div class="v"><?php echo htmlspecialchars($data_pegawai['nama_seksi'] ?? '—'); ?></div></div>
      <div class="profile-field"><div class="k">Metode Absensi</div><div class="v"><?php echo $data_pegawai['metode_absen'] === 'geolocation' ? 'Geolocation (Lapangan)' : 'Fingerprint (Kantor)'; ?></div></div>
      <div class="profile-field"><div class="k">Titik Tugas Terdaftar</div><div class="v"><?php echo htmlspecialchars($data_pegawai['nama_lokasi'] ?? '—'); ?></div></div>
      <div class="profile-field"><div class="k">TMT Jabatan</div><div class="v"><?php echo $data_pegawai['tmt_jabatan'] ? tgl_id($data_pegawai['tmt_jabatan']) : '—'; ?></div></div>
      <div class="profile-field"><div class="k">Status Kepegawaian</div><div class="v"><?php echo ucfirst($data_pegawai['status_kepegawaian']); ?></div></div>
      <div class="profile-field"><div class="k">Nomor HP Terdaftar</div><div class="v"><?php echo htmlspecialchars($data_pegawai['nomor_hp'] ?? '—'); ?></div></div>
      <div class="profile-field"><div class="k">Email</div><div class="v" style="word-break:break-all"><?php echo htmlspecialchars($data_pegawai['email'] ?? '—'); ?></div></div>
    </div>
  </main>
</section>

<!-- ================= PENGATURAN ================= -->
<section class="screen" id="s-pengaturan">
  <div class="page-head">
    <div class="ph-row">
      <button class="back-btn" onclick="goTo('menu')">‹</button>
      <div><h1>Pengaturan</h1><p>Kelola akun dan preferensi notifikasi</p></div>
    </div>
  </div>
  <main>
    <?php if ($pesan_sandi): ?>
    <div class="card" style="border-left:4px solid var(--<?php echo $pesan_sandi[0] === 'ok' ? 'green' : 'red'; ?>-500);padding:12px 14px;margin-bottom:12px">
      <b><?php echo htmlspecialchars($pesan_sandi[1]); ?></b><br><span style="font-size:12px;color:var(--ink-600)"><?php echo htmlspecialchars($pesan_sandi[2]); ?></span>
    </div>
    <?php endif; ?>
    <div class="settings-label">Akun</div>
    <div class="card" style="padding:14px 15px">
      <form method="POST" action="proses_ganti_password.php">
        <?php echo csrf_field(); ?>
        <div class="form-field"><label>Kata Sandi Lama</label><input type="password" name="password_lama" required></div>
        <div class="form-field"><label>Kata Sandi Baru</label><input type="password" name="password_baru" placeholder="min. 6 karakter" required></div>
        <div class="form-field"><label>Konfirmasi Kata Sandi Baru</label><input type="password" name="password_konfirmasi" required></div>
        <button type="submit" class="btn-submit">Ganti Kata Sandi</button>
      </form>
    </div>

    <div class="settings-label">Notifikasi</div>
    <input type="hidden" id="csrfTokenNotifikasi" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <div class="card" style="padding:2px 15px">
      <div class="settings-row">
        <div><div class="t">Notifikasi WhatsApp</div><div class="s">Pengingat cek-in dan status pengajuan</div></div>
        <div class="switch<?php echo $data_pegawai['notif_wa_aktif'] ? ' on' : ''; ?>" onclick="toggleNotifikasi(this, 'wa')"></div>
      </div>
      <div class="settings-row">
        <div><div class="t">Notifikasi Email</div><div class="s">Slip tukin bulanan</div></div>
        <div class="switch<?php echo $data_pegawai['notif_email_aktif'] ? ' on' : ''; ?>" onclick="toggleNotifikasi(this, 'email')"></div>
      </div>
      <div class="settings-row">
        <div><div class="t">Pengingat Cek-in Otomatis</div><div class="s">Diingatkan bila belum cek-in jam 08:15</div></div>
        <div class="switch<?php echo $data_pegawai['notif_pengingat_cekin_aktif'] ? ' on' : ''; ?>" onclick="toggleNotifikasi(this, 'pengingat_cekin')"></div>
      </div>
    </div>
  </main>
</section>

<!-- ================= BOTTOM NAV ================= -->
<nav class="bottomnav">
  <button class="nav-btn active" data-view="beranda" onclick="goTo('beranda')"><span class="ic">⌂</span><span class="lb">Beranda</span></button>
  <button class="nav-btn" data-view="riwayat" onclick="goTo('riwayat')"><span class="ic">🗓</span><span class="lb">Riwayat</span></button>
  <button class="nav-btn nav-fab" data-view="absen" onclick="goTo('absen')"><span class="ic">📍</span><span class="lb">Absen</span></button>
  <button class="nav-btn" data-view="tukin" onclick="goTo('tukin')"><span class="ic">💳</span><span class="lb">Tukin</span></button>
  <button class="nav-btn" data-view="menu" onclick="goTo('menu')"><span class="ic">☰</span><span class="lb">Menu</span></button>
</nav>

<div class="toast" id="toast">
  <div class="ic">✓</div>
  <div><div class="t">Cek-in Berhasil</div><div class="d">09:41 WIB · Dalam radius (Sah)</div></div>
</div>

<script src="offline.js"></script>
<script>
function goTo(view){
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
  document.getElementById('s-'+view).classList.add('active');
  document.querySelectorAll('.nav-btn').forEach(b=>b.classList.toggle('active', b.dataset.view===view));
  window.scrollTo(0,0);
}

/**
 * showToast() dipakai toggleNotifikasi() di atas untuk konfirmasi visual
 * setelah preferensi berhasil/gagal disimpan ke server, dan di beberapa
 * tempat lain (hasil cek-in, dst). Memakai elemen <div class="toast"
 * id="toast"> — CSS-nya tinggal toggle class .show.
 */
let _toastTimeout = null;
function showToast(judul, deskripsi){
  const t = document.getElementById('toast');
  if (!t) return;
  t.querySelector('.t').textContent = judul;
  t.querySelector('.d').textContent = deskripsi || '';
  t.classList.add('show');
  clearTimeout(_toastTimeout);
  _toastTimeout = setTimeout(() => t.classList.remove('show'), 3500);
}
let isOffline = false;
/**
 * Toggle notifikasi di layar Pengaturan — optimistic UI (nyala/mati
 * duluan di layar) lalu dikonfirmasi ke server lewat fetch(). Kalau
 * server menolak (mis. sesi habis), toggle DIKEMBALIKAN ke posisi
 * semula supaya tampilan tidak berbohong soal apa yang benar-benar
 * tersimpan.
 */
async function toggleNotifikasi(el, jenis){
  const sudahAktifSebelumnya = el.classList.contains('on');
  el.classList.toggle('on'); // optimistic — langsung terasa responsif
  const aktifSekarang = el.classList.contains('on');

  try{
    const csrfToken = document.getElementById('csrfTokenNotifikasi').value;
    const body = new URLSearchParams({ csrf_token: csrfToken, jenis, aktif: aktifSekarang ? '1' : '0' });
    const res  = await fetch('proses_atur_notifikasi.php', { method: 'POST', body });
    const data = await res.json();
    if (!data.ok) throw new Error(data.pesan || 'Gagal menyimpan');
    showToast('Preferensi Tersimpan', aktifSekarang ? 'Notifikasi diaktifkan' : 'Notifikasi dimatikan');
  }catch(err){
    el.classList.toggle('on', sudahAktifSebelumnya); // batalkan optimistic update
    showToast('Gagal Menyimpan', 'Koneksi bermasalah — coba lagi');
  }
}

// Data pegawai aktif dari PHP — dipakai di tombol cek-in & JS lainnya
const _NAMA_PEGAWAI = <?php echo json_encode($data_pegawai['nama']); ?>;
const _SEKSI_PEGAWAI = <?php echo json_encode($data_pegawai['nama_seksi'] ?? '—'); ?>;
const _METODE_ABSEN  = <?php echo json_encode($data_pegawai['metode_absen']); ?>;

function toggleOffline(){
  isOffline = !isOffline;
  document.getElementById('offlineSwitch').classList.toggle('on', isOffline);
  document.getElementById('offlineBanner').classList.toggle('show', isOffline);
  document.getElementById('inpMode').value = isOffline ? 'offline' : 'online';
  const btn = document.getElementById('checkinBtn');
  const subTeks = _NAMA_PEGAWAI + ' · Seksi ' + _SEKSI_PEGAWAI;
  btn.innerHTML = isOffline
    ? 'Simpan Cek-in (Offline)<div class="sub">' + subTeks + '</div>'
    : 'Cek-in Sekarang<div class="sub">' + subTeks + '</div>';
  btn.style.background = isOffline ? 'var(--amber-500)' : '';
}

/* ===== AMBIL LOKASI GPS ASLI ===== */
function ambilLokasi(){
  const badge = document.getElementById('lokasiBadge');
  const teks  = document.getElementById('lokasiText');
  badge.className = 'badge warn';
  badge.textContent = 'Mencari…';
  teks.textContent = 'Mencari lokasi GPS…';

  if(!navigator.geolocation){
    badge.className = 'badge bad';
    badge.textContent = 'Tidak Didukung';
    teks.textContent = 'Perangkat/peramban tidak mendukung GPS';
    return;
  }

  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      const akurasi = pos.coords.accuracy;

      document.getElementById('inpLat').value = lat;
      document.getElementById('inpLng').value = lng;
      document.getElementById('inpAkurasi').value = akurasi;

      badge.className = 'badge ok';
      badge.textContent = 'Lokasi Ditemukan';
      teks.textContent = `${lat.toFixed(4)}°, ${lng.toFixed(4)}° · akurasi ${Math.round(akurasi)} m`;

      // Kalau foto sudah diambil duluan sebelum lokasi ketemu, gambar ulang
      // overlay-nya sekarang supaya koordinat yang tertanam di foto akurat
      if(fotoAsliDataUrl){ gambarUlangOverlay(); }
    },
    (err) => {
      badge.className = 'badge bad';
      badge.textContent = 'Gagal Ambil Lokasi';
      teks.textContent = 'Izinkan akses lokasi di peramban, lalu coba lagi';
    },
    { enableHighAccuracy: true, timeout: 10000 }
  );
}

/* =====================================================================
   FOTO WAJIB + OVERLAY GPS/WAKTU YANG BENAR-BENAR "NEMPEL" DI FILE
   -----------------------------------------------------------------
   Alurnya: foto asli dibaca -> digambar ulang ke <canvas> -> teks
   GPS+waktu digambar di atasnya SEBAGAI PIKSEL (bukan lapisan HTML
   terpisah) -> canvas diubah jadi file JPG baru -> file baru inilah
   yang dipasang balik ke <input type="file"> pakai DataTransfer,
   supaya yang benar-benar terkirim ke proses_cekin.php adalah foto
   yang sudah ada overlay-nya, persis seperti hasil kamera aplikasi
   absensi pada umumnya.
   ===================================================================== */
let fotoAsliDataUrl = null; // simpan foto ASLI (sebelum overlay) supaya bisa digambar ulang kalau lokasi baru ketemu belakangan
let fotoSiap = false; // true setelah canvas selesai membakar overlay & menggantikan file di input

function previewFoto(input){
  if(!input.files || !input.files[0]) return;
  fotoSiap = false;
  const reader = new FileReader();
  reader.onload = (e) => {
    fotoAsliDataUrl = e.target.result;
    gambarUlangOverlay();
  };
  reader.readAsDataURL(input.files[0]);
}

function gambarUlangOverlay(){
  if(!fotoAsliDataUrl) return;

  const img = new Image();
  img.onload = () => {
    const canvas = document.createElement('canvas');
    canvas.width  = img.width;
    canvas.height = img.height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0);

    const lat   = document.getElementById('inpLat').value;
    const lng   = document.getElementById('inpLng').value;
    const waktu = new Date().toLocaleString('id-ID', {
      day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit'
    });
    const baris1 = (lat && lng) ? `${parseFloat(lat).toFixed(4)}, ${parseFloat(lng).toFixed(4)}` : 'Menunggu lokasi GPS…';
    const baris2 = `${waktu} WIB`;

    // Kotak semi-transparan di bagian bawah foto, ukurannya menyesuaikan resolusi foto
    const tinggiKotak = Math.max(56, img.height * 0.1);
    const ukuranFont  = Math.max(16, Math.round(img.width * 0.03));

    ctx.fillStyle = 'rgba(0,0,0,0.55)';
    ctx.fillRect(0, img.height - tinggiKotak, img.width, tinggiKotak);

    ctx.fillStyle = '#ffffff';
    ctx.font = `${ukuranFont}px monospace`;
    ctx.textBaseline = 'middle';
    ctx.fillText(baris1, 16, img.height - tinggiKotak * 0.62);
    ctx.fillText(baris2, 16, img.height - tinggiKotak * 0.24);

    // Tampilkan hasilnya sebagai pratinjau
    const previewImg = document.getElementById('fotoPreview');
    previewImg.src = canvas.toDataURL('image/jpeg', 0.92);
    previewImg.style.display = 'block';
    document.getElementById('camIcon').style.display = 'none';
    document.getElementById('camOverlay').style.display = 'none'; // sudah nempel di foto, lapisan HTML ini tak diperlukan lagi

    // Ubah canvas jadi file JPG baru, lalu PASANG BALIK ke <input type="file">
    canvas.toBlob((blob) => {
      const fileBaru = new File([blob], 'cekin_' + Date.now() + '.jpg', { type: 'image/jpeg' });
      const dt = new DataTransfer();
      dt.items.add(fileBaru);
      document.getElementById('inpFoto').files = dt.files;
      fotoSiap = true;
    }, 'image/jpeg', 0.92);
  };
  img.src = fotoAsliDataUrl;
}

/* ===== CEK KETERLAMBATAN (cermin aturan server: helpers.php) =====
 * Senin-Kamis masuk 07:00, Jumat 07:30. Toleransi 30 menit bebas
 * potongan; 30-60 menit bisa diajukan alasan ke Kasubbag TU; di atas
 * 60 menit alasan tetap bisa ditulis untuk catatan tapi tidak
 * membebaskan potongan (keputusan akhir tetap di server, ini hanya
 * membantu pegawai tahu situasinya sebelum menekan Cek-in).
 */
function cekStatusKeterlambatan(){
  const sekarang = new Date();
  const hari = sekarang.getDay(); // 0=Minggu..6=Sabtu
  if (hari === 0 || hari === 6) return; // akhir pekan, tak relevan

  const jamMasukStandar = new Date(sekarang);
  if (hari === 5) { jamMasukStandar.setHours(7, 30, 0, 0); } // Jumat
  else            { jamMasukStandar.setHours(7, 0, 0, 0); }  // Senin-Kamis

  const menitTerlambat = Math.round((sekarang - jamMasukStandar) / 60000);
  const box  = document.getElementById('alasanTerlambatBox');
  const hint = document.getElementById('alasanTerlambatHint');
  if (!box || !hint) return;

  if (menitTerlambat <= 30) {
    box.style.display = 'none';
    return;
  }
  box.style.display = 'block';
  if (menitTerlambat <= 60) {
    hint.textContent = `Sudah lewat ${menitTerlambat} menit dari jam masuk. Masih bisa diajukan alasan ke Kasubbag TU — kalau disetujui, potongan hari ini dibebaskan.`;
  } else {
    hint.textContent = `Sudah lewat ${menitTerlambat} menit dari jam masuk (di atas 1 jam) — potongan tunjangan hari ini otomatis berlaku. Alasan tetap bisa ditulis untuk catatan.`;
  }
}
cekStatusKeterlambatan();

/* ===== SUBMIT FORM CEK-IN (fetch + antrian offline sungguhan) ===== */
document.getElementById('formCekin').addEventListener('submit', async function(e){
  e.preventDefault(); // SELALU dicegat sekarang — kita yang atur pengirimannya via fetch/antrian

  const form = e.target;
  const lat = document.getElementById('inpLat').value;
  const lng = document.getElementById('inpLng').value;
  const fileFoto = document.getElementById('inpFoto').files[0];

  if(!lat || !lng){
    alert('Lokasi GPS belum terdeteksi. Tekan "Perbarui Lokasi GPS" dan izinkan akses lokasi dulu.');
    return;
  }
  if(!fileFoto){
    alert('Foto wajib diambil sebelum cek-in.');
    return;
  }
  if(!fotoSiap){
    alert('Foto sedang diproses (menambahkan GPS & waktu), tunggu sebentar lalu tekan Cek-in lagi.');
    return;
  }

  // Catat waktu SAAT tombol ditekan — inilah yang jadi "waktu kehadiran resmi"
  document.getElementById('inpWaktu').value = new Date().toISOString().slice(0,19).replace('T',' ');

  const btn = document.getElementById('checkinBtn');
  btn.disabled = true;
  btn.style.opacity = '.7';

  // Kumpulkan semua field non-file dari form jadi objek biasa, supaya
  // bisa disimpan ke IndexedDB kalau ternyata perlu diantre (Blob foto
  // disimpan terpisah, bukan lewat FormData, karena FormData sendiri
  // tidak bisa disimpan langsung ke IndexedDB).
  const fields = {};
  new FormData(form).forEach((val, key) => { if (key !== 'foto') fields[key] = val; });

  const paksaOffline = (typeof isOffline !== 'undefined' && isOffline);

  if (!paksaOffline && navigator.onLine !== false) {
    try {
      const fd = new FormData(form);
      const res = await fetch(form.action, { method: 'POST', body: fd });
      // fetch mengikuti redirect bawaan proses_cekin.php — res.url adalah
      // URL akhir setelah redirect (pegawai.php?cekin=...#...)
      window.location.href = res.url || 'pegawai.php?cekin=berhasil#beranda';
      return;
    } catch (err) {
      // fetch gagal total -> benar-benar tidak ada koneksi, lanjut ke
      // jalur antrian offline di bawah (bukan alert error ke pengguna)
    }
  }

  // ===== JALUR OFFLINE: simpan ke IndexedDB, jangan hilangkan data pegawai =====
  try {
    await offlineSimpanAntrian(fields, fileFoto, form.action);
    document.getElementById('offlineBanner').classList.add('show');
    alert('Tidak ada koneksi — cek-in tersimpan di HP ini dan akan otomatis terkirim begitu sinyal kembali.');
    await offlineInitServiceWorker(); // minta Background Sync coba lagi otomatis nanti
    await perbaruiBadgeAntrian();
    goTo('beranda');
  } catch (err) {
    alert('Gagal menyimpan cek-in secara lokal. Coba lagi, atau tunggu sampai sinyal kembali.');
    console.error('offlineSimpanAntrian gagal:', err);
  } finally {
    btn.disabled = false;
    btn.style.opacity = '';
  }
});

/* ===== ANTRIAN OFFLINE: badge, sinkron manual, jaring pengaman ===== */
async function perbaruiBadgeAntrian(){
  try {
    const jml = await offlineHitungAntrian();
    const kartu = document.getElementById('antrianOfflineCard');
    document.getElementById('antrianOfflineTeks').textContent = jml + ' cek-in menunggu disinkronkan';
    kartu.style.display = jml > 0 ? 'block' : 'none';
  } catch (e) { /* IndexedDB belum siap / tidak didukung — abaikan, bukan fitur kritis */ }
}

async function sinkronkanAntrianManual(){
  const hasil = await offlineSinkronkanSekarang();
  if (hasil.sesiHabis) {
    alert('Sesi login sudah berakhir selagi offline. Cek-in yang tertunda tetap tersimpan — silakan masuk ulang, lalu sinkronkan lagi dari sini.');
  } else if (hasil.berhasil > 0) {
    showToast(hasil.berhasil + ' cek-in berhasil disinkronkan', 'Data sudah tercatat di server');
  } else if (!hasil.sesiHabis && hasil.berhasil === 0 && hasil.ditolak === 0) {
    alert('Belum ada koneksi ke server — dicoba lagi nanti.');
  }
  if (hasil.ditolak > 0) {
    tampilkanPeringatanDitolak(hasil.ditolak);
  }
  await perbaruiBadgeAntrian();
}

/** Tampilkan peringatan item ditolak tanpa menghapus datanya. */
function tampilkanPeringatanDitolak(jumlah) {
  let el = document.getElementById('peringatanAntrianDitolak');
  if (!el) {
    el = document.createElement('div');
    el.id = 'peringatanAntrianDitolak';
    el.style.cssText = 'background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;margin:8px 0;font-size:14px;color:#856404;';
    // Sisipkan setelah antrianOfflineCard jika ada
    const kartu = document.getElementById('antrianOfflineCard');
    if (kartu && kartu.parentNode) {
      kartu.parentNode.insertBefore(el, kartu.nextSibling);
    } else {
      document.body.prepend(el);
    }
  }
  el.textContent = jumlah + ' cek-in offline ditolak server. Hubungi Kasubbag TU untuk tindak lanjut.';
  el.style.display = 'block';
}

// Jaring pengaman: coba sinkron begitu koneksi kembali, atau saat
// halaman baru dibuka (menutupi browser yang tidak dukung Background Sync)
window.addEventListener('online', () => { sinkronkanAntrianManual(); });
document.addEventListener('DOMContentLoaded', () => {
  offlineInitServiceWorker();
  perbaruiBadgeAntrian();
  if (navigator.onLine) sinkronkanAntrianManual();
});

// Pesan dari sw.js (hasil Background Sync di latar belakang)
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data?.tipe === 'sinkron_berhasil') {
      showToast('Cek-in tersinkron', 'Cek-in yang tersimpan lokal sudah terkirim ke server');
      perbaruiBadgeAntrian();
    } else if (event.data?.tipe === 'sesi_habis') {
      perbaruiBadgeAntrian();
    } else if (event.data?.tipe === 'ditolak') {
      // Item ditolak permanen oleh server via Background Sync
      tampilkanPeringatanDitolak(1);
      perbaruiBadgeAntrian();
    }
  });
}


// Ambil lokasi otomatis begitu layar Cek-in dibuka
document.querySelector('.nav-btn[data-view="absen"]')?.addEventListener('click', ambilLokasi);
document.querySelector('.status-hero .cta')?.addEventListener('click', ambilLokasi);

// Tampilkan toast hasil cek-in/cek-out kalau ada (dikirim lewat redirect proses_cekin.php)
<?php if ($pesan_cekin): ?>
window.addEventListener('DOMContentLoaded', () => {
  showToast(<?php echo json_encode($pesan_cekin[1]); ?>, <?php echo json_encode($pesan_cekin[2]); ?>);
});
<?php endif; ?>

// Buka layar yang sesuai kalau URL membawa hash (mis. hasil redirect
// dari proses_ajukan_izin.php / proses_ganti_password.php / klik chip
// filter bulan di Riwayat Absensi) — tanpa ini, reload halaman akan
// selalu kembali ke layar Beranda.
(function(){
  const hash = window.location.hash.replace('#', '');
  if (hash && document.getElementById('s-' + hash)) {
    goTo(hash);
  }
})();

<?php if ($pesan_pengajuan): ?>
window.addEventListener('DOMContentLoaded', () => {
  showToast(<?php echo json_encode($pesan_pengajuan[1]); ?>, <?php echo json_encode($pesan_pengajuan[2]); ?>);
});
<?php endif; ?>

<?php if ($pesan_sandi): ?>
window.addEventListener('DOMContentLoaded', () => {
  showToast(<?php echo json_encode($pesan_sandi[1]); ?>, <?php echo json_encode($pesan_sandi[2]); ?>);
});
<?php endif; ?>

function doLogout(){
  if(confirm('Keluar dari akun? Kamu perlu login kembali untuk mengakses SIMPEG-H.')){
    window.location.href = 'logout.php?from=pegawai';
  }
}

/* ===== TANDAI NOTIFIKASI DIBACA + BUKA HALAMAN ===== */
function tandaiNotifDibacaDanBuka() {
  // Panggil endpoint mark-as-read via fetch, lalu buka halaman
  const csrfToken = document.getElementById('csrfTokenNotifikasi')?.value || '';
  fetch('proses_tandai_notif_dibaca.php', {
    method: 'POST',
    body: new URLSearchParams({ csrf_token: csrfToken })
  }).catch(() => {}); // silent — tidak blokir navigasi
  // Hilangkan dot badge segera (optimistic)
  const dot = document.getElementById('dotNotifBell');
  if (dot) dot.style.display = 'none';
  goTo('notifikasi');
}

/* ===== PETA LEAFLET (Cek-in Lapangan) ===== */
let _mapCekin = null;
let _markerPegawai = null;
let _circleGeofence = null;

function inisiasiPetaCekin() {
  if (typeof L === 'undefined') return; // Leaflet belum dimuat
  if (_mapCekin) return; // sudah diinisiasi

  const titikLat  = <?php echo json_encode((float)($data_pegawai['latitude']  ?? -5.4291)); ?>;
  const titikLng  = <?php echo json_encode((float)($data_pegawai['longitude'] ?? 105.2612)); ?>;
  const radius    = <?php echo json_encode((int)($data_pegawai['radius_meter'] ?? 150)); ?>;
  const namaLokasi = <?php echo json_encode($data_pegawai['nama_lokasi'] ?? 'Titik Tugas'); ?>;

  // Inisiasi peta pada div #mapCekin
  _mapCekin = L.map('mapCekin', { zoomControl: false, attributionControl: false }).setView([titikLat, titikLng], 16);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap'
  }).addTo(_mapCekin);

  // Lingkaran geofence
  _circleGeofence = L.circle([titikLat, titikLng], {
    radius,
    color: '#1E8F82',
    fillColor: '#1E8F82',
    fillOpacity: 0.12,
    weight: 2,
    dashArray: '6,4'
  }).addTo(_mapCekin);

  // Marker titik tugas
  const iconTitik = L.divIcon({
    html: '<div style="width:12px;height:12px;border-radius:50%;background:#1E8F82;box-shadow:0 0 0 6px rgba(30,143,130,.25)"></div>',
    iconSize: [12, 12], iconAnchor: [6, 6], className: ''
  });
  L.marker([titikLat, titikLng], { icon: iconTitik })
    .addTo(_mapCekin)
    .bindPopup('<b>' + namaLokasi + '</b><br>Radius: ' + radius + ' m');

  // Bar nama lokasi di atas peta
  const infoBar = L.control({ position: 'topleft' });
  infoBar.onAdd = () => {
    const d = L.DomUtil.create('div');
    d.innerHTML = '<div style="background:rgba(30,143,130,.94);color:#fff;padding:9px 14px;border-radius:0 0 10px 0;font-family:var(--font-body);font-size:12px;max-width:260px"><div style="font-size:10px;opacity:.85">TITIK TUGAS</div><div style="font-weight:700;margin-top:2px">' + namaLokasi + '</div></div>';
    return d;
  };
  infoBar.addTo(_mapCekin);
}

function perbaruiPosisiPeta(lat, lng) {
  if (!_mapCekin) inisiasiPetaCekin();
  if (!_mapCekin) return;
  const ikonPegawai = L.divIcon({
    html: '<div style="width:10px;height:10px;border-radius:50%;background:var(--red-500);box-shadow:0 0 0 6px rgba(194,75,75,.22)"></div>',
    iconSize: [10, 10], iconAnchor: [5, 5], className: ''
  });
  if (_markerPegawai) _markerPegawai.setLatLng([lat, lng]);
  else _markerPegawai = L.marker([lat, lng], { icon: ikonPegawai }).addTo(_mapCekin).bindPopup('Posisi kamu sekarang');
  _mapCekin.setView([lat, lng], 16);
}

// Panggil inisiasi peta saat tab Absen dibuka
document.querySelector('.nav-btn[data-view="absen"]')?.addEventListener('click', () => {
  setTimeout(inisiasiPetaCekin, 300);
});
document.querySelector('.status-hero .cta')?.addEventListener('click', () => {
  setTimeout(inisiasiPetaCekin, 300);
});

/* ===== FORM CUTI — tampilkan tanggal selesai untuk cuti tahunan ===== */
document.getElementById('jenisPengajuan')?.addEventListener('change', function() {
  const box = document.getElementById('tanggalSelesaiBox');
  if (!box) return;
  box.style.display = (this.value === 'cuti_tahunan' || this.value === 'izin_sakit') ? 'block' : 'none';
});
</script>

<!-- ── [POIN 2] LIVENESS DETECTION — Script include ────────────────────── -->
<!-- face-api.js dan model tersimpan LOKAL di assets/face-api/ sehingga    -->
<!-- Service Worker bisa meng-cache semua file ini untuk mode offline-first -->
<script src="assets/face-api/face-api.min.js"></script>
<script src="assets/face-api/liveness.js"></script>

<script>
/* ===================================================================
   POIN 2: INTEGRASI LIVENESS DETECTION KE FORM CEK-IN
   -----------------------------------------------------------------
   Alur lengkap setelah modifikasi:
     1. Pegawai buka tab Cek-in → kamera tidak aktif, ada tombol "Ketuk"
     2. Ketuk tombol "Verifikasi Wajah" → stream kamera depan dimulai
     3. face-api.js loop mendeteksi landmark → hitung EAR kedipan
     4. Setelah BLINK_REQUIRED blink terdeteksi:
        a. Ambil frame dari <video> ke <canvas>
        b. Bakar overlay GPS+waktu ke canvas (fungsi gambarUlangOverlay())
        c. canvas.toBlob() → File → DataTransfer → pasang ke inpFoto
        d. Set inpLivenessPassed = 1
        e. Tampilkan preview foto + badge "LIVENESS ✓"
        f. Hentikan stream kamera
     5. Tombol "Cek-in Sekarang" aktif jika: GPS + liveness lulus
     6. Fallback: tombol foto manual muncul jika kamera depan gagal
   =================================================================== */

/* ── State liveness ── */
let livenessLulus = false;       // true setelah blink terdeteksi & foto siap
let modelSiap     = false;       // true setelah face-api model selesai dimuat
let kameraAktif   = false;       // true saat video stream sedang berjalan
const BLINK_REQUIRED_UI = 1;     // harus sync dengan konstanta di liveness.js

/* ── Inisialisasi: muat model di background saat halaman load ── */
async function inisialisasiLiveness() {
  try {
    modelSiap = await LivenessDetector.muatModel();
    if (!modelSiap) {
      console.warn('[Liveness] Model gagal dimuat — mode fallback foto manual aktif');
      document.getElementById('livenesFallbackBtn').style.display = 'block';
    } else {
      // Siapkan dot progress blink
      const bp = document.getElementById('blinkProgress');
      bp.innerHTML = '';
      for (let i = 0; i < BLINK_REQUIRED_UI; i++) {
        const dot = document.createElement('div');
        dot.className = 'blink-dot';
        dot.id = 'blinkDot' + i;
        bp.appendChild(dot);
      }
    }
  } catch(e) {
    console.error('[Liveness] inisialisasi error:', e);
    document.getElementById('livenesFallbackBtn').style.display = 'block';
  }
}

/* ── Aktifkan kamera untuk liveness detection ── */
async function livenessAktifkan() {
  if (kameraAktif || livenessLulus) return;

  const startBtn = document.getElementById('livenessStartBtn');
  startBtn.style.display = 'none';
  document.getElementById('livenessPanel').style.display = 'block';

  if (!modelSiap) {
    document.getElementById('livenessStatus').textContent = '⏳ Memuat model AI…';
    document.getElementById('livenessStatus').dataset.mode = 'neutral';
    modelSiap = await LivenessDetector.muatModel();
    if (!modelSiap) {
      document.getElementById('livenessStatus').textContent = '⚠ Model gagal — gunakan foto manual';
      document.getElementById('livenessStatus').dataset.mode = 'error';
      document.getElementById('livenesFallbackBtn').style.display = 'block';
      return;
    }
  }

  kameraAktif = true;
  const videoEl  = document.getElementById('livenessVideo');
  const canvasEl = document.getElementById('livenessCanvas');
  const statusEl = document.getElementById('livenessStatus');

  videoEl.style.display = 'block';

  await LivenessDetector.mulai(
    videoEl, canvasEl, statusEl,
    // ── onSuccess: liveness lulus, tangkap frame dan bakar overlay ──
    async (dataUrl) => {
      livenessLulus = true;
      kameraAktif   = false;

      // Simpan foto asli dari video (sebelum overlay GPS)
      fotoAsliDataUrl = dataUrl;
      fotoSiap = false;

      // Hentikan stream kamera (hemat baterai)
      LivenessDetector.berhenti();
      videoEl.style.display = 'none';
      canvasEl.style.display = 'none';

      // Bakar overlay GPS + waktu ke foto seperti alur lama
      // (gambarUlangOverlay() sudah ada dan akan mengisi inpFoto via DataTransfer)
      gambarUlangOverlay();

      // Tandai liveness lulus di hidden input
      document.getElementById('inpLivenessPassed').value = '1';

      // Tampilkan badge
      document.getElementById('livenessBadge').style.display = 'block';

      // Update dot progress
      for (let i = 0; i < BLINK_REQUIRED_UI; i++) {
        const dot = document.getElementById('blinkDot' + i);
        if (dot) dot.classList.add('aktif');
      }

      // Perbarui overlay GPS jika lokasi sudah ada
      const lat = document.getElementById('inpLat').value;
      const lng = document.getElementById('inpLng').value;
      if (lat && lng) {
        const camOverlay = document.getElementById('camOverlay');
        camOverlay.style.display = 'block';
        camOverlay.textContent = `${parseFloat(lat).toFixed(4)}, ${parseFloat(lng).toFixed(4)} · ${new Date().toLocaleTimeString('id-ID')} WIB`;
      }

      perbarui_btnCekin();
    },
    // ── onError: kamera gagal, tampilkan fallback ──
    (pesan) => {
      kameraAktif = false;
      document.getElementById('livenesFallbackBtn').style.display = 'block';
      startBtn.style.display = 'flex';
      startBtn.querySelector('.start-label').textContent = '🔄 Coba Lagi';
    }
  );
}

/* ── Fallback: pengguna pilih foto manual (tanpa liveness) ── */
function livenessFallback() {
  // Hentikan kamera dulu jika masih aktif
  LivenessDetector.berhenti();
  kameraAktif = false;

  document.getElementById('livenessVideo').style.display = 'none';
  document.getElementById('livenessCanvas').style.display = 'none';
  document.getElementById('livenessPanel').style.display = 'none';
  document.getElementById('livenessStartBtn').style.display = 'none';
  document.getElementById('livenesFallbackBtn').style.display = 'none';

  // Restore tampilan kamera lama (input file + capture)
  const inpFoto = document.getElementById('inpFoto');
  inpFoto.setAttribute('capture', 'environment');
  inpFoto.click(); // langsung buka kamera belakang

  // Mode fallback: tidak wajibkan liveness
  livenessLulus = true;
  document.getElementById('inpLivenessPassed').value = '0'; // bisa dibedakan di server
  perbarui_btnCekin();
}

/* ── Atur enable/disable tombol cek-in berdasarkan state ── */
function perbarui_btnCekin() {
  const btn    = document.getElementById('checkinBtn');
  const latOk  = !!document.getElementById('inpLat').value;
  const fotoOk = livenessLulus && fotoSiap;

  if (latOk && fotoOk) {
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.background = '';
  } else {
    btn.disabled = true;
    btn.style.opacity = '.5';
    btn.style.background = 'var(--ink-400)';
  }
}

/* ── Hentikan kamera saat pindah tab ── */
document.querySelectorAll('.nav-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    if (btn.dataset.view !== 'absen' && kameraAktif) {
      LivenessDetector.berhenti();
      kameraAktif = false;
      document.getElementById('livenessVideo').style.display = 'none';
    }
  });
});

/* ── Perbarui status tombol juga setelah GPS & foto berubah ── */
// Hook ke ambilLokasi (GPS success)
const _ambilLokasiAsli = ambilLokasi;
ambilLokasi = function() {
  const cb = navigator.geolocation ? 'afterGPS' : null;
  _ambilLokasiAsli();
  // Setelah lokasi masuk, perbarui camOverlay jika video masih aktif
  setTimeout(() => {
    if (kameraAktif || livenessLulus) {
      const lat = document.getElementById('inpLat').value;
      const lng = document.getElementById('inpLng').value;
      if (lat && lng) {
        const camOverlay = document.getElementById('camOverlay');
        camOverlay.style.display = 'block';
        camOverlay.textContent = `${parseFloat(lat).toFixed(4)}, ${parseFloat(lng).toFixed(4)} · ${new Date().toLocaleTimeString('id-ID')} WIB`;
        if (fotoAsliDataUrl) gambarUlangOverlay(); // bakar ulang overlay GPS di foto
      }
      perbarui_btnCekin();
    }
  }, 3000);
};

/* ── Hook setelah gambarUlangOverlay selesai (fotoSiap = true) ── */
// Override fungsi gambarUlangOverlay untuk menambah perbarui_btnCekin
const _gambarUlangOverlayAsli = gambarUlangOverlay;
gambarUlangOverlay = function() {
  // Panggil fungsi asli (sudah defined di atas di blok <script> lama)
  // Lalu setelah blob selesai, perbarui tombol
  const inpFoto = document.getElementById('inpFoto');
  const observer = new MutationObserver(() => {
    if (inpFoto.files && inpFoto.files.length > 0) {
      observer.disconnect();
      perbarui_btnCekin();
    }
  });
  // Tapi karena files bukan attribute DOM, monitor via fotoSiap lewat polling singkat
  _gambarUlangOverlayAsli();
  // Polling ringan 100ms x10 = 1 detik max, sampai fotoSiap true
  let tries = 0;
  const poll = setInterval(() => {
    tries++;
    if (fotoSiap || tries > 10) {
      clearInterval(poll);
      perbarui_btnCekin();
    }
  }, 100);
};

/* ── Modifikasi validasi submit: wajibkan liveness lulus ── */
// Hapus listener submit lama tidak bisa, jadi kita cegat di tombol sendiri
document.getElementById('checkinBtn').addEventListener('click', function(e) {
  if (!livenessLulus) {
    e.preventDefault();
    e.stopImmediatePropagation();
    alert('Verifikasi wajah (blink detection) wajib dilakukan terlebih dahulu sebelum cek-in.');
    return false;
  }
}, true); // capture phase — terpanggil sebelum listener submit di bawah

/* ── Tombol cekin disable by default sampai liveness + GPS lulus ── */
document.addEventListener('DOMContentLoaded', () => {
  // Disable tombol saat pertama buka
  const btn = document.getElementById('checkinBtn');
  btn.disabled = true;
  btn.style.opacity = '.5';
  btn.style.background = 'var(--ink-400)';

  // Muat model AI di background (tidak blokir UI)
  inisialisasiLiveness();
});

/* ── Aktifkan kamera otomatis saat buka tab Absen ── */
document.querySelector('.nav-btn[data-view="absen"]')?.addEventListener('click', () => {
  // Tunda sedikit supaya animasi tab selesai dulu
  setTimeout(() => {
    if (!livenessLulus && !kameraAktif) {
      livenessAktifkan();
    }
    ambilLokasi();
  }, 400);
});
document.querySelector('.status-hero .cta')?.addEventListener('click', () => {
  setTimeout(() => {
    if (!livenessLulus && !kameraAktif) livenessAktifkan();
    ambilLokasi();
  }, 400);
});
</body>
</html>
