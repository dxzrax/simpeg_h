<?php
session_start();
require 'csrf.php';

/**
 * Pesan error dikirim lewat query string oleh proses_login.php atau
 * cek_sesi.php (mis. login.php?error=salah) — tidak lagi disimulasikan
 * di JavaScript seperti versi mockup awal.
 */
$pesan_error_map = [
    'kosong'         => 'Username dan password wajib diisi.',
    'salah'          => 'Username atau password salah.',
    'sesi_habis'     => 'Sesi kamu sudah berakhir, silakan login kembali.',
    'akses_ditolak'  => 'Akun kamu tidak punya akses ke halaman tersebut.',
    'csrf'           => 'Sesi form sudah kedaluwarsa, silakan coba lagi.',
    'terkunci_baru'  => 'Terlalu banyak percobaan salah — akun dikunci sementara selama 15 menit demi keamanan.',
];
$kode_error   = $_GET['error'] ?? '';

// Kasus 'terkunci' beda dari yang lain — pesannya butuh waktu sisa kunci
// yang dikirim proses_login.php lewat parameter 'hingga', jadi tidak
// cukup dari peta statis di atas.
if ($kode_error === 'terkunci' && isset($_GET['hingga'])) {
    $menit_sisa  = max(1, (int) ceil((strtotime($_GET['hingga']) - time()) / 60));
    $pesan_error = "Akun ini masih terkunci sementara — coba lagi dalam kurang lebih $menit_sisa menit.";
} else {
    $pesan_error = $pesan_error_map[$kode_error] ?? '';
}

// Pesan sukses dikirim login.php?aktivasi=berhasil oleh proses_aktivasi.php
// setelah pegawai berhasil membuat akun sendiri lewat kode aktivasi.
$pesan_sukses = (($_GET['aktivasi'] ?? '') === 'berhasil')
    ? 'Akun berhasil diaktivasi! Silakan masuk dengan username dan password yang baru saja kamu buat.'
    : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk | SIMPEG-H — BPTD Kelas II Lampung</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* =====================================================================
   SIMPEG-H — Halaman Login
   Semua CSS ada di dalam file ini (tidak ada file .css terpisah)
   Palet & token warna disamakan dengan admin.html / pegawai.html
   ===================================================================== */
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
html,body{height:100%;}
body{
  font-family:var(--font-body);
  background:var(--bg);
  color:var(--ink-900);
  -webkit-font-smoothing:antialiased;
}

.login-wrap{
  min-height:100vh;
  display:grid;
  grid-template-columns:1fr 460px;
}

/* ===== PANEL KIRI — BRAND ===== */
.brand-panel{
  background:
    radial-gradient(circle at 15% 20%, rgba(30,143,130,.35), transparent 45%),
    radial-gradient(circle at 85% 80%, rgba(44,110,155,.35), transparent 50%),
    linear-gradient(160deg, var(--navy-900), var(--navy-800) 60%, var(--navy-700));
  color:#fff;
  padding:56px 60px;
  display:flex;
  flex-direction:column;
  justify-content:space-between;
  position:relative;
  overflow:hidden;
}
.brand-panel::before{
  content:'';
  position:absolute;inset:0;
  background-image:radial-gradient(rgba(255,255,255,.06) 1px, transparent 1px);
  background-size:26px 26px;
  opacity:.5;
}
.brand-top{position:relative;z-index:1;display:flex;align-items:center;gap:12px;}
.brand-mark{
  width:44px;height:44px;border-radius:11px;
  background:linear-gradient(135deg,var(--blue-500),var(--teal-500));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-display);font-weight:700;font-size:17px;color:#fff;
  flex-shrink:0;
}
.brand-top .name{font-family:var(--font-display);font-weight:700;font-size:19px;letter-spacing:.2px;}
.brand-top .sub{font-size:11.5px;color:#8CA3B8;letter-spacing:.3px;}

.brand-mid{position:relative;z-index:1;max-width:420px;}
.brand-mid h1{
  font-family:var(--font-display);font-size:32px;font-weight:700;
  line-height:1.25;letter-spacing:-.3px;margin-bottom:16px;
}
.brand-mid p{font-size:14px;color:#B9C7D4;line-height:1.7;}

.brand-features{position:relative;z-index:1;display:flex;flex-direction:column;gap:14px;margin-top:32px;}
.bf-item{display:flex;align-items:center;gap:12px;}
.bf-ic{
  width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.12);
  display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;
}
.bf-txt .t{font-size:13px;font-weight:600;color:#fff;}
.bf-txt .s{font-size:11.5px;color:#8CA3B8;}

.brand-foot{position:relative;z-index:1;font-size:11.5px;color:#7D93A8;}

/* ===== PANEL KANAN — FORM ===== */
.form-panel{
  background:var(--surface);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:40px;
}
.form-box{width:100%;max-width:340px;}
.form-box h2{font-family:var(--font-display);font-size:22px;font-weight:700;margin-bottom:6px;}
.form-box .lead{font-size:13px;color:var(--ink-600);margin-bottom:28px;}

.field{margin-bottom:16px;}
.field label{
  display:block;font-size:12px;font-weight:600;color:var(--ink-600);
  margin-bottom:6px;letter-spacing:.2px;
}
.input-wrap{position:relative;}
.input-wrap input{
  width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:9px;
  font-family:var(--font-body);font-size:13.5px;color:var(--ink-900);
  background:var(--bg);outline:none;transition:.15s;
}
.input-wrap input:focus{border-color:var(--teal-500);background:#fff;box-shadow:0 0 0 3px rgba(30,143,130,.12);}
.input-wrap .toggle-eye{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  cursor:pointer;font-size:15px;color:var(--ink-400);user-select:none;background:none;border:none;
}
.field-err{font-size:11.5px;color:var(--red-500);margin-top:6px;display:none;}
.field-err.show{display:block;}

.row-between{display:flex;justify-content:space-between;align-items:center;margin:4px 0 22px;}
.remember{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--ink-600);}
.remember input{accent-color:var(--teal-500);width:15px;height:15px;}
.forgot{font-size:12.5px;color:var(--blue-500);text-decoration:none;font-weight:600;}
.forgot:hover{text-decoration:underline;}

.btn-login{
  width:100%;padding:12px;border:none;border-radius:9px;
  background:var(--teal-500);color:#fff;font-family:var(--font-body);
  font-size:14px;font-weight:700;cursor:pointer;transition:.15s;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.btn-login:hover{background:#187C71;}
.btn-login:active{transform:scale(.99);}
.btn-login .spinner{
  width:15px;height:15px;border-radius:50%;border:2px solid rgba(255,255,255,.4);
  border-top-color:#fff;animation:spin .6s linear infinite;display:none;
}
.btn-login.loading .spinner{display:inline-block;}
.btn-login.loading .btn-label{opacity:.85;}
@keyframes spin{to{transform:rotate(360deg);}}

.alert{
  display:none;align-items:center;gap:8px;
  background:var(--red-100);color:var(--red-500);
  font-size:12.5px;font-weight:600;padding:10px 12px;border-radius:8px;margin-bottom:16px;
}
.alert.show{display:flex;}
.alert.sukses{background:var(--green-100);color:var(--green-500);}

.divider{display:flex;align-items:center;gap:10px;margin:26px 0 18px;}
.divider .line{flex:1;height:1px;background:var(--line);}
.divider span{font-size:11px;color:var(--ink-400);font-family:var(--font-mono);}

.demo-note{
  background:var(--amber-100);border:1px solid #EFDDB6;border-radius:9px;
  padding:12px 14px;font-size:11.5px;color:#7A5A19;line-height:1.6;
}
.demo-note b{font-family:var(--font-mono);}
.demo-note .dn-head{font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:6px;}

@media (max-width: 860px){
  .login-wrap{grid-template-columns:1fr;}
  .brand-panel{display:none;}
  .form-panel{padding:24px;}
}
</style>
</head>
<body>

<div class="login-wrap">

  <!-- ===== PANEL KIRI ===== -->
  <div class="brand-panel">
    <div class="brand-top">
      <div class="brand-mark">SH</div>
      <div>
        <div class="name">SIMPEG-H</div>
        <div class="sub">BPTD Kelas II Lampung</div>
      </div>
    </div>

    <div class="brand-mid">
      <h1>Kepegawaian terpadu, absensi hybrid, satu sistem.</h1>
      <p>Rekapitulasi kehadiran dan perhitungan tunjangan kinerja pegawai kantor & lapangan — fingerprint dan geolocation dalam satu alur data.</p>

      <div class="brand-features">
        <div class="bf-item">
          <div class="bf-ic">🖐️</div>
          <div class="bf-txt"><div class="t">Absensi Fingerprint & Geolocation</div><div class="s">Satu tabel, satu aturan potongan</div></div>
        </div>
        <div class="bf-item">
          <div class="bf-ic">🛰️</div>
          <div class="bf-txt"><div class="t">Offline-first untuk pegawai lapangan</div><div class="s">Tetap tercatat meski sinyal lemah</div></div>
        </div>
        <div class="bf-item">
          <div class="bf-ic">🔍</div>
          <div class="bf-txt"><div class="t">Audit trail tunjangan kinerja</div><div class="s">Setiap potongan bisa ditelusuri</div></div>
        </div>
      </div>
    </div>

    <div class="brand-foot">© 2026 BPTD Kelas II Lampung · Sistem Informasi Kepegawaian</div>
  </div>

  <!-- ===== PANEL KANAN — FORM LOGIN ===== -->
  <div class="form-panel">
    <div class="form-box">
      <h2>Masuk ke akun kamu</h2>
      <p class="lead">Gunakan NIP/username dan password yang terdaftar</p>

      <div class="alert<?php echo $pesan_error ? ' show' : ''; ?>" id="loginAlert">⚠️ <span id="loginAlertText"><?php echo htmlspecialchars($pesan_error); ?></span></div>
      <div class="alert sukses<?php echo $pesan_sukses ? ' show' : ''; ?>">✅ <span><?php echo htmlspecialchars($pesan_sukses); ?></span></div>

      <form id="loginForm" action="proses_login.php" method="POST">
        <?php echo csrf_field(); ?>
        <div class="field">
          <label for="username">Username / NIP</label>
          <div class="input-wrap">
            <input type="text" id="username" name="username" placeholder="mis. bayu.kurniawan" autocomplete="username" required>
          </div>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
            <button type="button" class="toggle-eye" id="toggleEye" onclick="togglePassword()">👁️</button>
          </div>
        </div>

        <div class="row-between">
          <label class="remember"><input type="checkbox" id="remember"> Ingat saya</label>
          <a href="#" class="forgot" onclick="alert('Hubungi Kasubbag TU untuk reset password.');return false;">Lupa password?</a>
        </div>

        <button type="submit" class="btn-login" id="btnLogin">
          <span class="spinner"></span>
          <span class="btn-label">Masuk</span>
        </button>
      </form>
      <p style="text-align:center;font-size:12.5px;color:var(--ink-600);margin-top:16px;">
        Pegawai baru? <a href="aktivasi.php" style="color:var(--blue-500);font-weight:600;text-decoration:none;">Aktivasi akun di sini →</a>
      </p>

      <div class="divider"><div class="line"></div><span>INFO DEMO</span><div class="line"></div></div>

      <div class="demo-note">
        <div class="dn-head">🔑 Kredensial demo</div>
        Admin/Kasubbag TU: <b>kasubbag.tu</b><br>
        Pegawai lapangan: <b>bayu.kurniawan</b> / <b>siti.rahma</b><br>
        Password: <b>password123</b> — <i>hanya berlaku setelah kamu jalankan <code>gen_password.php</code> dan update tabel <code>users</code>.</i>
      </div>
    </div>
  </div>

</div>

<script>
function togglePassword(){
  const pw = document.getElementById('password');
  const eye = document.getElementById('toggleEye');
  const show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  eye.textContent = show ? '🙈' : '👁️';
}

// Tampilkan animasi loading saat form benar-benar dikirim ke proses_login.php
document.getElementById('loginForm').addEventListener('submit', function(){
  const btn = document.getElementById('btnLogin');
  btn.classList.add('loading');
  btn.disabled = true;
  // Form tetap submit secara normal (tidak ada preventDefault) —
  // browser akan mengirim POST ke proses_login.php lalu berpindah halaman.
});
</script>

</body>
</html>
