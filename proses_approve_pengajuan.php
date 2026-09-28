<?php
/**
 * proses_approve_pengajuan.php
 * ---------------------------------------------------------------------
 * Menerima keputusan Kasubbag TU/admin (Setuju / Tolak) untuk baris
 * [POIN 1] Notifikasi eksternal (WA + Email) dikirim setelah commit
 * via NotificationGateway.php — kalau gateway gagal, proses utama
 * tidak rollback (fire-and-forget, bukan bagian dari transaksi DB).
 * `pengajuan` (izin_sakit / cuti_tahunan / dinas_luar) yang dikirim
 * pegawai lewat pegawai.php (layar "Izin/Cuti/Dinas Luar").
 *
 * SEBELUM FILE INI ADA: setiap pengajuan permanen berstatus 'menunggu'
 * karena tidak ada satu pun cara untuk mengubahnya — akibatnya
 * cron_tandai_alpa.php tidak akan pernah menemukan pengajuan yang
 * 'disetujui', sehingga pegawai yang sebenarnya izin/cuti tetap
 * ditandai 'alpa' oleh cron. File ini menutup gap itu.
 *
 * Pola sama persis dengan proses_verifikasi.php (yang menangani
 * verifikasi absensi) — bedanya file ini menangani tabel `pengajuan`.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';
require_once 'NotificationGateway.php'; // [POIN 1] Gateway WA + Email

// Kembali ke Dashboard (tempat panel "Pengajuan Menunggu Persetujuan"
// berada) dengan mempertahankan pesan hasil aksi.
$query_kembali = 'view=dashboard';

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
$pengajuan_id  = isset($_POST['pengajuan_id']) ? (int) $_POST['pengajuan_id'] : 0;
$keputusan     = $_POST['keputusan'] ?? ''; // 'setuju' atau 'tolak'

if ($pengajuan_id <= 0 || !in_array($keputusan, ['setuju', 'tolak'], true)) {
    header('Location: admin.php?pengajuan=gagal&' . $query_kembali);
    exit;
}

// 1. Pastikan pengajuan ini MASIH 'menunggu' — mencegah dua admin
//    memproses baris yang sama, atau klik tombol dua kali. Ambil juga
//    pegawai_id, jenis, tanggal untuk notifikasi ke pegawai di langkah 3.
$stmt = mysqli_prepare($koneksi, "SELECT pegawai_id, jenis, tanggal, status FROM pengajuan WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $pengajuan_id);
mysqli_stmt_execute($stmt);
$pengajuan = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$pengajuan || $pengajuan['status'] !== 'menunggu') {
    header('Location: admin.php?pengajuan=sudah_diproses&' . $query_kembali);
    exit;
}

$status_baru = ($keputusan === 'setuju') ? 'disetujui' : 'ditolak';

try {
    mysqli_begin_transaction($koneksi);

    // 2. Simpan keputusan
    $stmt = mysqli_prepare($koneksi, "
        UPDATE pengajuan
        SET status = ?, disetujui_oleh = ?
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, 'sii', $status_baru, $admin_user_id, $pengajuan_id);
    mysqli_stmt_execute($stmt);

    // 3. Beri tahu pegawai lewat Notifikasi (pola sama seperti di
    //    proses_ajukan_izin.php saat pengajuan pertama kali dikirim)
    $label_jenis = ['izin_sakit' => 'Izin Sakit', 'cuti_tahunan' => 'Cuti Tahunan', 'dinas_luar' => 'Dinas Luar'][$pengajuan['jenis']] ?? $pengajuan['jenis'];
    $tgl_indo = date('d/m/Y', strtotime($pengajuan['tanggal']));
    if ($status_baru === 'disetujui') {
        $judul     = 'Pengajuan disetujui';
        $deskripsi = $label_jenis . ' tanggal ' . $tgl_indo . ' disetujui Kasubbag TU';
        $jenis_notif = 'ok';
    } else {
        $judul     = 'Pengajuan ditolak';
        $deskripsi = $label_jenis . ' tanggal ' . $tgl_indo . ' ditolak Kasubbag TU';
        $jenis_notif = 'warn';
    }
    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO notifikasi (pegawai_id, judul, deskripsi, jenis)
        VALUES (?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, 'isss', $pengajuan['pegawai_id'], $judul, $deskripsi, $jenis_notif);
    mysqli_stmt_execute($stmt);

    mysqli_commit($koneksi);

    // ── [POIN 1] KIRIM NOTIFIKASI EKSTERNAL (WA + Email) ──────────────
    // Fire-and-forget: error gateway TIDAK rollback transaksi di atas.
    // In-app notification (tabel `notifikasi`) sudah tersimpan via
    // langkah 3 di atas — ini menambah kanal WA + Email di atasnya.
    try {
        $gw = NotificationGateway::fromDb($koneksi, $pengajuan['pegawai_id']);
        if ($gw !== null) {
            $gw->kirimApprovalPengajuan($label_jenis, $tgl_indo, $status_baru);
        }
    } catch (\Throwable $gw_err) {
        error_log('NotificationGateway error (proses_approve_pengajuan): ' . $gw_err->getMessage());
    }
    // ─────────────────────────────────────────────────────────────────

    header('Location: admin.php?pengajuan=berhasil&' . $query_kembali);
    exit;

} catch (\Throwable $e) {
    mysqli_rollback($koneksi);
    error_log('proses_approve_pengajuan.php gagal: ' . $e->getMessage());
    header('Location: admin.php?pengajuan=gagal&' . $query_kembali);
    exit;
}

/**
 * =======================================================================
 * CATATAN:
 *
 * Kalau pengajuan yang disetujui adalah 'dinas_luar', ini TIDAK otomatis
 * membuat baris di tabel `jadwal_dinas_luar` (yang dipakai proses_cekin.php
 * untuk mencocokkan cek-in geolocation dengan jadwal resmi). Keduanya
 * sengaja dibiarkan terpisah untuk saat ini: `pengajuan` jenis dinas_luar
 * adalah pegawai MELAPORKAN akan dinas luar, sedangkan `jadwal_dinas_luar`
 * adalah Kasubbag TU MENUGASKAN dinas luar lewat menu Titik Lokasi Tugas.
 * Kalau nanti mau disatukan (menyetujui pengajuan otomatis membuat jadwal
 * resmi), itu penambahan yang bisa dikerjakan terpisah.
 * =======================================================================
 */
