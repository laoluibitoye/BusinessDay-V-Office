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

$requestId = (int)($_GET['id'] ?? 0);
if (!$requestId) { header('Location: irs.php'); exit; }

$req = null;
try {
    $row = $db->prepare("SELECT r.*, u.name requester_name, u.email requester_email,
        u.department requester_dept, u.phone requester_phone
        FROM irs_requests r JOIN users u ON u.id=r.requester_id WHERE r.id=?");
    $row->execute([$requestId]);
    $req = $row->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (!$req) { header('Location: irs.php'); exit; }

// Access check via IrsFlow
if (!IrsFlow::canView($user, $req)) { header('Location: irs.php'); exit; }

$isRequester = ((int)$user['id'] === (int)$req['requester_id']);
$isAdmin     = Auth::isAdmin($user);

// Get available flow actions for this user at this stage
$availableActions = IrsFlow::getAvailableActions($db, $user, $req['type'], $req['status']);
// Admin can also act if no flow actor matches — but only on non-terminal stages
if ($isAdmin && empty($availableActions) && !IrsFlow::isTerminal($req['status'])) {
    $availableActions = IrsFlow::getTransitions($db, $req['type'], $req['status']);
}

$showWithdraw       = $isRequester && in_array($req['status'], IrsFlow::WITHDRAWABLE_STAGES) && !$isAdmin;
$showResubmit       = $isRequester && in_array($req['status'], ['rejected', 'draft']);
$showCorrections    = $isRequester && $req['status'] === 'pending_corrections';
$hasPushbackContext = !empty($req['rejected_by'])
                   && !in_array($req['status'], ['rejected', 'pending_corrections', 'completed', 'draft']);
$showRefundUpload   = $isRequester && $req['status'] === 'pending_post' && $req['type'] === 'retirement';

// Audit trail (exclude notes)
$auditRows = [];
try {
    $as = $db->prepare("SELECT a.*, u.name uname FROM irs_audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.request_id=? AND a.action != 'note' ORDER BY a.created_at ASC");
    $as->execute([$requestId]);
    $auditRows = $as->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Notes (separate from audit trail)
$noteRows = [];
try {
    $ns = $db->prepare("SELECT a.*, u.name uname FROM irs_audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.request_id=? AND a.action='note' ORDER BY a.created_at ASC");
    $ns->execute([$requestId]);
    $noteRows = $ns->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Attachments
$attachments = [];
try {
    $ats = $db->prepare("SELECT a.*, u.name uname FROM irs_attachments a LEFT JOIN users u ON u.id=a.user_id WHERE a.request_id=? ORDER BY a.created_at ASC");
    $ats->execute([$requestId]);
    $attachments = $ats->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Journal entries
$journalEntries = [];
try {
    $jq = $db->prepare("SELECT * FROM irs_journal_entries WHERE request_id=? ORDER BY line_no ASC");
    $jq->execute([$requestId]);
    $journalEntries = $jq->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

function getNameById(PDO $db, ?int $id): string {
    if (!$id) return '—';
    static $cache = [];
    if (!isset($cache[$id])) {
        $s = $db->prepare("SELECT name FROM users WHERE id=?");
        $s->execute([$id]);
        $cache[$id] = $s->fetchColumn() ?: '—';
    }
    return $cache[$id];
}

$typeLabels = ['requisition'=>'Requisition','caution'=>'Caution Payment','payment'=>'Payment Request','petty_cash'=>'Petty Cash','retirement'=>'Retirement'];
$st    = $req['status'];
$stLbl = IrsFlow::stageLabel($db, $req['type'], $st) ?: IrsFlow::defaultStageLabel($st);
$stCol = IrsFlow::stageColor($st);
$isNew = !empty($_GET['new']);

$hasActions = !empty($availableActions) || $showWithdraw || $showResubmit || $showCorrections;

// Petty cash disbursement state. Declared here rather than only inside the
// actions panel, which does not render when $hasActions is false — the script
// block at the foot of the page reads these either way.
$disburseTrans = null;
$pcFloat = 0.0; $pcUsed = 0.0; $pcRemaining = 0.0;

// Stages for approval progress panel
$flowStages = IrsFlow::getStages($db, $req['type']);

Layout::shell($user, 'irs', 0, $req['ref_number'].' — Internal Request');
?>
<style>
.irs-action-group { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.5rem; padding:1rem; margin-bottom:.875rem; }
.irs-action-group + .irs-action-group { margin-top:0; }
.irs-action-label { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin-bottom:.6rem; }
.irs-btn-row { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.5rem; }
/* Approval progress — connected flow line: green behind travelled stages,
   blue fading to grey at the live one, plain grey ahead. */
.irs-stage-row { display:flex; align-items:flex-start; gap:.6rem; padding:.4rem 0; position:relative; }
.irs-stage-row::before { content:''; position:absolute; left:10px; top:30px; bottom:-8px; width:2px; background:#dbe3ec; border-radius:2px; }
.irs-stage-row:last-child::before { display:none; }
.irs-stage-row.is-done::before { background:#86d5a8; }
.irs-stage-row.is-cur::before  { background:linear-gradient(#3b82f6 0 40%, #dbe3ec 40% 100%); }
.irs-stage-dot { width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.72rem; font-weight:700; margin-top:.1rem; position:relative; z-index:1; }
.irs-stage-row.is-cur .irs-stage-dot { box-shadow:0 0 0 3px rgba(59,130,246,.18); }
.irs-stage-owner { font-size:.7rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; color:#94a3b8; margin-top:.12rem; }
.irs-stage-reason { margin-top:.35rem; background:#fef3c7; border-left:3px solid #fbbf24; border-radius:0 .3rem .3rem 0; padding:.32rem .55rem; font-size:.77rem; color:#b45309; overflow-wrap:anywhere; line-height:1.45; }

/* Activity trail — collapsed by default; Approval Progress is the visible tier */
.irs-log > summary { list-style:none; cursor:pointer; padding:.7rem 1rem; display:flex; align-items:center; gap:.45rem; user-select:none; }
.irs-log > summary::-webkit-details-marker { display:none; }
.irs-log > summary:focus-visible { outline:2px solid #002850; outline-offset:-2px; border-radius:.4rem; }
.irs-log-chev { display:inline-block; transition:transform .18s ease; font-size:.62rem; color:#94a3b8; }
.irs-log[open] .irs-log-chev { transform:rotate(90deg); }
.irs-log-title { font-size:.93rem; font-weight:700; color:#002850; }
.irs-log-hint { font-size:.77rem; color:#94a3b8; font-weight:400; }
.irs-log-body { padding:0 1rem 1rem; border-top:1px solid #f1f5f9; }
@media (prefers-reduced-motion:reduce) { .irs-log-chev { transition:none; } }
/* mobile-all-pages */
@media(max-width:768px){
    .irs-two-col,.irs-three-col{grid-template-columns:1fr!important;}
    .irs-detail-grid,[class*='-grid']{grid-template-columns:1fr;}
}
</style>

<div class="hri-page">
  <div class="hri-page-hd">
    <h1 class="hri-page-title" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
      <span style="font-family:monospace;color:var(--navy);"><?= htmlspecialchars($req['ref_number']) ?></span>
      <span style="background:<?= $stCol ?>20;color:<?= $stCol ?>;font-size:.8rem;padding:.3rem .8rem;border-radius:9999px;font-weight:600;">
        <?= htmlspecialchars($stLbl) ?>
      </span>
    </h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
      <a href="irs.php" class="hri-btn hri-btn-outline">&larr; Back</a>
      <?php if (!empty($req['payment_raised_at'])): ?>
      <a href="irs-voucher.php?id=<?= $requestId ?>" target="_blank" class="hri-btn hri-btn-outline">&#128203; Payment Voucher</a>
      <?php endif; ?>
      <?php if (IrsFlow::isTerminal($st)): ?>
      <a href="irs-new.php?from=<?= $requestId ?>" class="hri-btn hri-btn-outline">&#128260; Raise Similar</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isNew): ?>
  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:.5rem;padding:.875rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;color:#166534;">
    &#9989; <strong>Request submitted.</strong> Reference: <strong><?= htmlspecialchars($req['ref_number']) ?></strong> — Check back here to track progress.
  </div>
  <?php endif; ?>

  <?php if ($showCorrections): ?>
  <div style="background:#fffbeb;border:1px solid #fcd34d;border-left:4px solid #f59e0b;border-radius:.5rem;padding:.875rem 1.25rem;margin-bottom:1.25rem;color:#92400e;">
    <strong>&#9888; Corrections Required</strong> — Review the rejection note in the Actions panel and submit your corrections. Only the original request data can be edited; previously completed stages will restart.
  </div>
  <?php endif; ?>

  <?php if ($hasPushbackContext): ?>
  <div style="background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid #f97316;border-radius:.5rem;padding:.875rem 1.25rem;margin-bottom:1.25rem;">
    <div style="font-weight:700;color:#9a3412;font-size:.88rem;margin-bottom:.3rem;">&#9888; Previously Rejected — Returned for Re-review</div>
    <div style="font-size:.83rem;color:#7c2d12;">
      Rejected by <strong><?= htmlspecialchars(getNameById($db, (int)$req['rejected_by'])) ?></strong>
      <?php if (!empty($req['rejected_at'])): ?>on <?= date('d M Y, g:ia', strtotime($req['rejected_at'])) ?><?php endif; ?>
    </div>
    <?php if (!empty($req['rejection_reason'])): ?>
    <div style="margin-top:.4rem;background:#fff;border-radius:.3rem;padding:.4rem .7rem;font-size:.84rem;color:#7c2d12;line-height:1.5;"><?= nl2br(htmlspecialchars($req['rejection_reason'])) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;">

    <!-- ── Left column ──────────────────────────────────────────────────── -->
    <div>

      <!-- Request Details -->
      <div class="hri-card" style="margin-bottom:1.25rem;">
        <div class="hri-card-hd"><h2 class="hri-card-title">Request Details</h2></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;padding-bottom:.5rem;">
          <div>
            <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Type</div>
            <div style="font-weight:500;"><?= $typeLabels[$req['type']] ?? $req['type'] ?></div>
          </div>
          <div>
            <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Priority</div>
            <div style="font-weight:500;text-transform:capitalize;"><?= $req['priority'] ?></div>
          </div>
          <div>
            <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Requester</div>
            <div style="font-weight:500;"><?= htmlspecialchars($req['requester_name']) ?></div>
            <div style="font-size:.8rem;color:#64748b;"><?= htmlspecialchars($req['requester_dept'] ?? '') ?></div>
          </div>
          <div>
            <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Department</div>
            <div style="font-weight:500;"><?= htmlspecialchars($req['department']) ?></div>
          </div>
          <div>
            <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Amount Requested</div>
            <div style="font-weight:700;font-size:1.1rem;color:var(--navy);">&#8358;<?= number_format((float)$req['amount'], 2) ?></div>
          </div>
          <?php if (!empty($req['actual_amount']) && (float)$req['actual_amount'] > 0 && $req['type'] !== 'retirement'): ?>
          <div>
            <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Actual Amount (Payment)</div>
            <div style="font-weight:700;font-size:1.05rem;color:#059669;">&#8358;<?= number_format((float)$req['actual_amount'], 2) ?></div>
          </div>
          <?php endif; ?>
          <div>
            <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Submitted</div>
            <div style="font-weight:500;"><?= date('d M Y, g:ia', strtotime($req['created_at'])) ?></div>
          </div>
        </div>

        <div style="margin-top:1rem;">
          <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem;">Description</div>
          <div style="background:#f8fafc;border-radius:.4rem;padding:.75rem 1rem;line-height:1.6;"><?= nl2br(htmlspecialchars($req['description'])) ?></div>
        </div>
        <?php if (!empty($req['notes'])): ?>
        <div style="margin-top:.875rem;">
          <div style="font-size:.76rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem;">Notes</div>
          <div style="background:#f8fafc;border-radius:.4rem;padding:.75rem 1rem;line-height:1.6;"><?= nl2br(htmlspecialchars($req['notes'])) ?></div>
        </div>
        <?php endif; ?>

        <!-- Beneficiary details for Payment Request (captured at submission) -->
        <?php if ($req['type'] === 'payment' && (!empty($req['beneficiaries_json']) || !empty($req['bank']))): ?>
        <?php
        $bRows = [];
        if (!empty($req['beneficiaries_json'])) $bRows = json_decode($req['beneficiaries_json'], true) ?: [];
        if (empty($bRows) && !empty($req['bank'])) $bRows = [['bank'=>$req['bank'],'account_number'=>$req['account_number']??'','account_name'=>$req['account_name']??'']];
        ?>
        <?php if (!empty($bRows)): ?>
        <div style="margin-top:1rem;padding:.875rem 1rem;background:#f0f9ff;border-radius:.5rem;">
          <div style="font-weight:600;margin-bottom:.5rem;color:#0891b2;font-size:.9rem;">&#127970; Beneficiary Details</div>
          <?php foreach ($bRows as $bi => $br): ?>
          <?php if (count($bRows) > 1): ?>
          <div style="font-size:.73rem;font-weight:600;color:#0891b2;margin-bottom:.2rem;<?= $bi > 0 ? 'margin-top:.5rem;padding-top:.4rem;border-top:1px solid #bae6fd;' : '' ?>">Beneficiary <?= $bi + 1 ?></div>
          <?php endif; ?>
          <div style="display:grid;grid-template-columns:1fr 120px 1fr;gap:.5rem;" class="irs-three-col">
            <div><div style="color:#64748b;font-size:.73rem;margin-bottom:.1rem;">Bank</div><div style="font-weight:600;"><?= htmlspecialchars($br['bank'] ?? '—') ?></div></div>
            <div><div style="color:#64748b;font-size:.73rem;margin-bottom:.1rem;">Account No.</div><div style="font-weight:600;font-family:monospace;"><?= htmlspecialchars($br['account_number'] ?? '—') ?></div></div>
            <div><div style="color:#64748b;font-size:.73rem;margin-bottom:.1rem;">Account Name</div><div style="font-weight:600;"><?= htmlspecialchars($br['account_name'] ?? '—') ?></div></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Journal entries (payment: submitted at initiation; retirement: attached by HOD Accounts) -->
        <?php if ($req['type'] === 'payment' && empty($journalEntries)): ?>
        <div style="margin-top:1rem;padding:.9rem 1rem;background:#fef3c7;border:1px solid #fbbf24;border-radius:.5rem;">
          <div style="font-weight:600;color:#b45309;font-size:.88rem;margin-bottom:.3rem;">&#9888; No Journal Entries</div>
          <div style="font-size:.8rem;color:#92400e;">This Payment Request was submitted without accounting journal entries. The requester must resubmit with the required double-entry lines before this can be approved.</div>
        </div>
        <?php endif; ?>
        <?php if (in_array($req['type'], ['payment','retirement','petty_cash']) && !empty($journalEntries)): ?>
        <div style="margin-top:1rem;padding:1rem;background:#f5f3ff;border-radius:.5rem;">
          <div style="font-weight:600;color:#6d28d9;font-size:.9rem;margin-bottom:.6rem;">&#128216; Journal Entries</div>
          <?php $jTotD = 0; $jTotC = 0; ?>
          <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
              <thead><tr style="background:#f5f3ff;">
                <th style="padding:.3rem .45rem;text-align:left;color:#6d28d9;font-weight:600;white-space:nowrap;">Code</th>
                <th style="padding:.3rem .45rem;text-align:left;color:#6d28d9;font-weight:600;">Account Name</th>
                <th style="padding:.3rem .45rem;text-align:left;color:#6d28d9;font-weight:600;">Description</th>
                <th style="padding:.3rem .45rem;text-align:right;color:#6d28d9;font-weight:600;white-space:nowrap;">Debit (&#8358;)</th>
                <th style="padding:.3rem .45rem;text-align:right;color:#6d28d9;font-weight:600;white-space:nowrap;">Credit (&#8358;)</th>
              </tr></thead>
              <tbody><?php foreach ($journalEntries as $je): $jTotD += (float)$je['debit']; $jTotC += (float)$je['credit']; ?>
                <tr style="border-bottom:1px solid #ede9fe;">
                  <td style="padding:.3rem .45rem;font-family:monospace;color:#64748b;white-space:nowrap;"><?= htmlspecialchars($je['account_code'] ?? '—') ?></td>
                  <td style="padding:.3rem .45rem;font-weight:500;"><?= htmlspecialchars($je['account_name']) ?></td>
                  <td style="padding:.3rem .45rem;color:#64748b;font-size:.75rem;"><?= htmlspecialchars($je['description'] ?? '') ?></td>
                  <td style="padding:.3rem .45rem;text-align:right;font-family:monospace;"><?= $je['debit']  > 0 ? number_format((float)$je['debit'],2)  : '—' ?></td>
                  <td style="padding:.3rem .45rem;text-align:right;font-family:monospace;"><?= $je['credit'] > 0 ? number_format((float)$je['credit'],2) : '—' ?></td>
                </tr>
              <?php endforeach; ?></tbody>
              <tfoot><tr style="background:#f5f3ff;font-weight:700;">
                <td colspan="3" style="padding:.3rem .45rem;text-align:right;color:#6d28d9;font-size:.78rem;">Totals</td>
                <td style="padding:.3rem .45rem;text-align:right;font-family:monospace;">&#8358;<?= number_format($jTotD,2) ?></td>
                <td style="padding:.3rem .45rem;text-align:right;font-family:monospace;">&#8358;<?= number_format($jTotC,2) ?></td>
              </tr>
              <tr><td colspan="5" style="padding:.2rem .45rem;text-align:right;font-size:.73rem;">
                <?php if (abs($jTotD-$jTotC)<0.01): ?><span style="color:#059669;font-weight:600;">&#10003; Balanced</span>
                <?php else: ?><span style="color:#ef4444;font-weight:600;">&#9888; Out of balance by &#8358;<?= number_format(abs($jTotD-$jTotC),2) ?></span><?php endif; ?>
              </td></tr></tfoot>
            </table>
          </div>
          <?php if ($req['type'] === 'retirement' && !empty($req['accounts_reviewed_at'])): ?>
          <div style="font-size:.73rem;color:#94a3b8;margin-top:.4rem;">Journals attached by <?= htmlspecialchars(getNameById($db,(int)$req['accounts_reviewer_id'])) ?> on <?= date('d M Y, g:ia',strtotime($req['accounts_reviewed_at'])) ?></div>
          <?php elseif ($req['type'] === 'petty_cash' && !empty($req['custodian_actioned_at'])): ?>
          <div style="font-size:.73rem;color:#94a3b8;margin-top:.4rem;">Entered at disbursement by <?= htmlspecialchars(getNameById($db,(int)$req['custodian_id'])) ?> on <?= date('d M Y, g:ia',strtotime($req['custodian_actioned_at'])) ?><?php if (!empty($req['actual_amount'])): ?> &middot; cash handed over &#8358;<?= number_format((float)$req['actual_amount'],2) ?><?php endif; ?></div>
          <?php else: ?>
          <div style="font-size:.73rem;color:#94a3b8;margin-top:.4rem;">Submitted with request by <?= htmlspecialchars(getNameById($db,(int)$req['requester_id'])) ?> on <?= date('d M Y, g:ia',strtotime($req['created_at'])) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Payment details (after payment raise — requisition / caution) -->
        <?php if (!empty($req['payment_method']) || !empty($req['paying_bank'])): ?>
        <div style="margin-top:1rem;padding:1rem;background:#f0fdf4;border-radius:.5rem;">
          <div style="font-weight:600;margin-bottom:.6rem;color:#059669;font-size:.9rem;">&#128179; Payment Details</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.6rem;">
            <?php if (!empty($req['paying_bank'])): ?>
            <div style="border-left:3px solid #059669;padding-left:.5rem;"><div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">&#128228; Transfer From</div><div style="font-weight:600;"><?= htmlspecialchars($req['paying_bank']) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($req['payee_name'])): ?>
            <div><div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Payee</div><div style="font-weight:600;"><?= htmlspecialchars($req['payee_name']) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($req['payment_method'])): ?>
            <div><div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Method</div><div style="font-weight:600;"><?= htmlspecialchars($req['payment_method']) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($req['bank'])): ?>
            <div><div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Beneficiary's Bank</div><div style="font-weight:600;"><?= htmlspecialchars($req['bank']) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($req['account_name'])): ?>
            <div><div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Account Name</div><div style="font-weight:600;"><?= htmlspecialchars($req['account_name']) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($req['account_number'])): ?>
            <div><div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Account No.</div><div style="font-weight:600;font-family:monospace;"><?= htmlspecialchars($req['account_number']) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($req['journal_ref'])): ?>
            <div><div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Journal Ref</div><div style="font-weight:600;font-family:monospace;"><?= htmlspecialchars($req['journal_ref']) ?></div></div>
            <?php endif; ?>
          </div>
          <?php if (!empty($req['payment_raised_by'])): ?>
          <div style="font-size:.78rem;color:#64748b;margin-top:.5rem;">Raised by <?= getNameById($db,(int)$req['payment_raised_by']) ?> on <?= date('d M Y, g:ia',strtotime($req['payment_raised_at'])) ?></div>
          <?php endif; ?>
          <?php if (!empty($journalEntries) && $req['type'] !== 'payment'): ?>
          <div style="margin-top:.875rem;padding-top:.75rem;border-top:1px solid #d8b4fe;">
            <div style="font-weight:600;color:#6d28d9;font-size:.82rem;margin-bottom:.5rem;">&#128196; Journal Entries</div>
            <div style="overflow-x:auto;">
              <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                <thead>
                  <tr style="background:#f5f3ff;">
                    <th style="padding:.3rem .45rem;text-align:left;color:#6d28d9;font-weight:600;white-space:nowrap;">Code</th>
                    <th style="padding:.3rem .45rem;text-align:left;color:#6d28d9;font-weight:600;">Account Name</th>
                    <th style="padding:.3rem .45rem;text-align:left;color:#6d28d9;font-weight:600;">Description</th>
                    <th style="padding:.3rem .45rem;text-align:right;color:#6d28d9;font-weight:600;white-space:nowrap;">Debit (&#8358;)</th>
                    <th style="padding:.3rem .45rem;text-align:right;color:#6d28d9;font-weight:600;white-space:nowrap;">Credit (&#8358;)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $jTotD = 0; $jTotC = 0;
                  foreach ($journalEntries as $je):
                      $jTotD += (float)$je['debit'];
                      $jTotC += (float)$je['credit'];
                  ?>
                  <tr style="border-bottom:1px solid #ede9fe;">
                    <td style="padding:.3rem .45rem;font-family:monospace;color:#64748b;white-space:nowrap;"><?= htmlspecialchars($je['account_code'] ?? '—') ?></td>
                    <td style="padding:.3rem .45rem;font-weight:500;"><?= htmlspecialchars($je['account_name']) ?></td>
                    <td style="padding:.3rem .45rem;color:#64748b;font-size:.75rem;"><?= htmlspecialchars($je['description'] ?? '') ?></td>
                    <td style="padding:.3rem .45rem;text-align:right;font-family:monospace;"><?= $je['debit']  > 0 ? number_format((float)$je['debit'],2)  : '—' ?></td>
                    <td style="padding:.3rem .45rem;text-align:right;font-family:monospace;"><?= $je['credit'] > 0 ? number_format((float)$je['credit'],2) : '—' ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr style="background:#f5f3ff;font-weight:700;">
                    <td colspan="3" style="padding:.3rem .45rem;text-align:right;color:#6d28d9;font-size:.78rem;">Totals</td>
                    <td style="padding:.3rem .45rem;text-align:right;font-family:monospace;">&#8358;<?= number_format($jTotD,2) ?></td>
                    <td style="padding:.3rem .45rem;text-align:right;font-family:monospace;">&#8358;<?= number_format($jTotC,2) ?></td>
                  </tr>
                  <tr>
                    <td colspan="5" style="padding:.2rem .45rem;text-align:right;font-size:.73rem;">
                      <?php if (abs($jTotD - $jTotC) < 0.01): ?>
                      <span style="color:#059669;font-weight:600;">&#10003; Balanced</span>
                      <?php else: ?>
                      <span style="color:#ef4444;font-weight:600;">&#9888; Out of balance by &#8358;<?= number_format(abs($jTotD-$jTotC),2) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Retirement: reconciliation details -->
        <?php if ($req['type'] === 'retirement'): ?>
        <div style="margin-top:1rem;padding:1rem;background:#f5f3ff;border-radius:.5rem;">
          <div style="font-weight:600;margin-bottom:.6rem;color:#6d28d9;">&#128204; Retirement Details</div>
          <?php
            $retAdvance    = (float)($req['advance_amount'] ?? $req['amount'] ?? 0);
            $retSpent      = (float)($req['actual_amount'] ?? 0);
            $retOutstanding = $retAdvance - $retSpent;
            $outColor = $retOutstanding > 0.005 ? '#dc2626' : ($retOutstanding < -0.005 ? '#0891b2' : '#059669');
            $outLabel = $retOutstanding > 0.005 ? 'Refund Due' : ($retOutstanding < -0.005 ? 'Addl. Payment' : 'Exact Match');
          ?>
          <?php if (!empty($req['related_ref'])): ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.6rem;margin-bottom:.6rem;">
            <div style="background:#fff;border-radius:.4rem;padding:.5rem .7rem;border:1px solid #ddd6fe;">
              <div style="color:#64748b;font-size:.72rem;margin-bottom:.15rem;text-transform:uppercase;">Original Ref</div>
              <div style="font-family:monospace;font-weight:700;color:#6d28d9;"><?= htmlspecialchars($req['related_ref']) ?></div>
            </div>
            <div style="background:#fff;border-radius:.4rem;padding:.5rem .7rem;border:1px solid #ddd6fe;">
              <div style="color:#64748b;font-size:.72rem;margin-bottom:.15rem;text-transform:uppercase;">Amount Received</div>
              <div style="font-weight:700;color:#002850;">&#8358;<?= number_format($retAdvance,2) ?></div>
            </div>
            <div style="background:#fff;border-radius:.4rem;padding:.5rem .7rem;border:1px solid #ddd6fe;">
              <div style="color:#64748b;font-size:.72rem;margin-bottom:.15rem;text-transform:uppercase;">Amount Spent</div>
              <div style="font-weight:700;">&#8358;<?= number_format($retSpent,2) ?></div>
            </div>
            <div style="background:<?= $outColor ?>12;border-radius:.4rem;padding:.5rem .7rem;border:2px solid <?= $outColor ?>40;">
              <div style="color:<?= $outColor ?>;font-size:.72rem;margin-bottom:.15rem;text-transform:uppercase;font-weight:700;"><?= $outLabel ?></div>
              <div style="font-weight:800;color:<?= $outColor ?>;">&#8358;<?= number_format(abs($retOutstanding),2) ?></div>
            </div>
          </div>
          <?php endif; ?>
          <?php if (!empty($req['reconciliation_status'])): ?>
          <div style="font-size:.85rem;font-weight:600;color:<?= ['exact'=>'#059669','refund_required'=>'#8b5cf6','additional_payment'=>'#0891b2'][$req['reconciliation_status']] ?? '#64748b' ?>;">
            Reconciliation: <?= ucwords(str_replace('_',' ',$req['reconciliation_status'])) ?>
          </div>
          <?php if (!empty($req['reconciliation_note'])): ?>
          <div style="font-size:.83rem;color:#64748b;margin-top:.3rem;"><?= nl2br(htmlspecialchars($req['reconciliation_note'])) ?></div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Sage ref if completed -->
        <?php if ($st === 'completed' && !empty($req['sage_ref'])): ?>
        <div style="margin-top:1rem;padding:.875rem 1rem;background:#f0fdf4;border:1px solid #86efac;border-radius:.5rem;display:flex;align-items:center;gap:.75rem;">
          &#9989; <span style="color:#166534;font-size:.9rem;">Posted to Sage — Ref: <strong style="font-family:monospace;"><?= htmlspecialchars($req['sage_ref']) ?></strong> on <?= date('d M Y, g:ia',strtotime($req['sage_posted_at'])) ?> by <?= getNameById($db,(int)$req['sage_posted_by']) ?></span>
        </div>
        <?php endif; ?>

        <!-- Rejection info -->
        <?php if ($st === 'rejected' && !empty($req['rejection_reason'])): ?>
        <?php $isWithdrawn = ($req['rejection_reason'] === 'Withdrawn by requester'); ?>
        <div style="margin-top:1rem;padding:.875rem 1rem;background:#fff1f2;border-left:4px solid #be123c;border:1px solid #fecdd3;border-radius:.5rem;">
          <div style="color:#be123c;font-weight:700;font-size:.95rem;margin-bottom:.4rem;">
            <?= $isWithdrawn ? '&#10006; Withdrawn by Requester' : '&#10060; Request Rejected' ?>
          </div>
          <?php if (!$isWithdrawn && !empty($req['rejected_by'])): ?>
          <div style="font-size:.8rem;color:#64748b;margin-bottom:.35rem;">
            Rejected by <strong><?= htmlspecialchars(getNameById($db,(int)$req['rejected_by'])) ?></strong>
            <?php if (!empty($req['rejected_at'])): ?>
            on <?= date('d M Y, g:ia', strtotime($req['rejected_at'])) ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <div style="color:#7f1d1d;font-size:.88rem;line-height:1.5;background:#fff;border-radius:.3rem;padding:.5rem .7rem;">
            <?= nl2br(htmlspecialchars($req['rejection_reason'])) ?>
          </div>
          <?php if ($isRequester && !$isWithdrawn): ?>
          <div style="font-size:.78rem;color:#be123c;margin-top:.5rem;font-style:italic;">
            &#8595; You can correct and resubmit this request using the button below.
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Attachments -->
      <div class="hri-card" style="margin-bottom:1.25rem;">
        <div class="hri-card-hd">
          <h2 class="hri-card-title">Attachments<?= count($attachments) ? ' ('.count($attachments).')' : '' ?></h2>
          <?php if (($isRequester && !IrsFlow::isTerminal($st)) || $isAdmin): ?>
          <button onclick="document.getElementById('attachUploadArea').style.display=''" class="hri-btn hri-btn-outline" style="font-size:.8rem;">&#128206; Add File</button>
          <?php endif; ?>
        </div>
        <?php if (empty($attachments)): ?>
        <div class="hri-empty" style="padding:1.5rem;">No attachments.</div>
        <?php else: ?>
        <?php foreach ($attachments as $att):
          $labelNames = ['journal_voucher'=>'Journal Voucher','invoice'=>'Invoice','approval_memo'=>'Approval Memo','receipts'=>'Receipts','proof_of_refund'=>'Proof of Refund','other'=>'Document'];
          $labelName = $labelNames[$att['label']] ?? $att['label'];
        ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-bottom:1px solid #f1f5f9;">
          <span style="font-size:1.2rem;">&#128206;</span>
          <span style="flex:1;font-size:.875rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($att['filename']) ?></span>
          <span style="font-size:.75rem;background:#e0f2fe;color:#0369a1;padding:.15rem .5rem;border-radius:9999px;white-space:nowrap;"><?= $labelName ?></span>
          <button type="button" onclick="openIrsView(<?= $att['id'] ?>,this.dataset.n)" data-n="<?= htmlspecialchars($att['filename'], ENT_QUOTES, 'UTF-8') ?>" class="hri-btn hri-btn-outline" style="font-size:.78rem;padding:.25rem .6rem;">&#128065; View</button>
          <a href="api/irs/serve.php?id=<?= $att['id'] ?>&dl=1" class="hri-btn hri-btn-outline" style="font-size:.78rem;padding:.25rem .6rem;text-decoration:none;" download>&#11015; Download</a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <!-- Inline upload -->
        <div id="attachUploadArea" style="display:none;padding-top:1rem;border-top:1px solid #f1f5f9;margin-top:.75rem;">
          <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
            <select id="inlineLabel" class="hri-select" style="font-size:.85rem;padding:.4rem .6rem;">
              <option value="other">General Document</option>
              <option value="invoice">Invoice / Quote</option>
              <option value="approval_memo">Approval Memo</option>
              <option value="journal_voucher">Journal Voucher</option>
              <option value="receipts">Receipts</option>
              <option value="proof_of_refund">Proof of Refund</option>
            </select>
            <input type="file" id="inlineFile" style="display:none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip">
            <button type="button" onclick="document.getElementById('inlineFile').click()" class="hri-btn hri-btn-outline" style="font-size:.85rem;">Choose File</button>
            <span id="inlineFileName" style="font-size:.85rem;color:#64748b;"></span>
          </div>
          <div style="margin-top:.75rem;display:flex;gap:.5rem;">
            <button onclick="uploadInline()" class="hri-btn hri-btn-navy" style="font-size:.85rem;" id="inlineUploadBtn">Upload</button>
            <button onclick="document.getElementById('attachUploadArea').style.display='none'" class="hri-btn hri-btn-outline" style="font-size:.85rem;">Cancel</button>
          </div>
        </div>
      </div>

      <!-- Audit Trail — collapsed; Approval Progress is the at-a-glance tier -->
      <div class="hri-card" style="margin-bottom:1.25rem;">
        <?php if (empty($auditRows)): ?>
        <div class="hri-card-hd"><h2 class="hri-card-title">Activity Trail</h2></div>
        <div class="hri-empty" style="padding:1.5rem;">No activity yet.</div>
        <?php else:
          $lastAl   = end($auditRows); reset($auditRows);
          $lastWhat = ucwords(str_replace('_', ' ', $lastAl['action']));
          $lastWhen = date('j M, g:ia', strtotime($lastAl['created_at']));
        ?>
        <details class="irs-log">
          <summary>
            <span class="irs-log-chev">&#9654;</span>
            <span class="irs-log-title">Activity Trail</span>
            <span class="irs-log-hint">&mdash; <?= count($auditRows) ?> <?= count($auditRows) === 1 ? 'entry' : 'entries' ?> &middot; last: <?= htmlspecialchars($lastWhat) ?>, <?= $lastWhen ?></span>
          </summary>
          <div class="irs-log-body">
        <?php foreach ($auditRows as $i=>$al):
          $actionIcons = ['submitted'=>'&#128229;','approve'=>'&#9989;','approve_journals'=>'&#128216;','approve_payment'=>'&#128179;','confirm_eligible'=>'&#9989;','mark_ineligible'=>'&#10060;','reject'=>'&#9888;','raise_payment'=>'&#128179;','return_payment'=>'&#9888;','process'=>'&#128176;','post'=>'&#127881;','withdrawn'=>'&#10006;','resubmitted'=>'&#128260;','submit_correction'=>'&#128260;','attachment_uploaded'=>'&#128206;'];
          $icon = $actionIcons[$al['action']] ?? '&#128203;';
          $isLast = ($i === count($auditRows)-1);
        ?>
        <div style="display:flex;gap:.75rem;padding:.55rem 0;<?= !$isLast ? 'border-bottom:1px solid #f1f5f9;' : '' ?>">
          <div style="width:26px;height:26px;background:#f1f5f9;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.85rem;margin-top:.1rem;"><?= $icon ?></div>
          <div style="flex:1;">
            <div style="font-size:.85rem;"><strong><?= htmlspecialchars($al['uname'] ?? 'System') ?></strong>
              <span style="color:#64748b;margin-left:.35rem;font-size:.8rem;"><?= htmlspecialchars(ucwords(str_replace('_',' ',$al['action']))) ?></span>
            </div>
            <?php if (!empty($al['detail'])): ?>
            <div style="font-size:.8rem;color:#64748b;margin-top:.15rem;"><?= htmlspecialchars($al['detail']) ?></div>
            <?php endif; ?>
            <div style="font-size:.75rem;color:#94a3b8;margin-top:.15rem;"><?= date('d M Y, g:ia',strtotime($al['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>
      </div>

      <!-- Notes / Internal Comments -->
      <div class="hri-card" id="notesCard">
        <div class="hri-card-hd"><h2 class="hri-card-title">&#128172; Notes</h2></div>
        <div id="notesList">
        <?php if (empty($noteRows)): ?>
        <div class="hri-empty" style="padding:1rem 0;" id="noNotesMsg">No notes yet.</div>
        <?php else: ?>
        <?php foreach ($noteRows as $n): ?>
        <div style="display:flex;gap:.6rem;padding:.5rem 0;border-bottom:1px solid #f1f5f9;">
          <div style="width:28px;height:28px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#1d4ed8;flex-shrink:0;">
            <?= htmlspecialchars(mb_strtoupper(mb_substr($n['uname'] ?? 'U', 0, 1))) ?>
          </div>
          <div style="flex:1;">
            <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($n['uname'] ?? 'Unknown') ?></div>
            <div style="font-size:.84rem;color:#334155;margin-top:.15rem;line-height:1.5;"><?= nl2br(htmlspecialchars($n['detail'])) ?></div>
            <div style="font-size:.73rem;color:#94a3b8;margin-top:.2rem;"><?= date('d M Y, g:ia', strtotime($n['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>
        <div style="margin-top:.75rem;display:flex;gap:.5rem;align-items:flex-end;">
          <textarea id="noteInput" class="hri-textarea" rows="2" placeholder="Add a note visible to all actors on this request..." style="flex:1;font-size:.84rem;resize:vertical;"></textarea>
          <button onclick="postNote()" class="hri-btn hri-btn-navy" style="font-size:.82rem;padding:.45rem .9rem;white-space:nowrap;" id="noteBtn">Add Note</button>
        </div>
      </div>
    </div>

    <!-- ── Right column ─────────────────────────────────────────────────── -->
    <div>

      <!-- Refund upload (retirement requester at pending_post) -->
      <?php if ($showRefundUpload): ?>
      <div class="hri-card" style="margin-bottom:1.25rem;border:2px solid #8b5cf6;">
        <div class="hri-card-hd" style="background:#f5f3ff;border-radius:.5rem .5rem 0 0;">
          <h2 class="hri-card-title" style="color:#6d28d9;">&#128203; Upload Proof of Refund</h2>
        </div>
        <p style="font-size:.85rem;color:#64748b;margin-bottom:.75rem;">Upload your bank transfer receipt or proof that the refund has been made.</p>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
          <input type="file" id="refundFile" style="display:none;" accept=".pdf,.jpg,.jpeg,.png">
          <button onclick="document.getElementById('refundFile').click()" class="hri-btn hri-btn-outline" style="font-size:.85rem;">Choose File</button>
          <span id="refundFileName" style="font-size:.85rem;color:#64748b;"></span>
        </div>
        <button onclick="uploadRefundProof()" class="hri-btn hri-btn-navy" style="width:100%;margin-top:.75rem;font-size:.875rem;" id="refundUploadBtn">Upload Proof</button>
      </div>
      <?php endif; ?>

      <!-- ── ACTION PANEL ─────────────────────────────────────────────── -->
      <?php if ($hasActions): ?>
      <div class="hri-card" style="margin-bottom:1.25rem;">
        <div class="hri-card-hd"><h2 class="hri-card-title">&#9889; Actions</h2></div>

        <?php if ($showResubmit): ?>
        <div class="irs-action-group" style="background:#eff6ff;border-color:#bfdbfe;">
          <div class="irs-action-label" style="color:#1d4ed8;">Resubmit Request</div>
          <textarea id="resubmitComment" class="hri-textarea" rows="2" placeholder="Optional: describe what you changed..."></textarea>
          <button onclick="doSpecial('resubmit','resubmitComment')" class="hri-btn hri-btn-navy" style="width:100%;margin-top:.5rem;font-size:.875rem;">&#128260; Resubmit for Review</button>
        </div>
        <?php endif; ?>

        <?php if ($showCorrections):
          $corrBenefRows = [];
          if (!empty($req['beneficiaries_json'])) $corrBenefRows = json_decode($req['beneficiaries_json'], true) ?: [];
          if (empty($corrBenefRows) && !empty($req['bank'])) {
              $corrBenefRows = [['bank'=>$req['bank'],'account_number'=>$req['account_number']??'','account_name'=>$req['account_name']??'']];
          }
        ?>
        <div class="irs-action-group" style="background:#fffbeb;border-color:#fcd34d;">
          <div class="irs-action-label" style="color:#92400e;">&#9888; Submit Corrections</div>
          <?php if (!empty($req['rejection_reason'])): ?>
          <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:.35rem;padding:.5rem .75rem;margin-bottom:.75rem;font-size:.82rem;color:#92400e;line-height:1.5;">
            <strong>Reason:</strong> <?= nl2br(htmlspecialchars($req['rejection_reason'])) ?>
          </div>
          <?php endif; ?>
          <div class="hri-form-group" style="margin-bottom:.5rem;">
            <label class="hri-label" style="font-size:.8rem;">Description <span style="color:#ef4444;">*</span></label>
            <textarea id="corrDesc" class="hri-textarea" rows="3"><?= htmlspecialchars($req['description'] ?? '') ?></textarea>
          </div>
          <div class="hri-form-group" style="margin-bottom:.5rem;">
            <label class="hri-label" style="font-size:.8rem;">Notes</label>
            <textarea id="corrNotes" class="hri-textarea" rows="2" placeholder="Additional notes..."><?= htmlspecialchars($req['notes'] ?? '') ?></textarea>
          </div>
          <div class="hri-form-group" style="margin-bottom:.5rem;">
            <label class="hri-label" style="font-size:.8rem;">Amount (&#8358;) <span style="color:#ef4444;">*</span></label>
            <input type="number" id="corrAmount" class="hri-input" step="0.01" min="0.01" value="<?= htmlspecialchars((string)$req['amount']) ?>">
          </div>
          <?php if (!empty($corrBenefRows) || in_array($req['type'], ['payment','requisition','caution'])): ?>
          <div style="font-size:.78rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem;">Beneficiary Details</div>
          <div id="corrBenefSection">
            <?php foreach ($corrBenefRows as $bi => $br): ?>
            <div id="cbr_<?= $bi ?>" class="corr-benef-row" style="display:grid;grid-template-columns:1fr 120px 1fr 28px;gap:.4rem;margin-bottom:.4rem;">
              <input value="<?= htmlspecialchars($br['bank'] ?? '') ?>" placeholder="Bank name" class="hri-input" style="font-size:.82rem;" oninput="updateCorrBenef()">
              <input value="<?= htmlspecialchars($br['account_number'] ?? '') ?>" placeholder="Account No." maxlength="10" class="hri-input" style="font-size:.82rem;font-family:monospace;" oninput="updateCorrBenef()">
              <input value="<?= htmlspecialchars($br['account_name'] ?? '') ?>" placeholder="Account Name" class="hri-input" style="font-size:.82rem;" oninput="updateCorrBenef()">
              <button type="button" onclick="removeCorrBenef(this)" style="background:none;border:1px solid #fca5a5;border-radius:.3rem;color:#ef4444;cursor:pointer;font-size:1rem;line-height:1;padding:.2rem .4rem;" title="Remove">&times;</button>
            </div>
            <?php endforeach; ?>
            <?php if (empty($corrBenefRows)): ?>
            <div id="cbr_0" class="corr-benef-row" style="display:grid;grid-template-columns:1fr 120px 1fr 28px;gap:.4rem;margin-bottom:.4rem;">
              <input placeholder="Bank name" class="hri-input" style="font-size:.82rem;" oninput="updateCorrBenef()">
              <input placeholder="Account No." maxlength="10" class="hri-input" style="font-size:.82rem;font-family:monospace;" oninput="updateCorrBenef()">
              <input placeholder="Account Name" class="hri-input" style="font-size:.82rem;" oninput="updateCorrBenef()">
              <button type="button" onclick="removeCorrBenef(this)" style="background:none;border:1px solid #fca5a5;border-radius:.3rem;color:#ef4444;cursor:pointer;font-size:1rem;line-height:1;padding:.2rem .4rem;" title="Remove">&times;</button>
            </div>
            <?php endif; ?>
          </div>
          <button type="button" onclick="addCorrBenef()" style="font-size:.78rem;background:none;border:1px solid #d1d5db;border-radius:.3rem;padding:.25rem .6rem;color:#475569;cursor:pointer;margin-bottom:.5rem;">+ Add Beneficiary Row</button>
          <input type="hidden" id="corrBenefJson" value="<?= htmlspecialchars(json_encode(empty($corrBenefRows) ? [['bank'=>'','account_number'=>'','account_name'=>'']] : $corrBenefRows)) ?>">
          <?php endif; ?>

          <?php if ($req['type'] === 'payment'): ?>
          <div style="margin-top:.4rem;margin-bottom:.5rem;">
            <div style="font-size:.78rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem;">Journal Entries <span style="font-weight:400;font-size:.72rem;color:#b45309;">(double-entry — required)</span></div>
            <div style="overflow-x:auto;margin-bottom:.35rem;">
              <table style="width:100%;border-collapse:collapse;font-size:.79rem;">
                <thead><tr style="background:#fef3c7;">
                  <th style="padding:.25rem .3rem;text-align:left;color:#92400e;font-weight:600;white-space:nowrap;width:76px;">Code</th>
                  <th style="padding:.25rem .3rem;text-align:left;color:#92400e;font-weight:600;">Account Name</th>
                  <th style="padding:.25rem .3rem;text-align:left;color:#92400e;font-weight:600;">Description</th>
                  <th style="padding:.25rem .3rem;text-align:right;color:#92400e;font-weight:600;white-space:nowrap;width:86px;">Debit (&#8358;)</th>
                  <th style="padding:.25rem .3rem;text-align:right;color:#92400e;font-weight:600;white-space:nowrap;width:86px;">Credit (&#8358;)</th>
                  <th style="width:22px;"></th>
                </tr></thead>
                <tbody id="corrJournalRows">
                  <?php
                  $cjSeed = !empty($journalEntries) ? $journalEntries : [
                      ['account_code'=>'','account_name'=>'','description'=>'','debit'=>0,'credit'=>0],
                      ['account_code'=>'','account_name'=>'','description'=>'','debit'=>0,'credit'=>0],
                  ];
                  foreach ($cjSeed as $cji => $cje):
                  ?>
                  <tr id="cjrow_<?= $cji ?>">
                    <td style="padding:.18rem .25rem;"><input list="hriCoaList" value="<?= htmlspecialchars($cje['account_code'] ?? '') ?>" placeholder="Code" class="hri-input" style="font-size:.77rem;width:70px;font-family:monospace;" oninput="_coaFillFromCode(this)" onchange="_coaFillFromCode(this)"></td>
                    <td style="padding:.18rem .25rem;"><input list="hriCoaNameList" value="<?= htmlspecialchars($cje['account_name'] ?? '') ?>" placeholder="Account name (required)" class="hri-input" style="font-size:.77rem;" oninput="_coaFillFromName(this)" onchange="_coaFillFromName(this)"></td>
                    <td style="padding:.18rem .25rem;"><input value="<?= htmlspecialchars($cje['description'] ?? '') ?>" placeholder="Optional" class="hri-input" style="font-size:.77rem;" oninput="updateCorrJournal()"></td>
                    <td style="padding:.18rem .25rem;"><input type="number" value="<?= (!empty($cje['debit']) && (float)$cje['debit'] > 0) ? $cje['debit'] : '' ?>" placeholder="0.00" step="0.01" min="0" class="hri-input" style="font-size:.77rem;text-align:right;" oninput="updateCorrJournal()"></td>
                    <td style="padding:.18rem .25rem;"><input type="number" value="<?= (!empty($cje['credit']) && (float)$cje['credit'] > 0) ? $cje['credit'] : '' ?>" placeholder="0.00" step="0.01" min="0" class="hri-input" style="font-size:.77rem;text-align:right;" oninput="updateCorrJournal()"></td>
                    <td style="padding:.18rem .15rem;text-align:center;"><button type="button" onclick="this.closest('tr').remove();updateCorrJournal();" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:1rem;line-height:1;padding:0;">&times;</button></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr style="background:#fef3c7;font-weight:700;font-size:.78rem;">
                    <td colspan="3" style="padding:.25rem .3rem;text-align:right;color:#92400e;">Totals</td>
                    <td style="padding:.25rem .3rem;text-align:right;font-family:monospace;" id="corrJTotD">&#8358;0.00</td>
                    <td style="padding:.25rem .3rem;text-align:right;font-family:monospace;" id="corrJTotC">&#8358;0.00</td>
                    <td></td>
                  </tr>
                  <tr><td colspan="6" style="padding:.15rem .3rem;text-align:right;font-size:.73rem;" id="corrJBalMsg"></td></tr>
                </tfoot>
              </table>
            </div>
            <button type="button" onclick="addCorrJournalRow()" style="font-size:.77rem;background:none;border:1px solid #d97706;color:#92400e;border-radius:.3rem;padding:.2rem .5rem;cursor:pointer;margin-bottom:.35rem;">+ Add Line</button>
            <input type="hidden" id="corrJournalJson" value="<?= htmlspecialchars(json_encode(array_map(function($e){ return ['account_code'=>$e['account_code']??'','account_name'=>$e['account_name']??'','description'=>$e['description']??'','debit'=>(float)($e['debit']??0),'credit'=>(float)($e['credit']??0)]; }, $journalEntries ?: []))) ?>">
          </div>
          <?php endif; ?>

          <div class="hri-form-group" style="margin-bottom:.5rem;">
            <label class="hri-label" style="font-size:.8rem;">Correction Note (optional)</label>
            <textarea id="corrNote" class="hri-textarea" rows="2" placeholder="Briefly describe what you corrected..."></textarea>
          </div>
          <button onclick="doSubmitCorrection()" class="hri-btn hri-btn-navy" style="width:100%;font-size:.875rem;margin-top:.25rem;">&#128260; Submit Corrections for Review</button>
        </div>
        <?php endif; ?>

        <?php
        // Separate raise_payment, approve_journals, and post from others — they need dedicated input forms
        $raisePaymentTrans    = null;
        $approveJournalsTrans = null;
        $postTrans            = null;
        $otherTrans           = [];
        foreach ($availableActions as $t) {
            if ($t['action_code'] === 'raise_payment')    { $raisePaymentTrans    = $t; continue; }
            if ($t['action_code'] === 'approve_journals') { $approveJournalsTrans = $t; continue; }
            if ($t['action_code'] === 'post')             { $postTrans            = $t; continue; }
            // Petty cash disburse — needs a journal form, not a bare button
            if ($t['action_code'] === 'process' && $req['type'] === 'petty_cash') { $disburseTrans = $t; continue; }
            $otherTrans[] = $t;
        }

        // Monthly petty cash float. 50,000 is the month's budget, not a per-request
        // cap — usage is shown and an overspend warned about, never blocked.
        $pcFloat = 50000; $pcUsed = 0; $pcRemaining = 0;
        if ($disburseTrans) {
            $pcFloat = defined('IRS_PETTY_CASH_LIMIT') ? (float)IRS_PETTY_CASH_LIMIT : 50000;
            if (function_exists('getIrsConfig')) {
                $pcCfg   = getIrsConfig();
                $pcFloat = (float)($pcCfg['petty_cash_limit'] ?? $pcFloat);
            }
            try {
                // Consumed at the moment cash leaves the box, so key off the
                // disbursement date rather than when the request was raised.
                $pcQ = $db->prepare("SELECT COALESCE(SUM(COALESCE(actual_amount, amount)),0)
                    FROM irs_requests
                    WHERE type='petty_cash'
                      AND custodian_actioned_at IS NOT NULL
                      AND YEAR(custodian_actioned_at)  = YEAR(CURDATE())
                      AND MONTH(custodian_actioned_at) = MONTH(CURDATE())
                      AND status <> 'rejected'
                      AND id <> ?");
                $pcQ->execute([$requestId]);
                $pcUsed = (float)$pcQ->fetchColumn();
            } catch (Exception $e) { $pcUsed = 0; }
            $pcRemaining = $pcFloat - $pcUsed;
        }
        ?>

        <?php if ($raisePaymentTrans): ?>
        <!-- Raise Payment Form -->
        <div class="irs-action-group" style="background:#f5f3ff;border-color:#c4b5fd;">
          <div class="irs-action-label" style="color:#6d28d9;">&#128179; Raise Payment</div>
          <!-- Amount + Payment Type + Originating Bank -->
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem;margin-bottom:.6rem;" class="irs-three-col">
            <div>
              <label class="hri-label" style="font-size:.8rem;">Amount (&#8358;) <span style="color:#ef4444;">*</span></label>
              <input type="number" id="payAmt" class="hri-input" step="0.01" min="0.01"
                value="<?= htmlspecialchars((string)($req['amount'] ?? '')) ?>" placeholder="0.00">
            </div>
            <div>
              <label class="hri-label" style="font-size:.8rem;">Payment Type</label>
              <select id="payMethod" class="hri-select">
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cheque">Cheque</option>
              </select>
            </div>
            <div>
              <label class="hri-label" style="font-size:.8rem;">Originating Bank <span style="color:#ef4444;">*</span></label>
              <select id="payOriginBank" class="hri-select">
                <option value="">— Select bank —</option>
                <option value="Access Bank">Access Bank</option>
                <option value="GT Bank">GT Bank</option>
                <option value="Lotus Bank">Lotus Bank</option>
                <option value="Sterling Bank">Sterling Bank</option>
                <option value="Keystone Bank">Keystone Bank</option>
                <option value="Fidelity Bank">Fidelity Bank</option>
                <option value="First Bank">First Bank</option>
              </select>
            </div>
          </div>
          <!-- Beneficiary details (read-only, from submission) -->
          <?php
          $benefRows = [];
          if (!empty($req['beneficiaries_json'])) {
              $benefRows = json_decode($req['beneficiaries_json'], true) ?: [];
          }
          if (empty($benefRows) && !empty($req['bank'])) {
              $benefRows = [['bank'=>$req['bank'],'account_number'=>$req['account_number']??'','account_name'=>$req['account_name']??'']];
          }
          ?>
          <?php if (!empty($benefRows)): ?>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.6rem .875rem;margin-bottom:.6rem;font-size:.82rem;">
            <div style="font-weight:600;color:#475569;margin-bottom:.5rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;">Beneficiary Details</div>
            <?php foreach ($benefRows as $bi => $br): ?>
            <?php if (count($benefRows) > 1): ?>
            <div style="font-size:.73rem;font-weight:600;color:#0891b2;margin-bottom:.25rem;<?= $bi > 0 ? 'margin-top:.5rem;padding-top:.5rem;border-top:1px solid #e2e8f0;' : '' ?>">Beneficiary <?= $bi + 1 ?></div>
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:1fr 130px 1fr;gap:.4rem;<?= count($benefRows) > 1 && $bi > 0 ? '' : '' ?>" class="irs-three-col">
              <div><span style="color:#94a3b8;">Bank</span><br><strong><?= htmlspecialchars($br['bank'] ?? '—') ?></strong></div>
              <div><span style="color:#94a3b8;">Account No.</span><br><strong><?= htmlspecialchars($br['account_number'] ?? '—') ?></strong></div>
              <div><span style="color:#94a3b8;">Account Name</span><br><strong><?= htmlspecialchars($br['account_name'] ?? '—') ?></strong></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <textarea id="payComment" class="hri-textarea" rows="2" placeholder="Additional payment notes (optional)..." style="margin-bottom:.6rem;"></textarea>
          <!-- Journal Entries -->
          <div style="margin:.6rem 0 .5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
              <span style="font-size:.78rem;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:.04em;">Journal Entries <span style="font-weight:400;color:#a78bfa;font-style:italic;">(double-entry)</span></span>
              <button type="button" onclick="addJournalRow()" style="font-size:.73rem;background:none;border:1px solid #8b5cf6;color:#6d28d9;border-radius:.3rem;padding:.2rem .5rem;cursor:pointer;">+ Add Line</button>
            </div>
            <div style="overflow-x:auto;border:1px solid #ddd6fe;border-radius:.35rem;">
              <table id="journalTable" style="width:100%;border-collapse:collapse;font-size:.78rem;min-width:520px;">
                <thead>
                  <tr style="background:#f5f3ff;">
                    <th style="padding:.3rem .4rem;text-align:left;font-weight:600;color:#6d28d9;width:90px;">Account Code</th>
                    <th style="padding:.3rem .4rem;text-align:left;font-weight:600;color:#6d28d9;">Account Name *</th>
                    <th style="padding:.3rem .4rem;text-align:left;font-weight:600;color:#6d28d9;">Description / Narration</th>
                    <th style="padding:.3rem .4rem;text-align:right;font-weight:600;color:#6d28d9;width:90px;">Debit (&#8358;)</th>
                    <th style="padding:.3rem .4rem;text-align:right;font-weight:600;color:#6d28d9;width:90px;">Credit (&#8358;)</th>
                    <th style="width:26px;"></th>
                  </tr>
                </thead>
                <tbody id="journalRows"></tbody>
                <datalist id="hriCoaList">
                  <option value="10000">PETTYCASH</option>
                  <option value="11000">FIRST BANK</option>
                  <option value="13000">GTB-MAIN</option>
                  <option value="13100">GTB-SUB</option>
                  <option value="13200">GTB-COT FREE</option>
                  <option value="14100">ACCESS BANK COT FREE</option>
                  <option value="15000">STERLING MAIN</option>
                  <option value="15100">STERLING PREMIUM</option>
                  <option value="16000">FIDELITY</option>
                  <option value="17000">KEYSTONE</option>
                  <option value="17500">KEYSTONE FIXED DEPOSIT</option>
                  <option value="18100">ECOBANK NAIRA</option>
                  <option value="18110">ECOBANK DOLLAR</option>
                  <option value="18120">ECOBANK POUNDS</option>
                  <option value="18300">UBA</option>
                  <option value="18400">UNION BANK</option>
                  <option value="18500">POLARIS</option>
                  <option value="18520">LOTUS BANK</option>
                  <option value="18550">INVESTMENT A/C</option>
                  <option value="18600">ACCOUNT RECEIVABLE</option>
                  <option value="18650">CASH ADVANCE</option>
                  <option value="18660">STAFF DEBTORS</option>
                  <option value="18670">SUNDRY DEBTORS</option>
                  <option value="18680">WHT RECEIVABLE</option>
                  <option value="18700">PREPAID RENT</option>
                  <option value="18710">PREPAID INSURANCE</option>
                  <option value="21500">ADMIN PENSION PAYABLE</option>
                  <option value="21700">ADMIN STAFF NHF PAYABLE</option>
                  <option value="21900">ADMIN PAYE PAYABLE</option>
                  <option value="22000">CONTRACT STAFF SALARY PAYABLE</option>
                  <option value="22100">CONTRACT STAFF PAYE PAYABLE</option>
                  <option value="22200">CONTRACT STAFF PENSION PAYABLE</option>
                  <option value="22300">CONTRACT STAFF MEDICAL PAYABLE</option>
                  <option value="24000">CONTRACT STAFF CAUTION FEE</option>
                  <option value="25000">SUNDRY CREDITOR</option>
                  <option value="28000">ACCRUALS</option>
                  <option value="40000">CONSULTING INCOME</option>
                  <option value="40500">OTHER INCOME</option>
                  <option value="43000">MANAGEMENT FEE</option>
                  <option value="51000">TRAINING COST</option>
                  <option value="53000">RECRUITMENT COST</option>
                  <option value="60000">MEDICAL EXPENSES</option>
                  <option value="61000">ADVERTISING</option>
                  <option value="62000">BANK CHARGES</option>
                  <option value="62500">VAT EXPENSE</option>
                  <option value="64000">DEPRECIATION</option>
                  <option value="66000">SUBSCRIPTIONS &amp; DUES</option>
                  <option value="67000">PENSION EXPENSES</option>
                  <option value="67100">TRANSPORT</option>
                  <option value="67300">PAYE &amp; LIRS</option>
                  <option value="67400">INSURANCE</option>
                  <option value="67500">LEGAL &amp; PROFESSIONAL</option>
                  <option value="67600">REPAIRS &amp; MAINTENANCE</option>
                  <option value="67700">STAFF WELFARE</option>
                  <option value="67800">MEALS &amp; ENTERTAINMENT</option>
                  <option value="67900">OFFICE EXPENSES</option>
                  <option value="68000">POSTAGE</option>
                  <option value="68100">RENTAL</option>
                  <option value="68200">TELEPHONE</option>
                  <option value="68300">TRAVEL</option>
                  <option value="68400">SALARIES</option>
                  <option value="68500">LEAVE ALLOWANCE</option>
                  <option value="68600">NSITF</option>
                  <option value="68800">DIESEL &amp; FUEL</option>
                  <option value="68900">ELECTRICITY</option>
                  <option value="69000">PRINTING &amp; STATIONERY</option>
                  <option value="69100">TRAINING &amp; DEVELOPMENT</option>
                  <option value="69200">AUDIT FEE</option>
                  <option value="69300">BAD DEBT</option>
                  <option value="69400">FINES/PENALTIES</option>
                </datalist>
                <tfoot>
                  <tr style="background:#f5f3ff;font-weight:700;font-size:.78rem;">
                    <td colspan="3" style="padding:.3rem .5rem;text-align:right;color:#6d28d9;">Totals</td>
                    <td style="padding:.3rem .4rem;text-align:right;font-family:monospace;" id="jTotalDebit">&#8358;0.00</td>
                    <td style="padding:.3rem .4rem;text-align:right;font-family:monospace;" id="jTotalCredit">&#8358;0.00</td>
                    <td></td>
                  </tr>
                  <tr>
                    <td colspan="6" style="padding:.2rem .5rem;text-align:right;font-size:.73rem;" id="jBalanceMsg"></td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <input type="hidden" id="journalEntriesJson" value="[]">
          </div>
          <button onclick="doRaisePayment()" class="hri-btn" style="background:#6d28d9;color:#fff;width:100%;margin-top:.5rem;font-size:.875rem;">
            &#128179; Raise Payment
          </button>
        </div>
        <?php endif; ?>

        <?php if ($approveJournalsTrans): ?>
        <!-- Approve & Attach Journals (Retirement — HOD Accounts stage) -->
        <div class="irs-action-group" style="background:#f5f3ff;border-color:#c4b5fd;">
          <div class="irs-action-label" style="color:#6d28d9;">&#128216; Approve &amp; Attach Journal Entries</div>
          <?php
          $retAdvanceHod = (float)($req['advance_amount'] ?? $req['amount'] ?? 0);
          $retSpentHod   = (float)($req['actual_amount'] ?? 0);
          ?>
          <?php if ($retAdvanceHod > 0): ?>
          <div style="background:#fff;border:1px solid #ddd6fe;border-radius:.4rem;padding:.6rem .875rem;margin-bottom:.6rem;">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;margin-bottom:.5rem;" class="irs-three-col">
              <?php $retDiffHod = $retAdvanceHod - $retSpentHod; $diffHodCol = $retDiffHod > 0.005 ? '#dc2626' : ($retDiffHod < -0.005 ? '#0891b2' : '#059669'); ?>
              <div><div style="color:#64748b;font-size:.72rem;margin-bottom:.1rem;">Advance</div><div style="font-weight:700;">&#8358;<?= number_format($retAdvanceHod,2) ?></div></div>
              <div><div style="color:#64748b;font-size:.72rem;margin-bottom:.1rem;">Amount Spent</div><div style="font-weight:700;">&#8358;<?= number_format($retSpentHod,2) ?></div></div>
              <div><div style="color:<?= $diffHodCol ?>;font-size:.72rem;margin-bottom:.1rem;font-weight:700;"><?= $retDiffHod > 0.005 ? 'Refund Due' : ($retDiffHod < -0.005 ? 'Addl. Needed' : 'Exact Match') ?></div><div style="font-weight:800;color:<?= $diffHodCol ?>;">&#8358;<?= number_format(abs($retDiffHod),2) ?></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;" class="irs-two-col">
              <div>
                <label style="font-size:.77rem;font-weight:600;color:#6d28d9;display:block;margin-bottom:.25rem;">Reconciliation Outcome</label>
                <select id="reconcileStatus" class="hri-select" style="font-size:.82rem;">
                  <option value="">— Select outcome —</option>
                  <option value="exact"<?= ($req['reconciliation_status']??'')==='exact'?' selected':'' ?>>Exact Match</option>
                  <option value="refund_required"<?= ($req['reconciliation_status']??'')==='refund_required'?' selected':'' ?>>Refund Required</option>
                  <option value="additional_payment"<?= ($req['reconciliation_status']??'')==='additional_payment'?' selected':'' ?>>Additional Payment</option>
                </select>
              </div>
              <div>
                <label style="font-size:.77rem;font-weight:600;color:#6d28d9;display:block;margin-bottom:.25rem;">Confirm Actual Spent (&#8358;)</label>
                <input type="number" id="reconcileActualAmt" class="hri-input" step="0.01" min="0"
                  value="<?= htmlspecialchars((string)($req['actual_amount'] ?? '')) ?>"
                  placeholder="Actual amount spent..." style="font-size:.82rem;">
              </div>
            </div>
          </div>
          <?php endif; ?>
          <!-- Journal entries table -->
          <div style="margin:.3rem 0 .5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
              <span style="font-size:.78rem;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:.04em;">Journal Entries <span style="font-weight:400;color:#a78bfa;font-style:italic;">(double-entry)</span></span>
              <button type="button" onclick="addJournalRow()" style="font-size:.73rem;background:none;border:1px solid #8b5cf6;color:#6d28d9;border-radius:.3rem;padding:.2rem .5rem;cursor:pointer;">+ Add Line</button>
            </div>
            <div style="overflow-x:auto;border:1px solid #ddd6fe;border-radius:.35rem;">
              <table id="journalTable" style="width:100%;border-collapse:collapse;font-size:.78rem;min-width:520px;">
                <thead>
                  <tr style="background:#f5f3ff;">
                    <th style="padding:.3rem .4rem;text-align:left;font-weight:600;color:#6d28d9;width:90px;">Account Code</th>
                    <th style="padding:.3rem .4rem;text-align:left;font-weight:600;color:#6d28d9;">Account Name *</th>
                    <th style="padding:.3rem .4rem;text-align:left;font-weight:600;color:#6d28d9;">Description / Narration</th>
                    <th style="padding:.3rem .4rem;text-align:right;font-weight:600;color:#6d28d9;width:90px;">Debit (&#8358;)</th>
                    <th style="padding:.3rem .4rem;text-align:right;font-weight:600;color:#6d28d9;width:90px;">Credit (&#8358;)</th>
                    <th style="width:26px;"></th>
                  </tr>
                </thead>
                <tbody id="journalRows"></tbody>
                <datalist id="hriCoaList">
                  <option value="10000">PETTYCASH</option><option value="11000">FIRST BANK</option>
                  <option value="13000">GTB-MAIN</option><option value="13100">GTB-SUB</option>
                  <option value="13200">GTB-COT FREE</option><option value="14100">ACCESS BANK COT FREE</option>
                  <option value="15000">STERLING MAIN</option><option value="15100">STERLING PREMIUM</option>
                  <option value="16000">FIDELITY</option><option value="17000">KEYSTONE</option>
                  <option value="17500">KEYSTONE FIXED DEPOSIT</option><option value="18100">ECOBANK NAIRA</option>
                  <option value="18110">ECOBANK DOLLAR</option><option value="18120">ECOBANK POUNDS</option>
                  <option value="18300">UBA</option><option value="18400">UNION BANK</option>
                  <option value="18500">POLARIS</option><option value="18520">LOTUS BANK</option>
                  <option value="18550">INVESTMENT A/C</option><option value="18600">ACCOUNT RECEIVABLE</option>
                  <option value="18650">CASH ADVANCE</option><option value="18660">STAFF DEBTORS</option>
                  <option value="18670">SUNDRY DEBTORS</option><option value="18680">WHT RECEIVABLE</option>
                  <option value="18700">PREPAID RENT</option><option value="18710">PREPAID INSURANCE</option>
                  <option value="21500">ADMIN PENSION PAYABLE</option><option value="21700">ADMIN STAFF NHF PAYABLE</option>
                  <option value="21900">ADMIN PAYE PAYABLE</option><option value="22000">CONTRACT STAFF SALARY PAYABLE</option>
                  <option value="22100">CONTRACT STAFF PAYE PAYABLE</option><option value="22200">CONTRACT STAFF PENSION PAYABLE</option>
                  <option value="22300">CONTRACT STAFF MEDICAL PAYABLE</option><option value="24000">CONTRACT STAFF CAUTION FEE</option>
                  <option value="25000">SUNDRY CREDITOR</option><option value="28000">ACCRUALS</option>
                  <option value="40000">CONSULTING INCOME</option><option value="40500">OTHER INCOME</option>
                  <option value="43000">MANAGEMENT FEE</option><option value="51000">TRAINING COST</option>
                  <option value="53000">RECRUITMENT COST</option><option value="60000">MEDICAL EXPENSES</option>
                  <option value="61000">ADVERTISING</option><option value="62000">BANK CHARGES</option>
                  <option value="62500">VAT EXPENSE</option><option value="64000">DEPRECIATION</option>
                  <option value="66000">SUBSCRIPTIONS &amp; DUES</option><option value="67000">PENSION EXPENSES</option>
                  <option value="67100">TRANSPORT</option><option value="67300">PAYE &amp; LIRS</option>
                  <option value="67400">INSURANCE</option><option value="67500">LEGAL &amp; PROFESSIONAL</option>
                  <option value="67600">REPAIRS &amp; MAINTENANCE</option><option value="67700">STAFF WELFARE</option>
                  <option value="67800">MEALS &amp; ENTERTAINMENT</option><option value="67900">OFFICE EXPENSES</option>
                  <option value="68000">POSTAGE</option><option value="68100">RENTAL</option>
                  <option value="68200">TELEPHONE</option><option value="68300">TRAVEL</option>
                  <option value="68400">SALARIES</option><option value="68500">LEAVE ALLOWANCE</option>
                  <option value="68600">NSITF</option><option value="68800">DIESEL &amp; FUEL</option>
                  <option value="68900">ELECTRICITY</option><option value="69000">PRINTING &amp; STATIONERY</option>
                  <option value="69100">TRAINING &amp; DEVELOPMENT</option><option value="69200">AUDIT FEE</option>
                  <option value="69300">BAD DEBT</option><option value="69400">FINES/PENALTIES</option>
                </datalist>
                <tfoot>
                  <tr style="background:#f5f3ff;font-weight:700;font-size:.78rem;">
                    <td colspan="3" style="padding:.3rem .5rem;text-align:right;color:#6d28d9;">Totals</td>
                    <td style="padding:.3rem .4rem;text-align:right;font-family:monospace;" id="jTotalDebit">&#8358;0.00</td>
                    <td style="padding:.3rem .4rem;text-align:right;font-family:monospace;" id="jTotalCredit">&#8358;0.00</td>
                    <td></td>
                  </tr>
                  <tr><td colspan="6" style="padding:.2rem .5rem;text-align:right;font-size:.73rem;" id="jBalanceMsg"></td></tr>
                </tfoot>
              </table>
            </div>
            <input type="hidden" id="journalEntriesJson" value="[]">
          </div>
          <textarea id="approveJournalComment" class="hri-textarea" rows="2" placeholder="Approval notes (optional)..." style="margin-bottom:.5rem;"></textarea>
          <button onclick="doApproveJournals()" class="hri-btn" style="background:#6d28d9;color:#fff;width:100%;font-size:.875rem;">
            &#9989; Approve &amp; Attach Journal Entries
          </button>
        </div>
        <?php endif; ?>

        <?php if ($disburseTrans):
          $pcPct = $pcFloat > 0 ? min(100, ($pcUsed / $pcFloat) * 100) : 0;
          $pcBar = $pcPct >= 100 ? '#ef4444' : ($pcPct >= 80 ? '#f59e0b' : '#059669');
        ?>
        <!-- Petty cash: enter the journal and hand over the cash -->
        <div class="irs-action-group" style="background:#fffbeb;border-color:#fcd34d;">
          <div class="irs-action-label" style="color:#92400e;">&#128176; Disburse Petty Cash</div>

          <!-- Monthly float usage — advisory, never blocking -->
          <div style="background:#fff;border:1px solid #fde68a;border-radius:.4rem;padding:.55rem .7rem;margin-bottom:.7rem;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;font-size:.76rem;margin-bottom:.3rem;">
              <span style="font-weight:700;color:#92400e;">Float &mdash; <?= date('F Y') ?></span>
              <span style="color:#64748b;font-variant-numeric:tabular-nums;">&#8358;<?= number_format($pcUsed,2) ?> of &#8358;<?= number_format($pcFloat,2) ?></span>
            </div>
            <div style="height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;">
              <div style="height:100%;width:<?= number_format($pcPct,1) ?>%;background:<?= $pcBar ?>;"></div>
            </div>
            <div style="font-size:.73rem;color:<?= $pcRemaining < 0 ? '#ef4444' : '#64748b' ?>;margin-top:.28rem;font-variant-numeric:tabular-nums;">
              <?php if ($pcRemaining >= 0): ?>
                &#8358;<?= number_format($pcRemaining,2) ?> remaining this month
              <?php else: ?>
                &#9888; Float exceeded by &#8358;<?= number_format(abs($pcRemaining),2) ?>
              <?php endif; ?>
            </div>
          </div>

          <label class="hri-label" style="font-size:.8rem;">Amount Disbursed (&#8358;) <span style="color:#ef4444;">*</span></label>
          <input type="number" id="pcAmount" class="hri-input" step="0.01" min="0.01"
                 value="<?= htmlspecialchars((string)$req['amount']) ?>"
                 oninput="pcCheckFloat()" style="margin-bottom:.2rem;">
          <div id="pcOverWarn" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:.35rem;padding:.4rem .6rem;font-size:.76rem;color:#b91c1c;margin-bottom:.5rem;"></div>

          <!-- Journal entries — same double-entry table as the other flows -->
          <div style="margin:.6rem 0 .5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.35rem;">
              <span style="font-size:.78rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;">Journal Entries <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#b45309;">(double-entry)</span></span>
              <button type="button" onclick="addJournalRow()" style="font-size:.73rem;background:none;border:1px solid #d97706;color:#92400e;border-radius:.3rem;padding:.2rem .5rem;cursor:pointer;">+ Add Line</button>
            </div>
            <div style="overflow-x:auto;">
              <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                <thead>
                  <tr style="background:#fef3c7;">
                    <th style="padding:.25rem .3rem;text-align:left;color:#92400e;font-weight:600;width:76px;">Code</th>
                    <th style="padding:.25rem .3rem;text-align:left;color:#92400e;font-weight:600;">Account Name</th>
                    <th style="padding:.25rem .3rem;text-align:left;color:#92400e;font-weight:600;">Narration</th>
                    <th style="padding:.25rem .3rem;text-align:right;color:#92400e;font-weight:600;width:84px;">Debit (&#8358;)</th>
                    <th style="padding:.25rem .3rem;text-align:right;color:#92400e;font-weight:600;width:84px;">Credit (&#8358;)</th>
                    <th style="width:24px;"></th>
                  </tr>
                </thead>
                <tbody id="journalRows"></tbody>
                <tfoot>
                  <tr style="background:#fef3c7;font-weight:700;font-size:.77rem;">
                    <td colspan="3" style="padding:.28rem .3rem;text-align:right;color:#92400e;">Totals</td>
                    <td style="padding:.28rem .3rem;text-align:right;font-family:monospace;" id="jTotalDebit">&#8358;0.00</td>
                    <td style="padding:.28rem .3rem;text-align:right;font-family:monospace;" id="jTotalCredit">&#8358;0.00</td>
                    <td></td>
                  </tr>
                  <tr><td colspan="6" style="padding:.15rem .3rem;text-align:right;font-size:.73rem;" id="jBalanceMsg"></td></tr>
                </tfoot>
              </table>
            </div>
            <input type="hidden" id="journalEntriesJson" value="[]">
          </div>

          <textarea id="pcComment" class="hri-textarea" rows="2" placeholder="Disbursement note (optional)..." style="margin-bottom:.5rem;"></textarea>
          <button onclick="doDisburse()" class="hri-btn" style="background:#d97706;color:#fff;width:100%;font-size:.875rem;">
            &#128176; <?= htmlspecialchars($disburseTrans['action_label']) ?>
          </button>
        </div>
        <?php endif; ?>

        <?php if ($postTrans): ?>
        <!-- Post to Sage Form -->
        <div class="irs-action-group" style="background:#f0fdf4;border-color:#86efac;">
          <div class="irs-action-label" style="color:#166534;">&#127881; Post to Sage</div>
          <input type="text" id="sageRefInput" class="hri-input" placeholder="Enter Sage reference number..." style="margin-bottom:.5rem;">
          <button onclick="doPostSage()" class="hri-btn" style="background:#059669;color:#fff;width:100%;font-size:.875rem;">Post &amp; Close Request</button>
        </div>
        <?php endif; ?>

        <?php if (!empty($otherTrans)): ?>
        <!-- Standard approve/reject/etc actions with shared comment -->
        <div class="irs-action-group">
          <div class="irs-action-label">Review Action</div>

          <?php if ($req['type'] === 'payment'): ?>
          <?php
          $jpD = 0; $jpC = 0;
          foreach ($journalEntries as $je) { $jpD += (float)$je['debit']; $jpC += (float)$je['credit']; }
          $jpBalanced = abs($jpD - $jpC) < 0.01;
          ?>
          <div style="margin-bottom:.7rem;padding:.55rem .7rem;border-radius:.4rem;<?= !empty($journalEntries) ? 'background:#f5f3ff;border:1px solid #a78bfa;' : 'background:#fef3c7;border:1px solid #fbbf24;' ?>">
            <?php if (!empty($journalEntries)): ?>
            <div style="font-size:.76rem;font-weight:600;color:#6d28d9;margin-bottom:.15rem;">&#128216; <?= count($journalEntries) ?> journal line<?= count($journalEntries) > 1 ? 's' : '' ?> submitted</div>
            <div style="font-size:.72rem;color:<?= $jpBalanced ? '#059669' : '#ef4444' ?>;">
              <?= $jpBalanced
                ? '&#10003; Balanced &mdash; Dr &amp; Cr &#8358;' . number_format($jpD, 2)
                : '&#9888; Out of balance by &#8358;' . number_format(abs($jpD - $jpC), 2) ?>
            </div>
            <?php else: ?>
            <div style="font-size:.76rem;font-weight:600;color:#b45309;margin-bottom:.1rem;">&#9888; No journal entries submitted</div>
            <div style="font-size:.72rem;color:#92400e;">Send back for corrections before approving.</div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php
          // Retirement reconciliation only in standard approve form (not when approve_journals form is shown)
          $needsReconcile = ($req['type'] === 'retirement' && $req['status'] === 'pending_hod_accounts' && $approveJournalsTrans === null);
          ?>
          <?php if ($needsReconcile): ?>
          <div style="margin-bottom:.6rem;">
            <label class="hri-label" style="font-size:.8rem;">Reconciliation Outcome <span style="color:#ef4444;">*</span></label>
            <select id="reconcileStatus" class="hri-select" style="margin-bottom:.4rem;">
              <option value="">— Select outcome —</option>
              <option value="exact">Exact Match (no difference)</option>
              <option value="refund_required">Refund Required (spent less than advance)</option>
              <option value="additional_payment">Additional Payment (spent more than advance)</option>
            </select>
            <label class="hri-label" style="font-size:.8rem;">Actual Amount Spent (&#8358;)</label>
            <input type="number" id="reconcileActualAmt" class="hri-input" step="0.01" min="0"
              value="<?= htmlspecialchars((string)($req['actual_amount'] ?? $req['amount'] ?? '')) ?>"
              placeholder="Enter actual amount spent...">
            <?php if (!empty($req['advance_amount'])): ?>
            <div style="font-size:.76rem;color:#64748b;margin-top:.2rem;">Advance: &#8358;<?= number_format((float)$req['advance_amount'],2) ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <textarea id="actionComment" class="hri-textarea" rows="2" placeholder="Comment (required to reject)..."></textarea>

          <?php
          $btnColors = ['success'=>'#10b981','danger'=>'#ef4444','warning'=>'#f59e0b','primary'=>'#002850'];
          ?>
          <div class="irs-btn-row">
            <?php foreach ($otherTrans as $t): ?>
            <?php $col = $btnColors[$t['btn_style']] ?? '#002850'; ?>
            <button onclick="doFlowAction('<?= htmlspecialchars($t['action_code']) ?>',<?= $t['requires_note'] ? 'true' : 'false' ?><?= $needsReconcile && $t['btn_style']==='success' ? ',true' : ',false' ?>)"
                class="hri-btn" style="background:<?= $col ?>;color:#fff;font-size:.85rem;flex:1;min-width:100px;">
              <?= htmlspecialchars($t['action_label']) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($showWithdraw): ?>
        <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid #f1f5f9;">
          <button onclick="doSpecial('withdraw')" class="hri-btn hri-btn-outline" style="width:100%;font-size:.85rem;color:#ef4444;border-color:#fca5a5;">
            &#10006; Withdraw Request
          </button>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- ── APPROVAL PROGRESS ──────────────────────────────────────────── -->
      <div class="hri-card">
        <div class="hri-card-hd"><h2 class="hri-card-title">&#128203; Approval Progress</h2></div>
        <?php
        // Map stage codes to the relevant audit/column data
        $stageActorMap = [
            'pending_hod_accounts'          => ['actor' => getNameById($db,(int)$req['accounts_reviewer_id']),       'at' => $req['accounts_reviewed_at']       ?? null],
            'pending_eligibility'           => ['actor' => getNameById($db,(int)$req['eligibility_reviewer_id']),    'at' => $req['eligibility_reviewed_at']    ?? null],
            'pending_md'                    => ['actor' => getNameById($db,(int)$req['manager_approver_id']),        'at' => $req['manager_approved_at']        ?? null],
            'pending_payment'               => ['actor' => getNameById($db,(int)$req['payment_raised_by']),          'at' => $req['payment_raised_at']          ?? null],
            'pending_hod_accounts_payment'  => ['actor' => getNameById($db,(int)($req['hod_payment_approved_by']??0)), 'at' => $req['hod_payment_approved_at']  ?? null],
            'pending_payment_approval'      => ['actor' => getNameById($db,(int)$req['payment_approval_by']),        'at' => $req['payment_approval_at']        ?? null],
            'pending_accountant'            => ['actor' => getNameById($db,(int)$req['custodian_id']),               'at' => $req['custodian_actioned_at']      ?? null],
            'pending_post'                  => ['actor' => getNameById($db,(int)$req['sage_posted_by']),             'at' => $req['sage_posted_at']             ?? null],
        ];
        $stageOrderList = array_filter($flowStages, function($s) { return !$s['is_initial']; });
        $currentStageOrder = 0;
        foreach ($flowStages as $fs) { if ($fs['stage_code'] === $st) { $currentStageOrder = $fs['stage_order']; break; } }

        foreach ($stageOrderList as $fs):
            $code = $fs['stage_code'];
            if ($fs['is_terminal']) continue; // skip completed/rejected in progress
            $info   = $stageActorMap[$code] ?? ['actor'=>'—','at'=>null];
            $isDone = $fs['stage_order'] < $currentStageOrder || $st === 'completed';
            $isRej  = $st === 'rejected';
            $isCur  = $code === $st;
            if ($isCur && $isRej) { $dot='&#10060;'; $dotBg='#fee2e2'; $dotColor='#ef4444'; }
            elseif ($isDone)      { $dot='&#10003;'; $dotBg='#dcfce7'; $dotColor='#059669'; }
            elseif ($isCur)       { $dot='&#9654;';  $dotBg='#eff6ff'; $dotColor='#3b82f6'; }
            else                  { $dot='&#9675;';  $dotBg='#f1f5f9'; $dotColor='#94a3b8'; }
        ?>
        <?php
            // Who owns this stage — shown until someone actually actions it, at
            // which point their name takes the slot. head_it/it_admin are the
            // admin catch-all in every actor list, so they never read as owners.
            $ownerText = '';
            if (!$isDone) {
                $ownerRoles = json_decode($fs['actor_roles'] ?? '[]', true) ?: [];
                $ownerNames = [];
                foreach ($ownerRoles as $rk) {
                    if (in_array($rk, ['head_it', 'it_admin'])) continue;
                    $ownerNames[] = IRS_STAGE_OWNERS[$rk]
                        ?? (defined('ROLES') && isset(ROLES[$rk]) ? ROLES[$rk]['label'] : ucwords(str_replace('_', ' ', $rk)));
                }
                $ownerNames = array_values(array_unique($ownerNames));
                if ($ownerNames) $ownerText = implode(' or ', array_slice($ownerNames, 0, 2));
            }
            $rowCls = $isDone ? 'is-done' : ($isCur ? 'is-cur' : 'is-wait');
        ?>
        <div class="irs-stage-row <?= $rowCls ?>">
          <div class="irs-stage-dot" style="background:<?= $dotBg ?>;color:<?= $dotColor ?>;"><?= $dot ?></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:.84rem;font-weight:<?= $isCur ? '700' : '500' ?>;color:<?= $isCur ? '#002850' : ($isDone ? 'inherit' : '#94a3b8') ?>;"><?= htmlspecialchars($fs['stage_label']) ?></div>
            <?php if ($isDone && $info['actor'] !== '—' && !empty($info['at'])): ?>
            <div style="font-size:.75rem;color:#64748b;"><?= htmlspecialchars($info['actor']) ?> — <?= date('d M Y, g:ia', strtotime($info['at'])) ?></div>
            <?php if ($code === 'pending_post' && !empty($req['sage_ref'])): ?>
            <div style="font-size:.75rem;color:#059669;font-family:monospace;">Sage: <?= htmlspecialchars($req['sage_ref']) ?></div>
            <?php endif; ?>
            <?php elseif ($ownerText !== ''): ?>
            <div class="irs-stage-owner"><?= htmlspecialchars($ownerText) ?></div>
            <?php endif; ?>
            <?php if ($code === 'pending_corrections' && !empty($req['rejection_reason'])): ?>
            <div class="irs-stage-reason"><strong>Reason:</strong> <?= nl2br(htmlspecialchars($req['rejection_reason'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
var requestId = <?= $requestId ?>;
var reqType   = <?= json_encode($req['type']) ?>;

function doFlowAction(actionCode, requireNote, requireReconcile) {
    var comment = (document.getElementById('actionComment') || {value:''}).value.trim();
    if (requireNote && comment === '') { alert('Please provide a comment or reason.'); return; }
    var fd = new FormData();
    fd.append('action', actionCode);
    fd.append('id', requestId);
    if (comment) fd.append('comment', comment);
    if (requireReconcile) {
        var recSel = document.getElementById('reconcileStatus');
        if (recSel && !recSel.value) { alert('Please select the reconciliation outcome.'); return; }
        if (recSel) fd.append('reconciliation_status', recSel.value);
        var recAmt = document.getElementById('reconcileActualAmt');
        if (recAmt && recAmt.value) fd.append('actual_amount', recAmt.value);
    }
    sendAction(fd, null);
}

function doSpecial(actionCode, commentId) {
    var comment = commentId ? (document.getElementById(commentId) || {value:''}).value.trim() : '';
    if (actionCode === 'withdraw' && !confirm('Are you sure you want to withdraw this request?')) return;
    var fd = new FormData();
    fd.append('action', actionCode);
    fd.append('id', requestId);
    if (comment) fd.append('comment', comment);
    sendAction(fd, actionCode === 'withdraw' ? 'irs.php' : null);
}

function doRaisePayment() {
    var amt = document.getElementById('payAmt').value;
    if (!amt || parseFloat(amt) <= 0) { alert('Please enter the payment amount.'); return; }
    var originBank = document.getElementById('payOriginBank').value;
    if (!originBank) { alert('Please select the originating bank.'); return; }
    var jData = JSON.parse(document.getElementById('journalEntriesJson').value || '[]');
    var jValid = jData.filter(function(l) { return l.account_name && l.account_name.trim(); });
    if (jValid.length < 2) { alert('Journal entries are required. Please add at least 2 lines (double-entry).'); return; }
    var totD = jValid.reduce(function(s,l){return s+(parseFloat(l.debit)||0);},0);
    var totC = jValid.reduce(function(s,l){return s+(parseFloat(l.credit)||0);},0);
    if (Math.abs(totD - totC) >= 0.01) { alert('Journal entries must balance — Debit (₦' + totD.toFixed(2) + ') ≠ Credit (₦' + totC.toFixed(2) + ').'); return; }
    var fd = new FormData();
    fd.append('action', 'raise_payment');
    fd.append('id', requestId);
    fd.append('actual_amount', amt);
    fd.append('payment_method', document.getElementById('payMethod').value);
    fd.append('originating_bank', originBank);
    var cmt = document.getElementById('payComment').value.trim();
    if (cmt) fd.append('comment', cmt);
    fd.append('journal_entries', document.getElementById('journalEntriesJson').value || '[]');
    sendAction(fd, null);
}

function doApproveJournals() {
    var jData = JSON.parse((document.getElementById('journalEntriesJson') || {value:'[]'}).value || '[]');
    var jValid = jData.filter(function(l) { return l.account_name && l.account_name.trim(); });
    if (jValid.length < 2) { alert('Please add at least 2 journal entry lines (double-entry) before approving.'); return; }
    var totD = jValid.reduce(function(s,l){return s+(parseFloat(l.debit)||0);},0);
    var totC = jValid.reduce(function(s,l){return s+(parseFloat(l.credit)||0);},0);
    if (Math.abs(totD - totC) >= 0.01) { alert('Journal entries must balance — Debit (₦' + totD.toFixed(2) + ') ≠ Credit (₦' + totC.toFixed(2) + ').'); return; }
    var fd = new FormData();
    fd.append('action', 'approve_journals');
    fd.append('id', requestId);
    var cmt = (document.getElementById('approveJournalComment') || {value:''}).value.trim();
    if (cmt) fd.append('comment', cmt);
    var recSel = document.getElementById('reconcileStatus');
    if (recSel && recSel.value) fd.append('reconciliation_status', recSel.value);
    var recAmt = document.getElementById('reconcileActualAmt');
    if (recAmt && recAmt.value) fd.append('actual_amount', recAmt.value);
    fd.append('journal_entries', (document.getElementById('journalEntriesJson') || {value:'[]'}).value || '[]');
    sendAction(fd, null);
}

function doPostSage() {
    var sage = document.getElementById('sageRefInput').value.trim();
    if (!sage) { alert('Please enter the Sage reference number.'); return; }
    var fd = new FormData();
    fd.append('action', 'post');
    fd.append('id', requestId);
    fd.append('sage_ref', sage);
    sendAction(fd, null);
}

// ── Chart of Accounts lookup ─────────────────────────────────────────────
var COA = {
    '10000':'PETTYCASH','11000':'FIRST BANK','13000':'GTB-MAIN','13100':'GTB-SUB',
    '13200':'GTB-COT FREE','14100':'ACCESS BANK COT FREE','15000':'STERLING MAIN',
    '15100':'STERLING PREMIUM','16000':'FIDELITY','17000':'KEYSTONE',
    '17500':'KEYSTONE FIXED DEPOSIT','18100':'ECOBANK NAIRA','18110':'ECOBANK DOLLAR',
    '18120':'ECOBANK POUNDS','18300':'UBA','18400':'UNION BANK','18500':'POLARIS',
    '18520':'LOTUS BANK','18550':'INVESTMENT A/C','18600':'ACCOUNT RECEIVABLE',
    '18650':'CASH ADVANCE','18660':'STAFF DEBTORS','18670':'SUNDRY DEBTORS',
    '18680':'WHT RECEIVABLE','18700':'PREPAID RENT','18710':'PREPAID INSURANCE',
    '21500':'ADMIN PENSION PAYABLE','21700':'ADMIN STAFF NHF PAYABLE',
    '21900':'ADMIN PAYE PAYABLE','22000':'CONTRACT STAFF SALARY PAYABLE',
    '22100':'CONTRACT STAFF PAYE PAYABLE','22200':'CONTRACT STAFF PENSION PAYABLE',
    '22300':'CONTRACT STAFF MEDICAL PAYABLE','24000':'CONTRACT STAFF CAUTION FEE',
    '25000':'SUNDRY CREDITOR','28000':'ACCRUALS',
    '40000':'CONSULTING INCOME','40500':'OTHER INCOME','43000':'MANAGEMENT FEE',
    '51000':'TRAINING COST','53000':'RECRUITMENT COST',
    '60000':'MEDICAL EXPENSES','61000':'ADVERTISING','62000':'BANK CHARGES',
    '62500':'VAT EXPENSE','64000':'DEPRECIATION','66000':'SUBSCRIPTIONS & DUES',
    '67000':'PENSION EXPENSES','67100':'TRANSPORT','67300':'PAYE & LIRS',
    '67400':'INSURANCE','67500':'LEGAL & PROFESSIONAL','67600':'REPAIRS & MAINTENANCE',
    '67700':'STAFF WELFARE','67800':'MEALS & ENTERTAINMENT','67900':'OFFICE EXPENSES',
    '68000':'POSTAGE','68100':'RENTAL','68200':'TELEPHONE','68300':'TRAVEL',
    '68400':'SALARIES','68500':'LEAVE ALLOWANCE','68600':'NSITF','68800':'DIESEL & FUEL',
    '68900':'ELECTRICITY','69000':'PRINTING & STATIONERY','69100':'TRAINING & DEVELOPMENT',
    '69200':'AUDIT FEE','69300':'BAD DEBT','69400':'FINES/PENALTIES'
};

<?php if ($disburseTrans): ?>
// ── Petty cash disbursement ───────────────────────────────────────────────
var PC_FLOAT     = <?= json_encode((float)$pcFloat) ?>;
var PC_USED      = <?= json_encode((float)$pcUsed) ?>;
var PC_REMAINING = <?= json_encode((float)$pcRemaining) ?>;

function _pcFmt(n) {
    return '₦' + Number(n).toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// Advisory only — the float is a budget, not a hard cap, so this never blocks.
function pcCheckFloat() {
    var amt  = parseFloat((document.getElementById('pcAmount') || {value:0}).value) || 0;
    var warn = document.getElementById('pcOverWarn');
    if (!warn) return;
    var over = (PC_USED + amt) - PC_FLOAT;
    if (amt > 0 && over > 0.005) {
        warn.innerHTML = '&#9888; This disburses ' + _pcFmt(amt) + ', taking the month to '
            + _pcFmt(PC_USED + amt) + ' &mdash; ' + _pcFmt(over) + ' over the '
            + _pcFmt(PC_FLOAT) + ' float. You can still proceed.';
        warn.style.display = '';
    } else {
        warn.style.display = 'none';
    }
}

function doDisburse() {
    var amt = (document.getElementById('pcAmount') || {value:''}).value;
    if (!amt || parseFloat(amt) <= 0) { alert('Please enter the amount disbursed.'); return; }

    updateJournal();
    var jData  = JSON.parse((document.getElementById('journalEntriesJson') || {value:'[]'}).value || '[]');
    var jValid = jData.filter(function(l) { return l.account_name && l.account_name.trim(); });
    if (jValid.length < 2) { alert('Please enter at least 2 journal lines (double-entry) before disbursing.'); return; }
    var totD = jValid.reduce(function(s,l){ return s + (parseFloat(l.debit)  || 0); }, 0);
    var totC = jValid.reduce(function(s,l){ return s + (parseFloat(l.credit) || 0); }, 0);
    if (Math.abs(totD - totC) >= 0.01) {
        alert('Journal entries must balance — Debit (' + _pcFmt(totD) + ') ≠ Credit (' + _pcFmt(totC) + ').');
        return;
    }
    if (Math.abs(totD - parseFloat(amt)) >= 0.01) {
        if (!confirm('The journal total (' + _pcFmt(totD) + ') does not match the amount disbursed ('
            + _pcFmt(parseFloat(amt)) + ').\n\nDisburse anyway?')) return;
    }

    var fd = new FormData();
    fd.append('action', 'process');
    fd.append('id', requestId);
    fd.append('actual_amount', amt);
    fd.append('journal_entries', JSON.stringify(jValid));
    var c = document.getElementById('pcComment');
    if (c && c.value.trim()) fd.append('comment', c.value.trim());
    sendAction(fd, null);
}
<?php endif; ?>

// ── Chart of Accounts pickers ─────────────────────────────────────────────
// The inline <datalist> only renders inside the raise-payment / approve-journals
// forms, so build them from COA here to make the pickers available on every
// stage (corrections included). Skipped if an inline list is already present.
(function buildCoaLists() {
    function make(id, keyIsCode) {
        if (document.getElementById(id)) return;
        var dl = document.createElement('datalist');
        dl.id = id;
        Object.keys(COA).forEach(function(code) {
            var o = document.createElement('option');
            o.value = keyIsCode ? code : COA[code];
            o.label = keyIsCode ? COA[code] : code;
            dl.appendChild(o);
        });
        document.body.appendChild(dl);
    }
    make('hriCoaList', true);       // pick by account code
    make('hriCoaNameList', false);  // pick by account name
})();

// Re-run the totals for whichever journal table the input belongs to
function _coaSync(inp) {
    if (inp.closest && inp.closest('#corrJournalRows')) {
        if (typeof updateCorrJournal === 'function') updateCorrJournal();
    } else if (typeof updateJournal === 'function') {
        updateJournal();
    }
}
// Code chosen → fill the account name
function _coaFillFromCode(inp) {
    var v = inp.value.trim(), row = inp.closest('tr');
    if (COA[v] && row) {
        var nameInp = row.querySelectorAll('input')[1];
        if (nameInp && !nameInp.value.trim()) nameInp.value = COA[v];
    }
    _coaSync(inp);
}
// Account name chosen → fill the code
function _coaFillFromName(inp) {
    var v = inp.value.trim().toUpperCase(), row = inp.closest('tr');
    if (v && row) {
        for (var c in COA) {
            if (COA[c] === v) {
                var codeInp = row.querySelectorAll('input')[0];
                if (codeInp && !codeInp.value.trim()) codeInp.value = c;
                break;
            }
        }
    }
    _coaSync(inp);
}

// ── Journal entry table ───────────────────────────────────────────────────
var _jCount = 0;
function _je(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
function _jeAccFill(codeInp) {
    var v = codeInp.value.trim();
    if (COA[v]) {
        var row = codeInp.closest('tr');
        if (row) {
            var nameInp = row.querySelectorAll('input')[1];
            if (nameInp && !nameInp.value.trim()) nameInp.value = COA[v];
        }
    }
    updateJournal();
}
function addJournalRow(code, name, desc, debit, credit) {
    var tbody = document.getElementById('journalRows');
    if (!tbody) return;
    var i = ++_jCount;
    var coaInp = function(val) {
        return '<input list="hriCoaList" value="'+_je(val||'')+'" placeholder="Code" style="width:100%;font-size:.77rem;border:1px solid #ddd6fe;border-radius:.22rem;padding:.22rem .3rem;background:#fff;box-sizing:border-box;" oninput="_jeAccFill(this)" onchange="_jeAccFill(this)">';
    };
    var inp = function(val, ph) {
        return '<input value="'+_je(val||'')+'" placeholder="'+_je(ph||'')+'" style="width:100%;font-size:.77rem;border:1px solid #ddd6fe;border-radius:.22rem;padding:.22rem .3rem;background:#fff;box-sizing:border-box;" onchange="updateJournal()" oninput="updateJournal()">';
    };
    var nameInp = function(val, ph) {
        return '<input list="hriCoaNameList" value="'+_je(val||'')+'" placeholder="'+_je(ph||'')+'" style="width:100%;font-size:.77rem;border:1px solid #ddd6fe;border-radius:.22rem;padding:.22rem .3rem;background:#fff;box-sizing:border-box;" onchange="_coaFillFromName(this)" oninput="_coaFillFromName(this)">';
    };
    var numInp = function(val, ph) {
        return '<input type="number" min="0" step="0.01" value="'+_je(val||'')+'" placeholder="'+_je(ph||'0.00')+'" style="width:100%;font-size:.77rem;border:1px solid #ddd6fe;border-radius:.22rem;padding:.22rem .3rem;text-align:right;background:#fff;box-sizing:border-box;" onchange="updateJournal()" oninput="updateJournal()">';
    };
    var tr = document.createElement('tr');
    tr.id = 'jrow_' + i;
    tr.style.borderBottom = '1px solid #ede9fe';
    tr.innerHTML =
        '<td style="padding:.22rem .3rem;">'+coaInp(code)+'</td>'+
        '<td style="padding:.22rem .3rem;">'+nameInp(name,'Account name (required)')+'</td>'+
        '<td style="padding:.22rem .3rem;">'+inp(desc,'Narration / description')+'</td>'+
        '<td style="padding:.22rem .3rem;">'+numInp(debit)+'</td>'+
        '<td style="padding:.22rem .3rem;">'+numInp(credit)+'</td>'+
        '<td style="padding:.22rem .2rem;text-align:center;"><button type="button" onclick="document.getElementById(\'jrow_'+i+'\').remove();updateJournal();" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:1.05rem;line-height:1;padding:0;">&times;</button></td>';
    tbody.appendChild(tr);
    updateJournal();
}
function updateJournal() {
    var rows = document.querySelectorAll('#journalRows tr');
    var lines = [], totD = 0, totC = 0;
    rows.forEach(function(tr) {
        var inputs = tr.querySelectorAll('input');
        if (inputs.length < 5) return;
        var code = inputs[0].value.trim(), name = inputs[1].value.trim();
        var desc = inputs[2].value.trim();
        var debit = parseFloat(inputs[3].value) || 0;
        var credit = parseFloat(inputs[4].value) || 0;
        if (name) { lines.push({account_code:code,account_name:name,description:desc,debit:debit,credit:credit}); totD += debit; totC += credit; }
    });
    var jEl = document.getElementById('journalEntriesJson');
    if (jEl) jEl.value = JSON.stringify(lines);
    var dEl = document.getElementById('jTotalDebit'), cEl = document.getElementById('jTotalCredit'), bEl = document.getElementById('jBalanceMsg');
    if (dEl) dEl.textContent = '₦' + totD.toFixed(2);
    if (cEl) cEl.textContent = '₦' + totC.toFixed(2);
    if (bEl) {
        if (!lines.length) { bEl.innerHTML = ''; }
        else if (Math.abs(totD - totC) < 0.005) { bEl.innerHTML = '<span style="color:#059669;font-weight:700;">&#10003; Balanced</span>'; }
        else { bEl.innerHTML = '<span style="color:#ef4444;font-weight:700;">&#9888; Out of balance by &#8358;' + Math.abs(totD-totC).toFixed(2) + '</span>'; }
    }
}
// Seed journal rows from existing data or start with two blank rows
(function() {
    var existing = <?= json_encode(array_map(function($e) {
        return ['account_code'=>$e['account_code'],'account_name'=>$e['account_name'],'description'=>$e['description'],'debit'=>$e['debit'],'credit'=>$e['credit']];
    }, $journalEntries)) ?>;
    if (document.getElementById('journalRows')) {
        if (existing.length > 0) {
            existing.forEach(function(e) { addJournalRow(e.account_code, e.account_name, e.description, e.debit, e.credit); });
        } else {
            addJournalRow(); addJournalRow();
        }
    }
    // Petty cash: flag straight away if the pre-filled amount already
    // takes the month past the float
    if (typeof pcCheckFloat === 'function') pcCheckFloat();
})();

function sendAction(fd, redirect) {
    fetch('api/irs/action.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'X-CSRF-Token': window.CSRF_TOKEN},
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            window.location.href = redirect || ('irs-detail.php?id=' + requestId);
        } else {
            alert(data.error || 'Action failed. Please try again.');
        }
    })
    .catch(function() { alert('Network error. Please check your connection.'); });
}

// ── Correction form functions ─────────────────────────────────────────────
<?php if ($showCorrections): ?>
var _corrBenefCount = <?= max(1, count($corrBenefRows)) ?>;

function doSubmitCorrection() {
    var desc = (document.getElementById('corrDesc') || {value:''}).value.trim();
    if (!desc) { alert('Please provide a description.'); return; }
    var amt = (document.getElementById('corrAmount') || {value:'0'}).value;
    if (!amt || parseFloat(amt) <= 0) { alert('Please enter a valid amount.'); return; }
    updateCorrBenef();
    var corrJEl = document.getElementById('corrJournalJson');
    if (corrJEl) {
        updateCorrJournal();
        var jData = JSON.parse(corrJEl.value || '[]');
        var jValid = jData.filter(function(l) { return l.account_name && l.account_name.trim(); });
        if (jValid.length < 2) { alert('A Payment Request requires at least 2 journal entry lines. Please complete the Journal Entries section.'); return; }
        var totD = jValid.reduce(function(s,l){return s+(parseFloat(l.debit)||0);},0);
        var totC = jValid.reduce(function(s,l){return s+(parseFloat(l.credit)||0);},0);
        if (Math.abs(totD - totC) >= 0.01) { alert('Journal entries must balance. Debit (₦'+totD.toFixed(2)+') ≠ Credit (₦'+totC.toFixed(2)+').'); return; }
    }
    var fd = new FormData();
    fd.append('action', 'submit_correction');
    fd.append('id', requestId);
    fd.append('description', desc);
    fd.append('amount', amt);
    var notes = document.getElementById('corrNotes');
    if (notes) fd.append('notes', notes.value.trim());
    var corrNote = document.getElementById('corrNote');
    if (corrNote && corrNote.value.trim()) fd.append('correction_note', corrNote.value.trim());
    var bj = document.getElementById('corrBenefJson');
    if (bj) fd.append('beneficiaries_json', bj.value || '[]');
    if (corrJEl) fd.append('journal_entries_json', corrJEl.value || '[]');
    sendAction(fd, null);
}

function addCorrBenef() {
    var i = _corrBenefCount++;
    var div = document.createElement('div');
    div.id = 'cbr_' + i;
    div.className = 'corr-benef-row';
    div.style.cssText = 'display:grid;grid-template-columns:1fr 120px 1fr 28px;gap:.4rem;margin-bottom:.4rem;';
    var s = 'width:100%;font-size:.82rem;padding:.4rem .5rem;border:1px solid #d1d5db;border-radius:.35rem;box-sizing:border-box;';
    div.innerHTML = '<input placeholder="Bank name" style="'+s+'" oninput="updateCorrBenef()">'
        + '<input placeholder="Account No." maxlength="10" style="'+s+'font-family:monospace;" oninput="updateCorrBenef()">'
        + '<input placeholder="Account Name" style="'+s+'" oninput="updateCorrBenef()">'
        + '<button type="button" onclick="removeCorrBenef(this)" style="background:none;border:1px solid #fca5a5;border-radius:.3rem;color:#ef4444;cursor:pointer;font-size:1rem;line-height:1;padding:.2rem .4rem;" title="Remove">&times;</button>';
    document.getElementById('corrBenefSection').appendChild(div);
    updateCorrBenef();
}

function removeCorrBenef(btn) {
    var rows = document.querySelectorAll('#corrBenefSection .corr-benef-row');
    if (rows.length <= 1) { alert('At least one beneficiary row is required.'); return; }
    btn.closest('.corr-benef-row').remove();
    updateCorrBenef();
}

function updateCorrBenef() {
    var rows = document.querySelectorAll('#corrBenefSection .corr-benef-row');
    var benef = [];
    rows.forEach(function(r) {
        var ins = r.querySelectorAll('input');
        benef.push({
            bank:           ins[0] ? ins[0].value.trim() : '',
            account_number: ins[1] ? ins[1].value.trim() : '',
            account_name:   ins[2] ? ins[2].value.trim() : ''
        });
    });
    var bj = document.getElementById('corrBenefJson');
    if (bj) bj.value = JSON.stringify(benef);
}

<?php if ($req['type'] === 'payment'): ?>
var _corrJCount = <?= max(2, count($journalEntries ?: [])) ?>;

function addCorrJournalRow() {
    var i = _corrJCount++;
    var tbody = document.getElementById('corrJournalRows');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.id = 'cjrow_' + i;
    var s = 'width:100%;font-size:.77rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:.35rem;box-sizing:border-box;';
    tr.innerHTML =
        '<td style="padding:.18rem .25rem;"><input list="hriCoaList" placeholder="Code" style="'+s+'width:70px;font-family:monospace;" oninput="_coaFillFromCode(this)" onchange="_coaFillFromCode(this)"></td>' +
        '<td style="padding:.18rem .25rem;"><input list="hriCoaNameList" placeholder="Account name (required)" style="'+s+'" oninput="_coaFillFromName(this)" onchange="_coaFillFromName(this)"></td>' +
        '<td style="padding:.18rem .25rem;"><input placeholder="Optional" style="'+s+'" oninput="updateCorrJournal()"></td>' +
        '<td style="padding:.18rem .25rem;"><input type="number" placeholder="0.00" step="0.01" min="0" style="'+s+'text-align:right;" oninput="updateCorrJournal()"></td>' +
        '<td style="padding:.18rem .25rem;"><input type="number" placeholder="0.00" step="0.01" min="0" style="'+s+'text-align:right;" oninput="updateCorrJournal()"></td>' +
        '<td style="padding:.18rem .15rem;text-align:center;"><button type="button" onclick="this.closest(\'tr\').remove();updateCorrJournal();" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:1rem;line-height:1;padding:0;">&times;</button></td>';
    tbody.appendChild(tr);
}

function updateCorrJournal() {
    var rows = document.querySelectorAll('#corrJournalRows tr');
    var lines = [], totD = 0, totC = 0;
    rows.forEach(function(tr) {
        var ins = tr.querySelectorAll('input');
        var name = ins[1] ? ins[1].value.trim() : '';
        if (!name) return;
        var d = parseFloat((ins[3] && ins[3].value) || 0) || 0;
        var c = parseFloat((ins[4] && ins[4].value) || 0) || 0;
        totD += d; totC += c;
        lines.push({account_code: ins[0]?ins[0].value.trim():'', account_name:name, description:ins[2]?ins[2].value.trim():'', debit:d, credit:c});
    });
    var el = document.getElementById('corrJournalJson'); if (el) el.value = JSON.stringify(lines);
    var dEl = document.getElementById('corrJTotD'); if (dEl) dEl.textContent = '₦' + totD.toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
    var cEl = document.getElementById('corrJTotC'); if (cEl) cEl.textContent = '₦' + totC.toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
    var msg = document.getElementById('corrJBalMsg');
    if (msg) {
        if (totD === 0 && totC === 0) { msg.textContent = ''; }
        else if (Math.abs(totD - totC) < 0.01) { msg.innerHTML = '<span style="color:#059669;font-weight:600;">&#10003; Balanced</span>'; }
        else { msg.innerHTML = '<span style="color:#dc2626;font-weight:600;">&#9888; Unbalanced &mdash; Debit must equal Credit</span>'; }
    }
}
<?php endif; ?>
<?php endif; ?>

// Inline file upload
document.getElementById('inlineFile') && document.getElementById('inlineFile').addEventListener('change', function() {
    var sp = document.getElementById('inlineFileName');
    if (sp) sp.textContent = this.files[0] ? this.files[0].name : '';
});

function uploadInline() {
    var fi = document.getElementById('inlineFile');
    var label = document.getElementById('inlineLabel').value;
    if (!fi || !fi.files[0]) { alert('Please choose a file.'); return; }
    var fd = new FormData();
    fd.append('request_id', requestId);
    fd.append('label', label);
    fd.append('file', fi.files[0]);
    var btn = document.getElementById('inlineUploadBtn');
    btn.disabled = true; btn.textContent = 'Uploading...';
    fetch('api/irs/upload.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'X-CSRF-Token': window.CSRF_TOKEN},
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) { window.location.reload(); }
        else { alert(data.error || 'Upload failed.'); btn.disabled = false; btn.textContent = 'Upload'; }
    })
    .catch(function() { alert('Network error.'); btn.disabled = false; btn.textContent = 'Upload'; });
}

// Refund proof upload (retirement)
document.getElementById('refundFile') && document.getElementById('refundFile').addEventListener('change', function() {
    var sp = document.getElementById('refundFileName');
    if (sp) sp.textContent = this.files[0] ? this.files[0].name : '';
});

function uploadRefundProof() {
    var fi = document.getElementById('refundFile');
    if (!fi || !fi.files[0]) { alert('Please choose a file.'); return; }
    var fd = new FormData();
    fd.append('request_id', requestId);
    fd.append('label', 'proof_of_refund');
    fd.append('file', fi.files[0]);
    var btn = document.getElementById('refundUploadBtn');
    btn.disabled = true; btn.textContent = 'Uploading...';
    fetch('api/irs/upload.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'X-CSRF-Token': window.CSRF_TOKEN},
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) { window.location.reload(); }
        else { alert(data.error || 'Upload failed.'); btn.disabled = false; btn.textContent = 'Upload Proof'; }
    })
    .catch(function() { alert('Network error.'); btn.disabled = false; btn.textContent = 'Upload Proof'; });
}
</script>

<!-- IRS Attachment Viewer Modal -->
<div id="irsViewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:900;align-items:center;justify-content:center;padding:.5rem;">
  <div style="background:#fff;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.3);width:94vw;max-width:1300px;height:92vh;display:flex;flex-direction:column;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;flex-shrink:0;">
      <span id="irsViewTitle" style="font-size:.9rem;font-weight:600;color:#002850;max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
      <div style="display:flex;gap:10px;align-items:center;">
        <a id="irsViewDl" href="#" download style="font-size:.82rem;color:#002850;text-decoration:none;font-weight:600;padding:.3rem .75rem;border:1px solid #cbd5e1;border-radius:.35rem;">&#8681; Download</a>
        <button onclick="closeIrsView()" style="color:#64748b;background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;">&times;</button>
      </div>
    </div>
    <iframe id="irsViewFrame" src="" style="flex:1;width:100%;border:none;background:#f1f5f9;"></iframe>
  </div>
</div>
<script>
function openIrsView(id, name) {
    document.getElementById('irsViewTitle').textContent = name;
    document.getElementById('irsViewDl').href = 'api/irs/serve.php?id=' + id + '&dl=1';
    document.getElementById('irsViewDl').download = name;
    document.getElementById('irsViewFrame').src = 'api/irs/serve.php?id=' + id;
    var m = document.getElementById('irsViewModal');
    m.style.display = 'flex';
    document.addEventListener('keydown', _irsEsc);
}
function closeIrsView() {
    document.getElementById('irsViewModal').style.display = 'none';
    document.getElementById('irsViewFrame').src = '';
    document.removeEventListener('keydown', _irsEsc);
}
function _irsEsc(e) { if (e.key === 'Escape') closeIrsView(); }

function postNote() {
    var txt = document.getElementById('noteInput').value.trim();
    if (!txt) { document.getElementById('noteInput').focus(); return; }
    var btn = document.getElementById('noteBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
    var fd = new FormData();
    fd.append('request_id', <?= $requestId ?>);
    fd.append('note', txt);
    fetch('api/irs/comment.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token': window.CSRF_TOKEN},
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        btn.disabled = false; btn.textContent = 'Add Note';
        if (!d.ok) { alert(d.error || 'Could not save note.'); return; }
        document.getElementById('noteInput').value = '';
        var noMsg = document.getElementById('noNotesMsg');
        if (noMsg) noMsg.remove();
        var list = document.getElementById('notesList');
        var div  = document.createElement('div');
        div.style.cssText = 'display:flex;gap:.6rem;padding:.5rem 0;border-bottom:1px solid #f1f5f9;';
        div.innerHTML = '<div style="width:28px;height:28px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#1d4ed8;flex-shrink:0;">'
            + d.name.charAt(0).toUpperCase() + '</div>'
            + '<div style="flex:1;">'
            + '<div style="font-size:.82rem;font-weight:600;">' + d.name + '</div>'
            + '<div style="font-size:.84rem;color:#334155;margin-top:.15rem;line-height:1.5;">' + d.note.replace(/\n/g,'<br>') + '</div>'
            + '<div style="font-size:.73rem;color:#94a3b8;margin-top:.2rem;">' + d.time + '</div>'
            + '</div>';
        list.appendChild(div);
        div.scrollIntoView({behavior:'smooth', block:'nearest'});
    })
    .catch(function() {
        btn.disabled = false; btn.textContent = 'Add Note';
        alert('Network error. Please try again.');
    });
}
</script>
<?php Layout::end(); ?>
