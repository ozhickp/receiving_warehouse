<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

// Stock Adjustment (Opname) — koreksi stok sistem supaya sesuai hasil hitung fisik.
// Dibatasi ke admin karena langsung mengubah stock_current tanpa melalui alur
// Stock In/Out biasa. Kalau mau dibuka juga untuk role 'receiving', tinggal
// tambahkan 'receiving' di array requireRole() di bawah ini.
requireRole(['admin']);
$user       = currentUser();
$activePage = 'stock_adjust';
$pageTitle  = 'Stock Adjustment (Opname)';

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $materialId   = (int)($_POST['material_id']   ?? 0);
    $actualQty    = $_POST['actual_qty'] ?? '';
    $notes        = trim($_POST['notes'] ?? '');
    $errors       = [];

    if (!$materialId)                     $errors[] = 'Material wajib dipilih.';
    if ($actualQty === '' || !is_numeric($actualQty)) $errors[] = 'Stok fisik aktual wajib diisi dengan angka.';
    if (!$notes)                          $errors[] = 'Catatan / alasan penyesuaian wajib diisi (mis. hasil stock opname tanggal ...).';

    if (empty($errors)) {
        $actualQty = (float)$actualQty;
        if ($actualQty < 0) {
            $errors[] = 'Stok fisik aktual tidak boleh negatif.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT stock_current FROM materials WHERE id = ? FOR UPDATE");
            $stmt->execute([$materialId]);
            $before = $stmt->fetchColumn();

            if ($before === false) {
                throw new Exception('Material tidak ditemukan.');
            }
            $before = (float)$before;
            $diff   = $actualQty - $before;

            if (abs($diff) < 0.0001) {
                $errors[] = 'Tidak ada selisih antara stok sistem dan stok fisik — tidak perlu penyesuaian.';
                $pdo->rollBack();
            } else {
                $trxNo = generateTrxNo('ADJ');
                $pdo->prepare("
                    INSERT INTO stock_transactions
                        (transaction_no, type, source, material_id, qty, qty_before, qty_after, notes, created_by)
                    VALUES (?, 'ADJUST', 'adjustment', ?, ?, ?, ?, ?, ?)
                ")->execute([$trxNo, $materialId, $diff, $before, $actualQty, $notes, $user['id']]);
                // Trigger trg_stock_after_insert otomatis set materials.stock_current = qty_after untuk type ADJUST.

                $pdo->commit();
                flash('stock_adjust', "Penyesuaian stok berhasil disimpan ($trxNo). Selisih: " . ($diff > 0 ? '+' : '') . number_format($diff, 2) . '.');
                header('Location: ' . BASE_URL . '/transactions/stock_adjust.php');
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

$materials = $pdo->query("
    SELECT id, code, name, unit, stock_current
    FROM materials
    WHERE is_active = 1
    ORDER BY code
")->fetchAll();

$recentAdjust = $pdo->query("
    SELECT st.transaction_no, st.qty, st.qty_before, st.qty_after, st.notes, st.transaction_date,
           m.code AS material_code, m.name AS material_name, m.unit,
           u.full_name AS operator
    FROM stock_transactions st
    JOIN materials m ON st.material_id = m.id
    JOIN users u ON st.created_by = u.id
    WHERE st.type = 'ADJUST'
    ORDER BY st.transaction_date DESC
    LIMIT 15
")->fetchAll();

$flash = getFlash('stock_adjust');
$navbarTitle    = 'Stock Adjustment (Opname)';
$navbarSubtitle = 'Koreksi stok sistem agar sesuai hasil hitung fisik gudang';
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

<div class="alert alert-warning small">
    <i class="bi bi-info-circle me-1"></i>
    Penyesuaian ini mengubah <strong>total stok sistem material</strong> secara langsung berdasarkan hasil hitung fisik.
    Fitur ini belum memecah selisih per lokasi (Master Lokasi) — kalau selisih diketahui terjadi di lokasi tertentu,
    silakan sesuaikan juga breakdown lokasinya secara manual lewat Stock In / Stock Out.
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Form Penyesuaian Stok</div>
            <div class="card-body">
                <form method="post" id="formAdjust">
                    <?= csrfField() ?>
                    <input type="hidden" name="material_id" id="hidMatId">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Material <span class="text-danger">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" id="acMat" class="form-control"
                                placeholder="Ketik kode atau nama material..." autocomplete="off">
                            <div class="ac-list" id="acMatList" style="display:none"></div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Stok Sistem Saat Ini</label>
                            <input type="text" class="form-control" id="dispSystemQty" value="—" disabled>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Stok Fisik Aktual <span class="text-danger">*</span></label>
                            <input type="number" name="actual_qty" id="inpActualQty" class="form-control" min="0" step="any" required disabled>
                        </div>
                    </div>

                    <div class="mb-3" id="diffBox" style="display:none">
                        <div class="alert mb-0 py-2 px-3 small" id="diffAlert"></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Catatan / Alasan <span class="text-danger">*</span></label>
                        <textarea name="notes" id="inpNotes" class="form-control" rows="2"
                            placeholder="Contoh: Hasil stock opname bulanan Juli 2026, selisih ditemukan saat cycle count."></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-dark fw-semibold">
                            <i class="bi bi-arrow-repeat me-1"></i> Simpan Penyesuaian
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Riwayat Penyesuaian Terbaru</div>
            <div class="card-body p-0">
                <?php if (empty($recentAdjust)): ?>
                    <p class="text-muted text-center py-4 small">Belum ada riwayat penyesuaian stok.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>No. Trx</th>
                                    <th>Material</th>
                                    <th class="text-end">Sebelum</th>
                                    <th class="text-end">Selisih</th>
                                    <th class="text-end">Sesudah</th>
                                    <th>Operator</th>
                                    <th>Waktu</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentAdjust as $a): ?>
                                    <tr>
                                        <td><small><code><?= e($a['transaction_no']) ?></code></small></td>
                                        <td><small><?= e($a['material_code']) ?> — <?= e($a['material_name']) ?></small></td>
                                        <td class="text-end"><small><?= number_format($a['qty_before'], 2) ?></small></td>
                                        <td class="text-end">
                                            <small class="fw-semibold <?= $a['qty'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= $a['qty'] >= 0 ? '+' : '' ?><?= number_format($a['qty'], 2) ?>
                                            </small>
                                        </td>
                                        <td class="text-end"><small><?= number_format($a['qty_after'], 2) ?></small></td>
                                        <td><small><?= e($a['operator']) ?></small></td>
                                        <td><small><?= date('d/m/Y H:i', strtotime($a['transaction_date'])) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= acStyles() ?>

<script>
    const MATERIALS = <?= json_encode(array_map(fn($m) => [
                            'id'    => $m['id'],
                            'code'  => $m['code'],
                            'name'  => $m['name'],
                            'unit'  => $m['unit'],
                            'stock' => (float)$m['stock_current'],
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

    const inpActual = document.getElementById('inpActualQty');
    const dispSystem = document.getElementById('dispSystemQty');
    const diffBox = document.getElementById('diffBox');
    const diffAlert = document.getElementById('diffAlert');
    let systemQty = null;

    function updateDiff() {
        if (systemQty === null || inpActual.value === '') {
            diffBox.style.display = 'none';
            return;
        }
        const actual = parseFloat(inpActual.value);
        if (isNaN(actual)) {
            diffBox.style.display = 'none';
            return;
        }
        const diff = actual - systemQty;
        diffBox.style.display = 'block';
        if (Math.abs(diff) < 0.0001) {
            diffAlert.className = 'alert mb-0 py-2 px-3 small alert-secondary';
            diffAlert.textContent = 'Tidak ada selisih — stok fisik sama dengan stok sistem.';
        } else if (diff > 0) {
            diffAlert.className = 'alert mb-0 py-2 px-3 small alert-success';
            diffAlert.textContent = `Stok fisik LEBIH BANYAK dari sistem. Selisih: +${diff.toLocaleString('id-ID', {maximumFractionDigits: 3})}`;
        } else {
            diffAlert.className = 'alert mb-0 py-2 px-3 small alert-danger';
            diffAlert.textContent = `Stok fisik LEBIH SEDIKIT dari sistem. Selisih: ${diff.toLocaleString('id-ID', {maximumFractionDigits: 3})}`;
        }
    }
    inpActual.addEventListener('input', updateDiff);

    makeAC(
        document.getElementById('acMat'),
        document.getElementById('acMatList'),
        MATERIALS,
        d => {
            document.getElementById('hidMatId').value = d ? d.id : '';
            if (d) {
                systemQty = d.stock;
                dispSystem.value = `${d.stock.toLocaleString('id-ID', {maximumFractionDigits: 3})} ${d.unit}`;
                inpActual.disabled = false;
                inpActual.value = '';
                updateDiff();
            } else {
                systemQty = null;
                dispSystem.value = '—';
                inpActual.disabled = true;
                inpActual.value = '';
                diffBox.style.display = 'none';
                document.getElementById('acMat').classList.remove('ac-confirmed');
            }
        },
        d => d.code + ' — ' + d.name,
        (d, q) => `<div class="ac-item" data-id="${d.id}">
        <span class="ac-code">${hl(d.code, q)}</span>
        <span class="ac-name">${hl(d.name, q)}</span>
        <span class="ac-stock">Stok: ${d.stock.toFixed(2)} ${d.unit}</span>
    </div>`
    );

    document.getElementById('formAdjust').addEventListener('submit', function(e) {
        if (!document.getElementById('hidMatId').value) {
            e.preventDefault();
            document.getElementById('acMat').classList.add('is-invalid');
            alert('Pilih material dari daftar suggestion terlebih dahulu.');
            return;
        }
        if (!document.getElementById('inpNotes').value.trim()) {
            e.preventDefault();
            alert('Catatan / alasan penyesuaian wajib diisi.');
        }
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>