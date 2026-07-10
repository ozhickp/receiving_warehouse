<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);
$user       = currentUser();
$pageTitle  = 'Master Gudang';
$activePage = 'warehouses';
$pdo        = getPDO();

// ── Handle POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action      = $_POST['action'] ?? '';
    $code        = strtoupper(trim($_POST['code'] ?? ''));
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $errors      = [];

    if (!$code) $errors[] = 'Kode Gudang wajib diisi.';
    if (!$name) $errors[] = 'Nama Gudang wajib diisi.';

    if (empty($errors)) {
        if ($action === 'add') {
            try {
                $pdo->prepare("
                    INSERT INTO warehouses (code, name, description)
                    VALUES (?, ?, ?)
                ")->execute([$code, $name, $description ?: null]);
                flash('wh', "Gudang \"$name\" berhasil ditambahkan.");
            } catch (PDOException $e) {
                flash('wh', "Kode Gudang $code sudah dipakai.", 'error');
            }
        } elseif ($action === 'edit') {
            $id = (int)$_POST['id'];
            try {
                $pdo->prepare("
                    UPDATE warehouses SET code=?, name=?, description=? WHERE id=?
                ")->execute([$code, $name, $description ?: null, $id]);
                flash('wh', "Gudang \"$name\" berhasil diupdate.");
            } catch (PDOException $e) {
                flash('wh', "Kode Gudang $code sudah dipakai gudang lain.", 'error');
            }
        } elseif ($action === 'toggle') {
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE warehouses SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            flash('wh', 'Status gudang diubah.');
        }
        header('Location: ' . BASE_URL . '/inventory/warehouses.php');
        exit;
    }
}

$stmt = $pdo->query("
    SELECT w.*,
           COUNT(DISTINCT l.id) AS total_location,
           COALESCE(SUM(ml.qty), 0) AS total_qty
    FROM warehouses w
    LEFT JOIN locations l ON l.warehouse_id = w.id AND l.is_active = 1
    LEFT JOIN material_locations ml ON ml.location_id = l.id AND ml.qty > 0
    GROUP BY w.id
    ORDER BY w.name
");
$warehouses = $stmt->fetchAll();

$flash = getFlash('wh');
$navbarTitle    = 'Master Gudang';
$navbarSubtitle = 'Daftar gudang/site — setiap lokasi (Zone+Rak) terdaftar di bawah salah satu gudang';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-end mb-4">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddWh">
        <i class="bi bi-plus-lg me-1"></i> Tambah Gudang
    </button>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
        <?= e($flash['msg']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama Gudang</th>
                        <th>Deskripsi</th>
                        <th class="text-end">Jumlah Lokasi</th>
                        <th class="text-end">Total Barang</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($warehouses)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Belum ada gudang terdaftar.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($warehouses as $w): ?>
                            <tr>
                                <td><code class="fw-semibold"><?= e($w['code']) ?></code></td>
                                <td><?= e($w['name']) ?></td>
                                <td><small><?= e($w['description'] ?? '—') ?></small></td>
                                <td class="text-end"><?= (int)$w['total_location'] ?></td>
                                <td class="text-end"><?= number_format($w['total_qty'], 2) ?></td>
                                <td><?= $w['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
                                <td>
                                    <button class="btn btn-xs btn-outline-primary btn-edit-wh"
                                        data-wh="<?= htmlspecialchars(json_encode($w), ENT_QUOTES) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="post" class="d-inline"><?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $w['id'] ?>">
                                        <button class="btn btn-xs <?= $w['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <i class="bi bi-<?= $w['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="text-muted small mt-3">
    <i class="bi bi-info-circle me-1"></i>
    Menonaktifkan gudang tidak otomatis menonaktifkan lokasi di dalamnya — lokasi tetap perlu dinonaktifkan sendiri lewat Master Lokasi kalau memang gudangnya sudah tidak dipakai.
</p>

<!-- Modal Tambah -->
<div class="modal fade" id="modalAddWh" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post"><?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Tambah Gudang</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-5">
                            <label class="form-label fw-semibold small">Kode <span class="text-danger">*</span></label>
                            <input type="text" name="code" class="form-control text-uppercase" placeholder="Contoh: WH2" maxlength="20" required>
                        </div>
                        <div class="col-7">
                            <label class="form-label fw-semibold small">Nama Gudang <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Contoh: Gudang 2" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Deskripsi</label>
                            <input type="text" name="description" class="form-control" placeholder="Opsional, mis. alamat/lokasi gudang">
                        </div>
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

<!-- Modal Edit -->
<div class="modal fade" id="modalEditWh" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post"><?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editWhId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Gudang</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-5">
                            <label class="form-label fw-semibold small">Kode <span class="text-danger">*</span></label>
                            <input type="text" name="code" id="editWhCode" class="form-control text-uppercase" maxlength="20" required>
                        </div>
                        <div class="col-7">
                            <label class="form-label fw-semibold small">Nama Gudang <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="editWhName" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Deskripsi</label>
                            <input type="text" name="description" id="editWhDesc" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.btn-edit-wh').forEach(btn => {
        btn.addEventListener('click', () => {
            const w = JSON.parse(btn.dataset.wh);
            document.getElementById('editWhId').value = w.id;
            document.getElementById('editWhCode').value = w.code;
            document.getElementById('editWhName').value = w.name;
            document.getElementById('editWhDesc').value = w.description ?? '';
            new bootstrap.Modal(document.getElementById('modalEditWh')).show();
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