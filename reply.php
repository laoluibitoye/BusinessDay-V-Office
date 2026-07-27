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
$db   = getDB();
$mp   = $_SESSION['mail_pass'] ?? '';

$uid    = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$folder = $_GET['f'] ?? 'INBOX';
$mode   = in_array($_GET['mode'] ?? '', ['reply','replyall','forward']) ? $_GET['mode'] : 'reply';

$stdFolders = ['INBOX','Sent','Drafts','Trash','Spam','Starred'];
if (!in_array($folder, $stdFolders) && !preg_match('/^INBOX\.[A-Za-z0-9_\- ]+$/', $folder)) {
    $folder = 'INBOX';
}

$error = '';
$origFrom = $origFromEmail = $origTo = $origCc = $origSubject = $origDate = $origBodyHtml = '';
$toField  = $ccField = $subjectField = '';

if (!$uid || !$mp) {
    $error = 'Invalid request.';
} else {
    try {
        @imap_timeout(IMAP_OPENTIMEOUT, 8);
        @imap_timeout(IMAP_READTIMEOUT, 12);
        $imapFolder = ($folder === 'Starred') ? 'INBOX' : $folder;
        $mbox = ImapHelper::open($user['email'], $mp, $imapFolder);
        if (!$mbox) {
            $error = 'Cannot open mailbox.';
        } else {
            $msgNo = imap_msgno($mbox, $uid);
            if (!$msgNo) {
                $error = 'Message not found.';
            } else {
                $hdr = @imap_headerinfo($mbox, $msgNo);
                if ($hdr) {
                    $fromObj      = $hdr->from[0] ?? null;
                    $origFrom     = $fromObj ? (imap_utf8($fromObj->personal ?? '') ?: ($fromObj->mailbox.'@'.$fromObj->host)) : '';
                    $origFromEmail= $fromObj ? ($fromObj->mailbox.'@'.$fromObj->host) : '';

                    $toArr = [];
                    foreach ($hdr->to ?? [] as $t) {
                        $n = imap_utf8($t->personal ?? '');
                        $e = $t->mailbox.'@'.$t->host;
                        $toArr[] = $n ? "$n <$e>" : $e;
                    }
                    $origTo = implode(', ', $toArr);

                    $ccArr = [];
                    foreach ($hdr->cc ?? [] as $c) {
                        $n = imap_utf8($c->personal ?? '');
                        $e = $c->mailbox.'@'.$c->host;
                        $ccArr[] = $n ? "$n <$e>" : $e;
                    }
                    $origCc = implode(', ', $ccArr);

                    $origSubject = ImapHelper::decodeSubject(imap_utf8($hdr->subject ?? ''));
                    $origDate    = date('D, d M Y H:i', $hdr->udate ?? time());

                    $body = ImapHelper::getBody($mbox, $msgNo);
                    $origBodyHtml = $body['html']
                        ? ImapHelper::sanitizeHtml($body['html'])
                        : ImapHelper::plainToHtml($body['plain'] ?? '');

                    if (!empty($body['cid_map']) && $origBodyHtml) {
                        foreach ($body['cid_map'] as $cid => $partNum) {
                            $proxy = APP_URL.'/api/mail/attachment.php?uid='.$uid
                                   .'&folder='.urlencode($folder).'&part='.urlencode($partNum).'&name=inline';
                            $origBodyHtml = str_replace(
                                ['src="cid:'.$cid.'"', "src='cid:".$cid."'"],
                                ['src="'.$proxy.'"',   'src="'.$proxy.'"'],
                                $origBodyHtml
                            );
                        }
                    }

                    $myEmail = strtolower($user['email']);

                    // Reply-To header takes priority over From for the To field
                    $replyToEmail = '';
                    if (!empty($hdr->reply_to)) {
                        $rt = $hdr->reply_to[0];
                        $replyToEmail = $rt->mailbox.'@'.$rt->host;
                    }
                    $primaryTo = $replyToEmail ?: $origFromEmail;

                    if ($mode === 'reply') {
                        $toField      = $primaryTo;
                        $ccField      = '';
                        $subjectField = preg_match('/^Re:/i', $origSubject) ? $origSubject : 'Re: '.$origSubject;

                    } elseif ($mode === 'replyall') {
                        $toField      = $primaryTo;
                        $toFieldLow   = strtolower($toField);
                        $ccEmails     = [];
                        foreach ($hdr->to ?? [] as $t) {
                            $e = strtolower($t->mailbox.'@'.$t->host);
                            if ($e !== $myEmail && $e !== $toFieldLow)
                                $ccEmails[] = $t->mailbox.'@'.$t->host;
                        }
                        foreach ($hdr->cc ?? [] as $c) {
                            $e = strtolower($c->mailbox.'@'.$c->host);
                            if ($e !== $myEmail && $e !== $toFieldLow
                                && !in_array($e, array_map('strtolower', $ccEmails)))
                                $ccEmails[] = $c->mailbox.'@'.$c->host;
                        }
                        $ccField      = implode(', ', $ccEmails);
                        $subjectField = preg_match('/^Re:/i', $origSubject) ? $origSubject : 'Re: '.$origSubject;

                    } else { // forward
                        $toField      = '';
                        $ccField      = '';
                        $subjectField = preg_match('/^Fwd?:/i', $origSubject) ? $origSubject : 'Fwd: '.$origSubject;
                    }
                }
            }
            imap_close($mbox);
        }
    } catch (Exception $e) {
        $error = 'Error loading message: '.htmlspecialchars($e->getMessage());
    }
}

// Default signature
$sig = '';
try {
    $ss = $db->prepare("SELECT html FROM user_signatures WHERE user_id=? AND is_default=1 LIMIT 1");
    $ss->execute([$user['id']]);
    $sigRow = $ss->fetch();
    if ($sigRow && $sigRow['html']) {
        $raw = $sigRow['html'];
        $sig = (strpos($raw,'<') !== false) ? $raw : nl2br(htmlspecialchars($raw));
    }
} catch (Exception $e) {}

$modeLabel = ['reply'=>'Reply','replyall'=>'Reply All','forward'=>'Forward'][$mode];
$backUrl   = APP_URL.'/mail.php?f='.urlencode($folder).'&uid='.$uid;

// Build the srcdoc for the original message preview iframe
$srcdoc = '';
if ($origBodyHtml) {
    $srcdoc = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        .'<style>body{font-family:\'Google Sans\',Roboto,Arial,sans-serif;font-size:14px;padding:16px 20px;margin:0;color:#0f172a;line-height:1.6;}img{max-width:100%;}</style>'
        .'</head><body>'.$origBodyHtml.'</body></html>';
}

$_rpNavKeys = ['INBOX'=>'inbox','Starred'=>'starred','Sent'=>'sent','Drafts'=>'drafts','Trash'=>'trash','Spam'=>'spam'];
Layout::shell($user, $_rpNavKeys[$folder] ?? 'inbox', 0, $modeLabel.' — HRI Mail');
?>
<style>
/* Allow reply page to scroll — layout_shell sets overflow:hidden on body/#hriMain for inbox */
html, body { overflow: auto !important; }
#hriMain { overflow-y: auto !important; height: auto !important; }
.rp{width:100%;padding:16px 24px;box-sizing:border-box;}
.rp-hd{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;}
.rp-back{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border:1.5px solid #e2e8f0;border-radius:8px;color:#334155;font-size:13px;font-weight:600;text-decoration:none;background:#fff;transition:all .15s;}
.rp-back:hover{background:#f8fafc;border-color:#cbd5e1;}
.rp-mode-badge{padding:5px 12px;border-radius:99px;font-size:12px;font-weight:700;background:#002850;color:#fff;}
.rp-subj-preview{font-size:14px;color:#64748b;font-weight:400;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.rp-card{background:#fff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.09);overflow:hidden;margin-bottom:14px;}
.rp-status{padding:10px 18px;font-size:13px;border-radius:0;display:none;}
.rp-status.ok{background:#dcfce7;border-bottom:1px solid #86efac;color:#166534;}
.rp-status.er{background:#fee2e2;border-bottom:1px solid #fca5a5;color:#dc2626;}
/* Header rows */
.rp-row{display:flex;align-items:flex-start;border-bottom:1px solid #f1f5f9;}
.rp-row:last-of-type{border-bottom:none;}
.rp-lbl{width:68px;flex-shrink:0;padding:11px 8px 11px 18px;font-size:11.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;line-height:1.7;}
/* Pill box */
.rp-pills{flex:1;display:flex;flex-wrap:wrap;align-items:center;gap:4px;padding:7px 8px;min-height:40px;cursor:text;}
.rp-pill{display:inline-flex;align-items:center;gap:4px;background:#002850;color:#fff;border-radius:99px;padding:3px 6px 3px 11px;font-size:12.5px;font-weight:500;}
.rp-pill button{background:none;border:none;color:rgba(255,255,255,.75);cursor:pointer;font-size:13px;padding:0 2px;line-height:1;}
.rp-pill button:hover{color:#fff;}
.rp-pill-inp{border:none;outline:none;font-size:13.5px;font-family:inherit;color:#0f172a;background:transparent;min-width:160px;flex:1;padding:2px 0;}
.rp-toggle-row{display:flex;gap:6px;padding:0 14px;align-items:center;flex-shrink:0;}
.rp-toggle{font-size:12px;color:#94a3b8;cursor:pointer;padding:2px 6px;border-radius:4px;border:1px solid #e2e8f0;background:#fff;transition:all .12s;}
.rp-toggle:hover{color:#002850;border-color:#002850;}
.rp-subj-inp{flex:1;border:none;outline:none;font-size:14px;font-family:inherit;color:#0f172a;padding:11px 12px;}
/* Body */
.rp-body-wrap{padding:14px 20px 6px;}
.rp-compose{min-height:160px;outline:none;font-size:14px;font-family:'Google Sans',Roboto,Arial,sans-serif;color:#0f172a;line-height:1.65;padding:2px 0;}
.rp-compose:empty::before{content:'Write your message...';color:#94a3b8;pointer-events:none;}
/* Signature */
.rp-sig-sep{border:none;border-top:1px solid #e2e8f0;margin:10px 0 0;}
.rp-sig-wrap{padding:8px 20px 14px;}
.rp-sig-inner{font-size:13px;color:#334155;}
/* Attachments */
.rp-attach-row{padding:6px 20px 10px;display:flex;flex-wrap:wrap;gap:6px;}
.rp-attach-chip{display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;border-radius:6px;padding:4px 10px;font-size:12px;color:#334155;}
.rp-attach-chip button{background:none;border:none;cursor:pointer;color:#94a3b8;font-size:13px;padding:0;line-height:1;}
/* Toolbar */
.rp-toolbar{display:flex;align-items:center;gap:8px;padding:12px 20px;border-top:1px solid #f1f5f9;flex-wrap:wrap;}
.rp-send{padding:9px 22px;background:#002850;color:#fff;border:none;border-radius:8px;font-size:13.5px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s;display:inline-flex;align-items:center;gap:7px;}
.rp-send:hover{background:#003a72;}
.rp-send:disabled{background:#94a3b8;cursor:not-allowed;}
.rp-btn-sec{padding:9px 15px;background:#fff;color:#334155;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;display:inline-flex;align-items:center;gap:6px;}
.rp-btn-sec:hover{background:#f8fafc;}
.rp-shortcut{font-size:11px;color:#94a3b8;margin-left:auto;}
/* Original message */
.rp-orig{background:#fff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.09);overflow:hidden;}
.rp-orig-hd{padding:11px 20px;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;}
.rp-orig-hd:hover{background:#f1f5f9;}
.rp-orig-hd-left{font-size:13px;font-weight:700;color:#334155;}
.rp-orig-hd-right{font-size:12px;color:#94a3b8;display:flex;align-items:center;gap:8px;}
.rp-orig-meta{padding:10px 20px 8px;background:#fafbfc;border-bottom:1px solid #f1f5f9;font-size:12.5px;color:#64748b;line-height:1.85;}
.rp-orig-meta b{color:#334155;}
.rp-orig-iframe{width:100%;border:none;display:block;}
@media(max-width:640px){
    .rp{padding:8px 10px;}
    .rp-lbl{width:48px;padding:10px 4px 10px 12px;font-size:10.5px;}
    .rp-shortcut{display:none;}
}
</style>

<div class="rp">
    <div class="rp-hd">
        <a class="rp-back" href="<?= htmlspecialchars($backUrl) ?>">&#8592; Back to mail</a>
        <span class="rp-mode-badge">
            <?= $mode==='forward' ? '&#128257; Forward' : ($mode==='replyall' ? '&#8617;&#8617; Reply All' : '&#8617; Reply') ?>
        </span>
        <?php if ($origSubject): ?>
        <span class="rp-subj-preview"><?= htmlspecialchars($origSubject) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="rp-status er" style="display:block;border-radius:12px;margin-bottom:12px;">&#9888; <?= $error ?></div>
    <?php else: ?>

    <div class="rp-card">
        <div class="rp-status" id="rpStatus"></div>

        <!-- To -->
        <div class="rp-row">
            <div class="rp-lbl">To</div>
            <div class="rp-pills" id="rpToPills">
                <input class="rp-pill-inp" id="rpToInp" type="text"
                    placeholder="<?= $mode==='forward' ? 'Add recipients...' : '' ?>"
                    autocomplete="off" spellcheck="false"/>
            </div>
            <div class="rp-toggle-row">
                <button class="rp-toggle" id="rpCcToggle" onclick="rpShowCc()">Cc</button>
                <button class="rp-toggle" id="rpBccToggle" onclick="rpShowBcc()">Bcc</button>
            </div>
        </div>

        <!-- Cc (hidden until toggled or pre-filled) -->
        <div class="rp-row" id="rpCcRow" style="display:none;">
            <div class="rp-lbl">Cc</div>
            <div class="rp-pills" id="rpCcPills">
                <input class="rp-pill-inp" id="rpCcInp" type="text" autocomplete="off" spellcheck="false"/>
            </div>
        </div>

        <!-- Bcc -->
        <div class="rp-row" id="rpBccRow" style="display:none;">
            <div class="rp-lbl">Bcc</div>
            <div class="rp-pills" id="rpBccPills">
                <input class="rp-pill-inp" id="rpBccInp" type="text" autocomplete="off" spellcheck="false"/>
            </div>
        </div>

        <!-- Subject -->
        <div class="rp-row">
            <div class="rp-lbl">Subject</div>
            <input class="rp-subj-inp" id="rpSubInp" type="text"
                value="<?= htmlspecialchars($subjectField) ?>"/>
        </div>

        <!-- Compose area -->
        <div class="rp-body-wrap">
            <div class="rp-compose" id="rpBody" contenteditable="true" spellcheck="true"></div>
        </div>

        <!-- Signature -->
        <?php if ($sig): ?>
        <hr class="rp-sig-sep"/>
        <div class="rp-sig-wrap">
            <div class="rp-sig-inner" id="rpSig"><?= $sig ?></div>
        </div>
        <?php endif; ?>

        <!-- Attachment chips -->
        <div id="rpAttachRow" class="rp-attach-row" style="display:none;"></div>
        <input type="file" id="rpFileInput" multiple style="display:none;" onchange="rpAddFiles(this.files)"/>

        <!-- Toolbar -->
        <div class="rp-toolbar">
            <button class="rp-send" id="rpSendBtn" onclick="rpSend()">&#9658;&nbsp; Send</button>
            <button class="rp-btn-sec" onclick="document.getElementById('rpFileInput').click()">
                &#128206; Attach file
            </button>
            <button class="rp-btn-sec" onclick="rpDiscard()">&#128465; Discard</button>
            <span class="rp-shortcut">Ctrl+Enter to send</span>
        </div>
    </div>

    <!-- Original message panel -->
    <?php if ($origFrom || $origBodyHtml): ?>
    <div class="rp-orig">
        <div class="rp-orig-hd" onclick="rpToggleOrig()">
            <span class="rp-orig-hd-left">
                <?= $mode==='forward' ? '&#128257; Forwarded message' : '&#128394;&#65039; Original message' ?>
            </span>
            <span class="rp-orig-hd-right">
                <span><?= htmlspecialchars($origFrom) ?></span>
                <span>&middot;</span>
                <span><?= htmlspecialchars($origDate) ?></span>
                <span id="rpOrigIco">&#9660;</span>
            </span>
        </div>
        <div id="rpOrigPanel">
            <div class="rp-orig-meta">
                <b>From:</b> <?= htmlspecialchars($origFrom) ?>
                    <?php if ($origFromEmail && $origFromEmail !== $origFrom): ?>
                        &lt;<?= htmlspecialchars($origFromEmail) ?>&gt;
                    <?php endif; ?><br>
                <b>To:</b> <?= htmlspecialchars($origTo) ?><br>
                <?php if ($origCc): ?><b>Cc:</b> <?= htmlspecialchars($origCc) ?><br><?php endif; ?>
                <b>Date:</b> <?= htmlspecialchars($origDate) ?><br>
                <b>Subject:</b> <?= htmlspecialchars($origSubject) ?>
            </div>
            <?php if ($srcdoc): ?>
            <iframe class="rp-orig-iframe" id="rpOrigIframe"
                srcdoc="<?= htmlspecialchars($srcdoc, ENT_QUOTES, 'UTF-8') ?>"
                onload="rpResizeIframe(this)"
                sandbox="allow-same-origin allow-popups"></iframe>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; // end no error ?>
</div>

<script>
(function(){
'use strict';

var MODE    = <?= json_encode($mode) ?>;
var BACK    = <?= json_encode($backUrl) ?>;
var CSRF    = window.CSRF_TOKEN || '';

// Pre-filled data from server
var initTo  = <?= json_encode($toField) ?>;
var initCc  = <?= json_encode($ccField) ?>;

// Original message data for the quoted block in the sent email
var Q = {
    from:    <?= json_encode($origFrom) ?>,
    fromEmail: <?= json_encode($origFromEmail) ?>,
    to:      <?= json_encode($origTo) ?>,
    cc:      <?= json_encode($origCc) ?>,
    date:    <?= json_encode($origDate) ?>,
    subject: <?= json_encode($origSubject) ?>,
    body:    <?= json_encode($origBodyHtml, JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE) ?: '""' ?>
};

// ── PILLBOX ───────────────────────────────────────────────
function PillBox(wrapId, inputId) {
    var wrap  = document.getElementById(wrapId);
    var input = document.getElementById(inputId);
    var list  = [];

    function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function render() {
        wrap.querySelectorAll('.rp-pill').forEach(function(p){ p.remove(); });
        list.forEach(function(addr, i) {
            var p = document.createElement('span');
            p.className = 'rp-pill';
            p.innerHTML = esc(addr)+'<button title="Remove" onclick="event.stopPropagation()">&#10005;</button>';
            p.querySelector('button').addEventListener('click', function(){
                list.splice(i, 1); render();
            });
            wrap.insertBefore(p, input);
        });
    }

    function normalise(raw) {
        // Accept "Name <email>" or bare email
        var m = raw.match(/<([^>]+)>/);
        return (m ? m[1] : raw).trim();
    }

    function add(raw) {
        raw.split(/[,;]+/).forEach(function(part) {
            var email = normalise(part);
            if (!email) return;
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;
            var lo = email.toLowerCase();
            if (list.map(function(a){ return a.toLowerCase(); }).indexOf(lo) === -1)
                list.push(email);
        });
        input.value = '';
        render();
    }

    function seed(csv) {
        if (!csv) return;
        csv.split(',').forEach(function(part){ add(part.trim()); });
    }

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
            e.preventDefault();
            if (input.value.trim()) add(input.value);
        } else if (e.key === 'Backspace' && !input.value && list.length) {
            list.pop(); render();
        }
    });
    input.addEventListener('blur', function() {
        if (input.value.trim()) add(input.value);
    });

    return { add: add, seed: seed, getAll: function(){ return list.slice(); } };
}

var toBox  = PillBox('rpToPills',  'rpToInp');
var ccBox  = PillBox('rpCcPills',  'rpCcInp');
var bccBox = PillBox('rpBccPills', 'rpBccInp');

// ── CC / BCC TOGGLES (must be defined before seed so rpShowCc() is callable) ─
window.rpShowCc = function() {
    document.getElementById('rpCcRow').style.display = '';
    document.getElementById('rpCcToggle').style.display = 'none';
};
window.rpShowBcc = function() {
    document.getElementById('rpBccRow').style.display = '';
    document.getElementById('rpBccToggle').style.display = 'none';
};

// Seed recipients
toBox.seed(initTo);
if (initCc) { rpShowCc(); ccBox.seed(initCc); }

// Focus: forward → To field, reply → compose body
setTimeout(function(){
    if (MODE === 'forward') document.getElementById('rpToInp').focus();
    else document.getElementById('rpBody').focus();
}, 120);

// ── ATTACHMENTS ───────────────────────────────────────────
var attachFiles = [];
window.rpAddFiles = function(files) {
    Array.prototype.forEach.call(files, function(f){ attachFiles.push(f); });
    renderAttach();
};
window.rpRemoveFile = function(i) { attachFiles.splice(i,1); renderAttach(); };
function renderAttach() {
    var row = document.getElementById('rpAttachRow');
    if (!attachFiles.length) { row.style.display='none'; row.innerHTML=''; return; }
    row.style.display = 'flex';
    row.innerHTML = attachFiles.map(function(f,i){
        return '<span class="rp-attach-chip">&#128206; '+esc(f.name)
              +' <span style="color:#94a3b8;">('+Math.round(f.size/1024)+'KB)</span>'
              +'<button onclick="rpRemoveFile('+i+')">&#10005;</button></span>';
    }).join('');
}
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── ORIG MESSAGE TOGGLE ───────────────────────────────────
var origOpen = true;
window.rpToggleOrig = function() {
    origOpen = !origOpen;
    document.getElementById('rpOrigPanel').style.display = origOpen ? '' : 'none';
    document.getElementById('rpOrigIco').textContent = origOpen ? '▼' : '▶';
};
window.rpResizeIframe = function(f) {
    try {
        var h = f.contentDocument ? f.contentDocument.documentElement.scrollHeight : 400;
        f.style.height = (h + 24) + 'px';
    } catch(e) { f.style.height = '400px'; }
};

// ── BUILD QUOTED BLOCK FOR SENT EMAIL ────────────────────
function buildQuotedBlock() {
    var sep = MODE === 'forward'
        ? '<div style="font-weight:700;color:#334155;margin-bottom:8px;">---------- Forwarded message ---------</div>'
        : '<div style="font-weight:700;color:#334155;margin-bottom:8px;">On '+esc(Q.date)+', '+esc(Q.from)+' wrote:</div>';
    return '<div style="border-top:2px solid #e2e8f0;margin-top:20px;padding-top:14px;font-size:13px;font-family:\'Google Sans\',Roboto,Arial,sans-serif;color:#64748b;">'
        + sep
        + '<div style="margin-bottom:10px;line-height:1.9;">'
        + '<b>From:</b> '+esc(Q.from)+(Q.fromEmail&&Q.fromEmail!==Q.from?' &lt;'+esc(Q.fromEmail)+'&gt;':'')+'<br>'
        + '<b>Date:</b> '+esc(Q.date)+'<br>'
        + '<b>Subject:</b> '+esc(Q.subject)+'<br>'
        + '<b>To:</b> '+esc(Q.to)+'<br>'
        + (Q.cc ? '<b>Cc:</b> '+esc(Q.cc)+'<br>' : '')
        + '</div>'
        + '<blockquote style="border-left:3px solid #cbd5e1;margin:0;padding-left:14px;color:#374151;">'
        + Q.body
        + '</blockquote>'
        + '</div>';
}

// ── SEND ──────────────────────────────────────────────────
window.rpSend = function() {
    var toList  = toBox.getAll();
    var ccList  = ccBox.getAll();
    var bccList = bccBox.getAll();
    var subj    = (document.getElementById('rpSubInp').value || '').trim();
    var bodyEl  = document.getElementById('rpBody');
    var sigEl   = document.getElementById('rpSig');
    var btn     = document.getElementById('rpSendBtn');
    var status  = document.getElementById('rpStatus');

    if (!toList.length) { rpStatus('Recipient is required.', false); return; }
    if (!subj)          { rpStatus('Subject is required.',   false); return; }

    var composeHtml = bodyEl.innerHTML || '';
    var sigHtml     = sigEl ? sigEl.innerHTML : '';
    var fullBody    = composeHtml
        + (sigHtml.trim() ? '<br><br>' + sigHtml : '')
        + buildQuotedBlock();

    btn.disabled  = true;
    btn.innerHTML = '&#8987;&nbsp; Sending...';

    var fd = new FormData();
    fd.append('to',      toList.join(','));
    fd.append('cc',      ccList.join(','));
    fd.append('bcc',     bccList.join(','));
    fd.append('subject', subj);
    fd.append('body',    fullBody);
    attachFiles.forEach(function(f){ fd.append('attachments[]', f, f.name); });

    fetch('<?= APP_URL ?>/api/mail/send.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': CSRF },
        body: fd
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.ok) {
            rpStatus('&#10003; Message sent! Returning to inbox...', true);
            btn.innerHTML = '&#10003;&nbsp; Sent';
            setTimeout(function(){ window.location = BACK; }, 1500);
        } else {
            rpStatus(d.error || 'Send failed. Please try again.', false);
            btn.disabled  = false;
            btn.innerHTML = '&#9658;&nbsp; Send';
        }
    })
    .catch(function(){
        rpStatus('Network error. Please try again.', false);
        btn.disabled  = false;
        btn.innerHTML = '&#9658;&nbsp; Send';
    });
};

function rpStatus(msg, ok) {
    var el = document.getElementById('rpStatus');
    el.innerHTML  = msg;
    el.className  = 'rp-status ' + (ok ? 'ok' : 'er');
    el.style.display = 'block';
    if (!ok) el.scrollIntoView({behavior:'smooth', block:'nearest'});
}

window.rpDiscard = function() {
    var body = document.getElementById('rpBody');
    var hasContent = (body && body.textContent.trim()) || toBox.getAll().length > 0;
    if (hasContent) {
        if (!confirm('Discard this message?')) return;
    }
    window.location = BACK;
};

// Ctrl+Enter / Cmd+Enter to send
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') window.rpSend();
});

})();
</script>
<?php Layout::end(); ?>
