<?php
/**
 * cetak_kehadiran_seksi.php
 * ---------------------------------------------------------------------
 * "Ekspor PDF" di sistem ini TIDAK memakai pustaka PDF pihak ketiga
 * (FPDF/TCPDF/dompdf) — pustaka itu perlu instalasi lewat Composer yang
 * belum tentu tersedia di semua setup Laragon/XAMPP. Sebagai gantinya,
 * halaman ini adalah laporan HTML biasa yang dirancang rapi untuk
 * dicetak: buka di tab baru, klik "Cetak / Simpan sebagai PDF", lalu
 * pilih "Save as PDF" di dialog print browser. Hasilnya PDF asli,
 * tanpa dependency tambahan apa pun.
 * ---------------------------------------------------------------------
 */
require 'cek_sesi.php';
cek_role(['kasubbag', 'admin']);
require 'koneksi.php';
require 'helpers.php';

$hari_ini_sql = date('Y-m-d');

$stmt = mysqli_prepare($koneksi, "
    SELECT s.nama_seksi,
        COUNT(p.id) AS total_pegawai,
        SUM(a.id IS NOT NULL) AS hadir,
        SUM(a.sumber_absen = 'fingerprint') AS via_fp,
        SUM(a.sumber_absen = 'geolocation') AS via_geo
    FROM seksi s
    LEFT JOIN pegawai p ON p.seksi_id = s.id AND p.status_kepegawaian = 'aktif'
    LEFT JOIN absensi a ON a.pegawai_id = p.id AND a.tanggal = ?
    GROUP BY s.id, s.nama_seksi
    ORDER BY s.nama_seksi
");
mysqli_stmt_bind_param($stmt, 's', $hari_ini_sql);
mysqli_stmt_execute($stmt);
$rekap = mysqli_stmt_get_result($stmt);

$total_pegawai = 0; $total_hadir = 0;
$baris = [];
while ($r = mysqli_fetch_assoc($rekap)) { $baris[] = $r; $total_pegawai += (int) $r['total_pegawai']; $total_hadir += (int) $r['hadir']; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Kehadiran per Seksi — <?php echo tgl_id($hari_ini_sql); ?></title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#14202B;max-width:800px;margin:24px auto;padding:0 20px}
  .kop{text-align:center;border-bottom:3px solid #14202B;padding-bottom:12px;margin-bottom:20px}
  .kop h1{font-size:16px;margin:0 0 2px}
  .kop p{margin:0;font-size:12px;color:#555}
  h2{font-size:14px;margin:18px 0 8px}
  table{width:100%;border-collapse:collapse;margin-bottom:10px}
  th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;font-size:12.5px}
  th{background:#f0f2f4}
  td.num{text-align:right;font-variant-numeric:tabular-nums}
  .total-row{font-weight:700;background:#f7f9fa}
  .print-btn{margin:16px 0;padding:10px 18px;background:#1E8F82;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer}
  .foot{margin-top:30px;font-size:11px;color:#777;display:flex;justify-content:space-between}
  @media print{ .print-btn{display:none} body{margin:0} }
</style>
</head>
<body>
  <button class="print-btn" onclick="window.print()">🖨️ Cetak / Simpan sebagai PDF</button>
  <div class="kop">
    <h1>LAPORAN KEHADIRAN PER SEKSI</h1>
    <p>SIMPEG-H · BPTD Kelas II Lampung</p>
    <p>Tanggal: <?php echo tgl_id($hari_ini_sql); ?></p>
  </div>

  <table>
    <tr><th>Seksi</th><th>Total Pegawai</th><th>Hadir</th><th>Fingerprint</th><th>Geolocation</th><th>Tingkat Hadir</th></tr>
    <?php foreach ($baris as $rk):
        $persen = ((int) $rk['total_pegawai'] > 0) ? round((int) $rk['hadir'] / (int) $rk['total_pegawai'] * 100) : 0;
    ?>
    <tr>
      <td><?php echo htmlspecialchars($rk['nama_seksi']); ?></td>
      <td class="num"><?php echo (int) $rk['total_pegawai']; ?></td>
      <td class="num"><?php echo (int) $rk['hadir']; ?></td>
      <td class="num"><?php echo (int) $rk['via_fp']; ?></td>
      <td class="num"><?php echo (int) $rk['via_geo']; ?></td>
      <td class="num"><?php echo $persen; ?>%</td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
      <td>Total</td><td class="num"><?php echo $total_pegawai; ?></td><td class="num"><?php echo $total_hadir; ?></td>
      <td colspan="2"></td><td class="num"><?php echo $total_pegawai > 0 ? round($total_hadir / $total_pegawai * 100) : 0; ?>%</td>
    </tr>
  </table>

  <div class="foot">
    <span>Dicetak oleh: <?php echo htmlspecialchars($_SESSION['nama'] ?? '-'); ?></span>
    <span>Dicetak pada: <?php echo tgl_id(date('Y-m-d')) . ' ' . date('H:i') . ' WIB'; ?></span>
  </div>
</body>
</html>
