<?php
/**
 * proses_login.php
 * ---------------------------------------------------------------------
 * Menerima kiriman form dari login.php (method POST), mencocokkan
 * username + password ke tabel `users`, lalu membuka sesi login.
 *
 * Pola file ini ("proses_xxx.php") TIDAK menampilkan HTML apa pun —
 * tugasnya cuma: terima data -> proses -> redirect. Inilah contoh
 * konkret yang bisa kamu tiru untuk proses_cekin.php,
 * proses_tambah_pegawai.php, dst.
 *
 * PROTEKSI BRUTE-FORCE (kolom users.percobaan_gagal/terkunci_hingga di
 * db_simpeg_h.sql): setelah
 * LIMIT_PERCOBAAN_GAGAL kali salah password BERTURUT-TURUT, akun
 * dikunci selama DURASI_KUNCI_MENIT — bukan diblokir IP, karena semua
 * pegawai BPTD kemungkinan besar mengakses dari jaringan kantor yang
 * sama (blokir per-IP akan mengunci semua orang sekaligus). Penghitung
 * di-reset ke 0 setiap login berhasil.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';

const LIMIT_PERCOBAAN_GAGAL = 5;
const DURASI_KUNCI_MENIT    = 15;

// 1. Pastikan yang mengakses file ini benar-benar hasil submit form (POST),
//    bukan orang yang iseng buka proses_login.php langsung dari address bar.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}
csrf_verify_or_die('login.php');

// 2. Ambil & bersihkan input dari form
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: login.php?error=kosong');
    exit;
}

// 3. Ambil data user dari database — pakai prepared statement (WAJIB),
//    supaya tidak bisa disusupi SQL Injection lewat kolom username.
$stmt = mysqli_prepare($koneksi, "
    SELECT u.id, u.pegawai_id, u.password, u.role, u.percobaan_gagal, u.terkunci_hingga, p.nama
    FROM users u
    JOIN pegawai p ON p.id = u.pegawai_id
    WHERE u.username = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, 's', $username);
mysqli_stmt_execute($stmt);
$hasil = mysqli_stmt_get_result($stmt);
$user  = mysqli_fetch_assoc($hasil);

// 3b. Kalau akun ini SEDANG terkunci (waktu kunci belum lewat), tolak
//     langsung TANPA mengecek password sama sekali — supaya tidak
//     membuang waktu bcrypt ataupun memberi celah percobaan tambahan.
if ($user && $user['terkunci_hingga'] !== null && strtotime($user['terkunci_hingga']) > time()) {
    header('Location: login.php?error=terkunci&hingga=' . urlencode($user['terkunci_hingga']));
    exit;
}

// 4. Cocokkan password yang diketik dengan hash bcrypt yang tersimpan.
//    password_verify() otomatis tahu cara membaca hash bcrypt — kamu
//    TIDAK PERNAH membandingkan password dengan == secara langsung.
if (!$user || !password_verify($password, $user['password'])) {
    // Password salah (atau username tidak ada) — kalau usernya memang
    // ada, naikkan penghitung percobaan gagal dan kunci kalau sudah
    // mencapai batas. Username yang tidak ada di database sengaja TIDAK
    // dicatat di mana pun (tidak ada baris untuk disimpan), supaya
    // percobaan menebak-nebak username tidak membebani tabel users.
    if ($user) {
        $percobaan_baru      = (int) $user['percobaan_gagal'] + 1;
        $user_id_percobaan   = (int) $user['id']; // WAJIB ditampung ke variabel dulu — mysqli_stmt_bind_param()
                                                    // butuh argumen by-reference, dan $user['id'] (elemen array
                                                    // langsung) tidak bisa dipakai langsung sebagai referensi.
        $durasi_kunci        = DURASI_KUNCI_MENIT; // Konstanta juga wajib ditampung ke variabel untuk by-reference
        if ($percobaan_baru >= LIMIT_PERCOBAAN_GAGAL) {
            $stmt = mysqli_prepare($koneksi, "
                UPDATE users SET percobaan_gagal = ?, terkunci_hingga = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, 'iii', $percobaan_baru, $durasi_kunci, $user_id_percobaan);
            mysqli_stmt_execute($stmt);
            header('Location: login.php?error=terkunci_baru');
            exit;
        }
        $stmt = mysqli_prepare($koneksi, "UPDATE users SET percobaan_gagal = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $percobaan_baru, $user_id_percobaan);
        mysqli_stmt_execute($stmt);
    }
    header('Location: login.php?error=salah');
    exit;
}

// 5. Login berhasil -> regenerasi ID sesi DULU sebelum mengisi apa pun.
//    Tanpa ini, sesi yang dipakai SEBELUM login (mis. kalau penyerang
//    sempat menyisipkan session ID-nya sendiri ke korban) bisa tetap
//    valid setelah login — dikenal sebagai session fixation. Parameter
//    true = hapus juga sesi lama di sisi server, bukan cuma ganti ID.
session_regenerate_id(true);

// 6. Simpan data penting ke session. Session inilah yang nanti dibaca
//    oleh cek_sesi.php untuk melindungi admin.php / pegawai.php dari
//    akses tanpa login.
$_SESSION['user_id']    = $user['id'];
$_SESSION['pegawai_id'] = $user['pegawai_id']; // dipakai proses_cekin.php & file proses lain yang menyentuh tabel absensi/pengajuan
$_SESSION['nama']       = $user['nama'];
$_SESSION['role']       = $user['role'];

// 7. Catat waktu login terakhir, DAN reset penghitung percobaan gagal +
//    buka kunci kalau sebelumnya sempat terkunci lalu berhasil login
//    setelah masa kuncinya habis.
$user_id = (int) $user['id'];
mysqli_query($koneksi, "
    UPDATE users SET last_login = NOW(), percobaan_gagal = 0, terkunci_hingga = NULL
    WHERE id = $user_id
");

// 8. Redirect sesuai peran — inilah yang menggantikan DEMO_USERS
//    di JavaScript pada login.html versi mockup.
if ($user['role'] === 'kasubbag' || $user['role'] === 'admin') {
    header('Location: admin.php');
} else {
    header('Location: pegawai.php');
}
exit;
