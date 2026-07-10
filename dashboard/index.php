<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$user       = currentUser();
$activePage = 'dashboard';
$pageTitle  = 'Dashboard';

$pdo = getPDO();

// ── Stat: total material aktif
$totalMaterial = $pdo->query("SELECT COUNT(*) FROM materials WHERE is_active = 1")->fetchColumn();

// ── Stat: stok kritis (current <= minimum, > 0)
$kritis = $pdo->query("SELECT COUNT(*) FROM materials WHERE is_active = 1 AND stock_current > 0 AND stock_current <= stock_minimum")->fetchColumn();

// ── Stat: stok habis
$habis = $pdo->query("SELECT COUNT(*) FROM materials WHERE is_active = 1 AND stock_current <= 0")->fetchColumn();

// ── Stat: schedule hari ini
$scheduleHariIni = $pdo->query("SELECT COUNT(*) FROM supplier_schedules WHERE schedule_date = CURDATE()")->fetchColumn();

// ── Stat: transaksi Stock In hari ini (manual + timbangan)
$trxHariIni = $pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE type = 'IN' AND DATE(transaction_date) = CURDATE()")->fetchColumn();

// ── Stat: supplier aktif
$supplierAktif = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE is_active = 1")->fetchColumn();

// ── Daftar stok material (raw) — urut paling kritis, sekarang termasuk lokasi
// Catatan bug lama: "ORDER BY (stock_current/NULLIF(stock_minimum,0)) ASC" membuat
// material dengan stock_minimum = 0 menghasilkan NULL, dan di MariaDB/MySQL nilai
// NULL selalu diurutkan PALING ATAS pada ASC — jadi material yang sebenarnya tidak
// kritis (karena belum diatur minimum stoknya) malah nongol duluan di daftar
// "paling kritis". Perbaikan: taruh baris ber-NULL rasio di paling akhir via
// kolom bantu (rasio IS NULL) sebelum ikut diurutkan oleh rasionya.
$stocks = $pdo->query("
    SELECT m.code, m.name, m.unit, m.location, m.stock_current, m.stock_minimum,
           (m.stock_current / NULLIF(m.stock_minimum,0)) AS stock_ratio
    FROM materials m
    WHERE m.is_active = 1
    ORDER BY (stock_ratio IS NULL) ASC, stock_ratio ASC
    LIMIT 10
")->fetchAll();

// ── Schedule supplier hari ini
$schedules = $pdo->query("
    SELECT ss.*, s.name AS supplier_name, m.name AS material_name, m.unit,
           u.full_name AS created_by_name
    FROM supplier_schedules ss
    JOIN suppliers s  ON ss.supplier_id = s.id
    JOIN materials m  ON ss.material_id = m.id
    JOIN users u      ON ss.created_by  = u.id
    WHERE ss.schedule_date = CURDATE()
    ORDER BY ss.status ASC, ss.created_at ASC
")->fetchAll();

// ── Aktivitas terbaru: gabungan Stock In manual & Timbangan, transaksi terakhir
$recentActivity = $pdo->query("
    SELECT st.transaction_no, st.type, st.source, st.qty, st.location, st.po_number,
           m.name AS material_name, m.unit,
           s.name AS supplier_name, u.full_name AS operator, st.transaction_date
    FROM stock_transactions st
    JOIN materials m ON st.material_id = m.id
    LEFT JOIN suppliers s ON st.supplier_id = s.id
    JOIN users u ON st.created_by = u.id
    ORDER BY st.transaction_date DESC
    LIMIT 8
")->fetchAll();

$hari  = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
$bulan = ['January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'];

// Judul & tanggal dashboard sekarang ditampilkan di navbar atas (sejajar posisi
// user & tombol Keluar), bukan lagi di body halaman — lihat includes/header.php.
$navbarTitle    = 'Dashboard';
$navbarSubtitle = $hari[date('l')] . ', ' . date('d') . ' ' . $bulan[date('F')] . ' ' . date('Y');

include __DIR__ . '/../includes/header.php';
?>

<!-- ── Stat Cards ─────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="stat-card">
            <div class="stat-icon" style="background:#ede9fe"><i class="bi bi-box-seam" style="color:#7c3aed"></i></div>
            <div>
                <div class="stat-value"><?= $totalMaterial ?></div>
                <div class="stat-label">Total Material</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-exclamation-triangle" style="color:#d97706"></i></div>
            <div>
                <div class="stat-value"><?= $kritis ?></div>
                <div class="stat-label">Stok Kritis</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-slash-circle" style="color:#dc2626"></i></div>
            <div>
                <div class="stat-value"><?= $habis ?></div>
                <div class="stat-label">Stok Habis</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card">
            <div class="stat-icon" style="background:#d1fae5"><i class="bi bi-calendar-check" style="color:#059669"></i></div>
            <div>
                <div class="stat-value"><?= $scheduleHariIni ?></div>
                <div class="stat-label">Schedule Hari Ini</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dbeafe"><i class="bi bi-box-arrow-in-down" style="color:#2563eb"></i></div>
            <div>
                <div class="stat-value"><?= $trxHariIni ?></div>
                <div class="stat-label">Transaksi Masuk Hari Ini</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fce7f3"><i class="bi bi-truck" style="color:#db2777"></i></div>
            <div>
                <div class="stat-value"><?= $supplierAktif ?></div>
                <div class="stat-label">Supplier Aktif</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">

    <!-- ── Stok Material ──────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clipboard-data me-2 text-danger"></i>Stok Material (Raw)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Material</th>
                                <th>Lokasi</th>
                                <th class="text-end">Stok</th>
                                <th class="text-end">Min.</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stocks)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Belum ada data material.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($stocks as $s): ?>
                                    <tr>
                                        <td><code><?= e($s['code']) ?></code></td>
                                        <td><?= e($s['name']) ?></td>
                                        <td><small><?= $s['location'] ? '<span class="badge bg-light text-dark border">' . e($s['location']) . '</span>' : '<span class="text-muted">—</span>' ?></small></td>
                                        <td class="text-end"><?= number_format($s['stock_current'], 2) ?> <small class="text-muted"><?= e($s['unit']) ?></small></td>
                                        <td class="text-end"><?= number_format($s['stock_minimum'], 2) ?></td>
                                        <td><?= stockStatus((float)$s['stock_current'], (float)$s['stock_minimum']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Schedule Supplier Hari Ini ────────────────── -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-week me-2 text-primary"></i>Schedule Supplier Hari Ini</span>
                <a href="<?= BASE_URL ?>/schedule/index.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Material</th>
                                <th class="text-end">Qty Expected</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($schedules)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Tidak ada schedule hari ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($schedules as $sc): ?>
                                    <tr>
                                        <td><?= e($sc['supplier_name']) ?></td>
                                        <td><?= e($sc['material_name']) ?></td>
                                        <td class="text-end"><?= number_format($sc['qty_expected'], 2) ?> <small class="text-muted"><?= e($sc['unit']) ?></small></td>
                                        <td><?= scheduleStatusBadge($sc['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>


    <!-- ── Aktivitas Terbaru ──────────────────────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2 text-secondary"></i>Aktivitas Terbaru</span>
                <a href="<?= BASE_URL ?>/transactions/history.php" class="btn btn-sm btn-outline-secondary">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>No. Trx</th>
                                <th>Material</th>
                                <th>Supplier</th>
                                <th class="text-end">Qty</th>
                                <th>Lokasi</th>
                                <th>Sumber</th>
                                <th>Operator</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentActivity)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">Belum ada aktivitas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentActivity as $a): ?>
                                    <tr>
                                        <td><small><code><?= e($a['transaction_no']) ?></code></small></td>
                                        <td><small><?= e($a['material_name']) ?></small></td>
                                        <td><small><?= e($a['supplier_name'] ?? '-') ?></small></td>
                                        <td class="text-end"><small><?= number_format($a['qty'], 2) ?> <?= e($a['unit']) ?></small></td>
                                        <td><small><?= $a['location'] ? '<span class="badge bg-light text-dark border">' . e($a['location']) . '</span>' : '—' ?></small></td>
                                        <td><small><?= $a['source'] === 'weighing' ? '<span class="badge bg-info text-dark">Timbangan</span>' : '<span class="badge bg-secondary">Manual</span>' ?></small></td>
                                        <td><small><?= e($a['operator']) ?></small></td>
                                        <td><small><?= date('d/m H:i', strtotime($a['transaction_date'])) ?></small></td>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>