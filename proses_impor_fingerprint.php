<?php
/**
 * proses_impor_fingerprint.php
 * ============================================================================
 * Pemrosesan berkas laporan mesin presensi biometric (.xls, .xlsx, .csv).
 * Jalur Utama Integrasi Mesin Fingerprint SIMPEG-H (W2).
 *
 * TAHAPAN EKSEKUSI:
 *   1. PRATINJAU (Preview):
 *      - Unggah berkas, validasi MIME/ekstensi/ukuran (maks 5MB).
 *      - Parsing berkas menggunakan PhpSpreadsheet.
 *      - Ekstraksi blok identitas (PIN, Nama), tanggal, dan waktu scan mentah.
 *      - Pemetaan identitas: zkteco_pin -> Nama exact match.
 *      - Rekonsiliasi awal (status versi mesin vs versi sistem SIMPEG-H).
 *      - Simpan hasil pratinjau ke sesi pengguna (tanpa menulis ke DB).
 *   2. KONFIRMASI (Confirm):
 *      - Validasi token pratinjau & CSRF.
 *      - Tulis rekaman scan mentah ke `log_mesin_fingerprint` (sumber 'impor_berkas').
 *      - Panggil `rekap_absensi_dari_log()` untuk setiap pegawai-tanggal.
 *      - Perbarui status di `status_integrasi_mesin`.
 *      - Bersihkan berkas temporer & sesi pratinjau.
 * ============================================================================
 */

session_start();
require_once __DIR__ . '/cek_sesi.php';
cek_role(['kasubbag', 'admin']);
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/fingerprint_rekap.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$action = $_POST['action'] ?? ($_GET['action'] ?? 'preview');

// ─── AKSI: BATAL ─────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    if (isset($_SESSION['impor_preview']['file_path']) && file_exists($_SESSION['impor_preview']['file_path'])) {
        @unlink($_SESSION['impor_preview']['file_path']);
    }
    unset($_SESSION['impor_preview']);
    header('Location: admin.php?view=impor_fingerprint');
    exit;
}

// ─── AKSI: PRATINJAU (PREVIEW) ────────────────────────────────────────────────
if ($action === 'preview') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: admin.php?view=impor_fingerprint');
        exit;
    }
    csrf_verify_or_die('admin.php?view=impor_fingerprint');

    // 1. Validasi Berkas Upload
    if (!isset($_FILES['berkas_laporan']) || $_FILES['berkas_laporan']['error'] !== UPLOAD_ERR_OK) {
        $err_code = $_FILES['berkas_laporan']['error'] ?? -1;
        header("Location: admin.php?view=impor_fingerprint&error=unggah_gagal&code=$err_code");
        exit;
    }

    $file = $_FILES['berkas_laporan'];
    $max_size = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $max_size) {
        header('Location: admin.php?view=impor_fingerprint&error=ukuran_terlalu_besar');
        exit;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
        header('Location: admin.php?view=impor_fingerprint&error=format_tidak_didukung');
        exit;
    }

    // Pengecekan MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'application/csv',
        'application/octet-stream', // fallback untuk beberapa format excel binary lama
    ];
    if (!in_array($mime, $allowed_mimes, true) && $ext !== 'csv') {
        header("Location: admin.php?view=impor_fingerprint&error=mime_tidak_valid&mime=" . urlencode($mime));
        exit;
    }

    // Simpan ke direktori uploads/temp dengan nama acak aman
    $temp_dir = __DIR__ . '/uploads/temp';
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }
    $target_temp = $temp_dir . '/impor_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $target_temp)) {
        header('Location: admin.php?view=impor_fingerprint&error=gagal_simpan_temp');
        exit;
    }

    // 2. Baca Seluruh Master Pegawai untuk Pencocokan Cepat di Memori
    $pegawai_by_pin  = [];
    $pegawai_by_nama = [];
    $res_pgw = mysqli_query($koneksi, "
        SELECT id, nip, nama, zkteco_pin, status_kepegawaian, metode_absen
        FROM pegawai
    ");
    while ($p = mysqli_fetch_assoc($res_pgw)) {
        if ($p['zkteco_pin'] !== null) {
            $pegawai_by_pin[(int) $p['zkteco_pin']] = $p;
        }
        $nama_clean = strtolower(preg_replace('/[^a-z0-9]/', '', $p['nama']));
        $pegawai_by_nama[$nama_clean] = $p;
    }

    // 3. Parsing Menggunakan PhpSpreadsheet
    try {
        if ($ext === 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        } else {
            $reader = IOFactory::createReaderForFile($target_temp);
        }
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        $spreadsheet = $reader->load($target_temp);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();
        $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

        $parsed_pegawai = [];      // Key: PIN
        $scans_mentah   = [];      // Array scan mentah untuk DB
        $rekonsiliasi   = [];      // Sampel perbandingan
        $anomali        = [];

        $current_pin  = null;
        $current_nama = null;
        $periode_bulan = null;
        $periode_tahun = null;

        for ($r = 1; $r <= $highestRow; $r++) {
            $colA = trim((string) $sheet->getCell([1, $r])->getValue());
            $colB = trim((string) $sheet->getCell([2, $r])->getValue());

            // Deteksi periode waktu (misal "Dari 01-06-2026 s/d 30-06-2026")
            $row_text = '';
            for ($c = 1; $c <= min(26, $highestColIdx); $c++) {
                $v = trim((string) $sheet->getCell([$c, $r])->getValue());
                if ($v !== '') $row_text .= ' ' . $v;
            }
            if (preg_match('/Dari\s+(\d{2})[-|\/](\d{2})[-|\/](\d{4})/i', $row_text, $m)) {
                $periode_bulan = (int) $m[2];
                $periode_tahun = (int) $m[3];
            }

            // Deteksi Header Identitas Pegawai (Format Laporan Detail Harian)
            if ($colB === 'No. ID' || $colA === 'No. ID') {
                $val_pin = $sheet->getCell([6, $r + 1])->getValue(); // Kolom F
                if ($val_pin === null || $val_pin === '') {
                    $val_pin = $sheet->getCell([2, $r + 1])->getValue();
                }
                $current_pin = (int) $val_pin;
            }

            if ($colA === 'Nama' || $colB === 'Nama') {
                $val_nama = trim((string) $sheet->getCell([6, $r + 1])->getValue()); // Kolom F
                if ($val_nama === '') {
                    $val_nama = trim((string) $sheet->getCell([2, $r + 1])->getValue());
                }
                $current_nama = $val_nama;

                // Daftarkan pegawai ke daftar pemetaan jika ada PIN
                if ($current_pin !== null && !isset($parsed_pegawai[$current_pin])) {
                    // Cari di database: 1. Berdasarkan zkteco_pin
                    $matched = $pegawai_by_pin[$current_pin] ?? null;

                    // 2. Fallback: Berdasarkan kecocokan nama persis (normalized)
                    if (!$matched && $current_nama !== '') {
                        $n_clean = strtolower(preg_replace('/[^a-z0-9]/', '', $current_nama));
                        $matched = $pegawai_by_nama[$n_clean] ?? null;
                    }

                    $parsed_pegawai[$current_pin] = [
                        'pin'         => $current_pin,
                        'nama_berkas' => $current_nama,
                        'terpetakan'  => ($matched !== null),
                        'pegawai_id'  => $matched['id'] ?? null,
                        'nip'         => $matched['nip'] ?? null,
                        'nama_db'     => $matched['nama'] ?? null,
                        'status_pgw'  => $matched['status_kepegawaian'] ?? null,
                    ];
                }
            }

            // Deteksi Baris Kehadiran Harian
            if ($colA !== '' && $colA !== 'Tanggal' && strpos($colA, 'Dari') === false && $current_pin !== null) {
                // Parsing Tanggal
                $tgl_iso = null;
                $raw_tgl = $sheet->getCell([1, $r])->getValue();

                if (is_numeric($raw_tgl)) {
                    // Excel Serial Date Number
                    try {
                        $dt = ExcelDate::excelToDateTimeObject((float) $raw_tgl);
                        $d = (int) $dt->format('d');
                        $m = (int) $dt->format('m');
                        $y = (int) $dt->format('Y');

                        // Koreksi anomali Excel day-month swap (jika locale US menukar hari dan bulan)
                        if ($periode_bulan !== null && $m !== $periode_bulan && $d === $periode_bulan) {
                            $tgl_iso = sprintf('%04d-%02d-%02d', $y, $d, $m); // tukar balik ($d jadi bulan periode, $m jadi tanggal)
                        } else {
                            $tgl_iso = $dt->format('Y-m-d');
                        }
                    } catch (\Throwable $e) {
                        $anomali[] = "Baris $r: Gagal membaca tanggal numerik ($raw_tgl)";
                    }
                } elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', trim((string) $raw_tgl), $matches_tgl)) {
                    // String format DD/MM/YYYY
                    $day = (int) $matches_tgl[1];
                    $mon = (int) $matches_tgl[2];
                    $yr  = (int) $matches_tgl[3];
                    $tgl_iso = sprintf('%04d-%02d-%02d', $yr, $mon, $day);
                }

                if ($tgl_iso) {
                    // Ambil Scan Masuk (Kolom N / index 14) dan Scan Keluar (Kolom P / index 16)
                    $raw_scan_in  = $sheet->getCell([14, $r])->getValue();
                    $raw_scan_out = $sheet->getCell([16, $r])->getValue();
                    $raw_terlambat = $sheet->getCell([17, $r])->getValue(); // Kolom Q

                    $jam_masuk_str = null;
                    $jam_pulang_str = null;

                    // Konversi Jam Masuk
                    if ($raw_scan_in !== null && $raw_scan_in !== '') {
                        if (is_numeric($raw_scan_in)) {
                            $sec = (int) round(((float) $raw_scan_in) * 86400);
                            $jam_masuk_str = sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
                        } elseif (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', trim((string) $raw_scan_in))) {
                            $jam_masuk_str = trim((string) $raw_scan_in);
                            if (strlen($jam_masuk_str) === 5) $jam_masuk_str .= ':00';
                        }
                    }

                    // Konversi Jam Keluar
                    if ($raw_scan_out !== null && $raw_scan_out !== '') {
                        if (is_numeric($raw_scan_out)) {
                            $sec = (int) round(((float) $raw_scan_out) * 86400);
                            $jam_pulang_str = sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
                        } elseif (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', trim((string) $raw_scan_out))) {
                            $jam_pulang_str = trim((string) $raw_scan_out);
                            if (strlen($jam_pulang_str) === 5) $jam_pulang_str .= ':00';
                        }
                    }

                    $pgw_mapped = $parsed_pegawai[$current_pin] ?? null;
                    $pgw_id = $pgw_mapped['pegawai_id'] ?? null;

                    // Rekam Scan Masuk mentah
                    if ($jam_masuk_str) {
                        $scans_mentah[] = [
                            'pin'         => $current_pin,
                            'waktu_scan'  => "$tgl_iso $jam_masuk_str",
                            'punch_state' => 0,
                            'pegawai_id'  => $pgw_id,
                            'tanggal'     => $tgl_iso
                        ];
                    }

                    // Rekam Scan Keluar mentah
                    if ($jam_pulang_str) {
                        $scans_mentah[] = [
                            'pin'         => $current_pin,
                            'waktu_scan'  => "$tgl_iso $jam_pulang_str",
                            'punch_state' => 1,
                            'pegawai_id'  => $pgw_id,
                            'tanggal'     => $tgl_iso
                        ];
                    }

                    // Tambahkan ke tabel sampel rekonsiliasi (maksimal 20 sampel)
                    if (($jam_masuk_str || $jam_pulang_str) && count($rekonsiliasi) < 20) {
                        // Hitung status SIMPEG-H
                        $status_simpeg = 'tepat_waktu';
                        if ($jam_masuk_str) {
                            $std = jam_masuk_standar($tgl_iso);
                            $menit = max(0, (int) round((strtotime("$tgl_iso $jam_masuk_str") - strtotime("$tgl_iso $std")) / 60));
                            if ($menit > TOLERANSI_STANDAR_MENIT) {
                                $status_simpeg = "terlambat ({$menit}m)";
                            }
                        }

                        $status_mesin = 'Tepat Waktu';
                        if (!empty($raw_terlambat) && $raw_terlambat > 0) {
                            $status_mesin = "Terlambat ({$raw_terlambat}m)";
                        }

                        $rekonsiliasi[] = [
                            'pin'           => $current_pin,
                            'nama'          => $current_nama,
                            'tanggal'       => $tgl_iso,
                            'jam_masuk'     => $jam_masuk_str ?: '—',
                            'jam_pulang'    => $jam_pulang_str ?: '—',
                            'status_mesin'  => $status_mesin,
                            'status_simpeg' => $status_simpeg,
                            'terpetakan'    => ($pgw_id !== null)
                        ];
                    }
                }
            }
        }

        // Hitung Statistik Pratinjau
        $total_pegawai = count($parsed_pegawai);
        $terpetakan_count = 0;
        foreach ($parsed_pegawai as $p) {
            if ($p['terpetakan']) $terpetakan_count++;
        }

        $tanggal_list = array_column($scans_mentah, 'tanggal');
        $tgl_min = !empty($tanggal_list) ? min($tanggal_list) : '-';
        $tgl_max = !empty($tanggal_list) ? max($tanggal_list) : '-';

        $token_preview = bin2hex(random_bytes(16));
        $_SESSION['impor_preview'] = [
            'token'            => $token_preview,
            'file_name'        => $file['name'],
            'file_path'        => $target_temp,
            'total_pegawai'    => $total_pegawai,
            'terpetakan_count' => $terpetakan_count,
            'belum_terpetakan' => $total_pegawai - $terpetakan_count,
            'total_scans'      => count($scans_mentah),
            'rentang_tanggal'  => ($tgl_min !== '-' ? "$tgl_min s/d $tgl_max" : '-'),
            'parsed_pegawai'   => array_values($parsed_pegawai),
            'scans_mentah'     => $scans_mentah,
            'rekonsiliasi'     => $rekonsiliasi,
            'anomali'          => $anomali,
            'waktu_upload'     => date('Y-m-d H:i:s'),
        ];

        header('Location: admin.php?view=impor_fingerprint&mode=preview');
        exit;

    } catch (\Throwable $e) {
        if (file_exists($target_temp)) {
            @unlink($target_temp);
        }
        error_log("Gagal mem-parsing berkas impor: " . $e->getMessage());
        header('Location: admin.php?view=impor_fingerprint&error=gagal_parsing&msg=' . urlencode($e->getMessage()));
        exit;
    }
}

// ─── AKSI: KONFIRMASI IMPOR (CONFIRM & WRITE DB) ─────────────────────────────
if ($action === 'confirm') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: admin.php?view=impor_fingerprint');
        exit;
    }
    csrf_verify_or_die('admin.php?view=impor_fingerprint');

    $token = $_POST['token_preview'] ?? '';
    $preview_data = $_SESSION['impor_preview'] ?? null;

    if (!$preview_data || $token === '' || !hash_equals($preview_data['token'], $token)) {
        header('Location: admin.php?view=impor_fingerprint&error=token_kedaluwarsa');
        exit;
    }

    $scans = $preview_data['scans_mentah'] ?? [];
    if (empty($scans)) {
        header('Location: admin.php?view=impor_fingerprint&error=tidak_ada_data_scan');
        exit;
    }

    $batch_id = 'impor_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $diterima = 0;
    $duplikat = 0;
    $pasangan_rekap = []; // Key: "pegawai_id|tanggal"

    // 1. Simpan Seluruh Scan Mentah ke log_mesin_fingerprint (Batch Chunks)
    $chunk_size = 300;
    for ($i = 0; $i < count($scans); $i += $chunk_size) {
        $chunk = array_slice($scans, $i, $chunk_size);
        $values_sql = [];
        $types = '';
        $params = [];

        foreach ($chunk as $s) {
            $pin = (int) $s['pin'];
            $waktu = $s['waktu_scan'];
            $punch = (int) $s['punch_state'];
            $pgw_id = !empty($s['pegawai_id']) ? (int) $s['pegawai_id'] : null;

            $stmt_ins = mysqli_prepare($koneksi, "
                INSERT IGNORE INTO log_mesin_fingerprint
                    (pin, waktu_scan, punch_state, verify_type, pegawai_id, sumber, batch_id, diterima_pada)
                VALUES
                    (?, ?, ?, 0, ?, 'impor_berkas', ?, NOW())
            ");
            mysqli_stmt_bind_param($stmt_ins, 'isiis', $pin, $waktu, $punch, $pgw_id, $batch_id);
            mysqli_stmt_execute($stmt_ins);

            if (mysqli_affected_rows($koneksi) > 0) {
                $diterima++;
                if ($pgw_id !== null) {
                    $tgl_scan = substr($waktu, 0, 10);
                    $pasangan_rekap["{$pgw_id}|{$tgl_scan}"] = [
                        'pegawai_id' => $pgw_id,
                        'tanggal'    => $tgl_scan
                    ];
                }
            } else {
                $duplikat++;
            }
        }
    }

    // 2. Rekapitulasi Data Absensi untuk Seluruh Pasangan Pegawai & Tanggal yang Terpengaruh
    $direkap = 0;
    foreach ($pasangan_rekap as $item) {
        $res_rekap = rekap_absensi_dari_log($koneksi, $item['pegawai_id'], $item['tanggal']);
        if ($res_rekap['sukses'] && in_array($res_rekap['aksi'], ['insert', 'update', 'upgrade_alpa'], true)) {
            $direkap++;
        }
    }

    // 3. Catat Jejak Audit
    $user_id = (int) ($_SESSION['user_id'] ?? 1);
    $catatan_audit = sprintf(
        "Impor laporan fingerprint '%s': %d scan diterima, %d duplikat, %d hari direkap (Batch: %s).",
        $preview_data['file_name'],
        $diterima,
        $duplikat,
        $direkap,
        $batch_id
    );
    if (strlen($catatan_audit) > 255) {
        $catatan_audit = substr($catatan_audit, 0, 252) . '...';
    }
    catat_audit($koneksi, 'log_mesin_fingerprint', 0, 'tambah', null, null, $user_id, $catatan_audit);

    // 4. Perbarui status_integrasi_mesin
    $stmt_status = mysqli_prepare($koneksi, "
        INSERT INTO status_integrasi_mesin (nama, terakhir_heartbeat, terakhir_sukses, jumlah_terakhir, pesan)
        VALUES ('impor_berkas', NOW(), NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
            terakhir_heartbeat = NOW(),
            terakhir_sukses    = NOW(),
            jumlah_terakhir    = VALUES(jumlah_terakhir),
            pesan              = VALUES(pesan)
    ");
    mysqli_stmt_bind_param($stmt_status, 'is', $diterima, $catatan_audit);
    mysqli_stmt_execute($stmt_status);

    // 5. Bersihkan Berkas Temporer & Sesi
    if (file_exists($preview_data['file_path'])) {
        @unlink($preview_data['file_path']);
    }
    unset($_SESSION['impor_preview']);

    // Redirect dengan ringkasan sukses
    $q = http_build_query([
        'view'     => 'impor_fingerprint',
        'sukses'   => 1,
        'batch_id' => $batch_id,
        'diterima' => $diterima,
        'duplikat' => $duplikat,
        'direkap'  => $direkap,
    ]);
    header("Location: admin.php?$q");
    exit;
}

header('Location: admin.php?view=impor_fingerprint');
exit;
