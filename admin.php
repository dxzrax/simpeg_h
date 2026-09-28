<?php
require 'cek_sesi.php';
cek_role(['kasubbag', 'admin']);
require 'koneksi.php';
require 'helpers.php';

// Pesan generik kalau token CSRF tidak valid/kedaluwarsa (form dikirim
// dari halaman lama yang sudah lama dibuka, atau percobaan submit dari
// luar situs) — bisa muncul di halaman manapun, makanya ditangani di
// sini, bukan di salah satu peta pesan per-menu.
$csrf_gagal = ($_GET['error'] ?? '') === 'csrf';

/**
 * ---------------------------------------------------------------------
 * MONITORING KECURIGAAN — data & aksi verifikasi sungguhan
 * ---------------------------------------------------------------------
 * Pesan hasil verifikasi dikirim lewat query string oleh
 * proses_verifikasi.php (mis. admin.php?verifikasi=berhasil&view=kecurigaan)
 */
$pesan_verifikasi_map = [
    'berhasil'       => ['ok',   'Verifikasi Tersimpan', 'Keputusan berhasil dicatat'],
    'gagal'          => ['bad',  'Verifikasi Gagal',     'Data yang dikirim tidak valid'],
    'sudah_diproses' => ['warn', 'Sudah Diproses',       'Baris ini sudah diverifikasi sebelumnya'],
];
$kode_verifikasi  = $_GET['verifikasi'] ?? '';
$pesan_verifikasi = $pesan_verifikasi_map[$kode_verifikasi] ?? null;

$nama_bulan = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// Periode yang sedang ditampilkan — default bulan berjalan, bisa diganti lewat dropdown periode
$bulan_filter = isset($_GET['bulan']) ? max(1, min(12, (int) $_GET['bulan'])) : (int) date('n');
$tahun_filter = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');

// ===== 1. Statistik ringkas (4 kartu) — dihitung dari tabel absensi sungguhan =====
$stmt = mysqli_prepare($koneksi, "
    SELECT
      COALESCE(SUM(status_sinkronisasi = 'tersinkron'), 0) AS offline_count,
      COALESCE(SUM(status_verifikasi = 'perlu_verifikasi'), 0) AS menunggu_count,
      COALESCE(SUM(is_mock_location = 1), 0) AS spoof_count,
      AVG(CASE WHEN status_sinkronisasi = 'tersinkron'
                THEN TIMESTAMPDIFF(MINUTE, waktu_dibuat_perangkat, waktu_diterima_server) END) AS rata_menit_offline
    FROM absensi
    WHERE sumber_absen = 'geolocation' AND MONTH(tanggal) = ? AND YEAR(tanggal) = ?
");
mysqli_stmt_bind_param($stmt, 'ii', $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$stat_kecurigaan = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$rata_offline_txt = '—';
if ($stat_kecurigaan['rata_menit_offline'] !== null) {
    $total_menit = (int) round($stat_kecurigaan['rata_menit_offline']);
    $rata_offline_txt = intdiv($total_menit, 60) . 'j ' . ($total_menit % 60) . 'm';
}

// ===== 2. Baris yang relevan untuk ditinjau bulan ini =====
// (masih menunggu verifikasi, ATAU pernah tersinkron offline, ATAU sudah diputuskan bulan ini)
$stmt = mysqli_prepare($koneksi, "
    SELECT
        a.id AS absensi_id, a.tanggal, a.status_sinkronisasi, a.status_verifikasi,
        a.status_radius, a.is_mock_location, a.alasan_indikasi_spoofing,
        a.status_kehadiran, a.menit_terlambat, a.alasan_terlambat,
        a.status_jadwal, a.foto_url, a.catatan_verifikasi,
        p.nama, p.jabatan,
        t.nama_lokasi
    FROM absensi a
    JOIN pegawai p ON p.id = a.pegawai_id
    LEFT JOIN titik_lokasi_tugas t ON t.id = a.titik_lokasi_id
    WHERE a.sumber_absen = 'geolocation'
      AND (a.status_verifikasi != 'otomatis_sah' OR a.status_sinkronisasi = 'tersinkron')
      AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?
    ORDER BY (a.status_verifikasi = 'perlu_verifikasi') DESC, a.tanggal DESC
");
mysqli_stmt_bind_param($stmt, 'ii', $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$baris_kecurigaan = mysqli_stmt_get_result($stmt);

/**
 * ---------------------------------------------------------------------
 * TUNJANGAN KINERJA & AUDIT TRAIL TUKIN
 * ---------------------------------------------------------------------
 * Sumber datanya tabel `tukin` / `tukin_detail`, hasil dari
 * proses_hitung_tukin.php (dipicu tombol "Hitung Tukin Bulan Ini").
 * Sebelum tombol itu pernah ditekan untuk suatu periode, tabelnya
 * memang kosong — bukan bug, tampilkan status kosong yang jelas.
 */
$pesan_hitung_map = [
    'berhasil' => ['ok',  'Perhitungan Tukin Selesai', 'Rekap & Audit Trail sudah diperbarui untuk periode ini'],
    'gagal'    => ['bad', 'Perhitungan Tukin Gagal',   'Terjadi kesalahan saat memproses, silakan coba lagi'],
];
$kode_hitung  = $_GET['hitung'] ?? '';
$pesan_hitung = $pesan_hitung_map[$kode_hitung] ?? null;

// ===== Rekap tukin per pegawai untuk periode terpilih =====
$stmt = mysqli_prepare($koneksi, "
    SELECT t.*, p.nama, p.jabatan, p.metode_absen
    FROM tukin t
    JOIN pegawai p ON p.id = t.pegawai_id
    WHERE t.periode_bulan = ? AND t.periode_tahun = ?
    ORDER BY p.nama
");
mysqli_stmt_bind_param($stmt, 'ii', $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$baris_tukin = mysqli_stmt_get_result($stmt);

// Jumlah telat/alpa per pegawai periode ini, untuk kolom "Telat/Alpa"
$telat_alpa_map = []; // pegawai_id => ['telat'=>n, 'alpa'=>n]
$hasil_ta = mysqli_query($koneksi, "
    SELECT pegawai_id,
           SUM(status_kehadiran = 'terlambat') AS telat,
           SUM(status_kehadiran = 'alpa') AS alpa
    FROM absensi
    WHERE MONTH(tanggal) = $bulan_filter AND YEAR(tanggal) = $tahun_filter
    GROUP BY pegawai_id
");
while ($ta = mysqli_fetch_assoc($hasil_ta)) {
    $telat_alpa_map[(int) $ta['pegawai_id']] = $ta;
}

// ===== Audit Trail — rincian potongan tertaut ke absensi & aturan (pakai view bantuan) =====
$stmt = mysqli_prepare($koneksi, "
    SELECT * FROM v_audit_trail_tukin
    WHERE periode_bulan = ? AND periode_tahun = ?
    ORDER BY tanggal_absensi DESC
");
mysqli_stmt_bind_param($stmt, 'ii', $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$baris_audit = mysqli_stmt_get_result($stmt);

// Tambahkan pesan "sudah_final" ke peta pesan hitung tukin
$pesan_hitung_map['sudah_final'] = ['warn', 'Periode Sudah Final', 'Buka kunci dulu lewat "Buka Kunci Periode" kalau memang perlu dihitung ulang'];
$pesan_hitung = $pesan_hitung_map[$kode_hitung] ?? null;

/**
 * ---------------------------------------------------------------------
 * FINALISASI TUKIN — status kunci periode berjalan
 * ---------------------------------------------------------------------
 */
$pesan_finalisasi_map = [
    'berhasil_kunci' => ['ok',   'Periode Dikunci',    'Tukin periode ini sudah final, tidak bisa dihitung ulang tanpa dibuka dulu'],
    'berhasil_buka'  => ['ok',   'Kunci Dibuka',       'Periode ini bisa dihitung ulang lagi'],
    'kosong'         => ['warn', 'Belum Ada Rekap',    'Hitung tukin dulu sebelum bisa difinalisasi'],
];
$kode_finalisasi  = $_GET['finalisasi'] ?? '';
$pesan_finalisasi = $pesan_finalisasi_map[$kode_finalisasi] ?? null;

$stmt = mysqli_prepare($koneksi, "
    SELECT COUNT(*) AS jml, SUM(status = 'final') AS jml_final
    FROM tukin WHERE periode_bulan = ? AND periode_tahun = ?
");
mysqli_stmt_bind_param($stmt, 'ii', $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$status_periode_tukin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$periode_sudah_final = $status_periode_tukin['jml'] > 0 && (int) $status_periode_tukin['jml_final'] === (int) $status_periode_tukin['jml'];

// ===== Log perhitungan tukin — riwayat "siapa menekan Hitung Tukin, kapan"
//       untuk periode yang sedang ditampilkan (pelengkap Audit Trail Tukin) =====
$stmt = mysqli_prepare($koneksi, "
    SELECT l.*, u.username, p.nama AS nama_penghitung
    FROM log_perhitungan_tukin l
    JOIN users u ON u.id = l.dihitung_oleh
    JOIN pegawai p ON p.id = u.pegawai_id
    WHERE l.periode_bulan = ? AND l.periode_tahun = ?
    ORDER BY l.waktu_hitung DESC
");
mysqli_stmt_bind_param($stmt, 'ii', $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$baris_log_tukin = mysqli_stmt_get_result($stmt);
$log_tukin_terakhir = mysqli_num_rows($baris_log_tukin) > 0 ? mysqli_fetch_assoc($baris_log_tukin) : null;
mysqli_data_seek($baris_log_tukin, 0);

// ===== Rincian aturan potongan (untuk modal "Atur Aturan Potongan") =====
$daftar_aturan = mysqli_query($koneksi, "SELECT id, kode_aturan, nama_aturan, jenis, nominal_potongan FROM aturan_potongan ORDER BY kode_aturan");
$pesan_aturan_map = ['berhasil' => ['ok', 'Aturan Diperbarui', 'Nominal potongan baru akan dipakai saat Hitung Tukin berikutnya'], 'gagal' => ['bad', 'Gagal Menyimpan', 'Periksa kembali nominal yang diisi']];
$pesan_aturan = $pesan_aturan_map[$_GET['aturan'] ?? ''] ?? null;

/**
 * ---------------------------------------------------------------------
 * DATA PEGAWAI — CRUD
 * ---------------------------------------------------------------------
 */
$pesan_pegawai_map = [
    'berhasil'                  => ['ok',  'Pegawai Ditambahkan', 'Akun login untuk pegawai ini sudah aktif'],
    'berhasil_pegawai'           => ['ok',  'Pegawai Ditambahkan', 'Kode aktivasi berhasil dibuat'], // fallback, biasanya ditimpa dinamis di bawah
    'berhasil_ubah'              => ['ok',  'Data Diperbarui',     'Perubahan data pegawai sudah tersimpan'],
    'gagal_kosong'                => ['bad', 'Data Belum Lengkap',  'Nama dan NIP wajib diisi (username/password wajib khusus role Admin/Kasubbag)'],
    'gagal_password_pendek'       => ['bad', 'Password Terlalu Pendek', 'Minimal 6 karakter'],
    'gagal_nip_dipakai'           => ['bad', 'NIP Sudah Terdaftar', 'Gunakan NIP lain atau periksa data yang sudah ada'],
    'gagal_username_dipakai'      => ['bad', 'Username Sudah Dipakai', 'Pilih username lain'],
    'gagal_server'                => ['bad', 'Gagal Menyimpan', 'Terjadi kesalahan pada server'],
];
$pesan_pegawai = $pesan_pegawai_map[$_GET['pegawai'] ?? ''] ?? null;

// Kode aktivasi baru (kalau pegawai yang baru ditambahkan role='pegawai')
// dikirim lewat flash session sekali pakai, BUKAN query string — supaya
// kode sekali-pakai ini tidak nyangkut di riwayat URL/browser/server log.
if (($_GET['pegawai'] ?? '') === 'berhasil_pegawai' && isset($_SESSION['flash_kode_aktivasi'])) {
    $flash = $_SESSION['flash_kode_aktivasi'];
    unset($_SESSION['flash_kode_aktivasi']);
    $pesan_pegawai = ['ok', 'Pegawai Ditambahkan', "Kode aktivasi untuk {$flash['nama']} (NIP {$flash['nip']}): {$flash['kode']} — sampaikan ke pegawai untuk aktivasi mandiri."];
} elseif (($_GET['pegawai'] ?? '') === 'berhasil_pegawai') {
    $pesan_pegawai = ['ok', 'Pegawai Ditambahkan', 'Akun akan aktif setelah pegawai melakukan aktivasi mandiri.'];
}

// ===== Kelola Seksi (modal di menu Data Pegawai) =====
$pesan_seksi_map = [
    'berhasil'        => ['ok',  'Seksi Ditambahkan',  'Seksi baru langsung muncul di dropdown Data Pegawai'],
    'berhasil_ubah'    => ['ok',  'Nama Seksi Diperbarui', 'Perubahan sudah tersimpan'],
    'terhapus'         => ['ok',  'Seksi Dihapus',      'Pegawai yang tadinya di seksi ini sekarang tampil sebagai "Tanpa Seksi"'],
    'gagal_kosong'     => ['bad', 'Nama Seksi Kosong',  'Nama seksi wajib diisi'],
    'gagal_duplikat'   => ['bad', 'Nama Sudah Dipakai', 'Sudah ada seksi dengan nama yang sama'],
    'gagal_terlalu_panjang' => ['bad', 'Nama Terlalu Panjang', 'Maksimal 50 karakter'],
    'gagal'            => ['bad', 'Gagal Menyimpan',    'Data yang dikirim tidak valid'],
];
$pesan_seksi = $pesan_seksi_map[$_GET['seksi'] ?? ''] ?? null;

// ===== Approval Pengajuan Izin/Cuti/Dinas Luar (panel di Dashboard) =====
$pesan_pengajuan_admin_map = [
    'berhasil'         => ['ok',  'Keputusan Tersimpan', 'Pegawai sudah diberi tahu lewat notifikasi'],
    'sudah_diproses'   => ['warn','Sudah Diproses',      'Pengajuan ini sudah diputuskan sebelumnya'],
    'gagal'            => ['bad', 'Gagal Menyimpan',     'Data yang dikirim tidak valid'],
];
$pesan_pengajuan_admin = $pesan_pengajuan_admin_map[$_GET['pengajuan'] ?? ''] ?? null;

$daftar_seksi = mysqli_query($koneksi, "SELECT id, nama_seksi FROM seksi ORDER BY nama_seksi");
$daftar_seksi_arr = [];
while ($s = mysqli_fetch_assoc($daftar_seksi)) { $daftar_seksi_arr[] = $s; }

$daftar_titik_select = mysqli_query($koneksi, "SELECT id, nama_lokasi FROM titik_lokasi_tugas WHERE status != 'nonaktif' ORDER BY nama_lokasi");
$daftar_titik_arr = [];
while ($t = mysqli_fetch_assoc($daftar_titik_select)) { $daftar_titik_arr[] = $t; }

$baris_pegawai = mysqli_query($koneksi, "
    SELECT p.*, s.nama_seksi, u.username, u.role
    FROM pegawai p
    LEFT JOIN seksi s ON s.id = p.seksi_id
    LEFT JOIN users u ON u.pegawai_id = p.id
    ORDER BY p.nama
");

// Kalau lagi mode edit (?edit_pegawai=ID), ambil datanya untuk mengisi form
$edit_pegawai_id = isset($_GET['edit_pegawai']) ? (int) $_GET['edit_pegawai'] : 0;
$data_edit_pegawai = null;
$riwayat_mutasi_pegawai = []; // [POIN 3] historis mutasi metode
if ($edit_pegawai_id > 0) {
    $stmt = mysqli_prepare($koneksi, "
        SELECT p.*, u.username, u.role
        FROM pegawai p LEFT JOIN users u ON u.pegawai_id = p.id
        WHERE p.id = ?
    ");
    mysqli_stmt_bind_param($stmt, 'i', $edit_pegawai_id);
    mysqli_stmt_execute($stmt);
    $data_edit_pegawai = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // [POIN 3] Ambil riwayat mutasi metode untuk ditampilkan di modal edit
    $stmt = mysqli_prepare($koneksi, "
        SELECT r.*, u.username AS dicatat_oleh_username
        FROM riwayat_mutasi_metode r
        LEFT JOIN users u ON u.id = r.dicatat_oleh
        WHERE r.pegawai_id = ?
        ORDER BY r.tmt_berlaku DESC, r.id DESC
        LIMIT 10
    ");
    mysqli_stmt_bind_param($stmt, 'i', $edit_pegawai_id);
    mysqli_stmt_execute($stmt);
    $res_mutasi = mysqli_stmt_get_result($stmt);
    while ($rm = mysqli_fetch_assoc($res_mutasi)) {
        $riwayat_mutasi_pegawai[] = $rm;
    }
}


/**
 * ---------------------------------------------------------------------
 * TITIK LOKASI TUGAS & JADWAL DINAS LUAR — CRUD
 * ---------------------------------------------------------------------
 */
$pesan_titik_map = [
    'berhasil'          => ['ok',  'Titik Lokasi Ditambahkan', 'Titik baru langsung bisa dipakai untuk geofencing cek-in'],
    'berhasil_ubah'      => ['ok',  'Titik Lokasi Diperbarui',  'Perubahan sudah tersimpan'],
    'gagal_kosong'        => ['bad', 'Data Belum Lengkap',       'Nama lokasi dan koordinat wajib diisi'],
    'gagal_koordinat'     => ['bad', 'Koordinat Tidak Valid',    'Periksa kembali nilai latitude/longitude'],
];
$pesan_titik = $pesan_titik_map[$_GET['titik'] ?? ''] ?? null;

$pesan_jadwal_map = [
    'berhasil'      => ['ok',  'Jadwal Ditambahkan', 'Cek-in di titik & tanggal ini akan otomatis cocok dengan jadwal'],
    'terhapus'      => ['ok',  'Jadwal Dihapus',     ''],
    'gagal_kosong'   => ['bad', 'Data Belum Lengkap', 'Pegawai, titik lokasi, dan tanggal wajib diisi'],
    'gagal_tanggal'  => ['bad', 'Tanggal Tidak Valid', 'Tanggal selesai tidak boleh sebelum tanggal mulai'],
];
$pesan_jadwal = $pesan_jadwal_map[$_GET['jadwal'] ?? ''] ?? null;

$baris_titik = mysqli_query($koneksi, "
    SELECT t.*,
        (SELECT COUNT(*) FROM pegawai WHERE titik_lokasi_id = t.id) AS jml_pegawai
    FROM titik_lokasi_tugas t
    ORDER BY FIELD(t.status,'aktif','musiman','nonaktif'), t.nama_lokasi
");

$edit_titik_id = isset($_GET['edit_titik']) ? (int) $_GET['edit_titik'] : 0;
$data_edit_titik = null;
if ($edit_titik_id > 0) {
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM titik_lokasi_tugas WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $edit_titik_id);
    mysqli_stmt_execute($stmt);
    $data_edit_titik = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

$daftar_pegawai_select = mysqli_query($koneksi, "SELECT id, nama FROM pegawai WHERE status_kepegawaian != 'nonaktif' ORDER BY nama");
$daftar_pegawai_arr = [];
while ($pg = mysqli_fetch_assoc($daftar_pegawai_select)) { $daftar_pegawai_arr[] = $pg; }

$baris_jadwal = mysqli_query($koneksi, "
    SELECT j.*, p.nama AS nama_pegawai, t.nama_lokasi
    FROM jadwal_dinas_luar j
    JOIN pegawai p ON p.id = j.pegawai_id
    JOIN titik_lokasi_tugas t ON t.id = j.titik_lokasi_id
    WHERE j.tanggal_selesai >= CURDATE()
    ORDER BY j.tanggal_mulai
");

/**
 * ---------------------------------------------------------------------
 * KALENDER LIBUR — hari_libur (tanggal merah nasional/cuti bersama)
 * ---------------------------------------------------------------------
 * Basis hitung_hari_kerja_efektif() di helpers.php, dipakai proses_hitung_tukin.php
 * & cron_tandai_alpa.php. Ditampilkan per tahun (dropdown), default tahun berjalan.
 */
$pesan_libur_map = [
    'berhasil'     => ['ok',  'Tanggal Merah Tersimpan', 'Perhitungan hari kerja efektif akan ikut menyesuaikan'],
    'terhapus'     => ['ok',  'Tanggal Merah Dihapus',   ''],
    'gagal_kosong' => ['bad', 'Data Belum Lengkap',      'Tanggal dan keterangan wajib diisi'],
];
$pesan_libur = $pesan_libur_map[$_GET['libur'] ?? ''] ?? null;

$tahun_kalender = isset($_GET['tahun_kalender']) ? (int) $_GET['tahun_kalender'] : (int) date('Y');
$stmt = mysqli_prepare($koneksi, "SELECT * FROM hari_libur WHERE YEAR(tanggal) = ? ORDER BY tanggal");
mysqli_stmt_bind_param($stmt, 'i', $tahun_kalender);
mysqli_stmt_execute($stmt);
$baris_hari_libur = mysqli_stmt_get_result($stmt);

/**
 * ---------------------------------------------------------------------
 * INPUT MANUAL FINGERPRINT (Rekap Kantor) — lihat catatan lengkap di
 * proses_input_manual_fingerprint.php soal kenapa ini manual, bukan parser.
 * ---------------------------------------------------------------------
 */
$pesan_manual_map = [
    'berhasil'       => ['ok',  'Kehadiran Tersimpan', 'Data manual sudah masuk & langsung terhitung di tunjangan'],
    'gagal_kosong'   => ['bad', 'Data Belum Lengkap',  'Pegawai, tanggal, dan jam masuk wajib diisi'],
    'konflik_sumber' => ['bad', 'Data GPS Sudah Ada',  'Pegawai ini sudah punya cek-in dari sumber GPS di tanggal itu. Buka "+ Input Manual" lagi, isi ulang, lalu centang "Saya yakin ingin menimpa" kalau memang mau menggantinya.'],
];
$pesan_manual = $pesan_manual_map[$_GET['manual'] ?? ''] ?? null;

/**
 * ---------------------------------------------------------------------
 * DASHBOARD — rekap kehadiran HARI INI (real-time)
 * ---------------------------------------------------------------------
 * Sengaja terkunci ke tanggal hari ini (bukan periode yang bisa
 * dipilih seperti menu lain) — inilah bedanya "dashboard" (potret saat
 * ini) dengan "rekap" (Kantor/Kecurigaan/Tukin, yang historis per
 * periode). Kalau di-demo pada hari tanpa data cek-in, kartu akan
 * menunjukkan 0 — itu bukan bug, tinggal cek-in dulu lewat pegawai.php
 * supaya datanya muncul.
 */
$hari_ini_sql = date('Y-m-d');
$hari_ini_tampil = tgl_id($hari_ini_sql) . ' · ' . date('H:i') . ' WIB';

$total_pegawai_aktif = (int) mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS jml FROM pegawai WHERE status_kepegawaian = 'aktif'"))['jml'];

$stmt = mysqli_prepare($koneksi, "
    SELECT
        COUNT(*) AS hadir,
        SUM(sumber_absen = 'fingerprint') AS via_fingerprint,
        SUM(sumber_absen = 'geolocation') AS via_geolocation,
        SUM(status_verifikasi = 'perlu_verifikasi') AS perlu_verifikasi
    FROM absensi WHERE tanggal = ?
");
mysqli_stmt_bind_param($stmt, 's', $hari_ini_sql);
mysqli_stmt_execute($stmt);
$stat_hari_ini = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Contoh baris tiap jalur untuk panel "Alur Data Absensi Hybrid"
$stmt = mysqli_prepare($koneksi, "
    SELECT p.nama, a.jam_masuk, a.status_kehadiran
    FROM absensi a JOIN pegawai p ON p.id = a.pegawai_id
    WHERE a.tanggal = ? AND a.sumber_absen = 'fingerprint' ORDER BY a.jam_masuk LIMIT 3
");
mysqli_stmt_bind_param($stmt, 's', $hari_ini_sql);
mysqli_stmt_execute($stmt);
$contoh_kantor = mysqli_stmt_get_result($stmt);

$stmt = mysqli_prepare($koneksi, "
    SELECT p.nama, a.latitude, a.longitude, a.status_radius
    FROM absensi a JOIN pegawai p ON p.id = a.pegawai_id
    WHERE a.tanggal = ? AND a.sumber_absen = 'geolocation' ORDER BY a.jam_masuk LIMIT 3
");
mysqli_stmt_bind_param($stmt, 's', $hari_ini_sql);
mysqli_stmt_execute($stmt);
$contoh_lapangan = mysqli_stmt_get_result($stmt);

// Rekap per seksi hari ini
$stmt = mysqli_prepare($koneksi, "
    SELECT s.nama_seksi,
        COUNT(p.id) AS total_pegawai,
        SUM(a.id IS NOT NULL) AS hadir,
        SUM(a.sumber_absen = 'fingerprint') AS via_fp,
        SUM(a.sumber_absen = 'geolocation') AS via_geo
    FROM seksi s
    LEFT JOIN pegawai p ON p.seksi_id = s.id AND p.status_kepegawaian = 'aktif'
    LEFT JOIN absensi a ON a.pegawai_id = p.id AND a.tanggal = ?
    GROUP BY s.id, s.nama_seksi
    ORDER BY s.nama_seksi
");
mysqli_stmt_bind_param($stmt, 's', $hari_ini_sql);
mysqli_stmt_execute($stmt);
$rekap_seksi_hari_ini = mysqli_stmt_get_result($stmt);

/**
 * ---------------------------------------------------------------------
 * REKAP KANTOR — log fingerprint HARI INI (sama filosofinya dengan
 * Dashboard: potret hari berjalan, bukan rekap historis per periode)
 * ---------------------------------------------------------------------
 */
// Basis dari tabel pegawai (bukan absensi) supaya pegawai yang HARI INI
// belum/tidak cek-in sama sekali tetap muncul di log (bukan cuma yang
// sudah punya baris absensi). Catatan: cron_tandai_alpa.php baru mengisi
// status_kehadiran='alpa' di AKHIR hari kerja (lewat Task Scheduler,
// jam 23:00) — sebelum itu, baris tanpa cek-in di layar ini memang
// SEHARUSNYA masih terdeteksi lewat absensi_id yang NULL (bukan status
// 'alpa'), karena hari itu belum resmi berakhir saat dilihat siang hari.
$stmt = mysqli_prepare($koneksi, "
    SELECT p.nama, p.jabatan, p.nip, s.nama_seksi,
        a.id AS absensi_id, a.jam_masuk, a.jam_pulang, a.status_kehadiran,
        (SELECT COALESCE(SUM(td.nominal_potongan), 0) FROM tukin_detail td WHERE td.absensi_id = a.id) AS potongan
    FROM pegawai p
    LEFT JOIN seksi s ON s.id = p.seksi_id
    LEFT JOIN absensi a ON a.pegawai_id = p.id AND a.tanggal = ? AND a.sumber_absen = 'fingerprint'
    WHERE p.metode_absen = 'fingerprint' AND p.status_kepegawaian = 'aktif'
    ORDER BY (a.jam_masuk IS NULL), a.jam_masuk
");
mysqli_stmt_bind_param($stmt, 's', $hari_ini_sql);
mysqli_stmt_execute($stmt);
$baris_kantor = mysqli_stmt_get_result($stmt);

/**
 * ---------------------------------------------------------------------
 * CEK-IN LAPANGAN — log geolocation HARI INI (sungguhan, bukan mockup)
 * Ditampilkan berdampingan dengan ilustrasi PWA di layar "Cek-in Lapangan"
 * ---------------------------------------------------------------------
 */
$stmt = mysqli_prepare($koneksi, "
    SELECT p.nama, p.jabatan, s.nama_seksi,
        a.jam_masuk, a.status_radius, a.jarak_dari_titik_meter, a.status_kehadiran,
        a.is_mock_location, a.status_sinkronisasi, a.status_verifikasi, a.foto_url,
        t.nama_lokasi
    FROM absensi a
    JOIN pegawai p ON p.id = a.pegawai_id
    LEFT JOIN seksi s ON s.id = p.seksi_id
    LEFT JOIN titik_lokasi_tugas t ON t.id = a.titik_lokasi_id
    WHERE a.tanggal = ? AND a.sumber_absen = 'geolocation'
    ORDER BY a.jam_masuk DESC
");
mysqli_stmt_bind_param($stmt, 's', $hari_ini_sql);
mysqli_stmt_execute($stmt);
$baris_lapangan = mysqli_stmt_get_result($stmt);

/**
 * ---------------------------------------------------------------------
 * PENGAJUAN MENUNGGU PERSETUJUAN — panel di Dashboard
 * ---------------------------------------------------------------------
 */
$stmt = mysqli_prepare($koneksi, "
    SELECT pgj.id, pgj.jenis, pgj.tanggal, pgj.keterangan, pgj.tanggal_pengajuan,
        p.nama, p.jabatan, s.nama_seksi
    FROM pengajuan pgj
    JOIN pegawai p ON p.id = pgj.pegawai_id
    LEFT JOIN seksi s ON s.id = p.seksi_id
    WHERE pgj.status = 'menunggu'
    ORDER BY pgj.tanggal_pengajuan ASC
");
mysqli_stmt_execute($stmt);
$daftar_pengajuan_menunggu = mysqli_stmt_get_result($stmt);
$jumlah_pengajuan_menunggu = mysqli_num_rows($daftar_pengajuan_menunggu);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SIMPEG-H | BPTD Kelas II Lampung</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --navy-900:#0F2438;
  --navy-800:#15304A;
  --navy-700:#1B3A5C;
  --blue-500:#2C6E9B;
  --blue-100:#E4EEF5;
  --teal-500:#1E8F82;
  --teal-100:#E1F1EE;
  --amber-500:#D89B3C;
  --amber-100:#FBF0DE;
  --red-500:#C24B4B;
  --red-100:#FBEAEA;
  --green-500:#2F9464;
  --green-100:#E7F4EE;
  --bg:#F3F5F6;
  --surface:#FFFFFF;
  --ink-900:#14202B;
  --ink-600:#48545E;
  --ink-400:#8894A0;
  --line:#E2E7EA;
  --radius:10px;
  --font-display:'Space Grotesk',sans-serif;
  --font-body:'Plus Jakarta Sans',sans-serif;
  --font-mono:'IBM Plex Mono',monospace;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{
  font-family:var(--font-body);
  background:var(--bg);
  color:var(--ink-900);
  -webkit-font-smoothing:antialiased;
}
.app{display:grid;grid-template-columns:248px 1fr;min-height:100vh;}

/* SIDEBAR */
.sidebar{
  background:var(--navy-900);
  color:#fff;
  padding:26px 18px;
  display:flex;
  flex-direction:column;
  gap:28px;
}
.brand{display:flex;align-items:center;gap:10px;padding:0 6px;}
.brand-mark{
  width:36px;height:36px;border-radius:9px;
  background:linear-gradient(135deg,var(--blue-500),var(--teal-500));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-display);font-weight:700;font-size:14px;color:#fff;
  flex-shrink:0;
}
.brand-text{line-height:1.25;}
.brand-text .name{font-family:var(--font-display);font-weight:700;font-size:15px;letter-spacing:.2px;}
.brand-text .sub{font-size:10.5px;color:#8CA3B8;letter-spacing:.3px;}

.nav-group{display:flex;flex-direction:column;gap:2px;}
.nav-label{font-size:10.5px;text-transform:uppercase;letter-spacing:1px;color:#5D7690;padding:0 12px;margin-bottom:8px;font-weight:600;}
.nav-item{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;border-radius:8px;
  color:#B9C7D4;font-size:13.5px;font-weight:500;
  cursor:pointer;transition:.15s;border:none;background:none;width:100%;text-align:left;font-family:var(--font-body);
}
.nav-item:hover{background:#1A3350;color:#fff;}
.nav-item.active{background:var(--navy-700);color:#fff;}
.nav-item.active .dot{background:var(--amber-500);}
.nav-item .icon{width:18px;height:18px;flex-shrink:0;opacity:.9;}
.lane-tag{
  margin-left:auto;font-size:9.5px;font-family:var(--font-mono);
  padding:1px 6px;border-radius:20px;font-weight:600;
}
.lane-tag.k{background:rgba(44,110,155,.35);color:#9FCBEA;}
.lane-tag.l{background:rgba(30,143,130,.35);color:#8EDBCB;}

.sidebar-foot{margin-top:auto;padding:12px;border-top:1px solid #1E3A56;}
.user-chip{display:flex;align-items:center;gap:10px;}
.avatar{width:32px;height:32px;border-radius:50%;background:var(--amber-500);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:700;font-size:12px;color:var(--navy-900);}
.user-meta .u-name{font-size:12.5px;font-weight:600;color:#fff;}
.user-meta .u-role{font-size:10.5px;color:#7D93A8;}

/* MAIN */
main{padding:28px 34px 60px;max-width:1240px;}
.topbar{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:26px;}
.topbar h1{font-family:var(--font-display);font-size:23px;font-weight:700;letter-spacing:-.2px;}
.topbar p{color:var(--ink-600);font-size:13px;margin-top:4px;}
.date-pill{
  font-family:var(--font-mono);font-size:12px;color:var(--ink-600);
  background:var(--surface);border:1px solid var(--line);padding:8px 14px;border-radius:8px;
}

.view{display:none;}
.view.active{display:block;}

/* CARDS */
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:16px 18px;
}
.stat-card .lbl{font-size:11.5px;color:var(--ink-600);font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
.stat-card .val{font-family:var(--font-display);font-size:26px;font-weight:700;margin-top:6px;}
.stat-card .delta{font-size:11.5px;margin-top:5px;font-family:var(--font-mono);}
.delta.up{color:var(--green-500);}
.delta.down{color:var(--red-500);}
.delta.flat{color:var(--ink-400);}

/* SIGNATURE: TWIN LANE VISUAL */
.twin-lane{
  background:var(--surface);border:1px solid var(--line);border-radius:14px;
  padding:22px 24px 20px;margin-bottom:22px;
}
.twin-lane .th{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:18px;}
.twin-lane h3{font-family:var(--font-display);font-size:15.5px;font-weight:700;}
.twin-lane .sub{font-size:12px;color:var(--ink-600);}
.lanes{display:grid;grid-template-columns:1fr 1fr;gap:0;position:relative;}
.lane{padding:16px 20px;border-radius:10px;position:relative;}
.lane.kantor{background:var(--blue-100);margin-right:8px;}
.lane.lapangan{background:var(--teal-100);margin-left:8px;}
.lane-head{display:flex;align-items:center;gap:8px;margin-bottom:12px;}
.lane-icon{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
.lane.kantor .lane-icon{background:var(--blue-500);color:#fff;}
.lane.lapangan .lane-icon{background:var(--teal-500);color:#fff;}
.lane-head .t{font-weight:700;font-size:13.5px;}
.lane-head .st{font-size:11px;color:var(--ink-600);}
.src-row{display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.65);border-radius:7px;padding:7px 10px;font-size:12px;margin-bottom:6px;}
.src-row .nm{font-weight:600;}
.src-row .method{font-family:var(--font-mono);font-size:10.5px;color:var(--ink-600);}
.badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;font-family:var(--font-mono);}
.badge.ok{background:var(--green-100);color:var(--green-500);}
.badge.warn{background:var(--amber-100);color:#A9761C;}
.badge.bad{background:var(--red-100);color:var(--red-500);}
.converge{
  text-align:center;margin-top:14px;padding-top:14px;border-top:1px dashed var(--line);
  font-family:var(--font-mono);font-size:11.5px;color:var(--ink-600);
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.converge b{color:var(--navy-700);font-weight:700;}
.arrow-down{width:16px;height:16px;color:var(--ink-400);}

/* TABLE */
.panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:20px;}
.panel-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;}
.panel-head h3{font-family:var(--font-display);font-size:14.5px;font-weight:700;}
.panel-head .actions{display:flex;gap:8px;}
.btn{
  font-family:var(--font-body);font-size:12px;font-weight:600;padding:7px 13px;border-radius:7px;border:1px solid var(--line);
  background:var(--surface);color:var(--ink-900);cursor:pointer;
}
.btn.primary{background:var(--navy-700);border-color:var(--navy-700);color:#fff;}
table{width:100%;border-collapse:collapse;}
th{
  text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--ink-400);
  padding:10px 18px;font-weight:600;background:#FAFBFC;border-bottom:1px solid var(--line);
}
td{padding:11px 18px;font-size:13px;border-bottom:1px solid var(--line);}
tr:last-child td{border-bottom:none;}
.mono{font-family:var(--font-mono);font-size:12px;color:var(--ink-600);}
.emp{display:flex;align-items:center;gap:9px;}
.emp .av{width:28px;height:28px;border-radius:50%;background:var(--navy-800);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;font-family:var(--font-display);}
.emp .nm{font-weight:600;font-size:12.5px;}
.emp .role{font-size:11px;color:var(--ink-400);}

/* PHONE MOCK */
.phone-wrap{display:flex;gap:26px;align-items:flex-start;}
.phone{
  width:300px;background:var(--navy-900);border-radius:34px;padding:12px;box-shadow:0 20px 40px rgba(15,36,56,.25);flex-shrink:0;
}
.phone-screen{background:var(--bg);border-radius:24px;overflow:hidden;min-height:560px;display:flex;flex-direction:column;}
.phone-status{display:flex;justify-content:space-between;padding:10px 18px 4px;font-family:var(--font-mono);font-size:10px;color:var(--ink-600);}
.phone-app-bar{background:var(--teal-500);color:#fff;padding:14px 18px 18px;}
.phone-app-bar .loc-t{font-size:10.5px;opacity:.85;letter-spacing:.3px;}
.phone-app-bar .loc-n{font-family:var(--font-display);font-weight:700;font-size:15px;margin-top:2px;}
.map-box{
  margin:14px 16px;height:150px;border-radius:12px;position:relative;overflow:hidden;
  background:
    repeating-linear-gradient(0deg,#DCE9E6 0 1px,transparent 1px 26px),
    repeating-linear-gradient(90deg,#DCE9E6 0 1px,transparent 1px 26px),
    #EAF3F1;
  border:1px solid var(--line);
}
.geofence-ring{
  position:absolute;top:50%;left:50%;width:110px;height:110px;border-radius:50%;
  border:2px dashed var(--teal-500);background:rgba(30,143,130,.08);
  transform:translate(-50%,-50%);
}
.geofence-ring::after{
  content:'';position:absolute;top:50%;left:50%;width:9px;height:9px;border-radius:50%;
  background:var(--teal-500);transform:translate(-50%,-50%);box-shadow:0 0 0 5px rgba(30,143,130,.25);
}
.pin-you{
  position:absolute;top:38%;left:58%;width:8px;height:8px;border-radius:50%;background:var(--red-500);
  box-shadow:0 0 0 5px rgba(194,75,75,.22);
}
.phone-body{padding:2px 18px 18px;flex:1;display:flex;flex-direction:column;gap:12px;}
.geo-status-card{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:13px 14px;}
.geo-status-card .row1{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.geo-status-card .row1 .t{font-weight:700;font-size:13px;}
.geo-status-card .coord{font-family:var(--font-mono);font-size:10.5px;color:var(--ink-600);}
.checkin-btn{
  margin-top:auto;background:var(--teal-500);color:#fff;border:none;border-radius:12px;
  padding:15px;text-align:center;font-family:var(--font-display);font-weight:700;font-size:14px;
}
.checkin-btn .sub{font-family:var(--font-body);font-weight:500;font-size:10.5px;opacity:.85;margin-top:2px;}
.upload-photo{
  border:1.5px dashed var(--ink-400);border-radius:10px;padding:12px;text-align:center;font-size:11.5px;color:var(--ink-600);
}

.phone-side{flex:1;padding-top:6px;}
.phone-side h4{font-family:var(--font-display);font-size:14px;margin-bottom:10px;}
.info-list{display:flex;flex-direction:column;gap:8px;}
.info-list li{list-style:none;display:flex;gap:10px;font-size:12.5px;color:var(--ink-600);align-items:flex-start;}
.info-list .num{width:18px;height:18px;border-radius:5px;background:var(--teal-100);color:var(--teal-500);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:var(--font-mono);margin-top:1px;}

/* GEOFENCE ADMIN */
.geo-cards{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.geo-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px;}
.geo-card .th{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}
.geo-card .t{font-weight:700;font-size:13.5px;}
.geo-card .addr{font-size:11.5px;color:var(--ink-400);margin-top:2px;}
.geo-meta{display:flex;gap:16px;margin-top:12px;padding-top:12px;border-top:1px solid var(--line);}
.geo-meta div{font-size:11px;}
.geo-meta .k{color:var(--ink-400);text-transform:uppercase;letter-spacing:.3px;font-size:9.5px;font-weight:600;}
.geo-meta .v{font-family:var(--font-mono);font-weight:600;margin-top:2px;font-size:12.5px;}

/* TUKIN */
.tukin-bar{height:8px;border-radius:6px;background:var(--line);overflow:hidden;width:100%;}
.tukin-bar span{display:block;height:100%;background:var(--amber-500);}

footer.note{margin-top:28px;padding:14px 18px;background:var(--amber-100);border:1px solid #EFDDB6;border-radius:10px;font-size:12px;color:#7A5A19;}

/* ===== FITUR TAMBAHAN: foto wajib + offline + jadwal ===== */
.offline-banner{display:flex;align-items:center;gap:8px;background:var(--amber-100);color:#7A5A19;border-radius:9px;padding:9px 12px;font-size:11.5px;font-weight:600;margin-bottom:12px;}
.camera-mandatory{
  border-radius:12px;background:var(--navy-900);position:relative;overflow:hidden;
  height:150px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;
}
.camera-mandatory .cam-ic{font-size:30px;opacity:.55;}
.camera-mandatory .cam-overlay{
  position:absolute;left:8px;right:8px;bottom:8px;background:rgba(0,0,0,.55);border-radius:7px;
  padding:6px 9px;font-family:var(--font-mono);font-size:9.5px;color:#fff;line-height:1.5;
}
.schedule-row{display:flex;justify-content:space-between;align-items:center;background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-size:12px;margin-bottom:10px;}
.schedule-row .k{color:var(--ink-600);}
.offline-switch{width:38px;height:22px;border-radius:20px;background:var(--line);position:relative;flex-shrink:0;transition:.2s;cursor:pointer;}
.offline-switch::after{content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.2s;}
.offline-switch.on{background:var(--amber-500);}
.offline-switch.on::after{left:18px;}

/* AUDIT TRAIL */
.audit-row{cursor:pointer;}
.audit-row:hover{background:#FAFBFC;}
.audit-detail{display:none;background:#F7F9FA;}
.audit-detail.open{display:table-row;}
.audit-detail td{padding:14px 18px 16px 46px;border-bottom:1px solid var(--line);}
.audit-trace{display:flex;flex-direction:column;gap:6px;font-size:12px;}
.audit-trace .lk{display:flex;gap:8px;align-items:baseline;}
.audit-trace .lk b{font-family:var(--font-mono);color:var(--navy-700);font-weight:700;}
.chev-ic{font-size:11px;color:var(--ink-400);margin-left:6px;}

/* KECURIGAAN DASHBOARD */
.row-flag-danger{background:var(--red-100);}
.row-flag-warn{background:var(--amber-100);}
.photo-thumb{width:32px;height:32px;border-radius:8px;background:var(--line);display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--ink-600);flex-shrink:0;}

/* SIDEBAR LOGOUT */
.logout-link{margin-top:12px;font-size:11.5px;color:#8CA3B8;display:flex;align-items:center;gap:6px;cursor:pointer;padding:4px 2px;transition:.15s;}
.logout-link:hover{color:#fff;}

/* SELECT FILTER (dipakai untuk Filter Seksi/Periode) */
select.btn{appearance:none;-webkit-appearance:none;padding-right:26px;
  background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6'><path d='M0 0l5 6 5-6z' fill='%2348545E'/></svg>");
  background-repeat:no-repeat;background-position:right 10px center;cursor:pointer;}

/* ===== MODAL ===== */
.modal-overlay{position:fixed;inset:0;background:rgba(15,36,56,.45);display:none;align-items:center;justify-content:center;z-index:100;padding:20px;}
.modal-overlay.show{display:flex;}
.modal-box{background:var(--surface);border-radius:14px;padding:24px 26px;width:460px;max-width:100%;max-height:88vh;overflow-y:auto;box-shadow:0 24px 48px rgba(15,36,56,.3);}
.modal-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;}
.modal-head h3{font-family:var(--font-display);font-size:16.5px;font-weight:700;}
.modal-head p{font-size:12px;color:var(--ink-600);margin-top:3px;}
.modal-x{font-size:15px;color:var(--ink-400);background:none;border:none;cursor:pointer;flex-shrink:0;}
.modal-x:hover{color:var(--ink-900);}
.mfield{margin-bottom:13px;}
.mfield label{display:block;font-size:11.5px;font-weight:600;color:var(--ink-600);margin-bottom:6px;}
.mfield input,.mfield select{width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:8px;font-family:var(--font-body);font-size:13px;background:var(--bg);outline:none;color:var(--ink-900);}
.mfield input:focus,.mfield select:focus{border-color:var(--teal-500);background:#fff;}
.mrow{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;}
.rule-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--line);font-size:12.5px;}
.rule-row:last-child{border-bottom:none;}
.rule-row .kd{font-family:var(--font-mono);font-size:11px;color:var(--ink-400);}
</style>
</head>
<body>

<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">SH</div>
      <div class="brand-text">
        <div class="name">SIMPEG-H</div>
        <div class="sub">BPTD Kelas II Lampung</div>
      </div>
    </div>

    <div class="nav-group">
      <div class="nav-label">Ringkasan</div>
      <button class="nav-item active" data-view="dashboard"><span class="dot" style="width:5px;height:5px;border-radius:50%;background:#5D7690;display:inline-block"></span>Dashboard</button>
    </div>

    <div class="nav-group">
      <div class="nav-label">Absensi</div>
      <button class="nav-item" data-view="kantor">Rekap Kantor <span class="lane-tag k">FP</span></button>
      <button class="nav-item" data-view="impor_fingerprint">Impor Mesin <span class="lane-tag k">XLS</span></button>
      <button class="nav-item" data-view="lapangan">Cek-in Lapangan <span class="lane-tag l">GPS</span></button>
      <button class="nav-item" data-view="kecurigaan">Monitoring Kecurigaan <span class="lane-tag l" style="background:var(--amber-100);color:#A9761C">!</span></button>
    </div>

    <div class="nav-group">
      <div class="nav-label">Administrasi</div>
      <button class="nav-item" data-view="geofence">Titik Lokasi Tugas</button>
      <button class="nav-item" data-view="kalender">Kalender Libur</button>
      <button class="nav-item" data-view="tukin">Tunjangan Kinerja</button>
      <button class="nav-item" data-view="audit">Audit Trail Tukin</button>
      <button class="nav-item" data-view="pegawai">Data Pegawai</button>
    </div>

    <div class="sidebar-foot">
      <div class="user-chip">
        <div class="avatar"><?php
            $nama_login = $_SESSION['nama'] ?? '?';
            $kata = preg_split('/\s+/', trim($nama_login));
            echo htmlspecialchars(strtoupper(substr($kata[0], 0, 1) . (isset($kata[1]) ? substr($kata[1], 0, 1) : '')));
        ?></div>
        <div class="user-meta">
          <div class="u-name"><?php echo htmlspecialchars($_SESSION['nama'] ?? '—'); ?></div>
          <div class="u-role"><?php echo $_SESSION['role'] === 'admin' ? 'Admin' : 'Kasubbag TU'; ?></div>
        </div>
      </div>
      <div class="logout-link" onclick="doLogout()">⏻ Keluar Akun</div>
    </div>
  </aside>

  <main>

    <?php if ($csrf_gagal): ?>
    <div class="panel" style="border-left:4px solid var(--red-500);margin-bottom:16px;padding:12px 16px;">
      <b>Sesi Form Kedaluwarsa</b> — Halaman sudah terlalu lama dibuka, silakan ulangi aksinya.
    </div>
    <?php endif; ?>

    <!-- DASHBOARD -->
    <section class="view active" id="v-dashboard">
      <div class="topbar">
        <div>
          <h1>Dashboard Kehadiran Terpadu</h1>
          <p>Rekap real-time seluruh seksi — kantor &amp; lapangan dalam satu sistem</p>
        </div>
        <div class="date-pill"><?php echo htmlspecialchars($hari_ini_tampil); ?></div>
      </div>

      <div class="grid-4">
        <div class="stat-card">
          <div class="lbl">Hadir Hari Ini</div>
          <div class="val"><?php echo (int) $stat_hari_ini['hadir']; ?><span style="font-size:14px;color:var(--ink-400);font-weight:600"> / <?php echo $total_pegawai_aktif; ?></span></div>
          <div class="delta flat">dari seluruh pegawai aktif</div>
        </div>
        <div class="stat-card">
          <div class="lbl">Via Fingerprint</div>
          <div class="val" style="color:var(--blue-500)"><?php echo (int) $stat_hari_ini['via_fingerprint']; ?></div>
          <div class="delta flat">Pegawai kantor</div>
        </div>
        <div class="stat-card">
          <div class="lbl">Via Geolocation</div>
          <div class="val" style="color:var(--teal-500)"><?php echo (int) $stat_hari_ini['via_geolocation']; ?></div>
          <div class="delta flat">Jembatan timbang, posko, patroli</div>
        </div>
        <div class="stat-card">
          <div class="lbl">Perlu Verifikasi</div>
          <div class="val" style="color:var(--amber-500)"><?php echo (int) $stat_hari_ini['perlu_verifikasi']; ?></div>
          <div class="delta flat">Di luar radius / indikasi lokasi palsu</div>
        </div>
      </div>

      <div class="twin-lane">
        <div class="th">
          <div>
            <h3>Alur Data Absensi Hybrid</h3>
            <div class="sub">Dua sumber, satu rekap — inilah kebaruan sistem</div>
          </div>
        </div>
        <div class="lanes">
          <div class="lane kantor">
            <div class="lane-head">
              <div class="lane-icon">🖐</div>
              <div><div class="t">Jalur Kantor</div><div class="st">Mesin fingerprint · Seksi TU, Prasarana, Sarana</div></div>
            </div>
            <?php if (mysqli_num_rows($contoh_kantor) === 0): ?>
            <div class="src-row" style="color:var(--ink-400);font-size:12px">Belum ada cek-in fingerprint hari ini</div>
            <?php endif; ?>
            <?php while ($ck = mysqli_fetch_assoc($contoh_kantor)): ?>
            <div class="src-row"><span class="nm"><?php echo htmlspecialchars($ck['nama']); ?></span><span class="method"><?php echo substr($ck['jam_masuk'], 0, 8); ?></span><span class="badge <?php echo $ck['status_kehadiran'] === 'terlambat' ? 'warn' : 'ok'; ?>"><?php echo $ck['status_kehadiran'] === 'terlambat' ? 'Telat' : 'Tepat'; ?></span></div>
            <?php endwhile; ?>
          </div>
          <div class="lane lapangan">
            <div class="lane-head">
              <div class="lane-icon">📍</div>
              <div><div class="t">Jalur Lapangan</div><div class="st">Geolocation HP · Seksi LLASDP, Patroli</div></div>
            </div>
            <?php if (mysqli_num_rows($contoh_lapangan) === 0): ?>
            <div class="src-row" style="color:var(--ink-400);font-size:12px">Belum ada cek-in geolocation hari ini</div>
            <?php endif; ?>
            <?php while ($cl = mysqli_fetch_assoc($contoh_lapangan)): ?>
            <div class="src-row"><span class="nm"><?php echo htmlspecialchars($cl['nama']); ?></span><span class="method"><?php echo round($cl['latitude'], 3) . ', ' . round($cl['longitude'], 3); ?></span><span class="badge <?php echo $cl['status_radius'] === 'luar_radius' ? 'bad' : 'ok'; ?>"><?php echo $cl['status_radius'] === 'luar_radius' ? 'Luar radius' : 'Dalam radius'; ?></span></div>
            <?php endwhile; ?>
          </div>
        </div>
        <div class="converge">
          <span>tabel_absensi</span> · kolom <b>sumber_absen</b> membedakan asal data → dibaca satu <b>rule engine</b> untuk potongan Tukin
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h3>Kehadiran per Seksi — Hari Ini</h3>
          <div class="actions"><a href="cetak_kehadiran_seksi.php" target="_blank" class="btn" style="text-decoration:none">🖨️ Cetak / Ekspor PDF</a></div>
        </div>
        <table>
          <tr><th>Seksi</th><th>Total Pegawai</th><th>Hadir</th><th>Fingerprint</th><th>Geolocation</th><th>Tingkat Hadir</th></tr>
          <tbody>
          <?php while ($rk = mysqli_fetch_assoc($rekap_seksi_hari_ini)):
              $persen = ((int) $rk['total_pegawai'] > 0) ? round((int) $rk['hadir'] / (int) $rk['total_pegawai'] * 100) : 0;
          ?>
          <tr><td><?php echo htmlspecialchars($rk['nama_seksi']); ?></td><td class="mono"><?php echo (int) $rk['total_pegawai']; ?></td><td class="mono"><?php echo (int) $rk['hadir']; ?></td><td class="mono"><?php echo (int) $rk['via_fp']; ?></td><td class="mono"><?php echo (int) $rk['via_geo']; ?></td><td><div class="tukin-bar"><span style="width:<?php echo $persen; ?>%"></span></div></td></tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pesan_pengajuan_admin): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_pengajuan_admin[0] === 'ok' ? 'green' : ($pesan_pengajuan_admin[0] === 'bad' ? 'red' : 'amber'); ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_pengajuan_admin[1]); ?></b> — <?php echo htmlspecialchars($pesan_pengajuan_admin[2]); ?>
      </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-head">
          <h3>Pengajuan Menunggu Persetujuan<?php echo $jumlah_pengajuan_menunggu > 0 ? ' <span class="badge warn" style="margin-left:6px">' . $jumlah_pengajuan_menunggu . '</span>' : ''; ?></h3>
          <div class="actions"><a href="admin.php?view=pegawai" class="btn" style="text-decoration:none">Lihat Data Pegawai</a></div>
        </div>
        <table>
          <tr><th>Pegawai</th><th>Jenis</th><th>Tanggal</th><th>Keterangan</th><th>Diajukan</th><th>Aksi</th></tr>
          <tbody>
          <?php if ($jumlah_pengajuan_menunggu === 0): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--ink-400)">Tidak ada pengajuan yang menunggu persetujuan.</td></tr>
          <?php endif; ?>
          <?php while ($pm = mysqli_fetch_assoc($daftar_pengajuan_menunggu)):
              $label_jenis_pm = ['izin_sakit' => 'Izin Sakit', 'cuti_tahunan' => 'Cuti Tahunan', 'dinas_luar' => 'Dinas Luar'][$pm['jenis']] ?? $pm['jenis'];
          ?>
          <tr>
            <td><?php echo htmlspecialchars($pm['nama']); ?><div class="role" style="font-size:11px;color:var(--ink-400)"><?php echo htmlspecialchars($pm['nama_seksi'] ?? '—'); ?></div></td>
            <td><?php echo htmlspecialchars($label_jenis_pm); ?></td>
            <td class="mono"><?php echo tgl_id($pm['tanggal']); ?></td>
            <td><?php echo htmlspecialchars($pm['keterangan'] ?: '—'); ?></td>
            <td class="mono" style="font-size:11.5px;color:var(--ink-400)"><?php echo date('d/m H:i', strtotime($pm['tanggal_pengajuan'])); ?></td>
            <td>
              <form method="POST" action="proses_approve_pengajuan.php" style="display:inline-block">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="pengajuan_id" value="<?php echo (int) $pm['id']; ?>">
                <input type="hidden" name="keputusan" value="setuju">
                <button type="submit" class="btn" style="color:var(--green-500);padding:5px 10px;font-size:11.5px">✓ Setuju</button>
              </form>
              <form method="POST" action="proses_approve_pengajuan.php" style="display:inline-block" onsubmit="return confirm('Tolak pengajuan ini?');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="pengajuan_id" value="<?php echo (int) $pm['id']; ?>">
                <input type="hidden" name="keputusan" value="tolak">
                <button type="submit" class="btn" style="color:var(--red-500);padding:5px 10px;font-size:11.5px">✕ Tolak</button>
              </form>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- KANTOR -->
    <section class="view" id="v-kantor">
      <div class="topbar">
        <div>
          <h1>Rekap Absensi Kantor</h1>
          <p>Presensi fingerprint kantor disinkronisasi melalui <b>Impor Laporan Berkas Mesin (.xls/.xlsx/.csv)</b>, push daemon otomatis, atau input manual admin.</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <a href="admin.php?view=impor_fingerprint" class="btn" style="text-decoration:none">📥 Impor Laporan Mesin</a>
          <button class="btn primary" onclick="openModal('modalManualFP')">+ Input Manual</button>
          <div class="date-pill">Hari ini · <?php echo date('H:i'); ?> WIB</div>
        </div>
      </div>
      <?php if ($pesan_manual): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_manual[0] === 'ok' ? 'green' : 'red'; ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_manual[1]); ?></b> — <?php echo htmlspecialchars($pesan_manual[2]); ?>
      </div>
      <?php endif; ?>
      <div class="panel">
        <div class="panel-head">
          <h3>Log Fingerprint — Hari Ini</h3>
          <div class="actions">
            <select class="btn" id="filterSeksiKantor" onchange="filterKantor()">
              <option value="">Filter Seksi: Semua</option>
              <?php foreach ($daftar_seksi_arr as $sk): ?>
              <option value="<?php echo htmlspecialchars($sk['nama_seksi']); ?>"><?php echo htmlspecialchars($sk['nama_seksi']); ?></option>
              <?php endforeach; ?>
            </select>
            <a href="cetak_kehadiran_seksi.php" target="_blank" class="btn primary" style="text-decoration:none">🖨️ Cetak / Ekspor PDF</a>
          </div>
        </div>
        <table>
          <tr><th>Pegawai</th><th>NIP</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Status</th><th>Potongan Tukin</th></tr>
          <tbody id="tblKantor">
          <?php while ($bk = mysqli_fetch_assoc($baris_kantor)):
              $inisial = strtoupper(substr($bk['nama'], 0, 1) . (strpos($bk['nama'], ' ') !== false ? substr(strrchr($bk['nama'], ' '), 1, 1) : ''));
              if ($bk['absensi_id'] === null) {
                  $badge = '<span class="badge bad">Belum Cek-in</span>';
              } elseif ($bk['status_kehadiran'] === 'terlambat') {
                  $badge = '<span class="badge warn">Terlambat</span>';
              } else {
                  $badge = '<span class="badge ok">Tepat Waktu</span>';
              }
          ?>
          <tr data-seksi="<?php echo htmlspecialchars($bk['nama_seksi'] ?? ''); ?>">
            <td><div class="emp"><div class="av"><?php echo htmlspecialchars($inisial); ?></div><div><div class="nm"><?php echo htmlspecialchars($bk['nama']); ?></div><div class="role"><?php echo htmlspecialchars($bk['jabatan'] ?? ''); ?></div></div></div></td>
            <td class="mono"><?php echo htmlspecialchars($bk['nip']); ?></td>
            <td class="mono"><?php echo $bk['jam_masuk'] ? substr($bk['jam_masuk'], 0, 8) : '—'; ?></td>
            <td class="mono"><?php echo $bk['jam_pulang'] ? substr($bk['jam_pulang'], 0, 8) : '—'; ?></td>
            <td><?php echo $badge; ?></td>
            <td class="mono">Rp <?php echo number_format((float) $bk['potongan'], 0, ',', '.'); ?></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <footer class="note">Baris "Belum Cek-in" berarti belum ada data yang diinput admin atau ditarik dari mesin untuk pegawai ini hari ini. Status ini otomatis berubah jadi "Alpa" (atau "Cuti" kalau ada izin yang disetujui) setelah <span class="mono">cron_tandai_alpa.php</span> berjalan di akhir hari kerja (jam 23:00 lewat Task Scheduler).</footer>
    </section>

    <!-- IMPOR LAPORAN MESIN FINGERPRINT (W2) -->
    <section class="view" id="v-impor_fingerprint">
      <?php include 'admin_impor_fingerprint.php'; ?>
    </section>

    <!-- LAPANGAN -->
    <section class="view" id="v-lapangan">
      <div class="topbar">
        <div>
          <h1>Cek-in Lapangan</h1>
          <p>Tampilan aplikasi web (PWA) untuk pegawai patroli &amp; dinas luar</p>
        </div>
      </div>
      <div class="phone-wrap">
        <div class="phone">
          <div class="phone-screen">
            <div class="phone-status"><span>9:41</span><span>●●● 4G</span></div>
            <div class="phone-app-bar">
              <div class="loc-t">TITIK TUGAS TERDEKAT</div>
              <div class="loc-n">Jembatan Timbang Way Urang</div>
            </div>
            <div class="map-box">
              <div class="geofence-ring"></div>
              <div class="pin-you"></div>
            </div>
            <div class="phone-body">
              <div style="display:flex;justify-content:space-between;align-items:center;background:var(--surface);border:1px solid var(--line);border-radius:9px;padding:8px 11px;font-size:11px;color:var(--ink-600);margin-bottom:2px;">
                <span>Simulasikan offline</span>
                <div class="offline-switch on" id="adminOfflineSwitch" onclick="toggleAdminOffline()" style="width:34px;height:20px;"></div>
              </div>
              <div class="offline-banner" id="adminOfflineBanner">📶 Tidak ada koneksi internet</div>
              <div class="geo-status-card">
                <div class="row1"><span class="t">Status Lokasi</span><span class="badge ok">Dalam Radius</span></div>
                <div class="coord">-5.4291° S, 105.2612° E · jarak 42 m</div>
              </div>
              <div class="camera-mandatory">
                <span class="cam-ic">🤳</span>
                <div class="cam-overlay">-5.4291, 105.2612 · akurasi 6 m<br>26 Jul 2026, 07:55:12 WIB</div>
              </div>
              <div class="schedule-row"><span class="k">Jadwal Dinas Luar</span><span class="badge ok">Sesuai Jadwal</span></div>
              <div class="checkin-btn" id="adminCheckinBtn" style="background:var(--amber-500)">Simpan Cek-in (Offline)<div class="sub">Doni Prasetyo · Seksi LLASDP</div></div>
            </div>
          </div>
        </div>
        <div class="phone-side">
          <h4>Cara kerja verifikasi</h4>
          <ul class="info-list">
            <li><span class="num">1</span>Aplikasi mengambil koordinat GPS HP saat pegawai menekan cek-in.</li>
            <li><span class="num">2</span>Koordinat dicocokkan dengan titik lokasi tugas terdaftar (radius geofencing, mis. 100–200 m).</li>
            <li><span class="num">3</span>Jika di luar radius, status berubah "Perlu Verifikasi" dan masuk antrian persetujuan atasan.</li>
            <li><span class="num">4</span>Foto <b>wajib</b> diambil langsung dari kamera (bukan unggahan) — GPS &amp; waktu ditumpangkan otomatis pada foto sebagai bukti tambahan.</li>
            <li><span class="num">5</span>Waktu &amp; lokasi cek-in dicocokkan otomatis dengan jadwal dinas luar pegawai yang terdaftar hari itu.</li>
            <li><span class="num">6</span>Data tersimpan ke tabel absensi yang sama dengan fingerprint, ditandai <span class="mono" style="background:var(--teal-100);padding:1px 6px;border-radius:4px">sumber_absen: geolocation</span>.</li>
          </ul>
          <footer class="note">Jika sinyal lemah, foto &amp; data cek-in disimpan sementara di perangkat (IndexedDB via Service Worker) dan disinkronkan otomatis begitu koneksi kembali tersedia — waktu cek-in yang sah tetap mengikuti saat data dibuat di perangkat, bukan saat diterima server.</footer>
        </div>
      </div>

      <div class="panel" style="margin-top:20px">
        <div class="panel-head">
          <h3>Log Cek-in Lapangan — Hari Ini</h3>
          <div class="actions"><div class="date-pill">Hari ini · <?php echo date('H:i'); ?> WIB</div></div>
        </div>
        <table>
          <tr><th>Pegawai</th><th>Titik Lokasi</th><th>Jam Masuk</th><th>Jarak</th><th>Status Radius</th><th>Sinkronisasi</th><th>Verifikasi</th><th>Foto</th></tr>
          <tbody>
          <?php if (mysqli_num_rows($baris_lapangan) === 0): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--ink-400)">Belum ada cek-in via geolocation hari ini.</td></tr>
          <?php endif; ?>
          <?php while ($lp = mysqli_fetch_assoc($baris_lapangan)):
              $inisial = strtoupper(substr($lp['nama'], 0, 1) . (strpos($lp['nama'], ' ') !== false ? substr(strrchr($lp['nama'], ' '), 1, 1) : ''));
              $badge_radius = $lp['status_radius'] === 'dalam_radius'
                  ? '<span class="badge ok">Dalam Radius</span>'
                  : ($lp['status_radius'] === 'luar_radius' ? '<span class="badge bad">Luar Radius</span>' : '<span class="badge warn">Tidak Berlaku</span>');
              $badge_verif = ['otomatis_sah' => 'ok', 'perlu_verifikasi' => 'warn', 'ditolak' => 'bad', 'disetujui' => 'ok'][$lp['status_verifikasi']] ?? 'warn';
          ?>
          <tr>
            <td><div class="emp"><div class="av"><?php echo htmlspecialchars($inisial); ?></div><div><div class="nm"><?php echo htmlspecialchars($lp['nama']); ?></div><div class="role"><?php echo htmlspecialchars($lp['nama_seksi'] ?? '—'); ?></div></div></div></td>
            <td><?php echo htmlspecialchars($lp['nama_lokasi'] ?? '—'); ?></td>
            <td class="mono"><?php echo $lp['jam_masuk'] ? substr($lp['jam_masuk'], 0, 8) : '—'; ?></td>
            <td class="mono"><?php echo $lp['jarak_dari_titik_meter'] !== null ? round((float) $lp['jarak_dari_titik_meter']) . ' m' : '—'; ?></td>
            <td><?php echo $badge_radius; ?><?php echo $lp['is_mock_location'] ? ' <span class="badge bad" style="margin-left:4px">⚠ Mock</span>' : ''; ?></td>
            <td><?php echo $lp['status_sinkronisasi'] === 'online' ? '<span class="badge ok">Online</span>' : ($lp['status_sinkronisasi'] === 'tersimpan_lokal' ? '<span class="badge warn">Tersimpan Lokal</span>' : '<span class="badge ok">Tersinkron</span>'); ?></td>
            <td><span class="badge <?php echo $badge_verif; ?>"><?php echo ucfirst(str_replace('_', ' ', $lp['status_verifikasi'])); ?></span></td>
            <td><?php echo $lp['foto_url'] ? '<a href="' . htmlspecialchars($lp['foto_url']) . '" target="_blank">Lihat</a>' : '—'; ?></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- MONITORING KECURIGAAN -->
    <section class="view" id="v-kecurigaan">
      <div class="topbar">
        <div>
          <h1>Monitoring Kecurigaan Offline &amp; Lokasi</h1>
          <p>Rekap frekuensi cek-in offline dan indikasi lokasi palsu (mock location) untuk ditinjau atasan</p>
        </div>
        <div class="date-pill">Periode: <?php echo htmlspecialchars($nama_bulan[$bulan_filter] . ' ' . $tahun_filter); ?></div>
      </div>

      <div class="grid-4">
        <div class="stat-card">
          <div class="lbl">Cek-in Offline Bulan Ini</div>
          <div class="val"><?php echo (int) $stat_kecurigaan['offline_count']; ?></div>
          <div class="delta flat">Dari seluruh cek-in lapangan</div>
        </div>
        <div class="stat-card">
          <div class="lbl">Menunggu Verifikasi</div>
          <div class="val" style="color:var(--amber-500)"><?php echo (int) $stat_kecurigaan['menunggu_count']; ?></div>
          <div class="delta flat">Perlu ditinjau Kasubbag TU</div>
        </div>
        <div class="stat-card">
          <div class="lbl">Indikasi Lokasi Palsu</div>
          <div class="val" style="color:var(--red-500)"><?php echo (int) $stat_kecurigaan['spoof_count']; ?></div>
          <div class="delta flat">Kecepatan/akurasi janggal</div>
        </div>
        <div class="stat-card">
          <div class="lbl">Rata-rata Durasi Offline</div>
          <div class="val"><?php echo htmlspecialchars($rata_offline_txt); ?></div>
          <div class="delta flat">Sebelum tersinkron ke server</div>
        </div>
      </div>

      <?php if ($pesan_verifikasi): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_verifikasi[0] === 'ok' ? 'green' : ($pesan_verifikasi[0] === 'bad' ? 'red' : 'amber'); ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_verifikasi[1]); ?></b> — <?php echo htmlspecialchars($pesan_verifikasi[2]); ?>
      </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-head">
          <h3>Baris Perlu Ditinjau &amp; Riwayat Verifikasi</h3>
          <div class="actions">
            <select class="btn" onchange="if(this.value) window.location.href='admin.php?view=kecurigaan&'+this.value">
              <?php for ($i = 0; $i < 3; $i++):
                  $b = $bulan_filter - $i; $t = $tahun_filter;
                  if ($b <= 0) { $b += 12; $t -= 1; }
              ?>
              <option value="bulan=<?php echo $b; ?>&tahun=<?php echo $t; ?>" <?php echo ($b === $bulan_filter && $t === $tahun_filter) ? 'selected' : ''; ?>>Periode: <?php echo $nama_bulan[$b] . ' ' . $t; ?></option>
              <?php endfor; ?>
            </select>
            <a href="cetak_kecurigaan.php?bulan=<?php echo $bulan_filter; ?>&tahun=<?php echo $tahun_filter; ?>" target="_blank" class="btn primary" style="text-decoration:none">🖨️ Cetak / Ekspor PDF</a>
          </div>
        </div>
        <table>
          <tr><th>Pegawai</th><th>Tanggal</th><th>Jadwal Dinas Luar</th><th>Sinkronisasi</th><th>Lokasi</th><th>Foto</th><th>Indikasi</th><th>Aksi / Status</th></tr>
          <tbody>
          <?php if (mysqli_num_rows($baris_kecurigaan) === 0): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--ink-400)">Tidak ada cek-in offline, luar radius, indikasi lokasi palsu, atau keterlambatan dengan alasan pada periode ini.</td></tr>
          <?php endif; ?>
          <?php while ($r = mysqli_fetch_assoc($baris_kecurigaan)):
              $inisial = strtoupper(substr($r['nama'], 0, 1) . (strpos($r['nama'], ' ') !== false ? substr(strrchr($r['nama'], ' '), 1, 1) : ''));
              $row_class = $r['is_mock_location'] ? 'row-flag-danger' : ($r['status_verifikasi'] === 'perlu_verifikasi' ? 'row-flag-warn' : '');

              $badge_jadwal = '<span class="mono" style="color:var(--ink-400)">—</span>';
              if ($r['status_jadwal'] === 'sesuai')       $badge_jadwal = '<span class="badge ok">Sesuai</span>';
              elseif ($r['status_jadwal'] === 'diluar_jadwal') $badge_jadwal = '<span class="badge warn">Di luar jadwal</span>';
              elseif ($r['status_jadwal'] === 'tidak_ada_jadwal') $badge_jadwal = '<span class="badge bad">Tanpa jadwal</span>';

              $badge_sinkron = ($r['status_sinkronisasi'] === 'tersinkron')
                  ? '<span class="badge warn">Offline → Sinkron</span>'
                  : '<span class="mono" style="color:var(--ink-400)">Online</span>';

              $badge_indikasi = '<span class="mono" style="color:var(--ink-400)">—</span>';
              if ($r['is_mock_location']) {
                  $badge_indikasi = '<span class="badge bad" title="' . htmlspecialchars($r['alasan_indikasi_spoofing'] ?? '') . '">Kemungkinan Lokasi Palsu</span>';
              } elseif ($r['status_radius'] === 'luar_radius') {
                  $badge_indikasi = '<span class="badge warn">Luar Radius</span>';
              } elseif ($r['status_kehadiran'] === 'terlambat' && $r['alasan_terlambat']) {
                  $badge_indikasi = '<span class="badge warn" title="' . htmlspecialchars($r['alasan_terlambat']) . '">Terlambat ' . (int) $r['menit_terlambat'] . ' menit — ada alasan</span>';
              }
          ?>
          <tr class="<?php echo $row_class; ?>">
            <td><div class="emp"><div class="av"><?php echo htmlspecialchars($inisial); ?></div><div><div class="nm"><?php echo htmlspecialchars($r['nama']); ?></div><div class="role"><?php echo htmlspecialchars($r['jabatan'] ?? ''); ?></div></div></div></td>
            <td class="mono"><?php echo tgl_id($r['tanggal']); ?></td>
            <td><?php echo $badge_jadwal; ?></td>
            <td><?php echo $badge_sinkron; ?></td>
            <td class="mono"><?php echo htmlspecialchars($r['nama_lokasi'] ?? '—'); ?></td>
            <td>
              <?php if ($r['foto_url']): ?>
              <a href="<?php echo htmlspecialchars($r['foto_url']); ?>" target="_blank"><div class="photo-thumb" style="background-image:url('<?php echo htmlspecialchars($r['foto_url']); ?>');background-size:cover;background-position:center;"></div></a>
              <?php else: ?>
              <div class="photo-thumb">📷</div>
              <?php endif; ?>
            </td>
            <td><?php echo $badge_indikasi; ?></td>
            <td>
              <?php if ($r['status_verifikasi'] === 'perlu_verifikasi'): ?>
                <form method="POST" action="proses_verifikasi.php" style="display:inline-block">
                <?php echo csrf_field(); ?>
                  <input type="hidden" name="absensi_id" value="<?php echo (int) $r['absensi_id']; ?>">
                  <input type="hidden" name="keputusan" value="setuju">
                  <input type="hidden" name="bulan" value="<?php echo $bulan_filter; ?>">
                  <input type="hidden" name="tahun" value="<?php echo $tahun_filter; ?>">
                  <button type="submit" class="btn" style="color:var(--green-500);padding:5px 10px;font-size:11.5px">✓ Setuju</button>
                </form>
                <form method="POST" action="proses_verifikasi.php" style="display:inline-block" onsubmit="return confirm('Tolak cek-in ini sebagai tidak sah? Potongan tukin akan berlaku.');">
                <?php echo csrf_field(); ?>
                  <input type="hidden" name="absensi_id" value="<?php echo (int) $r['absensi_id']; ?>">
                  <input type="hidden" name="keputusan" value="tolak">
                  <input type="hidden" name="bulan" value="<?php echo $bulan_filter; ?>">
                  <input type="hidden" name="tahun" value="<?php echo $tahun_filter; ?>">
                  <button type="submit" class="btn" style="color:var(--red-500);padding:5px 10px;font-size:11.5px">✕ Tolak</button>
                </form>
              <?php elseif ($r['status_verifikasi'] === 'disetujui'): ?>
                <span class="mono" style="color:var(--green-500);font-weight:700" title="<?php echo htmlspecialchars($r['catatan_verifikasi'] ?? ''); ?>">Disetujui</span>
              <?php elseif ($r['status_verifikasi'] === 'ditolak'): ?>
                <span class="mono" style="color:var(--red-500);font-weight:700" title="<?php echo htmlspecialchars($r['catatan_verifikasi'] ?? ''); ?>">Ditolak</span>
              <?php else: ?>
                <span class="mono" style="color:var(--ink-400)">Otomatis Sah</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <footer class="note">Deteksi lokasi palsu bersifat heuristik (kecepatan perpindahan tidak wajar, akurasi GPS janggal, koordinat berulang persis) — sistem hanya menandai untuk ditinjau. Tombol <b>Setuju</b> berarti cek-in dianggap sah walau ditandai (tidak ada potongan); <b>Tolak</b> berarti cek-in dianggap tidak sah dan akan masuk sebagai potongan pada perhitungan tukin.</footer>
    </section>

    <!-- AUDIT TRAIL TUKIN -->
    <section class="view" id="v-audit">
      <div class="topbar">
        <div>
          <h1>Audit Trail Perhitungan Tukin</h1>
          <p>Telusuri setiap potongan tukin kembali ke baris absensi &amp; aturan yang menjadi dasarnya</p>
        </div>
        <div class="date-pill">Periode: <?php echo htmlspecialchars($nama_bulan[$bulan_filter] . ' ' . $tahun_filter); ?></div>
      </div>
      <div class="panel">
        <div class="panel-head">
          <h3>Rincian Potongan — Klik baris untuk menelusuri</h3>
        </div>
        <table>
          <tr><th>Pegawai</th><th>Tanggal</th><th>Sebab Potongan</th><th>Potongan</th><th></th></tr>
          <tbody>
          <?php if (mysqli_num_rows($baris_audit) === 0): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--ink-400)">Belum ada rincian potongan untuk periode ini — klik "Hitung Tukin Bulan Ini" di menu Tunjangan Kinerja dulu.</td></tr>
          <?php endif; ?>
          <?php $ad_i = 0; while ($au = mysqli_fetch_assoc($baris_audit)): $ad_i++;
              $inisial = strtoupper(substr($au['nama'], 0, 1) . (strpos($au['nama'], ' ') !== false ? substr(strrchr($au['nama'], ' '), 1, 1) : ''));
              $sebab_map = [
                  'terlambat'    => 'Keterlambatan cek-in ' . $au['sumber_absen'],
                  'alpa'         => 'Tidak hadir tanpa keterangan (alpa)',
                  'luar_radius'  => 'Cek-in di luar radius geofence',
                  'lokasi_palsu' => 'Indikasi lokasi palsu (mock location) terverifikasi',
              ];
              $jenis = null;
              foreach (['terlambat','alpa','luar_radius','lokasi_palsu'] as $j) {
                  if (stripos($au['nama_aturan'], $j) !== false || $au['kode_aturan'] === ['terlambat'=>'R-02','alpa'=>'R-01','luar_radius'=>'R-07','lokasi_palsu'=>'R-09'][$j]) { $jenis = $j; break; }
              }
              $sebab_teks = $sebab_map[$jenis] ?? $au['nama_aturan'];
          ?>
          <tr class="audit-row" onclick="document.getElementById('ad-<?php echo $ad_i; ?>').classList.toggle('open')">
            <td><div class="emp"><div class="av"><?php echo htmlspecialchars($inisial); ?></div><div><div class="nm"><?php echo htmlspecialchars($au['nama']); ?></div></div></div></td>
            <td class="mono"><?php echo tgl_id($au['tanggal_absensi']); ?></td>
            <td><?php echo htmlspecialchars($sebab_teks); ?></td>
            <td class="mono" style="font-weight:700">Rp <?php echo number_format((float) $au['nominal_potongan'], 0, ',', '.'); ?></td>
            <td class="chev-ic">▾ lihat rincian</td>
          </tr>
          <tr class="audit-detail" id="ad-<?php echo $ad_i; ?>">
            <td colspan="5">
              <div class="audit-trace">
                <div class="lk"><b>id_absensi #<?php echo (int) $au['tukin_detail_id']; ?></b> — Cek-in <?php echo tgl_id($au['tanggal_absensi']); ?><?php echo $au['jam_masuk'] ? ', ' . substr($au['jam_masuk'], 0, 5) . ' WIB' : ''; ?> · sumber_absen: <?php echo htmlspecialchars($au['sumber_absen']); ?><?php echo ($au['latitude'] !== null) ? ' · ' . $au['latitude'] . ', ' . $au['longitude'] : ''; ?></div>
                <div class="lk"><b>id_aturan #<?php echo htmlspecialchars($au['kode_aturan']); ?></b> — <?php echo htmlspecialchars($au['nama_aturan']); ?>: Rp <?php echo number_format((float) $au['nominal_potongan'], 0, ',', '.'); ?></div>
                <?php if ($au['status_verifikasi'] === 'ditolak'): ?>
                <div class="lk"><b>Verifikasi</b> — ditolak (dianggap tidak sah) oleh Kasubbag TU<?php echo $au['waktu_verifikasi'] ? ' pada ' . tgl_id($au['waktu_verifikasi']) : ''; ?><?php echo $au['catatan_verifikasi'] ? ' — "' . htmlspecialchars($au['catatan_verifikasi']) . '"' : ''; ?></div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <footer class="note">Setiap baris potongan tertaut ke baris absensi asli (tanggal, jam, sumber absen, status verifikasi) dan aturan yang diterapkan — admin dapat menunjukkan bukti ini langsung kepada pegawai tanpa menelusuri data secara manual.</footer>
    </section>

    <!-- GEOFENCE -->
    <section class="view" id="v-geofence">
      <div class="topbar">
        <div>
          <h1>Manajemen Titik Lokasi Tugas</h1>
          <p>Admin dapat mendaftarkan koordinat dinas luar tanpa instalasi alat fisik</p>
        </div>
        <button class="btn primary" onclick="openModal('modalTitik')">+ Tambah Titik Lokasi</button>
      </div>

      <?php if ($pesan_titik): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_titik[0] === 'ok' ? 'green' : 'red'; ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_titik[1]); ?></b> — <?php echo htmlspecialchars($pesan_titik[2]); ?>
      </div>
      <?php endif; ?>

      <div class="geo-cards" id="geoCardsList">
        <?php mysqli_data_seek($baris_titik, 0); while ($tl = mysqli_fetch_assoc($baris_titik)):
            $badge_status = ['aktif' => 'ok', 'musiman' => 'warn', 'nonaktif' => 'bad'][$tl['status']];
            $label_status = ucfirst($tl['status']);
        ?>
        <div class="geo-card">
          <div class="th"><div><div class="t"><?php echo htmlspecialchars($tl['nama_lokasi']); ?></div><div class="addr"><?php echo htmlspecialchars($tl['alamat'] ?? '—'); ?></div></div><span class="badge <?php echo $badge_status; ?>"><?php echo $label_status; ?></span></div>
          <div class="geo-meta">
            <div><div class="k">Radius</div><div class="v"><?php echo (int) $tl['radius_meter']; ?> m</div></div>
            <div><div class="k">Koordinat</div><div class="v"><?php echo $tl['latitude']; ?>, <?php echo $tl['longitude']; ?></div></div>
            <div><div class="k">Pegawai Terdaftar</div><div class="v"><?php echo (int) $tl['jml_pegawai']; ?></div></div>
          </div>
          <div style="margin-top:10px;text-align:right"><a href="admin.php?view=geofence&edit_titik=<?php echo (int) $tl['id']; ?>" class="btn" style="font-size:11.5px;padding:5px 10px;display:inline-block;text-decoration:none">✎ Edit</a></div>
        </div>
        <?php endwhile; ?>
        <?php if (mysqli_num_rows($baris_titik) === 0): ?>
        <p style="color:var(--ink-400)">Belum ada titik lokasi tugas terdaftar.</p>
        <?php endif; ?>
      </div>

      <div class="panel" style="margin-top:20px">
        <div class="panel-head">
          <h3>Jadwal Dinas Luar (akan datang)</h3>
          <div class="actions"><button class="btn primary" onclick="openModal('modalJadwal')">+ Tambah Jadwal</button></div>
        </div>
        <?php if ($pesan_jadwal): ?>
        <div style="border-left:4px solid var(--<?php echo $pesan_jadwal[0] === 'ok' ? 'green' : 'red'; ?>-500);padding:10px 14px;margin-bottom:12px;background:var(--bg);border-radius:8px">
          <b><?php echo htmlspecialchars($pesan_jadwal[1]); ?></b><?php echo $pesan_jadwal[2] ? ' — ' . htmlspecialchars($pesan_jadwal[2]) : ''; ?>
        </div>
        <?php endif; ?>
        <table>
          <tr><th>Pegawai</th><th>Titik Lokasi</th><th>Tanggal</th><th>Jam</th><th>Keterangan</th><th></th></tr>
          <tbody>
          <?php if (mysqli_num_rows($baris_jadwal) === 0): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--ink-400)">Belum ada jadwal dinas luar akan datang.</td></tr>
          <?php endif; ?>
          <?php while ($jd = mysqli_fetch_assoc($baris_jadwal)): ?>
          <tr>
            <td><?php echo htmlspecialchars($jd['nama_pegawai']); ?></td>
            <td><?php echo htmlspecialchars($jd['nama_lokasi']); ?></td>
            <td class="mono"><?php echo tgl_id($jd['tanggal_mulai'], false) . ' – ' . tgl_id($jd['tanggal_selesai']); ?></td>
            <td class="mono"><?php echo $jd['jam_mulai'] ? substr($jd['jam_mulai'],0,5).'–'.substr($jd['jam_selesai'],0,5) : '—'; ?></td>
            <td><?php echo htmlspecialchars($jd['keterangan'] ?? '—'); ?></td>
            <td>
              <form method="POST" action="proses_jadwal_dinas_luar.php" onsubmit="return confirm('Hapus jadwal ini?');" style="display:inline">
              <?php echo csrf_field(); ?>
                <input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="jadwal_id" value="<?php echo (int) $jd['id']; ?>">
                <button type="submit" class="btn" style="color:var(--red-500);padding:4px 8px;font-size:11px">Hapus</button>
              </form>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- KALENDER LIBUR -->
    <section class="view" id="v-kalender">
      <div class="topbar">
        <div>
          <h1>Kalender Libur</h1>
          <p>Tanggal merah di sini otomatis dikecualikan dari hari kerja efektif (tunjangan) &amp; penandaan alpa</p>
        </div>
        <button class="btn primary" onclick="openModal('modalLibur')">+ Tambah Tanggal Merah</button>
      </div>

      <?php if ($pesan_libur): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_libur[0] === 'ok' ? 'green' : 'red'; ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_libur[1]); ?></b> — <?php echo htmlspecialchars($pesan_libur[2]); ?>
      </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-head">
          <h3>Tanggal Merah Tahun <?php echo $tahun_kalender; ?></h3>
          <div class="actions">
            <form method="GET" action="admin.php" style="display:inline-block">
              <input type="hidden" name="view" value="kalender">
              <select class="btn" name="tahun_kalender" onchange="this.form.submit()">
                <?php for ($th = (int) date('Y') - 1; $th <= (int) date('Y') + 1; $th++): ?>
                <option value="<?php echo $th; ?>" <?php echo $th === $tahun_kalender ? 'selected' : ''; ?>><?php echo $th; ?></option>
                <?php endfor; ?>
              </select>
            </form>
          </div>
        </div>
        <table>
          <tr><th>Tanggal</th><th>Hari</th><th>Keterangan</th><th>Jenis</th><th></th></tr>
          <tbody>
          <?php if (mysqli_num_rows($baris_hari_libur) === 0): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--ink-400)">Belum ada tanggal merah terdaftar untuk tahun ini.</td></tr>
          <?php endif; ?>
          <?php
          $nama_hari_arr = ['', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
          while ($hl = mysqli_fetch_assoc($baris_hari_libur)):
              $hari_ke = (int) date('N', strtotime($hl['tanggal']));
          ?>
          <tr>
            <td class="mono"><?php echo tgl_id($hl['tanggal']); ?></td>
            <td><?php echo $nama_hari_arr[$hari_ke]; ?></td>
            <td><?php echo htmlspecialchars($hl['keterangan']); ?></td>
            <td><span class="badge <?php echo $hl['jenis'] === 'nasional' ? 'ok' : 'warn'; ?>"><?php echo $hl['jenis'] === 'nasional' ? 'Nasional' : 'Cuti Bersama'; ?></span></td>
            <td>
              <form method="POST" action="proses_kelola_hari_libur.php" onsubmit="return confirm('Hapus tanggal merah ini?');" style="display:inline">
              <?php echo csrf_field(); ?>
                <input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($hl['tanggal']); ?>">
                <button type="submit" class="btn" style="color:var(--red-500);padding:4px 8px;font-size:11px">Hapus</button>
              </form>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <footer class="note">Sampel tanggal merah 2026 yang sudah ada masih perlu diverifikasi lengkap ke SKB 3 Menteri resmi (terutama cuti bersama) sebelum dipakai untuk perhitungan tukin sungguhan.</footer>
    </section>

    <!-- TUKIN -->
    <section class="view" id="v-tukin">
      <div class="topbar">
        <div>
          <h1>Perhitungan Tunjangan Kinerja</h1>
          <p>Rule engine membaca gabungan data fingerprint &amp; geolocation secara otomatis</p>
        </div>
        <div class="date-pill">Periode: <?php echo htmlspecialchars($nama_bulan[$bulan_filter] . ' ' . $tahun_filter); ?></div>
      </div>

      <?php if ($pesan_hitung): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_hitung[0] === 'ok' ? 'green' : ($pesan_hitung[0] === 'warn' ? 'amber' : 'red'); ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_hitung[1]); ?></b> — <?php echo htmlspecialchars($pesan_hitung[2]); ?>
      </div>
      <?php endif; ?>
      <?php if ($pesan_finalisasi): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_finalisasi[0] === 'ok' ? 'green' : 'amber'; ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_finalisasi[1]); ?></b> — <?php echo htmlspecialchars($pesan_finalisasi[2]); ?>
      </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-head">
          <h3>Rekap Potongan Tunjangan per Pegawai <?php echo $periode_sudah_final ? '<span class="badge ok" style="margin-left:8px">Final</span>' : (mysqli_num_rows($baris_tukin) > 0 ? '<span class="badge warn" style="margin-left:8px">Draft</span>' : ''); ?></h3>
          <div class="actions">
            <button class="btn" onclick="openModal('modalAturan')">Atur Aturan Potongan</button>
            <form method="POST" action="proses_hitung_tukin.php" style="display:inline-block" onsubmit="return confirm('Hitung ulang tukin periode <?php echo $nama_bulan[$bulan_filter]; ?> <?php echo $tahun_filter; ?> untuk semua pegawai aktif? Rincian potongan lama pada periode ini akan ditimpa.');">
            <?php echo csrf_field(); ?>
              <input type="hidden" name="bulan" value="<?php echo $bulan_filter; ?>">
              <input type="hidden" name="tahun" value="<?php echo $tahun_filter; ?>">
              <button type="submit" class="btn primary" <?php echo $periode_sudah_final ? 'disabled title="Buka kunci dulu untuk menghitung ulang"' : ''; ?>>⟳ Hitung Tukin Bulan Ini</button>
            </form>
            <?php if (mysqli_num_rows($baris_tukin) > 0): ?>
            <form method="POST" action="proses_finalisasi_tukin.php" style="display:inline-block" onsubmit="return confirm('<?php echo $periode_sudah_final ? 'Buka kunci periode ini supaya bisa dihitung ulang?' : 'Kunci (finalisasi) tukin periode ini? Setelah final, tidak bisa dihitung ulang tanpa dibuka kembali.'; ?>');">
            <?php echo csrf_field(); ?>
              <input type="hidden" name="bulan" value="<?php echo $bulan_filter; ?>">
              <input type="hidden" name="tahun" value="<?php echo $tahun_filter; ?>">
              <input type="hidden" name="aksi" value="<?php echo $periode_sudah_final ? 'buka' : 'kunci'; ?>">
              <button type="submit" class="btn"><?php echo $periode_sudah_final ? '🔓 Buka Kunci Periode' : '🔒 Finalisasi Periode'; ?></button>
            </form>
            <?php endif; ?>
            <a href="cetak_spj_tukin.php?bulan=<?php echo $bulan_filter; ?>&tahun=<?php echo $tahun_filter; ?>" target="_blank" class="btn primary" style="text-decoration:none">🖨️ Cetak / Ekspor SPJ (PDF)</a>
          </div>
        </div>
        <?php if (mysqli_num_rows($baris_tukin) > 0): mysqli_data_seek($baris_tukin, 0); $tk_contoh = mysqli_fetch_assoc($baris_tukin); mysqli_data_seek($baris_tukin, 0); ?>
        <p style="color:var(--ink-400);font-size:12.5px;margin:-4px 0 4px">Basis: Rp<?php echo number_format(TARIF_HARIAN_TUNJANGAN, 0, ',', '.'); ?>/hari × <?php echo (int) $tk_contoh['hari_kerja_efektif']; ?> hari kerja efektif bulan ini (Senin-Jumat, dikurangi tanggal merah) = Rp<?php echo number_format((float) $tk_contoh['tukin_pokok'], 0, ',', '.'); ?> — sama untuk semua pegawai aktif sebelum dipotong pelanggaran.</p>
        <?php endif; ?>
        <?php if ($log_tukin_terakhir): ?>
        <p style="color:var(--ink-400);font-size:12.5px;margin:0 0 12px">
          Terakhir dihitung oleh <b><?php echo htmlspecialchars($log_tukin_terakhir['nama_penghitung']); ?></b>
          pada <?php echo tgl_id(date('Y-m-d', strtotime($log_tukin_terakhir['waktu_hitung']))); ?>, <?php echo date('H:i', strtotime($log_tukin_terakhir['waktu_hitung'])); ?> WIB
          (<?php echo (int) $log_tukin_terakhir['jumlah_pegawai_diproses']; ?> pegawai diproses)
          <?php if (mysqli_num_rows($baris_log_tukin) > 1): ?>
          <span class="mono">· dihitung ulang <?php echo mysqli_num_rows($baris_log_tukin); ?>× untuk periode ini</span>
          <?php endif; ?>
        </p>
        <?php endif; ?>
        <table>
          <tr><th>Pegawai</th><th>Tunjangan Pokok</th><th>Telat/Alpa</th><th>Sumber Absen</th><th>Potongan</th><th>Tunjangan Diterima</th></tr>
          <tbody>
          <?php if (mysqli_num_rows($baris_tukin) === 0): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--ink-400)">Belum ada rekap untuk periode ini — klik "Hitung Tukin Bulan Ini" untuk memprosesnya dari data absensi.</td></tr>
          <?php endif; ?>
          <?php while ($tk = mysqli_fetch_assoc($baris_tukin)):
              $inisial = strtoupper(substr($tk['nama'], 0, 1) . (strpos($tk['nama'], ' ') !== false ? substr(strrchr($tk['nama'], ' '), 1, 1) : ''));
              $ta = $telat_alpa_map[(int) $tk['pegawai_id']] ?? ['telat' => 0, 'alpa' => 0];
              $lane = $tk['metode_absen'] === 'geolocation'
                  ? '<span class="lane-tag l" style="background:var(--teal-100);color:var(--teal-500)">Geolocation</span>'
                  : '<span class="lane-tag k" style="background:var(--blue-100);color:var(--blue-500)">Fingerprint</span>';
          ?>
          <tr>
            <td><div class="emp"><div class="av"><?php echo htmlspecialchars($inisial); ?></div><div><div class="nm"><?php echo htmlspecialchars($tk['nama']); ?></div><div class="role"><?php echo htmlspecialchars($tk['jabatan'] ?? ''); ?></div></div></div></td>
            <td class="mono"><?php echo number_format((float) $tk['tukin_pokok'], 0, ',', '.'); ?></td>
            <td class="mono"><?php echo (int) $ta['telat']; ?>x / <?php echo (int) $ta['alpa']; ?>x</td>
            <td><?php echo $lane; ?></td>
            <td class="mono"><?php echo number_format((float) $tk['total_potongan'], 0, ',', '.'); ?></td>
            <td class="mono" style="font-weight:700"><?php echo number_format((float) $tk['tukin_diterima'], 0, ',', '.'); ?></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <footer class="note">Potongan luar radius/lokasi palsu/keterlambatan dengan alasan baru masuk hitungan setelah baris terkait diputuskan <b>Tolak</b> di menu Monitoring Kecurigaan — baris yang masih menunggu atau sudah disetujui tidak dipotong.</footer>
    </section>

    <!-- PEGAWAI -->
    <section class="view" id="v-pegawai">
      <div class="topbar">
        <div>
          <h1>Data Pegawai</h1>
          <p>Profil, jabatan, dan metode absensi yang berlaku per pegawai</p>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn" onclick="openModal('modalSeksi')">🗂️ Kelola Seksi</button>
          <button class="btn primary" onclick="openModal('modalPegawai')">+ Tambah Pegawai</button>
        </div>
      </div>

      <?php if ($pesan_pegawai): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_pegawai[0] === 'ok' ? 'green' : 'red'; ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_pegawai[1]); ?></b> — <?php echo htmlspecialchars($pesan_pegawai[2]); ?>
      </div>
      <?php endif; ?>
      <?php if ($pesan_seksi): ?>
      <div class="panel" style="border-left:4px solid var(--<?php echo $pesan_seksi[0] === 'ok' ? 'green' : 'red'; ?>-500);margin-bottom:16px;padding:12px 16px;">
        <b><?php echo htmlspecialchars($pesan_seksi[1]); ?></b> — <?php echo htmlspecialchars($pesan_seksi[2]); ?>
      </div>
      <?php endif; ?>

      <div class="panel">
        <table>
          <tr><th>Pegawai</th><th>NIP</th><th>Jabatan</th><th>Seksi</th><th>Metode Absen</th><th>Status</th><th>Akun</th><th></th></tr>
          <tbody id="tblPegawai">
          <?php while ($pgw = mysqli_fetch_assoc($baris_pegawai)):
              $inisial = strtoupper(substr($pgw['nama'], 0, 1) . (strpos($pgw['nama'], ' ') !== false ? substr(strrchr($pgw['nama'], ' '), 1, 1) : ''));
              $lane = $pgw['metode_absen'] === 'geolocation'
                  ? '<span class="lane-tag l" style="background:var(--teal-100);color:var(--teal-500)">Geolocation</span>'
                  : '<span class="lane-tag k" style="background:var(--blue-100);color:var(--blue-500)">Fingerprint</span>';
              $badge_status = ['aktif' => 'ok', 'cuti' => 'warn', 'nonaktif' => 'bad'][$pgw['status_kepegawaian']];
          ?>
          <tr>
            <td><div class="emp"><div class="av"><?php echo htmlspecialchars($inisial); ?></div><div><div class="nm"><?php echo htmlspecialchars($pgw['nama']); ?></div></div></div></td>
            <td class="mono"><?php echo htmlspecialchars($pgw['nip']); ?></td>
            <td><?php echo htmlspecialchars($pgw['jabatan'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars($pgw['nama_seksi'] ?? '—'); ?></td>
            <td><?php echo $lane; ?></td>
            <td><span class="badge <?php echo $badge_status; ?>"><?php echo ucfirst($pgw['status_kepegawaian']); ?></span></td>
            <td class="mono" style="font-size:11px"><?php echo htmlspecialchars($pgw['username'] ?? '—'); ?><br><span style="color:var(--ink-400)"><?php echo htmlspecialchars($pgw['role'] ?? ''); ?></span></td>
            <td><a href="admin.php?view=pegawai&edit_pegawai=<?php echo (int) $pgw['id']; ?>" class="btn" style="font-size:11px;padding:4px 8px;text-decoration:none">✎ Edit</a></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</div>

<!-- TOAST -->
<div id="toast" style="position:fixed;bottom:26px;right:26px;background:var(--navy-900);color:#fff;padding:14px 18px;border-radius:10px;box-shadow:0 12px 28px rgba(15,36,56,.3);z-index:200;opacity:0;transform:translateY(10px);transition:.25s;pointer-events:none;max-width:320px;">
  <div style="font-size:13px;font-weight:700;" id="toastTitle">Berhasil</div>
  <div style="font-size:11.5px;color:#B9C7D4;margin-top:3px;" id="toastMsg">Pesan</div>
</div>

<!-- MODAL: TAMBAH / EDIT TITIK LOKASI -->
<div class="modal-overlay" id="modalTitik">
  <div class="modal-box">
    <div class="modal-head">
      <div><h3><?php echo $data_edit_titik ? 'Ubah Titik Lokasi Tugas' : 'Tambah Titik Lokasi Tugas'; ?></h3><p>Daftarkan koordinat dinas luar baru untuk geofencing</p></div>
      <button class="modal-x" onclick="closeModal('modalTitik')">✕</button>
    </div>
    <form method="POST" action="proses_titik_lokasi.php">
    <?php echo csrf_field(); ?>
      <input type="hidden" name="aksi" value="<?php echo $data_edit_titik ? 'edit' : 'tambah'; ?>">
      <?php if ($data_edit_titik): ?><input type="hidden" name="titik_id" value="<?php echo (int) $data_edit_titik['id']; ?>"><?php endif; ?>
      <div class="mfield"><label>Nama Lokasi</label><input type="text" name="nama_lokasi" placeholder="mis. Rampcheck Sukadana" value="<?php echo htmlspecialchars($data_edit_titik['nama_lokasi'] ?? ''); ?>" required></div>
      <div class="mfield"><label>Alamat</label><input type="text" name="alamat" placeholder="mis. Lampung Timur" value="<?php echo htmlspecialchars($data_edit_titik['alamat'] ?? ''); ?>"></div>
      <div class="mrow">
        <div class="mfield"><label>Latitude</label><input type="text" name="latitude" placeholder="-5.4291" value="<?php echo htmlspecialchars($data_edit_titik['latitude'] ?? ''); ?>" required></div>
        <div class="mfield"><label>Longitude</label><input type="text" name="longitude" placeholder="105.2612" value="<?php echo htmlspecialchars($data_edit_titik['longitude'] ?? ''); ?>" required></div>
      </div>
      <div class="mrow">
        <div class="mfield"><label>Radius (meter)</label><input type="number" name="radius_meter" placeholder="150" value="<?php echo htmlspecialchars($data_edit_titik['radius_meter'] ?? '150'); ?>" required></div>
        <div class="mfield"><label>Status</label>
          <select name="status">
            <?php foreach (['aktif' => 'Aktif', 'musiman' => 'Musiman', 'nonaktif' => 'Nonaktif'] as $val => $lbl): ?>
            <option value="<?php echo $val; ?>" <?php echo (($data_edit_titik['status'] ?? 'aktif') === $val) ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modalTitik')">Batal</button>
        <button type="submit" class="btn primary">Simpan Titik Lokasi</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: TAMBAH JADWAL DINAS LUAR -->
<div class="modal-overlay" id="modalJadwal">
  <div class="modal-box">
    <div class="modal-head">
      <div><h3>Tambah Jadwal Dinas Luar</h3><p>Cek-in pegawai di titik &amp; tanggal ini akan otomatis dianggap sesuai jadwal</p></div>
      <button class="modal-x" onclick="closeModal('modalJadwal')">✕</button>
    </div>
    <form method="POST" action="proses_jadwal_dinas_luar.php">
    <?php echo csrf_field(); ?>
      <input type="hidden" name="aksi" value="tambah">
      <div class="mfield"><label>Pegawai</label>
        <select name="pegawai_id" required>
          <option value="">— Pilih pegawai —</option>
          <?php foreach ($daftar_pegawai_arr as $pg): ?>
          <option value="<?php echo (int) $pg['id']; ?>"><?php echo htmlspecialchars($pg['nama']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mfield"><label>Titik Lokasi</label>
        <select name="titik_lokasi_id" required>
          <option value="">— Pilih titik lokasi —</option>
          <?php foreach ($daftar_titik_arr as $tk): ?>
          <option value="<?php echo (int) $tk['id']; ?>"><?php echo htmlspecialchars($tk['nama_lokasi']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mrow">
        <div class="mfield"><label>Tanggal Mulai</label><input type="date" name="tanggal_mulai" required></div>
        <div class="mfield"><label>Tanggal Selesai</label><input type="date" name="tanggal_selesai" required></div>
      </div>
      <div class="mrow">
        <div class="mfield"><label>Jam Mulai (opsional)</label><input type="time" name="jam_mulai"></div>
        <div class="mfield"><label>Jam Selesai (opsional)</label><input type="time" name="jam_selesai"></div>
      </div>
      <div class="mfield"><label>Keterangan</label><input type="text" name="keterangan" placeholder="mis. Rampcheck lebaran"></div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modalJadwal')">Batal</button>
        <button type="submit" class="btn primary">Simpan Jadwal</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: TAMBAH TANGGAL MERAH -->
<div class="modal-overlay" id="modalLibur">
  <div class="modal-box">
    <div class="modal-head">
      <div><h3>Tambah Tanggal Merah</h3><p>Otomatis dikecualikan dari hari kerja efektif &amp; penandaan alpa</p></div>
      <button class="modal-x" onclick="closeModal('modalLibur')">✕</button>
    </div>
    <form method="POST" action="proses_kelola_hari_libur.php">
    <?php echo csrf_field(); ?>
      <input type="hidden" name="aksi" value="tambah">
      <div class="mfield"><label>Tanggal</label><input type="date" name="tanggal" required></div>
      <div class="mfield"><label>Keterangan</label><input type="text" name="keterangan" placeholder="mis. Idulfitri 1447 H" required></div>
      <div class="mfield"><label>Jenis</label>
        <select name="jenis">
          <option value="nasional">Hari Libur Nasional</option>
          <option value="cuti_bersama">Cuti Bersama</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modalLibur')">Batal</button>
        <button type="submit" class="btn primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: INPUT MANUAL FINGERPRINT -->
<div class="modal-overlay" id="modalManualFP">
  <div class="modal-box">
    <div class="modal-head">
      <div><h3>Input Kehadiran Manual</h3><p>Mesin fingerprint kantor tidak punya software export — cocokkan dari SD card &amp; catatan kertas, lalu ketik hasilnya di sini</p></div>
      <button class="modal-x" onclick="closeModal('modalManualFP')">✕</button>
    </div>
    <form method="POST" action="proses_input_manual_fingerprint.php">
    <?php echo csrf_field(); ?>
      <div class="mfield"><label>Pegawai</label>
        <select name="pegawai_id" required>
          <option value="">— Pilih pegawai —</option>
          <?php foreach ($daftar_pegawai_arr as $pg): ?>
          <option value="<?php echo (int) $pg['id']; ?>"><?php echo htmlspecialchars($pg['nama']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mfield"><label>Tanggal</label><input type="date" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required></div>
      <div class="mrow">
        <div class="mfield"><label>Jam Masuk</label><input type="time" name="jam_masuk" required></div>
        <div class="mfield"><label>Jam Pulang (opsional)</label><input type="time" name="jam_pulang"></div>
      </div>
      <label style="display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--ink-600);margin-top:4px">
        <input type="checkbox" name="konfirmasi_timpa" value="1" style="margin-top:2px">
        <span>Saya yakin ingin menimpa — hanya dicentang kalau sistem sebelumnya menolak karena pegawai ini sudah punya cek-in GPS di tanggal yang sama</span>
      </label>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modalManualFP')">Batal</button>
        <button type="submit" class="btn primary">Simpan Kehadiran</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: ATURAN POTONGAN -->
<div class="modal-overlay" id="modalAturan">
  <div class="modal-box">
    <div class="modal-head">
      <div><h3>Aturan Potongan Tunjangan Kinerja</h3><p>Rule engine — dibaca otomatis untuk absensi fingerprint &amp; geolocation</p></div>
      <button class="modal-x" onclick="closeModal('modalAturan')">✕</button>
    </div>
    <?php if ($pesan_aturan): ?>
    <div style="border-left:4px solid var(--<?php echo $pesan_aturan[0] === 'ok' ? 'green' : 'red'; ?>-500);padding:10px 14px;margin-bottom:12px;background:var(--bg);border-radius:8px">
      <b><?php echo htmlspecialchars($pesan_aturan[1]); ?></b> — <?php echo htmlspecialchars($pesan_aturan[2]); ?>
    </div>
    <?php endif; ?>
    <form method="POST" action="proses_atur_potongan.php">
    <?php echo csrf_field(); ?>
      <div id="listAturan">
        <?php mysqli_data_seek($daftar_aturan, 0); while ($at = mysqli_fetch_assoc($daftar_aturan)): ?>
        <div class="rule-row">
          <div><div style="font-weight:600"><?php echo htmlspecialchars($at['nama_aturan']); ?></div><div class="kd"><?php echo htmlspecialchars($at['kode_aturan']); ?> · <?php echo htmlspecialchars($at['jenis']); ?></div></div>
          <div>
            <input type="hidden" name="aturan_id[]" value="<?php echo (int) $at['id']; ?>">
            Rp <input type="number" name="nominal[]" value="<?php echo (int) $at['nominal_potongan']; ?>" style="width:100px;padding:6px 8px;border:1.5px solid var(--line);border-radius:6px;font-family:var(--font-mono);text-align:right">
          </div>
        </div>
        <?php endwhile; ?>
      </div>
      <footer class="note" style="margin-top:16px">Perubahan nominal di sini TIDAK otomatis menghitung ulang tukin yang sudah ada — klik "Hitung Tukin Bulan Ini" lagi setelah menyimpan supaya nominal baru diterapkan.</footer>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modalAturan')">Batal</button>
        <button type="submit" class="btn primary">Simpan Semua</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: TAMBAH / EDIT PEGAWAI -->
<div class="modal-overlay" id="modalPegawai">
  <div class="modal-box">
    <div class="modal-head">
      <div><h3><?php echo $data_edit_pegawai ? 'Ubah Data Pegawai' : 'Tambah Pegawai'; ?></h3><p>Data induk kepegawaian <?php echo $data_edit_pegawai ? '& akun login' : 'baru sekaligus akun login'; ?></p></div>
      <button class="modal-x" onclick="closeModal('modalPegawai')">✕</button>
    </div>
    <form method="POST" action="<?php echo $data_edit_pegawai ? 'proses_edit_pegawai.php' : 'proses_tambah_pegawai.php'; ?>">
    <?php echo csrf_field(); ?>
      <?php if ($data_edit_pegawai): ?><input type="hidden" name="pegawai_id" value="<?php echo (int) $data_edit_pegawai['id']; ?>"><?php endif; ?>
      <div class="mrow">
        <div class="mfield"><label>Nama Lengkap</label><input type="text" name="nama" placeholder="mis. Putri Ramadhani" value="<?php echo htmlspecialchars($data_edit_pegawai['nama'] ?? ''); ?>" required></div>
        <div class="mfield"><label>NIP</label><input type="text" name="nip" placeholder="19xxxxxxxxxxxxxxxxxx" value="<?php echo htmlspecialchars($data_edit_pegawai['nip'] ?? ''); ?>" required></div>
      </div>
      <div class="mrow">
        <div class="mfield"><label>Jabatan</label><input type="text" name="jabatan" placeholder="mis. Staf Prasarana" value="<?php echo htmlspecialchars($data_edit_pegawai['jabatan'] ?? ''); ?>"></div>
        <div class="mfield"><label>Kelas Jabatan</label><input type="number" name="kelas_jabatan" placeholder="mis. 7" value="<?php echo htmlspecialchars($data_edit_pegawai['kelas_jabatan'] ?? ''); ?>"></div>
      </div>
      <div class="mrow">
        <div class="mfield"><label>Seksi <a href="javascript:void(0)" onclick="closeModal('modalPegawai');openModal('modalSeksi');" style="font-weight:400;font-size:11px;color:var(--blue-500);text-decoration:none">(Kelola Seksi)</a></label>
          <select name="seksi_id">
            <option value="">— Pilih seksi —</option>
            <?php foreach ($daftar_seksi_arr as $sk): ?>
            <option value="<?php echo (int) $sk['id']; ?>" <?php echo (($data_edit_pegawai['seksi_id'] ?? null) == $sk['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sk['nama_seksi']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mfield"><label>Metode Absen</label>
          <select name="metode_absen" id="metodeAbsenSelect">
            <option value="fingerprint" <?php echo (($data_edit_pegawai['metode_absen'] ?? 'fingerprint') === 'fingerprint') ? 'selected' : ''; ?>>Fingerprint</option>
            <option value="geolocation" <?php echo (($data_edit_pegawai['metode_absen'] ?? '') === 'geolocation') ? 'selected' : ''; ?>>Geolocation</option>
          </select>
        </div>
      </div>
      <?php if ($data_edit_pegawai): /* ── [POIN 3] Panel TMT & Riwayat Mutasi — hanya mode Edit ── */ ?>
      <div id="blokMutasiMetode" style="display:none;background:var(--amber-50,#fffbeb);border:1px solid var(--amber-200,#fde68a);border-radius:10px;padding:12px 14px;margin-bottom:10px;">
        <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:8px;">⚠️ Metode berubah — isi TMT berlaku</div>
        <div class="mrow">
          <div class="mfield">
            <label>TMT Berlaku Metode Baru <span style="color:var(--red-500)">*</span></label>
            <input type="date" name="tmt_mutasi" id="inpTmtMutasi" value="<?php echo date('Y-m-d'); ?>"
                   min="<?php echo date('Y-m-01'); ?>" style="border-color:var(--amber-500)">
            <small style="color:var(--ink-600);font-size:10.5px">Tanggal pertama metode baru berlaku (inklusif). Hari sebelumnya masih dihitung dengan metode lama.</small>
          </div>
          <div class="mfield">
            <label>Keterangan Mutasi (opsional)</label>
            <input type="text" name="keterangan_mutasi" placeholder="mis. Mutasi ke lapangan Bakauheni" maxlength="200">
          </div>
        </div>
      </div>
      <?php if (!empty($riwayat_mutasi_pegawai)): ?>
      <details style="margin-bottom:12px;" <?php echo count($riwayat_mutasi_pegawai) === 1 ? '' : 'open'; ?>>
        <summary style="cursor:pointer;font-size:12px;font-weight:700;color:var(--ink-600);padding:6px 0;">
          📋 Riwayat Mutasi Metode (<?php echo count($riwayat_mutasi_pegawai); ?> entri)
        </summary>
        <div style="margin-top:8px;border:1px solid var(--line);border-radius:8px;overflow:hidden;">
          <table style="width:100%;font-size:11.5px;border-collapse:collapse;">
            <thead><tr style="background:var(--bg);">
              <th style="padding:6px 10px;text-align:left;font-weight:600;border-bottom:1px solid var(--line);">TMT Berlaku</th>
              <th style="padding:6px 10px;text-align:left;font-weight:600;border-bottom:1px solid var(--line);">Dari</th>
              <th style="padding:6px 10px;text-align:left;font-weight:600;border-bottom:1px solid var(--line);">Menjadi</th>
              <th style="padding:6px 10px;text-align:left;font-weight:600;border-bottom:1px solid var(--line);">Keterangan</th>
              <th style="padding:6px 10px;text-align:left;font-weight:600;border-bottom:1px solid var(--line);">Oleh</th>
            </tr></thead>
            <tbody>
            <?php foreach ($riwayat_mutasi_pegawai as $rm): ?>
            <tr style="border-bottom:1px solid var(--line);">
              <td style="padding:6px 10px;font-family:var(--font-mono)"><?php echo tgl_id($rm['tmt_berlaku']); ?></td>
              <td style="padding:6px 10px;">
                <?php if ($rm['metode_sebelum'] === null): ?>
                  <span style="color:var(--ink-400);font-style:italic">Entri Awal</span>
                <?php else: ?>
                  <span style="background:var(--blue-100);color:var(--blue-500);padding:2px 7px;border-radius:5px;font-size:10.5px;font-weight:700"><?php echo ucfirst($rm['metode_sebelum']); ?></span>
                <?php endif; ?>
              </td>
              <td style="padding:6px 10px;">
                <span style="background:var(--teal-100);color:var(--teal-500);padding:2px 7px;border-radius:5px;font-size:10.5px;font-weight:700"><?php echo ucfirst($rm['metode_sesudah']); ?></span>
              </td>
              <td style="padding:6px 10px;color:var(--ink-600)"><?php echo htmlspecialchars($rm['keterangan'] ?? '—'); ?></td>
              <td style="padding:6px 10px;font-family:var(--font-mono);font-size:10.5px;color:var(--ink-400)"><?php echo htmlspecialchars($rm['dicatat_oleh_username'] ?? '—'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
      <?php endif; /* end riwayat_mutasi_pegawai */ ?>
      <?php endif; /* end mode edit */ ?>

      <div class="mrow">
        <div class="mfield"><label>Titik Lokasi Utama (khusus geolocation)</label>
          <select name="titik_lokasi_id">
            <option value="">—</option>
            <?php foreach ($daftar_titik_arr as $tk): ?>
            <option value="<?php echo (int) $tk['id']; ?>" <?php echo (($data_edit_pegawai['titik_lokasi_id'] ?? null) == $tk['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tk['nama_lokasi']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mfield"><label>Status Kepegawaian</label>
          <select name="status_kepegawaian">
            <?php foreach (['aktif' => 'Aktif', 'cuti' => 'Cuti', 'nonaktif' => 'Nonaktif'] as $val => $lbl): ?>
            <option value="<?php echo $val; ?>" <?php echo (($data_edit_pegawai['status_kepegawaian'] ?? 'aktif') === $val) ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mrow">
        <div class="mfield"><label>Nomor HP</label><input type="text" name="nomor_hp" placeholder="08xxxxxxxxxx" value="<?php echo htmlspecialchars($data_edit_pegawai['nomor_hp'] ?? ''); ?>"></div>
        <div class="mfield"><label>Email</label><input type="email" name="email" placeholder="nama@bptd.go.id" value="<?php echo htmlspecialchars($data_edit_pegawai['email'] ?? ''); ?>"></div>
      </div>
      <div class="mfield"><label>TMT Jabatan</label><input type="date" name="tmt_jabatan" value="<?php echo htmlspecialchars($data_edit_pegawai['tmt_jabatan'] ?? ''); ?>"></div>
      <hr style="border:none;border-top:1px solid var(--line);margin:14px 0">
      <div id="blokKredensial">
      <div class="mrow">
        <div class="mfield"><label>Username</label><input type="text" name="username" placeholder="mis. putri.ramadhani" value="<?php echo htmlspecialchars($data_edit_pegawai['username'] ?? ''); ?>"></div>
        <div class="mfield"><label>Password <?php echo $data_edit_pegawai ? '(kosongkan jika tidak diubah)' : ''; ?></label><input type="password" name="password" placeholder="min. 6 karakter"></div>
      </div>
      </div>
      <p id="hintAktivasiMandiri" class="mfield" style="display:none;font-size:12px;color:var(--ink-600);background:var(--blue-100);padding:10px 12px;border-radius:8px;">ℹ️ Pegawai akan mendapat kode aktivasi untuk membuat username & password sendiri — tidak perlu diisi di sini.</p>
      <div class="mfield"><label>Peran</label>
        <select name="role" id="rolePegawaiSelect">
          <option value="pegawai" <?php echo (($data_edit_pegawai['role'] ?? 'pegawai') === 'pegawai') ? 'selected' : ''; ?>>Pegawai</option>
          <option value="kasubbag" <?php echo (($data_edit_pegawai['role'] ?? '') === 'kasubbag') ? 'selected' : ''; ?>>Kasubbag TU</option>
          <option value="admin" <?php echo (($data_edit_pegawai['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modalPegawai')">Batal</button>
        <button type="submit" class="btn primary">Simpan Pegawai</button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  const modeEdit = <?php echo $data_edit_pegawai ? 'true' : 'false'; ?>;
  const roleSel  = document.getElementById('rolePegawaiSelect');
  const blok     = document.getElementById('blokKredensial');
  const hint     = document.getElementById('hintAktivasiMandiri');
  const userInp  = blok.querySelector('input[name="username"]');
  const passInp  = blok.querySelector('input[name="password"]');

  function toggleKredensial(){
    const tampilkan = modeEdit || (roleSel.value !== 'pegawai');
    blok.style.display = tampilkan ? '' : 'none';
    hint.style.display = tampilkan ? 'none' : '';
    const wajib = tampilkan && !modeEdit; // saat edit tetap opsional, sama seperti sebelumnya
    userInp.required = wajib;
    passInp.required = wajib;
  }
  roleSel.addEventListener('change', toggleKredensial);
  toggleKredensial(); // jalankan sekali saat modal dibuka
})();

// ── [POIN 3] Tampilkan panel TMT Mutasi saat metode_absen berubah ──────────
(function(){
  const modeEdit = <?php echo $data_edit_pegawai ? 'true' : 'false'; ?>;
  if (!modeEdit) return; // panel ini hanya relevan saat mode edit

  const metodeSel   = document.getElementById('metodeAbsenSelect');
  const blokMutasi  = document.getElementById('blokMutasiMetode');
  if (!metodeSel || !blokMutasi) return;

  // Nilai asli pegawai sebelum diubah di form (disimpan dari PHP ke JS)
  const metodeAwal  = <?php echo json_encode($data_edit_pegawai['metode_absen'] ?? 'fingerprint'); ?>;

  function toggleMutasi(){
    const berubah = metodeSel.value !== metodeAwal;
    blokMutasi.style.display = berubah ? '' : 'none';
    // Buat tmt_mutasi required hanya kalau blok terlihat
    const inpTmt = document.getElementById('inpTmtMutasi');
    if (inpTmt) inpTmt.required = berubah;
  }
  metodeSel.addEventListener('change', toggleMutasi);
  toggleMutasi(); // jalankan sekali untuk kondisi awal
})();
// ── Akhir [POIN 3] ──────────────────────────────────────────────────────────
</script>


<!-- MODAL: KELOLA SEKSI -->
<div class="modal-overlay" id="modalSeksi">
  <div class="modal-box">
    <div class="modal-head">
      <div><h3>Kelola Seksi</h3><p>Unit kerja yang tersedia di dropdown Data Pegawai</p></div>
      <button class="modal-x" onclick="closeModal('modalSeksi')">✕</button>
    </div>

    <?php if ($pesan_seksi): ?>
    <div style="border-left:4px solid var(--<?php echo $pesan_seksi[0] === 'ok' ? 'green' : 'red'; ?>-500);padding:10px 14px;margin-bottom:14px;background:var(--bg);border-radius:8px">
      <b><?php echo htmlspecialchars($pesan_seksi[1]); ?></b> — <?php echo htmlspecialchars($pesan_seksi[2]); ?>
    </div>
    <?php endif; ?>

    <div id="listSeksi" style="margin-bottom:18px">
      <?php if (empty($daftar_seksi_arr)): ?>
      <div style="font-size:12.5px;color:var(--ink-400);text-align:center;padding:10px 0">Belum ada seksi. Tambahkan lewat form di bawah.</div>
      <?php endif; ?>
      <?php foreach ($daftar_seksi_arr as $sk): ?>
      <div class="rule-row">
        <form method="POST" action="proses_seksi.php" style="flex:1;display:flex;gap:8px;align-items:center;margin-right:8px">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="aksi" value="edit">
          <input type="hidden" name="seksi_id" value="<?php echo (int) $sk['id']; ?>">
          <input type="text" name="nama_seksi" value="<?php echo htmlspecialchars($sk['nama_seksi']); ?>" maxlength="50" style="flex:1;padding:6px 8px;border:1.5px solid var(--line);border-radius:6px;font-size:12.5px" required>
          <button type="submit" class="btn" style="padding:5px 10px;font-size:11.5px">Simpan</button>
        </form>
        <form method="POST" action="proses_seksi.php" onsubmit="return confirm('Hapus seksi &quot;<?php echo htmlspecialchars(addslashes($sk['nama_seksi'])); ?>&quot;? Pegawai di seksi ini akan tampil sebagai Tanpa Seksi.');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="aksi" value="hapus">
          <input type="hidden" name="seksi_id" value="<?php echo (int) $sk['id']; ?>">
          <button type="submit" class="btn" style="padding:5px 10px;font-size:11.5px;color:var(--red-500)">✕ Hapus</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" action="proses_seksi.php">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="aksi" value="tambah">
      <div class="mfield">
        <label>+ Tambah Seksi Baru</label>
        <input type="text" name="nama_seksi" placeholder="mis. Seksi Lalu Lintas Angkutan Sungai" maxlength="50" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modalSeksi')">Tutup</button>
        <button type="submit" class="btn primary">Tambah Seksi</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.nav-item').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.nav-item').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('v-'+btn.dataset.view).classList.add('active');
  });
});

// Buka tab yang sesuai kalau halaman ini hasil redirect dari proses_*.php
// (mis. admin.php?view=kecurigaan&verifikasi=berhasil)
(function(){
  const params = new URLSearchParams(window.location.search);
  const targetView = params.get('view');
  if (targetView) {
    const btn = document.querySelector('.nav-item[data-view="'+targetView+'"]');
    if (btn) btn.click();
  }
  // Buka modal Edit otomatis kalau datang dari link "✎ Edit"
  if (params.get('edit_pegawai')) openModal('modalPegawai');
  if (params.get('edit_titik')) openModal('modalTitik');
  if (params.get('seksi')) openModal('modalSeksi');
})();

/* ===== TOAST ===== */
let toastTimer;
function showToast(title, msg){
  const toast = document.getElementById('toast');
  document.getElementById('toastTitle').textContent = title;
  document.getElementById('toastMsg').textContent = msg;
  toast.style.opacity = 1;
  toast.style.transform = 'translateY(0)';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(()=>{
    toast.style.opacity = 0;
    toast.style.transform = 'translateY(10px)';
  }, 3200);
}

/* ===== MODAL ===== */
function openModal(id){ document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(ov=>{
  ov.addEventListener('click', e=>{ if(e.target === ov) ov.classList.remove('show'); });
});

/* ===== FILTER REKAP KANTOR ===== */
function filterKantor(){
  const val = document.getElementById('filterSeksiKantor').value;
  document.querySelectorAll('#tblKantor tr').forEach(row=>{
    row.style.display = (!val || row.dataset.seksi === val) ? '' : 'none';
  });
}

/* ===== TOGGLE OFFLINE (preview Cek-in Lapangan) ===== */
function toggleAdminOffline(){
  const sw = document.getElementById('adminOfflineSwitch');
  const on = sw.classList.toggle('on');
  document.getElementById('adminOfflineBanner').style.display = on ? 'flex' : 'none';
  const btn = document.getElementById('adminCheckinBtn');
  btn.style.background = on ? 'var(--amber-500)' : 'var(--teal-500)';
  btn.innerHTML = on
    ? 'Simpan Cek-in (Offline)<div class="sub">Doni Prasetyo · Seksi LLASDP</div>'
    : 'Cek-in Sekarang<div class="sub">Doni Prasetyo · Seksi LLASDP</div>';
}

/* Catatan: form Tambah/Edit Titik Lokasi, Tambah/Edit Pegawai, Tambah
   Jadwal Dinas Luar, dan Atur Aturan Potongan sekarang submit langsung
   ke proses_*.php masing-masing (form POST biasa, reload halaman) —
   tidak lagi ditangani lewat JS di sini. */

/* ===== LOGOUT ===== */
function doLogout(){
  if(confirm('Keluar dari akun Kasubbag TU? Kamu perlu login kembali untuk mengakses SIMPEG-H.')){
    window.location.href = 'logout.php?from=admin';
  }
}
</script>
</body>
</html>
