-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 28, 2026 at 01:06 PM
-- Server version: 8.0.30
-- PHP Version: 8.1.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_simpeg_h`
--

-- --------------------------------------------------------

--
-- Table structure for table `absensi`
--

CREATE TABLE `absensi` (
  `id` int UNSIGNED NOT NULL,
  `pegawai_id` int UNSIGNED NOT NULL,
  `tanggal` date NOT NULL,
  `jam_masuk` time DEFAULT NULL,
  `jam_pulang` time DEFAULT NULL,
  `sumber_absen` enum('fingerprint','geolocation','sistem') COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `akurasi_gps_meter` decimal(6,2) DEFAULT NULL,
  `titik_lokasi_id` int UNSIGNED DEFAULT NULL,
  `jarak_dari_titik_meter` decimal(8,2) DEFAULT NULL,
  `status_radius` enum('dalam_radius','luar_radius','tidak_berlaku') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tidak_berlaku',
  `status_kehadiran` enum('tepat_waktu','terlambat','alpa','cuti') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tepat_waktu',
  `menit_terlambat` int UNSIGNED NOT NULL DEFAULT '0',
  `alasan_terlambat` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Diisi pegawai sendiri saat cek-in kalau terlambat 30-60 menit, ditinjau via status_verifikasi',
  `foto_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata_exif` json DEFAULT NULL COMMENT 'GPS, waktu pengambilan, resolusi foto',
  `is_mock_location` tinyint(1) NOT NULL DEFAULT '0',
  `alasan_indikasi_spoofing` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'mis. kecepatan mustahil, akurasi janggal, koordinat berulang',
  `status_sinkronisasi` enum('online','tersimpan_lokal','tersinkron') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online',
  `waktu_dibuat_perangkat` datetime DEFAULT NULL COMMENT 'Waktu kehadiran resmi (bukan waktu diterima server)',
  `waktu_diterima_server` datetime DEFAULT NULL,
  `jadwal_dinas_id` int UNSIGNED DEFAULT NULL,
  `status_jadwal` enum('sesuai','tidak_ada_jadwal','diluar_jadwal') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_verifikasi` enum('otomatis_sah','perlu_verifikasi','disetujui','ditolak') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'otomatis_sah',
  `diverifikasi_oleh` int UNSIGNED DEFAULT NULL COMMENT 'FK ke users.id (Kasubbag TU/atasan)',
  `waktu_verifikasi` datetime DEFAULT NULL,
  `catatan_verifikasi` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `absensi`
--

INSERT INTO `absensi` (`id`, `pegawai_id`, `tanggal`, `jam_masuk`, `jam_pulang`, `sumber_absen`, `latitude`, `longitude`, `akurasi_gps_meter`, `titik_lokasi_id`, `jarak_dari_titik_meter`, `status_radius`, `status_kehadiran`, `menit_terlambat`, `foto_url`, `metadata_exif`, `is_mock_location`, `alasan_indikasi_spoofing`, `status_sinkronisasi`, `waktu_dibuat_perangkat`, `waktu_diterima_server`, `jadwal_dinas_id`, `status_jadwal`, `status_verifikasi`, `diverifikasi_oleh`, `waktu_verifikasi`, `catatan_verifikasi`, `created_at`) VALUES
(1, 1, '2026-07-02', '08:01:12', '16:32:40', 'fingerprint', NULL, NULL, NULL, NULL, NULL, 'tidak_berlaku', 'tepat_waktu', 0, NULL, NULL, 0, NULL, 'online', NULL, NULL, NULL, NULL, 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26'),
(2, 2, '2026-07-02', '08:14:47', '16:30:02', 'fingerprint', NULL, NULL, NULL, NULL, NULL, 'tidak_berlaku', 'terlambat', 14, NULL, NULL, 0, NULL, 'online', NULL, NULL, NULL, NULL, 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26'),
(3, 3, '2026-07-02', '07:58:03', '16:35:11', 'fingerprint', NULL, NULL, NULL, NULL, NULL, 'tidak_berlaku', 'tepat_waktu', 0, NULL, NULL, 0, NULL, 'online', NULL, NULL, NULL, NULL, 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26'),
(4, 4, '2026-07-02', NULL, NULL, 'fingerprint', NULL, NULL, NULL, NULL, NULL, 'tidak_berlaku', 'alpa', 0, NULL, NULL, 0, NULL, 'online', NULL, NULL, NULL, NULL, 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26'),
(5, 5, '2026-07-02', '08:00:00', NULL, 'geolocation', '-5.4290000', '105.2611000', '6.00', 1, '18.00', 'dalam_radius', 'tepat_waktu', 0, '/uploads/absensi/2026-07-02_bayu.jpg', NULL, 0, NULL, 'online', '2026-07-02 08:00:00', '2026-07-02 08:00:02', 1, 'sesuai', 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26'),
(6, 7, '2026-06-26', '08:12:00', NULL, 'geolocation', '-5.4430000', '105.2880000', '9.00', 1, '3200.00', 'luar_radius', 'tepat_waktu', 0, '/uploads/absensi/2026-06-26_siti.jpg', NULL, 0, NULL, 'online', '2026-06-26 08:12:00', '2026-06-26 08:12:03', 2, 'sesuai', 'disetujui', NULL, '2026-06-27 09:00:00', 'Disetujui sebagai tidak sah — cek-in di luar radius geofence', '2026-07-25 10:33:26'),
(7, 6, '2026-06-26', '08:00:00', '16:00:00', 'geolocation', '-5.3841000', '105.2456000', '5.50', 3, '10.00', 'dalam_radius', 'tepat_waktu', 0, '/uploads/absensi/2026-06-26_doni.jpg', NULL, 0, NULL, 'online', '2026-06-26 08:00:00', '2026-06-26 08:00:02', 3, 'sesuai', 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26'),
(8, 5, '2026-06-25', '07:55:00', '16:33:00', 'geolocation', '-5.4292000', '105.2613000', '7.00', 1, '15.00', 'dalam_radius', 'tepat_waktu', 0, '/uploads/absensi/2026-06-25_bayu.jpg', NULL, 0, NULL, 'tersinkron', '2026-06-25 07:55:00', '2026-06-25 09:58:00', 1, 'sesuai', 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26'),
(9, 3, '2026-07-03', '08:00:00', '08:05:00', 'geolocation', '-5.8722000', '105.7530000', '1.00', 2, '15.00', 'dalam_radius', 'tepat_waktu', 0, '/uploads/absensi/2026-07-03_ahmad.jpg', NULL, 1, 'Kecepatan perpindahan tidak wajar (40 km dalam 5 menit) & akurasi GPS statis', 'online', '2026-07-03 08:05:00', '2026-07-03 08:05:02', 5, 'sesuai', 'perlu_verifikasi', NULL, NULL, 'Menunggu tinjauan Kasubbag TU — indikasi mock location', '2026-07-25 10:33:26'),
(10, 7, '2026-07-04', '07:50:00', '16:20:00', 'geolocation', '-5.4291500', '105.2612500', '8.00', 1, '20.00', 'dalam_radius', 'tepat_waktu', 0, '/uploads/absensi/2026-07-04_siti.jpg', NULL, 0, NULL, 'tersinkron', '2026-07-04 07:50:00', '2026-07-04 10:15:00', 2, 'sesuai', 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26'),
(11, 7, '2026-07-05', '07:52:00', '16:22:00', 'geolocation', '-5.4290800', '105.2611800', '7.50', 1, '12.00', 'dalam_radius', 'tepat_waktu', 0, '/uploads/absensi/2026-07-05_siti.jpg', NULL, 0, NULL, 'tersinkron', '2026-07-05 07:52:00', '2026-07-05 11:40:00', 2, 'sesuai', 'otomatis_sah', NULL, NULL, NULL, '2026-07-25 10:33:26');

-- --------------------------------------------------------

--
-- Table structure for table `aturan_potongan`
--

CREATE TABLE `aturan_potongan` (
  `id` int UNSIGNED NOT NULL,
  `kode_aturan` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_aturan` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jenis` enum('terlambat','alpa','luar_radius','lokasi_palsu','lainnya') COLLATE utf8mb4_unicode_ci NOT NULL,
  `nominal_potongan` decimal(12,2) NOT NULL DEFAULT '0.00',
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `aturan_potongan`
--

INSERT INTO `aturan_potongan` (`id`, `kode_aturan`, `nama_aturan`, `jenis`, `nominal_potongan`, `keterangan`) VALUES
(1, 'R-01', 'Potongan alpa (tidak hadir tanpa keterangan)', 'alpa', '35000.00', 'Setara tunjangan makan satu hari — berlaku untuk sumber absen fingerprint maupun geolocation'),
(2, 'R-02', 'Potongan keterlambatan tanpa alasan disetujui', 'terlambat', '35000.00', 'Toleransi 30 menit; 30-60 menit bisa dibebaskan kalau alasan disetujui Kasubbag TU; di atas 60 menit selalu kena potongan'),
(3, 'R-07', 'Potongan cek-in di luar radius geofence', 'luar_radius', '35000.00', 'Berlaku setelah diverifikasi dan disetujui tidak sah oleh atasan'),
(4, 'R-09', 'Potongan indikasi lokasi palsu (mock location) terverifikasi', 'lokasi_palsu', '35000.00', 'Berlaku hanya setelah atasan menyetujui sebagai tidak sah, bukan otomatis');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
-- Ditambahkan untuk State Tracking (before/after JSON) pada pegawai,
-- aturan_potongan, dan transisi kunci/buka-kunci tukin — pelengkap
-- Audit Trail Tukin yang sudah ada (tukin_detail/log_perhitungan_tukin).
--

CREATE TABLE `audit_log` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_tabel` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT "'pegawai' | 'aturan_potongan' | 'tukin'",
  `id_record` int UNSIGNED NOT NULL COMMENT 'Sengaja TANPA FK — polymorphic, tabel rujukan beda-beda tergantung nama_tabel',
  `aksi` enum('tambah','ubah','nonaktifkan','kunci','buka_kunci') COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_sebelum` json DEFAULT NULL COMMENT 'NULL kalau aksi = tambah',
  `data_sesudah` json DEFAULT NULL,
  `kolom_berubah` json DEFAULT NULL COMMENT 'daftar nama kolom yang nilainya beda',
  `dilakukan_oleh` int UNSIGNED NOT NULL,
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_tabel_record` (`nama_tabel`,`id_record`),
  KEY `idx_audit_waktu` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hari_libur`
--
-- Kalender libur nasional & cuti bersama — dipakai untuk menghitung hari
-- kerja efektif per bulan (helpers.php: hitung_hari_kerja_efektif()) dan
-- supaya cron_tandai_alpa.php tidak salah menandai alpa di tanggal merah
-- yang jatuh di hari kerja (Senin-Jumat). Diisi ulang admin tiap tahun
-- lewat menu Kalender Libur di admin.php.
--

CREATE TABLE `hari_libur` (
  `tanggal` date NOT NULL,
  `keterangan` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jenis` enum('nasional','cuti_bersama') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'nasional',
  `dibuat_oleh` int UNSIGNED DEFAULT NULL COMMENT 'FK ke users.id, admin yang menambahkan',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `hari_libur`
--
-- CATATAN: sampel 2026 ini HANYA 17 hari libur nasional + sebagian cuti
-- bersama yang berhasil dipastikan sumbernya (SKB 3 Menteri, ditandatangani
-- 19 Sep 2025). BELUM LENGKAP 8 cuti bersama — verifikasi & lengkapi lewat
-- menu Kalender Libur sebelum dipakai untuk perhitungan tukin sungguhan.
--

INSERT INTO `hari_libur` (`tanggal`, `keterangan`, `jenis`) VALUES
('2026-01-01', 'Tahun Baru Masehi', 'nasional'),
('2026-01-16', 'Isra Mikraj Nabi Muhammad SAW', 'nasional'),
('2026-02-16', 'Cuti Bersama Tahun Baru Imlek', 'cuti_bersama'),
('2026-02-17', 'Tahun Baru Imlek 2577 Kongzili', 'nasional'),
('2026-03-18', 'Cuti Bersama Hari Suci Nyepi', 'cuti_bersama'),
('2026-03-19', 'Hari Suci Nyepi (Tahun Baru Saka 1948)', 'nasional'),
('2026-03-20', 'Cuti Bersama Idulfitri', 'cuti_bersama'),
('2026-03-21', 'Idulfitri 1447 H', 'nasional'),
('2026-03-22', 'Idulfitri 1447 H', 'nasional'),
('2026-03-23', 'Cuti Bersama Idulfitri', 'cuti_bersama'),
('2026-03-24', 'Cuti Bersama Idulfitri', 'cuti_bersama'),
('2026-04-03', 'Wafat Yesus Kristus', 'nasional'),
('2026-04-05', 'Kebangkitan Yesus Kristus (Paskah)', 'nasional'),
('2026-05-01', 'Hari Buruh Internasional', 'nasional'),
('2026-05-14', 'Kenaikan Yesus Kristus', 'nasional'),
('2026-05-27', 'Iduladha 1447 H', 'nasional'),
('2026-05-31', 'Hari Raya Waisak 2570 BE', 'nasional'),
('2026-06-01', 'Hari Lahir Pancasila', 'nasional'),
('2026-06-16', '1 Muharam Tahun Baru Islam 1448 H', 'nasional'),
('2026-08-17', 'Hari Proklamasi Kemerdekaan RI', 'nasional'),
('2026-08-25', 'Maulid Nabi Muhammad SAW', 'nasional'),
('2026-12-24', 'Cuti Bersama Hari Raya Natal', 'cuti_bersama'),
('2026-12-25', 'Kelahiran Yesus Kristus (Natal)', 'nasional');

-- --------------------------------------------------------

--
-- Table structure for table `jadwal_dinas_luar`
--

CREATE TABLE `jadwal_dinas_luar` (
  `id` int UNSIGNED NOT NULL,
  `pegawai_id` int UNSIGNED NOT NULL,
  `titik_lokasi_id` int UNSIGNED NOT NULL,
  `tanggal_mulai` date NOT NULL,
  `tanggal_selesai` date NOT NULL,
  `jam_mulai` time DEFAULT NULL,
  `jam_selesai` time DEFAULT NULL,
  `keterangan` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `jadwal_dinas_luar`
--

INSERT INTO `jadwal_dinas_luar` (`id`, `pegawai_id`, `titik_lokasi_id`, `tanggal_mulai`, `tanggal_selesai`, `jam_mulai`, `jam_selesai`, `keterangan`, `created_at`) VALUES
(1, 5, 1, '2026-06-01', '2026-06-30', '07:00:00', '15:00:00', 'Jaga rutin Jembatan Timbang Way Urang - Juni 2026', '2026-07-25 10:33:26'),
(2, 7, 1, '2026-06-01', '2026-06-30', '07:00:00', '15:00:00', 'Jaga rutin Jembatan Timbang Way Urang - Juni 2026', '2026-07-25 10:33:26'),
(3, 6, 3, '2026-06-01', '2026-06-30', '08:00:00', '16:00:00', 'Rampcheck rutin Terminal Rajabasa - Juni 2026', '2026-07-25 10:33:26'),
(4, 6, 2, '2026-06-29', '2026-06-29', '06:00:00', '18:00:00', 'Dinas luar Posko Lebaran Bakauheni', '2026-07-25 10:33:26'),
(5, 3, 2, '2026-07-03', '2026-07-03', '06:00:00', '18:00:00', 'Dinas luar Posko Bakauheni (di luar jadwal rutin Ahmad Fauzan)', '2026-07-25 10:33:26');

-- --------------------------------------------------------

--
-- Table structure for table `log_perhitungan_tukin`
--
-- Satu baris setiap kali tombol "Hitung Tukin Bulan Ini" ditekan —
-- pelengkap audit trail di level periode (tukin_detail sudah mencatat
-- di level baris absensi, ini mencatat di level "siapa & kapan hitung
-- ulang periode ini"). Dibuat FK RESTRICT ke users supaya baris log
-- tidak bisa hilang gara-gara akun penghitungnya dihapus belakangan.
--

CREATE TABLE `log_perhitungan_tukin` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `periode_bulan` tinyint UNSIGNED NOT NULL,
  `periode_tahun` smallint UNSIGNED NOT NULL,
  `dihitung_oleh` int UNSIGNED NOT NULL COMMENT 'FK ke users.id',
  `jumlah_pegawai_diproses` int UNSIGNED NOT NULL,
  `hari_kerja_efektif_dipakai` tinyint UNSIGNED NOT NULL COMMENT 'Snapshot hari kerja efektif SAAT dihitung',
  `waktu_hitung` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_tukin_periode` (`periode_bulan`, `periode_tahun`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `metadata_perangkat`
--

CREATE TABLE `metadata_perangkat` (
  `id` int UNSIGNED NOT NULL,
  `absensi_id` int UNSIGNED NOT NULL,
  `jenis_perangkat` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'mis. Android, iOS, Desktop',
  `browser` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `versi_aplikasi` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode_saat_cekin` enum('online','offline') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `metadata_perangkat`
--

INSERT INTO `metadata_perangkat` (`id`, `absensi_id`, `jenis_perangkat`, `browser`, `versi_aplikasi`, `mode_saat_cekin`) VALUES
(1, 5, 'Android', 'Chrome Mobile', '1.2.0', 'online'),
(2, 6, 'Android', 'Chrome Mobile', '1.2.0', 'online'),
(3, 7, 'Android', 'Chrome Mobile', '1.2.0', 'online'),
(4, 8, 'Android', 'Chrome Mobile', '1.2.0', 'offline'),
(5, 9, 'Android', 'Chrome Mobile', '1.2.0', 'online'),
(6, 10, 'Android', 'Chrome Mobile', '1.2.0', 'offline'),
(7, 11, 'Android', 'Chrome Mobile', '1.2.0', 'offline');

-- --------------------------------------------------------

--
-- Table structure for table `notifikasi`
--

CREATE TABLE `notifikasi` (
  `id` int UNSIGNED NOT NULL,
  `pegawai_id` int UNSIGNED NOT NULL,
  `judul` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deskripsi` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis` enum('ok','warn','info') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifikasi`
--

INSERT INTO `notifikasi` (`id`, `pegawai_id`, `judul`, `deskripsi`, `jenis`, `is_read`, `created_at`) VALUES
(1, 5, 'Pengajuan disetujui', 'Dinas luar Posko Bakauheni telah disetujui Kasubbag TU', 'ok', 0, '2026-07-25 10:33:26'),
(2, 5, 'Cek-in di luar radius geofence', '26 Jun 2026 - sedang menunggu verifikasi atasan', 'warn', 0, '2026-07-25 10:33:26'),
(3, 5, 'Slip tukin Juni tersedia', 'Total diterima Rp 3.400.000, tanpa potongan', 'info', 0, '2026-07-25 10:33:26'),
(4, 5, 'Pengingat cek-in', 'Kamu belum cek-in hari ini, batas toleransi 08:15 WIB', 'warn', 1, '2026-07-25 10:33:26'),
(5, 5, 'Izin sakit disetujui', '15 Mei 2026 telah disetujui', 'ok', 1, '2026-07-25 10:33:26');

-- --------------------------------------------------------

--
-- Table structure for table `pegawai`
--

CREATE TABLE `pegawai` (
  `id` int UNSIGNED NOT NULL,
  `nip` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kode_aktivasi` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Kode aktivasi mandiri sementara (KF-30) — NULL kalau sudah dipakai, atau kalau role admin/kasubbag (dibuatkan admin langsung)',
  `nama` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jabatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kelas_jabatan` tinyint UNSIGNED DEFAULT NULL,
  `seksi_id` int UNSIGNED DEFAULT NULL,
  `metode_absen` enum('fingerprint','geolocation') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fingerprint',
  `titik_lokasi_id` int UNSIGNED DEFAULT NULL COMMENT 'Titik tugas utama untuk pegawai lapangan',
  `status_kepegawaian` enum('aktif','cuti','nonaktif') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `nomor_hp` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notif_wa_aktif` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Preferensi tersimpan, belum ada pengirim WA sungguhan',
  `notif_email_aktif` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Preferensi tersimpan, belum ada pengirim email sungguhan',
  `notif_pengingat_cekin_aktif` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Toggle pengingat cek-in — siap dipakai cron pengingat di masa depan',
  `tmt_jabatan` date DEFAULT NULL,
  `foto_profil` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pegawai`
--

INSERT INTO `pegawai` (`id`, `nip`, `nama`, `jabatan`, `kelas_jabatan`, `seksi_id`, `metode_absen`, `titik_lokasi_id`, `status_kepegawaian`, `nomor_hp`, `email`, `tmt_jabatan`, `foto_profil`, `created_at`) VALUES
(1, '198501142010011041', 'Rian Saputra', 'Kasi Prasarana', 9, 2, 'fingerprint', NULL, 'aktif', '0812-xxxx-1041', 'rian.s@bptd2lampung.go.id', '2010-01-14', NULL, '2026-07-25 10:33:26'),
(2, '199002182019022018', 'Dewi Anjani', 'Staf Tata Usaha', 7, 1, 'fingerprint', NULL, 'aktif', '0812-xxxx-2018', 'dewi.a@bptd2lampung.go.id', '2019-02-18', NULL, '2026-07-25 10:33:26'),
(3, '198812172012121067', 'Ahmad Fauzan', 'Staf Sarana', 7, 3, 'fingerprint', NULL, 'aktif', '0812-xxxx-1067', 'ahmad.f@bptd2lampung.go.id', '2012-12-17', NULL, '2026-07-25 10:33:26'),
(4, '199105292019031029', 'Hendra Purnomo', 'Staf Tata Usaha', 6, 1, 'fingerprint', NULL, 'cuti', '0812-xxxx-1029', 'hendra.p@bptd2lampung.go.id', '2019-03-29', NULL, '2026-07-25 10:33:26'),
(5, '199203082019031005', 'Bayu Kurniawan', 'Staf LLASDP', 7, 4, 'geolocation', 1, 'aktif', '0812-xxxx-7841', 'bayu.k@bptd2lampung.go.id', '2019-03-08', NULL, '2026-07-25 10:33:26'),
(6, '199411102019121102', 'Doni Prasetyo', 'Staf Rampcheck', 7, 4, 'geolocation', 3, 'aktif', '0812-xxxx-1102', 'doni.p@bptd2lampung.go.id', '2019-12-10', NULL, '2026-07-25 10:33:26'),
(7, '199407152019032201', 'Siti Rahma', 'Staf LLASDP', 7, 4, 'geolocation', 1, 'aktif', '0812-xxxx-2201', 'siti.r@bptd2lampung.go.id', '2019-03-15', NULL, '2026-07-25 10:33:26'),
(8, '197908252005012008', 'Kartika Br Sitepu', 'Kasubbag Tata Usaha', 8, 1, 'fingerprint', NULL, 'aktif', '0812-xxxx-2008', 'kartika.s@bptd2lampung.go.id', '2005-01-25', NULL, '2026-07-25 10:33:26'),
(9, '200001012024011001', 'Septeo', 'Admin Sistem', NULL, NULL, 'fingerprint', NULL, 'aktif', NULL, NULL, NULL, NULL, '2026-07-28 11:56:55'),
(10, '200001012024011002', 'Rian', 'Kasubbag Tata Usaha', NULL, NULL, 'fingerprint', NULL, 'aktif', NULL, NULL, NULL, NULL, '2026-07-28 11:56:55'),
(11, '200001012024011003', 'Putra', 'Staf Lapangan', NULL, NULL, 'geolocation', NULL, 'aktif', NULL, NULL, NULL, NULL, '2026-07-28 11:56:55');

-- --------------------------------------------------------

--
-- Table structure for table `pengajuan`
--

CREATE TABLE `pengajuan` (
  `id` int UNSIGNED NOT NULL,
  `pegawai_id` int UNSIGNED NOT NULL,
  `jenis` enum('izin_sakit','cuti_tahunan','dinas_luar') COLLATE utf8mb4_unicode_ci NOT NULL,
  `tanggal` date NOT NULL,
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('menunggu','disetujui','ditolak') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'menunggu',
  `disetujui_oleh` int UNSIGNED DEFAULT NULL,
  `tanggal_pengajuan` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pengajuan`
--

INSERT INTO `pengajuan` (`id`, `pegawai_id`, `jenis`, `tanggal`, `keterangan`, `status`, `disetujui_oleh`, `tanggal_pengajuan`) VALUES
(1, 5, 'dinas_luar', '2026-06-29', 'Dinas luar Posko Bakauheni', 'disetujui', NULL, '2026-07-25 10:33:26'),
(2, 5, 'cuti_tahunan', '2026-06-24', 'Keperluan keluarga', 'disetujui', NULL, '2026-07-25 10:33:26'),
(3, 5, 'izin_sakit', '2026-05-15', 'Rawat jalan', 'disetujui', NULL, '2026-07-25 10:33:26'),
(4, 5, 'dinas_luar', '2026-07-03', 'Posko Bakauheni', 'menunggu', NULL, '2026-07-25 10:33:26');

-- --------------------------------------------------------

--
-- Table structure for table `seksi`
--

CREATE TABLE `seksi` (
  `id` int UNSIGNED NOT NULL,
  `nama_seksi` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `seksi`
--

INSERT INTO `seksi` (`id`, `nama_seksi`) VALUES
(4, 'LLASDP'),
(2, 'Prasarana'),
(3, 'Sarana'),
(1, 'Tata Usaha');

-- --------------------------------------------------------

--
-- Table structure for table `titik_lokasi_tugas`
--

CREATE TABLE `titik_lokasi_tugas` (
  `id` int UNSIGNED NOT NULL,
  `nama_lokasi` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alamat` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `radius_meter` int UNSIGNED NOT NULL DEFAULT '150',
  `status` enum('aktif','musiman','nonaktif') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `titik_lokasi_tugas`
--

INSERT INTO `titik_lokasi_tugas` (`id`, `nama_lokasi`, `alamat`, `latitude`, `longitude`, `radius_meter`, `status`, `created_at`) VALUES
(1, 'Jembatan Timbang Way Urang', 'Lampung Selatan', '-5.4291000', '105.2612000', 150, 'aktif', '2026-07-25 10:33:26'),
(2, 'Posko Lebaran Bakauheni', 'Musiman, Lampung Selatan', '-5.8721000', '105.7524000', 200, 'musiman', '2026-07-25 10:33:26'),
(3, 'Rampcheck Terminal Rajabasa', 'Bandar Lampung', '-5.3841000', '105.2456000', 100, 'aktif', '2026-07-25 10:33:26'),
(4, 'Titik Patroli Sungai Way Sekampung', 'Lampung Timur', '-5.2078000', '105.6890000', 180, 'nonaktif', '2026-07-25 10:33:26');

-- --------------------------------------------------------

--
-- Table structure for table `tukin`
--

CREATE TABLE `tukin` (
  `id` int UNSIGNED NOT NULL,
  `pegawai_id` int UNSIGNED NOT NULL,
  `periode_bulan` tinyint UNSIGNED NOT NULL,
  `periode_tahun` smallint UNSIGNED NOT NULL,
  `tukin_pokok` decimal(12,2) NOT NULL,
  `hari_kerja_efektif` tinyint UNSIGNED DEFAULT NULL,
  `total_potongan` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tukin_diterima` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','final') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tukin`
--

INSERT INTO `tukin` (`id`, `pegawai_id`, `periode_bulan`, `periode_tahun`, `tukin_pokok`, `hari_kerja_efektif`, `total_potongan`, `tukin_diterima`, `status`, `created_at`) VALUES
(1, 1, 6, 2026, '4200000.00', 22, '0.00', '4200000.00', 'final', '2026-07-25 10:33:26'),
(2, 2, 6, 2026, '3100000.00', 19, '75000.00', '3025000.00', 'final', '2026-07-25 10:33:26'),
(3, 5, 6, 2026, '3400000.00', 21, '0.00', '3400000.00', 'final', '2026-07-25 10:33:26'),
(4, 7, 6, 2026, '3400000.00', 21, '50000.00', '3350000.00', 'final', '2026-07-25 10:33:26');

-- --------------------------------------------------------

--
-- Table structure for table `tukin_detail`
--

CREATE TABLE `tukin_detail` (
  `id` int UNSIGNED NOT NULL,
  `tukin_id` int UNSIGNED NOT NULL,
  `absensi_id` int UNSIGNED NOT NULL,
  `aturan_id` int UNSIGNED NOT NULL,
  `nominal_potongan` decimal(12,2) NOT NULL,
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tukin_detail`
--

INSERT INTO `tukin_detail` (`id`, `tukin_id`, `absensi_id`, `aturan_id`, `nominal_potongan`, `keterangan`) VALUES
(1, 2, 2, 2, '25000.00', 'Terlambat 14 menit, 18 Juni 2026'),
(2, 2, 2, 2, '50000.00', 'Akumulasi keterlambatan lain bulan Juni 2026 (3x kejadian)'),
(3, 4, 6, 3, '50000.00', 'Cek-in 26 Juni 2026 di luar radius geofence, disetujui tidak sah oleh Kasubbag TU');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `pegawai_id` int UNSIGNED NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Hash bcrypt — gunakan password_hash() PHP, JANGAN simpan plain text',
  `role` enum('admin','kasubbag','pegawai') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pegawai',
  `last_login` datetime DEFAULT NULL,
  `percobaan_gagal` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Reset ke 0 setiap login berhasil',
  `terkunci_hingga` datetime DEFAULT NULL COMMENT 'Kalau terisi & masih di masa depan, login ditolak dulu apa pun passwordnya',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `pegawai_id`, `username`, `password`, `role`, `last_login`, `created_at`) VALUES
(1, 9, 'admin.septeo', '$2b$10$JyYsx/op6DGsNtWzWJOI0.rd1IM8UmM2A6xTzzHV4pR6euxepq8E.', 'admin', '2026-07-28 19:08:30', '2026-07-28 11:56:55'),
(2, 10, 'kasubbag.rian', '$2b$10$JyYsx/op6DGsNtWzWJOI0.rd1IM8UmM2A6xTzzHV4pR6euxepq8E.', 'kasubbag', '2026-07-28 19:10:22', '2026-07-28 11:56:55'),
(3, 11, 'pegawai.putra', '$2b$10$JyYsx/op6DGsNtWzWJOI0.rd1IM8UmM2A6xTzzHV4pR6euxepq8E.', 'pegawai', '2026-07-28 19:10:43', '2026-07-28 11:56:55');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_audit_trail_tukin`
-- (See below for the actual view)
--
CREATE TABLE `v_audit_trail_tukin` (
`tukin_detail_id` int unsigned
,`nama` varchar(100)
,`periode_bulan` tinyint unsigned
,`periode_tahun` smallint unsigned
,`nominal_potongan` decimal(12,2)
,`kode_aturan` varchar(10)
,`nama_aturan` varchar(150)
,`tanggal_absensi` date
,`jam_masuk` time
,`sumber_absen` enum('fingerprint','geolocation','sistem')
,`latitude` decimal(10,7)
,`longitude` decimal(10,7)
,`status_verifikasi` enum('otomatis_sah','perlu_verifikasi','disetujui','ditolak')
,`waktu_verifikasi` datetime
,`catatan_verifikasi` varchar(255)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_dashboard_kehadiran_hari_ini`
-- (See below for the actual view)
--
CREATE TABLE `v_dashboard_kehadiran_hari_ini` (
`absensi_id` int unsigned
,`nama` varchar(100)
,`nip` varchar(20)
,`nama_seksi` varchar(50)
,`sumber_absen` enum('fingerprint','geolocation','sistem')
,`jam_masuk` time
,`status_kehadiran` enum('tepat_waktu','terlambat','alpa','cuti')
,`status_radius` enum('dalam_radius','luar_radius','tidak_berlaku')
,`status_verifikasi` enum('otomatis_sah','perlu_verifikasi','disetujui','ditolak')
,`is_mock_location` tinyint(1)
,`status_sinkronisasi` enum('online','tersimpan_lokal','tersinkron')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_monitoring_kecurigaan`
-- (See below for the actual view)
--
CREATE TABLE `v_monitoring_kecurigaan` (
`nama` varchar(100)
,`nip` varchar(20)
,`nama_seksi` varchar(50)
,`total_offline` bigint
,`total_indikasi_lokasi_palsu` decimal(25,0)
,`status_jadwal` enum('sesuai','tidak_ada_jadwal','diluar_jadwal')
,`status_verifikasi` enum('otomatis_sah','perlu_verifikasi','disetujui','ditolak')
);

-- --------------------------------------------------------

--
-- Structure for view `v_audit_trail_tukin`
--
DROP TABLE IF EXISTS `v_audit_trail_tukin`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_audit_trail_tukin`  AS SELECT `td`.`id` AS `tukin_detail_id`, `p`.`nama` AS `nama`, `t`.`periode_bulan` AS `periode_bulan`, `t`.`periode_tahun` AS `periode_tahun`, `td`.`nominal_potongan` AS `nominal_potongan`, `ar`.`kode_aturan` AS `kode_aturan`, `ar`.`nama_aturan` AS `nama_aturan`, `a`.`tanggal` AS `tanggal_absensi`, `a`.`jam_masuk` AS `jam_masuk`, `a`.`sumber_absen` AS `sumber_absen`, `a`.`latitude` AS `latitude`, `a`.`longitude` AS `longitude`, `a`.`status_verifikasi` AS `status_verifikasi`, `a`.`waktu_verifikasi` AS `waktu_verifikasi`, `a`.`catatan_verifikasi` AS `catatan_verifikasi` FROM ((((`tukin_detail` `td` join `tukin` `t` on((`t`.`id` = `td`.`tukin_id`))) join `pegawai` `p` on((`p`.`id` = `t`.`pegawai_id`))) join `aturan_potongan` `ar` on((`ar`.`id` = `td`.`aturan_id`))) join `absensi` `a` on((`a`.`id` = `td`.`absensi_id`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_dashboard_kehadiran_hari_ini`
--
DROP TABLE IF EXISTS `v_dashboard_kehadiran_hari_ini`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_dashboard_kehadiran_hari_ini`  AS SELECT `a`.`id` AS `absensi_id`, `p`.`nama` AS `nama`, `p`.`nip` AS `nip`, `s`.`nama_seksi` AS `nama_seksi`, `a`.`sumber_absen` AS `sumber_absen`, `a`.`jam_masuk` AS `jam_masuk`, `a`.`status_kehadiran` AS `status_kehadiran`, `a`.`status_radius` AS `status_radius`, `a`.`status_verifikasi` AS `status_verifikasi`, `a`.`is_mock_location` AS `is_mock_location`, `a`.`status_sinkronisasi` AS `status_sinkronisasi` FROM ((`absensi` `a` join `pegawai` `p` on((`p`.`id` = `a`.`pegawai_id`))) left join `seksi` `s` on((`s`.`id` = `p`.`seksi_id`))) WHERE (`a`.`tanggal` = curdate())  ;

-- --------------------------------------------------------

--
-- Structure for view `v_monitoring_kecurigaan`
--
DROP TABLE IF EXISTS `v_monitoring_kecurigaan`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_monitoring_kecurigaan`  AS SELECT `p`.`nama` AS `nama`, `p`.`nip` AS `nip`, `s`.`nama_seksi` AS `nama_seksi`, count((case when (`a`.`status_sinkronisasi` in ('tersimpan_lokal','tersinkron')) then 1 end)) AS `total_offline`, sum(`a`.`is_mock_location`) AS `total_indikasi_lokasi_palsu`, `a`.`status_jadwal` AS `status_jadwal`, `a`.`status_verifikasi` AS `status_verifikasi` FROM ((`absensi` `a` join `pegawai` `p` on((`p`.`id` = `a`.`pegawai_id`))) left join `seksi` `s` on((`s`.`id` = `p`.`seksi_id`))) GROUP BY `p`.`id`, `a`.`status_jadwal`, `a`.`status_verifikasi`  ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `absensi`
--
ALTER TABLE `absensi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_absensi_harian` (`pegawai_id`,`tanggal`),
  ADD KEY `fk_absensi_titik` (`titik_lokasi_id`),
  ADD KEY `fk_absensi_jadwal` (`jadwal_dinas_id`),
  ADD KEY `fk_absensi_verifikator` (`diverifikasi_oleh`),
  ADD KEY `idx_absensi_status_sinkron` (`status_sinkronisasi`),
  ADD KEY `idx_absensi_mock` (`is_mock_location`);

--
-- Indexes for table `aturan_potongan`
--
ALTER TABLE `aturan_potongan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kode_aturan` (`kode_aturan`);

--
-- Indexes for table `jadwal_dinas_luar`
--
ALTER TABLE `jadwal_dinas_luar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_jadwal_pegawai` (`pegawai_id`),
  ADD KEY `fk_jadwal_titik` (`titik_lokasi_id`);

--
-- Indexes for table `metadata_perangkat`
--
ALTER TABLE `metadata_perangkat`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `absensi_id` (`absensi_id`);

--
-- Indexes for table `notifikasi`
--
ALTER TABLE `notifikasi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notifikasi_pegawai` (`pegawai_id`);

--
-- Indexes for table `pegawai`
--
ALTER TABLE `pegawai`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nip` (`nip`),
  ADD KEY `fk_pegawai_seksi` (`seksi_id`),
  ADD KEY `fk_pegawai_titik` (`titik_lokasi_id`);

--
-- Indexes for table `pengajuan`
--
ALTER TABLE `pengajuan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pengajuan_pegawai` (`pegawai_id`),
  ADD KEY `fk_pengajuan_verifikator` (`disetujui_oleh`);

--
-- Indexes for table `seksi`
--
ALTER TABLE `seksi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nama_seksi` (`nama_seksi`);

--
-- Indexes for table `titik_lokasi_tugas`
--
ALTER TABLE `titik_lokasi_tugas`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tukin`
--
ALTER TABLE `tukin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tukin_periode` (`pegawai_id`,`periode_bulan`,`periode_tahun`);

--
-- Indexes for table `tukin_detail`
--
ALTER TABLE `tukin_detail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_tukindetail_tukin` (`tukin_id`),
  ADD KEY `fk_tukindetail_absensi` (`absensi_id`),
  ADD KEY `fk_tukindetail_aturan` (`aturan_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pegawai_id` (`pegawai_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `absensi`
--
ALTER TABLE `absensi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `aturan_potongan`
--
ALTER TABLE `aturan_potongan`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `jadwal_dinas_luar`
--
ALTER TABLE `jadwal_dinas_luar`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `metadata_perangkat`
--
ALTER TABLE `metadata_perangkat`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notifikasi`
--
ALTER TABLE `notifikasi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `pegawai`
--
ALTER TABLE `pegawai`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `pengajuan`
--
ALTER TABLE `pengajuan`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `seksi`
--
ALTER TABLE `seksi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `titik_lokasi_tugas`
--
ALTER TABLE `titik_lokasi_tugas`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tukin`
--
ALTER TABLE `tukin`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tukin_detail`
--
ALTER TABLE `tukin_detail`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `absensi`
--
ALTER TABLE `absensi`
  ADD CONSTRAINT `fk_absensi_jadwal` FOREIGN KEY (`jadwal_dinas_id`) REFERENCES `jadwal_dinas_luar` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_absensi_pegawai` FOREIGN KEY (`pegawai_id`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_absensi_titik` FOREIGN KEY (`titik_lokasi_id`) REFERENCES `titik_lokasi_tugas` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_absensi_verifikator` FOREIGN KEY (`diverifikasi_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`dilakukan_oleh`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `jadwal_dinas_luar`
--
ALTER TABLE `jadwal_dinas_luar`
  ADD CONSTRAINT `fk_jadwal_pegawai` FOREIGN KEY (`pegawai_id`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_jadwal_titik` FOREIGN KEY (`titik_lokasi_id`) REFERENCES `titik_lokasi_tugas` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `log_perhitungan_tukin`
--
ALTER TABLE `log_perhitungan_tukin`
  ADD CONSTRAINT `fk_logtukin_users` FOREIGN KEY (`dihitung_oleh`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `metadata_perangkat`
--
ALTER TABLE `metadata_perangkat`
  ADD CONSTRAINT `fk_metadata_absensi` FOREIGN KEY (`absensi_id`) REFERENCES `absensi` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifikasi`
--
ALTER TABLE `notifikasi`
  ADD CONSTRAINT `fk_notifikasi_pegawai` FOREIGN KEY (`pegawai_id`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pegawai`
--
ALTER TABLE `pegawai`
  ADD CONSTRAINT `fk_pegawai_seksi` FOREIGN KEY (`seksi_id`) REFERENCES `seksi` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pegawai_titik` FOREIGN KEY (`titik_lokasi_id`) REFERENCES `titik_lokasi_tugas` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `pengajuan`
--
ALTER TABLE `pengajuan`
  ADD CONSTRAINT `fk_pengajuan_pegawai` FOREIGN KEY (`pegawai_id`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pengajuan_verifikator` FOREIGN KEY (`disetujui_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tukin`
--
ALTER TABLE `tukin`
  ADD CONSTRAINT `fk_tukin_pegawai` FOREIGN KEY (`pegawai_id`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tukin_detail`
--
ALTER TABLE `tukin_detail`
  ADD CONSTRAINT `fk_tukindetail_absensi` FOREIGN KEY (`absensi_id`) REFERENCES `absensi` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tukindetail_aturan` FOREIGN KEY (`aturan_id`) REFERENCES `aturan_potongan` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_tukindetail_tukin` FOREIGN KEY (`tukin_id`) REFERENCES `tukin` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_pegawai` FOREIGN KEY (`pegawai_id`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
