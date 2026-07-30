<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// admin/tasks.php — Enterprise task monitoring (IT admin)
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';
$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_outsourcing','head_compliance','head_accounts','head_training','head_cso','cs_manager','training_manager']);
$db   = getDB();
$uid  = (int)$user['id'];
$success = '';

/* ── Handle status update ── */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $tid    = (int)($_POST['task_id']??0);
    $status = $_POST['status']??'';
    if ($tid && in_array($status,['pending','in_progress','done'])) {
        $completedAt = $status==='done' ? ',completed_at=NOW()' : ($status==='pending'?',completed_at=NULL':'');
        $db->prepare("UPDATE tasks SET status=?,updated_at=NOW()$completedAt WHERE id=?")->execute([$status,$tid]);
        Auth::auditLog($uid,'admin_task_update',"Task #$tid status → $status");
        $success = 'Task updated.';
    }
}

/* ── Filters ── */
$fStatus  = $_GET['status']??'active';
$fUser    = (int)($_GET['user']??0);
$fSource  = $_GET['source']??'all';
$fPrio    = $_GET['priority']??'all';

$where  = ['1=1'];
$params = [];

if ($fStatus==='active')  { $where[]="t.status!='done'"; }
elseif ($fStatus==='done'){ $where[]="t.status='done'"; }
elseif ($fStatus==='overdue'){ $where[]="t.due_date < CURDATE() AND t.status!='done'"; }

if ($fUser)               { $where[]="t.user_id=?"; $params[]=$fUser; }
if ($fSource!=='all')     { $where[]="t.source=?"; $params[]=$fSource; }
if ($fPrio!=='all')       { $where[]="t.priority=?"; $params[]=$fPrio; }

$whereSql = implode(' AND ', $where);
$q = "SELECT t.*, u_own.name as owner_name, u_own.role as owner_role,
             u_cr.name as creator_name, u_as.name as assignee_name,
             (SELECT COUNT(*) FROM task_collaborators tc WHERE tc.task_id=t.id) as collab_count,
             (SELECT COUNT(*) FROM task_collaborators tc WHERE tc.task_id=t.id AND tc.input_required=1 AND tc.input_provided_at IS NULL) as pending_inputs
      FROM tasks t
      LEFT JOIN users u_own ON u_own.id=t.user_id
      LEFT JOIN users u_cr  ON u_cr.id=t.created_by
      LEFT JOIN users u_as  ON u_as.id=t.assigned_to
      WHERE $whereSql
      ORDER BY FIELD(t.priority,'urgent','high','normal','low'),
               CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,
               t.due_date ASC, t.created_at DESC";
$stm = $db->prepare($q);
$stm->execute($params);
$tasks = $stm->fetchAll();

/* ── Summary stats ── */
$stats = $db->query("SELECT
    COUNT(*) as total,
    SUM(status='pending') as pending,
    SUM(status='in_progress') as in_progress,
    SUM(status='done') as done,
    SUM(due_date < CURDATE() AND status!='done') as overdue,
    SUM(source='it_request') as it_tasks
    FROM tasks")->fetch();

/* ── Users for filter ── */
$allUsers = $db->query("SELECT id,name FROM users WHERE is_active=1 ORDER BY name")->fetchAll();

$pc = ['urgent'=>'#dc2626','high'=>'#f59e0b','normal'=>'#3b82f6','low'=>'#94a3b8'];
$pl = ['urgent'=>'Urgent','high'=>'High','normal'=>'Normal','low'=>'Low'];
$rShort = array_map(function($r){ return $r['label']; }, ROLES);

Layout::shell($user, 'tasks', 0, 'All Tasks');
?>
<style>
.atpage{width:100%;max-width:1600px;margin:0 auto;padding:24px 16px 24px;overflow-y:auto;height:100%;}
.atpgt{font-size:18px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.atpgs{font-size:13px;color:var(--g400);margin-bottom:16px;}
.atscg{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:18px;}
.atsc{background:var(--w);border-radius:9px;padding:10px 12px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center;}
.atscv{font-size:18px;font-weight:700;color:var(--navy);}
.atscl{font-size:10px;color:var(--g400);margin-top:2px;}
.filters{background:var(--w);border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:12px 16px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;}
.filter-group{display:flex;align-items:center;gap:7px;}
.filter-label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--g400);letter-spacing:.05em;white-space:nowrap;}
select.fslt{border:1.5px solid var(--g200);border-radius:7px;padding:6px 10px;font-size:12.5px;font-family:inherit;color:var(--g700);outline:none;background:var(--g50);}
select.fslt:focus{border-color:var(--green);}
.fgo{padding:7px 14px;border-radius:7px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;background:var(--navy);color:#fff;font-family:inherit;}
.atcard{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.atalert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;background:#dcfce7;border:1px solid #86efac;color:#166534;}
.attbl{width:100%;border-collapse:collapse;}
.attbl th{background:var(--g50);padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--g400);text-align:left;border-bottom:1px solid var(--g200);}
.attbl td{padding:10px 14px;border-bottom:1px solid var(--g50);font-size:13px;vertical-align:middle;}
.attbl tr:last-child td{border-bottom:none;}
.attbl tr:hover td{background:var(--g50);}
.atpill{font-size:10.5px;padding:2px 7px;border-radius:20px;font-weight:600;display:inline-block;white-space:nowrap;}
.atpill-p{background:#fef3c7;color:#92400e;}
.atpill-i{background:#dbeafe;color:#1e40af;}
.atpill-d{background:#dcfce7;color:#166534;}
.atpill-it{background:#fce7f3;color:#9d174d;}
.status-sel{border:1.5px solid var(--g200);border-radius:6px;padding:4px 8px;font-size:12px;font-family:inherit;color:var(--g700);outline:none;cursor:pointer;background:var(--g50);}
.due-ov{color:var(--red);font-weight:600;}
.due-so{color:#f59e0b;font-weight:600;}
.atempty{padding:32px;text-align:center;color:var(--g400);font-size:13px;}
.name-cell{font-weight:600;color:var(--g900);}
.sub-cell{font-size:11.5px;color:var(--g400);margin-top:2px;}
@media(max-width:640px){.atscg{grid-template-columns:repeat(3,1fr);}}
@media(max-width:420px){.atscg{grid-template-columns:repeat(2,1fr);}}
</style>
<div class="atpage">
<div class="atpgt">&#127962; Enterprise Task Monitor</div>
<div class="atpgs">All tasks across the organisation</div>

<?php if($success):?><div class="atalert">&#9989; <?=htmlspecialchars($success)?></div><?php endif;?>

<!-- Stats -->
<div class="atscg">
    <div class="atsc"><div class="atscv"><?=$stats['total']??0?></div><div class="atscl">Total</div></div>
    <div class="atsc"><div class="atscv" style="color:#f59e0b;"><?=$stats['pending']??0?></div><div class="atscl">Pending</div></div>
    <div class="atsc"><div class="atscv" style="color:#3b82f6;"><?=$stats['in_progress']??0?></div><div class="atscl">In Progress</div></div>
    <div class="atsc"><div class="atscv" style="color:#64A014;"><?=$stats['done']??0?></div><div class="atscl">Done</div></div>
    <div class="atsc"><div class="atscv" style="color:#dc2626;"><?=$stats['overdue']??0?></div><div class="atscl">Overdue</div></div>
    <div class="atsc"><div class="atscv" style="color:#9d174d;"><?=$stats['it_tasks']??0?></div><div class="atscl">IT-Sourced</div></div>
</div>

<!-- Filters -->
<form method="GET" class="filters">
    <div class="filter-group">
        <span class="filter-label">Status</span>
        <select name="status" class="fslt">
            <option value="active"   <?=$fStatus==='active'  ?'selected':''?>>Active</option>
            <option value="done"     <?=$fStatus==='done'    ?'selected':''?>>Done</option>
            <option value="overdue"  <?=$fStatus==='overdue' ?'selected':''?>>Overdue</option>
            <option value="all"      <?=$fStatus==='all'     ?'selected':''?>>All</option>
        </select>
    </div>
    <div class="filter-group">
        <span class="filter-label">Employee</span>
        <select name="user" class="fslt">
            <option value="">Everyone</option>
            <?php foreach($allUsers as $u): ?>
            <option value="<?=$u['id']?>" <?=$fUser==$u['id']?'selected':''?>><?=htmlspecialchars($u['name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <span class="filter-label">Source</span>
        <select name="source" class="fslt">
            <option value="all"        <?=$fSource==='all'       ?'selected':''?>>All</option>
            <option value="manual"     <?=$fSource==='manual'    ?'selected':''?>>Manual</option>
            <option value="it_request" <?=$fSource==='it_request'?'selected':''?>>IT Request</option>
        </select>
    </div>
    <div class="filter-group">
        <span class="filter-label">Priority</span>
        <select name="priority" class="fslt">
            <option value="all"    <?=$fPrio==='all'    ?'selected':''?>>All</option>
            <option value="urgent" <?=$fPrio==='urgent' ?'selected':''?>>Urgent</option>
            <option value="high"   <?=$fPrio==='high'   ?'selected':''?>>High</option>
            <option value="normal" <?=$fPrio==='normal' ?'selected':''?>>Normal</option>
            <option value="low"    <?=$fPrio==='low'    ?'selected':''?>>Low</option>
        </select>
    </div>
    <button type="submit" class="fgo">Apply</button>
    <a href="tasks.php" style="font-size:12px;color:var(--g400);text-decoration:none;margin-left:4px;">Reset</a>
    <span style="margin-left:auto;font-size:12px;color:var(--g400);"><?=count($tasks)?> tasks</span>
</form>

<!-- Task table -->
<div class="atcard">
<?php if(empty($tasks)): ?>
<div class="atempty">No tasks match the selected filters.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="attbl">
<thead>
<tr>
    <th>Task</th>
    <th>Owner / Assignee</th>
    <th>Priority</th>
    <th>Due Date</th>
    <th>Source</th>
    <th>Status</th>
</tr>
</thead>
<tbody>
<?php foreach($tasks as $t):
    $isDone = $t['status']==='done';
    $dTs    = $t['due_date'] ? strtotime($t['due_date']) : null;
    $tod    = strtotime(date('Y-m-d'));
    $dueClass = $dueLabel = '';
    if ($dTs && !$isDone) {
        $diff = ($dTs-$tod)/86400;
        if ($diff < 0)     { $dueClass='due-ov'; $dueLabel='&#9888; Overdue'; }
        elseif ($diff <= 1){ $dueClass='due-so'; }
    }
    $statusPill = ['pending'=>'atpill-p','in_progress'=>'atpill-i','done'=>'atpill-d'];
    $statusLbl  = ['pending'=>'Pending','in_progress'=>'In Progress','done'=>'Done'];
?>
<tr>
    <td>
        <div class="name-cell"><?=htmlspecialchars($t['title'])?></div>
        <?php if($t['description']): ?>
        <div class="sub-cell"><?=htmlspecialchars(substr($t['description'],0,70))?></div>
        <?php endif; ?>
        <?php if(($t['collab_count']??0)>0):?>
        <div class="sub-cell">&#128101; <?=$t['collab_count']?> collaborator<?=$t['collab_count']!=1?'s':''?>
        <?php if(($t['pending_inputs']??0)>0):?> &middot; <span style="color:#92400e;font-weight:600;">&#9889; <?=$t['pending_inputs']?> input pending</span><?php endif;?>
        </div>
        <?php endif;?>
    </td>
    <td>
        <div style="font-size:13px;font-weight:600;"><?=htmlspecialchars($t['owner_name']??'—')?></div>
        <?php if($t['owner_role']): ?><div class="sub-cell"><?=$rShort[$t['owner_role']]??$t['owner_role']?></div><?php endif;?>
        <?php if($t['assignee_name'] && $t['assignee_name']!==$t['owner_name']): ?>
        <div class="sub-cell">Assigned by: <?=htmlspecialchars($t['creator_name']??'—')?></div>
        <?php endif;?>
    </td>
    <td><span style="color:<?=$pc[$t['priority']]??'#94a3b8'?>;font-weight:600;font-size:12px;"><?=$pl[$t['priority']]??'—'?></span></td>
    <td>
        <?php if($t['due_date']): ?>
        <span class="<?=$dueClass?>"><?=htmlspecialchars(date('d M Y',strtotime($t['due_date'])))?></span>
        <?php if($dueLabel): ?><div style="font-size:11px;color:var(--red);"><?=$dueLabel?></div><?php endif;?>
        <?php else: ?>—<?php endif;?>
    </td>
    <td>
        <?php if(($t['source']??'manual')==='it_request'): ?>
        <span class="atpill atpill-it">IT Request</span>
        <?php else:?>
        <span style="font-size:12px;color:var(--g400);">Manual</span>
        <?php endif;?>
    </td>
    <td>
        <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-start;">
            <span class="atpill <?=$statusPill[$t['status']]??''?>"><?=$statusLbl[$t['status']]??$t['status']?></span>
            <form method="POST" style="margin:0;">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="task_id" value="<?=$t['id']?>"/>
                <select class="status-sel" name="status" onchange="this.form.submit()">
                    <option value="pending"     <?=$t['status']==='pending'    ?'selected':''?>>Pending</option>
                    <option value="in_progress" <?=$t['status']==='in_progress'?'selected':''?>>In Progress</option>
                    <option value="done"        <?=$t['status']==='done'       ?'selected':''?>>Done</option>
                </select>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</div>
<?php Layout::end(); ?>
