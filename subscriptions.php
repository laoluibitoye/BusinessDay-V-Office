<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user   = Auth::requireRole(['head_it','it_admin','md','bdm','head_accounts']);
$db     = getDB();
$isMgr  = in_array($user['role'], ['head_it','it_admin','md','bdm']);

// ── Stats ──────────────────────────────────────────────────────────────────
$stats = ['active'=>0,'due_soon'=>0,'expired'=>0,'cancelled'=>0,'monthly_cost'=>0,'annual_cost'=>0];
try {
    $allSubs = $db->query("SELECT status, cycle, cost, renewal_date FROM subscriptions")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allSubs as $s) {
        if ($s['status'] === 'cancelled') { $stats['cancelled']++; continue; }
        $days = (int)ceil((strtotime($s['renewal_date']) - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0) $stats['expired']++;
        elseif ($days <= 30) $stats['due_soon']++;
        else $stats['active']++;
        // Normalise cost to monthly equivalent
        $c = (float)$s['cost'];
        if ($c > 0) {
            $monthly = match($s['cycle']) {
                'monthly'   => $c,
                'quarterly' => $c / 3,
                'biannual'  => $c / 6,
                'annual'    => $c / 12,
                default     => 0,
            };
            $stats['monthly_cost'] += $monthly;
            $stats['annual_cost']  += $monthly * 12;
        }
    }
} catch(Exception $e) {}

// ── Filters ────────────────────────────────────────────────────────────────
$filterCat    = $_GET['category'] ?? '';
$filterStatus = $_GET['status']   ?? '';
$search       = trim($_GET['q']   ?? '');

$where  = 'WHERE 1=1';
$params = [];
if ($filterCat    !== '') { $where .= ' AND s.category=?';  $params[] = $filterCat; }
if ($filterStatus !== '') {
    if ($filterStatus === 'active')     { $where .= ' AND s.status!=\'cancelled\' AND s.renewal_date > DATE_ADD(CURDATE(),INTERVAL 30 DAY)'; }
    elseif ($filterStatus === 'due_soon'){ $where .= ' AND s.status!=\'cancelled\' AND s.renewal_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND s.renewal_date>=CURDATE()'; }
    elseif ($filterStatus === 'expired'){ $where .= ' AND s.status!=\'cancelled\' AND s.renewal_date<CURDATE()'; }
    else                                { $where .= ' AND s.status=?'; $params[] = $filterStatus; }
}
if ($search !== '') { $where .= ' AND (s.name LIKE ? OR s.vendor LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

try {
    $stmt = $db->prepare("SELECT s.*, u.name owner_name
        FROM subscriptions s LEFT JOIN users u ON u.id=s.owner_id
        $where ORDER BY s.renewal_date ASC, s.name ASC");
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) { $subscriptions = []; }

// ── Staff list for owner dropdown ──────────────────────────────────────────
$staff = [];
try {
    $staff = $db->query("SELECT id, name, role FROM users WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$catLabels  = ['internet'=>'Internet','software'=>'Software','domain'=>'Domain','hosting'=>'Hosting','other'=>'Other'];
$catIcons   = ['internet'=>'&#127760;','software'=>'&#128187;','domain'=>'&#127988;','hosting'=>'&#9729;','other'=>'&#128203;'];
$cycleLabels= ['monthly'=>'Monthly','quarterly'=>'Quarterly','biannual'=>'Every 6 months','annual'=>'Annual'];

function subDaysStatus(array $s): array {
    if ($s['status'] === 'cancelled') return ['Cancelled','#94a3b8','—'];
    $days = (int)ceil((strtotime($s['renewal_date']) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0)        return ['Expired',   '#ef4444', abs($days).' days ago'];
    if ($days === 0)      return ['Due Today!', '#dc2626', 'Today'];
    if ($days <= 7)       return ['Critical',   '#dc2626', 'In '.$days.' day'.($days>1?'s':'')];
    if ($days <= 14)      return ['Due Soon',   '#f59e0b', 'In '.$days.' days'];
    if ($days <= 30)      return ['Expiring',   '#d97706', 'In '.$days.' days'];
    return ['Active',     '#059669', date('d M Y', strtotime($s['renewal_date']))];
}

Layout::shell($user, 'subscriptions', 0, 'Subscription Tracker');
?>
<style>
.sub-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;}
.sub-modal-overlay.open{display:flex;}
.sub-modal{background:#fff;border-radius:.75rem;padding:1.75rem;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.sub-modal-wide{max-width:700px;}
/* mobile-all-pages */
@media(max-width:768px){
    [class*='-grid']{grid-template-columns:1fr;}
    [class*='stat']{grid-template-columns:repeat(2,1fr);}
}
</style>

<div class="hri-page">
  <div class="hri-page-hd">
    <h1 class="hri-page-title">&#128197; Subscription Tracker</h1>
    <?php if ($isMgr): ?>
    <button onclick="openAddModal()" class="hri-btn hri-btn-navy">&#43; Add Subscription</button>
    <?php endif; ?>
  </div>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:.875rem;margin-bottom:1.5rem;">
    <div class="hri-card" style="text-align:center;padding:1rem .75rem;cursor:pointer;" onclick="filterBy('status','active')">
      <div style="font-size:1.6rem;font-weight:700;color:#059669;"><?= $stats['active'] ?></div>
      <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Active</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1rem .75rem;cursor:pointer;" onclick="filterBy('status','due_soon')">
      <div style="font-size:1.6rem;font-weight:700;color:#f59e0b;"><?= $stats['due_soon'] ?></div>
      <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Due ≤30 days</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1rem .75rem;cursor:pointer;" onclick="filterBy('status','expired')">
      <div style="font-size:1.6rem;font-weight:700;color:#ef4444;"><?= $stats['expired'] ?></div>
      <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Expired</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1rem .75rem;cursor:pointer;" onclick="filterBy('status','cancelled')">
      <div style="font-size:1.6rem;font-weight:700;color:#94a3b8;"><?= $stats['cancelled'] ?></div>
      <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Cancelled</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1rem .75rem;">
      <div style="font-size:1.15rem;font-weight:700;color:var(--navy);">&#8358;<?= number_format($stats['monthly_cost'],0) ?></div>
      <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Est./Month</div>
    </div>
    <div class="hri-card" style="text-align:center;padding:1rem .75rem;">
      <div style="font-size:1.15rem;font-weight:700;color:var(--navy);">&#8358;<?= number_format($stats['annual_cost'],0) ?></div>
      <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Est./Year</div>
    </div>
  </div>

  <div class="hri-card">
    <!-- Filter bar -->
    <div class="hri-card-hd" style="flex-wrap:wrap;gap:.75rem;">
      <h2 class="hri-card-title">All Subscriptions <span style="font-size:.85rem;font-weight:400;color:#64748b;">(<?= count($subscriptions) ?>)</span></h2>
      <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;" id="filterForm">
        <input name="q" class="hri-input" style="font-size:.8rem;padding:.35rem .6rem;width:160px;" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
        <select name="category" class="hri-select" style="font-size:.8rem;padding:.35rem .6rem;" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php foreach ($catLabels as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $filterCat===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" id="statusFilter" class="hri-select" style="font-size:.8rem;padding:.35rem .6rem;" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="active"    <?= $filterStatus==='active'    ? 'selected' : '' ?>>Active</option>
          <option value="due_soon"  <?= $filterStatus==='due_soon'  ? 'selected' : '' ?>>Due ≤30 days</option>
          <option value="expired"   <?= $filterStatus==='expired'   ? 'selected' : '' ?>>Expired</option>
          <option value="cancelled" <?= $filterStatus==='cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <?php if ($search || $filterCat || $filterStatus): ?>
        <a href="subscriptions.php" class="hri-btn hri-btn-outline" style="font-size:.8rem;padding:.35rem .6rem;">&#10005; Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($subscriptions)): ?>
    <div class="hri-empty" style="padding:3rem;">
      <div style="font-size:2.5rem;margin-bottom:.75rem;">&#128197;</div>
      <div>No subscriptions found.</div>
      <?php if ($isMgr): ?>
      <button onclick="openAddModal()" class="hri-btn hri-btn-navy" style="margin-top:1rem;">Add First Subscription</button>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
            <th style="padding:.7rem 1rem;text-align:left;font-weight:600;color:#64748b;white-space:nowrap;">Name</th>
            <th style="padding:.7rem 1rem;text-align:left;font-weight:600;color:#64748b;">Category</th>
            <th style="padding:.7rem 1rem;text-align:left;font-weight:600;color:#64748b;">Vendor</th>
            <th style="padding:.7rem 1rem;text-align:left;font-weight:600;color:#64748b;">Cycle</th>
            <th style="padding:.7rem 1rem;text-align:right;font-weight:600;color:#64748b;">Cost</th>
            <th style="padding:.7rem 1rem;text-align:left;font-weight:600;color:#64748b;white-space:nowrap;">Next Renewal</th>
            <th style="padding:.7rem 1rem;text-align:center;font-weight:600;color:#64748b;">Status</th>
            <th style="padding:.7rem 1rem;text-align:left;font-weight:600;color:#64748b;">Owner</th>
            <th style="padding:.7rem 1rem;text-align:center;font-weight:600;color:#64748b;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subscriptions as $s):
            list($stLbl,$stCol,$dueIn) = subDaysStatus($s);
            $isCancelled = ($s['status'] === 'cancelled');
            $rowStyle    = $isCancelled ? 'opacity:.55;' : '';
            $urgentBg    = (!$isCancelled && in_array($stLbl, ['Expired','Critical','Due Today!'])) ? 'background:#fff1f2;' : '';
          ?>
          <tr style="border-bottom:1px solid #f1f5f9;<?= $rowStyle . $urgentBg ?>"
              onmouseenter="if(!this.style.background||this.style.background==='')this.style.background='#f8fafc'"
              onmouseleave="this.style.background='<?= ($urgentBg ? '#fff1f2' : '') ?>'">
            <td style="padding:.7rem 1rem;font-weight:600;">
              <?= $catIcons[$s['category']] ?? '&#128203;' ?> <?= htmlspecialchars($s['name']) ?>
              <?php if ($s['auto_renews']): ?>
              <span style="font-size:.7rem;background:#dbeafe;color:#1d4ed8;padding:.1rem .4rem;border-radius:9999px;margin-left:.3rem;font-weight:500;">AUTO</span>
              <?php endif; ?>
            </td>
            <td style="padding:.7rem 1rem;color:#64748b;"><?= $catLabels[$s['category']] ?? $s['category'] ?></td>
            <td style="padding:.7rem 1rem;color:#64748b;"><?= htmlspecialchars($s['vendor'] ?? '—') ?></td>
            <td style="padding:.7rem 1rem;white-space:nowrap;">
              <span style="font-size:.8rem;background:#f1f5f9;padding:.2rem .5rem;border-radius:.3rem;">
                <?= $cycleLabels[$s['cycle']] ?? $s['cycle'] ?>
              </span>
            </td>
            <td style="padding:.7rem 1rem;text-align:right;font-weight:600;">
              <?= $s['cost'] !== null ? '₦'.number_format((float)$s['cost'],2) : '—' ?>
            </td>
            <td style="padding:.7rem 1rem;white-space:nowrap;">
              <div style="font-weight:500;"><?= date('d M Y',strtotime($s['renewal_date'])) ?></div>
              <div style="font-size:.78rem;color:<?= $stCol ?>;"><?= $dueIn ?></div>
            </td>
            <td style="padding:.7rem 1rem;text-align:center;">
              <span style="background:<?= $stCol ?>20;color:<?= $stCol ?>;font-size:.75rem;padding:.25rem .6rem;border-radius:9999px;font-weight:600;white-space:nowrap;">
                <?= $stLbl ?>
              </span>
            </td>
            <td style="padding:.7rem 1rem;font-size:.82rem;color:#64748b;"><?= htmlspecialchars($s['owner_name'] ?? '—') ?></td>
            <td style="padding:.7rem 1rem;text-align:center;">
              <?php if (!$isCancelled): ?>
              <div style="display:flex;gap:.35rem;justify-content:center;flex-wrap:wrap;">
                <button onclick="openRenewModal(<?= htmlspecialchars(json_encode($s)) ?>)" class="hri-btn" style="background:#059669;color:#fff;font-size:.75rem;padding:.3rem .6rem;white-space:nowrap;">
                  &#128260; Renew
                </button>
                <?php if ($isMgr): ?>
                <button onclick="openHistoryModal(<?= $s['id'] ?>,<?= htmlspecialchars(json_encode($s['name'])) ?>)" class="hri-btn hri-btn-outline" style="font-size:.75rem;padding:.3rem .5rem;" title="Renewal history">&#128203;</button>
                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($s)) ?>)" class="hri-btn hri-btn-outline" style="font-size:.75rem;padding:.3rem .5rem;" title="Edit">&#9998;</button>
                <button onclick="cancelSub(<?= $s['id'] ?>,<?= htmlspecialchars(json_encode($s['name'])) ?>)" class="hri-btn hri-btn-outline" style="font-size:.75rem;padding:.3rem .5rem;color:#ef4444;" title="Cancel subscription">&#10005;</button>
                <?php endif; ?>
              </div>
              <?php else: ?>
              <div style="display:flex;gap:.35rem;justify-content:center;">
                <button onclick="openHistoryModal(<?= $s['id'] ?>,<?= htmlspecialchars(json_encode($s['name'])) ?>)" class="hri-btn hri-btn-outline" style="font-size:.75rem;padding:.3rem .5rem;" title="History">&#128203;</button>
                <?php if ($isMgr): ?>
                <button onclick="deleteSub(<?= $s['id'] ?>,<?= htmlspecialchars(json_encode($s['name'])) ?>)" class="hri-btn hri-btn-outline" style="font-size:.75rem;padding:.3rem .5rem;color:#ef4444;" title="Delete permanently">&#128465;</button>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── RENEW MODAL ─────────────────────────────────────────────── -->
<div class="sub-modal-overlay" id="renewOverlay">
  <div class="sub-modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
      <h2 style="font-size:1.1rem;font-weight:700;color:var(--navy);">&#128260; Record Renewal</h2>
      <button onclick="closeModal('renewOverlay')" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">&#10005;</button>
    </div>
    <div id="renewSubInfo" style="background:#f0fdf4;border:1px solid #86efac;border-radius:.5rem;padding:.875rem 1rem;margin-bottom:1.25rem;font-size:.875rem;"></div>
    <div class="hri-form-group" style="margin-bottom:1rem;">
      <label class="hri-label">Amount Paid (&#8358;)</label>
      <input type="number" id="renewAmount" class="hri-input" step="0.01" min="0" placeholder="Leave blank if unknown">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
      <div class="hri-form-group">
        <label class="hri-label">Invoice / Receipt Ref</label>
        <input type="text" id="renewInvoice" class="hri-input" placeholder="Optional">
      </div>
      <div class="hri-form-group">
        <label class="hri-label">IRS Payment Ref</label>
        <input type="text" id="renewIrsRef" class="hri-input" placeholder="e.g. PAY-2026-0001">
      </div>
    </div>
    <div class="hri-form-group" style="margin-bottom:1rem;">
      <label class="hri-label">Notes</label>
      <textarea id="renewNotes" class="hri-textarea" rows="2" placeholder="Optional notes..."></textarea>
    </div>
    <div class="hri-form-group" style="margin-bottom:1.5rem;">
      <label class="hri-label">New Renewal Date <span style="color:#ef4444;">*</span></label>
      <input type="date" id="renewNewDate" class="hri-input">
      <div style="font-size:.75rem;color:#64748b;margin-top:.25rem;">Pre-filled with the auto-calculated date — change it if needed.</div>
    </div>
    <div style="display:flex;gap:.75rem;justify-content:flex-end;">
      <button type="button" onclick="closeModal('renewOverlay')" class="hri-btn hri-btn-outline">Cancel</button>
      <button type="button" onclick="submitRenew()" class="hri-btn" style="background:#059669;color:#fff;" id="renewBtn">&#128260; Confirm Renewal</button>
    </div>
  </div>
</div>

<!-- ── ADD / EDIT MODAL ──────────────────────────────────────────── -->
<div class="sub-modal-overlay" id="saveOverlay">
  <div class="sub-modal sub-modal-wide">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
      <h2 style="font-size:1.1rem;font-weight:700;color:var(--navy);" id="saveModalTitle">Add Subscription</h2>
      <button onclick="closeModal('saveOverlay')" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">&#10005;</button>
    </div>
    <input type="hidden" id="saveId" value="">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
      <div class="hri-form-group" style="grid-column:1/-1;">
        <label class="hri-label">Subscription Name <span style="color:#ef4444;">*</span></label>
        <input type="text" id="saveName" class="hri-input" placeholder="e.g. Microsoft 365, RackNerd Hosting">
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Category <span style="color:#ef4444;">*</span></label>
        <select id="saveCategory" class="hri-select">
          <option value="">— Select —</option>
          <option value="internet">Internet</option>
          <option value="software">Software</option>
          <option value="domain">Domain</option>
          <option value="hosting">Hosting</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Billing Cycle <span style="color:#ef4444;">*</span></label>
        <select id="saveCycle" class="hri-select" onchange="previewRenewal()">
          <option value="">— Select —</option>
          <option value="monthly">Monthly</option>
          <option value="quarterly">Quarterly (every 3 months)</option>
          <option value="biannual">Every 6 months</option>
          <option value="annual">Annual</option>
        </select>
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Vendor / Provider</label>
        <input type="text" id="saveVendor" class="hri-input" placeholder="e.g. Microsoft, GoDaddy">
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Account Reference</label>
        <input type="text" id="saveAccountRef" class="hri-input" placeholder="Account ID or login hint">
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Cost (&#8358;)</label>
        <input type="number" id="saveCost" class="hri-input" step="0.01" min="0" placeholder="0.00">
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Currency</label>
        <select id="saveCurrency" class="hri-select">
          <option value="NGN">NGN (₦)</option>
          <option value="USD">USD ($)</option>
          <option value="GBP">GBP (£)</option>
          <option value="EUR">EUR (€)</option>
        </select>
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Start Date <span style="color:#ef4444;">*</span></label>
        <input type="date" id="saveStartDate" class="hri-input" onchange="previewRenewal()">
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Next Renewal Date</label>
        <input type="date" id="saveRenewalDate" class="hri-input">
        <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;" id="renewalCalcNote">Auto-calculated from start date + cycle if left blank.</div>
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Alert Days Before Renewal</label>
        <select id="saveNotifyDays" class="hri-select">
          <option value="7">7 days</option>
          <option value="14">14 days</option>
          <option value="30" selected>30 days</option>
          <option value="60">60 days</option>
        </select>
      </div>
      <div class="hri-form-group">
        <label class="hri-label">Managed By</label>
        <select id="saveOwnerId" class="hri-select">
          <option value="">— Select staff —</option>
          <?php foreach ($staff as $st): ?>
          <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="hri-form-group" style="grid-column:1/-1;">
        <label class="hri-label" style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
          <input type="checkbox" id="saveAutoRenews"> Auto-renews (charged automatically)
        </label>
      </div>
      <div class="hri-form-group" style="grid-column:1/-1;">
        <label class="hri-label">Notes</label>
        <textarea id="saveNotes" class="hri-textarea" rows="2" placeholder="Renewal instructions, login portal URL, etc."></textarea>
      </div>
    </div>
    <div style="display:flex;gap:.75rem;justify-content:flex-end;">
      <button onclick="closeModal('saveOverlay')" class="hri-btn hri-btn-outline">Cancel</button>
      <button onclick="submitSave()" class="hri-btn hri-btn-navy" id="saveBtn">Save Subscription</button>
    </div>
  </div>
</div>

<!-- ── HISTORY MODAL ──────────────────────────────────────────────── -->
<div class="sub-modal-overlay" id="historyOverlay">
  <div class="sub-modal sub-modal-wide">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
      <h2 style="font-size:1.1rem;font-weight:700;color:var(--navy);">&#128203; Renewal History: <span id="historySubName"></span></h2>
      <button onclick="closeModal('historyOverlay')" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">&#10005;</button>
    </div>
    <div id="historyContent" style="min-height:80px;"></div>
  </div>
</div>

<script>
var currentRenewSub = null;

// ── Filter helpers ──────────────────────────────────────────────────────────
function filterBy(key, val) {
    var f = document.getElementById('filterForm');
    var inp = f.querySelector('[name="' + key + '"]') || f.querySelector('#' + key + 'Filter');
    if (inp) { inp.value = val; f.submit(); }
}

// ── Modals ──────────────────────────────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openModal(id)  { document.getElementById(id).classList.add('open'); }

document.querySelectorAll('.sub-modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === el) el.classList.remove('open'); });
});

// ── Renew Modal ─────────────────────────────────────────────────────────────
function openRenewModal(sub) {
    currentRenewSub = sub;
    document.getElementById('renewAmount').value  = '';
    document.getElementById('renewInvoice').value = '';
    document.getElementById('renewIrsRef').value  = '';
    document.getElementById('renewNotes').value   = '';
    document.getElementById('renewBtn').disabled  = false;
    document.getElementById('renewBtn').textContent = '🔄 Confirm Renewal';

    // Info block
    var days = Math.ceil((new Date(sub.renewal_date) - new Date()) / 86400000);
    var daysStr = days < 0 ? Math.abs(days)+' days overdue' : (days === 0 ? 'Due today' : 'Due in '+days+' day'+(days>1?'s':''));
    document.getElementById('renewSubInfo').innerHTML =
        '<strong>' + escH(sub.name) + '</strong> &mdash; ' + escH(sub.vendor||sub.category) +
        '<br>Cycle: <strong>' + escH(sub.cycle) + '</strong>' +
        (sub.cost ? ' &middot; &#8358;' + parseFloat(sub.cost).toLocaleString('en-NG',{minimumFractionDigits:2}) : '') +
        '<br>Current renewal date: <strong>' + formatDate(sub.renewal_date) + '</strong>' +
        ' <span style="color:' + (days<=0?'#ef4444':'#f59e0b') + '">(' + daysStr + ')</span>';

    // Pre-fill new renewal date field with auto-calculated date (editable)
    document.getElementById('renewNewDate').value = calcNextISO(sub.renewal_date, sub.cycle);
    openModal('renewOverlay');
}

function submitRenew() {
    if (!currentRenewSub) return;
    var btn = document.getElementById('renewBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
    var fd = new FormData();
    fd.append('id', currentRenewSub.id);
    fd.append('amount_paid',      document.getElementById('renewAmount').value);
    fd.append('invoice_ref',      document.getElementById('renewInvoice').value);
    fd.append('irs_ref',          document.getElementById('renewIrsRef').value);
    fd.append('notes',            document.getElementById('renewNotes').value);
    fd.append('new_renewal_date', document.getElementById('renewNewDate').value);
    fetch('api/subscriptions/renew.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body:fd
    })
    .then(function(r){return r.json();})
    .then(function(d){
        if (d.ok) { closeModal('renewOverlay'); window.location.reload(); }
        else { alert(d.error||'Renewal failed.'); btn.disabled=false; btn.textContent='🔄 Confirm Renewal'; }
    })
    .catch(function(){ alert('Network error.'); btn.disabled=false; btn.textContent='🔄 Confirm Renewal'; });
}

// ── Add / Edit Modal ─────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('saveModalTitle').textContent = 'Add Subscription';
    document.getElementById('saveId').value          = '';
    document.getElementById('saveName').value         = '';
    document.getElementById('saveCategory').value     = '';
    document.getElementById('saveCycle').value        = '';
    document.getElementById('saveVendor').value       = '';
    document.getElementById('saveAccountRef').value   = '';
    document.getElementById('saveCost').value         = '';
    document.getElementById('saveCurrency').value     = 'NGN';
    document.getElementById('saveStartDate').value    = new Date().toISOString().slice(0,10);
    document.getElementById('saveRenewalDate').value  = '';
    document.getElementById('saveNotifyDays').value   = '30';
    document.getElementById('saveOwnerId').value      = '';
    document.getElementById('saveAutoRenews').checked = false;
    document.getElementById('saveNotes').value        = '';
    document.getElementById('saveBtn').textContent    = 'Save Subscription';
    openModal('saveOverlay');
}

function openEditModal(sub) {
    document.getElementById('saveModalTitle').textContent = 'Edit Subscription';
    document.getElementById('saveId').value          = sub.id;
    document.getElementById('saveName').value         = sub.name || '';
    document.getElementById('saveCategory').value     = sub.category || '';
    document.getElementById('saveCycle').value        = sub.cycle || '';
    document.getElementById('saveVendor').value       = sub.vendor || '';
    document.getElementById('saveAccountRef').value   = sub.account_ref || '';
    document.getElementById('saveCost').value         = sub.cost || '';
    document.getElementById('saveCurrency').value     = sub.currency || 'NGN';
    document.getElementById('saveStartDate').value    = sub.start_date || '';
    document.getElementById('saveRenewalDate').value  = sub.renewal_date || '';
    document.getElementById('saveNotifyDays').value   = sub.notify_days || '30';
    document.getElementById('saveOwnerId').value      = sub.owner_id || '';
    document.getElementById('saveAutoRenews').checked = sub.auto_renews == 1;
    document.getElementById('saveNotes').value        = sub.notes || '';
    document.getElementById('saveBtn').textContent    = 'Update Subscription';
    openModal('saveOverlay');
}

function previewRenewal() {
    var start = document.getElementById('saveStartDate').value;
    var cycle = document.getElementById('saveCycle').value;
    var rdField = document.getElementById('saveRenewalDate');
    var note = document.getElementById('renewalCalcNote');
    if (start && cycle && !rdField.value) {
        var calc = calcNext(start, cycle);
        note.textContent = 'Will be set to: ' + calc;
    }
}

function submitSave() {
    var id   = document.getElementById('saveId').value;
    var name = document.getElementById('saveName').value.trim();
    var cat  = document.getElementById('saveCategory').value;
    var cyc  = document.getElementById('saveCycle').value;
    var sd   = document.getElementById('saveStartDate').value;
    if (!name || !cat || !cyc || !sd) { alert('Name, category, cycle and start date are required.'); return; }

    var fd = new FormData();
    fd.append('id',           id);
    fd.append('name',         name);
    fd.append('category',     cat);
    fd.append('vendor',       document.getElementById('saveVendor').value);
    fd.append('account_ref',  document.getElementById('saveAccountRef').value);
    fd.append('cycle',        cyc);
    fd.append('cost',         document.getElementById('saveCost').value);
    fd.append('currency',     document.getElementById('saveCurrency').value);
    fd.append('start_date',   sd);
    fd.append('renewal_date', document.getElementById('saveRenewalDate').value);
    fd.append('notify_days',  document.getElementById('saveNotifyDays').value);
    fd.append('owner_id',     document.getElementById('saveOwnerId').value);
    fd.append('notes',        document.getElementById('saveNotes').value);
    if (document.getElementById('saveAutoRenews').checked) fd.append('auto_renews','1');

    var btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
    fetch('api/subscriptions/save.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body:fd
    })
    .then(function(r){return r.json();})
    .then(function(d){
        if (d.ok) { closeModal('saveOverlay'); window.location.reload(); }
        else { alert(d.error||'Save failed.'); btn.disabled=false; btn.textContent='Save Subscription'; }
    })
    .catch(function(){ alert('Network error.'); btn.disabled=false; btn.textContent='Save Subscription'; });
}

// ── History Modal ────────────────────────────────────────────────────────────
function openHistoryModal(subId, name) {
    document.getElementById('historySubName').textContent = name;
    document.getElementById('historyContent').innerHTML = '<div style="text-align:center;padding:1.5rem;color:#64748b;">Loading...</div>';
    openModal('historyOverlay');
    fetch('api/subscriptions/history.php?id=' + subId, {credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
        if (!d.ok || !d.history.length) {
            document.getElementById('historyContent').innerHTML = '<div style="text-align:center;padding:1.5rem;color:#94a3b8;">No renewal history yet.</div>';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;font-size:.875rem;">' +
            '<thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">' +
            '<th style="padding:.6rem .875rem;text-align:left;font-weight:600;color:#64748b;">Date</th>' +
            '<th style="padding:.6rem .875rem;text-align:right;font-weight:600;color:#64748b;">Amount</th>' +
            '<th style="padding:.6rem .875rem;text-align:left;font-weight:600;color:#64748b;">Invoice Ref</th>' +
            '<th style="padding:.6rem .875rem;text-align:left;font-weight:600;color:#64748b;">IRS Ref</th>' +
            '<th style="padding:.6rem .875rem;text-align:left;font-weight:600;color:#64748b;">Next Renewal Set To</th>' +
            '<th style="padding:.6rem .875rem;text-align:left;font-weight:600;color:#64748b;">By</th>' +
            '</tr></thead><tbody>';
        d.history.forEach(function(h) {
            html += '<tr style="border-bottom:1px solid #f1f5f9;">' +
                '<td style="padding:.55rem .875rem;">' + escH(h.renewed_at) + '</td>' +
                '<td style="padding:.55rem .875rem;text-align:right;font-weight:600;">' + (h.amount_paid ? '₦'+parseFloat(h.amount_paid).toLocaleString('en-NG',{minimumFractionDigits:2}) : '—') + '</td>' +
                '<td style="padding:.55rem .875rem;font-family:monospace;font-size:.82rem;">' + escH(h.invoice_ref||'—') + '</td>' +
                '<td style="padding:.55rem .875rem;font-family:monospace;font-size:.82rem;">' + escH(h.irs_ref||'—') + '</td>' +
                '<td style="padding:.55rem .875rem;font-weight:500;">' + escH(h.next_renewal_date) + '</td>' +
                '<td style="padding:.55rem .875rem;color:#64748b;">' + escH(h.user_name||'—') + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('historyContent').innerHTML = html;
    })
    .catch(function(){ document.getElementById('historyContent').innerHTML = '<div style="color:#ef4444;padding:1rem;">Failed to load history.</div>'; });
}

// ── Cancel / Delete ───────────────────────────────────────────────────────────
function cancelSub(id, name) {
    if (!confirm('Cancel subscription "' + name + '"?\n\nHistory will be kept. You can still view renewal records.')) return;
    postAction('api/subscriptions/delete.php', {id:id, action:'cancel'});
}
function deleteSub(id, name) {
    if (!confirm('Permanently delete "' + name + '" and all renewal history?\n\nThis cannot be undone.')) return;
    postAction('api/subscriptions/delete.php', {id:id, action:'delete'});
}
function postAction(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    fetch(url, { method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':window.CSRF_TOKEN}, body:fd })
    .then(function(r){return r.json();})
    .then(function(d){ if (d.ok) window.location.reload(); else alert(d.error||'Failed.'); })
    .catch(function(){ alert('Network error.'); });
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function formatDate(ds) {
    if (!ds) return '—';
    var d = new Date(ds + 'T00:00:00');
    return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
}
function calcNext(fromDate, cycle) {
    if (!fromDate || !cycle) return '—';
    var base = fromDate < new Date().toISOString().slice(0,10) ? new Date().toISOString().slice(0,10) : fromDate;
    var d = new Date(base + 'T00:00:00');
    switch(cycle) {
        case 'monthly':   d.setMonth(d.getMonth()+1);   break;
        case 'quarterly': d.setMonth(d.getMonth()+3);   break;
        case 'biannual':  d.setMonth(d.getMonth()+6);   break;
        case 'annual':    d.setFullYear(d.getFullYear()+1); break;
    }
    return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
}
function calcNextISO(fromDate, cycle) {
    if (!fromDate || !cycle) return '';
    var base = fromDate < new Date().toISOString().slice(0,10) ? new Date().toISOString().slice(0,10) : fromDate;
    var d = new Date(base + 'T00:00:00');
    switch(cycle) {
        case 'monthly':   d.setMonth(d.getMonth()+1);   break;
        case 'quarterly': d.setMonth(d.getMonth()+3);   break;
        case 'biannual':  d.setMonth(d.getMonth()+6);   break;
        case 'annual':    d.setFullYear(d.getFullYear()+1); break;
    }
    return d.toISOString().slice(0,10);
}
</script>
<?php Layout::end(); ?>
