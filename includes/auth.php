<?php
require_once __DIR__ . '/../config/app.php';

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // ── Hardening cookie sesi ─────────────────────────────
        // HttpOnly: cookie tidak bisa dibaca lewat JavaScript (mitigasi pencurian
        //           sesi lewat XSS).
        // Secure  : cookie hanya dikirim lewat HTTPS — otomatis terdeteksi dari
        //           request saat ini, supaya tidak merusak login di lingkungan
        //           HTTP-only seperti XAMPP lokal yang belum pasang SSL.
        // SameSite: 'Lax' — cukup untuk mencegah cookie ikut terkirim di request
        //           cross-site (CSRF-adjacent hardening), tanpa mengganggu alur
        //           navigasi biasa (klik link dari luar, dsb).
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path'     => BASE_URL . '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool
{
    startSession();
    if (empty($_SESSION['user_id'])) return false;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        sessionDestroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    touchLastSeen((int)$_SESSION['user_id']);
    return true;
}

// Catat "terakhir aktif" user ke database, dipakai untuk fitur "Sedang Login".
// Di-throttle (maksimal sekali per 60 detik per sesi) supaya tidak menulis ke
// DB di setiap request — cukup untuk mendeteksi user yang online dalam jendela
// ONLINE_THRESHOLD_SECONDS (5 menit).
function touchLastSeen(int $userId): void
{
    if (!$userId || !function_exists('getPDO')) return; // database.php mungkin belum di-require di titik ini
    $now = time();
    if (isset($_SESSION['last_seen_sync']) && ($now - $_SESSION['last_seen_sync']) < 60) return;
    try {
        getPDO()->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ?")->execute([$userId]);
        $_SESSION['last_seen_sync'] = $now;
    } catch (Throwable $e) {
        // Diamkan — kolom last_seen_at mungkin belum dimigrasi di DB lama; jangan sampai gagal login/akses karena ini.
    }
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
}

function requireRole(array $roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        die('<div style="padding:40px;font-family:sans-serif"><h3>403 — Akses Ditolak</h3><p>Anda tidak memiliki hak akses ke halaman ini.</p><a href="' . BASE_URL . '/dashboard/index.php">Kembali</a></div>');
    }
}

function setSession(array $user): void
{
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['full_name'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['last_activity'] = time();
}

function sessionDestroy(): void
{
    startSession();
    $_SESSION = [];
    session_destroy();
}

function currentUser(): array
{
    startSession();
    return [
        'id'   => $_SESSION['user_id']   ?? 0,
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
    ];
}

// Helper: cek apakah role saat ini termasuk dalam array
function hasRole(array $roles): bool
{
    startSession();
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}
