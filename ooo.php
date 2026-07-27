<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// Out of Office settings page
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$success = '';
$error   = '';

// Load current OOO settings
$oooStmt = $db->prepare("SELECT * FROM ooo_settings WHERE user_id = ?");
$oooStmt->execute([$user['id']]);
$ooo = $oooStmt->fetch();

// Defaults
$isEnabled = $ooo ? (bool)$ooo['is_enabled'] : false;
$startDate = $ooo['start_date'] ?? '';
$endDate   = $ooo['end_date']   ?? '';
$subject   = $ooo['subject']    ?? 'Out of Office: ' . $user['name'];
$message   = $ooo['message']    ?? "Thank you for your email. I am currently out of the office and will respond upon my return.\n\nFor urgent matters, please contact HR Indexx Limited directly at +234... or visit our office at 12 Macarthy Street, Onikan, Lagos Island.\n\nKind regards,\n" . $user['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $newEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $newStart   = $_POST['start_date'] ?: null;
        $newEnd     = $_POST['end_date']   ?: null;
        $newSubject = trim($_POST['subject'] ?? 'Out of Office');
        $newMessage = trim($_POST['message'] ?? '');

        if (!$newSubject) { $error = 'Subject cannot be empty.'; }
        elseif (!$newMessage) { $error = 'Message cannot be empty.'; }
        elseif ($newStart && $newEnd && $newEnd < $newStart) { $error = 'End date must be after start date.'; }
        else {
            $db->prepare("INSERT INTO ooo_settings (user_id, is_enabled, start_date, end_date, subject, message)
                          VALUES (?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE is_enabled=?, start_date=?, end_date=?, subject=?, message=?, updated_at=NOW()")
               ->execute([$user['id'], $newEnabled, $newStart, $newEnd, $newSubject, $newMessage,
                           $newEnabled, $newStart, $newEnd, $newSubject, $newMessage]);

            // Clear sent-reply log when OOO is updated (so new period gets fresh replies)
            if ($newEnabled && $newStart) {
                $db->prepare("DELETE FROM ooo_replies_sent WHERE ooo_user_id = ? AND replied_date < ?")->execute([$user['id'], $newStart]);
            }

            Auth::auditLog($user['id'], 'ooo_updated', 'OOO ' . ($newEnabled ? 'enabled' : 'disabled') . ($newStart ? " ($newStart to $newEnd)" : ''));
            $success = $newEnabled ? 'Out of Office is now active.' : 'Out of Office has been turned off.';

            // Reload
            $oooStmt->execute([$user['id']]);
            $ooo = $oooStmt->fetch();
            $isEnabled = (bool)$ooo['is_enabled'];
            $startDate = $ooo['start_date'] ?? '';
            $endDate   = $ooo['end_date']   ?? '';
            $subject   = $ooo['subject'];
            $message   = $ooo['message'];
        }

    } elseif ($action === 'toggle') {
        $newVal = $isEnabled ? 0 : 1;
        if ($ooo) {
            $db->prepare("UPDATE ooo_settings SET is_enabled=?, updated_at=NOW() WHERE user_id=?")->execute([$newVal, $user['id']]);
        } else {
            $db->prepare("INSERT INTO ooo_settings (user_id, is_enabled, subject, message) VALUES (?,?,?,?)")
               ->execute([$user['id'], $newVal, $subject, $message]);
        }
        Auth::auditLog($user['id'], 'ooo_toggled', 'OOO ' . ($newVal ? 'enabled' : 'disabled'));
        header('Location: ooo.php?saved=1');
        exit;
    }
}

// Is OOO auto-active today based on dates?
$autoActive = false;
if ($startDate && $endDate) {
    $today = date('Y-m-d');
    $autoActive = ($today >= $startDate && $today <= $endDate);
}

if (isset($_GET['saved'])) {
    $success = $isEnabled ? 'Out of Office is now active.' : 'Out of Office has been turned off.';
}
Layout::shell($user, 'ooo', 0, 'Out of Office');
?>
<style>
.ooo-wrap{max-width:680px;}
.pgt{font-size:17px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.pgs{font-size:12.5px;color:var(--g400);margin-bottom:20px;}
.card{background:var(--w);border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
.chd{padding:14px 20px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.chd-title{font-size:13.5px;font-weight:700;color:var(--navy);}
.cbd{padding:20px;}
.fg{margin-bottom:16px;}
label{display:block;font-size:11px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
input[type=text],input[type=date],select,textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:9px 12px;font-size:13.5px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;}
input[type=text]:focus,input[type=date]:focus,select:focus,textarea:focus{border-color:var(--green);background:var(--w);}
textarea{resize:vertical;min-height:120px;}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.btn{padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:background .15s;display:inline-flex;align-items:center;gap:6px;}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{background:#4d7c10;}
.btn-outline{background:transparent;border:1.5px solid var(--g200);color:var(--g700);}
.btn-outline:hover{border-color:var(--navy);color:var(--navy);}
.btn-red{background:#dc2626;color:#fff;}
.btn-red:hover{background:#b91c1c;}
.alert{padding:12px 16px;border-radius:9px;font-size:13px;margin-bottom:16px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.status-banner{padding:14px 18px;border-radius:10px;display:flex;align-items:center;gap:12px;margin-bottom:16px;}
.status-banner.on{background:#fef9c3;border:1.5px solid #fde047;}
.status-banner.off{background:var(--g50);border:1.5px solid var(--g200);}
.status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.status-dot.on{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2);}
.status-dot.off{background:var(--g400);}
.status-text{font-size:13px;font-weight:600;}
.status-sub{font-size:12px;color:var(--g500);margin-top:2px;}
.tog-form{margin-left:auto;}
.check-wrap{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--g50);border-radius:8px;border:1.5px solid var(--g200);cursor:pointer;transition:all .15s;}
.check-wrap:hover{border-color:var(--green);}
.check-wrap input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:var(--green);}
.check-label{font-size:13px;font-weight:600;color:var(--g700);cursor:pointer;}
@media(max-width:640px){
    .row2{grid-template-columns:1fr;}
}
</style>
<div style="padding:20px 24px;overflow-y:auto;height:100%;">
<div class="ooo-wrap">
    <div class="pgt">✈️ Out of Office Settings</div>
    <div class="pgs">Set an automatic reply when you receive emails while away.</div>

    <?php if ($success): ?><div class="alert ok">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Status banner -->
    <div class="status-banner <?= $isEnabled ? 'on' : 'off' ?>">
        <div class="status-dot <?= $isEnabled ? 'on' : 'off' ?>"></div>
        <div>
            <div class="status-text"><?= $isEnabled ? 'Out of Office is ON' : 'Out of Office is OFF' ?></div>
            <?php if ($isEnabled && $startDate && $endDate): ?>
            <div class="status-sub">Active: <?= date('d M Y', strtotime($startDate)) ?> – <?= date('d M Y', strtotime($endDate)) ?></div>
            <?php elseif ($isEnabled): ?>
            <div class="status-sub">Auto-replies are being sent</div>
            <?php else: ?>
            <div class="status-sub">Auto-replies are not active</div>
            <?php endif; ?>
        </div>
        <form method="POST" class="tog-form">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="toggle"/>
            <button type="submit" class="btn <?= $isEnabled ? 'btn-red' : 'btn-green' ?>">
                <?= $isEnabled ? '⏹ Turn Off' : '▶ Turn On' ?>
            </button>
        </form>
    </div>

    <!-- Settings form -->
    <div class="card">
        <div class="chd">
            <span class="chd-title">✉️ Auto-Reply Configuration</span>
        </div>
        <div class="cbd">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="save"/>

                <div class="fg">
                    <label class="check-wrap">
                        <input type="checkbox" name="is_enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>/>
                        <span class="check-label">Enable Out of Office auto-reply</span>
                    </label>
                </div>

                <div class="fg row2">
                    <div>
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"/>
                    </div>
                    <div>
                        <label>End Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"/>
                    </div>
                </div>

                <div class="fg">
                    <label>Reply Subject</label>
                    <input type="text" name="subject" value="<?= htmlspecialchars($subject) ?>" required/>
                </div>

                <div class="fg">
                    <label>Auto-Reply Message</label>
                    <textarea name="message" required><?= htmlspecialchars($message) ?></textarea>
                </div>

                <div style="display:flex;gap:10px;">
                    <button type="submit" class="btn btn-green">💾 Save Settings</button>
                    <a href="/" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="chd"><span class="chd-title">ℹ️ How It Works</span></div>
        <div class="cbd" style="color:var(--g500);font-size:13px;line-height:1.7;">
            <p>When Out of Office is enabled, HRI Mail will automatically send your reply message to anyone who emails you — once per sender per day to avoid repetition.</p>
            <br/>
            <p><strong>Note:</strong> Auto-replies are queued when you receive new mail while logged in to HRI Mail. For best results, enable OOO before you go on leave and ensure the dates are set correctly.</p>
        </div>
    </div>
</div>
</div>
<?php Layout::end(); ?>
