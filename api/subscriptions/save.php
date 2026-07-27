<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::requireRole(['head_it','it_admin','md','bdm','head_accounts']);
$db   = getDB();

$id         = (int)($_POST['id'] ?? 0);
$name       = trim($_POST['name']       ?? '');
$category   = $_POST['category']        ?? '';
$vendor     = trim($_POST['vendor']     ?? '');
$accountRef = trim($_POST['account_ref']?? '');
$cycle      = $_POST['cycle']           ?? '';
$cost       = (float)($_POST['cost']    ?? 0);
$currency   = trim($_POST['currency']   ?? 'NGN') ?: 'NGN';
$startDate  = $_POST['start_date']      ?? '';
$renewalDate= $_POST['renewal_date']    ?? '';
$autoRenews = isset($_POST['auto_renews']) ? 1 : 0;
$notifyDays = max(1, (int)($_POST['notify_days'] ?? 30));
$notes      = trim($_POST['notes']      ?? '');
$ownerId    = (int)($_POST['owner_id']  ?? $user['id']);
$status     = $_POST['status']          ?? 'active';

$validCategories = ['internet','software','domain','hosting','other'];
$validCycles     = ['monthly','quarterly','biannual','annual'];
$validStatuses   = ['active','pending_renewal','expired','cancelled'];

if ($name === '')                              { echo json_encode(['ok'=>false,'error'=>'Name is required.']); exit; }
if (!in_array($category, $validCategories))   { echo json_encode(['ok'=>false,'error'=>'Invalid category.']); exit; }
if (!in_array($cycle, $validCycles))          { echo json_encode(['ok'=>false,'error'=>'Invalid billing cycle.']); exit; }
if (!$startDate || !strtotime($startDate))    { echo json_encode(['ok'=>false,'error'=>'Valid start date required.']); exit; }
if (!in_array($status, $validStatuses))       $status = 'active';

// If no renewal_date given, calculate from start_date + cycle
if (!$renewalDate || !strtotime($renewalDate)) {
    $d = new DateTime($startDate);
    switch ($cycle) {
        case 'monthly':   $d->modify('+1 month');  break;
        case 'quarterly': $d->modify('+3 months'); break;
        case 'biannual':  $d->modify('+6 months'); break;
        case 'annual':    $d->modify('+1 year');   break;
    }
    $renewalDate = $d->format('Y-m-d');
}

try {
    if ($id > 0) {
        // Edit existing
        $existing = $db->prepare("SELECT id FROM subscriptions WHERE id=?")->execute([$id]);
        $db->prepare("UPDATE subscriptions SET
            name=?,category=?,vendor=?,account_ref=?,cycle=?,cost=?,currency=?,
            start_date=?,renewal_date=?,auto_renews=?,notify_days=?,notes=?,
            owner_id=?,status=?,updated_at=NOW()
            WHERE id=?")->execute([
            $name,$category,$vendor ?: null,$accountRef ?: null,$cycle,$cost ?: null,$currency,
            $startDate,$renewalDate,$autoRenews,$notifyDays,$notes ?: null,
            $ownerId ?: null,$status,$id
        ]);
        Auth::auditLog($user['id'],'subscription_updated',"Updated subscription: {$name}");
        echo json_encode(['ok'=>true,'id'=>$id,'message'=>'Subscription updated.']);
    } else {
        // New subscription
        $ins = $db->prepare("INSERT INTO subscriptions
            (name,category,vendor,account_ref,cycle,cost,currency,start_date,renewal_date,
             auto_renews,notify_days,notes,owner_id,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            $name,$category,$vendor ?: null,$accountRef ?: null,$cycle,$cost ?: null,$currency,
            $startDate,$renewalDate,$autoRenews,$notifyDays,$notes ?: null,
            $ownerId ?: null,'active',$user['id']
        ]);
        $newId = (int)$db->lastInsertId();
        Auth::auditLog($user['id'],'subscription_added',"Added subscription: {$name} (cycle: {$cycle}, renewal: {$renewalDate})");
        echo json_encode(['ok'=>true,'id'=>$newId,'renewal_date'=>$renewalDate,'message'=>'Subscription added.']);
    }
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Save failed. Please try again.']);
}
