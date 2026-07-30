<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/IrsFlow.php';

$user = Auth::require();
$db   = getDB();

$requestId = (int)($_GET['id'] ?? 0);
if (!$requestId) { header('Location: irs.php'); exit; }

$req = null;
try {
    $s = $db->prepare("SELECT r.*, u.name requester_name, u.department requester_dept
        FROM irs_requests r JOIN users u ON u.id=r.requester_id WHERE r.id=?");
    $s->execute([$requestId]);
    $req = $s->fetch(PDO::FETCH_ASSOC);
} catch(Exception $e) {}
if (!$req || !IrsFlow::canView($user, $req)) { header('Location: irs.php'); exit; }
if (empty($req['payment_raised_at'])) { header('Location: irs-detail.php?id='.$requestId); exit; }

$journalEntries = [];
try {
    $jq = $db->prepare("SELECT * FROM irs_journal_entries WHERE request_id=? ORDER BY line_no ASC");
    $jq->execute([$requestId]);
    $journalEntries = $jq->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

function vName(PDO $db, ?int $id): string {
    if (!$id) return '—';
    static $c = [];
    if (!isset($c[$id])) {
        $s = $db->prepare("SELECT name FROM users WHERE id=?");
        $s->execute([$id]);
        $c[$id] = $s->fetchColumn() ?: '—';
    }
    return $c[$id];
}

$typeLabels = ['requisition'=>'Requisition','caution'=>'Caution Payment','payment'=>'Payment Request','petty_cash'=>'Petty Cash','retirement'=>'Retirement'];
$totalDebit  = array_sum(array_column($journalEntries, 'debit'));
$totalCredit = array_sum(array_column($journalEntries, 'credit'));

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payment Voucher — <?= htmlspecialchars($req['ref_number']) ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#1e293b;background:#fff;padding:20px;}
.voucher{max-width:1600px;margin:0 auto;border:2px solid #002850;border-radius:6px;overflow:hidden;}
.v-header{background:#002850;color:#fff;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;}
.v-logo{font-size:18px;font-weight:800;letter-spacing:.5px;}
.v-subtitle{font-size:10px;opacity:.75;margin-top:2px;}
.v-ref{text-align:right;}
.v-ref .ref-num{font-family:monospace;font-size:16px;font-weight:700;}
.v-ref .ref-lbl{font-size:10px;opacity:.7;}
.v-body{padding:20px 24px;}
.v-meta{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:18px;padding:12px 16px;background:#f8fafc;border-radius:4px;border:1px solid #e2e8f0;}
.v-meta-item label{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;display:block;margin-bottom:2px;}
.v-meta-item span{font-weight:600;font-size:12px;}
.v-section-title{font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:#64748b;font-weight:700;border-bottom:1px solid #e2e8f0;padding-bottom:4px;margin-bottom:10px;}
.v-desc{background:#f8fafc;border-radius:4px;padding:10px 14px;margin-bottom:18px;font-size:12px;line-height:1.55;color:#334155;}
.v-payment{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;}
.v-payment-box{border:1px solid #e2e8f0;border-radius:4px;padding:12px 14px;}
.v-payment-box label{font-size:9px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;display:block;margin-bottom:2px;}
.v-payment-box span{font-weight:600;}
.v-amount{font-size:22px;font-weight:800;color:#002850;text-align:center;padding:14px;border:2px solid #002850;border-radius:4px;margin-bottom:18px;}
.v-amount small{display:block;font-size:10px;font-weight:400;color:#64748b;margin-top:2px;}
table.journal{width:100%;border-collapse:collapse;margin-bottom:18px;font-size:11px;}
table.journal th{background:#f1f5f9;padding:6px 8px;text-align:left;font-weight:600;color:#64748b;text-transform:uppercase;font-size:9px;letter-spacing:.05em;}
table.journal td{padding:6px 8px;border-bottom:1px solid #f1f5f9;}
table.journal .num{text-align:right;font-family:monospace;}
table.journal tfoot td{font-weight:700;border-top:2px solid #e2e8f0;padding-top:8px;}
table.journal .balanced{color:#059669;}
table.journal .unbalanced{color:#dc2626;}
.v-sigs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:20px;}
.v-sig{border-top:1px solid #1e293b;padding-top:6px;}
.v-sig label{font-size:9px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;}
.v-sig .sig-name{font-size:11px;font-weight:600;margin-top:2px;}
.v-sig .sig-date{font-size:10px;color:#64748b;}
.v-footer{background:#f8fafc;border-top:1px solid #e2e8f0;padding:10px 24px;display:flex;justify-content:space-between;font-size:10px;color:#94a3b8;}
.no-print{margin-bottom:16px;display:flex;gap:8px;}
@media print{
    .no-print{display:none!important;}
    body{padding:0;}
    .voucher{border:none;max-width:100%;}
    .v-header{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .v-meta{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
/* mobile-all-pages */
@media(max-width:768px){
    table{font-size:11px;}
}
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()" style="background:#002850;color:#fff;border:none;padding:8px 20px;border-radius:4px;font-size:13px;cursor:pointer;font-weight:600;">&#128424; Print Voucher</button>
  <a href="irs-detail.php?id=<?= $requestId ?>" style="background:#f1f5f9;color:#334155;border:none;padding:8px 16px;border-radius:4px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block;">&larr; Back to Request</a>
</div>

<div class="voucher">

  <div class="v-header">
    <div>
      <div class="v-logo">HR INDEXX LIMITED</div>
      <div class="v-subtitle">12 Macarthy Street, Onikan, Lagos Island &bull; RC: 446051</div>
    </div>
    <div class="v-ref">
      <div class="v-ref-lbl" style="font-size:10px;opacity:.7;">PAYMENT VOUCHER</div>
      <div class="ref-num"><?= htmlspecialchars($req['ref_number']) ?></div>
      <div style="font-size:10px;opacity:.7;">Date: <?= date('d M Y', strtotime($req['payment_raised_at'])) ?></div>
    </div>
  </div>

  <div class="v-body">

    <div class="v-meta">
      <div class="v-meta-item">
        <label>Request Type</label>
        <span><?= $typeLabels[$req['type']] ?? $req['type'] ?></span>
      </div>
      <div class="v-meta-item">
        <label>Requested By</label>
        <span><?= htmlspecialchars($req['requester_name']) ?></span>
      </div>
      <div class="v-meta-item">
        <label>Department</label>
        <span><?= htmlspecialchars($req['requester_dept'] ?? $req['department'] ?? '—') ?></span>
      </div>
      <div class="v-meta-item">
        <label>Priority</label>
        <span style="text-transform:capitalize;"><?= $req['priority'] ?></span>
      </div>
      <div class="v-meta-item">
        <label>Raised By</label>
        <span><?= htmlspecialchars(vName($db, (int)$req['payment_raised_by'])) ?></span>
      </div>
      <div class="v-meta-item">
        <label>Raised On</label>
        <span><?= date('d M Y, g:ia', strtotime($req['payment_raised_at'])) ?></span>
      </div>
    </div>

    <div class="v-section-title">Purpose / Description</div>
    <div class="v-desc"><?= nl2br(htmlspecialchars($req['description'])) ?><?php if (!empty($req['notes'])): ?><br><br><em style="color:#64748b;">Note: <?= nl2br(htmlspecialchars($req['notes'])) ?></em><?php endif; ?></div>

    <div class="v-amount">
      &#8358;<?= number_format((float)($req['actual_amount'] ?? $req['amount']), 2) ?>
      <small>
        <?php if (!empty($req['actual_amount']) && (float)$req['actual_amount'] !== (float)$req['amount']): ?>
          Actual Amount — Original Request: &#8358;<?= number_format((float)$req['amount'], 2) ?>
        <?php else: ?>
          Payment Amount
        <?php endif; ?>
      </small>
    </div>

    <?php if (!empty($req['bank']) || !empty($req['paying_bank'])): ?>
    <div class="v-section-title">Payment Details</div>
    <div class="v-payment">
      <div class="v-payment-box">
        <label>&#128228; Paying From (Our Account)</label>
        <span><?= htmlspecialchars($req['paying_bank'] ?? '—') ?></span>
      </div>
      <div class="v-payment-box">
        <label>Payment Method</label>
        <span><?= htmlspecialchars($req['payment_method'] ?? '—') ?></span>
      </div>
      <div class="v-payment-box">
        <label>&#128229; Beneficiary Bank</label>
        <span><?= htmlspecialchars($req['bank'] ?? '—') ?></span>
      </div>
      <div class="v-payment-box">
        <label>Account Name</label>
        <span><?= htmlspecialchars($req['account_name'] ?? '—') ?></span>
      </div>
      <?php if (!empty($req['payee_name'])): ?>
      <div class="v-payment-box">
        <label>Payee / Beneficiary</label>
        <span><?= htmlspecialchars($req['payee_name']) ?></span>
      </div>
      <?php endif; ?>
      <div class="v-payment-box">
        <label>Account Number</label>
        <span style="font-family:monospace;font-size:14px;"><?= htmlspecialchars($req['account_number'] ?? '—') ?></span>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($journalEntries)): ?>
    <div class="v-section-title">Journal Entries</div>
    <table class="journal">
      <thead>
        <tr>
          <th style="width:28px;">#</th>
          <th style="width:80px;">Account Code</th>
          <th>Account Name</th>
          <th>Description</th>
          <th class="num">Debit (&#8358;)</th>
          <th class="num">Credit (&#8358;)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($journalEntries as $jl): ?>
      <tr>
        <td><?= $jl['line_no'] ?></td>
        <td style="font-family:monospace;"><?= htmlspecialchars($jl['account_code'] ?? '') ?></td>
        <td><?= htmlspecialchars($jl['account_name']) ?></td>
        <td style="color:#64748b;"><?= htmlspecialchars($jl['description'] ?? '') ?></td>
        <td class="num"><?= $jl['debit'] > 0 ? number_format((float)$jl['debit'], 2) : '' ?></td>
        <td class="num"><?= $jl['credit'] > 0 ? number_format((float)$jl['credit'], 2) : '' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="text-align:right;font-size:10px;padding-right:8px;">
            <?php if (abs($totalDebit - $totalCredit) < 0.01): ?>
            <span class="balanced">&#10003; Balanced</span>
            <?php else: ?>
            <span class="unbalanced">&#9888; Unbalanced — Difference: &#8358;<?= number_format(abs($totalDebit - $totalCredit), 2) ?></span>
            <?php endif; ?>
          </td>
          <td class="num"><?= number_format($totalDebit, 2) ?></td>
          <td class="num"><?= number_format($totalCredit, 2) ?></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>

    <?php if (!empty($req['sage_ref'])): ?>
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:4px;padding:8px 14px;margin-bottom:18px;font-size:11px;color:#166534;">
      &#9989; Posted to Sage — Ref: <strong style="font-family:monospace;"><?= htmlspecialchars($req['sage_ref']) ?></strong>
      on <?= date('d M Y, g:ia', strtotime($req['sage_posted_at'])) ?>
      by <?= htmlspecialchars(vName($db, (int)$req['sage_posted_by'])) ?>
    </div>
    <?php endif; ?>

    <!-- Signature line -->
    <div class="v-sigs">
      <div class="v-sig">
        <label>Prepared By</label>
        <div class="sig-name"><?= htmlspecialchars(vName($db, (int)$req['payment_raised_by'])) ?></div>
        <div class="sig-date"><?= $req['payment_raised_at'] ? date('d M Y', strtotime($req['payment_raised_at'])) : '' ?></div>
      </div>
      <div class="v-sig">
        <label>Authorised By</label>
        <div class="sig-name"><?= htmlspecialchars(vName($db, (int)$req['manager_approver_id'])) ?></div>
        <div class="sig-date"><?= $req['manager_approved_at'] ? date('d M Y', strtotime($req['manager_approved_at'])) : '' ?></div>
      </div>
      <div class="v-sig">
        <label>Posted By</label>
        <div class="sig-name"><?= htmlspecialchars(vName($db, (int)$req['sage_posted_by'])) ?></div>
        <div class="sig-date"><?= $req['sage_posted_at'] ? date('d M Y', strtotime($req['sage_posted_at'])) : '' ?></div>
      </div>
    </div>

  </div><!-- .v-body -->

  <div class="v-footer">
    <span>HR Indexx Limited &bull; NDPC/DCP/12819 &bull; RC: 446051</span>
    <span>Printed: <?= date('d M Y, g:ia') ?> by <?= htmlspecialchars($user['name']) ?></span>
  </div>

</div><!-- .voucher -->
</body>
</html>
