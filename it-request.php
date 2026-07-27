<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// it-request.php — IT Support Request Form
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issueType = trim($_POST['issue_type'] ?? '');
    $priority  = $_POST['priority'] ?? 'normal';
    $desc      = trim($_POST['description'] ?? '');

    if (!$issueType || !$desc) {
        $error = 'Please fill in all required fields.';
    } else {
        $db->prepare("INSERT INTO it_requests (user_id, issue_type, priority, description, status)
                      VALUES (?, ?, ?, ?, 'open')")
           ->execute([$user['id'], $issueType, $priority, $desc]);
        $rid = $db->lastInsertId();
        Auth::auditLog($user['id'], 'it_request', "Submitted IT request #$rid: $issueType");

        // Auto-create task for IT team (head_it + it_admin)
        try {
            $itAdminIds = $db->query("SELECT id FROM users WHERE role IN ('it_admin','head_it') AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            $dueDate = date('Y-m-d', strtotime('+3 days'));
            foreach ($itAdminIds as $itId) {
                $db->prepare("INSERT INTO tasks (user_id,created_by,assigned_to,title,description,due_date,priority,status,source)
                              VALUES (?,NULL,?,?,?,?,?,'pending','it_request')")
                   ->execute([$itId, $itId,
                              "IT Request: $issueType — {$user['name']}",
                              "From: {$user['name']}\nPriority: $priority\n\n$desc",
                              $dueDate,
                              $priority === 'urgent' ? 'urgent' : 'high']);
            }
        } catch (Exception $e) { /* task auto-create is optional; ticket already saved */ }

        // Notify IT team (head_it + it_admin)
        $itAdmins = $db->query("SELECT email, name FROM users WHERE role IN ('it_admin','head_it') AND is_active=1")->fetchAll();
        foreach ($itAdmins as $it) {
            $body = "Dear {$it['name']},\n\n{$user['name']} has submitted an IT support request.\n\nType: $issueType\nPriority: $priority\nDescription:\n$desc\n\nView and manage: " . APP_URL . "/admin/it-requests.php\n\nHR Indexx Limited";
            @mail($it['email'], "IT Request — $issueType ({$user['name']})", $body,
                  "From: HRI Mail <noreply@hrindexx.com>\r\nContent-Type: text/plain; charset=UTF-8\r\n");
        }
        $success = "IT support request submitted. The IT team has been notified and will respond shortly.";
    }
}

$myRequests = $db->prepare("SELECT * FROM it_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$myRequests->execute([$user['id']]);
$myReqs = $myRequests->fetchAll();

Layout::shell($user, 'it_request', 0, 'IT Support');
?>
<style>
.ipage{max-width:820px;margin:0 auto;padding:24px 16px 40px;display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;overflow-y:auto;height:100%;min-height:0;}
.icard{background:var(--w);border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.ichd{padding:14px 18px;border-bottom:1px solid var(--g100);font-size:13.5px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px;}
.icbd{padding:18px;}
.ifg{margin-bottom:13px;}
.ifg label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
.ifg input,.ifg select,.ifg textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:9px 12px;font-size:13.5px;font-family:inherit;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;}
.ifg input:focus,.ifg select:focus,.ifg textarea:focus{border-color:var(--green);background:var(--w);}
.ifg textarea{resize:none;height:110px;}
.ibtn{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;background:var(--green);color:#fff;width:100%;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .15s;}
.ibtn:hover{background:#4d7c10;}
.ialert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:14px;}
.ialert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.ialert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.ri-item{padding:11px 18px;border-bottom:1px solid var(--g50);display:flex;align-items:center;gap:10px;}
.ri-item:last-child{border-bottom:none;}
.ri-info{flex:1;}
.ri-type{font-size:13px;font-weight:600;color:var(--g900);}
.ri-meta{font-size:11.5px;color:var(--g400);margin-top:2px;}
.ipill{padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.ipill.open{background:#fef3c7;color:#92400e;}
.ipill.in_progress{background:#dbeafe;color:#1e40af;}
.ipill.resolved{background:#dcfce7;color:#166534;}
.ipill.urgent{background:#fee2e2;color:var(--red);}
.iempty{padding:20px;text-align:center;color:var(--g400);font-size:13px;}
@media(max-width:640px){.ipage{grid-template-columns:1fr;padding:0 12px 40px;margin:14px auto;}}
</style>
<div class="ipage">
    <div>
        <?php if ($success): ?><div class="ialert ok">&#10003; <?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="ialert er">&#9888; <?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="icard">
            <div class="ichd">&#128295; Submit IT Support Request</div>
            <div class="icbd">
                <form method="POST">
                    <?= Auth::csrfField() ?>
                    <div class="ifg"><label>Issue Type *</label>
                        <select name="issue_type" required>
                            <option value="">Select issue type&hellip;</option>
                            <option>Email Problem</option>
                            <option>Login / Password Issue</option>
                            <option>Printer / Scanner</option>
                            <option>Software Install / Update</option>
                            <option>Network / Internet</option>
                            <option>Computer / Hardware</option>
                            <option>HRI Mail Issue</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="ifg"><label>Priority</label>
                        <select name="priority">
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="ifg"><label>Describe the Issue *</label>
                        <textarea name="description" placeholder="What's happening? When did it start? What have you tried?" required></textarea>
                    </div>
                    <button type="submit" class="ibtn">&#128228; Submit Request</button>
                </form>
            </div>
        </div>
    </div>
    <div class="icard">
        <div class="ichd">&#128203; My Requests</div>
        <?php if (empty($myReqs)): ?>
        <div class="iempty">No requests yet</div>
        <?php else: ?>
        <?php foreach ($myReqs as $r): ?>
        <div class="ri-item" style="cursor:pointer;" onclick="toggleTicket(<?=(int)$r['id']?>)">
            <div class="ri-info">
                <div class="ri-type"><?= htmlspecialchars($r['issue_type']) ?></div>
                <div class="ri-meta"><?= date('d M Y H:i', strtotime($r['created_at'])) ?> &middot; Ticket #<?= $r['id'] ?></div>
            </div>
            <?php if ($r['priority']==='urgent'): ?>
            <span class="ipill urgent">Urgent</span>
            <?php endif; ?>
            <span class="ipill <?= $r['status'] ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span>
            <span style="font-size:16px;color:var(--g400);margin-left:4px;" id="arr<?= $r['id'] ?>">&#8964;</span>
        </div>
        <div id="tk<?= $r['id'] ?>" style="display:none;padding:10px 18px 14px;background:var(--g50);border-bottom:1px solid var(--g100);font-size:12.5px;color:var(--g700);">
            <strong>Description:</strong><br/>
            <?= nl2br(htmlspecialchars($r['description'] ?? '—')) ?>
            <?php if (!empty($r['admin_notes'])): ?>
            <div style="margin-top:8px;padding:8px;background:#fff;border-radius:6px;border-left:3px solid var(--green);">
                <strong style="color:var(--navy);">IT Admin Notes:</strong><br/>
                <?= nl2br(htmlspecialchars($r['admin_notes'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<script>
function toggleTicket(id){
    var el=document.getElementById('tk'+id),arr=document.getElementById('arr'+id);
    if(!el)return;
    var open=el.style.display==='block';
    el.style.display=open?'none':'block';
    if(arr)arr.innerHTML=open?'&#8964;':'&#8963;';
}
</script>
<?php Layout::end(); ?>
