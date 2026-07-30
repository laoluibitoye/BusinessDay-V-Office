<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin']);
$db   = getDB();

$success = '';
$error   = '';

// ── CHECK TABLES ────────────────────────────────────────────────────────────
$deptReady   = false;
$permReady   = false;
$customReady = false;
try { $db->query("SELECT 1 FROM departments LIMIT 1");    $deptReady   = true; } catch(PDOException $e){}
try { $db->query("SELECT 1 FROM role_permissions LIMIT 1"); $permReady  = true; } catch(PDOException $e){}
try { $db->query("SELECT 1 FROM custom_roles LIMIT 1");     $customReady= true; } catch(PDOException $e){}

// Load disabled roles early so POST handler can reference it
$deletedRoles = [];
if ($permReady) {
    try {
        $drSt = $db->query("SELECT role_key FROM role_permissions WHERE feature='_disabled' AND access='write'");
        $deletedRoles = $drSt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
}

// Load custom roles early so POST handler can reference it
$customRoles = [];
if ($customReady) {
    try {
        foreach ($db->query("SELECT * FROM custom_roles ORDER BY level, label") as $cr) {
            $customRoles[$cr['role_key']] = [
                'label'      => $cr['label'],
                'icon'       => $cr['icon']  ?: '👤',
                'level'      => (int)$cr['level'],
                'color'      => $cr['color'] ?: '#64748b',
                'permissions'=> [],
                '_custom'    => true,
            ];
        }
    } catch (PDOException $e) {}
}
// Merged view: built-in roles take precedence over any same-key custom role
$allRoles = ROLES + $customRoles;

// ── HANDLE POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // -- SAVE ROLE PERMISSIONS --
    if ($act === 'save_permissions' && $permReady) {
        $allowed = ['none','read','write'];
        $perms   = $_POST['perms'] ?? [];
        foreach ($perms as $roleKey => $features) {
            if (!isset($allRoles[$roleKey])) continue;
            if (in_array($roleKey, $deletedRoles)) continue; // never overwrite a disabled role
            foreach ($features as $feature => $access) {
                $access = in_array($access, $allowed) ? $access : 'none';
                $db->prepare("INSERT INTO role_permissions (role_key, feature, access)
                              VALUES (?,?,?) ON DUPLICATE KEY UPDATE access=VALUES(access)")
                   ->execute([$roleKey, $feature, $access]);
            }
        }
        Auth::auditLog($user['id'], 'permissions_updated', 'Role permissions matrix updated');
        $success = 'Role permissions saved successfully.';
        header('Location: roles.php?saved=1');
        exit;
    }

    // -- DELETE ROLE (disable: set all features to none + add _disabled marker) --
    if ($act === 'delete_role' && $permReady) {
        $rk = $_POST['role_key'] ?? '';
        if (!isset(ROLES[$rk])) { $error = 'Invalid role.'; }
        else {
            $features_all = ['mail','tasks','leave_request','leave_approve','signing','vault','compliance','directory','roster','broadcast','it_request','visitors','admin'];
            foreach ($features_all as $fk) {
                $db->prepare("INSERT INTO role_permissions (role_key,feature,access) VALUES (?,?,'none') ON DUPLICATE KEY UPDATE access='none'")->execute([$rk,$fk]);
            }
            $db->prepare("INSERT INTO role_permissions (role_key,feature,access) VALUES (?,'_disabled','write') ON DUPLICATE KEY UPDATE access='write'")->execute([$rk]);
            Auth::auditLog($user['id'], 'role_deleted', "Role disabled: $rk");
            $success = 'Role "'.htmlspecialchars(ROLES[$rk]['label'] ?? $rk).'" disabled. All users with this role will have no access.';
            header('Location: roles.php?saved=1'); exit;
        }
    }

    // -- RESTORE ROLE (re-enable: remove all DB entries, falls back to ROLES constant defaults) --
    if ($act === 'restore_role' && $permReady) {
        $rk = $_POST['role_key'] ?? '';
        if (!isset(ROLES[$rk])) { $error = 'Invalid role.'; }
        else {
            $db->prepare("DELETE FROM role_permissions WHERE role_key=?")->execute([$rk]);
            Auth::auditLog($user['id'], 'role_restored', "Role restored: $rk");
            $success = 'Role "'.htmlspecialchars(ROLES[$rk]['label'] ?? $rk).'" restored to default permissions.';
            header('Location: roles.php?saved=1'); exit;
        }
    }

    // -- ADD CUSTOM ROLE --
    if ($act === 'add_role' && $customReady) {
        $rk    = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['role_key']   ?? '')));
        $label = trim($_POST['role_label'] ?? '');
        $icon  = trim($_POST['role_icon']  ?? '👤') ?: '👤';
        $level = max(1, min(4, (int)($_POST['role_level'] ?? 4)));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['role_color'] ?? '') ? $_POST['role_color'] : '#64748b';
        if (!$rk || !$label) { $error = 'Role key and label are required.'; }
        elseif (isset(ROLES[$rk]))  { $error = "\"$rk\" is a built-in role key — choose a different key."; }
        elseif (strlen($rk) > 50)   { $error = 'Role key must be 50 characters or fewer.'; }
        else {
            try {
                $db->prepare("INSERT INTO custom_roles (role_key, label, icon, level, color) VALUES (?,?,?,?,?)")
                   ->execute([$rk, $label, $icon, $level, $color]);
                Auth::auditLog($user['id'], 'role_created', "Custom role created: $rk ($label)");
                header('Location: roles.php?saved=1&msg='.urlencode("Role \"$label\" created.")); exit;
            } catch (PDOException $e) {
                $error = strpos($e->getMessage(),'Duplicate') ? "Role key \"$rk\" already exists." : 'Error creating role. Check that custom_roles table exists.';
            }
        }
    }

    // -- DELETE CUSTOM ROLE (permanent) --
    if ($act === 'delete_custom_role' && $customReady) {
        $rk = $_POST['role_key'] ?? '';
        if (!isset($customRoles[$rk])) { $error = 'Invalid custom role.'; }
        else {
            $lbl = $customRoles[$rk]['label'];
            $db->prepare("DELETE FROM custom_roles WHERE role_key=?")->execute([$rk]);
            $db->prepare("DELETE FROM role_permissions WHERE role_key=?")->execute([$rk]);
            Auth::auditLog($user['id'], 'role_deleted', "Custom role permanently deleted: $rk");
            header('Location: roles.php?saved=1&msg='.urlencode("Role \"$lbl\" deleted.")); exit;
        }
    }

    // -- ADD DEPARTMENT --
    if ($act === 'add_dept') {
        $name     = trim($_POST['dept_name'] ?? '');
        $headRole = $_POST['dept_head_role'] ?? '';
        $color    = $_POST['dept_color'] ?? '#64748b';
        if (!$name) { $error = 'Department name required.'; }
        else {
            try {
                $db->prepare("INSERT INTO departments (name, head_role, color) VALUES (?,?,?)")
                   ->execute([$name, $headRole ?: null, $color]);
                Auth::auditLog($user['id'], 'dept_added', "Department added: $name");
                $success = "Department \"$name\" added.";
            } catch (PDOException $e) {
                $error = strpos($e->getMessage(), 'Duplicate') !== false
                    ? "Department \"$name\" already exists."
                    : 'Error: run sprint4a_migration.sql first.';
            }
        }
    }

    // -- DELETE DEPARTMENT --
    if ($act === 'delete_dept') {
        $id = (int)($_POST['dept_id'] ?? 0);
        try {
            $row = $db->prepare("SELECT name FROM departments WHERE id=?");
            $row->execute([$id]);
            $drow = $row->fetch();
            $db->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);
            Auth::auditLog($user['id'], 'dept_deleted', "Department deleted: {$drow['name']}");
            $success = "Department deleted.";
        } catch (PDOException $e) {
            $error = 'Cannot delete — run sprint4a_migration.sql first.';
        }
    }
}

if (isset($_GET['saved'])) $success = !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Saved successfully.';

// ── DATA ─────────────────────────────────────────────────────────────────────
$departments = [];
try {
    $departments = $db->query("SELECT d.*, u.name as head_name
        FROM departments d LEFT JOIN users u ON u.role = d.head_role AND u.is_active=1
        ORDER BY d.name")->fetchAll();
} catch (PDOException $e) {}

$roleCounts = [];
try {
    $rc = $db->query("SELECT role, COUNT(*) as cnt FROM users WHERE is_active=1 GROUP BY role");
    foreach ($rc->fetchAll() as $r) $roleCounts[$r['role']] = $r['cnt'];
} catch (PDOException $e) {}

// Load permission matrix from DB (exclude _disabled sentinel from feature matrix)
$permMatrix = [];
if ($permReady) {
    try {
        foreach ($db->query("SELECT * FROM role_permissions WHERE feature != '_disabled'")->fetchAll() as $p) {
            $permMatrix[$p['role_key']][$p['feature']] = $p['access'];
        }
    } catch (PDOException $e) {}
}
// $deletedRoles already loaded above the POST block

// Features available in the system
$features = [
    'mail'         => ['label'=>'Mail',           'icon'=>'✉️'],
    'tasks'        => ['label'=>'Tasks',          'icon'=>'✅'],
    'leave_request'=> ['label'=>'Leave (Submit)', 'icon'=>'🏖️'],
    'leave_approve'=> ['label'=>'Leave (Approve)','icon'=>'👍'],
    'signing'      => ['label'=>'Signing',        'icon'=>'✍️'],
    'vault'        => ['label'=>'Vault',          'icon'=>'🔒'],
    'compliance'   => ['label'=>'Compliance',     'icon'=>'📋'],
    'directory'    => ['label'=>'Directory',      'icon'=>'📂'],
    'roster'       => ['label'=>'Roster',         'icon'=>'📅'],
    'broadcast'    => ['label'=>'Broadcast',      'icon'=>'📣'],
    'it_request'   => ['label'=>'IT Request',     'icon'=>'🖥️'],
    'visitors'     => ['label'=>'Visitors',       'icon'=>'🏢'],
    'admin'        => ['label'=>'Admin Panel',    'icon'=>'⚙️'],
];

// Group ALL roles (built-in + custom) by level
$byLevel = [];
foreach ($allRoles as $key => $cfg) {
    $byLevel[$cfg['level'] ?? 4][$key] = $cfg;
}
ksort($byLevel);
$levelLabels = [1=>'Management', 2=>'Heads of Department (HODs)', 3=>'Managers', 4=>'Staff'];

Layout::shell($user, 'roles', 0, 'Roles & Departments');
?>
<style>
.alert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:18px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.ph{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.pt{font-size:18px;font-weight:700;color:var(--navy);}
.ps{font-size:12.5px;color:var(--g400);margin-top:2px;}
.level-section{margin-bottom:28px;}
.level-hd{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--g400);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--g200);}
.role-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;}
.role-card{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;border-top:4px solid;}
.role-top{padding:12px 16px;display:flex;align-items:center;gap:10px;}
.role-ico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.role-name{font-size:13px;font-weight:700;color:var(--g900);}
.role-key{font-size:11px;color:var(--g400);font-family:monospace;}
.role-count{font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:var(--g100);color:var(--g500);}
.role-perms{padding:8px 16px 14px;display:flex;flex-wrap:wrap;gap:5px;}
.perm{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:20px;background:var(--g100);color:var(--g700);}
.perm.all{background:#dcfce7;color:#166534;}
.perm.leave{background:#dbeafe;color:#1e40af;}
.perm.comp{background:#f3f0ff;color:#7c3aed;}
.perm.vault{background:#fef3c7;color:#92400e;}
.card{background:var(--w);border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:20px;}
.card-hd{padding:14px 18px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.card-ttl{font-size:13.5px;font-weight:700;color:var(--navy);}
.tbl{width:100%;border-collapse:collapse;}
.tbl th{background:var(--g50);padding:9px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.tbl td{padding:11px 14px;border-bottom:1px solid var(--g50);font-size:13px;color:var(--g700);vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.dept-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;}
.btn{padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:all .15s;display:inline-flex;align-items:center;gap:5px;}
.btn.gn{background:var(--green);color:#fff;}
.btn.gn:hover{background:#4d7c10;}
.btn.nv{background:var(--navy);color:#fff;}
.btn.nv:hover{background:#001a38;}
.btn.rd{background:#dc2626;color:#fff;padding:4px 10px;font-size:11.5px;}
.fg{margin-bottom:10px;}
label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
input,select{border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);width:100%;}
input:focus,select:focus{border-color:var(--green);}
.form-row{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;}
.notice{background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:12px 16px;font-size:12.5px;color:#92400e;margin-bottom:18px;}
/* Help legend */
.help-legend{display:flex;gap:14px;flex-wrap:wrap;padding:12px 18px;background:var(--g50);border-bottom:1px solid var(--g100);}
.hl-item{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--g700);}
.hl-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;white-space:nowrap;}
.hl-badge.none{background:var(--g100);color:var(--g500);}
.hl-badge.read{background:#dbeafe;color:#1e40af;}
.hl-badge.write{background:#dcfce7;color:#166534;}
/* New role modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;display:none;align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.modal{background:var(--w);border-radius:16px;box-shadow:0 8px 32px rgba(0,40,80,.14);width:100%;max-width:440px;overflow:hidden;}
.modal-hd{padding:15px 20px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.modal-ttl{font-size:15px;font-weight:700;color:var(--navy);}
.modal-close{cursor:pointer;color:var(--g400);font-size:18px;line-height:1;}
.modal-bd{padding:20px;display:flex;flex-direction:column;gap:13px;}
.modal-ft{padding:14px 20px;border-top:1px solid var(--g100);display:flex;gap:8px;justify-content:flex-end;}
.fg2{display:flex;flex-direction:column;gap:4px;}
.fg2 label{font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;}
.fg2 input,.fg2 select{border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);width:100%;}
.fg2 input:focus,.fg2 select:focus{border-color:var(--green);}
.key-hint{font-size:11px;color:var(--g400);margin-top:2px;}
.custom-badge{font-size:9.5px;padding:1px 5px;border-radius:4px;background:#fef3c7;color:#92400e;font-weight:700;margin-left:4px;vertical-align:middle;}
/* Role Permissions Matrix */
.perm-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.perm-table{width:100%;border-collapse:collapse;min-width:620px;}
.perm-table th{background:var(--g50);padding:6px 4px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--g500);border-bottom:2px solid var(--g200);white-space:nowrap;text-align:center;}
.perm-table th.role-col{text-align:left;min-width:170px;position:sticky;left:0;background:var(--g50);z-index:2;}
.perm-table td{padding:6px 4px;border-bottom:1px solid var(--g50);vertical-align:middle;}
.perm-table td.role-name-cell{position:sticky;left:0;background:var(--w);z-index:1;min-width:170px;}
.perm-table tr:last-child td{border-bottom:none;}
.perm-table .level-row td{background:var(--g50);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g500);padding:6px 10px;}
.pt-btn{width:34px;height:24px;border-radius:5px;border:1.5px solid var(--g200);cursor:pointer;font-size:11px;font-weight:700;font-family:'Inter',sans-serif;background:var(--g50);transition:all .1s;display:inline-flex;align-items:center;justify-content:center;padding:0;line-height:1;}
.pt-btn.v-none{background:var(--g100);color:var(--g400);border-color:var(--g200);}
.pt-btn.v-read{background:#eff6ff;color:#1e40af;border-color:#93c5fd;}
.pt-btn.v-write{background:#f0fdf4;color:#166534;border-color:#86efac;}
.pt-btn:hover{transform:scale(1.15);z-index:1;position:relative;}
.role-ico-sm{display:inline-block;margin-right:5px;font-size:14px;}
.role-lv{display:inline-block;font-size:9.5px;padding:1px 6px;border-radius:10px;background:var(--g100);color:var(--g500);font-weight:600;margin-left:5px;vertical-align:middle;}
.sticky-save{position:sticky;bottom:0;background:rgba(255,255,255,.96);backdrop-filter:blur(6px);padding:12px 18px;border-top:1px solid var(--g200);display:flex;align-items:center;gap:12px;z-index:10;}
/* mobile-all-pages */
@media(max-width:768px){
    .form-row{grid-template-columns:1fr;}
    .role-grid{grid-template-columns:1fr;}
}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">

    <?php if ($success): ?>
    <div class="alert ok">&#10003; <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert er">&#9888; <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="ph">
        <div>
            <div class="pt">&#127917; Roles &amp; Permissions</div>
            <div class="ps">Manage role access levels and department structure.</div>
        </div>
        <?php if ($customReady): ?>
        <button class="btn nv" onclick="document.getElementById('newRoleModal').classList.add('open')">+ New Role</button>
        <?php else: ?>
        <span style="font-size:12px;color:var(--g400);">Run migration SQL to enable custom roles &#8595;</span>
        <?php endif; ?>
    </div>

    <!-- ── ROLE OVERVIEW CARDS ── -->
    <?php foreach ($byLevel as $level => $roles): ?>
    <div class="level-section">
        <div class="level-hd"><?= $levelLabels[$level] ?? "Level $level" ?></div>
        <div class="role-grid">
        <?php foreach ($roles as $key => $cfg): ?>
        <?php
            $cnt       = $roleCounts[$key] ?? 0;
            $perms     = $cfg['permissions'] ?? [];
            $bgLight   = $cfg['color'] . '22';
            $isDeleted = in_array($key, $deletedRoles);
            $isCustom  = !empty($cfg['_custom']);
        ?>
        <div class="role-card" style="border-top-color:<?= $cfg['color'] ?>;<?= $isDeleted ? 'opacity:.55;' : '' ?>">
            <div class="role-top">
                <div class="role-ico" style="background:<?= $bgLight ?>"><?= $cfg['icon'] ?></div>
                <div style="flex:1;min-width:0;">
                    <div class="role-name">
                        <?= htmlspecialchars($cfg['label']) ?>
                        <?php if ($isCustom): ?><span class="custom-badge">CUSTOM</span><?php endif; ?>
                        <?php if ($isDeleted): ?><span style="font-size:10px;background:#fee2e2;color:#dc2626;border-radius:4px;padding:1px 5px;font-weight:600;margin-left:3px;">DISABLED</span><?php endif; ?>
                    </div>
                    <div class="role-key"><?= htmlspecialchars($key) ?></div>
                </div>
                <div class="role-count"><?= $cnt ?> staff</div>
            </div>
            <div class="role-perms">
                <?php if ($isDeleted): ?>
                <span class="perm" style="background:#fee2e2;color:#dc2626;">&#128683; No Access</span>
                <?php elseif (in_array('all', $perms)): ?>
                <span class="perm all">&#10003; Full Access</span>
                <?php else: ?>
                <?php foreach ($perms as $p): ?>
                <?php $cls = 'perm' . ($p==='leave_approve'||$p==='leave_request'?' leave':($p==='compliance'?' comp':($p==='vault'?' vault':''))); ?>
                <span class="<?= $cls ?>"><?= htmlspecialchars($p) ?></span>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($permReady): ?>
            <div style="padding:8px 16px 12px;border-top:1px solid var(--g100);display:flex;gap:6px;">
            <?php if ($isCustom): ?>
                <?php if ($isDeleted): ?>
                <form method="POST" style="display:inline;">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="restore_role"/>
                    <input type="hidden" name="role_key" value="<?= htmlspecialchars($key) ?>"/>
                    <button type="submit" class="btn" style="padding:4px 10px;font-size:11px;background:#dcfce7;color:#166534;border:1px solid #86efac;">&#9654; Restore</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete \"<?= htmlspecialchars(addslashes($cfg['label'])) ?>\"? This cannot be undone and will remove all permissions for this role.')">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="delete_custom_role"/>
                    <input type="hidden" name="role_key" value="<?= htmlspecialchars($key) ?>"/>
                    <button type="submit" class="btn rd" style="padding:4px 10px;font-size:11px;">&#128465; Delete</button>
                </form>
            <?php else: ?>
                <?php if ($isDeleted): ?>
                <form method="POST" style="display:inline;">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="restore_role"/>
                    <input type="hidden" name="role_key" value="<?= htmlspecialchars($key) ?>"/>
                    <button type="submit" class="btn" style="padding:4px 10px;font-size:11px;background:#dcfce7;color:#166534;border:1px solid #86efac;">&#9654; Restore</button>
                </form>
                <?php else: ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Disable \"<?= htmlspecialchars(addslashes($cfg['label'])) ?>\"? All <?= $cnt ?> user(s) with this role will lose all access immediately.')">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="delete_role"/>
                    <input type="hidden" name="role_key" value="<?= htmlspecialchars($key) ?>"/>
                    <button type="submit" class="btn rd" style="padding:4px 10px;font-size:11px;">&#128683; Disable</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ── ROLE PERMISSIONS MATRIX ── -->
    <div class="card" id="permMatrix">
        <div class="card-hd">
            <div class="card-ttl">&#9881; Feature Access Matrix</div>
            <?php if (!$permReady): ?>
            <span style="font-size:11.5px;color:#f59e0b;">&#9888; Run sprint5_migration.sql to enable</span>
            <?php endif; ?>
        </div>

        <?php if (!$permReady): ?>
        <div class="notice" style="margin:16px;">
            &#9888; The <code>role_permissions</code> table does not exist yet.
            Run <code>database/sprint5_migration.sql</code> in phpMyAdmin, then reload this page.
        </div>
        <?php else: ?>
        <!-- Access level legend -->
        <div class="help-legend" style="align-items:center;">
            <span style="font-size:12px;color:var(--g600);font-weight:700;">Click to cycle:</span>
            <div class="hl-item"><button class="pt-btn v-none" style="cursor:default;">&#8212;</button> <span><strong>None</strong> — blocked, hidden</span></div>
            <div class="hl-item"><button class="pt-btn v-read" style="cursor:default;">R</button> <span><strong>Read</strong> — view only</span></div>
            <div class="hl-item"><button class="pt-btn v-write" style="cursor:default;">W</button> <span><strong>Write</strong> — full access</span></div>
            <span style="font-size:11px;color:var(--g400);margin-left:auto;">None &#8594; R &#8594; W &#8594; None</span>
        </div>
        <form method="POST" id="permForm">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save_permissions"/>
            <div class="perm-wrap">
            <table class="perm-table">
                <thead>
                    <tr>
                        <th class="role-col">Role</th>
                        <?php foreach ($features as $fk => $fd): ?>
                        <th title="<?= htmlspecialchars($fd['label']) ?>"><?= $fd['icon'] ?><br/><span style="font-size:9.5px;"><?= htmlspecialchars($fd['label']) ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $prevLevel = null;
                foreach ($allRoles as $roleKey => $roleCfg):
                    if (in_array($roleKey, $deletedRoles)) continue; // skip disabled roles
                    $level = $roleCfg['level'] ?? 4;
                    if ($level !== $prevLevel):
                        $prevLevel = $level;
                ?>
                <tr class="level-row">
                    <td colspan="<?= count($features) + 1 ?>"><?= htmlspecialchars($levelLabels[$level] ?? "Level $level") ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="role-name-cell">
                        <span class="role-ico-sm"><?= $roleCfg['icon'] ?></span>
                        <?= htmlspecialchars($roleCfg['label']) ?>
                        <span class="role-lv">L<?= $level ?></span>
                        <?php if (!empty($roleCfg['_custom'])): ?><span class="custom-badge">CUSTOM</span><?php endif; ?>
                    </td>
                    <?php foreach ($features as $fk => $fd):
                        $hasAll = in_array('all', $roleCfg['permissions'] ?? []);
                        if (isset($permMatrix[$roleKey][$fk])) {
                            $cur = $permMatrix[$roleKey][$fk];
                        } elseif ($hasAll) {
                            $cur = 'write';
                        } elseif (!empty($roleCfg['_custom'])) {
                            $cur = 'none'; // custom roles start with no hardcoded permissions
                        } else {
                            $codePerms = $roleCfg['permissions'] ?? [];
                            if (in_array($fk, $codePerms)) $cur = 'write';
                            elseif ($fk === 'mail') $cur = 'write';
                            else $cur = 'none';
                        }
                    ?>
                    <td style="text-align:center;padding:5px 3px;">
                        <?php $ptTxt = $cur==='none' ? '&#8212;' : ($cur==='read' ? 'R' : 'W'); ?>
                        <button type="button" class="pt-btn v-<?= $cur ?>"
                            title="<?= ucfirst($cur) ?> — click to cycle"
                            onclick="cyclePerm(this)"><?= $ptTxt ?></button>
                        <input type="hidden" name="perms[<?= $roleKey ?>][<?= $fk ?>]" value="<?= $cur ?>">
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="sticky-save">
                <button type="submit" class="btn nv">&#128190; Save Permissions</button>
                <span id="dirtyNote" style="font-size:12px;color:#f59e0b;display:none;">&#9679; Unsaved changes</span>
                <span style="font-size:12px;color:var(--g400);">Changes take effect immediately for all users.</span>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- ── DEPARTMENTS ── -->
    <div class="card">
        <div class="card-hd">
            <div class="card-ttl">&#127970; Departments</div>
            <?php if (!$deptReady): ?>
            <span style="font-size:11.5px;color:#f59e0b;">&#9888; Run sprint4a_migration.sql to enable</span>
            <?php endif; ?>
        </div>

        <?php if ($deptReady): ?>
        <div style="padding:14px 18px;border-bottom:1px solid var(--g100);">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="add_dept"/>
                <div class="form-row">
                    <div class="fg" style="margin:0;">
                        <label>Department Name *</label>
                        <input type="text" name="dept_name" placeholder="e.g. Risk Management" required/>
                    </div>
                    <div class="fg" style="margin:0;">
                        <label>Head Role</label>
                        <select name="dept_head_role">
                            <option value="">None</option>
                            <?php foreach (ROLES as $rk => $rc): if ($rc['level'] <= 2): ?>
                            <option value="<?= $rk ?>"><?= $rc['icon'] ?> <?= htmlspecialchars($rc['label']) ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="fg" style="margin:0;">
                        <label>Colour</label>
                        <input type="color" name="dept_color" value="#64748b" style="height:38px;padding:2px 4px;cursor:pointer;"/>
                    </div>
                </div>
                <button type="submit" class="btn gn" style="margin-top:10px;">+ Add Department</button>
            </form>
        </div>

        <table class="tbl">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Head Role</th>
                    <th>Current Head</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($departments)): ?>
            <tr><td colspan="4" style="padding:24px;text-align:center;color:var(--g400);">No departments yet. Add one above.</td></tr>
            <?php endif; ?>
            <?php foreach ($departments as $d): ?>
            <tr>
                <td>
                    <span class="dept-dot" style="background:<?= htmlspecialchars($d['color'] ?? '#64748b') ?>"></span>
                    <strong><?= htmlspecialchars($d['name']) ?></strong>
                </td>
                <td style="color:var(--g500);font-size:12.5px;">
                    <?php if ($d['head_role'] && isset(ROLES[$d['head_role']])): ?>
                    <?= ROLES[$d['head_role']]['icon'] ?> <?= htmlspecialchars(ROLES[$d['head_role']]['label']) ?>
                    <?php else: ?>&#8212;<?php endif; ?>
                </td>
                <td style="color:var(--g700);"><?= $d['head_name'] ? htmlspecialchars($d['head_name']) : '<span style="color:var(--g400);">Unassigned</span>' ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this department?')">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="delete_dept"/>
                        <input type="hidden" name="dept_id" value="<?= $d['id'] ?>"/>
                        <button type="submit" class="btn rd">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="notice" style="margin:16px;">
            &#9888; The departments table does not exist yet. Run <code>database/sprint4a_migration.sql</code> in phpMyAdmin to create it, then reload.
        </div>
        <?php endif; ?>
    </div>

    <div style="font-size:12px;color:var(--g400);margin-top:4px;margin-bottom:24px;">
        &#8505; Role permissions saved here are enforced at runtime. Changes apply to all active sessions immediately.
        <?php if (!$customReady): ?>
        &nbsp;&bull;&nbsp;To enable custom roles, run this SQL in phpMyAdmin:
        <code style="background:var(--g100);padding:2px 6px;border-radius:4px;display:inline-block;margin-top:4px;">CREATE TABLE custom_roles (role_key VARCHAR(50) NOT NULL PRIMARY KEY, label VARCHAR(100) NOT NULL, icon VARCHAR(20) DEFAULT '&#128100;', level TINYINT UNSIGNED DEFAULT 4, color VARCHAR(7) DEFAULT '#64748b', created_at DATETIME DEFAULT CURRENT_TIMESTAMP);</code>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ NEW ROLE MODAL ═══ -->
<div class="modal-bg" id="newRoleModal">
    <div class="modal">
        <div class="modal-hd">
            <div class="modal-ttl">&#10010; Create New Role</div>
            <span class="modal-close" onclick="document.getElementById('newRoleModal').classList.remove('open')">&#10005;</span>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="add_role"/>
            <div class="modal-bd">
                <div class="fg2">
                    <label>Role Key *</label>
                    <input type="text" name="role_key" placeholder="e.g. intern, receptionist" required
                           pattern="[a-z0-9_]+" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'')"/>
                    <div class="key-hint">Lowercase letters, numbers and underscores only. Used internally — cannot be changed later.</div>
                </div>
                <div class="fg2">
                    <label>Display Name *</label>
                    <input type="text" name="role_label" placeholder="e.g. Intern, Front Desk Receptionist" required maxlength="100"/>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:10px;">
                    <div class="fg2">
                        <label>Level</label>
                        <select name="role_level">
                            <option value="1">1 — Management</option>
                            <option value="2">2 — HOD</option>
                            <option value="3">3 — Manager</option>
                            <option value="4" selected>4 — Staff</option>
                        </select>
                    </div>
                    <div class="fg2">
                        <label>Icon (emoji)</label>
                        <input type="text" name="role_icon" placeholder="👤" maxlength="5" style="font-size:18px;text-align:center;"/>
                    </div>
                    <div class="fg2">
                        <label>Colour</label>
                        <input type="color" name="role_color" value="#64748b" style="height:38px;padding:2px 4px;cursor:pointer;width:100%;"/>
                    </div>
                </div>
                <div style="font-size:12px;color:var(--g500);background:var(--g50);border-radius:8px;padding:10px 12px;line-height:1.6;">
                    &#8505; After creating the role, go to the <strong>Feature Access Matrix</strong> below to set what this role can access. New roles start with <strong>no access</strong> to anything.
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="document.getElementById('newRoleModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn nv">Create Role</button>
            </div>
        </form>
    </div>
</div>

<script>
var dirty = false;
var _ptCycle = ['none','read','write'];
var _ptIcon  = {none:'&#8212;', read:'R', write:'W'};
var _ptTitle = {none:'None — click for Read', read:'Read — click for Write', write:'Write — click to clear'};

function cyclePerm(btn) {
    var inp  = btn.nextElementSibling; // hidden input
    var cur  = inp.value;
    var next = _ptCycle[(_ptCycle.indexOf(cur) + 1) % 3];
    inp.value        = next;
    btn.className    = 'pt-btn v-' + next;
    btn.innerHTML    = _ptIcon[next];
    btn.title        = _ptTitle[next];
    markDirty();
}

function markDirty() {
    dirty = true;
    var n = document.getElementById('dirtyNote');
    if (n) n.style.display = 'inline';
}
window.onbeforeunload = function() {
    if (dirty) return 'You have unsaved permission changes. Leave anyway?';
};
document.getElementById('permForm') && document.getElementById('permForm').addEventListener('submit', function(){ dirty = false; });
// Close modal on backdrop click
document.getElementById('newRoleModal') && document.getElementById('newRoleModal').addEventListener('click', function(e){
    if (e.target === this) this.classList.remove('open');
});
</script>
<?php Layout::end(); ?>
