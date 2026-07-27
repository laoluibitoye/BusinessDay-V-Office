<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// leave.php — HRI Webmail Leave Application
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$error   = '';
$success = '';

// Load leave flow config for this role from DB
function getLeaveFlow($requesterRole, $db) {
    try {
        $s = $db->prepare("SELECT * FROM leave_flow_rules WHERE requester_role = ?");
        $s->execute([$requesterRole]);
        $row = $s->fetch();
        if ($row) return $row;
    } catch (PDOException $e) {}
    return ['stage1_approver'=>null,'stage2_approver'=>'hr','stage3_approver'=>'md'];
}
$flow = getLeaveFlow($user['role'], $db);

// Determine initial stage from flow
$initialStage = 'approved';
if ($flow['stage1_approver'])     $initialStage = 'lm_review';
elseif ($flow['stage2_approver']) $initialStage = 'hr_review';
elseif ($flow['stage3_approver']) $initialStage = 'md_review';

// Build ordered stage list for the sidebar widget (role label + approver count)
$flowStages = [];
$flowCountStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role=? AND is_active=1");
foreach (['stage1_approver'=>'lm_review','stage2_approver'=>'hr_review','stage3_approver'=>'md_review'] as $col=>$stageName) {
    if (!empty($flow[$col])) {
        $flowCountStmt->execute([$flow[$col]]);
        $cnt   = (int)$flowCountStmt->fetchColumn();
        $rInfo = ROLES[$flow[$col]] ?? ['label'=>$flow[$col],'icon'=>''];
        $flowStages[] = ['role'=>$flow[$col],'label'=>$rInfo['label'],'count'=>$cnt,'stage'=>$stageName];
    }
}

// ── SUBMIT LEAVE ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {
    $leaveType  = $_POST['leave_type']  ?? '';
    $startDate  = $_POST['start_date']  ?? '';
    $endDate    = $_POST['end_date']    ?? '';
    $reason     = trim($_POST['reason'] ?? '');
    $coverStaff = trim($_POST['cover_staff'] ?? '');

    // Count weekdays only (Mon–Fri), excluding Sat/Sun
    $days = 0;
    if ($startDate && $endDate) {
        $cur = new DateTime($startDate);
        $end = new DateTime($endDate);
        while ($cur <= $end) {
            $dow = (int)$cur->format('N'); // 1=Mon … 7=Sun
            if ($dow <= 5) $days++;
            $cur->modify('+1 day');
        }
    }

    // Pre-compute available balance for annual leave so the INSERT can be guarded
    $balRemain = null;
    if ($leaveType === 'annual') {
        try {
            $qb = $db->prepare("SELECT entitled_days, carried_over FROM leave_balance WHERE user_id=? AND year=YEAR(CURDATE())");
            $qb->execute([$user['id']]); $qbr = $qb->fetch();
            $qEnt = $qbr ? (int)$qbr['entitled_days'] : 15;
            $qCar = $qbr ? (int)$qbr['carried_over']  : 0;
            if (!$qbr) {
                $qs = $db->prepare("SELECT ws.annual_leave_days FROM staff_schedules ss JOIN work_schedules ws ON ws.id=ss.schedule_id WHERE ss.user_id=?");
                $qs->execute([$user['id']]); $qsr = $qs->fetch();
                if ($qsr) $qEnt = (int)$qsr['annual_leave_days'];
            }
            $qu = $db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(CURDATE()) AND (current_stage='approved' OR md_status='approved')");
            $qu->execute([$user['id']]); $qUsed = (int)$qu->fetchColumn();
            $qp = $db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(CURDATE()) AND current_stage NOT IN ('approved','rejected')");
            $qp->execute([$user['id']]); $qPend = (int)$qp->fetchColumn();
            $balRemain = max(0, $qEnt + $qCar - $qUsed - $qPend);
        } catch (PDOException $e) {} // skip if tables not yet created
    }

    if (!$leaveType || !$startDate || !$endDate || !$reason) {
        $error = 'Please fill in all required fields.';
    } elseif ($days < 1) {
        $error = 'End date must be on or after start date.';
    } elseif ($startDate < date('Y-m-d')) {
        $error = 'Start date cannot be in the past.';
    } elseif ($leaveType === 'annual' && $balRemain !== null && $days > $balRemain) {
        $error = "Insufficient leave balance. You have $balRemain day(s) remaining (including carry-over).";
    } else {
        $db->prepare("INSERT INTO leave_requests
            (user_id, leave_type, start_date, end_date, days, reason, cover_staff, current_stage)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$user['id'], $leaveType, $startDate, $endDate, $days, $reason, $coverStaff, $initialStage]);

        $leaveId = $db->lastInsertId();
        Auth::auditLog($user['id'], 'leave_submitted', "Leave request #$leaveId submitted");

        if ($initialStage !== 'approved') {
            sendLeaveNotification($user, $leaveId, $initialStage, $flow, $db);
        }

        $stageDesc = ['lm_review'=>'Your Line Manager','hr_review'=>'HR','md_review'=>'Management'][$initialStage] ?? '';
        $success = "Leave request submitted successfully for $days day(s)." . ($stageDesc ? " $stageDesc has been notified." : " Your leave has been auto-approved.");
    }
}

// ── FETCH MY LEAVE HISTORY ──────────────────────────────────
$myLeaves = $db->prepare("SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC");
$myLeaves->execute([$user['id']]);
$leaves = $myLeaves->fetchAll();

// ── LEAVE BALANCE ─────────────────────────────────────────────────────────────
// Entitlement: read from leave_balance → staff_schedules → default 15
// Used/Pending: always counted live from leave_requests (source of truth)
$entitled    = 15;
$carriedOver = 0;
$schedName   = '';
$noSchedule  = false;

// Step 1: get entitlement
try {
    $balStmt = $db->prepare("SELECT entitled_days, carried_over FROM leave_balance WHERE user_id=? AND year=YEAR(CURDATE())");
    $balStmt->execute([$user['id']]);
    $bal = $balStmt->fetch();
    if ($bal) {
        $entitled    = (int)$bal['entitled_days'];
        $carriedOver = (int)$bal['carried_over'];
    } else {
        // No leave_balance row — try staff_schedules
        try {
            $schStmt = $db->prepare("SELECT ws.annual_leave_days, ws.name FROM staff_schedules ss JOIN work_schedules ws ON ws.id=ss.schedule_id WHERE ss.user_id=?");
            $schStmt->execute([$user['id']]);
            $sch = $schStmt->fetch();
            if ($sch) {
                $entitled  = (int)$sch['annual_leave_days'];
                $schedName = $sch['name'];
            } else {
                $noSchedule = true; // user has no schedule assigned yet
            }
        } catch (PDOException $e) {
            $noSchedule = true; // work_schedules tables not yet created
        }
    }
} catch (PDOException $e) {
    $noSchedule = true; // leave_balance table not yet created
}

// Step 2: count used + pending from leave_requests (always live)
$usedStmt = $db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(CURDATE()) AND (current_stage='approved' OR md_status='approved')");
$usedStmt->execute([$user['id']]);
$used = (int)$usedStmt->fetchColumn();

$pendStmt = $db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(CURDATE()) AND current_stage NOT IN ('approved','rejected')");
$pendStmt->execute([$user['id']]);
$pending = (int)$pendStmt->fetchColumn();

$totalEntitled = $entitled + $carriedOver;
$balance       = max(0, $totalEntitled - $used - $pending);

// ── SEND NOTIFICATION EMAIL ─────────────────────────────────
function sendLeaveNotification($staff, $leaveId, $initialStage, $flow, $db) {
    $leaveStmt = $db->prepare("SELECT * FROM leave_requests WHERE id = ?");
    $leaveStmt->execute([$leaveId]);
    $leave = $leaveStmt->fetch();
    if (!$leave) return;

    $startFmt = date('d M Y', strtotime($leave['start_date']));
    $endFmt   = date('d M Y', strtotime($leave['end_date']));

    $stageMap = [
        'lm_review' => ['role'=>$flow['stage1_approver'],'key'=>'lm'],
        'hr_review'  => ['role'=>$flow['stage2_approver'],'key'=>'hr'],
        'md_review'  => ['role'=>$flow['stage3_approver'],'key'=>'md'],
    ];
    $info = $stageMap[$initialStage] ?? null;
    if (!$info || empty($info['role'])) return;

    $approvers = $db->prepare("SELECT * FROM users WHERE role = ? AND is_active = 1");
    $approvers->execute([$info['role']]);
    foreach ($approvers->fetchAll() as $approver) {
        $body = buildLeaveEmail($staff['name'], $leave, $startFmt, $endFmt, $approver['name']);
        sendMail($approver['email'], $approver['name'], "Leave Request Awaiting Approval — {$staff['name']}", $body);
    }

    $stageDesc = ['lm_review'=>'your Line Manager','hr_review'=>'HR','md_review'=>'Management'][$initialStage] ?? 'your approver';
    $ackBody = "Dear {$staff['name']},\n\n"
        . "Your leave request has been submitted and forwarded to $stageDesc for review.\n\n"
        . "Type:   " . ucfirst(str_replace('_',' ',$leave['leave_type'])) . "\n"
        . "Dates:  $startFmt to $endFmt ({$leave['days']} days)\n\n"
        . "You will be notified when a decision is made.\n\n"
        . "View your leave history: " . APP_URL . "/leave.php\n\n"
        . "HR Indexx Limited";
    sendMail($staff['email'], $staff['name'], "Leave Request Submitted — Awaiting Approval", $ackBody);
}

function buildLeaveEmail($staffName, $leave, $startFmt, $endFmt, $approverName) {
    return "Dear $approverName,\n\n"
         . "$staffName has submitted a leave request requiring your approval.\n\n"
         . "Type:   " . ucfirst(str_replace('_',' ',$leave['leave_type'])) . "\n"
         . "Dates:  $startFmt to $endFmt ({$leave['days']} days)\n"
         . "Reason: {$leave['reason']}\n"
         . ($leave['cover_staff'] ? "Cover:  {$leave['cover_staff']}\n" : "")
         . "\nTo approve or decline, log in to HRI Mail and go to:\n"
         . APP_URL . "/leave-approvals.php\n\n"
         . "This is an automated notification from HRI Mail.\n"
         . "HR Indexx Limited · 12 Macarthy Street, Onikan, Lagos Island";
}

function sendMail($to, $toName, $subject, $body) {
    sendSystemMail($to, $toName, $subject, $body);
}

// Stage labels
function stageLabel($stage, $status) {
    $map = [
        'submitted'  => ['Submitted','⏳','#64748b'],
        'lm_review'  => ['Awaiting LM','⏳','#f59e0b'],
        'hr_review'  => ['Awaiting HR','⏳','#3b82f6'],
        'md_review'  => ['Awaiting MD','⏳','#8b5cf6'],
        'approved'   => ['Approved','✅','#64A014'],
        'rejected'   => ['Rejected','❌','#dc2626'],
    ];
    return $map[$stage] ?? [$stage,'','#64748b'];
}

Layout::shell($user, 'leave', 0, 'Leave Request');
?>
<style>
/* FORM CARD */
.card{background:var(--w);border-radius:14px;box-shadow:var(--shl);overflow:hidden;}
.chd{padding:14px 20px;border-bottom:1px solid var(--g100);display:flex;align-items:center;gap:10px;}
.chd-ico{font-size:22px;}
.chd-title{font-size:14px;font-weight:700;color:var(--navy);}
.chd-sub{font-size:11.5px;color:var(--g400);margin-top:1px;}
.cbd{padding:20px;}
.alert{padding:11px 16px;border-radius:9px;font-size:13px;display:flex;align-items:flex-start;gap:8px;margin-bottom:16px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.fg{margin-bottom:14px;}
.fg.row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
input,select,textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:9px 12px;font-size:13.5px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;}
input:focus,select:focus,textarea:focus{border-color:var(--green);background:var(--w);}
textarea{resize:none;height:80px;}
.cft{padding:14px 20px;border-top:1px solid var(--g100);display:flex;align-items:center;gap:8px;}
.btn{padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:all .15s;display:inline-flex;align-items:center;gap:6px;}
.btn.gn{background:var(--green);color:#fff;}
.btn.gn:hover{background:var(--gd);}
.btn.ol{background:transparent;border:1.5px solid var(--g200);color:var(--g700);}
.btn.ol:hover{border-color:var(--navy);color:var(--navy);}
/* Balance widget */
.bal-card{background:linear-gradient(135deg,#002850,#001635);border-radius:12px;padding:18px;color:#fff;margin-bottom:14px;}
.bal-title{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.6);margin-bottom:8px;}
.bal-days{font-size:36px;font-weight:700;line-height:1;}
.bal-sub{font-size:12px;color:rgba(255,255,255,.6);margin-top:4px;}
.bal-bar{height:6px;background:rgba(255,255,255,.2);border-radius:99px;margin-top:12px;overflow:hidden;}
.bal-fill{height:100%;background:var(--green);border-radius:99px;}
/* Approval flow */
.flow-card{background:var(--w);border-radius:12px;box-shadow:var(--sh);overflow:hidden;margin-bottom:14px;}
.flow-hd{padding:12px 16px;border-bottom:1px solid var(--g100);font-size:12.5px;font-weight:700;color:var(--navy);}
.flow-body{padding:14px 16px;}
.flow-step{display:flex;align-items:center;gap:10px;padding:7px 0;position:relative;}
.flow-step:not(:last-child)::after{content:'';position:absolute;left:14px;top:32px;bottom:-7px;width:2px;background:var(--g200);}
.flow-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;font-weight:700;}
.flow-dot.active{background:var(--navy);color:#fff;}
.flow-dot.done{background:var(--green);color:#fff;}
.flow-dot.pending{background:var(--g100);color:var(--g400);}
.flow-dot.skip{background:var(--g200);color:var(--g400);}
.flow-txt{flex:1;}
.flow-name{font-size:12.5px;font-weight:600;color:var(--g900);}
.flow-sub{font-size:11px;color:var(--g400);margin-top:1px;}
.flow-tag{font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:20px;}
.flow-tag.active{background:var(--nl);color:var(--navy);}
.flow-tag.skip{background:var(--g100);color:var(--g500);}
/* History table */
.hist-card{background:var(--w);border-radius:12px;box-shadow:var(--sh);overflow:hidden;}
.hist-hd{padding:12px 16px;border-bottom:1px solid var(--g100);font-size:12.5px;font-weight:700;color:var(--navy);}
.hist-table{width:100%;border-collapse:collapse;}
.hist-table th{background:var(--g50);padding:8px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.hist-table td{padding:10px 14px;border-bottom:1px solid var(--g50);font-size:12.5px;color:var(--g700);vertical-align:middle;}
.hist-table tr:last-child td{border-bottom:none;}
.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.pill.approved{background:#dcfce7;color:#166534;}
.pill.rejected{background:#fee2e2;color:var(--red);}
.pill.pending{background:#fef3c7;color:#92400e;}
.pill.hr{background:#dbeafe;color:#1e40af;}
.pill.lm{background:#f3f0ff;color:var(--purple);}
.pill.md{background:var(--nl);color:var(--navy);}
/* Right sidebar widgets */
.right-col{display:flex;flex-direction:column;gap:14px;}
::-webkit-scrollbar{width:4px;}::-webkit-scrollbar-thumb{background:var(--g200);border-radius:99px;}
.leave-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;max-width:960px;margin:0 auto;}
@media(max-width:640px){
    .leave-grid{grid-template-columns:1fr;}
    .fg.row{grid-template-columns:1fr;}
}
</style>
<div style="padding:20px 24px;max-width:1040px;margin:0 auto;overflow-y:auto;height:100%;">
<div class="leave-grid">

    <!-- LEFT: FORM -->
    <div>
        <div class="card" style="margin-bottom:20px;">
            <div class="chd">
                <div class="chd-ico">🏖️</div>
                <div>
                    <div class="chd-title">Submit Leave Request</div>
                    <div class="chd-sub">
                        Your request goes to: <strong><?php
                            if (empty($flowStages)) {
                                echo 'Auto-approved';
                            } else {
                                echo implode(' &#8594; ', array_map(function($s){ return htmlspecialchars($s['label']); }, $flowStages));
                            }
                        ?></strong>
                    </div>
                </div>
            </div>
            <div class="cbd">
                <?php if ($success): ?>
                <div class="alert ok">✅ <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?= Auth::csrfField() ?>
                    <div class="fg">
                        <label>Leave Type *</label>
                        <select name="leave_type" required>
                            <option value="">Select type…</option>
                            <option value="annual">Annual Leave</option>
                            <option value="sick">Sick Leave</option>
                            <option value="compassionate">Compassionate Leave</option>
                            <option value="maternity">Maternity Leave</option>
                            <option value="paternity">Paternity Leave</option>
                            <option value="unpaid">Unpaid Leave</option>
                        </select>
                    </div>

                    <div class="fg row">
                        <div>
                            <label>Start Date *</label>
                            <input type="date" name="start_date" min="<?= date('Y-m-d') ?>"
                                   required onchange="calcDays()"/>
                        </div>
                        <div>
                            <label>End Date *</label>
                            <input type="date" name="end_date" min="<?= date('Y-m-d') ?>"
                                   required onchange="calcDays()"/>
                        </div>
                    </div>

                    <div class="fg" id="daysPreview" style="display:none;">
                        <div style="background:var(--nl);border-radius:8px;padding:9px 13px;font-size:13px;color:var(--navy);font-weight:600;">
                            📅 <span id="daysCount">0</span> working day(s) requested
                        </div>
                    </div>

                    <div class="fg">
                        <label>Reason *</label>
                        <textarea name="reason" placeholder="Brief reason for leave…" required></textarea>
                    </div>

                    <div class="fg">
                        <label>Cover Arranged With</label>
                        <input type="text" name="cover_staff"
                               placeholder="Who will cover your duties at HRI?"/>
                    </div>

                    <div class="cft" style="padding:0;border:none;margin-top:4px;">
                        <button type="submit" name="submit_leave" class="btn gn">
                            📤 Submit Leave Request
                        </button>
                        <a href="/" class="btn ol">Cancel</a>
                        <div style="margin-left:auto;font-size:12px;color:var(--g400);">
                            Balance: <strong style="color:var(--navy);"><?= max(0,$balance) ?> days</strong> remaining
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- LEAVE HISTORY -->
        <div class="hist-card">
            <div class="hist-hd">📋 My Leave History</div>
            <?php if (empty($leaves)): ?>
            <div style="padding:20px;text-align:center;color:var(--g400);font-size:13px;">No leave requests yet</div>
            <?php else: ?>
            <table class="hist-table">
                <thead>
                    <tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Stage</th></tr>
                </thead>
                <tbody>
                <?php foreach ($leaves as $lv):
                    [$stageLabel,$stageIco,$stageColor] = stageLabel($lv['current_stage'], '');
                ?>
                <tr>
                    <td><?= ucfirst(str_replace('_',' ',$lv['leave_type'])) ?></td>
                    <td style="white-space:nowrap;">
                        <?= date('d M', strtotime($lv['start_date'])) ?> –
                        <?= date('d M Y', strtotime($lv['end_date'])) ?>
                    </td>
                    <td><?= $lv['days'] ?></td>
                    <td>
                        <?php
                        $cls = match($lv['current_stage']) {
                            'approved' => 'approved',
                            'rejected' => 'rejected',
                            'lm_review'=> 'lm',
                            'hr_review'=> 'hr',
                            'md_review'=> 'md',
                            default    => 'pending',
                        };
                        ?>
                        <span class="pill <?= $cls ?>"><?= $stageIco ?> <?= $stageLabel ?></span>
                    </td>
                    <td>
                        <?php
                        // Show who has approved so far
                        $approvals = [];
                        if ($lv['lm_status'] === 'approved') $approvals[] = 'LM ✓';
                        if ($lv['hr_status'] === 'approved') $approvals[] = 'HR ✓';
                        if ($lv['md_status'] === 'approved') $approvals[] = 'MD ✓';
                        echo $approvals ? implode(' · ', $approvals) : '—';
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT COL -->
    <div class="right-col">

        <!-- Annual Leave Balance -->
        <div class="bal-card">
            <div class="bal-title">Annual Leave Balance <?= date('Y') ?><?= $schedName ? ' · '.htmlspecialchars($schedName) : '' ?></div>
            <div class="bal-days"><?= $balance ?> <span style="font-size:18px;font-weight:400;opacity:.7;">days left</span></div>
            <div class="bal-sub">
                <?= $used ?> used · <?= $pending ?> pending · <?= $totalEntitled ?> entitled<?= $carriedOver ? ' (+'.$carriedOver.' carried)' : '' ?>
            </div>
            <div class="bal-bar">
                <div class="bal-fill" style="width:<?= $totalEntitled > 0 ? min(100,round(($used/$totalEntitled)*100)) : 0 ?>%"></div>
            </div>
            <?php if ($noSchedule): ?>
            <div style="margin-top:10px;font-size:11px;background:rgba(255,255,255,.15);border-radius:6px;padding:7px 10px;color:rgba(255,255,255,.85);">
                ⚠️ No work schedule assigned. Admin → Work Schedules to assign one.<br/>Showing default 15 days until assigned.
            </div>
            <?php endif; ?>
        </div>

        <!-- Approval Flow -->
        <div class="flow-card">
            <div class="flow-hd">&#128203; Approval Flow &#8212; <?= htmlspecialchars(ROLES[$user['role']]['label'] ?? $user['role']) ?></div>
            <div class="flow-body">
                <?php if (empty($flowStages)): ?>
                <div style="text-align:center;padding:14px;color:var(--g400);font-size:13px;">
                    &#10003; Your leave is auto-approved on submission.
                </div>
                <?php else: ?>
                <?php
                $total = count($flowStages);
                foreach ($flowStages as $i => $step):
                    $ord = $i === $total-1 ? 'Final' : ($i===0?'First':'Stage '.($i+1));
                ?>
                <div class="flow-step">
                    <div class="flow-dot active"><?= $i+1 ?></div>
                    <div class="flow-txt">
                        <div class="flow-name"><?= htmlspecialchars($step['label']) ?></div>
                        <div class="flow-sub"><?= $step['count'] ?> staff in this role</div>
                    </div>
                    <span class="flow-tag active"><?= $ord ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Leave Types Info -->
        <div class="flow-card">
            <div class="flow-hd">📖 Leave Entitlements</div>
            <div class="flow-body">
                <?php
                $types = [
                    ['Annual Leave',       $totalEntitled.' days/year',  '#64A014'],
                    ['Sick Leave',         'As needed',      '#3b82f6'],
                    ['Compassionate',      'Up to 5 days',   '#8b5cf6'],
                    ['Maternity Leave',    '12 weeks',       '#f97316'],
                    ['Paternity Leave',    '2 weeks',        '#f59e0b'],
                    ['Unpaid Leave',       'With approval',  '#94a3b8'],
                ];
                foreach ($types as [$name,$days,$color]): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--g50);">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>;flex-shrink:0;"></div>
                        <span style="font-size:12.5px;color:var(--g700);"><?= $name ?></span>
                    </div>
                    <span style="font-size:12px;font-weight:600;color:var(--navy);"><?= $days ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>
</div>

<script>
function calcDays() {
    const start = document.querySelector('[name="start_date"]').value;
    const end   = document.querySelector('[name="end_date"]').value;
    if (!start || !end) return;
    // Count Mon–Fri only, skip Sat (6) and Sun (0)
    let count = 0;
    const cur = new Date(start + 'T00:00:00');
    const fin = new Date(end   + 'T00:00:00');
    while (cur <= fin) {
        const dow = cur.getDay();
        if (dow !== 0 && dow !== 6) count++;
        cur.setDate(cur.getDate() + 1);
    }
    const prev = document.getElementById('daysPreview');
    const cnt  = document.getElementById('daysCount');
    if (count > 0) {
        cnt.textContent = count;
        prev.style.display = 'block';
    } else {
        prev.style.display = 'none';
    }
}
</script>
<?php Layout::end(); ?>
