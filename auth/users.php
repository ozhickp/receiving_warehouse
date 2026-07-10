<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);
$user       = currentUser();
$activePage = 'users';
$pageTitle  = 'Kelola User';

$pdo = getPDO();

// ── Tambah User ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $username = trim($_POST['username']  ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $role     = $_POST['role']           ?? 'receiving';
    $password = $_POST['password']       ?? '';
    $errors   = [];

    if (!$username)           $errors[] = 'Username wajib diisi.';
    if (!$fullName)           $errors[] = 'Nama lengkap wajib diisi.';
    $errors = array_merge($errors, passwordPolicyErrors($password));
    if (!in_array($role, ['admin', 'ppic', 'receiving', 'manager'])) $errors[] = 'Role tidak valid.';

    if (empty($errors)) {
        try {
            $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]);
            flash('users', 'User berhasil ditambahkan.');
            header('Location: ' . BASE_URL . '/auth/users.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Username sudah digunakan.';
        }
    }
}

// ── Reset Password ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_pw') {
    csrfCheck();
    $uid      = (int)($_POST['user_id']      ?? 0);
    $password = $_POST['new_password'] ?? '';

    if (!$uid) {
        // FIX: previously this silently failed with no feedback at all.
        flash('users', 'User tidak ditemukan. Reset password gagal.', 'danger');
    } else {
        $pwErrors = passwordPolicyErrors($password);
        if ($pwErrors) {
            flash('users', implode(' ', $pwErrors) . ' Reset password gagal.', 'danger');
        } else {
            $pdo->prepare("UPDATE users SET password = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
            flash('users', 'Password berhasil direset.');
        }
    }
    header('Location: ' . BASE_URL . '/auth/users.php');
    exit;
}

// ── Toggle Aktif ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    csrfCheck();
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid !== $user['id']) {
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?")->execute([$uid]);
    }
    header('Location: ' . BASE_URL . '/auth/users.php');
    exit;
}

// ── Buka Kunci Akun (akibat rate limiting login) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock') {
    csrfCheck();
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid) {
        $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$uid]);
        flash('users', 'Kunci akun berhasil dibuka.');
    }
    header('Location: ' . BASE_URL . '/auth/users.php');
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY role, full_name")->fetchAll();

// User yang aktif dalam ONLINE_THRESHOLD_SECONDS terakhir dianggap "sedang login".
// Catatan: ini estimasi berbasis aktivitas terakhir (last_seen_at), bukan daftar sesi
// yang benar-benar live — kalau seseorang menutup tab tanpa logout, statusnya akan
// tetap "Online" sampai ONLINE_THRESHOLD_SECONDS terlewati sejak request terakhirnya.
$onlineUsers = $pdo->query("
    SELECT id, username, full_name, role, last_seen_at
    FROM users
    WHERE last_seen_at IS NOT NULL
      AND last_seen_at >= (NOW() - INTERVAL " . (int)ONLINE_THRESHOLD_SECONDS . " SECOND)
    ORDER BY last_seen_at DESC
")->fetchAll();
$onlineIds = array_column($onlineUsers, 'id');

$flash = getFlash('users');

$navbarTitle    = 'Kelola User';
$navbarSubtitle = 'Manajemen akun pengguna sistem';

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-end mb-4">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd">
        <i class="bi bi-person-plus me-1"></i> Tambah User
    </button>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
        <?= e($flash['msg']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success" style="width:10px;height:10px;padding:0;border-radius:50%;"></span>
            <span class="fw-semibold">Sedang Login: <?= count($onlineUsers) ?> user</span>
            <small class="text-muted">(aktif dalam <?= (int)(ONLINE_THRESHOLD_SECONDS / 60) ?> menit terakhir)</small>
        </div>
        <?php if ($onlineUsers): ?>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach ($onlineUsers as $ou): ?>
                    <span class="badge bg-light text-dark border">
                        <span class="text-success">●</span> <?= e($ou['full_name']) ?>
                        <small class="text-muted">(<?= e($ou['role']) ?>)</small>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <small class="text-muted">Tidak ada user yang sedang aktif saat ini.</small>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Online</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><code><?= e($u['username']) ?></code></td>
                            <td><?= e($u['full_name']) ?></td>
                            <td>
                                <?php
                                $roleColor = match ($u['role']) {
                                    'admin'     => 'danger',
                                    'ppic'      => 'primary',
                                    'receiving' => 'success',
                                    'manager'   => 'info',
                                    default     => 'secondary',
                                };
                                $roleLabel = match ($u['role']) {
                                    'admin'     => 'Admin',
                                    'ppic'      => 'PPIC',
                                    'receiving' => 'Receiving',
                                    'manager'   => 'Manager',
                                    default     => $u['role'],
                                };
                                ?>
                                <span class="badge bg-<?= $roleColor ?>"><?= $roleLabel ?></span>
                            </td>
                            <td>
                                <?= $u['is_active']
                                    ? '<span class="badge bg-success">Aktif</span>'
                                    : '<span class="badge bg-secondary">Nonaktif</span>' ?>
                                <?php if (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()): ?>
                                    <span class="badge bg-danger" title="Terkunci sampai <?= e($u['locked_until']) ?>">
                                        <i class="bi bi-lock-fill"></i> Terkunci
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array($u['id'], $onlineIds, true)): ?>
                                    <span class="text-success">●</span> <small>Online</small>
                                <?php elseif (!empty($u['last_seen_at'])): ?>
                                    <small class="text-muted"><?= date('d/m H:i', strtotime($u['last_seen_at'])) ?></small>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td><small><?= date('d/m/Y', strtotime($u['created_at'])) ?></small></td>
                            <td class="d-flex gap-1">
                                <button class="btn btn-xs btn-outline-secondary btn-reset-pw"
                                    data-uid="<?= $u['id'] ?>" data-name="<?= e($u['full_name']) ?>"
                                    data-active="<?= (int)$u['is_active'] ?>">
                                    <i class="bi bi-key"></i> Reset PW
                                </button>
                                <?php if (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="unlock">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button class="btn btn-xs btn-outline-warning">
                                            <i class="bi bi-unlock"></i> Buka Kunci
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($u['id'] !== $user['id']): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button class="btn btn-xs <?= $u['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <i class="bi bi-<?= $u['is_active'] ? 'person-x' : 'person-check' ?>"></i>
                                            <?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah User -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Tambah User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select">
                            <option value="receiving">Receiving</option>
                            <option value="ppic">PPIC</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                        <div class="form-text">
                            <b>Receiving</b>: Stock In/Out, konfirmasi schedule, weighing.<br>
                            <b>PPIC</b>: Input schedule supplier.<br>
                            <b>Manager</b>: Lihat dashboard & history.<br>
                            <b>Admin</b>: Akses penuh.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
                        <div class="form-text">Minimal <?= PASSWORD_MIN_LENGTH ?> karakter, kombinasi huruf dan angka.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Reset Password -->
<div class="modal fade" id="modalResetPw" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reset_pw">
                <input type="hidden" name="user_id" id="resetUid">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small mb-3">Reset password untuk: <strong id="resetName"></strong></p>
                    <!-- FIX: warns admin when the target account is inactive, since an inactive
                         account cannot log in (login.php filters WHERE is_active = 1) even
                         with a freshly reset, correct password. -->
                    <div id="resetInactiveWarning" class="alert alert-warning py-2 small d-none">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        User ini berstatus <strong>Nonaktif</strong>. Password akan direset, tapi user
                        tetap tidak akan bisa login sampai statusnya diaktifkan kembali.
                    </div>
                    <input type="password" name="new_password" class="form-control"
                        placeholder="Password baru (min <?= PASSWORD_MIN_LENGTH ?>, huruf+angka)" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning">Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.btn-reset-pw').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('resetUid').value = btn.dataset.uid;
            document.getElementById('resetName').textContent = btn.dataset.name;
            document.getElementById('resetInactiveWarning')
                .classList.toggle('d-none', btn.dataset.active === '1');
            new bootstrap.Modal(document.getElementById('modalResetPw')).show();
        });
    });
</script>

<style>
    .btn-xs {
        font-size: 12px;
        padding: 3px 8px;
    }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>