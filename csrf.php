<?php
/**
 * csrf.php
 * ---------------------------------------------------------------------
 * Proteksi CSRF sederhana berbasis token acak per sesi login.
 *
 * CARA PAKAI:
 * 1. require file ini SETELAH session_start() aktif (cek_sesi.php dan
 *    login.php sudah melakukan ini otomatis).
 * 2. Di setiap <form method="POST">, sisipkan <?php echo csrf_field(); ?>
 *    tepat setelah tag <form ...>.
 * 3. Di baris paling atas proses_*.php (setelah session_start()),
 *    panggil csrf_verify_or_die('halaman_tujuan.php') — kalau token
 *    tidak cocok/tidak ada, otomatis redirect & exit sebelum kode lain
 *    di file itu sempat jalan.
 *
 * KENAPA INI PERLU:
 * Tanpa token ini, situs lain bisa memicu browser korban (yang sedang
 * login) mengirim form POST ke proses_*.php tanpa sepengetahuannya
 * (cross-site request forgery) — mis. form tersembunyi yang otomatis
 * submit ke proses_verifikasi.php begitu korban membuka halaman jahat.
 * ---------------------------------------------------------------------
 */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Cetak hidden input siap pakai di dalam <form>. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool {
    return isset($_SESSION['csrf_token']) && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Dipanggil di awal proses_*.php. Kalau token tidak valid, langsung
 * redirect ke $redirect_ke (dengan ?error=csrf) dan hentikan eksekusi
 * file — baris-baris setelah pemanggilan ini tidak akan pernah jalan.
 */
function csrf_verify_or_die(string $redirect_ke): void {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_valid($token)) {
        $pemisah = (strpos($redirect_ke, '?') !== false) ? '&' : '?';
        header('Location: ' . $redirect_ke . $pemisah . 'error=csrf');
        exit;
    }
}
