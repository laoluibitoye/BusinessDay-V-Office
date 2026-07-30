<?php
/**
 * "My IT Tickets" — shared dashboard widget.
 * Expects: $user (array), $db (PDO).
 *
 * Staff could raise an IT request but had no way to see what happened to it —
 * only superadmin.php listed tickets, and that is the queue view for IT, not
 * the requester's view. This closes that loop on every dashboard.
 *
 * Renders nothing at all when the user has no open tickets, so it stays out of
 * the way for everyone who is not waiting on IT.
 */
if (!isset($user) || !isset($db)) return;

// A dashboard widget must never be able to take the whole page down. Catch
// Throwable, not Exception: a TypeError or a missing table is an Error in
// PHP 7+, which an Exception handler does not catch and which would surface
// as a bare 500 on the user's home page.
$_myTix = [];
try {
    $__tq = $db->prepare("SELECT t.id, t.issue_type, t.priority, t.description, t.status,
               t.created_at, a.name AS assignee,
               DATEDIFF(NOW(), t.created_at) AS days_open
        FROM it_requests t
        LEFT JOIN users a ON a.id = t.assigned_to
        WHERE t.user_id = ? AND t.status <> 'closed'
        ORDER BY FIELD(t.status,'in_progress','open','resolved'),
                 FIELD(t.priority,'urgent','normal'), t.created_at ASC
        LIMIT 4");
    $__tq->execute([$user['id']]);
    $_myTix = $__tq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_e) { return; }

if (empty($_myTix)) return;   // nothing waiting on IT — render nothing

$_tixStyle = [
    'open'        => ['#fef3c7', '#b45309', 'Open'],
    'in_progress' => ['#dbeafe', '#1d4ed8', 'In Progress'],
    'resolved'    => ['#dcfce7', '#059669', 'Resolved'],
];
?>
<div class="card" style="margin-bottom:16px;">
    <div class="chd">
        <span class="cht">&#128295; My IT Tickets
            <span style="background:#e2e8f0;color:#475569;font-size:10px;padding:2px 8px;border-radius:99px;margin-left:6px;font-weight:700;"><?= count($_myTix) ?></span>
        </span>
        <a href="it-request.php" class="chl">All &#8594;</a>
    </div>
    <?php foreach ($_myTix as $__t):
        $__s   = $_tixStyle[$__t['status']] ?? ['#f1f5f9', '#64748b', ucfirst((string)$__t['status'])];
        $__age = (int)($__t['days_open'] ?? 0);
        $__urg = $__t['priority'] === 'urgent';
    ?>
    <div class="ritem">
        <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?php if ($__urg): ?><span style="background:#fee2e2;color:#dc2626;font-size:9.5px;padding:1px 5px;border-radius:3px;font-weight:700;text-transform:uppercase;margin-right:4px;">Urgent</span><?php endif; ?>
                <?= htmlspecialchars($__t['issue_type']) ?>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= htmlspecialchars(mb_substr((string)$__t['description'], 0, 60)) ?>
            </div>
            <div style="font-size:10.5px;color:#94a3b8;margin-top:2px;">
                <?= $__age > 0 ? $__age . 'd open' : 'raised today' ?>
                <?php if (!empty($__t['assignee'])): ?> &bull; with <?= htmlspecialchars($__t['assignee']) ?><?php endif; ?>
            </div>
        </div>
        <span style="background:<?= $__s[0] ?>;color:<?= $__s[1] ?>;font-size:10px;padding:2px 8px;border-radius:99px;font-weight:600;white-space:nowrap;flex-shrink:0;"><?= $__s[2] ?></span>
    </div>
    <?php endforeach; ?>
</div>
