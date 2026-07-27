<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/ImapHelper.php';

$user   = Auth::require();
$db     = getDB();
$folder = $_GET['f'] ?? 'INBOX';
$uid    = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$page   = max(1,(int)($_GET['p'] ?? 1));
$perPage = 50;
$mp = $_SESSION['mail_pass'] ?? '';
// Allow standard folders plus any custom INBOX.* folder
$stdFolders = ['INBOX','Sent','Drafts','Trash','Spam','Starred'];
if (!in_array($folder, $stdFolders) && !preg_match('/^INBOX\.[A-Za-z0-9_\- ]+$/', $folder)) {
    $folder = 'INBOX';
}
// Cache custom IMAP folder list in session (refreshed every 30 min)
if ($mp && (empty($_SESSION['imap_folders_ts']) || time()-$_SESSION['imap_folders_ts'] > 1800)) {
    try {
        $allFolders = ImapHelper::listFolders($user['email'], $mp);
        $sysSet = ['INBOX','INBOX.Sent','INBOX.Trash','INBOX.Drafts','INBOX.Junk E-mail','INBOX.Spam','INBOX.CCA','INBOX.Archive','INBOX.Cognito','INBOX.Trap25','INBOX.Junk'];
        $customFolders = [];
        foreach ($allFolders as $f) {
            if (!in_array($f,$sysSet) && strpos($f,'INBOX.') === 0) {
                $customFolders[] = ['full'=>$f,'display'=>str_replace('INBOX.','',$f)];
            }
        }
        $_SESSION['imap_custom_folders'] = $customFolders;
        $_SESSION['imap_folders_ts']     = time();
    } catch (Exception $e) {}
}
$isStarred = ($folder === 'Starred');

// ── AJAX QUICK DELETE ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['quick_delete'])) {
    header('Content-Type: application/json');
    $dUid=(int)($_POST['uid']??0);
    if ($dUid && $mp) {
        $mbox=ImapHelper::open($user['email'],$mp,$folder);
        if ($mbox) {
            $inTrash = ($folder === 'Trash');
            if ($inTrash) {
                // Permanent delete from Trash
                imap_delete($mbox, (string)$dUid, FT_UID);
                imap_expunge($mbox);
                Auth::auditLog($user['id'],'email_purged',"Permanently deleted UID $dUid from Trash");
            } else {
                $trash=ImapHelper::resolveFolder($user['email'],$mp,'Trash');
                @imap_mail_move($mbox,(string)$dUid,$trash,CP_UID);
                imap_expunge($mbox);
                // Trash move is routine — only permanent deletes (email_purged above) are logged
            }
            imap_close($mbox);
            $db->prepare("DELETE FROM mail_cache WHERE user_id=? AND uid=? AND folder=?")->execute([$user['id'],$dUid,$folder]);
            echo json_encode(['ok'=>true,'purged'=>$inTrash]); exit;
        }
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── AJAX BULK ACTIONS ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_action'])) {
    header('Content-Type: application/json');
    $action=$_POST['bulk_action'];
    $uids=array_filter(array_map('intval',$_POST['uids']??[]));
    if ($uids && $mp) {
        $mbox=ImapHelper::open($user['email'],$mp,$folder);
        if ($mbox) {
            $uidStr=implode(',',$uids);
            if ($action==='delete') {
                if ($folder === 'Trash') {
                    // Permanently delete from Trash
                    foreach ($uids as $u2) { imap_delete($mbox, (string)$u2, FT_UID); }
                    imap_expunge($mbox);
                } else {
                    $t=ImapHelper::resolveFolder($user['email'],$mp,'Trash');
                    @imap_mail_move($mbox,$uidStr,$t,CP_UID);
                    imap_expunge($mbox);
                }
            }
            elseif ($action==='read')   imap_setflag_full($mbox,$uidStr,'\\Seen',ST_UID);
            elseif ($action==='unread') imap_clearflag_full($mbox,$uidStr,'\\Seen',ST_UID);
            elseif ($action==='star')   imap_setflag_full($mbox,$uidStr,'\\Flagged',ST_UID);
            imap_close($mbox);
            echo json_encode(['ok'=>true,'count'=>count($uids)]); exit;
        }
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── LOAD EMAILS ──────────────────────────────────────────
$emails=[]; $selected=null; $bodyHtml=''; $attachments=[]; $error=''; $total=0;
$avColors=['#002850','#64A014','#8b5cf6','#f97316','#3b82f6','#f59e0b','#0891b2','#dc2626'];
function avColor($s,$c){return $c[abs(crc32($s))%count($c)];}

if (!$mp) {
    $error='Session expired. Please log out and back in.';
} else {
    try {
        @imap_timeout(IMAP_OPENTIMEOUT,8); @imap_timeout(IMAP_READTIMEOUT,12);
        // For Starred, open INBOX and search FLAGGED
        $imapFolder = $isStarred ? 'INBOX' : $folder;
        $mbox=ImapHelper::open($user['email'],$mp,$imapFolder);
        if (!$mbox) { $error='Cannot open '.htmlspecialchars($folder).'. '.htmlspecialchars(imap_last_error()); }
        else {
            // Get real total unseen count from INBOX (not page-scoped)
            if (!$isStarred && $folder === 'INBOX') {
                $chk = @imap_check($mbox);
                $unread = $chk ? (int)($chk->Unseen ?? 0) : 0;
            }
            if ($isStarred) {
                // Search for flagged/starred messages
                $flaggedMsgs = @imap_search($mbox,'FLAGGED') ?: [];
                $total = count($flaggedMsgs);
                $paged = array_slice(array_reverse($flaggedMsgs), ($page-1)*$perPage, $perPage);
                $ovs   = $total > 0 ? (@imap_fetch_overview($mbox, implode(',',$paged)) ?: []) : [];
            } else {
                $total=imap_num_msg($mbox);
                $start=$total-(($page-1)*$perPage);
                $end=max(1,$start-$perPage+1);
                if ($total>0) {
                    $ovs=@imap_fetch_overview($mbox,"$end:$start") ?: [];
                    $ovs=array_reverse($ovs);
                }
            } // end else (non-starred)
            if ($total>0 || $isStarred) {
                $cacheRows = [];
                foreach ($ovs as $o) {
                    $subj=ImapHelper::decodeSubject($o->subject??'');
                    $from=ImapHelper::decodeSender($o->from??'');
                    $fromEmail='';
                    if (preg_match('/<([^>]+)>/',$o->from??'',$m2)) $fromEmail=$m2[1];
                    $ts=isset($o->date)?strtotime($o->date):0;
                    $mUid=$o->uid??$o->msgno;
                    $ini=strtoupper(substr(preg_replace('/[^A-Za-z ]/','',trim($from)),0,1));
                    if (strpos($from,' ')!==false) $ini.=strtoupper(substr(strrchr($from,' '),1,1));
                    $emails[]=['uid'=>$mUid,'from'=>$from,'from_email'=>$fromEmail,'subject'=>$subj?:'(no subject)','date'=>$ts,'unread'=>!($o->seen??false),'starred'=>(bool)($o->flagged??false),'answered'=>(bool)($o->answered??false),'initials'=>($ini)?:'?'];
                    if ($total<500||($mUid%10)===0) {
                        $cacheRows[] = [(int)$user['id'],$mUid,$folder,$from,$fromEmail,$subj?:'(no subject)',($o->seen??false)?1:0,$ts?date('Y-m-d H:i:s',$ts):null];
                    }
                }
                if (!empty($cacheRows)) {
                    try {
                        $ph = implode(',', array_fill(0, count($cacheRows), '(?,?,?,?,?,?,?,?)'));
                        $db->prepare("INSERT INTO mail_cache (user_id,uid,folder,from_name,from_email,subject,is_read,date_sent) VALUES $ph ON DUPLICATE KEY UPDATE from_name=VALUES(from_name),subject=VALUES(subject),is_read=VALUES(is_read)")
                           ->execute(array_merge(...$cacheRows));
                    } catch(Exception $e) {}
                }
            }
            // Open selected message
            if ($uid) {
                $msgNo=imap_msgno($mbox,$uid);
                if ($msgNo) {
                    $hdr=@imap_headerinfo($mbox,$msgNo);
                    if ($hdr) {
                        $fromObj=$hdr->from[0]??null;
                        $fromDisp=$fromObj?(imap_utf8($fromObj->personal??'')?:($fromObj->mailbox.'@'.$fromObj->host)):'Unknown';
                        $fromAddr=$fromObj?$fromObj->mailbox.'@'.$fromObj->host:'';
                        $toArr=[];
                        foreach ($hdr->to??[] as $t){$n2=imap_utf8($t->personal??'');$toArr[]=($n2?"$n2 <{$t->mailbox}@{$t->host}>":"{$t->mailbox}@{$t->host}");}
                        $ccArr=[];
                        foreach ($hdr->cc??[] as $t){$n2=imap_utf8($t->personal??'');$ccArr[]=($n2?"$n2 <{$t->mailbox}@{$t->host}>":"{$t->mailbox}@{$t->host}");}
                        $subjDisp=ImapHelper::decodeSubject(imap_utf8($hdr->subject??''));
                        $body=ImapHelper::getBody($mbox,$msgNo);
                        $attachments=$body['attachments'];
                        $bodyHtml=$body['html']?ImapHelper::sanitizeHtml($body['html']):ImapHelper::plainToHtml($body['plain']);
                        // Replace CID references with proxied attachment URLs
                        if (!empty($body['cid_map']) && $bodyHtml) {
                            foreach ($body['cid_map'] as $cid => $partNum) {
                                $proxyUrl = APP_URL.'/api/mail/attachment.php?uid='.$uid
                                          .'&folder='.urlencode($folder)
                                          .'&part='.urlencode($partNum)
                                          .'&name=inline';
                                $bodyHtml = str_replace(
                                    ['src="cid:'.$cid.'"', "src='cid:".$cid."'"],
                                    ['src="'.$proxyUrl.'"', 'src="'.$proxyUrl.'"'],
                                    $bodyHtml
                                );
                            }
                        }
                        $selected=['uid'=>$uid,'subject'=>$subjDisp?:'(no subject)','from'=>$fromDisp,'from_email'=>$fromAddr,'to'=>implode(', ',$toArr),'cc'=>implode(', ',$ccArr),'date'=>date('D, d M Y H:i',$hdr->udate??time()),'seen'=>empty($hdr->Unseen),'flagged'=>($hdr->Flagged??'')==='F'];
                        imap_setflag_full($mbox,(string)$uid,'\\Seen',ST_UID);
                        $db->prepare("UPDATE mail_cache SET is_read=1 WHERE user_id=? AND uid=? AND folder=?")->execute([$user['id'],$uid,$folder]);
                        Auth::trackUsage($user['id'],'emails_read');
                        foreach($emails as &$em){if($em['uid']==$uid)$em['unread']=false;}unset($em);
                    }
                }
            }
            imap_close($mbox);
        }
    } catch(Exception $e){ $error='Error: '.$e->getMessage(); }
}

// $unread is set from real IMAP check when in INBOX; page-scoped fallback for other folders
if (!isset($unread) || ($folder !== 'INBOX' && !$isStarred)) {
    $unread = count(array_filter($emails, function($e){ return $e['unread']; }));
}
$totalPages=max(1,ceil($total/$perPage));
$role=ROLES[$user['role']];

// ⑦ THREAD GROUPING — group emails by normalized subject
function normalizeSubject($s) {
    $s = trim($s);
    // Strip all leading Re:/Fwd:/etc prefixes (chain of any depth)
    while (preg_match('/^(Re|Fwd?|Fw|Aw|Sv|VS|TR|RES|AW):\s*/i', $s)) {
        $s = preg_replace('/^(Re|Fwd?|Fw|Aw|Sv|VS|TR|RES|AW):\s*/i', '', $s);
    }
    return strtolower(trim($s));
}
if (!$uid && !$isStarred && count($emails) > 1) {
    $threads = [];
    $threadMap = [];
    foreach ($emails as $em) {
        $key = normalizeSubject($em['subject']);
        if (!isset($threadMap[$key])) {
            $threadMap[$key] = count($threads);
            $em['thread_count'] = 1;
            $em['thread_key']   = $key;
            $em['thread_msgs']  = [$em['uid']];
            $threads[] = $em;
        } else {
            $idx = $threadMap[$key];
            $threads[$idx]['thread_count']++;
            $threads[$idx]['thread_msgs'][] = $em['uid'];
            // Keep unread=true if any msg is unread
            if ($em['unread']) $threads[$idx]['unread'] = true;
            // Keep most recent date
            if ($em['date'] > $threads[$idx]['date']) {
                $threads[$idx]['date']     = $em['date'];
                $threads[$idx]['from']     = $em['from'];
                $threads[$idx]['initials'] = $em['initials'];
            }
        }
    }
    $emails = $threads;
}

// Layout shell
require_once __DIR__ . '/lib/Layout.php';
$_navKeys   = ['INBOX'=>'inbox','Starred'=>'starred','Sent'=>'sent','Drafts'=>'drafts','Trash'=>'trash','Spam'=>'spam'];
$_navActive = $_navKeys[$folder] ?? 'inbox';
Layout::shell($user, $_navActive, $unread, ucfirst($folder));
?>

<style>
/* ── MAIL PAGE SPECIFIC ── */
.mail-layout{display:grid;grid-template-columns:300px 1fr;height:calc(100vh - var(--top-h));overflow:hidden;}
.mail-list-pane{border-right:1px solid var(--g200);background:var(--w);display:flex;flex-direction:column;overflow:hidden;}
.mail-view-pane{background:var(--w);display:flex;flex-direction:column;height:100%;overflow:hidden;}

/* List header */
.ml-hd{padding:10px 12px 8px;border-bottom:1px solid var(--g100);display:flex;align-items:center;gap:7px;flex-shrink:0;}
.ml-title{font-size:14px;font-weight:700;color:var(--navy);flex:1;}
.ml-count{font-size:11px;color:var(--g400);}
.sel-all{padding:3px 8px;border-radius:5px;border:1px solid var(--g200);background:var(--w);color:var(--g500);font-size:11px;cursor:pointer;font-family:'Inter',sans-serif;}

/* Bulk bar */
.bulk-bar{background:var(--nl);border-bottom:1px solid var(--g300);padding:7px 12px;display:none;align-items:center;gap:6px;flex-shrink:0;}
.bulk-bar.show{display:flex;}
.bulk-cnt{font-size:12px;font-weight:600;color:var(--navy);flex:1;}
.bb{padding:3px 9px;border-radius:5px;border:1.5px solid var(--g300);background:var(--w);color:var(--g700);font-size:11.5px;cursor:pointer;font-family:'Inter',sans-serif;}
.bb:hover{border-color:var(--navy);color:var(--navy);}
.bb.rd:hover{border-color:var(--red);color:var(--red);}

/* Error bar */
.err-bar{background:#fee2e2;border-bottom:1px solid #fca5a5;padding:8px 14px;font-size:12.5px;color:var(--red);flex-shrink:0;}

/* Mail items */
.mail-list{flex:1;overflow-y:auto;}
.mi{display:flex;align-items:flex-start;padding:10px 11px 10px 16px;gap:8px;border-bottom:1px solid var(--g100);cursor:pointer;transition:background .1s;position:relative;user-select:none;}
.mi:hover{background:var(--g50);}
.mi:hover .qdel{opacity:1;}
.mi.unread{background:linear-gradient(90deg,rgba(100,160,20,.04),transparent);}
.mi.unread .mf,.mi.unread .ms{font-weight:700;}
.mi.active{background:var(--nl);border-left:3px solid var(--navy);}
.mchk{width:16px;height:16px;border-radius:3px;border:1.5px solid var(--g300);cursor:pointer;flex-shrink:0;margin-top:3px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;background:#fff;transition:all .12s;}
.mchk:hover{border-color:var(--navy);}
.mchk.on{background:var(--navy);border-color:var(--navy);}
/* Unread dot: absolute so it never shifts column alignment */
.udot{position:absolute;left:5px;top:50%;transform:translateY(-50%);width:6px;height:6px;border-radius:50%;background:var(--green);pointer-events:none;}
.mav{width:32px;height:32px;border-radius:50%;color:#fff;font-weight:700;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.mi-info{flex:1;min-width:0;}
.mf{font-size:12.5px;color:var(--g900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ms{font-size:12px;color:var(--g500);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;}
.mi-right{display:flex;flex-direction:column;align-items:flex-end;gap:2px;flex-shrink:0;}
.mt{font-size:10.5px;color:var(--g400);}
.mi.unread .mt{color:#4d7c10;font-weight:600;}
.qdel{position:absolute;right:5px;top:50%;transform:translateY(-50%);width:22px;height:22px;border-radius:4px;background:#fee2e2;border:none;color:var(--red);font-size:11px;cursor:pointer;opacity:0;display:flex;align-items:center;justify-content:center;}
.mi-qacts{display:flex;gap:2px;visibility:hidden;align-items:center;margin-top:2px;}
.mi:hover .mi-qacts,.mi-qacts.has-flag{visibility:visible;}
.mi-qa{background:none;border:none;cursor:pointer;width:18px;height:18px;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--g400);padding:0;}
.mi-qa:hover{background:var(--g100);color:var(--navy);}
.mi-qa.flag-on{color:#ef4444;}

/* Pagination */
.ml-pgn{padding:7px 12px;border-top:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;background:var(--g50);}
.pg-info{font-size:10.5px;color:var(--g400);}
.pg-btns{display:flex;gap:4px;}
.pg-btn{padding:3px 9px;border-radius:5px;border:1px solid var(--g200);background:var(--w);color:var(--g700);font-size:11px;text-decoration:none;cursor:pointer;font-family:'Inter',sans-serif;}
.pg-btn:hover{border-color:var(--navy);color:var(--navy);}
.pg-btn.on{background:var(--navy);color:#fff;border-color:var(--navy);}

/* Mail view */
.mv-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--g400);gap:10px;}
.mv-empty-ico{font-size:56px;opacity:.25;}
.mv-hd{padding:16px 20px 12px;border-bottom:1px solid var(--g100);flex-shrink:0;}
.mv-subj{font-size:17px;font-weight:700;color:var(--g900);margin-bottom:10px;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;}
.mv-meta{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;}
.mv-av{width:36px;height:36px;border-radius:50%;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.mv-meta-body{flex:1;min-width:0;}
.mv-from{font-size:13px;font-weight:600;color:var(--g900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.mv-addr{font-size:11.5px;color:var(--g400);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.mv-date{flex-shrink:0;font-size:11.5px;color:var(--g400);margin-left:8px;white-space:nowrap;}
.mv-acts{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
.mv-acts-del{margin-left:auto;display:flex;}
.act-btn{padding:7px 14px;border-radius:20px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid var(--g300);background:var(--w);color:var(--g700);display:inline-flex;align-items:center;gap:5px;transition:all .12s;font-family:'Google Sans',Roboto,Arial,sans-serif;text-decoration:none;box-shadow:0 1px 2px rgba(0,0,0,.1);}
.act-btn:hover{background:var(--g100);border-color:var(--g300);box-shadow:0 1px 3px rgba(0,0,0,.15);}
.act-btn.primary{background:var(--nl2);color:var(--navy);border-color:var(--nl2);}
.act-btn.primary:hover{background:#c5d8fc;}
.act-btn.green{background:#e6f4ea;color:#137333;border-color:#e6f4ea;}
.act-btn.green:hover{background:#d4edda;}
.act-btn.danger{border-color:var(--g300);color:var(--red);}
.act-btn.danger:hover{background:#fce8e6;}
.act-btn.ai-active{background:var(--gl);color:var(--gd);border-color:var(--green);}
.att-row{padding:8px 20px;border-bottom:1px solid var(--g100);display:flex;gap:7px;flex-wrap:wrap;flex-shrink:0;background:var(--g50);}
.att{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:var(--w);border:1px solid var(--g200);border-radius:6px;font-size:12px;color:var(--g700);}
.att-link{color:var(--navy);opacity:.7;font-size:13px;cursor:pointer;background:none;border:none;padding:0 2px;}
.att-link:hover{opacity:1;}
/* AI strip — sits between action bar and email body, toggled by button */
.ai-strip{display:none;border-bottom:1px solid var(--g100);flex-shrink:0;}
.ai-strip.open{display:block;}
.ai-panel{background:linear-gradient(135deg,var(--gl),var(--nl));padding:10px 20px;border-bottom:1px solid var(--g200);}
.ai-lbl{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--gd);margin-bottom:3px;}
.ai-txt{font-size:12px;color:var(--g700);line-height:1.55;}
.ai-go{margin-top:5px;padding:4px 11px;border-radius:5px;background:var(--navy);color:#fff;border:none;font-size:11.5px;cursor:pointer;font-family:'Inter',sans-serif;}
.sr-row{padding:7px 20px;display:flex;gap:6px;flex-wrap:wrap;}
.sr{padding:4px 12px;border-radius:20px;background:var(--w);border:1.5px solid var(--g200);color:var(--g700);font-size:12px;cursor:pointer;font-family:'Inter',sans-serif;}
.sr:hover{border-color:var(--navy);color:var(--navy);background:var(--nl);}
.mv-html{height:100%;overflow:hidden;margin:0;padding:0;}
.mv-iframe{width:100%;border:none;display:block;min-height:200px;}

/* ── MOBILE SPECIFIC ── */
@media(max-width:680px){
    .mail-layout{grid-template-columns:1fr;height:auto;}
    .mail-list-pane{height:auto;border-right:none;}
    .mail-view-pane{display:none;position:fixed;inset:0;top:var(--top-h);z-index:250;overflow-y:auto;}
    .mail-view-pane.mob-open{display:flex;flex-direction:column;}
    .mob-back{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--g100);background:var(--w);font-size:13.5px;font-weight:600;color:var(--navy);cursor:pointer;flex-shrink:0;}
    .mv-acts{gap:4px;}
    .act-btn{padding:5px 9px;font-size:12px;}
    .mv-subj{font-size:15px;}
}
@media(min-width:681px){
    .mob-back{display:none;}
}
.mv-body{flex:1;min-height:0;overflow-y:auto;position:relative;}
</style>

<div class="mail-layout">
    <!-- MAIL LIST -->
    <div class="mail-list-pane">
        <?php $pgFrom=($page-1)*$perPage+1; $pgTo=min($page*$perPage,$total); ?>
        <div class="ml-hd">
            <div>
                <div class="ml-title"><?=htmlspecialchars($folder)?></div>
                <div class="ml-count"><?=$total>0?$pgFrom.'–'.$pgTo.' of '.number_format($total):'0 messages'?></div>
            </div>
            <button class="sel-all" onclick="toggleSelAll()">&#9745; All</button>
        </div>
        <?php if($error): ?><div class="err-bar">&#9888; <?=$error?></div><?php endif; ?>
        <div class="bulk-bar" id="bulkBar">
            <span class="bulk-cnt" id="bulkCnt">0 selected</span>
            <button class="bb" onclick="bulkAction('read')">Read</button>
            <button class="bb" onclick="bulkAction('unread')">Unread</button>
            <button class="bb" onclick="bulkAction('star')" title="Flag as important">&#9873; Flag</button>
            <button class="bb rd" onclick="bulkAction('delete')">&#128465; Delete</button>
            <button class="bb" onclick="deselAll()">&#10005;</button>
        </div>
        <div class="mail-list" id="mailList">
            <?php if(empty($emails)&&!$error): ?>
            <div style="padding:32px;text-align:center;color:var(--g400);font-size:13px;">No messages in <?=htmlspecialchars($folder)?></div>
            <?php endif; ?>
            <?php foreach($emails as $em):
                $col=avColor($em['initials'],$avColors);
                $isActive=($uid==$em['uid']);
                $ts2=$em['date'];
                if($ts2&&date('Ymd',$ts2)===date('Ymd')) $dStr=date('H:i',$ts2);
                elseif($ts2&&date('Y',$ts2)===date('Y')) $dStr=date('d M',$ts2);
                else $dStr=$ts2?date('d M Y',$ts2):'';
            ?>
            <div class="mi<?=$em['unread']?' unread':''?><?=$isActive?' active':''?>"
                 id="mi<?=$em['uid']?>" data-uid="<?=$em['uid']?>"
                 onclick="openMail(<?=$em['uid']?>)">
                <div class="mchk" id="ck<?=$em['uid']?>" onclick="event.stopPropagation();toggleChk(<?=$em['uid']?>)"></div>
                <?php if($em['unread']): ?><div class="udot"></div><?php endif; ?>
                <div class="mav" style="background:<?=$col?>"><?=htmlspecialchars($em['initials'])?></div>
                <div class="mi-info">
                    <div class="mf" style="display:flex;align-items:center;gap:4px;">
                        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($em['from'])?></span>
                        <?php if(($em['thread_count']??1)>1): ?>
                        <span style="flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
                            background:var(--g300);color:var(--g700);border-radius:99px;
                            font-size:11px;font-weight:700;padding:1px 7px;">
                            <?=$em['thread_count']?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="ms"><?=htmlspecialchars($em['subject'])?></div>
                </div>
                <div class="mi-right">
                    <div class="mt"><?=$dStr?></div>
                    <div class="mi-qacts<?=$em['starred']?' has-flag':''?>">
                        <button class="mi-qa<?=$em['starred']?' flag-on':''?>"
                            onclick="event.stopPropagation();quickFlag(<?=$em['uid']?>,<?=$em['starred']?'true':'false'?>)"
                            title="<?=$em['starred']?'Remove flag':'Flag as important'?>">&#9873;</button>
                        <?php if(!$em['unread']): ?>
                        <button class="mi-qa"
                            onclick="event.stopPropagation();quickMarkUnread(<?=$em['uid']?>)"
                            title="Mark as unread">&#128262;</button>
                        <?php endif; ?>
                    </div>
                </div>
                <button class="qdel" onclick="event.stopPropagation();quickDel(<?=$em['uid']?>)" title="Delete">&#128465;</button>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="ml-pgn">
            <div class="pg-info"><?=$totalPages>1?'Page '.$page.' / '.$totalPages:''?></div>
            <div class="pg-btns">
                <?php if($page>1): ?><a class="pg-btn" href="mail.php?f=<?=urlencode($folder)?>&p=<?=$page-1?>">&larr; Prev</a><?php endif; ?>
                <?php if($page<$totalPages): ?><a class="pg-btn" href="mail.php?f=<?=urlencode($folder)?>&p=<?=$page+1?>">Next &rarr;</a><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- MAIL VIEW -->
    <div class="mail-view-pane<?=$selected?' mob-open':''?>" id="mailViewPane">
        <!-- Mobile back button -->
        <div class="mob-back" onclick="mobileBack()">&#8592; Back to <?=htmlspecialchars($folder)?></div>

        <?php if($selected):
            $fIni=strtoupper(substr(preg_replace('/[^A-Za-z ]/','',trim($selected['from'])),0,1));
            if(strpos($selected['from'],' ')!==false) $fIni.=strtoupper(substr(strrchr($selected['from'],' '),1,1));
            $fCol=avColor($fIni,$avColors);
        ?>
        <div class="mv-hd">
            <div class="mv-subj" title="<?=htmlspecialchars($selected['subject'])?>"><?=htmlspecialchars($selected['subject'])?></div>
            <div class="mv-meta">
                <div class="mv-av" style="background:<?=$fCol?>"><?=htmlspecialchars($fIni)?></div>
                <div class="mv-meta-body">
                    <div class="mv-from" title="<?=htmlspecialchars($selected['from'])?>"><?=htmlspecialchars($selected['from'])?></div>
                    <div class="mv-addr" title="To: <?=htmlspecialchars($selected['to'])?>">To: <?=htmlspecialchars($selected['to'])?></div>
                    <?php if (!empty($selected['cc'])): ?>
                    <div class="mv-addr" title="CC: <?=htmlspecialchars($selected['cc'])?>">CC: <?=htmlspecialchars($selected['cc'])?></div>
                    <?php endif; ?>
                </div>
                <div class="mv-date"><?=$selected['date']?></div>
            </div>
            <div class="mv-acts">
                <button class="act-btn primary" id="btnReply">&#8617; Reply</button>
                <button class="act-btn" id="btnReplyAll">&#8617;&#8617; Reply All</button>
                <button class="act-btn" id="btnFwd">&#8618; Forward</button>
                <button class="act-btn" id="btnToggleRead" onclick="toggleRead(<?=$selected['uid']?>,<?=$selected['seen']?'true':'false'?>)">
                    <?=$selected['seen']?'&#128262; Mark Unread':'&#128261; Mark Read'?>
                </button>
                <button class="act-btn<?=$selected['flagged']?' on':''?>" id="btnStar" onclick="toggleStar(<?=$selected['uid']?>,<?=$selected['flagged']?'true':'false'?>)" title="Flag as important" style="<?=$selected['flagged']?'color:var(--red);':''?>">
                    &#9873; <?=$selected['flagged']?'Flagged':'Flag'?>
                </button>
                <button class="act-btn" onclick="printEmail()" title="Print email">&#128438; Print</button>
                <button class="act-btn green" onclick="addTaskFromMail(<?=$selected['uid']?>,<?=htmlspecialchars(json_encode($selected['subject']??'(no subject)'),ENT_QUOTES)?>)">&#9989; Task</button>
                <button class="act-btn" onclick="saveVault(<?=$selected['uid']?>)">&#128193; Vault</button>
                <button class="act-btn" id="btnAi" onclick="toggleAiStrip()">&#129302; AI Assist</button>
                <div class="mv-acts-del">
                    <button class="act-btn danger" onclick="quickDel(<?=$selected['uid']?>)">&#128465;</button>
                </div>
            </div>
        </div>
        <?php if(!empty($attachments)): ?>
        <div class="att-row">
            <span style="font-size:10.5px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.05em;">&#128206; <?=count($attachments)?> Attachment<?=count($attachments)>1?'s':''?></span>
            <?php foreach($attachments as $att):
                $base=APP_URL.'/api/mail/attachment.php?uid='.$uid.'&folder='.urlencode($folder).'&part='.urlencode($att['partNum']).'&name='.urlencode($att['name']);
                $ext=strtolower(pathinfo($att['name'],PATHINFO_EXTENSION));
                $prev=in_array($ext,['pdf','jpg','jpeg','png','gif','webp','svg']);
            ?>
            <span class="att">
                <?php
                $attIcon = in_array($ext,['pdf']) ? '📄' : (in_array($ext,['jpg','jpeg','png','gif','webp','svg','bmp']) ? '🖼️' : (in_array($ext,['zip','rar','7z']) ? '🗜️' : (in_array($ext,['doc','docx']) ? '📝' : (in_array($ext,['xls','xlsx','csv']) ? '📊' : '📎'))));
                ?>
                <?=$attIcon?> <?=htmlspecialchars($att['name'])?>
                <button class="att-link" onclick="openAttModal('<?=$base?>&inline=1',<?=json_encode($att['name'])?>,<?=json_encode($ext)?>)" title="View">&#128065;</button>
                <a class="att-link" href="<?=$base?>" title="Download" download>&#11015;</a>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <!-- AI strip — collapsed by default, shown when AI Assist button clicked -->
        <div class="ai-strip" id="aiStrip">
            <div class="ai-panel">
                <div class="ai-lbl">&#129302; AI Summary</div>
                <div class="ai-txt" id="aiTxt">Click to generate a summary of this email.</div>
                <button class="ai-go" id="aiBtn" onclick="getSummary(<?=$selected['uid']?>)">Generate Summary</button>
            </div>
            <div class="sr-row" id="srRow">
                <span style="font-size:9.5px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;align-self:center;">Smart Replies</span>
                <button class="sr" onclick="getSmartReplies(<?=$selected['uid']?>)">Generate smart replies...</button>
            </div>
        </div>
        <!-- Email body (scroll area — AI is now outside) -->
        <div class="mv-body">
            <?php
$emailStyle = '<style>html,body{margin:0;padding:0;}body{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#334155;padding:18px 22px;word-break:break-word;}a{color:#002850;}img{max-width:100%;height:auto;}table{border-collapse:collapse;max-width:100%!important;}blockquote{border-left:3px solid #e2e8f0;padding-left:12px;color:#64748b;margin:10px 0;}</style>';
$emailContent = $bodyHtml ?: '<p style="color:#94a3b8;font-style:italic;">Email body is empty or could not be loaded.</p>';
$srcdocContent = '<!DOCTYPE html><html><head><meta charset="UTF-8"/>' . $emailStyle . '</head><body>' . $emailContent . '</body></html>';
?>
<iframe class="mv-iframe" id="mailFrame"
        sandbox="allow-same-origin allow-popups"
        title="Email content"
        srcdoc="<?=htmlspecialchars($srcdocContent, ENT_QUOTES, 'UTF-8')?>"></iframe>
        </div>

        <?php else: ?>
        <div class="mv-empty">
            <div class="mv-empty-ico">&#128236;</div>
            <div style="font-size:14px;font-weight:500;color:var(--g500);">Select an email to read</div>
            <div style="font-size:12px;color:var(--g400);"><?=number_format($total)?> messages</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if($selected): ?>
<script>
// Reply/Forward data
var replyData = <?=json_encode([
    'uid'      => $selected['uid'],
    'from'     => $selected['from'],
    'fromEmail'=> $selected['from_email'],
    'to'       => $selected['to'],
    'cc'       => $selected['cc'] ?? '',
    'subject'  => $selected['subject'],
    'date'     => $selected['date'],
    'body'     => $bodyHtml,
])?>;

var rpBase = '<?= APP_URL ?>/reply.php?uid=' + replyData.uid + '&f=' + encodeURIComponent(<?= json_encode($folder) ?>);
document.getElementById('btnReply').addEventListener('click', function(){
    window.location.href = rpBase + '&mode=reply';
});
document.getElementById('btnReplyAll').addEventListener('click', function(){
    window.location.href = rpBase + '&mode=replyall';
});
document.getElementById('btnFwd').addEventListener('click', function(){
    window.location.href = rpBase + '&mode=forward';
});

(function(){
    var frame = document.getElementById('mailFrame');
    if (!frame) return;
    // Auto-resize iframe to content height
    function resize() {
        try {
            var h = frame.contentDocument
                  ? frame.contentDocument.documentElement.scrollHeight
                  : (frame.contentWindow.document.documentElement.scrollHeight || 400);
            frame.style.height = (h + 30) + 'px';
        } catch(e) {
            frame.style.height = '400px';
        }
    }
    frame.addEventListener('load', function(){
        resize();
        setTimeout(resize, 300);
        setTimeout(resize, 1200);
        try {
            var imgs = frame.contentDocument.querySelectorAll('img');
            imgs.forEach(function(img){ img.addEventListener('load', resize); });
        } catch(e) {}
    });
    setTimeout(resize, 500);
})();
function toggleAiStrip() {
    var strip = document.getElementById('aiStrip');
    var btn = document.getElementById('btnAi');
    if (!strip) return;
    var open = strip.classList.toggle('open');
    if (btn) { if (open) btn.classList.add('ai-active'); else btn.classList.remove('ai-active'); }
}
function getSummary(uid){
    var b=document.getElementById('aiBtn');
    var t=document.getElementById('aiTxt');
    b.textContent='Generating...';b.disabled=true;
    // POST the body text directly - avoids IMAP re-fetch in summarise.php
    var bodyText = replyData && replyData.body ? replyData.body : '';
    bodyText = bodyText.replace(/<[^>]+>/g,'').substring(0,3000); // strip HTML tags
    var fd = new FormData();
    fd.append('body', bodyText);
    fd.append('subject', replyData ? replyData.subject : '');
    fd.append('uid', uid);
    fd.append('folder', '<?=urlencode($folder)?>');
    var ctrl = new AbortController();
    var tmr  = setTimeout(function(){ ctrl.abort(); }, 30000);
    fetch('api/ai/summarise.php', {
        method:'POST',
        credentials:'same-origin',
        headers:{'X-CSRF-Token':CSRF_TOKEN},
        signal: ctrl.signal,
        body: fd
    })
    .then(function(r){
        clearTimeout(tmr);
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(d){
        if(d.summary){
            t.innerHTML=d.summary.replace(/\n/g,'<br>');
        } else {
            t.textContent = d.error || 'Could not generate summary.';
        }
        b.style.display='none';
    })
    .catch(function(err){
        console.error('AI Summary error:', err);
        t.textContent='Error: ' + (err.message || 'Request failed');
        b.textContent='Retry';b.disabled=false;
    });
}
function getSmartReplies(uid){
    var row=document.getElementById('srRow');
    row.innerHTML='<div style="color:var(--g400);font-size:12px;padding:4px 0;">Generating smart replies...</div>';
    var bodyText=<?=json_encode(strip_tags($bodyHtml??''))?>.substring(0,2000);
    var fd=new FormData();
    fd.append('body', bodyText.substring(0,2000));
    fd.append('subject','<?=htmlspecialchars(addslashes($selected['subject'] ?? ''))?>');
    fd.append('from','<?=htmlspecialchars(addslashes($selected['from'] ?? ''))?>');
    fetch('api/ai/smart-reply.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(!d.ok||!d.replies||!d.replies.length)throw new Error(d.error||'No replies');
        var h='<span style="font-size:9.5px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;align-self:center;">Smart Replies</span>';
        d.replies.forEach(function(rep){
            var safeBody = (rep.body||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
            h+='<button class="sr" data-srbody="'+safeBody+'">'+rep.label+'</button>';
        });
        row.innerHTML=h;
    })
    .catch(function(e){row.innerHTML='<div style="color:var(--g400);font-size:12px;">Could not generate replies. Try again.</div>';});
}
function doSmartReply(body){
    window.hriOpenCompose({
        to: replyData.fromEmail,
        subject: 'Re: ' + replyData.subject,
        body: body ? body.replace(/\n/g,'<br/>') : ''
    });
}
document.getElementById('srRow').addEventListener('click', function(e){
    var btn = e.target.closest('[data-srbody]');
    if (btn) doSmartReply(btn.getAttribute('data-srbody'));
});
</script>
<?php endif; ?>

<script>
var folder='<?=addslashes($folder)?>';
var curPage=<?=$page?>;
var curUid=<?=$uid?>;
var sel=new Set();

function openMail(uid){
    var url='mail.php?f='+encodeURIComponent(folder)+'&uid='+uid+'&p='+curPage;
    if(window.innerWidth<=680){
        location.href=url;
    } else {
        location.href=url;
    }
}

function mobileBack(){
    document.getElementById('mailViewPane').classList.remove('mob-open');
    history.pushState({},'','mail.php?f='+encodeURIComponent(folder)+'&p='+curPage);
}

function toggleChk(uid){var c=document.getElementById('ck'+uid);if(sel.has(uid)){sel.delete(uid);c.classList.remove('on');c.textContent='';}else{sel.add(uid);c.classList.add('on');c.textContent='✓';}updBulk();}
function toggleSelAll(){var items=document.querySelectorAll('.mi[data-uid]');var allOn=items.length>0&&items.length===sel.size;items.forEach(function(mi){var uid=parseInt(mi.dataset.uid);var ck=document.getElementById('ck'+uid);if(allOn){sel.delete(uid);if(ck){ck.classList.remove('on');ck.textContent='';}}else{sel.add(uid);if(ck){ck.classList.add('on');ck.textContent='✓';}}});updBulk();}
function deselAll(){sel.forEach(function(uid){var c=document.getElementById('ck'+uid);if(c){c.classList.remove('on');c.textContent='';}});sel.clear();updBulk();}
function updBulk(){var bar=document.getElementById('bulkBar');var cnt=document.getElementById('bulkCnt');if(sel.size>0){bar.classList.add('show');cnt.textContent=sel.size+' selected';}else bar.classList.remove('show');}

var inTrashFolder=(folder==='Trash');

function bulkAction(action){
    if(sel.size===0)return;
    if(action==='delete'){
        var msg=inTrashFolder?'Permanently delete '+sel.size+' message(s)? This cannot be undone.':'Move '+sel.size+' message(s) to Trash?';
        if(!confirm(msg))return;
    }
    var fd=new FormData();fd.append('bulk_action',action);sel.forEach(function(u){fd.append('uids[]',u);});
    fetch('mail.php?f='+encodeURIComponent(folder),{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN},body:fd}).then(function(r){return r.json();}).then(function(d){
        if(d.ok){
            var toast=action==='delete'?(inTrashFolder?'&#128465; Permanently deleted':'&#128465; '+d.count+' moved to Trash'):'&#10003; Done';
            hriShowToast(toast,action==='delete'?'err':'ok');
            if(action==='delete'){sel.forEach(function(uid){var el=document.getElementById('mi'+uid);if(el)el.remove();});deselAll();}else location.reload();
        }
    });
}

function quickDel(uid){
    if(inTrashFolder&&!confirm('Permanently delete this message? This cannot be undone.'))return;
    var fd=new FormData();fd.append('quick_delete','1');fd.append('uid',uid);
    fetch('mail.php?f='+encodeURIComponent(folder),{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN},body:fd}).then(function(r){return r.json();}).then(function(d){
        if(d.ok){
            hriShowToast(d.purged?'&#128465; Permanently deleted':'&#128465; Moved to Trash','err');
            var el=document.getElementById('mi'+uid);if(el)el.remove();
            if(uid===curUid)setTimeout(function(){location.href='mail.php?f='+encodeURIComponent(folder)+'&p='+curPage;},500);
        }
    });
}

/* ── QUICK FLAG / QUICK MARK UNREAD (from list) ── */
function quickFlag(uid, currentlyFlagged) {
    var fd = new FormData();
    fd.append('action', currentlyFlagged ? 'unstar' : 'star');
    fd.append('uid', uid);
    fd.append('folder', folder);
    fetch('api/mail/action.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(!d.ok) return;
        var mi = document.getElementById('mi'+uid);
        var btn = mi ? mi.querySelector('.mi-qa') : null;
        var qacts = mi ? mi.querySelector('.mi-qacts') : null;
        if(currentlyFlagged){
            if(btn){btn.classList.remove('flag-on');btn.title='Flag as important';btn.setAttribute('onclick','quickFlag('+uid+',false)');}
            if(qacts) qacts.classList.remove('has-flag');
            hriShowToast('Flag removed','ok');
        } else {
            if(btn){btn.classList.add('flag-on');btn.title='Remove flag';btn.setAttribute('onclick','quickFlag('+uid+',true)');}
            if(qacts) qacts.classList.add('has-flag');
            hriShowToast('&#9873; Flagged as important','ok');
        }
    });
}
function quickMarkUnread(uid) {
    var fd = new FormData();
    fd.append('action','mark_unread');
    fd.append('uid',uid);
    fd.append('folder',folder);
    fetch('api/mail/action.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(!d.ok) return;
        var mi = document.getElementById('mi'+uid);
        if(mi){
            mi.classList.add('unread');
            if(!mi.querySelector('.udot')){
                var dot=document.createElement('div');dot.className='udot';
                var chk=mi.querySelector('.mchk');if(chk)chk.after(dot);
            }
            var qacts=mi.querySelector('.mi-qacts');if(qacts)qacts.remove();
        }
        hriShowToast('&#128262; Marked as unread','ok');
    });
}

/* ── MARK READ/UNREAD ── */
function toggleRead(uid, currentlySeen) {
    var action = currentlySeen ? 'mark_unread' : 'mark_read';
    var fd = new FormData();
    fd.append('action', action);
    fd.append('uid',    uid);
    fd.append('folder', folder);
    fetch('api/mail/action.php', {method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok){
            if(typeof hriShowToast==='function')
                hriShowToast(currentlySeen ? 'Marked as unread' : 'Marked as read', 'ok');
            // Update button
            var btn = document.getElementById('btnToggleRead');
            if(btn){
                btn.innerHTML = currentlySeen ? '&#128261; Mark Read' : '&#128262; Mark Unread';
                btn.setAttribute('onclick','toggleRead('+uid+','+(currentlySeen?'false':'true')+')');
            }
        }
    });
}

/* ── STAR / UNSTAR ── */
function toggleStar(uid, currentlyStarred) {
    var action = currentlyStarred ? 'unstar' : 'star';
    var fd = new FormData();
    fd.append('action', action);
    fd.append('uid',    uid);
    fd.append('folder', folder);
    fetch('api/mail/action.php', {method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok){
            var btn = document.getElementById('btnStar');
            if(btn){
                btn.innerHTML = '&#9873; ' + (currentlyStarred ? 'Flag' : 'Flagged');
                btn.style.color = currentlyStarred ? '' : '#ef4444';
                if(currentlyStarred) btn.classList.remove('on'); else btn.classList.add('on');
                btn.setAttribute('onclick','toggleStar('+uid+','+(currentlyStarred?'false':'true')+')');
            }
        }
    });
}

/* ── PRINT EMAIL ── */
function printEmail() {
    var frame = document.getElementById('mailFrame');
    if (!frame) { alert('Email body not loaded yet'); return; }
    var fromEl   = document.querySelector('.mv-from');
    var subjEl   = document.querySelector('.mv-subj');
    var dateEl   = document.querySelector('.mv-date');
    var from    = fromEl   ? fromEl.textContent   : '';
    var subj    = subjEl   ? subjEl.textContent   : '(no subject)';
    var dateStr = dateEl   ? dateEl.textContent   : '';
    var bodyHtml = '';
    try { bodyHtml = frame.contentDocument.body.innerHTML; } catch(e) { bodyHtml = '(email body unavailable)'; }
    var win = window.open('','_blank','width=860,height=700');
    if (!win) { alert('Please allow popups for this site to print'); return; }
    win.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"/><title>'+subj+'</title><style>'
        +'body{font-family:Arial,sans-serif;padding:30px;color:#222;max-width:700px;margin:0 auto;}'
        +'h2{color:#002850;margin-bottom:4px;font-size:18px;}'
        +'.meta{color:#666;font-size:13px;margin-bottom:20px;border-bottom:1px solid #eee;padding-bottom:12px;}'
        +'@media print{.no-print{display:none!important}}'
        +'</style></head><body>'
        +'<h2>'+subj+'</h2>'
        +'<div class="meta">From: <strong>'+from+'</strong> &nbsp;&nbsp; Date: '+dateStr+'</div>'
        +'<div>'+bodyHtml+'</div>'
        +'<br><div class="no-print" style="margin-top:20px;">'
        +'<button onclick="window.print();" style="background:#002850;color:#fff;padding:8px 20px;border:none;cursor:pointer;border-radius:4px;font-size:14px;margin-right:8px;">&#128438; Print</button>'
        +'<button onclick="window.close();" style="background:#eee;color:#333;padding:8px 20px;border:none;cursor:pointer;border-radius:4px;font-size:14px;">Close</button>'
        +'</div></body></html>');
    win.document.close();
    setTimeout(function(){ win.focus(); }, 300);
}

function saveVault(uid){
    var fd = new FormData();
    fd.append('action','vault');
    fd.append('uid', uid);
    fd.append('folder', folder);
    fetch('api/mail/action.php',{
        method:'POST',
        credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN||''},
        body:fd
    })
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        return r.json();
    })
    .then(function(d){
        if(typeof hriShowToast==='function') hriShowToast(d.ok?'✅ Saved to Vault':'Could not save to Vault',d.ok?'ok':'err');
        else alert(d.ok?'Saved to Vault':'Could not save to Vault');
    })
    .catch(function(e){ 
        if(typeof hriShowToast==='function') hriShowToast('Vault save failed','err');
        else alert('Vault save failed');
    });
}

function addTaskFromMail(uid, subject) {
    var title = prompt('Task title:', 'Re: ' + subject);
    if (!title) return;
    fetch('api/tasks/add.php', {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN||''},
        body:JSON.stringify({title:title})
    })
    .then(function(r){ return r.text(); })
    .then(function(txt){
        var d;
        try { d = JSON.parse(txt); } catch(e) { d = {ok:false, error:'Server error (non-JSON). Status may require page refresh.'}; }
        if (typeof window.hriShowToast === 'function') window.hriShowToast(d.ok ? 'Task added' : (d.error || 'Error adding task'), d.ok ? 'ok' : 'err');
        else alert(d.ok ? 'Task added' : (d.error || 'Error adding task'));
    })
    .catch(function(){ alert('Network error. Check your connection and try again.'); });
}

document.addEventListener('keydown',function(e){
    if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA')return;
    if(e.key==='/')  {e.preventDefault();document.querySelector('.hri-search')&&document.querySelector('.hri-search').focus();}
    if(e.key==='Escape')deselAll();
});

// Auto-open compose panel if redirected from compose.php
(function(){
    if(location.hash==='#compose'){
        history.replaceState(null,'',location.pathname+location.search);
        // Wait for DOM + shell JS to fully load
        if(document.readyState==='loading'){
            document.addEventListener('DOMContentLoaded',function(){
                setTimeout(function(){ 
                    if(typeof hriOpenCompose==='function') hriOpenCompose();
                },200);
            });
        } else {
            setTimeout(function(){ 
                if(typeof hriOpenCompose==='function') hriOpenCompose();
            },200);
        }
    }
})();

// If it's a reply/forward URL, open compose with pre-fill
<?php if($selected && isset($_GET['uid'])): ?>
// Check if we came from a reply link
<?php endif; ?>
</script>

<!-- Attachment Viewer Modal -->
<div id="attViewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:950;flex-direction:column;">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 18px;background:#1a1a1a;flex-shrink:0;">
    <span id="attViewTitle" style="color:#fff;font-size:13px;font-weight:600;max-width:65%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
    <div style="display:flex;gap:14px;align-items:center;">
      <a id="attViewDl" href="#" style="color:#94a3b8;font-size:12px;text-decoration:none;padding:4px 10px;border:1px solid #374151;border-radius:4px;">&#11015; Download</a>
      <button onclick="closeAttModal()" style="color:#fff;background:none;border:none;font-size:24px;cursor:pointer;line-height:1;padding:0 4px;">&times;</button>
    </div>
  </div>
  <div style="flex:1;overflow:hidden;position:relative;">
    <iframe id="attViewFrame" src="" style="display:none;width:100%;height:100%;border:none;background:#fff;"></iframe>
    <img id="attViewImg" src="" alt="" style="display:none;max-width:100%;max-height:100%;margin:auto;object-fit:contain;position:absolute;inset:0;">
    <div id="attViewNoPreview" style="display:none;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#94a3b8;gap:12px;">
      <div style="font-size:48px;">📎</div>
      <div style="font-size:15px;font-weight:500;" id="attViewNoPreviewName"></div>
      <div style="font-size:13px;">This file type cannot be previewed in the browser.</div>
      <a id="attViewNoDl" href="#" style="margin-top:8px;padding:10px 24px;background:#002850;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">&#11015; Download File</a>
    </div>
  </div>
</div>
<script>
function openAttModal(url, name, ext) {
    var frame = document.getElementById('attViewFrame');
    var img   = document.getElementById('attViewImg');
    var nopre = document.getElementById('attViewNoPreview');
    var dlUrl = url.replace('&inline=1','');
    document.getElementById('attViewTitle').textContent = name;
    document.getElementById('attViewDl').href     = dlUrl;
    document.getElementById('attViewDl').download  = name;
    document.getElementById('attViewNoDl').href    = dlUrl;
    document.getElementById('attViewNoPreviewName').textContent = name;
    frame.style.display = 'none'; frame.src = '';
    img.style.display   = 'none'; img.src   = '';
    nopre.style.display = 'none';
    var imgExts = ['jpg','jpeg','png','gif','webp','svg','bmp'];
    var frmExts = ['pdf','txt','csv','html','htm'];
    if (imgExts.indexOf(ext) !== -1) {
        img.src = url; img.style.display = 'block';
    } else if (frmExts.indexOf(ext) !== -1) {
        frame.src = url; frame.style.display = 'block';
    } else {
        nopre.style.display = 'flex';
    }
    document.getElementById('attViewModal').style.display = 'flex';
    document.addEventListener('keydown', _attEsc);
}
function closeAttModal() {
    document.getElementById('attViewModal').style.display = 'none';
    document.getElementById('attViewFrame').src = '';
    document.getElementById('attViewImg').src   = '';
    document.removeEventListener('keydown', _attEsc);
}
function _attEsc(e) { if (e.key === 'Escape') closeAttModal(); }
</script>
<?php Layout::end(); ?>
