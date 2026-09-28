-- ============================================================================
-- MIGRASI: Tambah kolom zkteco_pin ke tabel pegawai
-- ============================================================================
-- Kolom ini menyimpan "User ID" pegawai di mesin ZKTeco (angka pendek 1-65535).
-- Berbeda dengan NIP (18 digit) — ZKTeco membatasi Enrollment Number max 9 digit.
--
-- CARA PAKAI:
--   1. Jalankan query ini SATU KALI di phpMyAdmin atau MySQL CLI
--   2. Isi zkteco_pin untuk setiap pegawai fingerprint (lihat contoh UPDATE di bawah)
--   3. Cek User ID pegawai: di layar mesin ZKTeco → Menu → User Management → cari nama
-- ============================================================================

ALTER TABLE `pegawai`
  ADD COLUMN `zkteco_pin` SMALLINT UNSIGNED DEFAULT NULL
    COMMENT 'User ID (Enrollment Number) di mesin ZKTeco. NULL = belum terdaftar/dipetakan. Max 65535 sesuai batas ZKTeco firmware.'
    AFTER `nip`;

-- Index untuk pencarian cepat saat endpoint PHP lookup pin → pegawai
CREATE INDEX `idx_pegawai_zkteco_pin` ON `pegawai` (`zkteco_pin`);

-- ============================================================================
-- SEED: Petakan zkteco_pin untuk pegawai yang sudah ada
-- ============================================================================
-- Ganti nilai User ID di bawah sesuai konfigurasi mesin ZKTeco Anda.
-- Cek User ID tiap pegawai di layar mesin: Menu → User Mgt → (scroll cari nama)
-- HANYA pegawai dengan metode_absen = 'fingerprint' yang perlu diisi.
-- ============================================================================

-- Contoh mapping (SESUAIKAN dengan User ID di mesin Anda):
-- UPDATE `pegawai` SET zkteco_pin = 1 WHERE nip = '198501142010011041';  -- Rian Saputra
-- UPDATE `pegawai` SET zkteco_pin = 2 WHERE nip = '199002182019022018';  -- Dewi Anjani
-- UPDATE `pegawai` SET zkteco_pin = 3 WHERE nip = '198812172012121067';  -- Ahmad Fauzan
-- UPDATE `pegawai` SET zkteco_pin = 4 WHERE nip = '199105292019031029';  -- Hendra Purnomo
-- UPDATE `pegawai` SET zkteco_pin = 8 WHERE nip = '197908252005012008';  -- Kartika Br Sitepu

-- Cek hasil mapping:
SELECT id, nip, nama, metode_absen, zkteco_pin
FROM pegawai
WHERE metode_absen = 'fingerprint'
ORDER BY id;
