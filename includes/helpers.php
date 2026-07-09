<?php
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function flash(string $key, string $msg, string $type = 'success'): void
{
    startSession();
    $_SESSION['flash'][$key] = ['msg' => $msg, 'type' => $type];
}

function getFlash(string $key): ?array
{
    startSession();
    if (isset($_SESSION['flash'][$key])) {
        $f = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $f;
    }
    return null;
}

function generateTrxNo(string $prefix = 'TRX'): string
{
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

// ── CSRF Protection ───────────────────────────────────────────────

// Ambil token CSRF untuk session saat ini, generate kalau belum ada.
function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Hidden input siap pakai — taruh di dalam <form method="post">...</form>.
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

// Validasi token dari $_POST. Kalau tidak ada/tidak cocok, hentikan request dengan 403.
function csrfCheck(): void
{
    startSession();
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Sesi form sudah tidak valid (CSRF token salah/kedaluwarsa). Silakan refresh halaman dan coba lagi.');
    }
}

function stockStatus(float $current, float $minimum): string
{
    if ($current <= 0)              return '<span class="badge bg-danger">Habis</span>';
    if ($current <= $minimum)       return '<span class="badge bg-warning text-dark">Kritis</span>';
    if ($current <= $minimum * 1.2) return '<span class="badge bg-info text-dark">Rendah</span>';
    return '<span class="badge bg-success">Aman</span>';
}

// ── Kebijakan Password ────────────────────────────────────────────
// Minimal PASSWORD_MIN_LENGTH karakter, harus ada huruf DAN angka.
// Return array kosong kalau valid, atau daftar pesan error kalau tidak.
function passwordPolicyErrors(string $password): array
{
    $errors = [];
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password minimal ' . PASSWORD_MIN_LENGTH . ' karakter.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        $errors[] = 'Password harus mengandung minimal 1 huruf.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password harus mengandung minimal 1 angka.';
    }
    return $errors;
}

function scheduleStatusBadge(string $status): string
{
    return match ($status) {
        'planned'   => '<span class="badge bg-primary">Planned</span>',
        'confirmed' => '<span class="badge bg-success">Confirmed</span>',
        'cancelled' => '<span class="badge bg-secondary">Cancelled</span>',
        default     => '<span class="badge bg-light text-dark">' . e($status) . '</span>',
    };
}

function acStyles(): string
{
    return '
<style>
.ac-wrap { position: relative; }
.ac-list {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 1050;
    background: #fff; border: 1px solid #ced4da; border-top: none;
    border-radius: 0 0 6px 6px; max-height: 240px; overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
}
.ac-item {
    padding: 8px 12px; cursor: pointer; font-size: 13px;
    border-bottom: 1px solid #f0f0f0;
    display: flex; flex-direction: column; gap: 1px;
}
.ac-item:last-child { border-bottom: none; }
.ac-item:hover, .ac-item.active { background: #f0f4ff; }
.ac-code { font-weight: 600; color: #1a1a2e; font-size: 12px; }
.ac-name { color: #555; font-size: 13px; }
.ac-stock { font-size: 11px; color: #888; }
.ac-empty { padding: 10px 12px; color: #999; font-size: 13px; }
.ac-confirmed { border-color: #198754 !important; background: #f0fff4 !important; }
.loc-chip { transition: background .15s; }
.loc-chip:hover { background: #e7f1ff !important; border-color: #86b7fe !important; }
.loc-chip.loc-chip-active { background: #cfe2ff !important; border-color: #0d6efd !important; }
</style>';
}

// ── Mapping Lokasi (Master Lokasi terdaftar) ─────────────────────

// Ambil full_code dari lokasi terdaftar (hanya jika aktif). Return null jika tidak valid.
function resolveLocation(PDO $pdo, int $locationId): ?string
{
    if (!$locationId) return null;
    $stmt = $pdo->prepare("SELECT full_code FROM locations WHERE id = ? AND is_active = 1");
    $stmt->execute([$locationId]);
    $code = $stmt->fetchColumn();
    return $code !== false ? $code : null;
}

// Update lokasi default material + tambah/upsert qty di material_locations (dipakai saat Stock In / Timbangan)
function applyMaterialLocation(PDO $pdo, int $materialId, int $locationId, string $locCode, float $qty): void
{
    $pdo->prepare("UPDATE materials SET location = ?, default_location_id = ? WHERE id = ?")
        ->execute([$locCode, $locationId, $materialId]);

    $pdo->prepare("
        INSERT INTO material_locations (material_id, location_id, qty)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = CURRENT_TIMESTAMP
    ")->execute([$materialId, $locationId, $qty]);
}

// Kurangi qty di material_locations (dipakai saat Stock Out).
// Return false kalau baris lokasi tidak ada atau stok di lokasi itu tidak cukup — caller harus rollback kalau false.
function decrementMaterialLocation(PDO $pdo, int $materialId, int $locationId, float $qty): bool
{
    $stmt = $pdo->prepare("
        SELECT qty FROM material_locations
        WHERE material_id = ? AND location_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$materialId, $locationId]);
    $current = $stmt->fetchColumn();

    if ($current === false || (float)$current < $qty) {
        return false;
    }

    $pdo->prepare("
        UPDATE material_locations
        SET qty = qty - ?, updated_at = CURRENT_TIMESTAMP
        WHERE material_id = ? AND location_id = ?
    ")->execute([$qty, $materialId, $locationId]);

    return true;
}
