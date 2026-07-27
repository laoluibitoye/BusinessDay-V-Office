<?php
/**
 * Shared IRS dashboard widget — included by all dashboard files.
 * Expects: $user (array), $db (PDO), Auth class already loaded.
 * Uses IrsFlow FSM stage tables (not old hardcoded status strings).
 */
if (!isset($user) || !isset($db)) return;
if (!class_exists('IrsFlow')) {
    $__irsFlowPath = __DIR__ . '/../lib/IrsFlow.php';
    if (file_exists($__irsFlowPath)) require_once $__irsFlowPath; else return;
}

$_isAdmin = Auth::isAdmin($user);

// Find stages where this user's role can act (from FSM table)
$_actorStages = [];
try {
    $__ps = $db->prepare("SELECT DISTINCT stage_code FROM irs_flow_stages WHERE JSON_CONTAINS(actor_roles, ?)");
    $__ps->execute([json_encode($user['role'])]);
    $_actorStages = $__ps->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $_e) {}

$_canApprove = !empty($_actorStages) || $_isAdmin;
$_irsQueueCnt = 0;
$_irsQueue    = [];
$_myIrs       = [];

try {
    // ── Pending actions queue (for approvers / admins) ────────────────────────
    if ($_canApprove) {
        if ($_isAdmin) {
            // Admin sees all non-terminal, non-draft
            $__cq = $db->prepare("SELECT COUNT(*) FROM irs_requests WHERE status NOT IN ('completed','rejected','draft')");
            $__cq->execute();
            $_irsQueueCnt = (int)$__cq->fetchColumn();

            $__lq = $db->prepare("SELECT r.id, r.ref_number, r.type, r.status, r.priority, r.amount, r.description,
                u.name requester_name, r.created_at,
                DATEDIFF(NOW(), r.created_at) AS days_open
                FROM irs_requests r JOIN users u ON u.id=r.requester_id
                WHERE r.status NOT IN ('completed','rejected','draft')
                ORDER BY FIELD(r.priority,'urgent','normal','low'), r.created_at ASC LIMIT 6");
            $__lq->execute();
            $_irsQueue = $__lq->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($_actorStages)) {
            $__ph = implode(',', array_fill(0, count($_actorStages), '?'));
            $__cq = $db->prepare("SELECT COUNT(*) FROM irs_requests WHERE status IN ($__ph)");
            $__cq->execute($_actorStages);
            $_irsQueueCnt = (int)$__cq->fetchColumn();

            $__lq = $db->prepare("SELECT r.id, r.ref_number, r.type, r.status, r.priority, r.amount, r.description,
                u.name requester_name, r.created_at,
                DATEDIFF(NOW(), r.created_at) AS days_open
                FROM irs_requests r JOIN users u ON u.id=r.requester_id
                WHERE r.status IN ($__ph)
                ORDER BY FIELD(r.priority,'urgent','normal','low'), r.created_at ASC LIMIT 6");
            $__lq->execute($_actorStages);
            $_irsQueue = $__lq->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // ── My own active requests (everyone sees their own) ──────────────────────
    $__mq = $db->prepare("SELECT id, ref_number, type, status, priority, amount, description, created_at
        FROM irs_requests WHERE requester_id=? AND status NOT IN ('completed','rejected','draft')
        ORDER BY created_at DESC LIMIT 4");
    $__mq->execute([$user['id']]);
    $_myIrs = $__mq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $_e) { return; }
?>

<?php if ($_canApprove): ?>
<!-- IRS Pending Actions Widget -->
<div class="card" style="margin-bottom:16px;">
    <div class="chd" style="<?= $_irsQueueCnt > 0 ? 'border-left:4px solid #ef4444;' : '' ?>">
        <span class="cht">
            &#9889; IRS — Pending My Action
            <?php if ($_irsQueueCnt > 0): ?>
            <span style="background:#ef4444;color:#fff;font-size:10px;padding:2px 8px;border-radius:99px;margin-left:6px;font-weight:700;"><?= $_irsQueueCnt ?></span>
            <?php endif; ?>
        </span>
        <a href="irs.php?view=all" class="chl">View All &#8594;</a>
    </div>
    <?php if (empty($_irsQueue)): ?>
    <div class="empty">&#10003; No requests pending your action</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;white-space:nowrap;">Ref</th>
                <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;">Requester</th>
                <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;">Type</th>
                <th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600;">Amount</th>
                <th style="padding:7px 10px;text-align:center;color:#64748b;font-weight:600;">Stage</th>
                <th style="padding:7px 10px;text-align:center;color:#64748b;font-weight:600;">Age</th>
                <th style="padding:7px 10px;"></th>
            </tr></thead>
            <tbody>
            <?php
            $__typeL  = ['requisition'=>'Requisition','caution'=>'Caution','payment'=>'Payment Req.','petty_cash'=>'Petty Cash','retirement'=>'Retirement'];
            $__priCol = ['urgent'=>['#fee2e2','#dc2626'],'normal'=>['#f0fdf4','#166534'],'low'=>['#f8fafc','#64748b']];
            foreach ($_irsQueue as $__r):
                $__stLbl = IrsFlow::defaultStageLabel($__r['status']);
                $__stCol = IrsFlow::stageColor($__r['status']);
                $__tl    = $__typeL[$__r['type']] ?? $__r['type'];
                $__days  = (int)($__r['days_open'] ?? 0);
                $__dCol  = $__days >= 5 ? '#ef4444' : ($__days >= 3 ? '#f59e0b' : '#64748b');
                $__pri   = $__priCol[$__r['priority']] ?? ['#f8fafc','#64748b'];
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;" onmouseenter="this.style.background='#f8fafc'" onmouseleave="this.style.background=''">
                <td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#002850;white-space:nowrap;">
                    <span style="background:<?= $__pri[0] ?>;color:<?= $__pri[1] ?>;font-size:9.5px;padding:1px 5px;border-radius:3px;font-family:sans-serif;font-weight:700;margin-right:4px;text-transform:uppercase;"><?= $__r['priority'] ?></span>
                    <?= htmlspecialchars($__r['ref_number']) ?>
                </td>
                <td style="padding:7px 10px;color:#334155;"><?= htmlspecialchars($__r['requester_name']) ?></td>
                <td style="padding:7px 10px;"><span style="background:#e0f2fe;color:#0369a1;font-size:10.5px;padding:2px 7px;border-radius:99px;white-space:nowrap;"><?= $__tl ?></span></td>
                <td style="padding:7px 10px;text-align:right;font-weight:600;white-space:nowrap;">&#8358;<?= number_format((float)$__r['amount']) ?></td>
                <td style="padding:7px 10px;text-align:center;"><span style="background:<?= $__stCol ?>20;color:<?= $__stCol ?>;font-size:10px;padding:2px 7px;border-radius:99px;font-weight:600;white-space:nowrap;"><?= $__stLbl ?></span></td>
                <td style="padding:7px 10px;text-align:center;font-weight:600;color:<?= $__dCol ?>;white-space:nowrap;font-size:11.5px;"><?= $__days ?>d</td>
                <td style="padding:7px 10px;text-align:right;"><a href="irs-detail.php?id=<?= $__r['id'] ?>" style="font-size:11.5px;font-weight:600;color:#002850;text-decoration:none;background:#eff6ff;padding:3px 9px;border-radius:4px;white-space:nowrap;">Action &#8594;</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($_irsQueueCnt > 6): ?>
    <div style="padding:7px 12px;border-top:1px solid #f1f5f9;text-align:right;font-size:11.5px;">
        <a href="irs.php?view=all" style="color:#002850;font-weight:600;">+<?= $_irsQueueCnt - 6 ?> more &rarr;</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($_myIrs)): ?>
<!-- My active IRS submissions -->
<div class="card" style="margin-bottom:16px;">
    <div class="chd">
        <span class="cht">&#128196; My Active IRS Requests</span>
        <a href="irs.php" class="chl">All &#8594;</a>
    </div>
    <?php
    $__typeL2 = ['requisition'=>'Requisition','caution'=>'Caution','payment'=>'Payment Req.','petty_cash'=>'Petty Cash','retirement'=>'Retirement'];
    foreach ($_myIrs as $__r):
        $__stCol = IrsFlow::stageColor($__r['status']);
        $__stLbl = IrsFlow::defaultStageLabel($__r['status']);
        $__tl    = $__typeL2[$__r['type']] ?? $__r['type'];
    ?>
    <div class="ritem">
        <div style="font-family:monospace;font-weight:700;color:#002850;flex-shrink:0;font-size:12px;"><?= htmlspecialchars($__r['ref_number']) ?></div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(substr($__r['description'],0,55)) ?></div>
            <div style="font-size:11px;color:#64748b;"><?= $__tl ?> &bull; &#8358;<?= number_format((float)$__r['amount']) ?></div>
        </div>
        <span style="background:<?= $__stCol ?>20;color:<?= $__stCol ?>;font-size:10px;padding:2px 7px;border-radius:99px;font-weight:600;white-space:nowrap;flex-shrink:0;"><?= $__stLbl ?></span>
        <a href="irs-detail.php?id=<?= $__r['id'] ?>" class="chl" style="font-size:11.5px;flex-shrink:0;">View &#8594;</a>
    </div>
    <?php endforeach; ?>
    <div style="padding:8px 14px;border-top:1px solid #f1f5f9;">
        <a href="irs-new.php" style="display:inline-block;background:#002850;color:#fff;padding:5px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">&#43; New Request</a>
    </div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:16px;">
    <div class="chd">
        <span class="cht">&#128196; My IRS Requests</span>
        <a href="irs-new.php" style="display:inline-block;background:#002850;color:#fff;padding:4px 12px;border-radius:6px;font-size:11.5px;font-weight:600;text-decoration:none;">&#43; New</a>
    </div>
    <div class="empty">No active requests — <a href="irs-new.php" style="color:#002850;font-weight:600;">Submit one</a></div>
</div>
<?php endif; ?>
