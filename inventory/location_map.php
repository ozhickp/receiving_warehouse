<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin', 'receiving', 'manager']);
$user       = currentUser();
$pageTitle  = 'Peta Lokasi';
$activePage = 'location_map';
$pdo        = getPDO();

// Daftar gudang aktif, untuk selector di atas kanvas
$warehouses = $pdo->query("SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY name")->fetchAll();

$selectedWhId = (int)($_GET['warehouse_id'] ?? 0);
if (!$selectedWhId && $warehouses) $selectedWhId = (int)$warehouses[0]['id'];

$locations = [];
if ($selectedWhId) {
    $stmt = $pdo->prepare("
        SELECT l.id, l.full_code, l.description, l.capacity_max, l.map_x, l.map_y, l.map_w, l.map_h,
               COALESCE(SUM(ml.qty), 0) AS total_qty
        FROM locations l
        LEFT JOIN material_locations ml ON ml.location_id = l.id AND ml.qty > 0
        WHERE l.is_active = 1 AND l.warehouse_id = ?
        GROUP BY l.id
        ORDER BY l.full_code
    ");
    $stmt->execute([$selectedWhId]);
    $locations = $stmt->fetchAll();
}

// Breakdown material per lokasi, dipakai untuk isi modal saat kotak diklik
$breakdown = [];
if (!empty($locations)) {
    $locIds = array_column($locations, 'id');
    $in     = implode(',', array_fill(0, count($locIds), '?'));
    $stmt   = $pdo->prepare("
        SELECT ml.location_id, m.code, m.name, m.unit, ml.qty
        FROM material_locations ml
        JOIN materials m ON m.id = ml.material_id
        WHERE ml.qty > 0 AND ml.location_id IN ($in)
        ORDER BY ml.location_id, m.code
    ");
    $stmt->execute($locIds);
    foreach ($stmt->fetchAll() as $r) {
        $breakdown[(int)$r['location_id']][] = [
            'code' => $r['code'],
            'name' => $r['name'],
            'unit' => $r['unit'],
            'qty'  => (float)$r['qty'],
        ];
    }
}

// Susun posisi + status warna. Lokasi yang belum pernah diatur posisinya (map_x/y NULL)
// ditaruh sementara di grid rapi supaya tetap kelihatan, sambil menunggu admin mengatur di editor.
$gridCols = 6;
$spacingX = 160;
$spacingY = 110;
$mapData  = [];
$i = 0;
foreach ($locations as $l) {
    $qty = (float)$l['total_qty'];
    $cap = $l['capacity_max'] !== null ? (float)$l['capacity_max'] : null;

    if ($qty <= 0) {
        $status = 'empty';
    } elseif ($cap && $qty > $cap) {
        $status = 'over';
    } elseif ($cap && $qty >= $cap * 0.8) {
        $status = 'near';
    } else {
        $status = 'filled';
    }

    $hasPos = $l['map_x'] !== null && $l['map_y'] !== null;
    $mapData[] = [
        'id'     => $l['id'],
        'code'   => $l['full_code'],
        'desc'   => $l['description'],
        'qty'    => $qty,
        'cap'    => $cap,
        'status' => $status,
        'x'      => $hasPos ? (int)$l['map_x'] : 20 + ($i % $gridCols) * $spacingX,
        'y'      => $hasPos ? (int)$l['map_y'] : 20 + intdiv($i, $gridCols) * $spacingY,
        'w'      => (int)$l['map_w'] ?: 120,
        'h'      => (int)$l['map_h'] ?: 80,
    ];
    $i++;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <div class="page-title">Peta Lokasi</div>
        <div class="page-subtitle">Denah penempatan barang — klik kotak untuk lihat isi lokasi</div>
    </div>
    <?php if (hasRole(['admin'])): ?>
        <a href="<?= BASE_URL ?>/inventory/location_map_editor.php<?= $selectedWhId ? '?warehouse_id=' . $selectedWhId : '' ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil me-1"></i> Atur Tata Letak
        </a>
    <?php endif; ?>
</div>

<?php if (empty($warehouses)): ?>
    <div class="alert alert-info">Belum ada gudang aktif.</div>
<?php else: ?>

    <div class="mb-3">
        <label class="form-label small fw-semibold mb-1">Pilih Gudang</label>
        <select class="form-select form-select-sm" style="max-width:320px"
            onchange="location.href='<?= BASE_URL ?>/inventory/location_map.php?warehouse_id='+this.value">
            <?php foreach ($warehouses as $w): ?>
                <option value="<?= $w['id'] ?>" <?= $w['id'] === $selectedWhId ? 'selected' : '' ?>>
                    <?= e($w['name']) ?> (<?= e($w['code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="d-flex flex-wrap gap-3 mb-3">
        <span class="legend-item"><span class="legend-swatch status-empty"></span> Kosong</span>
        <span class="legend-item"><span class="legend-swatch status-filled"></span> Terisi</span>
        <span class="legend-item"><span class="legend-swatch status-near"></span> Mendekati kapasitas</span>
        <span class="legend-item"><span class="legend-swatch status-over"></span> Melebihi kapasitas</span>
    </div>

    <?php if (empty($locations)): ?>
        <div class="alert alert-info">Belum ada lokasi terdaftar di gudang ini.</div>
    <?php else: ?>

        <div class="map-scroll">
            <div id="mapCanvas"></div>
        </div>

    <?php endif; ?>
<?php endif; ?>

<!-- Modal Detail Lokasi -->
<div class="modal fade" id="modalLocDetail" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalLocTitle"><i class="bi bi-geo-alt me-2"></i>Detail Lokasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalLocBody">
                <!-- diisi via JS -->
            </div>
        </div>
    </div>
</div>

<style>
    .map-scroll {
        width: 100%;
        max-height: 70vh;
        overflow: auto;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        background: #fafbfc;
    }

    #mapCanvas {
        position: relative;
        width: 1600px;
        height: 1000px;
        background-image:
            linear-gradient(to right, #ececec 1px, transparent 1px),
            linear-gradient(to bottom, #ececec 1px, transparent 1px);
        background-size: 40px 40px;
    }

    .map-box {
        position: absolute;
        border-radius: 6px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        user-select: none;
        font-size: 12px;
        font-weight: 600;
        text-align: center;
        padding: 4px;
        transition: box-shadow .15s;
    }

    .map-box:hover {
        box-shadow: 0 0 0 3px rgba(0, 0, 0, .12);
    }

    .map-box .map-box-qty {
        font-size: 11px;
        font-weight: 500;
        opacity: .85;
    }

    .map-box.status-empty {
        background: #f1f3f5;
        border: 2px solid #adb5bd;
        color: #495057;
    }

    .map-box.status-filled {
        background: #e7f1ff;
        border: 2px solid #0d6efd;
        color: #0a3d91;
    }

    .map-box.status-near {
        background: #fff3cd;
        border: 2px solid #ffc107;
        color: #7a5b00;
    }

    .map-box.status-over {
        background: #f8d7da;
        border: 2px solid #dc3545;
        color: #7a1f28;
    }

    .legend-item {
        font-size: 13px;
        color: #495057;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .legend-swatch {
        width: 14px;
        height: 14px;
        border-radius: 3px;
        display: inline-block;
    }

    .legend-swatch.status-empty {
        background: #f1f3f5;
        border: 2px solid #adb5bd;
    }

    .legend-swatch.status-filled {
        background: #e7f1ff;
        border: 2px solid #0d6efd;
    }

    .legend-swatch.status-near {
        background: #fff3cd;
        border: 2px solid #ffc107;
    }

    .legend-swatch.status-over {
        background: #f8d7da;
        border: 2px solid #dc3545;
    }
</style>

<script>
    const MAP_DATA = <?= json_encode($mapData) ?>;
    const BREAKDOWN = <?= json_encode($breakdown) ?>; // location_id -> [{code, name, unit, qty}, ...]

    const canvas = document.getElementById('mapCanvas');

    function makeBox(loc) {
        const box = document.createElement('div');
        box.className = 'map-box status-' + loc.status;
        box.dataset.id = loc.id;
        box.style.left = loc.x + 'px';
        box.style.top = loc.y + 'px';
        box.style.width = loc.w + 'px';
        box.style.height = loc.h + 'px';
        box.innerHTML = `
        <div>${loc.code}</div>
        <div class="map-box-qty">${loc.qty.toLocaleString('id-ID')}</div>
    `;
        box.addEventListener('click', () => openDetail(loc));
        return box;
    }

    if (canvas) {
        MAP_DATA.forEach(loc => canvas.appendChild(makeBox(loc)));
    }

    function openDetail(loc) {
        document.getElementById('modalLocTitle').innerHTML =
            `<i class="bi bi-geo-alt me-2"></i>Lokasi ${loc.code}`;

        const items = BREAKDOWN[loc.id] || [];
        let capInfo = '';
        if (loc.cap) {
            capInfo = `<div class="text-muted small mb-2">Kapasitas maksimum: ${loc.cap.toLocaleString('id-ID')}</div>`;
        }

        let body = `
        ${loc.desc ? `<p class="text-muted small mb-2">${loc.desc}</p>` : ''}
        <div class="mb-2"><strong>Total qty di lokasi ini:</strong> ${loc.qty.toLocaleString('id-ID')}</div>
        ${capInfo}
    `;

        if (!items.length) {
            body += '<p class="text-muted text-center py-3 small mb-0">Lokasi ini sedang kosong.</p>';
        } else {
            body += `
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Kode</th><th>Nama Material</th><th class="text-end">Qty</th></tr></thead>
                    <tbody>
                        ${items.map(it => `
                            <tr>
                                <td><small><code>${it.code}</code></small></td>
                                <td><small>${it.name}</small></td>
                                <td class="text-end"><small>${it.qty.toLocaleString('id-ID')} ${it.unit}</small></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        }

        document.getElementById('modalLocBody').innerHTML = body;
        new bootstrap.Modal(document.getElementById('modalLocDetail')).show();
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>