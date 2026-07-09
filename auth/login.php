<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $pdo  = getPDO();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // ── Rate limiting / lockout ──────────────────────────
        // Kalau akun sedang terkunci (terlalu banyak percobaan gagal), tolak
        // sebelum sempat cek password sama sekali.
        if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $minutesLeft = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
            $error = "Akun ini terkunci sementara karena terlalu banyak percobaan login gagal. Coba lagi dalam {$minutesLeft} menit, atau hubungi admin untuk membuka kunci.";
        } else {
            // Dummy hash dipakai kalau username tidak ditemukan, supaya waktu respons
            // "username tidak ada" dan "password salah" mirip (mempersulit user enumeration).
            $hashToCheck = $user['password'] ?? '$2y$10$abcdefghijklmnopqrstuuJZ8h0nq1p6xW1u4o2gk3d5q0f6b6C1O';
            $passwordOk  = password_verify($password, $hashToCheck) && $user;

            if ($passwordOk) {
                $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
                    ->execute([$user['id']]);
                setSession($user);
                header('Location: ' . BASE_URL . '/dashboard/index.php');
                exit;
            }

            if ($user) {
                $attempts = (int)$user['failed_login_attempts'] + 1;
                if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                    $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
                        ->execute([LOGIN_LOCKOUT_SECONDS, $user['id']]);
                    $error = 'Terlalu banyak percobaan gagal. Akun dikunci sementara selama ' . (int)(LOGIN_LOCKOUT_SECONDS / 60) . ' menit.';
                } else {
                    $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")
                        ->execute([$attempts, $user['id']]);
                    $remaining = LOGIN_MAX_ATTEMPTS - $attempts;
                    $error = "Username atau password salah. Percobaan tersisa: {$remaining}.";
                }
            } else {
                $error = 'Username atau password salah.';
            }
        }
    } else {
        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: #1a1a2e;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            background: #fff;
            border-radius: 16px;
            padding: 40px 36px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .3);
        }

        .login-icon {
            width: 60px;
            height: 60px;
            background: #9a031e;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .login-icon i {
            color: #fff;
            font-size: 28px;
        }

        .btn-login {
            background: #9a031e;
            color: #fff;
            border: none;
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-login:hover {
            background: #7a0218;
            color: #fff;
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="login-icon"><i class="bi bi-building-fill-check"></i></div>
        <h5 class="text-center fw-bold mb-1"><?= APP_NAME ?></h5>
        <p class="text-center text-muted small mb-4">Masuk ke sistem</p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Username</label>
                <input type="text" name="username" class="form-control" autofocus required>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-semibold">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn-login">Masuk</button>
        </form>

        <p class="text-center small mt-3 mb-0">
            Belum punya akun?
            <a href="<?= BASE_URL ?>/auth/register.php" class="fw-semibold text-decoration-none" style="color:#9a031e;">Daftar di sini</a>
        </p>
    </div>
</body>

</html>