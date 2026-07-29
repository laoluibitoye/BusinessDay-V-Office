<?php
if (!isset($user) || !is_array($user)) { header('Location: ../index.php'); exit; }
$db = getDB();
$mp = Auth::mailPass();
$role = ROLES[$user['role']];
$ini = strtoupper(substr($user['name'],0,1).(strpos($user['name'],' ')!==false?substr($user['name'],strpos($user['name'],' ')+1,1):''));
$firstName = explode(' ', $user['name'])[0];
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$openIT = 0; $itList = []; $itStats = [];
try {
    $openIT = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE status IN ('open','in_progress')")->fetchColumn();
    $itList = $db->query("SELECT ir.*,u.name as rname FROM it_requests ir JOIN users u ON u.id=ir.user_id WHERE ir.status IN ('open','in_progress') ORDER BY FIELD(ir.priority,'urgent','high','normal','low'),ir.created_at DESC LIMIT 8")->fetchAll();
    $itStats['open']    = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE status='open'")->fetchColumn();
    $itStats['inprog']  = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE status='in_progress'")->fetchColumn();
    $itStats['closed']  = (int)$db->query("SELECT COUNT(*) FROM it_requests WHERE DATE(created_at)=CURDATE() AND status='closed'")->fetchColumn();
} catch(Exception $e){}

$onlineStaff = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE expires_at>NOW() AND COALESCE(last_active,created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE)")->fetchColumn();
$onlineList = $db->query("SELECT u.name,u.role,u.email,COALESCE(s.last_active,s.created_at) as last_active,s.ip_address FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.expires_at>NOW() AND COALESCE(s.last_active,s.created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE) ORDER BY COALESCE(s.last_active,s.created_at) DESC LIMIT 10")->fetchAll();
$loginFails = (int)$db->query("SELECT COUNT(*) FROM login_history WHERE status='failed' AND DATE(created_at)=CURDATE()")->fetchColumn();
$recentFails = $db->query("SELECT lh.*,u.name FROM login_history lh LEFT JOIN users u ON u.id=lh.user_id WHERE lh.status='failed' AND lh.created_at>DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY lh.created_at DESC LIMIT 6")->fetchAll();
$auditList = $db->query("SELECT al.action,al.detail,al.created_at,al.ip_address,u.name FROM audit_log al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.created_at DESC LIMIT 10")->fetchAll();

$breachList = []; try{$breachList=$db->query("SELECT * FROM breach_log ORDER BY created_at DESC LIMIT 3")->fetchAll();}catch(Exception $e){}
$ndpcDays = null; try{$r=$db->query("SELECT DATEDIFF(expiry_date,CURDATE()) as dl FROM compliance_docs WHERE LOWER(name) LIKE '%ndpc%' LIMIT 1");$row=$r->fetch();if($row)$ndpcDays=(int)$row['dl'];}catch(Exception $e){}

$unread=0;set_time_limit(30);
if($mp){try{@imap_timeout(IMAP_OPENTIMEOUT,5);@imap_timeout(IMAP_READTIMEOUT,8);$mx=@imap_open('{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}INBOX',$user['email'],$mp,0,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);if($mx){$unread=count(@imap_search($mx,'UNSEEN')??[]);imap_close($mx);}}catch(Exception $e){}}

Layout::shell($user, 'dashboard', $unread, 'Dashboard');
?>
<style>
.hri-page{padding:20px;max-width:1200px;margin:0 auto;}
.wlcbar{background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%);border-radius:12px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;box-shadow:0 8px 32px rgba(12,74,110,.25);}
.wav{width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;font-weight:800;font-size:15px;display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.2);flex-shrink:0;}
.wname{font-size:17px;font-weight:700;color:#fff;margin-bottom:3px;}.wsub{font-size:12px;color:rgba(255,255,255,.65);}
.wclk{margin-left:auto;text-align:right;}.wtime{font-size:24px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums;}.wtz{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px;}
.krow{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:12px;margin-bottom:18px;}
.kpi{background:#fff;border-radius:12px;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid #e2e8f0;display:block;text-decoration:none;transition:all .2s;}.kpi:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);transform:translateY(-2px);}
.kpi.nv{border-top-color:#002850;}.kpi.gn{border-top-color:#64A014;}.kpi.rd{border-top-color:#dc2626;}.kpi.wn{border-top-color:#f59e0b;}.kpi.bl{border-top-color:#0891b2;}.kpi.pu{border-top-color:#8b5cf6;}
.kval{font-size:28px;font-weight:800;color:#002850;line-height:1;margin-bottom:4px;}.klbl{font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}.ksub{font-size:11.5px;color:#94a3b8;margin-top:3px;}
.gmain{display:grid;grid-template-columns:1fr 300px;gap:16px;}.cleft,.cright{display:flex;flex-direction:column;gap:16px;}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.chd{padding:11px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
.cht{font-size:13px;font-weight:700;color:#002850;}.chl{font-size:12.5px;color:#64A014;font-weight:600;text-decoration:none;}.chl:hover{text-decoration:underline;}
.empty{padding:26px;text-align:center;color:#94a3b8;font-size:13px;}
.ritem{display:flex;align-items:center;gap:9px;padding:9px 14px;border-bottom:1px solid #f1f5f9;}.ritem:last-child{border-bottom:none;}
.pill{padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;}.pill.op{background:#fee2e2;color:#dc2626;}.pill.ip{background:#fef3c7;color:#92400e;}.pill.cl{background:#dcfce7;color:#166534;}
.itbtn{padding:4px 10px;border-radius:6px;background:#0891b2;color:#fff;font-size:11px;font-weight:600;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
.odot{width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;}
.adot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.prio-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
.qagl{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;padding:12px;}
.qa{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 6px;border-radius:9px;background:#f1f5f9;border:1.5px solid transparent;cursor:pointer;transition:all .15s;text-decoration:none;font-family:inherit;}
.qa:hover{background:#e0f2fe;border-color:#0891b2;}.qi{font-size:20px;}.ql{font-size:11px;font-weight:600;color:#334155;text-align:center;line-height:1.3;}
@media(max-width:900px){.gmain{grid-template-columns:1fr;}}
</style>
<div class="hri-page">
<div class="wlcbar">
    <div class="wav"><?=$ini?></div>
    <div><div class="wname"><?=$greeting?>, <?=$firstName?> &#128737;</div><div class="wsub"><?=date('l, d F Y')?> &bull; <?=htmlspecialchars($role['label'])?> &bull; IT Operations</div></div>
    <div class="wclk"><div class="wtime" id="clk">--:--:--</div><div class="wtz">WAT (Lagos)</div></div>
</div>

<div class="krow">
    <a class="kpi <?=$openIT>0?'wn':'gn'?>" href="admin/it-requests.php"><div class="kval"><?=$openIT?></div><div class="klbl">Open IT Tickets</div></a>
    <a class="kpi <?=$itStats['inprog']>0?'bl':'nv'?>" href="admin/it-requests.php"><div class="kval"><?=$itStats['inprog']??0?></div><div class="klbl">In Progress</div></a>
    <a class="kpi gn" href="admin/it-requests.php"><div class="kval"><?=$itStats['closed']??0?></div><div class="klbl">Closed Today</div></a>
    <a class="kpi bl" href="admin/sessions.php"><div class="kval"><?=$onlineStaff?></div><div class="klbl">Staff Online</div></a>
    <a class="kpi <?=$loginFails>=3?'rd':'nv'?>" href="admin/audit.php"><div class="kval"><?=$loginFails?></div><div class="klbl">Failed Logins</div><div class="ksub">today</div></a>
    <a class="kpi <?=$unread>0?'gn':'nv'?>" href="mail.php"><div class="kval"><?=$unread?></div><div class="klbl">Unread Mail</div></a>
    <?php if($ndpcDays!==null): ?>
    <a class="kpi <?=$ndpcDays<30?'rd':($ndpcDays<60?'wn':'gn')?>" href="compliance.php"><div class="kval"><?=$ndpcDays?></div><div class="klbl">NDPC Expiry</div><div class="ksub">days remaining</div></a>
    <?php endif; ?>
</div>

<div class="gmain">
    <div class="cleft">
        <div class="card">
            <div class="chd"><div class="cht">&#128295; Open IT Tickets</div><a class="chl" href="admin/it-requests.php">All tickets &#8594;</a></div>
            <?php if(empty($itList)): ?><div class="empty">&#10003; No open IT tickets</div>
            <?php else: $pc=['urgent'=>'#dc2626','high'=>'#f59e0b','normal'=>'#0891b2','low'=>'#94a3b8'];
            foreach($itList as $t): $pst=['open'=>'op','in_progress'=>'ip','closed'=>'cl'][$t['status']]??'op'; ?>
            <div class="ritem">
                <div class="prio-dot" style="background:<?=$pc[$t['priority']]??'#94a3b8'?>"></div>
                <div style="flex:1;"><div style="font-size:13px;font-weight:600;"><?=htmlspecialchars(substr($t['title']??$t['subject']??'IT Request',0,45))?></div><div style="font-size:11.5px;color:#64748b;"><?=htmlspecialchars($t['rname'])?> &bull; <?=date('d M h:i A',strtotime($t['created_at']))?></div></div>
                <span class="pill <?=$pst?>"><?=ucfirst(str_replace('_',' ',$t['status']))?></span>
                <a href="admin/it-requests.php" class="itbtn">View</a>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <div class="chd"><div class="cht">&#128274; Security — Failed Logins (24h)</div><a class="chl" href="admin/audit.php">Full log &#8594;</a></div>
            <?php if(empty($recentFails)): ?><div class="empty">No failed logins in the last 24 hours &#10003;</div>
            <?php else: foreach($recentFails as $f): $mi=(int)round((time()-strtotime($f['created_at']))/60); ?>
            <div class="ritem">
                <div style="width:8px;height:8px;border-radius:50%;background:#dc2626;flex-shrink:0;"></div>
                <div style="flex:1;"><div style="font-size:13px;"><?=htmlspecialchars($f['email']??$f['name']??'Unknown')?></div><div style="font-size:11.5px;color:#94a3b8;">IP: <?=htmlspecialchars($f['ip_address']??'—')?></div></div>
                <div style="font-size:11px;color:#94a3b8;"><?=$mi<60?$mi.'m ago':date('h:i A',strtotime($f['created_at']))?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <div class="chd"><div class="cht">&#128214; Recent Audit Log</div><a class="chl" href="admin/audit.php">View all &#8594;</a></div>
            <?php $ac=['login'=>'#22c55e','logout'=>'#94a3b8','email_sent'=>'#3b82f6','email_deleted'=>'#dc2626','document_signed'=>'#f59e0b','vault_upload'=>'#64A014','it_request'=>'#8b5cf6'];
            foreach($auditList as $a): $col=$ac[$a['action']]??'#94a3b8'; $mi=(int)round((time()-strtotime($a['created_at']))/60); ?>
            <div class="ritem">
                <div class="adot" style="background:<?=$col?>"></div>
                <div style="flex:1;font-size:12.5px;"><strong><?=htmlspecialchars($a['name']??'System')?></strong> — <?=htmlspecialchars(str_replace('_',' ',$a['action']))?></div>
                <div style="font-size:11px;color:#94a3b8;"><?=$mi<60?$mi.'m ago':date('h:i A',strtotime($a['created_at']))?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="cright">
        <div class="card">
            <div class="chd"><div class="cht">&#9889; Quick Actions</div></div>
            <div class="qagl">
                <button class="qa" onclick="if(typeof hriOpenCompose==='function')hriOpenCompose()"><div class="qi">&#9998;</div><div class="ql">Compose</div></button>
                <a class="qa" href="admin/it-requests.php"><div class="qi">&#128295;</div><div class="ql">IT Tickets</div></a>
                <a class="qa" href="admin/sessions.php"><div class="qi">&#128994;</div><div class="ql">Sessions</div></a>
                <a class="qa" href="admin/audit.php"><div class="qi">&#128214;</div><div class="ql">Audit Log</div></a>
                <a class="qa" href="admin/users.php"><div class="qi">&#128737;</div><div class="ql">Manage Staff</div></a>
                <a class="qa" href="compliance.php"><div class="qi">&#128203;</div><div class="ql">Compliance</div></a>
                <a class="qa" href="breach.php"><div class="qi">&#128680;</div><div class="ql">Breach Log</div></a>
                <a class="qa" href="profile.php"><div class="qi">&#128100;</div><div class="ql">My Profile</div></a>
            </div>
        </div>

        <div class="card">
            <div class="chd"><div class="cht">&#128994; Staff Online Now</div><span style="font-size:12px;color:#94a3b8;"><?=$onlineStaff?> active</span></div>
            <?php if(empty($onlineList)): ?><div class="empty">No staff online</div>
            <?php else: foreach($onlineList as $s): $rl=ROLES[$s['role']]['label']??$s['role']; $mi=(int)round((time()-strtotime($s['last_active']))/60); ?>
            <div class="ritem">
                <div class="odot"></div>
                <div style="flex:1;"><div style="font-size:12.5px;font-weight:600;"><?=htmlspecialchars($s['name'])?></div><div style="font-size:11px;color:#94a3b8;"><?=$s['ip_address']??'—'?></div></div>
                <div style="font-size:11px;color:#94a3b8;"><?=$mi===0?'now':$mi.'m'?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <?php if(!empty($breachList)): ?>
        <div class="card">
            <div class="chd"><div class="cht" style="color:#dc2626;">&#128680; Breach Log</div><a class="chl" href="breach.php">View all &#8594;</a></div>
            <?php foreach($breachList as $b): $sc=['low'=>'#94a3b8','medium'=>'#f59e0b','high'=>'#dc2626','critical'=>'#7f1d1d'][$b['severity']]??'#94a3b8'; ?>
            <div class="ritem">
                <div style="width:8px;height:8px;border-radius:50%;background:<?=$sc?>;flex-shrink:0;"></div>
                <div style="flex:1;"><div style="font-size:12.5px;font-weight:600;"><?=htmlspecialchars(substr($b['description'],0,40))?></div><div style="font-size:11px;color:#94a3b8;"><?=ucfirst($b['severity'])?> &bull; <?=date('d M Y',strtotime($b['breach_date']))?></div></div>
                <span style="font-size:11px;padding:1px 6px;border-radius:99px;font-weight:600;background:<?=$b['status']==='open'?'#fee2e2':'#dcfce7'?>;color:<?=$b['status']==='open'?'#dc2626':'#166534'?>"><?=$b['status']?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<script>
function tick(){var n=new Date(),h=n.getHours(),ap=h>=12?'PM':'AM',h12=h%12||12,el=document.getElementById('clk');if(el)el.textContent=String(h12).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0')+' '+ap;}
tick();setInterval(tick,1000);
</script>
<?php Layout::end(); ?>
