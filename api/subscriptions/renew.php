<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::requireRole(['head_it','it_admin','md','bdm','head_accounts']);
$db   = getDB();

$subId       = (int)($_POST['id']              ?? 0);
$amountPaid  = (float)($_POST['amount_paid']    ?? 0);
$invoiceRef  = trim($_POST['invoice_ref']        ?? '');
$irsRef      = trim($_POST['irs_ref']            ?? '');
$notes       = trim($_POST['notes']              ?? '');
$manualDate  = trim($_POST['new_renewal_date']   ?? '');

if (!$subId) { echo json_encode(['ok'=>false,'error'=>'Invalid subscription.']); exit; }

$row = $db->prepare("SELECT * FROM subscriptions WHERE id=?");
$row->execute([$subId]);
$sub = $row->fetch(PDO::FETCH_ASSOC);
if (!$sub) { echo json_encode(['ok'=>false,'error'=>'Subscription not found.']); exit; }
if ($sub['status'] === 'cancelled') { echo json_encode(['ok'=>false,'error'=>'Cannot renew a cancelled subscription.']); exit; }

// Use manual date if provided and valid; otherwise auto-calculate from cycle
if ($manualDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $manualDate)) {
    $newRenewalDate = $manualDate;
} else {
    $baseDate = $sub['renewal_date'] < date('Y-m-d') ? date('Y-m-d') : $sub['renewal_date'];
    $d = new DateTime($baseDate);
    switch ($sub['cycle']) {
        case 'monthly':   $d->modify('+1 month');  break;
        case 'quarterly': $d->modify('+3 months'); break;
        case 'biannual':  $d->modify('+6 months'); break;
        case 'annual':    $d->modify('+1 year');   break;
    }
    $newRenewalDate = $d->format('Y-m-d');
}

try {
    // Record renewal in history
    $ins = $db->prepare("INSERT INTO subscription_renewals
        (subscription_id,user_id,renewed_at,amount_paid,irs_ref,invoice_ref,notes,next_renewal_date)
        VALUES (?,?,CURDATE(),?,?,?,?,?)");
    $ins->execute([
        $subId, $user['id'],
        $amountPaid > 0 ? $amountPaid : null,
        $irsRef ?: null, $invoiceRef ?: null, $notes ?: null,
        $newRenewalDate
    ]);

    // Update subscription: new renewal date + status (essential)
    $db->prepare("UPDATE subscriptions SET renewal_date=?, status='active' WHERE id=?")
       ->execute([$newRenewalDate, $subId]);

    // Reset alert flags if columns exist (ignore if missing — safe to skip)
    try {
        $db->prepare("UPDATE subscriptions SET alert_30_sent=0, alert_7_sent=0 WHERE id=?")
           ->execute([$subId]);
    } catch (Exception $e) {}

    $amtNote = $amountPaid > 0 ? ' — ₦'.number_format($amountPaid,2) : '';
    Auth::auditLog($user['id'], 'subscription_renewed',
        "Renewed: {$sub['name']}{$amtNote} → next renewal: {$newRenewalDate}");

    echo json_encode([
        'ok'              => true,
        'new_renewal_date'=> $newRenewalDate,
        'formatted'       => date('d M Y', strtotime($newRenewalDate)),
        'message'         => "Renewed successfully. Next renewal: " . date('d M Y', strtotime($newRenewalDate))
    ]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Renewal failed. Please try again.']);
}
