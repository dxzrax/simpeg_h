-- ==========================================================================
-- MIGRASI: Tabel riwayat_mutasi_metode
-- ==========================================================================
-- Mencatat setiap perubahan metode presensi pegawai (fingerprint <->
-- geolocation) beserta Tanggal Mulai Berlaku (TMT), sehingga rule engine
-- perhitungan Tukin bisa membagi proporsi hari kerja secara dinamis
-- dalam bulan yang mengalami mutasi metode di tengah periode.
--
-- Aturan bisnis:
--   - Satu baris per kejadian mutasi (termasuk entri awal saat pegawai
--     pertama kali ditambahkan ke sistem, agar periode sebelum mutasi
--     pertama pun bisa dihitung dengan benar).
--   - tmt_berlaku adalah tanggal PERTAMA yang metode barunya berlaku
--     (inklusif). Hari sebelumnya masih dihitung dengan metode_lama.
--   - Kolom metode_sebelum NULL hanya untuk baris "entri awal" (saat
--     pegawai pertama kali dibuat, belum ada riwayat sebelumnya).
--   - dicatat_oleh FK ke users.id, RESTRICT supaya log tidak hilang
--     meski akunnya kelak dihapus (same pattern dengan log_perhitungan_tukin).
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `riwayat_mutasi_metode` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `pegawai_id`      INT UNSIGNED     NOT NULL COMMENT 'FK ke pegawai.id',
  `metode_sebelum`  ENUM('fingerprint','geolocation') COLLATE utf8mb4_unicode_ci
                    DEFAULT NULL     COMMENT 'NULL untuk entri awal (pegawai baru)',
  `metode_sesudah`  ENUM('fingerprint','geolocation') COLLATE utf8mb4_unicode_ci
                    NOT NULL         COMMENT 'Metode yang mulai berlaku sejak tmt_berlaku',
  `tmt_berlaku`     DATE             NOT NULL COMMENT 'Tanggal Mulai Berlaku — metode_sesudah berlaku mulai hari ini',
  `keterangan`      VARCHAR(255)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dicatat_oleh`    INT UNSIGNED     NOT NULL COMMENT 'FK ke users.id (admin/kasubbag yang menyimpan perubahan)',
  `created_at`      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- Cepat ketika helpers.php mencari riwayat mutasi pegawai tertentu
  -- dalam rentang bulan tertentu (ORDER BY tmt_berlaku)
  KEY `idx_mutasi_pegawai_tmt` (`pegawai_id`, `tmt_berlaku`),
  -- FK ke pegawai: CASCADE DELETE supaya riwayat ikut bersih kalau pegawai dihapus
  CONSTRAINT `fk_mutasi_pegawai`
    FOREIGN KEY (`pegawai_id`) REFERENCES `pegawai` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  -- FK ke users: RESTRICT supaya log tetap utuh meski akun penghitung dihapus
  CONSTRAINT `fk_mutasi_dicatat_oleh`
    FOREIGN KEY (`dicatat_oleh`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historis perubahan metode presensi pegawai. Dipakai oleh hitung_tukin_periode() untuk menghitung proporsi hari fingerprint vs geolocation dalam satu periode.';


-- ==========================================================================
-- SEED: Isi riwayat awal untuk semua pegawai yang sudah ada di sistem.
-- ==========================================================================
-- Karena tabel ini baru, pegawai yang sudah ada belum punya riwayat.
-- Kita buat satu baris "entri awal" per pegawai menggunakan metode_absen
-- yang saat ini aktif, dengan tmt_berlaku = tanggal pertama absensi mereka
-- (atau 2026-01-01 sebagai fallback) dan metode_sebelum = NULL.
--
-- Script ini mencari user dengan role 'admin' atau 'kasubbag' yang ID-nya
-- paling kecil sebagai "dicatat_oleh" untuk seed awal.
-- Kalau struktur users belum ada saat migrasi dijalankan, sesuaikan
-- nilai @admin_user_id di bawah secara manual.
-- ==========================================================================

SET @admin_user_id = (
    SELECT MIN(id) FROM users
    WHERE role IN ('admin', 'kasubbag')
    LIMIT 1
);

-- Pastikan @admin_user_id tidak NULL (kalau tabel users masih kosong,
-- set manual: SET @admin_user_id = 1; )
-- Hentikan migrasi dengan error yang jelas jika tidak ada admin
SELECT IF(
    @admin_user_id IS NULL,
    (SELECT CAST('ERROR: Tidak ada user admin/kasubbag. Seed riwayat_mutasi_metode dibatalkan. Set @admin_user_id secara manual.' AS CHAR)),
    'OK — akan melanjutkan seed'
) AS status_seed;

-- Insert satu baris per pegawai aktif yang belum punya riwayat mutasi
INSERT INTO `riwayat_mutasi_metode`
    (`pegawai_id`, `metode_sebelum`, `metode_sesudah`, `tmt_berlaku`, `keterangan`, `dicatat_oleh`)
SELECT
    p.id,
    NULL                            AS metode_sebelum,  -- entri awal
    p.metode_absen                  AS metode_sesudah,
    -- tmt_berlaku = tanggal absensi pertama, atau 2026-01-01 kalau belum ada
    COALESCE(
        (SELECT MIN(a.tanggal) FROM absensi a WHERE a.pegawai_id = p.id),
        '2026-01-01'
    )                               AS tmt_berlaku,
    'Entri awal otomatis dari migrasi — metode aktif saat tabel dibuat'
                                    AS keterangan,
    @admin_user_id                  AS dicatat_oleh
FROM pegawai p
WHERE NOT EXISTS (
    SELECT 1 FROM riwayat_mutasi_metode r WHERE r.pegawai_id = p.id
);
