<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$pageTitle  = 'Stok Sekarang';
$activePage = 'stock';
$pdo        = getPDO();

$filterStatus = $_GET['status'] ?? '';
$filterCat    = $_GET['category_id'] ?? '';

$sql = "SELECT m.*, c.name AS category_name FROM materials m
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE m.is_active = 1";
$params = [];
if ($filterCat) { $sql .= " AND m.category_id=?"; $params[] = $filterCat; }
if ($filterStatus === 'kritis') $sql .= " AND m.stock_current <= m.stock_minimum AND m.stock_current > 0";
elseif ($filterStatus === 'habis') $sql .= " AND m.stock_current <= 0";
elseif ($filterStatus === 'aman')  $sql .= " AND m.stock_current > m.stock_minimum";
$sql .= " ORDER BY m.stock_current / NULLIF(m.stock_minimum,0) ASC, m.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stocks = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-clipboard-data"></i> Stok Material Saat Ini</h5>
    <small class="text-muted">Update: <?= date('d/m/Y H:i') ?></small>
</div>

<!-- Filter -->
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">Semua Status</option>
            <option value="kritis" <?= $filterStatus==='kritis'?'selected':'' ?>>Kritis</option>
            <option value="habis"  <?= $filterStatus==='habis'?'selected':'' ?>>Habis</option>
            <option value="aman"   <?= $filterStatus==='aman'?'selected':'' ?>>Aman</option>
        </select>
    </div>
    <div class="col-auto">
        <select name="category_id" class="form-select form-select-sm">
            <option value="">Semua Kategori</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterCat==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
        <a href="stock.php" class="btn btn-sm btn-outline-danger">Reset</a>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Kode</th><th>Nama</th><th>Kategori</th>
                    <th class="text-end">Stok</th><th class="text-end">Minimum</th>
                    <th>Level</th><th>Status</th><th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stocks as $s):
                $pct = $s['stock_minimum'] > 0 ? min(100, ($s['stock_current'] / $s['stock_minimum']) * 100) : 100;
                $barColor = $pct >= 100 ? 'bg-success' : ($pct > 0 ? 'bg-warning' : 'bg-danger');
            ?>
            <tr>
                <td><code><?= e($s['code']) ?></code></td>
                <td><?= e($s['name']) ?></td>
                <td><small><?= e($s['category_name'] ?? '-') ?></small></td>
                <td class="text-end fw-bold <?= $s['stock_current'] <= 0 ? 'text-danger' : ($s['stock_current'] <= $s['stock_minimum'] ? 'text-warning' : '') ?>">
                    <?= number_format($s['stock_current'],2) ?> <?= e($s['unit']) ?>
                </td>
                <td class="text-end text-muted"><?= number_format($s['stock_minimum'],2) ?></td>
                <td style="min-width:100px">
                    <div class="progress" style="height:6px">
                        <div class="progress-bar <?= $barColor ?>" style="width:<?= min(100,$pct) ?>%"></div>
                    </div>
                    <small class="text-muted"><?= number_format($pct,0) ?>%</small>
                </td>
                <td><?= stockStatus((float)$s['stock_current'], (float)$s['stock_minimum']) ?></td>
                <td>
                    <a href="../transactions/stock_in.php" class="btn btn-xs btn-sm btn-outline-success py-0">IN</a>
                    <a href="../transactions/stock_out.php" class="btn btn-xs btn-sm btn-outline-danger py-0">OUT</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($stocks)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
