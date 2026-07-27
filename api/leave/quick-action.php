<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$id       = (int)($_POST['id']       ?? 0);
$decision = trim($_POST['decision']  ?? '');  // 'approve' or 'return'
$comment  = trim($_POST['comment']   ?? '');

if (!$id || !in_array($decision, ['approve','return'])) {
    echo json_encode(['ok'=>false,'error'=>'Invalid request.']);
    exit;
}

$isSuperAdmin = Auth::isAdmin($user);

$stmt = $db->prepare("
    SELECT lr.*, u.name as staff_name, u.email as staff_email, u.role as staff_role,
           lfr.stage1_approver, lfr.stage2_approver, lfr.stage3_approver
    FROM leave_requests lr
    JOIN users u ON u.id = lr.user_id
    LEFT JOIN leave_flow_rules lfr ON lfr.requester_role = u.role
    WHERE lr.id = ?
");
$stmt->execute([$id]);
$leave = $stmt->fetch();

if (!$leave) {
    echo json_encode(['ok'=>false,'error'=>'Leave request not found.']);
    exit;
}

$stage = $leave['current_stage'];
if (!in_array($stage, ['lm_review','hr_review','md_review'])) {
    echo json_encode(['ok'=>false,'error'=>'This request is not pending action.']);
    exit;
}

// Authorization check
$authorized = $isSuperAdmin;
if (!$authorized) {
    if ($stage === 'lm_review' && ($leave['stage1_approver'] ?? '') === $user['role']) $authorized = true;
    if ($stage === 'hr_review'  && ($leave['stage2_approver'] ?? '') === $user['role']) $authorized = true;
    if ($stage === 'md_review'  && ($leave['stage3_approver'] ?? '') === $user['role']) $authorized = true;
}

if (!$authorized) {
    echo json_encode(['ok'=>false,'error'=>'You are not authorized to action this request.']);
    exit;
}

// Apply decision
if ($decision === 'approve') {
    if ($stage === 'lm_review') {
        $next = $leave['stage2_approver'] ? 'hr_review'
              : ($leave['stage3_approver'] ? 'md_review' : 'approved');
        $db->prepare("UPDATE leave_requests SET current_stage=?,lm_status='approved',lm_actioned_by=?,lm_actioned_at=NOW(),lm_comment=? WHERE id=?")
           ->execute([$next, $user['id'], $comment, $id]);
    } elseif ($stage === 'hr_review') {
        $next = $leave['stage3_approver'] ? 'md_review' : 'approved';
        $db->prepare("UPDATE leave_requests SET current_stage=?,hr_status='approved',hr_actioned_by=?,hr_actioned_at=NOW(),hr_comment=? WHERE id=?")
           ->execute([$next, $user['id'], $comment, $id]);
    } elseif ($stage === 'md_review') {
        $db->prepare("UPDATE leave_requests SET current_stage='approved',md_status='approved',md_actioned_by=?,md_actioned_at=NOW(),md_comment=? WHERE id=?")
           ->execute([$user['id'], $comment, $id]);
    }
    $actionLabel = 'Approved';
    Auth::auditLog($user['id'], 'leave_approved', "Quick-action: Leave #$id approved at $stage");

} else {
    // return (soft reject)
    $cols = ['lm_review'=>'lm','hr_review'=>'hr','md_review'=>'md'];
    $col  = $cols[$stage] ?? 'hr';
    $db->prepare("UPDATE leave_requests SET current_stage='rejected',{$col}_status='rejected',{$col}_actioned_by=?,{$col}_actioned_at=NOW(),{$col}_comment=? WHERE id=?")
       ->execute([$user['id'], $comment, $id]);
    $actionLabel = 'Returned';
    Auth::auditLog($user['id'], 'leave_returned', "Quick-action: Leave #$id returned at $stage");
}

// Notify requester
try {
    $leaveTypeFmt = ucwords(str_replace('_', ' ', $leave['leave_type']));
    $stageLabel   = ['lm_review'=>'Line Manager','hr_review'=>'Human Resources','md_review'=>'Management'][$stage] ?? $stage;
    $emailBody    = '<p>Dear ' . htmlspecialchars($leave['staff_name']) . ',</p>'
                  . '<p>Your <strong>' . $leaveTypeFmt . '</strong> leave request ('
                  . date('d M Y', strtotime($leave['start_date'])) . ' – '
                  . date('d M Y', strtotime($leave['end_date'])) . ', ' . $leave['days'] . ' day(s))'
                  . ' has been <strong>' . strtolower($actionLabel) . '</strong> at the <strong>' . $stageLabel . '</strong> stage.</p>';
    if ($comment) {
        $emailBody .= '<p><strong>Comment:</strong> ' . htmlspecialchars($comment) . '</p>';
    }
    $emailBody .= '<p><a href="' . APP_URL . '/leave.php" style="background:#002850;color:#fff;padding:8px 18px;border-radius:5px;text-decoration:none;display:inline-block;">View Leave Status</a></p>';
    $emailBody .= '<p style="color:#94a3b8;font-size:12px;">HR Indexx Limited · HRI Mail</p>';

    sendSystemMail($leave['staff_email'], $leave['staff_name'],
        "Leave Request {$actionLabel} — {$leaveTypeFmt}", $emailBody);
} catch (Exception $e) {} // non-fatal — action already saved

echo json_encode(['ok'=>true,'decision'=>$decision,'stage'=>$stage]);
