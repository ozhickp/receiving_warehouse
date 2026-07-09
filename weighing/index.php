<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin', 'receiving']);
$user       = currentUser();
$activePage = 'weighing';
$pageTitle  = 'Weighing System';

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $materialId      = (int)($_POST['material_id']       ?? 0);
    $supplierId      = (int)($_POST['supplier_id']       ?? 0);
    $poNumber        = trim($_POST['po_number']          ?? '');
    $doNumber        = trim($_POST['do_number']          ?? '');
    $grossWeight     = (float)($_POST['gross_weight']    ?? 0);
    $tareWeight      = (float)($_POST['tare_weight']     ?? 0);
    $qtyPerBox       = (int)($_POST['qty_per_box']       ?? 0);
    $boxCount        = (int)($_POST['box_count']         ?? 0);
    $weightPerUnitRef = (float)($_POST['weight_per_unit_ref'] ?? 0);
    $locationId      = (int)($_POST['location_id']          ?? 0);
    $notes           = trim($_POST['notes']              ?? '');
    $errors          = [];

    if (!$materialId)    $errors[] = 'Material wajib dipilih.';
    if (!$supplierId)    $errors[] = 'Supplier wajib dipilih.';
    if ($grossWeight <= 0) $errors[] = 'Gross weight harus > 0.';
    if ($tareWeight < 0) $errors[] = 'Tare weight tidak boleh negatif.';
    if ($grossWeight <= $tareWeight) $errors[] = 'Gross weight harus lebih besar dari tare weight.';
    if ($qtyPerBox  <= 0) $errors[] = 'Qty per box harus > 0.';
    if ($boxCount   <= 0) $errors[] = 'Jumlah box harus > 0.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $locCode = resolveLocation($pdo, $locationId);
            if (!$locCode) $locationId = 0; // lokasi tidak valid/tidak aktif, abaikan

            $recordNo = generateTrxNo('WGH');
            $pdo->prepare("
                INSERT INTO weighing_records
                    (record_no, material_id, supplier_id, po_number, do_number,
                     gross_weight, tare_weight, qty_per_box, box_count,
                     weight_per_unit_ref, location, location_id, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $recordNo,
                $materialId,
                $supplierId,
                $poNumber,
                $doNumber,
                $grossWeight,
                $tareWeight,
                $qtyPerBox,
                $boxCount,
                $weightPerUnitRef ?: null,
                $locCode,
                $locationId ?: null,
                $notes,
                $user['id']
            ]);
            $weighingId = (int)$pdo->lastInsertId();

            // Qty yang dihasilkan dari timbangan (box x qty per box) otomatis
            // ditambahkan ke stok, sama seperti Stock In manual.
            $qtyCalculated = $qtyPerBox * $boxCount;

            $stmt = $pdo->prepare("SELECT stock_current FROM materials WHERE id = ? FOR UPDATE");
            $stmt->execute([$materialId]);
            $before = (float)$stmt->fetchColumn();
            $after  = $before + $qtyCalculated;

            $trxNo = generateTrxNo('IN');
            $pdo->prepare("
                INSERT INTO stock_transactions
                    (transaction_no, type, source, material_id, supplier_id, weighing_record_id,
                     qty, qty_before, qty_after, po_number, do_number, location, location_id, notes, created_by)
                VALUES (?, 'IN', 'weighing', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $trxNo,
                $materialId,
                $supplierId,
                $weighingId,
                $qtyCalculated,
                $before,
                $after,
                $poNumber,
                $doNumber,
                $locCode,
                $locationId ?: null,
                'Otomatis dari Weighing System (' . $recordNo . ')',
                $user['id']
            ]);

            if ($locationId) {
                applyMaterialLocation($pdo, $materialId, $locationId, $locCode, $qtyCalculated);
            }

            $pdo->commit();
            flash('weighing', "Data timbangan disimpan ($recordNo) & stok bertambah $qtyCalculated otomatis.");
            header('Location: ' . BASE_URL . '/weighing/index.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();
$materials = $pdo->query("
    SELECT m.id, m.code, m.name, m.unit, m.weight_per_unit, m.default_location_id, l.full_code AS default_location_code
    FROM materials m
    LEFT JOIN locations l ON l.id = m.default_location_id
    WHERE m.is_active = 1
    ORDER BY m.code
")->fetchAll();
// FIX: join ke warehouses supaya dropdown lokasi menampilkan konteks gudangnya.
$locations = $pdo->query("
    SELECT l.id, l.full_code, l.description, w.name AS warehouse_name
    FROM locations l
    JOIN warehouses w ON w.id = l.warehouse_id
    WHERE l.is_active = 1
    ORDER BY w.name, l.full_code
")->fetchAll();

$records = $pdo->query("
    SELECT wr.*, m.name AS material_name, m.code AS material_code, m.unit,
           s.name AS supplier_name, u.full_name AS operator,
           st.transaction_no AS stock_trx_no
    FROM weighing_records wr
    JOIN materials m ON wr.material_id = m.id
    JOIN suppliers s ON wr.supplier_id = s.id
    JOIN users u     ON wr.created_by  = u.id
    LEFT JOIN stock_transactions st ON st.weighing_record_id = wr.id
    ORDER BY wr.created_at DESC
    LIMIT 30
")->fetchAll();

$flash = getFlash('weighing');
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title mb-1">Weighing System</div>
<div class="page-subtitle mb-4">Input penerimaan berbasis timbangan — otomatis menambah stok material</div>

<?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= e($flash['msg']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Form Input ─────────────────────────────────── -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-calculator me-2"></i>Input Timbangan</div>
            <div class="card-body">
                <form method="post" id="formWeigh"><?= csrfField() ?>
                    <input type="hidden" name="material_id" id="hidMatId">
                    <input type="hidden" name="supplier_id" id="hidSupplierId">

                    <!-- Material AC -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Material <span class="text-danger">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" id="acMat" class="form-control"
                                placeholder="Ketik kode atau nama material..." autocomplete="off">
                            <div class="ac-list" id="acMatList" style="display:none"></div>
                        </div>
                    </div>

                    <!-- Supplier AC -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Supplier <span class="text-danger">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" id="acSupplier" class="form-control"
                                placeholder="Ketik nama supplier..." autocomplete="off">
                            <div class="ac-list" id="acSupplierList" style="display:none"></div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">No. PO</label>
                            <input type="text" name="po_number" class="form-control" placeholder="Opsional">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">No. DO / SJ</label>
                            <input type="text" name="do_number" class="form-control" placeholder="Opsional">
                        </div>
                    </div>

                    <hr class="my-3">
                    <div class="fw-semibold small text-muted mb-2">Data Timbangan</div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Gross Weight (kg) <span class="text-danger">*</span></label>
                            <input type="number" name="gross_weight" id="inpGross" class="form-control"
                                min="0.001" step="any" placeholder="Berat total" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Tare Weight (kg)</label>
                            <input type="number" name="tare_weight" id="inpTare" class="form-control"
                                min="0" step="any" placeholder="Berat pallet/packaging" value="0">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Qty per Box <span class="text-danger">*</span></label>
                            <input type="number" name="qty_per_box" id="inpQtyBox" class="form-control"
                                min="1" step="1" placeholder="pcs per box" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Jumlah Box <span class="text-danger">*</span></label>
                            <input type="number" name="box_count" id="inpBoxCount" class="form-control"
                                min="1" step="1" placeholder="Jumlah box" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Berat per Unit Spec (kg)</label>
                        <input type="number" name="weight_per_unit_ref" id="inpWRef" class="form-control"
                            min="0" step="any" placeholder="Otomatis dari master material">
                        <div class="form-text">Otomatis diisi dari master material jika ada.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Lokasi Penempatan</label>
                        <input type="hidden" name="location_id" id="hidLocId">
                        <div class="ac-wrap">
                            <input type="text" id="acLoc" class="form-control"
                                placeholder="Cari lokasi (mis. SY-AF)..." autocomplete="off">
                            <div class="ac-list" id="acLocList" style="display:none"></div>
                        </div>
                        <div class="form-text">Otomatis terisi dari lokasi default material jika ada.</div>
                    </div>

                    <!-- Kalkulasi live -->
                    <div class="calc-result mb-4" id="calcResult" style="display:none">
                        <div class="fw-semibold small mb-2">
                            <i class="bi bi-lightning-charge-fill text-warning me-1"></i>Hasil Kalkulasi
                        </div>
                        <div class="calc-row"><span class="calc-label">Net Weight</span><span class="calc-value" id="cNetWeight">—</span></div>
                        <div class="calc-row"><span class="calc-label">Qty Estimasi (box × pcs)</span><span class="calc-value" id="cQtyEst">—</span></div>
                        <div class="calc-row"><span class="calc-label">Berat/unit Aktual</span><span class="calc-value" id="cWActual">—</span></div>
                        <div class="calc-row"><span class="calc-label">Berat/unit Spec</span><span class="calc-value" id="cWRef">—</span></div>
                        <div class="calc-row"><span class="calc-label">Deviasi</span><span class="calc-value" id="cDeviation">—</span></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Catatan</label>
                        <input type="text" name="notes" class="form-control" placeholder="Opsional">
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Simpan Data Timbangan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Riwayat Timbangan ──────────────────────────── -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Riwayat Timbangan (30 Terakhir)</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>No. Record</th>
                                <th>Material</th>
                                <th class="text-end">Net (kg)</th>
                                <th class="text-end">Qty Est.</th>
                                <th class="text-end">W/unit Aktual</th>
                                <th class="text-end">W/unit Spec</th>
                                <th>Lokasi</th>
                                <th>Status Stok</th>
                                <th>Operator</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">Belum ada data timbangan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $r):
                                    $deviation = null;
                                    $devClass  = '';
                                    if ($r['weight_per_unit_ref'] && $r['weight_per_unit_actual']) {
                                        $deviation = (($r['weight_per_unit_actual'] - $r['weight_per_unit_ref']) / $r['weight_per_unit_ref']) * 100;
                                        $devClass  = abs($deviation) <= 1 ? 'deviation-ok' : (abs($deviation) <= 3 ? 'deviation-warn' : 'deviation-bad');
                                    }
                                ?>
                                    <tr>
                                        <td><small><code><?= e($r['record_no']) ?></code></small></td>
                                        <td><small><?= e($r['material_code']) ?> — <?= e($r['material_name']) ?></small></td>
                                        <td class="text-end"><small><?= number_format($r['net_weight'], 3) ?></small></td>
                                        <td class="text-end"><small><?= number_format($r['qty_calculated'], 0) ?> <?= e($r['unit']) ?></small></td>
                                        <td class="text-end"><small><?= $r['weight_per_unit_actual'] ? number_format($r['weight_per_unit_actual'], 4) . ' kg' : '—' ?></small></td>
                                        <td class="text-end"><small><?= $r['weight_per_unit_ref'] ? number_format($r['weight_per_unit_ref'], 4) . ' kg' : '—' ?></small></td>
                                        <td><small><?= $r['location'] ? '<span class="badge bg-light text-dark border">' . e($r['location']) . '</span>' : '—' ?></small></td>
                                        <td><small>
                                                <?php if ($r['stock_trx_no']): ?>
                                                    <span class="badge bg-success" title="<?= e($r['stock_trx_no']) ?>">
                                                        <i class="bi bi-check-circle"></i> Masuk Stok
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">—</span>
                                                <?php endif; ?>
                                            </small></td>
                                        <td><small><?= e($r['operator']) ?></small></td>
                                        <td><small><?= date('d/m H:i', strtotime($r['created_at'])) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<?= acStyles() ?>

<script>
    const MATERIALS = <?= json_encode(array_map(fn($m) => [
                            'id'   => $m['id'],
                            'code' => $m['code'],
                            'name' => $m['name'],
                            'unit' => $m['unit'],
                            'wref' => (float)$m['weight_per_unit'],
                            'defLocId'   => $m['default_location_id'],
                            'defLocCode' => $m['default_location_code'],
                        ], $materials)) ?>;

    const SUPPLIERS = <?= json_encode(array_map(fn($s) => [
                            'id'   => $s['id'],
                            'name' => $s['name'],
                        ], $suppliers)) ?>;

    const LOCATIONS = <?= json_encode(array_map(fn($l) => [
                            'id'   => $l['id'],
                            'code' => $l['full_code'],
                            'desc' => $l['description'],
                            'wh'   => $l['warehouse_name'],
                        ], $locations)) ?>;

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

    // Material AC
    makeAC(
        document.getElementById('acMat'),
        document.getElementById('acMatList'),
        MATERIALS,
        d => {
            document.getElementById('hidMatId').value = d ? d.id : '';
            if (!d) {
                document.getElementById('acMat').classList.remove('ac-confirmed');
                return;
            }
            // Auto-fill weight per unit dari master
            if (d.wref > 0) document.getElementById('inpWRef').value = d.wref;
            // Auto-fill lokasi default jika field lokasi masih kosong
            const locHid = document.getElementById('hidLocId');
            const locInp = document.getElementById('acLoc');
            if (locHid && !locHid.value && d.defLocId && d.defLocCode) {
                locHid.value = d.defLocId;
                locInp.value = d.defLocCode;
                locInp.classList.add('ac-confirmed');
            }
            recalc();
        },
        d => d.code + ' — ' + d.name,
        (d, q) => `<div class="ac-item" data-id="${d.id}">
        <span class="ac-code">${hl(d.code, q)}</span>
        <span class="ac-name">${hl(d.name, q)}</span>
    </div>`
    );

    // Supplier AC
    makeAC(
        document.getElementById('acSupplier'),
        document.getElementById('acSupplierList'),
        SUPPLIERS,
        d => {
            document.getElementById('hidSupplierId').value = d ? d.id : '';
            if (!d) document.getElementById('acSupplier').classList.remove('ac-confirmed');
        },
        d => d.name,
        (d, q) => `<div class="ac-item" data-id="${d.id}">
        <span class="ac-name">${hl(d.name, q)}</span>
    </div>`
    );

    // Lokasi AC (dari Master Lokasi terdaftar)
    makeAC(
        document.getElementById('acLoc'),
        document.getElementById('acLocList'),
        LOCATIONS,
        d => {
            document.getElementById('hidLocId').value = d ? d.id : '';
            if (!d) document.getElementById('acLoc').classList.remove('ac-confirmed');
        },
        d => d.wh + ' ' + d.code,
        (d, q) => `<div class="ac-item" data-id="${d.id}">
        <span class="ac-code">${hl(d.code, q)} <span class="ac-name">— ${hl(d.wh, q)}</span></span>
        ${d.desc ? `<span class="ac-name">${hl(d.desc, q)}</span>` : ''}
    </div>`
    );

    // Live kalkulasi
    ['inpGross', 'inpTare', 'inpQtyBox', 'inpBoxCount', 'inpWRef'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', recalc);
    });

    function recalc() {
        const gross = parseFloat(document.getElementById('inpGross').value) || 0;
        const tare = parseFloat(document.getElementById('inpTare').value) || 0;
        const qtyBox = parseInt(document.getElementById('inpQtyBox').value) || 0;
        const boxCount = parseInt(document.getElementById('inpBoxCount').value) || 0;
        const wRef = parseFloat(document.getElementById('inpWRef').value) || 0;

        if (gross <= 0) {
            document.getElementById('calcResult').style.display = 'none';
            return;
        }

        const net = gross - tare;
        const qtyTotal = qtyBox * boxCount;
        const wActual = qtyTotal > 0 ? net / qtyTotal : 0;

        document.getElementById('calcResult').style.display = 'block';
        document.getElementById('cNetWeight').textContent = net.toFixed(3) + ' kg';
        document.getElementById('cQtyEst').textContent = qtyTotal.toLocaleString() + ' pcs';
        document.getElementById('cWActual').textContent = wActual > 0 ? wActual.toFixed(4) + ' kg/pcs' : '—';
        document.getElementById('cWRef').textContent = wRef > 0 ? wRef.toFixed(4) + ' kg/pcs' : '—';

        if (wRef > 0 && wActual > 0) {
            const dev = ((wActual - wRef) / wRef) * 100;
            const el = document.getElementById('cDeviation');
            el.textContent = (dev >= 0 ? '+' : '') + dev.toFixed(2) + '%';
            el.className = 'calc-value ' + (Math.abs(dev) <= 1 ? 'deviation-ok' : Math.abs(dev) <= 3 ? 'deviation-warn' : 'deviation-bad');
        } else {
            document.getElementById('cDeviation').textContent = '—';
            document.getElementById('cDeviation').className = 'calc-value';
        }
    }

    // Validasi submit
    document.getElementById('formWeigh').addEventListener('submit', function(e) {
        let ok = true;
        if (!document.getElementById('hidMatId').value) {
            document.getElementById('acMat').classList.add('is-invalid');
            ok = false;
        }
        if (!document.getElementById('hidSupplierId').value) {
            document.getElementById('acSupplier').classList.add('is-invalid');
            ok = false;
        }
        if (!ok) {
            e.preventDefault();
            alert('Pastikan material dan supplier dipilih dari suggestion.');
        }
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>