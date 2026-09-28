<?php
/**
 * proses_edit_pegawai.php
 * ---------------------------------------------------------------------
 * Mengubah data pegawai yang SUDAH ADA. Password & username bersifat
 * opsional saat edit — dikosongkan berarti "tidak diubah", supaya admin
 * tidak wajib mengetik ulang password tiap kali cuma mau ganti nomor HP.
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

$pegawai_id = isset($_POST['pegawai_id']) ? (int) $_POST['pegawai_id'] : 0;
if ($pegawai_id <= 0) {
    header('Location: admin.php?view=pegawai&pegawai=gagal');
    exit;
}

$nip            = trim($_POST['nip'] ?? '');
$nama           = trim($_POST['nama'] ?? '');
$jabatan        = trim($_POST['jabatan'] ?? '') ?: null;
$kelas_jabatan  = ($_POST['kelas_jabatan'] ?? '') !== '' ? (int) $_POST['kelas_jabatan'] : null;
$seksi_id       = ($_POST['seksi_id'] ?? '') !== '' ? (int) $_POST['seksi_id'] : null;
$metode_absen   = in_array($_POST['metode_absen'] ?? '', ['fingerprint', 'geolocation'], true) ? $_POST['metode_absen'] : 'fingerprint';
$titik_lokasi_id = ($_POST['titik_lokasi_id'] ?? '') !== '' ? (int) $_POST['titik_lokasi_id'] : null;
$status_kepegawaian = in_array($_POST['status_kepegawaian'] ?? '', ['aktif', 'cuti', 'nonaktif'], true) ? $_POST['status_kepegawaian'] : 'aktif';
$nomor_hp       = trim($_POST['nomor_hp'] ?? '') ?: null;
$email          = trim($_POST['email'] ?? '') ?: null;
$tmt_jabatan    = trim($_POST['tmt_jabatan'] ?? '') ?: null;
// [POIN 3] TMT berlakunya mutasi metode — diisi admin di form edit.
// Kosong → default hari ini (perubahan langsung berlaku).
$tmt_mutasi_raw = trim($_POST['tmt_mutasi'] ?? '');
$tmt_mutasi     = ($tmt_mutasi_raw !== '' && strtotime($tmt_mutasi_raw) !== false) ? $tmt_mutasi_raw : date('Y-m-d');
$ket_mutasi     = trim($_POST['keterangan_mutasi'] ?? '') ?: null;

$username_baru = trim($_POST['username'] ?? '');
$password_baru = $_POST['password'] ?? '';
$role_baru     = in_array($_POST['role'] ?? '', ['admin', 'kasubbag', 'pegawai'], true) ? $_POST['role'] : null;

if ($nip === '' || $nama === '') {
    header('Location: admin.php?view=pegawai&pegawai=gagal_kosong');
    exit;
}
if ($username_baru !== '' && $password_baru !== '' && strlen($password_baru) < 6) {
    header('Location: admin.php?view=pegawai&pegawai=gagal_password_pendek');
    exit;
}

// Kalau NIP diubah, pastikan tidak bentrok dengan pegawai LAIN
$stmt = mysqli_prepare($koneksi, "SELECT id FROM pegawai WHERE nip = ? AND id != ?");
mysqli_stmt_bind_param($stmt, 'si', $nip, $pegawai_id);
mysqli_stmt_execute($stmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
    header('Location: admin.php?view=pegawai&pegawai=gagal_nip_dipakai');
    exit;
}

// Kalau username diisi & diubah, pastikan tidak bentrok dengan user LAIN
if ($username_baru !== '') {
    $stmt = mysqli_prepare($koneksi, "
        SELECT u.id FROM users u WHERE u.username = ? AND u.pegawai_id != ?
    ");
    mysqli_stmt_bind_param($stmt, 'si', $username_baru, $pegawai_id);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        header('Location: admin.php?view=pegawai&pegawai=gagal_username_dipakai');
        exit;
    }
}

mysqli_begin_transaction($koneksi);
try {
    // State SEBELUM diubah, untuk audit_log — kode_aktivasi dibuang dari
    // snapshot (kredensial sementara, bukan data yang relevan diaudit)
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM pegawai WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
    mysqli_stmt_execute($stmt);
    $sebelum = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    unset($sebelum['kode_aktivasi']);

    $stmt = mysqli_prepare($koneksi, "
        UPDATE pegawai SET
            nip = ?, nama = ?, jabatan = ?, kelas_jabatan = ?, seksi_id = ?,
            metode_absen = ?, titik_lokasi_id = ?, status_kepegawaian = ?,
            nomor_hp = ?, email = ?, tmt_jabatan = ?
        WHERE id = ?
    ");
    mysqli_stmt_bind_param(
        $stmt, 'sssiisissssi',
        $nip, $nama, $jabatan, $kelas_jabatan, $seksi_id, $metode_absen, $titik_lokasi_id,
        $status_kepegawaian, $nomor_hp, $email, $tmt_jabatan, $pegawai_id
    );
    mysqli_stmt_execute($stmt);

    // State SESUDAH diubah, lalu catat audit — aksi 'ubah' kalau status
    // kepegawaian tetap, atau 'nonaktifkan' kalau baru saja dinonaktifkan
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM pegawai WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
    mysqli_stmt_execute($stmt);
    $sesudah = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    unset($sesudah['kode_aktivasi']);

    $jadi_nonaktif = $sebelum['status_kepegawaian'] !== 'nonaktif' && $sesudah['status_kepegawaian'] === 'nonaktif';
    catat_audit($koneksi, 'pegawai', $pegawai_id, $jadi_nonaktif ? 'nonaktifkan' : 'ubah', $sebelum, $sesudah, (int) $_SESSION['user_id']);

    // ── [POIN 3] Catat mutasi metode jika metode_absen berubah ─────────────
    // Perubahan ini TIDAK menimpa nilai lama — sebaliknya, dicatat sebagai
    // baris historis baru di riwayat_mutasi_metode dengan:
    //   - metode_sebelum = metode yang berlaku sampai sebelum tmt_mutasi
    //   - metode_sesudah = metode baru yang akan berlaku mulai tmt_mutasi
    // Rule engine hitung_tukin_periode() kemudian membagi hari kerja dalam
    // bulan berjalan secara proporsional berdasarkan riwayat ini.
    if ($sebelum['metode_absen'] !== $sesudah['metode_absen']) {
        $stmt = mysqli_prepare($koneksi, "
            INSERT INTO riwayat_mutasi_metode
                (pegawai_id, metode_sebelum, metode_sesudah, tmt_berlaku, keterangan, dicatat_oleh)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param(
            $stmt, 'issssi',
            $pegawai_id,
            $sebelum['metode_absen'],   // metode_sebelum
            $sesudah['metode_absen'],   // metode_sesudah (= $metode_absen dari form)
            $tmt_mutasi,                // tanggal mulai berlaku
            $ket_mutasi,                // keterangan (opsional)
            $_SESSION['user_id']        // dicatat_oleh
        );
        mysqli_stmt_execute($stmt);
    }
    // ── Akhir [POIN 3] ──────────────────────────────────────────────────────


    // Username/role diubah kalau field-nya diisi (independen dari password)
    if ($username_baru !== '' || $role_baru !== null) {
        $set_parts = [];
        $params = [];
        $types = '';
        if ($username_baru !== '') { $set_parts[] = 'username = ?'; $params[] = $username_baru; $types .= 's'; }
        if ($role_baru !== null)   { $set_parts[] = 'role = ?';     $params[] = $role_baru;     $types .= 's'; }
        if (!empty($set_parts)) {
            $params[] = $pegawai_id;
            $types .= 'i';
            $sql = 'UPDATE users SET ' . implode(', ', $set_parts) . ' WHERE pegawai_id = ?';
            $stmt = mysqli_prepare($koneksi, $sql);
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
        }
    }

    // Password diubah HANYA kalau field-nya diisi
    if ($password_baru !== '') {
        $hash = password_hash($password_baru, PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($koneksi, "UPDATE users SET password = ? WHERE pegawai_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $hash, $pegawai_id);
        mysqli_stmt_execute($stmt);
    }

    mysqli_commit($koneksi);
    header('Location: admin.php?view=pegawai&pegawai=berhasil_ubah');
    exit;

} catch (\Throwable $e) {
    mysqli_rollback($koneksi);
    error_log('proses_edit_pegawai.php gagal: ' . $e->getMessage());
    header('Location: admin.php?view=pegawai&pegawai=gagal_server');
    exit;
}
