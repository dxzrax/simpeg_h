<?php
/**
 * index.php
 * ---------------------------------------------------------------------
 * Halaman awal (landing page) SIMPEG-H — tampil sebelum login, untuk
 * SEMUA orang (baik nanti dia login sebagai Kasubbag TU/admin maupun
 * sebagai pegawai). Halaman ini cuma berisi intro + tombol "Masuk";
 * proses_login.php-lah yang nanti menentukan redirect ke admin.php
 * atau pegawai.php sesuai role di tabel users.
 *
 * Kalau orang yang membuka halaman ini ternyata sesi-nya masih aktif
 * (sudah login sebelumnya), langsung lempar ke dashboard yang sesuai
 * — tidak perlu lihat landing page lagi.
 * ---------------------------------------------------------------------
 */

session_start();

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'kasubbag' || $_SESSION['role'] === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: pegawai.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SIMPEG-H — Sistem Informasi Kepegawaian BPTD Kelas II Lampung</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* =====================================================================
   SIMPEG-H — Halaman Awal (Landing / Intro)
   Semua CSS ada di dalam file ini (tidak ada file .css terpisah).
   Token warna & font disamakan persis dengan login.php / admin.php /
   pegawai.php supaya identitas visualnya konsisten satu sistem.
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
html{scroll-behavior:smooth;}
body{
  font-family:var(--font-body);
  background:var(--bg);
  color:var(--ink-900);
  -webkit-font-smoothing:antialiased;
}
a{color:inherit;}
img,svg{display:block;}

/* ===== TOPBAR ===== */
.topbar{
  position:sticky;top:0;z-index:20;
  display:flex;align-items:center;justify-content:space-between;
  padding:18px 60px;
  background:rgba(243,245,246,.85);
  backdrop-filter:blur(10px);
  border-bottom:1px solid var(--line);
}
.brand{display:flex;align-items:center;gap:11px;}
.brand-mark{
  width:38px;height:38px;border-radius:10px;
  background:linear-gradient(135deg,var(--blue-500),var(--teal-500));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-display);font-weight:700;font-size:15px;color:#fff;
  flex-shrink:0;
}
.brand .name{font-family:var(--font-display);font-weight:700;font-size:16.5px;letter-spacing:.2px;line-height:1.2;}
.brand .sub{font-size:11px;color:var(--ink-600);letter-spacing:.2px;}
.btn-masuk-top{
  padding:9px 18px;border-radius:8px;text-decoration:none;
  background:var(--navy-900);color:#fff;font-size:13px;font-weight:600;
  display:flex;align-items:center;gap:6px;transition:.15s;
}
.btn-masuk-top:hover{background:var(--navy-700);}

/* ===== HERO ===== */
.hero{
  position:relative;overflow:hidden;
  background:
    radial-gradient(circle at 12% 15%, rgba(30,143,130,.35), transparent 45%),
    radial-gradient(circle at 88% 85%, rgba(44,110,155,.35), transparent 50%),
    linear-gradient(160deg, var(--navy-900), var(--navy-800) 60%, var(--navy-700));
  color:#fff;
  padding:96px 60px 108px;
  text-align:center;
}
.hero::before{
  content:'';position:absolute;inset:0;
  background-image:radial-gradient(rgba(255,255,255,.06) 1px, transparent 1px);
  background-size:26px 26px;
  opacity:.5;
}
.hero-inner{position:relative;z-index:1;max-width:700px;margin:0 auto;}
.eyebrow{
  display:inline-flex;align-items:center;gap:8px;
  font-family:var(--font-mono);font-size:11.5px;letter-spacing:1.2px;
  color:#9FD8CF;background:rgba(30,143,130,.18);
  border:1px solid rgba(30,143,130,.4);
  padding:6px 14px;border-radius:20px;margin-bottom:24px;
}
.eyebrow .dotpulse{width:6px;height:6px;border-radius:50%;background:#4ADEC7;box-shadow:0 0 0 3px rgba(74,222,199,.25);}
.hero h1{
  font-family:var(--font-display);font-weight:700;
  font-size:40px;line-height:1.25;letter-spacing:-.4px;
  margin-bottom:18px;
}
.hero p{font-size:15px;color:#B9C7D4;line-height:1.75;max-width:560px;margin:0 auto;}
.cta-row{display:flex;gap:14px;justify-content:center;margin-top:36px;flex-wrap:wrap;}
.btn-primary{
  padding:13px 28px;border-radius:9px;text-decoration:none;
  background:var(--teal-500);color:#fff;font-size:14.5px;font-weight:700;
  display:flex;align-items:center;gap:8px;transition:.15s;
}
.btn-primary:hover{background:#187C71;transform:translateY(-1px);}
.btn-ghost{
  padding:13px 24px;border-radius:9px;text-decoration:none;
  background:rgba(255,255,255,.06);color:#fff;font-size:14.5px;font-weight:600;
  border:1px solid rgba(255,255,255,.18);transition:.15s;
}
.btn-ghost:hover{background:rgba(255,255,255,.12);}

.hero-stats{
  position:relative;z-index:1;
  display:flex;gap:0;justify-content:center;margin-top:56px;
  max-width:620px;margin-left:auto;margin-right:auto;
  border-top:1px solid rgba(255,255,255,.12);
  padding-top:28px;
}
.hs-item{flex:1;text-align:center;}
.hs-item .n{font-family:var(--font-display);font-size:22px;font-weight:700;color:#fff;}
.hs-item .l{font-size:11.5px;color:#8CA3B8;margin-top:4px;letter-spacing:.2px;}

/* ===== FEATURES ===== */
.section{padding:76px 60px;max-width:1120px;margin:0 auto;}
.section-head{text-align:center;max-width:560px;margin:0 auto 44px;}
.section-head .kicker{
  font-family:var(--font-mono);font-size:11px;letter-spacing:1.2px;
  color:var(--teal-500);text-transform:uppercase;margin-bottom:10px;
}
.section-head h2{font-family:var(--font-display);font-size:26px;font-weight:700;margin-bottom:10px;letter-spacing:-.2px;}
.section-head p{font-size:13.5px;color:var(--ink-600);line-height:1.7;}

.feature-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;}
.feature-card{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:24px 20px;transition:.15s;
}
.feature-card:hover{border-color:var(--teal-500);box-shadow:0 6px 20px rgba(15,36,56,.06);transform:translateY(-2px);}
.fc-ic{
  width:42px;height:42px;border-radius:11px;
  display:flex;align-items:center;justify-content:center;font-size:19px;
  margin-bottom:16px;
}
.fc-ic.c1{background:var(--teal-100);}
.fc-ic.c2{background:var(--blue-100);}
.fc-ic.c3{background:var(--amber-100);}
.fc-ic.c4{background:var(--green-100);}
.feature-card h3{font-family:var(--font-display);font-size:14.5px;font-weight:700;margin-bottom:8px;letter-spacing:-.1px;}
.feature-card p{font-size:12.5px;color:var(--ink-600);line-height:1.6;}

/* ===== ROLES ===== */
.roles-wrap{background:var(--surface);border-top:1px solid var(--line);border-bottom:1px solid var(--line);}
.roles-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.role-card{
  border:1.5px solid var(--line);border-radius:14px;padding:30px 28px;
  display:flex;flex-direction:column;gap:16px;
}
.role-top{display:flex;align-items:center;gap:14px;}
.role-ic{
  width:46px;height:46px;border-radius:12px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:21px;color:#fff;
}
.role-ic.admin{background:linear-gradient(135deg,var(--navy-800),var(--blue-500));}
.role-ic.pegawai{background:linear-gradient(135deg,var(--teal-500),#2FAE9E);}
.role-top .rt-name{font-family:var(--font-display);font-weight:700;font-size:16.5px;}
.role-top .rt-sub{font-size:12px;color:var(--ink-600);}
.role-list{list-style:none;display:flex;flex-direction:column;gap:9px;}
.role-list li{font-size:13px;color:var(--ink-600);display:flex;align-items:flex-start;gap:9px;line-height:1.5;}
.role-list li::before{content:'✓';color:var(--teal-500);font-weight:700;flex-shrink:0;margin-top:1px;}
.role-cta{
  align-self:flex-start;margin-top:4px;text-decoration:none;
  font-size:13px;font-weight:700;color:var(--blue-500);
  display:flex;align-items:center;gap:6px;
}
.role-cta:hover{text-decoration:underline;}

/* ===== FOOTER ===== */
footer{
  text-align:center;padding:32px 20px;color:var(--ink-400);font-size:12px;
}
footer .f-name{font-weight:600;color:var(--ink-600);}

@media (max-width: 900px){
  .topbar{padding:16px 24px;}
  .hero{padding:64px 24px 76px;}
  .hero h1{font-size:30px;}
  .hero-stats{flex-wrap:wrap;gap:20px;}
  .hs-item{flex:1 1 45%;}
  .section{padding:56px 24px;}
  .feature-grid{grid-template-columns:1fr 1fr;}
  .roles-grid{grid-template-columns:1fr;}
}
@media (max-width: 540px){
  .feature-grid{grid-template-columns:1fr;}
  .hero h1{font-size:25px;}
}
</style>
</head>
<body>

<!-- ===== TOPBAR ===== -->
<div class="topbar">
  <div class="brand">
    <div class="brand-mark">SH</div>
    <div>
      <div class="name">SIMPEG-H</div>
      <div class="sub">BPTD Kelas II Lampung</div>
    </div>
  </div>
  <a href="login.php" class="btn-masuk-top">Masuk →</a>
</div>

<!-- ===== HERO ===== -->
<section class="hero">
  <div class="hero-inner">
    <div class="eyebrow"><span class="dotpulse"></span> SISTEM INFORMASI KEPEGAWAIAN</div>
    <h1>Kepegawaian, absensi, dan tunjangan kinerja — dalam satu sistem terpadu.</h1>
    <p>Digunakan oleh Kasubbag TU dan seluruh pegawai BPTD Kelas II Lampung untuk mencatat kehadiran, mengajukan izin, dan memantau tunjangan kinerja secara transparan — kantor maupun lapangan.</p>
    <div class="cta-row">
      <a href="login.php" class="btn-primary">Masuk ke Sistem →</a>
      <a href="#fitur" class="btn-ghost">Lihat fitur</a>
    </div>

    <div class="hero-stats">
      <div class="hs-item"><div class="n">2</div><div class="l">Portal · Admin & Pegawai</div></div>
      <div class="hs-item"><div class="n">Hybrid</div><div class="l">Fingerprint & Geolocation</div></div>
      <div class="hs-item"><div class="n">24/7</div><div class="l">Absensi & Pengajuan Online</div></div>
    </div>
  </div>
</section>

<!-- ===== FITUR ===== -->
<section class="section" id="fitur">
  <div class="section-head">
    <div class="kicker">Fitur Utama</div>
    <h2>Satu sistem, untuk seluruh alur kepegawaian</h2>
    <p>Dari absensi harian sampai perhitungan tunjangan kinerja, semua tercatat dan bisa ditelusuri.</p>
  </div>
  <div class="feature-grid">
    <div class="feature-card">
      <div class="fc-ic c1">🖐️</div>
      <h3>Absensi Hybrid</h3>
      <p>Cek-in/cek-out dengan fingerprint di kantor dan geolocation di lapangan — otomatis divalidasi terhadap radius titik lokasi tugas.</p>
    </div>
    <div class="feature-card">
      <div class="fc-ic c2">📝</div>
      <h3>Pengajuan Online</h3>
      <p>Ajukan izin, cuti tahunan, atau dinas luar langsung dari akun pegawai, lalu pantau status persetujuannya secara real-time.</p>
    </div>
    <div class="feature-card">
      <div class="fc-ic c3">💳</div>
      <h3>Tunjangan Kinerja Transparan</h3>
      <p>Rincian potongan dihitung dari aturan yang tersimpan di sistem, dengan audit trail yang bisa ditelusuri setiap saat.</p>
    </div>
    <div class="feature-card">
      <div class="fc-ic c4">🔍</div>
      <h3>Monitoring Kecurigaan</h3>
      <p>Pola kehadiran yang tidak wajar terdeteksi otomatis, membantu Kasubbag TU menindaklanjuti lebih cepat.</p>
    </div>
  </div>
</section>

<!-- ===== UNTUK SIAPA ===== -->
<div class="roles-wrap">
  <section class="section">
    <div class="section-head">
      <div class="kicker">Untuk Siapa</div>
      <h2>Satu pintu masuk, dua peran</h2>
      <p>Login yang sama akan mengarahkan kamu otomatis sesuai peran yang terdaftar.</p>
    </div>
    <div class="roles-grid">
      <div class="role-card">
        <div class="role-top">
          <div class="role-ic admin">🗂️</div>
          <div>
            <div class="rt-name">Kasubbag TU / Admin</div>
            <div class="rt-sub">Pengelolaan &amp; pengawasan</div>
          </div>
        </div>
        <ul class="role-list">
          <li>Kelola data pegawai & titik lokasi tugas</li>
          <li>Atur aturan potongan tunjangan kinerja</li>
          <li>Pantau monitoring kecurigaan kehadiran</li>
          <li>Telusuri audit trail tunjangan kinerja</li>
        </ul>
        <a href="login.php" class="role-cta">Masuk sebagai Admin →</a>
      </div>
      <div class="role-card">
        <div class="role-top">
          <div class="role-ic pegawai">🧑‍💼</div>
          <div>
            <div class="rt-name">Pegawai Kantor & Lapangan</div>
            <div class="rt-sub">Absensi &amp; pengajuan harian</div>
          </div>
        </div>
        <ul class="role-list">
          <li>Cek-in / cek-out harian</li>
          <li>Ajukan izin, cuti, atau dinas luar</li>
          <li>Pantau riwayat absensi & notifikasi</li>
          <li>Lihat slip tunjangan kinerja pribadi</li>
        </ul>
        <a href="login.php" class="role-cta">Masuk sebagai Pegawai →</a>
      </div>
    </div>
  </section>
</div>

<footer>
  <span class="f-name">SIMPEG-H</span> · Sistem Informasi Kepegawaian Terintegrasi<br>
  © 2026 BPTD Kelas II Lampung
</footer>

</body>
</html>
