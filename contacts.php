<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// contacts.php — Contact Book with CSV import (Sprint 2 ⑨)
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

// Ensure contacts table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS contacts (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        name        VARCHAR(200) NOT NULL,
        email       VARCHAR(300) NOT NULL,
        phone       VARCHAR(40),
        company     VARCHAR(200),
        `group`     VARCHAR(100) DEFAULT 'general',
        notes       TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id), INDEX(email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS contact_groups (
        id      INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name    VARCHAR(100) NOT NULL,
        INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save':
            $id      = (int)($_POST['id'] ?? 0);
            $name    = Auth::sanitiseString($_POST['name']    ?? '', 200);
            $email   = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $phone   = Auth::sanitiseString($_POST['phone']   ?? '', 40);
            $company = Auth::sanitiseString($_POST['company'] ?? '', 200);
            $group   = Auth::sanitiseString($_POST['group']   ?? 'general', 100);
            $notes   = Auth::sanitiseString($_POST['notes']   ?? '', 1000);
            if (!$name || !$email) { echo json_encode(['ok'=>false,'error'=>'Name and valid email required']); exit; }
            if ($id) {
                $db->prepare("UPDATE contacts SET name=?,email=?,phone=?,company=?,`group`=?,notes=? WHERE id=? AND user_id=?")
                   ->execute([$name,$email,$phone,$company,$group,$notes,$id,$user['id']]);
            } else {
                $db->prepare("INSERT INTO contacts (user_id,name,email,phone,company,`group`,notes) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$user['id'],$name,$email,$phone,$company,$group,$notes]);
                $id = (int)$db->lastInsertId();
            }
            echo json_encode(['ok'=>true,'id'=>$id]);
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM contacts WHERE id=? AND user_id=?")->execute([$id,$user['id']]);
            echo json_encode(['ok'=>true]);
            break;

        case 'import_csv':
            if (empty($_FILES['csv']['tmp_name'])) { echo json_encode(['ok'=>false,'error'=>'No file uploaded']); exit; }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['csv']['tmp_name']);
            if (!in_array($mime, ['text/plain','text/csv','application/csv','application/octet-stream'])) {
                echo json_encode(['ok'=>false,'error'=>'File must be a CSV']); exit;
            }
            $handle = fopen($_FILES['csv']['tmp_name'], 'r');
            $imported = 0; $skipped = 0;
            $header = fgetcsv($handle); // Skip header row
            while (($row = fgetcsv($handle)) !== false) {
                $name  = trim($row[0] ?? '');
                $email = filter_var(trim($row[1] ?? ''), FILTER_VALIDATE_EMAIL);
                $phone = trim($row[2] ?? '');
                $company = trim($row[3] ?? '');
                $group   = trim($row[4] ?? 'general') ?: 'general';
                if (!$name || !$email) { $skipped++; continue; }
                // Upsert - update if email exists, insert if not
                $exists = $db->prepare("SELECT id FROM contacts WHERE email=? AND user_id=? LIMIT 1");
                $exists->execute([$email, $user['id']]);
                if ($exists->fetch()) {
                    $db->prepare("UPDATE contacts SET name=?,phone=?,company=?,`group`=? WHERE email=? AND user_id=?")
                       ->execute([$name,$phone,$company,$group,$email,$user['id']]);
                } else {
                    $db->prepare("INSERT INTO contacts (user_id,name,email,phone,company,`group`) VALUES (?,?,?,?,?,?)")
                       ->execute([$user['id'],$name,$email,$phone,$company,$group]);
                }
                $imported++;
            }
            fclose($handle);
            echo json_encode(['ok'=>true,'imported'=>$imported,'skipped'=>$skipped]);
            break;

        case 'search':
            $q = '%'.Auth::sanitiseString($_POST['q']??'',100).'%';
            $rows = $db->prepare("SELECT * FROM contacts WHERE user_id=? AND (name LIKE ? OR email LIKE ? OR company LIKE ?) ORDER BY name LIMIT 20");
            $rows->execute([$user['id'],$q,$q,$q]);
            echo json_encode(['ok'=>true,'contacts'=>$rows->fetchAll()]);
            break;

        case 'group_emails':
            $grp  = Auth::sanitiseString($_POST['group']??'',100);
            $rows = $db->prepare("SELECT email,name FROM contacts WHERE user_id=? AND `group`=?");
            $rows->execute([$user['id'],$grp]);
            $contacts = $rows->fetchAll();
            $emails = implode(', ', array_map(function($c){ return $c['name'].' <'.$c['email'].'>'; }, $contacts));
            echo json_encode(['ok'=>true,'emails'=>$emails,'count'=>count($contacts)]);
            break;

        default:
            echo json_encode(['ok'=>false,'error'=>'Unknown action']);
    }
    exit;
}

// ── LOAD DATA ────────────────────────────────────────────────
$q = Auth::sanitiseString($_GET['q'] ?? '', 100);
$grpFilter = Auth::sanitiseString($_GET['group'] ?? '', 100);

$where  = 'WHERE user_id=?';
$params = [$user['id']];
if ($q) { $where .= ' AND (name LIKE ? OR email LIKE ? OR company LIKE ?)'; $params = array_merge($params, ["%$q%","%$q%","%$q%"]); }
if ($grpFilter) { $where .= ' AND `group`=?'; $params[] = $grpFilter; }

$contacts = $db->prepare("SELECT * FROM contacts $where ORDER BY name");
$contacts->execute($params);
$contacts = $contacts->fetchAll();

$groups = $db->prepare("SELECT DISTINCT `group` FROM contacts WHERE user_id=? ORDER BY `group`");
$groups->execute([$user['id']]);
$groups = array_column($groups->fetchAll(), 'group');

Layout::shell($user, 'contacts', 0, 'Contacts');
?>
<div class="hri-page" style="max-width:1100px;">
    <div class="hri-page-hd">
        <h1 class="hri-page-title">👥 Contact Book</h1>
        <div style="display:flex;gap:8px;">
            <button class="hri-btn hri-btn-outline" onclick="document.getElementById('csvImport').click()">⬆ Import CSV</button>
            <input type="file" id="csvImport" accept=".csv,text/csv" style="display:none" onchange="importCSV(this)"/>
            <button class="hri-btn hri-btn-navy" onclick="openEditor()">+ Add Contact</button>
        </div>
    </div>

    <style>.con-grid{display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;}@media(max-width:640px){.con-grid{grid-template-columns:1fr;}}</style>
    <div class="con-grid">

        <!-- Contact list -->
        <div>
            <!-- Search + filter -->
            <form method="GET" style="display:flex;gap:8px;margin-bottom:16px;">
                <input type="text" name="q" value="<?=htmlspecialchars($q)?>"
                       placeholder="Search name, email, company..."
                       class="hri-input" style="flex:1;"/>
                <select name="group" class="hri-select" style="width:160px;">
                    <option value="">All Groups</option>
                    <?php foreach ($groups as $g): ?>
                    <option value="<?=htmlspecialchars($g)?>" <?=$grpFilter===$g?'selected':''?>><?=htmlspecialchars(ucfirst($g))?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="hri-btn hri-btn-navy">Search</button>
            </form>

            <!-- Group send -->
            <?php if ($groups): ?>
            <div class="hri-card" style="padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;">
                <span style="font-size:13px;color:var(--g600);">Send to group:</span>
                <select id="groupSendSel" class="hri-select" style="flex:1;">
                    <?php foreach ($groups as $g): ?>
                    <option value="<?=htmlspecialchars($g)?>"><?=htmlspecialchars(ucfirst($g))?></option>
                    <?php endforeach; ?>
                </select>
                <button class="hri-btn hri-btn-navy" onclick="composeToGroup()">&#9998; Compose to Group</button>
            </div>
            <?php endif; ?>

            <!-- Contact rows -->
            <div class="hri-card" id="contactList">
                <?php if (empty($contacts)): ?>
                <div class="hri-empty">
                    No contacts yet.<br><br>
                    <strong>CSV Format:</strong> name, email, phone, company, group<br>
                    <small style="color:var(--g400);">Header row optional. One contact per line.</small>
                </div>
                <?php else: ?>
                <?php foreach ($contacts as $c): ?>
                <div class="contact-row" id="cr-<?=$c['id']?>"
                     style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--g100);">
                    <div style="width:38px;height:38px;border-radius:50%;background:var(--navy);color:#fff;
                         font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <?=strtoupper(substr($c['name'],0,1)).(strpos($c['name'],' ')!==false?strtoupper(substr(strrchr($c['name'],' '),1,1)):'')?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:14px;font-weight:600;color:var(--g900);"><?=htmlspecialchars($c['name'])?></div>
                        <div style="font-size:12px;color:var(--g500);"><?=htmlspecialchars($c['email'])?><?=$c['company']?' · '.htmlspecialchars($c['company']):''?></div>
                    </div>
                    <div style="display:flex;gap:6px;flex-shrink:0;">
                        <span class="hri-pill hri-pill-info" style="font-size:11px;"><?=htmlspecialchars($c['group'])?></span>
                        <button class="hri-btn hri-btn-navy" style="padding:4px 10px;font-size:12px;"
                                onclick="composeToContact('<?=htmlspecialchars(addslashes($c['email']))?>','<?=htmlspecialchars(addslashes($c['name']))?>')">✉</button>
                        <button class="hri-btn hri-btn-outline" style="padding:4px 10px;font-size:12px;"
                                onclick="editContact(<?=htmlspecialchars(json_encode($c), ENT_QUOTES)?>)">Edit</button>
                        <button style="padding:4px 8px;font-size:12px;background:#fee2e2;color:var(--red);border:none;border-radius:4px;cursor:pointer;"
                                onclick="deleteContact(<?=$c['id']?>,this)">✕</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($contacts): ?>
            <div style="font-size:12px;color:var(--g400);margin-top:8px;"><?=count($contacts)?> contact<?=count($contacts)!==1?'s':''?></div>
            <?php endif; ?>
        </div>

        <!-- Editor panel -->
        <div style="position:sticky;top:80px;">
            <div class="hri-card" style="padding:20px;" id="editorPanel">
                <div style="font-size:14px;font-weight:600;color:var(--navy);margin-bottom:16px;" id="editorTitle">Add Contact</div>
                <div class="hri-form-group">
                    <label class="hri-label">Full Name *</label>
                    <input type="text" id="cName" class="hri-input" placeholder="John Adewale"/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Email Address *</label>
                    <input type="email" id="cEmail" class="hri-input" placeholder="john@example.com"/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Phone</label>
                    <input type="text" id="cPhone" class="hri-input" placeholder="+234 801 234 5678"/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Company</label>
                    <input type="text" id="cCompany" class="hri-input" placeholder="Company name"/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Group</label>
                    <input type="text" id="cGroup" class="hri-input" placeholder="vendors, clients, ndpc..." list="groupList"/>
                    <datalist id="groupList">
                        <?php foreach ($groups as $g): ?>
                        <option value="<?=htmlspecialchars($g)?>">
                        <?php endforeach; ?>
                        <option value="clients"><option value="vendors"><option value="ndpc">
                        <option value="partners"><option value="staff"><option value="general">
                    </datalist>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Notes</label>
                    <textarea id="cNotes" class="hri-textarea" rows="3" placeholder="Optional notes..."></textarea>
                </div>
                <div style="display:flex;gap:8px;">
                    <button class="hri-btn hri-btn-navy" style="flex:1;" onclick="saveContact()">Save Contact</button>
                    <button class="hri-btn hri-btn-outline" onclick="clearEditor()">Clear</button>
                </div>
                <input type="hidden" id="cId" value="0"/>
                <div id="cStatus" style="font-size:12px;margin-top:8px;"></div>
            </div>

            <!-- CSV import guide -->
            <div class="hri-card" style="padding:16px;margin-top:12px;">
                <div style="font-size:12px;font-weight:700;color:var(--navy);margin-bottom:8px;">CSV Import Format</div>
                <div style="font-family:monospace;font-size:11px;background:var(--g50);padding:8px;border-radius:4px;color:var(--g700);">
                    name,email,phone,company,group<br>
                    John Adewale,john@co.com,08012345,EBIS,clients<br>
                    Mr Wale,wale@ebis.ng,,EBIS Ltd,clients
                </div>
                <div style="font-size:11px;color:var(--g400);margin-top:6px;">Header row optional. Phone/company/group can be blank.</div>
            </div>
        </div>
    </div>
</div>

<script>
var currentId = 0;

function openEditor() {
    clearEditor();
    document.getElementById('cName').focus();
}
function clearEditor() {
    currentId = 0;
    ['cId','cName','cEmail','cPhone','cCompany','cGroup','cNotes'].forEach(function(id){
        document.getElementById(id).value = id==='cId'?'0':'';
    });
    document.getElementById('editorTitle').textContent = 'Add Contact';
    document.getElementById('cStatus').textContent = '';
}
function editContact(c) {
    currentId = c.id;
    document.getElementById('cId').value      = c.id;
    document.getElementById('cName').value    = c.name || '';
    document.getElementById('cEmail').value   = c.email || '';
    document.getElementById('cPhone').value   = c.phone || '';
    document.getElementById('cCompany').value = c.company || '';
    document.getElementById('cGroup').value   = c.group || '';
    document.getElementById('cNotes').value   = c.notes || '';
    document.getElementById('editorTitle').textContent = 'Edit: ' + c.name;
    document.getElementById('editorPanel').scrollIntoView({behavior:'smooth'});
}
function saveContact() {
    var fd = new FormData();
    fd.append('action','save');
    ['id','name','email','phone','company','group','notes'].forEach(function(f){
        fd.append(f, document.getElementById('c'+f.charAt(0).toUpperCase()+f.slice(1)).value||'');
    });
    fetch('contacts.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        var st = document.getElementById('cStatus');
        if(d.ok){ st.style.color='var(--green)'; st.textContent='Saved!'; setTimeout(function(){location.reload();},800); }
        else { st.style.color='var(--red)'; st.textContent='Error: '+(d.error||'Failed'); }
    });
}
function deleteContact(id, btn) {
    if(!confirm('Delete this contact?')) return;
    var fd = new FormData(); fd.append('action','delete'); fd.append('id',id);
    fetch('contacts.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){ if(d.ok) document.getElementById('cr-'+id).remove(); });
}
function composeToContact(email, name) {
    if(typeof window.hriOpenCompose==='function') window.hriOpenCompose({to:email});
    else location.href='mail.php#compose';
}
function composeToGroup() {
    var grp = document.getElementById('groupSendSel').value;
    var fd = new FormData(); fd.append('action','group_emails'); fd.append('group',grp);
    fetch('contacts.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok&&d.count>0){
            if(typeof window.hriOpenCompose==='function') window.hriOpenCompose({to:d.emails});
            else alert('Emails: '+d.emails);
        } else alert('No contacts in this group');
    });
}
function importCSV(input) {
    var file = input.files[0];
    if(!file) return;
    var fd = new FormData(); fd.append('action','import_csv'); fd.append('csv',file);
    fetch('contacts.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok) { alert('Imported '+d.imported+' contacts'+(d.skipped?' ('+d.skipped+' skipped)':'')); location.reload(); }
        else alert('Import error: '+(d.error||'Failed'));
    });
    input.value = '';
}
</script>
<?php Layout::end(); ?>
