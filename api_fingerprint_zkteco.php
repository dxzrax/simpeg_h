<?php
/**
 * api_fingerprint_zkteco.php
 * ============================================================================
 * Endpoint HTTP penerima payload log kehadiran dari daemon ZKTeco / mesin biometric.
 *
 * FITUR UTAMA (W1 Refactor):
 *   1. Menerima pengiriman batch (s/d 500 rekaman) maupun single-record lama.
 *   2. Autentikasi via header X-Daemon-Key yang dicocokkan dengan ZKTECO_API_KEY.
 *   3. Filter IP & opsi proteksi HTTPS dari config.php.
 *   4. Idempotensi tingkat database via INSERT IGNORE ke `log_mesin_fingerprint`.
 *   5. PIN tidak dikenal tetap disimpan ke log mentah (pegawai_id NULL) dan
 *      dilaporkan sebagai galat permanen (daemon tidak perlu retry loop).
 *   6. Otomatis merekapitulasi data scan ke tabel `absensi` via fingerprint_rekap.php.
 *   7. Menolak timestamp masa depan per rekaman secara parsial tanpa menggagalkan
 *      seluruh batch.
 *   8. Aksi ringan {"aksi": "heartbeat"} untuk memantau keaktifan daemon di status_integrasi_mesin.
 * ============================================================================
 */

// ─── Output & Buffer Clean ───────────────────────────────────────────────────
while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ─── Include Dependensi ──────────────────────────────────────────────────────
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/fingerprint_rekap.php';

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// ─── Fallback Konfigurasi Default ────────────────────────────────────────────
if (!defined('ZKTECO_API_KEY')) {
    define('ZKTECO_API_KEY', 'simpeg_h_zkteco_secret_key_demo_2026');
}
if (!defined('ZKTECO_ALLOWED_IPS')) {
    define('ZKTECO_ALLOWED_IPS', ['127.0.0.1', '::1', 'localhost']);
}
if (!defined('ZKTECO_WAJIB_HTTPS')) {
    define('ZKTECO_WAJIB_HTTPS', false);
}
if (!defined('ZK_TOLERANSI_MASA_DEPAN_DETIK')) {
    define('ZK_TOLERANSI_MASA_DEPAN_DETIK', 600); // 10 menit
}

// ─── Helper Response (Tanpa tipe 'never' untuk kompatibilitas PHP 8.0) ─────────
function send_json_ok(array $extra = []) {
    echo json_encode(array_merge(['sukses' => true], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function send_json_err(string $pesan, int $http = 400, array $extra = []) {
    http_response_code($http);
    echo json_encode(array_merge(['sukses' => false, 'pesan' => $pesan], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Validasi Protokol HTTP ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_json_err('Metode permintaan tidak diizinkan. Hanya menerima HTTP POST.', 405);
}

// Pengecekan HTTPS jika diwajibkan
if (ZKTECO_WAJIB_HTTPS) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if (!$is_https) {
        send_json_err('Endpoint mewajibkan koneksi aman HTTPS.', 403);
    }
}

// Pengecekan Whitelist IP
if (!empty(ZKTECO_ALLOWED_IPS)) {
    $caller_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowed = false;
    foreach (ZKTECO_ALLOWED_IPS as $pattern) {
        if ($pattern === $caller_ip || $pattern === 'localhost' && in_array($caller_ip, ['127.0.0.1', '::1'], true)) {
            $allowed = true;
            break;
        }
        // Pengecekan subnet CIDR sederhana (misal 192.168.1.0/24)
        if (strpos($pattern, '/') !== false) {
            list($subnet, $bits) = explode('/', $pattern);
            if (ip2long($caller_ip) !== false && ip2long($subnet) !== false) {
                $mask = -1 << (32 - (int) $bits);
                $subnet_long = ip2long($subnet) & $mask;
                if ((ip2long($caller_ip) & $mask) == $subnet_long) {
                    $allowed = true;
                    break;
                }
            }
        }
    }
    if (!$allowed) {
        error_log("api_fingerprint_zkteco: Akses ditolak dari IP: $caller_ip");
        send_json_err('Akses ditolak: IP pengirim tidak terdaftar dalam whitelist.', 403);
    }
}

// ─── Baca Body Request JSON ──────────────────────────────────────────────────
$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) {
    send_json_err('Body permintaan kosong.', 400);
}

$payload = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    send_json_err('Format payload JSON tidak valid: ' . json_last_error_msg(), 400);
}

// ─── Validasi Kunci Keamanan (X-Daemon-Key Header atau body) ───────────────────
$hdr_key = $_SERVER['HTTP_X_DAEMON_KEY'] ?? ($payload['api_key'] ?? '');
if (!hash_equals(ZKTECO_API_KEY, (string) $hdr_key)) {
    error_log("api_fingerprint_zkteco: Percobaan akses dengan API key tidak valid");
    send_json_err('Akses tidak terotorisasi: Kunci X-Daemon-Key tidak cocok.', 401);
}

// ─── Penanganan Aksi Heartbeat ───────────────────────────────────────────────
if (isset($payload['aksi']) && $payload['aksi'] === 'heartbeat') {
    $pesan_hb = isset($payload['pesan']) ? substr(trim($payload['pesan']), 0, 250) : 'Daemon aktif dan beroperasi normal';
    $stmt_hb = mysqli_prepare($koneksi, "
        INSERT INTO status_integrasi_mesin (nama, terakhir_heartbeat, terakhir_sukses, jumlah_terakhir, pesan)
        VALUES ('zk_socket', NOW(), NOW(), 0, ?)
        ON DUPLICATE KEY UPDATE
            terakhir_heartbeat = NOW(),
            pesan = VALUES(pesan)
    ");
    mysqli_stmt_bind_param($stmt_hb, 's', $pesan_hb);
    mysqli_stmt_execute($stmt_hb);

    send_json_ok([
        'pesan'       => 'Heartbeat berhasil diterima dan status integrasi diperbarui.',
        'server_time' => date('Y-m-d H:i:s')
    ]);
}

// ─── Normalisasi Rekaman (Mendukung Batch & Single-Record) ───────────────────
$records = [];
if (isset($payload['records']) && is_array($payload['records'])) {
    $records = $payload['records'];
} else {
    // Single record backward compatibility
    $records = [$payload];
}

if (count($records) > 500) {
    send_json_err('Maksimal pengiriman batch adalah 500 rekaman per permintaan.', 400);
}

$batch_id = $payload['batch_id'] ?? bin2hex(random_bytes(16));
$sekarang_ts = time();
$batas_depan_ts = $sekarang_ts + ZK_TOLERANSI_MASA_DEPAN_DETIK;

$diterima = 0;
$duplikat = 0;
$ditolak = [];
$pasangan_rekap = []; // Key: "$pegawai_id|$tanggal"

// ─── Cache Pemetaan zkteco_pin -> pegawai_id di Memori ────────────────────────
$cache_pin = [];
$res_pin = mysqli_query($koneksi, "SELECT id, zkteco_pin, nama, status_kepegawaian FROM pegawai WHERE zkteco_pin IS NOT NULL");
while ($row = mysqli_fetch_assoc($res_pin)) {
    $cache_pin[(int) $row['zkteco_pin']] = [
        'id'                 => (int) $row['id'],
        'nama'               => $row['nama'],
        'status_kepegawaian' => $row['status_kepegawaian'],
    ];
}

// ─── Proses Rekaman Satu per Satu ────────────────────────────────────────────
foreach ($records as $idx => $r) {
    // Ambil PIN
    $pin_str = trim((string) ($r['pin'] ?? ''));
    if ($pin_str === '' || !ctype_digit($pin_str)) {
        $ditolak[] = [
            'indeks'    => $idx,
            'pin'       => $pin_str,
            'alasan'    => "Field 'pin' wajib berupa angka positif",
            'permanen'  => true
        ];
        continue;
    }
    $pin_int = (int) $pin_str;

    // Normalisasi waktu_scan
    $waktu_scan = '';
    if (!empty($r['waktu_scan'])) {
        $waktu_scan = trim($r['waktu_scan']);
    } elseif (!empty($r['tanggal']) && !empty($r['jam'])) {
        $waktu_scan = trim($r['tanggal']) . ' ' . trim($r['jam']);
    } elseif (!empty($r['timestamp_iso'])) {
        $waktu_scan = date('Y-m-d H:i:s', strtotime($r['timestamp_iso']));
    }

    $scan_ts = strtotime($waktu_scan);
    if ($scan_ts === false) {
        $ditolak[] = [
            'indeks'    => $idx,
            'pin'       => $pin_int,
            'alasan'    => "Format waktu_scan tidak valid: '$waktu_scan'",
            'permanen'  => true
        ];
        continue;
    }

    // Pengecekan Timestamp Masa Depan (Tolak per rekaman)
    if ($scan_ts > $batas_depan_ts) {
        $ditolak[] = [
            'indeks'    => $idx,
            'pin'       => $pin_int,
            'waktu'     => $waktu_scan,
            'alasan'    => "Waktu scan berada di masa depan (> " . ZK_TOLERANSI_MASA_DEPAN_DETIK . " detik)",
            'permanen'  => true
        ];
        continue;
    }

    $waktu_scan_formatted = date('Y-m-d H:i:s', $scan_ts);
    $tanggal_scan = date('Y-m-d', $scan_ts);
    $punch_state = isset($r['punch']) ? (int) $r['punch'] : (isset($r['punch_state']) ? (int) $r['punch_state'] : null);
    $verify_type = isset($r['verify']) ? (int) $r['verify'] : (isset($r['verifikasi_metode']) ? (int) $r['verifikasi_metode'] : null);

    // Cari mapping pegawai
    $pegawai_id = null;
    if (isset($cache_pin[$pin_int])) {
        $pegawai_info = $cache_pin[$pin_int];
        if ($pegawai_info['status_kepegawaian'] === 'nonaktif') {
            $ditolak[] = [
                'indeks'    => $idx,
                'pin'       => $pin_int,
                'alasan'    => "Pegawai {$pegawai_info['nama']} (ID {$pegawai_info['id']}) berstatus nonaktif",
                'permanen'  => true
            ];
            // Simpan log mentah tetap dengan pegawai_id NULL/catatan agar jejak audit ada
            $pegawai_id = null;
        } else {
            $pegawai_id = $pegawai_info['id'];
        }
    } else {
        // PIN tak dikenal: tetap simpan ke log mentah dengan pegawai_id NULL
        $ditolak[] = [
            'indeks'    => $idx,
            'pin'       => $pin_int,
            'waktu'     => $waktu_scan_formatted,
            'alasan'    => "PIN $pin_int belum dipetakan ke pegawai. Tersimpan di log mentah.",
            'permanen'  => true
        ];
    }

    // Simpan ke log_mesin_fingerprint (Idempotent via INSERT IGNORE)
    $stmt_ins = mysqli_prepare($koneksi, "
        INSERT IGNORE INTO log_mesin_fingerprint
            (pin, waktu_scan, punch_state, verify_type, pegawai_id, sumber, batch_id, diterima_pada)
        VALUES
            (?, ?, ?, ?, ?, 'zk_socket', ?, NOW())
    ");
    mysqli_stmt_bind_param(
        $stmt_ins, 'isiiss',
        $pin_int, $waktu_scan_formatted, $punch_state, $verify_type, $pegawai_id, $batch_id
    );
    mysqli_stmt_execute($stmt_ins);

    if (mysqli_affected_rows($koneksi) > 0) {
        $diterima++;
        // Hanya daftarkan untuk rekap jika pegawai_id valid
        if ($pegawai_id !== null) {
            $kunci_rekap = "{$pegawai_id}|{$tanggal_scan}";
            $pasangan_rekap[$kunci_rekap] = [
                'pegawai_id' => $pegawai_id,
                'tanggal'    => $tanggal_scan,
            ];
        }
    } else {
        $duplikat++;
    }
}

// ─── Rekapitulasi Data Absensi ───────────────────────────────────────────────
$direkap = 0;
foreach ($pasangan_rekap as $item) {
    $res_rekap = rekap_absensi_dari_log($koneksi, $item['pegawai_id'], $item['tanggal']);
    if ($res_rekap['sukses'] && in_array($res_rekap['aksi'], ['insert', 'update', 'upgrade_alpa'], true)) {
        $direkap++;
    }
}

// ─── Perbarui Status Integrasi Mesin ─────────────────────────────────────────
$pesan_status = sprintf(
    "Batch %s: %d diterima, %d duplikat, %d ditolak, %d direkap.",
    substr($batch_id, 0, 8),
    $diterima,
    $duplikat,
    count($ditolak),
    $direkap
);

$stmt_status = mysqli_prepare($koneksi, "
    INSERT INTO status_integrasi_mesin (nama, terakhir_heartbeat, terakhir_sukses, jumlah_terakhir, pesan)
    VALUES ('zk_socket', NOW(), NOW(), ?, ?)
    ON DUPLICATE KEY UPDATE
        terakhir_heartbeat = NOW(),
        terakhir_sukses    = NOW(),
        jumlah_terakhir    = VALUES(jumlah_terakhir),
        pesan              = VALUES(pesan)
");
mysqli_stmt_bind_param($stmt_status, 'is', $diterima, $pesan_status);
mysqli_stmt_execute($stmt_status);

// ─── Kembalikan Respons JSON ─────────────────────────────────────────────────
send_json_ok([
    'diterima' => $diterima,
    'duplikat' => $duplikat,
    'ditolak'  => $ditolak,
    'direkap'  => $direkap,
    'batch_id' => $batch_id,
    'pesan'    => "Pemrosesan batch selesai: $diterima rekaman baru diterima, $duplikat duplikat dilewati."
]);
