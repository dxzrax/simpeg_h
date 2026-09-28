<?php
/**
 * cron_pengingat_cekin.php
 * -----------------------------------------------------------------------
 * Cron job pengingat cek-in pagi — kirim WA ke pegawai yang:
 *   1. Status kepegawaian = 'aktif'
 *   2. notif_pengingat_cekin_aktif = 1 (pegawai memilih opt-in di pengaturan)
 *   3. Hari ini adalah hari kerja (Senin-Jumat, bukan tanggal merah)
 *   4. Sudah melewati jam pengingat (default 07:30) tapi BELUM ada
 *      baris absensi untuk hari ini di tabel `absensi`
 *
 * CARA MENJALANKAN (daftarkan di Task Scheduler Windows / crontab Linux):
 *
 *   Windows Task Scheduler:
 *     Program: C:\laragon\bin\php\php-8.x.x\php.exe
 *     Argumen: C:\dx\simpeg_h\cron_pengingat_cekin.php
 *     Trigger: Setiap hari Senin-Jumat, pukul 07:30
 *
 *   Linux crontab (crontab -e):
 *     30 7 * * 1-5 /usr/bin/php /var/www/html/simpeg_h/cron_pengingat_cekin.php
 *
 * CATATAN: Cron ini harus dijalankan SETELAH jam kerja mulai (07:00/07:30),
 * bukan tengah malam — supaya pegawai yang memang belum cek-in saja
 * yang diingatkan, bukan semua pegawai di awal hari.
 * -----------------------------------------------------------------------
 */

// Cron berjalan dari CLI, bukan web — pastikan path absolut
define('SIMPEG_CRON', true);

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/NotificationGateway.php';

// Hanya jalankan di hari kerja
$hari_ini = date('Y-m-d');
if (!is_hari_kerja($hari_ini, $koneksi)) {
    echo "[CRON] {$hari_ini} bukan hari kerja, pengingat dilewati.\n";
    exit(0);
}

// Ambil pegawai aktif yang opt-in pengingat dan belum cek-in hari ini
$stmt = mysqli_prepare($koneksi, "
    SELECT p.id AS pegawai_id, p.nama, p.nomor_hp, p.email,
           p.notif_wa_aktif, p.notif_email_aktif
    FROM pegawai p
    LEFT JOIN absensi a ON a.pegawai_id = p.id AND a.tanggal = ?
    WHERE p.status_kepegawaian = 'aktif'
      AND p.notif_pengingat_cekin_aktif = 1
      AND a.id IS NULL
");
mysqli_stmt_bind_param($stmt, 's', $hari_ini);
mysqli_stmt_execute($stmt);
$pegawai_belum_cekin = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

$total   = count($pegawai_belum_cekin);
$berhasil = 0;

foreach ($pegawai_belum_cekin as $peg) {
    $gw = new NotificationGateway(
        $peg['nama'],
        $peg['nomor_hp'],
        $peg['email'],
        (bool) $peg['notif_wa_aktif'],
        (bool) $peg['notif_email_aktif']
    );
    try {
        $gw->kirimPengingatCekin();
        $berhasil++;
    } catch (\Throwable $e) {
        error_log('[CRON] Gagal kirim pengingat ke ' . $peg['nama'] . ': ' . $e->getMessage());
    }
}

echo "[CRON] {$hari_ini}: {$berhasil}/{$total} pengingat cek-in terkirim.\n";
exit(0);
