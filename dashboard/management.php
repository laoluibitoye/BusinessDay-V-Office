<?php
if (!isset($user) || !is_array($user)) { header('Location: ../index.php'); exit; }
$db = getDB();
$mp = Auth::mailPass();
$ini = strtoupper(substr($user['name'],0,1).(strpos($user['name'],' ')!==false?substr($user['name'],strpos($user['name'],' ')+1,1):''));
$firstName = explode(' ', $user['name'])[0];
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

// ── CORE METRICS ─────────────────────────────────────────────────────────────
$totalStaff = $onlineStaff = $newStaffMth = $pendingLeave = 0;
$onLeaveToday = [];
try {
    $totalStaff   = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
    $onlineStaff  = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE expires_at>NOW() AND COALESCE(last_active,created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE)")->fetchColumn();
    $newStaffMth  = (int)$db->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
    $pendingLeave = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage IN ('lm_review','hr_review','md_review')")->fetchColumn();
    $onLeaveToday = $db->query("SELECT u.name,lr.leave_type FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE lr.current_stage='approved' AND CURDATE() BETWEEN lr.start_date AND lr.end_date ORDER BY u.name")->fetchAll();
} catch(Exception $e) {}

// ── TASKS ────────────────────────────────────────────────────────────────────
$taskStats = ['total'=>0,'todo'=>0,'in_progress'=>0,'done'=>0,'overdue'=>0];
try {
    foreach ($db->query("SELECT status,COUNT(*) c FROM tasks GROUP BY status")->fetchAll() as $t) {
        $taskStats['total'] += $t['c'];
        if (isset($taskStats[$t['status']])) $taskStats[$t['status']] = (int)$t['c'];
    }
    $taskStats['overdue'] = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status!='done' AND due_date IS NOT NULL AND due_date<CURDATE()")->fetchColumn();
} catch(Exception $e){}

// ── PER-EMPLOYEE TASK PERFORMANCE ────────────────────────────────────────────
$empTaskPerf = [];
try {
    $empTaskPerf = $db->query("SELECT u.id, u.name, u.role,
        SUM(CASE WHEN t.status IN ('pending','in_progress') THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) as done_count,
        SUM(CASE WHEN t.status!='done' AND t.due_date IS NOT NULL AND t.due_date<CURDATE() THEN 1 ELSE 0 END) as overdue_count,
        COUNT(t.id) as total_count,
        ROUND(AVG(CASE WHEN t.status!='done' THEN t.progress ELSE 100 END)) as avg_progress
        FROM users u INNER JOIN tasks t ON t.user_id=u.id
        WHERE u.is_active=1
        GROUP BY u.id, u.name, u.role
        HAVING total_count > 0
        ORDER BY overdue_count DESC, active_count DESC
        LIMIT 20")->fetchAll();
} catch(Exception $e){}

// ── DOCUMENT SIGNING ─────────────────────────────────────────────────────────
$pendingSigning = 0; $signingList = [];
try {
    $pendingSigning = (int)$db->query("SELECT COUNT(DISTINCT request_id) FROM sign_signatories WHERE status='pending'")->fetchColumn();
    $signingList = $db->query("SELECT sr.id,sr.title,sr.created_at,u.name as creator,
        (SELECT COUNT(*) FROM sign_signatories WHERE request_id=sr.id) as total_sigs,
        (SELECT COUNT(*) FROM sign_signatories WHERE request_id=sr.id AND status='signed') as signed_count
        FROM sign_requests sr JOIN users u ON u.id=sr.created_by
        ORDER BY sr.created_at DESC LIMIT 8")->fetchAll();
} catch(Exception $e){}

// ── COMPLIANCE EXPIRING ────────────────────────────────────────────────────
$compExpiring = 0; $compExpiryList = [];
try {
    $compExpiring = (int)$db->query("SELECT COUNT(*) FROM compliance_docs WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
    $compExpiryList = $db->query("SELECT name,expiry_date,category,DATEDIFF(expiry_date,CURDATE()) dl FROM compliance_docs WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) ORDER BY expiry_date LIMIT 8")->fetchAll();
} catch(Exception $e){}

// ── SLAs EXPIRING ─────────────────────────────────────────────────────────
$slaExpiring = 0; $slaExpiryList = [];
try {
    $slaExpiring = (int)$db->query("SELECT COUNT(*) FROM sla_tracker WHERE status='active' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
    $slaExpiryList = $db->query("SELECT name,client,expiry_date,DATEDIFF(expiry_date,CURDATE()) dl FROM sla_tracker WHERE status='active' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) ORDER BY expiry_date LIMIT 8")->fetchAll();
} catch(Exception $e){}

// ── SUBSCRIPTIONS EXPIRING ────────────────────────────────────────────────
$subExpiring = 0; $subExpiryList = []; $subsReady = false;
try {
    $subExpiring = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND renewal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
    $subExpiryList = $db->query("SELECT name,vendor,renewal_date,cost,currency,auto_renews,DATEDIFF(renewal_date,CURDATE()) dl FROM subscriptions WHERE status='active' AND renewal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) ORDER BY renewal_date LIMIT 8")->fetchAll();
    $subsReady = true;
} catch(Exception $e){}

// ── LEAVE ANALYTICS ───────────────────────────────────────────────────────────
$leaveThisMonth = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$leaveApproved  = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage='approved' AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$leaveByType = [];
try { $leaveByType = $db->query("SELECT leave_type,COUNT(*) c FROM leave_requests WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) GROUP BY leave_type ORDER BY c DESC")->fetchAll(); } catch(Exception $e){}
$leaveTypeMax = !empty($leaveByType) ? max(array_column($leaveByType,'c')) : 1;

// ── LEAVE BALANCE OVERVIEW ────────────────────────────────────────────────
$leaveBalances = [];
try {
    $leaveBalances = $db->query("SELECT u.name, u.role, lb.entitled_days, lb.used_days, lb.pending_days,
        (lb.entitled_days - lb.used_days - lb.pending_days) as remaining
        FROM leave_balance lb JOIN users u ON u.id=lb.user_id
        WHERE lb.year=YEAR(NOW()) AND u.is_active=1
        ORDER BY remaining ASC LIMIT 15")->fetchAll();
} catch(Exception $e){}

// ── BREACH LOG ────────────────────────────────────────────────────────────────
$openBreaches = 0;
try { $openBreaches = (int)$db->query("SELECT COUNT(*) FROM breach_log WHERE status IN ('open','contained')")->fetchColumn(); } catch(Exception $e){}

// ── VISITORS ─────────────────────────────────────────────────────────────
$visitorsToday = 0;
try { $visitorsToday = (int)$db->query("SELECT COUNT(*) FROM visitors WHERE DATE(visit_date)=CURDATE()")->fetchColumn(); } catch(Exception $e){}

// ── STAFF BY DEPARTMENT ───────────────────────────────────────────────────────
$deptCounts = [];
try {
    $deptCounts = $db->query("SELECT COALESCE(NULLIF(department,''), CASE WHEN role IN ('md','bdm') THEN 'Executive' ELSE 'Unassigned' END) as dept,COUNT(*) c FROM users WHERE is_active=1 GROUP BY dept ORDER BY c DESC LIMIT 8")->fetchAll();
} catch(Exception $e){}

// ── ANNOUNCEMENTS — reused from Layout::shell() cache (no second query) ──────
$announcements = [];

// ── ONLINE LIST & PENDING LEAVE LIST ─────────────────────────────────────────
$onlineList = $db->query("SELECT u.name,u.role,MAX(COALESCE(s.last_active,s.created_at)) as last_active FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.expires_at>NOW() AND COALESCE(s.last_active,s.created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE) GROUP BY u.id,u.name,u.role ORDER BY last_active DESC LIMIT 10")->fetchAll();
$pendingLeaveList = $db->query("SELECT lr.*,u.name as rname FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE lr.current_stage IN ('lm_review','hr_review','md_review') ORDER BY lr.created_at DESC LIMIT 10")->fetchAll();

// ── UNREAD MAIL ───────────────────────────────────────────────────────────────
$unread = 0; set_time_limit(30);
if ($mp) { try { @imap_timeout(IMAP_OPENTIMEOUT,5);@imap_timeout(IMAP_READTIMEOUT,8);$mx=@imap_open('{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}INBOX',$user['email'],$mp,0,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);if($mx){$unread=count(@imap_search($mx,'UNSEEN')??[]);imap_close($mx);}} catch(Exception $e){}}

// ── EMAIL QUEUE ───────────────────────────────────────────────────────────────
$scheduledMail = 0;
try { $scheduledMail = (int)$db->query("SELECT COUNT(*) FROM email_queue WHERE sent=0 AND send_at>NOW()")->fetchColumn(); } catch(Exception $e){}

// ── TODAY'S ROSTER ────────────────────────────────────────────────────────────
$todayDow   = (int)date('N');
$todayName  = date('l');
$onLeaveIds = [];
$onlineIds  = [];
foreach ($db->query("SELECT user_id FROM leave_requests WHERE current_stage='approved' AND CURDATE() BETWEEN start_date AND end_date")->fetchAll() as $r)
    $onLeaveIds[] = (int)$r['user_id'];
foreach ($db->query("SELECT DISTINCT user_id FROM sessions WHERE expires_at>NOW() AND COALESCE(last_active,created_at)>DATE_SUB(NOW(),INTERVAL 30 MINUTE)")->fetchAll() as $r)
    $onlineIds[] = (int)$r['user_id'];
$scheduleReady = false;
try { $db->query("SELECT 1 FROM staff_schedules LIMIT 1"); $scheduleReady = true; } catch(Exception $e){}
if ($scheduleReady) {
    $rosterStaff = $db->query("SELECT u.id,u.name,u.role,u.department,u.avatar_color,ss.working_days FROM users u LEFT JOIN staff_schedules ss ON ss.user_id=u.id WHERE u.is_active=1 ORDER BY u.name")->fetchAll();
} else {
    $rosterStaff = $db->query("SELECT u.id,u.name,u.role,u.department,u.avatar_color,NULL as working_days FROM users u WHERE u.is_active=1 ORDER BY u.name")->fetchAll();
}
$rosterGroups = ['online'=>[],'working'=>[],'leave'=>[],'off'=>[]];
foreach ($rosterStaff as $s) {
    $id = (int)$s['id'];
    if (in_array($id, $onLeaveIds)) { $rosterGroups['leave'][] = $s; continue; }
    if (!empty($s['working_days'])) {
        $workDays  = array_map('intval', explode(',', $s['working_days']));
        $isWorkDay = in_array($todayDow, $workDays);
    } else {
        $isWorkDay = ($todayDow <= 5);
    }
    if (!$isWorkDay)               { $rosterGroups['off'][]    = $s; continue; }
    if (in_array($id, $onlineIds)) { $rosterGroups['online'][] = $s; continue; }
    $rosterGroups['working'][] = $s;
}
$rosterAvColors = ['#002850','#64A014','#8b5cf6','#f97316','#3b82f6','#f59e0b','#0891b2','#db2777'];

// ── WEEKLY ROSTER DATA ────────────────────────────────────────────────────────
$weekMonday = date('Y-m-d', strtotime('monday this week'));
if ((int)date('N') === 7) $weekMonday = date('Y-m-d', strtotime('last monday'));
$weekDates = [];
for ($i = 0; $i < 7; $i++) $weekDates[] = date('Y-m-d', strtotime($weekMonday." +{$i} days"));
$weekSunday  = $weekDates[6];
$nextMonday  = date('Y-m-d', strtotime($weekSunday.' +1 day'));
$nextWeekDates = [];
for ($i = 0; $i < 7; $i++) $nextWeekDates[] = date('Y-m-d', strtotime($nextMonday." +{$i} days"));
$nextSunday = $nextWeekDates[6];
$weekLeaveMap = [];
$wlq = $db->query("SELECT user_id,start_date,end_date FROM leave_requests WHERE current_stage='approved' AND end_date>='$weekMonday' AND start_date<='$nextSunday'");
foreach ($wlq->fetchAll() as $wl) {
    $s2 = strtotime($wl['start_date']); $e2 = strtotime($wl['end_date']);
    for ($d2 = $s2; $d2 <= $e2; $d2 += 86400) $weekLeaveMap[(int)$wl['user_id']][date('Y-m-d',$d2)] = true;
}
function mgRosterDayStatus($staff, $date, $onlineIds, $weekLeaveMap) {
    $id = (int)$staff['id'];
    if (isset($weekLeaveMap[$id][$date])) return 'leave';
    $dow = (int)date('N', strtotime($date));
    if (!empty($staff['working_days'])) {
        $wd = array_map('intval', explode(',', $staff['working_days']));
        $isWork = in_array($dow, $wd);
    } else {
        $isWork = ($dow <= 5);
    }
    if (!$isWork) return 'off';
    if ($date === date('Y-m-d') && in_array($id, $onlineIds)) return 'online';
    return 'working';
}

Layout::shell($user, 'dashboard', $unread, 'Dashboard');
$announcements = Layout::getAnnouncements();
?>
<style>
.hri-page{padding:20px 24px;max-width:1600px;margin:0 auto;width:100%;}

/* Welcome bar */
.wlcbar{background:linear-gradient(135deg,#002850 0%,#003a72 60%,#0a4a8c 100%);border-radius:14px;padding:18px 24px;margin-bottom:16px;display:flex;align-items:center;gap:14px;box-shadow:0 8px 32px rgba(0,40,80,.18);}
.wav{width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;font-weight:800;font-size:16px;display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.25);flex-shrink:0;}
.wname{font-size:17px;font-weight:700;color:#fff;margin-bottom:2px;}
.wsub{font-size:12px;color:rgba(255,255,255,.6);}
.wclk{margin-left:auto;text-align:right;}
.wtime{font-size:26px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums;line-height:1;}
.wtz{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px;}

/* KPI Strip */
.krow{display:grid;grid-template-columns:repeat(auto-fit,minmax(128px,1fr));gap:8px;margin-bottom:14px;}
.kpi{background:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 1px 3px rgba(0,0,0,.07);border-top:3px solid #e2e8f0;display:block;text-decoration:none;transition:all .18s;}
.kpi:hover{box-shadow:0 4px 18px rgba(0,0,0,.1);transform:translateY(-2px);}
.kpi.nv{border-top-color:#002850;} .kpi.gn{border-top-color:#64A014;} .kpi.rd{border-top-color:#dc2626;} .kpi.wn{border-top-color:#f59e0b;} .kpi.pu{border-top-color:#8b5cf6;} .kpi.cy{border-top-color:#0891b2;}
.kval{font-size:24px;font-weight:800;color:#002850;line-height:1;margin-bottom:3px;}
.klbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;}
.ksub{font-size:10.5px;color:#94a3b8;margin-top:2px;}

/* Expiry alert banner */
.exp-banner{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;}
.exp-alert{flex:1;min-width:200px;padding:12px 16px;border-radius:10px;display:flex;align-items:center;gap:10px;text-decoration:none;cursor:pointer;}
.exp-alert.rd{background:#fee2e2;border:1px solid #fca5a5;}
.exp-alert.wn{background:#fef3c7;border:1px solid #fcd34d;}
.exp-alert.cy{background:#e0f2fe;border:1px solid #7dd3fc;}
.exp-ico{font-size:20px;flex-shrink:0;}
.exp-txt{flex:1;}
.exp-lbl{font-size:12.5px;font-weight:700;color:#0f172a;}
.exp-sub{font-size:11px;color:#475569;margin-top:1px;}
.exp-count{font-size:22px;font-weight:800;color:#0f172a;}

/* Main layout */
.dmain{display:grid;grid-template-columns:1fr 1fr 270px;gap:14px;margin-bottom:14px;}
.dcol{display:flex;flex-direction:column;gap:14px;}

/* Cards */
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.07);overflow:hidden;}
.chd{padding:11px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
.cht{font-size:13px;font-weight:700;color:#002850;}
.chl{font-size:12px;color:#64A014;font-weight:600;text-decoration:none;}
.chl:hover{text-decoration:underline;}
.empty{padding:24px;text-align:center;color:#94a3b8;font-size:13px;}
.ritem{display:flex;align-items:center;gap:9px;padding:9px 14px;border-bottom:1px solid #f1f5f9;}
.ritem:last-child{border-bottom:none;}

/* Task stat row */
.task-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;padding:12px;}
.tstat{border-radius:10px;padding:12px 8px;text-align:center;}
.tstat.todo{background:#f1f5f9;} .tstat.prog{background:#dbeafe;} .tstat.done{background:#dcfce7;} .tstat.over{background:#fee2e2;}
.tstat-val{font-size:22px;font-weight:800;color:#0f172a;line-height:1;}
.tstat-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-top:3px;}
.tstat.todo .tstat-lbl{color:#64748b;} .tstat.prog .tstat-lbl{color:#1e40af;} .tstat.done .tstat-lbl{color:#166534;} .tstat.over .tstat-lbl{color:#dc2626;}

/* Employee performance table */
.perf-tbl{width:100%;border-collapse:collapse;}
.perf-tbl th{background:#f8fafc;padding:8px 12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;text-align:left;border-bottom:1px solid #f1f5f9;white-space:nowrap;}
.perf-tbl td{padding:8px 12px;border-bottom:1px solid #f8fafc;font-size:12.5px;vertical-align:middle;}
.perf-tbl tr:last-child td{border-bottom:none;}
.perf-tbl tr:hover td{background:#f8fafc;}
.prog-mini{height:5px;background:#f1f5f9;border-radius:99px;overflow:hidden;min-width:60px;}
.prog-mini-fill{height:100%;border-radius:99px;}

/* Leave bars */
.ltype-row{padding:8px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;}
.ltype-row:last-child{border-bottom:none;}
.ltype-bar-bg{flex:1;height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;}
.ltype-bar{height:100%;background:#64A014;border-radius:99px;}
.ltype-val{font-size:12px;font-weight:700;color:#0f172a;min-width:18px;text-align:right;}

/* Leave balance */
.lbal-row{padding:7px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;}
.lbal-row:last-child{border-bottom:none;}
.lbal-bar-bg{flex:1;height:5px;background:#f1f5f9;border-radius:99px;overflow:hidden;}
.lbal-bar{height:100%;border-radius:99px;}

/* Dept bar */
.dept-row{padding:7px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;}
.dept-row:last-child{border-bottom:none;}
.dept-bar-bg{flex:1;height:5px;background:#f1f5f9;border-radius:99px;overflow:hidden;}
.dept-bar{height:100%;background:#002850;border-radius:99px;}

/* Expiry 3-col grid */
.exp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;}
.exp-item{padding:9px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;}
.exp-item:last-child{border-bottom:none;}
.dtag{padding:2px 7px;border-radius:99px;font-size:11px;font-weight:700;}
.dtag.rd{background:#fee2e2;color:#dc2626;} .dtag.wn{background:#fef3c7;color:#92400e;} .dtag.ok{background:#dcfce7;color:#166534;}

/* Signing */
.sign-row{display:flex;align-items:center;gap:9px;padding:9px 14px;border-bottom:1px solid #f1f5f9;}
.sign-row:last-child{border-bottom:none;}
.sign-prog{font-size:11px;color:#64748b;}

/* Quick actions */
.qagl{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:10px;}
.qa{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 4px;border-radius:8px;background:#f8fafc;border:1.5px solid transparent;cursor:pointer;transition:all .15s;text-decoration:none;font-family:inherit;}
.qa:hover{background:#e8f0fb;border-color:#002850;}
.qi{font-size:18px;} .ql{font-size:10px;font-weight:600;color:#334155;text-align:center;line-height:1.3;}

/* Pills */
.pill{padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;}
.pill.sub{background:#dbeafe;color:#1e40af;} .pill.lm{background:#fef3c7;color:#92400e;}
.pill.hr{background:#e0e7ff;color:#4338ca;} .pill.app{background:#dcfce7;color:#166534;}
.lapp{padding:4px 9px;border-radius:6px;background:#64A014;color:#fff;font-size:11px;font-weight:600;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}

/* Bottom 2-col */
.d2col{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}

/* Staff online */
.odot{width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;}

/* Announcements */
.annrow{padding:10px 14px;border-bottom:1px solid #f8fafc;}
.annrow:last-child{border-bottom:none;}
.antit{font-size:13px;font-weight:600;color:#0f172a;}
.anbod{font-size:12px;color:#64748b;margin-top:2px;line-height:1.5;}
.pri-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;vertical-align:middle;}

/* Roster */
.roster-card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.07);overflow:hidden;margin-bottom:14px;}
.roster-tabs{display:flex;gap:0;border-bottom:1px solid #f1f5f9;padding:0 14px;overflow-x:auto;scrollbar-width:none;}
.rtab{padding:8px 14px;font-size:12px;font-weight:700;color:#94a3b8;cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;background:none;border-top:none;border-left:none;border-right:none;font-family:inherit;white-space:nowrap;}
.rtab.active{color:#002850;border-bottom-color:#002850;}
.rtab .rbadge{display:inline-block;padding:1px 6px;border-radius:99px;font-size:10px;font-weight:700;margin-left:4px;vertical-align:middle;}
.rtab.t-online .rbadge{background:#dcfce7;color:#166534;}
.rtab.t-working .rbadge{background:#e8f0fb;color:#002850;}
.rtab.t-leave .rbadge{background:#fef3c7;color:#92400e;}
.rtab.t-off .rbadge{background:#f1f5f9;color:#64748b;}
.roster-pane{display:none;padding:14px;}
.roster-pane.active{display:block;}
.roster-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;}
.rstaff{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;border:1.5px solid #f1f5f9;transition:background .12s;}
.rstaff:hover{background:#f8fafc;}
.rav{width:36px;height:36px;border-radius:50%;color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;}
.rst-dot{width:10px;height:10px;border-radius:50%;border:2px solid #fff;position:absolute;bottom:0;right:0;}
.rst-dot.online{background:#22c55e;} .rst-dot.working{background:#3b82f6;} .rst-dot.leave{background:#f59e0b;} .rst-dot.off{background:#cbd5e1;}
.rname{font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rrole{font-size:11px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.roster-empty{padding:24px;text-align:center;color:#94a3b8;font-size:13px;}
.roster-legend{display:flex;gap:16px;padding:10px 14px 14px;flex-wrap:wrap;}
.rleg{display:flex;align-items:center;gap:5px;font-size:11.5px;color:#64748b;}
.rleg-dot{width:9px;height:9px;border-radius:50%;}
.rtab.t-week .rbadge{background:#f0f4ff;color:#3b82f6;}
.week-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.week-table{width:100%;border-collapse:collapse;font-size:12px;min-width:640px;}
.week-table th{padding:7px 8px;text-align:center;font-weight:700;font-size:11px;color:#64748b;background:#f8fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap;}
.week-table th.wt-staff{text-align:left;min-width:160px;position:sticky;left:0;z-index:3;background:#f8fafc;border-right:2px solid #e2e8f0;}
.week-table th.wt-today{background:#eff6ff;color:#1d4ed8;}
.week-table th.wt-wknd{background:#fafafa;color:#94a3b8;}
.week-table td{padding:5px 7px;text-align:center;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.week-table td.wt-staff{text-align:left;position:sticky;left:0;background:#fff;z-index:2;border-right:2px solid #f1f5f9;}
.week-table td.wt-today{background:#f0f6ff;}
.week-table td.wt-wknd{background:#fafafa;}
.week-table tr:hover td{background:#f8fafc;}
.week-table tr:hover td.wt-today{background:#e8f2ff;}
.week-table tr:hover td.wt-staff{background:#f8fafc;}
.wday-cell{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10.5px;font-weight:600;white-space:nowrap;}
.wdc-online{background:#dcfce7;color:#166534;}
.wdc-working{background:#dbeafe;color:#1e40af;}
.wdc-leave{background:#fef3c7;color:#92400e;}
.wdc-off{color:#cbd5e1;font-size:14px;line-height:1;}
.wdc-past{opacity:.5;}
.wk-staff-cell{display:flex;align-items:center;gap:8px;padding:4px 8px;}
.wk-av{width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;flex-shrink:0;}
.wk-staff-name{font-size:12.5px;font-weight:600;color:#0f172a;}
.wk-staff-role{font-size:10.5px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;}
.wk-hdr-date{display:block;font-size:10px;font-weight:400;color:#94a3b8;margin-top:1px;}
.wk-hdr-today-date{display:block;font-size:10px;font-weight:600;color:#3b82f6;margin-top:1px;}
@media(max-width:1200px){.dmain{grid-template-columns:1fr 1fr;}.dcol:last-child{grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));}.exp-grid{grid-template-columns:1fr 1fr;}.d2col{grid-template-columns:1fr;}}
@media(max-width:768px){.dmain{grid-template-columns:1fr;}.krow{grid-template-columns:repeat(3,1fr);}.exp-grid{grid-template-columns:1fr;}.exp-banner{flex-direction:column;}.task-row{grid-template-columns:repeat(2,1fr);}.roster-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:420px){.krow{grid-template-columns:repeat(2,1fr);}}
/* mobile-wrapper-fix */
@media(max-width:768px){
    .hri-page{padding:14px 12px;}
}
</style>

<div class="hri-page">

<!-- ── WELCOME BAR ── -->
<div class="wlcbar">
    <div class="wav"><?=$ini?></div>
    <div>
        <div class="wname"><?=$greeting?>, <?=$firstName?> &#128081;</div>
        <div class="wsub"><?=date('l, d F Y')?> &bull; HR Indexx Limited &bull; <?=$totalStaff?> staff<?php if($newStaffMth>0): ?> &bull; <span style="color:#86efac;">+<?=$newStaffMth?> this month</span><?php endif; ?></div>
    </div>
    <div class="wclk">
        <div class="wtime" id="clk">--:--:--</div>
        <div class="wtz">WAT (Lagos)</div>
    </div>
</div>

<!-- ── KPI STRIP ── -->
<div class="krow">
    <a class="kpi nv" href="admin/users.php"><div class="kval"><?=$totalStaff?></div><div class="klbl">Total Staff</div><div class="ksub"><?=$newStaffMth>0?'+'.$newStaffMth.' this month':'active employees'?></div></a>
    <a class="kpi <?=$onlineStaff>0?'gn':'nv'?>" href="admin/sessions.php"><div class="kval" data-kpi="staff_online"><?=$onlineStaff?></div><div class="klbl">Online Now</div><div class="ksub">active last 15 min</div></a>
    <a class="kpi <?=$pendingLeave>0?'pu':'nv'?>" href="leave-approvals.php"><div class="kval" data-kpi="leave_pending"><?=$pendingLeave?></div><div class="klbl">Pending Leave</div><div class="ksub"><?=count($onLeaveToday)?> on leave today</div></a>
    <a class="kpi <?=$taskStats['overdue']>0?'rd':($taskStats['total']>0?'gn':'nv')?>" href="tasks.php"><div class="kval"><?=$taskStats['total']?></div><div class="klbl">Tasks Total</div><div class="ksub"><?=$taskStats['overdue']>0?$taskStats['overdue'].' overdue':$taskStats['in_progress'].' in progress'?></div></a>
    <a class="kpi <?=$pendingSigning>0?'wn':'nv'?>" href="signing.php"><div class="kval"><?=$pendingSigning?></div><div class="klbl">Pending Sigs</div><div class="ksub">awaiting signature</div></a>
    <a class="kpi <?=$subExpiring>0?'rd':'nv'?>" href="compliance.php"><div class="kval"><?=$subExpiring?></div><div class="klbl">Subs Expiring</div><div class="ksub">within 30 days</div></a>
    <a class="kpi <?=$slaExpiring>0?'wn':'nv'?>" href="compliance.php"><div class="kval"><?=$slaExpiring?></div><div class="klbl">SLAs Expiring</div><div class="ksub">within 30 days</div></a>
    <a class="kpi <?=$compExpiring>0?'wn':'nv'?>" href="compliance.php"><div class="kval"><?=$compExpiring?></div><div class="klbl">Docs Expiring</div><div class="ksub">compliance docs</div></a>
    <a class="kpi <?=$openBreaches>0?'rd':'nv'?>" href="breach.php"><div class="kval"><?=$openBreaches?></div><div class="klbl">Open Breaches</div><div class="ksub">NDPA 2023</div></a>
    <a class="kpi <?=$unread>0?'cy':'nv'?>" href="mail.php"><div class="kval"><?=$unread?></div><div class="klbl">Unread Mail</div><div class="ksub"><?=$scheduledMail?> scheduled</div></a>
</div>

<!-- ── EXPIRY ALERT BANNER (only shows when items are expiring) ── -->
<?php
$totalExpiring = $subExpiring + $slaExpiring + $compExpiring;
if ($totalExpiring > 0):
?>
<div class="exp-banner">
    <?php if($subExpiring > 0): ?>
    <a class="exp-alert rd" href="compliance.php">
        <div class="exp-ico">&#128230;</div>
        <div class="exp-txt">
            <div class="exp-lbl">Subscriptions Expiring</div>
            <div class="exp-sub"><?=$subExpiring?> renewal<?=$subExpiring!=1?'s':''?> due within 30 days &mdash; action required</div>
        </div>
        <div class="exp-count"><?=$subExpiring?></div>
    </a>
    <?php endif; ?>
    <?php if($slaExpiring > 0): ?>
    <a class="exp-alert wn" href="compliance.php">
        <div class="exp-ico">&#128202;</div>
        <div class="exp-txt">
            <div class="exp-lbl">SLAs Expiring</div>
            <div class="exp-sub"><?=$slaExpiring?> SLA<?=$slaExpiring!=1?'s':''?> expiring within 30 days</div>
        </div>
        <div class="exp-count"><?=$slaExpiring?></div>
    </a>
    <?php endif; ?>
    <?php if($compExpiring > 0): ?>
    <a class="exp-alert cy" href="compliance.php">
        <div class="exp-ico">&#128203;</div>
        <div class="exp-txt">
            <div class="exp-lbl">Compliance Docs Expiring</div>
            <div class="exp-sub"><?=$compExpiring?> document<?=$compExpiring!=1?'s':''?> expiring within 30 days</div>
        </div>
        <div class="exp-count"><?=$compExpiring?></div>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_alerts.php';      // Band 4 — only renders when something is wrong ?>
<?php require __DIR__ . '/_irs_widget.php';  // Band 1 + my requests ?>
<?php require __DIR__ . '/_my_work.php';     // Band 2 — my tasks ?>
<?php require __DIR__ . '/_my_tickets.php';  // Band 2 — my IT tickets ?>

<!-- ── MAIN GRID ── -->
<div class="dmain">

    <!-- ── COL 1: Leave ── -->
    <div class="dcol">

        <!-- PENDING LEAVE APPROVALS -->
        <div class="card">
            <div class="chd">
                <div class="cht">&#127958; Pending Leave <?php if($pendingLeave>0): ?><span style="background:#fee2e2;color:#dc2626;font-size:10px;padding:1px 6px;border-radius:99px;margin-left:6px;font-weight:700;"><?=$pendingLeave?></span><?php endif; ?></div>
                <a class="chl" href="leave-approvals.php">Review all &#8594;</a>
            </div>
            <?php if(empty($pendingLeaveList)): ?><div class="empty">No pending leave requests &#10003;</div>
            <?php else: foreach($pendingLeaveList as $lr):
                $stage=$lr['current_stage'];
                $pc=['submitted'=>'sub','lm_review'=>'lm','hr_review'=>'hr','md_review'=>'hr'][$stage]??'sub'; ?>
            <div class="ritem" id="lr-row-<?=$lr['id']?>">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;"><?=htmlspecialchars($lr['rname'])?></div>
                    <div style="font-size:11.5px;color:#64748b;"><?=ucfirst(str_replace('_',' ',$lr['leave_type']))?> &bull; <?=date('d M',strtotime($lr['start_date']))?>&ndash;<?=date('d M',strtotime($lr['end_date']))?> (<?=$lr['days']?>d)</div>
                </div>
                <span class="pill <?=$pc?>" style="white-space:nowrap;"><?=str_replace('_',' ',ucwords($stage,'_'))?></span>
                <button onclick="openLeaveDrawer(<?=$lr['id']?>)" class="lapp">Review</button>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- LEAVE BY TYPE THIS MONTH -->

        <!-- LEAVE BALANCE PER EMPLOYEE -->
        <?php if(!empty($leaveBalances)): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#9889; Leave Balance <?=date('Y')?></div><span style="font-size:11.5px;color:#94a3b8;">lowest remaining first</span></div>
            <?php foreach($leaveBalances as $lb):
                $usedPct = $lb['entitled_days']>0 ? round(($lb['used_days']/$lb['entitled_days'])*100) : 0;
                $barColor = $usedPct>=90?'#dc2626':($usedPct>=70?'#f59e0b':'#64A014'); ?>
            <div class="lbal-row">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">
                        <span style="font-size:12.5px;font-weight:600;color:#0f172a;"><?=htmlspecialchars($lb['name'])?></span>
                        <span style="font-size:11px;color:#64748b;"><?=$lb['used_days']?>/<?=$lb['entitled_days']?> used</span>
                    </div>
                    <div class="lbal-bar-bg"><div class="lbal-bar" style="width:<?=$usedPct?>%;background:<?=$barColor?>;"></div></div>
                </div>
                <div style="font-size:12px;font-weight:700;color:<?=$lb['remaining']<=3?'#dc2626':($lb['remaining']<=7?'#f59e0b':'#166534')?>;min-width:34px;text-align:right;"><?=$lb['remaining']?>d</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- ── COL 2: Tasks ── -->
    <div class="dcol">

        <!-- TASK STATS -->

        <!-- EMPLOYEE TASK PERFORMANCE -->

        <!-- DOCUMENT SIGNING -->
        <div class="card">
            <div class="chd">
                <div class="cht">&#9997; Document Signing<?php if($pendingSigning>0): ?> <span style="background:#fef3c7;color:#92400e;font-size:10px;padding:1px 6px;border-radius:99px;margin-left:4px;font-weight:700;"><?=$pendingSigning?> pending</span><?php endif; ?></div>
                <a class="chl" href="signing.php">View all &#8594;</a>
            </div>
            <?php if(empty($signingList)): ?><div class="empty">No signing requests</div>
            <?php else: foreach($signingList as $sr):
                $total=(int)$sr['total_sigs']; $signed=(int)$sr['signed_count']; $pct=$total>0?round($signed/$total*100):0; ?>
            <div class="sign-row">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($sr['title'])?></div>
                    <div style="font-size:11px;color:#94a3b8;"><?=htmlspecialchars($sr['creator'])?> &bull; <?=date('d M',strtotime($sr['created_at']))?></div>
                    <div style="margin-top:4px;display:flex;align-items:center;gap:7px;">
                        <div style="flex:1;height:4px;background:#f1f5f9;border-radius:99px;overflow:hidden;"><div style="width:<?=$pct?>%;height:100%;background:<?=$pct==100?'#22c55e':'#64A014'?>;border-radius:99px;"></div></div>
                        <div class="sign-prog"><?=$signed?>/<?=$total?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

    </div>

    <!-- ── COL 3: Sidebar ── -->
    <div class="dcol">

        <!-- QUICK ACTIONS -->
        <div class="card">
            <div class="chd"><div class="cht">&#9889; Quick Actions</div></div>
            <div class="qagl">
                <?php if (in_array($user['role'], ['md','bdm','head_accounts','head_it'], true)): ?>
                <a class="qa" href="audit-export.php"><div class="qi">&#128202;</div><div class="ql">Audit Export</div></a>
                <?php endif; ?>
                <button class="qa" onclick="if(typeof hriOpenCompose==='function')hriOpenCompose()"><div class="qi">&#9998;</div><div class="ql">Compose</div></button>
                <a class="qa" href="admin/broadcast.php"><div class="qi">&#128226;</div><div class="ql">Broadcast</div></a>
                <a class="qa" href="leave-approvals.php"><div class="qi">&#127958;</div><div class="ql">Leave Queue</div></a>
                <a class="qa" href="tasks.php"><div class="qi">&#10003;</div><div class="ql">Tasks</div></a>
                <a class="qa" href="signing.php"><div class="qi">&#9997;</div><div class="ql">Signing</div></a>
                <a class="qa" href="compliance.php"><div class="qi">&#128203;</div><div class="ql">Compliance</div></a>
                <a class="qa" href="breach.php"><div class="qi">&#128680;</div><div class="ql">Breach Log</div></a>
                <a class="qa" href="directory.php"><div class="qi">&#128101;</div><div class="ql">Directory</div></a>
                <a class="qa" href="admin/users.php"><div class="qi">&#128100;</div><div class="ql">Staff</div></a>
            </div>
        </div>

        <!-- STAFF ONLINE -->
        <div class="card">
            <div class="chd"><div class="cht">&#128994; Staff Online</div><span style="font-size:12px;color:#94a3b8;"><?=$onlineStaff?> active</span></div>
            <?php if(empty($onlineList)): ?><div class="empty">No staff online</div>
            <?php else: foreach($onlineList as $s): $rl=ROLES[$s['role']]['label']??$s['role']; $mi=(int)round((time()-strtotime($s['last_active']))/60); ?>
            <div class="ritem">
                <div class="odot"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;"><?=htmlspecialchars($s['name'])?></div>
                    <div style="font-size:11px;color:#94a3b8;"><?=$rl?></div>
                </div>
                <div style="font-size:11px;color:#94a3b8;"><?=$mi===0?'now':$mi.'m'?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- ON LEAVE TODAY -->
        <?php if(!empty($onLeaveToday)): ?>
        <div class="card">
            <div class="chd"><div class="cht">&#127958; On Leave Today</div><span style="font-size:12px;color:#94a3b8;"><?=count($onLeaveToday)?></span></div>
            <?php foreach($onLeaveToday as $l): ?>
            <div class="ritem">
                <div style="flex:1;font-size:13px;font-weight:600;"><?=htmlspecialchars($l['name'])?></div>
                <span class="pill sub"><?=ucfirst(str_replace('_',' ',$l['leave_type']))?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── EXPIRY TRACKER (3-col deep dive) ── -->
<?php if(!empty($subExpiryList) || !empty($slaExpiryList) || !empty($compExpiryList)): ?>
<div class="exp-grid">

    <!-- SUBSCRIPTIONS -->
    <div class="card">
        <div class="chd"><div class="cht" style="color:#dc2626;">&#128230; Subscriptions (next 60 days)</div><a class="chl" href="compliance.php">Manage &#8594;</a></div>
        <?php if(!$subsReady): ?>
        <div class="empty" style="font-size:11.5px;">Run sprint3b_migration.sql to enable subscriptions</div>
        <?php elseif(empty($subExpiryList)): ?>
        <div class="empty">No subscriptions expiring soon &#10003;</div>
        <?php else: foreach($subExpiryList as $s): $d=(int)$s['dl']; ?>
        <div class="exp-item">
            <div style="flex:1;min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($s['name'])?></div>
                <div style="font-size:11px;color:#94a3b8;"><?=htmlspecialchars($s['vendor']??'')?><?php if(!empty($s['cost'])): ?> &bull; <?=number_format($s['cost'],0)?> <?=htmlspecialchars($s['currency']??'NGN')?><?php endif; ?></div>
                <div style="font-size:11px;color:#64748b;"><?=date('d M Y',strtotime($s['renewal_date']))?><?php if(!empty($s['auto_renews'])): ?> &bull; <span style="color:#64A014;">Auto&#8209;renews</span><?php endif; ?></div>
            </div>
            <span class="dtag <?=$d<14?'rd':($d<30?'wn':'ok')?>"><?=$d?>d</span>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- SLAs -->
    <div class="card">
        <div class="chd"><div class="cht" style="color:#f59e0b;">&#128202; SLAs (next 60 days)</div><a class="chl" href="compliance.php">Manage &#8594;</a></div>
        <?php if(empty($slaExpiryList)): ?>
        <div class="empty">No SLAs expiring soon &#10003;</div>
        <?php else: foreach($slaExpiryList as $s): $d=(int)$s['dl']; ?>
        <div class="exp-item">
            <div style="flex:1;min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($s['name'])?></div>
                <div style="font-size:11px;color:#94a3b8;"><?=htmlspecialchars($s['client']??'')?> &bull; <?=date('d M Y',strtotime($s['expiry_date']))?></div>
            </div>
            <span class="dtag <?=$d<14?'rd':($d<30?'wn':'ok')?>"><?=$d?>d</span>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- COMPLIANCE DOCS -->
    <div class="card">
        <div class="chd"><div class="cht" style="color:#0891b2;">&#128203; Compliance Docs (next 60 days)</div><a class="chl" href="compliance.php">Manage &#8594;</a></div>
        <?php if(empty($compExpiryList)): ?>
        <div class="empty">No compliance docs expiring soon &#10003;</div>
        <?php else: foreach($compExpiryList as $c): $d=(int)$c['dl']; ?>
        <div class="exp-item">
            <div style="flex:1;min-width:0;">
                <div style="font-size:12.5px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($c['name'])?></div>
                <div style="font-size:11px;color:#94a3b8;"><?=ucfirst(str_replace('_',' ',$c['category']??''))?> &bull; <?=date('d M Y',strtotime($c['expiry_date']))?></div>
            </div>
            <span class="dtag <?=$d<14?'rd':($d<30?'wn':'ok')?>"><?=$d?>d</span>
        </div>
        <?php endforeach; endif; ?>
    </div>

</div>
<?php endif; ?>

<!-- ── BOTTOM 2-COL: Department + Announcements ── -->
<div class="d2col">

    <!-- STAFF BY DEPARTMENT -->

    <!-- ANNOUNCEMENTS -->
    <div class="card">
        <div class="chd"><div class="cht">&#128226; Announcements</div><a class="chl" href="admin/announcements.php">Manage &#8594;</a></div>
        <?php $priColor=['normal'=>'#94a3b8','high'=>'#f59e0b','urgent'=>'#dc2626'];
        if(empty($announcements)): ?><div class="empty">No active announcements. <a href="admin/announcements.php" style="color:#64A014;font-weight:600;">Post one &#8594;</a></div>
        <?php else: foreach($announcements as $a): ?>
        <div class="annrow">
            <div class="antit"><span class="pri-dot" style="background:<?=$priColor[$a['priority']]??'#94a3b8'?>"></span><?=htmlspecialchars($a['title'])?></div>
            <div class="anbod"><?=htmlspecialchars(substr(strip_tags($a['body']),0,100))?><?=strlen($a['body'])>100?'&hellip;':''?></div>
        </div>
        <?php endforeach; endif; ?>
    </div>

</div>

<!-- ── STAFF ROSTER ── -->
<?php
$rosterCounts = ['online'=>count($rosterGroups['online']),'working'=>count($rosterGroups['working']),'leave'=>count($rosterGroups['leave']),'off'=>count($rosterGroups['off'])];
$atWork  = $rosterCounts['online'] + $rosterCounts['working'];
$dayLbls = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$todayYmd = date('Y-m-d');
$wkRange  = date('d M', strtotime($weekMonday)).'&ndash;'.date('d M', strtotime($weekSunday));
$nwkRange = date('d M', strtotime($nextMonday)).'&ndash;'.date('d M', strtotime($nextSunday));
?>
<div class="roster-card">
    <div class="chd" style="padding:12px 16px;">
        <div class="cht">&#128197; Staff Roster</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="font-size:12px;color:#64748b;"><?=$todayName?>, <?=date('d F Y')?> &bull; <?=$atWork?> at work &bull; <?=$rosterCounts['leave']?> on leave &bull; <?=$rosterCounts['off']?> off</span>
            <?php if(!$scheduleReady): ?><span style="font-size:11px;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;">&#9888; Set work schedules in Admin &#8594; Users</span><?php endif; ?>
        </div>
    </div>
    <div class="roster-tabs">
        <button class="rtab t-online active" onclick="switchRTab(this,'r-online')">&#128994; Online<span class="rbadge"><?=$rosterCounts['online']?></span></button>
        <button class="rtab t-working" onclick="switchRTab(this,'r-working')">&#128188; At Work<span class="rbadge"><?=$rosterCounts['working']?></span></button>
        <button class="rtab t-leave" onclick="switchRTab(this,'r-leave')">&#127958; On Leave<span class="rbadge"><?=$rosterCounts['leave']?></span></button>
        <button class="rtab t-off" onclick="switchRTab(this,'r-off')">&#9202; Off Today<span class="rbadge"><?=$rosterCounts['off']?></span></button>
        <button class="rtab t-week" onclick="switchRTab(this,'r-thisweek')" style="border-left:1px solid #f1f5f9;margin-left:4px;">&#128197; This Week<span class="rbadge" style="background:#eff6ff;color:#3b82f6;"><?=$wkRange?></span></button>
        <button class="rtab t-week" onclick="switchRTab(this,'r-nextweek')">&#10145; Next Week<span class="rbadge" style="background:#f0fdf4;color:#166534;"><?=$nwkRange?></span></button>
    </div>
    <?php
    $rosterDefs = [
        'online'  => ['id'=>'r-online',  'status'=>'online',  'label'=>'Currently active on the system',            'empty'=>'Nobody online right now'],
        'working' => ['id'=>'r-working', 'status'=>'working', 'label'=>'Expected at work &mdash; not yet logged in', 'empty'=>'Everyone is either online or off today'],
        'leave'   => ['id'=>'r-leave',   'status'=>'leave',   'label'=>'On approved leave today',                   'empty'=>'Nobody on leave today &#10003;'],
        'off'     => ['id'=>'r-off',     'status'=>'off',     'label'=>'Rest day per their work schedule',          'empty'=>'No scheduled off days today'],
    ];
    foreach ($rosterDefs as $key => $def):
        $group = $rosterGroups[$key];
    ?>
    <div class="roster-pane <?=$key==='online'?'active':''?>" id="<?=$def['id']?>">
        <?php if(empty($group)): ?>
        <div class="roster-empty"><?=$def['empty']?></div>
        <?php else: ?>
        <div style="font-size:11.5px;color:#94a3b8;margin-bottom:10px;"><?=$def['label']?></div>
        <div class="roster-grid">
        <?php foreach($group as $s):
            $sini = strtoupper(substr($s['name'],0,1).(strpos($s['name'],' ')!==false?substr($s['name'],strpos($s['name'],' ')+1,1):''));
            $scol = !empty($s['avatar_color']) ? $s['avatar_color'] : $rosterAvColors[ord($sini[0]??'A') % count($rosterAvColors)];
            $rlbl = ROLES[$s['role']]['label'] ?? $s['role'];
        ?>
        <div class="rstaff">
            <div class="rav" style="background:<?=$scol?>">
                <?=$sini?>
                <span class="rst-dot <?=$def['status']?>"></span>
            </div>
            <div style="min-width:0;">
                <div class="rname"><?=htmlspecialchars($s['name'])?></div>
                <div class="rrole"><?=$rlbl?><?php if(!empty($s['department'])): ?> &bull; <?=htmlspecialchars($s['department'])?><?php endif; ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php foreach([['r-thisweek',$weekDates],['r-nextweek',$nextWeekDates]] as list($paneId,$dates)): ?>
    <div class="roster-pane" id="<?=$paneId?>">
        <?php if(empty($rosterStaff)): ?>
        <div class="roster-empty">No active staff found.</div>
        <?php else: ?>
        <div class="week-table-wrap">
        <table class="week-table">
        <thead>
            <tr>
                <th class="wt-staff">Staff</th>
                <?php foreach($dates as $di => $dt):
                    $isToday = ($dt === $todayYmd);
                    $dow2    = (int)date('N', strtotime($dt));
                    $isWknd  = ($dow2 >= 6);
                    $thClass = $isToday ? 'wt-today' : ($isWknd ? 'wt-wknd' : '');
                ?>
                <th class="<?=$thClass?>"><?=$dayLbls[$di]?><?php if($isToday): ?><span class="wk-hdr-today-date"><?=date('d M',strtotime($dt))?></span><?php else: ?><span class="wk-hdr-date"><?=date('d M',strtotime($dt))?></span><?php endif; ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach($rosterStaff as $s):
            $sini3 = strtoupper(substr($s['name'],0,1).(strpos($s['name'],' ')!==false?substr($s['name'],strpos($s['name'],' ')+1,1):''));
            $scol3 = !empty($s['avatar_color']) ? $s['avatar_color'] : $rosterAvColors[ord($sini3[0]??'A') % count($rosterAvColors)];
            $rlbl3 = ROLES[$s['role']]['label'] ?? $s['role'];
        ?>
        <tr>
            <td class="wt-staff">
                <div class="wk-staff-cell">
                    <span class="wk-av" style="background:<?=$scol3?>"><?=$sini3?></span>
                    <div><div class="wk-staff-name"><?=htmlspecialchars($s['name'])?></div><div class="wk-staff-role"><?=$rlbl3?></div></div>
                </div>
            </td>
            <?php foreach($dates as $dt):
                $dayStatus  = mgRosterDayStatus($s, $dt, $onlineIds, $weekLeaveMap);
                $isToday    = ($dt === $todayYmd);
                $isPast     = ($dt < $todayYmd);
                $dow2       = (int)date('N', strtotime($dt));
                $isWknd     = ($dow2 >= 6);
                $tdClass    = ($isToday ? 'wt-today ' : '') . ($isWknd ? 'wt-wknd' : '');
                $cellLabels = ['online'=>'&#9679; Online','working'=>'&#9670; Work','leave'=>'&#9673; Leave','off'=>'&mdash;'];
                $cellClass  = ['online'=>'wdc-online','working'=>'wdc-working','leave'=>'wdc-leave','off'=>'wdc-off'];
                $pastClass  = ($isPast && $dayStatus !== 'leave') ? 'wdc-past' : '';
            ?>
            <td class="<?=$tdClass?>"><span class="wday-cell <?=$cellClass[$dayStatus]?> <?=$pastClass?>"><?=$cellLabels[$dayStatus]?></span></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="roster-legend" id="leg-today">
        <span class="rleg"><span class="rleg-dot" style="background:#22c55e;"></span> Online now</span>
        <span class="rleg"><span class="rleg-dot" style="background:#3b82f6;"></span> At work (not logged in)</span>
        <span class="rleg"><span class="rleg-dot" style="background:#f59e0b;"></span> On approved leave</span>
        <span class="rleg"><span class="rleg-dot" style="background:#cbd5e1;"></span> Off day</span>
    </div>
    <div class="roster-legend" id="leg-week" style="display:none;">
        <span class="rleg"><span class="wday-cell wdc-online" style="padding:1px 7px;">&#9679; Online</span> Active on system today</span>
        <span class="rleg"><span class="wday-cell wdc-working" style="padding:1px 7px;">&#9670; Work</span> Scheduled working day</span>
        <span class="rleg"><span class="wday-cell wdc-leave" style="padding:1px 7px;">&#9673; Leave</span> On approved leave</span>
        <span class="rleg"><span class="wday-cell wdc-off" style="padding:1px 7px;">&mdash;</span> Rest day / not scheduled</span>
        <?php if(!$scheduleReady): ?><span class="rleg" style="color:#92400e;">&#9888; Showing default Mon&ndash;Fri (no work schedules configured)</span><?php endif; ?>
    </div>
</div>


</div><!-- /hri-page -->

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
    if (!lr) { window.location='leave-approvals.php?id='+id; return; }
    var stages = {'lm_review':'Line Manager Review','hr_review':'HR Review','md_review':'MD/Management Review'};
    var types = lr.leave_type.replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();});
    document.getElementById('ldrBody').innerHTML =
        '<div style="margin-bottom:12px;"><div style="font-size:17px;font-weight:800;color:#002850;margin-bottom:2px;">'+escHtml(lr.rname)+'</div>'
        + '<div style="font-size:12px;color:#94a3b8;">Leave request #'+lr.id+'</div></div>'
        + '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<tr><td style="padding:6px 0;color:#64748b;width:110px;">Type</td><td style="padding:6px 0;font-weight:600;">'+escHtml(types)+'</td></tr>'
        + '<tr><td style="padding:6px 0;color:#64748b;">Dates</td><td style="padding:6px 0;font-weight:600;">'+fmtDate(lr.start_date)+' &ndash; '+fmtDate(lr.end_date)+' <span style="color:#94a3b8;">('+lr.days+' day'+((lr.days!=1)?'s':'')+')</span></td></tr>'
        + '<tr><td style="padding:6px 0;color:#64748b;">Stage</td><td style="padding:6px 0;"><span style="background:#e0e7ff;color:#3730a3;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:600;">'+(stages[lr.current_stage]||lr.current_stage)+'</span></td></tr>'
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
    msg.textContent = 'Saving...';
    msg.style.color = '#64748b';
    fetch('api/leave/quick-action.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN}, body:fd
    }).then(function(r){return r.json();}).then(function(d){
        if (d.ok) {
            msg.textContent = decision==='approve' ? '&#10003; Approved!' : '&#8617; Returned.';
            msg.style.color = decision==='approve' ? '#166534' : '#dc2626';
            var row = document.getElementById('lr-row-'+_ldrId);
            if (row) { row.style.transition='opacity .4s'; row.style.opacity='0'; setTimeout(function(){row.remove();},400); }
            var kv = document.querySelector('[data-kpi="leave_pending"]');
            if (kv) { var n=parseInt(kv.textContent||'0'); kv.textContent=Math.max(0,n-1); }
            setTimeout(closeLeaveDrawer, 1200);
        } else {
            msg.textContent = d.error || 'Error. Try again.';
            msg.style.color = '#dc2626';
        }
    }).catch(function(){ msg.textContent='Network error. Try again.'; msg.style.color='#dc2626'; });
}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function fmtDate(s){if(!s)return'';var p=s.split('-');var ms=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return parseInt(p[2])+' '+ms[parseInt(p[1])-1]+' '+p[0];}

function tick(){var n=new Date(),h=n.getHours(),ap=h>=12?'PM':'AM',h12=h%12||12,el=document.getElementById('clk');if(el)el.textContent=String(h12).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0')+' '+ap;}
tick();setInterval(tick,1000);

// KPI auto-refresh (60s)
(function(){
    function refreshKpis(){
        fetch('api/dashboard/kpi.php',{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
        .then(function(r){return r.json();}).then(function(d){
            if(!d.ok)return;
            var kpis=d.data.kpis;
            Object.keys(kpis).forEach(function(k){
                var el=document.querySelector('[data-kpi="'+k+'"]');
                if(el){el.textContent=kpis[k];el.style.transition='transform .15s';el.style.transform='scale(1.08)';setTimeout(function(){el.style.transform='scale(1)';},200);}
            });
        }).catch(function(){});
    }
    setInterval(refreshKpis,60000);
})();

function switchRTab(btn,paneId){
    document.querySelectorAll('.rtab').forEach(function(b){b.classList.remove('active');});
    document.querySelectorAll('.roster-pane').forEach(function(p){p.classList.remove('active');});
    btn.classList.add('active');
    var p=document.getElementById(paneId);if(p)p.classList.add('active');
    var isWeek=(paneId==='r-thisweek'||paneId==='r-nextweek');
    var lt=document.getElementById('leg-today'),lw=document.getElementById('leg-week');
    if(lt)lt.style.display=isWeek?'none':'flex';
    if(lw)lw.style.display=isWeek?'flex':'none';
}
</script>
<?php Layout::end(); ?>
