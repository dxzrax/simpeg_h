<?php
/**
 * aktivasi.php
 * ---------------------------------------------------------------------
 * Halaman aktivasi mandiri (KF-30) untuk pegawai yang datanya sudah
 * didaftarkan Admin/Kasubbag TU tapi belum punya akun login. Pegawai
 * memasukkan NIP + kode aktivasi sementara dari admin, lalu membuat
 * username & password sendiri.
 * ---------------------------------------------------------------------
 */
session_start();
require 'csrf.php';

$pesan_error_map = [
    'kosong'            => 'Semua kolom wajib diisi.',
    'password_pendek'   => 'Password minimal 6 karakter.',
    'password_beda'     => 'Konfirmasi password tidak sama dengan password.',
    'tidak_cocok'       => 'NIP atau kode aktivasi tidak cocok — periksa kembali, atau akun ini mungkin sudah pernah diaktivasi.',
    'username_dipakai'  => 'Username sudah dipakai, pilih username lain.',
    'server'            => 'Terjadi kesalahan pada server, coba lagi.',
];
$pesan_error = $pesan_error_map[$_GET['error'] ?? ''] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aktivasi Akun | SIMPEG-H — BPTD Kelas II Lampung</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --navy-900:#0F2438;--navy-800:#15304A;--navy-700:#1B3A5C;
  --blue-500:#2C6E9B;--blue-100:#E4EEF5;--teal-500:#1E8F82;--teal-100:#E1F1EE;
  --amber-500:#D89B3C;--amber-100:#FBF0DE;--red-500:#C24B4B;--red-100:#FBEAEA;
  --green-500:#2F9464;--green-100:#E7F4EE;--bg:#F3F5F6;--surface:#FFFFFF;
  --ink-900:#14202B;--ink-600:#48545E;--ink-400:#8894A0;--line:#E2E7EA;--radius:10px;
  --font-display:'Space Grotesk',sans-serif;--font-body:'Plus Jakarta Sans',sans-serif;--font-mono:'IBM Plex Mono',monospace;
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{font-family:var(--font-body);background:var(--bg);color:var(--ink-900);-webkit-font-smoothing:antialiased;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.card{width:100%;max-width:400px;background:var(--surface);border-radius:16px;padding:36px;box-shadow:0 1px 3px rgba(15,36,56,.08),0 8px 24px rgba(15,36,56,.06);}
.card-top{display:flex;align-items:center;gap:12px;margin-bottom:24px;}
.brand-mark{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--blue-500),var(--teal-500));
  display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:700;font-size:15px;color:#fff;flex-shrink:0;}
.card-top .name{font-family:var(--font-display);font-weight:700;font-size:16px;}
.card-top .sub{font-size:11px;color:var(--ink-400);}
h2{font-family:var(--font-display);font-size:20px;font-weight:700;margin-bottom:6px;}
.lead{font-size:12.5px;color:var(--ink-600);margin-bottom:22px;line-height:1.6;}
.field{margin-bottom:14px;}
.field label{display:block;font-size:12px;font-weight:600;color:var(--ink-600);margin-bottom:6px;}
.field input{width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:9px;
  font-family:var(--font-body);font-size:13.5px;color:var(--ink-900);background:var(--bg);outline:none;transition:.15s;}
.field input:focus{border-color:var(--teal-500);background:#fff;box-shadow:0 0 0 3px rgba(30,143,130,.12);}
.field .hint{font-size:11px;color:var(--ink-400);margin-top:5px;}
.field-err{font-size:11.5px;color:var(--red-500);margin-top:6px;display:none;}
.field-err.show{display:block;}
.alert{display:none;align-items:center;gap:8px;background:var(--red-100);color:var(--red-500);
  font-size:12.5px;font-weight:600;padding:10px 12px;border-radius:8px;margin-bottom:16px;}
.alert.show{display:flex;}
.btn-aktivasi{width:100%;padding:12px;border:none;border-radius:9px;background:var(--teal-500);color:#fff;
  font-family:var(--font-body);font-size:14px;font-weight:700;cursor:pointer;transition:.15s;margin-top:6px;
  display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-aktivasi:hover{background:#187C71;}
.btn-aktivasi .spinner{width:15px;height:15px;border-radius:50%;border:2px solid rgba(255,255,255,.4);
  border-top-color:#fff;animation:spin .6s linear infinite;display:none;}
.btn-aktivasi.loading .spinner{display:inline-block;}
@keyframes spin{to{transform:rotate(360deg);}}
.back-link{display:block;text-align:center;margin-top:18px;font-size:12.5px;color:var(--blue-500);font-weight:600;text-decoration:none;}
.back-link:hover{text-decoration:underline;}
</style>
</head>
<body>
<div class="card">
  <div class="card-top">
    <div class="brand-mark">SH</div>
    <div><div class="name">SIMPEG-H</div><div class="sub">BPTD Kelas II Lampung</div></div>
  </div>

  <h2>Aktivasi Akun</h2>
  <p class="lead">Untuk pegawai yang datanya sudah didaftarkan Admin/Kasubbag TU. Masukkan NIP dan kode aktivasi dari admin, lalu buat username & password sendiri.</p>

  <div class="alert<?php echo $pesan_error ? ' show' : ''; ?>">⚠️ <span><?php echo htmlspecialchars($pesan_error); ?></span></div>

  <form id="formAktivasi" action="proses_aktivasi.php" method="POST" autocomplete="off">
    <?php echo csrf_field(); ?>
    <div class="field">
      <label for="nip">NIP</label>
      <input type="text" id="nip" name="nip" placeholder="19xxxxxxxxxxxxxxxxxx" required>
    </div>
    <div class="field">
      <label for="kode_aktivasi">Kode Aktivasi</label>
      <input type="text" id="kode_aktivasi" name="kode_aktivasi" placeholder="mis. K7M9PQXT" maxlength="8" style="text-transform:uppercase;font-family:var(--font-mono);letter-spacing:1px;" required>
      <div class="hint">Kode 8 karakter dari Admin/Kasubbag TU — sekali pakai.</div>
    </div>
    <div class="field">
      <label for="username">Username Baru</label>
      <input type="text" id="username" name="username" placeholder="mis. putri.ramadhani" required>
    </div>
    <div class="field">
      <label for="password">Password Baru</label>
      <input type="password" id="password" name="password" placeholder="min. 6 karakter" required>
    </div>
    <div class="field">
      <label for="konfirmasi">Konfirmasi Password</label>
      <input type="password" id="konfirmasi" name="konfirmasi" placeholder="ulangi password" required>
      <div class="field-err" id="errKonfirmasi">Password dan konfirmasi tidak sama.</div>
    </div>
    <button type="submit" class="btn-aktivasi" id="btnAktivasi">
      <span class="spinner"></span>
      <span class="btn-label">Aktivasi Akun</span>
    </button>
  </form>

  <a href="login.php" class="back-link">← Sudah punya akun? Masuk di sini</a>
</div>

<script>
document.getElementById('kode_aktivasi').addEventListener('input', function(){ this.value = this.value.toUpperCase(); });

const form = document.getElementById('formAktivasi');
const pass = document.getElementById('password');
const konf = document.getElementById('konfirmasi');
const errK = document.getElementById('errKonfirmasi');

form.addEventListener('submit', function(e){
  if (pass.value !== konf.value) {
    e.preventDefault();
    errK.classList.add('show');
    konf.focus();
    return;
  }
  errK.classList.remove('show');
  const btn = document.getElementById('btnAktivasi');
  btn.classList.add('loading');
  btn.disabled = true;
});
</script>
</body>
</html>
