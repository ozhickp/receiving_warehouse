<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin', 'ppic', 'receiving']);
startSession(); // pastikan session aktif
$user       = currentUser();
$activePage = 'schedule';
$pageTitle  = 'Schedule Supplier';

$pdo = getPDO();

// ── Handle: Tambah Schedule ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $supplierId   = (int)($_POST['supplier_id']  ?? 0);
    $materialId   = (int)($_POST['material_id']  ?? 0);
    $scheduleDate = trim($_POST['schedule_date'] ?? '');
    $qtyExpected  = (float)($_POST['qty_expected'] ?? 0);
    $poNumber     = trim($_POST['po_number']     ?? '');
    $notes        = trim($_POST['notes']         ?? '');
    $errors       = [];

    if (!$supplierId)      $errors[] = 'Supplier wajib dipilih.';
    if (!$materialId)      $errors[] = 'Material wajib dipilih.';
    if (!$scheduleDate)    $errors[] = 'Tanggal wajib diisi.';
    if ($qtyExpected <= 0) $errors[] = 'Qty expected harus > 0.';
    if (!$poNumber)        $errors[] = 'No. PO wajib diisi.';

    if (empty($errors)) {
        $pdo->prepare("
            INSERT INTO supplier_schedules
                (schedule_date, supplier_id, material_id, qty_expected, po_number, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$scheduleDate, $supplierId, $materialId, $qtyExpected, $poNumber, $notes, $user['id']]);
        flash('schedule', 'Schedule berhasil ditambahkan.');
        header('Location: ' . BASE_URL . '/schedule/index.php');
        exit;
    }
}

// ── Handle: Konfirmasi ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    csrfCheck();
    $scheduleId    = (int)($_POST['schedule_id']   ?? 0);
    $doNumber      = trim($_POST['do_number']      ?? '');
    $qtyActual     = (float)($_POST['qty_actual']  ?? 0);
    $itemCondition = trim($_POST['item_condition'] ?? '');
    $errors        = [];

    if (!$doNumber)      $errors[] = 'No. DO / Surat Jalan wajib diisi.';
    if ($qtyActual <= 0) $errors[] = 'Qty aktual harus > 0.';

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE supplier_schedules
            SET status = 'confirmed', do_number = ?, qty_actual = ?, item_condition = ?,
                confirmed_by = ?, confirmed_at = NOW()
            WHERE id = ? AND status = 'planned'
        ")->execute([$doNumber, $qtyActual, $itemCondition, $user['id'], $scheduleId]);
        flash('schedule', 'Kedatangan supplier berhasil dikonfirmasi.');
        header('Location: ' . BASE_URL . '/schedule/index.php');
        exit;
    }
}

// ── Handle: Cancel ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    csrfCheck();
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);
    $pdo->prepare("UPDATE supplier_schedules SET status = 'cancelled' WHERE id = ? AND status = 'planned'")
        ->execute([$scheduleId]);
    flash('schedule', 'Schedule dibatalkan.');
    header('Location: ' . BASE_URL . '/schedule/index.php');
    exit;
}

// ── Data ─────────────────────────────────────────────────
$filterDate   = $_GET['date']   ?? date('Y-m-d');
$filterStatus = $_GET['status'] ?? '';

$where  = ["DATE(ss.schedule_date) = :d"];
$params = [':d' => $filterDate];
if ($filterStatus) {
    $where[] = "ss.status = :st";
    $params[':st'] = $filterStatus;
}

$schedules = $pdo->prepare("
    SELECT ss.*, s.name AS supplier_name, m.name AS material_name, m.unit,
           uc.full_name AS created_by_name, uk.full_name AS confirmed_by_name
    FROM supplier_schedules ss
    JOIN suppliers s ON ss.supplier_id = s.id
    JOIN materials m ON ss.material_id = m.id
    JOIN users uc    ON ss.created_by  = uc.id
    LEFT JOIN users uk ON ss.confirmed_by = uk.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ss.status ASC, ss.created_at ASC
");
$schedules->execute($params);
$schedules = $schedules->fetchAll();

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();
$materials = $pdo->query("SELECT id, code, name, unit FROM materials WHERE is_active = 1 ORDER BY code")->fetchAll();

$totalPlanned = $pdo->prepare("SELECT COUNT(*) FROM supplier_schedules WHERE schedule_date = ? AND status = 'planned'");
$totalPlanned->execute([$filterDate]);
$totalPlanned = (int)$totalPlanned->fetchColumn();

$flash = getFlash('schedule');
$navbarTitle    = 'Schedule Supplier';
$navbarSubtitle = 'Rencana kedatangan supplier';
include __DIR__ . '/../includes/header.php';
?>

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

<?php if ($totalPlanned > 0): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-people-fill fs-5"></i>
        <div>Hari ini terdapat <strong><?= $totalPlanned ?> kedatangan supplier</strong> yang belum dikonfirmasi. Pastikan personil receiving sudah siap.</div>
    </div>
<?php endif; ?>

<!-- Filter sejajar dengan tombol Tambah Schedule di kanan -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div class="card mb-0 flex-grow-1">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Tanggal</label>
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        <option value="planned" <?= $filterStatus === 'planned'   ? 'selected' : '' ?>>Planned</option>
                        <option value="confirmed" <?= $filterStatus === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    <a href="<?= BASE_URL ?>/schedule/index.php" class="btn btn-sm btn-outline-secondary">Hari Ini</a>
                </div>
            </form>
        </div>
    </div>
    <?php if (hasRole(['admin', 'ppic'])): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd">
            <i class="bi bi-plus-lg me-1"></i> Tambah Schedule
        </button>
    <?php endif; ?>
</div>

<!-- Tabel -->
<div class="card">
    <div class="card-header">Schedule: <?= date('d F Y', strtotime($filterDate)) ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Material</th>
                        <th class="text-end">Qty Expected</th>
                        <th>No. PO</th>
                        <th>No. DO</th>
                        <th class="text-end">Qty Aktual</th>
                        <th>Kondisi</th>
                        <th>Status</th>
                        <th>Dibuat Oleh</th>
                        <th>Dikonfirmasi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">Tidak ada schedule untuk tanggal ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $sc): ?>
                            <tr>
                                <td><?= e($sc['supplier_name']) ?></td>
                                <td><?= e($sc['material_name']) ?></td>
                                <td class="text-end"><?= number_format($sc['qty_expected'], 2) ?> <small class="text-muted"><?= e($sc['unit']) ?></small></td>
                                <td><code><?= e($sc['po_number']) ?></code></td>
                                <td><?= $sc['do_number'] ? '<code>' . e($sc['do_number']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end"><?= $sc['qty_actual'] !== null ? number_format($sc['qty_actual'], 2) . ' <small class="text-muted">' . e($sc['unit']) . '</small>' : '<span class="text-muted">—</span>' ?></td>
                                <td><small><?= e($sc['item_condition'] ?? '—') ?></small></td>
                                <td><?= scheduleStatusBadge($sc['status']) ?></td>
                                <td><small><?= e($sc['created_by_name']) ?></small></td>
                                <td>
                                    <?php if ($sc['confirmed_by_name']): ?>
                                        <small><?= e($sc['confirmed_by_name']) ?><br><span class="text-muted"><?= date('d/m H:i', strtotime($sc['confirmed_at'])) ?></span></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sc['status'] === 'planned'): ?>
                                        <?php if (hasRole(['admin', 'receiving'])): ?>
                                            <button class="btn btn-xs btn-success btn-confirm-trigger me-1"
                                                data-id="<?= $sc['id'] ?>"
                                                data-supplier="<?= e($sc['supplier_name']) ?>"
                                                data-material="<?= e($sc['material_name']) ?>"
                                                data-qty="<?= $sc['qty_expected'] ?>"
                                                data-unit="<?= e($sc['unit']) ?>">
                                                <i class="bi bi-check2-circle"></i> Konfirmasi
                                            </button>
                                        <?php endif; ?>
                                        <?php if (hasRole(['admin', 'ppic'])): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Batalkan schedule ini?')"><?= csrfField() ?>
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="schedule_id" value="<?= $sc['id'] ?>">
                                                <button class="btn btn-xs btn-outline-secondary"><i class="bi bi-x"></i> Batal</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Modal Tambah Schedule ──────────────────────────── -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="formAdd"><?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="supplier_id" id="hidAddSupplierId">
                <input type="hidden" name="material_id" id="hidAddMaterialId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Tambah Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Tanggal Kedatangan <span class="text-danger">*</span></label>
                        <input type="date" name="schedule_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Supplier <span class="text-danger">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" id="acAddSupplier" class="form-control"
                                placeholder="Ketik nama supplier..." autocomplete="off">
                            <div class="ac-list" id="acAddSupplierList" style="display:none"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Material <span class="text-danger">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" id="acAddMaterial" class="form-control"
                                placeholder="Ketik kode atau nama material..." autocomplete="off">
                            <div class="ac-list" id="acAddMaterialList" style="display:none"></div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Qty Expected <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="qty_expected" class="form-control" min="0.001" step="any" required>
                                <span class="input-group-text" id="addMatUnit">—</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">No. PO <span class="text-danger">*</span></label>
                            <input type="text" name="po_number" class="form-control" placeholder="PO-2024-001" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold small">Catatan</label>
                        <input type="text" name="notes" class="form-control" placeholder="Opsional">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal Konfirmasi ────────────────────────────────── -->
<div class="modal fade" id="modalConfirm" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post"><?= csrfField() ?>
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="schedule_id" id="confirmScheduleId">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check2-circle me-2"></i>Konfirmasi Kedatangan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3"><strong id="confirmInfo"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">No. DO / Surat Jalan <span class="text-danger">*</span></label>
                        <input type="text" name="do_number" class="form-control" placeholder="Nomor DO dari supplier" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Qty Aktual Diterima <span class="text-danger">*</span></label>
                        <input type="number" name="qty_actual" id="confirmQty" class="form-control" min="0.001" step="any" required>
                        <div class="form-text" id="confirmQtyHint"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Kondisi Barang</label>
                        <input type="text" name="item_condition" class="form-control" placeholder="Contoh: Baik, ada kerusakan kemasan, dll">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Konfirmasi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= acStyles() ?>

<script>
    const SUPPLIERS = <?= json_encode(array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name']], $suppliers)) ?>;
    const MATERIALS = <?= json_encode(array_map(fn($m) => [
                            'id'   => $m['id'],
                            'code' => $m['code'],
                            'name' => $m['name'],
                            'unit' => $m['unit'],
                        ], $materials)) ?>;

    function hl(text, q) {
        if (!q) return text;
        const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return text.replace(re, '<mark style="padding:0;background:#fff3cd">$1</mark>');
    }

    function makeAC(inputEl, listEl, data, onSelect, labelFn, itemHtmlFn) {
        let idx = -1;

        function render(q) {
            const ql = q.trim().toLowerCase();
            if (!ql) {
                listEl.style.display = 'none';
                return;
            }
            const hits = data.filter(d => labelFn(d).toLowerCase().includes(ql)).slice(0, 20);
            listEl.innerHTML = hits.length ?
                hits.map((d, i) => itemHtmlFn(d, q.trim(), i)).join('') :
                '<div class="ac-empty">Tidak ditemukan.</div>';
            listEl.style.display = 'block';
            idx = -1;
        }
        inputEl.addEventListener('input', () => {
            onSelect(null);
            render(inputEl.value);
        });
        inputEl.addEventListener('keydown', e => {
            const items = listEl.querySelectorAll('.ac-item');
            if (!items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                idx = Math.min(idx + 1, items.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                idx = Math.max(idx - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (idx >= 0) items[idx].dispatchEvent(new Event('mousedown'));
                return;
            } else if (e.key === 'Escape') {
                listEl.style.display = 'none';
                return;
            }
            items.forEach((el, i) => el.classList.toggle('active', i === idx));
            if (idx >= 0) items[idx].scrollIntoView({
                block: 'nearest'
            });
        });
        listEl.addEventListener('mousedown', e => {
            const item = e.target.closest('.ac-item');
            if (!item) return;
            const d = data.find(x => String(x.id) === item.dataset.id);
            if (d) {
                inputEl.value = labelFn(d);
                inputEl.classList.add('ac-confirmed');
                listEl.style.display = 'none';
                idx = -1;
                onSelect(d);
            }
        });
        document.addEventListener('click', e => {
            if (!inputEl.closest('.ac-wrap').contains(e.target)) listEl.style.display = 'none';
        });
    }

    // Supplier AC
    makeAC(
        document.getElementById('acAddSupplier'),
        document.getElementById('acAddSupplierList'),
        SUPPLIERS,
        d => {
            document.getElementById('hidAddSupplierId').value = d ? d.id : '';
        },
        d => d.name,
        (d, q) => `<div class="ac-item" data-id="${d.id}"><span class="ac-name">${hl(d.name, q)}</span></div>`
    );

    // Material AC
    makeAC(
        document.getElementById('acAddMaterial'),
        document.getElementById('acAddMaterialList'),
        MATERIALS,
        d => {
            document.getElementById('hidAddMaterialId').value = d ? d.id : '';
            document.getElementById('addMatUnit').textContent = d ? d.unit : '—';
        },
        d => d.code + ' — ' + d.name,
        (d, q) => `<div class="ac-item" data-id="${d.id}">
        <span class="ac-code">${hl(d.code, q)}</span>
        <span class="ac-name">${hl(d.name, q)}</span>
    </div>`
    );

    // Reset AC saat modal dibuka
    document.getElementById('modalAdd').addEventListener('show.bs.modal', () => {
        ['acAddSupplier', 'acAddMaterial'].forEach(id => {
            const el = document.getElementById(id);
            el.value = '';
            el.classList.remove('ac-confirmed', 'is-invalid');
        });
        document.getElementById('hidAddSupplierId').value = '';
        document.getElementById('hidAddMaterialId').value = '';
        document.getElementById('addMatUnit').textContent = '—';
    });

    // Validasi modal submit
    document.getElementById('formAdd').addEventListener('submit', function(e) {
        let ok = true;
        if (!document.getElementById('hidAddSupplierId').value) {
            document.getElementById('acAddSupplier').classList.add('is-invalid');
            ok = false;
        }
        if (!document.getElementById('hidAddMaterialId').value) {
            document.getElementById('acAddMaterial').classList.add('is-invalid');
            ok = false;
        }
        if (!ok) {
            e.preventDefault();
            alert('Pastikan supplier dan material dipilih dari suggestion.');
        }
    });

    // Konfirmasi trigger
    document.querySelectorAll('.btn-confirm-trigger').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('confirmScheduleId').value = btn.dataset.id;
            document.getElementById('confirmInfo').textContent = `${btn.dataset.supplier} — ${btn.dataset.material}`;
            document.getElementById('confirmQty').value = btn.dataset.qty;
            document.getElementById('confirmQtyHint').textContent = `Qty expected: ${parseFloat(btn.dataset.qty).toFixed(2)} ${btn.dataset.unit}`;
            new bootstrap.Modal(document.getElementById('modalConfirm')).show();
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