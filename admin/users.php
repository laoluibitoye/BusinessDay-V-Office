<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// admin/users.php — HRI Webmail User Management
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr']);
$isAdmin = Auth::isAdmin($user);
$db   = getDB();

// ── ROLE LISTS (must be defined before POST handlers) ─────────
$disabledRoles = [];
try {
    $drStmt = $db->query("SELECT role_key FROM role_permissions WHERE feature='_disabled' AND access='write'");
    $disabledRoles = $drStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

$customRolesForDropdown = [];
try {
    foreach ($db->query("SELECT role_key, label, icon FROM custom_roles ORDER BY level, label") as $cr) {
        $customRolesForDropdown[$cr['role_key']] = ['label'=>$cr['label'],'icon'=>$cr['icon']?:'👤'];
    }
} catch (PDOException $e) {}
$allRolesForDropdown = ROLES + array_map(function($cr){ return ['label'=>$cr['label'],'icon'=>$cr['icon'],'level'=>4,'color'=>'#64748b','permissions'=>[]]; }, $customRolesForDropdown);

$success = '';
$error   = '';

// ── ADD STAFF ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name       = trim($_POST['name'] ?? '');
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $role       = $_POST['role'] ?? '';
    $lm_id      = !empty($_POST['line_manager_id']) ? (int)$_POST['line_manager_id'] : null;
    $dept       = trim($_POST['department'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $temppass   = trim($_POST['password'] ?? '');

    if (!$name || !$email || !$role || !$temppass) {
        $error = 'Name, email, role and temporary password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!array_key_exists($role, $allRolesForDropdown)) {
        $error = 'Invalid role selected.';
    } elseif (!$isAdmin && (ROLES[$role]['level'] ?? 4) < (ROLES[$user['role']]['level'] ?? 4)) {
        $error = 'You cannot assign a role with higher authority than your own.';
    } else {
        // Check duplicate
        try {
            $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = 'A staff account with this email already exists.';
            } else {
                $hash = password_hash($temppass, PASSWORD_BCRYPT);
                $colors = ['#002850','#64A014','#8b5cf6','#f97316','#3b82f6','#f59e0b','#0891b2','#dc2626'];
                $color  = $colors[array_rand($colors)];

                $db->prepare("INSERT INTO users (name, email, password_hash, role, line_manager_id, department, phone, avatar_color, is_active)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
                   ->execute([$name, $email, $hash, $role, $lm_id, $dept, $phone, $color]);

                $newId      = $db->lastInsertId();
                $scheduleId = !empty($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : null;
                $startDate  = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                if ($scheduleId) {
                    try {
                        $db->prepare("INSERT INTO staff_schedules (user_id, schedule_id, effective_from) VALUES (?,?,?)")
                           ->execute([$newId, $scheduleId, $startDate]);
                        $wsRow = $db->prepare("SELECT annual_leave_days FROM work_schedules WHERE id=?");
                        $wsRow->execute([$scheduleId]);
                        $wsr = $wsRow->fetch();
                        if ($wsr) {
                            $db->prepare("INSERT INTO leave_balance (user_id, year, entitled_days) VALUES (?,?,?) ON DUPLICATE KEY UPDATE entitled_days=?")
                               ->execute([$newId, date('Y'), $wsr['annual_leave_days'], $wsr['annual_leave_days']]);
                        }
                    } catch (PDOException $e) { /* sprint4a_migration.sql not yet run */ }
                }
                Auth::auditLog($user['id'], 'add_user', "Added user: $name ($email) as $role");

                $roleLabel = ROLES[$role]['label'] ?? ucfirst(str_replace('_',' ',$role));
                $deptLine  = $dept ? "Department: $dept\n" : '';
                $welcomeBody = "Dear $name,\n\n"
                    . "Welcome to HR Indexx Limited! Your HRI Mail account has been created.\n\n"
                    . "Your login details:\n"
                    . "  Email:    $email\n"
                    . "  Password: $temppass\n"
                    . "  Role:     $roleLabel\n"
                    . ($deptLine)
                    . "\nLogin at: " . APP_URL . "/login.php\n\n"
                    . "Please change your password after your first login by going to:\n"
                    . APP_URL . "/profile.php\n\n"
                    . "If you have any issues logging in, contact the IT Admin.\n\n"
                    . "Welcome aboard!\n"
                    . "HR Indexx Limited · 12 Macarthy Street, Onikan, Lagos Island";
                sendSystemMail($email, $name, "Welcome to HRI Mail — Your Account is Ready", nl2br(htmlspecialchars($welcomeBody)));

                $adminBody = "New staff account created:\n\nName:  $name\nEmail: $email\nRole:  $roleLabel\n"
                    . ($deptLine)
                    . "\nAccount ID: $newId\n\nManage at: " . APP_URL . "/admin/users.php\n\nHRI Mail";
                sendSystemMail($user['email'], $user['name'], "New Staff Account Created — $name", nl2br(htmlspecialchars($adminBody)));

                $success = "Staff account created for $name. Welcome email sent to $email.";
            }
        } catch (PDOException $e) {
            error_log('HRI users.php add error: ' . $e->getMessage());
            $error = 'Database error creating account. Please try again or contact IT Admin.';
        }
    }
}

// ── TOGGLE ACTIVE ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $tid = (int)($_POST['user_id'] ?? 0);
    if ($tid && $tid !== $user['id']) {
        try {
            $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$tid]);
            Auth::auditLog($user['id'], 'toggle_user', "Toggled active status for user ID $tid");
            $success = 'Account status updated.';
        } catch (PDOException $e) { $error = 'Error: ' . htmlspecialchars($e->getMessage()); }
    }
}

// ── UPDATE STAFF PROFILE (role, dept, phone, LM) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    $tid     = (int)($_POST['user_id'] ?? 0);
    $newrole = $_POST['role'] ?? '';
    $newlm   = !empty($_POST['line_manager_id']) ? (int)$_POST['line_manager_id'] : null;
    $dept    = trim($_POST['department'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    if (!$tid) {
        $error = 'Invalid user.';
    } elseif (!array_key_exists($newrole, $allRolesForDropdown)) {
        $error = 'Invalid role selected. Please choose a valid role from the list.';
    } elseif (!$isAdmin && (ROLES[$newrole]['level'] ?? 4) < (ROLES[$user['role']]['level'] ?? 4)) {
        $error = 'You cannot assign a role with higher authority than your own.';
    } else {
        try {
            $db->prepare("UPDATE users SET role=?, line_manager_id=?, department=?, phone=? WHERE id=?")
               ->execute([$newrole, $newlm, $dept, $phone, $tid]);
            Auth::auditLog($user['id'], 'update_user', "Updated profile for user $tid: role=$newrole dept=$dept");
            $success = 'Staff profile updated. Role changes take effect on the staff member\'s next page load — no re-login needed.';
        } catch (PDOException $e) { $error = 'Error: ' . htmlspecialchars($e->getMessage()); }
    }
}

// ── UPDATE HR PROFILE (DOB, NOK, bank details) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_hr_profile') {
    $tid  = (int)($_POST['user_id'] ?? 0);
    if ($tid) {
        try {
            $dob    = trim($_POST['date_of_birth'] ?? '') ?: null;
            $nokNm  = trim($_POST['next_of_kin_name'] ?? '');
            $nokPh  = trim($_POST['next_of_kin_phone'] ?? '');
            $nokRel = trim($_POST['next_of_kin_relation'] ?? '');
            $emCon  = trim($_POST['emergency_contact'] ?? '');
            $bankNm = trim($_POST['bank_name'] ?? '');
            $accNum = trim($_POST['account_number'] ?? '');
            $accNm  = trim($_POST['account_name'] ?? '');
            $db->prepare("INSERT INTO user_profiles (user_id,date_of_birth,next_of_kin_name,next_of_kin_phone,next_of_kin_relation,emergency_contact,bank_name,account_number,account_name)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE date_of_birth=VALUES(date_of_birth),next_of_kin_name=VALUES(next_of_kin_name),next_of_kin_phone=VALUES(next_of_kin_phone),next_of_kin_relation=VALUES(next_of_kin_relation),emergency_contact=VALUES(emergency_contact),bank_name=VALUES(bank_name),account_number=VALUES(account_number),account_name=VALUES(account_name),updated_at=NOW()")
               ->execute([$tid,$dob,$nokNm,$nokPh,$nokRel,$emCon,$bankNm,$accNum,$accNm]);
            Auth::auditLog($user['id'], 'update_hr_profile', "Updated HR profile for user ID $tid");
            $success = 'HR profile saved.';
        } catch (PDOException $e) { $error = 'Error: ' . htmlspecialchars($e->getMessage()); }
    }
}

// ── RESET PASSWORD ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_pass') {
    $tid      = (int)($_POST['user_id'] ?? 0);
    $newpass  = trim($_POST['new_password'] ?? '');
    if ($tid && strlen($newpass) >= 8) {
        try {
            $hash = password_hash($newpass, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $tid]);
            $db->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$tid]);
            Auth::auditLog($user['id'], 'reset_password', "Reset password for user ID $tid");
            $success = 'Password reset successfully. All active sessions ended.';
        } catch (PDOException $e) { $error = 'Error: ' . htmlspecialchars($e->getMessage()); }
    } else {
        $error = 'Password must be at least 8 characters.';
    }
}

// ── DELETE USER ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $tid = (int)($_POST['user_id'] ?? 0);
    if ($tid && $tid !== $user['id']) {
        try {
            $nm = $db->prepare("SELECT name, email FROM users WHERE id = ?");
            $nm->execute([$tid]);
            $del = $nm->fetch();
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$tid]);
            Auth::auditLog($user['id'], 'delete_user', "Deleted user: {$del['name']} ({$del['email']})");
            $success = "Staff account for {$del['name']} has been deleted.";
        } catch (PDOException $e) { $error = 'Error: ' . htmlspecialchars($e->getMessage()); }
    }
}

// ── FETCH ALL USERS ────────────────────────────────────────
try {
    $allUsers = $db->query("SELECT u.*, lm.name as lm_name,
        up.date_of_birth, up.next_of_kin_name, up.next_of_kin_phone, up.next_of_kin_relation,
        up.emergency_contact, up.bank_name, up.account_number, up.account_name
        FROM users u
        LEFT JOIN users lm ON lm.id = u.line_manager_id
        LEFT JOIN user_profiles up ON up.user_id = u.id
        ORDER BY u.role, u.name")->fetchAll();
} catch (PDOException $e) {
    // user_profiles table not yet created — fall back without HR fields
    $allUsers = $db->query("SELECT u.*, lm.name as lm_name,
        NULL as date_of_birth, NULL as next_of_kin_name, NULL as next_of_kin_phone,
        NULL as next_of_kin_relation, NULL as emergency_contact,
        NULL as bank_name, NULL as account_number, NULL as account_name
        FROM users u
        LEFT JOIN users lm ON lm.id = u.line_manager_id
        ORDER BY u.role, u.name")->fetchAll();
}

// Work schedules (safe — tables may not exist yet)
$workSchedules = [];
try { $workSchedules = $db->query("SELECT * FROM work_schedules ORDER BY id")->fetchAll(); } catch (PDOException $e) {}

// Departments — try DB table first, fall back to canonical list
$deptList = [];
try { $deptList = $db->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $e) {}
if (empty($deptList)) {
    $deptList = [
        'Accounts',
        'Business Development',
        'Client Service Operations',
        'Compliance',
        'Human Resources',
        'IT',
        'Management',
        'Outsourcing',
        'Training',
    ];
}

// Stats
$totalStaff  = count($allUsers);
$activeStaff = count(array_filter($allUsers, function($u){ return (bool)$u['is_active']; }));
$roleCounts  = array_count_values(array_column($allUsers, 'role'));

// Online now (session active in last 30 mins)
$onlineStmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE COALESCE(last_active,created_at) > DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND expires_at > NOW()");
$onlineCount = $onlineStmt->fetchColumn();

Layout::shell($user, 'users', 0, 'User Management');
?>
<style>
/* STAT CARDS */
.scg{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px;}
.sc{background:var(--w);border-radius:11px;padding:14px 16px;box-shadow:var(--sh);border-top:3px solid var(--navy);}
.sc.gn{border-top-color:var(--green);}
.sc.or{border-top-color:#f97316;}
.sc.pu{border-top-color:#8b5cf6;}
.sc-ico{font-size:18px;margin-bottom:5px;}
.sc-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g400);margin-bottom:3px;}
.sc-val{font-size:22px;font-weight:700;color:var(--g900);}
/* ROLE BREAKDOWN */
.rbk{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:22px;}
.rbk-item{display:flex;align-items:center;gap:6px;background:var(--w);border-radius:8px;padding:6px 12px;font-size:12px;box-shadow:var(--sh);}
.rbk-dot{width:8px;height:8px;border-radius:50%;}
/* CARD */
.card{background:var(--w);border-radius:13px;box-shadow:var(--sh);overflow:hidden;margin-bottom:20px;}
.card-hd{padding:14px 18px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.card-ttl{font-size:13.5px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:7px;}
/* ALERTS */
.alert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
/* PAGE HEADER */
.ph{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.pt{font-size:18px;font-weight:700;color:var(--navy);}
.ps{font-size:12.5px;color:var(--g400);margin-top:2px;}
/* FORM */
.fg{display:flex;flex-direction:column;gap:5px;}
.fg.full{grid-column:1/-1;}
label{font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;}
input,select,textarea{border:1.5px solid var(--g200);border-radius:8px;padding:9px 12px;font-size:13.5px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;width:100%;}
input:focus,select:focus{border-color:var(--green);background:var(--w);}
.hint{font-size:11.5px;color:var(--g400);margin-left:auto;}
/* BUTTONS */
.btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:all .15s;display:inline-flex;align-items:center;gap:6px;}
.btn.pr{background:var(--navy);color:#fff;}
.btn.pr:hover{background:#003a72;}
.btn.gn{background:var(--green);color:#fff;}
.btn.gn:hover{background:#4d7c10;}
.btn.ol{background:transparent;border:1.5px solid var(--g200);color:var(--g700);}
.btn.ol:hover{border-color:var(--navy);color:var(--navy);}
.btn.rd{background:#dc2626;color:#fff;}
.btn.sm{padding:5px 11px;font-size:11.5px;}
/* STAFF TABLE */
.tbl{width:100%;border-collapse:collapse;}
.tbl th{background:var(--g50);padding:10px 16px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.tbl td{padding:12px 16px;border-bottom:1px solid var(--g50);font-size:13px;color:var(--g700);vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tr:hover td{background:var(--g50);}
/* Avatar */
.uav{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;flex-shrink:0;}
.uinfo{display:flex;align-items:center;gap:10px;}
.uname{font-size:13px;font-weight:600;color:var(--g900);}
.uemail{font-size:11.5px;color:var(--g400);}
/* Pills */
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.pill.active{background:#dcfce7;color:#166534;}
.pill.inactive{background:var(--g100);color:var(--g500);}
/* Role badge */
.rbadge{padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;color:#fff;display:inline-block;}
/* Actions dropdown */
.act-wrap{position:relative;display:inline-block;}
.act-btn{padding:5px 10px;border-radius:6px;background:var(--g100);border:1px solid var(--g200);color:var(--g700);font-size:12px;cursor:pointer;font-family:'Inter',sans-serif;display:flex;align-items:center;gap:4px;}
.act-btn:hover{background:#e8f0fb;color:var(--navy);}
.act-menu{position:absolute;right:0;top:100%;margin-top:4px;background:var(--w);border:1px solid var(--g200);border-radius:9px;box-shadow:0 8px 32px rgba(0,40,80,.14);min-width:180px;z-index:100;display:none;overflow:hidden;}
.act-menu.open{display:block;}
.act-item{display:flex;align-items:center;gap:8px;padding:9px 14px;font-size:13px;color:var(--g700);cursor:pointer;transition:background .1s;border:none;background:transparent;width:100%;font-family:'Inter',sans-serif;text-align:left;}
.act-item:hover{background:var(--g50);}
.act-item.red{color:#dc2626;}
.act-item.red:hover{background:#fee2e2;}
.act-sep{height:1px;background:var(--g100);margin:3px 0;}
/* MODAL */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;display:none;align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.modal{background:var(--w);border-radius:16px;box-shadow:0 8px 32px rgba(0,40,80,.14);width:100%;max-width:480px;overflow:hidden;}
.modal-hd{padding:16px 20px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.modal-ttl{font-size:15px;font-weight:700;color:var(--navy);}
.modal-close{cursor:pointer;color:var(--g400);font-size:18px;line-height:1;}
.modal-close:hover{color:var(--g900);}
.modal-bd{padding:20px;}
.modal-ft{padding:14px 20px;border-top:1px solid var(--g100);display:flex;gap:8px;justify-content:flex-end;}
/* SEARCH */
.search-bar{display:flex;gap:10px;align-items:center;margin-bottom:14px;}
.search-inp{flex:1;border:1.5px solid var(--g200);border-radius:8px;padding:8px 12px;font-size:13px;font-family:'Inter',sans-serif;outline:none;color:var(--g900);background:var(--w);}
.search-inp:focus{border-color:var(--green);}
.filter-sel{border:1.5px solid var(--g200);border-radius:8px;padding:8px 12px;font-size:13px;font-family:'Inter',sans-serif;color:var(--g700);outline:none;cursor:pointer;background:var(--w);}
.filter-sel:focus{border-color:var(--green);}
/* Password strength */
.pwd-meter{height:4px;border-radius:99px;background:var(--g200);margin-top:6px;overflow:hidden;}
.pwd-fill{height:100%;border-radius:99px;transition:width .3s,background .3s;}
.pwd-hint{font-size:11px;color:var(--g400);margin-top:4px;}
@media(max-width:640px){
    .scg{grid-template-columns:repeat(2,1fr);}
    .search-bar{flex-wrap:wrap;}
    .filter-sel{width:100%;}
}
@media(max-width:380px){.scg{grid-template-columns:1fr;}}
/* mobile-all-pages */
@media(max-width:768px){
    .scg{grid-template-columns:repeat(2,1fr);}
}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">

    <?php if ($success): ?>
    <div class="alert ok">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="ph">
        <div>
            <div class="pt">👥 User Management</div>
            <div class="ps">Add, manage and assign roles to all HRI staff</div>
        </div>
        <?php if ($isAdmin): ?>
        <button class="btn gn" onclick="openModal('addModal')">+ Add Staff Member</button>
        <?php endif; ?>
    </div>

    <!-- STAT CARDS -->
    <div class="scg">
        <div class="sc">
            <div class="sc-ico">👥</div>
            <div class="sc-lbl">Total Staff</div>
            <div class="sc-val"><?= $totalStaff ?></div>
        </div>
        <div class="sc gn">
            <div class="sc-ico">✅</div>
            <div class="sc-lbl">Active Accounts</div>
            <div class="sc-val"><?= $activeStaff ?></div>
        </div>
        <div class="sc or">
            <div class="sc-ico">🟢</div>
            <div class="sc-lbl">Online Now</div>
            <div class="sc-val"><?= $onlineCount ?></div>
        </div>
        <div class="sc pu">
            <div class="sc-ico">🔒</div>
            <div class="sc-lbl">Inactive Accounts</div>
            <div class="sc-val"><?= $totalStaff - $activeStaff ?></div>
        </div>
    </div>

    <!-- ROLE BREAKDOWN -->
    <div class="rbk">
        <?php foreach (ROLES as $rkey => $rcfg):
            $cnt = $roleCounts[$rkey] ?? 0; ?>
        <div class="rbk-item">
            <div class="rbk-dot" style="background:<?= $rcfg['color'] ?>"></div>
            <?= $rcfg['icon'] ?> <?= $rcfg['label'] ?>
            <strong style="color:var(--navy);"><?= $cnt ?></strong>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- STAFF TABLE -->
    <div class="card">
        <div class="card-hd">
            <div class="card-ttl">👥 All Staff Accounts</div>
        </div>
        <!-- Search + Filter -->
        <div style="padding:14px 18px;border-bottom:1px solid var(--g100);">
            <div class="search-bar">
                <input class="search-inp" id="staffSearch" placeholder="Search by name or email…" oninput="filterTable()"/>
                <select class="filter-sel" id="roleFilter" onchange="filterTable()">
                    <option value="">All Roles</option>
                    <?php foreach ($allRolesForDropdown as $rkey => $rcfg): if (in_array($rkey, $disabledRoles)) continue; ?>
                    <option value="<?= $rkey ?>"><?= $rcfg['icon'] ?> <?= $rcfg['label'] ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-sel" id="statusFilter" onchange="filterTable()">
                    <option value="">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>
        <table class="tbl" id="staffTable">
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Role</th>
                    <th>Line Manager</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allUsers as $u):
                $rc = ROLES[$u['role']] ?? ['label'=>ucfirst(str_replace('_',' ',$u['role'])),'icon'=>'👤','color'=>'#64748b','level'=>4,'permissions'=>[]];
                $initials = strtoupper(substr($u['name'], 0, 1) . (strpos($u['name'],' ') !== false ? substr($u['name'], strpos($u['name'],' ')+1, 1) : ''));
            ?>
            <tr data-name="<?= htmlspecialchars(strtolower($u['name']), ENT_QUOTES) ?>" data-email="<?= htmlspecialchars(strtolower($u['email']), ENT_QUOTES) ?>" data-role="<?= htmlspecialchars($u['role']) ?>" data-active="<?= $u['is_active'] ? '1' : '0' ?>">
                <td>
                    <div class="uinfo">
                        <div class="uav" style="background:<?= htmlspecialchars($u['avatar_color']) ?>"><?= $initials ?></div>
                        <div>
                            <div class="uname"><?= htmlspecialchars($u['name']) ?></div>
                            <div class="uemail"><?= htmlspecialchars($u['email']) ?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="rbadge" style="background:<?= $rc['color'] ?>"><?= $rc['icon'] ?> <?= $rc['label'] ?></span>
                </td>
                <td><?= $u['lm_name'] ? htmlspecialchars($u['lm_name']) : '<span style="color:var(--g400);">—</span>' ?></td>
                <td><?= $u['department'] ? htmlspecialchars($u['department']) : '<span style="color:var(--g400);">—</span>' ?></td>
                <td>
                    <span class="pill <?= $u['is_active'] ? 'active' : 'inactive' ?>">
                        <?= $u['is_active'] ? '● Active' : '○ Inactive' ?>
                    </span>
                </td>
                <td style="color:var(--g400);font-size:12px;">
                    <?= $u['last_login'] ? date('d M Y H:i', strtotime($u['last_login'])) : 'Never' ?>
                </td>
                <td>
                    <?php if ($u['id'] !== $user['id']): ?>
                    <div class="act-wrap">
                        <button class="act-btn" onclick="toggleMenu(this)">Actions ▾</button>
                        <div class="act-menu">
                            <button class="act-item" onclick="openEdit(<?= htmlspecialchars(json_encode(['id'=>$u['id'],'name'=>$u['name'],'role'=>$u['role'],'line_manager_id'=>$u['line_manager_id'],'department'=>$u['department'],'phone'=>$u['phone']])) ?>)">
                                &#9999;&#65039; Edit Role / Dept
                            </button>
                            <button class="act-item" onclick="openHrProfile(<?= htmlspecialchars(json_encode(['id'=>$u['id'],'name'=>$u['name'],'date_of_birth'=>$u['date_of_birth'],'next_of_kin_name'=>$u['next_of_kin_name'],'next_of_kin_phone'=>$u['next_of_kin_phone'],'next_of_kin_relation'=>$u['next_of_kin_relation'],'emergency_contact'=>$u['emergency_contact'],'bank_name'=>$u['bank_name'],'account_number'=>$u['account_number'],'account_name'=>$u['account_name']])) ?>)">
                                &#128101; HR Profile
                            </button>
                            <?php if ($isAdmin): ?>
                            <button class="act-item" onclick="openResetPass(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name']) ?>')">
                                &#128273; Reset Password
                            </button>
                            <div class="act-sep"></div>
                            <form method="POST" style="margin:0;">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="toggle"/>
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
                                <button type="submit" class="act-item">
                                    <?= $u['is_active'] ? '&#128274; Deactivate' : '&#10003; Activate' ?>
                                </button>
                            </form>
                            <div class="act-sep"></div>
                            <button class="act-item red" onclick="confirmDelete(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name']) ?>')">
                                &#128465;&#65039; Delete Account
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--g400);">You</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="padding:12px 18px;border-top:1px solid var(--g100);font-size:12px;color:var(--g400);">
            Showing <span id="rowCount"><?= count($allUsers) ?></span> staff members
        </div>
    </div>

</div>

<!-- ═══════ ADD STAFF MODAL ═══════ -->
<div class="modal-bg" id="addModal">
    <div class="modal">
        <div class="modal-hd">
            <div class="modal-ttl">➕ Add Staff Member</div>
            <span class="modal-close" onclick="closeModal('addModal')">✕</span>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="add"/>
            <div class="modal-bd">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="fg">
                        <label>Full Name *</label>
                        <input type="text" name="name" placeholder="e.g. Nesta Eze" required/>
                    </div>
                    <div class="fg">
                        <label>Email Address *</label>
                        <input type="email" name="email" placeholder="nesta@hrindexx.com" required/>
                    </div>
                    <div class="fg">
                        <label>Role *</label>
                        <select name="role" required>
                            <option value="">Select role…</option>
                            <?php foreach ($allRolesForDropdown as $rkey => $rcfg): if (in_array($rkey, $disabledRoles)) continue; ?>
                            <option value="<?= $rkey ?>"><?= $rcfg['icon'] ?> <?= $rcfg['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Line Manager</label>
                        <select name="line_manager_id">
                            <option value="">None / MD reports to no one</option>
                            <?php foreach ($allUsers as $mu): ?>
                            <option value="<?= $mu['id'] ?>"><?= htmlspecialchars($mu['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Department</label>
                        <select name="department">
                            <option value="">Select department…</option>
                            <?php foreach ($deptList as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Phone</label>
                        <input type="text" name="phone" placeholder="e.g. 08012345678"/>
                    </div>
                    <div class="fg">
                        <label>Work Schedule</label>
                        <select name="schedule_id">
                            <option value="">No schedule (assign later)</option>
                            <?php foreach ($workSchedules as $ws): ?>
                            <option value="<?= $ws['id'] ?>"><?= htmlspecialchars($ws['name']) ?> — <?= $ws['annual_leave_days'] ?> days leave/yr</option>
                            <?php endforeach; ?>
                            <?php if (empty($workSchedules)): ?>
                            <option disabled>Run sprint4a_migration.sql to enable schedules</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Employment Start Date</label>
                        <input type="date" name="start_date" max="<?= date('Y-m-d') ?>"/>
                    </div>
                    <div class="fg" style="grid-column:1/-1;">
                        <label>Temporary Password *</label>
                        <input type="text" name="password" id="addPassInp" placeholder="Min 8 characters" required oninput="checkStrength(this,'addMeter','addHint')"/>
                        <div class="pwd-meter"><div class="pwd-fill" id="addMeter"></div></div>
                        <div class="pwd-hint" id="addHint">Staff will use this to first log in</div>
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn gn">➕ Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════ EDIT STAFF MODAL ═══════ -->
<div class="modal-bg" id="editModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-hd">
            <div class="modal-ttl" id="editTitle">&#9999;&#65039; Edit Staff</div>
            <span class="modal-close" onclick="closeModal('editModal')">&#10005;</span>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="update_user"/>
            <input type="hidden" name="user_id" id="editUserId"/>
            <div class="modal-bd">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="fg">
                        <label>Role</label>
                        <select name="role" id="editRole">
                            <?php foreach ($allRolesForDropdown as $rkey => $rcfg): if (in_array($rkey, $disabledRoles)) continue; ?>
                            <option value="<?= $rkey ?>"><?= $rcfg['icon'] ?> <?= $rcfg['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Line Manager</label>
                        <select name="line_manager_id" id="editLM">
                            <option value="">None</option>
                            <?php foreach ($allUsers as $mu): ?>
                            <option value="<?= $mu['id'] ?>"><?= htmlspecialchars($mu['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Department</label>
                        <select name="department" id="editDept">
                            <option value="">Select department&hellip;</option>
                            <?php foreach ($deptList as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Phone</label>
                        <input type="text" name="phone" id="editPhone" placeholder="e.g. 08012345678"/>
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn pr">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════ HR PROFILE MODAL ═══════ -->
<div class="modal-bg" id="hrModal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-hd">
            <div class="modal-ttl" id="hrModalTitle">&#128101; HR Profile</div>
            <span class="modal-close" onclick="closeModal('hrModal')">&#10005;</span>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="update_hr_profile"/>
            <input type="hidden" name="user_id" id="hrUserId"/>
            <div class="modal-bd" style="max-height:70vh;overflow-y:auto;">
                <p style="font-size:12px;color:var(--g400);margin:0 0 14px;background:var(--g50);padding:8px 12px;border-radius:7px;">
                    &#128274; Sensitive personal data — only HR and admin can access this.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="fg" style="grid-column:1/-1;">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" id="hrDob"/>
                    </div>
                    <div class="fg" style="grid-column:1/-1;margin-top:8px;">
                        <div style="font-size:10.5px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.07em;padding-bottom:6px;border-bottom:1px solid var(--g100);">Next of Kin</div>
                    </div>
                    <div class="fg">
                        <label>Full Name</label>
                        <input type="text" name="next_of_kin_name" id="hrNokName" placeholder="e.g. Jane Doe"/>
                    </div>
                    <div class="fg">
                        <label>Phone</label>
                        <input type="text" name="next_of_kin_phone" id="hrNokPhone" placeholder="e.g. 08022222222"/>
                    </div>
                    <div class="fg">
                        <label>Relationship</label>
                        <select name="next_of_kin_relation" id="hrNokRel">
                            <option value="">Select&hellip;</option>
                            <option value="Spouse">Spouse</option>
                            <option value="Parent">Parent</option>
                            <option value="Sibling">Sibling</option>
                            <option value="Child">Child</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Emergency Contact</label>
                        <input type="text" name="emergency_contact" id="hrEmCon" placeholder="Name: number or email"/>
                    </div>
                    <div class="fg" style="grid-column:1/-1;margin-top:8px;">
                        <div style="font-size:10.5px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.07em;padding-bottom:6px;border-bottom:1px solid var(--g100);">Bank Details (for payroll)</div>
                    </div>
                    <div class="fg">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" id="hrBankName" placeholder="e.g. First Bank"/>
                    </div>
                    <div class="fg">
                        <label>Account Number</label>
                        <input type="text" name="account_number" id="hrAccNum" placeholder="10-digit NUBAN"/>
                    </div>
                    <div class="fg" style="grid-column:1/-1;">
                        <label>Account Name</label>
                        <input type="text" name="account_name" id="hrAccName" placeholder="As on bank records"/>
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="closeModal('hrModal')">Cancel</button>
                <button type="submit" class="btn pr">&#128190; Save HR Profile</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════ RESET PASSWORD MODAL ═══════ -->
<div class="modal-bg" id="passModal">
    <div class="modal">
        <div class="modal-hd">
            <div class="modal-ttl">🔑 Reset Password</div>
            <span class="modal-close" onclick="closeModal('passModal')">✕</span>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="reset_pass"/>
            <input type="hidden" name="user_id" id="passUserId"/>
            <div class="modal-bd">
                <p style="font-size:13px;color:var(--g700);margin-bottom:14px;">
                    Resetting password for <strong id="passUserName"></strong>. All their active sessions will be ended immediately.
                </p>
                <div class="fg">
                    <label>New Password</label>
                    <input type="text" name="new_password" id="passInp" placeholder="Min 6 characters" required oninput="checkStrength(this,'passMeter','passHint')"/>
                    <div class="pwd-meter"><div class="pwd-fill" id="passMeter"></div></div>
                    <div class="pwd-hint" id="passHint">Make sure to share this securely with the staff member</div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="closeModal('passModal')">Cancel</button>
                <button type="submit" class="btn rd">🔑 Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════ DELETE CONFIRM MODAL ═══════ -->
<div class="modal-bg" id="delModal">
    <div class="modal">
        <div class="modal-hd">
            <div class="modal-ttl" style="color:#dc2626;">🗑️ Delete Account</div>
            <span class="modal-close" onclick="closeModal('delModal')">✕</span>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="delete"/>
            <input type="hidden" name="user_id" id="delUserId"/>
            <div class="modal-bd">
                <p style="font-size:13.5px;color:var(--g700);line-height:1.6;">
                    You are about to permanently delete the account for <strong id="delUserName" style="color:#dc2626;"></strong>.<br/><br/>
                    This will remove all their tasks, sessions, and app data. Their emails remain on the mail server.<br/><br/>
                    <strong>This cannot be undone.</strong>
                </p>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="closeModal('delModal')">Cancel</button>
                <button type="submit" class="btn rd">Yes, Delete Account</button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal controls
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bg').forEach(m => m.addEventListener('click', e => { if(e.target === m) m.classList.remove('open'); }));

// Action menu toggle
function toggleMenu(btn){
    const menu = btn.nextElementSibling;
    document.querySelectorAll('.act-menu.open').forEach(m => { if(m !== menu) m.classList.remove('open'); });
    menu.classList.toggle('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.act-wrap')) document.querySelectorAll('.act-menu').forEach(m => m.classList.remove('open'));
});

// Open edit modal
function openEdit(u){
    document.getElementById('editUserId').value = u.id;
    document.getElementById('editTitle').textContent = '✏️ Edit — ' + u.name;
    document.getElementById('editRole').value = u.role;
    document.getElementById('editLM').value = u.line_manager_id || '';
    document.getElementById('editDept').value = u.department || '';
    document.getElementById('editPhone').value = u.phone || '';
    openModal('editModal');
}

// Open HR profile modal
function openHrProfile(u){
    document.getElementById('hrUserId').value = u.id;
    document.getElementById('hrModalTitle').textContent = '👥 HR Profile — ' + u.name;
    document.getElementById('hrDob').value = u.date_of_birth || '';
    document.getElementById('hrNokName').value = u.next_of_kin_name || '';
    document.getElementById('hrNokPhone').value = u.next_of_kin_phone || '';
    document.getElementById('hrNokRel').value = u.next_of_kin_relation || '';
    document.getElementById('hrEmCon').value = u.emergency_contact || '';
    document.getElementById('hrBankName').value = u.bank_name || '';
    document.getElementById('hrAccNum').value = u.account_number || '';
    document.getElementById('hrAccName').value = u.account_name || '';
    openModal('hrModal');
}

// Open reset password modal
function openResetPass(id, name){
    document.getElementById('passUserId').value = id;
    document.getElementById('passUserName').textContent = name;
    document.getElementById('passInp').value = '';
    openModal('passModal');
}

// Open delete modal
function confirmDelete(id, name){
    document.getElementById('delUserId').value = id;
    document.getElementById('delUserName').textContent = name;
    openModal('delModal');
}

// Password strength meter
function checkStrength(inp, meterId, hintId){
    const v = inp.value;
    const m = document.getElementById(meterId);
    const h = document.getElementById(hintId);
    let score = 0;
    if (v.length >= 8) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const colors = ['#dc2626','#f59e0b','#3b82f6','#64A014'];
    const labels = ['Too weak','Fair','Good','Strong ✓'];
    const widths = ['25%','50%','75%','100%'];
    if (v.length === 0){ m.style.width='0'; h.textContent='Staff will use this to first log in'; return; }
    m.style.width = widths[score-1] || '10%';
    m.style.background = colors[score-1] || '#dc2626';
    h.textContent = labels[score-1] || 'Too weak';
}

// Table search + filter
function filterTable(){
    const q    = (document.getElementById('staffSearch').value || '').toLowerCase();
    const role = document.getElementById('roleFilter').value;
    const stat = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('#staffTable tbody tr');
    let visible = 0;
    rows.forEach(function(r){
        var name  = r.dataset.name  || '';
        var email = r.dataset.email || '';
        var nameMatch = name.includes(q) || email.includes(q);
        var roleMatch = !role || r.dataset.role === role;
        var statMatch = stat === '' || (r.dataset.active || '0') === stat;
        var show = nameMatch && roleMatch && statMatch;
        r.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var rc = document.getElementById('rowCount');
    if (rc) rc.textContent = visible;
}
</script>
<?php Layout::end(); ?>
