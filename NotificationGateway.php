<?php
/**
 * NotificationGateway.php
 * -----------------------------------------------------------------------
 * Service class tunggal untuk semua pengiriman notifikasi eksternal
 * (WhatsApp via Fonnte + Email via SMTP/PHPMailer) di sistem SIMPEG-H.
 *
 * PRINSIP DESAIN:
 *   • Semua metode publik bersifat "fire-and-forget" — memanggil gateway
 *     ini TIDAK boleh memblokir transaksi utama. Kalau WA/email gagal,
 *     in-app notification yang sudah ada tetap tersimpan, dan error
 *     dicatat di error_log. Proses utama (approve, verifikasi, hitung
 *     tukin) TIDAK ikut rollback.
 *   • Kelas ini TIDAK tahu soal session atau $_POST — ia hanya menerima
 *     data bersih dan mengirimkannya.
 *   • Seluruh teks pesan dalam Bahasa Indonesia, gaya formal dinas.
 *
 * CARA PAKAI (di proses_*.php setelah transaksi DB commit):
 *   require_once 'NotificationGateway.php';
 *   $gw = NotificationGateway::fromDb($koneksi, $pegawai_id);
 *   $gw->kirimApprovalPengajuan($jenis_label, $tanggal_str, $status);
 *
 * DEPENDENSI:
 *   • config.php           — konstanta FONNTE_API_KEY, EMAIL_*, dll.
 *   • PHPMailer (opsional) — autoload via Composer atau manual include
 *     (lihat instruksi setup di bawah). Kalau PHPMailer tidak ada,
 *     email fallback ke fungsi mail() bawaan PHP atau dilewati.
 *
 * SETUP PHPMAILER TANPA COMPOSER (untuk Laragon offline):
 *   1. Unduh https://github.com/PHPMailer/PHPMailer/releases
 *   2. Ekstrak ke simpeg_h/lib/phpmailer/
 *   3. Pastikan di sana ada: PHPMailer.php, SMTP.php, Exception.php
 *
 * SETUP PHPMAILER DENGAN COMPOSER (cara baku):
 *   cd C:\dx\simpeg_h
 *   composer require phpmailer/phpmailer
 *   → generates vendor/autoload.php (sudah di-require di bawah)
 * -----------------------------------------------------------------------
 */

// Muat konfigurasi — koneksi.php sudah di-require oleh pemanggil
if (!defined('FONNTE_API_KEY')) {
    $config_path = __DIR__ . '/config.php';
    if (file_exists($config_path)) {
        require_once $config_path;
    }
}

// PHPMailer: coba vendor/autoload.php dulu (Composer), fallback ke lib manual
$_mailer_autoload = __DIR__ . '/vendor/autoload.php';
$_mailer_manual   = __DIR__ . '/lib/phpmailer/PHPMailer.php';

if (file_exists($_mailer_autoload)) {
    require_once $_mailer_autoload;
} elseif (file_exists($_mailer_manual)) {
    require_once $_mailer_manual;
    require_once __DIR__ . '/lib/phpmailer/SMTP.php';
    require_once __DIR__ . '/lib/phpmailer/Exception.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

class NotificationGateway
{
    private string  $nama;
    private ?string $nomor_hp;
    private ?string $email;
    private bool    $wa_aktif;
    private bool    $email_aktif;

    // -----------------------------------------------------------------
    // Constructor & Factory
    // -----------------------------------------------------------------

    public function __construct(
        string  $nama,
        ?string $nomor_hp,
        ?string $email,
        bool    $wa_aktif    = true,
        bool    $email_aktif = true
    ) {
        $this->nama        = $nama;
        $this->nomor_hp    = $nomor_hp;
        $this->email       = $email;
        $this->wa_aktif    = $wa_aktif;
        $this->email_aktif = $email_aktif;
    }

    /**
     * Buat instance dari database (cara yang paling sering dipakai).
     * Mengembalikan NULL kalau pegawai_id tidak ditemukan.
     */
    public static function fromDb(mysqli $koneksi, int $pegawai_id): ?self
    {
        $stmt = mysqli_prepare($koneksi,
            "SELECT nama, nomor_hp, email, notif_wa_aktif, notif_email_aktif
             FROM pegawai WHERE id = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $pegawai_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) {
            return null;
        }

        return new self(
            $row['nama'],
            $row['nomor_hp'],
            $row['email'],
            (bool) $row['notif_wa_aktif'],
            (bool) $row['notif_email_aktif']
        );
    }

    // -----------------------------------------------------------------
    // PUBLIC TRIGGER METHODS (satu metode per kejadian bisnis)
    // Pemanggil hanya perlu tahu kejadiannya, TIDAK perlu susun teks.
    // -----------------------------------------------------------------

    /**
     * [1] Notifikasi hasil approval pengajuan izin/cuti/dinas luar.
     *
     * Dipanggil dari: proses_approve_pengajuan.php
     *
     * @param string $jenis_label  Teks ramah manusia, mis. "Izin Sakit"
     * @param string $tanggal_str  Format "DD/MM/YYYY", mis. "23/09/2026"
     * @param string $status_baru  'disetujui' atau 'ditolak'
     */
    public function kirimApprovalPengajuan(
        string $jenis_label,
        string $tanggal_str,
        string $status_baru
    ): void {
        $icon   = ($status_baru === 'disetujui') ? '✅' : '❌';
        $kata   = ($status_baru === 'disetujui') ? 'DISETUJUI' : 'DITOLAK';
        $saran  = ($status_baru === 'disetujui')
            ? 'Tidak perlu tindakan lebih lanjut.'
            : 'Silakan hubungi Kasubbag TU untuk keterangan lebih lanjut.';

        $pesan_wa = <<<WA
            {$icon} *SIMPEG-H — Informasi Pengajuan*

            Yth. {$this->nama},

            Pengajuan *{$jenis_label}* untuk tanggal *{$tanggal_str}* Anda telah *{$kata}* oleh Kasubbag TU.

            {$saran}

            _Pesan ini dikirim otomatis oleh sistem SIMPEG-H BPTD Kelas II Lampung._
            WA;

        $subjek_email = "[SIMPEG-H] Pengajuan {$jenis_label} {$kata}";
        $badan_email  = $this->templateEmail(
            "Pengajuan {$jenis_label} {$kata}",
            "<p>Yth. <strong>{$this->nama}</strong>,</p>
            <p>Pengajuan <strong>{$jenis_label}</strong> untuk tanggal <strong>{$tanggal_str}</strong>
            telah <strong style='color:" . ($status_baru === 'disetujui' ? '#16a34a' : '#dc2626') . "'>{$kata}</strong>
            oleh Kasubbag TU BPTD Kelas II Lampung.</p>
            <p>{$saran}</p>"
        );

        $this->kirim($pesan_wa, $subjek_email, $badan_email);
    }

    /**
     * [2] Notifikasi hasil verifikasi absensi (perlu_verifikasi → disetujui/ditolak).
     *
     * Dipanggil dari: proses_verifikasi.php
     *
     * @param string $tanggal_str  Format "DD/MM/YYYY"
     * @param string $status_baru  'disetujui' atau 'ditolak'
     * @param string $catatan      Catatan verifikasi dari admin
     */
    public function kirimVerifikasiAbsensi(
        string $tanggal_str,
        string $status_baru,
        string $catatan
    ): void {
        $icon  = ($status_baru === 'disetujui') ? '✅' : '⚠️';
        $kata  = ($status_baru === 'disetujui') ? 'DIVERIFIKASI SAH' : 'DITOLAK (tidak sah)';
        $warna = ($status_baru === 'disetujui') ? '#16a34a' : '#dc2626';

        $pesan_wa = <<<WA
            {$icon} *SIMPEG-H — Verifikasi Kehadiran*

            Yth. {$this->nama},

            Catatan kehadiran Anda pada tanggal *{$tanggal_str}* telah *{$kata}* oleh Kasubbag TU.

            📝 Catatan: _{$catatan}_

            Silakan cek detail di sistem SIMPEG-H.

            _Pesan ini dikirim otomatis oleh sistem SIMPEG-H BPTD Kelas II Lampung._
            WA;

        $subjek_email = "[SIMPEG-H] Verifikasi Kehadiran {$tanggal_str} — {$kata}";
        $badan_email  = $this->templateEmail(
            "Hasil Verifikasi Kehadiran",
            "<p>Yth. <strong>{$this->nama}</strong>,</p>
            <p>Catatan kehadiran Anda pada tanggal <strong>{$tanggal_str}</strong> telah
            <strong style='color:{$warna}'>{$kata}</strong> oleh Kasubbag TU.</p>
            <p><strong>Catatan:</strong> {$catatan}</p>
            <p>Silakan masuk ke sistem SIMPEG-H untuk melihat rincian lebih lanjut.</p>"
        );

        $this->kirim($pesan_wa, $subjek_email, $badan_email);
    }

    /**
     * [3] Notifikasi Tukin sudah dihitung/difinalisasi untuk satu periode.
     *
     * Dipanggil dari: proses_hitung_tukin.php / proses_finalisasi_tukin.php
     *
     * @param string $periode_label  Mis. "September 2026"
     * @param float  $tukin_diterima Nominal bersih yang diterima
     * @param float  $total_potongan Total potongan
     * @param string $status         'draft' atau 'final'
     */
    public function kirimInfoTukin(
        string $periode_label,
        float  $tukin_diterima,
        float  $total_potongan,
        string $status = 'draft'
    ): void {
        $status_label  = ($status === 'final') ? 'FINAL' : 'DRAFT';
        $icon          = ($status === 'final') ? '💰' : '📊';
        $nominal_bersih = 'Rp ' . number_format($tukin_diterima, 0, ',', '.');
        $potongan_fmt   = 'Rp ' . number_format($total_potongan, 0, ',', '.');

        $pesan_wa = <<<WA
            {$icon} *SIMPEG-H — Info Tunjangan Kinerja ({$status_label})*

            Yth. {$this->nama},

            Perhitungan Tunjangan Kinerja periode *{$periode_label}* telah tersedia.

            📋 Status      : {$status_label}
            ➖ Total Potongan : {$potongan_fmt}
            💵 Tunjangan Bersih : *{$nominal_bersih}*

            Silakan login ke SIMPEG-H untuk melihat rincian potongan.

            _Pesan ini dikirim otomatis oleh sistem SIMPEG-H BPTD Kelas II Lampung._
            WA;

        $subjek_email = "[SIMPEG-H] Rekap Tukin {$periode_label} — {$status_label}";
        $badan_email  = $this->templateEmail(
            "Rekap Tunjangan Kinerja {$periode_label}",
            "<p>Yth. <strong>{$this->nama}</strong>,</p>
            <p>Perhitungan Tunjangan Kinerja periode <strong>{$periode_label}</strong>
            telah berstatus <strong>{$status_label}</strong>.</p>
            <table style='border-collapse:collapse;width:100%;max-width:400px'>
              <tr><td style='padding:8px;border:1px solid #e5e7eb'>Total Potongan</td>
                  <td style='padding:8px;border:1px solid #e5e7eb;color:#dc2626'>{$potongan_fmt}</td></tr>
              <tr><td style='padding:8px;border:1px solid #e5e7eb'><strong>Tunjangan Bersih</strong></td>
                  <td style='padding:8px;border:1px solid #e5e7eb;color:#16a34a'><strong>{$nominal_bersih}</strong></td></tr>
            </table>
            <p style='margin-top:16px'>Silakan login ke sistem SIMPEG-H untuk melihat rincian lengkap potongan per hari.</p>"
        );

        $this->kirim($pesan_wa, $subjek_email, $badan_email);
    }

    /**
     * [4] Notifikasi pengingat cek-in (untuk cron job pengingat pagi).
     * Hanya dikirim kalau notif_pengingat_cekin_aktif = 1 di pegawai.
     *
     * Dipanggil dari: cron_pengingat_cekin.php (yang kamu buat sendiri)
     */
    public function kirimPengingatCekin(): void
    {
        $jam_sekarang = date('H:i');
        $pesan_wa = <<<WA
            ⏰ *SIMPEG-H — Pengingat Cek-In*

            Yth. {$this->nama},

            Anda belum melakukan cek-in hari ini. Jam kerja sudah dimulai (pukul {$jam_sekarang}).

            Silakan buka SIMPEG-H dan lakukan cek-in segera.

            _Pesan ini dikirim otomatis oleh sistem SIMPEG-H._
            WA;

        $this->kirimWa($pesan_wa);
    }

    // -----------------------------------------------------------------
    // PRIVATE HELPERS
    // -----------------------------------------------------------------

    /**
     * Dispatcher utama: kirim WA dan email secara paralel (non-blocking
     * karena keduanya hanya HTTP/SMTP call satu arah).
     */
    private function kirim(string $pesan_wa, string $subjek, string $badan_html): void
    {
        if ($this->wa_aktif) {
            $this->kirimWa($pesan_wa);
        }
        if ($this->email_aktif) {
            $this->kirimEmail($subjek, $badan_html);
        }
    }

    /**
     * Kirim WA via Fonnte REST API.
     * Docs: https://fonnte.com/docs
     */
    private function kirimWa(string $pesan): void
    {
        // Normalkan nomor HP ke format 628xxxx (Fonnte minta tanpa + dan tanpa 0 di awal)
        $nomor = $this->normalisasiNomorHp($this->nomor_hp);
        if (!$nomor) {
            $this->log('WA', 'SKIP', 'Nomor HP pegawai kosong atau tidak valid');
            return;
        }

        if (!defined('NOTIF_WA_ENABLED') || !NOTIF_WA_ENABLED) {
            $this->log('WA', 'DISABLED', "→ {$nomor}: " . mb_substr($pesan, 0, 50) . '…');
            return;
        }

        if (!defined('FONNTE_API_KEY') || FONNTE_API_KEY === 'GANTI_DENGAN_API_KEY_FONNTE_KAMU') {
            $this->log('WA', 'NO_KEY', 'FONNTE_API_KEY belum diisi di config.php');
            return;
        }

        $payload = [
            'target'  => $nomor,
            'message' => trim(preg_replace('/^[ \t]+/m', '', $pesan)), // hapus indentasi dalam heredoc
            'delay'   => 0,
            'schedule'=> 0,
            'typing'  => false,
        ];

        $ch = curl_init('https://api.fonnte.com/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Authorization: ' . FONNTE_API_KEY],
            CURLOPT_TIMEOUT        => 10, // 10 detik timeout — jangan terlalu lama
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $this->log('WA', 'CURL_ERR', $curl_err);
            return;
        }

        $resp_decoded = json_decode($response, true);
        $sukses = ($http_code === 200 && ($resp_decoded['status'] ?? false) === true);
        $status = $sukses ? 'OK' : "FAIL [{$http_code}]";
        $this->log('WA', $status, "→ {$nomor}: " . ($resp_decoded['reason'] ?? $response));
    }

    /**
     * Kirim email via SMTP menggunakan PHPMailer.
     * Fallback ke PHP mail() kalau PHPMailer tidak terinstal.
     */
    private function kirimEmail(string $subjek, string $badan_html): void
    {
        if (!$this->email || !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->log('EMAIL', 'SKIP', 'Alamat email pegawai kosong atau tidak valid');
            return;
        }

        if (!defined('NOTIF_EMAIL_ENABLED') || !NOTIF_EMAIL_ENABLED) {
            $this->log('EMAIL', 'DISABLED', "→ {$this->email}: {$subjek}");
            return;
        }

        // Coba PHPMailer dulu (lebih handal, SMTP auth, TLS)
        if (class_exists(PHPMailer::class)) {
            $this->kirimEmailPHPMailer($subjek, $badan_html);
        } else {
            // Fallback: PHP mail() bawaan (tanpa auth SMTP — hanya bekerja kalau
            // server sudah dikonfigurasi sendmail/postfix, seperti di VPS prod)
            $this->kirimEmailFallback($subjek, $badan_html);
        }
    }

    private function kirimEmailPHPMailer(string $subjek, string $badan_html): void
    {
        try {
            $mail = new PHPMailer(true);

            // Konfigurasi server
            $mail->isSMTP();
            $mail->Host       = EMAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = EMAIL_USERNAME;
            $mail->Password   = EMAIL_PASSWORD;
            $mail->SMTPSecure = (EMAIL_ENCRYPTION === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = EMAIL_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 10;

            // Pengirim & penerima
            $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
            $mail->addAddress($this->email, $this->nama);
            $mail->addReplyTo(EMAIL_FROM, EMAIL_FROM_NAME);

            // Konten
            $mail->isHTML(true);
            $mail->Subject = $subjek;
            $mail->Body    = $badan_html;
            $mail->AltBody = strip_tags(str_replace(['</p>','<br>','<br/>'], "\n", $badan_html));

            $mail->send();
            $this->log('EMAIL', 'OK', "→ {$this->email}: {$subjek}");

        } catch (MailerException $e) {
            $this->log('EMAIL', 'MAILER_ERR', $e->getMessage());
        } catch (\Throwable $e) {
            $this->log('EMAIL', 'ERR', $e->getMessage());
        }
    }

    private function kirimEmailFallback(string $subjek, string $badan_html): void
    {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM . ">\r\n";

        $sukses = @mail($this->email, $subjek, $badan_html, $headers);
        $this->log('EMAIL', $sukses ? 'OK_FALLBACK' : 'FAIL_FALLBACK', "→ {$this->email}: {$subjek}");
    }

    /**
     * Template HTML email seragam bergaya corporate sederhana.
     */
    private function templateEmail(string $judul_konten, string $isi_html): string
    {
        $app_url = defined('APP_URL') ? APP_URL : '#';
        $tahun   = date('Y');
        return <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$judul_konten}</title></head>
        <body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:32px 16px">
              <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)">
                <!-- HEADER -->
                <tr><td style="background:#1e3a5f;padding:24px 32px">
                  <h1 style="margin:0;color:#fff;font-size:20px;font-weight:700">SIMPEG-H</h1>
                  <p style="margin:4px 0 0;color:#93c5fd;font-size:13px">Sistem Informasi Kepegawaian Terintegrasi<br>BPTD Kelas II Lampung</p>
                </td></tr>
                <!-- SUBHEADER -->
                <tr><td style="background:#dbeafe;padding:12px 32px">
                  <h2 style="margin:0;font-size:15px;color:#1e3a5f;font-weight:600">{$judul_konten}</h2>
                </td></tr>
                <!-- BODY -->
                <tr><td style="padding:28px 32px;font-size:14px;line-height:1.7;color:#374151">
                  {$isi_html}
                </td></tr>
                <!-- CTA BUTTON -->
                <tr><td style="padding:0 32px 28px;text-align:center">
                  <a href="{$app_url}" style="display:inline-block;background:#1e3a5f;color:#fff;text-decoration:none;padding:10px 28px;border-radius:6px;font-size:14px;font-weight:600">Buka SIMPEG-H</a>
                </td></tr>
                <!-- FOOTER -->
                <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
                  <p style="margin:0;font-size:12px;color:#9ca3af">
                    Email ini dikirim otomatis oleh sistem SIMPEG-H.<br>
                    Mohon tidak membalas email ini langsung.<br>
                    &copy; {$tahun} BPTD Kelas II Lampung
                  </p>
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    /**
     * Normalisasi nomor HP ke format yang diterima Fonnte (628xxxx).
     * Mendukung format: 08xx, +628xx, 628xx, dengan/tanpa spasi/dash.
     */
    private function normalisasiNomorHp(?string $nomor): ?string
    {
        if (!$nomor) return null;

        // Hapus semua karakter non-digit
        $nomor = preg_replace('/\D/', '', $nomor);

        if (empty($nomor)) return null;

        // Konversi ke awalan 62
        if (str_starts_with($nomor, '08')) {
            $nomor = '62' . substr($nomor, 1);
        } elseif (str_starts_with($nomor, '8')) {
            $nomor = '62' . $nomor;
        } elseif (str_starts_with($nomor, '628')) {
            // sudah benar
        } elseif (str_starts_with($nomor, '0062')) {
            $nomor = '62' . substr($nomor, 4);
        }

        // Validasi panjang: nomor Indonesia 628xx biasanya 11-14 digit total
        if (strlen($nomor) < 10 || strlen($nomor) > 15) {
            return null;
        }

        return $nomor;
    }

    /**
     * Catat aktivitas notifikasi ke error_log PHP.
     * Format: [SIMPEG-H NOTIF] {CHANNEL} {STATUS} — {detail}
     */
    private function log(string $channel, string $status, string $detail): void
    {
        if (!defined('NOTIF_LOG_ENABLED') || !NOTIF_LOG_ENABLED) return;
        error_log(sprintf(
            '[SIMPEG-H NOTIF] %s %s — %s | pegawai: %s',
            $channel,
            $status,
            $detail,
            $this->nama
        ));
    }
}
