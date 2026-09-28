<?php
/**
 * cetak_kecurigaan.php — lihat catatan pola cetak di cetak_kehadiran_seksi.php
 */
require 'cek_sesi.php';
cek_role(['kasubbag', 'admin']);
require 'koneksi.php';
require 'helpers.php';

$nama_bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$bulan_filter = isset($_GET['bulan']) ? max(1, min(12, (int) $_GET['bulan'])) : (int) date('n');
$tahun_filter = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');

$stmt = mysqli_prepare($koneksi, "
    SELECT a.tanggal, a.status_sinkronisasi, a.status_verifikasi, a.status_radius,
        a.is_mock_location, a.alasan_indikasi_spoofing, a.catatan_verifikasi,
        p.nama, p.jabatan, t.nama_lokasi
    FROM absensi a
    JOIN pegawai p ON p.id = a.pegawai_id
    LEFT JOIN titik_lokasi_tugas t ON t.id = a.titik_lokasi_id
    WHERE a.sumber_absen = 'geolocation'
      AND (a.status_verifikasi != 'otomatis_sah' OR a.status_sinkronisasi = 'tersinkron')
      AND MONTH(a.tanggal) = ? AND YEAR(a.tanggal) = ?
    ORDER BY a.tanggal
");
mysqli_stmt_bind_param($stmt, 'ii', $bulan_filter, $tahun_filter);
mysqli_stmt_execute($stmt);
$baris = mysqli_stmt_get_result($stmt);
$data = [];
while ($r = mysqli_fetch_assoc($baris)) { $data[] = $r; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Monitoring Kecurigaan — <?php echo $nama_bulan[$bulan_filter] . ' ' . $tahun_filter; ?></title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#14202B;max-width:900px;margin:24px auto;padding:0 20px}
  .kop{text-align:center;border-bottom:3px solid #14202B;padding-bottom:12px;margin-bottom:20px}
  .kop h1{font-size:16px;margin:0 0 2px}
  .kop p{margin:0;font-size:12px;color:#555}
  table{width:100%;border-collapse:collapse;margin-bottom:10px}
  th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;font-size:12px;vertical-align:top}
  th{background:#f0f2f4}
  .print-btn{margin:16px 0;padding:10px 18px;background:#1E8F82;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer}
  .foot{margin-top:30px;font-size:11px;color:#777;display:flex;justify-content:space-between}
  .st-ok{color:#2F9464;font-weight:700}
  .st-warn{color:#D89B3C;font-weight:700}
  .st-bad{color:#C24B4B;font-weight:700}
  @media print{ .print-btn{display:none} body{margin:0} }
</style>
</head>
<body>
  <button class="print-btn" onclick="window.print()">🖨️ Cetak / Simpan sebagai PDF</button>
  <div class="kop">
    <h1>LAPORAN MONITORING KECURIGAAN OFFLINE &amp; LOKASI</h1>
    <p>SIMPEG-H · BPTD Kelas II Lampung</p>
    <p>Periode: <?php echo $nama_bulan[$bulan_filter] . ' ' . $tahun_filter; ?></p>
  </div>

  <table>
    <tr><th>Pegawai</th><th>Tanggal</th><th>Lokasi</th><th>Indikasi</th><th>Status Verifikasi</th><th>Catatan</th></tr>
    <?php if (empty($data)): ?>
    <tr><td colspan="6" style="text-align:center;color:#888">Tidak ada data pada periode ini.</td></tr>
    <?php endif; ?>
    <?php foreach ($data as $r):
        $indikasi = [];
        if ($r['is_mock_location']) $indikasi[] = 'Indikasi lokasi palsu';
        if ($r['status_radius'] === 'luar_radius') $indikasi[] = 'Luar radius';
        if ($r['status_sinkronisasi'] === 'tersinkron') $indikasi[] = 'Offline → sinkron';
        $status_class = ['otomatis_sah' => '', 'perlu_verifikasi' => 'st-warn', 'disetujui' => 'st-ok', 'ditolak' => 'st-bad'][$r['status_verifikasi']];
        $status_label = ['otomatis_sah' => 'Otomatis Sah', 'perlu_verifikasi' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'][$r['status_verifikasi']];
    ?>
    <tr>
      <td><?php echo htmlspecialchars($r['nama']); ?><br><small><?php echo htmlspecialchars($r['jabatan'] ?? ''); ?></small></td>
      <td><?php echo tgl_id($r['tanggal']); ?></td>
      <td><?php echo htmlspecialchars($r['nama_lokasi'] ?? '—'); ?></td>
      <td><?php echo htmlspecialchars(implode(', ', $indikasi) ?: '—'); ?></td>
      <td class="<?php echo $status_class; ?>"><?php echo $status_label; ?></td>
      <td><?php echo htmlspecialchars($r['catatan_verifikasi'] ?? '—'); ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <div class="foot">
    <span>Dicetak oleh: <?php echo htmlspecialchars($_SESSION['nama'] ?? '-'); ?></span>
    <span>Dicetak pada: <?php echo tgl_id(date('Y-m-d')) . ' ' . date('H:i') . ' WIB'; ?></span>
  </div>
</body>
</html>
