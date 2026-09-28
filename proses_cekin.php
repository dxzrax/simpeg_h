<?php
/**
 * proses_cekin.php
 * ---------------------------------------------------------------------
 * Menerima submit dari layar "Cek-in / Cek-out" di pegawai.php.
 * Ini file proses PALING kompleks di sistem ini karena menggabungkan:
 *   1. Radius geofence (rumus Haversine)
 *   2. Foto wajib (upload file) + ekstraksi EXIF sebagai sinyal tambahan
 *   3. Deteksi kasar indikasi lokasi palsu (heuristik, BUKAN mutlak) —
 *      multi-sinyal yang dirangkai: akurasi, kecepatan, koordinat
 *      berulang, dan ketidakcocokan EXIF foto
 *   4. Pencocokan jadwal dinas luar
 *   5. Status sinkronisasi offline-first
 *   6. Logika cek-in vs cek-out dalam satu baris tabel `absensi`
 *
 * Polanya TETAP SAMA dengan proses_login.php: terima -> validasi ->
 * proses -> redirect. Cuma langkah "proses"-nya lebih banyak di sini.
 * ---------------------------------------------------------------------
 */

session_start();
require 'koneksi.php';
require 'csrf.php';
require 'helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pegawai.php');
    exit;
}
csrf_verify_or_die('pegawai.php');
if (!isset($_SESSION['pegawai_id'])) {
    header('Location: login.php?error=sesi_habis');
    exit;
}

$pegawai_id = (int) $_SESSION['pegawai_id'];

/**
 * Rumus Haversine — menghitung jarak dua koordinat GPS di permukaan
 * bumi, hasilnya dalam meter. Ini dasar dari SEMUA logika geofence.
 */
function hitung_jarak_meter($lat1, $lng1, $lat2, $lng2) {
    $radius_bumi = 6371000; // meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $radius_bumi * $c;
}

/**
 * Konversi koordinat GPS format EXIF (derajat/menit/detik, tiap bagian
 * berupa pecahan rational "49/1") ke desimal biasa. $referensi = 'N'/'S'
 * untuk latitude, 'E'/'W' untuk longitude — menentukan tanda +/-.
 */
function exif_gps_ke_desimal($koordinat, $referensi) {
    if (!is_array($koordinat) || count($koordinat) !== 3) {
        return null;
    }
    $pecahan_ke_desimal = function ($bagian) {
        if (strpos((string) $bagian, '/') !== false) {
            [$pembilang, $penyebut] = explode('/', $bagian);
            $penyebut = (float) $penyebut;
            return $penyebut != 0 ? ((float) $pembilang / $penyebut) : 0.0;
        }
        return (float) $bagian;
    };
    $derajat = $pecahan_ke_desimal($koordinat[0]);
    $menit   = $pecahan_ke_desimal($koordinat[1]);
    $detik   = $pecahan_ke_desimal($koordinat[2]);
    $desimal = $derajat + ($menit / 60) + ($detik / 3600);
    if ($referensi === 'S' || $referensi === 'W') {
        $desimal *= -1;
    }
    return $desimal;
}

// =====================================================================
// 1. AMBIL INPUT DARI FORM (dikirim oleh JS di pegawai.php — lihat
//    catatan di bagian bawah file ini soal field apa saja yang wajib
//    dikirim dari sisi front-end)
// =====================================================================
$latitude  = isset($_POST['latitude'])    ? (float) $_POST['latitude']    : null;
$longitude = isset($_POST['longitude'])   ? (float) $_POST['longitude']   : null;
$akurasi   = isset($_POST['akurasi_gps']) ? (float) $_POST['akurasi_gps'] : null;

// 'online' / 'offline' — dikirim JS sesuai kondisi navigator.onLine saat tombol ditekan
$mode_cekin = (($_POST['mode'] ?? 'online') === 'offline') ? 'offline' : 'online';

// Waktu SAAT TOMBOL DITEKAN di perangkat (bukan waktu sampai di server) —
// inilah yang dianggap "waktu kehadiran resmi" sesuai konsep offline-first
$waktu_device_raw = $_POST['waktu_device'] ?? null;
$waktu_device      = $waktu_device_raw ? date('Y-m-d H:i:s', strtotime($waktu_device_raw)) : date('Y-m-d H:i:s');
$tanggal_hari_ini  = date('Y-m-d', strtotime($waktu_device));
$jam_sekarang      = date('H:i:s', strtotime($waktu_device));

// Alasan keterlambatan — OPSIONAL, diisi pegawai sendiri lewat form kalau
// dia sadar sudah lewat toleransi standar (30 menit). Kalau kosong, tidak
// ada pembebasan potongan otomatis (lihat langkah 7b & 7 di bawah).
// mb_substr() (bukan substr() biasa) supaya alasan berkarakter multi-byte
// (é, akronim tak baku, dsb) tidak terpotong di tengah karakter.
$alasan_terlambat = trim((string) ($_POST['alasan_terlambat'] ?? ''));
$alasan_terlambat = $alasan_terlambat !== '' ? mb_substr($alasan_terlambat, 0, 255) : null;

if ($latitude === null || $longitude === null) {
    header('Location: pegawai.php?cekin=gagal_lokasi#absen');
    exit;
}

// =====================================================================
// 2. VALIDASI FOTO — tolak sebelum menyentuh disk sama sekali
// =====================================================================
// Menggunakan upload_helpers.php (validasi murni: tidak menulis file,
// tidak redirect). Urutan cek: skalar, error code, is_uploaded_file,
// ukuran aktual, MIME dari isi file, getimagesize anti-bomb.
// GAGAL-AMAN: jika ekstensi fileinfo tidak ada, tolak upload.
require_once __DIR__ . '/upload_helpers.php';

$hasil_validasi = validasi_upload_foto($_FILES['foto'] ?? []);
if (!$hasil_validasi['ok']) {
    // Catat ke error_log dengan detail (MIME terdeteksi, ukuran aktual, pegawai_id).
    // Nama file dari klien disanitasi sebelum dicatat, tidak pernah ditulis ke disk.
    $nama_log   = preg_replace('/[^\w.\-]/', '_', (string) ($_FILES['foto']['name'] ?? ''));
    $mime_log   = $hasil_validasi['mime'] ?? 'tidak_diketahui';
    $ukuran_log = isset($_FILES['foto']['tmp_name'])
        ? (@filesize($_FILES['foto']['tmp_name']) ?: 0)
        : 0;
    error_log(sprintf(
        'proses_cekin: validasi foto gagal [kode=%s, pegawai_id=%d, MIME_terdeteksi=%s, ukuran=%d, nama_sanitasi=%s]',
        $hasil_validasi['kode'], $pegawai_id, $mime_log, $ukuran_log, $nama_log
    ));
    header('Location: pegawai.php?cekin=' . $hasil_validasi['kode'] . '#absen');
    exit;
}

// =====================================================================
// 2b. EKSTRAKSI METADATA EXIF DARI FILE TMP — SEBELUM re-encode GD
//     (Re-encode via GD membuang semua EXIF, jadi harus dibaca dari tmp.)
//     Variabel $exif_lat, $exif_lon, $exif_waktu, $metadata_exif_json
//     dipakai oleh langkah 5 (deteksi spoofing) dan INSERT di langkah 8.
//     Gagal baca EXIF tidak menggagalkan cek-in.
// =====================================================================
$metadata_exif_json = null;
$exif_lat   = null;
$exif_lon   = null;
$exif_waktu = null;
$tmp_foto   = $_FILES['foto']['tmp_name'];   // alias untuk keterbacaan kode

if (function_exists('exif_read_data') && $hasil_validasi['mime'] === 'image/jpeg') {
    // Baca dari tmp (bukan dari disk tujuan — foto belum disimpan di sini)
    $exif_mentah = @exif_read_data($tmp_foto, 'ANY_TAG', true);
    if ($exif_mentah !== false) {
        if (isset($exif_mentah['GPS']['GPSLatitude'], $exif_mentah['GPS']['GPSLatitudeRef'])) {
            $exif_lat = exif_gps_ke_desimal($exif_mentah['GPS']['GPSLatitude'], $exif_mentah['GPS']['GPSLatitudeRef']);
        }
        if (isset($exif_mentah['GPS']['GPSLongitude'], $exif_mentah['GPS']['GPSLongitudeRef'])) {
            $exif_lon = exif_gps_ke_desimal($exif_mentah['GPS']['GPSLongitude'], $exif_mentah['GPS']['GPSLongitudeRef']);
        }
        if (isset($exif_mentah['EXIF']['DateTimeOriginal'])) {
            $waktu_parsed = DateTime::createFromFormat('Y:m:d H:i:s', $exif_mentah['EXIF']['DateTimeOriginal']);
            if ($waktu_parsed !== false) {
                $exif_waktu = $waktu_parsed->format('Y-m-d H:i:s');
            }
        }
        $metadata_exif_json = json_encode([
            'kamera'     => $exif_mentah['IFD0']['Model'] ?? null,
            'waktu_foto' => $exif_waktu,
            'gps_lat'    => $exif_lat,
            'gps_lon'    => $exif_lon,
        ], JSON_UNESCAPED_UNICODE);
    }
}

// =====================================================================
// 2c. PERSIAPAN PENYIMPANAN FOTO — variabel, bukan tulis ke disk dulu.
//     Foto BELUM ditulis ke disk di sini. Penulisan ditunda ke tepat
//     sebelum INSERT (langkah 8) supaya cabang cek-out (~baris 382) dan
//     sudah_lengkap (~baris 392) tidak meninggalkan file yatim di disk.
//     Tidak ada unlink() yang diperlukan di dua cabang tersebut.
// =====================================================================
$folder_tujuan = __DIR__ . '/uploads/absensi/';
if (!is_dir($folder_tujuan)) {
    mkdir($folder_tujuan, 0755, true);
}
// Nama file: acak, ekstensi selalu .jpg (dari hasil deteksi, bukan dari klien)
$nama_file = 'absensi_' . $pegawai_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.jpg';
$path_tujuan = $folder_tujuan . $nama_file;
$foto_url    = 'uploads/absensi/' . $nama_file;



// =====================================================================
// 3. CARI TITIK LOKASI TUGAS TERDEKAT (radius geofence)
// =====================================================================
$hasil_titik = mysqli_query($koneksi, "
    SELECT id, latitude, longitude, radius_meter
    FROM titik_lokasi_tugas
    WHERE status != 'nonaktif'
");

$titik_id = null;
$jarak_terpilih = null;
$radius_titik = null;

while ($t = mysqli_fetch_assoc($hasil_titik)) {
    $jarak = hitung_jarak_meter($latitude, $longitude, (float) $t['latitude'], (float) $t['longitude']);
    if ($jarak_terpilih === null || $jarak < $jarak_terpilih) {
        $jarak_terpilih = $jarak;
        $titik_id       = (int) $t['id'];
        $radius_titik   = (float) $t['radius_meter'];
    }
}

$status_radius = 'tidak_berlaku';
if ($titik_id !== null) {
    $status_radius = ($jarak_terpilih <= $radius_titik) ? 'dalam_radius' : 'luar_radius';
}

// =====================================================================
// 4. COCOKKAN DENGAN JADWAL DINAS LUAR HARI INI (KF-27)
//    Catatan: versi ini baru mencocokkan TANGGAL & TITIK LOKASI, belum
//    memeriksa jam_mulai/jam_selesai secara presisi — cukup untuk
//    kebutuhan skripsi, bisa diperhalus lagi kalau pembimbing minta.
// =====================================================================
$status_jadwal   = 'tidak_ada_jadwal';
$jadwal_dinas_id = null;

$stmt = mysqli_prepare($koneksi, "
    SELECT id, titik_lokasi_id
    FROM jadwal_dinas_luar
    WHERE pegawai_id = ? AND ? BETWEEN tanggal_mulai AND tanggal_selesai
");
mysqli_stmt_bind_param($stmt, 'is', $pegawai_id, $tanggal_hari_ini);
mysqli_stmt_execute($stmt);
$hasil_jadwal = mysqli_stmt_get_result($stmt);

while ($j = mysqli_fetch_assoc($hasil_jadwal)) {
    $jadwal_dinas_id = (int) $j['id'];
    if ($titik_id !== null && (int) $j['titik_lokasi_id'] === $titik_id) {
        $status_jadwal = 'sesuai';
        break; // ketemu yang cocok persis, tidak perlu cek baris jadwal lain
    }
    $status_jadwal = 'diluar_jadwal';
}

// =====================================================================
// 5. DETEKSI KASAR INDIKASI LOKASI PALSU (KF-23/KF-28 — heuristik, BUKAN
//    pemblokiran mutlak, hanya menandai untuk ditinjau atasan). Sinyal
//    yang terpenuhi DIRANGKAI ke dalam array (bukan saling menimpa) —
//    kalau beberapa sinyal terpenuhi sekaligus, Kasubbag TU melihat
//    semuanya sekaligus di alasan_indikasi_spoofing, bukan cuma yang
//    terakhir dicek.
// =====================================================================
$sinyal_spoofing = [];

// a) Akurasi GPS yang "terlalu sempurna" (asli biasanya ada noise alami)
if ($akurasi !== null && $akurasi <= 1.0) {
    $sinyal_spoofing[] = 'Akurasi GPS tidak wajar (<= 1 meter)';
}

// b) Kecepatan perpindahan mustahil dibanding cek-in terakhir pegawai ini
$stmt = mysqli_prepare($koneksi, "
    SELECT latitude, longitude, waktu_dibuat_perangkat
    FROM absensi
    WHERE pegawai_id = ? AND latitude IS NOT NULL
    ORDER BY waktu_dibuat_perangkat DESC LIMIT 1
");
mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
mysqli_stmt_execute($stmt);
$absen_terakhir = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($absen_terakhir && $absen_terakhir['waktu_dibuat_perangkat']) {
    $jarak_prev    = hitung_jarak_meter($latitude, $longitude, (float) $absen_terakhir['latitude'], (float) $absen_terakhir['longitude']);
    $selisih_detik = abs(strtotime($waktu_device) - strtotime($absen_terakhir['waktu_dibuat_perangkat']));

    if ($selisih_detik > 0) {
        $kecepatan_kmh = ($jarak_prev / 1000) / ($selisih_detik / 3600);
        if ($kecepatan_kmh > 200) { // tidak ada moda transportasi wajar >200 km/jam di area tugas
            $sinyal_spoofing[] = 'Kecepatan perpindahan tidak wajar (~' . round($kecepatan_kmh) . ' km/jam)';
        }
    }
}

// c) Pola koordinat berulang — titik presisi tinggi yang SAMA PERSIS
//    muncul >3 kali sebelumnya BUKAN di titik lokasi tugas resmi (kalau
//    memang di titik tugas resmi, wajar koordinatnya mirip terus setiap
//    hari — itu bukan indikasi apa-apa, makanya dilewati kalau
//    dalam_radius)
if ($status_radius !== 'dalam_radius') {
    $lat_bulat = round($latitude, 5);  // ~1 meter presisi, toleransi noise GPS wajar
    $lon_bulat = round($longitude, 5);
    $stmt = mysqli_prepare($koneksi, "
        SELECT COUNT(*) AS jumlah FROM absensi
        WHERE pegawai_id = ? AND sumber_absen = 'geolocation'
          AND ROUND(latitude, 5) = ? AND ROUND(longitude, 5) = ?
    ");
    mysqli_stmt_bind_param($stmt, 'idd', $pegawai_id, $lat_bulat, $lon_bulat);
    mysqli_stmt_execute($stmt);
    $jumlah_koordinat_sama = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['jumlah'];

    if ($jumlah_koordinat_sama >= 3) { // ditambah cek-in kali ini -> total lebih dari 3
        $sinyal_spoofing[] = "Koordinat identik berulang {$jumlah_koordinat_sama}x sebelumnya, bukan titik tugas terdaftar";
    }
}

// d) Metadata EXIF foto (langkah 2b) tidak cocok dengan lokasi/waktu yang
//    disubmit — dibaca langsung dari file JPEG, independen dari apa pun
//    yang dikirim lewat form/JS.
if ($exif_lat !== null && $exif_lon !== null) {
    $jarak_exif = hitung_jarak_meter($latitude, $longitude, $exif_lat, $exif_lon);
    if ($jarak_exif > 500) { // toleransi 500m — GPS kamera vs browser wajar sedikit beda
        $sinyal_spoofing[] = 'Lokasi EXIF foto (~' . round($jarak_exif) . 'm dari koordinat submit) tidak cocok';
    }
}
if ($exif_waktu !== null) {
    $selisih_exif_detik = abs(strtotime($waktu_device) - strtotime($exif_waktu));
    if ($selisih_exif_detik > 900) { // toleransi 15 menit
        $sinyal_spoofing[] = 'Waktu pengambilan foto (EXIF) beda >15 menit dari waktu cek-in';
    }
}

$is_mock_location = !empty($sinyal_spoofing) ? 1 : 0;
$alasan_spoofing  = !empty($sinyal_spoofing) ? implode(' | ', $sinyal_spoofing) : null;

// ── [POIN 2] Baca flag liveness dari form (diisi JS setelah blink terdeteksi) ──
// liveness_passed = 1 → blink terdeteksi via face-api.js (verifikasi asli)
// liveness_passed = 0 → foto diambil manual (fallback) atau tidak ada JS
// Jika liveness TIDAK lulus (nilai 0) AND bukan mode offline, tambahkan
// ke sinyal spoofing supaya baris absensi masuk jalur perlu_verifikasi.
$liveness_passed = (isset($_POST['liveness_passed']) && $_POST['liveness_passed'] === '1') ? 1 : 0;
if (!$liveness_passed && $mode_cekin !== 'offline') {
    $sinyal_spoofing[] = 'Foto diambil tanpa verifikasi wajah (liveness tidak lulus)';
    $is_mock_location  = 1;
    $alasan_spoofing   = $alasan_spoofing
        ? $alasan_spoofing . ' | Foto diambil tanpa verifikasi wajah (liveness tidak lulus)'
        : 'Foto diambil tanpa verifikasi wajah (liveness tidak lulus)';
}
// ──────────────────────────────────────────────────────────────────────

// =====================================================================
// 6. TENTUKAN STATUS SINKRONISASI (offline-first, KF-25)
//    Kalau toggle "mode offline" aktif DI JS, ATAU waktu perangkat
//    terpaut jauh dari waktu server saat ini, anggap data ini sempat
//    "ngendon" dulu sebelum terkirim.
// =====================================================================
$selisih_ke_server   = abs(time() - strtotime($waktu_device));
$status_sinkronisasi = ($mode_cekin === 'offline' || $selisih_ke_server > 120) ? 'tersinkron' : 'online';

// =====================================================================
// 7b. TENTUKAN STATUS KEHADIRAN (tepat_waktu / terlambat) — jam masuk
//     standar sekarang beda per hari (Jumat 07:30, hari lain 07:00, lihat
//     jam_masuk_standar() di helpers.php), dengan toleransi dua tingkat:
//
//       0-30 menit   : tidak dianggap terlambat sama sekali (toleransi standar)
//       30-60 menit  : dianggap terlambat, TAPI kalau pegawai mengisi
//                      alasan_terlambat, baris ini ditandai perlu_verifikasi
//                      supaya Kasubbag TU bisa membebaskan potongannya
//                      (dipakai pola status_verifikasi yang sama seperti
//                      luar_radius/lokasi_palsu — lihat proses_verifikasi.php)
//       > 60 menit   : selalu terlambat, tidak ada jalur pembebasan potongan
//                      lewat alasan lagi, apa pun isian pegawai
// =====================================================================
$jam_masuk_standar_hari_ini = jam_masuk_standar($tanggal_hari_ini);
$selisih_menit_dari_standar = (int) round((strtotime($jam_sekarang) - strtotime($jam_masuk_standar_hari_ini)) / 60);
$menit_terlambat  = max(0, $selisih_menit_dari_standar);
$status_kehadiran = ($menit_terlambat > TOLERANSI_STANDAR_MENIT) ? 'terlambat' : 'tepat_waktu';

$terlambat_bisa_diajukan_alasan =
    $status_kehadiran === 'terlambat'
    && $menit_terlambat <= TOLERANSI_MAKSIMAL_MENIT
    && $alasan_terlambat !== null;

// =====================================================================
// 7. STATUS VERIFIKASI — otomatis sah kalau semuanya wajar, perlu
//    ditinjau atasan kalau luar radius, indikasi lokasi palsu, ATAU
//    terlambat 30-60 menit dengan alasan yang diajukan pegawai
// =====================================================================
$status_verifikasi = ($status_radius === 'luar_radius' || $is_mock_location || $terlambat_bisa_diajukan_alasan)
    ? 'perlu_verifikasi'
    : 'otomatis_sah';

// =====================================================================
// 8. CEK APAKAH PEGAWAI SUDAH CEK-IN HARI INI -> tentukan CEK-IN vs CEK-OUT
//    (satu tombol di pegawai.php dipakai untuk keduanya, sesuai desain awal)
// =====================================================================
$stmt = mysqli_prepare($koneksi, "SELECT id, jam_masuk, jam_pulang FROM absensi WHERE pegawai_id = ? AND tanggal = ?");
mysqli_stmt_bind_param($stmt, 'is', $pegawai_id, $tanggal_hari_ini);
mysqli_stmt_execute($stmt);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($existing && $existing['jam_masuk'] && !$existing['jam_pulang']) {
    // ===== CEK-OUT: tinggal update jam_pulang, tidak perlu insert baru =====
    $stmt = mysqli_prepare($koneksi, "UPDATE absensi SET jam_pulang = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $jam_sekarang, $existing['id']);
    mysqli_stmt_execute($stmt);

    header('Location: pegawai.php?cekin=cekout_berhasil#beranda');
    exit;
}

if ($existing) {
    // Sudah cek-in DAN cek-out hari ini -> tidak boleh dobel
    header('Location: pegawai.php?cekin=sudah_lengkap#beranda');
    exit;
}

// ===== CEK-IN BARU: insert baris absensi =====
// =====================================================================
// 2d. SIMPAN FOTO KE DISK — TERLAMBAT INTENTIONAL (late-write)
//     Kode sampai sini berarti:
//       a) validasi sudah lulus di langkah 2
//       b) cabang cek-out dan sudah_lengkap sudah EXIT di atas
//     Tidak ada file yatim yang mungkin tertinggal di uploads/.
//
//     Re-encode via GD membuang semua payload yang mungkin disisipkan
//     dalam file (polyglot JPEG/PHP). Ini adalah sanitasi konten.
//     Fallback ke move_uploaded_file() jika GD tidak tersedia.
// =====================================================================
$foto_tersimpan = false;
if (function_exists('imagecreatefromjpeg') && function_exists('imagecreatefromstring')) {
    // GD tersedia — re-encode untuk strip payload tersembunyi
    $raw_bytes = file_get_contents($tmp_foto);
    if ($raw_bytes !== false) {
        $gd = @imagecreatefromstring($raw_bytes);
        if ($gd !== false) {
            // Perkecil jika sisi terpanjang > 2560 px
            $lebar_asli  = imagesx($gd);
            $tinggi_asli = imagesy($gd);
            $maks_sisi   = 2560;
            if ($lebar_asli > $maks_sisi || $tinggi_asli > $maks_sisi) {
                $rasio = max($lebar_asli, $tinggi_asli) / $maks_sisi;
                $lebar_baru  = (int) round($lebar_asli  / $rasio);
                $tinggi_baru = (int) round($tinggi_asli / $rasio);
                if ($hasil_validasi['mime'] === 'image/png') {
                    // Ratakan ke latar putih (PNG mungkin transparan)
                    $kanvas = imagecreatetruecolor($lebar_baru, $tinggi_baru);
                    imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
                    imagecopyresampled($kanvas, $gd, 0, 0, 0, 0,
                        $lebar_baru, $tinggi_baru, $lebar_asli, $tinggi_asli);
                    imagedestroy($gd);
                    $gd = $kanvas;
                } else {
                    $kanvas = imagecreatetruecolor($lebar_baru, $tinggi_baru);
                    imagecopyresampled($kanvas, $gd, 0, 0, 0, 0,
                        $lebar_baru, $tinggi_baru, $lebar_asli, $tinggi_asli);
                    imagedestroy($gd);
                    $gd = $kanvas;
                }
            } elseif ($hasil_validasi['mime'] === 'image/png') {
                // PNG dimensi normal — ratakan ke latar putih sebelum encode JPEG
                $kanvas = imagecreatetruecolor($lebar_asli, $tinggi_asli);
                imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
                imagecopy($kanvas, $gd, 0, 0, 0, 0, $lebar_asli, $tinggi_asli);
                imagedestroy($gd);
                $gd = $kanvas;
            }

            // Encode ke JPEG kualitas 88 — selalu output .jpg
            if (imagejpeg($gd, $path_tujuan, 88)) {
                chmod($path_tujuan, 0644);
                $foto_tersimpan = true;
            }
            imagedestroy($gd);
        }
    }
}

if (!$foto_tersimpan) {
    // GD tidak tersedia ATAU decode gagal (file korup tidak lolos GD meski
    // lulus validasi awal — mungkin terjadi pada JPEG tak standar).
    if (!function_exists('imagecreatefromjpeg')) {
        // Fallback: GD memang tidak ada — move_uploaded_file, log peringatan
        error_log('proses_cekin: GD tidak tersedia, fallback ke move_uploaded_file (pegawai_id=' . $pegawai_id . ')');
        if (!move_uploaded_file($tmp_foto, $path_tujuan)) {
            // Fallback pun gagal — tolak cek-in
            error_log('proses_cekin: move_uploaded_file gagal (pegawai_id=' . $pegawai_id . ')');
            header('Location: pegawai.php?cekin=gagal_isi_foto#absen');
            exit;
        }
        chmod($path_tujuan, 0644);
    } else {
        // GD ada tapi decode gagal — file korup / tidak bisa diproses GD
        error_log('proses_cekin: GD decode gagal (pegawai_id=' . $pegawai_id . ')');
        header('Location: pegawai.php?cekin=gagal_isi_foto#absen');
        exit;
    }
}

// Catatan: seluruh parameter di-bind sebagai tipe 's' (string) supaya
// sederhana — MySQL otomatis mengonversi ke tipe kolom asli (int/desimal)
// saat insert. Nilai NULL tetap terkirim sebagai NULL apa pun tipe char-nya.

$stmt = mysqli_prepare($koneksi, "
    INSERT INTO absensi
        (pegawai_id, tanggal, jam_masuk, sumber_absen,
         latitude, longitude, akurasi_gps_meter,
         titik_lokasi_id, jarak_dari_titik_meter, status_radius, status_kehadiran, menit_terlambat, alasan_terlambat,
         foto_url, metadata_exif, is_mock_location, alasan_indikasi_spoofing,
         status_sinkronisasi, waktu_dibuat_perangkat, waktu_diterima_server,
         jadwal_dinas_id, status_jadwal, status_verifikasi)
    VALUES
        (?, ?, ?, 'geolocation',
         ?, ?, ?,
         ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, NOW(),
         ?, ?, ?)
");
mysqli_stmt_bind_param(
    $stmt,
    str_repeat('s', 21),
    $pegawai_id, $tanggal_hari_ini, $jam_sekarang,
    $latitude, $longitude, $akurasi,
    $titik_id, $jarak_terpilih, $status_radius, $status_kehadiran, $menit_terlambat, $alasan_terlambat,
    $foto_url, $metadata_exif_json, $is_mock_location, $alasan_spoofing,
    $status_sinkronisasi, $waktu_device,
    $jadwal_dinas_id, $status_jadwal, $status_verifikasi
);
mysqli_stmt_execute($stmt);
$absensi_id = mysqli_insert_id($koneksi);

// =====================================================================
// 9. SIMPAN METADATA PERANGKAT (KF-29) — pelengkap, bukan tabel utama
// =====================================================================
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$jenis_perangkat = 'Desktop';
if (stripos($user_agent, 'android') !== false) {
    $jenis_perangkat = 'Android';
} elseif (stripos($user_agent, 'iphone') !== false || stripos($user_agent, 'ipad') !== false) {
    $jenis_perangkat = 'iOS';
}
$browser        = 'Browser';
$versi_aplikasi = '1.0';

$stmt = mysqli_prepare($koneksi, "
    INSERT INTO metadata_perangkat (absensi_id, jenis_perangkat, browser, versi_aplikasi, mode_saat_cekin)
    VALUES (?, ?, ?, ?, ?)
");
mysqli_stmt_bind_param($stmt, 'issss', $absensi_id, $jenis_perangkat, $browser, $versi_aplikasi, $mode_cekin);
mysqli_stmt_execute($stmt);

// =====================================================================
// 10. REDIRECT — pegawai.php membaca ?cekin=... untuk menampilkan toast
// =====================================================================
$pesan = ($status_verifikasi === 'perlu_verifikasi') ? 'berhasil_perlu_verifikasi' : 'berhasil';
header("Location: pegawai.php?cekin=$pesan#beranda");
exit;

/**
 * =======================================================================
 * STATUS: form Cek-in di pegawai.php sudah wired sepenuhnya ke file ini
 * (geolocation, kamera langsung via capture="environment", watermark
 * koordinat di foto) — bukan mockup lagi.
 *
 * Kalau pegawai mengisi alasan_terlambat lewat form (muncul kondisional
 * di pegawai.php saat JS mendeteksi keterlambatan 30-60 menit), baris
 * ini otomatis masuk antrean Monitoring Kecurigaan di admin.php sama
 * seperti kasus luar_radius/lokasi_palsu — Kasubbag TU yang memutuskan
 * disetujui (potongan dibebaskan) atau ditolak (potongan tetap berlaku)
 * lewat proses_verifikasi.php, tanpa perlu alur terpisah.
 * =======================================================================
 */
