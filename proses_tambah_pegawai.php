<?php
/**
 * proses_tambah_pegawai.php
 * ---------------------------------------------------------------------
 * Menambah data pegawai baru. Perilakunya bercabang menurut peran:
 *
 *  - role = 'pegawai'  -> HANYA insert ke tabel `pegawai`, plus buat
 *    kode_aktivasi acak. TIDAK membuat baris `users` di sini — pegawai
 *    membuat akunnya sendiri lewat aktivasi.php (KF-30). Ini kasus
 *    paling umum, karena mayoritas dari 278 pegawai BPTD berperan
 *    'pegawai'.
 *
 *  - role = 'admin' / 'kasubbag' -> tetap seperti sebelumnya: admin
 *    langsung menentukan username & password di form ini, baris
 *    `users` dibuat seketika. Akun berperan tinggi jumlahnya sedikit
 *    dan dibuat oleh admin yang sudah dipercaya, jadi self-activation
 *    tidak diperlukan untuk kasus ini.
 *
 * Dipanggil dari form "Tambah Pegawai" di menu Data Pegawai (admin.php).
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';
require 'helpers.php'; // catat_audit()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php?view=pegawai');
    exit;
}
csrf_verify_or_die('admin.php?view=pegawai');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['kasubbag', 'admin'], true)) {
    header('Location: login.php?error=akses_ditolak');
    exit;
}

// 1. Ambil & bersihkan input
$nip             = trim($_POST['nip'] ?? '');
$nama            = trim($_POST['nama'] ?? '');
$jabatan         = trim($_POST['jabatan'] ?? '') ?: null;
$kelas_jabatan   = ($_POST['kelas_jabatan'] ?? '') !== '' ? (int) $_POST['kelas_jabatan'] : null;
$seksi_id        = ($_POST['seksi_id'] ?? '') !== '' ? (int) $_POST['seksi_id'] : null;
$metode_absen    = in_array($_POST['metode_absen'] ?? '', ['fingerprint', 'geolocation'], true) ? $_POST['metode_absen'] : 'fingerprint';
$titik_lokasi_id = ($_POST['titik_lokasi_id'] ?? '') !== '' ? (int) $_POST['titik_lokasi_id'] : null;
$status_kepegawaian = in_array($_POST['status_kepegawaian'] ?? '', ['aktif', 'cuti', 'nonaktif'], true) ? $_POST['status_kepegawaian'] : 'aktif';
$nomor_hp        = trim($_POST['nomor_hp'] ?? '') ?: null;
$email           = trim($_POST['email'] ?? '') ?: null;
$tmt_jabatan     = trim($_POST['tmt_jabatan'] ?? '') ?: null;

$role = in_array($_POST['role'] ?? '', ['admin', 'kasubbag', 'pegawai'], true) ? $_POST['role'] : 'pegawai';
$butuh_kredensial_langsung = $role !== 'pegawai'; // admin/kasubbag: kredensial diisi admin langsung

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// 2. Validasi wajib isi
if ($nip === '' || $nama === '') {
    header('Location: admin.php?view=pegawai&pegawai=gagal_kosong');
    exit;
}
if ($butuh_kredensial_langsung) {
    if ($username === '' || $password === '') {
        header('Location: admin.php?view=pegawai&pegawai=gagal_kosong');
        exit;
    }
    if (strlen($password) < 6) {
        header('Location: admin.php?view=pegawai&pegawai=gagal_password_pendek');
        exit;
    }
}

// 3. Cek duplikat NIP / username lebih dulu supaya pesan error jelas
$stmt = mysqli_prepare($koneksi, "SELECT id FROM pegawai WHERE nip = ?");
mysqli_stmt_bind_param($stmt, 's', $nip);
mysqli_stmt_execute($stmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
    header('Location: admin.php?view=pegawai&pegawai=gagal_nip_dipakai');
    exit;
}
if ($butuh_kredensial_langsung) {
    $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        header('Location: admin.php?view=pegawai&pegawai=gagal_username_dipakai');
        exit;
    }
}

// 4. Kode aktivasi acak (hanya kalau role = pegawai) — 8 karakter, tanpa
//    0/O/1/I/L yang gampang ketuker saat disampaikan lisan/tertulis.
function buat_kode_aktivasi(): string {
    $abjad = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $kode = '';
    for ($i = 0; $i < 8; $i++) {
        $kode .= $abjad[random_int(0, strlen($abjad) - 1)];
    }
    return $kode;
}
$kode_aktivasi = $butuh_kredensial_langsung ? null : buat_kode_aktivasi();

// 5. Transaksi
mysqli_begin_transaction($koneksi);
try {
    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO pegawai
            (nip, nama, jabatan, kelas_jabatan, seksi_id, metode_absen, titik_lokasi_id,
             status_kepegawaian, nomor_hp, email, tmt_jabatan, kode_aktivasi)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param(
        $stmt, 'sssiisisssss',
        $nip, $nama, $jabatan, $kelas_jabatan, $seksi_id, $metode_absen, $titik_lokasi_id,
        $status_kepegawaian, $nomor_hp, $email, $tmt_jabatan, $kode_aktivasi
    );
    mysqli_stmt_execute($stmt);
    $pegawai_id = mysqli_insert_id($koneksi);

    if ($butuh_kredensial_langsung) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($koneksi, "INSERT INTO users (pegawai_id, username, password, role) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isss', $pegawai_id, $username, $hash, $role);
        mysqli_stmt_execute($stmt);
    }

    // State tracking (audit_log) — baris baru, jadi data_sebelum NULL.
    // kode_aktivasi SENGAJA dibuang dari snapshot: itu kredensial sekali-
    // pakai, sudah diteruskan lewat flash session di bawah, tidak perlu
    // tersimpan permanen di riwayat audit.
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM pegawai WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
    mysqli_stmt_execute($stmt);
    $untuk_audit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    unset($untuk_audit['kode_aktivasi']);
    catat_audit($koneksi, 'pegawai', $pegawai_id, 'tambah', null, $untuk_audit, (int) $_SESSION['user_id']);

    // ── [POIN 3] Catat entri awal riwayat_mutasi_metode ────────────────────
    // Setiap pegawai baru WAJIB punya minimal satu baris riwayat metode
    // agar rule engine hitung_tukin_periode() dapat menentukan proporsi
    // hari fingerprint vs geolocation sejak awal. metode_sebelum = NULL
    // menandai ini adalah "entri awal" (bukan mutasi dari metode lain).
    // tmt_berlaku = tmt_jabatan kalau diisi, fallback ke tanggal hari ini.
    $tmt_mulai_awal = ($tmt_jabatan !== null && $tmt_jabatan !== '') ? $tmt_jabatan : date('Y-m-d');
    $ket_awal = 'Entri awal saat pegawai didaftarkan ke SIMPEG-H';
    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO riwayat_mutasi_metode
            (pegawai_id, metode_sebelum, metode_sesudah, tmt_berlaku, keterangan, dicatat_oleh)
        VALUES (?, NULL, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, 'isssi', $pegawai_id, $metode_absen, $tmt_mulai_awal, $ket_awal, $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    // ── Akhir [POIN 3] ──────────────────────────────────────────────────────

    mysqli_commit($koneksi);

    if ($butuh_kredensial_langsung) {
        header('Location: admin.php?view=pegawai&pegawai=berhasil');
    } else {
        // Kode aktivasi lewat flash session sekali pakai — BUKAN query
        // string — supaya tidak nyangkut di riwayat URL/browser/server log.
        $_SESSION['flash_kode_aktivasi'] = ['nama' => $nama, 'nip' => $nip, 'kode' => $kode_aktivasi];
        header('Location: admin.php?view=pegawai&pegawai=berhasil_pegawai');
    }
    exit;

} catch (\Throwable $e) {
    mysqli_rollback($koneksi);
    error_log('proses_tambah_pegawai.php gagal: ' . $e->getMessage());
    header('Location: admin.php?view=pegawai&pegawai=gagal_server');
    exit;
}
