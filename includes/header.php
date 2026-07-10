<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();
$user       = currentUser();
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Receiving Warehouse') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .navbar-title-block {
            margin-left: .75rem;
            line-height: 1.2;
        }

        .navbar-page-title {
            font-weight: 600;
            font-size: 1rem;
        }

        .navbar-page-subtitle {
            font-size: .78rem;
            color: #6c757d;
        }
    </style>
</head>

<body>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <i class="bi bi-building-fill-check"></i>
            <span><?= APP_NAME ?></span>
        </div>

        <nav class="sidebar-nav">

            <!-- Dashboard -->
            <a href="<?= BASE_URL ?>/dashboard/index.php"
                class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>

            <!-- ── 1. MATERIAL ─────────────────────────────── -->
            <div class="nav-section">Material</div>

            <?php if (hasRole(['admin', 'receiving'])): ?>
                <a href="<?= BASE_URL ?>/transactions/stock_in.php"
                    class="nav-item <?= $activePage === 'stock_in' ? 'active' : '' ?>">
                    <i class="bi bi-box-arrow-in-down"></i> Stock In
                </a>
                <a href="<?= BASE_URL ?>/transactions/stock_out.php"
                    class="nav-item <?= $activePage === 'stock_out' ? 'active' : '' ?>">
                    <i class="bi bi-box-arrow-up"></i> Stock Out
                </a>
            <?php endif; ?>

            <?php if (hasRole(['admin'])): ?>
                <a href="<?= BASE_URL ?>/transactions/stock_adjust.php"
                    class="nav-item <?= $activePage === 'stock_adjust' ? 'active' : '' ?>">
                    <i class="bi bi-arrow-repeat"></i> Stock Adjustment
                </a>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>/transactions/history.php"
                class="nav-item <?= $activePage === 'history' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i> History
            </a>

            <?php if (hasRole(['admin', 'receiving', 'manager'])): ?>
                <a href="<?= BASE_URL ?>/inventory/location_map.php"
                    class="nav-item <?= $activePage === 'location_map' ? 'active' : '' ?>">
                    <i class="bi bi-map"></i> Peta Lokasi
                </a>
            <?php endif; ?>

            <!-- ── 2. SCHEDULE SUPPLIER ────────────────────── -->
            <?php if (hasRole(['admin', 'ppic', 'receiving'])): ?>
                <div class="nav-section">Schedule Supplier</div>
                <a href="<?= BASE_URL ?>/schedule/index.php"
                    class="nav-item <?= $activePage === 'schedule' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-week"></i> Jadwal Kedatangan
                </a>
            <?php endif; ?>

            <!-- ── 3. WEIGHING SYSTEM ──────────────────────── -->
            <?php if (hasRole(['admin', 'receiving'])): ?>
                <div class="nav-section">Weighing System</div>
                <a href="<?= BASE_URL ?>/weighing/index.php"
                    class="nav-item <?= $activePage === 'weighing' ? 'active' : '' ?>">
                    <i class="bi bi-calculator"></i> Input Timbangan
                </a>
            <?php endif; ?>

            <!-- ── ADMIN ───────────────────────────────────── -->
            <?php if (hasRole(['admin'])): ?>
                <div class="nav-section">Master Data</div>
                <a href="<?= BASE_URL ?>/suppliers/index.php"
                    class="nav-item <?= $activePage === 'suppliers' ? 'active' : '' ?>">
                    <i class="bi bi-truck"></i> Supplier
                </a>
                <a href="<?= BASE_URL ?>/inventory/materials.php"
                    class="nav-item <?= $activePage === 'materials' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i> Master Material
                </a>
                <a href="<?= BASE_URL ?>/inventory/warehouses.php"
                    class="nav-item <?= $activePage === 'warehouses' ? 'active' : '' ?>">
                    <i class="bi bi-building"></i> Master Gudang
                </a>
                <a href="<?= BASE_URL ?>/inventory/locations.php"
                    class="nav-item <?= $activePage === 'locations' ? 'active' : '' ?>">
                    <i class="bi bi-geo-alt"></i> Master Lokasi
                </a>
                <a href="<?= BASE_URL ?>/inventory/location_map_editor.php"
                    class="nav-item <?= $activePage === 'location_map_editor' ? 'active' : '' ?>">
                    <i class="bi bi-pencil-square"></i> Atur Peta Lokasi
                </a>

                <div class="nav-section">Admin</div>
                <a href="<?= BASE_URL ?>/auth/users.php"
                    class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
                    <i class="bi bi-people"></i> Kelola User
                </a>
            <?php endif; ?>

        </nav>
    </div>

    <div class="main-wrapper" id="mainWrapper">
        <div class="top-navbar">
            <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <?php if (!empty($navbarTitle)): ?>
                <div class="navbar-title-block">
                    <div class="navbar-page-title"><?= e($navbarTitle) ?></div>
                    <?php if (!empty($navbarSubtitle)): ?>
                        <div class="navbar-page-subtitle"><?= e($navbarSubtitle) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="badge role-badge"><?= e($user['role']) ?></span>
                <span class="user-name"><?= e($user['name']) ?></span>
                <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i> Keluar
                </a>
            </div>
        </div>
        <div class="page-content">