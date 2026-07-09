<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');
$matId = (int)($_GET['material_id'] ?? 0);
if (!$matId) { echo '[]'; exit; }

$pdo  = getPDO();
$stmt = $pdo->prepare("
    SELECT id, batch_number, remaining_qty, received_date, expiry_date
    FROM batches
    WHERE material_id = ? AND remaining_qty > 0
    ORDER BY received_date ASC, id ASC
");
$stmt->execute([$matId]);
echo json_encode($stmt->fetchAll());
