<?php
/**
 * cetak_spj_tukin.php — lihat catatan pola cetak di cetak_kehadiran_seksi.php
 */
require 'cek_sesi.php';
cek_role(['kasubbag', 'admin']);
require 'koneksi.php';
require 'helpers.php';

$nama_bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$bulan_filter = isset($_GET['bulan']) ? max(1, min(12, (int) $_GET['bulan'])) : (int) date('n');
$tahun_filter = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');

$stmt = mysqli_prepare($koneksi, "
    SELECT t.*, p.nama, p.nip, p.jabatan
    FROM tukin t JOIN pegawai p ON p.id = t.pegawai_id
    WHERE t.periode_bulan = ? AND t.periode_tahun = ?
    ORDER BY p.nama
");
mysqli_stmt_bind_param($stmt, 'ii', $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$baris = mysqli_stmt_get_result($stmt);
$data = []; $total_pokok = 0; $total_potongan = 0; $total_diterima = 0;
while ($r = mysqli_fetch_assoc($baris)) {
    $data[] = $r;
    $total_pokok += (float) $r['tukin_pokok'];
    $total_potongan += (float) $r['total_potongan'];
    $total_diterima += (float) $r['tukin_diterima'];
}
$semua_final = !empty($data) && count(array_filter($data, fn($r) => $r['status'] === 'final')) === count($data);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>SPJ Tunjangan Kinerja — <?php echo $nama_bulan[$bulan_filter] . ' ' . $tahun_filter; ?></title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#14202B;max-width:850px;margin:24px auto;padding:0 20px}
  .kop{text-align:center;border-bottom:3px solid #14202B;padding-bottom:12px;margin-bottom:20px}
  .kop h1{font-size:16px;margin:0 0 2px}
  .kop p{margin:0;font-size:12px;color:#555}
  .status-note{text-align:center;margin-bottom:14px;font-size:12px;padding:8px;border-radius:6px}
  .status-note.final{background:#E7F4EE;color:#2F9464}
  .status-note.draft{background:#FBF0DE;color:#A9761C}
  table{width:100%;border-collapse:collapse;margin-bottom:10px}
  th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;font-size:12.5px}
  th{background:#f0f2f4}
  td.num{text-align:right;font-variant-numeric:tabular-nums}
  .total-row{font-weight:700;background:#f7f9fa}
  .print-btn{margin:16px 0;padding:10px 18px;background:#1E8F82;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer}
  .foot{margin-top:30px;font-size:11px;color:#777;display:flex;justify-content:space-between}
  .sign{margin-top:50px;display:flex;justify-content:flex-end}
  .sign div{text-align:center;width:220px}
  .sign .line{margin-top:60px;border-top:1px solid #333}
  @media print{ .print-btn{display:none} body{margin:0} }
</style>
</head>
<body>
  <button class="print-btn" onclick="window.print()">🖨️ Cetak / Simpan sebagai PDF</button>
  <div class="kop">
    <h1>SURAT PERTANGGUNGJAWABAN (SPJ)<br>TUNJANGAN KINERJA PEGAWAI</h1>
    <p>SIMPEG-H · BPTD Kelas II Lampung</p>
    <p>Periode: <?php echo $nama_bulan[$bulan_filter] . ' ' . $tahun_filter; ?></p>
  </div>

  <?php if (!empty($data)): ?>
  <div class="status-note <?php echo $semua_final ? 'final' : 'draft'; ?>">
    Status: <?php echo $semua_final ? 'FINAL — sudah dikunci Kasubbag TU' : 'DRAFT — belum difinalisasi, angka masih bisa berubah'; ?>
  </div>
  <?php endif; ?>

  <table>
    <tr><th>Pegawai</th><th>NIP</th><th>Tukin Pokok</th><th>Hari Efektif</th><th>Potongan</th><th>Diterima</th></tr>
    <?php if (empty($data)): ?>
    <tr><td colspan="6" style="text-align:center;color:#888">Belum ada rekap tukin untuk periode ini.</td></tr>
    <?php endif; ?>
    <?php foreach ($data as $r): ?>
    <tr>
      <td><?php echo htmlspecialchars($r['nama']); ?><br><small><?php echo htmlspecialchars($r['jabatan'] ?? ''); ?></small></td>
      <td><?php echo htmlspecialchars($r['nip']); ?></td>
      <td class="num"><?php echo number_format($r['tukin_pokok'], 0, ',', '.'); ?></td>
      <td class="num"><?php echo (int) $r['hari_kerja_efektif']; ?></td>
      <td class="num"><?php echo number_format($r['total_potongan'], 0, ',', '.'); ?></td>
      <td class="num"><?php echo number_format($r['tukin_diterima'], 0, ',', '.'); ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!empty($data)): ?>
    <tr class="total-row">
      <td colspan="2">Total</td>
      <td class="num"><?php echo number_format($total_pokok, 0, ',', '.'); ?></td>
      <td></td>
      <td class="num"><?php echo number_format($total_potongan, 0, ',', '.'); ?></td>
      <td class="num"><?php echo number_format($total_diterima, 0, ',', '.'); ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <div class="sign">
    <div>
      <div>Kasubbag Tata Usaha</div>
      <div class="line"></div>
      <div><?php echo htmlspecialchars($_SESSION['nama'] ?? '-'); ?></div>
    </div>
  </div>

  <div class="foot">
    <span>Dicetak oleh: <?php echo htmlspecialchars($_SESSION['nama'] ?? '-'); ?></span>
    <span>Dicetak pada: <?php echo tgl_id(date('Y-m-d')) . ' ' . date('H:i') . ' WIB'; ?></span>
  </div>
</body>
</html>
