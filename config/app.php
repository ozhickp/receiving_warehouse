<?php
// ── Environment & Error Handling ──────────────────────────
// PENTING: default-nya 'production' (fail-safe) — supaya kalau file ini
// ter-upload ke server tanpa sempat diubah, aplikasi TIDAK menampilkan
// detail error/stack trace ke pengunjung secara default.
//
// Untuk development di localhost/XAMPP, ubah baris di bawah ini jadi:
//     define('APP_ENV', 'development');
// supaya error PHP tampil langsung di browser (memudahkan debugging).
define('APP_ENV', 'production');

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');       // JANGAN tampilkan error ke pengunjung
    ini_set('display_startup_errors', '0');
}

// Selalu catat error ke file log, di kedua environment, supaya tetap bisa
// ditelusuri tanpa membocorkan apa pun ke pengunjung.
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/php_error.log');
unset($logDir);

// Tangkap exception yang tidak ke-catch di manapun (mis. lupa try/catch di
// halaman baru) supaya pengunjung tetap dapat pesan yang rapi, bukan stack
// trace mentah yang bisa membocorkan path folder atau query SQL.
set_exception_handler(function (Throwable $e) {
    error_log('[Uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (APP_ENV === 'development') {
        echo '<pre style="padding:20px;background:#fee;color:#900;white-space:pre-wrap;">'
            . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString())
            . '</pre>';
    } else {
        echo '<div style="padding:40px;font-family:sans-serif;text-align:center;">'
            . '<h3>Terjadi kesalahan pada sistem</h3>'
            . '<p>Silakan coba lagi beberapa saat, atau hubungi admin jika masalah berlanjut.</p>'
            . '</div>';
    }
});

define('APP_NAME',    'Receiving Warehouse');
define('APP_VERSION', '2.0.0');
define('BASE_URL',    '/receiving_warehouse'); // sesuaikan dengan path XAMPP

// Session timeout: 8 jam
define('SESSION_TIMEOUT', 28800);

// ── Kebijakan Keamanan Login ─────────────────────────────
define('LOGIN_MAX_ATTEMPTS',  5);    // percobaan gagal sebelum akun dikunci sementara
define('LOGIN_LOCKOUT_SECONDS', 900); // lama penguncian: 15 menit
define('PASSWORD_MIN_LENGTH', 8);

// Dianggap "online" kalau aktivitas terakhir kurang dari ini (detik).
// Berbeda dari SESSION_TIMEOUT (yang menentukan kapan sesi benar-benar habis).
define('ONLINE_THRESHOLD_SECONDS', 300); // 5 menit