<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$pdo     = getPDO();
$errors  = [];
$success = false;

// Admin sengaja TIDAK termasuk di sini — role dengan akses penuh tidak boleh
// bisa dibuat sendiri lewat halaman publik. Admin baru harus dibuat oleh admin lain
// lewat menu Kelola User.
$allowedRoles = [
    'receiving' => 'Receiving',
    'ppic'      => 'PPIC',
    'manager'   => 'Manager',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    $username = trim($_POST['username']        ?? '');
    $fullName = trim($_POST['full_name']       ?? '');
    $role     = $_POST['role']                 ?? '';
    $password = $_POST['password']             ?? '';
    $confirm  = $_POST['confirm_password']     ?? '';

    if (!$username)                              $errors[] = 'Username wajib diisi.';
    if (!$fullName)                              $errors[] = 'Nama lengkap wajib diisi.';
    if (!array_key_exists($role, $allowedRoles)) $errors[] = 'Role tidak valid.';
    $errors = array_merge($errors, passwordPolicyErrors($password));
    if ($password !== $confirm)                  $errors[] = 'Konfirmasi password tidak cocok.';

    if (empty($errors)) {
        try {
            $pdo->prepare("INSERT INTO users (username, password, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]);
            $success = true;
        } catch (PDOException $e) {
            $errors[] = 'Username sudah digunakan.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: #1a1a2e;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 0;
        }

        .login-card {
            background: #fff;
            border-radius: 16px;
            padding: 40px 36px;
            width: 100%;
            max-width: 420px;
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
        <div class="login-icon"><i class="bi bi-person-plus-fill"></i></div>
        <h5 class="text-center fw-bold mb-1"><?= APP_NAME ?></h5>
        <p class="text-center text-muted small mb-4">Daftar akun baru</p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2 small">
                Akun berhasil dibuat. Silakan
                <a href="<?= BASE_URL ?>/auth/login.php">login di sini</a>.
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger py-2 small">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Username</label>
                    <input type="text" name="username" class="form-control"
                        value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nama Lengkap</label>
                    <input type="text" name="full_name" class="form-control"
                        value="<?= e($_POST['full_name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Role</label>
                    <select name="role" class="form-select" required>
                        <option value="">-- Pilih Role --</option>
                        <?php foreach ($allowedRoles as $val => $label): ?>
                            <option value="<?= $val ?>" <?= (($_POST['role'] ?? '') === $val) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        <b>Receiving</b>: Stock In/Out, konfirmasi schedule, weighing.<br>
                        <b>PPIC</b>: Input schedule supplier.<br>
                        <b>Manager</b>: Lihat dashboard & history.
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Password</label>
                    <input type="password" name="password" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
                    <div class="form-text">Minimal <?= PASSWORD_MIN_LENGTH ?> karakter, kombinasi huruf dan angka.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Konfirmasi Password</label>
                    <input type="password" name="confirm_password" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
                </div>
                <button type="submit" class="btn-login">Daftar</button>
            </form>

            <p class="text-center small mt-3 mb-0">
                Sudah punya akun?
                <a href="<?= BASE_URL ?>/auth/login.php" class="fw-semibold text-decoration-none" style="color:#9a031e;">Login di sini</a>
            </p>
        <?php endif; ?>
    </div>
</body>

</html>