<?php
/**
 * upload_helpers.php
 * ============================================================================
 * Fungsi validasi upload foto yang dipakai proses_cekin.php.
 * Tidak ada side-effect (tidak menulis file, tidak redirect).
 *
 * URUTAN PEMERIKSAAN (sesuai spesifikasi):
 *   a. Semua elemen $_FILES harus skalar (cegah array injection / TypeError)
 *   b. Peta kode error PHP upload
 *   c. is_uploaded_file() — bisa dinonaktifkan untuk uji CLI
 *   d. filesize() file sebenarnya: > 0 dan <= 3 MB
 *   e. MIME dari ISI file via finfo (whitelist: image/jpeg, image/png)
 *   f. getimagesize() — konsistensi tipe + dimensi + batas piksel (anti-bomb)
 *
 * CATATAN KEAMANAN:
 *   - Sama sekali tidak mempercayai nama file atau Content-Type dari klien.
 *   - Jika ekstensi fileinfo tidak ada, selalu tolak upload (GAGAL-AMAN).
 *   - Detail kegagalan e/f tidak pernah diteruskan ke redirect URL;
 *     hanya dicatat di error_log oleh pemanggil.
 * ============================================================================
 */

/**
 * Validasi berkas foto yang diupload.
 *
 * @param array $berkas  Satu elemen dari $_FILES (mis. $_FILES['foto'])
 * @param bool  $cek_is_uploaded  True (default) = panggil is_uploaded_file().
 *                                False untuk pengujian CLI (fixture buatan).
 * @return array{ok: bool, kode: string, mime: string|null}
 *   - ok   : true = lulus semua cek
 *   - kode : kode redirect jika gagal; 'ok' jika lulus
 *   - mime : string MIME yang terdeteksi (hanya jika lulus atau gagal di step e/f)
 */
function validasi_upload_foto(array $berkas, bool $cek_is_uploaded = true): array
{
    // ── a. Cek semua elemen wajib adalah SKALAR (cegah array-injection & TypeError) ──
    foreach (['name', 'tmp_name', 'error', 'size'] as $kunci) {
        if (!isset($berkas[$kunci]) || is_array($berkas[$kunci])) {
            return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => null];
        }
    }

    $error    = (int) $berkas['error'];
    $tmp      = (string) $berkas['tmp_name'];

    // ── b. Peta kode error PHP upload ─────────────────────────────────────────
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'kode' => 'gagal_foto_wajib', 'mime' => null];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'kode' => 'gagal_ukuran_foto', 'mime' => null];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => null];
    }

    // ── c. is_uploaded_file (dilewati bila $cek_is_uploaded = false) ──────────
    if ($cek_is_uploaded && !is_uploaded_file($tmp)) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => null];
    }

    // ── d. Ukuran file SEBENARNYA (bukan nilai dari klien) ───────────────────
    // filesize() membaca dari filesystem, bukan dari header HTTP —
    // tidak bisa ditipu oleh nilai $_FILES['size'] yang dimanipulasi.
    $ukuran = @filesize($tmp);
    if ($ukuran === false || $ukuran <= 0) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => null];
    }
    if ($ukuran > 3 * 1024 * 1024) {
        return ['ok' => false, 'kode' => 'gagal_ukuran_foto', 'mime' => null];
    }

    // ── e. MIME dari ISI file — GAGAL-AMAN jika fileinfo tidak ada ───────────
    // Jika fileinfo tidak tersedia, TOLAK upload (jangan loloskan begitu saja).
    if (!function_exists('finfo_open')) {
        // Tidak mungkin memvalidasi konten — lebih aman ditolak daripada diloloskan
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => null];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => null];
    }
    $mime = finfo_file($finfo, $tmp);
    finfo_close($finfo);

    $mime_diizinkan = ['image/jpeg', 'image/png'];
    if ($mime === false || !in_array($mime, $mime_diizinkan, true)) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => ($mime ?: null)];
    }

    // ── f. getimagesize: konsistensi tipe + dimensi + batas piksel ───────────
    // Dilakukan SEBELUM decode GD untuk menangkap decompression-bomb
    // tanpa memuat seluruh gambar ke memori.
    // getimagesize() membaca header saja (bukan decode penuh) sehingga aman.
    $info_gambar = @getimagesize($tmp);
    if ($info_gambar === false) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => $mime];
    }

    $lebar  = (int) $info_gambar[0];
    $tinggi = (int) $info_gambar[1];
    $tipe   = $info_gambar[2]; // konstanta IMAGETYPE_*

    if ($lebar <= 0 || $tinggi <= 0) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => $mime];
    }

    // Batas piksel: cegah decompression bomb (mis. PNG 20000x20000, file kecil)
    // 16 juta piksel ≈ gambar 4000x4000 — lebih dari cukup untuk foto selfie
    if (($lebar * $tinggi) > 16_000_000) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => $mime];
    }

    // Konsistensi: tipe dari getimagesize harus cocok dengan MIME dari finfo
    $tipe_cocok = match ($mime) {
        'image/jpeg' => $tipe === IMAGETYPE_JPEG,
        'image/png'  => $tipe === IMAGETYPE_PNG,
        default      => false,
    };
    if (!$tipe_cocok) {
        return ['ok' => false, 'kode' => 'gagal_isi_foto', 'mime' => $mime];
    }

    return ['ok' => true, 'kode' => 'ok', 'mime' => $mime];
}
