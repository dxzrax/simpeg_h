<?php
/**
 * proses_finalisasi_tukin.php
 * ---------------------------------------------------------------------
 * Mengunci status seluruh baris `tukin` pada satu periode dari 'draft'
 * menjadi 'final'. Setelah final:
 *   - hitung_tukin_periode() (helpers.php) MENOLAK menghitung ulang
 *     periode itu — dipakai baik oleh tombol manual di bawah maupun
 *     cron_hitung_tukin_draft.php — supaya rekap yang sudah dianggap
 *     sah untuk SPJ tidak tiba-tiba berubah angkanya.
 *   - Kalau memang perlu dihitung ulang (mis. ada koreksi data absensi
 *     susulan), admin harus sengaja membuka kunci dulu lewat aksi=buka
 *     di file ini, baru boleh menghitung ulang.
 *
 * Setiap transisi kunci/buka-kunci dicatat ke audit_log per baris tukin
 * (state sebelum & sesudah) — momen paling kritis untuk ditelusuri,
 * karena angka yang sudah jadi dasar SPJ ikut berubah kalau dibuka lagi.
 * Rincian per-potongan (tukin_detail) tetap hanya mencerminkan hitungan
 * TERBARU — kalau butuh rincian versi lama sebelum buka-kunci, itu belum
 * ikut disnapshot di sini (baru state agregat tukin-nya).
 *
 * Butuh minimal satu baris `tukin` untuk periode ini supaya ada yang
 * difinalisasi — kalau belum pernah dihitung sama sekali, tombol ini
 * tidak berguna (makanya di UI ditampilkan hanya setelah rekap terisi).
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';
require 'helpers.php'; // catat_audit()
require_once 'NotificationGateway.php'; // [POIN 1] Gateway WA + Email

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
$aksi  = ($_POST['aksi'] ?? 'kunci') === 'buka' ? 'buka' : 'kunci';

$stmt = mysqli_prepare($koneksi, "SELECT COUNT(*) AS jml FROM tukin WHERE periode_bulan = ? AND periode_tahun = ?");
mysqli_stmt_bind_param($stmt, 'ii', $bulan, $tahun);
mysqli_stmt_execute($stmt);
$jml = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['jml'];

if ($jml === 0) {
    header("Location: admin.php?view=tukin&finalisasi=kosong&bulan=$bulan&tahun=$tahun");
    exit;
}

mysqli_begin_transaction($koneksi);
try {
    // State SEBELUM, per baris tukin di periode ini — dipakai audit_log
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM tukin WHERE periode_bulan = ? AND periode_tahun = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $bulan, $tahun);
    mysqli_stmt_execute($stmt);
    $baris_sebelum = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $status_baru = ($aksi === 'kunci') ? 'final' : 'draft';
    $stmt = mysqli_prepare($koneksi, "UPDATE tukin SET status = ? WHERE periode_bulan = ? AND periode_tahun = ?");
    mysqli_stmt_bind_param($stmt, 'sii', $status_baru, $bulan, $tahun);
    mysqli_stmt_execute($stmt);

    // State SESUDAH, dipasangkan ke "sebelum" lewat id, lalu dicatat satu
    // per satu — supaya tiap baris tukin (= tiap pegawai) punya audit_log
    // sendiri, konsisten dengan makna id_record yang polymorphic
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM tukin WHERE periode_bulan = ? AND periode_tahun = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $bulan, $tahun);
    mysqli_stmt_execute($stmt);
    $baris_sesudah = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    $peta_sesudah = [];
    foreach ($baris_sesudah as $baris) {
        $peta_sesudah[$baris['id']] = $baris;
    }

    $aksi_audit = ($aksi === 'kunci') ? 'kunci' : 'buka_kunci';
    foreach ($baris_sebelum as $sebelum) {
        $sesudah = $peta_sesudah[$sebelum['id']] ?? null;
        if ($sesudah === null) {
            continue; // seharusnya tidak terjadi, jaga-jaga saja
        }
        catat_audit($koneksi, 'tukin', (int) $sebelum['id'], $aksi_audit, $sebelum, $sesudah, (int) $_SESSION['user_id']);
    }

    mysqli_commit($koneksi);
} catch (\Throwable $e) {
    mysqli_rollback($koneksi);
    error_log('proses_finalisasi_tukin.php gagal: ' . $e->getMessage());
    header("Location: admin.php?view=tukin&finalisasi=gagal&bulan=$bulan&tahun=$tahun");
    exit;
}

// ── [POIN 1] KIRIM NOTIFIKASI TUKIN ke semua pegawai (saat finalisasi kunci saja) ──
// Bukan saat buka-kunci (aksi=buka) karena belum ada perubahan angka definitif.
if ($aksi === 'kunci') {
    $bulan_nama = ['', 'Januari','Februari','Maret','April','Mei','Juni',
                       'Juli','Agustus','September','Oktober','November','Desember'];
    $periode_label = ($bulan_nama[$bulan] ?? $bulan) . ' ' . $tahun;

    // Ambil rekap tukin semua pegawai periode ini
    $stmt_notif = mysqli_prepare($koneksi, "
        SELECT t.pegawai_id, t.tukin_diterima, t.total_potongan
        FROM tukin t WHERE t.periode_bulan = ? AND t.periode_tahun = ?
    ");
    mysqli_stmt_bind_param($stmt_notif, 'ii', $bulan, $tahun);
    mysqli_stmt_execute($stmt_notif);
    $rows_notif = mysqli_fetch_all(mysqli_stmt_get_result($stmt_notif), MYSQLI_ASSOC);

    foreach ($rows_notif as $row_n) {
        try {
            $gw = NotificationGateway::fromDb($koneksi, (int) $row_n['pegawai_id']);
            if ($gw !== null) {
                $gw->kirimInfoTukin(
                    $periode_label,
                    (float) $row_n['tukin_diterima'],
                    (float) $row_n['total_potongan'],
                    'final'
                );
            }
        } catch (\Throwable $gw_err) {
            // Log satu per satu — gagal kirim ke satu pegawai tidak stop sisanya
            error_log('NotificationGateway error (finalisasi tukin, pegawai_id=' . $row_n['pegawai_id'] . '): ' . $gw_err->getMessage());
        }
    }
}
// ─────────────────────────────────────────────────────────────────

$pesan = ($aksi === 'kunci') ? 'berhasil_kunci' : 'berhasil_buka';
header("Location: admin.php?view=tukin&finalisasi=$pesan&bulan=$bulan&tahun=$tahun");
exit;
