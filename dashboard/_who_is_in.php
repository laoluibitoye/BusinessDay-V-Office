<?php
/**
 * Band 3 — "Who is in today". Shared dashboard widget.
 * Expects: $user (array), $db (PDO).
 *
 * RESTRICTED: the work roster is visible to HR, Super Admin and Management
 * only. The check lives here rather than in each dashboard, so the rule holds
 * wherever this file is included and cannot be re-introduced by accident.
 *
 * "Rostered today" = staff whose staff_schedules.working_days contains today's
 * ISO day number (Mon=1 … Sun=7), minus anyone on approved leave.
 */
if (!isset($user) || !isset($db)) return;

if (!defined('ROSTER_VISIBLE_ROLES')) {
    // Who is ALLOWED to see the roster at all.
    define('ROSTER_VISIBLE_ROLES', ['hr', 'head_it', 'it_admin', 'md', 'bdm']);
}
if (!in_array($user['role'] ?? '', ROSTER_VISIBLE_ROLES, true)) return;

// md/bdm are allowed, but management.php already carries the full week-grid
// Staff Roster — the only place that view exists, since there is no roster.php.
// Rendering this as well would show the same thing twice on one page.
if (in_array($user['role'], ['md', 'bdm'], true)) return;

$_inToday = $_onLeave = $_online = [];
$_noSchedule = 0;
try {
    $__dow = (int)date('N');   // 1 = Monday … 7 = Sunday

    // On approved leave today — needed first so they can be excluded from "in"
    $__lq = $db->prepare("SELECT lr.user_id, u.name, u.department, lr.end_date
        FROM leave_requests lr JOIN users u ON u.id = lr.user_id
        WHERE lr.current_stage = 'approved'
          AND CURDATE() BETWEEN lr.start_date AND lr.end_date
        ORDER BY u.name");
    $__lq->execute();
    $_onLeave = $__lq->fetchAll(PDO::FETCH_ASSOC);
    $__leaveIds = array_column($_onLeave, 'user_id');

    // Rostered today
    $__sq = $db->prepare("SELECT u.id, u.name, u.department, u.role, ws.name AS schedule
        FROM users u
        JOIN staff_schedules ss ON ss.user_id = u.id
        LEFT JOIN work_schedules ws ON ws.id = ss.schedule_id
        WHERE u.is_active = 1
          AND FIND_IN_SET(?, ss.working_days)
        ORDER BY u.department, u.name");
    $__sq->execute([$__dow]);
    foreach ($__sq->fetchAll(PDO::FETCH_ASSOC) as $__r) {
        if (!in_array($__r['id'], $__leaveIds)) $_inToday[] = $__r;
    }

    // Active staff with no schedule assigned — their entitlement cannot be computed
    $__nq = $db->query("SELECT COUNT(*) FROM users u
        LEFT JOIN staff_schedules ss ON ss.user_id = u.id
        WHERE u.is_active = 1 AND ss.id IS NULL");
    $_noSchedule = (int)$__nq->fetchColumn();

    // Online in the last 5 minutes
    $__oq = $db->query("SELECT DISTINCT u.name, u.department
        FROM sessions s JOIN users u ON u.id = s.user_id
        WHERE s.last_active > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ORDER BY u.name");
    $_online = $__oq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_e) { return; }

// Group the rostered list by department so it stays readable at ~20 staff
$_byDept = [];
foreach ($_inToday as $__r) $_byDept[$__r['department'] ?: 'Unassigned'][] = $__r['name'];
?>
<div class="card" style="margin-bottom:16px;">
    <div class="chd">
        <span class="cht">&#128197; Who Is In &mdash; <?= date('D j M') ?></span>
        <a href="directory.php" class="chl">Directory &#8594;</a>
    </div>

    <!-- headline counts -->
    <div style="display:flex;gap:0;border-bottom:1px solid #f1f5f9;">
        <div style="flex:1;padding:10px 14px;text-align:center;border-right:1px solid #f1f5f9;">
            <div style="font-size:20px;font-weight:700;color:#059669;line-height:1.2;"><?= count($_inToday) ?></div>
            <div style="font-size:10.5px;color:#64748b;">Rostered</div>
        </div>
        <div style="flex:1;padding:10px 14px;text-align:center;border-right:1px solid #f1f5f9;">
            <div style="font-size:20px;font-weight:700;color:#b45309;line-height:1.2;"><?= count($_onLeave) ?></div>
            <div style="font-size:10.5px;color:#64748b;">On Leave</div>
        </div>
        <div style="flex:1;padding:10px 14px;text-align:center;">
            <div style="font-size:20px;font-weight:700;color:#1d4ed8;line-height:1.2;"><?= count($_online) ?></div>
            <div style="font-size:10.5px;color:#64748b;">Online Now</div>
        </div>
    </div>

    <?php if (!empty($_byDept)): ?>
    <?php foreach ($_byDept as $__dept => $__names): ?>
    <div style="padding:7px 14px;border-bottom:1px solid #f1f5f9;display:flex;gap:9px;align-items:baseline;">
        <span style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;min-width:96px;flex-shrink:0;"><?= htmlspecialchars($__dept) ?></span>
        <span style="font-size:12.5px;color:#334155;"><?= htmlspecialchars(implode(', ', $__names)) ?></span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty">Nobody is rostered today.</div>
    <?php endif; ?>

    <?php if (!empty($_onLeave)): ?>
    <div style="padding:7px 14px;border-bottom:1px solid #f1f5f9;display:flex;gap:9px;align-items:baseline;background:#fffbeb;">
        <span style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#b45309;min-width:96px;flex-shrink:0;">On Leave</span>
        <span style="font-size:12.5px;color:#92400e;">
            <?php $__lv = [];
            foreach ($_onLeave as $__l) $__lv[] = $__l['name'] . ' (back ' . date('j M', strtotime($__l['end_date'] . ' +1 day')) . ')';
            echo htmlspecialchars(implode(', ', $__lv)); ?>
        </span>
    </div>
    <?php endif; ?>

    <?php if ($_noSchedule > 0): ?>
    <div style="padding:7px 14px;font-size:11.5px;color:#b45309;background:#fffbeb;">
        &#9888; <?= $_noSchedule ?> active staff have no work schedule assigned &mdash;
        <a href="admin/users.php" style="color:#92400e;font-weight:600;">assign one</a>
        <span style="color:#a16207;">(their leave entitlement cannot be calculated without it)</span>
    </div>
    <?php endif; ?>
</div>
