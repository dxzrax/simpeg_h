<?php
/**
 * fingerprint_rekap.php
 * ============================================================================
 * Modul Rekonsiliasi & Rekapitulasi Data Scan Fingerprint ke Tabel Absensi.
 *
 * Menerapkan arsitektur:
 *   [Raw Log: log_mesin_fingerprint] ──► [rekap_absensi_dari_log()] ──► [absensi]
 *
 * ATURAN BISNIS (Konsisten dengan proses_input_manual_fingerprint.php & AUDIT W1):
 *   1. Idempotent: dijalankan berulang kali untuk pegawai & tanggal yang sama
 *      menghasilkan nilai absensi yang sama tanpa duplikasi/anomali.
 *   2. jam_masuk = MIN(waktu_scan) pada tanggal tersebut.
 *   3. jam_pulang = MAX(waktu_scan) HANYA jika selisih dengan tap awal >= ZK_MIN_MENIT_MASUK_PULANG (default 60 menit).
 *      Tidak mengandalkan tombol/punch_state mesin fisik.
 *   4. Hari libur (is_hari_kerja = false): log scan tetap tersimpan di tabel log mentah,
 *      namun baris absensi TIDAK dibuat.
 *   5. Proteksi sumber data:
 *      - Baris 'geolocation' TIDAK PERNAH ditimpa.
 *      - Baris dengan status_verifikasi 'disetujui'/'ditolak' manual TIDAK PERNAH ditimpa.
 *      - Baris 'sistem' berstatus 'alpa' otomatis di-upgrade menjadi 'fingerprint' (Bug B2 fixed).
 *      - Baris 'sistem' berstatus 'cuti'/'izin' tetap mempertahankan status cuti/izinnya.
 * ============================================================================
 */

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/helpers.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Rekap absensi harian pegawai dari data mentah log_mesin_fingerprint.
 *
 * @param mysqli $koneksi
 * @param int $pegawai_id
 * @param string $tanggal Format YYYY-MM-DD
 * @return array Status dan rincian proses rekap
 */
function rekap_absensi_dari_log(mysqli $koneksi, int $pegawai_id, string $tanggal): array {
    if ($pegawai_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['sukses' => false, 'aksi' => 'invalid_input', 'pesan' => 'Parameter pegawai_id atau tanggal tidak valid'];
    }

    // 1. Cek apakah tanggal adalah hari kerja resmi
    if (!is_hari_kerja($tanggal, $koneksi)) {
        return [
            'sukses' => true,
            'aksi'   => 'dilewati_hari_libur',
            'pesan'  => "Tanggal $tanggal bukan hari kerja resmi (libur akhir pekan / libur nasional). Log disimpan tanpa membuat baris absensi."
        ];
    }

    // 2. Ambil seluruh log scan mentah untuk pegawai pada tanggal tersebut
    $stmt_log = mysqli_prepare($koneksi, "
        SELECT waktu_scan, punch_state, verify_type, sumber
        FROM log_mesin_fingerprint
        WHERE pegawai_id = ? AND DATE(waktu_scan) = ?
        ORDER BY waktu_scan ASC
    ");
    mysqli_stmt_bind_param($stmt_log, 'is', $pegawai_id, $tanggal);
    mysqli_stmt_execute($stmt_log);
    $res_log = mysqli_stmt_get_result($stmt_log);
    $scans = [];
    while ($row = mysqli_fetch_assoc($res_log)) {
        $scans[] = $row;
    }

    if (empty($scans)) {
        return [
            'sukses' => true,
            'aksi'   => 'tidak_ada_log',
            'pesan'  => "Tidak ditemukan rekaman log mesin untuk pegawai ID $pegawai_id pada tanggal $tanggal."
        ];
    }

    // 3. Tentukan jam masuk dan jam pulang
    $waktu_awal_str  = $scans[0]['waktu_scan'];
    $waktu_akhir_str = $scans[count($scans) - 1]['waktu_scan'];

    $jam_masuk = date('H:i:s', strtotime($waktu_awal_str));
    $jam_pulang = null;

    $min_menit_pulang = defined('ZK_MIN_MENIT_MASUK_PULANG') ? ZK_MIN_MENIT_MASUK_PULANG : 60;
    $selisih_detik = strtotime($waktu_akhir_str) - strtotime($waktu_awal_str);

    if ($selisih_detik >= ($min_menit_pulang * 60)) {
        $jam_pulang = date('H:i:s', strtotime($waktu_akhir_str));
    }

    // 4. Hitung status kehadiran dan keterlambatan (aturan konsisten dengan proses_input_manual_fingerprint.php)
    $jam_masuk_standar_hari_ini = jam_masuk_standar($tanggal);
    $menit_terlambat = max(0, (int) round((strtotime("$tanggal $jam_masuk") - strtotime("$tanggal $jam_masuk_standar_hari_ini")) / 60));
    $toleransi = defined('TOLERANSI_STANDAR_MENIT') ? TOLERANSI_STANDAR_MENIT : 30;
    $status_kehadiran = ($menit_terlambat > $toleransi) ? 'terlambat' : 'tepat_waktu';

    $catatan = sprintf(
        "Sinkronisasi fingerprint (%d tap terdeteksi). Tap pertama %s%s.",
        count($scans),
        $jam_masuk,
        $jam_pulang ? ", tap terakhir $jam_pulang" : ""
    );

    // 5. Eksekusi penulisan ke tabel absensi dalam transaksi
    mysqli_begin_transaction($koneksi);
    try {
        $stmt_cur = mysqli_prepare($koneksi, "
            SELECT id, sumber_absen, status_kehadiran, status_verifikasi, jam_masuk, jam_pulang, catatan_verifikasi
            FROM absensi
            WHERE pegawai_id = ? AND tanggal = ?
            FOR UPDATE
        ");
        mysqli_stmt_bind_param($stmt_cur, 'is', $pegawai_id, $tanggal);
        mysqli_stmt_execute($stmt_cur);
        $cur = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cur));

        // Kasus A: Sudah ada presensi dari geolocation (GPS) -> Jangan ditimpa (proteksi bukti foto & koordinat)
        if ($cur && $cur['sumber_absen'] === 'geolocation') {
            mysqli_commit($koneksi);
            return [
                'sukses' => true,
                'aksi'   => 'dilewati_geolocation',
                'pesan'  => "Presensi sudah ada dari geolocation ($tanggal). Fingerprint diabaikan agar data GPS & foto tidak tertimpa."
            ];
        }

        // Kasus B: Sudah ada koreksi/keputusan manual admin ('disetujui' / 'ditolak') -> Jangan ditimpa
        if ($cur && in_array($cur['status_verifikasi'], ['disetujui', 'ditolak'], true)) {
            mysqli_commit($koneksi);
            return [
                'sukses' => true,
                'aksi'   => 'dilewati_koreksi_manual',
                'pesan'  => "Status verifikasi absensi sudah diset manual oleh admin ({$cur['status_verifikasi']}). Tidak ditimpa."
            ];
        }

        // Kasus C: Belum ada baris sama sekali -> INSERT
        if (!$cur) {
            $stmt_ins = mysqli_prepare($koneksi, "
                INSERT INTO absensi
                    (pegawai_id, tanggal, jam_masuk, jam_pulang, sumber_absen,
                     status_radius, status_kehadiran, menit_terlambat,
                     status_sinkronisasi, waktu_dibuat_perangkat, waktu_diterima_server,
                     status_verifikasi, diverifikasi_oleh, waktu_verifikasi, catatan_verifikasi)
                VALUES
                    (?, ?, ?, ?, 'fingerprint',
                     'tidak_berlaku', ?, ?,
                     'online', NULL, NOW(),
                     'otomatis_sah', NULL, NOW(), ?)
            ");
            mysqli_stmt_bind_param(
                $stmt_ins, 'issssis',
                $pegawai_id, $tanggal, $jam_masuk, $jam_pulang,
                $status_kehadiran, $menit_terlambat, $catatan
            );
            mysqli_stmt_execute($stmt_ins);
            mysqli_commit($koneksi);
            return [
                'sukses'           => true,
                'aksi'             => 'insert',
                'status_kehadiran' => $status_kehadiran,
                'jam_masuk'        => $jam_masuk,
                'jam_pulang'       => $jam_pulang,
                'menit_terlambat'  => $menit_terlambat,
                'pesan'            => "Baris absensi baru berhasil dibuat dari fingerprint."
            ];
        }

        // Kasus D: Baris ada dari 'sistem' berstatus 'alpa' -> UPGRADE jadi fingerprint (Fix Bug B2)
        if ($cur['sumber_absen'] === 'sistem' && $cur['status_kehadiran'] === 'alpa') {
            $catatan_upgrade = "Status alpa sistem diperbarui: terbukti hadir di fingerprint. " . $catatan;
            $stmt_upg = mysqli_prepare($koneksi, "
                UPDATE absensi
                SET jam_masuk           = ?,
                    jam_pulang          = ?,
                    sumber_absen        = 'fingerprint',
                    status_kehadiran    = ?,
                    menit_terlambat     = ?,
                    status_sinkronisasi = 'online',
                    status_verifikasi   = 'otomatis_sah',
                    diverifikasi_oleh   = NULL,
                    waktu_verifikasi    = NOW(),
                    catatan_verifikasi  = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param(
                $stmt_upg, 'sssisi',
                $jam_masuk, $jam_pulang, $status_kehadiran, $menit_terlambat, $catatan_upgrade, $cur['id']
            );
            mysqli_stmt_execute($stmt_upg);
            mysqli_commit($koneksi);
            return [
                'sukses'           => true,
                'aksi'             => 'upgrade_alpa',
                'status_kehadiran' => $status_kehadiran,
                'jam_masuk'        => $jam_masuk,
                'jam_pulang'       => $jam_pulang,
                'menit_terlambat'  => $menit_terlambat,
                'pesan'            => "Baris alpa sistem berhasil di-upgrade menjadi kehadiran fingerprint."
            ];
        }

        // Kasus E: Baris ada dari 'sistem' berstatus 'cuti', 'izin', atau 'dinas_luar' -> Pertahankan status izin
        if (in_array($cur['status_kehadiran'], ['cuti', 'izin', 'dinas_luar'], true)) {
            $catatan_tambahan = ($cur['catatan_verifikasi'] ? $cur['catatan_verifikasi'] . " | " : "") .
                                "Catatan: Pegawai juga melakukan tap fingerprint ($jam_masuk).";
            $stmt_cuti = mysqli_prepare($koneksi, "
                UPDATE absensi
                SET jam_masuk          = COALESCE(jam_masuk, ?),
                    jam_pulang         = COALESCE(jam_pulang, ?),
                    catatan_verifikasi = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt_cuti, 'sssi', $jam_masuk, $jam_pulang, $catatan_tambahan, $cur['id']);
            mysqli_stmt_execute($stmt_cuti);
            mysqli_commit($koneksi);
            return [
                'sukses'           => true,
                'aksi'             => 'pertahankan_izin',
                'status_kehadiran' => $cur['status_kehadiran'],
                'jam_masuk'        => $jam_masuk,
                'jam_pulang'       => $jam_pulang,
                'pesan'            => "Status {$cur['status_kehadiran']} dipertahankan, jam scan dicatat."
            ];
        }

        // Kasus F: Baris sebelumnya adalah 'fingerprint' otomatis -> UPDATE
        $stmt_upd = mysqli_prepare($koneksi, "
            UPDATE absensi
            SET jam_masuk           = ?,
                jam_pulang          = ?,
                status_kehadiran    = ?,
                menit_terlambat     = ?,
                catatan_verifikasi  = ?,
                status_sinkronisasi = 'online',
                status_verifikasi   = 'otomatis_sah',
                waktu_verifikasi    = NOW()
            WHERE id = ?
        ");
        mysqli_stmt_bind_param(
            $stmt_upd, 'sssisi',
            $jam_masuk, $jam_pulang, $status_kehadiran, $menit_terlambat, $catatan, $cur['id']
        );
        mysqli_stmt_execute($stmt_upd);
        mysqli_commit($koneksi);

        return [
            'sukses'           => true,
            'aksi'             => 'update',
            'status_kehadiran' => $status_kehadiran,
            'jam_masuk'        => $jam_masuk,
            'jam_pulang'       => $jam_pulang,
            'menit_terlambat'  => $menit_terlambat,
            'pesan'            => "Baris absensi fingerprint berhasil diperbarui."
        ];

    } catch (\Throwable $e) {
        mysqli_rollback($koneksi);
        error_log("rekap_absensi_dari_log error: " . $e->getMessage());
        return [
            'sukses' => false,
            'aksi'   => 'db_error',
            'pesan'  => 'Galat database saat rekap: ' . $e->getMessage()
        ];
    }
}

/**
 * Petakan ulang log mentah yang belum berelasi ke pegawai (pegawai_id IS NULL)
 * setelah PIN mesin dihubungkan ke data pegawai.
 *
 * @param mysqli $koneksi
 * @param int $pin PIN mesin
 * @return array
 */
function petakan_ulang_log_pin(mysqli $koneksi, int $pin): array {
    // 1. Cari pegawai_id
    $stmt = mysqli_prepare($koneksi, "SELECT id, nama FROM pegawai WHERE zkteco_pin = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $pin);
    mysqli_stmt_execute($stmt);
    $pegawai = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$pegawai) {
        return [
            'sukses' => false,
            'pesan'  => "PIN $pin belum dipetakan ke pegawai mana pun di tabel pegawai."
        ];
    }

    $pegawai_id = (int) $pegawai['id'];

    // 2. Hubungkan log mentah yang belum memiliki pegawai_id
    $stmt_upd = mysqli_prepare($koneksi, "
        UPDATE log_mesin_fingerprint
        SET pegawai_id = ?
        WHERE pin = ? AND pegawai_id IS NULL
    ");
    mysqli_stmt_bind_param($stmt_upd, 'ii', $pegawai_id, $pin);
    mysqli_stmt_execute($stmt_upd);
    $terpetakan = mysqli_affected_rows($koneksi);

    // 3. Ambil daftar tanggal unik yang terpengaruh
    $stmt_tgl = mysqli_prepare($koneksi, "
        SELECT DISTINCT DATE(waktu_scan) AS tanggal
        FROM log_mesin_fingerprint
        WHERE pegawai_id = ?
        ORDER BY tanggal ASC
    ");
    mysqli_stmt_bind_param($stmt_tgl, 'i', $pegawai_id);
    mysqli_stmt_execute($stmt_tgl);
    $res_tgl = mysqli_stmt_get_result($stmt_tgl);

    $direkap = 0;
    while ($r = mysqli_fetch_assoc($res_tgl)) {
        $hasil_rekap = rekap_absensi_dari_log($koneksi, $pegawai_id, $r['tanggal']);
        if ($hasil_rekap['sukses'] && in_array($hasil_rekap['aksi'], ['insert', 'update', 'upgrade_alpa'], true)) {
            $direkap++;
        }
    }

    return [
        'sukses'     => true,
        'pegawai_id' => $pegawai_id,
        'nama'       => $pegawai['nama'],
        'terpetakan' => $terpetakan,
        'direkap'    => $direkap,
        'pesan'      => "Berhasil memetakan $terpetakan log scan ke {$pegawai['nama']} dan merekap $direkap hari kerja."
    ];
}
