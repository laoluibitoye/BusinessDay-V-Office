<?php
if (!isset($user) || !is_array($user)) { header('Location: ../index.php'); exit; }
$db = getDB();
$mp = $_SESSION['mail_pass'] ?? '';
$ini = strtoupper(substr($user['name'],0,1).(strpos($user['name'],' ')!==false?substr($user['name'],strpos($user['name'],' ')+1,1):''));
$firstName = explode(' ', $user['name'])[0];
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$totalStaff = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$leavePending = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage IN ('submitted','lm_review','hr_review','md_review')")->fetchColumn();
$hrQueue = $db->query("SELECT lr.*,u.name as rname FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE lr.current_stage='hr_review' ORDER BY lr.created_at DESC LIMIT 8")->fetchAll();

$deptCounts = $db->query("SELECT department, COUNT(*) as cnt FROM users WHERE is_active=1 AND department IS NOT NULL AND department!='' GROUP BY department ORDER BY cnt DESC")->fetchAll();
$newJoiners = $db->query("SELECT name,role,department,created_at FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active=1 ORDER BY created_at DESC LIMIT 5")->fetchAll();
$expiryList = []; try{$expiryList=$db->query("SELECT name,expiry_date,DATEDIFF(expiry_date,CURDATE()) as dl FROM compliance_docs WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) ORDER BY expiry_date LIMIT 5")->fetchAll();}catch(Exception $e){}
$announcements=[]; try{$announcements=$db->query("SELECT a.*,u.name as author FROM announcements a JOIN users u ON u.id=a.user_id WHERE a.is_active=1 AND (a.expires_at IS NULL OR a.expires_at>NOW()) ORDER BY a.priority DESC,a.created_at DESC LIMIT 5")->fetchAll();}catch(Exception $e){}

$onLeaveToday = $db->query("SELECT u.name, lr.leave_type, lr.end_date FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE lr.current_stage='approved' AND CURDATE() BETWEEN lr.start_date AND lr.end_date ORDER BY lr.end_date ASC")->fetchAll();

$sigCount=0;
try{$sg=$db->prepare("SELECT COUNT(*) FROM sign_signatories ss JOIN sign_requests sr ON sr.id=ss.request_id WHERE ss.user_id=? AND ss.status='pending' AND sr.expires_at>NOW()");$sg->execute([$user['id']]);$sigCount=(int)$sg->fetchColumn();}catch(Exception $e){}

$myTasks=[];
try{$ts=$db->prepare("SELECT * FROM tasks WHERE user_id=? AND status!='done' ORDER BY FIELD(priority,'urgent','high','normal','low'),CASE WHEN due_date IS NULL THEN 1 ELSE 0 END,due_date ASC LIMIT 6");$ts->execute([$user['id']]);$myTasks=$ts->fetchAll();}catch(Exception $e){}

$entitled=15;$usedDays=0;$pendDays=0;
try{$b=$db->prepare("SELECT entitled_days FROM leave_balance WHERE user_id=? AND year=YEAR(CURDATE())");$b->execute([$user['id']]);$br=$b->fetch();if($br){$entitled=(int)$br['entitled_days'];}else{try{$s2=$db->prepare("SELECT ws.annual_leave_days FROM staff_schedules ss JOIN work_schedules ws ON ws.id=ss.schedule_id WHERE ss.user_id=?");$s2->execute([$user['id']]);$sr2=$s2->fetch();if($sr2)$entitled=(int)$sr2['annual_leave_days'];}catch(PDOException $e){}}}catch(PDOException $e){}
$lU=$db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND (current_stage='approved' OR md_status='approved') AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW())");$lU->execute([$user['id']]);$usedDays=(int)$lU->fetchColumn();
$lP=$db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW()) AND current_stage NOT IN ('approved','rejected')");$lP->execute([$user['id']]);$pendDays=(int)$lP->fetchColumn();
$remainLeave=max(0,$entitled-$usedDays-$pendDays);

$unread=0;set_time_limit(30);
if($mp){try{@imap_timeout(IMAP_OPENTIMEOUT,5);@imap_timeout(IMAP_READTIMEOUT,8);$mx=@imap_open('{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}INBOX',$user['email'],$mp,0,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);if($mx){$unread=count(@imap_search($mx,'UNSEEN')??[]);imap_close($mx);}}catch(Exception $e){}}

Layout::shell($user, 'dashboard', $unread, 'Dashboard');
?>
<style>
.hri-page{padding:20px;max-width:1160px;margin:0 auto;}
.wlcbar{background:linear-gradient(135deg,#7c3aed 0%,#5b21b6 100%);border-radius:12px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;box-shadow:0 8px 32px rgba(124,58,237,.25);}
.wav{width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;font-weight:800;font-size:15px;display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.2);flex-shrink:0;}
.wname{font-size:17px;font-weight:700;color:#fff;margin-bottom:3px;}.wsub{font-size:12px;color:rgba(255,255,255,.65);}
.wclk{margin-left:auto;text-align:right;}.wtime{font-size:24px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums;}.wtz{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px;}
.krow{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:12px;margin-bottom:18px;}
.kpi{background:#fff;border-radius:12px;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid #e2e8f0;display:block;text-decoration:none;transition:all .2s;}.kpi:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);transform:translateY(-2px);}
.kpi.nv{border-top-color:#002850;}.kpi.gn{border-top-color:#64A014;}.kpi.rd{border-top-color:#dc2626;}.kpi.wn{border-top-color:#f59e0b;}.kpi.pu{border-top-color:#8b5cf6;}
.kval{font-size:28px;font-weight:800;color:#002850;line-height:1;margin-bottom:4px;}.klbl{font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}.ksub{font-size:11.5px;color:#94a3b8;margin-top:3px;}
.gmain{display:grid;grid-template-columns:1fr 300px;gap:16px;}.cleft,.cright{display:flex;flex-direction:column;gap:16px;}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.chd{padding:11px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
.cht{font-size:13px;font-weight:700;color:#002850;}.chl{font-size:12.5px;color:#64A014;font-weight:600;text-decoration:none;}.chl:hover{text-decoration:underline;}
.empty{padding:26px;text-align:center;color:#94a3b8;font-size:13px;}
.ritem{display:flex;align-items:center;gap:9px;padding:9px 14px;border-bottom:1px solid #f1f5f9;}.ritem:last-child{border-bottom:none;}
.pill{padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;}.pill.sub{background:#dbeafe;color:#1e40af;}.pill.hr{background:#e0e7ff;color:#4338ca;}.pill.lm{background:#fef3c7;color:#92400e;}
.lapp{padding:4px 10px;border-radius:6px;background:#64A014;color:#fff;font-size:11px;font-weight:600;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
.dtag{padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;}.dtag.rd{background:#fee2e2;color:#dc2626;}.dtag.wn{background:#fef3c7;color:#92400e;}.dtag.ok{background:#dcfce7;color:#166534;}
.annrow{padding:10px 14px;border-bottom:1px solid #f8fafc;}.annrow:last-child{border-bottom:none;}.antit{font-size:13px;font-weight:600;color:#0f172a;}.anbod{font-size:12px;color:#64748b;margin-top:3px;line-height:1.5;}
.dept-bar{height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin-top:4px;}.dept-fill{height:100%;border-radius:99px;background:#7c3aed;}
.qagl{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;padding:12px;}
.qa{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 6px;border-radius:9px;background:#f1f5f9;border:1.5px solid transparent;cursor:pointer;transition:all .15s;text-decoration:none;font-family:inherit;}
.qa:hover{background:#e8f0fb;border-color:#002850;}.qi{font-size:20px;}.ql{font-size:11px;font-weight:600;color:#334155;text-align:center;line-height:1.3;}
@media(max-width:900px){.gmain{grid-template-columns:1fr;}}
</style>
<div class="hri-page">
<div class="wlcbar">
    <div class="wav"><?=$ini?></div>
    <div><div class="wname"><?=$greeting?>, <?=$firstName?> &#128101;</div><div class="wsub"><?=date('l, d F Y')?> &bull; Human Resources &bull; <?=$totalStaff?> staff</div></div>
    <div class="wclk"><div class="wtime" id="clk">--:--:--</div><div class="wtz">WAT (Lagos)</div></div>
</div>

<div class="krow">
    <a class="kpi <?=$unread>0?'gn':'nv'?>" href="mail.php"><div class="kval"><?=$unread?></div><div class="klbl">Unread Mail</div></a>
    <a class="kpi nv" href="admin/users.php"><div class="kval"><?=$totalStaff?></div><div class="klbl">Total Staff</div></a>
    <a class="kpi <?=$leavePending>0?'pu':'nv'?>" href="leave-approvals.php"><div class="kval" data-kpi="leave_pending"><?=$leavePending?></div><div class="klbl">Pending Leave</div></a>
    <a class="kpi <?=count($hrQueue)>0?'wn':'nv'?>" href="leave-approvals.php"><div class="kval" data-kpi="at_hr_stage"><?=count($hrQueue)?></div><div class="klbl">At HR Stage</div></a>
    <a class="kpi <?=$sigCount>0?'wn':'nv'?>" href="signing.php"><div class="kval" data-kpi="sig_pending"><?=$sigCount?></div><div class="klbl">To Sign</div></a>
    <a class="kpi <?=$remainLeave<=5?'rd':($remainLeave<=10?'wn':'gn')?>" href="leave.php"><div class="kval" data-kpi="remain_leave"><?=$remainLeave?></div><div class="klbl">My Leave Left</div><div class="ksub"><?=$usedDays?>/<?=$entitled?> used</div></a>
    <?php if(!empty($newJoiners)): ?><a class="kpi gn" href="admin/users.php"><div class="kval"><?=count($newJoiners)?></div><div class="klbl">New Joiners</div><div class="ksub">last 30 days</div></a><?php endif; ?>
    <?php if(!empty($onLeaveToday)): ?><a class="kpi wn" href="leave-approvals.php"><div class="kval"><?=count($onLeaveToday)?></div><div class="klbl">On Leave Today</div></a><?php endif; ?>
</div>

<div class="gmain">
    <div class="cleft">
        <div class="card">
            <div class="chd"><div class="cht">&#127958; Leave at HR Review Stage</div><a class="chl" href="leave-approvals.php">All requests &#8594;</a></div>
            <?php if(empty($hrQueue)): ?><div class="empty">No leave requests at HR stage &#10003;</div>
            <?php else: foreach($hrQueue as $lr): ?>
            <div class="ritem" id="lr-row-<?=$lr['id']?>">
                <div style="flex:1;"><div style="font-size:13px;font-weight:600;"><?=htmlspecialchars($lr['rname'])?></div><div style="font-size:12px;color:#64748b;"><?=ucfirst($lr['leave_type'])?> &bull; <?=date('d M',strtotime($lr['start_date']))?> – <?=date('d M',strtotime($lr['end_date']))?> (<?=$lr['days']?> days)</div></div>
                <span class="pill hr">HR Review</span>
                <button onclick="openLeaveDrawer(<?=$lr['id']?>)" class="lapp">Review</button>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <?php if(!empty($onLeaveToday)): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#127958; On Leave Today</div></div>
            <?php foreach($onLeaveToday as $l): ?>
            <div class="ritem"><div style="flex:1;font-size:13px;"><?=htmlspecialchars($l['name'])?></div><span class="pill sub"><?=ucfirst($l['leave_type'])?></span><span style="font-size:11px;color:#94a3b8;">until <?=date('d M',strtotime($l['end_date']))?></span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($deptCounts)): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#128200; Headcount by Department</div></div>
            <?php $maxCnt=max(array_column($deptCounts,'cnt'));
            foreach($deptCounts as $dept): ?>
            <div style="padding:8px 14px;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;justify-content:space-between;font-size:12.5px;"><span><?=htmlspecialchars($dept['department'])?></span><strong><?=$dept['cnt']?></strong></div>
                <div class="dept-bar"><div class="dept-fill" style="width:<?=round($dept['cnt']/$maxCnt*100)?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($announcements)): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#128226; Announcements</div><a class="chl" href="admin/announcements.php">Manage &#8594;</a></div>
            <?php foreach($announcements as $a): ?>
            <div class="annrow"><div class="antit"><?=htmlspecialchars($a['title'])?></div><div class="anbod"><?=htmlspecialchars(substr(strip_tags($a['body']),0,120))?></div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="cright">
        <div class="card">
            <div class="chd"><div class="cht">&#9889; Quick Actions</div></div>
            <div class="qagl">
                <button class="qa" onclick="if(typeof hriOpenCompose==='function')hriOpenCompose()"><div class="qi">&#9998;</div><div class="ql">Compose</div></button>
                <a class="qa" href="admin/broadcast.php"><div class="qi">&#128226;</div><div class="ql">Broadcast</div></a>
                <a class="qa" href="leave-approvals.php"><div class="qi">&#127958;</div><div class="ql">Leave Queue</div></a>
                <a class="qa" href="admin/users.php"><div class="qi">&#128737;</div><div class="ql">Manage Staff</div></a>
                <a class="qa" href="compliance.php"><div class="qi">&#128203;</div><div class="ql">Compliance</div></a>
                <a class="qa" href="admin/announcements.php"><div class="qi">&#128226;</div><div class="ql">Announce</div></a>
                <a class="qa" href="directory.php"><div class="qi">&#128101;</div><div class="ql">Directory</div></a>
                <a class="qa" href="profile.php"><div class="qi">&#128100;</div><div class="ql">My Profile</div></a>
            </div>
        </div>

        <?php if(!empty($newJoiners)): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#127381; New Joiners (30 days)</div></div>
            <?php foreach($newJoiners as $nj): $rl=ROLES[$nj['role']]['label']??$nj['role']; ?>
            <div class="ritem">
                <div style="flex:1;"><div style="font-size:13px;font-weight:600;"><?=htmlspecialchars($nj['name'])?></div><div style="font-size:11.5px;color:#94a3b8;"><?=$rl?></div></div>
                <div style="font-size:11px;color:#94a3b8;"><?=date('d M',strtotime($nj['created_at']))?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($expiryList)): ?>
        <div class="card">
            <div class="chd"><div class="cht" style="color:#f59e0b;">&#9888; Compliance Expiring</div><a class="chl" href="compliance.php">View &#8594;</a></div>
            <?php foreach($expiryList as $c): $d=(int)$c['dl']; ?>
            <div class="ritem"><div style="flex:1;font-size:13px;"><?=htmlspecialchars($c['name'])?></div><span class="dtag <?=$d<14?'rd':($d<30?'wn':'ok')?>"><?=$d?>d</span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="chd"><div class="cht">&#9989; My Tasks</div><a class="chl" href="tasks.php">All &#8594;</a></div>
            <?php if(empty($myTasks)): ?><div class="empty" style="padding:16px;">&#10003; No open tasks</div>
            <?php else: $pc4=['urgent'=>'#dc2626','high'=>'#f59e0b','normal'=>'#002850','low'=>'#94a3b8'];
            foreach($myTasks as $t): $dTs=$t['due_date']?strtotime($t['due_date']):null;$n2t=strtotime(date('Y-m-d'));
                if($dTs&&$dTs<$n2t){$dc2='ov';$ds3='Overdue';}elseif($dTs&&($dTs-$n2t)<172800){$dc2='sn';$ds3=date('d M',$dTs);}elseif($dTs){$dc2='ok';$ds3=date('d M',$dTs);}else{$dc2='';$ds3='';}
            ?>
            <div class="ritem">
                <div style="width:8px;height:8px;border-radius:50%;background:<?=$pc4[$t['priority']]??'#94a3b8'?>;flex-shrink:0;"></div>
                <div style="flex:1;min-width:0;"><div style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars(substr($t['title'],0,40))?></div><div style="font-size:11px;color:#94a3b8;"><?=ucfirst($t['priority'])?></div></div>
                <?php if($ds3): ?><span class="dtag <?=$dc2?>"><?=$ds3?></span><?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <div class="chd"><div class="cht">&#127958; My Leave <?=date('Y')?></div><a class="chl" href="leave.php">Request &#8594;</a></div>
            <div style="padding:13px 14px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span><strong style="font-size:20px;color:#002850;"><?=$remainLeave?></strong> days remaining</span><span style="color:#94a3b8;"><?=$usedDays?>/<?=$entitled?> used</span></div>
                <div style="height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;"><div style="height:100%;background:<?=$remainLeave<=5?'#dc2626':($remainLeave<=10?'#f59e0b':'#64A014')?>;border-radius:99px;width:<?=min(100,$entitled>0?round($usedDays/$entitled*100):0)?>%;"></div></div>
                <?php if($pendDays>0): ?><div style="font-size:12px;color:#f59e0b;margin-top:4px;">&#8987; <?=$pendDays?> days pending</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/_irs_widget.php'; ?>
</div>

<!-- ── LEAVE REVIEW DRAWER ── -->
<div id="ldrOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:490;" onclick="closeLeaveDrawer()"></div>
<div id="ldrPanel" style="position:fixed;right:0;top:var(--top-h,64px);width:380px;max-width:100vw;height:calc(100vh - var(--top-h,64px));background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.12);transform:translateX(100%);transition:transform .25s cubic-bezier(.4,0,.2,1);z-index:500;display:flex;flex-direction:column;">
    <div style="padding:16px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="font-size:15px;font-weight:700;color:#002850;">&#127958; Review Leave Request</div>
        <button onclick="closeLeaveDrawer()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1;">&times;</button>
    </div>
    <div id="ldrBody" style="flex:1;overflow-y:auto;padding:16px 18px;font-size:13.5px;color:#334155;"></div>
    <div style="padding:14px 18px;border-top:1px solid #f1f5f9;flex-shrink:0;">
        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:5px;">Comment (optional):</label>
        <textarea id="ldrComment" rows="2" style="width:100%;box-sizing:border-box;border:1px solid #e2e8f0;border-radius:7px;padding:7px 10px;font-size:13px;font-family:inherit;resize:none;outline:none;margin-bottom:10px;" placeholder="Add a note..."></textarea>
        <div style="display:flex;gap:8px;">
            <button onclick="submitLeaveAction('approve')" style="flex:1;background:#64A014;color:#fff;border:none;border-radius:7px;padding:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">&#10003; Approve</button>
            <button onclick="submitLeaveAction('return')" style="flex:1;background:#dc2626;color:#fff;border:none;border-radius:7px;padding:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">&#8617; Return</button>
            <a href="leave-approvals.php" style="flex:1;display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#334155;border-radius:7px;padding:9px;font-size:13px;font-weight:600;text-decoration:none;">Full View</a>
        </div>
        <div id="ldrMsg" style="margin-top:8px;font-size:12px;text-align:center;min-height:16px;"></div>
    </div>
</div>

<script>
var _leaveDat = <?=json_encode(array_values($hrQueue))?>;
var _ldrId = 0;

function openLeaveDrawer(id) {
    _ldrId = id;
    var lr = null;
    for (var i = 0; i < _leaveDat.length; i++) { if (parseInt(_leaveDat[i].id) === id) { lr = _leaveDat[i]; break; } }
    if (!lr) { window.location='leave-approvals.php?id='+id; return; }
    var types = lr.leave_type.replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();});
    document.getElementById('ldrBody').innerHTML =
        '<div style="margin-bottom:12px;"><div style="font-size:17px;font-weight:800;color:#002850;margin-bottom:2px;">'+escHtml(lr.rname)+'</div>'
        + '<div style="font-size:12px;color:#94a3b8;">Leave request #'+lr.id+' &bull; HR Review Stage</div></div>'
        + '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<tr><td style="padding:6px 0;color:#64748b;width:110px;">Type</td><td style="padding:6px 0;font-weight:600;">'+escHtml(types)+'</td></tr>'
        + '<tr><td style="padding:6px 0;color:#64748b;">Dates</td><td style="padding:6px 0;font-weight:600;">'+fmtDate(lr.start_date)+' &ndash; '+fmtDate(lr.end_date)+' <span style="color:#94a3b8;">('+lr.days+' day'+((lr.days!=1)?'s':'')+')</span></td></tr>'
        + (lr.reason?'<tr><td style="padding:6px 0;color:#64748b;vertical-align:top;">Reason</td><td style="padding:6px 0;">'+escHtml(lr.reason)+'</td></tr>':'')
        + (lr.cover_staff?'<tr><td style="padding:6px 0;color:#64748b;">Cover</td><td style="padding:6px 0;">'+escHtml(lr.cover_staff)+'</td></tr>':'')
        + '</table>';
    document.getElementById('ldrComment').value = '';
    document.getElementById('ldrMsg').textContent = '';
    document.getElementById('ldrOverlay').style.display = 'block';
    document.getElementById('ldrPanel').style.transform = 'translateX(0)';
}
function closeLeaveDrawer() {
    document.getElementById('ldrOverlay').style.display = 'none';
    document.getElementById('ldrPanel').style.transform = 'translateX(100%)';
}
function submitLeaveAction(decision) {
    var fd = new FormData();
    fd.append('id', _ldrId);
    fd.append('decision', decision);
    fd.append('comment', document.getElementById('ldrComment').value.trim());
    var msg = document.getElementById('ldrMsg');
    msg.textContent = 'Saving...'; msg.style.color = '#64748b';
    fetch('api/leave/quick-action.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN}, body:fd
    }).then(function(r){return r.json();}).then(function(d){
        if (d.ok) {
            msg.textContent = decision==='approve' ? '&#10003; Approved!' : '&#8617; Returned.';
            msg.style.color = decision==='approve' ? '#166534' : '#dc2626';
            var row = document.getElementById('lr-row-'+_ldrId);
            if (row) { row.style.transition='opacity .4s'; row.style.opacity='0'; setTimeout(function(){row.remove();},400); }
            var kv = document.querySelector('[data-kpi="at_hr_stage"]');
            if (kv) { var n=parseInt(kv.textContent||'0'); kv.textContent=Math.max(0,n-1); }
            var kv2 = document.querySelector('[data-kpi="leave_pending"]');
            if (kv2) { var n2=parseInt(kv2.textContent||'0'); kv2.textContent=Math.max(0,n2-1); }
            setTimeout(closeLeaveDrawer, 1200);
        } else { msg.textContent = d.error||'Error. Try again.'; msg.style.color='#dc2626'; }
    }).catch(function(){ msg.textContent='Network error. Try again.'; msg.style.color='#dc2626'; });
}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function fmtDate(s){if(!s)return'';var p=s.split('-');var ms=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return parseInt(p[2])+' '+ms[parseInt(p[1])-1]+' '+p[0];}
function tick(){var n=new Date(),h=n.getHours(),ap=h>=12?'PM':'AM',h12=h%12||12,el=document.getElementById('clk');if(el)el.textContent=String(h12).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0')+' '+ap;}
tick();setInterval(tick,1000);
(function(){function refreshKpis(){fetch('api/dashboard/kpi.php',{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}}).then(function(r){return r.json();}).then(function(d){if(!d.ok)return;var kpis=d.data.kpis;Object.keys(kpis).forEach(function(k){var el=document.querySelector('[data-kpi="'+k+'"]');if(el){el.textContent=kpis[k];}});}).catch(function(){});}setInterval(refreshKpis,60000);})();
</script>
<?php Layout::end(); ?>
