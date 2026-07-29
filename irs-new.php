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

$canRaisePayment = in_array($user['role'], IrsFlow::PAYMENT_RAISER_ROLES) || Auth::isAdmin($user);

// "Raise Similar" — pre-fill from an existing request
$fromReq = null;
$fromId  = (int)($_GET['from'] ?? 0);
if ($fromId) {
    try {
        $fs = $db->prepare("SELECT id, type, amount, description, notes, priority, department, bank, account_name, account_number, beneficiaries_json FROM irs_requests WHERE id=?");
        $fs->execute([$fromId]);
        $row = $fs->fetch(PDO::FETCH_ASSOC);
        if ($row && IrsFlow::canView($user, $row)) $fromReq = $row;
    } catch(Exception $e) {}
}

$pettyCashLimit = defined('IRS_PETTY_CASH_LIMIT') ? IRS_PETTY_CASH_LIMIT : 50000;
if (function_exists('getIrsConfig')) {
    $irsCfg = getIrsConfig();
    $pettyCashLimit = $irsCfg['petty_cash_limit'] ?? $pettyCashLimit;
}

// For retirement: fetch user's own completed requisitions to retire against
$myPostedRefs = [];
try {
    $prs = $db->prepare("SELECT ref_number, amount, description, department, created_at FROM irs_requests
        WHERE requester_id=? AND status='completed' AND type='requisition'
        ORDER BY created_at DESC LIMIT 50");
    $prs->execute([$user['id']]);
    $myPostedRefs = $prs->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$dept = htmlspecialchars($user['department'] ?? '');

Layout::shell($user, 'irs', 0, 'New Internal Request');
?>
<style>
.irs-wizard { max-width:780px; margin:0 auto; }
.irs-type-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:.875rem; }
.irs-type-card {
    border:2px solid #e2e8f0; border-radius:.75rem; padding:1.25rem 1rem;
    text-align:center; cursor:pointer; transition:all .18s;
    background:#fff; position:relative; overflow:hidden;
}
.irs-type-card:hover { border-color:#002850; background:#f8fafc; transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,40,80,.12); }
.irs-type-card.selected { border-color:#002850; background:#eff6ff; box-shadow:0 0 0 3px rgba(0,40,80,.12); }
.irs-type-card .type-icon { font-size:2.25rem; margin-bottom:.5rem; display:block; }
.irs-type-card .type-name { font-weight:700; color:#002850; font-size:.9rem; margin-bottom:.3rem; }
.irs-type-card .type-desc { font-size:.75rem; color:#64748b; line-height:1.4; }
.irs-type-card .type-check { position:absolute; top:8px; right:8px; width:20px; height:20px;
    background:#002850; border-radius:50%; display:none; align-items:center; justify-content:center; color:#fff; font-size:11px; }
.irs-type-card.selected .type-check { display:flex; }

.irs-section { margin-bottom:1.25rem; }
.irs-section-hd { font-size:.72rem; font-weight:700; color:#64748b; text-transform:uppercase;
    letter-spacing:.07em; border-bottom:2px solid #f1f5f9; padding-bottom:.5rem; margin-bottom:.875rem; }
.irs-field { margin-bottom:.875rem; }
.irs-field label { display:block; font-size:.83rem; font-weight:600; color:#374151; margin-bottom:.35rem; }
.irs-field label .req { color:#ef4444; margin-left:2px; }

.irs-outstanding-box { border-radius:.5rem; padding:.75rem 1rem; font-weight:700;
    display:flex; justify-content:space-between; align-items:center; }
.irs-outstanding-refund   { background:#fef2f2; border:2px solid #fecaca; color:#dc2626; }
.irs-outstanding-addl     { background:#eff6ff; border:2px solid #bfdbfe; color:#1d4ed8; }
.irs-outstanding-exact    { background:#f0fdf4; border:2px solid #bbf7d0; color:#166534; }

.receipt-drop-zone { border:2px dashed #cbd5e1; border-radius:.5rem; padding:1.5rem 1rem;
    text-align:center; cursor:pointer; transition:all .15s; }
.receipt-drop-zone:hover, .receipt-drop-zone.drag-over { border-color:#002850; background:#eff6ff; }
.receipt-required { border-color:#ef4444 !important; background:#fff1f2; }

/* ── Wizard step indicator ── */
.wiz-steps { display:flex; align-items:center; margin-bottom:1.75rem; user-select:none; }
.wiz-step { display:flex; flex-direction:column; align-items:center; gap:.3rem; min-width:64px; }
.wiz-dot {
    width:36px; height:36px; border-radius:50%; font-weight:700; font-size:.9rem;
    display:flex; align-items:center; justify-content:center; transition:all .22s;
    background:#e2e8f0; color:#94a3b8; border:2px solid #e2e8f0;
}
.wiz-lbl { font-size:.71rem; font-weight:600; color:#94a3b8; white-space:nowrap; }
.wiz-step.ws-active .wiz-dot { background:#002850; color:#fff; border-color:#002850; }
.wiz-step.ws-active .wiz-lbl { color:#002850; }
.wiz-step.ws-done .wiz-dot { background:#059669; color:#fff; border-color:#059669; content:'✓'; }
.wiz-step.ws-done .wiz-lbl { color:#059669; }
.wiz-line { flex:1; height:2px; background:#e2e8f0; margin:0 .3rem; margin-bottom:.95rem; transition:background .22s; }
.wiz-line.ws-done { background:#059669; }

/* ── Review panel ── */
.review-row { display:flex; gap:.5rem; padding:.55rem 0; border-bottom:1px solid #f1f5f9; font-size:.875rem; }
.review-row:last-child { border-bottom:none; }
.review-lbl { min-width:150px; color:#64748b; font-weight:500; flex-shrink:0; }
.review-val { color:#1e293b; font-weight:600; word-break:break-word; }

@media(max-width:600px) {
    .irs-type-grid { grid-template-columns:1fr 1fr; }
    .irs-two-col { grid-template-columns:1fr !important; }
    .irs-three-col { grid-template-columns:1fr !important; }
    .wiz-lbl { display:none; }
    .review-row { flex-direction:column; gap:.2rem; }
    .review-lbl { min-width:auto; }
}
</style>

<div class="hri-page">
  <div class="hri-page-hd">
    <h1 class="hri-page-title">&#128196; <?= $fromReq ? 'Raise Similar Request' : 'New Internal Request' ?></h1>
    <a href="irs.php" class="hri-btn hri-btn-outline">&larr; My Requests</a>
  </div>
  <?php if ($fromReq): ?>
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:.5rem;padding:.75rem 1.25rem;margin-bottom:1.25rem;font-size:.875rem;color:#1d4ed8;">
    &#128260; Pre-filled from a previous request. Review all fields before submitting.
  </div>
  <?php endif; ?>

  <div class="irs-wizard">

    <!-- ── Wizard step indicator ── -->
    <div class="wiz-steps">
      <div class="wiz-step ws-active" id="wiz-s1">
        <div class="wiz-dot" id="wiz-d1">1</div>
        <div class="wiz-lbl">Type</div>
      </div>
      <div class="wiz-line" id="wiz-l1"></div>
      <div class="wiz-step" id="wiz-s2">
        <div class="wiz-dot" id="wiz-d2">2</div>
        <div class="wiz-lbl">Details</div>
      </div>
      <div class="wiz-line" id="wiz-l2"></div>
      <div class="wiz-step" id="wiz-s3">
        <div class="wiz-dot" id="wiz-d3">3</div>
        <div class="wiz-lbl">Review</div>
      </div>
    </div>

    <!-- ── Step 1: Type Picker ── -->
    <div id="stepType">
      <div class="hri-card" style="margin-bottom:1.25rem;">
        <div class="hri-card-hd"><h2 class="hri-card-title">Step 1 — Choose Request Type</h2></div>
        <p style="font-size:.85rem;color:#64748b;margin-bottom:1.25rem;">Select the type of internal request you want to submit.</p>
        <div class="irs-type-grid">
          <div class="irs-type-card" data-type="requisition" onclick="selectType('requisition')">
            <div class="type-check">&#10003;</div>
            <span class="type-icon">&#128203;</span>
            <div class="type-name">Requisition</div>
            <div class="type-desc">Purchase approval for goods or services</div>
          </div>
          <div class="irs-type-card" data-type="caution" onclick="selectType('caution')">
            <div class="type-check">&#10003;</div>
            <span class="type-icon">&#9888;</span>
            <div class="type-name">Caution Payment</div>
            <div class="type-desc">Salary advance or caution fee request</div>
          </div>
          <?php if ($canRaisePayment): ?>
          <div class="irs-type-card" data-type="payment" onclick="selectType('payment')">
            <div class="type-check">&#10003;</div>
            <span class="type-icon">&#128179;</span>
            <div class="type-name">Payment Request</div>
            <div class="type-desc">Direct bank payment with journal voucher</div>
          </div>
          <?php endif; ?>
          <div class="irs-type-card" data-type="petty_cash" onclick="selectType('petty_cash')">
            <div class="type-check">&#10003;</div>
            <span class="type-icon">&#128176;</span>
            <div class="type-name">Petty Cash</div>
            <div class="type-desc">Cash disbursement up to &#8358;<?= number_format($pettyCashLimit) ?></div>
          </div>
          <div class="irs-type-card" data-type="retirement" onclick="selectType('retirement')">
            <div class="type-check">&#10003;</div>
            <span class="type-icon">&#128204;</span>
            <div class="type-name">Retirement</div>
            <div class="type-desc">Account for advance or cash already received</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Step 2: Form ── -->
    <div id="stepForm" style="display:none;">

      <!-- Header with selected type badge -->
      <div class="hri-card" style="margin-bottom:1.25rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
          <div style="display:flex;align-items:center;gap:.75rem;">
            <span id="formTypeIcon" style="font-size:1.75rem;"></span>
            <div>
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;">Request Type</div>
              <div id="formTypeName" style="font-weight:700;color:#002850;font-size:1.05rem;"></div>
            </div>
          </div>
          <button onclick="resetType()" class="hri-btn hri-btn-outline" style="font-size:.8rem;">&#9664; Change Type</button>
        </div>
      </div>

      <form id="irsForm">
        <input type="hidden" id="reqType" name="type" value="">

        <!-- Section: Beneficiary Details (hidden for retirement and petty cash) -->
        <div id="bankSection" class="hri-card irs-section" style="margin-bottom:1rem;display:none;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
            <div class="irs-section-hd" style="margin:0;">&#127970; Beneficiary Details</div>
            <button type="button" onclick="addBenefRow()" style="font-size:.73rem;background:none;border:1px solid #0891b2;color:#0891b2;border-radius:.3rem;padding:.2rem .6rem;cursor:pointer;">+ Add Beneficiary</button>
          </div>
          <!-- Autocomplete datalists (populated from saved accounts on load) -->
          <datalist id="savedAcctByNum"></datalist>
          <datalist id="savedAcctByName"></datalist>
          <!-- Column headers -->
          <div style="display:grid;grid-template-columns:1fr 130px 1fr 32px;gap:.5rem;margin-bottom:.25rem;padding:0 .1rem;">
            <div style="font-size:.72rem;font-weight:600;color:#64748b;">Bank Name <span id="bankStar" style="color:#ef4444;">*</span><span id="bankOpt" style="color:#94a3b8;font-weight:400;display:none;">(optional)</span></div>
            <div style="font-size:.72rem;font-weight:600;color:#64748b;">Account No. <span id="acctNoStar" style="color:#ef4444;">*</span><span id="acctNoOpt" style="color:#94a3b8;font-weight:400;display:none;">(optional)</span></div>
            <div style="font-size:.72rem;font-weight:600;color:#64748b;">Account Name <span id="acctNameStar" style="color:#ef4444;">*</span><span id="acctNameOpt" style="color:#94a3b8;font-weight:400;display:none;">(optional)</span></div>
            <div></div>
          </div>
          <div id="benefRows"></div>
          <input type="hidden" id="beneficiariesJson" name="beneficiaries_json" value="[]">
        </div>

        <!-- Section: Basic Info -->
        <div class="hri-card irs-section" style="margin-bottom:1rem;">
          <div class="irs-section-hd">&#128203; Basic Information</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;" class="irs-two-col">
            <div class="irs-field">
              <label>Department <span class="req">*</span></label>
              <input name="department" class="hri-input" value="<?= $dept ?>" required placeholder="Your department">
            </div>
            <div class="irs-field">
              <label>Priority</label>
              <select name="priority" class="hri-select">
                <option value="normal">&#9654; Normal</option>
                <option value="urgent">&#128308; Urgent</option>
                <option value="low">&#128312; Low</option>
              </select>
            </div>
          </div>
          <div class="irs-field">
            <label>Description / Purpose <span class="req">*</span></label>
            <textarea name="description" class="hri-textarea" rows="3" required placeholder="Describe clearly what this request is for and the justification..."></textarea>
          </div>
          <div class="irs-field">
            <label>Additional Notes</label>
            <textarea name="notes" class="hri-textarea" rows="2" placeholder="Any supporting information, references, or context..."></textarea>
          </div>
        </div>

        <!-- Section: Amount -->
        <div class="hri-card irs-section" style="margin-bottom:1rem;">
          <div class="irs-section-hd">&#8358; Amount</div>
          <div id="amountSection">
            <!-- Normal: single amount field -->
            <div id="normalAmountGroup">
              <div class="irs-field">
                <label id="amountLabel">Amount (&#8358;) <span class="req">*</span></label>
                <input type="number" name="amount" id="amountInput" class="hri-input" min="1" step="0.01" required placeholder="0.00" oninput="updatePettyCashWarning()">
              </div>
              <div id="pettyCashWarning" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:.4rem;padding:.6rem .875rem;font-size:.82rem;color:#92400e;margin-top:.35rem;">
                &#9888; Petty cash limit is &#8358;<?= number_format($pettyCashLimit) ?>. Amounts above this will be rejected. Consider a Payment Request instead.
              </div>
            </div>

            <!-- Retirement: amount requested (auto) + amount spent + outstanding -->
            <div id="retirementAmountGroup" style="display:none;">
              <div class="irs-field">
                <label>Original Request Reference <span class="req">*</span></label>
                <?php if (!empty($myPostedRefs)): ?>
                <select name="related_ref" id="relatedRefSelect" class="hri-select" onchange="updateRetirementRef(this)">
                  <option value="">— Select the original request to retire —</option>
                  <?php foreach ($myPostedRefs as $pr): ?>
                  <option value="<?= htmlspecialchars($pr['ref_number']) ?>"
                    data-amount="<?= htmlspecialchars((string)$pr['amount']) ?>"
                    data-desc="<?= htmlspecialchars(mb_substr($pr['description'],0,120)) ?>"
                    data-dept="<?= htmlspecialchars($pr['department'] ?? '') ?>"
                    data-date="<?= date('d M Y', strtotime($pr['created_at'])) ?>">
                    <?= htmlspecialchars($pr['ref_number']) ?> — &#8358;<?= number_format((float)$pr['amount'],2) ?> — <?= htmlspecialchars(mb_substr($pr['description'],0,55)) ?> (<?= date('d M Y', strtotime($pr['created_at'])) ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input name="related_ref" id="relatedRefManual" class="hri-input" placeholder="e.g. REQ-2026-0001 or PAY-2026-0001" oninput="syncManualRef(this)">
                <?php endif; ?>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;" class="irs-two-col">
                <div class="irs-field">
                  <label>Amount Requested (Advance) <span class="req">*</span></label>
                  <input type="number" name="advance_display" id="retAmountRequested" class="hri-input" min="0" step="0.01" readonly placeholder="Auto-filled from original request" style="background:#f8fafc;cursor:not-allowed;">
                </div>
                <div class="irs-field">
                  <label>Amount Spent <span class="req">*</span></label>
                  <input type="number" name="actual_amount" id="retAmountSpent" class="hri-input" min="0" step="0.01" placeholder="0.00" oninput="calcOutstanding()">
                </div>
              </div>

              <div id="outstandingBox" style="display:none;margin-bottom:.75rem;">
                <div class="irs-outstanding-box" id="outstandingInner">
                  <span id="outstandingLabel" style="font-size:.82rem;font-weight:600;"></span>
                  <span id="outstandingAmt" style="font-size:1.1rem;font-weight:800;"></span>
                </div>
              </div>
            </div>
          </div>
        </div>


        <!-- Section: Journal Entries (payment type only) -->
        <div id="journalSection" class="hri-card irs-section" style="margin-bottom:1rem;display:none;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
            <div class="irs-section-hd" style="margin:0;">&#128216; Journal Entries <span style="font-weight:400;color:#a78bfa;font-style:italic;">(double-entry)</span></div>
            <button type="button" onclick="addJournalRow()" style="font-size:.73rem;background:none;border:1px solid #8b5cf6;color:#6d28d9;border-radius:.3rem;padding:.2rem .5rem;cursor:pointer;">+ Add Line</button>
          </div>
          <div style="overflow-x:auto;border:1px solid #ddd6fe;border-radius:.35rem;">
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;min-width:520px;">
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
          <input type="hidden" id="journalEntriesJson" name="journal_entries_json" value="[]">
        </div>

        <!-- Section: Attachments -->
        <div class="hri-card irs-section" style="margin-bottom:1.25rem;">
          <div class="irs-section-hd">&#128206; Attachments <span id="attachRequired" style="display:none;color:#ef4444;font-weight:700;">(Receipts Required *)</span></div>

          <!-- Retirement: prominent receipt upload zone -->
          <div id="receiptZone" style="display:none;margin-bottom:1rem;">
            <div class="receipt-drop-zone" id="receiptDropArea" onclick="triggerReceiptFile()">
              <div style="font-size:2rem;margin-bottom:.35rem;">&#128197;</div>
              <div style="font-weight:600;color:#002850;margin-bottom:.25rem;">Upload Receipts</div>
              <div style="font-size:.8rem;color:#64748b;">Click to select or drag &amp; drop your receipt files</div>
              <div style="font-size:.75rem;color:#94a3b8;margin-top:.35rem;">PDF, JPG, PNG accepted</div>
            </div>
            <input type="file" id="receiptFileInput" style="display:none;" accept=".pdf,.jpg,.jpeg,.png" multiple onchange="handleReceiptFiles(this)">
          </div>

          <!-- Regular attach list -->
          <div id="attachList" style="margin-bottom:.75rem;"></div>

          <div id="regularAttach" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
            <select id="attachLabel" class="hri-select" style="font-size:.85rem;padding:.4rem .6rem;">
              <option value="other">General Document</option>
              <option value="invoice">Invoice / Quote</option>
              <option value="approval_memo">Approval Memo</option>
              <option value="journal_voucher">Journal Voucher</option>
              <option value="receipts">Receipts</option>
              <option value="proof_of_refund">Proof of Refund</option>
            </select>
            <input type="file" id="attachFile" style="display:none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" multiple>
            <button type="button" onclick="document.getElementById('attachFile').click()" class="hri-btn hri-btn-outline" style="font-size:.85rem;">
              &#128206; Attach File
            </button>
            <span style="font-size:.8rem;color:#94a3b8;" id="noAttachNote">Optional for most types</span>
          </div>
        </div>

        <!-- Step 2 footer: go to review -->
        <div style="display:flex;gap:.75rem;justify-content:space-between;flex-wrap:wrap;">
          <button type="button" onclick="resetType()" class="hri-btn hri-btn-outline">&larr; Back</button>
          <button type="button" onclick="goToReview()" class="hri-btn hri-btn-navy" style="min-width:160px;">
            Next: Review &#8594;
          </button>
        </div>
      </form>
    </div>

    <!-- ── Step 3: Review & Submit ── -->
    <div id="stepReview" style="display:none;">
      <div class="hri-card" style="margin-bottom:1.25rem;">
        <div class="hri-card-hd">
          <h2 class="hri-card-title">&#128203; Review Your Request</h2>
        </div>
        <p style="font-size:.85rem;color:#64748b;margin-bottom:1.25rem;">Check the details below. Click Back to make changes, or Submit to send the request.</p>
        <div id="reviewContent"></div>
        <div style="display:flex;gap:.75rem;justify-content:space-between;flex-wrap:wrap;margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #f1f5f9;">
          <button type="button" onclick="goToStep(2)" class="hri-btn hri-btn-outline">&larr; Back</button>
          <button type="button" onclick="submitIRS()" class="hri-btn hri-btn-navy" id="submitBtn" style="min-width:180px;">
            &#10003; Submit Request
          </button>
        </div>
      </div>
    </div>

  </div><!-- .irs-wizard -->
</div>

<script>
var pendingUploads = [];
var selectedType   = '';
var PETTY_CASH_LIMIT = <?= (int)$pettyCashLimit ?>;

// ── Beneficiary rows ─────────────────────────────────────────────────────
var _bCount = 0;
function addBenefRow(bank, acctNo, acctName) {
    var c = ++_bCount;
    var wrap = document.createElement('div');
    wrap.id = 'br_' + c;
    wrap.style.cssText = 'display:grid;grid-template-columns:1fr 130px 1fr 32px;gap:.5rem;margin-bottom:.4rem;align-items:center;';
    var s = 'width:100%;font-size:.82rem;border:1px solid #cbd5e1;border-radius:.3rem;padding:.3rem .5rem;background:#fff;box-sizing:border-box;';
    var esc = function(v) { return String(v||'').replace(/"/g,'&quot;'); };
    var removeBtn = _bCount === 1 ? ''
        : '<button type="button" onclick="document.getElementById(\'br_'+c+'\').remove();updateBenefJson();" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:1.1rem;line-height:1;padding:0;width:28px;text-align:center;">&times;</button>';
    wrap.innerHTML =
        '<div><input value="'+esc(bank)+'" placeholder="Bank name" style="'+s+'" oninput="updateBenefJson()"></div>' +
        '<div><input list="savedAcctByNum" value="'+esc(acctNo)+'" placeholder="Account number (NUBAN)" maxlength="10" style="'+s+'" oninput="updateBenefJson();_fillFromSaved(this,1)"></div>' +
        '<div><input list="savedAcctByName" value="'+esc(acctName)+'" placeholder="Account holder name" style="'+s+'" oninput="updateBenefJson();_fillFromSaved(this,2)"></div>' +
        '<div style="display:flex;align-items:center;justify-content:center;">'+removeBtn+'</div>';
    document.getElementById('benefRows').appendChild(wrap);
    updateBenefJson();
}

// Auto-fill all 3 fields when an account number or name matches a saved record
function _fillFromSaved(inp, fieldIndex) {
    var val = inp.value.trim();
    if (!val || !_savedAccounts.length) return;
    var acct = null;
    for (var i = 0; i < _savedAccounts.length; i++) {
        if (fieldIndex === 1 && _savedAccounts[i].account_number === val) { acct = _savedAccounts[i]; break; }
        if (fieldIndex === 2 && _savedAccounts[i].account_name   === val) { acct = _savedAccounts[i]; break; }
    }
    if (!acct) return;
    var row = inp.closest('div[id^="br_"]');
    if (!row) return;
    var ins = row.querySelectorAll('input');
    if (ins[0]) ins[0].value = acct.bank;
    if (ins[1]) ins[1].value = acct.account_number;
    if (ins[2]) ins[2].value = acct.account_name;
    updateBenefJson();
}
function updateBenefJson() {
    var rows = document.querySelectorAll('#benefRows > div');
    var arr = [];
    rows.forEach(function(r) {
        var ins = r.querySelectorAll('input');
        var b = ins[0] ? ins[0].value.trim() : '';
        var n = ins[1] ? ins[1].value.trim() : '';
        var a = ins[2] ? ins[2].value.trim() : '';
        arr.push({bank: b, account_number: n, account_name: a});
    });
    var el = document.getElementById('beneficiariesJson');
    if (el) el.value = JSON.stringify(arr);
}

// ── Journal entries (payment type) ───────────────────────────────────────
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
    '25000':'SUNDRY CREDITOR','28000':'ACCRUALS','40000':'CONSULTING INCOME',
    '40500':'OTHER INCOME','43000':'MANAGEMENT FEE','51000':'TRAINING COST',
    '53000':'RECRUITMENT COST','60000':'MEDICAL EXPENSES','61000':'ADVERTISING',
    '62000':'BANK CHARGES','62500':'VAT EXPENSE','64000':'DEPRECIATION',
    '66000':'SUBSCRIPTIONS & DUES','67000':'PENSION EXPENSES','67100':'TRANSPORT',
    '67300':'PAYE & LIRS','67400':'INSURANCE','67500':'LEGAL & PROFESSIONAL',
    '67600':'REPAIRS & MAINTENANCE','67700':'STAFF WELFARE','67800':'MEALS & ENTERTAINMENT',
    '67900':'OFFICE EXPENSES','68000':'POSTAGE','68100':'RENTAL','68200':'TELEPHONE',
    '68300':'TRAVEL','68400':'SALARIES','68500':'LEAVE ALLOWANCE','68600':'NSITF',
    '68800':'DIESEL & FUEL','68900':'ELECTRICITY','69000':'PRINTING & STATIONERY',
    '69100':'TRAINING & DEVELOPMENT','69200':'AUDIT FEE','69300':'BAD DEBT',
    '69400':'FINES/PENALTIES'
};
var _jCount = 0;
function _je(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
function _jeAccFill(codeInp) {
    var v = codeInp.value.trim();
    if (COA[v]) {
        var row = codeInp.closest('tr');
        if (row) { var n = row.querySelectorAll('input')[1]; if (n && !n.value.trim()) n.value = COA[v]; }
    }
    updateJournal();
}
// Account name chosen from the list → fill in its code
function _jeCodeFill(nameInp) {
    var v = nameInp.value.trim().toUpperCase(), row = nameInp.closest('tr');
    if (v && row) {
        for (var c in COA) {
            if (COA[c] === v) {
                var codeInp = row.querySelectorAll('input')[0];
                if (codeInp && !codeInp.value.trim()) codeInp.value = c;
                break;
            }
        }
    }
    updateJournal();
}
// Name picker list — built from COA so both fields offer the same accounts
(function buildCoaNameList() {
    if (document.getElementById('hriCoaNameList')) return;
    var dl = document.createElement('datalist');
    dl.id = 'hriCoaNameList';
    Object.keys(COA).forEach(function(code) {
        var o = document.createElement('option');
        o.value = COA[code];
        o.label = code;
        dl.appendChild(o);
    });
    document.body.appendChild(dl);
})();
function addJournalRow(code, name, desc, debit, credit) {
    var tbody = document.getElementById('journalRows');
    if (!tbody) return;
    var i = ++_jCount;
    var s  = 'width:100%;font-size:.77rem;border:1px solid #ddd6fe;border-radius:.22rem;padding:.22rem .3rem;background:#fff;box-sizing:border-box;';
    var tr = document.createElement('tr');
    tr.id  = 'jrow_' + i;
    tr.style.borderBottom = '1px solid #ede9fe';
    tr.innerHTML =
        '<td style="padding:.22rem .3rem;"><input list="hriCoaList" value="'+_je(code||'')+'" placeholder="Code" style="'+s+'" oninput="_jeAccFill(this)" onchange="_jeAccFill(this)"></td>'+
        '<td style="padding:.22rem .3rem;"><input list="hriCoaNameList" value="'+_je(name||'')+'" placeholder="Account name (required)" style="'+s+'" onchange="_jeCodeFill(this)" oninput="_jeCodeFill(this)"></td>'+
        '<td style="padding:.22rem .3rem;"><input value="'+_je(desc||'')+'" placeholder="Narration" style="'+s+'" onchange="updateJournal()" oninput="updateJournal()"></td>'+
        '<td style="padding:.22rem .3rem;"><input type="number" min="0" step="0.01" value="'+_je(debit||'')+'" placeholder="0.00" style="'+s+'text-align:right;" onchange="updateJournal()" oninput="updateJournal()"></td>'+
        '<td style="padding:.22rem .3rem;"><input type="number" min="0" step="0.01" value="'+_je(credit||'')+'" placeholder="0.00" style="'+s+'text-align:right;" onchange="updateJournal()" oninput="updateJournal()"></td>'+
        '<td style="padding:.22rem .2rem;text-align:center;"><button type="button" onclick="document.getElementById(\'jrow_'+i+'\').remove();updateJournal();" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:1.05rem;line-height:1;padding:0;">&times;</button></td>';
    tbody.appendChild(tr);
    updateJournal();
}
function updateJournal() {
    var rows = document.querySelectorAll('#journalRows tr');
    var lines = [], totD = 0, totC = 0;
    rows.forEach(function(tr) {
        var ins = tr.querySelectorAll('input');
        var acctName = ins[1] ? ins[1].value.trim() : '';
        if (!acctName) return;
        var d = parseFloat(ins[3] && ins[3].value || 0) || 0;
        var c = parseFloat(ins[4] && ins[4].value || 0) || 0;
        totD += d; totC += c;
        lines.push({account_code: ins[0]?ins[0].value.trim():'', account_name:acctName, description:ins[2]?ins[2].value.trim():'', debit:d, credit:c});
    });
    var el = document.getElementById('journalEntriesJson'); if (el) el.value = JSON.stringify(lines);
    var dEl = document.getElementById('jTotalDebit');  if (dEl) dEl.textContent = '₦' + totD.toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
    var cEl = document.getElementById('jTotalCredit'); if (cEl) cEl.textContent = '₦' + totC.toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
    var msg = document.getElementById('jBalanceMsg');
    if (msg) {
        if (totD === 0 && totC === 0) { msg.textContent = ''; }
        else if (Math.abs(totD - totC) < 0.01) { msg.textContent = '✅ Balanced'; msg.style.color = '#059669'; }
        else { msg.textContent = '⚠ Unbalanced — Debit and Credit must be equal'; msg.style.color = '#dc2626'; }
    }
}

// ── Saved accounts (power the inline autocomplete on each row) ────────────
var _savedAccounts = [];
function loadSavedAccounts() {
    fetch('api/irs/saved-accounts.php', {credentials:'same-origin', headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.ok || !d.accounts || !d.accounts.length) return;
        _savedAccounts = d.accounts;
        var dlNum  = document.getElementById('savedAcctByNum');
        var dlName = document.getElementById('savedAcctByName');
        d.accounts.forEach(function(a) {
            if (dlNum) {
                var o = document.createElement('option');
                o.value = a.account_number;
                o.label = a.account_name + (a.bank ? ' — ' + a.bank : '');
                dlNum.appendChild(o);
            }
            if (dlName) {
                var o2 = document.createElement('option');
                o2.value = a.account_name;
                o2.label = a.account_number + (a.bank ? ' — ' + a.bank : '');
                dlName.appendChild(o2);
            }
        });
    }).catch(function() {});
}
function autoSaveAccounts(benefRows) {
    if (!benefRows || !benefRows.length) return;
    benefRows.forEach(function(b) {
        if (!b.bank || !b.account_number) return;
        var fd = new FormData();
        fd.append('action', 'save');
        fd.append('bank', b.bank);
        fd.append('account_number', b.account_number);
        fd.append('account_name', b.account_name || '');
        fetch('api/irs/saved-accounts.php', {method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':window.CSRF_TOKEN}, body:fd})
        .catch(function(){});
    });
}
// Load saved accounts as soon as the page is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadSavedAccounts);
} else {
    loadSavedAccounts();
}
var FROM_REQ = <?= $fromReq ? json_encode($fromReq) : 'null' ?>;

var typeConfig = {
    requisition: { icon:'&#128203;', name:'Requisition',      color:'#002850' },
    caution:     { icon:'&#9888;',   name:'Caution Payment',  color:'#d97706' },
    payment:     { icon:'&#128179;', name:'Payment Request',  color:'#0891b2' },
    petty_cash:  { icon:'&#128176;', name:'Petty Cash',       color:'#059669' },
    retirement:  { icon:'&#128204;', name:'Retirement',       color:'#6d28d9' },
};

// ── Wizard step indicator ──────────────────────────────────────────────────
function setWizardStep(n) {
    for (var i = 1; i <= 3; i++) {
        var s = document.getElementById('wiz-s' + i);
        var d = document.getElementById('wiz-d' + i);
        if (!s) continue;
        s.classList.remove('ws-active','ws-done');
        if (i < n)  { s.classList.add('ws-done');   d.innerHTML = '&#10003;'; }
        if (i === n){ s.classList.add('ws-active');  d.innerHTML = i; }
        if (i > n)  { d.innerHTML = i; }
        if (i < 3) {
            var l = document.getElementById('wiz-l' + i);
            if (l) l.classList.toggle('ws-done', i < n);
        }
    }
}

function goToStep(n) {
    document.getElementById('stepType').style.display   = n === 1 ? '' : 'none';
    document.getElementById('stepForm').style.display   = n === 2 ? '' : 'none';
    document.getElementById('stepReview').style.display = n === 3 ? '' : 'none';
    setWizardStep(n);
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function goToReview() {
    var form = document.getElementById('irsForm');

    // For retirement: require at least one receipt
    if (selectedType === 'retirement') {
        var hasReceipt = pendingUploads.some(function(u) { return u.label === 'receipts'; });
        if (!hasReceipt) {
            var dz = document.getElementById('receiptDropArea');
            if (dz) dz.classList.add('receipt-required');
            alert('Please upload at least one receipt before proceeding.');
            document.getElementById('receiptDropArea').scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
        var reqAmt = document.getElementById('retAmountRequested').value;
        if (!reqAmt || parseFloat(reqAmt) <= 0) {
            alert('Please select the original request reference to auto-fill the advance amount.');
            return;
        }
        document.getElementById('amountInput').value = reqAmt;
    }

    // Validate beneficiary rows. Payment Request is exempt: the payee is
    // identified by the journal entries and supporting documents, so bank
    // details may legitimately be filled in later by Accounts.
    var hasBankSection = (selectedType !== 'retirement' && selectedType !== 'petty_cash');
    if (hasBankSection && selectedType !== 'payment') {
        var benefData = JSON.parse(document.getElementById('beneficiariesJson').value || '[]');
        var firstBank = benefData[0] && benefData[0].bank ? benefData[0].bank.trim() : '';
        if (!firstBank) {
            alert('Please enter the beneficiary bank name.');
            document.getElementById('bankSection').scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
    }

    if (!form.checkValidity()) { form.reportValidity(); return; }

    // Payment Request: require balanced double-entry journals
    if (selectedType === 'payment') {
        var jData = JSON.parse((document.getElementById('journalEntriesJson') || {value:'[]'}).value || '[]');
        var jValid = jData.filter(function(l) { return l.account_name && l.account_name.trim(); });
        if (jValid.length < 2) {
            alert('A Payment Request requires at least 2 journal entry lines (double-entry). Please complete the Journal Entries section.');
            document.getElementById('journalSection').scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
        var totD = jValid.reduce(function(s,l){return s+(parseFloat(l.debit)||0);},0);
        var totC = jValid.reduce(function(s,l){return s+(parseFloat(l.credit)||0);},0);
        if (Math.abs(totD - totC) >= 0.01) {
            alert('Journal entries must balance. Debit total (₦' + totD.toFixed(2) + ') must equal Credit total (₦' + totC.toFixed(2) + ').');
            document.getElementById('journalSection').scrollIntoView({behavior:'smooth', block:'center'});
            return;
        }
    }

    // Build review panel
    document.getElementById('reviewContent').innerHTML = buildReviewHTML();
    goToStep(3);
}

function buildReviewHTML() {
    var cfg = typeConfig[selectedType] || {};
    var typeLabels = {requisition:'Requisition',caution:'Caution Payment',payment:'Payment Request',petty_cash:'Petty Cash',retirement:'Retirement'};
    var form = document.getElementById('irsForm');
    function fv(name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value.trim() : '';
    }

    var isRetirement  = selectedType === 'retirement';
    var hasBankFields = (selectedType !== 'retirement' && selectedType !== 'petty_cash');

    var amount = isRetirement
        ? (document.getElementById('retAmountSpent') ? document.getElementById('retAmountSpent').value : '')
        : fv('amount');
    var amountFmt = amount ? '₦' + parseFloat(amount).toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2}) : '—';

    var priLabel = {'normal':'Normal','urgent':'Urgent','low':'Low'};

    var rows = [
        ['Request Type',  cfg.icon + ' ' + (typeLabels[selectedType] || selectedType)],
        ['Priority',      priLabel[fv('priority')] || fv('priority')],
        ['Department',    fv('department') || '—'],
        ['Amount',        amountFmt],
    ];
    if (hasBankFields) {
        var benefData = JSON.parse(document.getElementById('beneficiariesJson').value || '[]');
        benefData.forEach(function(b, idx) {
            var label = benefData.length > 1 ? 'Beneficiary ' + (idx + 1) : null;
            if (label) rows.push(['— ' + label + ' —', '']);
            rows.push(['Bank Name',      b.bank           || '—']);
            rows.push(['Account Number', b.account_number || '—']);
            rows.push(['Account Name',   b.account_name   || '—']);
        });
    }
    if (isRetirement) {
        var relSel = document.getElementById('relatedRefSelect') || document.getElementById('relatedRefManual');
        var relVal = relSel ? relSel.value : '';
        var reqAmt = document.getElementById('retAmountRequested');
        rows.push(['Original Ref', relVal || '—']);
        rows.push(['Advance Amount', reqAmt ? '₦' + parseFloat(reqAmt.value||0).toLocaleString('en-NG',{minimumFractionDigits:2}) : '—']);
    }
    rows.push(['Description', fv('description') || '—']);
    if (fv('notes')) rows.push(['Notes', fv('notes')]);
    if (selectedType === 'payment') {
        var jData = JSON.parse((document.getElementById('journalEntriesJson') || {value:'[]'}).value || '[]');
        var jValid = jData.filter(function(l){return l.account_name;});
        rows.push(['Journal Entries', jValid.length > 0 ? jValid.length + ' line(s)' : '— none —']);
    }
    if (pendingUploads.length > 0) {
        rows.push(['Attachments', pendingUploads.length + ' file(s) attached']);
    }

    var html = '<div>';
    rows.forEach(function(r) {
        html += '<div class="review-row"><div class="review-lbl">' + r[0] + '</div><div class="review-val">' + escHtml(String(r[1])) + '</div></div>';
    });
    html += '</div>';
    return html;
}

function selectType(t) {
    selectedType = t;
    document.getElementById('reqType').value = t;

    var cfg = typeConfig[t];
    document.getElementById('formTypeIcon').innerHTML = cfg.icon;
    document.getElementById('formTypeName').textContent = cfg.name;

    // Card highlight
    document.querySelectorAll('.irs-type-card').forEach(function(c) {
        c.classList.toggle('selected', c.dataset.type === t);
    });

    var isPayment    = t === 'payment';
    var isRetirement = t === 'retirement';
    var isPettyCash  = t === 'petty_cash';

    var hasBankSection = (!isRetirement && !isPettyCash);
    document.getElementById('bankSection').style.display           = hasBankSection ? '' : 'none';
    document.getElementById('retirementAmountGroup').style.display = isRetirement ? '' : 'none';
    document.getElementById('normalAmountGroup').style.display     = isRetirement ? 'none' : '';
    document.getElementById('receiptZone').style.display           = isRetirement ? '' : 'none';
    document.getElementById('attachRequired').style.display        = isRetirement ? '' : 'none';
    document.getElementById('noAttachNote').style.display          = isRetirement ? 'none' : '';

    var amtLabel = document.getElementById('amountLabel');
    if (amtLabel) amtLabel.innerHTML = 'Amount (&#8358;) <span class="req">*</span>';
    if (isPettyCash) amtLabel.innerHTML = 'Amount (&#8358;) — Max &#8358;' + PETTY_CASH_LIMIT.toLocaleString() + ' <span class="req">*</span>';

    // Seed one blank beneficiary row the first time the bank section appears
    if (hasBankSection && document.getElementById('benefRows').children.length === 0) {
        addBenefRow();
    }

    // Journal entries section — payment type only
    var isPayment = (t === 'payment');
    document.getElementById('journalSection').style.display = isPayment ? '' : 'none';

    // Payment Request pays external parties who may be identified by bank alone.
    // Neither of these was ever enforced — submit.php validates only the bank
    // name — so the asterisks were claiming a rule that did not exist.
    [['bankStar','bankOpt'], ['acctNoStar','acctNoOpt'], ['acctNameStar','acctNameOpt']].forEach(function(pair) {
        var star = document.getElementById(pair[0]);
        var opt  = document.getElementById(pair[1]);
        if (star) star.style.display = isPayment ? 'none' : '';
        if (opt)  opt.style.display  = isPayment ? '' : 'none';
    });
    if (isPayment && document.getElementById('journalRows').children.length === 0) {
        addJournalRow(); // seed two blank lines — double-entry needs a Dr and a Cr
        addJournalRow();
    }

    if (isPayment) {
        document.getElementById('attachLabel').value = 'journal_voucher';
    } else if (isRetirement) {
        document.getElementById('attachLabel').value = 'receipts';
    } else {
        document.getElementById('attachLabel').value = 'other';
    }

    var amtInput = document.getElementById('amountInput');
    var retReq   = document.getElementById('retAmountSpent');
    if (amtInput) amtInput.required = !isRetirement;
    if (retReq)   retReq.required   = isRetirement;

    var relRef = document.getElementById('relatedRefSelect') || document.getElementById('relatedRefManual');
    if (relRef) relRef.required = isRetirement;

    goToStep(2);
}

function resetType() {
    selectedType = '';
    document.querySelectorAll('.irs-type-card').forEach(function(c) { c.classList.remove('selected'); });
    goToStep(1);
}

function updateRetirementRef(sel) {
    var opt  = sel.options[sel.selectedIndex];
    var amt  = opt ? opt.getAttribute('data-amount') : '';
    var desc = opt ? opt.getAttribute('data-desc')   : '';
    var dept = opt ? opt.getAttribute('data-dept')   : '';
    var date = opt ? opt.getAttribute('data-date')   : '';
    var ref  = sel.value;
    if (amt) {
        document.getElementById('retAmountRequested').value = parseFloat(amt).toFixed(2);
        calcOutstanding();
        // Auto-fill a very descriptive title so it's easy to identify later
        var descField = document.querySelector('[name="description"]');
        if (descField) {
            var amtFmt = '₦' + parseFloat(amt).toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2});
            var parts  = ['Retirement of ' + ref];
            if (desc) parts.push(desc);
            parts.push(amtFmt);
            if (dept) parts.push('Dept: ' + dept);
            if (date) parts.push('Dated: ' + date);
            descField.value = parts.join(' — ');
        }
    } else {
        document.getElementById('retAmountRequested').value = '';
        document.getElementById('outstandingBox').style.display = 'none';
    }
}

function syncManualRef(input) {
    // When user types ref manually, clear the auto-amount
    document.getElementById('retAmountRequested').value = '';
    document.getElementById('outstandingBox').style.display = 'none';
}

function calcOutstanding() {
    var requested = parseFloat(document.getElementById('retAmountRequested').value) || 0;
    var spent     = parseFloat(document.getElementById('retAmountSpent').value)     || 0;
    if (requested <= 0) { document.getElementById('outstandingBox').style.display = 'none'; return; }
    var outstanding = requested - spent;
    var box   = document.getElementById('outstandingBox');
    var inner = document.getElementById('outstandingInner');
    var lbl   = document.getElementById('outstandingLabel');
    var amt   = document.getElementById('outstandingAmt');
    box.style.display = '';
    if (outstanding > 0.005) {
        inner.className = 'irs-outstanding-box irs-outstanding-refund';
        lbl.textContent = 'Refund Due (spent less than advance)';
        amt.textContent = '₦' + outstanding.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    } else if (outstanding < -0.005) {
        inner.className = 'irs-outstanding-box irs-outstanding-addl';
        lbl.textContent = 'Additional Payment Required (spent more)';
        amt.textContent = '₦' + Math.abs(outstanding).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    } else {
        inner.className = 'irs-outstanding-box irs-outstanding-exact';
        lbl.textContent = 'Exact Match — No balance';
        amt.textContent = '₦0.00';
    }
}

function updatePettyCashWarning() {
    if (selectedType !== 'petty_cash') return;
    var val = parseFloat(document.getElementById('amountInput').value) || 0;
    document.getElementById('pettyCashWarning').style.display = val > PETTY_CASH_LIMIT ? '' : 'none';
}

// ── Drag & drop for retirement receipt zone ──
(function() {
    var dz = document.getElementById('receiptDropArea');
    if (!dz) return;
    ['dragenter','dragover'].forEach(function(ev) {
        dz.addEventListener(ev, function(e) { e.preventDefault(); dz.classList.add('drag-over'); });
    });
    ['dragleave','drop'].forEach(function(ev) {
        dz.addEventListener(ev, function(e) { e.preventDefault(); dz.classList.remove('drag-over'); });
    });
    dz.addEventListener('drop', function(e) {
        var files = e.dataTransfer.files;
        for (var i = 0; i < files.length; i++) addFile(files[i], 'receipts');
    });
})();

function triggerReceiptFile() {
    document.getElementById('receiptFileInput').click();
}

function handleReceiptFiles(input) {
    for (var i = 0; i < input.files.length; i++) {
        addFile(input.files[i], 'receipts');
    }
    input.value = '';
    // Update drop zone text
    var dz = document.getElementById('receiptDropArea');
    if (dz) dz.classList.remove('receipt-required');
}

// Regular file attach handler
document.getElementById('attachFile').addEventListener('change', function() {
    var label = document.getElementById('attachLabel').value;
    for (var i = 0; i < this.files.length; i++) addFile(this.files[i], label);
    this.value = '';
});

function addFile(file, label) {
    var tempId = Date.now() + Math.random();
    pendingUploads.push({file: file, label: label, tempId: tempId});
    renderAttachItem(file.name, label, tempId);
}

function renderAttachItem(name, label, tempId) {
    var list = document.getElementById('attachList');
    var div = document.createElement('div');
    div.id = 'att_' + tempId;
    div.style.cssText = 'display:flex;align-items:center;gap:.5rem;padding:.4rem .75rem;background:#f1f5f9;border-radius:.4rem;margin-bottom:.35rem;font-size:.84rem;';
    var labelBadge = {'receipts':'&#128197; Receipt','journal_voucher':'&#128196; JV','invoice':'&#128203; Invoice',
                      'approval_memo':'&#128203; Memo','other':'&#128206; Doc','proof_of_refund':'&#9989; Proof'};
    div.innerHTML = '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escHtml(name) + '</span>' +
        '<span style="font-size:.75rem;color:#64748b;white-space:nowrap;margin-right:.25rem;">' + (labelBadge[label] || label) + '</span>' +
        '<button type="button" onclick="removeAttach(' + tempId + ')" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:1.1rem;line-height:1;padding:0 2px;">&#10005;</button>';
    list.appendChild(div);
}

function removeAttach(tempId) {
    pendingUploads = pendingUploads.filter(function(u) { return u.tempId !== tempId; });
    var el = document.getElementById('att_' + tempId);
    if (el) el.remove();
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function submitIRS() {
    if (!selectedType) { alert('Please select a request type first.'); return; }
    var form = document.getElementById('irsForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '&#9203; Submitting...';

    var fd = new FormData(form);
    if (selectedType === 'retirement') {
        var relSel = document.getElementById('relatedRefSelect');
        if (relSel && relSel.value) fd.set('related_ref', relSel.value);
    }

    fetch('api/irs/submit.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'X-CSRF-Token': window.CSRF_TOKEN},
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) {
            alert(data.error || 'Submission failed. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '&#10003; Submit Request';
            return;
        }
        var requestId = data.id;
        // Payment Request: warn loudly if journals did not persist
        if (selectedType === 'payment' && data.journals_saved === 0) {
            alert('WARNING: The request was created (' + data.ref + ') but NO journal entries were saved.\n\nReason: '
                + (data.journal_error || 'unknown')
                + '\n\nApprovers will not see any journal details. Please report this.');
        }
        // Auto-save beneficiary accounts for this user
        try { autoSaveAccounts(JSON.parse(document.getElementById('beneficiariesJson').value || '[]')); } catch(e) {}
        if (pendingUploads.length === 0) {
            window.location.href = 'irs-detail.php?id=' + requestId + '&new=1';
            return;
        }
        btn.innerHTML = '&#128206; Uploading files...';
        uploadNext(pendingUploads.slice(), 0, requestId);
    })
    .catch(function() {
        alert('Network error. Please check your connection and try again.');
        btn.disabled = false;
        btn.innerHTML = 'Submit Request &#8594;';
    });
}

// Auto-fill from previous request if ?from= is set
(function() {
    if (!FROM_REQ) return;
    var type = FROM_REQ.type;
    if (!type) return;
    selectType(type); // already calls goToStep(2)
    setTimeout(function() {
        var desc = document.querySelector('[name="description"]');
        var notes = document.querySelector('[name="notes"]');
        var pri   = document.querySelector('[name="priority"]');
        var dept  = document.querySelector('[name="department"]');
        var amt   = document.getElementById('amountInput');
        if (desc && FROM_REQ.description) desc.value = FROM_REQ.description;
        if (notes && FROM_REQ.notes)       notes.value = FROM_REQ.notes;
        if (pri  && FROM_REQ.priority)     pri.value  = FROM_REQ.priority;
        if (dept && FROM_REQ.department)   dept.value = FROM_REQ.department;
        if (amt  && FROM_REQ.amount && type !== 'retirement') amt.value = FROM_REQ.amount;
        // Pre-fill beneficiary rows from previous request
        var hasBenef = (type !== 'retirement' && type !== 'petty_cash');
        if (hasBenef) {
            var benefSrc = [];
            try { benefSrc = JSON.parse(FROM_REQ.beneficiaries_json || '[]'); } catch(e) {}
            if (!benefSrc.length && FROM_REQ.bank) {
                benefSrc = [{bank: FROM_REQ.bank, account_number: FROM_REQ.account_number || '', account_name: FROM_REQ.account_name || ''}];
            }
            var bRows = document.getElementById('benefRows');
            if (bRows) {
                bRows.innerHTML = '';
                _bCount = 0;
                benefSrc.forEach(function(b) { addBenefRow(b.bank, b.account_number, b.account_name); });
                if (!benefSrc.length) addBenefRow();
            }
        }
    }, 100);
})();

function uploadNext(uploads, idx, requestId) {
    if (idx >= uploads.length) {
        window.location.href = 'irs-detail.php?id=' + requestId + '&new=1';
        return;
    }
    var u = uploads[idx];
    var fd = new FormData();
    fd.append('request_id', requestId);
    fd.append('label', u.label);
    fd.append('file', u.file);
    fetch('api/irs/upload.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'X-CSRF-Token': window.CSRF_TOKEN},
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function() { uploadNext(uploads, idx + 1, requestId); })
    .catch(function()  { uploadNext(uploads, idx + 1, requestId); });
}
</script>
<?php Layout::end(); ?>
