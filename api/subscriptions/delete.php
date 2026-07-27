<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::requireRole(['head_it','it_admin','md','bdm']);
$db   = getDB();

$id     = (int)($_POST['id']     ?? 0);
$action = $_POST['action']       ?? 'cancel'; // 'cancel' or 'delete'

if (!$id) { echo json_encode(['ok'=>false,'error'=>'Invalid subscription.']); exit; }

$row = $db->prepare("SELECT name FROM subscriptions WHERE id=?");
$row->execute([$id]);
$sub = $row->fetch(PDO::FETCH_ASSOC);
if (!$sub) { echo json_encode(['ok'=>false,'error'=>'Subscription not found.']); exit; }

try {
    if ($action === 'delete') {
        // Hard delete (no history kept) — only md/bdm/head_it
        $db->prepare("DELETE FROM subscription_renewals WHERE subscription_id=?")->execute([$id]);
        $db->prepare("DELETE FROM subscriptions WHERE id=?")->execute([$id]);
        Auth::auditLog($user['id'],'subscription_deleted',"Deleted subscription: {$sub['name']}");
        echo json_encode(['ok'=>true,'message'=>"Subscription '{$sub['name']}' deleted."]);
    } else {
        // Soft cancel — keeps history
        $db->prepare("UPDATE subscriptions SET status='cancelled', updated_at=NOW() WHERE id=?")->execute([$id]);
        Auth::auditLog($user['id'],'subscription_cancelled',"Cancelled subscription: {$sub['name']}");
        echo json_encode(['ok'=>true,'message'=>"Subscription '{$sub['name']}' cancelled."]);
    }
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Operation failed. Please try again.']);
}
