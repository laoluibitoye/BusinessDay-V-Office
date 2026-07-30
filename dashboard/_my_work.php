<?php
/**
 * Band 2 — "My Work". Shared dashboard widget, every role.
 * Expects: $user (array), $db (PDO).
 *
 * Tasks I owe, ordered by how late they are. Overdue is called out in red at
 * the top because that is the only part that needs a decision today; the rest
 * is context. Renders nothing when there is nothing outstanding.
 */
if (!isset($user) || !isset($db)) return;
require_once __DIR__ . '/_widget_css.php';

$_wkOverdue = $_wkToday = $_wkWeek = [];
$_wkDoneMonth = 0;
try {
    $__tq = $db->prepare("SELECT id, title, due_date, priority, status,
               COALESCE(progress, 0) AS progress,
               DATEDIFF(CURDATE(), due_date) AS days_late
        FROM tasks
        WHERE user_id = ? AND status <> 'done'
          AND due_date IS NOT NULL
          AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY due_date ASC, FIELD(priority,'urgent','high','normal','low')
        LIMIT 12");
    $__tq->execute([$user['id']]);
    foreach ($__tq->fetchAll(PDO::FETCH_ASSOC) as $__t) {
        $__d = (int)$__t['days_late'];
        if     ($__d > 0) $_wkOverdue[] = $__t;
        elseif ($__d === 0) $_wkToday[] = $__t;
        else                $_wkWeek[]  = $__t;
    }

    // Small sense of momentum — closed this month
    $__dq = $db->prepare("SELECT COUNT(*) FROM tasks
        WHERE user_id = ? AND status = 'done'
          AND YEAR(updated_at) = YEAR(CURDATE()) AND MONTH(updated_at) = MONTH(CURDATE())");
    $__dq->execute([$user['id']]);
    $_wkDoneMonth = (int)$__dq->fetchColumn();
} catch (Throwable $_e) { return; }

if (empty($_wkOverdue) && empty($_wkToday) && empty($_wkWeek)) return;

if (!function_exists('_wkRow')) {
    function _wkRow(array $t, string $tone): string {
        $col = ['late' => '#dc2626', 'today' => '#b45309', 'soon' => '#64748b'][$tone];
        $bg  = ['late' => '#fef2f2', 'today' => '#fffbeb', 'soon' => ''][$tone];
        $d   = (int)$t['days_late'];
        $when = $d > 0 ? $d . 'd late' : ($d === 0 ? 'due today' : 'due ' . date('D j M', strtotime($t['due_date'])));
        $prog = (int)$t['progress'];
        $h  = '<div class="hriw-row"' . ($bg ? ' style="background:' . $bg . ';"' : '') . '>';
        $h .= '<div style="flex:1;min-width:0;">';
        $h .= '<div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">';
        if ($t['priority'] === 'urgent') {
            $h .= '<span style="background:#fee2e2;color:#dc2626;font-size:9.5px;padding:1px 5px;border-radius:3px;font-weight:700;text-transform:uppercase;margin-right:4px;">Urgent</span>';
        }
        $h .= htmlspecialchars(mb_substr((string)$t['title'], 0, 58)) . '</div>';
        if ($prog > 0) {
            $h .= '<div style="height:3px;background:#f1f5f9;border-radius:2px;margin-top:5px;overflow:hidden;">'
                . '<div style="height:100%;width:' . $prog . '%;background:' . $col . ';"></div></div>';
        }
        $h .= '</div>';
        $h .= '<span style="font-size:11px;font-weight:600;color:' . $col . ';white-space:nowrap;flex-shrink:0;">' . $when . '</span>';
        $h .= '<a href="tasks.php" class="hriw-link" style="font-size:11px;flex-shrink:0;">Open</a>';
        $h .= '</div>';
        return $h;
    }
}
$_wkLate = count($_wkOverdue);
?>
<div class="hriw-card" style="margin-bottom:16px;<?= $_wkLate > 0 ? 'border-left:4px solid #dc2626;' : '' ?>">
    <div class="hriw-hd">
        <span class="hriw-title">&#9989; My Tasks
            <?php if ($_wkLate > 0): ?>
            <span style="background:#dc2626;color:#fff;font-size:10px;padding:2px 8px;border-radius:99px;margin-left:6px;font-weight:700;"><?= $_wkLate ?> overdue</span>
            <?php endif; ?>
        </span>
        <a href="tasks.php" class="hriw-link">All &#8594;</a>
    </div>

    <?php foreach ($_wkOverdue as $__t) echo _wkRow($__t, 'late'); ?>
    <?php foreach ($_wkToday   as $__t) echo _wkRow($__t, 'today'); ?>
    <?php foreach ($_wkWeek    as $__t) echo _wkRow($__t, 'soon'); ?>

    <?php if ($_wkDoneMonth > 0): ?>
    <div style="padding:6px 14px;border-top:1px solid #f1f5f9;font-size:11px;color:#94a3b8;">
        &#10003; <?= $_wkDoneMonth ?> completed this month
    </div>
    <?php endif; ?>
</div>
