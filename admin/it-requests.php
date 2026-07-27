<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// admin/it-requests.php — IT Admin manages all IT requests
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin']);
$db   = getDB();

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid    = (int)$_POST['request_id'];
    $status = $_POST['status'] ?? '';
    if ($rid && in_array($status, ['open','in_progress','resolved','closed'])) {
        $db->prepare("UPDATE it_requests SET status=?, resolved_at=".($status==='resolved'?'NOW()':'NULL').", assigned_to=? WHERE id=?")
           ->execute([$status, $user['id'], $rid]);
        Auth::auditLog($user['id'], 'it_request_updated', "IT Request #$rid status: $status");

        // Sync linked tasks
        if ($status === 'open')                              $taskStatus = 'pending';
        elseif ($status === 'in_progress')                   $taskStatus = 'in_progress';
        elseif ($status === 'resolved' || $status === 'closed') $taskStatus = 'done';
        else                                                 $taskStatus = null;
        if ($taskStatus) {
            $completedAt = $taskStatus==='done' ? ',completed_at=NOW()' : ',completed_at=NULL';
            $db->prepare("UPDATE tasks SET status=?,updated_at=NOW()$completedAt WHERE source_it_request_id=? AND source='it_request'")
               ->execute([$taskStatus, $rid]);
        }
        $success = 'Request updated.';
    }
}

$filter = $_GET['f'] ?? 'open';
$where  = $filter === 'all' ? '1=1' : "ir.status = '$filter'";
$reqs   = $db->query("SELECT ir.*, u.name as requester_name, u.email as requester_email
                       FROM it_requests ir JOIN users u ON u.id=ir.user_id
                       WHERE $where ORDER BY
                       CASE ir.priority WHEN 'urgent' THEN 1 ELSE 2 END,
                       ir.created_at DESC")->fetchAll();

Layout::shell($user, 'it_requests', 0, 'IT Requests');
?>
<style>
.tabs{display:flex;gap:5px;margin-bottom:16px;flex-wrap:wrap;}
.tab{padding:5px 14px;border-radius:20px;font-size:12.5px;font-weight:500;cursor:pointer;text-decoration:none;border:1.5px solid var(--g200);color:var(--g700);transition:all .12s;}
.tab:hover,.tab.on{background:var(--navy);color:#fff;border-color:var(--navy);}
.card{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.req-item{padding:14px 18px;border-bottom:1px solid var(--g50);display:flex;align-items:flex-start;gap:12px;}
.req-item:last-child{border-bottom:none;}
.ri-ico{font-size:22px;flex-shrink:0;margin-top:2px;}
.ri-info{flex:1;}
.ri-type{font-size:13.5px;font-weight:700;color:var(--g900);}
.ri-desc{font-size:12.5px;color:var(--g500);margin-top:4px;line-height:1.5;}
.ri-meta{font-size:11.5px;color:var(--g400);margin-top:5px;}
.ri-acts{display:flex;flex-direction:column;align-items:flex-end;gap:7px;flex-shrink:0;}
.pill{padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;display:inline-block;}
.pill.open{background:#fef3c7;color:#92400e;}
.pill.in_progress{background:#dbeafe;color:#1e40af;}
.pill.resolved{background:#dcfce7;color:#166534;}
.pill.closed{background:var(--g100);color:var(--g500);}
.pill.urgent{background:#fee2e2;color:var(--red);}
.status-sel{border:1.5px solid var(--g200);border-radius:7px;padding:5px 9px;font-size:12px;font-family:'Inter',sans-serif;color:var(--g700);outline:none;cursor:pointer;}
.alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;background:#dcfce7;border:1px solid #86efac;color:#166534;}
.empty{padding:28px;text-align:center;color:var(--g400);font-size:13px;}
.pgt{font-size:17px;font-weight:700;color:var(--navy);margin-bottom:14px;}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">
    <div class="pgt">🔧 IT Support Requests</div>
    <?php if ($success): ?><div class="alert">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <div class="tabs">
        <?php foreach (['open'=>'⏳ Open','in_progress'=>'🔄 In Progress','resolved'=>'✅ Resolved','closed'=>'📁 Closed','all'=>'📋 All'] as $f=>$l): ?>
        <a class="tab <?= $filter===$f?'on':'' ?>" href="it-requests.php?f=<?= $f ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
    <div class="card">
        <?php if (empty($reqs)): ?>
        <div class="empty">No <?= $filter ?> requests ✓</div>
        <?php else: ?>
        <?php foreach ($reqs as $r): ?>
        <div class="req-item">
            <div class="ri-ico">🔧</div>
            <div class="ri-info">
                <div class="ri-type"><?= htmlspecialchars($r['issue_type']) ?></div>
                <div class="ri-desc"><?= htmlspecialchars($r['description']) ?></div>
                <div class="ri-meta">
                    From: <strong><?= htmlspecialchars($r['requester_name']) ?></strong>
                    (<?= htmlspecialchars($r['requester_email']) ?>)
                    · <?= date('d M Y H:i', strtotime($r['created_at'])) ?>
                </div>
            </div>
            <div class="ri-acts">
                <?php if ($r['priority']==='urgent'): ?>
                <span class="pill urgent">🔴 Urgent</span>
                <?php endif; ?>
                <span class="pill <?= $r['status'] ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span>
                <form method="POST" style="display:flex;gap:6px;align-items:center;">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>"/>
                    <select class="status-sel" name="status" onchange="this.form.submit()">
                        <option value="open"        <?= $r['status']==='open'?'selected':'' ?>>Open</option>
                        <option value="in_progress" <?= $r['status']==='in_progress'?'selected':'' ?>>In Progress</option>
                        <option value="resolved"    <?= $r['status']==='resolved'?'selected':'' ?>>Resolved</option>
                        <option value="closed"      <?= $r['status']==='closed'?'selected':'' ?>>Closed</option>
                    </select>
                </form>
                <a href="<?= APP_URL ?>/compose.php?to=<?= urlencode($r['requester_email']) ?>&subject=<?= urlencode('Re: IT Request — '.$r['issue_type']) ?>"
                   style="font-size:11.5px;color:var(--green);font-weight:600;text-decoration:none;">✉️ Reply</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php Layout::end(); ?>
