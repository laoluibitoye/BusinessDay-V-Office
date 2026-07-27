<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();
$role = $user['role'];
$uid  = $user['id'];

$kpis   = [];
$badges = [];

try {
    // ── Common: pending leave count ──────────────────────────────────
    $leavePending = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage IN ('submitted','lm_review','hr_review','md_review')")->fetchColumn();

    // ── Common: IRS queue for approvers ─────────────────────────────
    $irsCfg = function_exists('getIrsConfig') ? getIrsConfig() : ['accounts_roles'=>defined('IRS_ACCOUNTS_ROLES')?IRS_ACCOUNTS_ROLES:['head_accounts','accountant'],'manager_roles'=>defined('IRS_MANAGER_ROLES')?IRS_MANAGER_ROLES:['md','bdm','head_outsourcing','hr'],'petty_cash_limit'=>defined('IRS_PETTY_CASH_LIMIT')?IRS_PETTY_CASH_LIMIT:50000,'notify_enabled'=>true];
    $isAccts = in_array($role, $irsCfg['accounts_roles']);
    $isMgr   = in_array($role, $irsCfg['manager_roles']);
    $isAdmin = Auth::isAdmin($user);
    $irsQueue = 0;
    if ($isAccts || $isMgr || $isAdmin) {
        $sts = [];
        if ($isAccts || $isAdmin) $sts = array_merge($sts, ['pending_accounts','pending_eligibility','pending_custodian','paid','disbursed','refund_required','additional_payment']);
        if ($isMgr   || $isAdmin) $sts[] = 'accounts_approved';
        $sts = array_unique($sts);
        if ($sts) {
            $ph = implode(',', array_fill(0, count($sts), '?'));
            $cq = $db->prepare("SELECT COUNT(*) FROM irs_requests WHERE status IN ($ph)");
            $cq->execute($sts);
            $irsQueue = (int)$cq->fetchColumn();
        }
    }

    // ── Role-specific ────────────────────────────────────────────────
    if (in_array($role, ['head_it','it_admin'])) {
        $openIT       = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE status IN ('open','in_progress')")->fetchColumn();
        $onlineStaff  = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE expires_at>NOW() AND COALESCE(last_active,created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE)")->fetchColumn();
        $loginFails   = (int)$db->query("SELECT COUNT(*) FROM login_history WHERE status='failed' AND DATE(created_at)=CURDATE()")->fetchColumn();
        $breachOpen   = 0; try{$breachOpen=(int)$db->query("SELECT COUNT(*) FROM breach_log WHERE status='open'")->fetchColumn();}catch(Exception $e){}
        $kpis = ['leave_pending'=>$leavePending,'it_open'=>$openIT,'staff_online'=>$onlineStaff,'login_fails'=>$loginFails,'breach_open'=>$breachOpen,'irs_queue'=>$irsQueue];

    } elseif (in_array($role, ['md','bdm'])) {
        $onlineStaff  = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE expires_at>NOW() AND COALESCE(last_active,created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE)")->fetchColumn();
        $onLeaveToday = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage='approved' AND CURDATE() BETWEEN start_date AND end_date")->fetchColumn();
        $taskOverdue  = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status!='done' AND due_date IS NOT NULL AND due_date<CURDATE()")->fetchColumn();
        $kpis = ['leave_pending'=>$leavePending,'staff_online'=>$onlineStaff,'on_leave_today'=>$onLeaveToday,'task_overdue'=>$taskOverdue,'irs_queue'=>$irsQueue];

    } elseif ($role === 'hr') {
        $totalStaff   = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
        $atHrStage    = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage='hr_review'")->fetchColumn();
        $onLeaveToday = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage='approved' AND CURDATE() BETWEEN start_date AND end_date")->fetchColumn();
        $kpis = ['leave_pending'=>$leavePending,'at_hr_stage'=>$atHrStage,'total_staff'=>$totalStaff,'on_leave_today'=>$onLeaveToday,'irs_queue'=>$irsQueue];

    } elseif (in_array($role, ['head_outsourcing','head_compliance','head_accounts','head_training','head_cso'])) {
        $drQ = $db->prepare("SELECT id FROM users WHERE line_manager_id=? AND is_active=1");
        $drQ->execute([$uid]); $drIds = array_column($drQ->fetchAll(), 'id');
        $drLeavePending = 0;
        if ($drIds) {
            $ph2 = implode(',', array_fill(0, count($drIds), '?'));
            $dlq = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id IN ($ph2) AND current_stage IN ('submitted','lm_review')");
            $dlq->execute($drIds); $drLeavePending = (int)$dlq->fetchColumn();
        }
        $sigPending = 0;
        try { $sg=$db->prepare("SELECT COUNT(*) FROM sign_signatories ss JOIN sign_requests sr ON sr.id=ss.request_id WHERE ss.user_id=? AND ss.status='pending' AND sr.expires_at>NOW()"); $sg->execute([$uid]); $sigPending=(int)$sg->fetchColumn(); } catch(Exception $e){}
        $kpis = ['dr_leave_pending'=>$drLeavePending,'dr_count'=>count($drIds),'sig_pending'=>$sigPending,'irs_queue'=>$irsQueue];

    } elseif (in_array($role, ['cs_manager','training_manager'])) {
        $drQ = $db->prepare("SELECT id FROM users WHERE line_manager_id=? AND is_active=1");
        $drQ->execute([$uid]); $drIds = array_column($drQ->fetchAll(), 'id');
        $drLeavePending = 0;
        if ($drIds) {
            $ph2 = implode(',', array_fill(0, count($drIds), '?'));
            $dlq = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id IN ($ph2) AND current_stage IN ('submitted','lm_review')");
            $dlq->execute($drIds); $drLeavePending = (int)$dlq->fetchColumn();
        }
        $kpis = ['dr_leave_pending'=>$drLeavePending,'dr_count'=>count($drIds),'irs_queue'=>$irsQueue];

    } else {
        // Staff
        $tq = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND status!='done'");
        $tq->execute([$uid]); $myTasks = (int)$tq->fetchColumn();
        $sigPending = 0;
        try { $sg=$db->prepare("SELECT COUNT(*) FROM sign_signatories ss JOIN sign_requests sr ON sr.id=ss.request_id WHERE ss.user_id=? AND ss.status='pending' AND sr.expires_at>NOW()"); $sg->execute([$uid]); $sigPending=(int)$sg->fetchColumn(); } catch(Exception $e){}
        $kpis = ['my_tasks'=>$myTasks,'sig_pending'=>$sigPending];
    }

    // ── My leave balance (universal) ─────────────────────────────────
    $entitled=15; $usedDays=0; $pendDays=0;
    try { $b=$db->prepare("SELECT entitled_days FROM leave_balance WHERE user_id=? AND year=YEAR(CURDATE())"); $b->execute([$uid]); $br=$b->fetch(); if($br) $entitled=(int)$br['entitled_days']; } catch(Exception $e){}
    try { $lU=$db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND (current_stage='approved' OR md_status='approved') AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW())"); $lU->execute([$uid]); $usedDays=(int)$lU->fetchColumn(); } catch(Exception $e){}
    try { $lP=$db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW()) AND current_stage NOT IN ('approved','rejected')"); $lP->execute([$uid]); $pendDays=(int)$lP->fetchColumn(); } catch(Exception $e){}
    $kpis['remain_leave'] = max(0, $entitled - $usedDays - $pendDays);

    $badges = [
        'leave_pending' => $leavePending,
        'irs_queue'     => $irsQueue,
    ];

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'KPI fetch error']);
    exit;
}

echo json_encode(['ok'=>true,'data'=>['kpis'=>$kpis,'badges'=>$badges]]);
