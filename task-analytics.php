<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';
$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_compliance']);
$db   = getDB();
$isAdmin = in_array($user['role'], ['head_it','it_admin','md','bdm']);

/* ── Org-wide task stats per user ── */
$perUser = $db->query("
    SELECT u.id, u.name, u.role,
        SUM(CASE WHEN t.status='pending'     THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN t.status='in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN t.status='done'        THEN 1 ELSE 0 END) as done,
        SUM(CASE WHEN t.due_date < CURDATE() AND t.status!='done' THEN 1 ELSE 0 END) as overdue,
        COUNT(t.id) as total,
        SUM(CASE WHEN t.source='it_request'  THEN 1 ELSE 0 END) as it_tasks
    FROM users u
    LEFT JOIN tasks t ON t.user_id = u.id
    WHERE u.is_active=1
    GROUP BY u.id, u.name, u.role
    ORDER BY (SUM(CASE WHEN t.status!='done' THEN 1 ELSE 0 END)) DESC
")->fetchAll();

/* ── IT request tasks stats ── */
$itStats = $db->query("
    SELECT
        SUM(source='it_request') as total_it,
        SUM(source='it_request' AND status='pending')     as it_pending,
        SUM(source='it_request' AND status='in_progress') as it_inprog,
        SUM(source='it_request' AND status='done')        as it_done,
        SUM(source='it_request' AND due_date < CURDATE() AND status!='done') as it_overdue
    FROM tasks
")->fetch();

/* ── Overall stats ── */
$overall = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(status='pending')     as pending,
        SUM(status='in_progress') as in_progress,
        SUM(status='done')        as done,
        SUM(due_date < CURDATE() AND status!='done') as overdue,
        SUM(source='it_request')  as it_sourced,
        SUM(CASE WHEN completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status='done' THEN 1 ELSE 0 END) as done_this_week
    FROM tasks
")->fetch();

/* ── Completion rate trend last 30 days ── */
$trend = $db->query("
    SELECT DATE(completed_at) as day, COUNT(*) as cnt
    FROM tasks WHERE status='done' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(completed_at) ORDER BY day ASC
")->fetchAll();

/* ── Most overdue ── */
$mostOverdue = $db->query("
    SELECT t.*, u.name as owner_name
    FROM tasks t JOIN users u ON u.id=t.user_id
    WHERE t.due_date < CURDATE() AND t.status!='done'
    ORDER BY t.due_date ASC LIMIT 8
")->fetchAll();

/* ── Pending inputs ── */
$pendingInputs = $db->query("
    SELECT tc.*, u_owner.name as task_owner, u_collab.name as collab_name, t.title as task_title
    FROM task_collaborators tc
    JOIN tasks t ON t.id=tc.task_id
    JOIN users u_owner ON u_owner.id=t.user_id
    JOIN users u_collab ON u_collab.id=tc.user_id
    WHERE tc.input_required=1 AND tc.input_provided_at IS NULL AND t.status!='done'
    ORDER BY t.due_date ASC LIMIT 10
")->fetchAll();

$rLabel = array_map(function($r){ return $r['label']; }, ROLES);

/* Max active tasks for bar scaling */
$maxActive = 1;
foreach ($perUser as $u) { $active = ($u['pending']+$u['in_progress']); if ($active>$maxActive) $maxActive=$active; }

Layout::shell($user, 'tasks', 0, 'Task Analytics');
?>
<style>
.tapage{max-width:1600px;margin:0 auto;padding:24px 16px 60px;overflow-y:auto;height:100%;}
.tapgt{font-size:19px;font-weight:700;color:var(--navy);margin-bottom:16px;}
.tascg{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:20px;}
.tasc{background:var(--w);border-radius:10px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center;border-top:3px solid var(--green);}
.tascv{font-size:24px;font-weight:700;color:var(--navy);}
.tascl{font-size:10.5px;color:var(--g400);margin-top:3px;}
.tagrid2{display:grid;grid-template-columns:2fr 1fr;gap:18px;align-items:start;margin-bottom:18px;}
.tacard{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:18px;}
.tachd{padding:12px 18px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.tacht{font-size:13px;font-weight:700;color:var(--navy);}

/* Workload table */
.wl-row{display:flex;align-items:center;gap:12px;padding:10px 18px;border-bottom:1px solid var(--g50);}
.wl-row:last-child{border-bottom:none;}
.wl-name{width:160px;flex-shrink:0;}
.wl-name-main{font-size:13px;font-weight:600;color:var(--g900);}
.wl-name-role{font-size:11px;color:var(--g400);}
.wl-bars{flex:1;}
.wl-bar-row{display:flex;align-items:center;gap:5px;margin-bottom:4px;}
.wl-bar-row:last-child{margin-bottom:0;}
.wl-bar-label{font-size:10.5px;color:var(--g400);width:60px;flex-shrink:0;text-align:right;}
.wl-bar-track{flex:1;height:8px;background:var(--g100);border-radius:4px;overflow:hidden;}
.wl-bar-fill{height:100%;border-radius:4px;transition:width .3s;}
.wl-nums{width:90px;flex-shrink:0;font-size:11.5px;color:var(--g500);text-align:right;}
.ov-badge{background:#fee2e2;color:var(--red);font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px;}
.done-badge{background:#dcfce7;color:#166534;font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px;}

/* Overdue / pending inputs */
.item-row{padding:10px 18px;border-bottom:1px solid var(--g50);display:flex;align-items:flex-start;gap:10px;}
.item-row:last-child{border-bottom:none;}
.item-info{flex:1;}
.item-title{font-size:13px;font-weight:600;color:var(--g900);}
.item-meta{font-size:11.5px;color:var(--g400);margin-top:2px;}
.item-side{text-align:right;flex-shrink:0;}

/* IT stats row */
.it-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:14px 18px;}
.it-sc{text-align:center;padding:10px;background:var(--g50);border-radius:8px;}
.it-v{font-size:18px;font-weight:700;color:var(--navy);}
.it-l{font-size:10.5px;color:var(--g400);margin-top:2px;}

/* Trend */
.trend-row{display:flex;align-items:flex-end;gap:3px;height:80px;padding:0 18px 14px;}
.trend-bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;}
.trend-bar{background:var(--green);border-radius:2px 2px 0 0;width:100%;min-height:2px;}
.trend-lbl{font-size:9px;color:var(--g400);}

@media(max-width:800px){.tascg{grid-template-columns:repeat(3,1fr);}.tagrid2{grid-template-columns:1fr;}}
@media(max-width:640px){.tascg{grid-template-columns:repeat(2,1fr);}.it-grid{grid-template-columns:repeat(2,1fr);}}
</style>
<div class="tapage">
<div class="tapgt">&#128202; Task Analytics &mdash; Organisation Overview</div>

<!-- Overall stats -->
<div class="tascg">
    <div class="tasc"><div class="tascv"><?=$overall['total']??0?></div><div class="tascl">Total Tasks</div></div>
    <div class="tasc" style="border-top-color:#f59e0b;"><div class="tascv"><?=($overall['pending']??0)+($overall['in_progress']??0)?></div><div class="tascl">Active</div></div>
    <div class="tasc" style="border-top-color:#dc2626;"><div class="tascv"><?=$overall['overdue']??0?></div><div class="tascl">Overdue</div></div>
    <div class="tasc"><div class="tascv"><?=$overall['done_this_week']??0?></div><div class="tascl">Done This Week</div></div>
    <div class="tasc" style="border-top-color:#9d174d;"><div class="tascv"><?=$overall['it_sourced']??0?></div><div class="tascl">IT-Sourced Tasks</div></div>
</div>

<div class="tagrid2">
<!-- Workload by employee -->
<div class="tacard">
    <div class="tachd">
        <div class="tacht">&#128101; Workload per Employee</div>
        <span style="font-size:11.5px;color:var(--g400);">Active tasks (pending + in progress)</span>
    </div>
    <?php foreach($perUser as $u):
        $active = $u['pending']+$u['in_progress'];
        $pct_p  = $maxActive>0 ? round(($u['pending']/$maxActive)*100) : 0;
        $pct_i  = $maxActive>0 ? round(($u['in_progress']/$maxActive)*100) : 0;
        $pct_d  = $u['total']>0 ? round(($u['done']/$u['total'])*100) : 0;
    ?>
    <div class="wl-row">
        <div class="wl-name">
            <div class="wl-name-main"><?=htmlspecialchars($u['name'])?></div>
            <div class="wl-name-role"><?=$rLabel[$u['role']]??$u['role']?></div>
        </div>
        <div class="wl-bars">
            <div class="wl-bar-row">
                <div class="wl-bar-label">Pending</div>
                <div class="wl-bar-track"><div class="wl-bar-fill" style="width:<?=$pct_p?>%;background:#f59e0b;"></div></div>
            </div>
            <div class="wl-bar-row">
                <div class="wl-bar-label">In Prog</div>
                <div class="wl-bar-track"><div class="wl-bar-fill" style="width:<?=$pct_i?>%;background:#3b82f6;"></div></div>
            </div>
        </div>
        <div class="wl-nums">
            <?php if($u['overdue']>0):?><span class="ov-badge">&#9888; <?=$u['overdue']?> OD</span><br style="margin:3px 0;"><?php endif;?>
            <?php if($pct_d>0):?><span class="done-badge">&#10003; <?=$pct_d?>%</span><?php endif;?>
            <div style="font-size:11px;color:var(--g400);margin-top:3px;"><?=$active?> active / <?=$u['total']?> total</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Right column -->
<div>
<!-- IT Request Tasks -->
<div class="tacard">
    <div class="tachd"><div class="tacht">&#128295; IT Request Tasks</div></div>
    <div class="it-grid">
        <div class="it-sc"><div class="it-v"><?=$itStats['total_it']??0?></div><div class="it-l">Total</div></div>
        <div class="it-sc"><div class="it-v" style="color:#f59e0b;"><?=$itStats['it_pending']??0?></div><div class="it-l">Pending</div></div>
        <div class="it-sc"><div class="it-v" style="color:#3b82f6;"><?=$itStats['it_inprog']??0?></div><div class="it-l">In Progress</div></div>
        <div class="it-sc"><div class="it-v" style="color:#64A014;"><?=$itStats['it_done']??0?></div><div class="it-l">Done</div></div>
    </div>
    <?php if(($itStats['it_overdue']??0)>0): ?>
    <div style="padding:8px 18px 12px;font-size:12.5px;color:var(--red);font-weight:600;">&#9888; <?=$itStats['it_overdue']?> IT task(s) overdue</div>
    <?php endif; ?>
</div>

<!-- Completion trend -->
<?php if(!empty($trend)):
    $maxTrend = max(array_column($trend,'cnt'));
?>
<div class="tacard">
    <div class="tachd"><div class="tacht">&#128200; Completed Last 30 Days</div></div>
    <div class="trend-row">
        <?php foreach($trend as $day):
            $h = $maxTrend>0 ? round(($day['cnt']/$maxTrend)*70) : 2;
            $lbl = date('d/m',strtotime($day['day']));
        ?>
        <div class="trend-bar-wrap">
            <div class="trend-bar" style="height:<?=$h?>px;" title="<?=$day['cnt']?> done on <?=$lbl?>"></div>
            <div class="trend-lbl"><?=$lbl?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Pending inputs -->
<?php if(!empty($pendingInputs)): ?>
<div class="tacard">
    <div class="tachd"><div class="tacht">&#9889; Awaiting Input</div><span style="font-size:11.5px;color:var(--red);"><?=count($pendingInputs)?></span></div>
    <?php foreach($pendingInputs as $pi): ?>
    <div class="item-row">
        <div class="item-info">
            <div class="item-title"><?=htmlspecialchars(substr($pi['task_title'],0,50))?></div>
            <div class="item-meta">
                From: <?=htmlspecialchars($pi['task_owner'])?> &middot; Waiting on <strong><?=htmlspecialchars($pi['collab_name'])?></strong>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>

<!-- Most overdue tasks -->
<?php if(!empty($mostOverdue)): ?>
<div class="tacard">
    <div class="tachd"><div class="tacht">&#9888;&#65039; Most Overdue Tasks</div></div>
    <?php foreach($mostOverdue as $t):
        $daysOver = round((strtotime(date('Y-m-d'))-strtotime($t['due_date']))/86400);
    ?>
    <div class="item-row">
        <div style="font-size:20px;flex-shrink:0;">&#9888;&#65039;</div>
        <div class="item-info">
            <div class="item-title"><?=htmlspecialchars($t['title'])?></div>
            <div class="item-meta">
                Owner: <strong><?=htmlspecialchars($t['owner_name'])?></strong> &middot;
                Due: <?=date('d M Y',strtotime($t['due_date']))?>
                <?php if(($t['source']??'manual')==='it_request'):?>&middot; <span style="color:#9d174d;font-weight:600;">IT Request</span><?php endif;?>
            </div>
        </div>
        <div class="item-side">
            <span class="ov-badge"><?=$daysOver?> day<?=$daysOver!=1?'s':''?> overdue</span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div>
<?php Layout::end(); ?>
