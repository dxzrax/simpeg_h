<?php
/**
 * admin_impor_fingerprint.php
 * ============================================================================
 * Tampilan antarmuka untuk Modul Impor Laporan Mesin Fingerprint (W2).
 * Di-include dari admin.php di dalam <section class="view" id="v-impor_fingerprint">.
 * ============================================================================
 */

// Pastikan hanya di-include dari admin.php yang sudah memuat sesi & otorisasi
if (!isset($koneksi)) {
    die('Akses langsung tidak diizinkan.');
}

// Ambil status integrasi impor berkas dari basis data
$stmt_stat = mysqli_prepare($koneksi, "
    SELECT terakhir_sukses, jumlah_terakhir, pesan
    FROM status_integrasi_mesin
    WHERE nama = 'impor_berkas'
    LIMIT 1
");
mysqli_stmt_execute($stmt_stat);
$status_impor = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_stat));

$is_preview = (($_GET['mode'] ?? '') === 'preview') && !empty($_SESSION['impor_preview']);
$preview = $is_preview ? $_SESSION['impor_preview'] : null;

$is_sukses = ($_GET['sukses'] ?? '') === '1';
$error_code = $_GET['error'] ?? '';
?>

<div class="topbar">
  <div>
    <h1>Impor Laporan Fingerprint (Jalur Utama)</h1>
    <p>Unggah berkas laporan mesin presensi (format <b>.xls</b>, <b>.xlsx</b>, atau <b>.csv</b>) untuk rekonsiliasi data kehadiran kantor secara akurat.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="admin.php?view=kantor" class="btn" style="text-decoration:none">← Kembali ke Rekap Kantor</a>
    <div class="date-pill">Periode Aktif · <?php echo date('F Y'); ?></div>
  </div>
</div>

<!-- Notifikasi Hasil Sukses -->
<?php if ($is_sukses): ?>
<div class="panel" style="border-left:4px solid var(--green-500);background:rgba(34,197,94,0.06);margin-bottom:20px;padding:16px 20px;">
  <div style="display:flex;align-items:center;gap:12px">
    <div style="font-size:24px">✅</div>
    <div>
      <b style="font-size:15px;color:var(--green-500)">Impor dan Rekonsiliasi Berkas Berhasil!</b>
      <div style="font-size:13px;color:var(--ink-400);margin-top:4px">
        Batch ID: <span class="mono" style="font-weight:600"><?php echo htmlspecialchars($_GET['batch_id'] ?? '-'); ?></span> | 
        Diterima: <b><?php echo (int) ($_GET['diterima'] ?? 0); ?></b> scan mentah | 
        Duplikat dilewati: <b><?php echo (int) ($_GET['duplikat'] ?? 0); ?></b> | 
        Absensi direkap: <b><?php echo (int) ($_GET['direkap'] ?? 0); ?></b> hari kerja pegawai.
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Notifikasi Galat -->
<?php if ($error_code): ?>
<div class="panel" style="border-left:4px solid var(--red-500);background:rgba(239,68,68,0.06);margin-bottom:20px;padding:16px 20px;">
  <b style="color:var(--red-500)">Terjadi Kesalahan Saat Mengimpor Berkas:</b>
  <div style="font-size:13px;color:var(--ink-400);margin-top:4px">
    <?php
      $err_map = [
          'unggah_gagal'          => 'Gagal mengunggah berkas ke server.',
          'ukuran_terlalu_besar'  => 'Ukuran berkas melebihi batas maksimal 5 MB.',
          'format_tidak_didukung' => 'Format berkas tidak didukung. Harap gunakan .xls, .xlsx, atau .csv.',
          'mime_tidak_valid'      => 'Tipe MIME berkas tidak dikenali sebagai spreadsheet.',
          'gagal_parsing'         => 'Gagal mem-parsing isi berkas: ' . htmlspecialchars($_GET['msg'] ?? ''),
          'token_kedaluwarsa'     => 'Sesi pratinjau telah kedaluwarsa. Silakan unggah ulang berkas Anda.',
          'tidak_ada_data_scan'   => 'Tidak ditemukan data scan yang dapat diimpor dari berkas ini.',
      ];
      echo $err_map[$error_code] ?? 'Kesalahan tidak diketahui (' . htmlspecialchars($error_code) . ').';
    ?>
  </div>
</div>
<?php endif; ?>

<!-- Kartu Status Operasional Integrasi Impor -->
<div class="kpi-grid" style="margin-bottom:24px">
  <div class="kpi">
    <div class="label">Jalur Integrasi Utama</div>
    <div class="val" style="font-size:18px">Impor Laporan Mesin</div>
    <div class="sub">Adapter Biometrik BPTD Lampung</div>
  </div>
  <div class="kpi">
    <div class="label">Terakhir Sukses Diimpor</div>
    <div class="val" style="font-size:16px" class="mono">
      <?php echo !empty($status_impor['terakhir_sukses']) ? date('d/m/Y H:i', strtotime($status_impor['terakhir_sukses'])) . ' WIB' : 'Belum Ada'; ?>
    </div>
    <div class="sub">Tercatat di status_integrasi_mesin</div>
  </div>
  <div class="kpi">
    <div class="label">Volume Terakhir</div>
    <div class="val"><?php echo (int) ($status_impor['jumlah_terakhir'] ?? 0); ?> <span style="font-size:14px;font-weight:400;color:var(--ink-400)">scan</span></div>
    <div class="sub">Scan mentah tersimpan di log</div>
  </div>
  <div class="kpi">
    <div class="label">Status Idempotensi</div>
    <div class="val" style="color:var(--green-500);font-size:18px">Terjamin (DB)</div>
    <div class="sub">UNIQUE KEY uq_pin_waktu</div>
  </div>
</div>

<?php if (!$is_preview): ?>
<!-- ======================================================================= -->
<!-- TAHAP 1: FORMULIR UNGGAH BERKAS                                         -->
<!-- ======================================================================= -->
<div class="panel">
  <div class="panel-head">
    <h3>Unggah Berkas Laporan Fingerprint</h3>
    <div class="actions">
      <span class="badge info">Format XLS, XLSX, CSV</span>
      <span class="badge warn">Maksimal 5 MB</span>
    </div>
  </div>
  
  <div style="padding:16px 0">
    <form action="proses_impor_fingerprint.php" method="POST" enctype="multipart/form-data">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="preview">

      <div style="border:2px dashed var(--ink-200);border-radius:10px;padding:36px;text-align:center;background:var(--bg-subtle, rgba(0,0,0,0.01));transition:all 0.2s;">
        <div style="font-size:48px;margin-bottom:12px">📂</div>
        <div style="font-size:16px;font-weight:600;margin-bottom:6px">Pilih Berkas Laporan Detail Harian</div>
        <div style="font-size:13px;color:var(--ink-400);margin-bottom:20px;max-width:540px;margin-left:auto;margin-right:auto;">
          Mendukung laporan ekspor standar software ZKTeco (kolom: <i>No. ID, Nama, Tanggal, Scan Masuk, Scan Keluar</i>). Berkas akan dipratinjau terlebih dahulu sebelum disimpan ke basis data.
        </div>
        
        <input type="file" name="berkas_laporan" id="berkas_laporan" accept=".xls,.xlsx,.csv" required style="display:inline-block;padding:8px 12px;background:#fff;border:1px solid var(--ink-200);border-radius:6px;font-size:13px;">
      </div>

      <div style="margin-top:20px;display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:12.5px;color:var(--ink-400)">
          💡 <b>Tip Keamanan:</b> Sistem secara otomatis menormalkan format tanggal dan menghitung ulang keterlambatan dengan aturan jam kerja resmi SIMPEG-H.
        </div>
        <button type="submit" class="btn primary" style="padding:10px 24px;font-size:14px">
          🔍 Pratinjau & Analisis Berkas
        </button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ======================================================================= -->
<!-- TAHAP 2: PRATINJAU & REKONSILIASI HASIL PARSING                          -->
<!-- ======================================================================= -->
<div class="panel" style="margin-bottom:20px;border-top:4px solid var(--brand, #2563eb);">
  <div class="panel-head">
    <div>
      <h3>Hasil Pratinjau: <?php echo htmlspecialchars($preview['file_name']); ?></h3>
      <div style="font-size:12px;color:var(--ink-400);margin-top:2px">Dianalisis pada <?php echo htmlspecialchars($preview['waktu_upload']); ?> WIB</div>
    </div>
    <div class="actions">
      <a href="proses_impor_fingerprint.php?action=cancel" class="btn" style="color:var(--red-500);text-decoration:none">✕ Batal & Ganti Berkas</a>
    </div>
  </div>

  <!-- 4 Kartu Statistik Hasil Parsing -->
  <div class="kpi-grid" style="margin:16px 0">
    <div class="kpi">
      <div class="label">Total Pegawai di Berkas</div>
      <div class="val"><?php echo (int) $preview['total_pegawai']; ?></div>
      <div class="sub">Blok identitas ditemukan</div>
    </div>
    <div class="kpi">
      <div class="label">Pegawai Terpetakan</div>
      <div class="val" style="color:var(--green-500)"><?php echo (int) $preview['terpetakan_count']; ?></div>
      <div class="sub"><?php echo (int) $preview['belum_terpetakan']; ?> belum terpetakan</div>
    </div>
    <div class="kpi">
      <div class="label">Rentang Tanggal</div>
      <div class="val" style="font-size:15px"><?php echo htmlspecialchars($preview['rentang_tanggal']); ?></div>
      <div class="sub">Periode laporan presensi</div>
    </div>
    <div class="kpi">
      <div class="label">Total Scan Mentah</div>
      <div class="val mono"><?php echo (int) $preview['total_scans']; ?></div>
      <div class="sub">Masuk & Keluar terdeteksi</div>
    </div>
  </div>

  <?php if (!empty($preview['belum_terpetakan']) && $preview['belum_terpetakan'] > 0): ?>
  <div style="margin-bottom:16px;padding:12px 16px;background:rgba(245,158,11,0.08);border-left:4px solid var(--amber-500);border-radius:4px;font-size:13px;">
    ⚠️ Terdapat <b><?php echo (int) $preview['belum_terpetakan']; ?> pegawai</b> di berkas yang PIN atau Namanya belum terdaftar di SIMPEG-H. Log scan mereka tetap akan disimpan dengan aman di <code>log_mesin_fingerprint</code> (pegawai_id NULL) dan dapat dipetakan ulang nanti.
  </div>
  <?php endif; ?>
</div>

<!-- Tabel Sampel Rekonsiliasi Aturan -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-head">
    <div>
      <h3>Sampel Rekonsiliasi Kehadiran (20 Baris Pertama)</h3>
      <div style="font-size:12px;color:var(--ink-400)">Perbandingan status kehadiran bawaan perangkat lunak mesin vs aturan resmi SIMPEG-H</div>
    </div>
    <div class="actions">
      <span class="badge ok">Aturan Terpusat</span>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th>Tanggal</th>
        <th>PIN</th>
        <th>Nama di Berkas</th>
        <th>Scan Masuk</th>
        <th>Scan Keluar</th>
        <th>Status Versi Mesin</th>
        <th>Status Versi SIMPEG-H</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($preview['rekonsiliasi'] as $rek): ?>
      <tr>
        <td class="mono"><?php echo htmlspecialchars($rek['tanggal']); ?></td>
        <td class="mono"><?php echo htmlspecialchars($rek['pin']); ?></td>
        <td>
          <b><?php echo htmlspecialchars($rek['nama']); ?></b>
          <?php if (!$rek['terpetakan']): ?>
            <span class="badge warn" style="font-size:10px;margin-left:4px">Belum Terpetakan</span>
          <?php endif; ?>
        </td>
        <td class="mono"><?php echo htmlspecialchars($rek['jam_masuk']); ?></td>
        <td class="mono"><?php echo htmlspecialchars($rek['jam_pulang']); ?></td>
        <td>
          <span class="badge <?php echo strpos(strtolower($rek['status_mesin']), 'terlambat') !== false ? 'bad' : 'ok'; ?>">
            <?php echo htmlspecialchars($rek['status_mesin']); ?>
          </span>
        </td>
        <td>
          <span class="badge <?php echo strpos(strtolower($rek['status_simpeg']), 'terlambat') !== false ? 'bad' : 'ok'; ?>">
            <?php echo htmlspecialchars($rek['status_simpeg']); ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Panel Aksi Konfirmasi Impor -->
<div class="panel" style="background:var(--bg-subtle, #f9fafb);border:1px solid var(--ink-200);text-align:right;padding:16px 20px;">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <div style="text-align:left">
      <b>Sudah memeriksa hasil pratinjau?</b>
      <div style="font-size:12.5px;color:var(--ink-400)">Klik tombol konfirmasi untuk menyimpan seluruh scan mentah dan memperbarui absensi harian pegawai.</div>
    </div>
    <div style="display:flex;gap:10px">
      <a href="proses_impor_fingerprint.php?action=cancel" class="btn" style="color:var(--red-500);text-decoration:none;padding:10px 18px">
        ✕ Batalkan
      </a>
      <form action="proses_impor_fingerprint.php" method="POST" style="margin:0">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="confirm">
        <input type="hidden" name="token_preview" value="<?php echo htmlspecialchars($preview['token']); ?>">
        <button type="submit" class="btn primary" style="padding:10px 26px;font-size:14px;background:var(--green-500);border-color:var(--green-500)">
          ✓ Konfirmasi & Proses Impor Sekarang
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
