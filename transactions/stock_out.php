<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin', 'receiving']);
$user       = currentUser();
$activePage = 'stock_out';
$pageTitle  = 'Stock Out';

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $materialId = (int)($_POST['material_id'] ?? 0);
    $qty        = (float)($_POST['qty']        ?? 0);
    $locationId = (int)($_POST['location_id']  ?? 0);
    $destType   = trim($_POST['destination_type']   ?? ''); // 'produksi' | 'vendor'
    $destVendor = trim($_POST['destination_vendor'] ?? '');
    $notes      = trim($_POST['notes']         ?? '');
    $errors     = [];

    // Tujuan disusun jadi satu string dan tetap disimpan ke kolom reference yang sudah ada
    if ($destType === 'produksi') {
        $reference = 'Produksi';
    } elseif ($destType === 'vendor') {
        $reference = $destVendor !== '' ? 'Vendor Lain: ' . $destVendor : 'Vendor Lain';
    } else {
        $reference = '';
    }

    if (!$materialId) $errors[] = 'Material wajib dipilih.';
    if ($qty <= 0)    $errors[] = 'Qty harus lebih dari 0.';
    if (!in_array($destType, ['produksi', 'vendor'], true)) $errors[] = 'Tujuan wajib dipilih (Produksi atau Vendor Lain).';
    if ($destType === 'vendor' && $destVendor === '') $errors[] = 'Nama vendor tujuan wajib diisi.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT stock_current FROM materials WHERE id = ? FOR UPDATE");
            $stmt->execute([$materialId]);
            $before = (float)$stmt->fetchColumn();

            // Lokasi kini otomatis mengikuti lokasi tempat barang tersimpan (bukan input bebas).
            // Validasi ulang di server: location_id yang dikirim harus benar-benar punya stok utk material ini.
            $locCode = null;
            if ($locationId) {
                $stmt = $pdo->prepare("
                    SELECT l.full_code
                    FROM material_locations ml
                    JOIN locations l ON l.id = ml.location_id
                    WHERE ml.material_id = ? AND ml.location_id = ? AND ml.qty > 0
                ");
                $stmt->execute([$materialId, $locationId]);
                $locCode = $stmt->fetchColumn();
                if (!$locCode) $locationId = 0;
            }

            // Kalau barang tersimpan di lebih dari satu lokasi, lokasi wajib dipilih (tidak boleh dikosongkan).
            $locationRequired = false;
            if (!$locationId) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM material_locations WHERE material_id = ? AND qty > 0");
                $stmt->execute([$materialId]);
                $locationRequired = (int)$stmt->fetchColumn() > 1;
            }

            if ($locationRequired) {
                $errors[] = 'Barang tersimpan di beberapa lokasi. Lokasi wajib dipilih.';
                $pdo->rollBack();
            } elseif ($qty > $before) {
                $errors[] = 'Qty melebihi stok tersedia (' . number_format($before, 2) . ').';
                $pdo->rollBack();
            } elseif ($locationId && !decrementMaterialLocation($pdo, $materialId, $locationId, $qty)) {
                $errors[] = 'Qty melebihi stok di lokasi ' . $locCode . '.';
                $pdo->rollBack();
            } else {
                $after = $before - $qty;
                $trxNo = generateTrxNo('OUT');
                $pdo->prepare("
                    INSERT INTO stock_transactions
                        (transaction_no, type, material_id, qty, qty_before, qty_after, reference, notes, location, location_id, created_by)
                    VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$trxNo, $materialId, $qty, $before, $after, $reference, $notes, $locCode, $locationId ?: null, $user['id']]);
                $pdo->commit();
                flash('stock_out', 'Stock Out berhasil disimpan.');
                header('Location: ' . BASE_URL . '/transactions/stock_out.php');
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal: ' . $e->getMessage();
        }
    }
}

$materials = $pdo->query("SELECT id, code, name, unit, stock_current FROM materials WHERE is_active = 1 ORDER BY code")->fetchAll();

// Breakdown stok per lokasi untuk tiap material — sumber kebenaran untuk lokasi otomatis saat material dipilih
// FIX: join ke warehouses supaya breakdown lokasi menampilkan konteks gudangnya.
$materialLocRows = $pdo->query("
    SELECT ml.material_id, ml.location_id, ml.qty, l.full_code, w.name AS warehouse_name
    FROM material_locations ml
    JOIN locations l ON l.id = ml.location_id
    JOIN warehouses w ON w.id = l.warehouse_id
    WHERE ml.qty > 0
    ORDER BY ml.material_id, w.name, l.full_code
")->fetchAll();

$locBreakdown = [];
foreach ($materialLocRows as $row) {
    $locBreakdown[(int)$row['material_id']][] = [
        'location_id' => (int)$row['location_id'],
        'code'        => $row['full_code'],
        'wh'          => $row['warehouse_name'],
        'qty'         => (float)$row['qty'],
    ];
}

$todayOut = $pdo->query("
    SELECT st.transaction_no, st.qty, st.reference, st.location,
           m.name AS material_name, m.unit
    FROM stock_transactions st
    JOIN materials m ON st.material_id = m.id
    WHERE st.type = 'OUT' AND DATE(st.transaction_date) = CURDATE()
    ORDER BY st.transaction_date DESC
")->fetchAll();

$flash = getFlash('stock_out');
$navbarTitle    = 'Stock Out';
$navbarSubtitle = 'Pengeluaran material';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= e($flash['msg']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Form Pengeluaran</div>
            <div class="card-body">
                <form method="post" id="formStockOut"><?= csrfField() ?>
                    <input type="hidden" name="material_id" id="hidMatId">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Material <span class="text-danger">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" id="acMat" class="form-control"
                                placeholder="Ketik kode atau nama material..." autocomplete="off">
                            <div class="ac-list" id="acMatList" style="display:none"></div>
                        </div>
                        <div id="stockBadge" class="mt-1" style="display:none">
                            <small class="text-success fw-semibold" id="stockBadgeText"></small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Qty <span class="text-danger">*</span></label>
                        <input type="number" name="qty" id="inpQty"
                            class="form-control" min="0.001" step="any" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Lokasi</label>
                        <input type="hidden" name="location_id" id="hidLocId">
                        <div id="locAuto" class="form-control-plaintext border rounded px-2 py-2 bg-light text-muted small">
                            Pilih material terlebih dahulu.
                        </div>
                        <div id="locChips" class="mt-2 d-flex flex-wrap gap-1"></div>
                        <small class="text-muted">Lokasi otomatis mengikuti tempat barang tersimpan.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Tujuan <span class="text-danger">*</span></label>
                        <select name="destination_type" id="selDest" class="form-select" required>
                            <option value="">-- Pilih Tujuan --</option>
                            <option value="produksi">Produksi</option>
                            <option value="vendor">Vendor Lain</option>
                        </select>
                        <input type="text" name="destination_vendor" id="inpDestVendor"
                            class="form-control mt-2" placeholder="Nama vendor tujuan" style="display:none">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Catatan</label>
                        <input type="text" name="notes" class="form-control" placeholder="Opsional">
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-warning text-dark fw-semibold">
                            <i class="bi bi-box-arrow-up me-1"></i> Simpan Stock Out
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Pengeluaran Hari Ini</div>
            <div class="card-body p-0">
                <?php if (empty($todayOut)): ?>
                    <p class="text-muted text-center py-4 small">Belum ada pengeluaran hari ini.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Material</th>
                                    <th class="text-end" style="width:55px">Qty</th>
                                    <th>Lokasi</th>
                                    <th>Tujuan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todayOut as $t): ?>
                                    <tr>
                                        <td><small><?= e($t['material_name']) ?></small></td>
                                        <td class="text-end" style="width:55px"><small><?= number_format($t['qty'], 0) ?></small></td>
                                        <td><small><?= e($t['location'] ?? '—') ?></small></td>
                                        <td><small><?= e($t['reference'] ?? '—') ?></small></td>
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

    // material_id -> [{location_id, code, qty}, ...]
    const LOC_BREAKDOWN = <?= json_encode($locBreakdown) ?>;

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

    const hidLocId = document.getElementById('hidLocId');
    const locAuto = document.getElementById('locAuto');
    const locChips = document.getElementById('locChips');

    function setLocAuto(text, variant) {
        const styles = {
            muted: 'form-control-plaintext border rounded px-2 py-2 bg-light text-muted small',
            ok: 'form-control-plaintext border rounded px-2 py-2 bg-success-subtle text-success fw-semibold small',
            warn: 'form-control-plaintext border rounded px-2 py-2 bg-warning-subtle text-dark small',
            empty: 'form-control-plaintext border rounded px-2 py-2 bg-danger-subtle text-danger fw-semibold small',
        };
        locAuto.className = styles[variant] || styles.muted;
        locAuto.textContent = text;
    }

    function clearLocationSelection() {
        hidLocId.value = '';
        locChips.innerHTML = '';
        setLocAuto('Pilih material terlebih dahulu.', 'muted');
    }

    // Lokasi otomatis mengikuti tempat barang tersimpan: 0 lokasi -> "Barang kosong",
    // 1 lokasi -> otomatis terisi, >1 lokasi -> pilih salah satu dari lokasi yang memang ada stoknya.
    function renderLocation(materialId) {
        const rows = LOC_BREAKDOWN[materialId] || [];
        locChips.innerHTML = '';

        if (!rows.length) {
            hidLocId.value = '';
            setLocAuto('Barang kosong', 'empty');
            return;
        }

        if (rows.length === 1) {
            hidLocId.value = rows[0].location_id;
            setLocAuto(`Lokasi: ${rows[0].wh} — ${rows[0].code} (stok ${rows[0].qty.toLocaleString('id-ID')})`, 'ok');
            return;
        }

        hidLocId.value = '';
        setLocAuto('Barang tersimpan di beberapa lokasi, pilih salah satu:', 'warn');
        locChips.innerHTML = rows.map(r => `
        <span class="badge bg-light text-dark border loc-chip" style="cursor:pointer"
              data-loc-id="${r.location_id}" data-loc-code="${r.code}" data-loc-wh="${r.wh}" data-loc-qty="${r.qty}">
            ${r.wh} — ${r.code}: ${r.qty.toLocaleString('id-ID')}
        </span>
    `).join('');
    }

    locChips.addEventListener('click', e => {
        const chip = e.target.closest('.loc-chip');
        if (!chip) return;
        hidLocId.value = chip.dataset.locId;
        setLocAuto(`Lokasi dipilih: ${chip.dataset.locWh} — ${chip.dataset.locCode} (stok ${Number(chip.dataset.locQty).toLocaleString('id-ID')})`, 'ok');
        locChips.querySelectorAll('.loc-chip').forEach(c => c.classList.toggle('loc-chip-active', c === chip));
    });

    const selDest = document.getElementById('selDest');
    const inpDestVendor = document.getElementById('inpDestVendor');
    selDest.addEventListener('change', () => {
        selDest.classList.remove('is-invalid');
        if (selDest.value === 'vendor') {
            inpDestVendor.style.display = 'block';
            inpDestVendor.required = true;
        } else {
            inpDestVendor.style.display = 'none';
            inpDestVendor.required = false;
            inpDestVendor.classList.remove('is-invalid');
            inpDestVendor.value = '';
        }
    });

    makeAC(
        document.getElementById('acMat'),
        document.getElementById('acMatList'),
        MATERIALS,
        d => {
            document.getElementById('hidMatId').value = d ? d.id : '';
            clearLocationSelection();
            if (d) {
                document.getElementById('stockBadgeText').textContent = `Stok saat ini: ${d.stock.toLocaleString('id-ID')}`;
                document.getElementById('stockBadge').style.display = 'block';
                renderLocation(d.id);
            } else {
                document.getElementById('stockBadge').style.display = 'none';
                document.getElementById('acMat').classList.remove('ac-confirmed');
                locChips.innerHTML = '';
            }
        },
        d => d.code + ' — ' + d.name,
        (d, q) => `<div class="ac-item" data-id="${d.id}">
        <span class="ac-code">${hl(d.code, q)}</span>
        <span class="ac-name">${hl(d.name, q)}</span>
        <span class="ac-stock">Stok: ${d.stock.toFixed(2)} ${d.unit}</span>
    </div>`
    );

    document.getElementById('formStockOut').addEventListener('submit', function(e) {
        const matId = document.getElementById('hidMatId').value;
        if (!matId) {
            e.preventDefault();
            document.getElementById('acMat').classList.add('is-invalid');
            alert('Pilih material dari daftar suggestion terlebih dahulu.');
            return;
        }

        const rows = LOC_BREAKDOWN[matId] || [];
        if (rows.length > 1 && !hidLocId.value) {
            e.preventDefault();
            alert('Barang tersimpan di beberapa lokasi. Pilih salah satu lokasi terlebih dahulu.');
            return;
        }

        if (!selDest.value) {
            e.preventDefault();
            selDest.classList.add('is-invalid');
            alert('Pilih tujuan (Produksi atau Vendor Lain) terlebih dahulu.');
            return;
        }

        if (selDest.value === 'vendor' && !inpDestVendor.value.trim()) {
            e.preventDefault();
            inpDestVendor.classList.add('is-invalid');
            alert('Isi nama vendor tujuan.');
            return;
        }

        // Validasi ringan di sisi client: cek qty vs stok di lokasi terpilih (validasi final tetap di server)
        const locId = hidLocId.value;
        if (locId) {
            const qty = parseFloat(document.getElementById('inpQty').value || '0');
            const row = rows.find(r => String(r.location_id) === String(locId));
            if (row && qty > row.qty) {
                e.preventDefault();
                alert(`Qty melebihi stok di lokasi ${row.code} (tersedia ${row.qty.toLocaleString('id-ID')}).`);
            }
        }
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>