<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';
require_once __DIR__ . '/lib/IrsFlow.php';

$user = Auth::require();
$db   = getDB();

$isAdmin    = Auth::isAdmin($user);
$canViewAll = IrsFlow::canViewAll($user);

// ── Actor stages for the current user ────────────────────────────────────────
$actorStages  = [];
$pendingActions = 0;
$myActionIds  = [];
try {
    $ps = $db->prepare("SELECT DISTINCT stage_code FROM irs_flow_stages WHERE JSON_CONTAINS(actor_roles, ?)");
    $ps->execute([json_encode($user['role'])]);
    $actorStages = $ps->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($actorStages)) {
        $ph = implode(',', array_fill(0, count($actorStages), '?'));
        $pc = $db->prepare("SELECT id FROM irs_requests WHERE status IN ($ph)");
        $pc->execute($actorStages);
        $myActionIds    = $pc->fetchAll(PDO::FETCH_COLUMN);
        $pendingActions = count($myActionIds);
    }
} catch(Exception $e) {}

// ── Stats ─────────────────────────────────────────────────────────────────────
$myStats = ['total'=>0,'active'=>0,'completed'=>0,'rejected'=>0];
try {
    $sq = $db->prepare("SELECT COUNT(*) total,
        SUM(status NOT IN ('completed','rejected','draft')) active,
        SUM(status='completed') completed,
        SUM(status='rejected') rejected
        FROM irs_requests WHERE requester_id=?");
    $sq->execute([$user['id']]);
    $myStats = $sq->fetch(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$pipelineValue = 0; $urgentCount = 0; $completedThisMonth = 0;
if ($canViewAll) {
    try {
        $pvq = $db->query("SELECT
            COALESCE(SUM(CASE WHEN status NOT IN ('completed','rejected','draft') THEN amount END),0),
            COALESCE(SUM(CASE WHEN status NOT IN ('completed','rejected','draft') AND priority='urgent' THEN 1 END),0),
            COALESCE(SUM(CASE WHEN status='completed' AND created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY) THEN 1 END),0)
            FROM irs_requests");
        $pvRow = $pvq->fetch(PDO::FETCH_NUM);
        $pipelineValue      = (float)$pvRow[0];
        $urgentCount        = (int)$pvRow[1];
        $completedThisMonth = (int)$pvRow[2];
    } catch(Exception $e) {}
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';
$filterView   = $_GET['view']   ?? ($canViewAll ? 'all' : 'mine');
$viewAll      = $canViewAll && $filterView === 'all';
$isFiltered   = $filterStatus !== '' || $filterType !== '';
$filterMyAction = !empty($_GET['my_action']);
$showClosed   = !empty($_GET['closed']);
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

// ── Main query — enhanced with stage join, age, last audit ───────────────────
$typeLabels = ['requisition'=>'Requisition','caution'=>'Caution Payment','payment'=>'Payment Request','petty_cash'=>'Petty Cash','retirement'=>'Retirement'];

$where  = $viewAll ? '1=1' : 'r.requester_id=?';
$params = $viewAll ? [] : [$user['id']];
if ($filterStatus !== '') { $where .= ' AND r.status=?';  $params[] = $filterStatus; }
if ($filterType   !== '') { $where .= ' AND r.type=?';    $params[] = $filterType; }
if ($filterMyAction && !empty($myActionIds)) {
    $mph = implode(',', array_fill(0, count($myActionIds), '?'));
    $where .= " AND r.id IN ($mph)";
    $params = array_merge($params, $myActionIds);
} elseif ($filterMyAction) {
    $where .= ' AND 1=0'; // no action items, return empty
}
// Default: hide completed/rejected unless user explicitly requests history
if (!$showClosed && !in_array($filterStatus, ['completed', 'rejected'])) {
    $where .= " AND r.status NOT IN ('completed','rejected')";
}

$total = 0; $requests = [];
try {
    $cs = $db->prepare("SELECT COUNT(*) FROM irs_requests r WHERE $where");
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    $ls = $db->prepare("SELECT r.*, u.name requester_name, u.department requester_dept,
        fs.stage_label AS fs_stage_label, fs.actor_roles AS fs_actor_roles,
        DATEDIFF(NOW(), COALESCE(r.updated_at, r.created_at)) AS days_at_stage,
        (SELECT CONCAT(au.name,' — ',al.action) FROM irs_audit_log al
         LEFT JOIN users au ON au.id=al.user_id
         WHERE al.request_id=r.id ORDER BY al.created_at DESC LIMIT 1) AS last_audit
        FROM irs_requests r
        JOIN users u ON u.id=r.requester_id
        LEFT JOIN irs_flow_stages fs ON fs.request_type=r.type AND fs.stage_code=r.status
        WHERE $where
        ORDER BY FIELD(r.priority,'urgent','normal','low'), r.created_at DESC
        LIMIT $perPage OFFSET $offset");
    $ls->execute($params);
    $requests = $ls->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$totalPages = max(1, (int)ceil($total / $perPage));

// Sort: my-action rows first, then by priority + date
usort($requests, function($a, $b) use ($myActionIds) {
    $aAct = in_array($a['id'], $myActionIds) ? 0 : 1;
    $bAct = in_array($b['id'], $myActionIds) ? 0 : 1;
    if ($aAct !== $bAct) return $aAct - $bAct;
    $priOrder = ['urgent'=>0,'normal'=>1,'low'=>2];
    $ap = $priOrder[$a['priority']] ?? 1;
    $bp = $priOrder[$b['priority']] ?? 1;
    if ($ap !== $bp) return $ap - $bp;
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Pre-fetch transitions for quick-action panel (keyed by [type][stage])
$actorTransitions  = [];
$complexActionCodes = ['raise_payment', 'post', 'return_payment'];
if (!empty($actorStages)) {
    try {
        $ph2 = implode(',', array_fill(0, count($actorStages), '?'));
        $trq = $db->prepare(
            "SELECT t.request_type, t.from_stage, t.action_code, t.action_label,
                    COALESCE(t.requires_note, 0) AS requires_note
             FROM irs_flow_transitions t
             JOIN irs_flow_stages s ON s.request_type=t.request_type AND s.stage_code=t.from_stage
             WHERE t.from_stage IN ($ph2)
               AND JSON_CONTAINS(s.actor_roles, ?)
             ORDER BY t.sort_order ASC"
        );
        $trq->execute(array_merge($actorStages, [json_encode($user['role'])]));
        foreach ($trq->fetchAll(PDO::FETCH_ASSOC) as $tr) {
            $actorTransitions[$tr['request_type']][$tr['from_stage']][] = $tr;
        }
    } catch(Exception $e) {}
}

// Helper: build actor label from JSON
function irsActorLabel(string $rolesJson): string {
    $roles = json_decode($rolesJson, true) ?: [];
    if (empty($roles)) return '';
    $labels = array_map(function($r) { return ROLES[$r]['label'] ?? $r; }, $roles);
    return implode(' / ', $labels);
}

Layout::shell($user, 'irs', 0, 'Internal Requests');
?>
<style>
/* ── IRS Table ───────────────────────────────────────────────────── */
.irs-tbl { width:100%; border-collapse:collapse; font-size:.86rem; }
.irs-tbl th { padding:.6rem .85rem; text-align:left; font-weight:600; color:#64748b; font-size:.78rem;
    background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
.irs-tbl td { padding:.65rem .85rem; vertical-align:middle; }
.irs-tbl tbody tr { border-bottom:1px solid #f1f5f9; }
.irs-tbl tbody tr:hover td { background:#f8fafc; }
.irs-tbl tbody tr:hover .irs-col-act { background:#f8fafc; }
/* amber left-border on my-action rows */
.irs-mine td:first-child { border-left:3px solid #f59e0b; padding-left:.65rem; }
.irs-mine td { background:#fffdf5; }
.irs-mine:hover td, .irs-mine:hover .irs-col-act { background:#fff8e6 !important; }
/* sticky actions column */
.irs-col-act {
    position:sticky; right:0; background:#fff;
    box-shadow:-3px 0 6px rgba(0,0,0,.06);
    white-space:nowrap; text-align:right;
}
.irs-mine .irs-col-act { background:#fffdf5; }
/* age colours */
.age-ok   { color:#94a3b8; font-size:.72rem; }
.age-warn { color:#f59e0b; font-weight:600; font-size:.72rem; }
.age-bad  { color:#ef4444; font-weight:700; font-size:.72rem; }
/* inline action buttons */
.qa-ok-btn { background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:.28rem .65rem;
    border-radius:.35rem;cursor:pointer;font-size:.78rem;font-weight:600; }
.qa-ng-btn { background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;padding:.28rem .65rem;
    border-radius:.35rem;cursor:pointer;font-size:.78rem;font-weight:600; }
/* floating confirm popup */
.qa-popup { position:fixed;background:#fff;border:1px solid #e2e8f0;border-radius:.6rem;
    padding:.85rem 1rem;box-shadow:0 8px 28px rgba(0,0,0,.14);z-index:1200;
    width:300px;display:none; }
.qa-popup-lbl { font-size:.78rem;font-weight:700;color:#334155;margin-bottom:.45rem; }
</style>

<div class="hri-page">
  <div class="hri-page-hd">
    <h1 class="hri-page-title">&#128196; Internal Request System</h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
      <?php if ($pendingActions > 0): ?>
      <a href="irs.php?view=all&my_action=1" class="hri-btn" style="background:#ef4444;color:#fff;">
        &#9889; <?= $pendingActions ?> Pending My Action
      </a>
      <?php endif; ?>
      <?php
      $_exportQs = 'view='.($viewAll?'all':'mine').($filterType?'&type='.urlencode($filterType):'').($filterStatus?'&status='.urlencode($filterStatus):'').($showClosed?'&closed=1':'');
      ?>
      <a href="irs-export.php?<?= $_exportQs ?>" class="hri-btn hri-btn-outline" style="font-size:.85rem;">&#8681; Export CSV</a>
      <a href="irs-new.php" class="hri-btn hri-btn-navy">&#43; New Request</a>
    </div>
  </div>

  <!-- ── F: Summary stat bar ──────────────────────────────────────────────── -->
  <?php if ($canViewAll): ?>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;">
    <div class="hri-card" style="text-align:center;padding:1.25rem;">
      <div style="font-size:1.5rem;font-weight:700;color:var(--navy);">&#8358;<?= number_format($pipelineValue) ?></div>
      <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">Total Pipeline Value</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1.25rem;<?= $pendingActions > 0 ? 'border-left:3px solid #ef4444;' : '' ?>">
      <div style="font-size:1.5rem;font-weight:700;color:<?= $pendingActions > 0 ? '#ef4444' : '#64748b' ?>;"><?= $pendingActions ?></div>
      <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">Pending My Action</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1.25rem;<?= $urgentCount > 0 ? 'border-left:3px solid #f59e0b;' : '' ?>">
      <div style="font-size:1.5rem;font-weight:700;color:<?= $urgentCount > 0 ? '#dc2626' : '#64748b' ?>;"><?= $urgentCount ?></div>
      <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">Urgent In Pipeline</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1.25rem;">
      <div style="font-size:1.5rem;font-weight:700;color:#059669;"><?= $completedThisMonth ?></div>
      <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">Completed This Month</div>
    </div>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;">
    <div class="hri-card" style="text-align:center;padding:1.25rem;">
      <div style="font-size:1.5rem;font-weight:700;color:var(--navy);"><?= (int)$myStats['total'] ?></div>
      <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">My Total Requests</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1.25rem;">
      <div style="font-size:1.5rem;font-weight:700;color:#f59e0b;"><?= (int)$myStats['active'] ?></div>
      <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">In Progress</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1.25rem;">
      <div style="font-size:1.5rem;font-weight:700;color:#059669;"><?= (int)$myStats['completed'] ?></div>
      <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">Completed</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1.25rem;">
      <div style="font-size:1.5rem;font-weight:700;color:#ef4444;"><?= (int)$myStats['rejected'] ?></div>
      <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">Declined</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="hri-card">
    <!-- Filters row -->
    <div class="hri-card-hd" style="flex-wrap:wrap;gap:.75rem;padding-bottom:.875rem;">
      <h2 class="hri-card-title" style="flex:1;min-width:160px;">
        <?php if ($filterMyAction): ?>&#9889; Pending My Action
        <?php elseif ($showClosed): ?>&#128065; Request History
        <?php elseif ($viewAll): ?>Active Requests
        <?php else: ?>My Active Requests
        <?php endif; ?>
        <?php if ($total): ?><span style="font-size:.85rem;font-weight:400;color:#64748b;">(<?= $total ?>)</span><?php endif; ?>
      </h2>
      <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        <?php if ($canViewAll): ?>
        <select name="view" class="hri-select" style="font-size:.8rem;padding:.35rem .6rem;" onchange="this.form.submit()">
          <option value="mine" <?= !$viewAll ? 'selected' : '' ?>>My Requests</option>
          <option value="all"  <?= $viewAll  ? 'selected' : '' ?>>All Requests</option>
        </select>
        <?php endif; ?>
        <select name="type" class="hri-select" style="font-size:.8rem;padding:.35rem .6rem;" onchange="this.form.submit()">
          <option value="">All Types</option>
          <?php foreach ($typeLabels as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $filterType===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" class="hri-select" style="font-size:.8rem;padding:.35rem .6rem;" onchange="this.form.submit()">
          <option value="">Active Only</option>
          <?php foreach (IrsFlow::allStageCodes() as $code): ?>
          <?php if (!$showClosed && in_array($code, ['completed','rejected'])) continue; ?>
          <option value="<?= $code ?>" <?= $filterStatus===$code ? 'selected' : '' ?>><?= IrsFlow::defaultStageLabel($code) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($showClosed): ?><input type="hidden" name="closed" value="1"><?php endif; ?>
        <?php
        $_qs = 'view='.($viewAll?'all':'mine').($filterType?'&type='.urlencode($filterType):'').($filterStatus?'&status='.urlencode($filterStatus):'');
        ?>
        <?php if ($showClosed): ?>
        <a href="irs.php?<?= $_qs ?>" class="hri-btn" style="font-size:.8rem;padding:.3rem .7rem;background:#059669;color:#fff;text-decoration:none;">&#10003; Showing History</a>
        <?php else: ?>
        <a href="irs.php?<?= $_qs ?>&closed=1" class="hri-btn hri-btn-outline" style="font-size:.8rem;padding:.3rem .7rem;text-decoration:none;">&#128065; Show History</a>
        <?php endif; ?>
        <?php if ($filterMyAction || $isFiltered): ?><a href="irs.php?view=<?= $viewAll?'all':'mine' ?><?= $showClosed?'&closed=1':'' ?>" class="hri-btn hri-btn-outline" style="font-size:.8rem;padding:.3rem .6rem;">&#10005; Clear Filters</a><?php endif; ?>
      </form>
    </div>

    <!-- A: Pending action banner -->
    <?php if ($pendingActions > 0 && !$filterMyAction): ?>
    <div style="margin:0 -1px;padding:.6rem 1rem;background:#fffbeb;border-top:1px solid #fcd34d;border-bottom:1px solid #fcd34d;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:.6rem;">
        <span style="font-size:1.1rem;">&#9889;</span>
        <span style="font-size:.875rem;font-weight:600;color:#92400e;">
          <?= $pendingActions ?> request<?= $pendingActions > 1 ? 's' : '' ?> require<?= $pendingActions === 1 ? 's' : '' ?> your action
        </span>
      </div>
      <a href="irs.php?view=all&amp;my_action=1" style="font-size:.8rem;font-weight:700;background:#f59e0b;color:#fff;padding:.3rem .9rem;border-radius:.35rem;text-decoration:none;white-space:nowrap;">Show Mine Only &#8594;</a>
    </div>
    <?php endif; ?>

    <?php if (empty($requests)): ?>
    <div class="hri-empty" style="padding:3rem;">
      <div style="font-size:2.5rem;margin-bottom:.75rem;">&#128196;</div>
      <div><?= $filterMyAction ? 'No requests currently need your action.' : 'No requests found.' ?></div>
      <a href="irs-new.php" class="hri-btn hri-btn-navy" style="margin-top:1rem;">Submit New Request</a>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="irs-tbl">
      <thead>
        <tr>
          <th>Ref</th>
          <th>Type</th>
          <?php if ($viewAll): ?><th>Requester</th><?php endif; ?>
          <th>Description</th>
          <th style="text-align:right;">Amount</th>
          <th>Stage</th>
          <th class="irs-col-act">Actions</th>
        </tr>
      </thead>
      <tbody>
    <?php foreach ($requests as $r):
      $rid        = $r['id'];
      $st         = $r['status'];
      $stCol      = IrsFlow::stageColor($st);
      $stLbl      = IrsFlow::defaultStageLabel($st);
      $isMyAction = in_array($rid, $myActionIds);
      $days       = (int)($r['days_at_stage'] ?? 0);
      $ageCls     = $days >= 5 ? 'age-bad' : ($days >= 3 ? 'age-warn' : 'age-ok');
      $ageStr     = $days === 0 ? 'Today' : ($days === 1 ? '1d' : $days.'d');
      $actorJson  = $r['fs_actor_roles'] ?? '[]';
      $actorLbl   = IrsFlow::isTerminal($st) ? '' : irsActorLabel($actorJson);
      $typeL      = $typeLabels[$r['type']] ?? $r['type'];
      $desc       = htmlspecialchars(mb_strimwidth($r['description'] ?? '', 0, 80, '…'));
      $rowTrans   = $actorTransitions[$r['type']][$r['status']] ?? [];
      $simpleActs = array_values(array_filter($rowTrans, function($t) use ($complexActionCodes) {
          return !in_array($t['action_code'], $complexActionCodes);
      }));
      $hasComplex = !empty(array_filter($rowTrans, function($t) use ($complexActionCodes) {
          return in_array($t['action_code'], $complexActionCodes);
      }));
    ?>
      <tr class="<?= $isMyAction ? 'irs-mine' : '' ?>">
        <td>
          <span style="font-family:monospace;font-size:.8rem;font-weight:700;color:#002850;"><?= htmlspecialchars($r['ref_number']) ?></span><br>
          <span style="font-size:.71rem;color:#94a3b8;"><?= date('d M', strtotime($r['created_at'])) ?></span>
        </td>
        <td>
          <span style="font-size:.78rem;font-weight:600;color:#0369a1;background:#e0f2fe;padding:.15rem .45rem;border-radius:9999px;white-space:nowrap;"><?= $typeL ?></span><br>
          <?php $priColors = ['urgent'=>['#fee2e2','#dc2626'],'normal'=>['#f0fdf4','#166534'],'low'=>['#f1f5f9','#64748b']]; $pc = $priColors[$r['priority']] ?? ['#f1f5f9','#64748b']; ?>
          <span style="font-size:.67rem;font-weight:700;text-transform:uppercase;background:<?= $pc[0] ?>;color:<?= $pc[1] ?>;padding:.1rem .35rem;border-radius:.2rem;white-space:nowrap;"><?= $r['priority'] ?></span>
        </td>
        <?php if ($viewAll): ?>
        <td>
          <span style="font-size:.84rem;font-weight:500;"><?= htmlspecialchars($r['requester_name']) ?></span><br>
          <span style="font-size:.72rem;color:#94a3b8;"><?= htmlspecialchars($r['requester_dept'] ?? '') ?></span>
        </td>
        <?php endif; ?>
        <td style="max-width:240px;color:#475569;"><?= $desc ?></td>
        <td style="text-align:right;white-space:nowrap;">
          <span style="font-weight:700;color:#002850;">&#8358;<?= number_format((float)$r['amount']) ?></span>
        </td>
        <td style="white-space:nowrap;">
          <span style="font-size:.75rem;padding:.2rem .55rem;border-radius:9999px;font-weight:600;background:<?= $stCol ?>20;color:<?= $stCol ?>;"><?= htmlspecialchars($stLbl) ?></span><br>
          <?php if ($actorLbl): ?><span style="font-size:.71rem;color:#94a3b8;">&#8594; <?= htmlspecialchars($actorLbl) ?></span><?php endif; ?>
          <span class="<?= $ageCls ?>"><?= $ageStr ?></span>
        </td>
        <td class="irs-col-act">
          <?php if ($isMyAction && !empty($simpleActs)):
            foreach ($simpleActs as $qa):
              $isNeg = strpos($qa['action_code'],'reject')!==false || strpos($qa['action_code'],'ineligible')!==false;
          ?>
          <button class="<?= $isNeg ? 'qa-ng-btn' : 'qa-ok-btn' ?>"
            onclick="showQa(this,<?= $rid ?>,<?= json_encode($qa['action_code']) ?>,<?= json_encode($qa['action_label']) ?>,<?= (int)$qa['requires_note'] ?>)">
            <?= $isNeg ? '&#10007;' : '&#10003;' ?> <?= htmlspecialchars($qa['action_label']) ?>
          </button>
          <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($isMyAction && ($hasComplex || empty($simpleActs))): ?>
          <a href="irs-detail.php?id=<?= $rid ?>" class="hri-btn hri-btn-navy" style="font-size:.78rem;padding:.28rem .7rem;text-decoration:none;white-space:nowrap;display:inline-block;">&#9889; Open</a>
          <?php else: ?>
          <a href="irs-detail.php?id=<?= $rid ?>" style="font-size:.78rem;color:#94a3b8;text-decoration:none;white-space:nowrap;">View &#8594;</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:.5rem;align-items:center;padding:1rem;justify-content:center;flex-wrap:wrap;">
      <?php $_pgBase = 'status='.urlencode($filterStatus).'&type='.urlencode($filterType).'&view='.($viewAll?'all':'mine').($filterMyAction?'&my_action=1':'').($showClosed?'&closed=1':''); ?>
      <?php if ($page > 1): ?>
      <a href="?page=<?= $page-1 ?>&<?= $_pgBase ?>" class="hri-btn hri-btn-outline" style="font-size:.8rem;padding:.3rem .7rem;">&laquo; Prev</a>
      <?php endif; ?>
      <span style="font-size:.85rem;color:#64748b;">Page <?= $page ?> of <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
      <a href="?page=<?= $page+1 ?>&<?= $_pgBase ?>" class="hri-btn hri-btn-outline" style="font-size:.8rem;padding:.3rem .7rem;">Next &raquo;</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<!-- Floating confirm popup -->
<div id="irsQaPopup" class="qa-popup">
  <div class="qa-popup-lbl" id="irsQaLbl"></div>
  <input id="irsQaInp" class="hri-input" placeholder="Comment (optional)…" style="font-size:.82rem;padding:.32rem .65rem;width:100%;">
  <div style="display:flex;gap:.5rem;margin-top:.5rem;">
    <button id="irsQaSub" onclick="submitQa()" class="hri-btn hri-btn-navy" style="font-size:.82rem;padding:.3rem .9rem;">Confirm</button>
    <button onclick="closeQa()" class="hri-btn hri-btn-outline" style="font-size:.82rem;padding:.3rem .75rem;">Cancel</button>
  </div>
</div>

<script>
(function() {
    var _state = {};

    window.showQa = function(btn, id, code, label, reqNote) {
        _state = { id: id, code: code, requiresNote: !!reqNote };
        document.getElementById('irsQaLbl').textContent = 'Confirm: ' + label;
        document.getElementById('irsQaInp').value = '';
        document.getElementById('irsQaSub').disabled = false;
        document.getElementById('irsQaSub').textContent = 'Confirm';
        var popup = document.getElementById('irsQaPopup');
        popup.style.display = 'block';
        var rect = btn.getBoundingClientRect();
        var left = Math.min(rect.left, window.innerWidth - 320);
        popup.style.left = Math.max(8, left) + 'px';
        popup.style.top  = (rect.bottom + window.scrollY + 6) + 'px';
        setTimeout(function() { document.getElementById('irsQaInp').focus(); }, 40);
        setTimeout(function() { document.addEventListener('click', _outsideClick, true); }, 10);
    };

    function _outsideClick(e) {
        var popup = document.getElementById('irsQaPopup');
        if (popup && !popup.contains(e.target)) closeQa();
    }

    window.closeQa = function() {
        document.getElementById('irsQaPopup').style.display = 'none';
        document.removeEventListener('click', _outsideClick, true);
    };

    window.submitQa = function() {
        var comment = document.getElementById('irsQaInp').value.trim();
        if (_state.requiresNote && !comment) { alert('A comment is required for this action.'); return; }
        var btn = document.getElementById('irsQaSub');
        btn.disabled = true; btn.textContent = 'Saving…';
        var fd = new FormData();
        fd.append('action', _state.code);
        fd.append('id', _state.id);
        fd.append('comment', comment);
        fetch('api/irs/action.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.CSRF_TOKEN },
            body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.ok) {
                btn.disabled = false; btn.textContent = 'Confirm';
                alert(d.error || 'Action failed. Please try again.');
                return;
            }
            location.reload();
        })
        .catch(function() {
            btn.disabled = false; btn.textContent = 'Confirm';
            alert('Network error. Please try again.');
        });
    };
})();
</script>
<?php Layout::end(); ?>
