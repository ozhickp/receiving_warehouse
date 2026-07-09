<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$activePage = 'history';
$pageTitle  = 'History Transaksi';

$pdo = getPDO();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$type     = $_GET['type']      ?? '';
$search   = trim($_GET['search'] ?? '');

$where  = ["DATE(st.transaction_date) BETWEEN :df AND :dt"];
$params = [':df' => $dateFrom, ':dt' => $dateTo];

if ($type)   { $where[] = "st.type = :type";  $params[':type'] = $type; }
if ($search) { $where[] = "(m.name LIKE :q OR m.code LIKE :q OR st.transaction_no LIKE :q OR st.po_number LIKE :q OR st.do_number LIKE :q)"; $params[':q'] = "%$search%"; }

$sql = "
    SELECT st.transaction_no, st.type, st.qty, st.qty_before, st.qty_after,
           st.po_number, st.do_number, st.reference, st.notes, st.transaction_date,
           m.name AS material_name, m.code AS material_code, m.unit,
           s.name AS supplier_name, u.full_name AS operator
    FROM stock_transactions st
    JOIN materials m ON st.material_id = m.id
    LEFT JOIN suppliers s ON st.supplier_id = s.id
    JOIN users u ON st.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY st.transaction_date DESC
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-title mb-1">History Transaksi</div>
<div class="page-subtitle mb-4">Riwayat semua transaksi Stock In & Out</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Dari</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Sampai</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Tipe</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="IN"     <?= $type==='IN'     ? 'selected':'' ?>>Stock In</option>
                    <option value="OUT"    <?= $type==='OUT'    ? 'selected':'' ?>>Stock Out</option>
                    <option value="ADJUST" <?= $type==='ADJUST' ? 'selected':'' ?>>Adjust</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Cari</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Nama material / No. Trx / PO / DO" value="<?= e($search) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <a href="<?= BASE_URL ?>/transactions/history.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Hasil: <?= count($rows) ?> transaksi</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>No. Trx</th>
                        <th>Tipe</th>
                        <th>Material</th>
                        <th>Supplier</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Sebelum</th>
                        <th class="text-end">Sesudah</th>
                        <th>No. PO</th>
                        <th>No. DO</th>
                        <th>Operator</th>
                        <th>Waktu</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><small><code><?= e($r['transaction_no']) ?></code></small></td>
                        <td>
                            <?php if ($r['type']==='IN'): ?>
                            <span class="badge bg-success">IN</span>
                            <?php elseif ($r['type']==='OUT'): ?>
                            <span class="badge bg-warning text-dark">OUT</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">ADJUST</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= e($r['material_code']) ?> — <?= e($r['material_name']) ?></small></td>
                        <td><small><?= e($r['supplier_name'] ?? '-') ?></small></td>
                        <td class="text-end"><small><?= number_format($r['qty'],2) ?> <?= e($r['unit']) ?></small></td>
                        <td class="text-end text-muted"><small><?= number_format($r['qty_before'],2) ?></small></td>
                        <td class="text-end"><small><?= number_format($r['qty_after'],2) ?></small></td>
                        <td><small><?= e($r['po_number'] ?? '-') ?></small></td>
                        <td><small><?= e($r['do_number'] ?? '-') ?></small></td>
                        <td><small><?= e($r['operator']) ?></small></td>
                        <td><small><?= date('d/m/Y H:i', strtotime($r['transaction_date'])) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
