<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';
$user = Auth::require();
$db   = getDB();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    $category = $_POST['category'] ?? 'general';
    if (!empty($_FILES['vault_file']['name']) && $_FILES['vault_file']['error'] === 0) {
        $uploadDir = __DIR__ . '/uploads/vault/' . $user['id'] . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $origName = basename($_FILES['vault_file']['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        // Server-side MIME detection — never trust browser-supplied type
        $detectedMime = mime_content_type($_FILES['vault_file']['tmp_name']) ?: 'application/octet-stream';
        if (!in_array($detectedMime, ALLOWED_ATTACHMENT_TYPES)) {
            $error = 'File type not allowed. Permitted types: PDF, Word, Excel, images, text, CSV, ZIP.';
        } elseif (in_array($ext, ['php','php3','php4','php5','phtml','phar','exe','sh','py','cgi'])) {
            $error = 'This file extension is not permitted.';
        } else {
        $safeName = uniqid('vault_') . '.' . $ext;
        $filePath = 'uploads/vault/' . $user['id'] . '/' . $safeName;
        if (move_uploaded_file($_FILES['vault_file']['tmp_name'], __DIR__ . '/' . $filePath)) {
            $db->prepare("INSERT INTO vault_files (user_id,filename,stored_name,file_path,file_size,mime_type,category,source) VALUES (?,?,?,?,?,?,?,'upload')")
               ->execute([$user['id'],$origName,$safeName,$filePath,$_FILES['vault_file']['size'],$detectedMime,$category]);
            Auth::trackUsage($user['id'],'vault_uploads');
            Auth::auditLog($user['id'],'vault_upload',"Uploaded: $origName");
            $success = htmlspecialchars($origName) . " uploaded successfully.";
        } else {
            $error = 'Upload failed. Ensure the uploads/vault/ folder exists and is writable.';
        }
        } // end MIME check else
    } else {
        $error = 'Please select a file to upload.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
    $fid = (int)($_POST['file_id'] ?? 0);
    $fs = $db->prepare("SELECT * FROM vault_files WHERE id=? AND user_id=?");
    $fs->execute([$fid, $user['id']]);
    $file = $fs->fetch();
    if ($file) {
        $fp = __DIR__ . '/' . ltrim($file['file_path'], '/');
        if (file_exists($fp)) unlink($fp);
        $db->prepare("DELETE FROM vault_files WHERE id=?")->execute([$fid]);
        Auth::auditLog($user['id'],'vault_delete',"Deleted: {$file['filename']}");
        $success = 'File deleted.';
    }
}

$catFilter = $_GET['cat'] ?? 'all';
$search    = trim($_GET['q'] ?? '');
$where  = "user_id = ?";
$params = [$user['id']];
if ($catFilter !== 'all') { $where .= " AND category = ?"; $params[] = $catFilter; }
if ($search) { $where .= " AND filename LIKE ?"; $params[] = "%" . $search . "%"; }

$fs2 = $db->prepare("SELECT * FROM vault_files WHERE $where ORDER BY created_at DESC");
$fs2->execute($params);
$files = $fs2->fetchAll();

$ccStmt = $db->prepare("SELECT category, COUNT(*) as cnt FROM vault_files WHERE user_id=? GROUP BY category");
$ccStmt->execute([$user['id']]);
$catCounts = [];
foreach ($ccStmt->fetchAll() as $row) $catCounts[$row['category']] = $row['cnt'];

$tcStmt = $db->prepare("SELECT COUNT(*) FROM vault_files WHERE user_id=?");
$tcStmt->execute([$user['id']]);
$totalCount = $tcStmt->fetchColumn();

// Categories - NO emoji in PHP array keys/values to avoid encoding issues
$cats = [
    'all'        => 'All Files',
    'compliance' => 'Compliance',
    'finance'    => 'Finance',
    'hr'         => 'HR',
    'it'         => 'IT',
    'client'     => 'Client',
    'payslip'    => 'Payslips',
    'signed'     => 'Signed Docs',
    'general'    => 'General',
];

$catIcons = [
    'all'        => '&#128193;',
    'compliance' => '&#128737;',
    'finance'    => '&#128176;',
    'hr'         => '&#128105;',
    'it'         => '&#128187;',
    'client'     => '&#129309;',
    'payslip'    => '&#128181;',
    'signed'     => '&#9997;',
    'general'    => '&#128196;',
];

function fmtSize($b) {
    if (!$b) return '—';
    if ($b < 1024) return $b . 'B';
    if ($b < 1048576) return round($b/1024,1) . 'KB';
    return round($b/1048576,1) . 'MB';
}

function fileTypeIcon($mime) {
    $m = strtolower($mime ?? '');
    if (str_contains($m,'pdf'))   return '&#128196;';
    if (str_contains($m,'word') || str_contains($m,'docx')) return '&#128221;';
    if (str_contains($m,'sheet') || str_contains($m,'xlsx')) return '&#128202;';
    if (str_contains($m,'image')) return '&#128444;';
    return '&#128206;';
}

Layout::shell($user, 'vault', 0, 'Document Vault');
?>
<style>
.layout{display:grid;grid-template-columns:190px 1fr;height:100%;min-height:0;}
.sb{background:#fff;border-right:1px solid #e2e8f0;padding:12px 0;overflow-y:auto;}
.sbi{display:flex;align-items:center;padding:7px 14px;gap:8px;text-decoration:none;color:#334155;font-size:12.5px;border-left:3px solid transparent;}
.sbi:hover{background:#f8fafc;}
.sbi.on{background:#e8f0fb;color:var(--navy);font-weight:600;border-left-color:var(--navy);}
.sbc{margin-left:auto;font-size:10.5px;color:#94a3b8;}
.main{overflow-y:auto;padding:22px;}
.top-row{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
.ptitle{font-size:17px;font-weight:700;color:var(--navy);flex:1;}
.srch{border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:13px;outline:none;color:#0f172a;width:200px;}
.ubtn{padding:8px 16px;border-radius:8px;background:var(--green);color:#fff;border:none;font-size:13px;font-weight:600;cursor:pointer;}
.alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;}
.alert-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert-er{background:#fee2e2;border:1px solid #fca5a5;color:#dc2626;}
.fgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:12px;}
.fc{background:#fff;border-radius:11px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.08);display:flex;flex-direction:column;gap:7px;border:1.5px solid transparent;transition:border-color .15s;}
.fc:hover{border-color:var(--navy);}
.fico{font-size:28px;}
.fn{font-size:12.5px;font-weight:600;color:#0f172a;word-break:break-word;line-height:1.4;}
.fm{font-size:11px;color:#94a3b8;}
.cp{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:600;background:#e8f0fb;color:var(--navy);align-self:flex-start;}
.fa{display:flex;gap:5px;flex-wrap:wrap;margin-top:2px;}
.fact{padding:4px 9px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;border:1px solid #e2e8f0;background:transparent;color:#334155;text-decoration:none;display:inline-block;}
.fact:hover{border-color:var(--navy);color:var(--navy);}
.fact-gn{background:var(--green);color:#fff;border-color:var(--green);}
.fact-gn:hover{background:#4d7c10;}
.fact-rd:hover{border-color:#dc2626;color:#dc2626;}
.empty{padding:48px;text-align:center;color:#94a3b8;font-size:13px;}
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-bg.open{display:flex;}
.modal{background:#fff;border-radius:14px;width:100%;max-width:420px;overflow:hidden;box-shadow:0 8px 32px rgba(0,40,80,.14);}
.modal-hd{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;font-size:14px;font-weight:700;color:var(--navy);}
.modal-cl{cursor:pointer;color:#94a3b8;font-size:18px;background:none;border:none;}
.modal-bd{padding:20px;}
.modal-ft{padding:14px 20px;border-top:1px solid #f1f5f9;display:flex;gap:8px;justify-content:flex-end;}
.btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;}
.btn-gn{background:var(--green);color:#fff;}
.btn-ol{background:transparent;border:1.5px solid #e2e8f0;color:#334155;}
label{display:block;font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
input[type=file]{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:13px;color:#0f172a;outline:none;background:#f8fafc;margin-bottom:13px;}
select{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:13px;color:#0f172a;outline:none;background:#f8fafc;font-family:Arial,sans-serif;}
.preview-bg{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:600;display:none;align-items:center;justify-content:center;flex-direction:column;gap:12px;padding:20px;}
.preview-bg.open{display:flex;}
.preview-close{position:fixed;top:16px;right:20px;color:#fff;font-size:28px;cursor:pointer;z-index:700;background:none;border:none;}
.preview-frame{width:100%;max-width:900px;height:80vh;border:none;border-radius:10px;background:#fff;}
.preview-img{max-width:90vw;max-height:80vh;border-radius:10px;object-fit:contain;}
.preview-name{color:rgba(255,255,255,.7);font-size:13px;}
@media(max-width:640px){
    .layout{grid-template-columns:1fr;}
    .sb{display:flex;flex-wrap:nowrap;overflow-x:auto;border-right:none;border-bottom:1px solid #e2e8f0;padding:6px 12px;gap:4px;-webkit-overflow-scrolling:touch;}
    .sbi{flex-shrink:0;border-left:none;border-radius:20px;padding:5px 12px;white-space:nowrap;}
    .sbi.on{border-left:none;}
    .main{padding:14px;}
    .fgrid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr));}
    .top-row{flex-wrap:wrap;}
    .srch{width:100%;}
}
/* mobile-all-pages */
@media(max-width:768px){
    .fgrid{grid-template-columns:repeat(2,1fr);}
}
</style>
<div class="layout">
    <nav class="sb">
        <?php foreach ($cats as $k => $lbl): ?>
        <a class="sbi <?= $catFilter === $k ? 'on' : '' ?>" href="vault.php?cat=<?= $k ?>">
            <span><?= $catIcons[$k] ?? '' ?></span>
            <span style="flex:1;"><?= $lbl ?></span>
            <?php if ($k === 'all'): ?>
            <span class="sbc"><?= $totalCount ?></span>
            <?php elseif (isset($catCounts[$k])): ?>
            <span class="sbc"><?= $catCounts[$k] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <main class="main">
        <?php if ($success): ?><div class="alert alert-ok">&#10003; <?= $success ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-er">&#9888; <?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="top-row">
            <div class="ptitle"><?= $catIcons[$catFilter] ?? '' ?> <?= htmlspecialchars($cats[$catFilter] ?? 'Files') ?>
                <span style="font-size:13px;font-weight:400;color:#94a3b8;margin-left:6px;"><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span>
            </div>
            <form method="GET" style="display:flex;gap:7px;">
                <input type="hidden" name="cat" value="<?= htmlspecialchars($catFilter) ?>"/>
                <input class="srch" type="text" name="q" placeholder="Search files..." value="<?= htmlspecialchars($search) ?>"/>
                <button type="submit" style="padding:8px 14px;border-radius:8px;background:var(--navy);color:#fff;border:none;font-size:13px;cursor:pointer;">Go</button>
            </form>
            <button class="ubtn" onclick="document.getElementById('uploadModal').classList.add('open')">+ Upload File</button>
        </div>

        <?php if (empty($files)): ?>
        <div class="empty">No files here yet.<br/><span style="font-size:12px;display:block;margin-top:8px;">Upload a file or save an email attachment.</span></div>
        <?php else: ?>
        <div class="fgrid">
            <?php foreach ($files as $f):
                $fullPath  = __DIR__ . '/' . ltrim($f['file_path'], '/');
                $fileExists= file_exists($fullPath);
                $serveUrl  = APP_URL . '/vault-serve.php?id=' . $f['id'];
                $mime      = strtolower($f['mime_type'] ?? '');
                $previewable = $fileExists && (str_contains($mime, 'pdf') || str_contains($mime, 'image'));
                $fname     = $f['filename'];
                $display   = strlen($fname) > 32 ? substr($fname, 0, 30) . '...' : $fname;
            ?>
            <div class="fc">
                <div class="fico"><?= fileTypeIcon($f['mime_type']) ?></div>
                <div class="fn" title="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($display) ?></div>
                <div class="fm"><?= fmtSize($f['file_size']) ?> &middot; <?= date('d M Y', strtotime($f['created_at'])) ?></div>
                <span class="cp"><?= htmlspecialchars(ucfirst($f['category'])) ?></span>
                <div class="fa">
                    <?php if ($previewable): ?>
                    <button class="fact fact-gn" onclick="previewFile('<?= $serveUrl ?>','<?= htmlspecialchars(addslashes($fname)) ?>','<?= $mime ?>')">View</button>
                    <?php endif; ?>
                    <?php if ($fileExists): ?>
                    <a class="fact" href="<?= $serveUrl ?>&dl=1">Download</a>
                    <?php else: ?>
                    <span style="font-size:11px;color:#94a3b8;">File missing</span>
                    <?php endif; ?>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="file_id" value="<?= $f['id'] ?>"/>
                        <?= Auth::csrfField() ?>
                        <button type="submit" name="delete_file" class="fact fact-rd" onclick="return confirm('Delete this file?')">Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- UPLOAD MODAL -->
<div class="modal-bg" id="uploadModal">
    <div class="modal">
        <div class="modal-hd">Upload File to Vault
            <button class="modal-cl" onclick="document.getElementById('uploadModal').classList.remove('open')">x</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?= Auth::csrfField() ?>
            <div class="modal-bd">
                <label>Select File *</label>
                <input type="file" name="vault_file" required/>
                <label>Category</label>
                <select name="category">
                    <?php foreach ($cats as $k => $lbl): if ($k === 'all') continue; ?>
                    <option value="<?= $k ?>"><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:12px;color:#94a3b8;margin-top:10px;">PDFs and images can be previewed inline after upload.</div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ol" onclick="document.getElementById('uploadModal').classList.remove('open')">Cancel</button>
                <button type="submit" name="upload_file" class="btn btn-gn">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- PREVIEW MODAL -->
<div class="preview-bg" id="previewModal">
    <button class="preview-close" onclick="closePreview()">x</button>
    <div class="preview-name" id="previewName"></div>
    <iframe class="preview-frame" id="previewFrame" style="display:none;"></iframe>
    <img class="preview-img" id="previewImg" style="display:none;" alt="Preview"/>
</div>

<script>
function previewFile(url, name, mime) {
    document.getElementById('previewName').textContent = name;
    var frame = document.getElementById('previewFrame');
    var img   = document.getElementById('previewImg');
    if (mime.indexOf('image') !== -1) {
        img.src = url; img.style.display = 'block'; frame.style.display = 'none';
    } else {
        frame.src = url; frame.style.display = 'block'; img.style.display = 'none';
    }
    document.getElementById('previewModal').classList.add('open');
}
function closePreview() {
    document.getElementById('previewModal').classList.remove('open');
    document.getElementById('previewFrame').src = '';
    document.getElementById('previewImg').src = '';
}
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});
document.getElementById('uploadModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePreview(); });
</script>
<?php Layout::end(); ?>
