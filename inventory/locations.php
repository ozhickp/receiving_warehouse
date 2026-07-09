<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);
$user       = currentUser();
$pageTitle  = 'Master Lokasi';
$activePage = 'locations';
$pdo        = getPDO();

// ── Handle POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action      = $_POST['action'] ?? '';
    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $zoneCode    = strtoupper(trim($_POST['zone_code'] ?? ''));
    $rakCode     = strtoupper(trim($_POST['rak_code']  ?? ''));
    $description = trim($_POST['description'] ?? '');
    $capacityMax = ($_POST['capacity_max'] ?? '') !== '' ? (float)$_POST['capacity_max'] : null;
    $errors      = [];

    if (!$warehouseId) $errors[] = 'Gudang wajib dipilih.';
    if (!$zoneCode)     $errors[] = 'Kode Zone wajib diisi.';
    if (!$rakCode)      $errors[] = 'Kode Rak wajib diisi.';

    if (empty($errors)) {
        $fullCode = $zoneCode . '-' . $rakCode;

        if ($action === 'add') {
            try {
                $pdo->prepare("
                    INSERT INTO locations (warehouse_id, zone_code, rak_code, full_code, description, capacity_max)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$warehouseId, $zoneCode, $rakCode, $fullCode, $description ?: null, $capacityMax]);
                flash('loc', "Lokasi $fullCode berhasil ditambahkan.");
            } catch (PDOException $e) {
                flash('loc', "Lokasi $fullCode sudah terdaftar (kode Zone-Rak harus unik secara global, tidak peduli gudang mana).", 'error');
            }
        } elseif ($action === 'edit') {
            $id = (int)$_POST['id'];
            try {
                $pdo->prepare("
                    UPDATE locations SET warehouse_id=?, zone_code=?, rak_code=?, full_code=?, description=?, capacity_max=?
                    WHERE id=?
                ")->execute([$warehouseId, $zoneCode, $rakCode, $fullCode, $description ?: null, $capacityMax, $id]);
                flash('loc', "Lokasi $fullCode berhasil diupdate.");
            } catch (PDOException $e) {
                flash('loc', "Kombinasi Zone-Rak $fullCode sudah dipakai lokasi lain.", 'error');
            }
        } elseif ($action === 'toggle') {
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE locations SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            flash('loc', 'Status lokasi diubah.');
        }
        header('Location: ' . BASE_URL . '/inventory/locations.php');
        exit;
    }
}

// Daftar gudang aktif — dipakai untuk dropdown filter & form add/edit
$warehouses = $pdo->query("SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY name")->fetchAll();

$search      = trim($_GET['q'] ?? '');
$filterWhId  = (int)($_GET['warehouse_id'] ?? 0);
$where       = ['1=1'];
$params      = [];

if ($search) {
    $where[]  = "(l.full_code LIKE ? OR l.zone_code LIKE ? OR l.rak_code LIKE ? OR l.description LIKE ?)";
    $params   = array_merge($params, array_fill(0, 4, "%$search%"));
}
if ($filterWhId) {
    $where[]  = "l.warehouse_id = ?";
    $params[] = $filterWhId;
}

$stmt = $pdo->prepare("
    SELECT l.*, w.code AS warehouse_code, w.name AS warehouse_name,
           COALESCE(SUM(ml.qty), 0) AS total_qty,
           COUNT(DISTINCT ml.material_id) AS total_material
    FROM locations l
    JOIN warehouses w ON w.id = l.warehouse_id
    LEFT JOIN material_locations ml ON ml.location_id = l.id AND ml.qty > 0
    WHERE " . implode(' AND ', $where) . "
    GROUP BY l.id
    ORDER BY w.name ASC, l.full_code ASC
");
$stmt->execute($params);
$locations = $stmt->fetchAll();

$flash = getFlash('loc');
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <div class="page-title">Master Lokasi</div>
        <div class="page-subtitle">Daftar lokasi penempatan barang (Zone + Rak) yang terdaftar, per gudang</div>
    </div>
    <?php if (empty($warehouses)): ?>
        <a href="<?= BASE_URL ?>/inventory/warehouses.php" class="btn btn-outline-primary">
            <i class="bi bi-building me-1"></i> Tambah Gudang Dulu
        </a>
    <?php else: ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd">
            <i class="bi bi-plus-lg me-1"></i> Tambah Lokasi
        </button>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
        <?= e($flash['msg']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (empty($warehouses)): ?>
    <div class="alert alert-info">
        Belum ada gudang aktif. Daftarkan minimal 1 gudang dulu di menu <strong>Master Gudang</strong> sebelum menambahkan lokasi.
    </div>
<?php endif; ?>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Gudang</label>
                <select name="warehouse_id" class="form-select form-select-sm">
                    <option value="">Semua Gudang</option>
                    <?php foreach ($warehouses as $w): ?>
                        <option value="<?= $w['id'] ?>" <?= $filterWhId === (int)$w['id'] ? 'selected' : '' ?>>
                            <?= e($w['name']) ?> (<?= e($w['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Cari</label>
                <input type="text" name="q" class="form-control form-control-sm"
                    placeholder="Kode zone, rak, atau deskripsi..." value="<?= e($search) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <a href="<?= BASE_URL ?>/inventory/locations.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
            <div class="col-auto ms-auto">
                <span class="text-muted small"><?= count($locations) ?> lokasi ditemukan</span>
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
                        <th>Gudang</th>
                        <th>Kode Lokasi</th>
                        <th>Deskripsi</th>
                        <th class="text-end">Kapasitas Maks.</th>
                        <th class="text-end">Total Barang Saat Ini</th>
                        <th class="text-end">Jenis Material</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($locations)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Belum ada lokasi terdaftar.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($locations as $l): ?>
                            <tr>
                                <td><small class="badge bg-light text-dark border"><?= e($l['warehouse_name']) ?></small></td>
                                <td><code class="fw-semibold"><?= e($l['full_code']) ?></code></td>
                                <td><small><?= e($l['description'] ?? '—') ?></small></td>
                                <td class="text-end">
                                    <?php if ($l['capacity_max']): ?>
                                        <small><?= number_format($l['capacity_max'], 2) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format($l['total_qty'], 2) ?></td>
                                <td class="text-end"><?= (int)$l['total_material'] ?></td>
                                <td><?= $l['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
                                <td>
                                    <button class="btn btn-xs btn-outline-primary btn-edit-loc"
                                        data-loc="<?= htmlspecialchars(json_encode($l), ENT_QUOTES) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="post" class="d-inline"><?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                        <button class="btn btn-xs <?= $l['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <i class="bi bi-<?= $l['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
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
    Kode Zone-Rak (mis. SY-AF) harus unik.
    Kolom "Kapasitas Maks." disiapkan untuk fitur peringatan lokasi penuh — belum dijalankan
</p>

<!-- Modal Tambah -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post"><?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Tambah Lokasi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Gudang <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-select" required>
                                <option value="">— Pilih Gudang —</option>
                                <?php foreach ($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= e($w['name']) ?> (<?= e($w['code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Kode Zone <span class="text-danger">*</span></label>
                            <input type="text" name="zone_code" class="form-control text-uppercase" placeholder="Contoh: SY" maxlength="10" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Kode Rak <span class="text-danger">*</span></label>
                            <input type="text" name="rak_code" class="form-control text-uppercase" placeholder="Contoh: AF" maxlength="10" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Deskripsi</label>
                            <input type="text" name="description" class="form-control" placeholder="Contoh: Storage Yard - Area Depan (opsional)">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Kapasitas Maksimum</label>
                            <input type="number" name="capacity_max" class="form-control" min="0" step="any" placeholder="Opsional, belum diaktifkan validasinya">
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
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Lokasi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Gudang <span class="text-danger">*</span></label>
                            <select name="warehouse_id" id="editWarehouseId" class="form-select" required>
                                <option value="">— Pilih Gudang —</option>
                                <?php foreach ($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= e($w['name']) ?> (<?= e($w['code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Kode Zone <span class="text-danger">*</span></label>
                            <input type="text" name="zone_code" id="editZone" class="form-control text-uppercase" maxlength="10" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Kode Rak <span class="text-danger">*</span></label>
                            <input type="text" name="rak_code" id="editRak" class="form-control text-uppercase" maxlength="10" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Deskripsi</label>
                            <input type="text" name="description" id="editDesc" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Kapasitas Maksimum</label>
                            <input type="number" name="capacity_max" id="editCapacity" class="form-control" min="0" step="any">
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
    document.querySelectorAll('.btn-edit-loc').forEach(btn => {
        btn.addEventListener('click', () => {
            const l = JSON.parse(btn.dataset.loc);
            document.getElementById('editId').value = l.id;
            document.getElementById('editWarehouseId').value = l.warehouse_id;
            document.getElementById('editZone').value = l.zone_code;
            document.getElementById('editRak').value = l.rak_code;
            document.getElementById('editDesc').value = l.description ?? '';
            document.getElementById('editCapacity').value = l.capacity_max ?? '';
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