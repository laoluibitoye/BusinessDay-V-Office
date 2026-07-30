<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// sop.php — Standard Operating Procedures
// All staff: view only | head_compliance: upload | head_it: full access
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$canUpload = in_array($user['role'], ['head_compliance','head_it','it_admin','md','bdm']);
$canDelete = in_array($user['role'], ['head_it','it_admin','md','bdm']);

define('SOP_UPLOAD_DIR', __DIR__ . '/uploads/sops/');

$SOP_CATEGORIES = ['General','HR','IT','Operations','Finance','Compliance','Training','Client Service','Admin'];

$success = '';
$error   = '';

// ── UPLOAD SOP ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'upload' && $canUpload) {
    $title   = trim($_POST['title'] ?? '');
    $desc    = trim($_POST['description'] ?? '');
    $cat     = $_POST['category'] ?? 'General';
    $version = trim($_POST['version'] ?? '1.0');

    if (!$title) {
        $error = 'Title is required.';
    } elseif (empty($_FILES['sop_file']['name']) || $_FILES['sop_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a file to upload.';
    } else {
        $allowed   = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
                      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $ext       = strtolower(pathinfo($_FILES['sop_file']['name'], PATHINFO_EXTENSION));
        $origName  = basename($_FILES['sop_file']['name']);

        if (!isset($allowed[$ext])) {
            $error = 'Only PDF, DOC, and DOCX files are allowed for SOPs.';
        } elseif ($_FILES['sop_file']['size'] > 20 * 1024 * 1024) {
            $error = 'File must be under 20MB.';
        } else {
            if (!is_dir(SOP_UPLOAD_DIR)) mkdir(SOP_UPLOAD_DIR, 0755, true);
            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            $filePath   = SOP_UPLOAD_DIR . $storedName;

            if (!move_uploaded_file($_FILES['sop_file']['tmp_name'], $filePath)) {
                $error = 'File upload failed. Check server upload permissions.';
            } else {
                $mime       = $allowed[$ext];
                $fileSize   = filesize($filePath);
                $supersedes = (int)($_POST['supersedes_id'] ?? 0);
                $db->prepare("INSERT INTO sop_documents (title, description, category, version, filename, stored_name, file_path, mime_type, file_size, uploaded_by, supersedes_id)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$title, $desc, $cat, $version, $origName, $storedName, $filePath, $mime, $fileSize, $user['id'], $supersedes ?: null]);
                $newSopId = (int)$db->lastInsertId();
                Auth::auditLog($user['id'], 'sop_uploaded', "SOP uploaded: $title (v$version)");
                if ($supersedes > 0) {
                    $db->prepare("UPDATE sop_documents SET is_active=0 WHERE id=?")->execute([$supersedes]);
                    Auth::auditLog($user['id'], 'sop_superseded', "SOP #$supersedes superseded by #$newSopId: $title v$version");
                    $success = "SOP \"$title\" uploaded and previous version deactivated.";
                } else {
                    $success = "SOP \"$title\" uploaded successfully.";
                }
            }
        }
    }
}

// ── DELETE SOP (head_it only) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete' && $canDelete) {
    $sopId = (int)($_POST['sop_id'] ?? 0);
    $row   = $db->prepare("SELECT * FROM sop_documents WHERE id = ?");
    $row->execute([$sopId]);
    $sop = $row->fetch();
    if ($sop) {
        if ($sop['file_path'] && file_exists($sop['file_path'])) {
            @unlink($sop['file_path']);
        }
        $db->prepare("DELETE FROM sop_documents WHERE id = ?")->execute([$sopId]);
        Auth::auditLog($user['id'], 'sop_deleted', "SOP deleted: {$sop['title']}");
        $success = "SOP \"{$sop['title']}\" deleted.";
    }
}

// ── TOGGLE ACTIVE (head_it only) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'toggle' && $canDelete) {
    $sopId = (int)($_POST['sop_id'] ?? 0);
    $db->prepare("UPDATE sop_documents SET is_active = NOT is_active WHERE id = ?")->execute([$sopId]);
    header('Location: sop.php');
    exit;
}

// ── FETCH SOPs ────────────────────────────────────────────────────────────
$filterCat = $_GET['cat'] ?? '';
$search    = trim($_GET['q'] ?? '');

$whereClause = 'WHERE s.is_active = 1';
$params = [];

if ($filterCat && in_array($filterCat, $SOP_CATEGORIES)) {
    $whereClause .= ' AND s.category = ?';
    $params[] = $filterCat;
}
if ($search) {
    $whereClause .= ' AND (s.title LIKE ? OR s.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $db->prepare("SELECT s.*, u.name as uploader_name, old.title as supersedes_title, old.version as supersedes_version FROM sop_documents s LEFT JOIN users u ON u.id=s.uploaded_by LEFT JOIN sop_documents old ON old.id=s.supersedes_id $whereClause ORDER BY s.category, s.title");
$stmt->execute($params);
$sops = $stmt->fetchAll();

// All SOPs for admin (including inactive)
$allSops = [];
if ($canDelete) {
    $allSops = $db->query("SELECT s.*, u.name as uploader_name, old.title as supersedes_title, old.version as supersedes_version FROM sop_documents s LEFT JOIN users u ON u.id=s.uploaded_by LEFT JOIN sop_documents old ON old.id=s.supersedes_id ORDER BY s.is_active DESC, s.category, s.title")->fetchAll();
}

// Map: which SOP has been superseded by which (for "Superseded by" display on inactive cards)
$supersededByMap = [];
$allForMap = $canDelete ? $allSops : $sops;
foreach ($allForMap as $s) {
    if (!empty($s['supersedes_id'])) {
        $supersededByMap[(int)$s['supersedes_id']] = ['id' => $s['id'], 'title' => $s['title'], 'version' => $s['version']];
    }
}

// Active SOPs for the "supersedes" dropdown in the upload form
$activeSopsForSelect = $db->query("SELECT id, title, version, category FROM sop_documents WHERE is_active=1 ORDER BY category, title")->fetchAll();

// Counts per category
$catCounts = [];
foreach ($sops as $s) {
    $catCounts[$s['category']] = ($catCounts[$s['category']] ?? 0) + 1;
}

function fmtSize($bytes) {
    if (!$bytes) return '';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1048576, 1) . ' MB';
}

Layout::shell($user, 'sop', 0, 'Standard Operating Procedures');
?>
<style>
/* SOP page uses a 2-column layout inside the Layout::shell main area */
.sop-page{display:grid;grid-template-columns:200px 1fr;gap:0;height:100%;}
.sop-sidebar{background:var(--w);border-right:1px solid var(--g200);padding:18px 0;}
.sidebar-title{font-size:10.5px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.06em;padding:0 16px;margin-bottom:8px;}
.cat-link{display:flex;align-items:center;justify-content:space-between;padding:7px 16px;font-size:13px;color:var(--g700);text-decoration:none;transition:background .1s;cursor:pointer;border:none;background:none;width:100%;text-align:left;font-family:'Inter',sans-serif;}
.cat-link:hover,.cat-link.on{background:#e8f0fb;color:var(--navy);font-weight:600;}
.cat-count{font-size:11px;background:var(--g100);color:var(--g500);padding:1px 7px;border-radius:20px;}
.cat-link.on .cat-count{background:var(--navy);color:#fff;}
.sidebar-divider{border:none;border-top:1px solid var(--g100);margin:10px 0;}
.sop-main{padding:22px 24px 40px;overflow-y:auto;}
.main-hd{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
.main-title{font-size:17px;font-weight:700;color:var(--navy);}
.search-box{flex:1;min-width:200px;max-width:320px;position:relative;}
.search-box input{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:7px 12px 7px 34px;font-size:13px;font-family:'Inter',sans-serif;outline:none;background:var(--g50);}
.search-box input:focus{border-color:var(--green);background:var(--w);}
.search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--g400);font-size:14px;}
.sop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;}
.sop-card{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:16px 18px;display:flex;flex-direction:column;gap:10px;transition:box-shadow .15s;}
.sop-card:hover{box-shadow:0 4px 16px rgba(0,40,80,.12);}
.sop-card.inactive{opacity:.5;}
.sop-icon{font-size:28px;flex-shrink:0;}
.sop-title{font-size:13.5px;font-weight:700;color:var(--g900);line-height:1.3;}
.sop-desc{font-size:12px;color:var(--g500);line-height:1.5;flex:1;}
.sop-meta{font-size:11px;color:var(--g400);display:flex;gap:10px;flex-wrap:wrap;}
.sop-acts{display:flex;gap:7px;align-items:center;margin-top:4px;}
.btn-view{padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;background:var(--navy);color:#fff;border:none;cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:5px;transition:background .15s;}
.btn-view:hover{background:#003a72;}
.btn-sm{padding:5px 10px;border-radius:6px;font-size:11.5px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;}
.btn-tog{background:var(--g100);color:var(--g500);border:1.5px solid var(--g200);}
.btn-del{background:#fee2e2;color:var(--red);border:1.5px solid #fca5a5;}
.pill{padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:600;display:inline-block;}
.pill-cat{background:#f3f0ff;color:#8b5cf6;}
.pill-ver{background:#dbeafe;color:#1e40af;}
.alert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:16px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.empty{padding:48px 24px;text-align:center;color:var(--g400);}
.empty-icon{font-size:40px;margin-bottom:10px;}
.upload-card{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:20px;margin-bottom:18px;border-top:3px solid var(--green);}
.upload-title{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:14px;}
.fg{margin-bottom:12px;}
label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
input[type=text],input[type=file],select,textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;}
input[type=text]:focus,select:focus,textarea:focus{border-color:var(--green);background:var(--w);}
input[type=file]{padding:6px 10px;cursor:pointer;}
textarea{resize:none;height:60px;}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.btn-upload{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;background:var(--green);color:#fff;border:none;cursor:pointer;font-family:'Inter',sans-serif;width:100%;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .15s;}
.btn-upload:hover{background:#4d7c10;}
.notice{background:#fef9c3;border:1px solid #fde047;border-radius:9px;padding:10px 14px;font-size:12.5px;color:#854d0e;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.sop-chain{font-size:11.5px;padding:4px 8px;border-radius:6px;margin-top:2px;}
.sop-chain-replaces{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.sop-chain-superseded{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;}
/* Preview modal */
#previewOverlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;align-items:center;justify-content:center;}
#previewOverlay.open{display:flex;}
#previewBox{background:var(--w);width:94vw;max-width:980px;height:90vh;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.4);}
#previewHeader{padding:12px 18px;border-bottom:1px solid var(--g200);display:flex;align-items:center;gap:12px;flex-shrink:0;}
#previewTitle{flex:1;font-size:14px;font-weight:700;color:var(--navy);}
#previewBadge{font-size:11px;color:var(--g500);background:var(--g100);padding:2px 9px;border-radius:20px;}
#previewNote{font-size:11.5px;color:var(--g500);margin-left:auto;}
#previewClose{border:none;background:none;font-size:22px;cursor:pointer;color:var(--g500);padding:2px 6px;}
#previewClose:hover{color:var(--g900);}
#previewFrame{flex:1;border:none;width:100%;}
@media(max-width:640px){
    .sop-page{grid-template-columns:1fr;min-height:auto;}
    .sop-sidebar{border-right:none;border-bottom:1px solid var(--g200);padding:8px 0;display:flex;flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;}
    .sidebar-title{display:none;}
    .sidebar-divider{display:none;}
    .cat-link{flex-shrink:0;white-space:nowrap;margin:2px 3px;border-radius:20px;}
    .sop-main{padding:14px 12px;}
    .sop-grid{grid-template-columns:1fr;}
    .row2{grid-template-columns:1fr;}
    .main-hd{flex-wrap:wrap;}
    .search-box{max-width:none;width:100%;}
}
/* mobile-all-pages */
@media(max-width:768px){
    .sop-grid{grid-template-columns:1fr;}
}
</style>

<div class="sop-page">
    <!-- Category sidebar -->
    <div class="sop-sidebar">
        <div class="sidebar-title">Categories</div>
        <a class="cat-link <?= !$filterCat ? 'on' : '' ?>" href="sop.php">
            All SOPs
            <span class="cat-count"><?= count($sops) ?></span>
        </a>
        <hr class="sidebar-divider"/>
        <?php foreach ($SOP_CATEGORIES as $cat): ?>
        <?php $cnt = $catCounts[$cat] ?? 0; if ($cnt === 0 && !$filterCat) continue; ?>
        <a class="cat-link <?= $filterCat===$cat?'on':'' ?>" href="sop.php?cat=<?= urlencode($cat) ?>">
            <?= htmlspecialchars($cat) ?>
            <?php if ($cnt): ?><span class="cat-count"><?= $cnt ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>

        <?php if ($canDelete): ?>
        <hr class="sidebar-divider"/>
        <div class="sidebar-title" style="margin-top:6px;">Admin</div>
        <a class="cat-link" href="sop.php?all=1" style="font-size:12px;">📂 Manage All SOPs</a>
        <?php endif; ?>
    </div>

    <!-- Main content -->
    <div class="sop-main">
        <?php if ($success): ?><div class="alert ok">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <!-- Upload form (head_compliance + head_it only) -->
        <?php if ($canUpload): ?>
        <div class="upload-card">
            <div class="upload-title">📤 Upload New SOP</div>
            <div class="notice">⚠️ SOPs are view-only for all staff. Upload only finalized, approved documents.</div>
            <form method="POST" enctype="multipart/form-data">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="act" value="upload"/>
                <div class="row2">
                    <div class="fg"><label>SOP Title *</label><input type="text" name="title" placeholder="e.g. Leave Application Procedure" required/></div>
                    <div class="row2">
                        <div class="fg"><label>Category</label>
                            <select name="category">
                                <?php foreach ($SOP_CATEGORIES as $c): ?>
                                <option value="<?= $c ?>"><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fg"><label>Version</label><input type="text" name="version" value="1.0" placeholder="1.0"/></div>
                    </div>
                </div>
                <div class="fg"><label>Description (optional)</label><textarea name="description" placeholder="Brief description of this SOP's purpose…"></textarea></div>
                <div class="fg"><label>File (PDF, DOC, DOCX — max 20MB) *</label><input type="file" name="sop_file" accept=".pdf,.doc,.docx" required/></div>
                <?php if (!empty($activeSopsForSelect)): ?>
                <div class="fg">
                    <label>Supersedes / Replaces (optional)</label>
                    <select name="supersedes_id">
                        <option value="">— This is a brand new SOP —</option>
                        <?php
                        $lastCat = '';
                        foreach ($activeSopsForSelect as $os):
                            if ($os['category'] !== $lastCat) {
                                if ($lastCat !== '') echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($os['category']) . '">';
                                $lastCat = $os['category'];
                            }
                        ?>
                        <option value="<?= $os['id'] ?>"><?= htmlspecialchars($os['title']) ?> (v<?= htmlspecialchars($os['version']) ?>)</option>
                        <?php endforeach; if ($lastCat !== '') echo '</optgroup>'; ?>
                    </select>
                    <div style="font-size:11px;color:var(--g400);margin-top:4px;">⚠ Selecting a previous version will deactivate it automatically.</div>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-upload">📤 Upload SOP</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- SOP list header -->
        <div class="main-hd">
            <div class="main-title">
                📖 <?= $filterCat ? htmlspecialchars($filterCat) . ' SOPs' : 'All SOPs' ?>
                <span style="font-size:13px;font-weight:400;color:var(--g400);margin-left:6px;">(<?= count($sops) ?>)</span>
            </div>
            <form class="search-box" method="GET">
                <?php if ($filterCat): ?><input type="hidden" name="cat" value="<?= htmlspecialchars($filterCat) ?>"/><?php endif; ?>
                <span class="search-icon">🔍</span>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search SOPs…"/>
            </form>
        </div>

        <!-- Admin: show all (including inactive) -->
        <?php
        $displaySops = (isset($_GET['all']) && $canDelete) ? $allSops : $sops;
        if (isset($_GET['all']) && $canDelete) {
            echo '<div class="notice" style="margin-bottom:14px;">👁 Admin view — showing all SOPs including inactive ones.</div>';
        }
        ?>

        <?php if (empty($displaySops)): ?>
        <div class="empty">
            <div class="empty-icon">📂</div>
            <?php if ($search): ?>
            <p>No SOPs found for "<strong><?= htmlspecialchars($search) ?></strong>"</p>
            <p style="margin-top:8px;"><a href="sop.php<?= $filterCat?'?cat='.urlencode($filterCat):'' ?>" style="color:var(--green);">Clear search</a></p>
            <?php elseif ($filterCat): ?>
            <p>No SOPs in the <strong><?= htmlspecialchars($filterCat) ?></strong> category yet.</p>
            <?php else: ?>
            <p>No SOPs have been uploaded yet.</p>
            <?php if ($canUpload): ?><p style="margin-top:8px;color:var(--g500);">Use the upload form above to add the first SOP.</p><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="sop-grid">
            <?php foreach ($displaySops as $s):
                $iconMap = ['HR'=>'👥','IT'=>'💻','Operations'=>'⚙️','Finance'=>'💰','Compliance'=>'📋',
                            'Training'=>'🎓','Client Service'=>'📞','Admin'=>'🏢','General'=>'📄'];
                $icon = $iconMap[$s['category']] ?? '📄';
            ?>
            <div class="sop-card <?= $s['is_active'] ? '' : 'inactive' ?>">
                <div style="display:flex;align-items:flex-start;gap:10px;">
                    <div class="sop-icon"><?= $icon ?></div>
                    <div style="flex:1;">
                        <div class="sop-title"><?= htmlspecialchars($s['title']) ?></div>
                        <div style="display:flex;gap:5px;margin-top:5px;flex-wrap:wrap;">
                            <span class="pill pill-cat"><?= htmlspecialchars($s['category']) ?></span>
                            <span class="pill pill-ver">v<?= htmlspecialchars($s['version']) ?></span>
                            <?php if (!$s['is_active']): ?><span class="pill" style="background:#fee2e2;color:var(--red);">Inactive</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($s['description']): ?>
                <div class="sop-desc"><?= htmlspecialchars($s['description']) ?></div>
                <?php endif; ?>
                <div class="sop-meta">
                    <span>📅 <?= date('d M Y', strtotime($s['created_at'])) ?></span>
                    <?php if ($s['uploader_name']): ?><span>👤 <?= htmlspecialchars($s['uploader_name']) ?></span><?php endif; ?>
                    <?php if ($s['file_size']): ?><span>📦 <?= fmtSize($s['file_size']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($s['supersedes_title'])): ?>
                <div class="sop-chain sop-chain-replaces">↩ Replaces: <strong><?= htmlspecialchars($s['supersedes_title']) ?></strong> v<?= htmlspecialchars($s['supersedes_version']) ?></div>
                <?php endif; ?>
                <?php if (isset($supersededByMap[(int)$s['id']])): $by = $supersededByMap[(int)$s['id']]; ?>
                <div class="sop-chain sop-chain-superseded">⚠ Superseded by: <strong><?= htmlspecialchars($by['title']) ?></strong> v<?= htmlspecialchars($by['version']) ?></div>
                <?php endif; ?>
                <div class="sop-acts">
                    <button class="btn-view" onclick="openPreview(<?= $s['id'] ?>, <?= htmlspecialchars(json_encode($s['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode('v'.$s['version']), ENT_QUOTES) ?>)">
                        👁 View SOP
                    </button>
                    <?php if ($canDelete): ?>
                    <form method="POST" style="display:inline;">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="act" value="toggle"/>
                        <input type="hidden" name="sop_id" value="<?= $s['id'] ?>"/>
                        <button type="submit" class="btn-sm btn-tog"><?= $s['is_active'] ? 'Hide' : 'Show' ?></button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this SOP? This cannot be undone.')">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="act" value="delete"/>
                        <input type="hidden" name="sop_id" value="<?= $s['id'] ?>"/>
                        <button type="submit" class="btn-sm btn-del">🗑</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Preview Modal (view-only, no download button in UI) -->
<div id="previewOverlay" onclick="if(event.target===this)closePreview()">
    <div id="previewBox">
        <div id="previewHeader">
            <span id="previewTitle"></span>
            <span id="previewBadge"></span>
            <span id="previewNote">👁 View only — not available for download</span>
            <button id="previewClose" onclick="closePreview()">✕</button>
        </div>
        <iframe id="previewFrame" src="" referrerpolicy="same-origin"></iframe>
    </div>
</div>

<script>
function openPreview(id, title, version) {
    document.getElementById('previewTitle').textContent = title;
    document.getElementById('previewBadge').textContent = version;
    document.getElementById('previewFrame').src = 'api/sop/preview.php?id=' + id;
    document.getElementById('previewOverlay').classList.add('open');
}
function closePreview() {
    document.getElementById('previewFrame').src = '';
    document.getElementById('previewOverlay').classList.remove('open');
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePreview(); });

// Disable right-click inside preview frame (best-effort)
document.getElementById('previewFrame').addEventListener('load', function() {
    try {
        this.contentDocument.addEventListener('contextmenu', function(e){ e.preventDefault(); });
    } catch(e) {}
});
</script>
<?php Layout::end(); ?>
