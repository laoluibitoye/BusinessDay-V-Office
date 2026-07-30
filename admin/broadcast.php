<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// admin/broadcast.php — Send email to all staff or selected groups
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_outsourcing','head_training','head_cso']);
$db   = getDB();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_broadcast'])) {
    $subject    = trim($_POST['subject'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $targetRole = $_POST['target_role'] ?? 'all';

    if (!$subject || !$body) { $error = 'Subject and body required.'; }
    else {
        // Strip CR/LF to prevent email header injection
        $subject = str_replace(["\r", "\n"], '', $subject);

        $where = $targetRole === 'all' ? "is_active=1" : "is_active=1 AND role=?";
        $stmt  = $db->prepare("SELECT name, email FROM users WHERE $where");
        $stmt->execute($targetRole === 'all' ? [] : [$targetRole]);
        $staff = $stmt->fetchAll();

        $sent = 0;
        foreach ($staff as $s) {
            $personalised = "Dear {$s['name']},\n\n$body\n\nBest regards,\n{$user['name']}\nHR Indexx Limited";
            if (sendSystemMail($s['email'], $s['name'], $subject, $personalised)) $sent++;
        }
        Auth::auditLog($user['id'], 'broadcast_sent', "Broadcast to $targetRole: $subject ($sent sent)");
        $success = "Broadcast sent to $sent staff member(s).";
    }
}

$allStaff = $db->query("SELECT id, name, email, role FROM users WHERE is_active=1 ORDER BY name")->fetchAll();

Layout::shell($user, 'broadcast', 0, 'Broadcast Mail');
?>
<style>
.card{background:var(--w);border-radius:14px;box-shadow:0 8px 32px rgba(0,40,80,.14);overflow:hidden;max-width:720px;}
.chd{background:var(--navy);padding:14px 20px;color:#fff;font-size:14px;font-weight:700;}
.cbd{padding:20px;}
.alert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:14px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.fg{margin-bottom:14px;}
label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
input,select,textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:9px 12px;font-size:13.5px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;}
input:focus,select:focus,textarea:focus{border-color:var(--green);background:var(--w);}
textarea{resize:vertical;height:180px;}
.recipients-preview{background:var(--g50);border-radius:8px;padding:11px 13px;font-size:12.5px;color:var(--g700);margin-top:7px;border:1px solid var(--g200);}
.btn{padding:10px 20px;border-radius:8px;font-size:13.5px;font-weight:700;cursor:pointer;border:none;font-family:'Inter',sans-serif;background:var(--green);color:#fff;display:inline-flex;align-items:center;gap:7px;transition:background .15s;}
.btn:hover{background:#4d7c10;}
/* mobile-all-pages */
@media(max-width:768px){
    [class*='-grid']{grid-template-columns:1fr;}
}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">
    <?php if ($success): ?><div class="alert ok">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="card">
        <div class="chd">📣 Broadcast Message to Staff</div>
        <div class="cbd">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <div class="fg">
                    <label>Send To</label>
                    <select name="target_role" onchange="updatePreview(this.value)">
                        <option value="all">All Staff (<?= count($allStaff) ?> members)</option>
                        <?php foreach (ROLES as $k=>$r): ?>
                        <option value="<?= $k ?>"><?= $r['icon'] ?> <?= $r['label'] ?> only</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="recipients-preview" id="recipPreview">
                        Sending to: <strong>All <?= count($allStaff) ?> staff members</strong>
                    </div>
                </div>
                <div class="fg">
                    <label>Subject *</label>
                    <input type="text" name="subject" placeholder="Email subject line…" required/>
                </div>
                <div class="fg">
                    <label>Message Body *</label>
                    <textarea name="body" placeholder="Write your message here…&#10;&#10;Note: Each email will be personalised with the staff member's name automatically." required></textarea>
                </div>
                <button type="submit" name="send_broadcast" class="btn"
                        onclick="return confirm('Send this broadcast to the selected staff?')">
                    📣 Send Broadcast
                </button>
            </form>
        </div>
    </div>
</div>
<script>
const staffByRole = <?= json_encode(array_reduce($allStaff, function($carry, $s) { $carry[$s['role']][] = $s['name']; return $carry; }, [])) ?>;
const totalStaff  = <?= count($allStaff) ?>;

function updatePreview(role) {
    const el = document.getElementById('recipPreview');
    if (role === 'all') {
        el.innerHTML = `Sending to: <strong>All ${totalStaff} staff members</strong>`;
    } else {
        const names = staffByRole[role] || [];
        el.innerHTML = `Sending to: <strong>${names.length} staff in this role</strong><br/><span style="color:var(--g400);font-size:12px;">${names.join(', ')}</span>`;
    }
}
</script>
<?php Layout::end(); ?>
