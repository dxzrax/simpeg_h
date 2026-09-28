<?php
/**
 * helpers.php
 * ---------------------------------------------------------------------
 * date() bawaan PHP mencetak nama bulan dalam bahasa Inggris (May, Aug,
 * Oct, Dec) kecuali locale server di-setting khusus — yang di Laragon
 * default TIDAK di-set ke Indonesia. Daripada bergantung pada
 * setlocale() yang perlu paket bahasa tambahan di Windows/XAMPP/Laragon,
 * fungsi ini menerjemahkan manual supaya tanggal selalu tampil dengan
 * singkatan bulan Indonesia (Mei, Agu, Okt, Des) di mana pun aplikasi
 * di-deploy.
 * ---------------------------------------------------------------------
 */

function tgl_id(string $tanggal_sql, bool $dengan_tahun = true): string {
    $bulan_singkat = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $ts = strtotime($tanggal_sql);
    if ($ts === false) return $tanggal_sql;
    $hari  = date('d', $ts);
    $bulan = $bulan_singkat[(int) date('n', $ts)];
    return $dengan_tahun ? "$hari $bulan " . date('Y', $ts) : "$hari $bulan";
}

/**
 * ---------------------------------------------------------------------
 * Jam kerja & hari kerja BPTD Kelas II Lampung (hasil wawancara admin TU,
 * 31 Juli 2026): Senin-Kamis 07:00-16:30, Jumat 07:30-17:00, Sabtu-Minggu
 * libur. Toleransi keterlambatan 30 menit; 30-60 menit bisa dibebaskan
 * kalau pegawai memberi alasan dan disetujui Kasubbag TU (lihat
 * proses_cekin.php & proses_verifikasi.php); di atas 60 menit selalu
 * dianggap terlambat tanpa toleransi.
 *
 * Dipusatkan di sini (bukan konstanta lepas di tiap file) supaya
 * proses_cekin.php, cron_tandai_alpa.php, dan proses_hitung_tukin.php
 * selalu pakai aturan yang SAMA — sebelumnya jam masuk standar hardcode
 * terpisah di proses_cekin.php tanpa membedakan hari Jumat.
 * ---------------------------------------------------------------------
 */
const TOLERANSI_STANDAR_MENIT  = 30; // bebas potongan otomatis, tidak perlu alasan
const TOLERANSI_MAKSIMAL_MENIT = 60; // di atas toleransi standar, hanya bisa dibebaskan lewat alasan yang disetujui

// "Tukin" di sistem ini sebenarnya tunjangan makan — gaji pokok pegawai
// tetap dari pusat dan tidak disentuh sistem ini. Rp35.000/hari kerja
// efektif, sama rata untuk semua pegawai aktif (bukan angka tersimpan
// per pegawai) — lihat proses_hitung_tukin.php.
const TARIF_HARIAN_TUNJANGAN = 35000;

function jam_masuk_standar(string $tanggal_sql): string {
    $hari_dalam_minggu = (int) date('N', strtotime($tanggal_sql)); // 5 = Jumat
    return $hari_dalam_minggu === 5 ? '07:30:00' : '07:00:00';
}

function jam_pulang_standar(string $tanggal_sql): string {
    $hari_dalam_minggu = (int) date('N', strtotime($tanggal_sql));
    return $hari_dalam_minggu === 5 ? '17:00:00' : '16:30:00';
}

function is_hari_kerja(string $tanggal_sql, mysqli $koneksi): bool {
    $hari_dalam_minggu = (int) date('N', strtotime($tanggal_sql)); // 6=Sabtu, 7=Minggu
    if ($hari_dalam_minggu >= 6) return false;
    $stmt = mysqli_prepare($koneksi, "SELECT 1 FROM hari_libur WHERE tanggal = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $tanggal_sql);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) === null;
}

/**
 * Jumlah hari kerja efektif dalam satu bulan kalender: Senin-Jumat,
 * dikurangi tanggal merah (hari_libur) yang jatuh di hari kerja. Dipakai
 * sebagai basis tunjangan makan (Rp35.000 x hasil fungsi ini) di
 * proses_hitung_tukin.php — angkanya SAMA untuk semua pegawai aktif
 * dalam satu periode, bukan dihitung per kehadiran pegawai.
 */
function hitung_hari_kerja_efektif(int $bulan, int $tahun, mysqli $koneksi): int {
    $hari_dalam_bulan = (int) date('t', mktime(0, 0, 0, $bulan, 1, $tahun));
    $jumlah_hari_kerja = 0;
    for ($h = 1; $h <= $hari_dalam_bulan; $h++) {
        $ts = mktime(0, 0, 0, $bulan, $h, $tahun);
        if ((int) date('N', $ts) <= 5) { // Senin(1)-Jumat(5)
            $jumlah_hari_kerja++;
        }
    }

    $stmt = mysqli_prepare($koneksi, "
        SELECT COUNT(*) AS jml FROM hari_libur
        WHERE MONTH(tanggal) = ? AND YEAR(tanggal) = ? AND DAYOFWEEK(tanggal) NOT IN (1, 7)
    ");
    // DAYOFWEEK MySQL: 1=Minggu, 7=Sabtu — NOT IN (1,7) berarti tanggal merah
    // yang jatuh di hari kerja (Senin-Jumat) saja yang dihitung sebagai pengurang.
    mysqli_stmt_bind_param($stmt, 'ii', $bulan, $tahun);
    mysqli_stmt_execute($stmt);
    $jml_libur_di_hari_kerja = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['jml'];

    return max(0, $jumlah_hari_kerja - $jml_libur_di_hari_kerja);
}

/**
 * ---------------------------------------------------------------------
 * AUDIT LOG (State Tracking) — pelengkap Audit Trail Tukin yang sudah
 * ada di tukin_detail/log_perhitungan_tukin. Kalau tukin_detail menjawab
 * "kenapa potongan ini ada", audit_log menjawab "apa yang berubah, kapan,
 * oleh siapa" — dipakai untuk data pegawai, aturan_potongan, dan momen
 * kunci/buka-kunci tukin (bukan tiap hitung ulang draft, supaya tidak
 * berisik — lihat proses_finalisasi_tukin.php).
 *
 * Dipanggil dari: proses_tambah_pegawai.php, proses_edit_pegawai.php,
 * proses_atur_potongan.php, proses_finalisasi_tukin.php.
 *
 * $data_sebelum / $data_sesudah: array asosiatif hasil mysqli_fetch_assoc()
 * langsung, atau NULL kalau tidak relevan (mis. $data_sebelum NULL saat
 * aksi 'tambah' karena belum ada baris sebelumnya).
 * ---------------------------------------------------------------------
 */
function catat_audit(
    mysqli $koneksi,
    string $nama_tabel,
    int $id_record,
    string $aksi,
    ?array $data_sebelum,
    ?array $data_sesudah,
    int $user_id,
    ?string $keterangan = null
): void {
    $kolom_berubah = null;
    if ($data_sebelum !== null && $data_sesudah !== null) {
        $berubah = [];
        foreach ($data_sesudah as $kolom => $nilai_baru) {
            $nilai_lama = $data_sebelum[$kolom] ?? null;
            if ((string) $nilai_lama !== (string) $nilai_baru) {
                $berubah[] = $kolom;
            }
        }
        if (!empty($berubah)) {
            $kolom_berubah = json_encode($berubah, JSON_UNESCAPED_UNICODE);
        }
    }

    $sebelum_json = $data_sebelum !== null ? json_encode($data_sebelum, JSON_UNESCAPED_UNICODE) : null;
    $sesudah_json = $data_sesudah !== null ? json_encode($data_sesudah, JSON_UNESCAPED_UNICODE) : null;

    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO audit_log
            (nama_tabel, id_record, aksi, data_sebelum, data_sesudah, kolom_berubah, dilakukan_oleh, keterangan)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param(
        $stmt, 'sissssis',
        $nama_tabel, $id_record, $aksi, $sebelum_json, $sesudah_json, $kolom_berubah, $user_id, $keterangan
    );
    mysqli_stmt_execute($stmt);
}

/**
 * ---------------------------------------------------------------------
 * [POIN 3] AMBIL RIWAYAT MUTASI METODE DALAM SATU BULAN — helper
 * ---------------------------------------------------------------------
 * Mengembalikan semua baris riwayat_mutasi_metode milik satu pegawai
 * yang RELEVAN untuk bulan/tahun tertentu: yaitu baris yang tmt_berlaku-
 * nya <= akhir bulan tersebut, diurutkan dari yang paling lama ke baru.
 * Hasilnya dipakai oleh hitung_proporsi_metode() di bawah.
 *
 * Kenapa diambil semua yang tmt <= akhir_bulan (bukan hanya yang ada
 * dalam rentang)?  Karena kita perlu mengetahui metode yang berlaku
 * SEBELUM mutasi pertama di bulan itu juga — baris dengan tmt < awal
 * bulan adalah "latar belakang" yang menentukan metode di hari-hari awal.
 * ---------------------------------------------------------------------
 */
function ambil_riwayat_metode_bulan(int $pegawai_id, int $bulan, int $tahun, mysqli $koneksi): array {
    $akhir_bulan = date('Y-m-d', mktime(0, 0, 0, $bulan + 1, 0, $tahun)); // hari terakhir bulan
    $stmt = mysqli_prepare($koneksi, "
        SELECT metode_sebelum, metode_sesudah, tmt_berlaku
        FROM riwayat_mutasi_metode
        WHERE pegawai_id = ? AND tmt_berlaku <= ?
        ORDER BY tmt_berlaku ASC, id ASC
    ");
    mysqli_stmt_bind_param($stmt, 'is', $pegawai_id, $akhir_bulan);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) { $rows[] = $r; }
    return $rows;
}

/**
 * ---------------------------------------------------------------------
 * [POIN 3] HITUNG PROPORSI HARI KERJA PER METODE — helper
 * ---------------------------------------------------------------------
 * Membagi hari kerja efektif dalam bulan tertentu ke dua kelompok:
 *   - 'fingerprint' : jumlah hari kerja di mana metode yang berlaku = fingerprint
 *   - 'geolocation' : jumlah hari kerja di mana metode yang berlaku = geolocation
 *
 * Algoritma:
 *   1. Iterasi setiap hari kerja dalam bulan (Senin-Jumat, bukan tanggal merah).
 *   2. Untuk setiap hari, tentukan metode yang berlaku dengan mencari
 *      baris riwayat mutasi dengan tmt_berlaku TERBESAR yang masih ≤ tanggal hari itu.
 *   3. Tambahkan ke counter metode yang sesuai.
 *
 * Kalau tidak ada riwayat mutasi sama sekali (pegawai lama sebelum migrasi),
 * fallback ke metode_absen aktif saat ini dari tabel pegawai — hari kerja
 * efektif seluruhnya dihitung sebagai satu metode.
 *
 * @param  int    $pegawai_id    ID pegawai
 * @param  int    $bulan         Bulan (1-12)
 * @param  int    $tahun         Tahun 4 digit
 * @param  string $metode_saat_ini  Nilai kolom pegawai.metode_absen (fallback)
 * @param  array  $riwayat       Hasil ambil_riwayat_metode_bulan() — bisa di-pass agar tidak query ulang
 * @param  mysqli $koneksi
 * @return array  ['fingerprint' => int, 'geolocation' => int, 'total' => int]
 * ---------------------------------------------------------------------
 */
function hitung_proporsi_metode(
    int $pegawai_id,
    int $bulan,
    int $tahun,
    string $metode_saat_ini,
    array $riwayat,
    mysqli $koneksi
): array {
    $proporsi = ['fingerprint' => 0, 'geolocation' => 0, 'total' => 0];

    // Kalau tidak ada riwayat sama sekali → semua hari kerja = metode_saat_ini
    if (empty($riwayat)) {
        $hari_kerja = hitung_hari_kerja_efektif($bulan, $tahun, $koneksi);
        $proporsi[$metode_saat_ini] = $hari_kerja;
        $proporsi['total']          = $hari_kerja;
        return $proporsi;
    }

    // Iterasi setiap hari dalam bulan
    $hari_dalam_bulan = (int) date('t', mktime(0, 0, 0, $bulan, 1, $tahun));
    for ($h = 1; $h <= $hari_dalam_bulan; $h++) {
        $tanggal_sql = sprintf('%04d-%02d-%02d', $tahun, $bulan, $h);
        if (!is_hari_kerja($tanggal_sql, $koneksi)) continue;

        // Cari metode yang berlaku pada tanggal ini:
        // ambil baris riwayat dengan tmt_berlaku terbesar yang masih <= tanggal_sql
        $metode_hari = null;
        foreach ($riwayat as $r) {
            if ($r['tmt_berlaku'] <= $tanggal_sql) {
                $metode_hari = $r['metode_sesudah'];
            }
        }
        // Fallback: kalau tidak ada riwayat yang berlaku (entri awal TMT-nya setelah hari ini), pakai metode_saat_ini
        if ($metode_hari === null) {
            $metode_hari = $metode_saat_ini;
        }

        $proporsi[$metode_hari]++;
        $proporsi['total']++;
    }

    return $proporsi;
}



/**
 * ---------------------------------------------------------------------
 * HITUNG TUKIN SATU PERIODE — Single Source of Truth untuk logika
 * perhitungan, dipakai proses_hitung_tukin.php (tombol manual, dipicu
 * Kasubbag TU/Admin) MAUPUN cron_hitung_tukin_draft.php (otomatis,
 * hanya untuk periode berstatus draft). Mengembalikan array terstruktur
 * — TIDAK redirect/echo sendiri, itu urusan pemanggil.
 *
 * Aturan bisnis: "Tukin" = tunjangan makan Rp35.000/hari kerja efektif
 * (TARIF_HARIAN_TUNJANGAN), sama rata semua pegawai aktif. Baris absensi
 * berstatus 'perlu_verifikasi' atau 'disetujui' TIDAK dipotong dulu —
 * hanya 'otomatis_sah'/'ditolak' yang final sebagai tidak sah yang
 * dipotong. Periode berstatus 'final' TIDAK BOLEH dihitung ulang lewat
 * jalur ini, harus dibuka kunci dulu lewat proses_finalisasi_tukin.php.
 *
 * CATATAN OPERASIONAL: tabel `hari_libur` masih sampel — kalau tanggal
 * merah bulan tertentu belum lengkap/akurat di tabel itu, hari kerja
 * efektif hasil hitungan ikut meleset. Pastikan menu Kalender Libur di
 * admin.php sudah diisi lengkap untuk bulan yang mau dihitung.
 * ---------------------------------------------------------------------
 */
function hitung_tukin_periode(mysqli $koneksi, int $bulan, int $tahun, int $user_id): array {
    if ($bulan < 1 || $bulan > 12 || $tahun < 2020) {
        return ['sukses' => false, 'status' => 'parameter_invalid', 'bulan' => $bulan, 'tahun' => $tahun, 'pesan' => 'Bulan/tahun tidak valid'];
    }

    $stmt = mysqli_prepare($koneksi, "
        SELECT COUNT(*) AS jml FROM tukin
        WHERE periode_bulan = ? AND periode_tahun = ? AND status = 'final'
    ");
    mysqli_stmt_bind_param($stmt, 'ii', $bulan, $tahun);
    mysqli_stmt_execute($stmt);
    if ((int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['jml'] > 0) {
        return ['sukses' => false, 'status' => 'sudah_final', 'bulan' => $bulan, 'tahun' => $tahun, 'pesan' => "Periode $bulan/$tahun sudah final, tidak dihitung ulang"];
    }

    $aturan_map = [];
    $hasil_aturan = mysqli_query($koneksi, "SELECT id, jenis, nominal_potongan FROM aturan_potongan");
    while ($ar = mysqli_fetch_assoc($hasil_aturan)) {
        $aturan_map[$ar['jenis']] = ['id' => (int) $ar['id'], 'nominal' => (float) $ar['nominal_potongan']];
    }

    $hari_kerja_efektif_bulan = hitung_hari_kerja_efektif($bulan, $tahun, $koneksi);
    $tukin_pokok_periode      = TARIF_HARIAN_TUNJANGAN * $hari_kerja_efektif_bulan;

    $hasil_pegawai = mysqli_query($koneksi, "SELECT id, metode_absen FROM pegawai WHERE status_kepegawaian = 'aktif'");

    mysqli_begin_transaction($koneksi);
    try {
        while ($peg = mysqli_fetch_assoc($hasil_pegawai)) {
            $pegawai_id      = (int) $peg['id'];
            $metode_aktif    = $peg['metode_absen']; // metode saat ini (kolom pegawai)

            // ── [POIN 3] Hitung proporsi hari kerja per metode ──────────────
            // Ambil riwayat mutasi metode untuk bulan ini, lalu hitung berapa
            // hari kerja efektif dihabiskan dengan fingerprint vs geolocation.
            // Ini menggantikan asumsi lama bahwa seluruh bulan = satu metode.
            $riwayat_metode = ambil_riwayat_metode_bulan($pegawai_id, $bulan, $tahun, $koneksi);
            $proporsi        = hitung_proporsi_metode(
                $pegawai_id, $bulan, $tahun,
                $metode_aktif, $riwayat_metode, $koneksi
            );
            // proporsi['fingerprint'] = jumlah hari kerja dengan metode fingerprint
            // proporsi['geolocation'] = jumlah hari kerja dengan metode geolocation
            // proporsi['total']       = total hari kerja efektif pegawai ini bulan ini
            //                           (biasanya = $hari_kerja_efektif_bulan, kecuali ada selisih edge case)
            $hari_efektif = $proporsi['total'] ?: $hari_kerja_efektif_bulan; // safety fallback
            $tukin_pokok  = TARIF_HARIAN_TUNJANGAN * $hari_efektif;
            // ── Akhir [POIN 3] proporsi ─────────────────────────────────────

            // Ambil baris absensi yang berpotensi dipotong untuk bulan ini.
            // Filter tambahan [POIN 3]: potongan alpa/terlambat FINGERPRINT hanya
            // berlaku di hari-hari fingerprint, dan potongan luar_radius/mock_location
            // GEOLOCATION hanya berlaku di hari-hari geolocation. Namun karena
            // sumber_absen tersimpan di baris absensi, kita bisa filter sesuai
            // sumber_absen — tidak perlu join kalender tambahan.
            $stmt = mysqli_prepare($koneksi, "
                SELECT id, tanggal, sumber_absen, status_kehadiran, status_radius, is_mock_location, status_verifikasi
                FROM absensi
                WHERE pegawai_id = ? AND MONTH(tanggal) = ? AND YEAR(tanggal) = ?
                  AND (
                        (status_kehadiran = 'terlambat' AND status_verifikasi IN ('otomatis_sah', 'ditolak'))
                     OR status_kehadiran = 'alpa'
                     OR (status_radius = 'luar_radius' AND status_verifikasi = 'ditolak')
                     OR (is_mock_location = 1 AND status_verifikasi = 'ditolak')
                  )
            ");
            mysqli_stmt_bind_param($stmt, 'iii', $pegawai_id, $bulan, $tahun);
            mysqli_stmt_execute($stmt);
            $baris_potongan = mysqli_stmt_get_result($stmt);

            $rincian = [];
            while ($b = mysqli_fetch_assoc($baris_potongan)) {
                // [POIN 3] Validasi cross-metode: potongan jenis geolocation
                // (luar_radius, lokasi_palsu) hanya berlaku kalau di hari itu
                // memang jadwalnya geolocation — cek dari riwayat mutasi.
                // Potongan terlambat/alpa dari fingerprint berlaku di hari fingerprint.
                // Potongan terlambat dari geolocation berlaku di hari geolocation.
                // Praktisnya, sumber_absen di baris absensi sudah merekam metode
                // yang dipakai pegawai di hari itu — kita pakai itu sebagai proxy.
                $sumber = $b['sumber_absen']; // 'fingerprint' | 'geolocation' | 'sistem'

                if ($b['status_kehadiran'] === 'terlambat' && isset($aturan_map['terlambat'])) {
                    $rincian[] = ['absensi_id' => (int) $b['id'], 'aturan_id' => $aturan_map['terlambat']['id'], 'nominal' => $aturan_map['terlambat']['nominal']];
                }
                if ($b['status_kehadiran'] === 'alpa' && isset($aturan_map['alpa'])) {
                    // Alpa berlaku apa pun metodenya — pegawai tidak hadir, titik.
                    $rincian[] = ['absensi_id' => (int) $b['id'], 'aturan_id' => $aturan_map['alpa']['id'], 'nominal' => $aturan_map['alpa']['nominal']];
                }
                if ($b['status_radius'] === 'luar_radius' && $b['status_verifikasi'] === 'ditolak' && isset($aturan_map['luar_radius'])) {
                    // Hanya berlaku untuk absen geolocation — fingerprint tidak punya radius
                    if ($sumber === 'geolocation') {
                        $rincian[] = ['absensi_id' => (int) $b['id'], 'aturan_id' => $aturan_map['luar_radius']['id'], 'nominal' => $aturan_map['luar_radius']['nominal']];
                    }
                }
                if ($b['is_mock_location'] == 1 && $b['status_verifikasi'] === 'ditolak' && isset($aturan_map['lokasi_palsu'])) {
                    // Sama, hanya geolocation yang bisa punya indikasi mock location
                    if ($sumber === 'geolocation') {
                        $rincian[] = ['absensi_id' => (int) $b['id'], 'aturan_id' => $aturan_map['lokasi_palsu']['id'], 'nominal' => $aturan_map['lokasi_palsu']['nominal']];
                    }
                }
            }


            $total_potongan = array_sum(array_column($rincian, 'nominal'));
            $tukin_diterima = max(0, $tukin_pokok - $total_potongan);

            $stmt = mysqli_prepare($koneksi, "
                INSERT INTO tukin (pegawai_id, periode_bulan, periode_tahun, tukin_pokok, hari_kerja_efektif, total_potongan, tukin_diterima, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')
                ON DUPLICATE KEY UPDATE
                    tukin_pokok = VALUES(tukin_pokok),
                    hari_kerja_efektif = VALUES(hari_kerja_efektif),
                    total_potongan = VALUES(total_potongan),
                    tukin_diterima = VALUES(tukin_diterima),
                    status = 'draft'
            ");
            mysqli_stmt_bind_param($stmt, 'iiidddd', $pegawai_id, $bulan, $tahun, $tukin_pokok, $hari_efektif, $total_potongan, $tukin_diterima);
            mysqli_stmt_execute($stmt);

            $stmt = mysqli_prepare($koneksi, "SELECT id FROM tukin WHERE pegawai_id = ? AND periode_bulan = ? AND periode_tahun = ?");
            mysqli_stmt_bind_param($stmt, 'iii', $pegawai_id, $bulan, $tahun);
            mysqli_stmt_execute($stmt);
            $tukin_id = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['id'];

            $stmt = mysqli_prepare($koneksi, "DELETE FROM tukin_detail WHERE tukin_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $tukin_id);
            mysqli_stmt_execute($stmt);

            if (!empty($rincian)) {
                $stmt = mysqli_prepare($koneksi, "INSERT INTO tukin_detail (tukin_id, absensi_id, aturan_id, nominal_potongan) VALUES (?, ?, ?, ?)");
                foreach ($rincian as $r) {
                    mysqli_stmt_bind_param($stmt, 'iiid', $tukin_id, $r['absensi_id'], $r['aturan_id'], $r['nominal']);
                    mysqli_stmt_execute($stmt);
                }
            }
        }

        $jumlah_pegawai_diproses = (int) mysqli_fetch_assoc(
            mysqli_query($koneksi, "SELECT COUNT(*) AS jml FROM pegawai WHERE status_kepegawaian = 'aktif'")
        )['jml'];

        $stmt = mysqli_prepare($koneksi, "
            INSERT INTO log_perhitungan_tukin
                (periode_bulan, periode_tahun, dihitung_oleh, jumlah_pegawai_diproses, hari_kerja_efektif_dipakai)
            VALUES (?, ?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, 'iiiii', $bulan, $tahun, $user_id, $jumlah_pegawai_diproses, $hari_kerja_efektif_bulan);
        mysqli_stmt_execute($stmt);

        mysqli_commit($koneksi);

        return [
            'sukses' => true,
            'status' => 'berhasil',
            'bulan' => $bulan,
            'tahun' => $tahun,
            'jumlah_pegawai_diproses' => $jumlah_pegawai_diproses,
            'hari_kerja_efektif' => $hari_kerja_efektif_bulan,
            'pesan' => "Periode $bulan/$tahun: $jumlah_pegawai_diproses pegawai diproses, $hari_kerja_efektif_bulan hari kerja efektif",
        ];

    } catch (\Throwable $e) {
        mysqli_rollback($koneksi);
        error_log("hitung_tukin_periode($bulan/$tahun) gagal: " . $e->getMessage());
        return [
            'sukses' => false,
            'status' => 'gagal',
            'bulan' => $bulan,
            'tahun' => $tahun,
            'pesan' => 'Terjadi kesalahan saat menghitung: ' . $e->getMessage(),
        ];
    }
}
