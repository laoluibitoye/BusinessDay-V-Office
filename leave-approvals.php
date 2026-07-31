<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$isSuperAdmin = Auth::isAdmin($user);

// ── APPROVE / REJECT ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);

    if (($act === 'approve' || $act === 'reject') && $id) {
        $stmt = $db->prepare("
            SELECT lr.*, u.role as staff_role,
                   lfr.stage1_approver, lfr.stage2_approver, lfr.stage3_approver
            FROM leave_requests lr
            JOIN users u ON u.id = lr.user_id
            LEFT JOIN leave_flow_rules lfr ON lfr.requester_role = u.role
            WHERE lr.id = ?
        ");
        $stmt->execute([$id]);
        $leave = $stmt->fetch();

        if ($leave) {
            $stage = $leave['current_stage'];
            $authorized = $isSuperAdmin;
            if (!$authorized) {
                if ($stage === 'lm_review' && ($leave['stage1_approver'] ?? '') === $user['role']) $authorized = true;
                if ($stage === 'hr_review'  && ($leave['stage2_approver'] ?? '') === $user['role']) $authorized = true;
                if ($stage === 'md_review'  && ($leave['stage3_approver'] ?? '') === $user['role']) $authorized = true;
            }

            if ($authorized && in_array($stage, ['lm_review','hr_review','md_review'])) {
                if ($act === 'approve') {
                    if ($stage === 'lm_review') {
                        $next = $leave['stage2_approver'] ? 'hr_review'
                              : ($leave['stage3_approver'] ? 'md_review' : 'approved');
                        $db->prepare("UPDATE leave_requests SET current_stage=?,lm_status='approved',lm_actioned_by=?,lm_actioned_at=NOW() WHERE id=?")
                           ->execute([$next, $user['id'], $id]);
                    } elseif ($stage === 'hr_review') {
                        $next = $leave['stage3_approver'] ? 'md_review' : 'approved';
                        $db->prepare("UPDATE leave_requests SET current_stage=?,hr_status='approved',hr_actioned_by=?,hr_actioned_at=NOW() WHERE id=?")
                           ->execute([$next, $user['id'], $id]);
                    } elseif ($stage === 'md_review') {
                        $db->prepare("UPDATE leave_requests SET current_stage='approved',md_status='approved',md_actioned_by=?,md_actioned_at=NOW() WHERE id=?")
                           ->execute([$user['id'], $id]);
                    }
                    Auth::auditLog($user['id'], 'leave_approved', "Leave #$id approved at $stage");

                } elseif ($act === 'reject') {
                    $reason = trim($_POST['reason'] ?? '');
                    $cols = ['lm_review'=>'lm','hr_review'=>'hr','md_review'=>'md'];
                    $col  = $cols[$stage] ?? 'hr';
                    $db->prepare("UPDATE leave_requests SET current_stage='rejected',{$col}_status='rejected',{$col}_actioned_by=?,{$col}_actioned_at=NOW(),{$col}_comment=? WHERE id=?")
                       ->execute([$user['id'], $reason, $id]);
                    Auth::auditLog($user['id'], 'leave_rejected', "Leave #$id rejected at $stage");
                }
            }
        }
    }
    header('Location: leave-approvals.php');
    exit;
}

// ── FETCH QUEUE ──────────────────────────────────────────────────
if ($isSuperAdmin) {
    $pendingStmt = $db->prepare("
        SELECT lr.*, u.name as staff_name, u.email as staff_email, u.role as staff_role, u.avatar_color,
               lfr.stage1_approver, lfr.stage2_approver, lfr.stage3_approver
        FROM leave_requests lr
        JOIN users u ON u.id = lr.user_id
        LEFT JOIN leave_flow_rules lfr ON lfr.requester_role = u.role
        WHERE lr.current_stage IN ('lm_review','hr_review','md_review')
        ORDER BY lr.created_at ASC
    ");
    $pendingStmt->execute();
} else {
    $pendingStmt = $db->prepare("
        SELECT lr.*, u.name as staff_name, u.email as staff_email, u.role as staff_role, u.avatar_color,
               lfr.stage1_approver, lfr.stage2_approver, lfr.stage3_approver
        FROM leave_requests lr
        JOIN users u ON u.id = lr.user_id
        LEFT JOIN leave_flow_rules lfr ON lfr.requester_role = u.role
        WHERE lr.current_stage IN ('lm_review','hr_review','md_review')
          AND (
               (lr.current_stage = 'lm_review' AND lfr.stage1_approver = ?)
            OR (lr.current_stage = 'hr_review'  AND lfr.stage2_approver = ?)
            OR (lr.current_stage = 'md_review'  AND lfr.stage3_approver = ?)
          )
        ORDER BY lr.created_at ASC
    ");
    $pendingStmt->execute([$user['role'], $user['role'], $user['role']]);
}
$pending = $pendingStmt->fetchAll();

// Completed decisions this user was involved in
$completedStmt = $db->prepare("
    SELECT lr.*, u.name as staff_name, u.role as staff_role
    FROM leave_requests lr
    JOIN users u ON u.id = lr.user_id
    WHERE lr.current_stage IN ('approved','rejected')
      AND (lr.lm_actioned_by=? OR lr.hr_actioned_by=? OR lr.md_actioned_by=?)
    ORDER BY lr.created_at DESC LIMIT 30
");
$completedStmt->execute([$user['id'], $user['id'], $user['id']]);
$completed = $completedStmt->fetchAll();

Layout::shell($user, 'leave_approvals', 0, 'Leave Approvals');
?>
<style>
.leave-row{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid var(--g50);transition:background .12s;}
.leave-row:last-child{border-bottom:none;}
.leave-row:hover{background:var(--g50);}
.lav{width:36px;height:36px;border-radius:50%;background:var(--navy);color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;}
.linfo{flex:1;min-width:0;}
.lname{font-size:13.5px;font-weight:700;color:var(--g900);}
.lmeta{font-size:12px;color:var(--g500);margin-top:2px;}
.lchain{font-size:11px;color:var(--g400);margin-top:4px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;}
.lchain-step{padding:1px 7px;border-radius:20px;font-weight:600;font-size:10.5px;}
.lchain-step.done{background:#dcfce7;color:#166534;}
.lchain-step.active{background:var(--nl);color:var(--navy);}
.lchain-step.wait{background:var(--g100);color:var(--g500);}
.ldates{font-size:12px;color:var(--navy);font-weight:600;white-space:nowrap;}
.ldays{font-size:11px;color:var(--g400);margin-top:1px;text-align:right;}
.lpill{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.lpill.lm{background:#f3f0ff;color:#7c3aed;}
.lpill.hr{background:#dbeafe;color:#1e40af;}
.lpill.md{background:var(--nl);color:var(--navy);}
.lpill.approved{background:#dcfce7;color:#166534;}
.lpill.rejected{background:#fee2e2;color:var(--red);}
.lacts{display:flex;gap:6px;align-items:center;flex-shrink:0;margin-top:2px;}
.section-title{font-size:13px;font-weight:700;color:var(--navy);margin:20px 0 10px;display:flex;align-items:center;gap:8px;}
.section-badge{background:var(--g200);color:var(--g600);font-size:10px;padding:2px 7px;border-radius:20px;}
.section-badge.warn{background:#fef3c7;color:#92400e;}
.reject-form{display:inline;}
/* mobile-all-pages */
@media(max-width:768px){
    /* .leave-row is flex: avatar and buttons are flex-shrink:0, but the
       middle .linfo has no flex sizing — with min-width:0 it collapsed to
       14px and rendered the staff name one letter per line. Give it the
       full row and drop the buttons onto their own line. */
    .leave-row{flex-wrap:wrap;}
    .linfo{flex:1 1 100%;min-width:0;}
    .lacts{width:100%;margin-top:10px;}
    /* chain steps are chips — hold their width, wrap the strip instead */
    .lchain{flex-wrap:wrap;}
    .lchain-step{flex-shrink:0;white-space:nowrap;}

    [class*='-grid']{grid-template-columns:1fr;}
}
</style>
<div class="hri-page" style="max-width:1600px;">
    <div class="hri-page-hd">
        <h1 class="hri-page-title">&#127958; Leave Approval Queue</h1>
        <div style="display:flex;gap:8px;">
            <?php if ($isSuperAdmin): ?>
            <a href="admin/leave-flows.php" class="hri-btn hri-btn-outline">&#9881; Configure Flows</a>
            <a href="admin/leave-queue.php" class="hri-btn hri-btn-outline">Admin View &#8594;</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- PENDING -->
    <div class="section-title">
        &#9203; Pending &#8212; Awaiting Your Action
        <span class="section-badge warn"><?= count($pending) ?></span>
    </div>

    <?php if (empty($pending)): ?>
    <div class="hri-card hri-empty">No leave requests pending your approval &#10003;</div>
    <?php else: ?>
    <div class="hri-card">
        <?php foreach ($pending as $lv):
            $initials   = strtoupper(substr($lv['staff_name'],0,1).(strpos($lv['staff_name'],' ')!==false?substr($lv['staff_name'],strpos($lv['staff_name'],' ')+1,1):''));
            $startFmt   = date('d M', strtotime($lv['start_date']));
            $endFmt     = date('d M Y', strtotime($lv['end_date']));
            $stageClass = ['lm_review'=>'lm','hr_review'=>'hr','md_review'=>'md'][$lv['current_stage']] ?? 'hr';
            $stageLabel = ['lm_review'=>'Awaiting Stage 1','hr_review'=>'Awaiting Stage 2','md_review'=>'Awaiting Final'][$lv['current_stage']] ?? '';

            // Build chain steps for display
            $chainSteps = [];
            foreach (['stage1_approver'=>'lm_review','stage2_approver'=>'hr_review','stage3_approver'=>'md_review'] as $col=>$sname) {
                if (!empty($lv[$col])) {
                    $rl = ROLES[$lv[$col]]['label'] ?? $lv[$col];
                    $cls = ($lv['current_stage']===$sname) ? 'active' : (in_array($sname,['lm_review','hr_review']) && $lv['current_stage']==='hr_review' && $sname==='lm_review' ? 'done' : 'wait');
                    if ($sname==='lm_review' && in_array($lv['current_stage'],['hr_review','md_review','approved'])) $cls='done';
                    if ($sname==='hr_review' && in_array($lv['current_stage'],['md_review','approved'])) $cls='done';
                    if ($sname==='md_review' && $lv['current_stage']==='approved') $cls='done';
                    $chainSteps[] = ['label'=>$rl,'cls'=>$cls];
                }
            }
        ?>
        <div class="leave-row">
            <div class="lav"><?= $initials ?></div>
            <div class="linfo">
                <div class="lname"><?= htmlspecialchars($lv['staff_name']) ?></div>
                <div class="lmeta">
                    <?= htmlspecialchars(ROLES[$lv['staff_role']]['label'] ?? $lv['staff_role']) ?>
                    &middot; <?= ucfirst(str_replace('_',' ',$lv['leave_type'])) ?>
                    <?php if ($lv['reason']): ?> &middot; "<?= htmlspecialchars(substr($lv['reason'],0,55)) ?><?= strlen($lv['reason'])>55?'…':'' ?>"<?php endif; ?>
                </div>
                <?php if (!empty($chainSteps)): ?>
                <div class="lchain">
                    <?php foreach ($chainSteps as $i=>$cs):
                        if ($i>0) echo '<span style="color:var(--g300);">&#8594;</span>';
                    ?>
                    <span class="lchain-step <?= $cs['cls'] ?>"><?= htmlspecialchars($cs['label']) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="text-align:right;flex-shrink:0;margin-right:8px;">
                <div class="ldates"><?= $startFmt ?> &#8211; <?= $endFmt ?></div>
                <div class="ldays"><?= $lv['days'] ?> day(s)</div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                <span class="lpill <?= $stageClass ?>"><?= $stageLabel ?></span>
                <div class="lacts">
                    <form method="POST" style="display:inline;">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="approve"/>
                        <input type="hidden" name="id" value="<?= $lv['id'] ?>"/>
                        <button class="hri-btn hri-btn-navy" style="padding:5px 12px;font-size:12px;">&#10003; Approve</button>
                    </form>
                    <form class="reject-form" method="POST" id="rf<?= $lv['id'] ?>">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="reject"/>
                        <input type="hidden" name="id" value="<?= $lv['id'] ?>"/>
                        <input type="hidden" name="reason" value="" id="rr<?= $lv['id'] ?>"/>
                        <button type="button" class="hri-btn" style="padding:5px 12px;font-size:12px;background:#fee2e2;color:var(--red);border:none;"
                            onclick="openRejectModal(<?= $lv['id'] ?>)">&#10007; Reject</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- COMPLETED -->
    <div class="section-title" style="margin-top:28px;">
        &#128203; Your Recent Decisions
        <span class="section-badge"><?= count($completed) ?></span>
    </div>

    <?php if (empty($completed)): ?>
    <div class="hri-card hri-empty">No decisions recorded yet</div>
    <?php else: ?>
    <div class="hri-card">
        <?php foreach (array_values($completed) as $lv):
            $initials   = strtoupper(substr($lv['staff_name'],0,1).(strpos($lv['staff_name'],' ')!==false?substr($lv['staff_name'],strpos($lv['staff_name'],' ')+1,1):''));
            $startFmt   = date('d M', strtotime($lv['start_date']));
            $endFmt     = date('d M Y', strtotime($lv['end_date']));
            $isApproved = ($lv['current_stage'] === 'approved');
        ?>
        <div class="leave-row">
            <div class="lav" style="background:<?= $isApproved?'var(--green)':'var(--red)' ?>"><?= $initials ?></div>
            <div class="linfo">
                <div class="lname"><?= htmlspecialchars($lv['staff_name']) ?></div>
                <div class="lmeta">
                    <?= ucfirst(str_replace('_',' ',$lv['leave_type'])) ?> &middot; <?= $lv['days'] ?> days
                    <?php
                    $comment = $lv['lm_comment'] ?: $lv['hr_comment'] ?: $lv['md_comment'] ?: '';
                    if ($comment && !$isApproved): ?> &middot; <span style="color:var(--red);"><?= htmlspecialchars($comment) ?></span><?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0;margin-right:8px;">
                <div class="ldates"><?= $startFmt ?> &#8211; <?= $endFmt ?></div>
                <div class="ldays"><?= date('d M Y', strtotime($lv['created_at'])) ?></div>
            </div>
            <span class="lpill <?= $isApproved?'approved':'rejected' ?>"><?= $isApproved?'&#10003; Approved':'&#10007; Declined' ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Rejection reason modal -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:24px 28px;max-width:420px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.18);">
        <div style="font-weight:700;font-size:15px;color:var(--navy);margin-bottom:12px;">Rejection Reason</div>
        <textarea id="rejectReasonTa" rows="3" placeholder="Optional — enter reason for rejection" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-family:'Inter',sans-serif;font-size:13px;resize:vertical;box-sizing:border-box;color:#0f172a;"></textarea>
        <div style="display:flex;gap:10px;margin-top:14px;justify-content:flex-end;">
            <button onclick="closeRejectModal()" style="padding:8px 18px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;cursor:pointer;font-size:13px;">Cancel</button>
            <button onclick="confirmReject()" style="padding:8px 18px;border:none;border-radius:8px;background:#dc2626;color:#fff;cursor:pointer;font-size:13px;font-weight:600;">Confirm Reject</button>
        </div>
    </div>
</div>
<script>
var _rejectTargetId = null;
function openRejectModal(id) {
    _rejectTargetId = id;
    document.getElementById('rejectReasonTa').value = '';
    var m = document.getElementById('rejectModal');
    m.style.display = 'flex';
    setTimeout(function(){ document.getElementById('rejectReasonTa').focus(); }, 50);
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    _rejectTargetId = null;
}
function confirmReject() {
    if (!_rejectTargetId) return;
    var reason = document.getElementById('rejectReasonTa').value;
    document.getElementById('rr' + _rejectTargetId).value = reason;
    closeRejectModal();
    document.getElementById('rf' + _rejectTargetId).submit();
}
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
</script>
<?php Layout::end(); ?>
