<?php
/**
 * Shared IRS dashboard widget — included by every dashboard file.
 * Expects: $user (array), $db (PDO), Auth class already loaded.
 *
 * Three sections, in order of urgency:
 *   1. Needs YOUR action as requester  — pushed back for corrections / rejected.
 *      This was missing entirely: when an approver returns a request the
 *      requester had no dashboard signal and the request simply stalled.
 *   2. Pending YOUR action as approver — derived from irs_flow_stages.actor_roles,
 *      so new stages (petty cash posting, payment verification) appear
 *      automatically without touching this file.
 *   3. Tracking — where each of my requests has got to, how far through the
 *      flow, who holds it now, and how long it has been sitting.
 */
if (!isset($user) || !isset($db)) return;
require_once __DIR__ . '/_widget_css.php';
if (!class_exists('IrsFlow')) {
    $__irsFlowPath = __DIR__ . '/../lib/IrsFlow.php';
    if (file_exists($__irsFlowPath)) require_once $__irsFlowPath; else return;
}

$_isAdmin = Auth::isAdmin($user);

// ── Flow map: stage order + label + owning roles, per request type ────────────
// One small query; used for the progress indicator and "who holds it now".
$_flowMap = [];   // [type][stage_code] = ['label'=>, 'rank'=>, 'roles'=>[]]
$_flowMax = [];   // [type] = number of forward steps in the flow
try {
    $__fs = $db->query("SELECT request_type, stage_code, stage_label, stage_order, actor_roles, is_terminal
                        FROM irs_flow_stages ORDER BY request_type, stage_order");
    foreach ($__fs->fetchAll(PDO::FETCH_ASSOC) as $__s) {
        $__t = $__s['request_type'];
        // Rank by POSITION, not by stage_order — requisition numbers its stages
        // 10/20/40/45/50/80, so a raw ratio would put Accounts Review at 13%
        // when it is really step 2 of 7.
        $__forward = !$__s['is_terminal']
                  && !in_array($__s['stage_code'], ['pending_corrections', 'draft'], true);
        $__rank = 0;
        if ($__forward) {
            $_flowMax[$__t] = ($_flowMax[$__t] ?? 0) + 1;
            $__rank = $_flowMax[$__t];
        }
        $_flowMap[$__t][$__s['stage_code']] = [
            'label' => $__s['stage_label'],
            'rank'  => $__rank,
            'roles' => json_decode($__s['actor_roles'] ?? '[]', true) ?: [],
        ];
    }
} catch (Throwable $_e) {}

// Guarded: dashboards include this with require (not require_once), so a second
// include would otherwise fatal on redeclaration.
if (!function_exists('_irsLbl')) {
    /** Stage label for a type, falling back to the code-level default. */
    function _irsLbl(array $flowMap, string $type, string $code): string {
        return $flowMap[$type][$code]['label'] ?? IrsFlow::defaultStageLabel($code);
    }
    /** How far through the flow, as a percentage of forward steps. */
    function _irsPct(array $flowMap, array $flowMax, string $type, string $code): int {
        if ($code === 'completed') return 100;
        $rank = $flowMap[$type][$code]['rank'] ?? 0;
        $max  = $flowMax[$type] ?? 0;
        if ($max <= 0 || $rank <= 0) return 4;   // corrections/draft — barely started
        return max(4, min(100, (int)round(($rank / $max) * 100)));
    }
    /** "Step 3 of 5" — clearer than a bar on its own. */
    function _irsStep(array $flowMap, array $flowMax, string $type, string $code): string {
        $rank = $flowMap[$type][$code]['rank'] ?? 0;
        $max  = $flowMax[$type] ?? 0;
        if ($rank <= 0 || $max <= 0) return '';
        return 'Step ' . $rank . ' of ' . $max;
    }
    /** Plain-English owner of the stage a request is sitting at. */
    function _irsOwner(array $flowMap, string $type, string $code): string {
        $roles = $flowMap[$type][$code]['roles'] ?? [];
        $names = [];
        foreach ($roles as $__rk) {
            if (in_array($__rk, ['head_it', 'it_admin'], true)) continue;   // admin catch-all
            $names[] = (defined('IRS_STAGE_OWNERS') && isset(IRS_STAGE_OWNERS[$__rk]))
                ? IRS_STAGE_OWNERS[$__rk]
                : (defined('ROLES') && isset(ROLES[$__rk]) ? ROLES[$__rk]['label'] : ucwords(str_replace('_', ' ', $__rk)));
        }
        $names = array_values(array_unique($names));
        return $names ? implode(' or ', array_slice($names, 0, 2)) : '';
    }
}

// Stages this user's role can act on
$_actorStages = [];
try {
    $__ps = $db->prepare("SELECT DISTINCT stage_code FROM irs_flow_stages WHERE JSON_CONTAINS(actor_roles, ?)");
    $__ps->execute([json_encode($user['role'])]);
    $_actorStages = $__ps->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $_e) {}

$_canApprove  = !empty($_actorStages) || $_isAdmin;
$_irsQueueCnt = 0;
$_irsQueue    = [];
$_myActive    = [];
$_myAction    = [];   // mine, waiting on ME to correct or resubmit

try {
    // ── Approver queue ────────────────────────────────────────────────────────
    if ($_canApprove) {
        $__sel = "r.id, r.ref_number, r.type, r.status, r.priority, r.amount, r.description,
                  u.name requester_name, r.created_at, DATEDIFF(NOW(), r.created_at) AS days_open";
        if ($_isAdmin) {
            $__cq = $db->query("SELECT COUNT(*) FROM irs_requests WHERE status NOT IN ('completed','rejected','draft','pending_corrections')");
            $_irsQueueCnt = (int)$__cq->fetchColumn();
            $__lq = $db->query("SELECT $__sel FROM irs_requests r JOIN users u ON u.id=r.requester_id
                WHERE r.status NOT IN ('completed','rejected','draft','pending_corrections')
                ORDER BY FIELD(r.priority,'urgent','normal','low'), r.created_at ASC LIMIT 6");
            $_irsQueue = $__lq->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $__ph = implode(',', array_fill(0, count($_actorStages), '?'));
            $__cq = $db->prepare("SELECT COUNT(*) FROM irs_requests WHERE status IN ($__ph)");
            $__cq->execute($_actorStages);
            $_irsQueueCnt = (int)$__cq->fetchColumn();
            $__lq = $db->prepare("SELECT $__sel FROM irs_requests r JOIN users u ON u.id=r.requester_id
                WHERE r.status IN ($__ph)
                ORDER BY FIELD(r.priority,'urgent','normal','low'), r.created_at ASC LIMIT 6");
            $__lq->execute($_actorStages);
            $_irsQueue = $__lq->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // ── Mine: in flight, split into "waiting on me" vs "with an approver" ─────
    $__mq = $db->prepare("SELECT id, ref_number, type, status, priority, amount, description, created_at,
            rejection_reason, DATEDIFF(NOW(), COALESCE(updated_at, created_at)) AS days_at_stage
        FROM irs_requests
        WHERE requester_id = ? AND status NOT IN ('completed','rejected')
        ORDER BY created_at DESC LIMIT 12");
    $__mq->execute([$user['id']]);
    foreach ($__mq->fetchAll(PDO::FETCH_ASSOC) as $__r) {
        if (in_array($__r['status'], ['pending_corrections', 'draft'], true)) $_myAction[] = $__r;
        else                                                                  $_myActive[] = $__r;
    }

    // Completed and rejected requests are deliberately NOT shown. The dashboard
    // is for what is still moving; closed items are history and live in irs.php.
} catch (Throwable $_e) { return; }

$__typeL = ['requisition'=>'Requisition','caution'=>'Caution Fee','payment'=>'Payment Req.','petty_cash'=>'Petty Cash','retirement'=>'Retirement'];
?>

<?php if (!empty($_myAction)): ?>
<!-- ── 1. Returned to me — I have to act ───────────────────────────────────── -->
<div class="hriw-card" style="margin-bottom:16px;border-left:4px solid #f59e0b;">
    <div class="hriw-hd">
        <span class="hriw-title">
            &#9888; Returned to You &mdash; Action Needed
            <span style="background:#f59e0b;color:#fff;font-size:10px;padding:2px 8px;border-radius:99px;margin-left:6px;font-weight:700;"><?= count($_myAction) ?></span>
        </span>
        <a href="irs.php" class="hriw-link">All &#8594;</a>
    </div>
    <?php foreach ($_myAction as $__r):
        $__isDraft = $__r['status'] === 'draft';
        $__age     = (int)($__r['days_at_stage'] ?? 0);
    ?>
    <div class="hriw-row" style="background:#fffbeb;">
        <div style="font-family:monospace;font-weight:700;color:#92400e;flex-shrink:0;font-size:12px;"><?= htmlspecialchars($__r['ref_number']) ?></div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(mb_substr($__r['description'], 0, 55)) ?></div>
            <?php if (!$__isDraft && !empty($__r['rejection_reason'])): ?>
            <div style="font-size:11px;color:#b45309;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <strong>Reason:</strong> <?= htmlspecialchars(mb_substr($__r['rejection_reason'], 0, 70)) ?>
            </div>
            <?php else: ?>
            <div style="font-size:11px;color:#92400e;margin-top:2px;"><?= $__isDraft ? 'Draft — not yet submitted' : 'Sent back for correction' ?></div>
            <?php endif; ?>
        </div>
        <?php if ($__age > 0): ?>
        <span style="font-size:11px;color:#b45309;font-weight:600;flex-shrink:0;"><?= $__age ?>d waiting</span>
        <?php endif; ?>
        <a href="irs-detail.php?id=<?= (int)$__r['id'] ?>" style="font-size:11.5px;font-weight:600;color:#fff;background:#d97706;padding:4px 11px;border-radius:4px;text-decoration:none;white-space:nowrap;flex-shrink:0;">
            <?= $__isDraft ? 'Finish' : 'Correct' ?> &#8594;
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($_canApprove): ?>
<!-- ── 2. Waiting on me as an approver ─────────────────────────────────────── -->
<div class="hriw-card" style="margin-bottom:16px;">
    <div class="hriw-hd" style="<?= $_irsQueueCnt > 0 ? 'border-left:4px solid #ef4444;' : '' ?>">
        <span class="hriw-title">
            &#9889; IRS &mdash; Pending My Action
            <?php if ($_irsQueueCnt > 0): ?>
            <span style="background:#ef4444;color:#fff;font-size:10px;padding:2px 8px;border-radius:99px;margin-left:6px;font-weight:700;"><?= $_irsQueueCnt ?></span>
            <?php endif; ?>
        </span>
        <a href="irs-approvals.php" class="hriw-link">Approvals &#8594;</a>
    </div>
    <?php if (empty($_irsQueue)): ?>
    <div class="hriw-empty">&#10003; No requests pending your action</div>
    <?php else: ?>
    <!-- hri-no-enhance: layout_shell.php bolts a search box, row counter and CSV
         button onto every table it finds. Useful on a full listing page, pure
         clutter on a six-row dashboard card. -->
    <div class="hriw-tbl-wrap">
        <table class="hriw-tbl hri-no-enhance">
            <thead><tr>
                <th>Ref</th>
                <th>Requester</th>
                <th>Type</th>
                <th class="hriw-num">Amount</th>
                <th style="text-align:center;">Stage</th>
                <th style="text-align:center;">Age</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php
            $__priCol = ['urgent'=>['#fee2e2','#dc2626'],'normal'=>['#f0fdf4','#166534'],'low'=>['#f8fafc','#64748b']];
            foreach ($_irsQueue as $__r):
                $__stLbl = _irsLbl($_flowMap, $__r['type'], $__r['status']);
                $__stCol = IrsFlow::stageColor($__r['status']);
                $__days  = (int)($__r['days_open'] ?? 0);
                $__dCol  = $__days >= 5 ? '#ef4444' : ($__days >= 3 ? '#f59e0b' : '#64748b');
                $__pri   = $__priCol[$__r['priority']] ?? ['#f8fafc','#64748b'];
            ?>
            <tr>
                <td style="font-family:monospace;font-weight:700;color:#002850;white-space:nowrap;">
                    <span class="hriw-tag" style="background:<?= $__pri[0] ?>;color:<?= $__pri[1] ?>;font-family:inherit;"><?= htmlspecialchars($__r['priority']) ?></span>
                    <?= htmlspecialchars($__r['ref_number']) ?>
                </td>
                <td style="color:#334155;"><?= htmlspecialchars($__r['requester_name']) ?></td>
                <td><span class="hriw-chip" style="background:#e0f2fe;color:#0369a1;"><?= $__typeL[$__r['type']] ?? htmlspecialchars($__r['type']) ?></span></td>
                <td class="hriw-num" style="font-weight:600;">&#8358;<?= number_format((float)$__r['amount']) ?></td>
                <td style="text-align:center;"><span class="hriw-chip" style="background:<?= $__stCol ?>20;color:<?= $__stCol ?>;"><?= htmlspecialchars($__stLbl) ?></span></td>
                <td style="text-align:center;font-weight:600;color:<?= $__dCol ?>;white-space:nowrap;"><?= $__days ?>d</td>
                <td style="text-align:right;"><a href="irs-detail.php?id=<?= (int)$__r['id'] ?>" class="hriw-act">Action &#8594;</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($_irsQueueCnt > 6): ?>
    <div style="padding:7px 12px;border-top:1px solid #f1f5f9;text-align:right;font-size:11.5px;">
        <a href="irs-approvals.php" style="color:#002850;font-weight:600;">+<?= $_irsQueueCnt - 6 ?> more &rarr;</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── 3. Tracking: where my requests have got to ──────────────────────────── -->
<div class="hriw-card" style="margin-bottom:16px;">
    <div class="hriw-hd">
        <span class="hriw-title">&#128196; My Requests</span>
        <a href="irs-new.php" style="display:inline-block;background:#002850;color:#fff;padding:4px 12px;border-radius:6px;font-size:11.5px;font-weight:600;text-decoration:none;">&#43; New</a>
    </div>

    <?php if (empty($_myActive)): ?>
    <div class="hriw-empty">No requests yet &mdash; <a href="irs-new.php" style="color:#002850;font-weight:600;">submit one</a></div>
    <?php else: ?>

    <?php foreach ($_myActive as $__r):
        $__stLbl = _irsLbl($_flowMap, $__r['type'], $__r['status']);
        $__stCol = IrsFlow::stageColor($__r['status']);
        $__pct   = _irsPct($_flowMap, $_flowMax, $__r['type'], $__r['status']);
        $__owner = _irsOwner($_flowMap, $__r['type'], $__r['status']);
        $__age   = (int)($__r['days_at_stage'] ?? 0);
    ?>
    <div style="padding:9px 14px;border-bottom:1px solid #f1f5f9;">
        <div style="display:flex;align-items:center;gap:9px;">
            <span style="font-family:monospace;font-weight:700;color:#002850;font-size:12px;flex-shrink:0;"><?= htmlspecialchars($__r['ref_number']) ?></span>
            <span style="flex:1;min-width:0;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(mb_substr($__r['description'], 0, 50)) ?></span>
            <span style="background:<?= $__stCol ?>20;color:<?= $__stCol ?>;font-size:10px;padding:2px 7px;border-radius:99px;font-weight:600;white-space:nowrap;flex-shrink:0;"><?= htmlspecialchars($__stLbl) ?></span>
            <a href="irs-detail.php?id=<?= (int)$__r['id'] ?>" class="hriw-link" style="font-size:11.5px;flex-shrink:0;">View &#8594;</a>
        </div>
        <!-- progress through the flow -->
        <div style="height:4px;background:#f1f5f9;border-radius:2px;margin-top:6px;overflow:hidden;">
            <div style="height:100%;width:<?= $__pct ?>%;background:<?= $__stCol ?>;border-radius:2px;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;gap:8px;margin-top:3px;font-size:10.5px;color:#94a3b8;">
            <span>
                <?php $__step = _irsStep($_flowMap, $_flowMax, $__r['type'], $__r['status']); ?>
                <?php if ($__step !== ''): ?><strong style="color:#64748b;"><?= $__step ?></strong> &bull; <?php endif; ?>
                <?= $__typeL[$__r['type']] ?? htmlspecialchars($__r['type']) ?>
                &bull; &#8358;<?= number_format((float)$__r['amount']) ?>
                <?php if ($__owner !== ''): ?>&bull; with <?= htmlspecialchars($__owner) ?><?php endif; ?>
            </span>
            <span><?= $__age > 0 ? $__age . 'd at this stage' : 'moved today' ?></span>
        </div>
    </div>
    <?php endforeach; ?>

    <div style="padding:7px 14px;text-align:right;">
        <a href="irs.php" style="font-size:11.5px;color:#002850;font-weight:600;">All my requests &#8594;</a>
    </div>
    <?php endif; ?>
</div>
