<?php
/**
 * proses_seksi.php
 * ---------------------------------------------------------------------
 * CRUD untuk tabel `seksi` (unit kerja/departemen) — sebelumnya admin.php
 * HANYA membaca tabel ini untuk isi dropdown "Seksi" di form Data Pegawai,
 * tidak ada satu pun cara menambah/mengubah/menghapus seksi lewat UI.
 *
 * Tiga aksi lewat field hidden `aksi`:
 *   aksi=tambah -> INSERT seksi baru
 *   aksi=edit   -> UPDATE nama seksi yang sudah ada
 *   aksi=hapus  -> DELETE permanen
 *
 * KENAPA HAPUS PERMANEN AMAN DI SINI (beda dengan absensi/tukin yang
 * tidak boleh hilang): kolom pegawai.seksi_id memakai
 * "ON DELETE SET NULL" (lihat db_simpeg_h.sql) — kalau sebuah seksi
 * dihapus, pegawai yang tadinya ada di seksi itu TIDAK ikut terhapus,
 * cuma seksi_id mereka jadi NULL (tampil sebagai "—" / "Tanpa Seksi").
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php?view=pegawai');
    exit;
}
csrf_verify_or_die('admin.php?view=pegawai');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['kasubbag', 'admin'], true)) {
    header('Location: login.php?error=akses_ditolak');
    exit;
}

$aksi = $_POST['aksi'] ?? 'tambah';
$aksi = in_array($aksi, ['tambah', 'edit', 'hapus'], true) ? $aksi : 'tambah';

// =====================================================================
// aksi = hapus
// =====================================================================
if ($aksi === 'hapus') {
    $seksi_id = isset($_POST['seksi_id']) ? (int) $_POST['seksi_id'] : 0;
    if ($seksi_id <= 0) {
        header('Location: admin.php?view=pegawai&seksi=gagal');
        exit;
    }
    $stmt = mysqli_prepare($koneksi, "DELETE FROM seksi WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $seksi_id);
    mysqli_stmt_execute($stmt);

    header('Location: admin.php?view=pegawai&seksi=terhapus');
    exit;
}

// =====================================================================
// aksi = tambah / edit — keduanya butuh nama_seksi yang valid & unik
// =====================================================================
$nama_seksi = trim($_POST['nama_seksi'] ?? '');

if ($nama_seksi === '') {
    header('Location: admin.php?view=pegawai&seksi=gagal_kosong');
    exit;
}
if (mb_strlen($nama_seksi) > 50) {
    // Kolom nama_seksi VARCHAR(50) — dipotong di sini dulu supaya pesan
    // errornya jelas, bukan MySQL yang diam-diam memotong nilainya.
    header('Location: admin.php?view=pegawai&seksi=gagal_terlalu_panjang');
    exit;
}

if ($aksi === 'tambah') {
    // Cek duplikat MANUAL dulu supaya pesan error jelas — UNIQUE KEY di
    // database sebenarnya sudah menjamin ini juga (lihat catatan yang
    // sama di proses_tambah_pegawai.php), tapi mengecek dulu di sini
    // menghindari exception mysqli_sql_exception yang sekarang aktif
    // (lihat koneksi.php) hanya untuk kasus yang sudah bisa ditebak.
    $stmt = mysqli_prepare($koneksi, "SELECT id FROM seksi WHERE nama_seksi = ?");
    mysqli_stmt_bind_param($stmt, 's', $nama_seksi);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        header('Location: admin.php?view=pegawai&seksi=gagal_duplikat');
        exit;
    }

    $stmt = mysqli_prepare($koneksi, "INSERT INTO seksi (nama_seksi) VALUES (?)");
    mysqli_stmt_bind_param($stmt, 's', $nama_seksi);
    mysqli_stmt_execute($stmt);

    header('Location: admin.php?view=pegawai&seksi=berhasil');
    exit;
}

// ===== aksi = edit =====
$seksi_id = isset($_POST['seksi_id']) ? (int) $_POST['seksi_id'] : 0;
if ($seksi_id <= 0) {
    header('Location: admin.php?view=pegawai&seksi=gagal');
    exit;
}

// Cek duplikat dengan seksi LAIN (bukan dirinya sendiri)
$stmt = mysqli_prepare($koneksi, "SELECT id FROM seksi WHERE nama_seksi = ? AND id != ?");
mysqli_stmt_bind_param($stmt, 'si', $nama_seksi, $seksi_id);
mysqli_stmt_execute($stmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
    header('Location: admin.php?view=pegawai&seksi=gagal_duplikat');
    exit;
}

$stmt = mysqli_prepare($koneksi, "UPDATE seksi SET nama_seksi = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'si', $nama_seksi, $seksi_id);
mysqli_stmt_execute($stmt);

header('Location: admin.php?view=pegawai&seksi=berhasil_ubah');
exit;

/**
 * =======================================================================
 * CATATAN UNTUK LANGKAH SELANJUTNYA (belum dikerjakan di file ini):
 *
 * admin.php BELUM punya form/tombol yang mengirim ke file ini — menu
 * "Data Pegawai" saat ini cuma menampilkan seksi lewat dropdown baca-saja
 * (lihat $daftar_seksi_arr di admin.php). Supaya proses_seksi.php ini
 * benar-benar terpakai, admin.php perlu ditambah minimal:
 *
 *   1. Tombol kecil "Kelola Seksi" di dekat dropdown Seksi pada form
 *      Tambah/Edit Pegawai, yang membuka modal berisi:
 *        - Daftar seksi yang ada (nama + tombol Edit/Hapus)
 *        - Form kecil "+ Tambah Seksi" (input nama_seksi + tombol submit)
 *   2. Tiga <form method="POST" action="proses_seksi.php"> di dalam modal
 *      itu — pola persis seperti modal "Atur Aturan Potongan" di
 *      admin.php (lihat form ke proses_atur_potongan.php), termasu
 *      csrf_field() di masing-masing form.
 *   3. Peta pesan $pesan_seksi_map di bagian atas admin.php untuk
 *      menampilkan toast sukses/gagal, membaca $_GET['seksi'] — sama
 *      persis polanya dengan $pesan_titik_map / $pesan_jadwal_map yang
 *      sudah ada di admin.php.
 *
 * Ini bagian yang akan kita kerjakan berikutnya kalau kamu mau lanjut.
 * =======================================================================
 */
