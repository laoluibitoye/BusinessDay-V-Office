<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['head_it', 'md', 'bdm']);
$db   = getDB();

$_irsConfigFn = function_exists('getIrsConfig');
$irsCfg = $_irsConfigFn ? getIrsConfig() : [
    'accounts_roles'   => defined('IRS_ACCOUNTS_ROLES_DEFAULT') ? IRS_ACCOUNTS_ROLES_DEFAULT : ['head_accounts','accountant'],
    'manager_roles'    => defined('IRS_MANAGER_ROLES_DEFAULT')  ? IRS_MANAGER_ROLES_DEFAULT  : ['md','bdm'],
    'petty_cash_limit' => defined('IRS_PETTY_CASH_LIMIT_DEFAULT') ? IRS_PETTY_CASH_LIMIT_DEFAULT : 50000,
    'notify_enabled'   => true,
];

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_roles') {
        $newAccts = array_values(array_intersect(array_keys(ROLES), $_POST['accounts_roles'] ?? []));
        $newMgrs  = array_values(array_intersect(array_keys(ROLES), $_POST['manager_roles'] ?? []));
        $newLimit = max(0, (int)($_POST['petty_cash_limit'] ?? 50000));

        // Validate: accounts and manager roles must not fully overlap
        $overlap = array_intersect($newAccts, $newMgrs);
        if (count($overlap) === count($newMgrs) && !empty($newMgrs)) {
            $err = 'Manager approval roles and Accounts roles cannot be identical — same person cannot approve their own request.';
        } elseif (empty($newAccts)) {
            $err = 'You must assign at least one Accounts role.';
        } elseif (empty($newMgrs)) {
            $err = 'You must assign at least one Manager approval role.';
        } else {
            try {
                $upsert = $db->prepare("INSERT INTO irs_config (`key`, value, label, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE value=VALUES(value), updated_by=VALUES(updated_by), updated_at=NOW()");
                $upsert->execute(['accounts_roles', json_encode($newAccts), 'Roles that perform Accounts review, custodian & Sage posting', $user['id']]);
                $upsert->execute(['manager_roles',  json_encode($newMgrs),  'Roles that perform final Manager approval', $user['id']]);
                $upsert->execute(['petty_cash_limit', (string)$newLimit, 'Petty cash disbursement ceiling (₦)', $user['id']]);
                Auth::auditLog($user['id'], 'irs_settings_update', 'Updated IRS role config and petty cash limit to ₦'.number_format($newLimit));
                $msg = 'IRS configuration saved successfully. Changes take effect immediately.';
                // Reload
                $irsCfg = $_irsConfigFn ? getIrsConfig() : $irsCfg;
            } catch (Exception $e) {
                $err = 'Save failed — ensure the irs_config migration SQL has been run in phpMyAdmin. Error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    if ($action === 'reset_defaults') {
        try {
            $del = $db->exec("DELETE FROM irs_config");
            Auth::auditLog($user['id'], 'irs_settings_reset', 'Reset IRS config to system defaults');
            $msg = 'Configuration reset to system defaults.';
            $irsCfg = $_irsConfigFn ? getIrsConfig() : $irsCfg;
        } catch (Exception $e) {
            $err = 'Reset failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Reload fresh after any save
$irsCfg = $_irsConfigFn ? getIrsConfig() : $irsCfg;

// Get history of changes
$history = [];
try {
    $history = $db->query("SELECT c.*, u.name changer FROM irs_config c LEFT JOIN users u ON u.id=c.updated_by ORDER BY c.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$allRoles = ROLES;
$currentAccts = $irsCfg['accounts_roles'];
$currentMgrs  = $irsCfg['manager_roles'];
$currentLimit = $irsCfg['petty_cash_limit'];

Layout::shell($user, 'admin', 0, 'IRS Settings');
?>
<style>
.irs-section{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;}
.irs-section-title{font-size:1rem;font-weight:700;color:#002850;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;}
.role-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.6rem;}
.role-cb{display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:border-color .15s,background .15s;}
.role-cb:hover{background:#f8fafc;border-color:#cbd5e1;}
.role-cb.accts-checked{background:#fef3c7;border-color:#f59e0b;}
.role-cb.mgr-checked{background:#dbeafe;border-color:#3b82f6;}
.role-cb.both-checked{background:#fee2e2;border-color:#ef4444;}
.overlap-warn{display:none;color:#dc2626;font-size:.85rem;padding:.5rem .75rem;background:#fee2e2;border-radius:8px;margin-top:.75rem;}
.flow-diagram{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;padding:1rem;background:#f8fafc;border-radius:10px;font-size:.85rem;}
.flow-box{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .9rem;text-align:center;font-weight:600;color:#002850;}
.flow-arrow{color:#94a3b8;font-size:1.1rem;}
@media(max-width:640px){.role-grid{grid-template-columns:1fr 1fr;}}
</style>

<div class="hri-page">
<div class="hri-page-hd">
    <h1 class="hri-page-title">&#9881; IRS Configuration</h1>
    <a href="../irs-approvals.php" class="hri-btn hri-btn-outline">View Approvals Queue &#8594;</a>
</div>

<?php if ($msg): ?>
<div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:.9rem 1.25rem;border-radius:10px;margin-bottom:1rem;font-weight:500;">&#10003; <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#dc2626;padding:.9rem 1.25rem;border-radius:10px;margin-bottom:1rem;font-weight:500;">&#9888; <?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<!-- Current flow diagram -->
<div class="irs-section">
    <div class="irs-section-title">&#128200; Current Approval Flow</div>
    <div class="flow-diagram">
        <div class="flow-box" style="background:#fef3c7;border-color:#f59e0b;">&#128196; Requester submits</div>
        <div class="flow-arrow">&#8594;</div>
        <div class="flow-box" style="background:#fef3c7;border-color:#f59e0b;">
            Accounts Review<br>
            <span style="font-size:.75rem;font-weight:400;color:#64748b;"><?= implode(', ', array_map(function($r){ return ROLES[$r]['label'] ?? $r; }, $currentAccts)) ?></span>
        </div>
        <div class="flow-arrow">&#8594;</div>
        <div class="flow-box" style="background:#dbeafe;border-color:#3b82f6;">
            Manager Approval<br>
            <span style="font-size:.75rem;font-weight:400;color:#64748b;"><?= count($currentMgrs) ?> roles</span>
        </div>
        <div class="flow-arrow">&#8594;</div>
        <div class="flow-box" style="background:#dcfce7;border-color:#86efac;">&#10003; Posted to Sage &amp; Closed</div>
    </div>
    <div style="margin-top:.75rem;font-size:.82rem;color:#64748b;">
        Petty cash ceiling: <strong>&#8358;<?= number_format($currentLimit) ?></strong> — requests above this cannot be disbursed as petty cash.
    </div>
</div>

<form method="post">
<!-- Accounts roles -->
<div class="irs-section">
    <div class="irs-section-title" style="color:#92400e;">&#128190; Stage 1 — Accounts Review Roles</div>
    <p style="font-size:.85rem;color:#64748b;margin-bottom:1rem;">These roles handle eligibility checks, custodian verification, payment processing, and Sage posting. They see all statuses that require financial action.</p>
    <div class="role-grid" id="acctsGrid">
    <?php foreach ($allRoles as $key => $rd): $checked = in_array($key, $currentAccts); ?>
    <label class="role-cb <?= $checked ? 'accts-checked' : '' ?>" id="al_<?= $key ?>" onclick="updateRoleStyle('<?= $key ?>')">
        <input type="checkbox" name="accounts_roles[]" value="<?= $key ?>" <?= $checked ? 'checked' : '' ?> onchange="updateRoleStyle('<?= $key ?>')">
        <span><?= $rd['icon'] ?> <?= htmlspecialchars($rd['label']) ?></span>
        <span style="font-size:.7rem;color:#94a3b8;margin-left:auto;">L<?= $rd['level'] ?></span>
    </label>
    <?php endforeach; ?>
    </div>
</div>

<!-- Manager roles -->
<div class="irs-section">
    <div class="irs-section-title" style="color:#1e40af;">&#9989; Stage 2 — Manager Approval Roles</div>
    <p style="font-size:.85rem;color:#64748b;margin-bottom:1rem;">These roles give final sign-off after accounts review. <strong>Should be Level 1 only (MD and BDM)</strong> — only top management should have final approval authority. They should NOT overlap with Accounts roles to prevent self-approval.</p>
    <div class="role-grid" id="mgrGrid">
    <?php foreach ($allRoles as $key => $rd): $checked = in_array($key, $currentMgrs); ?>
    <label class="role-cb <?= $checked ? 'mgr-checked' : '' ?>" id="ml_<?= $key ?>" onclick="updateRoleStyle('<?= $key ?>')">
        <input type="checkbox" name="manager_roles[]" value="<?= $key ?>" <?= $checked ? 'checked' : '' ?> onchange="updateRoleStyle('<?= $key ?>')">
        <span><?= $rd['icon'] ?> <?= htmlspecialchars($rd['label']) ?></span>
        <span style="font-size:.7rem;color:#94a3b8;margin-left:auto;">L<?= $rd['level'] ?></span>
    </label>
    <?php endforeach; ?>
    </div>
    <div id="overlapWarn" class="overlap-warn">&#9888; Overlap detected — roles marked in red appear in BOTH groups. This means one person could approve their own request. Remove from one group to fix.</div>
</div>

<!-- Petty cash limit -->
<div class="irs-section">
    <div class="irs-section-title">&#128176; Petty Cash Limit</div>
    <div style="max-width:320px;">
        <label class="hri-label">Maximum disbursement ceiling (&#8358;)</label>
        <input type="number" name="petty_cash_limit" class="hri-input" value="<?= $currentLimit ?>" min="0" step="1000" style="font-size:1.1rem;font-weight:700;">
        <div style="font-size:.8rem;color:#64748b;margin-top:.4rem;">Requests above this amount will show "Exceeds Limit" and cannot proceed as petty cash.</div>
    </div>
</div>

<div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
    <button type="submit" name="action" value="save_roles" class="hri-btn hri-btn-navy" style="font-size:.95rem;padding:.65rem 1.75rem;">&#10003; Save Configuration</button>
    <button type="submit" name="action" value="reset_defaults" class="hri-btn hri-btn-outline" style="color:#dc2626;border-color:#fca5a5;" onclick="return confirm('Reset all IRS config to system defaults? This cannot be undone.')">&#8635; Reset to Defaults</button>
    <span style="font-size:.8rem;color:#64748b;">Changes take effect immediately for all users.</span>
</div>
</form>

<?php if (!empty($history)): ?>
<!-- Change history -->
<div class="irs-section" style="margin-top:1.5rem;">
    <div class="irs-section-title">&#128203; Configuration Change Log</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
        <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
            <th style="padding:.5rem 1rem;text-align:left;color:#64748b;">Setting</th>
            <th style="padding:.5rem 1rem;text-align:left;color:#64748b;">Current Value</th>
            <th style="padding:.5rem 1rem;text-align:left;color:#64748b;">Description</th>
            <th style="padding:.5rem 1rem;text-align:left;color:#64748b;">Last Changed By</th>
            <th style="padding:.5rem 1rem;text-align:left;color:#64748b;">When</th>
        </tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
        <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:.6rem 1rem;font-family:monospace;color:#002850;"><?= htmlspecialchars($h['key']) ?></td>
            <td style="padding:.6rem 1rem;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.78rem;" title="<?= htmlspecialchars($h['value']) ?>"><?= htmlspecialchars($h['value']) ?></td>
            <td style="padding:.6rem 1rem;color:#64748b;"><?= htmlspecialchars($h['label'] ?? '') ?></td>
            <td style="padding:.6rem 1rem;"><?= htmlspecialchars($h['changer'] ?? 'System') ?></td>
            <td style="padding:.6rem 1rem;color:#64748b;white-space:nowrap;"><?= $h['updated_at'] ? date('d M Y H:i', strtotime($h['updated_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div><!-- /hri-page -->

<script>
var _accts = <?= json_encode($currentAccts) ?>;
var _mgrs  = <?= json_encode($currentMgrs) ?>;

function getRoleStates(key) {
    var inAccts = document.querySelector('#acctsGrid input[value="'+key+'"]')?.checked;
    var inMgrs  = document.querySelector('#mgrGrid input[value="'+key+'"]')?.checked;
    return {inAccts, inMgrs};
}

function updateRoleStyle(key) {
    var s = getRoleStates(key);
    var al = document.getElementById('al_'+key);
    var ml = document.getElementById('ml_'+key);
    if (al) {
        al.classList.remove('accts-checked','both-checked');
        if (s.inAccts && s.inMgrs) al.classList.add('both-checked');
        else if (s.inAccts) al.classList.add('accts-checked');
    }
    if (ml) {
        ml.classList.remove('mgr-checked','both-checked');
        if (s.inAccts && s.inMgrs) ml.classList.add('both-checked');
        else if (s.inMgrs) ml.classList.add('mgr-checked');
    }
    checkOverlaps();
}

function checkOverlaps() {
    var overlap = [];
    document.querySelectorAll('#acctsGrid input[type=checkbox]:checked').forEach(function(cb) {
        var ml = document.querySelector('#mgrGrid input[value="'+cb.value+'"]:checked');
        if (ml) overlap.push(cb.value);
    });
    var w = document.getElementById('overlapWarn');
    if (w) w.style.display = overlap.length ? 'block' : 'none';
}

// Init on load
document.querySelectorAll('#acctsGrid input, #mgrGrid input').forEach(function(cb) {
    updateRoleStyle(cb.value);
});
checkOverlaps();
</script>
<?php Layout::end(); ?>
