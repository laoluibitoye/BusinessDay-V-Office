<?php
if (!isset($user) || !is_array($user)) { header('Location: ../index.php'); exit; }
$db = getDB();
$mp = Auth::mailPass();
$role = ROLES[$user['role']];
$ini = strtoupper(substr($user['name'],0,1).(strpos($user['name'],' ')!==false?substr($user['name'],strpos($user['name'],' ')+1,1):''));
$firstName = explode(' ', $user['name'])[0];
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$tasks = $db->prepare("SELECT * FROM tasks WHERE user_id=? AND status!='done' ORDER BY FIELD(priority,'urgent','high','normal','low'), CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date ASC LIMIT 8");
$tasks->execute([$user['id']]); $myTasks = $tasks->fetchAll();
$overdue = array_filter($myTasks, function($t){ return $t['due_date'] && strtotime($t['due_date']) < strtotime(date('Y-m-d')); });

$sigCount = 0;
try {
    $sg = $db->prepare("SELECT COUNT(*) FROM sign_signatories ss JOIN sign_requests sr ON sr.id=ss.request_id WHERE ss.user_id=? AND ss.status='pending' AND sr.expires_at > NOW()");
    $sg->execute([$user['id']]); $sigCount = (int)$sg->fetchColumn();
} catch(Exception $e){}

$entitled = 15; $usedDays = 0; $pendDays = 0;
try {
    $b = $db->prepare("SELECT entitled_days FROM leave_balance WHERE user_id=? AND year=YEAR(CURDATE())");
    $b->execute([$user['id']]); $br = $b->fetch();
    if ($br) { $entitled = (int)$br['entitled_days']; }
    else {
        try {
            $s2 = $db->prepare("SELECT ws.annual_leave_days FROM staff_schedules ss JOIN work_schedules ws ON ws.id=ss.schedule_id WHERE ss.user_id=?");
            $s2->execute([$user['id']]); $sr2 = $s2->fetch();
            if ($sr2) $entitled = (int)$sr2['annual_leave_days'];
        } catch(PDOException $e){}
    }
} catch(PDOException $e){}
$lU = $db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND (current_stage='approved' OR md_status='approved') AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW())");
$lU->execute([$user['id']]); $usedDays = (int)$lU->fetchColumn();
$lP = $db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW()) AND current_stage NOT IN ('approved','rejected')");
$lP->execute([$user['id']]); $pendDays = (int)$lP->fetchColumn();
$remainLeave = max(0, $entitled - $usedDays - $pendDays);

$curLeave = $db->prepare("SELECT * FROM leave_requests WHERE user_id=? AND current_stage NOT IN ('approved','rejected') ORDER BY created_at DESC LIMIT 1");
$curLeave->execute([$user['id']]); $curLeaveRow = $curLeave->fetch();

$announcements = [];
try {
    $an = $db->prepare("SELECT a.*, u.name as author FROM announcements a JOIN users u ON u.id=a.user_id WHERE a.is_active=1 AND (a.expires_at IS NULL OR a.expires_at > NOW()) AND (a.target_role IS NULL OR a.target_role=? OR a.target_role='all') ORDER BY a.priority DESC, a.created_at DESC LIMIT 5");
    $an->execute([$user['role']]); $announcements = $an->fetchAll();
} catch(PDOException $e){}

$emails = []; $unread = 0;
set_time_limit(30);
if ($mp) {
    try {
        @imap_timeout(IMAP_OPENTIMEOUT, 5); @imap_timeout(IMAP_READTIMEOUT, 8);
        $mbox = @imap_open('{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}INBOX', $user['email'], $mp, 0, 1, ['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
        if ($mbox) {
            $total = imap_num_msg($mbox); $unread = count(@imap_search($mbox,'UNSEEN') ?: []);
            if ($total > 0) { $s = max(1,$total-4); $emails = array_reverse(@imap_fetch_overview($mbox,"$s:$total") ?: []); }
            imap_close($mbox);
        }
    } catch(Exception $e){}
}
$avColors = ['#002850','#64A014','#8b5cf6','#f97316','#3b82f6','#dc2626','#0891b2'];
function dshAvCol($s,$c){return $c[abs(crc32($s))%count($c)];}
function dshFrom($f){if(preg_match('/"?([^"<]+)"?\s*<?/',$f,$m))return trim($m[1],'" ');return $f;}

Layout::shell($user, 'dashboard', $unread, 'Dashboard');
?>
<style>
.hri-page{padding:20px;max-width:1160px;margin:0 auto;}
.wlcbar{background:linear-gradient(135deg,#002850 0%,#003a72 100%);border-radius:12px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;box-shadow:0 8px 32px rgba(0,40,80,.14);}
.wav{width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;font-weight:800;font-size:15px;display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.2);flex-shrink:0;}
.wname{font-size:17px;font-weight:700;color:#fff;margin-bottom:3px;} .wsub{font-size:12px;color:rgba(255,255,255,.6);}
.wclk{margin-left:auto;text-align:right;} .wtime{font-size:24px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums;} .wtz{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px;}
.abar{border-radius:10px;padding:11px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;font-size:13px;}
.abar.red{background:#fee2e2;border:1px solid #fca5a5;color:#dc2626;} .abar.warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.krow{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:12px;margin-bottom:18px;}
.kpi{background:#fff;border-radius:12px;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid #e2e8f0;display:block;text-decoration:none;transition:all .2s;}
.kpi:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);transform:translateY(-2px);}
.kpi.nv{border-top-color:#002850;} .kpi.gn{border-top-color:#64A014;} .kpi.rd{border-top-color:#dc2626;} .kpi.wn{border-top-color:#f59e0b;}
.kval{font-size:28px;font-weight:800;color:#002850;line-height:1;margin-bottom:4px;} .klbl{font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;} .ksub{font-size:11.5px;color:#94a3b8;margin-top:3px;}
.gmain{display:grid;grid-template-columns:1fr 310px;gap:16px;}
.cleft,.cright{display:flex;flex-direction:column;gap:16px;}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.chd{padding:11px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
.cht{font-size:13px;font-weight:700;color:#002850;display:flex;align-items:center;gap:7px;}
.chl{font-size:12.5px;color:#64A014;font-weight:600;text-decoration:none;} .chl:hover{text-decoration:underline;}
.empty{padding:26px;text-align:center;color:#94a3b8;font-size:13px;}
.erow{display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid #f1f5f9;text-decoration:none;transition:background .1s;}
.erow:hover{background:#f8fafc;} .erow.ur .ef{font-weight:700;color:#002850;}
.eav{width:32px;height:32px;border-radius:50%;color:#fff;font-weight:700;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.eb{flex:1;min-width:0;} .ef{font-size:12.5px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;} .es{font-size:12px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;} .et{font-size:11px;color:#94a3b8;} .upip{width:6px;height:6px;border-radius:50%;background:#64A014;flex-shrink:0;}
.trow{display:flex;align-items:center;gap:9px;padding:9px 14px;border-bottom:1px solid #f1f5f9;}
.tdot{width:9px;height:9px;border-radius:50%;flex-shrink:0;} .tb{flex:1;min-width:0;} .tt{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;} .tp{font-size:11px;color:#94a3b8;}
.tdue{font-size:11.5px;font-weight:600;padding:2px 7px;border-radius:99px;} .tdue.ov{background:#fee2e2;color:#dc2626;} .tdue.sn{background:#fef3c7;color:#92400e;} .tdue.ok{background:#dcfce7;color:#166534;}
.qagl{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;padding:12px;}
.qa{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 6px;border-radius:9px;background:#f1f5f9;border:1.5px solid transparent;cursor:pointer;transition:all .15s;text-decoration:none;font-family:inherit;}
.qa:hover{background:#e8f0fb;border-color:#002850;} .qi{font-size:20px;} .ql{font-size:11px;font-weight:600;color:#334155;text-align:center;line-height:1.3;}
.lbar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin:7px 0;}
.lf{height:100%;border-radius:99px;background:#64A014;} .lf.wn{background:#f59e0b;} .lf.dg{background:#dc2626;}
.annrow{padding:10px 14px;border-bottom:1px solid #f8fafc;} .annrow:last-child{border-bottom:none;}
.antit{font-size:13px;font-weight:600;color:#0f172a;} .anbod{font-size:12px;color:#64748b;margin-top:3px;line-height:1.5;} .anmet{font-size:11px;color:#94a3b8;margin-top:3px;}
.apil{display:inline-block;padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;margin-bottom:3px;}
.apil.high{background:#fee2e2;color:#dc2626;} .apil.normal{background:#dbeafe;color:#1e40af;} .apil.low{background:#f1f5f9;color:#64748b;}
.lstatus{padding:12px 14px;} .sbadge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600;}
.sbadge.sub{background:#dbeafe;color:#1e40af;} .sbadge.lm{background:#fef3c7;color:#92400e;} .sbadge.hr{background:#e0e7ff;color:#4338ca;} .sbadge.ap{background:#dcfce7;color:#166534;} .sbadge.rj{background:#fee2e2;color:#dc2626;}
@media(max-width:900px){.gmain{grid-template-columns:1fr;}.cright{display:grid;grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.krow{grid-template-columns:repeat(2,1fr);}.cright{grid-template-columns:1fr;}}
</style>
<div class="hri-page">
<?php if(!empty($overdue)): ?>
<div class="abar red">&#9888; <?=count($overdue)?> task<?=count($overdue)>1?'s':''?> overdue<?php if($sigCount>0): ?> &bull; <?=$sigCount?> document<?=$sigCount>1?'s':''?> to sign<?php endif; ?>
<a href="tasks.php" style="margin-left:auto;background:#dc2626;color:#fff;padding:5px 13px;border-radius:7px;font-size:12.5px;font-weight:600;white-space:nowrap;">View &#8594;</a></div>
<?php elseif($sigCount>0): ?>
<div class="abar warn">&#9997; <?=$sigCount?> document<?=$sigCount>1?'s':''?> awaiting your signature
<a href="signing.php" style="margin-left:auto;background:#92400e;color:#fff;padding:5px 13px;border-radius:7px;font-size:12.5px;font-weight:600;white-space:nowrap;">Review &#8594;</a></div>
<?php endif; ?>

<div class="wlcbar">
    <div class="wav"><?=$ini?></div>
    <div><div class="wname"><?=$greeting?>, <?=$firstName?> <?=$role['icon']?></div><div class="wsub"><?=date('l, d F Y')?> &bull; <?=htmlspecialchars($user['email'])?></div></div>
    <div class="wclk"><div class="wtime" id="clk">--:--:--</div><div class="wtz">WAT (Lagos)</div></div>
</div>

<div class="krow">
    <a class="kpi <?=$unread>0?'gn':'nv'?>" href="mail.php"><div class="kval"><?=$unread?></div><div class="klbl">Unread Emails</div></a>
    <a class="kpi <?=count($overdue)>0?'rd':(count($myTasks)>0?'wn':'nv')?>" href="tasks.php"><div class="kval" data-kpi="my_tasks"><?=count($myTasks)?></div><div class="klbl">Open Tasks</div></a>
    <a class="kpi <?=$sigCount>0?'wn':'nv'?>" href="signing.php"><div class="kval" data-kpi="sig_pending"><?=$sigCount?></div><div class="klbl">To Sign</div></a>
    <a class="kpi <?=$remainLeave<=5?'rd':($remainLeave<=10?'wn':'gn')?>" href="leave.php"><div class="kval" data-kpi="remain_leave"><?=$remainLeave?></div><div class="klbl">Leave Days Left</div><div class="ksub"><?=$usedDays?>/<?=$entitled?> used</div></a>
</div>

<div class="gmain">
    <div class="cleft">
        <div class="card">
            <div class="chd"><div class="cht">&#128236; Recent Inbox <?php if($unread>0): ?><span style="background:#64A014;color:#fff;font-size:10.5px;padding:1px 8px;border-radius:99px;font-weight:700;"><?=$unread?> new</span><?php endif; ?></div><a class="chl" href="mail.php">View all &#8594;</a></div>
            <?php if(empty($emails)): ?><div class="empty">&#128236; <?=$mp?'No messages':'Log in to mail first'?></div>
            <?php else: foreach($emails as $em):
                $fn=dshFrom($em->from??'Unknown'); $subj=$em->subject??'(no subject)';
                if(preg_match('/=\?[^?]+\?[BbQq]\?/',$subj)) $subj=imap_utf8($subj);
                $ini2=strtoupper(substr($fn,0,2)); $col=dshAvCol($ini2,$avColors);
                $ts=strtotime($em->date??'now'); $ds=date('Ymd',$ts)===date('Ymd')?date('h:i A',$ts):date('d M',$ts);
                $ur=!($em->seen??false);
            ?>
            <a class="erow <?=$ur?'ur':''?>" href="mail.php?uid=<?=$em->uid?>&f=INBOX">
                <?php if($ur): ?><div class="upip"></div><?php endif; ?>
                <div class="eav" style="background:<?=$col?>"><?=htmlspecialchars($ini2)?></div>
                <div class="eb"><div class="ef"><?=htmlspecialchars(substr($fn,0,30))?></div><div class="es"><?=htmlspecialchars(substr($subj,0,60))?></div></div>
                <div class="et"><?=$ds?></div>
            </a>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <div class="chd"><div class="cht">&#9989; My Tasks</div><a class="chl" href="tasks.php">Manage all &#8594;</a></div>
            <?php if(empty($myTasks)): ?><div class="empty">&#10003; All caught up — no open tasks</div>
            <?php else: $pc=['urgent'=>'#dc2626','high'=>'#f59e0b','normal'=>'#002850','low'=>'#94a3b8'];
            foreach($myTasks as $t): $dTs=$t['due_date']?strtotime($t['due_date']):null; $n2=strtotime(date('Y-m-d'));
                if($dTs){if($dTs<$n2){$dc='ov';$ds2='Overdue';}elseif(($dTs-$n2)<172800){$dc='sn';$ds2=date('d M',$dTs);}else{$dc='ok';$ds2=date('d M',$dTs);}}else{$dc='';$ds2='';}
            ?>
            <div class="trow">
                <div class="tdot" style="background:<?=$pc[$t['priority']]??'#94a3b8'?>"></div>
                <div class="tb"><div class="tt"><?=htmlspecialchars(substr($t['title'],0,55))?></div><div class="tp"><?=ucfirst($t['priority'])?> priority</div></div>
                <?php if($ds2): ?><div class="tdue <?=$dc?>"><?=$ds2?></div><?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <?php if(!empty($announcements)): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#128226; Announcements</div></div>
            <?php foreach($announcements as $a): ?>
            <div class="annrow">
                <span class="apil <?=$a['priority']?>"><?=ucfirst($a['priority'])?></span>
                <div class="antit"><?=htmlspecialchars($a['title'])?></div>
                <div class="anbod"><?=htmlspecialchars(substr(strip_tags($a['body']),0,150))?></div>
                <div class="anmet">— <?=htmlspecialchars($a['author'])?> &bull; <?=date('d M',strtotime($a['created_at']))?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="cright">
        <div class="card">
            <div class="chd"><div class="cht">&#9889; Quick Actions</div></div>
            <div class="qagl">
                <button class="qa" onclick="if(typeof hriOpenCompose==='function')hriOpenCompose()"><div class="qi">&#9998;</div><div class="ql">Compose Email</div></button>
                <a class="qa" href="tasks.php"><div class="qi">&#9989;</div><div class="ql">My Tasks</div></a>
                <a class="qa" href="leave.php"><div class="qi">&#127958;</div><div class="ql">Request Leave</div></a>
                <a class="qa" href="signing.php"><div class="qi">&#9997;</div><div class="ql">Sign Document</div></a>
                <a class="qa" href="vault.php"><div class="qi">&#128193;</div><div class="ql">Document Vault</div></a>
                <a class="qa" href="it-request.php"><div class="qi">&#128295;</div><div class="ql">IT Support</div></a>
                <a class="qa" href="directory.php"><div class="qi">&#128101;</div><div class="ql">Staff Directory</div></a>
                <a class="qa" href="profile.php"><div class="qi">&#128100;</div><div class="ql">My Profile</div></a>
                <a class="qa" href="signature.php"><div class="qi">&#128394;</div><div class="ql">My Signature</div></a>
            </div>
        </div>

        <div class="card">
            <div class="chd"><div class="cht">&#127958; Leave Balance <?=date('Y')?></div><a class="chl" href="leave.php">Request &#8594;</a></div>
            <div style="padding:13px 14px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                    <span><strong style="font-size:20px;color:#002850;"><?=$remainLeave?></strong> days remaining</span>
                    <span style="color:#94a3b8;"><?=$usedDays?>/<?=$entitled?> used</span>
                </div>
                <div class="lbar"><div class="lf <?=$remainLeave<=5?'dg':($remainLeave<=10?'wn':'')?>" style="width:<?=min(100,$entitled>0?round($usedDays/$entitled*100):0)?>%"></div></div>
                <?php if($pendDays>0): ?><div style="font-size:12px;color:#f59e0b;margin-top:4px;">&#8987; <?=$pendDays?> days pending approval</div><?php endif; ?>
                <?php if($remainLeave<=5): ?><div style="font-size:12px;color:#dc2626;margin-top:4px;font-weight:600;">&#9888; Only <?=$remainLeave?> days left</div><?php endif; ?>
            </div>
        </div>

        <?php if($curLeaveRow): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#127958; My Leave Request</div><a class="chl" href="leave.php">All requests &#8594;</a></div>
            <div class="lstatus">
                <div style="font-size:13px;font-weight:600;"><?=ucfirst($curLeaveRow['leave_type'])?> Leave</div>
                <div style="font-size:12px;color:#64748b;margin:4px 0 10px;"><?=date('d M Y',strtotime($curLeaveRow['start_date']));?> — <?=date('d M Y',strtotime($curLeaveRow['end_date']))?> (<?=$curLeaveRow['days']?> days)</div>
                <?php
                $stage=$curLeaveRow['current_stage'];
                $rejected = ($stage === 'rejected');
                $stageSteps = [
                    'submitted' => 'Submitted',
                    'lm_review' => 'Line Mgr',
                    'hr_review' => 'HR Review',
                    'md_review' => 'MD Review',
                    'approved'  => 'Approved',
                ];
                $stageOrder = array_keys($stageSteps);
                $curIdx = array_search($stage, $stageOrder);
                if ($curIdx === false) $curIdx = 0;
                ?>
                <?php if($rejected): ?>
                <span class="sbadge rj">&#10007; Rejected</span>
                <?php else: ?>
                <div style="display:flex;align-items:center;gap:0;margin-top:2px;overflow-x:auto;">
                <?php foreach($stageSteps as $sk => $sl):
                    $idx = array_search($sk, $stageOrder);
                    $done = $idx < $curIdx;
                    $active = $idx === $curIdx;
                    $color = $active ? '#002850' : ($done ? '#64A014' : '#cbd5e1');
                    $txtCol = $active ? '#002850' : ($done ? '#166534' : '#94a3b8');
                    $fw = $active ? '700' : ($done ? '600' : '400');
                ?>
                <div style="display:flex;flex-direction:column;align-items:center;flex:1;min-width:0;">
                    <div style="width:24px;height:24px;border-radius:50%;background:<?=$color?>;display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;font-weight:700;flex-shrink:0;"><?=$done?'&#10003;':($idx+1)?></div>
                    <div style="font-size:9.5px;color:<?=$txtCol?>;font-weight:<?=$fw?>;text-align:center;margin-top:3px;white-space:nowrap;"><?=$sl?></div>
                </div>
                <?php if($idx < count($stageSteps)-1): ?>
                <div style="height:2px;flex:1;background:<?=$done?'#64A014':'#e2e8f0'?>;margin-bottom:14px;min-width:8px;"></div>
                <?php endif; ?>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/_alerts.php';      // Band 4 — only renders when something is wrong ?>
<?php require __DIR__ . '/_irs_widget.php';  // Band 1 + my requests ?>
<?php require __DIR__ . '/_my_work.php';     // Band 2 — my tasks ?>
<?php require __DIR__ . '/_my_tickets.php';  // Band 2 — my IT tickets ?>
<?php require __DIR__ . '/_roster.php';      // Band 3 — shared Staff Roster, role-restricted ?>
</div>
<script>
function tick(){var n=new Date(),h=n.getHours(),ap=h>=12?'PM':'AM',h12=h%12||12,el=document.getElementById('clk');if(el)el.textContent=String(h12).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0')+' '+ap;}
tick();setInterval(tick,1000);
(function(){function refreshKpis(){fetch('api/dashboard/kpi.php',{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}}).then(function(r){return r.json();}).then(function(d){if(!d.ok)return;var kpis=d.data.kpis;Object.keys(kpis).forEach(function(k){var el=document.querySelector('[data-kpi="'+k+'"]');if(el)el.textContent=kpis[k];});}).catch(function(){});}setInterval(refreshKpis,60000);})();
</script>
<?php Layout::end(); ?>
