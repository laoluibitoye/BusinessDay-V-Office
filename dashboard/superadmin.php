<?php
if (!isset($user) || !is_array($user)) { header('Location: ../index.php'); exit; }
$db = getDB();
$mp = Auth::mailPass();
$ini = strtoupper(substr($user['name'],0,1).(strpos($user['name'],' ')!==false?substr($user['name'],strpos($user['name'],' ')+1,1):''));
$firstName = explode(' ', $user['name'])[0];
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

// ── SYSTEM STATS ─────────────────────────────────────────────
$totalStaff = $onlineStaff = $loginFails = 0;
try {
    $statsRow = $db->query("SELECT
        (SELECT COUNT(*) FROM users WHERE is_active=1) AS total_staff,
        (SELECT COUNT(DISTINCT user_id) FROM sessions WHERE expires_at>NOW() AND COALESCE(last_active,created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE)) AS online_staff,
        (SELECT COUNT(*) FROM login_history WHERE status='failed' AND DATE(created_at)=CURDATE()) AS login_fails
    ")->fetch(PDO::FETCH_ASSOC);
    $totalStaff  = (int)($statsRow['total_staff']  ?? 0);
    $onlineStaff = (int)($statsRow['online_staff'] ?? 0);
    $loginFails  = (int)($statsRow['login_fails']  ?? 0);
} catch (Exception $e) {}

// ── IT STATS ─────────────────────────────────────────────────
$openIT = 0; $itList = []; $itStats = [];
try {
    $openIT            = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE status IN ('open','in_progress')")->fetchColumn();
    $itStats['open']   = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE status='open'")->fetchColumn();
    $itStats['inprog'] = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE status='in_progress'")->fetchColumn();
    $itStats['closed'] = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE DATE(created_at)=CURDATE() AND status='closed'")->fetchColumn();
    $itList            = $db->query("SELECT ir.*,u.name as rname FROM it_requests ir JOIN users u ON u.id=ir.user_id WHERE ir.status IN ('open','in_progress') ORDER BY FIELD(ir.priority,'urgent','high','normal','low'),ir.created_at DESC LIMIT 8")->fetchAll();
} catch(Exception $e){}

// ── NDPC / COMPLIANCE ────────────────────────────────────────
$ndpcDays = null;
try { $r=$db->query("SELECT DATEDIFF(expiry_date,CURDATE()) as dl FROM compliance_docs WHERE LOWER(name) LIKE '%ndpc%' LIMIT 1"); $row=$r->fetch(); if($row) $ndpcDays=(int)$row['dl']; } catch(Exception $e){}
$expiryList = [];
try { $expiryList=$db->query("SELECT name,expiry_date,DATEDIFF(expiry_date,CURDATE()) as dl FROM compliance_docs WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) ORDER BY expiry_date LIMIT 6")->fetchAll(); } catch(Exception $e){}

// ── SECURITY ────────────────────────────────────────────────
$onlineList  = $db->query("SELECT u.name,u.role,MIN(s.ip_address) as ip_address,MAX(COALESCE(s.last_active,s.created_at)) as last_active FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.expires_at>NOW() AND COALESCE(s.last_active,s.created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE) GROUP BY u.id,u.name,u.role ORDER BY last_active DESC LIMIT 12")->fetchAll();
$recentFails = $db->query("SELECT lh.*,u.name FROM login_history lh LEFT JOIN users u ON u.id=lh.user_id WHERE lh.status='failed' AND lh.created_at>DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY lh.created_at DESC LIMIT 8")->fetchAll();
$auditList   = $db->query("SELECT al.action,al.detail,al.created_at,al.ip_address,u.name FROM audit_log al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.created_at DESC LIMIT 12")->fetchAll();
$breachList  = []; try{$breachList=$db->query("SELECT * FROM breach_log ORDER BY created_at DESC LIMIT 5")->fetchAll();}catch(Exception $e){}

// ── APP USAGE STATS (7-day summary) ──────────────────────────
$usageStats = []; $usageToday = [];
try {
    $uRows = $db->query("SELECT metric, SUM(count) AS total_week, SUM(IF(DATE(date)=CURDATE(),count,0)) AS today FROM usage_stats WHERE date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY metric")->fetchAll();
    foreach ($uRows as $ur) {
        $usageStats[$ur['metric']] = (int)$ur['total_week'];
        $usageToday[$ur['metric']] = (int)$ur['today'];
    }
} catch(Exception $e){}

// ── SUBSCRIPTION EXPIRY ALERTS ────────────────────────────────
$subAlerts = [];
try { $subAlerts = $db->query("SELECT name, next_renewal_date, DATEDIFF(next_renewal_date,CURDATE()) as dl FROM subscriptions WHERE next_renewal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND is_active=1 ORDER BY next_renewal_date LIMIT 4")->fetchAll(); } catch(Exception $e){}

// ── BREACH COUNT ─────────────────────────────────────────────
$breachOpen = 0;
try { $breachOpen = (int)$db->query("SELECT COUNT(*) FROM breach_log WHERE status='open'")->fetchColumn(); } catch(Exception $e){}

// ── MY OWN LEAVE BALANCE ─────────────────────────────────────
$entitled=15; $usedDays=0; $pendDays=0;
try { $b=$db->prepare("SELECT entitled_days FROM leave_balance WHERE user_id=? AND year=YEAR(CURDATE())"); $b->execute([$user['id']]); $br=$b->fetch(); if($br)$entitled=(int)$br['entitled_days']; } catch(Exception $e){}
$lU=$db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND (current_stage='approved' OR md_status='approved') AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW())");
$lU->execute([$user['id']]); $usedDays=(int)$lU->fetchColumn();
$lP=$db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type='annual' AND YEAR(start_date)=YEAR(NOW()) AND current_stage NOT IN ('approved','rejected')");
$lP->execute([$user['id']]); $pendDays=(int)$lP->fetchColumn();
$remainLeave=max(0,$entitled-$usedDays-$pendDays);

// ── IMAP UNREAD ───────────────────────────────────────────────
$unread=0; set_time_limit(30);
if($mp){try{@imap_timeout(IMAP_OPENTIMEOUT,5);@imap_timeout(IMAP_READTIMEOUT,8);$mx=@imap_open('{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}INBOX',$user['email'],$mp,0,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);if($mx){$unread=count(@imap_search($mx,'UNSEEN')??[]);imap_close($mx);}}catch(Exception $e){}}

Layout::shell($user, 'dashboard', $unread, 'Dashboard');
$announcements = Layout::getAnnouncements();
?>
<style>
.hri-page{padding:20px;max-width:1300px;margin:0 auto;}
/* Welcome bar */
.wlcbar{background:linear-gradient(135deg,#002850 0%,#0c4a6e 60%,#003a72 100%);border-radius:12px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;box-shadow:0 8px 32px rgba(0,40,80,.18);}
.wav{width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;font-weight:800;font-size:16px;display:flex;align-items:center;justify-content:center;border:2px solid rgba(100,160,20,.5);flex-shrink:0;}
.wname{font-size:17px;font-weight:700;color:#fff;margin-bottom:3px;}.wsub{font-size:12px;color:rgba(255,255,255,.6);}
.wbadge{margin-left:10px;background:rgba(100,160,20,.25);border:1px solid rgba(100,160,20,.5);color:#a3e635;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;letter-spacing:.04em;}
.wclk{margin-left:auto;text-align:right;}.wtime{font-size:24px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums;}.wtz{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px;}
/* KPI row */
.krow{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.kpi{background:#fff;border-radius:12px;padding:14px 15px;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid #e2e8f0;display:block;text-decoration:none;transition:all .2s;}.kpi:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);transform:translateY(-2px);}
.kpi.nv{border-top-color:#002850;}.kpi.gn{border-top-color:#64A014;}.kpi.rd{border-top-color:#dc2626;}.kpi.wn{border-top-color:#f59e0b;}.kpi.bl{border-top-color:#0891b2;}.kpi.cy{border-top-color:#0d9488;}
.kval{font-size:26px;font-weight:800;color:#002850;line-height:1;margin-bottom:4px;}.klbl{font-size:10.5px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}.ksub{font-size:11px;color:#94a3b8;margin-top:2px;}
/* Section headers */
.sec-hd{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin:22px 0 10px;display:flex;align-items:center;gap:8px;}
.sec-hd::after{content:'';flex:1;height:1px;background:#f1f5f9;}
/* Main grid */
.gmain{display:grid;grid-template-columns:1fr 290px;gap:16px;}.cleft,.cright{display:flex;flex-direction:column;gap:16px;}
/* Cards */
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.chd{padding:11px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
.cht{font-size:13px;font-weight:700;color:#002850;}.chl{font-size:12px;color:#64A014;font-weight:600;text-decoration:none;}.chl:hover{text-decoration:underline;}
.empty{padding:24px;text-align:center;color:#94a3b8;font-size:13px;}
.ritem{display:flex;align-items:center;gap:9px;padding:9px 14px;border-bottom:1px solid #f1f5f9;}.ritem:last-child{border-bottom:none;}
/* Pills */
.pill{padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;}
.pill.op{background:#fee2e2;color:#dc2626;}.pill.ip{background:#fef3c7;color:#92400e;}.pill.cl{background:#dcfce7;color:#166534;}
.dtag{padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;}.dtag.rd{background:#fee2e2;color:#dc2626;}.dtag.wn{background:#fef3c7;color:#92400e;}.dtag.ok{background:#dcfce7;color:#166534;}
/* Buttons */
.itbtn{padding:4px 10px;border-radius:6px;background:#0891b2;color:#fff;font-size:11px;font-weight:600;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
/* Dots */
.odot{width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;}
.adot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.pdot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
/* Quick actions */
.qagl{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;padding:10px;}
.qa{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 4px;border-radius:9px;background:#f8fafc;border:1.5px solid transparent;cursor:pointer;transition:all .15s;text-decoration:none;font-family:inherit;}
.qa:hover{background:#e8f0fb;border-color:#002850;}.qi{font-size:18px;}.ql{font-size:10.5px;font-weight:600;color:#334155;text-align:center;line-height:1.3;}
/* Leave bar */
.lbar{height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin:6px 0;}.lf{height:100%;border-radius:99px;background:#64A014;}.lf.wn{background:#f59e0b;}.lf.dg{background:#dc2626;}
/* App usage grid */
.ugrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;padding:12px 14px;}
.ucard{background:#f8fafc;border-radius:9px;padding:12px 14px;text-align:center;border:1.5px solid #f1f5f9;}
.uval{font-size:22px;font-weight:800;color:#002850;line-height:1;}.ulbl{font-size:10.5px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-top:3px;}
.utoday{font-size:11px;color:#64A014;font-weight:600;margin-top:2px;}
/* Announcements */
.annrow{padding:10px 14px;border-bottom:1px solid #f8fafc;}.annrow:last-child{border-bottom:none;}.antit{font-size:13px;font-weight:600;color:#0f172a;}.anbod{font-size:12px;color:#64748b;margin-top:3px;line-height:1.5;}
@media(max-width:1024px){.gmain{grid-template-columns:1fr;}}
@media(max-width:600px){.krow{grid-template-columns:repeat(2,1fr);}.wlcbar{flex-wrap:wrap;}.wclk{margin-left:0;}}
</style>

<div class="hri-page">

<!-- ── WELCOME BAR ─────────────────────────────────────── -->
<div class="wlcbar">
    <div class="wav"><?=$ini?></div>
    <div>
        <div class="wname"><?=$greeting?>, <?=$firstName?> &#128737;<span class="wbadge">SUPER ADMIN</span></div>
        <div class="wsub"><?=date('l, d F Y')?> &bull; HR Indexx Limited &bull; <?=$totalStaff?> active users &bull; <?=$onlineStaff?> online now</div>
    </div>
    <div class="wclk"><div class="wtime" id="clk">--:--:--</div><div class="wtz">WAT (Lagos)</div></div>
</div>

<!-- ── KPI ROW ────────────────────────────────────────── -->
<div class="krow">
    <a class="kpi nv" href="admin/users.php"><div class="kval"><?=$totalStaff?></div><div class="klbl">Active Users</div><div class="ksub"><?=$onlineStaff?> online now</div></a>
    <a class="kpi bl" href="admin/sessions.php"><div class="kval" data-kpi="staff_online"><?=$onlineStaff?></div><div class="klbl">Active Sessions</div><div class="ksub">last 30 minutes</div></a>
    <a class="kpi <?=$unread>0?'gn':'nv'?>" href="mail.php"><div class="kval"><?=$unread?></div><div class="klbl">Unread Mail</div><div class="ksub">your inbox</div></a>
    <a class="kpi <?=$openIT>0?'wn':'gn'?>" href="admin/it-requests.php"><div class="kval" data-kpi="it_open"><?=$openIT?></div><div class="klbl">IT Tickets</div><div class="ksub"><?=$itStats['inprog']??0?> in progress</div></a>
    <a class="kpi <?=$loginFails>=3?'rd':($loginFails>0?'wn':'gn')?>" href="admin/audit.php"><div class="kval" data-kpi="login_fails"><?=$loginFails?></div><div class="klbl">Failed Logins</div><div class="ksub">today</div></a>
    <?php if($ndpcDays!==null): ?>
    <a class="kpi <?=$ndpcDays<30?'rd':($ndpcDays<60?'wn':'gn')?>" href="compliance.php"><div class="kval"><?=$ndpcDays?></div><div class="klbl">NDPC Expiry</div><div class="ksub">days remaining</div></a>
    <?php endif; ?>
    <?php if($breachOpen>0): ?>
    <a class="kpi rd" href="breach.php"><div class="kval"><?=$breachOpen?></div><div class="klbl">Open Breaches</div><div class="ksub">NDPA log</div></a>
    <?php endif; ?>
    <?php if(!empty($subAlerts)): ?>
    <a class="kpi wn" href="subscriptions.php"><div class="kval"><?=count($subAlerts)?></div><div class="klbl">Sub Renewals</div><div class="ksub">due within 30 days</div></a>
    <?php endif; ?>
</div>

<!-- ── MAIN GRID ──────────────────────────────────────── -->
<div class="gmain">
<div class="cleft">

    <!-- IT Operations -->
    <div class="sec-hd">&#128295; IT Operations</div>
    <div class="card">
        <div class="chd">
            <div class="cht">&#128295; Open IT Tickets</div>
            <div style="display:flex;gap:10px;align-items:center;">
                <span style="font-size:11.5px;color:#64748b;">&#128308; <?=$itStats['open']??0?> open &bull; &#128992; <?=$itStats['inprog']??0?> in progress &bull; &#9989; <?=$itStats['closed']??0?> closed today</span>
                <a class="chl" href="admin/it-requests.php">All &#8594;</a>
            </div>
        </div>
        <?php if(empty($itList)): ?><div class="empty">&#10003; No open IT tickets</div>
        <?php else:
        $pc2=['urgent'=>'#dc2626','high'=>'#f59e0b','normal'=>'#0891b2','low'=>'#94a3b8'];
        foreach($itList as $t): $pst=['open'=>'op','in_progress'=>'ip','closed'=>'cl'][$t['status']]??'op'; ?>
        <div class="ritem">
            <div class="pdot" style="background:<?=$pc2[$t['priority']]??'#94a3b8'?>"></div>
            <div style="flex:1;"><div style="font-size:13px;font-weight:600;"><?=htmlspecialchars($t['issue_type']??$t['title']??'IT Request')?></div><div style="font-size:11.5px;color:#64748b;"><?=htmlspecialchars($t['rname'])?> &bull; <?=date('d M h:i A',strtotime($t['created_at']))?><?php if(!empty($t['description'])): ?> &bull; <?=htmlspecialchars(substr($t['description'],0,40))?><?php endif;?></div></div>
            <span class="pill <?=$pst?>"><?=ucfirst(str_replace('_',' ',$t['status']))?></span>
            <a href="admin/it-requests.php" class="itbtn">View</a>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Security -->
    <div class="sec-hd">&#128274; Security</div>
    <div class="card">
        <div class="chd"><div class="cht">&#128274; Failed Login Attempts (24h)</div><a class="chl" href="admin/audit.php">Full log &#8594;</a></div>
        <?php if(empty($recentFails)): ?><div class="empty">No failed logins in the last 24 hours &#10003;</div>
        <?php else: foreach($recentFails as $f): $mi=(int)round((time()-strtotime($f['created_at']))/60); ?>
        <div class="ritem">
            <div style="width:8px;height:8px;border-radius:50%;background:#dc2626;flex-shrink:0;"></div>
            <div style="flex:1;"><div style="font-size:13px;"><?=htmlspecialchars($f['email']??$f['name']??'Unknown')?></div><div style="font-size:11.5px;color:#94a3b8;">IP: <?=htmlspecialchars($f['ip_address']??'—')?></div></div>
            <div style="font-size:11px;color:#94a3b8;"><?=$mi<60?$mi.'m ago':date('h:i A',strtotime($f['created_at']))?></div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if(!empty($breachList)): ?>
    <div class="card">
        <div class="chd"><div class="cht" style="color:#dc2626;">&#128680; NDPC Data Breach Log</div><a class="chl" href="breach.php">View all &#8594;</a></div>
        <?php $sc2=['low'=>'#94a3b8','medium'=>'#f59e0b','high'=>'#dc2626','critical'=>'#7f1d1d'];
        foreach($breachList as $b): $sc=$sc2[$b['severity']]??'#94a3b8'; ?>
        <div class="ritem">
            <div style="width:8px;height:8px;border-radius:50%;background:<?=$sc?>;flex-shrink:0;"></div>
            <div style="flex:1;"><div style="font-size:13px;font-weight:600;"><?=htmlspecialchars(substr($b['description'],0,55))?></div><div style="font-size:11px;color:#94a3b8;"><?=ucfirst($b['severity'])?> &bull; <?=date('d M Y',strtotime($b['breach_date']))?></div></div>
            <span style="font-size:11px;padding:1px 7px;border-radius:99px;font-weight:600;background:<?=$b['status']==='open'?'#fee2e2':'#dcfce7'?>;color:<?=$b['status']==='open'?'#dc2626':'#166534'?>"><?=$b['status']?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Announcements -->
    <div class="sec-hd">&#128226; Announcements</div>
    <div class="card">
        <div class="chd"><div class="cht">&#128226; Company Announcements</div><a class="chl" href="admin/announcements.php">Manage &#8594;</a></div>
        <?php if(empty($announcements)): ?><div class="empty">No active announcements. <a href="admin/announcements.php" style="color:#64A014;font-weight:600;">Post one &#8594;</a></div>
        <?php else: foreach($announcements as $a): ?>
        <div class="annrow"><div class="antit"><?=htmlspecialchars($a['title'])?> <span style="font-size:10.5px;color:#94a3b8;">[<?=ucfirst($a['priority'])?>]</span></div><div class="anbod"><?=htmlspecialchars(substr(strip_tags($a['body']),0,130))?></div></div>
        <?php endforeach; endif; ?>
    </div>

</div><!-- /cleft -->

<div class="cright">

    <!-- Quick Actions -->
    <div class="card">
        <div class="chd"><div class="cht">&#9889; Quick Actions</div></div>
        <div class="qagl">
                <?php if (in_array($user['role'], ['md','bdm','head_accounts','head_it'], true)): ?>
                <a class="qa" href="audit-export.php"><div class="qi">&#128202;</div><div class="ql">Audit Export</div></a>
                <?php endif; ?>
            <button class="qa" onclick="if(typeof hriOpenCompose==='function')hriOpenCompose()"><div class="qi">&#9998;</div><div class="ql">Compose</div></button>
            <a class="qa" href="admin/broadcast.php"><div class="qi">&#128226;</div><div class="ql">Broadcast</div></a>
            <a class="qa" href="admin/users.php"><div class="qi">&#128101;</div><div class="ql">Manage Users</div></a>
            <a class="qa" href="admin/sessions.php"><div class="qi">&#128274;</div><div class="ql">Sessions</div></a>
            <a class="qa" href="admin/audit.php"><div class="qi">&#128214;</div><div class="ql">Audit Log</div></a>
            <a class="qa" href="admin/it-requests.php"><div class="qi">&#128421;&#65039;</div><div class="ql">IT Tickets</div></a>
            <a class="qa" href="admin/announcements.php"><div class="qi">&#128226;</div><div class="ql">Announce</div></a>
            <a class="qa" href="admin/roles.php"><div class="qi">&#128272;</div><div class="ql">Permissions</div></a>
            <a class="qa" href="compliance.php"><div class="qi">&#128203;</div><div class="ql">Compliance</div></a>
            <a class="qa" href="breach.php"><div class="qi">&#128680;</div><div class="ql">Breach Log</div></a>
            <a class="qa" href="signing.php"><div class="qi">&#9997;</div><div class="ql">Signing</div></a>
            <a class="qa" href="subscriptions.php"><div class="qi">&#128197;</div><div class="ql">Subscriptions</div></a>
            <a class="qa" href="admin/irs-settings.php"><div class="qi">&#9881;</div><div class="ql">IRS Settings</div></a>
            <a class="qa" href="irs-approvals.php"><div class="qi">&#128196;</div><div class="ql">IRS Queue</div></a>
        </div>
    </div>

    <!-- Staff Online -->
    <div class="card">
        <div class="chd"><div class="cht">&#128994; Staff Online Now</div><a class="chl" href="admin/sessions.php">Sessions &#8594;</a></div>
        <?php if(empty($onlineList)): ?><div class="empty">No staff online</div>
        <?php else: foreach($onlineList as $s): $rl=ROLES[$s['role']]['label']??$s['role']; $mi=(int)round((time()-strtotime($s['last_active']))/60); ?>
        <div class="ritem">
            <div class="odot"></div>
            <div style="flex:1;"><div style="font-size:12.5px;font-weight:600;"><?=htmlspecialchars($s['name'])?></div><div style="font-size:11px;color:#94a3b8;"><?=$rl?></div></div>
            <div style="font-size:11px;color:#94a3b8;"><?=$mi===0?'now':$mi.'m ago'?></div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- My Leave Balance -->
    <div class="card">
        <div class="chd"><div class="cht">&#127957; My Leave Balance <?=date('Y')?></div><a class="chl" href="leave.php">Request &#8594;</a></div>
        <div style="padding:13px 14px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:5px;align-items:baseline;">
                <span><strong style="font-size:22px;color:#002850;"><?=$remainLeave?></strong> <span style="font-size:12px;color:#64748b;">days remaining</span></span>
                <span style="font-size:12px;color:#94a3b8;"><?=$usedDays?>/<?=$entitled?> used</span>
            </div>
            <div class="lbar"><div class="lf <?=$remainLeave<=5?'dg':($remainLeave<=10?'wn':'')?>" style="width:<?=min(100,$entitled>0?round($usedDays/$entitled*100):0)?>%"></div></div>
            <?php if($pendDays>0): ?><div style="font-size:12px;color:#f59e0b;margin-top:4px;">&#8987; <?=$pendDays?> days pending approval</div><?php endif; ?>
        </div>
    </div>

    <!-- Compliance Expiring -->
    <?php if(!empty($expiryList) || !empty($subAlerts)): ?>
    <div class="card">
        <div class="chd"><div class="cht" style="color:#f59e0b;">&#9888; Expiring Soon</div></div>
        <?php foreach($expiryList as $c): $d=(int)$c['dl']; ?>
        <div class="ritem">
            <span style="font-size:14px;">&#128203;</span>
            <div style="flex:1;font-size:13px;"><?=htmlspecialchars($c['name'])?></div>
            <span class="dtag <?=$d<14?'rd':($d<30?'wn':'ok')?>"><?=$d?>d</span>
        </div>
        <?php endforeach; ?>
        <?php foreach($subAlerts as $sa): $sd=(int)$sa['dl']; ?>
        <div class="ritem">
            <span style="font-size:14px;">&#128197;</span>
            <div style="flex:1;font-size:13px;"><?=htmlspecialchars($sa['name'])?></div>
            <span class="dtag <?=$sd<7?'rd':($sd<14?'wn':'ok')?>"><?=$sd?>d</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /cright -->
</div><!-- /gmain -->
<?php require __DIR__ . '/_alerts.php';      // Band 4 — only renders when something is wrong ?>
<?php require __DIR__ . '/_irs_widget.php';  // Band 1 + my requests ?>
<?php require __DIR__ . '/_my_work.php';     // Band 2 — my tasks ?>
<?php require __DIR__ . '/_my_tickets.php';  // Band 2 — my IT tickets ?>
<?php require __DIR__ . '/_who_is_in.php';   // Band 3 — HR / Super Admin / Management only ?>
</div><!-- /hri-page -->
<script>
function tick(){var n=new Date(),h=n.getHours(),ap=h>=12?'PM':'AM',h12=h%12||12,el=document.getElementById('clk');if(el)el.textContent=String(h12).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0')+' '+ap;}
tick();setInterval(tick,1000);
(function(){function refreshKpis(){fetch('api/dashboard/kpi.php',{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}}).then(function(r){return r.json();}).then(function(d){if(!d.ok)return;var kpis=d.data.kpis;Object.keys(kpis).forEach(function(k){var el=document.querySelector('[data-kpi="'+k+'"]');if(el)el.textContent=kpis[k];});}).catch(function(){});}setInterval(refreshKpis,60000);})();
</script>
<?php Layout::end(); ?>
