<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin', 'receiving']);
$user       = currentUser();
$activePage = 'stock_in';
$pageTitle  = 'Stock In';

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $poNumber   = trim($_POST['po_number']   ?? '');
    $doNumber   = trim($_POST['do_number']   ?? '');
    $notes      = trim($_POST['notes']       ?? '');
    $items      = $_POST['items'] ?? [];

    $errors = [];
    if (!$supplierId)  $errors[] = 'Supplier wajib dipilih.';
    if (!$poNumber)    $errors[] = 'No. PO wajib diisi.';
    if (!$doNumber)    $errors[] = 'No. DO / Surat Jalan wajib diisi.';
    if (empty($items)) $errors[] = 'Minimal 1 item material.';

    // Validasi tiap item: Material, Qty, dan Lokasi wajib diisi lengkap.
    // Baris yang benar-benar kosong (sisa tombol "Tambah Item" yang tidak diisi) diabaikan, bukan dianggap error.
    $validItems = [];
    if (empty($errors)) {
        foreach ($items as $i => $item) {
            $itMaterialId = (int)($item['material_id'] ?? 0);
            $itQtyRaw     = trim((string)($item['qty'] ?? ''));
            $itLocationId = (int)($item['location_id'] ?? 0);

            if (!$itMaterialId && $itQtyRaw === '' && !$itLocationId) continue; // baris kosong, abaikan

            $rowNo = $i + 1;
            $qtyOk = $itQtyRaw !== '' && is_numeric($itQtyRaw) && (float)$itQtyRaw > 0;
            if (!$itMaterialId) $errors[] = "Item baris $rowNo: material wajib dipilih.";
            if (!$qtyOk)        $errors[] = "Item baris $rowNo: qty wajib diisi lebih dari 0.";
            if (!$itLocationId) $errors[] = "Item baris $rowNo: lokasi wajib dipilih.";

            if ($itMaterialId && $qtyOk && $itLocationId) {
                $validItems[] = ['material_id' => $itMaterialId, 'qty' => (float)$itQtyRaw, 'location_id' => $itLocationId];
            }
        }
        if (empty($errors) && !$validItems) $errors[] = 'Minimal 1 item material yang lengkap (material, qty, lokasi).';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            foreach ($validItems as $item) {
                $materialId = $item['material_id'];
                $qty        = $item['qty'];
                $locationId = $item['location_id'];

                $locCode = resolveLocation($pdo, $locationId);
                if (!$locCode) {
                    throw new Exception("Lokasi tidak valid/tidak aktif untuk salah satu item (material ID $materialId).");
                }

                $stmt = $pdo->prepare("SELECT stock_current FROM materials WHERE id = ? FOR UPDATE");
                $stmt->execute([$materialId]);
                $before = (float)$stmt->fetchColumn();
                $after  = $before + $qty;

                $trxNo = generateTrxNo('IN');
                $pdo->prepare("
                    INSERT INTO stock_transactions
                        (transaction_no, type, source, material_id, supplier_id, qty, qty_before, qty_after, po_number, do_number, location, location_id, notes, created_by)
                    VALUES (?, 'IN', 'manual', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$trxNo, $materialId, $supplierId, $qty, $before, $after, $poNumber, $doNumber, $locCode, $locationId, $notes, $user['id']]);

                // Update lokasi default material + mapping stok per lokasi
                applyMaterialLocation($pdo, $materialId, $locationId, $locCode, $qty);
            }
            $pdo->commit();
            flash('stock_in', 'Stock In berhasil disimpan.');
            header('Location: ' . BASE_URL . '/transactions/stock_in.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();
$materials = $pdo->query("
    SELECT m.id, m.code, m.name, m.unit, m.stock_current, m.default_location_id, l.full_code AS default_location_code
    FROM materials m
    LEFT JOIN locations l ON l.id = m.default_location_id
    WHERE m.is_active = 1
    ORDER BY m.code
")->fetchAll();

// Master Lokasi terdaftar — hanya yang aktif yang bisa dipilih
// FIX: join ke warehouses supaya dropdown lokasi menampilkan konteks gudangnya
// (sebelumnya hanya kode lokasi tanpa nama gudang, membingungkan kalau ada
// kode lokasi yang mirip di gudang berbeda).
$locations = $pdo->query("
    SELECT l.id, l.full_code, l.description, w.name AS warehouse_name
    FROM locations l
    JOIN warehouses w ON w.id = l.warehouse_id
    WHERE l.is_active = 1
    ORDER BY w.name, l.full_code
")->fetchAll();

$todayIn = $pdo->query("
    SELECT st.transaction_no, st.qty, st.po_number, st.location, st.source,
           m.name AS material_name, m.unit
    FROM stock_transactions st
    JOIN materials m ON st.material_id = m.id
    WHERE st.type = 'IN' AND DATE(st.transaction_date) = CURDATE()
    ORDER BY st.transaction_date DESC
")->fetchAll();

$flash = getFlash('stock_in');
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title mb-1">Stock In</div>
<div class="page-subtitle mb-4">Penerimaan barang dari supplier</div>

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

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Form Penerimaan Barang</div>
            <div class="card-body">
                <form method="post" id="formStockIn"><?= csrfField() ?>
                    <input type="hidden" name="supplier_id" id="hidSupplierId">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Supplier <span class="text-danger">*</span></label>
                            <div class="ac-wrap">
                                <input type="text" id="acSupplier" class="form-control"
                                    placeholder="Ketik nama supplier..." autocomplete="off">
                                <div class="ac-list" id="acSupplierList" style="display:none"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">No. PO <span class="text-danger">*</span></label>
                            <input type="text" name="po_number" class="form-control" placeholder="Contoh: PO-2024-001" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">No. DO / Surat Jalan <span class="text-danger">*</span></label>
                            <input type="text" name="do_number" class="form-control" placeholder="Nomor DO dari supplier" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Catatan</label>
                            <input type="text" name="notes" class="form-control" placeholder="Opsional">
                        </div>
                    </div>

                    <div class="fw-semibold small mb-2">Item Material <span class="text-danger">*</span></div>
                    <div class="row g-2 mb-1 d-none d-md-flex">
                        <div class="col-md-5"><small class="text-muted">Material <span class="text-danger">*</span></small></div>
                        <div class="col-md-2"><small class="text-muted">Qty <span class="text-danger">*</span></small></div>
                        <div class="col-md-4"><small class="text-muted">Lokasi (Master Lokasi) <span class="text-danger">*</span></small></div>
                    </div>
                    <div id="itemRows"></div>

                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="btnAddRow">
                        <i class="bi bi-plus-lg"></i> Tambah Item
                    </button>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-box-arrow-in-down me-1"></i> Simpan Stock In
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Penerimaan Hari Ini -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Penerimaan Hari Ini</div>
            <div class="card-body p-0">
                <?php if (empty($todayIn)): ?>
                    <p class="text-muted text-center py-4 small">Belum ada penerimaan hari ini.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>No. PO</th>
                                    <th>Material</th>
                                    <th class="text-end" style="width:60px">Qty</th>
                                    <th>Lokasi</th>
                                    <th>Sumber</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todayIn as $t): ?>
                                    <tr>
                                        <td><small><code><?= e($t['po_number'] ?? '-') ?></code></small></td>
                                        <td><small><?= e($t['material_name']) ?></small></td>
                                        <td class="text-end" style="width:60px"><small><?= number_format($t['qty'], 0) ?></small></td>
                                        <td><small><?= $t['location'] ? '<span class="badge bg-light text-dark border">' . e($t['location']) . '</span>' : '—' ?></small></td>
                                        <td><small><?= $t['source'] === 'weighing' ? '<span class="badge bg-info text-dark">Timbangan</span>' : '<span class="badge bg-secondary">Manual</span>' ?></small></td>
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
    const SUPPLIERS = <?= json_encode(array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name']], $suppliers)) ?>;
    const MATERIALS = <?= json_encode(array_map(fn($m) => [
                            'id'       => $m['id'],
                            'code'     => $m['code'],
                            'name'     => $m['name'],
                            'unit'     => $m['unit'],
                            'stock'    => (float)$m['stock_current'],
                            'defLocId'   => $m['default_location_id'],
                            'defLocCode' => $m['default_location_code'],
                        ], $materials)) ?>;
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
        (d, q) => `<div class="ac-item" data-id="${d.id}"><span class="ac-name">${hl(d.name, q)}</span></div>`
    );

    // Material AC per row
    let rowIndex = 0;

    function buildRow(idx) {
        const div = document.createElement('div');
        div.className = 'item-row row g-2 mb-2';
        div.innerHTML = `
        <div class="col-md-5">
            <div class="ac-wrap">
                <input type="text" class="form-control form-control-sm ac-mat-input"
                       placeholder="Ketik kode atau nama material..." autocomplete="off">
                <input type="hidden" name="items[${idx}][material_id]" class="ac-mat-hidden">
                <div class="ac-list ac-mat-list" style="display:none"></div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <input type="number" name="items[${idx}][qty]"
                   class="form-control form-control-sm" placeholder="Qty" min="0.001" step="any" required>
        </div>
        <div class="col-md-4 col-5">
            <div class="ac-wrap">
                <input type="text" class="form-control form-control-sm ac-loc-input"
                       placeholder="Cari lokasi (mis. SY-AF)..." autocomplete="off">
                <input type="hidden" name="items[${idx}][location_id]" class="ac-loc-hidden">
                <div class="ac-list ac-loc-list" style="display:none"></div>
            </div>
        </div>
        <div class="col-md-1 col-1 d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row">
                <i class="bi bi-x"></i>
            </button>
        </div>`;

        const inp = div.querySelector('.ac-mat-input');
        const hid = div.querySelector('.ac-mat-hidden');
        const list = div.querySelector('.ac-mat-list');
        const locInp = div.querySelector('.ac-loc-input');
        const locHid = div.querySelector('.ac-loc-hidden');
        const locList = div.querySelector('.ac-loc-list');
        const qtyInp = div.querySelector('input[name*="[qty]"]');
        qtyInp.addEventListener('input', () => qtyInp.classList.remove('is-invalid'));

        makeAC(locInp, locList, LOCATIONS,
            d => {
                locHid.value = d ? d.id : '';
                if (!d) locInp.classList.remove('ac-confirmed');
            },
            d => d.wh + ' ' + d.code,
            (d, q) => `<div class="ac-item" data-id="${d.id}">
            <span class="ac-code">${hl(d.code, q)} <span class="ac-name">— ${hl(d.wh, q)}</span></span>
            ${d.desc ? `<span class="ac-name">${hl(d.desc, q)}</span>` : ''}
        </div>`
        );

        makeAC(inp, list, MATERIALS,
            d => {
                hid.value = d ? d.id : '';
                if (!d) {
                    inp.classList.remove('ac-confirmed');
                    return;
                }
                // Auto-isi lokasi default material ini jika lokasi belum dipilih manual
                if (!locHid.value && d.defLocId && d.defLocCode) {
                    locHid.value = d.defLocId;
                    locInp.value = d.defLocCode;
                    locInp.classList.add('ac-confirmed');
                }
            },
            d => d.code + ' — ' + d.name,
            (d, q) => `<div class="ac-item" data-id="${d.id}">
            <span class="ac-code">${hl(d.code, q)}</span>
            <span class="ac-name">${hl(d.name, q)}</span>
        </div>`
        );
        return div;
    }

    document.getElementById('btnAddRow').addEventListener('click', () => {
        document.getElementById('itemRows').appendChild(buildRow(rowIndex++));
    });

    document.getElementById('itemRows').addEventListener('click', e => {
        if (e.target.closest('.btn-remove-row')) {
            const rows = document.querySelectorAll('.item-row');
            if (rows.length > 1) e.target.closest('.item-row').remove();
        }
    });

    document.getElementById('formStockIn').addEventListener('submit', function(e) {
        let ok = true;
        if (!document.getElementById('hidSupplierId').value) {
            document.getElementById('acSupplier').classList.add('is-invalid');
            ok = false;
        }

        document.querySelectorAll('.item-row').forEach(row => {
            const matHid = row.querySelector('.ac-mat-hidden');
            const matInp = row.querySelector('.ac-mat-input');
            const qtyInp = row.querySelector('input[name*="[qty]"]');
            const locHid = row.querySelector('.ac-loc-hidden');
            const locInp = row.querySelector('.ac-loc-input');

            const isBlankRow = !matHid.value && !qtyInp.value.trim() && !locHid.value;
            if (isBlankRow) return; // baris kosong sisa "Tambah Item", biarkan (akan diabaikan server)

            if (!matHid.value) {
                matInp.classList.add('is-invalid');
                ok = false;
            }
            if (!qtyInp.value || parseFloat(qtyInp.value) <= 0) {
                qtyInp.classList.add('is-invalid');
                ok = false;
            }
            if (!locHid.value) {
                locInp.classList.add('is-invalid');
                ok = false;
            }
        });

        if (!ok) {
            e.preventDefault();
            alert('Pastikan supplier dipilih, dan setiap item terisi lengkap: material, qty, dan lokasi.');
        }
    });

    document.getElementById('itemRows').appendChild(buildRow(rowIndex++));
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>