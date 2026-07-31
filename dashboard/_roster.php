<?php
/**
 * Shared Staff Roster widget — the same tabbed roster that has always been on
 * the Management dashboard, extracted verbatim so HR and Super Admin get the
 * identical thing rather than a second, different-looking version.
 *
 * Expects: $user (array), $db (PDO).
 *
 * RESTRICTED to hr, head_it, it_admin, md and bdm. The check lives here, not in
 * each dashboard, so the rule holds wherever this file is included.
 *
 * Self-contained: it does its own queries and carries its own CSS and JS, both
 * guarded so including it alongside management.php cannot double up.
 */
if (!isset($user) || !isset($db)) return;
if (!defined('ROSTER_VISIBLE_ROLES')) {
    define('ROSTER_VISIBLE_ROLES', ['hr', 'head_it', 'it_admin', 'md', 'bdm']);
}
if (!in_array($user['role'] ?? '', ROSTER_VISIBLE_ROLES, true)) return;
if (defined('HRI_ROSTER_RENDERED')) return;   // management.php already drew it
define('HRI_ROSTER_RENDERED', true);

try {
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
if (!function_exists('mgRosterDayStatus')):
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
endif;
} catch (Throwable $_e) { return; }
?>
<style>
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
/* mobile-all-pages */
@media(max-width:768px){
    .roster-grid{grid-template-columns:1fr;}
    /* Tabs must NOT shrink. The global *{min-width:0} that fixes grid overflow
       also lets flex items collapse below their content — which made these six
       tabs overlap into unreadable mush on a phone. Hold their width and let
       the strip scroll instead. */
    .roster-tabs{overflow-x:auto;-webkit-overflow-scrolling:touch;flex-wrap:nowrap;}
    .rtab{flex:0 0 auto;white-space:nowrap;padding:10px 12px;font-size:13px;}
    .rtab .rbadge{flex-shrink:0;}
    /* week table keeps its own scroller — do not let the global rule flatten it */
    .week-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
    .week-table{display:table;}
}
</style>
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
<script>
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
