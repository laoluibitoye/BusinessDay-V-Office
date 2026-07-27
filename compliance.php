<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// compliance.php — SLA Register & Compliance Documents
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_compliance','head_outsourcing','head_accounts','head_training','head_cso']);
$db   = getDB();

$success = '';
$error   = '';
$canUpload = in_array($user['role'], ['head_compliance','head_it','it_admin','md','bdm']);

define('COMPLIANCE_UPLOAD_DIR', __DIR__ . '/uploads/compliance/');

// ── ADD SLA ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sla'])) {
    $name   = trim($_POST['name'] ?? '');
    $client = trim($_POST['client'] ?? '');
    $expiry = $_POST['expiry_date'] ?? '';
    $notify = (int)($_POST['notify_days'] ?? 30);
    $desc   = trim($_POST['description'] ?? '');
    if (!$name || !$expiry) { $error = 'SLA name and expiry date required.'; }
    else {
        $db->prepare("INSERT INTO sla_tracker (name, client, description, expiry_date, owner_id, notify_days, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$name, $client, $desc, $expiry, $user['id'], $notify, $user['id']]);
        Auth::auditLog($user['id'], 'sla_added', "SLA added: $name");
        $success = "SLA \"$name\" added.";
    }
}

// ── ADD COMPLIANCE DOC (with optional file upload) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_doc'])) {
    $name     = trim($_POST['doc_name'] ?? '');
    $category = $_POST['doc_category'] ?? 'regulatory';
    $expiry   = $_POST['doc_expiry'] ?: null;
    $notify   = (int)($_POST['doc_notify'] ?? 30);
    $owner    = (int)($_POST['doc_owner'] ?? $user['id']);
    $desc     = trim($_POST['doc_desc'] ?? '');

    if (!$name) { $error = 'Document title required.'; }
    else {
        $storedName = null; $filePath = null; $mimeType = null;

        if (!empty($_FILES['doc_file']['name']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
                        'png'=>'image/png','doc'=>'application/msword',
                        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $ext          = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
            $detectedMime = mime_content_type($_FILES['doc_file']['tmp_name']) ?: '';
            // DOCX is a ZIP container — some servers return application/zip or octet-stream
            $docxAliases  = ['application/zip','application/octet-stream','application/x-zip-compressed'];
            $mimeOk       = isset($allowed[$ext]) &&
                            ($detectedMime === $allowed[$ext] || ($ext === 'docx' && in_array($detectedMime, $docxAliases)));
            if (!isset($allowed[$ext])) {
                $error = 'Only PDF, JPG, PNG, DOC, DOCX files allowed.';
            } elseif (!$mimeOk) {
                $error = 'File type mismatch — upload rejected.';
            } elseif ($_FILES['doc_file']['size'] > 10 * 1024 * 1024) {
                $error = 'File must be under 10MB.';
            } else {
                if (!is_dir(COMPLIANCE_UPLOAD_DIR)) mkdir(COMPLIANCE_UPLOAD_DIR, 0755, true);
                $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
                $filePath   = COMPLIANCE_UPLOAD_DIR . $storedName;
                if (!move_uploaded_file($_FILES['doc_file']['tmp_name'], $filePath)) {
                    $error = 'File upload failed. Check server permissions.';
                    $storedName = $filePath = null;
                } else {
                    $mimeType = $allowed[$ext];
                }
            }
        }

        if (!$error) {
            $db->prepare("INSERT INTO compliance_docs (name, category, description, expiry_date, notify_days, owner_id, created_by, stored_name, file_path, mime_type)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$name, $category, $desc, $expiry, $notify, $owner, $user['id'], $storedName, $filePath, $mimeType]);
            Auth::auditLog($user['id'], 'compliance_doc_added', "Compliance doc added: $name");
            $success = "Document \"$name\" added." . ($storedName ? ' File uploaded.' : '');
        }
    }
}

// ── UPDATE SLA STATUS ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sla_status'])) {
    $id = (int)$_POST['sla_id'];
    $st = $_POST['new_status'] ?? 'active';
    $db->prepare("UPDATE sla_tracker SET status=? WHERE id=?")->execute([$st, $id]);
    $success = 'SLA status updated.';
}

// ── FETCH DATA ────────────────────────────────────────────────────────────
$slas = $db->query("SELECT s.*, u.name as owner_name FROM sla_tracker s LEFT JOIN users u ON u.id=s.owner_id ORDER BY s.expiry_date ASC")->fetchAll();
$docs = $db->query("SELECT d.*, u.name as owner_name FROM compliance_docs d LEFT JOIN users u ON u.id=d.owner_id ORDER BY CASE WHEN d.expiry_date IS NULL THEN 1 ELSE 0 END, d.expiry_date ASC")->fetchAll();
$allStaff = $db->query("SELECT id, name FROM users WHERE is_active=1 ORDER BY name")->fetchAll();

function daysUntil($date) {
    if (!$date) return null;
    return (int)ceil((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
}
function daysClass($days) {
    if ($days === null) return 'gray';
    if ($days < 0)  return 'red';
    if ($days < 30) return 'warn';
    return 'green';
}
function daysLabel($days) {
    if ($days === null) return '—';
    if ($days < 0)  return abs($days) . 'd overdue';
    if ($days === 0) return 'Today';
    return $days . 'd';
}
Layout::shell($user, 'compliance', 0, 'Compliance Tracker');
?>
<style>
.tabs{display:flex;gap:5px;margin-bottom:16px;flex-wrap:wrap;}
.tab{padding:6px 15px;border-radius:20px;font-size:12.5px;font-weight:500;cursor:pointer;border:1.5px solid var(--g200);color:var(--g700);text-decoration:none;transition:all .12s;}
.tab.on,.tab:hover{background:var(--navy);color:#fff;border-color:var(--navy);}
.section{display:none;}
.section.on{display:grid;grid-template-columns:1fr 330px;gap:16px;align-items:start;}
.card{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
.chd{padding:11px 18px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.chd-title{font-size:13px;font-weight:700;color:var(--navy);}
.chd-count{font-size:12px;color:var(--g400);}
.tbl{width:100%;border-collapse:collapse;}
.tbl th{background:var(--g50);padding:8px 13px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.tbl td{padding:10px 13px;border-bottom:1px solid var(--g50);font-size:13px;color:var(--g700);vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tr:hover td{background:var(--g50);}
.days-badge{padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block;white-space:nowrap;}
.days-badge.red{background:#fee2e2;color:var(--red);}
.days-badge.warn{background:#fef3c7;color:#92400e;}
.days-badge.green{background:#dcfce7;color:#166534;}
.days-badge.gray{background:var(--g100);color:var(--g500);}
.pill{padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:600;display:inline-block;}
.pill.active{background:#dcfce7;color:#166534;}
.pill.expired{background:#fee2e2;color:var(--red);}
.pill.expiring,.pill.expiring_soon,.pill.pending_renewal{background:#fef3c7;color:#92400e;}
.pill.renewed,.pill.cancelled{background:var(--g100);color:var(--g500);}
.pill.pu{background:#f3f0ff;color:#8b5cf6;}
.pill.tl{background:#e0f2fe;color:#0891b2;}
.alert{padding:10px 16px;border-radius:9px;font-size:13px;margin-bottom:14px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.fg{margin-bottom:11px;}
label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
input[type=text],input[type=date],input[type=number],input[type=file],select,textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;}
input:focus,select:focus,textarea:focus{border-color:var(--green);background:var(--w);}
input[type=file]{padding:6px 10px;cursor:pointer;}
textarea{resize:none;height:55px;}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:9px;}
.btn{padding:8px 15px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:5px;transition:all .15s;}
.btn.gn{background:var(--green);color:#fff;width:100%;justify-content:center;}
.btn.gn:hover{background:#4d7c10;}
.btn-preview{padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:600;background:#dbeafe;color:#1e40af;border:1.5px solid #93c5fd;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
.btn-preview:hover{background:#bfdbfe;}
.empty{padding:24px;text-align:center;color:var(--g400);font-size:13px;}
.sel-sm{border:1.5px solid var(--g200);border-radius:7px;padding:3px 7px;font-size:11.5px;font-family:'Inter',sans-serif;color:var(--g700);outline:none;cursor:pointer;background:var(--w);}
.check-row{display:flex;align-items:center;gap:8px;margin-bottom:11px;}
.check-row input[type=checkbox]{width:15px;height:15px;accent-color:var(--green);cursor:pointer;}
.check-row label{font-size:12.5px;font-weight:500;color:var(--g700);text-transform:none;letter-spacing:0;margin:0;cursor:pointer;}
/* Preview modal */
#previewOverlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;align-items:center;justify-content:center;}
#previewOverlay.open{display:flex;}
#previewBox{background:var(--w);width:92vw;max-width:960px;height:88vh;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.4);}
#previewHeader{padding:12px 18px;border-bottom:1px solid var(--g200);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
#previewTitle{font-size:14px;font-weight:700;color:var(--navy);}
#previewClose{border:none;background:none;font-size:22px;cursor:pointer;color:var(--g500);line-height:1;padding:2px 6px;}
#previewClose:hover{color:var(--g900);}
#previewFrame{flex:1;border:none;width:100%;height:100%;}
.pgt{font-size:17px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.pgs{font-size:12.5px;color:var(--g400);margin-bottom:14px;}
@media(max-width:640px){
    .section.on{grid-template-columns:1fr;}
    .row2{grid-template-columns:1fr;}
}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">
    <div class="pgt">📋 Compliance & SLA Tracker</div>
    <div class="pgs">Monitor SLAs and compliance documents</div>

    <?php if ($success): ?><div class="alert ok">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="tabs">
        <a class="tab on" href="#" onclick="showSection('sla',this);return false;">📊 SLAs (<?= count($slas) ?>)</a>
        <a class="tab" href="#" onclick="showSection('docs',this);return false;">📄 Compliance Docs (<?= count($docs) ?>)</a>
    </div>

    <!-- ══ SLA SECTION ══════════════════════════════════════════════════════ -->
    <div id="sec-sla" class="section on">
        <div>
            <div class="card">
                <div class="chd"><div class="chd-title">📊 SLA Register</div><span class="chd-count"><?= count($slas) ?> SLAs</span></div>
                <?php if (empty($slas)): ?>
                <div class="empty">No SLAs added yet</div>
                <?php else: ?>
                <table class="tbl">
                    <thead><tr><th>SLA Name</th><th>Client</th><th>Owner</th><th>Expiry</th><th>Days Left</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($slas as $s):
                        $days = daysUntil($s['expiry_date']);
                        $statusCls = in_array($s['status'], ['active','expired','renewed']) ? $s['status'] : 'expiring';
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($s['name']) ?></strong><?php if ($s['description']): ?><br/><span style="font-size:11.5px;color:var(--g400);"><?= htmlspecialchars(substr($s['description'],0,55)) ?></span><?php endif; ?></td>
                        <td><?= htmlspecialchars($s['client'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($s['owner_name'] ?? '—') ?></td>
                        <td style="white-space:nowrap"><?= $s['expiry_date'] ? date('d M Y', strtotime($s['expiry_date'])) : '—' ?></td>
                        <td><span class="days-badge <?= daysClass($days) ?>"><?= daysLabel($days) ?></span></td>
                        <td><span class="pill <?= $statusCls ?>"><?= ucfirst(str_replace('_',' ',$s['status'])) ?></span></td>
                        <td>
                            <form method="POST">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="sla_id" value="<?= $s['id'] ?>"/>
                                <input type="hidden" name="update_sla_status" value="1"/>
                                <select class="sel-sm" name="new_status" onchange="this.form.submit()">
                                    <option value="active" <?= $s['status']==='active'?'selected':'' ?>>Active</option>
                                    <option value="expiring_soon" <?= $s['status']==='expiring_soon'?'selected':'' ?>>Expiring</option>
                                    <option value="expired" <?= $s['status']==='expired'?'selected':'' ?>>Expired</option>
                                    <option value="renewed" <?= $s['status']==='renewed'?'selected':'' ?>>Renewed</option>
                                </select>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <div class="card" style="border-top:3px solid #8b5cf6;">
            <div class="chd"><div class="chd-title">➕ Add SLA</div></div>
            <div style="padding:15px 18px;">
                <form method="POST">
                    <?= Auth::csrfField() ?>
                    <div class="fg"><label>SLA Name *</label><input type="text" name="name" placeholder="e.g. GTB Service Agreement" required/></div>
                    <div class="fg"><label>Client / Party</label><input type="text" name="client" placeholder="e.g. GTB Bank"/></div>
                    <div class="fg"><label>Expiry Date *</label><input type="date" name="expiry_date" required/></div>
                    <div class="fg"><label>Alert Before (days)</label><input type="number" name="notify_days" value="30" min="1" max="180"/></div>
                    <div class="fg"><label>Description</label><textarea name="description" placeholder="Brief description…"></textarea></div>
                    <button type="submit" name="add_sla" class="btn gn">➕ Add SLA</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ COMPLIANCE DOCS SECTION ══════════════════════════════════════════ -->
    <div id="sec-docs" class="section">
        <div>
            <div class="card">
                <div class="chd"><div class="chd-title">📄 Compliance Documents</div><span class="chd-count"><?= count($docs) ?> documents</span></div>
                <?php if (empty($docs)): ?>
                <div class="empty">No compliance documents added yet</div>
                <?php else: ?>
                <table class="tbl">
                    <thead><tr><th>Title</th><th>Category</th><th>Owner</th><th>Expiry</th><th>Days Left</th><th>Status</th><th>File</th></tr></thead>
                    <tbody>
                    <?php foreach ($docs as $d):
                        $days = daysUntil($d['expiry_date']);
                        $statusCls = $d['status'] === 'expired' ? 'expired' : ($days !== null && $days < 30 ? 'expiring' : 'active');
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($d['name']) ?></strong><?php if ($d['description']): ?><br/><span style="font-size:11.5px;color:var(--g400);"><?= htmlspecialchars(substr($d['description'],0,50)) ?></span><?php endif; ?></td>
                        <td><span class="pill pu"><?= ucfirst(str_replace('_',' ',$d['category'])) ?></span></td>
                        <td><?= htmlspecialchars($d['owner_name'] ?? '—') ?></td>
                        <td style="white-space:nowrap"><?= $d['expiry_date'] ? date('d M Y', strtotime($d['expiry_date'])) : 'No expiry' ?></td>
                        <td><span class="days-badge <?= daysClass($days) ?>"><?= daysLabel($days) ?></span></td>
                        <td><span class="pill <?= $statusCls ?>"><?= ucfirst($d['status']) ?></span></td>
                        <td>
                            <?php if ($d['stored_name']): ?>
                            <button class="btn-preview" onclick="openPreview('compliance',<?= $d['id'] ?>,<?= htmlspecialchars(json_encode($d['name']), ENT_QUOTES) ?>)">👁 View</button>
                            <?php else: ?>
                            <span style="font-size:11.5px;color:var(--g400);">No file</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <div class="card" style="border-top:3px solid var(--navy);">
            <div class="chd"><div class="chd-title">➕ Add Compliance Document</div></div>
            <div style="padding:15px 18px;">
                <form method="POST" enctype="multipart/form-data">
                    <?= Auth::csrfField() ?>
                    <div class="fg"><label>Document Title *</label><input type="text" name="doc_name" placeholder="e.g. NDPC EHL Registration" required/></div>
                    <div class="fg"><label>Category</label>
                        <select name="doc_category">
                            <option value="regulatory">Regulatory</option>
                            <option value="policy">Policy</option>
                            <option value="certification">Certification</option>
                            <option value="audit">Audit</option>
                            <option value="data_protection">Data Protection</option>
                            <option value="it">IT</option>
                            <option value="finance">Finance</option>
                            <option value="hr">HR</option>
                        </select>
                    </div>
                    <div class="row2">
                        <div class="fg"><label>Expiry Date</label><input type="date" name="doc_expiry"/></div>
                        <div class="fg"><label>Alert (days before)</label><input type="number" name="doc_notify" value="30" min="1" max="180"/></div>
                    </div>
                    <div class="fg"><label>Assigned To</label>
                        <select name="doc_owner">
                            <?php foreach ($allStaff as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id']==$user['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg"><label>Upload File (PDF / Image / Word — max 10MB)</label><input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"/></div>
                    <div class="fg"><label>Description</label><textarea name="doc_desc" placeholder="What is this document?"></textarea></div>
                    <button type="submit" name="add_doc" class="btn gn">➕ Add Document</button>
                </form>
            </div>
        </div>
    </div>


</div>

<!-- Preview Modal -->
<div id="previewOverlay" onclick="if(event.target===this)closePreview()">
    <div id="previewBox">
        <div id="previewHeader">
            <span id="previewTitle"></span>
            <button id="previewClose" onclick="closePreview()">✕</button>
        </div>
        <iframe id="previewFrame" src="" referrerpolicy="same-origin"></iframe>
    </div>
</div>

<script>
function showSection(key, el) {
    document.querySelectorAll('.section').forEach(function(s){ s.classList.remove('on'); });
    document.querySelectorAll('.tab').forEach(function(t){ t.classList.remove('on'); });
    document.getElementById('sec-' + key).classList.add('on');
    el.classList.add('on');
}

function openPreview(type, id, title) {
    document.getElementById('previewTitle').textContent = title;
    document.getElementById('previewFrame').src = 'api/compliance/preview.php?type=' + type + '&id=' + id;
    document.getElementById('previewOverlay').classList.add('open');
}
function closePreview() {
    document.getElementById('previewFrame').src = '';
    document.getElementById('previewOverlay').classList.remove('open');
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closePreview(); });

// Show correct tab from hash
var hash = window.location.hash.replace('#','');
if (hash === 'docs') document.querySelector('[onclick*="docs"]').click();
</script>
<?php Layout::end(); ?>
