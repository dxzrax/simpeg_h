<?php
/**
 * gen_password.php
 * ---------------------------------------------------------------------
 * PENTING: Semua hash password di db_simpeg_h.sql saat ini adalah
 * PLACEHOLDER ('$2y$10$PLACEHOLDERHASH...') — bukan hash asli.
 * password_verify() di proses_login.php TIDAK AKAN PERNAH cocok
 * dengan placeholder itu, jadi login pasti gagal sebelum kamu
 * jalankan file ini satu kali.
 *
 * CATATAN UNTUK FASE SELANJUTNYA: sekarang setelah menu Data Pegawai
 * di admin.php sudah tersambung (proses_tambah_pegawai.php /
 * proses_edit_pegawai.php), file ini SEBENARNYA hanya perlu dipakai
 * SEKALI di awal — untuk akun demo yang sudah ada duluan di seed data.
 * Pegawai baru & ganti password ke depannya cukup lewat menu Data
 * Pegawai (admin) atau Pengaturan (pegawai), tidak perlu file ini lagi.
 *
 * KEAMANAN: karena file ini mencetak password & hash, akses dibatasi
 * hanya untuk yang SUDAH LOGIN sebagai admin/kasubbag (lewat
 * cek_sesi.php) — bukan sembarang orang yang kebetulan tahu URL-nya.
 * Setelah dipakai, klik tombol "Hapus File Ini Sekarang" di bawah
 * supaya tidak tertinggal di server produksi.
 *
 * CARA PAKAI:
 * 1. Buka file ini (perlu login admin/kasubbag dulu).
 * 2. Salin hash yang muncul.
 * 3. Buka phpMyAdmin -> tabel `users` -> jalankan SQL berikut untuk
 *    SETIAP baris (ganti 'HASIL_HASH_DI_SINI' dan username-nya):
 *
 *      UPDATE users SET password = 'HASIL_HASH_DI_SINI' WHERE username = 'kasubbag.tu';
 *      UPDATE users SET password = 'HASIL_HASH_DI_SINI' WHERE username = 'bayu.kurniawan';
 *      -- ...ulangi untuk semua username lain
 *
 *    (Boleh pakai hash yang SAMA untuk semua akun demo, karena ini
 *    cuma untuk keperluan sidang/demo — bukan produksi sungguhan.)
 *
 * 4. Klik tombol di bawah untuk MENGHAPUS file ini dari server setelah
 *    selesai dipakai — jangan dibiarkan bisa diakses seterusnya.
 * ---------------------------------------------------------------------
 */

require 'cek_sesi.php';
cek_role(['kasubbag', 'admin']);

$sudah_dihapus = false;
if (($_POST['aksi'] ?? '') === 'hapus_file' && csrf_valid($_POST['csrf_token'] ?? null)) {
    $sudah_dihapus = @unlink(__FILE__);
}

$password_asli = 'password123'; // sesuai kredensial demo di login.html
$hash = password_hash($password_asli, PASSWORD_BCRYPT);
?>
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><title>Generator Password Demo</title>
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:40px auto;padding:0 20px;color:#14202B}
pre{background:#f0f2f4;padding:14px;border-radius:8px;font-size:13px;overflow-x:auto}
.warn{background:#FBEAEA;color:#C24B4B;padding:12px 14px;border-radius:8px;margin:16px 0;font-size:13px}
.ok{background:#E7F4EE;color:#2F9464;padding:12px 14px;border-radius:8px;margin:16px 0;font-size:13px}
button{padding:10px 16px;background:#C24B4B;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px}</style>
</head>
<body>
<h2>Generator Password Demo</h2>

<?php if ($sudah_dihapus): ?>
<div class="ok">✓ File <code>gen_password.php</code> berhasil dihapus dari server. Halaman ini tidak akan bisa dibuka lagi.</div>
<?php else: ?>

<pre>Password asli : <?php echo htmlspecialchars($password_asli); ?>
Hash bcrypt   : <?php echo htmlspecialchars($hash); ?></pre>
<p>Salin baris <b>Hash bcrypt</b> di atas, lalu tempelkan ke kolom <code>password</code> pada tabel <code>users</code> lewat phpMyAdmin (lihat contoh SQL di komentar bagian atas file ini).</p>

<div class="warn">⚠️ Jangan biarkan file ini tetap ada di server setelah selesai dipakai — file yang mencetak hash password tidak boleh bisa diakses terus-menerus.</div>

<form method="POST" onsubmit="return confirm('Hapus gen_password.php dari server sekarang? Aksi ini tidak bisa dibatalkan.');">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="aksi" value="hapus_file">
  <button type="submit">🗑️ Hapus File Ini Sekarang</button>
</form>
<?php endif; ?>
</body>
</html>

