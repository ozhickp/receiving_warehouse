<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$pageTitle  = 'Batch / Lot';
$activePage = 'batches';
$pdo        = getPDO();
$user       = currentUser();

// Data for dropdowns
$materials = $pdo->query("SELECT id, code, name, unit FROM materials WHERE is_active=1 ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT id, code, name FROM suppliers WHERE is_active=1 ORDER BY name")->fetchAll();

// Search/filter
$filterMat = $_GET['material_id'] ?? '';
$filterSup = $_GET['supplier_id'] ?? '';
$filterDate = $_GET['date'] ?? '';

$sql = "SELECT b.*, m.name AS material_name, m.code AS material_code, m.unit,
               s.name AS supplier_name, u.full_name AS created_by_name
        FROM batches b
        JOIN materials m ON m.id = b.material_id
        JOIN suppliers s ON s.id = b.supplier_id
        JOIN users u     ON u.id = b.created_by
        WHERE 1=1";
$params = [];
if ($filterMat) { $sql .= " AND b.material_id = ?"; $params[] = $filterMat; }
if ($filterSup) { $sql .= " AND b.supplier_id = ?"; $params[] = $filterSup; }
if ($filterDate) { $sql .= " AND b.received_date = ?"; $params[] = $filterDate; }
$sql .= " ORDER BY b.received_date DESC, b.id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<?php $f = getFlash('batch'); if ($f): ?>
<div class="alert alert-<?= $f['type']==='success'?'success':'danger' ?> alert-dismissible">
    <?= e($f['msg']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-layers"></i> Batch / Lot Material</h5>
    <small class="text-muted">Batch dibuat otomatis saat Stock IN</small>
</div>

<!-- Filter -->
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="material_id" class="form-select form-select-sm">
            <option value="">Semua Material</option>
            <?php foreach ($materials as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $filterMat==$m['id']?'selected':'' ?>><?= e($m['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="supplier_id" class="form-select form-select-sm">
            <option value="">Semua Supplier</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filterSup==$s['id']?'selected':'' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
        <a href="batches.php" class="btn btn-sm btn-outline-danger">Reset</a>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>No Batch</th><th>Material</th><th>Supplier</th>
                    <th>Tgl Terima</th><th>Exp. Date</th>
                    <th class="text-end">Qty Terima</th><th class="text-end">Sisa</th>
                    <th>PO No</th><th>Dibuat Oleh</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
            <?php
                $pct = $b['received_qty'] > 0 ? ($b['remaining_qty'] / $b['received_qty']) * 100 : 0;
                $barColor = $pct > 50 ? 'bg-success' : ($pct > 20 ? 'bg-warning' : 'bg-danger');
                $isExpired = $b['expiry_date'] && $b['expiry_date'] < date('Y-m-d');
                $expiringSoon = $b['expiry_date'] && !$isExpired && strtotime($b['expiry_date']) <= strtotime('+30 days');
            ?>
            <tr>
                <td><code class="small"><?= e($b['batch_number']) ?></code></td>
                <td>
                    <span class="fw-semibold"><?= e($b['material_code']) ?></span><br>
                    <small class="text-muted"><?= e($b['material_name']) ?></small>
                </td>
                <td><?= e($b['supplier_name']) ?></td>
                <td><?= date('d/m/Y', strtotime($b['received_date'])) ?></td>
                <td>
                    <?php if ($b['expiry_date']): ?>
                        <span class="<?= $isExpired?'text-danger fw-bold':($expiringSoon?'text-warning fw-semibold':'') ?>">
                            <?= date('d/m/Y', strtotime($b['expiry_date'])) ?>
                            <?= $isExpired ? ' ⚠ Expired' : ($expiringSoon ? ' ⚠ Soon' : '') ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?= number_format($b['received_qty'],2) ?> <?= e($b['unit']) ?></td>
                <td class="text-end">
                    <?= number_format($b['remaining_qty'],2) ?>
                    <div class="progress mt-1" style="height:4px;width:70px;margin-left:auto">
                        <div class="progress-bar <?= $barColor ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                </td>
                <td><small><?= e($b['po_number'] ?? '-') ?></small></td>
                <td><small><?= e($b['created_by_name']) ?></small><br><small class="text-muted"><?= date('d/m H:i', strtotime($b['created_at'])) ?></small></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($batches)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">Belum ada data batch</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
