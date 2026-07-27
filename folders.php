<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/ImapHelper.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$mp   = $_SESSION['mail_pass'] ?? '';

// ── AJAX ACTIONS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $name   = trim($_POST['name'] ?? '');
    $src    = trim($_POST['src']  ?? '');
    $dest   = trim($_POST['dest'] ?? '');

    // Protect system folders
    $protected = ['INBOX','INBOX.Sent','INBOX.Trash','INBOX.Drafts','INBOX.Junk E-mail','INBOX.Spam'];

    $server = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}';
    $mbox   = @imap_open($server, $user['email'], $mp, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR'=>'GSSAPI']);

    if (!$mbox) { echo json_encode(['ok'=>false,'error'=>'Cannot connect to mailbox']); exit; }

    switch ($action) {
        case 'create':
            if (!$name || strlen($name) > 60) { echo json_encode(['ok'=>false,'error'=>'Invalid folder name']); break; }
            $safeName = preg_replace('/[^a-zA-Z0-9 _\-]/', '', $name);
            $fullName = 'INBOX.' . str_replace(' ', '_', $safeName);
            $ok = imap_createmailbox($mbox, imap_utf7_encode($server . $fullName));
            Auth::auditLog($user['id'], 'folder_created', $fullName);
            echo json_encode(['ok'=>$ok, 'folder'=>$fullName, 'error'=>$ok?null:imap_last_error()]);
            break;

        case 'rename':
            if (in_array($src, $protected)) { echo json_encode(['ok'=>false,'error'=>'Cannot rename system folder']); break; }
            $safeName = preg_replace('/[^a-zA-Z0-9 _\-]/', '', $dest);
            $newName  = 'INBOX.' . str_replace(' ', '_', $safeName);
            $ok = imap_renamemailbox($mbox, imap_utf7_encode($server.$src), imap_utf7_encode($server.$newName));
            echo json_encode(['ok'=>$ok, 'error'=>$ok?null:imap_last_error()]);
            break;

        case 'delete':
            if (in_array($src, $protected)) { echo json_encode(['ok'=>false,'error'=>'Cannot delete system folder']); break; }
            // Move messages to trash first
            $mbox2 = @imap_open($server.$src, $user['email'], $mp, 0, 1, ['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
            if ($mbox2 && imap_num_msg($mbox2) > 0) {
                $msgs = @imap_search($mbox2, 'ALL') ?: [];
                if ($msgs) {
                    $trash = 'INBOX.Trash';
                    @imap_mail_move($mbox2, implode(',', $msgs), $trash);
                    imap_expunge($mbox2);
                }
                imap_close($mbox2);
            }
            $ok = imap_deletemailbox($mbox, imap_utf7_encode($server.$src));
            Auth::auditLog($user['id'], 'folder_deleted', $src);
            echo json_encode(['ok'=>$ok, 'error'=>$ok?null:imap_last_error()]);
            break;

        case 'move_email':
            $uid     = (int)($_POST['uid'] ?? 0);
            $fromF   = Auth::sanitiseString($_POST['from_folder'] ?? 'INBOX', 60);
            $destF   = Auth::sanitiseString($_POST['dest_folder'] ?? '', 60);
            if (!$uid || !$destF) { echo json_encode(['ok'=>false,'error'=>'Missing uid or dest']); break; }
            imap_close($mbox);
            $mbox3 = ImapHelper::open($user['email'], $mp, $fromF);
            if (!$mbox3) { echo json_encode(['ok'=>false,'error'=>'Cannot open source folder']); break; }
            $ok = @imap_mail_move($mbox3, (string)$uid, $destF, CP_UID);
            imap_expunge($mbox3);
            imap_close($mbox3);
            Auth::auditLog($user['id'], 'email_moved', "UID $uid → $destF");
            echo json_encode(['ok'=>$ok]);
            exit;

        default:
            echo json_encode(['ok'=>false,'error'=>'Unknown action']);
    }
    imap_close($mbox);
    exit;
}

// ── GET FOLDER LIST ──────────────────────────────────────────
$folders = [];
if ($mp) {
    $all = ImapHelper::listFolders($user['email'], $mp);
    $server = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}';
    $protected = ['INBOX','INBOX.Sent','INBOX.Trash','INBOX.Drafts','INBOX.Junk E-mail','INBOX.Spam','INBOX.CCA','INBOX.Archive','INBOX.Cognito','INBOX.Trap25','INBOX.Junk'];
    foreach ($all as $f) {
        $display = str_replace('INBOX.', '', $f);
        $isSystem = in_array($f, $protected);
        $folders[] = ['full'=>$f, 'display'=>$display, 'system'=>$isSystem];
    }
}

Layout::shell($user, 'folders', 0, 'Manage Folders');
?>
<div class="hri-page" style="max-width:760px;">
    <div class="hri-page-hd">
        <h1 class="hri-page-title">📁 Manage Folders</h1>
        <button class="hri-btn hri-btn-navy" onclick="showCreateFolder()">+ New Folder</button>
    </div>

    <!-- Create folder form (hidden) -->
    <div id="createForm" style="display:none;margin-bottom:20px;">
        <div class="hri-card" style="padding:16px;">
            <div style="font-size:14px;font-weight:600;margin-bottom:10px;">Create New Folder</div>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="text" id="newFolderName" placeholder="Folder name (e.g. HR Policies)"
                       maxlength="40"
                       class="hri-input" style="flex:1;"
                       onkeydown="if(event.key==='Enter') createFolder()"/>
                <button class="hri-btn hri-btn-navy" onclick="createFolder()">Create</button>
                <button class="hri-btn hri-btn-outline" onclick="document.getElementById('createForm').style.display='none'">Cancel</button>
            </div>
            <div style="font-size:12px;color:var(--g400);margin-top:6px;">Will be created as INBOX.[name]</div>
        </div>
    </div>

    <div class="hri-card">
        <div class="hri-card-hd">
            <span class="hri-card-title">📂 All Folders (<?=count($folders)?>)</span>
        </div>
        <?php foreach ($folders as $f): ?>
        <div style="display:flex;align-items:center;padding:10px 16px;border-bottom:1px solid var(--g100);gap:12px;"
             id="frow-<?=htmlspecialchars(base64_encode($f['full']))?>">
            <span style="font-size:18px;"><?=$f['system']?'📬':'📁'?></span>
            <span style="flex:1;font-size:14px;color:var(--g800);" id="fname-<?=htmlspecialchars(base64_encode($f['full']))?>">
                <?=htmlspecialchars($f['display'])?>
            </span>
            <?php if($f['system']): ?>
            <span class="hri-pill hri-pill-info" style="font-size:11px;">System</span>
            <?php else: ?>
            <a href="/mail.php?f=<?=urlencode($f['full'])?>" class="hri-btn hri-btn-outline" style="padding:4px 12px;font-size:12px;">Open</a>
            <button class="hri-btn hri-btn-outline" style="padding:4px 12px;font-size:12px;"
                    onclick="renameFolder('<?=htmlspecialchars(addslashes($f['full']))?>','<?=htmlspecialchars(addslashes($f['display']))?>')">Rename</button>
            <button class="hri-btn" style="padding:4px 12px;font-size:12px;background:#fee2e2;color:var(--red);border:none;"
                    onclick="deleteFolder('<?=htmlspecialchars(addslashes($f['full']))?>','<?=htmlspecialchars(addslashes($f['display']))?>')">Delete</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($folders)): ?>
        <div class="hri-empty">Could not load folders. Check your mail connection.</div>
        <?php endif; ?>
    </div>

    <div style="margin-top:16px;" class="hri-card" style="padding:16px;">
        <div class="hri-card-hd"><span class="hri-card-title">📨 Move Email to Folder</span></div>
        <div style="padding:16px;">
            <div style="font-size:13px;color:var(--g500);margin-bottom:12px;">
                You can also move emails from the inbox by right-clicking (coming in Sprint 3).<br>
                For now, enter the UID and select destination:
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <input type="number" id="moveUid" placeholder="Email UID" class="hri-input" style="width:120px;"/>
                <select id="moveSrc" class="hri-select" style="width:160px;">
                    <option value="INBOX">INBOX</option>
                    <option value="Sent">Sent</option>
                </select>
                <span style="color:var(--g400);">→</span>
                <select id="moveDest" class="hri-select" style="flex:1;min-width:160px;">
                    <?php foreach($folders as $f): ?>
                    <option value="<?=htmlspecialchars($f['full'])?>"><?=htmlspecialchars($f['display'])?></option>
                    <?php endforeach; ?>
                </select>
                <button class="hri-btn hri-btn-navy" onclick="moveEmail()">Move</button>
            </div>
        </div>
    </div>
</div>

<div id="statusMsg" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
     background:var(--g900);color:#fff;padding:10px 20px;border-radius:6px;display:none;
     font-size:13px;z-index:9999;"></div>

<script>
function showStatus(msg, ok) {
    var el = document.getElementById('statusMsg');
    el.textContent = msg;
    el.style.borderLeft = '4px solid ' + (ok ? '#1a7f37' : '#d93025');
    el.style.display = 'block';
    setTimeout(function(){ el.style.display='none'; }, 3000);
}
function showCreateFolder() {
    document.getElementById('createForm').style.display = 'block';
    setTimeout(function(){ document.getElementById('newFolderName').focus(); }, 100);
}
function apiCall(data, cb) {
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    fetch('folders.php', {method:'POST', body:fd, credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(cb)
    .catch(function(e){ showStatus('Error: ' + e.message, false); });
}
function createFolder() {
    var name = document.getElementById('newFolderName').value.trim();
    if (!name) return;
    apiCall({action:'create', name:name}, function(d) {
        if (d.ok) { showStatus('Folder "' + name + '" created', true); setTimeout(function(){ location.reload(); }, 1200); }
        else showStatus('Error: ' + (d.error || 'Failed'), false);
    });
}
function renameFolder(full, current) {
    var newName = prompt('Rename "' + current + '" to:', current);
    if (!newName || newName === current) return;
    apiCall({action:'rename', src:full, dest:newName}, function(d) {
        if (d.ok) { showStatus('Renamed to "' + newName + '"', true); setTimeout(function(){ location.reload(); }, 1200); }
        else showStatus('Error: ' + (d.error || 'Failed'), false);
    });
}
function deleteFolder(full, display) {
    if (!confirm('Delete folder "' + display + '"?\n\nAll emails will be moved to Trash first.')) return;
    apiCall({action:'delete', src:full}, function(d) {
        if (d.ok) { showStatus('Folder deleted', true); setTimeout(function(){ location.reload(); }, 1200); }
        else showStatus('Error: ' + (d.error || 'Failed'), false);
    });
}
function moveEmail() {
    var uid  = document.getElementById('moveUid').value;
    var src  = document.getElementById('moveSrc').value;
    var dest = document.getElementById('moveDest').value;
    if (!uid) { alert('Enter an email UID'); return; }
    apiCall({action:'move_email', uid:uid, from_folder:src, dest_folder:dest}, function(d) {
        showStatus(d.ok ? 'Email moved to ' + dest : 'Error: ' + (d.error||'Failed'), d.ok);
    });
}
</script>
<?php Layout::end(); ?>
