<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();
$success = ''; $error = '';

$profileReady = false;
try { $db->query("SELECT 1 FROM user_profiles LIMIT 1"); $profileReady = true; } catch(PDOException $e){}

// ── CHANGE NAME ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_name'])) {
    $name = trim($_POST['display_name'] ?? '');
    if (strlen($name) < 2) { $error = 'Name must be at least 2 characters.'; }
    else {
        $db->prepare("UPDATE users SET name=? WHERE id=?")->execute([$name, $user['id']]);
        $_SESSION['user_name'] = $name;
        $user['name'] = $name;
        Auth::auditLog($user['id'], 'profile_name_changed', 'User changed their display name');
        $success = 'Display name updated successfully.';
    }
}

// ── UPDATE BASIC PROFILE ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $phone = trim($_POST['phone'] ?? '');
    $dept  = trim($_POST['department'] ?? '');
    $db->prepare("UPDATE users SET phone=?, department=? WHERE id=?")->execute([$phone, $dept, $user['id']]);
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$user['id']]);
    $user = array_merge($user, $stmt->fetch());
    Auth::auditLog($user['id'], 'profile_updated', 'User updated their profile');
    $success = 'Profile updated.';
}

// ── UPDATE HR PROFILE (extended fields) ──────────────────────
if ($profileReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_hr_profile'])) {
    $fields = ['date_of_birth','bio','address','national_id','employment_start','qualifications',
               'nok_name','nok_phone','nok_relationship','nok_email','nok_address',
               'emg_name','emg_phone','emg_relationship','emg_email','bank_name','bank_account'];
    $vals = [];
    foreach ($fields as $f) $vals[$f] = trim($_POST[$f] ?? '') ?: null;

    $db->prepare("INSERT INTO user_profiles (user_id, " . implode(', ', $fields) . ")
                  VALUES (?, " . implode(', ', array_fill(0, count($fields), '?')) . ")
                  ON DUPLICATE KEY UPDATE " . implode(', ', array_map(function($f){ return "$f=VALUES($f)"; }, $fields)))
       ->execute(array_merge([$user['id']], array_values($vals)));
    Auth::auditLog($user['id'], 'hr_profile_updated', 'User updated HR profile details');
    $success = 'HR profile saved.';
}

// ── CHANGE PASSWORD ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!$current || !$new || !$confirm) { $error = 'All password fields are required.'; }
    elseif (!password_verify($current, $user['password_hash'])) { $error = 'Current password is incorrect.'; }
    elseif (strlen($new) < 8) { $error = 'New password must be at least 8 characters.'; }
    elseif ($new !== $confirm) { $error = 'New passwords do not match.'; }
    elseif (password_verify($new, $user['password_hash'])) { $error = 'New password must be different from your current password.'; }
    else {
        $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
        Auth::setMailPass($new);
        // Invalidate all other sessions so hijacked sessions can't persist after a password change
        $db->prepare("DELETE FROM sessions WHERE user_id=? AND token!=?")->execute([$user['id'], $_SESSION['token'] ?? '']);
        Auth::auditLog($user['id'], 'password_changed', 'User changed their password — other sessions terminated');
        $success = 'Password changed. All other devices have been signed out. Your new password is now active for HRI Mail and your cPanel email.';
    }
}

// Load current data
$stmt = $db->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$user['id']]); $fresh = $stmt->fetch();
if ($fresh) $user = array_merge($user, $fresh);

$hrProfile = [];
if ($profileReady) {
    $hp = $db->prepare("SELECT * FROM user_profiles WHERE user_id=?"); $hp->execute([$user['id']]); $hrProfile = $hp->fetch() ?: [];
}

$roleConfig = ROLES[$user['role']];
$ini = strtoupper(substr($user['name'],0,1).(strpos($user['name'],' ')!==false?substr($user['name'],strpos($user['name'],' ')+1,1):''));

Layout::shell($user, 'profile', 0, 'My Profile');
?>
<style>
.prof-hd{background:linear-gradient(135deg,var(--navy) 0%,#003a72 100%);border-radius:14px;padding:22px;margin-bottom:18px;display:flex;align-items:center;gap:16px;box-shadow:0 8px 32px rgba(0,40,80,.14);}
.pav{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:22px;flex-shrink:0;border:3px solid rgba(255,255,255,.25);}
.pname{font-size:19px;font-weight:700;color:#fff;margin-bottom:3px;}
.pemail{font-size:13px;color:rgba(255,255,255,.65);margin-top:2px;}
.prole{font-size:12px;font-weight:600;margin-top:4px;color:rgba(255,255,255,.8);}
.card{background:#fff;border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
.chd{padding:13px 18px;border-bottom:1px solid #f1f5f9;font-size:13.5px;font-weight:700;color:var(--navy);}
.cbd{padding:18px;}
.alert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:14px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:#dc2626;}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fg{margin-bottom:13px;}
.fg.full{grid-column:1/-1;}
label{display:block;font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
input[type=text],input[type=date],input[type=email],input[type=password],input[type=tel],select,textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:13.5px;font-family:'Inter',sans-serif;color:#0f172a;outline:none;background:#f8fafc;transition:border-color .15s;}
input[type=text]:focus,input[type=date]:focus,input[type=email]:focus,input[type=password]:focus,input[type=tel]:focus,select:focus,textarea:focus{border-color:var(--green);background:#fff;}
input:disabled{background:#f1f5f9;color:#94a3b8;cursor:not-allowed;}
textarea{resize:vertical;min-height:80px;}
.btn{padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.btn.gn{background:var(--green);color:#fff;}.btn.gn:hover{background:#4d7c10;}
.btn.rd{background:#dc2626;color:#fff;}
.btn.ol{background:transparent;border:1.5px solid #e2e8f0;color:#334155;}
.info-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f8fafc;font-size:13px;}
.info-row:last-child{border-bottom:none;}
.info-lbl{color:#94a3b8;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
.info-val{color:#334155;font-weight:500;}
.sec-hd{font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin:16px 0 8px;border-bottom:1px solid #f1f5f9;padding-bottom:4px;}
.pwd-meter{height:4px;border-radius:99px;background:#e2e8f0;margin-top:5px;overflow:hidden;}
.pwd-fill{height:100%;border-radius:99px;transition:width .3s,background .3s;}
.not-ready{background:#f8fafc;border:1.5px dashed #e2e8f0;border-radius:10px;padding:16px;font-size:13px;color:#94a3b8;text-align:center;}
@media(max-width:640px){
    .fgrid{grid-template-columns:1fr;}
}
/* mobile-all-pages */
@media(max-width:768px){
    .fgrid{grid-template-columns:1fr;}
}
</style>
<div class="hri-page" style="max-width:1600px;">
    <?php if ($success): ?><div class="alert ok">&#10003; <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert er">&#9888; <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="prof-hd">
        <div class="pav" style="background:<?= htmlspecialchars($user['avatar_color'] ?? '#002850') ?>"><?= $ini ?></div>
        <div>
            <div class="pname"><?= htmlspecialchars($user['name']) ?></div>
            <div class="pemail">&#128231; <?= htmlspecialchars($user['email']) ?></div>
            <div class="prole"><?= $roleConfig['icon'] ?> <?= $roleConfig['label'] ?> &bull; HR Indexx Limited</div>
        </div>
    </div>

    <!-- Account Info -->
    <div class="card">
        <div class="chd">&#8505;&#65039; Account Information</div>
        <div class="cbd">
            <div class="info-row"><div class="info-lbl">Full Name</div><div class="info-val"><?= htmlspecialchars($user['name']) ?></div></div>
            <div class="info-row"><div class="info-lbl">Email</div><div class="info-val"><?= htmlspecialchars($user['email']) ?></div></div>
            <div class="info-row"><div class="info-lbl">Role</div><div class="info-val"><?= $roleConfig['icon'] ?> <?= $roleConfig['label'] ?></div></div>
            <div class="info-row"><div class="info-lbl">Department</div><div class="info-val"><?= htmlspecialchars($user['department'] ?? '—') ?></div></div>
            <div class="info-row"><div class="info-lbl">Phone</div><div class="info-val"><?= htmlspecialchars($user['phone'] ?? '—') ?></div></div>
            <div class="info-row"><div class="info-lbl">Last Login</div><div class="info-val"><?= $user['last_login'] ? date('d M Y h:i A', strtotime($user['last_login'])) : 'First login' ?></div></div>
        </div>
    </div>

    <!-- Change Display Name -->
    <div class="card">
        <div class="chd">&#128394; Change Display Name</div>
        <div class="cbd">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <div class="fg">
                    <label>Full Name / Display Name</label>
                    <input type="text" name="display_name" value="<?= htmlspecialchars($user['name']) ?>" placeholder="Enter your full name" required maxlength="100"/>
                </div>
                <button type="submit" name="change_name" class="btn gn">&#10003; Update Name</button>
            </form>
        </div>
    </div>

    <!-- Basic Profile -->
    <div class="card">
        <div class="chd">&#128222; Contact &amp; Department</div>
        <div class="cbd">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <div class="fgrid">
                    <div class="fg"><label>Department</label><input type="text" name="department" value="<?= htmlspecialchars($user['department'] ?? '') ?>" placeholder="e.g. IT, Compliance"/></div>
                    <div class="fg"><label>Phone Number</label><input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="e.g. 08012345678"/></div>
                </div>
                <button type="submit" name="update_profile" class="btn gn">&#128190; Save</button>
            </form>
        </div>
    </div>

    <!-- Extended HR Profile -->
    <div class="card">
        <div class="chd">&#128101; HR Profile — Personal Details</div>
        <div class="cbd">
            <?php if (!$profileReady): ?>
            <div class="not-ready">&#8505; Extended profile requires <strong>database/sprint5_migration.sql</strong> to be run in phpMyAdmin first.</div>
            <?php else: ?>
            <form method="POST">
                <?= Auth::csrfField() ?>
                <div class="sec-hd">Personal Information</div>
                <div class="fgrid">
                    <div class="fg"><label>Date of Birth</label><input type="date" name="date_of_birth" value="<?= htmlspecialchars($hrProfile['date_of_birth']??'') ?>"/></div>
                    <div class="fg"><label>National ID / NIN</label><input type="text" name="national_id" value="<?= htmlspecialchars($hrProfile['national_id']??'') ?>" placeholder="e.g. 12345678901"/></div>
                    <div class="fg"><label>Employment Start Date</label><input type="date" name="employment_start" value="<?= htmlspecialchars($hrProfile['employment_start']??'') ?>"/></div>
                    <div class="fg full"><label>Home Address</label><input type="text" name="address" value="<?= htmlspecialchars($hrProfile['address']??'') ?>" placeholder="Full residential address"/></div>
                    <div class="fg full"><label>Bio / About Me</label><textarea name="bio" placeholder="Brief professional bio..."><?= htmlspecialchars($hrProfile['bio']??'') ?></textarea></div>
                    <div class="fg full"><label>Qualifications / Certifications</label><textarea name="qualifications" placeholder="e.g. B.Sc. Computer Science — UNILAG 2015&#10;CISSP Certified — 2020&#10;PMP — 2022"><?= htmlspecialchars($hrProfile['qualifications']??'') ?></textarea></div>
                </div>

                <div class="sec-hd">Next of Kin</div>
                <div class="fgrid">
                    <div class="fg"><label>Full Name</label><input type="text" name="nok_name" value="<?= htmlspecialchars($hrProfile['nok_name']??'') ?>" placeholder="Next of kin full name"/></div>
                    <div class="fg"><label>Relationship</label><input type="text" name="nok_relationship" value="<?= htmlspecialchars($hrProfile['nok_relationship']??'') ?>" placeholder="e.g. Spouse, Parent, Sibling"/></div>
                    <div class="fg"><label>Phone Number</label><input type="tel" name="nok_phone" value="<?= htmlspecialchars($hrProfile['nok_phone']??'') ?>" placeholder="Phone number"/></div>
                    <div class="fg"><label>Email Address</label><input type="email" name="nok_email" value="<?= htmlspecialchars($hrProfile['nok_email']??'') ?>" placeholder="Email (optional)"/></div>
                    <div class="fg full"><label>Address</label><input type="text" name="nok_address" value="<?= htmlspecialchars($hrProfile['nok_address']??'') ?>" placeholder="Next of kin address (optional)"/></div>
                </div>

                <div class="sec-hd">Emergency Contact</div>
                <div class="fgrid">
                    <div class="fg"><label>Full Name</label><input type="text" name="emg_name" value="<?= htmlspecialchars($hrProfile['emg_name']??'') ?>" placeholder="Emergency contact name"/></div>
                    <div class="fg"><label>Relationship</label><input type="text" name="emg_relationship" value="<?= htmlspecialchars($hrProfile['emg_relationship']??'') ?>" placeholder="e.g. Spouse, Friend"/></div>
                    <div class="fg"><label>Phone Number</label><input type="tel" name="emg_phone" value="<?= htmlspecialchars($hrProfile['emg_phone']??'') ?>" placeholder="Primary phone"/></div>
                    <div class="fg"><label>Email Address</label><input type="email" name="emg_email" value="<?= htmlspecialchars($hrProfile['emg_email']??'') ?>" placeholder="Email (optional)"/></div>
                </div>

                <div class="sec-hd">Banking Details <span style="font-size:10px;font-weight:400;color:#94a3b8;">(for payroll — visible to HR only)</span></div>
                <div class="fgrid">
                    <div class="fg"><label>Bank Name</label><input type="text" name="bank_name" value="<?= htmlspecialchars($hrProfile['bank_name']??'') ?>" placeholder="e.g. Zenith Bank"/></div>
                    <div class="fg"><label>Account Number</label><input type="text" name="bank_account" value="<?= htmlspecialchars($hrProfile['bank_account']??'') ?>" placeholder="10-digit account number" maxlength="20"/></div>
                </div>

                <button type="submit" name="update_hr_profile" class="btn gn">&#128190; Save HR Profile</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Change Password -->
    <div class="card">
        <div class="chd">&#128273; Change Password</div>
        <div class="cbd">
            <div class="alert" style="background:#f0f7e6;border:1px solid #c6e89a;color:#4d7c10;margin-bottom:14px;">
                &#9888; Your HRI Mail password and cPanel email password are the same. Changing it here updates both simultaneously.
            </div>
            <form method="POST">
                <?= Auth::csrfField() ?>
                <div class="fgrid">
                    <div class="fg full"><label>Current Password</label><input type="password" name="current_password" placeholder="Your current password" required/></div>
                    <div class="fg">
                        <label>New Password</label>
                        <input type="password" name="new_password" id="newPass" placeholder="Min 8 characters" required oninput="pwdStrength(this)"/>
                        <div class="pwd-meter"><div class="pwd-fill" id="pwdFill"></div></div>
                    </div>
                    <div class="fg"><label>Confirm New Password</label><input type="password" name="confirm_password" placeholder="Re-enter new password" required/></div>
                </div>
                <button type="submit" name="change_password" class="btn gn">&#128273; Change Password</button>
            </form>
        </div>
    </div>

    <!-- Session Management -->
    <div class="card" style="border:1.5px solid #fee2e2;">
        <div class="chd" style="color:#dc2626;">&#9888; Session Management</div>
        <div class="cbd">
            <p style="font-size:13px;color:#334155;margin-bottom:14px;">End all other active sessions on other devices. You will remain logged in on this device.</p>
            <form method="POST" action="api/auth/kill-all-sessions.php">
                <?= Auth::csrfField() ?>
                <button type="submit" class="btn rd" onclick="return confirm('End all other sessions?')">&#128682; End All Other Sessions</button>
            </form>
        </div>
    </div>
</div>
<script>
function pwdStrength(inp) {
    var v=inp.value,score=0;
    if(v.length>=8) score++; if(/[A-Z]/.test(v)) score++; if(/[0-9]/.test(v)) score++; if(/[^A-Za-z0-9]/.test(v)) score++;
    var colors=['#dc2626','#f59e0b','#3b82f6','#64A014'],widths=['25%','50%','75%','100%'];
    var f=document.getElementById('pwdFill');
    f.style.width=v.length?(widths[score-1]||'10%'):'0';
    f.style.background=v.length?(colors[score-1]||'#dc2626'):'transparent';
}
</script>
<?php Layout::end(); ?>
