<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$success = '';
$error   = '';

// ── UPLOAD SIGNATURE IMAGE ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_img'])) {
    if (!empty($_FILES['sig_image']['tmp_name']) && $_FILES['sig_image']['error'] === 0) {
        $dir = __DIR__ . '/uploads/signatures/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext     = strtolower(pathinfo($_FILES['sig_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','svg','webp'];
        if (!in_array($ext, $allowed)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'err' => 'Only JPG, PNG, GIF, SVG, WEBP allowed.']);
            exit;
        }
        $fname = 'sig_' . $user['id'] . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['sig_image']['tmp_name'], $dir . $fname)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'url' => APP_URL . '/uploads/signatures/' . $fname]);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'err' => 'Upload failed']);
        exit;
    }
}

// ── SAVE SIGNATURE ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sig'])) {
    $sigHtml  = $_POST['signature_html'] ?? '';
    $isActive = 1; // always enabled — is_default=1 means "use in compose"

    $allowedTags = ['p','br','b','i','u','strong','em','span','div','a','img','ul','ol','li','h1','h2','h3','h4','table','tr','td','th','tbody','thead','hr','font'];
    $sigHtml = strip_tags($sigHtml, $allowedTags);
    $sigHtml = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $sigHtml);
    $sigHtml = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', '', $sigHtml);

    try {
        // Check if user already has a row (UNIQUE constraint on user_id)
        $chk = $db->prepare("SELECT id FROM user_signatures WHERE user_id=?");
        $chk->execute([$user['id']]);
        $existId = $chk->fetchColumn();

        if ($existId) {
            $db->prepare("UPDATE user_signatures SET html=?, is_default=? WHERE user_id=?")
               ->execute([$sigHtml, $isActive, $user['id']]);
        } else {
            $db->prepare("INSERT INTO user_signatures (user_id, name, html, is_default) VALUES (?,?,?,?)")
               ->execute([$user['id'], 'My Signature', $sigHtml, $isActive]);
        }

        Auth::auditLog($user['id'], 'signature_updated', 'Email signature updated');
        header('Location: signature.php?ok=1');
        exit;
    } catch (Exception $e) {
        $error = 'Could not save: ' . $e->getMessage();
    }
}

// ── DELETE SIGNATURE ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sig'])) {
    try {
        $db->prepare("DELETE FROM user_signatures WHERE user_id=?")->execute([$user['id']]);
        Auth::auditLog($user['id'], 'signature_deleted', 'Signature cleared');
        header('Location: signature.php?ok=3');
        exit;
    } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
}

// ── SUCCESS FROM REDIRECT ───────────────────────────────────
$okCode = (int)($_GET['ok'] ?? 0);
if ($okCode === 1) $success = 'Signature saved successfully.';
if ($okCode === 3) $success = 'Signature cleared.';

// ── LOAD USER SIGNATURE ─────────────────────────────────────
$existing = null;
try {
    $s = $db->prepare("SELECT id, html, is_default FROM user_signatures WHERE user_id=?");
    $s->execute([$user['id']]);
    $existing = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── SYSTEM DEFAULT LOAD/SAVE ───────────────────────────────
$sysDefault = '';
try {
    $sd = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_signature'");
    $sd->execute();
    $sysDefault = $sd->fetchColumn() ?: '';
} catch (Exception $e) {}

$role    = ROLES[$user['role']];
$isAdmin = in_array($user['role'], ['head_it','it_admin','md','bdm']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_default']) && $isAdmin) {
    $defSig = $_POST['default_signature'] ?? '';
    $defSig = strip_tags($defSig, ['p','br','b','i','u','strong','em','span','div','a','img','table','tr','td','th','hr','font']);
    $defSig = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $defSig);
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('default_signature',?)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()")
           ->execute([$defSig]);
        $sysDefault = $defSig;
        $success = 'System default signature saved.';
    } catch (Exception $e) { $error = 'Could not save default: ' . $e->getMessage(); }
}

// ── PRESET SIGNATURE HTML ─────────────────────────────────
$_logo = APP_URL . '/hri-logo.png';
$_em   = htmlspecialchars($user['email']);
$_rl   = htmlspecialchars($role['label']);
$_nm   = htmlspecialchars($user['name']);

$defaultSig =
    '<table cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif;border-collapse:collapse;max-width:560px;">'
  . '<tr><td style="background:#002850;padding:14px 20px;border-radius:10px 10px 0 0;">'
  . '<table cellpadding="0" cellspacing="0" style="width:100%;"><tr>'
  . '<td><img src="' . $_logo . '" alt="HR Indexx Limited" style="height:44px;width:auto;display:block;"/></td>'
  . '<td style="text-align:right;vertical-align:middle;font-size:11px;color:rgba(255,255,255,0.55);">People. Performance. Purpose.</td>'
  . '</tr></table></td></tr>'
  . '<tr><td style="background:#ffffff;padding:16px 20px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
  . '<div style="font-size:16px;font-weight:700;color:#002850;margin-bottom:2px;">' . $_nm . '</div>'
  . '<div style="font-size:12px;color:#64A014;font-weight:600;margin-bottom:8px;">' . $_rl . ' &mdash; HR Indexx Limited</div>'
  . '<table cellpadding="2" cellspacing="0" style="font-size:12px;color:#64748b;">'
  . '<tr><td style="padding-right:8px;">&#128205;</td><td>12 Macarthy Street, Onikan, Lagos Island</td></tr>'
  . '<tr><td>&#128231;</td><td><a href="mailto:' . $_em . '" style="color:#002850;text-decoration:none;">' . $_em . '</a></td></tr>'
  . '<tr><td>&#127760;</td><td><a href="https://hrindexx.com" style="color:#64A014;text-decoration:none;">www.hrindexx.com</a></td></tr>'
  . '</table></td></tr>'
  . '<tr><td style="background:#f8fafc;padding:10px 20px;border:1px solid #e2e8f0;border-top:3px solid #64A014;border-radius:0 0 10px 10px;">'
  . '<div style="font-size:9.5px;color:#94a3b8;line-height:1.6;">'
  . '<strong style="color:#64748b;">CONFIDENTIALITY &amp; DATA PROTECTION NOTICE:</strong> '
  . 'This email and any attachments are confidential and intended solely for the use of the named addressee(s). '
  . 'If you have received this email in error, please notify the sender immediately and permanently delete all copies. '
  . 'Unauthorised use, disclosure, or copying is strictly prohibited.<br/>'
  . 'HR Indexx Limited (RC&nbsp;446051) processes personal data in accordance with the '
  . '<strong>Nigeria Data Protection Act 2023 (NDPA 2023)</strong> and holds NDPC Certificate No.&nbsp;NDPC/DCP/12819. '
  . 'Data protection enquiries: <a href="mailto:ooloritun@hrindexx.com" style="color:#94a3b8;">ooloritun@hrindexx.com</a>.'
  . '</div></td></tr></table>';

$sysSigTemplate =
    '<table cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif;border-collapse:collapse;max-width:560px;">'
  . '<tr><td style="background:#002850;padding:14px 20px;border-radius:10px 10px 0 0;">'
  . '<table cellpadding="0" cellspacing="0" style="width:100%;"><tr>'
  . '<td><img src="' . $_logo . '" alt="HR Indexx Limited" style="height:44px;width:auto;display:block;"/></td>'
  . '<td style="text-align:right;vertical-align:middle;font-size:11px;color:rgba(255,255,255,0.55);">People. Performance. Purpose.</td>'
  . '</tr></table></td></tr>'
  . '<tr><td style="background:#ffffff;padding:16px 20px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
  . '<div style="font-size:16px;font-weight:700;color:#002850;margin-bottom:2px;">{name}</div>'
  . '<div style="font-size:12px;color:#64A014;font-weight:600;margin-bottom:8px;">{role} &mdash; HR Indexx Limited</div>'
  . '<table cellpadding="2" cellspacing="0" style="font-size:12px;color:#64748b;">'
  . '<tr><td style="padding-right:8px;">&#128205;</td><td>12 Macarthy Street, Onikan, Lagos Island</td></tr>'
  . '<tr><td>&#128231;</td><td><a href="mailto:{email}" style="color:#002850;text-decoration:none;">{email}</a></td></tr>'
  . '<tr><td>&#127760;</td><td><a href="https://hrindexx.com" style="color:#64A014;text-decoration:none;">www.hrindexx.com</a></td></tr>'
  . '</table></td></tr>'
  . '<tr><td style="background:#f8fafc;padding:10px 20px;border:1px solid #e2e8f0;border-top:3px solid #64A014;border-radius:0 0 10px 10px;">'
  . '<div style="font-size:9.5px;color:#94a3b8;line-height:1.6;">'
  . '<strong style="color:#64748b;">CONFIDENTIALITY &amp; DATA PROTECTION NOTICE:</strong> '
  . 'This email and any attachments are confidential and intended solely for the use of the named addressee(s). '
  . 'If you have received this email in error, please notify the sender immediately and permanently delete all copies. '
  . 'Unauthorised use, disclosure, or copying is strictly prohibited.<br/>'
  . 'HR Indexx Limited (RC&nbsp;446051) processes personal data in accordance with the '
  . '<strong>Nigeria Data Protection Act 2023 (NDPA 2023)</strong> and holds NDPC Certificate No.&nbsp;NDPC/DCP/12819. '
  . 'Data protection enquiries: <a href="mailto:ooloritun@hrindexx.com" style="color:#94a3b8;">ooloritun@hrindexx.com</a>.'
  . '</div></td></tr></table>';

$currentSig = $existing ? $existing['html'] : ($sysDefault ?: $defaultSig);

Layout::shell($user, 'signature', 0, 'Email Signature');
?>
<div class="hri-page">
<style>
.sgpage{width:100%;max-width:1600px;margin:0 auto;padding:0 0 40px;}
.tabs{display:flex;gap:6px;margin-bottom:18px;}
.tab{padding:7px 16px;border-radius:8px;border:1.5px solid var(--g200);background:var(--w);color:var(--g700);font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;font-family:'Inter',sans-serif;}
.tab.on{background:var(--navy);color:#fff;border-color:var(--navy);}
.card{background:var(--w);border-radius:14px;box-shadow:var(--sh);overflow:hidden;margin-bottom:16px;}
.chd{padding:13px 18px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.cht{font-size:13.5px;font-weight:700;color:var(--navy);}
.chsub{font-size:12px;color:var(--g400);}
.alert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:16px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.tgl-row{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid var(--g50);}
.tgl-info .tl{font-size:13.5px;font-weight:500;color:var(--g900);}
.tgl-info .ts{font-size:12px;color:var(--g400);margin-top:2px;}
.tgl{width:42px;height:23px;border-radius:99px;background:#cbd5e1;cursor:pointer;position:relative;transition:background .2s;flex-shrink:0;}
.tgl.on{background:var(--green);}
.tgl::after{content:'';position:absolute;width:17px;height:17px;border-radius:50%;background:#fff;top:3px;left:3px;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);}
.tgl.on::after{transform:translateX(19px);}
.ed-wrap{border:1.5px solid var(--g200);border-radius:10px;overflow:hidden;margin-bottom:12px;}
.ed-wrap:focus-within{border-color:var(--navy);}
.ed-tb{background:var(--g50);padding:6px 10px;border-bottom:1px solid var(--g200);display:flex;gap:4px;flex-wrap:wrap;align-items:center;}
.etb{padding:4px 8px;border-radius:5px;border:1px solid var(--g200);background:var(--w);color:var(--g700);font-size:12px;cursor:pointer;font-family:'Inter',sans-serif;transition:all .1s;}
.etb:hover{background:var(--nl);color:var(--navy);}
.tsep{width:1px;height:18px;background:var(--g200);margin:0 2px;}
.ed-body{min-height:160px;padding:14px 16px;font-size:14px;outline:none;line-height:1.7;color:var(--g700);}
.presets{display:flex;gap:6px;flex-wrap:wrap;padding:10px 18px;border-bottom:1px solid var(--g50);}
.preset{padding:5px 13px;border-radius:6px;border:1.5px solid var(--g200);background:var(--w);color:var(--g700);font-size:12px;cursor:pointer;font-family:'Inter',sans-serif;transition:all .12s;}
.preset:hover{border-color:var(--navy);color:var(--navy);background:var(--nl);}
.preview-box{background:var(--g50);border:1.5px solid var(--g200);border-radius:10px;padding:16px;margin-bottom:14px;}
.preview-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g400);margin-bottom:10px;}
.preview-sig{border-top:1px solid var(--g200);padding-top:12px;}
.btn-row{display:flex;gap:8px;padding:14px 18px;border-top:1px solid var(--g100);flex-wrap:wrap;align-items:center;}
.btn{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.btn.gn{background:var(--green);color:#fff;}
.btn.gn:hover{background:var(--gd);}
.btn.ol{background:transparent;border:1.5px solid var(--g200);color:var(--g700);}
.btn.ol:hover{border-color:var(--navy);color:var(--navy);}
.btn.rd{background:transparent;border:1.5px solid var(--g200);color:var(--red);}
.btn.rd:hover{border-color:var(--red);}
.btn.nv{background:var(--navy);color:#fff;}
.img-panel{background:var(--g50);border:1.5px dashed var(--g200);border-radius:8px;padding:14px;margin-bottom:12px;}
.img-panel-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g400);margin-bottom:10px;}
.img-grid{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;}
.img-upload-btn{padding:7px 13px;border-radius:7px;border:1.5px dashed var(--g200);background:var(--w);color:var(--g700);font-size:12px;cursor:pointer;font-family:'Inter',sans-serif;transition:all .12s;display:inline-flex;align-items:center;gap:5px;}
.img-upload-btn:hover{border-color:var(--green);color:var(--green);}
.uploaded-img{width:80px;height:60px;object-fit:contain;border-radius:6px;border:1.5px solid var(--g200);background:#fff;cursor:pointer;padding:4px;}
.colors{display:flex;gap:4px;align-items:center;}
.csw{width:18px;height:18px;border-radius:50%;cursor:pointer;border:2px solid transparent;flex-shrink:0;transition:border-color .12s;}
.csw:hover{border-color:#fff;outline:1.5px solid #94a3b8;}
select.etb{padding:3px 6px;}
.admin-badge{background:#8b5cf6;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;}
/* mobile-all-pages */
@media(max-width:768px){
    .sgpage [style*='width:480px'],.sgpage [style*='width:220px']{width:100%!important;}
}
</style>

<div class="sgpage">
    <?php if ($success): ?><div class="alert ok">&#10003; <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert er">&#9888; <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="tabs">
        <button class="tab on" id="tabMy" onclick="switchTab('my')">&#9999; My Signature</button>
        <?php if ($isAdmin): ?>
        <button class="tab" id="tabDefault" onclick="switchTab('default')">&#128737; System Default <span class="admin-badge">Admin</span></button>
        <?php endif; ?>
    </div>

    <!-- MY SIGNATURE -->
    <div id="paneMy">
        <form method="POST" id="sigForm">
            <?= Auth::csrfField() ?>
            <div class="card">
                <div class="chd">
                    <div class="cht">&#9999; My Email Signature</div>
                    <div class="chsub"><?= htmlspecialchars($user['name']) ?> &mdash; <?= htmlspecialchars($role['label']) ?></div>
                </div>

                <!-- Presets -->
                <div class="presets">
                    <span style="font-size:11px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.06em;align-self:center;">Presets:</span>
                    <button type="button" class="preset" onclick="applyPreset('professional')">Professional</button>
                    <button type="button" class="preset" onclick="applyPreset('modern')">Modern</button>
                    <button type="button" class="preset" onclick="applyPreset('executive')">Executive</button>
                    <?php if ($sysDefault): ?>
                    <button type="button" class="preset" onclick="applyPreset('system')">&#9733; Company Default</button>
                    <?php endif; ?>
                    <button type="button" class="preset" style="color:var(--red);" onclick="clearSig()">Clear</button>
                </div>

                <!-- Editor -->
                <div style="padding:14px 18px 0;">
                    <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);margin-bottom:8px;">Signature Editor</div>
                    <div class="ed-wrap">
                        <div class="ed-tb">
                            <button type="button" class="etb" onclick="fmt('bold')"><b>B</b></button>
                            <button type="button" class="etb" onclick="fmt('italic')"><i>I</i></button>
                            <button type="button" class="etb" onclick="fmt('underline')"><u>U</u></button>
                            <div class="tsep"></div>
                            <div class="colors">
                                <div class="csw" style="background:#002850;" onclick="fmt('foreColor','#002850')" title="Navy"></div>
                                <div class="csw" style="background:#64A014;" onclick="fmt('foreColor','#64A014')" title="Green"></div>
                                <div class="csw" style="background:#dc2626;" onclick="fmt('foreColor','#dc2626')" title="Red"></div>
                                <div class="csw" style="background:#64748b;" onclick="fmt('foreColor','#64748b')" title="Gray"></div>
                                <div class="csw" style="background:#0f172a;" onclick="fmt('foreColor','#0f172a')" title="Black"></div>
                            </div>
                            <div class="tsep"></div>
                            <select class="etb" onchange="fmt('fontSize',this.value);this.value=''">
                                <option value="">Size</option>
                                <option value="1">10px</option>
                                <option value="2">13px</option>
                                <option value="3">16px</option>
                                <option value="4">18px</option>
                                <option value="5">24px</option>
                            </select>
                            <div class="tsep"></div>
                            <button type="button" class="etb" onclick="insertLink()">&#128279; Link</button>
                            <button type="button" class="etb" onclick="document.getElementById('imgFile').click()">&#128247; Image</button>
                            <button type="button" class="etb" onclick="insertHRLine()">&#9135; Line</button>
                            <div class="tsep"></div>
                            <button type="button" class="etb" onclick="updatePreview()">&#128065; Preview</button>
                        </div>
                        <div class="ed-body" id="editor" contenteditable="true" oninput="sync();updatePreview()">
                            <?= $currentSig ?>
                        </div>
                    </div>
                    <textarea name="signature_html" id="sigTa" style="display:none;"></textarea>
                    <input type="file" id="imgFile" accept="image/*" style="display:none;" onchange="uploadImage(this)"/>

                    <!-- Image panel -->
                    <div class="img-panel">
                        <div class="img-panel-title">&#128247; Signature Images — click an image to insert it</div>
                        <div class="img-grid" id="imgGrid">
                            <button type="button" class="img-upload-btn" onclick="document.getElementById('imgFile').click()">+ Upload Image or Logo</button>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div class="preview-box">
                        <div class="preview-lbl">&#128065; Preview — how it appears in outgoing emails</div>
                        <div style="font-size:13px;color:var(--g500);line-height:1.7;margin-bottom:10px;">
                            Hi <?= htmlspecialchars($user['name']) ?>,<br/>
                            Please find the attached document for your review.<br/><br/>Best regards,
                        </div>
                        <div class="preview-sig" id="preview"><?= $currentSig ?></div>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" name="save_sig" class="btn gn">&#128190; Save Signature</button>
                    <button type="button" class="btn ol" onclick="applyPreset('professional')">&#8635; Reset to Default</button>
                    <?php if ($existing): ?>
                    <form method="POST" style="margin-left:auto;" onsubmit="return confirm('Clear your signature? It will no longer appear in emails.')">
                        <?= Auth::csrfField() ?>
                        <button type="submit" name="delete_sig" class="btn rd">&#128465; Clear Signature</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- ADMIN: SYSTEM DEFAULT -->
    <?php if ($isAdmin): ?>
    <div id="paneDefault" style="display:none;">
        <form method="POST">
            <?= Auth::csrfField() ?>
            <div class="card">
                <div class="chd">
                    <div class="cht">&#128737; System Default Signature <span class="admin-badge">Admin Only</span></div>
                    <div class="chsub">Pre-fills for staff who haven't set their own</div>
                </div>
                <div style="padding:14px 18px 0;">
                    <div style="background:var(--nl);border-radius:8px;padding:10px 14px;font-size:12.5px;color:var(--navy);margin-bottom:14px;border:1px solid #bfcfe8;">
                        &#128161; Use <code>{name}</code>, <code>{role}</code>, <code>{email}</code> as placeholders — replaced with each user's details.
                    </div>
                    <div class="ed-wrap">
                        <div class="ed-tb">
                            <button type="button" class="etb" onclick="fmt2('bold')"><b>B</b></button>
                            <button type="button" class="etb" onclick="fmt2('italic')"><i>I</i></button>
                            <div class="tsep"></div>
                            <button type="button" class="etb" onclick="insertPlaceholder('{name}')">+ Name</button>
                            <button type="button" class="etb" onclick="insertPlaceholder('{role}')">+ Role</button>
                            <button type="button" class="etb" onclick="insertPlaceholder('{email}')">+ Email</button>
                            <button type="button" class="etb" onclick="insertPlaceholder('{phone}')">+ Phone</button>
                            <button type="button" class="etb" onclick="document.getElementById('imgFile2').click()">&#128247; Logo</button>
                        </div>
                        <div class="ed-body" id="editor2" contenteditable="true" oninput="sync2()" style="min-height:140px;">
                            <?= $sysDefault ?: $sysSigTemplate ?>
                        </div>
                    </div>
                    <textarea name="default_signature" id="defTa" style="display:none;"></textarea>
                    <input type="file" id="imgFile2" accept="image/*" style="display:none;" onchange="uploadImage2(this)"/>
                    <div class="preview-box" style="margin-top:10px;">
                        <div class="preview-lbl">Preview</div>
                        <div class="preview-sig" id="defPreview"><?= $sysDefault ?: $sysSigTemplate ?></div>
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" name="save_default" class="btn nv">&#128190; Save as System Default</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
</div><!-- /.hri-page -->

<script>
var sysDefault = <?= json_encode($sysDefault) ?>;
var userName   = <?= json_encode($user['name']) ?>;
var userEmail  = <?= json_encode($user['email']) ?>;
var userRole   = <?= json_encode($role['label']) ?>;
var logoUrl    = <?= json_encode(APP_URL . '/hri-logo.png') ?>;

function switchTab(t) {
    document.getElementById('tabMy').classList.toggle('on', t==='my');
    document.getElementById('paneMy').style.display = t==='my' ? '' : 'none';
    <?php if ($isAdmin): ?>
    document.getElementById('tabDefault').classList.toggle('on', t==='default');
    document.getElementById('paneDefault').style.display = t==='default' ? '' : 'none';
    <?php endif; ?>
}

function fmt(cmd, val) { document.getElementById('editor').focus(); document.execCommand(cmd, false, val||null); sync(); updatePreview(); }
function fmt2(cmd, val) { document.getElementById('editor2').focus(); document.execCommand(cmd, false, val||null); sync2(); }

function insertLink() {
    var url = prompt('URL:', 'https://'); if (!url) return;
    document.getElementById('editor').focus();
    document.execCommand('createLink', false, url); sync(); updatePreview();
}
function insertHRLine() {
    document.getElementById('editor').focus();
    document.execCommand('insertHTML', false, '<hr style="border:none;border-top:2px solid #002850;margin:8px 0;"/>');
    sync(); updatePreview();
}
function insertPlaceholder(ph) {
    document.getElementById('editor2').focus();
    document.execCommand('insertText', false, ph); sync2();
}

function sync()  { document.getElementById('sigTa').value = document.getElementById('editor').innerHTML; }
function sync2() { document.getElementById('defTa').value = document.getElementById('editor2').innerHTML; }
function updatePreview() { document.getElementById('preview').innerHTML = document.getElementById('editor').innerHTML; }
function clearSig() { document.getElementById('editor').innerHTML = ''; sync(); updatePreview(); }

var presets = {
    professional: '<table cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif;font-size:13px;border-collapse:collapse;max-width:480px;"><tr><td style="padding-right:16px;border-right:3px solid #64A014;vertical-align:middle;"><img src="'+logoUrl+'" alt="HR Indexx" style="height:52px;width:auto;display:block;"/></td><td style="padding-left:16px;vertical-align:top;line-height:1.7;"><div style="font-size:15px;font-weight:700;color:#002850;">'+userName+'</div><div style="font-size:12px;color:#64A014;font-weight:600;">'+userRole+' &mdash; HR Indexx Limited</div><div style="font-size:12px;color:#64748b;">12 Macarthy Street, Onikan, Lagos Island</div><a href="mailto:'+userEmail+'" style="font-size:12px;color:#002850;text-decoration:none;">'+userEmail+'</a></td></tr><tr><td colspan="2" style="padding-top:10px;"><hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 6px;"/><span style="font-size:10px;color:#94a3b8;line-height:1.5;">This email and any attachments are confidential and intended solely for the addressee. HR Indexx Limited &middot; RC 446051 &middot; Lagos, Nigeria.</span></td></tr></table>',
    modern: '<table cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif;font-size:13px;border-collapse:collapse;max-width:480px;"><tr><td style="padding-bottom:10px;"><img src="'+logoUrl+'" alt="HR Indexx" style="height:40px;width:auto;display:block;"/></td></tr><tr><td style="border-top:2px solid #002850;padding-top:10px;line-height:1.7;"><div style="font-size:15px;font-weight:700;color:#002850;">'+userName+'</div><div style="font-size:12px;color:#64A014;font-weight:600;">'+userRole+'</div><div style="font-size:12px;color:#64748b;">HR Indexx Limited &middot; Lagos Island, Nigeria</div><a href="mailto:'+userEmail+'" style="font-size:12px;color:#002850;text-decoration:none;">'+userEmail+'</a></td></tr><tr><td style="padding-top:8px;border-top:1px solid #e2e8f0;"><span style="font-size:10px;color:#94a3b8;line-height:1.4;">CONFIDENTIALITY NOTICE: This message is intended only for the individual or entity addressed. &copy; HR Indexx Limited (RC 446051).</span></td></tr></table>',
    executive: '<table cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif;border-collapse:collapse;max-width:520px;border:1px solid #e2e8f0;"><tr><td style="background:#002850;padding:14px 20px;"><img src="'+logoUrl+'" alt="HR Indexx" style="height:38px;width:auto;display:block;"/></td></tr><tr><td style="padding:14px 20px;border-bottom:3px solid #64A014;"><div style="font-size:16px;font-weight:700;color:#002850;margin-bottom:2px;">'+userName+'</div><div style="font-size:13px;color:#64A014;font-weight:600;margin-bottom:8px;">'+userRole+'</div><table cellpadding="2" cellspacing="0" style="font-size:12px;color:#64748b;"><tr><td style="padding-right:8px;">&#128205;</td><td>12 Macarthy Street, Onikan, Lagos Island</td></tr><tr><td>&#128231;</td><td><a href="mailto:'+userEmail+'" style="color:#002850;text-decoration:none;">'+userEmail+'</a></td></tr></table></td></tr><tr><td style="background:#f8fafc;padding:10px 20px;"><span style="font-size:10px;color:#94a3b8;line-height:1.5;">This communication is confidential and may be legally privileged. HR Indexx Limited (RC 446051). NDPA 2023. NDPC/DCP/12819.</span></td></tr></table>',
    system: sysDefault
};

function applyPreset(name) {
    if (name === 'system' && !sysDefault) { alert('No system default has been set by admin yet.'); return; }
    document.getElementById('editor').innerHTML = presets[name] || '';
    sync(); updatePreview();
}

function uploadImage(inp) {
    if (!inp.files[0]) return;
    var fd = new FormData();
    fd.append('sig_image', inp.files[0]);
    fd.append('upload_img', '1');
    fd.append('_csrf', window.CSRF_TOKEN);
    fetch('signature.php', {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) { addImageToGrid(d.url); insertImageToEditor(d.url); }
            else { alert('Upload failed: ' + (d.err||'unknown error')); }
        })
        .catch(function(e){ alert('Upload error: ' + e.message); });
    inp.value = '';
}

function uploadImage2(inp) {
    if (!inp.files[0]) return;
    var fd = new FormData();
    fd.append('sig_image', inp.files[0]);
    fd.append('upload_img', '1');
    fd.append('_csrf', window.CSRF_TOKEN);
    fetch('signature.php', {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                var ed = document.getElementById('editor2');
                ed.focus();
                document.execCommand('insertHTML', false, '<img src="'+d.url+'" style="max-height:60px;max-width:200px;" alt="logo"/>');
                sync2();
            }
        });
    inp.value = '';
}

function addImageToGrid(url) {
    var grid = document.getElementById('imgGrid');
    var img  = document.createElement('img');
    img.src  = url; img.className = 'uploaded-img'; img.title = 'Click to insert';
    img.onclick = function() { insertImageToEditor(url); };
    grid.insertBefore(img, grid.firstChild);
}

function insertImageToEditor(url) {
    var ed = document.getElementById('editor'); ed.focus();
    document.execCommand('insertHTML', false, '<img src="'+url+'" style="max-height:70px;max-width:220px;object-fit:contain;" alt="signature image"/>');
    sync(); updatePreview();
}

sync(); sync2();
</script>
<?php Layout::end(); ?>
