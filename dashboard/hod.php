<?php
if (!isset($user) || !is_array($user)) { header('Location: ../index.php'); exit; }
$db = getDB();
$mp = Auth::mailPass();
$role = ROLES[$user['role']];
$ini = strtoupper(substr($user['name'],0,1).(strpos($user['name'],' ')!==false?substr($user['name'],strpos($user['name'],' ')+1,1):''));
$firstName = explode(' ', $user['name'])[0];
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$drStmt = $db->prepare("SELECT id, name, email, role, department FROM users WHERE line_manager_id=? AND is_active=1");
$drStmt->execute([$user['id']]); $directReports = $drStmt->fetchAll();
$drIds = array_column($directReports, 'id'); $drCount = count($directReports);

$pendingLeaveList = [];
if (!empty($drIds)) {
    $ph = implode(',', array_fill(0, count($drIds), '?'));
    $lS = $db->prepare("SELECT lr.*,u.name as rname FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE lr.user_id IN ($ph) AND lr.current_stage IN ('submitted','lm_review') ORDER BY lr.created_at DESC LIMIT 8");
    $lS->execute($drIds); $pendingLeaveList = $lS->fetchAll();
}

$teamTasks = [];
if (!empty($drIds)) {
    $ph2 = implode(',', array_fill(0, count($drIds), '?'));
    $tS = $db->prepare("SELECT t.*,u.name as uname FROM tasks t JOIN users u ON u.id=t.user_id WHERE t.user_id IN ($ph2) AND t.status!='done' ORDER BY FIELD(t.priority,'urgent','high','normal','low') LIMIT 8");
    $tS->execute($drIds); $teamTasks = $tS->fetchAll();
}

$sigCount = 0;
try { $sg=$db->prepare("SELECT COUNT(*) FROM sign_signatories ss JOIN sign_requests sr ON sr.id=ss.request_id WHERE ss.user_id=? AND ss.status='pending' AND sr.expires_at>NOW()"); $sg->execute([$user['id']]); $sigCount=(int)$sg->fetchColumn(); } catch(Exception $e){}

$entitled=15;$usedDays=0;$pendDays=0;
try { $b=$db->prepare("SELECT entitled_days FROM leave_balance WHERE user_id=? AND year=YEAR(CURDATE())"); $b->execute([$user['id']]); $br=$b->fetch(); if($br){$entitled=(int)$br['entitled_days'];}else{try{$s2=$db->prepare("SELECT ws.annual_leave_days FROM staff_schedules ss JOIN work_schedules ws ON ws.id=ss.schedule_id WHERE ss.user_id=?");$s2->execute([$user['id']]);$sr2=$s2->fetch();if($sr2)$entitled=(int)$sr2['annual_leave_days'];}catch(PDOException $e){}} } catch(PDOException $e){}
$lU=$db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND (current_stage='approved' OR md_status='approved') AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW())");$lU->execute([$user['id']]);$usedDays=(int)$lU->fetchColumn();
$lP=$db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW()) AND current_stage NOT IN ('approved','rejected')");$lP->execute([$user['id']]);$pendDays=(int)$lP->fetchColumn();
$remainLeave=max(0,$entitled-$usedDays-$pendDays);

$announcements=[];
try{$an=$db->prepare("SELECT a.*,u.name as author FROM announcements a JOIN users u ON u.id=a.user_id WHERE a.is_active=1 AND (a.expires_at IS NULL OR a.expires_at>NOW()) AND (a.target_role IS NULL OR a.target_role=? OR a.target_role='all') ORDER BY a.priority DESC,a.created_at DESC LIMIT 3");$an->execute([$user['role']]);$announcements=$an->fetchAll();}catch(PDOException $e){}

$unread=0;set_time_limit(30);
if($mp){try{@imap_timeout(IMAP_OPENTIMEOUT,5);@imap_timeout(IMAP_READTIMEOUT,8);$mx=@imap_open('{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}INBOX',$user['email'],$mp,0,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);if($mx){$unread=count(@imap_search($mx,'UNSEEN')??[]);imap_close($mx);}}catch(Exception $e){}}

Layout::shell($user, 'dashboard', $unread, 'Dashboard');
?>
<style>
.hri-page{padding:20px;max-width:1160px;margin:0 auto;}
.wlcbar{background:linear-gradient(135deg,#002850 0%,#003a72 100%);border-radius:12px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;box-shadow:0 8px 32px rgba(0,40,80,.14);}
.wav{width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;font-weight:800;font-size:15px;display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.2);flex-shrink:0;}
.wname{font-size:17px;font-weight:700;color:#fff;margin-bottom:3px;}.wsub{font-size:12px;color:rgba(255,255,255,.6);}
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
.pill{padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;}.pill.sub{background:#dbeafe;color:#1e40af;}.pill.lm{background:#fef3c7;color:#92400e;}
.lapp{padding:4px 10px;border-radius:6px;background:#64A014;color:#fff;font-size:11px;font-weight:600;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
.tdot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}.tdue{font-size:11.5px;font-weight:600;padding:2px 7px;border-radius:99px;}.tdue.ov{background:#fee2e2;color:#dc2626;}.tdue.sn{background:#fef3c7;color:#92400e;}.tdue.ok{background:#dcfce7;color:#166534;}
.lbar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin:7px 0;}.lf{height:100%;border-radius:99px;background:#64A014;}.lf.wn{background:#f59e0b;}.lf.dg{background:#dc2626;}
.annrow{padding:10px 14px;border-bottom:1px solid #f8fafc;}.annrow:last-child{border-bottom:none;}.antit{font-size:13px;font-weight:600;color:#0f172a;}.anbod{font-size:12px;color:#64748b;margin-top:3px;line-height:1.5;}
.qagl{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;padding:12px;}
.qa{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 6px;border-radius:9px;background:#f1f5f9;border:1.5px solid transparent;cursor:pointer;transition:all .15s;text-decoration:none;font-family:inherit;}
.qa:hover{background:#e8f0fb;border-color:#002850;}.qi{font-size:20px;}.ql{font-size:11px;font-weight:600;color:#334155;text-align:center;line-height:1.3;}
@media(max-width:900px){.gmain{grid-template-columns:1fr;}}
</style>
<?php $overdueTeam=array_filter($teamTasks,function($t){return $t['due_date']&&strtotime($t['due_date'])<strtotime(date('Y-m-d'));}); ?>
<div class="hri-page">
<?php if(!empty($overdueTeam)): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:11px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;font-size:13px;color:#dc2626;">
    <span style="font-size:18px;">&#9888;</span>
    <span><strong><?=count($overdueTeam)?> team task<?=count($overdueTeam)>1?'s are':' is'?> overdue</strong> — action required</span>
    <a href="tasks.php" style="margin-left:auto;background:#dc2626;color:#fff;padding:5px 13px;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;">View Tasks &#8594;</a>
</div>
<?php endif; ?>
<div class="wlcbar">
    <div class="wav"><?=$ini?></div>
    <div><div class="wname"><?=$greeting?>, <?=$firstName?> <?=$role['icon']?></div><div class="wsub"><?=date('l, d F Y')?> &bull; <?=htmlspecialchars($role['label'])?> &bull; <?=$drCount?> direct report<?=$drCount!==1?'s':''?></div></div>
    <div class="wclk"><div class="wtime" id="clk">--:--:--</div><div class="wtz">WAT (Lagos)</div></div>
</div>

<div class="krow">
    <a class="kpi <?=$unread>0?'gn':'nv'?>" href="mail.php"><div class="kval"><?=$unread?></div><div class="klbl">Unread Mail</div></a>
    <a class="kpi <?=!empty($pendingLeaveList)?'pu':'nv'?>" href="leave-approvals.php"><div class="kval" data-kpi="dr_leave_pending"><?=count($pendingLeaveList)?></div><div class="klbl">Pending Leave</div><div class="ksub">from team</div></a>
    <a class="kpi nv" href="tasks.php"><div class="kval"><?=count($teamTasks)?></div><div class="klbl">Team Tasks</div><div class="ksub">open</div></a>
    <a class="kpi <?=$sigCount>0?'wn':'nv'?>" href="signing.php"><div class="kval"><?=$sigCount?></div><div class="klbl">To Sign</div></a>
    <a class="kpi <?=$remainLeave<=5?'rd':($remainLeave<=10?'wn':'gn')?>" href="leave.php"><div class="kval"><?=$remainLeave?></div><div class="klbl">My Leave Left</div><div class="ksub"><?=$usedDays?>/<?=$entitled?> used</div></a>
</div>

<div class="gmain">
    <div class="cleft">
        <?php require __DIR__ . '/_alerts.php';      // Band 4 — only renders when something is wrong ?>
        <?php require __DIR__ . '/_irs_widget.php';  // Band 1 + my requests ?>
        <?php require __DIR__ . '/_my_work.php';     // Band 2 — my tasks ?>
        <?php require __DIR__ . '/_my_tickets.php';  // Band 2 — my IT tickets ?>
        <div class="card">
            <div class="chd"><div class="cht">&#127958; Team Leave Requests</div><a class="chl" href="leave-approvals.php">Review all &#8594;</a></div>
            <?php if(empty($pendingLeaveList)): ?><div class="empty">No pending leave from your team &#10003;</div>
            <?php else: foreach($pendingLeaveList as $lr): $pc=['submitted'=>'sub','lm_review'=>'lm'][$lr['current_stage']]??'sub'; ?>
            <div class="ritem" id="lr-row-<?=$lr['id']?>">
                <div style="flex:1;"><div style="font-size:13px;font-weight:600;"><?=htmlspecialchars($lr['rname'])?></div><div style="font-size:12px;color:#64748b;"><?=ucfirst($lr['leave_type'])?> &bull; <?=date('d M',strtotime($lr['start_date']))?> – <?=date('d M',strtotime($lr['end_date']))?> (<?=$lr['days']?> days)</div></div>
                <span class="pill <?=$pc?>"><?=ucfirst(str_replace('_',' ',$lr['current_stage']))?></span>
                <button onclick="openLeaveDrawer(<?=$lr['id']?>)" class="lapp">Review</button>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <div class="chd"><div class="cht">&#9989; Team Tasks</div><a class="chl" href="tasks.php">View all &#8594;</a></div>
            <?php if(empty($teamTasks)): ?><div class="empty">No open tasks for your team</div>
            <?php else: $pc2=['urgent'=>'#dc2626','high'=>'#f59e0b','normal'=>'#002850','low'=>'#94a3b8'];
            foreach($teamTasks as $t): $dTs=$t['due_date']?strtotime($t['due_date']):null;$n2=strtotime(date('Y-m-d'));
                if($dTs){if($dTs<$n2){$dc='ov';$ds2='Overdue';}elseif(($dTs-$n2)<172800){$dc='sn';$ds2=date('d M',$dTs);}else{$dc='ok';$ds2=date('d M',$dTs);}}else{$dc='';$ds2='';}
            ?>
            <div class="ritem">
                <div class="tdot" style="background:<?=$pc2[$t['priority']]??'#94a3b8'?>"></div>
                <div style="flex:1;"><div style="font-size:13px;"><?=htmlspecialchars(substr($t['title'],0,50))?></div><div style="font-size:11.5px;color:#94a3b8;"><?=htmlspecialchars($t['uname'])?> &bull; <?=ucfirst($t['priority'])?></div></div>
                <?php if($ds2): ?><div class="tdue <?=$dc?>"><?=$ds2?></div><?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <?php if(!empty($announcements)): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#128226; Announcements</div></div>
            <?php foreach($announcements as $a): ?>
            <div class="annrow"><div class="antit"><?=htmlspecialchars($a['title'])?></div><div class="anbod"><?=htmlspecialchars(substr(strip_tags($a['body']),0,140))?></div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="cright">
        <div class="card">
            <div class="chd"><div class="cht">&#9889; Quick Actions</div></div>
            <div class="qagl">
                <?php if (in_array($user['role'], ['md','bdm','head_accounts','head_it'], true)): ?>
                <a class="qa" href="audit-export.php"><div class="qi">&#128202;</div><div class="ql">Audit Export</div></a>
                <?php endif; ?>
                <button class="qa" onclick="if(typeof hriOpenCompose==='function')hriOpenCompose()"><div class="qi">&#9998;</div><div class="ql">Compose</div></button>
                <a class="qa" href="leave-approvals.php"><div class="qi">&#127958;</div><div class="ql">Leave Queue</div></a>
                <a class="qa" href="tasks.php"><div class="qi">&#9989;</div><div class="ql">Tasks</div></a>
                <a class="qa" href="signing.php"><div class="qi">&#9997;</div><div class="ql">Sign Docs</div></a>
                <a class="qa" href="directory.php"><div class="qi">&#128101;</div><div class="ql">Directory</div></a>
                <a class="qa" href="leave.php"><div class="qi">&#127957;</div><div class="ql">My Leave</div></a>
                <a class="qa" href="vault.php"><div class="qi">&#128193;</div><div class="ql">Vault</div></a>
                <a class="qa" href="profile.php"><div class="qi">&#128100;</div><div class="ql">My Profile</div></a>
            </div>
        </div>

        <div class="card">
            <div class="chd"><div class="cht">&#127958; My Leave Balance <?=date('Y')?></div><a class="chl" href="leave.php">Request &#8594;</a></div>
            <div style="padding:13px 14px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span><strong style="font-size:20px;color:#002850;"><?=$remainLeave?></strong> days remaining</span><span style="color:#94a3b8;"><?=$usedDays?>/<?=$entitled?> used</span></div>
                <div class="lbar"><div class="lf <?=$remainLeave<=5?'dg':($remainLeave<=10?'wn':'')?>" style="width:<?=min(100,$entitled>0?round($usedDays/$entitled*100):0)?>%"></div></div>
                <?php if($pendDays>0): ?><div style="font-size:12px;color:#f59e0b;margin-top:4px;">&#8987; <?=$pendDays?> days pending</div><?php endif; ?>
            </div>
        </div>

    </div>
</div>
<?php require __DIR__ . '/_roster.php';      // Band 3 — shared Staff Roster, role-restricted ?>
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
var _leaveDat = <?=json_encode(array_values($pendingLeaveList))?>;
var _ldrId = 0;
function openLeaveDrawer(id) {
    _ldrId = id;
    var lr = null;
    for (var i = 0; i < _leaveDat.length; i++) { if (parseInt(_leaveDat[i].id) === id) { lr = _leaveDat[i]; break; } }
    if (!lr) { window.location='leave-approvals.php'; return; }
    var types = lr.leave_type.replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();});
    document.getElementById('ldrBody').innerHTML =
        '<div style="margin-bottom:12px;"><div style="font-size:17px;font-weight:800;color:#002850;margin-bottom:2px;">'+escHtml(lr.rname)+'</div>'
        + '<div style="font-size:12px;color:#94a3b8;">Leave request #'+lr.id+'</div></div>'
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
    fd.append('id', _ldrId); fd.append('decision', decision);
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
            var kv = document.querySelector('[data-kpi="dr_leave_pending"]');
            if (kv) { var n=parseInt(kv.textContent||'0'); kv.textContent=Math.max(0,n-1); }
            setTimeout(closeLeaveDrawer, 1200);
        } else { msg.textContent = d.error||'Error. Try again.'; msg.style.color='#dc2626'; }
    }).catch(function(){ msg.textContent='Network error. Try again.'; msg.style.color='#dc2626'; });
}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function fmtDate(s){if(!s)return'';var p=s.split('-');var ms=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return parseInt(p[2])+' '+ms[parseInt(p[1])-1]+' '+p[0];}
function tick(){var n=new Date(),h=n.getHours(),ap=h>=12?'PM':'AM',h12=h%12||12,el=document.getElementById('clk');if(el)el.textContent=String(h12).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0')+' '+ap;}
tick();setInterval(tick,1000);
(function(){function refreshKpis(){fetch('api/dashboard/kpi.php',{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}}).then(function(r){return r.json();}).then(function(d){if(!d.ok)return;var kpis=d.data.kpis;Object.keys(kpis).forEach(function(k){var el=document.querySelector('[data-kpi="'+k+'"]');if(el)el.textContent=kpis[k];});}).catch(function(){});}setInterval(refreshKpis,60000);})();
</script>
<?php Layout::end(); ?>
