<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::requireRole(['md','bdm','head_it','head_outsourcing','head_compliance','head_accounts','hr','head_training','head_cso','training_manager','cs_manager']);
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST required.']); exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) { echo json_encode(['ok'=>false,'error'=>'id required.']); exit; }

try {
    $stmt = $db->prepare("SELECT id, batch_ref, status FROM payslip_batches WHERE id=?");
    $stmt->execute([$id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) { echo json_encode(['ok'=>false,'error'=>'Batch not found.']); exit; }
    if (!in_array($batch['status'], ['queued','sending'])) {
        echo json_encode(['ok'=>false,'error'=>'Only queued or sending batches can be cancelled.']); exit;
    }

    $db->prepare("UPDATE payslip_batches SET status='cancelled' WHERE id=?")->execute([$id]);
    // Keep queue rows — they won't be picked up because batch status is cancelled

    Auth::auditLog($user['id'], 'payslip_batch_cancel',
        'Cancelled payslip batch ' . $batch['batch_ref']);

    echo json_encode(['ok'=>true,'message'=>'Batch ' . $batch['batch_ref'] . ' cancelled.']);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Database error.']);
}
