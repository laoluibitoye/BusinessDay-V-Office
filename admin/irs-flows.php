<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';
require_once __DIR__ . '/../lib/IrsFlow.php';

$user = Auth::requireRole(['head_it','md','bdm']);
$db   = getDB();

$allRoles = ROLES;

// Handle AJAX saves
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $sub = $_POST['sub'] ?? '';

    if ($sub === 'update_stage_roles') {
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $roles   = $_POST['roles'] ?? [];
        $roles   = array_filter($roles, function($r) use ($allRoles) { return isset($allRoles[$r]); });
        $rolesJson = json_encode(array_values($roles));
        try {
            $db->prepare("UPDATE irs_flow_stages SET actor_roles=? WHERE id=?")->execute([$rolesJson, $stageId]);
            Auth::auditLog($user['id'],'irs_flow_edit',"Stage #{$stageId} actor_roles updated to {$rolesJson}");
            echo json_encode(['ok'=>true]);
        } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>'DB error']); }
        exit;
    }

    if ($sub === 'update_transition') {
        $transId      = (int)($_POST['trans_id'] ?? 0);
        $actionLabel  = trim($_POST['action_label'] ?? '');
        $toStage      = trim($_POST['to_stage'] ?? '');
        $requiresNote = (int)($_POST['requires_note'] ?? 0);
        $btnStyle     = in_array($_POST['btn_style'], ['success','danger','warning','primary']) ? $_POST['btn_style'] : 'primary';
        $validStages  = IrsFlow::allStageCodes();
        if (!$transId || !$actionLabel || !$toStage) { echo json_encode(['ok'=>false,'error'=>'Invalid data']); exit; }
        if (!in_array($toStage, $validStages)) { echo json_encode(['ok'=>false,'error'=>'Invalid target stage code.']); exit; }
        try {
            $db->prepare("UPDATE irs_flow_transitions SET action_label=?,to_stage=?,requires_note=?,btn_style=?,updated_by=?,updated_at=NOW() WHERE id=?")
               ->execute([$actionLabel, $toStage, $requiresNote, $btnStyle, $user['id'], $transId]);
            Auth::auditLog($user['id'],'irs_flow_edit',"Transition #{$transId} updated: label={$actionLabel}, to={$toStage}");
            echo json_encode(['ok'=>true]);
        } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>'DB error']); }
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown sub-action']);
    exit;
}

$types = [
    'requisition' => ['label'=>'Requisition',     'icon'=>'&#128203;', 'color'=>'#002850'],
    'caution'     => ['label'=>'Caution Payment', 'icon'=>'&#9888;',   'color'=>'#d97706'],
    'payment'     => ['label'=>'Payment Request', 'icon'=>'&#128179;', 'color'=>'#0891b2'],
    'petty_cash'  => ['label'=>'Petty Cash',      'icon'=>'&#128176;', 'color'=>'#059669'],
    'retirement'  => ['label'=>'Retirement',      'icon'=>'&#128204;', 'color'=>'#6d28d9'],
];

// ── Table existence check ─────────────────────────────────────────────────────
$dbOk = ['stages' => false, 'transitions' => false, 'stages_seeded' => 0, 'trans_seeded' => 0];
try {
    $dbOk['stages']      = (bool)$db->query("SELECT 1 FROM irs_flow_stages   LIMIT 1")->fetchColumn();
    $dbOk['stages']      = true;
    $dbOk['stages_seeded'] = (int)$db->query("SELECT COUNT(*) FROM irs_flow_stages")->fetchColumn();
} catch (Exception $e) { $dbOk['stages'] = false; }
try {
    $dbOk['transitions']  = (bool)$db->query("SELECT 1 FROM irs_flow_transitions LIMIT 1")->fetchColumn();
    $dbOk['transitions']  = true;
    $dbOk['trans_seeded'] = (int)$db->query("SELECT COUNT(*) FROM irs_flow_transitions")->fetchColumn();
} catch (Exception $e) { $dbOk['transitions'] = false; }
$migrationNeeded = !$dbOk['stages'] || !$dbOk['transitions'];
$seedNeeded      = !$migrationNeeded && ($dbOk['stages_seeded'] === 0 || $dbOk['trans_seeded'] === 0);

// Load all stages + transitions grouped by type
$allStages      = [];
$allTransitions = [];
if (!$migrationNeeded) {
    foreach (array_keys($types) as $t) {
        $allStages[$t]      = IrsFlow::getStages($db, $t);
        $allTransitions[$t] = [];
        foreach ($allStages[$t] as $s) {
            if ($s['is_terminal'] || $s['is_initial']) continue;
            $allTransitions[$t][$s['stage_code']] = IrsFlow::getTransitions($db, $t, $s['stage_code']);
        }
    }
}

Layout::shell($user, 'admin', 0, 'IRS Flow Configuration');
?>
<style>
.irsf-tab { padding:.5rem 1rem; font-size:.85rem; font-weight:600; cursor:pointer; border-bottom:2px solid transparent; color:#64748b; white-space:nowrap; }
.irsf-tab.active { color:var(--navy); border-bottom-color:var(--navy); }
.irsf-panel { display:none; }
.irsf-panel.active { display:block; }

.irsf-stage { background:#fff; border:1.5px solid #e2e8f0; border-radius:.5rem; padding:.75rem 1rem; margin-bottom:.6rem; }
.irsf-stage-hd { display:flex; align-items:center; gap:.6rem; margin-bottom:.5rem; }
.irsf-stage-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.irsf-stage-name { font-weight:700; font-size:.9rem; color:#002850; }
.irsf-stage-code { font-family:monospace; font-size:.75rem; color:#64748b; margin-left:.25rem; }

.irsf-trans { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.4rem; padding:.5rem .75rem; margin-bottom:.4rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
.irsf-trans-arrow { color:#94a3b8; font-size:.8rem; }
.irsf-trans-badge { font-size:.72rem; padding:.15rem .5rem; border-radius:9999px; font-weight:600; }
.irsf-trans-badge.success { background:#dcfce7; color:#166534; }
.irsf-trans-badge.danger  { background:#fee2e2; color:#be123c; }
.irsf-trans-badge.warning { background:#fffbeb; color:#92400e; }
.irsf-trans-badge.primary { background:#eff6ff; color:#1d4ed8; }

.irsf-edit-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:800; align-items:center; justify-content:center; }
.irsf-modal { background:#fff; border-radius:.75rem; width:100%; max-width:440px; padding:1.5rem; }

.role-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.25rem .6rem; border:1.5px solid #e2e8f0; border-radius:9999px; font-size:.78rem; font-weight:500; cursor:pointer; margin:.2rem; transition:all .15s; }
.role-chip.selected { background:var(--navy); color:#fff; border-color:var(--navy); }
/* mobile-all-pages */
@media(max-width:768px){
    [class*='-grid'],[class*='-row']{grid-template-columns:1fr!important;}
}
</style>

<div class="hri-page">
  <div class="hri-page-hd">
    <h1 class="hri-page-title">&#9881; IRS Workflow Configuration</h1>
    <div style="font-size:.85rem;color:#64748b;">Configure approval flows for each request type. Changes take effect immediately.</div>
  </div>

  <?php if ($migrationNeeded): ?>
  <!-- ── Migration not run banner ──────────────────────────────────── -->
  <div style="background:#fff1f2;border:2px solid #fca5a5;border-radius:.5rem;padding:1.25rem 1.5rem;margin-bottom:1.5rem;">
    <div style="font-size:1rem;font-weight:700;color:#be123c;margin-bottom:.5rem;">&#10060; Database tables missing — migration required</div>
    <div style="font-size:.875rem;color:#7f1d1d;margin-bottom:1rem;line-height:1.6;">
      The following tables do not exist on the server:<br>
      <?php if (!$dbOk['stages']): ?><code style="background:#fee2e2;padding:.1rem .4rem;border-radius:.25rem;">irs_flow_stages</code> &nbsp;<?php endif; ?>
      <?php if (!$dbOk['transitions']): ?><code style="background:#fee2e2;padding:.1rem .4rem;border-radius:.25rem;">irs_flow_transitions</code><?php endif; ?>
    </div>
    <div style="font-size:.875rem;color:#be123c;font-weight:600;margin-bottom:.5rem;">To fix:</div>
    <ol style="font-size:.875rem;color:#7f1d1d;line-height:2;margin:0 0 0 1.25rem;">
      <li>Log in to <strong>cPanel &rarr; phpMyAdmin</strong></li>
      <li>Select database <code style="background:#fee2e2;padding:.1rem .4rem;border-radius:.25rem;">hrindexx_hri_webmail</code></li>
      <li>Click the <strong>SQL</strong> tab</li>
      <li>Open <code style="background:#fee2e2;padding:.1rem .4rem;border-radius:.25rem;">database/irs_flow_migration.sql</code> from your local files, copy its entire contents and paste it into the SQL box</li>
      <li>Click <strong>Go</strong> — then reload this page</li>
    </ol>
  </div>
  <?php elseif ($seedNeeded): ?>
  <!-- ── Tables exist but empty ────────────────────────────────────── -->
  <div style="background:#fffbeb;border:2px solid #fcd34d;border-radius:.5rem;padding:1.25rem 1.5rem;margin-bottom:1.5rem;">
    <div style="font-size:1rem;font-weight:700;color:#92400e;margin-bottom:.5rem;">&#9888; Tables exist but have no data</div>
    <div style="font-size:.875rem;color:#78350f;margin-bottom:.5rem;line-height:1.6;">
      <code>irs_flow_stages</code>: <?= $dbOk['stages_seeded'] ?> rows &nbsp;|&nbsp;
      <code>irs_flow_transitions</code>: <?= $dbOk['trans_seeded'] ?> rows
    </div>
    <div style="font-size:.875rem;color:#78350f;">
      The tables exist but the seed data (stage and transition definitions) was not inserted.<br>
      Run <code style="background:#fffbeb;padding:.1rem .4rem;">database/irs_flow_migration.sql</code> in phpMyAdmin — the INSERT IGNORE statements will fill in the flow definitions without affecting existing data.
    </div>
  </div>
  <?php else: ?>
  <!-- Normal notice -->
  <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:.5rem;padding:.75rem 1.25rem;font-size:.85rem;color:#92400e;margin-bottom:1.25rem;">
    &#9888; Changing actor roles here affects who can approve live requests immediately. Action labels and routes update on next page load.
  </div>
  <?php endif; ?>

  <?php if ($migrationNeeded || $seedNeeded): ?>
  </div><!-- .hri-page -->
  <?php Layout::end(); exit; ?>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="hri-card" style="padding:0 1rem;">
    <div style="display:flex;gap:0;overflow-x:auto;border-bottom:1px solid #e2e8f0;">
      <?php foreach ($types as $tKey => $tCfg): ?>
      <button class="irsf-tab<?= $tKey === 'requisition' ? ' active' : '' ?>"
          onclick="switchTab('<?= $tKey ?>')" id="tab_<?= $tKey ?>">
        <?= $tCfg['icon'] ?> <?= $tCfg['label'] ?>
      </button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Panels -->
  <?php foreach ($types as $tKey => $tCfg): ?>
  <div class="irsf-panel<?= $tKey === 'requisition' ? ' active' : '' ?>" id="panel_<?= $tKey ?>" style="margin-top:1.25rem;">
    <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:1.25rem;">

      <!-- Stage flow column -->
      <div class="hri-card">
        <div class="hri-card-hd"><h2 class="hri-card-title">&#128203; Stage Flow</h2></div>
        <p style="font-size:.82rem;color:#64748b;margin-bottom:1rem;">Click a stage to edit which roles can act on it. The flow order is fixed — contact IT to add/remove stages.</p>

        <?php foreach ($allStages[$tKey] as $stage): ?>
        <?php
          $isTerminal = $stage['is_terminal'];
          $isInitial  = $stage['is_initial'];
          $actors     = json_decode($stage['actor_roles'], true) ?: [];
          $color      = preg_replace('/[^a-zA-Z0-9#(),%. ]/', '', $stage['stage_color'] ?: '#94a3b8');
        ?>
        <div class="irsf-stage" <?= (!$isTerminal && !$isInitial) ? 'style="cursor:pointer;" onclick="editStage('.$stage['id'].','.json_encode($stage['stage_label']).','.json_encode($actors).')"' : '' ?>>
          <div class="irsf-stage-hd">
            <div class="irsf-stage-dot" style="background:<?= $color ?>;"></div>
            <span class="irsf-stage-name"><?= htmlspecialchars($stage['stage_label']) ?></span>
            <span class="irsf-stage-code">(<?= htmlspecialchars($stage['stage_code']) ?>)</span>
            <?php if ($isTerminal): ?>
            <span style="font-size:.72rem;background:#f1f5f9;color:#64748b;padding:.15rem .4rem;border-radius:.25rem;margin-left:auto;">Terminal</span>
            <?php elseif ($isInitial): ?>
            <span style="font-size:.72rem;background:#eff6ff;color:#1d4ed8;padding:.15rem .4rem;border-radius:.25rem;margin-left:auto;">Initial</span>
            <?php else: ?>
            <span style="font-size:.72rem;color:#94a3b8;margin-left:auto;">&#9999; Edit</span>
            <?php endif; ?>
          </div>
          <?php if (!$isTerminal && !$isInitial): ?>
          <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.1rem;">
            <?php if (empty($actors)): ?>
            <span style="font-size:.78rem;color:#ef4444;font-style:italic;">No actors defined — click to set</span>
            <?php else: ?>
            <?php foreach ($actors as $roleKey): ?>
            <span style="font-size:.75rem;background:<?= htmlspecialchars(ROLES[$roleKey]['color'] ?? '#64748b') ?>20;color:<?= htmlspecialchars(ROLES[$roleKey]['color'] ?? '#64748b') ?>;padding:.15rem .5rem;border-radius:9999px;font-weight:500;">
              <?= htmlspecialchars(ROLES[$roleKey]['icon'] ?? '') ?> <?= htmlspecialchars(ROLES[$roleKey]['label'] ?? $roleKey) ?>
            </span>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if (!$isTerminal && !empty($allTransitions[$tKey][$stage['stage_code']])): ?>
          <div style="margin-top:.6rem;padding-top:.5rem;border-top:1px dashed #e2e8f0;">
            <?php foreach ($allTransitions[$tKey][$stage['stage_code']] as $tr): ?>
            <div class="irsf-trans">
              <span class="irsf-trans-badge <?= htmlspecialchars($tr['btn_style']) ?>"><?= htmlspecialchars($tr['action_label']) ?></span>
              <span class="irsf-trans-arrow">&#8594;</span>
              <span style="font-size:.8rem;font-family:monospace;color:#002850;"><?= htmlspecialchars($tr['to_stage']) ?></span>
              <?php if ($tr['requires_note']): ?><span style="font-size:.72rem;color:#f59e0b;">note req.</span><?php endif; ?>
              <?php if ($tr['requires_amount']): ?><span style="font-size:.72rem;color:#8b5cf6;">amount req.</span><?php endif; ?>
              <?php if ($tr['requires_sage_ref']): ?><span style="font-size:.72rem;color:#059669;">sage req.</span><?php endif; ?>
              <button onclick="event.stopPropagation();editTransition(<?= $tr['id'] ?>,<?= json_encode($tr['action_label']) ?>,<?= json_encode($tr['to_stage']) ?>,<?= $tr['requires_note'] ?>,<?= json_encode($tr['btn_style']) ?>)"
                  class="hri-btn hri-btn-outline" style="font-size:.72rem;padding:.15rem .4rem;margin-left:auto;">Edit</button>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!$stage['is_terminal'] && !$stage['is_initial']): ?>
        <div style="text-align:center;color:#cbd5e1;font-size:1.1rem;margin:-2px 0 -2px;">&#8595;</div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <!-- Summary column -->
      <div>
        <div class="hri-card" style="margin-bottom:1.25rem;">
          <div class="hri-card-hd"><h2 class="hri-card-title">&#128200; Flow Summary</h2></div>
          <?php
          $nonTerminal = array_filter($allStages[$tKey], function($s) { return !$s['is_terminal'] && !$s['is_initial']; });
          ?>
          <div style="font-size:.85rem;color:#64748b;margin-bottom:.75rem;"><?= count($nonTerminal) ?> approval stage<?= count($nonTerminal) !== 1 ? 's' : '' ?></div>
          <ol style="margin:0;padding:0 0 0 1.25rem;font-size:.85rem;line-height:1.9;">
            <?php foreach ($nonTerminal as $s):
              $actors = json_decode($s['actor_roles'], true) ?: [];
              $actorLabels = array_map(function($r) { return ROLES[$r]['label'] ?? $r; }, $actors);
            ?>
            <li>
              <span style="font-weight:600;"><?= htmlspecialchars($s['stage_label']) ?></span>
              <?php if (!empty($actorLabels)): ?>
              <br><span style="color:#94a3b8;font-size:.78rem;"><?= htmlspecialchars(implode(', ', $actorLabels)) ?></span>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ol>
        </div>

        <div class="hri-card">
          <div class="hri-card-hd"><h2 class="hri-card-title">&#128274; Access Rules</h2></div>
          <div style="font-size:.82rem;line-height:1.7;color:#374151;">
            <div style="margin-bottom:.5rem;padding:.4rem .6rem;background:#f0fdf4;border-radius:.35rem;font-size:.78rem;">
              <strong>Who sees ALL requests:</strong><br>
              <?= implode(', ', array_map(function($r) { return ROLES[$r]['label'] ?? $r; }, IrsFlow::VIEW_ALL_ROLES)) ?>
            </div>
            <div style="padding:.4rem .6rem;background:#eff6ff;border-radius:.35rem;font-size:.78rem;">
              <strong>Payment Request restricted to:</strong><br>
              <?= implode(', ', array_map(function($r) { return ROLES[$r]['label'] ?? $r; }, IrsFlow::PAYMENT_RAISER_ROLES)) ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Edit Stage Modal -->
<div class="irsf-edit-overlay" id="stageModal">
  <div class="irsf-modal">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
      <h3 style="font-size:1rem;font-weight:700;color:#002850;margin:0;">Edit Stage Actors</h3>
      <button onclick="closeModals()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b;">&times;</button>
    </div>
    <div id="stageName" style="font-size:.85rem;color:#64748b;margin-bottom:.875rem;"></div>
    <div style="font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.5rem;">Select roles that can act at this stage:</div>
    <div id="roleChips" style="margin-bottom:1.25rem;"></div>
    <input type="hidden" id="editStageId">
    <div style="display:flex;gap:.5rem;justify-content:flex-end;">
      <button onclick="closeModals()" class="hri-btn hri-btn-outline">Cancel</button>
      <button onclick="saveStageRoles()" class="hri-btn hri-btn-navy" id="saveStageBtn">Save Roles</button>
    </div>
  </div>
</div>

<!-- Edit Transition Modal -->
<div class="irsf-edit-overlay" id="transModal">
  <div class="irsf-modal">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
      <h3 style="font-size:1rem;font-weight:700;color:#002850;margin:0;">Edit Transition</h3>
      <button onclick="closeModals()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b;">&times;</button>
    </div>
    <div class="hri-form-group" style="margin-bottom:.75rem;">
      <label class="hri-label">Button Label</label>
      <input type="text" id="transLabel" class="hri-input" placeholder="e.g. Approve, Reject...">
    </div>
    <div class="hri-form-group" style="margin-bottom:.75rem;">
      <label class="hri-label">Next Stage (stage_code)</label>
      <input type="text" id="transToStage" class="hri-input" placeholder="e.g. pending_md">
      <div style="font-size:.75rem;color:#94a3b8;margin-top:.2rem;">Available: draft, pending_eligibility, pending_accountant, pending_hod_accounts, pending_md, pending_payment, pending_payment_approval, pending_post, completed, rejected</div>
    </div>
    <div class="hri-form-group" style="margin-bottom:.75rem;">
      <label class="hri-label">Button Style</label>
      <select id="transBtnStyle" class="hri-select">
        <option value="success">Green (success / approve)</option>
        <option value="danger">Red (danger / reject)</option>
        <option value="warning">Amber (warning / return)</option>
        <option value="primary">Navy (primary / neutral)</option>
      </select>
    </div>
    <div style="margin-bottom:1.25rem;">
      <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem;">
        <input type="checkbox" id="transRequiresNote"> Require a comment/reason for this action
      </label>
    </div>
    <input type="hidden" id="editTransId">
    <div style="display:flex;gap:.5rem;justify-content:flex-end;">
      <button onclick="closeModals()" class="hri-btn hri-btn-outline">Cancel</button>
      <button onclick="saveTransition()" class="hri-btn hri-btn-navy">Save Transition</button>
    </div>
  </div>
</div>

<script>
var ALL_ROLES = <?= json_encode(array_map(function($k, $v) {
    return ['key'=>$k,'label'=>$v['label'],'icon'=>$v['icon'],'color'=>$v['color']];
}, array_keys(ROLES), ROLES)) ?>;

function switchTab(type) {
    document.querySelectorAll('.irsf-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.irsf-panel').forEach(function(p) { p.classList.remove('active'); });
    document.getElementById('tab_' + type).classList.add('active');
    document.getElementById('panel_' + type).classList.add('active');
}

function closeModals() {
    document.getElementById('stageModal').style.display = 'none';
    document.getElementById('transModal').style.display = 'none';
}

// ── Stage role editor ──────────────────────────────────────────────────────
var selectedRoles = [];

function editStage(id, label, currentRoles) {
    selectedRoles = currentRoles.slice();
    document.getElementById('editStageId').value = id;
    document.getElementById('stageName').textContent = 'Stage: ' + label;
    renderRoleChips();
    document.getElementById('stageModal').style.display = 'flex';
}

function renderRoleChips() {
    var container = document.getElementById('roleChips');
    container.innerHTML = '';
    ALL_ROLES.forEach(function(r) {
        var isSelected = selectedRoles.indexOf(r.key) !== -1;
        var chip = document.createElement('span');
        chip.className = 'role-chip' + (isSelected ? ' selected' : '');
        chip.innerHTML = r.icon + ' ' + r.label;
        chip.onclick = function() {
            var idx = selectedRoles.indexOf(r.key);
            if (idx === -1) { selectedRoles.push(r.key); }
            else { selectedRoles.splice(idx, 1); }
            renderRoleChips();
        };
        container.appendChild(chip);
    });
}

function saveStageRoles() {
    var btn = document.getElementById('saveStageBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
    var fd = new FormData();
    fd.append('sub', 'update_stage_roles');
    fd.append('stage_id', document.getElementById('editStageId').value);
    selectedRoles.forEach(function(r) { fd.append('roles[]', r); });
    fetch('', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN,'X-Requested-With':'XMLHttpRequest'},
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) { window.location.reload(); }
        else { alert(data.error || 'Save failed.'); btn.disabled = false; btn.textContent = 'Save Roles'; }
    })
    .catch(function() { alert('Network error.'); btn.disabled = false; btn.textContent = 'Save Roles'; });
}

// ── Transition editor ─────────────────────────────────────────────────────
function editTransition(id, label, toStage, requiresNote, btnStyle) {
    document.getElementById('editTransId').value = id;
    document.getElementById('transLabel').value = label;
    document.getElementById('transToStage').value = toStage;
    document.getElementById('transRequiresNote').checked = !!requiresNote;
    document.getElementById('transBtnStyle').value = btnStyle;
    document.getElementById('transModal').style.display = 'flex';
}

function saveTransition() {
    var fd = new FormData();
    fd.append('sub', 'update_transition');
    fd.append('trans_id', document.getElementById('editTransId').value);
    fd.append('action_label', document.getElementById('transLabel').value.trim());
    fd.append('to_stage', document.getElementById('transToStage').value.trim());
    fd.append('requires_note', document.getElementById('transRequiresNote').checked ? '1' : '0');
    fd.append('btn_style', document.getElementById('transBtnStyle').value);
    fetch('', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN,'X-Requested-With':'XMLHttpRequest'},
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) { window.location.reload(); }
        else { alert(data.error || 'Save failed.'); }
    })
    .catch(function() { alert('Network error.'); });
}

// Close modals on backdrop click
['stageModal','transModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModals();
    });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModals(); });
</script>
<?php Layout::end(); ?>
