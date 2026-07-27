<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// email-tpl.php — Email templates (Sprint 2 ⑩)
// IMPORTANT: No output before Auth::require() — session must start first
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS email_templates (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        name       VARCHAR(200) NOT NULL,
        subject    VARCHAR(500) NOT NULL,
        body       LONGTEXT,
        category   VARCHAR(80) DEFAULT 'general',
        is_shared  TINYINT(1) DEFAULT 1,
        created_at DATETIME,
        updated_at DATETIME,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Table may already exist - that is fine
}
// Add columns that may be missing if table was created from schema.sql
foreach ([
    "ALTER TABLE email_templates ADD COLUMN IF NOT EXISTS user_id INT NULL",
    "ALTER TABLE email_templates ADD COLUMN IF NOT EXISTS is_shared TINYINT(1) DEFAULT 1",
    "ALTER TABLE email_templates ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL",
    "UPDATE email_templates SET user_id=created_by WHERE user_id IS NULL",
] as $_sql) { try { $db->exec($_sql); } catch (Exception $_e) {} }
unset($_sql, $_e);

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save':
            $id       = (int)($_POST['id'] ?? 0);
            $name     = Auth::sanitiseString($_POST['name']     ?? '', 200);
            $subject  = Auth::sanitiseString($_POST['subject']  ?? '', 500);
            $body     = $_POST['body'] ?? '';
            $category = Auth::sanitiseString($_POST['category'] ?? 'general', 80);
            if (!$name || !$subject) { echo json_encode(['ok'=>false,'error'=>'Name and subject required']); exit; }
            if ($id) {
                $db->prepare("UPDATE email_templates SET name=?,subject=?,body=?,category=? WHERE id=? AND user_id=?")
                   ->execute([$name,$subject,$body,$category,$id,$user['id']]);
            } else {
                $db->prepare("INSERT INTO email_templates (user_id,name,subject,body,category) VALUES (?,?,?,?,?)")
                   ->execute([$user['id'],$name,$subject,$body,$category]);
                $id = (int)$db->lastInsertId();
            }
            Auth::auditLog($user['id'], 'template_saved', "ID $id: $name");
            echo json_encode(['ok'=>true,'id'=>$id]);
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM email_templates WHERE id=? AND user_id=?")->execute([$id,$user['id']]);
            echo json_encode(['ok'=>true]);
            break;

        case 'use':
            // Fill template variables and return populated subject/body
            $id   = (int)($_POST['id'] ?? 0);
            $vars = $_POST['vars'] ?? []; // e.g. ['staff_name'=>'John']
            $row  = $db->prepare("SELECT * FROM email_templates WHERE id=? LIMIT 1");
            $row->execute([$id]);
            $tpl  = $row->fetch();
            if (!$tpl) { echo json_encode(['ok'=>false,'error'=>'Template not found']); exit; }
            $subject = $tpl['subject'];
            $body    = $tpl['body'];
            // Replace {{variable}} placeholders
            foreach ($vars as $k => $v) {
                $key = preg_replace('/[^a-z0-9_]/', '', strtolower($k));
                $subject = str_replace('{{'.$key.'}}', htmlspecialchars($v), $subject);
                $body    = str_replace('{{'.$key.'}}', $v, $body);
            }
            echo json_encode(['ok'=>true,'subject'=>$subject,'body'=>$body]);
            break;

        case 'get':
            $id  = (int)($_POST['id'] ?? 0);
            $row = $db->prepare("SELECT * FROM email_templates WHERE id=? LIMIT 1");
            $row->execute([$id]);
            $tpl = $row->fetch();
            echo json_encode($tpl ? ['ok'=>true,'template'=>$tpl] : ['ok'=>false,'error'=>'Not found']);
            break;

        default:
            echo json_encode(['ok'=>false,'error'=>'Unknown action']);
    }
    exit;
}

// ── LOAD TEMPLATES ───────────────────────────────────────────
$templates = [];
try {
    $tmplStmt = $db->prepare("SELECT * FROM email_templates WHERE user_id=? OR is_shared=1 ORDER BY category,name");
    $tmplStmt->execute([$user['id']]);
    $templates = $tmplStmt->fetchAll();
} catch (Exception $e) {
    $templates = [];
}

$categories = ['general'=>'General','hr'=>'HR','leave'=>'Leave Management','it'=>'IT Support','compliance'=>'Compliance','onboarding'=>'Onboarding'];

// Pre-built HRI templates to seed
$seedTemplates = [
    ['Leave Approved','HR','leave','Leave Request Approved — {{staff_name}}',
     '<p>Dear {{staff_name}},</p><p>Your leave request for <strong>{{leave_dates}}</strong> has been <strong style="color:#1a7f37;">approved</strong>.</p><p>Please ensure your responsibilities are handed over to {{handover_person}} before your leave begins.</p><p>Enjoy your time off.</p><p>Regards,<br>HR Department<br>HR Indexx Limited</p>'],
    ['Leave Rejected','HR','leave','Leave Request Not Approved — {{staff_name}}',
     '<p>Dear {{staff_name}},</p><p>We regret to inform you that your leave request for <strong>{{leave_dates}}</strong> has been <strong style="color:#d93025;">declined</strong>.</p><p>Reason: {{reason}}</p><p>Please speak with your line manager if you have any questions.</p><p>Regards,<br>HR Department<br>HR Indexx Limited</p>'],
    ['Welcome Onboard','HR','onboarding','Welcome to HR Indexx Limited — {{staff_name}}',
     '<p>Dear {{staff_name}},</p><p>Welcome to <strong>HR Indexx Limited</strong>! We are delighted to have you join our team as <strong>{{job_title}}</strong>.</p><p>Your start date is <strong>{{start_date}}</strong>. Please report to {{reporting_manager}} at {{start_time}}.</p><p>Your HRI email address is: {{email}}</p><p>Please do not hesitate to contact the IT department if you need any assistance getting set up.</p><p>We look forward to working with you.</p><p>Warm regards,<br>HR Indexx Limited</p>'],
    ['IT Request Acknowledged','IT Support','it','IT Request Received — Ticket #{{ticket_id}}',
     '<p>Dear {{staff_name}},</p><p>Your IT support request has been received and assigned ticket number <strong>#{{ticket_id}}</strong>.</p><p>Issue: {{issue_description}}</p><p>Our team will respond within <strong>{{sla_hours}} business hours</strong>.</p><p>You can track your request at {{tracking_url}}</p><p>Regards,<br>IT Department<br>HR Indexx Limited</p>'],
    ['NDPC Compliance Reminder','Compliance','compliance','NDPC Certificate Renewal Reminder — {{days_remaining}} Days',
     '<p>Dear {{recipient_name}},</p><p>This is an automated reminder that the NDPC Data Protection Certificate for <strong>HR Indexx Limited</strong> is due for renewal in <strong>{{days_remaining}} days</strong>.</p><p>Certificate: {{certificate_number}}<br>Expiry Date: {{expiry_date}}</p><p>Please initiate the renewal process immediately to avoid compliance issues.</p><p>Action required by: {{action_deadline}}</p><p>Regards,<br>IT & Compliance Team<br>HR Indexx Limited</p>'],
];

// Seed default templates if none exist
if (empty($templates)) {
    try {
        foreach ($seedTemplates as $t) {
            $tName    = $t[0];
            $tCat     = $t[2];
            $tSubject = $t[3];
            $tBody    = $t[4];
            $stmt2 = $db->prepare("INSERT IGNORE INTO email_templates (user_id,name,subject,body,category,is_shared,created_at) VALUES (?,?,?,?,?,1,NOW())");
            $stmt2->execute([$user['id'],$tName,$tSubject,$tBody,$tCat]);
        }
    } catch (Exception $e) {}
    $stmt = $db->prepare("SELECT * FROM email_templates WHERE user_id=? OR is_shared=1 ORDER BY category,name");
    $stmt->execute([$user['id']]);
    $templates = $stmt->fetchAll();
}

Layout::shell($user, 'templates', 0, 'Email Templates');
?>
<div class="hri-page" style="max-width:1100px;">
    <div class="hri-page-hd">
        <h1 class="hri-page-title">📝 Email Templates</h1>
        <button class="hri-btn hri-btn-navy" onclick="openEditor()">+ New Template</button>
    </div>

    <style>.tpl-grid{display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;}@media(max-width:640px){.tpl-grid{grid-template-columns:1fr;}}</style>
    <div class="tpl-grid">

        <!-- Template list -->
        <div>
            <?php
            $byCategory = [];
            foreach ($templates as $t) $byCategory[$t['category']][] = $t;
            foreach ($byCategory as $cat => $tpls):
                $catLabel = $categories[$cat] ?? ucfirst($cat);
            ?>
            <div style="margin-bottom:16px;">
                <div style="font-size:11px;font-weight:700;color:var(--g500);text-transform:uppercase;
                     letter-spacing:.08em;padding:6px 0;margin-bottom:8px;border-bottom:1px solid var(--g200);">
                    <?=$catLabel?>
                </div>
                <?php foreach ($tpls as $t): ?>
                <div class="hri-card" style="margin-bottom:8px;padding:14px 16px;cursor:pointer;"
                     onclick="loadTemplate(<?=$t['id']?>)"
                     onmouseover="this.style.background='var(--g50)'"
                     onmouseout="this.style.background='var(--w)'">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <div>
                            <div style="font-size:14px;font-weight:600;color:var(--g900);"><?=htmlspecialchars($t['name'])?></div>
                            <div style="font-size:12px;color:var(--g500);margin-top:2px;"><?=htmlspecialchars($t['subject'])?></div>
                        </div>
                        <div style="display:flex;gap:6px;flex-shrink:0;">
                            <button class="hri-btn hri-btn-navy" style="padding:4px 12px;font-size:12px;"
                                    onclick="event.stopPropagation();useTemplate(<?=$t['id']?>)">Use</button>
                            <button class="hri-btn hri-btn-outline" style="padding:4px 12px;font-size:12px;"
                                    onclick="event.stopPropagation();editTemplate(<?=$t['id']?>)">Edit</button>
                            <button style="padding:4px 10px;font-size:12px;background:#fee2e2;color:var(--red);border:none;border-radius:4px;cursor:pointer;"
                                    onclick="event.stopPropagation();deleteTemplate(<?=$t['id']?>,this)">✕</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($templates)): ?>
            <div class="hri-empty">No templates yet. Create your first one →</div>
            <?php endif; ?>
        </div>

        <!-- Editor / Preview panel -->
        <div id="editorPanel" style="position:sticky;top:80px;">
            <div class="hri-card" style="padding:20px;">
                <div style="font-size:14px;font-weight:600;color:var(--navy);margin-bottom:16px;" id="editorTitle">New Template</div>

                <div class="hri-form-group">
                    <label class="hri-label">Template Name</label>
                    <input type="text" id="tplName" class="hri-input" placeholder="e.g. Leave Approved"/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Category</label>
                    <select id="tplCategory" class="hri-select">
                        <?php foreach ($categories as $k=>$v): ?>
                        <option value="<?=$k?>"><?=$v?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Subject Line</label>
                    <input type="text" id="tplSubject" class="hri-input" placeholder="e.g. Leave Approved — {{staff_name}}"/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Body</label>
                    <textarea id="tplBody" class="hri-textarea" rows="10"
                              placeholder="Use {{variable_name}} for placeholders&#10;e.g. Dear {{staff_name}},"></textarea>
                </div>

                <div style="font-size:11px;color:var(--g400);margin-bottom:14px;padding:8px 10px;background:var(--g50);border-radius:4px;">
                    <strong>Available variables:</strong><br>
                    {{staff_name}} {{leave_dates}} {{reason}} {{job_title}} {{start_date}}<br>
                    {{manager_name}} {{email}} {{ticket_id}} {{days_remaining}} {{expiry_date}}
                </div>

                <div style="display:flex;gap:8px;">
                    <button class="hri-btn hri-btn-navy" style="flex:1;" onclick="saveTemplate()">Save Template</button>
                    <button class="hri-btn hri-btn-outline" onclick="clearEditor()">Clear</button>
                </div>
                <input type="hidden" id="tplId" value="0"/>
                <div id="tplStatus" style="font-size:12px;color:var(--green);margin-top:8px;"></div>
            </div>
        </div>
    </div>
</div>

<script>
var currentTplId = 0;

function openEditor() {
    clearEditor();
    document.getElementById('tplName').focus();
}

function clearEditor() {
    currentTplId = 0;
    document.getElementById('tplId').value = '0';
    document.getElementById('tplName').value = '';
    document.getElementById('tplSubject').value = '';
    document.getElementById('tplBody').value = '';
    document.getElementById('tplCategory').value = 'general';
    document.getElementById('editorTitle').textContent = 'New Template';
    document.getElementById('tplStatus').textContent = '';
}

function editTemplate(id) {
    var fd = new FormData();
    fd.append('action','get');
    fd.append('id', id);
    fetch('email-tpl.php',{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
        if(!d.ok) return;
        var t = d.template;
        currentTplId = t.id;
        document.getElementById('tplId').value = t.id;
        document.getElementById('tplName').value = t.name;
        document.getElementById('tplSubject').value = t.subject;
        document.getElementById('tplBody').value = t.body;
        document.getElementById('tplCategory').value = t.category;
        document.getElementById('editorTitle').textContent = 'Edit: ' + t.name;
        document.getElementById('tplName').focus();
        document.getElementById('editorPanel').scrollIntoView({behavior:'smooth'});
    });
}

function loadTemplate(id) { editTemplate(id); }

function useTemplate(id) {
    // Open compose with template content
    var fd = new FormData();
    fd.append('action','use');
    fd.append('id', id);
    fetch('email-tpl.php',{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
        if(!d.ok) return;
        if(typeof window.hriOpenCompose === 'function') {
            window.hriOpenCompose({subject: d.subject, body: d.body});
        } else {
            alert('Open compose first, then use this template');
        }
    });
}

function saveTemplate() {
    var name     = document.getElementById('tplName').value.trim();
    var subject  = document.getElementById('tplSubject').value.trim();
    var body     = document.getElementById('tplBody').value;
    var category = document.getElementById('tplCategory').value;
    var id       = document.getElementById('tplId').value;
    if (!name || !subject) { alert('Name and subject required'); return; }
    var fd = new FormData();
    fd.append('action','save');
    fd.append('id', id);
    fd.append('name', name);
    fd.append('subject', subject);
    fd.append('body', body);
    fd.append('category', category);
    fetch('email-tpl.php',{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
        var st = document.getElementById('tplStatus');
        if(d.ok){
            st.textContent = 'Saved!';
            st.style.color = 'var(--green)';
            setTimeout(function(){ location.reload(); }, 1000);
        } else {
            st.textContent = 'Error: '+(d.error||'Failed');
            st.style.color = 'var(--red)';
        }
    });
}

function deleteTemplate(id, btn) {
    if(!confirm('Delete this template?')) return;
    var fd = new FormData();
    fd.append('action','delete');
    fd.append('id', id);
    fetch('email-tpl.php',{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok) btn.closest('.hri-card').remove();
    });
}
</script>
<?php Layout::end(); ?>
