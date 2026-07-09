<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);
$user       = currentUser();
$pageTitle  = 'Atur Peta Lokasi';
$activePage = 'location_map_editor';
$pdo        = getPDO();

// ── AJAX save (dipanggil via fetch, bukan submit form biasa) ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $payload = json_decode(file_get_contents('php://input'), true);

    // CSRF check untuk endpoint JSON: token dikirim di body, bukan $_POST biasa,
    // jadi tidak bisa pakai csrfCheck() standar (yang baca $_POST langsung).
    $sentToken = is_array($payload) ? ($payload['csrf_token'] ?? '') : '';
    if (!hash_equals(csrfToken(), (string)$sentToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sesi tidak valid, silakan refresh halaman dan coba lagi.']);
        exit;
    }

    if (!is_array($payload) || !isset($payload['locations']) || !is_array($payload['locations'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Payload tidak valid.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE locations SET map_x = ?, map_y = ?, map_w = ?, map_h = ? WHERE id = ?");
        foreach ($payload['locations'] as $loc) {
            $id = (int)($loc['id'] ?? 0);
            if (!$id) continue;
            $x = (int)($loc['map_x'] ?? 0);
            $y = (int)($loc['map_y'] ?? 0);
            $w = max(60, (int)($loc['map_w'] ?? 120));
            $h = max(40, (int)($loc['map_h'] ?? 80));
            $stmt->execute([$x, $y, $w, $h, $id]);
        }
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Daftar gudang aktif, untuk selector di atas kanvas
$warehouses = $pdo->query("SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY name")->fetchAll();

$selectedWhId = (int)($_GET['warehouse_id'] ?? 0);
if (!$selectedWhId && $warehouses) $selectedWhId = (int)$warehouses[0]['id'];

$locations = [];
if ($selectedWhId) {
    $stmt = $pdo->prepare("
        SELECT id, full_code, description, map_x, map_y, map_w, map_h
        FROM locations WHERE is_active = 1 AND warehouse_id = ? ORDER BY full_code
    ");
    $stmt->execute([$selectedWhId]);
    $locations = $stmt->fetchAll();
}

// Susun posisi awal: kalau lokasi belum pernah diatur (map_x/map_y NULL),
// taruh sementara di grid rapi supaya tidak numpuk di titik 0,0.
$gridCols = 6;
$spacingX = 160;
$spacingY = 110;
$mapData  = [];
$i = 0;
foreach ($locations as $l) {
    $hasPos = $l['map_x'] !== null && $l['map_y'] !== null;
    $mapData[] = [
        'id'   => $l['id'],
        'code' => $l['full_code'],
        'desc' => $l['description'],
        'x'    => $hasPos ? (int)$l['map_x'] : 20 + ($i % $gridCols) * $spacingX,
        'y'    => $hasPos ? (int)$l['map_y'] : 20 + intdiv($i, $gridCols) * $spacingY,
        'w'    => (int)$l['map_w'] ?: 120,
        'h'    => (int)$l['map_h'] ?: 80,
    ];
    $i++;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <div class="page-title">Atur Peta Lokasi</div>
        <div class="page-subtitle">Geser dan atur ukuran kotak sesuai denah gudang asli, lalu simpan</div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/inventory/location_map.php<?= $selectedWhId ? '?warehouse_id=' . $selectedWhId : '' ?>" class="btn btn-outline-secondary">
            <i class="bi bi-eye me-1"></i> Lihat Peta
        </a>
        <button class="btn btn-primary" id="btnSaveLayout" <?= empty($locations) ? 'disabled' : '' ?>>
            <i class="bi bi-save me-1"></i> Simpan Tata Letak
        </button>
    </div>
</div>

<?php if (empty($warehouses)): ?>
    <div class="alert alert-info">
        Belum ada gudang aktif. Daftarkan gudang dulu di menu <strong>Master Gudang</strong>.
    </div>
<?php else: ?>

    <div class="mb-3">
        <label class="form-label small fw-semibold mb-1">Pilih Gudang</label>
        <select class="form-select form-select-sm" style="max-width:320px"
            onchange="location.href='<?= BASE_URL ?>/inventory/location_map_editor.php?warehouse_id='+this.value">
            <?php foreach ($warehouses as $w): ?>
                <option value="<?= $w['id'] ?>" <?= $w['id'] === $selectedWhId ? 'selected' : '' ?>>
                    <?= e($w['name']) ?> (<?= e($w['code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (empty($locations)): ?>
        <div class="alert alert-info">Belum ada lokasi terdaftar di gudang ini. Tambahkan lokasi dulu di Master Lokasi.</div>
    <?php else: ?>

        <p class="text-muted small mb-2">
            <i class="bi bi-info-circle me-1"></i>
            Klik-tahan kotak untuk menggeser. Tarik kotak kecil di pojok kanan-bawah untuk mengubah ukuran.
        </p>

        <div class="map-scroll">
            <div id="mapCanvas"></div>
        </div>

    <?php endif; ?>
<?php endif; ?>

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
        background: #e7f1ff;
        border: 2px solid #0d6efd;
        border-radius: 6px;
        cursor: move;
        display: flex;
        align-items: center;
        justify-content: center;
        user-select: none;
        font-size: 12px;
        font-weight: 600;
        color: #0a3d91;
    }

    .map-box.map-box-active {
        box-shadow: 0 0 0 3px rgba(13, 110, 253, .35);
        z-index: 10;
    }

    .map-box-label {
        pointer-events: none;
        padding: 4px;
        text-align: center;
    }

    .map-box-resize {
        position: absolute;
        right: 0;
        bottom: 0;
        width: 14px;
        height: 14px;
        cursor: nwse-resize;
        background: #0d6efd;
        border-radius: 3px 0 6px 0;
    }
</style>

<script>
    const MAP_DATA = <?= json_encode($mapData) ?>;

    const canvas = document.getElementById('mapCanvas');
    let dragging = null;
    let resizing = null;

    function makeBox(loc) {
        const box = document.createElement('div');
        box.className = 'map-box';
        box.dataset.id = loc.id;
        box.style.left = loc.x + 'px';
        box.style.top = loc.y + 'px';
        box.style.width = loc.w + 'px';
        box.style.height = loc.h + 'px';
        box.title = loc.desc || '';
        box.innerHTML = `<div class="map-box-label">${loc.code}</div><div class="map-box-resize"></div>`;
        return box;
    }

    if (canvas) {
        MAP_DATA.forEach(loc => canvas.appendChild(makeBox(loc)));

        canvas.addEventListener('mousedown', e => {
            const box = e.target.closest('.map-box');
            if (!box) return;
            const canvasRect = canvas.getBoundingClientRect();

            if (e.target.classList.contains('map-box-resize')) {
                resizing = {
                    box,
                    startX: e.clientX,
                    startY: e.clientY,
                    startW: box.offsetWidth,
                    startH: box.offsetHeight
                };
            } else {
                dragging = {
                    box,
                    offsetX: e.clientX - box.offsetLeft - canvasRect.left + canvas.scrollLeft,
                    offsetY: e.clientY - box.offsetTop - canvasRect.top + canvas.scrollTop,
                };
                box.classList.add('map-box-active');
            }
            e.preventDefault();
        });

        document.addEventListener('mousemove', e => {
            const canvasRect = canvas.getBoundingClientRect();
            if (dragging) {
                let x = e.clientX - canvasRect.left + canvas.scrollLeft - dragging.offsetX;
                let y = e.clientY - canvasRect.top + canvas.scrollTop - dragging.offsetY;
                dragging.box.style.left = Math.max(0, x) + 'px';
                dragging.box.style.top = Math.max(0, y) + 'px';
            } else if (resizing) {
                const dw = e.clientX - resizing.startX;
                const dh = e.clientY - resizing.startY;
                resizing.box.style.width = Math.max(60, resizing.startW + dw) + 'px';
                resizing.box.style.height = Math.max(40, resizing.startH + dh) + 'px';
            }
        });

        document.addEventListener('mouseup', () => {
            if (dragging) dragging.box.classList.remove('map-box-active');
            dragging = null;
            resizing = null;
        });
    }

    const btnSave = document.getElementById('btnSaveLayout');
    if (btnSave && canvas) {
        btnSave.addEventListener('click', () => {
            const payload = {
                csrf_token: <?= json_encode(csrfToken()) ?>,
                locations: []
            };
            canvas.querySelectorAll('.map-box').forEach(box => {
                payload.locations.push({
                    id: box.dataset.id,
                    map_x: parseInt(box.style.left, 10),
                    map_y: parseInt(box.style.top, 10),
                    map_w: parseInt(box.style.width, 10),
                    map_h: parseInt(box.style.height, 10),
                });
            });
            btnSave.disabled = true;
            fetch('<?= BASE_URL ?>/inventory/location_map_editor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload),
                })
                .then(r => r.json())
                .then(data => {
                    btnSave.disabled = false;
                    alert(data.success ? 'Tata letak berhasil disimpan.' : ('Gagal menyimpan: ' + (data.message || 'unknown error')));
                })
                .catch(err => {
                    btnSave.disabled = false;
                    alert('Gagal menyimpan: ' + err.message);
                });
        });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>