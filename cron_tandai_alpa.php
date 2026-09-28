<?php
/**
 * cron_tandai_alpa.php
 * ---------------------------------------------------------------------
 * Menandai pegawai aktif yang TIDAK PERNAH cek-in sama sekali pada satu
 * tanggal sebagai 'alpa' di tabel `absensi` — tanpa file ini, baris
 * 'alpa' TIDAK PERNAH ada (proses_cekin.php hanya membuat baris kalau
 * pegawai memang menekan tombol cek-in), padahal aturan potongan R-01
 * "alpa" di proses_hitung_tukin.php butuh baris itu untuk bisa memotong
 * tukin. Tanpa cron ini, R-01 sama sekali tidak pernah berlaku — sama
 * seperti bug R-02 "terlambat" yang sudah diperbaiki di proses_cekin.php.
 *
 * Kalau pegawai punya `pengajuan` (izin_sakit/cuti_tahunan/dinas_luar)
 * yang SUDAH DISETUJUI untuk tanggal itu, baris yang dibuat statusnya
 * 'cuti' (bukan 'alpa') — enum status_kehadiran di skema memang sudah
 * menyediakan nilai ini untuk kasus ini.
 *
 * CARA MENJALANKAN (Laragon TIDAK punya cron bawaan seperti Linux):
 * 1. Jalankan dulu migrasi_cron_alpa.sql SEKALI lewat phpMyAdmin.
 * 2. Buat Windows Task Scheduler baru:
 *      Action  : C:\laragon\bin\php\php-8.x\php.exe
 *      Arguments: "C:\laragon\www\simpeg-h\cron_tandai_alpa.php"
 *      Trigger : Setiap hari, jam 23:00 (akhir jam kerja)
 *    (sesuaikan path php.exe dengan versi PHP di Laragon kamu — cek di
 *    Laragon > Menu > PHP > Version, lalu klik kanan > Open folder)
 *
 * UJI COBA MANUAL lewat browser (BUKAN cara produksi, cuma untuk demo
 * ke pembimbing): buka
 *      http://localhost/simpeg-h/cron_tandai_alpa.php?key=577f8aa675ef3fcd065619cce6e071293d552a990a274107&tanggal=2026-07-20
 * Parameter `tanggal` opsional (default hari ini). Parameter `key` WAJIB
 * kalau diakses lewat browser (lihat KUNCI_RAHASIA_CRON di bawah) supaya
 * orang lain tidak bisa iseng memicu file ini berkali-kali lewat URL.
 * Lewat Task Scheduler (CLI), `key` TIDAK diperlukan.
 * ---------------------------------------------------------------------
 */

require 'koneksi.php';
require 'helpers.php';

// =====================================================================
// 0. PROTEKSI AKSES — file ini TIDAK punya login session (dipanggil
//    Task Scheduler tanpa ada yang login), jadi diproteksi dengan cara
//    lain: bebas diakses lewat CLI (php-cli, Task Scheduler), tapi kalau
//    diakses lewat browser/HTTP wajib membawa kunci rahasia yang cocok.
//
//    Kunci di bawah ini di-generate acak (random_bytes) khusus untuk
//    instalasi ini, BUKAN lagi placeholder default — tapi kalau kamu
//    mau ganti sendiri, generate kunci baru dengan:
//        php -r "echo bin2hex(random_bytes(24));"
//    lalu tempel hasilnya di sini DAN di URL demo di atas.
// =====================================================================
const KUNCI_RAHASIA_CRON = '577f8aa675ef3fcd065619cce6e071293d552a990a274107';

$lewat_cli = (php_sapi_name() === 'cli');
if (!$lewat_cli) {
    $key_kiriman = $_GET['key'] ?? '';
    if (!hash_equals(KUNCI_RAHASIA_CRON, $key_kiriman)) {
        http_response_code(403);
        die('Akses ditolak. File ini hanya boleh dijalankan lewat CLI (Task Scheduler) atau dengan ?key= yang benar.');
    }
}

// =====================================================================
// 1. TENTUKAN TANGGAL TARGET
//    CLI  : php cron_tandai_alpa.php 2026-07-20   (argumen ke-1, opsional)
//    HTTP : ?tanggal=2026-07-20                    (opsional, buat testing)
//    Default: hari ini.
// =====================================================================
$tanggal_input = $lewat_cli ? ($argv[1] ?? null) : ($_GET['tanggal'] ?? null);
$tanggal_target = ($tanggal_input && strtotime($tanggal_input) !== false)
    ? date('Y-m-d', strtotime($tanggal_input))
    : date('Y-m-d');

// Lewati akhir pekan MAUPUN tanggal merah (tabel hari_libur) — sebelumnya
// hanya Sabtu/Minggu yang dilewati, sehingga tanggal merah di tengah
// pekan (Senin-Jumat) salah ditandai alpa untuk semua pegawai.
if (!is_hari_kerja($tanggal_target, $koneksi)) {
    $hari_dalam_minggu = (int) date('N', strtotime($tanggal_target));
    $sebab = $hari_dalam_minggu >= 6 ? 'akhir pekan' : 'tanggal merah (lihat tabel hari_libur)';
    $pesan = "Tanggal $tanggal_target adalah $sebab — dilewati, tidak ada yang ditandai alpa.\n";
    if ($lewat_cli) { echo $pesan; } else { echo nl2br(htmlspecialchars($pesan)); }
    exit;
}

// =====================================================================
// 2. CARI PEGAWAI AKTIF YANG BELUM PUNYA BARIS ABSENSI DI TANGGAL INI
// =====================================================================
$stmt = mysqli_prepare($koneksi, "
    SELECT p.id, p.nama
    FROM pegawai p
    WHERE p.status_kepegawaian = 'aktif'
      AND NOT EXISTS (
          SELECT 1 FROM absensi a WHERE a.pegawai_id = p.id AND a.tanggal = ?
      )
");
mysqli_stmt_bind_param($stmt, 's', $tanggal_target);
mysqli_stmt_execute($stmt);
$pegawai_belum_absen = mysqli_stmt_get_result($stmt);

$jumlah_alpa = 0;
$jumlah_cuti = 0;

try {
    mysqli_begin_transaction($koneksi);

    while ($peg = mysqli_fetch_assoc($pegawai_belum_absen)) {
        $pegawai_id = (int) $peg['id'];

        // 3. Cek apakah ada pengajuan (izin_sakit / cuti_tahunan / dinas_luar)
        //    yang SUDAH DISETUJUI untuk tanggal ini
        $stmt2 = mysqli_prepare($koneksi, "
            SELECT jenis FROM pengajuan
            WHERE pegawai_id = ? AND tanggal = ? AND status = 'disetujui'
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt2, 'is', $pegawai_id, $tanggal_target);
        mysqli_stmt_execute($stmt2);
        $pengajuan_disetujui = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));

        if ($pengajuan_disetujui) {
            $status_kehadiran = 'cuti';
            $catatan = 'Ditandai otomatis oleh sistem: sesuai pengajuan '
                . str_replace('_', ' ', $pengajuan_disetujui['jenis']) . ' yang disetujui, tidak ada cek-in.';
        } else {
            $status_kehadiran = 'alpa';
            $catatan = 'Ditandai otomatis oleh sistem: tidak ada cek-in dan tidak ada pengajuan izin/cuti yang disetujui.';
        }

        // 4. Insert baris absensi "kosong" (tanpa jam masuk/pulang, tanpa lokasi)
        //    sumber_absen = 'sistem' (lihat migrasi_cron_alpa.sql) supaya
        //    beda dengan cek-in sungguhan lewat fingerprint/geolocation.
        $stmt3 = mysqli_prepare($koneksi, "
            INSERT INTO absensi
                (pegawai_id, tanggal, sumber_absen, status_radius, status_kehadiran,
                 status_sinkronisasi, status_verifikasi, catatan_verifikasi)
            VALUES (?, ?, 'sistem', 'tidak_berlaku', ?, 'online', 'otomatis_sah', ?)
        ");
        mysqli_stmt_bind_param($stmt3, 'isss', $pegawai_id, $tanggal_target, $status_kehadiran, $catatan);
        mysqli_stmt_execute($stmt3);

        if ($status_kehadiran === 'alpa') { $jumlah_alpa++; } else { $jumlah_cuti++; }
    }

    mysqli_commit($koneksi);

    $ringkasan = "Selesai menandai tanggal $tanggal_target — $jumlah_alpa pegawai alpa, $jumlah_cuti pegawai cuti/izin (tanpa cek-in).\n";
    if ($lewat_cli) { echo $ringkasan; } else { echo nl2br(htmlspecialchars($ringkasan)); }

} catch (\Throwable $e) {
    mysqli_rollback($koneksi);
    error_log('cron_tandai_alpa.php gagal: ' . $e->getMessage());
    $pesan_gagal = "GAGAL menandai alpa untuk tanggal $tanggal_target: " . $e->getMessage() . "\n";
    if ($lewat_cli) { fwrite(STDERR, $pesan_gagal); exit(1); }
    http_response_code(500);
    echo nl2br(htmlspecialchars($pesan_gagal));
}

/**
 * =======================================================================
 * STATUS: tanggal merah (tabel hari_libur) sekarang ikut dilewati, tidak
 * cuma Sabtu/Minggu — lihat is_hari_kerja() di helpers.php. Pastikan
 * tabel hari_libur sudah lengkap untuk tahun berjalan lewat menu
 * Kalender Libur di admin.php sebelum cron ini diandalkan penuh.
 *
 * CATATAN UNTUK LANGKAH SELANJUTNYA (belum dikerjakan di file ini):
 * Pegawai dengan jadwal_dinas_luar di tanggal itu TIDAK dikecualikan
 * secara khusus di sini — kalau mereka dijadwalkan dinas luar tapi
 * tetap tidak cek-in sama sekali, tetap ditandai alpa (dianggap benar,
 * karena cek-in di lokasi dinas luar tetap wajib sesuai KF-27).
 * =======================================================================
 */
