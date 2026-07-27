<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::requireRole(['head_it','it_admin','md','bdm','head_accounts']);
$db   = getDB();

$subId = (int)($_GET['id'] ?? 0);
if (!$subId) { echo json_encode(['ok'=>false,'error'=>'Invalid subscription.']); exit; }

try {
    $stmt = $db->prepare("SELECT r.*, u.name user_name
        FROM subscription_renewals r LEFT JOIN users u ON u.id=r.user_id
        WHERE r.subscription_id=? ORDER BY r.renewed_at DESC");
    $stmt->execute([$subId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'history'=>$history]);
} catch(Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Failed to load history.']);
}
