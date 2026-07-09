<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);
$user       = currentUser();
$pageTitle  = 'Master Material';
$activePage = 'materials';
$pdo        = getPDO();

// ── Handle POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action  = $_POST['action'] ?? '';
    $code    = trim($_POST['code']          ?? '');
    $name    = trim($_POST['name']          ?? '');
    $unit    = trim($_POST['unit']          ?? 'UN');
    $catId   = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $minStock = (float)($_POST['stock_minimum'] ?? 0);
    $wpu     = isset($_POST['weight_per_unit']) && $_POST['weight_per_unit'] !== '' ? (float)$_POST['weight_per_unit'] : null;
    $desc    = trim($_POST['description']   ?? '');
    $errors  = [];

    if (!$code) $errors[] = 'Kode wajib diisi.';
    if (!$name) $errors[] = 'Nama wajib diisi.';

    if (empty($errors)) {
        if ($action === 'add') {
            try {
                $pdo->prepare("
                    INSERT INTO materials (code, name, category_id, unit, stock_minimum, weight_per_unit, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$code, $name, $catId, $unit, $minStock, $wpu, $desc]);
                flash('mat', 'Material berhasil ditambahkan.');
            } catch (PDOException $e) {
                flash('mat', 'Kode material sudah digunakan.', 'error');
            }
        } elseif ($action === 'edit') {
            $id = (int)$_POST['id'];
            $pdo->prepare("
                UPDATE materials SET code=?, name=?, category_id=?, unit=?, stock_minimum=?, weight_per_unit=?, description=?
                WHERE id=?
            ")->execute([$code, $name, $catId, $unit, $minStock, $wpu, $desc, $id]);
            flash('mat', 'Material berhasil diupdate.');
        } elseif ($action === 'toggle') {
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE materials SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            flash('mat', 'Status material diubah.');
        }
        header('Location: ' . BASE_URL . '/inventory/materials.php');
        exit;
    }
}

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$search     = trim($_GET['q'] ?? '');
$filterCat  = (int)($_GET['cat'] ?? 0);

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[] = "(m.code LIKE ? OR m.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterCat) {
    $where[] = "m.category_id = ?";
    $params[] = $filterCat;
}

$stmt = $pdo->prepare("
    SELECT m.*, c.name AS category_name
    FROM materials m
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.code ASC
");
$stmt->execute($params);
$materials = $stmt->fetchAll();

$flash = getFlash('mat');
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <div class="page-title">Master Material</div>
        <div class="page-subtitle">Daftar seluruh raw material</div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd">
        <i class="bi bi-plus-lg me-1"></i> Tambah Material
    </button>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
        <?= e($flash['msg']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Cari</label>
                <input type="text" name="q" class="form-control form-control-sm"
                    placeholder="Kode atau nama material..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Kategori</label>
                <select name="cat" class="form-select form-select-sm">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <a href="<?= BASE_URL ?>/inventory/materials.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
            <div class="col-auto ms-auto">
                <span class="text-muted small"><?= count($materials) ?> material ditemukan</span>
            </div>
        </form>
    </div>
</div>

<!-- Tabel -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Kategori</th>
                        <th class="text-end">Stok</th>
                        <th class="text-end">Min. Stok</th>
                        <th class="text-end">Berat/unit (kg)</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($materials)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Tidak ada data.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($materials as $m): ?>
                            <tr>
                                <td><code><?= e($m['code']) ?></code></td>
                                <td><?= e($m['name']) ?></td>
                                <td><small><?= e($m['category_name'] ?? '—') ?></small></td>
                                <td class="text-end"><?= number_format($m['stock_current'], 0) ?></td>
                                <td class="text-end"><?= number_format($m['stock_minimum'], 0) ?></td>
                                <td class="text-end"><?= $m['weight_per_unit'] ? number_format($m['weight_per_unit'], 4) : '—' ?></td>
                                <td><?= $m['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
                                <td>
                                    <button class="btn btn-xs btn-outline-primary btn-edit-mat"
                                        data-mat="<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="post" class="d-inline"><?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                        <button class="btn btn-xs <?= $m['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <i class="bi bi-<?= $m['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
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

<!-- Modal Tambah -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post"><?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Tambah Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-5">
                            <label class="form-label fw-semibold small">Kode <span class="text-danger">*</span></label>
                            <input type="text" name="code" class="form-control" required>
                        </div>
                        <div class="col-7">
                            <label class="form-label fw-semibold small">Nama <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Kategori</label>
                            <select name="category_id" class="form-select">
                                <option value="">— Pilih Kategori —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold small">Unit</label>
                            <input type="text" name="unit" class="form-control" value="UN">
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold small">Min. Stok</label>
                            <input type="number" name="stock_minimum" class="form-control" value="0" min="0" step="any">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Berat/unit (kg)</label>
                            <input type="number" name="weight_per_unit" class="form-control" min="0" step="any" placeholder="Opsional">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Deskripsi / Lokasi</label>
                            <input type="text" name="description" class="form-control" placeholder="Opsional">
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
<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post"><?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-5">
                            <label class="form-label fw-semibold small">Kode <span class="text-danger">*</span></label>
                            <input type="text" name="code" id="editCode" class="form-control" required>
                        </div>
                        <div class="col-7">
                            <label class="form-label fw-semibold small">Nama <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="editName" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Kategori</label>
                            <select name="category_id" id="editCatId" class="form-select">
                                <option value="">— Pilih Kategori —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold small">Unit</label>
                            <input type="text" name="unit" id="editUnit" class="form-control">
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold small">Min. Stok</label>
                            <input type="number" name="stock_minimum" id="editMinStock" class="form-control" min="0" step="any">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Berat/unit (kg)</label>
                            <input type="number" name="weight_per_unit" id="editWpu" class="form-control" min="0" step="any">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Deskripsi / Lokasi</label>
                            <input type="text" name="description" id="editDesc" class="form-control">
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
    document.querySelectorAll('.btn-edit-mat').forEach(btn => {
        btn.addEventListener('click', () => {
            const m = JSON.parse(btn.dataset.mat);
            document.getElementById('editId').value = m.id;
            document.getElementById('editCode').value = m.code;
            document.getElementById('editName').value = m.name;
            document.getElementById('editUnit').value = m.unit;
            document.getElementById('editMinStock').value = m.stock_minimum;
            document.getElementById('editWpu').value = m.weight_per_unit ?? '';
            document.getElementById('editDesc').value = m.description ?? '';
            document.getElementById('editCatId').value = m.category_id ?? '';
            new bootstrap.Modal(document.getElementById('modalEdit')).show();
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