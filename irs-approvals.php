<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$irsCfg = function_exists('getIrsConfig') ? getIrsConfig() : ['accounts_roles'=>defined('IRS_ACCOUNTS_ROLES')?IRS_ACCOUNTS_ROLES:['head_accounts','accountant'],'manager_roles'=>defined('IRS_MANAGER_ROLES')?IRS_MANAGER_ROLES:['md','bdm','head_outsourcing','hr'],'petty_cash_limit'=>defined('IRS_PETTY_CASH_LIMIT')?IRS_PETTY_CASH_LIMIT:50000,'notify_enabled'=>true];
$isAccountsTeam = in_array($user['role'], $irsCfg['accounts_roles']);
$isManager      = in_array($user['role'], $irsCfg['manager_roles']);
$isAdmin        = Auth::isAdmin($user);

if (!$isAccountsTeam && !$isManager && !$isAdmin) {
    header('Location: irs.php');
    exit;
}

// Build status filter based on role (uses current DB stage codes)
$myStatuses = [];
if ($isAccountsTeam || $isAdmin) {
    $myStatuses = array_merge($myStatuses, [
        'pending_hod_accounts', 'pending_eligibility', 'pending_accountant',
        'pending_payment', 'pending_hod_accounts_payment',
    ]);
}
if ($isManager || $isAdmin) {
    $myStatuses[] = 'pending_md';
    $myStatuses[] = 'pending_payment_approval';
}
$myStatuses = array_unique($myStatuses);

$filterType   = $_GET['type']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$ph    = implode(',', array_fill(0, count($myStatuses), '?'));
$where = "WHERE r.status IN ($ph)";
$params = $myStatuses;

if ($filterType !== '')   { $where .= ' AND r.type=?';   $params[] = $filterType; }
if ($filterStatus !== '') { $where .= ' AND r.status=?'; $params[] = $filterStatus; }

$total = 0; $requests = [];
try {
    $cs = $db->prepare("SELECT COUNT(*) FROM irs_requests r $where");
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    $ls = $db->prepare("SELECT r.*, u.name requester_name, u.department requester_dept
        FROM irs_requests r JOIN users u ON u.id=r.requester_id
        $where ORDER BY
          CASE r.priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
          r.created_at ASC
        LIMIT $perPage OFFSET $offset");
    $ls->execute($params);
    $requests = $ls->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$totalPages = max(1, (int)ceil($total / $perPage));

$typeLabels = ['requisition'=>'Requisition','caution'=>'Caution Payment','payment'=>'Payment Request','petty_cash'=>'Petty Cash','retirement'=>'Retirement'];
$statusLabels = [
    'pending_eligibility'=>'Pending Eligibility','pending_custodian'=>'Pending Custodian',
    'pending_accounts'=>'Pending Accounts','accounts_approved'=>'Awaiting Manager',
    'pending_payment'=>'Payment to Process','approved'=>'Awaiting Final Paid',
    'paid'=>'Ready to Post','disbursed'=>'Ready to Post',
    'refund_required'=>'Awaiting Refund Proof',
    'additional_payment'=>'Additional Payment Required',
];
$statusColors = [
    'pending_eligibility'=>'#f59e0b','pending_custodian'=>'#f59e0b','pending_accounts'=>'#f59e0b',
    'accounts_approved'=>'#3b82f6','pending_payment'=>'#8b5cf6',
    'approved'=>'#10b981','paid'=>'#0891b2','disbursed'=>'#0891b2',
    'refund_required'=>'#8b5cf6','additional_payment'=>'#6366f1',
];

// Group by stage label
$stageLabels = [
    'pending_eligibility'=>'Eligibility Review', 'pending_custodian'=>'Custodian Approval',
    'pending_accounts'=>'Accounts Review','accounts_approved'=>'Manager Approval',
    'pending_payment'=>'Payment Processing','approved'=>'Final Payment Confirmation',
    'paid'=>'Posting','disbursed'=>'Posting',
    'refund_required'=>'Refund Confirmation','additional_payment'=>'Additional Payment',
];

Layout::shell($user, 'irs', 0, 'IRS Approvals Queue');
?>
<div class="hri-page">
  <div class="hri-page-hd">
    <h1 class="hri-page-title">&#9889; IRS Approvals Queue</h1>
    <div style="display:flex;gap:.5rem;">
      <a href="irs.php" class="hri-btn hri-btn-outline">&#128196; All Requests</a>
      <a href="irs-new.php" class="hri-btn hri-btn-navy">&#43; New Request</a>
    </div>
  </div>

  <?php if ($total === 0): ?>
  <div class="hri-card">
    <div class="hri-empty" style="padding:3rem;">
      <div style="font-size:2.5rem;margin-bottom:.75rem;">&#9989;</div>
      <div style="font-size:1.1rem;font-weight:600;color:var(--navy);">All clear — no pending actions.</div>
      <div style="color:#64748b;margin-top:.4rem;">You have no requests awaiting your review.</div>
    </div>
  </div>
  <?php else: ?>

  <div class="hri-card" style="margin-bottom:1.25rem;">
    <div class="hri-card-hd" style="flex-wrap:wrap;gap:.75rem;">
      <h2 class="hri-card-title">
        <?= $total ?> Request<?= $total > 1 ? 's' : '' ?> Pending Action
      </h2>
      <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        <select name="type" class="hri-select" style="font-size:.8rem;padding:.35rem .6rem;" onchange="this.form.submit()">
          <option value="">All Types</option>
          <?php foreach ($typeLabels as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $filterType===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" class="hri-select" style="font-size:.8rem;padding:.35rem .6rem;" onchange="this.form.submit()">
          <option value="">All Stages</option>
          <?php foreach ($stageLabels as $k=>$v): if (in_array($k,$myStatuses)): ?>
          <option value="<?= $k ?>" <?= $filterStatus===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endif; endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <div style="display:grid;gap:.75rem;">
    <?php foreach ($requests as $r):
      $st    = $r['status'];
      $stCol = $statusColors[$st] ?? '#94a3b8';
      $stLbl = $statusLabels[$st] ?? $st;
      $priColor = ['urgent'=>'#ef4444','normal'=>'#64748b','low'=>'#94a3b8'][$r['priority']] ?? '#64748b';
      $urgentBg = $r['priority'] === 'urgent' ? 'border-left:3px solid #ef4444;' : '';
      $daysAgo = max(0, (int)round((time() - strtotime($r['created_at'])) / 86400));
    ?>
    <div class="hri-card" style="<?= $urgentBg ?>">
      <div style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
        <div style="flex:1;min-width:0;">
          <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.4rem;">
            <span style="font-family:monospace;font-weight:700;color:var(--navy);font-size:.95rem;"><?= htmlspecialchars($r['ref_number']) ?></span>
            <span style="background:#e0f2fe;color:#0369a1;font-size:.75rem;padding:.15rem .5rem;border-radius:9999px;font-weight:500;"><?= $typeLabels[$r['type']] ?? $r['type'] ?></span>
            <span style="background:<?= $stCol ?>20;color:<?= $stCol ?>;font-size:.75rem;padding:.15rem .5rem;border-radius:9999px;font-weight:500;"><?= $stLbl ?></span>
            <?php if ($r['priority'] === 'urgent'): ?>
            <span style="background:#fee2e2;color:#ef4444;font-size:.72rem;padding:.15rem .5rem;border-radius:9999px;font-weight:700;text-transform:uppercase;">URGENT</span>
            <?php endif; ?>
          </div>
          <div style="font-weight:500;margin-bottom:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:500px;" title="<?= htmlspecialchars($r['description']) ?>">
            <?= htmlspecialchars(mb_substr($r['description'], 0, 80)) ?><?= mb_strlen($r['description']) > 80 ? '…' : '' ?>
          </div>
          <div style="font-size:.82rem;color:#64748b;">
            <strong><?= htmlspecialchars($r['requester_name']) ?></strong> — <?= htmlspecialchars($r['requester_dept'] ?? '') ?>
            &middot; &#8358;<?= number_format((float)$r['amount'],2) ?>
            &middot; <?= $daysAgo === 0 ? 'Today' : $daysAgo.' day'.($daysAgo>1?'s':'').' ago' ?>
          </div>
        </div>
        <a href="irs-detail.php?id=<?= $r['id'] ?>" class="hri-btn hri-btn-navy" style="flex-shrink:0;white-space:nowrap;">Review &rarr;</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <div style="display:flex;gap:.5rem;align-items:center;justify-content:center;margin-top:1.25rem;flex-wrap:wrap;">
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page-1 ?>&type=<?= urlencode($filterType) ?>&status=<?= urlencode($filterStatus) ?>" class="hri-btn hri-btn-outline" style="font-size:.8rem;padding:.3rem .7rem;">&laquo; Prev</a>
    <?php endif; ?>
    <span style="font-size:.85rem;color:#64748b;">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page+1 ?>&type=<?= urlencode($filterType) ?>&status=<?= urlencode($filterStatus) ?>" class="hri-btn hri-btn-outline" style="font-size:.8rem;padding:.3rem .7rem;">Next &raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php Layout::end(); ?>
