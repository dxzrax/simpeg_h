<?php
/**
 * Menghentikan sesi login SUNGGUHAN di sini (bukan cuma di JavaScript).
 * Ambil dulu nama & peran sebelum sesi dihapus, supaya halaman ucapan
 * perpisahan masih bisa menyapa pakai nama — setelah ini pengguna
 * benar-benar tidak bisa akses admin.php/pegawai.php lagi (cek_sesi.php
 * akan menendang balik ke login.php).
 */
session_start();
$nama_keluar = $_SESSION['nama'] ?? 'Pengguna';
$role_keluar = $_SESSION['role'] ?? ((($_GET['from'] ?? '') === 'admin') ? 'kasubbag' : 'pegawai');

$_SESSION = [];
session_destroy();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Keluar | SIMPEG-H — BPTD Kelas II Lampung</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* =====================================================================
   SIMPEG-H — Halaman Logout
   Semua CSS ada di dalam file ini (tidak ada file .css terpisah)
   Palet & token warna disamakan dengan login.html / admin.html / pegawai.html
   ===================================================================== */
:root{
  --navy-900:#0F2438;
  --navy-800:#15304A;
  --navy-700:#1B3A5C;
  --blue-500:#2C6E9B;
  --teal-500:#1E8F82;
  --teal-100:#E1F1EE;
  --amber-500:#D89B3C;
  --amber-100:#FBF0DE;
  --green-500:#2F9464;
  --green-100:#E7F4EE;
  --bg:#F3F5F6;
  --surface:#FFFFFF;
  --ink-900:#14202B;
  --ink-600:#48545E;
  --ink-400:#8894A0;
  --line:#E2E7EA;
  --font-display:'Space Grotesk',sans-serif;
  --font-body:'Plus Jakarta Sans',sans-serif;
  --font-mono:'IBM Plex Mono',monospace;
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{
  font-family:var(--font-body);
  color:var(--ink-900);
  -webkit-font-smoothing:antialiased;
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  padding:24px;
  background:
    radial-gradient(circle at 20% 15%, rgba(30,143,130,.30), transparent 45%),
    radial-gradient(circle at 85% 85%, rgba(44,110,155,.30), transparent 50%),
    linear-gradient(160deg, var(--navy-900), var(--navy-800) 60%, var(--navy-700));
}

.card{
  background:var(--surface);
  border-radius:20px;
  padding:44px 40px 36px;
  width:420px;max-width:100%;
  text-align:center;
  box-shadow:0 30px 60px rgba(15,36,56,.35);
}

.brand-row{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:30px;}
.brand-mark{
  width:34px;height:34px;border-radius:9px;
  background:linear-gradient(135deg,var(--blue-500),var(--teal-500));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-display);font-weight:700;font-size:13px;color:#fff;
}
.brand-row .name{font-family:var(--font-display);font-weight:700;font-size:15px;}
.brand-row .sub{font-size:10.5px;color:var(--ink-400);}

.check-ring{
  width:72px;height:72px;border-radius:50%;
  background:var(--green-100);
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 22px;
  animation:pop .35s ease-out;
}
.check-ring .mark{font-size:32px;color:var(--green-500);}
@keyframes pop{from{transform:scale(.6);opacity:0;}to{transform:scale(1);opacity:1;}}

h1{font-family:var(--font-display);font-size:21px;font-weight:700;margin-bottom:8px;}
.lead{font-size:13px;color:var(--ink-600);line-height:1.6;margin-bottom:26px;}
.lead b{color:var(--ink-900);}

.session-box{
  background:var(--bg);border:1px solid var(--line);border-radius:12px;
  padding:14px 16px;margin-bottom:24px;text-align:left;
}
.session-box .row{display:flex;justify-content:space-between;font-size:12px;padding:5px 0;}
.session-box .row .k{color:var(--ink-400);}
.session-box .row .v{font-weight:600;font-family:var(--font-mono);font-size:11.5px;}
.session-box .row + .row{border-top:1px dashed var(--line);}

.btn-back{
  width:100%;padding:13px;border:none;border-radius:10px;
  background:var(--teal-500);color:#fff;font-family:var(--font-body);
  font-size:14px;font-weight:700;cursor:pointer;transition:.15s;margin-bottom:14px;
}
.btn-back:hover{background:#187C71;}

.countdown{font-size:12px;color:var(--ink-400);}
.countdown b{font-family:var(--font-mono);color:var(--ink-600);}

.foot{margin-top:26px;font-size:11px;color:var(--ink-400);}
</style>
</head>
<body>

<div class="card">
  <div class="brand-row">
    <div class="brand-mark">SH</div>
    <div>
      <div class="name">SIMPEG-H</div>
      <div class="sub">BPTD Kelas II Lampung</div>
    </div>
  </div>

  <div class="check-ring"><span class="mark">✓</span></div>

  <h1>Kamu Sudah Keluar</h1>
  <p class="lead" id="leadText">Sesi kamu telah diakhiri dengan aman. Sampai jumpa lagi, <b><?php echo htmlspecialchars($nama_keluar); ?></b>.</p>

  <div class="session-box">
    <div class="row"><span class="k">Peran</span><span class="v" id="sessRole"><?php echo htmlspecialchars($role_keluar === 'pegawai' ? 'Pegawai' : 'Kasubbag TU'); ?></span></div>
    <div class="row"><span class="k">Waktu Keluar</span><span class="v" id="sessTime">—</span></div>
  </div>

  <button class="btn-back" onclick="goLogin()">Kembali ke Halaman Login</button>
  <div class="countdown">Otomatis dialihkan dalam <b id="counter">6</b> detik…</div>

  <div class="foot">© 2026 BPTD Kelas II Lampung · Sistem Informasi Kepegawaian</div>
</div>

<script>
/* =====================================================================
   Nama & peran sudah disuntikkan langsung dari PHP (lihat atas file
   ini) berdasarkan data session SEBELUM session_destroy() dijalankan —
   bukan lagi ditebak dari parameter ?from= seperti versi mockup awal.
   ===================================================================== */
document.getElementById('sessTime').textContent = new Date().toLocaleString('id-ID', {
  day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'
}) + ' WIB';

function goLogin(){ window.location.href = 'login.php'; }

let n = 6;
const counterEl = document.getElementById('counter');
const timer = setInterval(()=>{
  n -= 1;
  counterEl.textContent = n;
  if(n <= 0){
    clearInterval(timer);
    goLogin();
  }
}, 1000);
</script>

</body>
</html>
