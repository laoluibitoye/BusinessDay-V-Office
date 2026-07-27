<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::requireRole(['md','bdm','head_it','head_outsourcing','head_compliance','head_accounts','hr','head_training','head_cso','training_manager','cs_manager']);
$db   = getDB();

$batchId = (int)($_GET['batch_id'] ?? 0);
if (!$batchId) { echo json_encode(['ok'=>false,'error'=>'batch_id required.']); exit; }

try {
    $batch = $db->prepare("SELECT b.*, u.name uploader FROM payslip_batches b JOIN users u ON u.id=b.uploaded_by WHERE b.id=?");
    $batch->execute([$batchId]);
    $b = $batch->fetch(PDO::FETCH_ASSOC);
    if (!$b) { echo json_encode(['ok'=>false,'error'=>'Batch not found.']); exit; }

    // Live counts from queue (more accurate than cached columns)
    $counts = $db->prepare("SELECT
        SUM(status='pending')  AS pending,
        SUM(status='sending')  AS sending,
        SUM(status='sent')     AS sent,
        SUM(status='failed')   AS failed,
        COUNT(*)               AS total
        FROM payslip_queue WHERE batch_id=?");
    $counts->execute([$batchId]);
    $c = $counts->fetch(PDO::FETCH_ASSOC);

    $items = $db->prepare("SELECT id,row_num,employee_name,employee_email,employee_id,
        designation,status,attempts,sent_at,error_msg
        FROM payslip_queue WHERE batch_id=? ORDER BY row_num ASC");
    $items->execute([$batchId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);

    // Estimate completion time based on rate limit
    $pending = (int)$c['pending'];
    $eta = null;
    if ($pending > 0) {
        $rateCheck = $db->query("SELECT COUNT(*) FROM payslip_queue WHERE status='sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
        $sentInHour = (int)$rateCheck->fetchColumn();
        $canSendNow = max(0, 15 - $sentInHour);
        if ($canSendNow > 0) {
            $minsRemaining = ceil($pending / 15 * 60);
            $eta = date('H:i', time() + $minsRemaining * 60);
        } else {
            $eta = 'Rate limit reached — resumes in ~1 hour';
        }
    }

    echo json_encode([
        'ok'    => true,
        'batch' => array_merge($b, [
            'live_total'   => (int)$c['total'],
            'live_sent'    => (int)$c['sent'],
            'live_failed'  => (int)$c['failed'],
            'live_pending' => (int)$c['pending'],
            'live_sending' => (int)$c['sending'],
            'eta'          => $eta,
        ]),
        'rows'  => $rows,
    ]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Query error — ensure payslip_migration.sql has been run.']);
}
