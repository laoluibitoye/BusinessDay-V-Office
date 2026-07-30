<?php
if(ob_get_level()===0)ob_start();
if(session_status()===PHP_SESSION_NONE)session_start();
// admin/leave-queue.php — Admin Leave Approvals Queue
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';
$user=Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_outsourcing','head_compliance','head_accounts','head_training','head_cso','training_manager','cs_manager']);
$db=getDB();

// Stage filter
$stage=in_array($_GET['stage']??'',['pending','approved','rejected','all'])?$_GET['stage']:'pending';

// Map stage to current_stage values
if($stage==='pending') $where="WHERE lr.current_stage IN ('lm_review','hr_review','md_review')";
elseif($stage==='approved') $where="WHERE lr.current_stage='approved'";
elseif($stage==='rejected') $where="WHERE lr.current_stage='rejected'";
else $where='';

// Handle approve/reject
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    $id=(int)($_POST['id']??0);
    if($action==='approve'&&$id){
        $db->prepare("UPDATE leave_requests SET current_stage='approved',hr_actioned_by=?,hr_actioned_at=NOW() WHERE id=?")->execute([$user['id'],$id]);
        Auth::auditLog($user['id'],'leave_approved',"ID $id");
    }elseif($action==='reject'&&$id){
        $db->prepare("UPDATE leave_requests SET current_stage='rejected',hr_actioned_by=?,hr_actioned_at=NOW(),hr_comment=? WHERE id=?")->execute([$user['id'],($_POST['reason']??''),$id]);
        Auth::auditLog($user['id'],'leave_rejected',"ID $id");
    }
    header('Location: leave-queue.php?stage='.$stage);exit;
}

$leaves=$db->query("
    SELECT lr.*, u.name, u.email, u.role,
           a.name as actioned_by_name
    FROM leave_requests lr
    JOIN users u ON u.id=lr.user_id
    LEFT JOIN users a ON a.id=lr.hr_actioned_by
    $where
    ORDER BY lr.created_at DESC
")->fetchAll();

$counts=[
    'pending'=>$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage IN ('lm_review','hr_review','md_review')")->fetchColumn(),
    'approved'=>$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage='approved'")->fetchColumn(),
    'rejected'=>$db->query("SELECT COUNT(*) FROM leave_requests WHERE current_stage='rejected'")->fetchColumn(),
];

$typeColors=['annual'=>'#3b82f6','sick'=>'#dc2626','maternity'=>'#8b5cf6','paternity'=>'#0891b2','emergency'=>'#f97316','study'=>'#059669','unpaid'=>'#94a3b8'];

Layout::shell($user,'leave_queue',0,'Leave Queue');
?>
<div class="hri-page" style="max-width:1600px;">
<div class="hri-page-hd">
  <h1 class="hri-page-title">📋 Leave Approvals Queue</h1>
  <div style="font-size:13px;color:var(--g500);">
    <?=$counts['pending']?> pending &middot; <?=$counts['approved']?> approved &middot; <?=$counts['rejected']?> rejected
  </div>
</div>

<div style="display:flex;gap:6px;margin-bottom:16px;">
<?php foreach(['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'❌ Rejected','all'=>'📋 All'] as $s=>$lbl):?>
  <a href="?stage=<?=$s?>" style="padding:6px 14px;border-radius:4px;font-size:13px;text-decoration:none;
     background:<?=$stage===$s?'var(--navy)':'var(--g100)'?>;color:<?=$stage===$s?'#fff':'var(--g700)'?>;">
    <?=$lbl?><?php if(isset($counts[$s])):?> <span style="background:rgba(0,0,0,.15);border-radius:99px;padding:1px 6px;font-size:11px;"><?=$counts[$s]?></span><?php endif;?>
  </a>
<?php endforeach;?>
</div>

<?php if(empty($leaves)):?>
<div class="hri-card hri-empty">No <?=$stage==='all'?'':$stage?> leave requests.</div>
<?php else:?>
<div class="hri-card">
<?php foreach($leaves as $lr):
  $days=(strtotime($lr['end_date'])-strtotime($lr['start_date']))/86400+1;
  $tc=$typeColors[strtolower($lr['leave_type']??'')]??'var(--g500)';
  $stageLabel=['lm_review'=>'LM Review','hr_review'=>'HR Review','md_review'=>'MD Review','approved'=>'Approved','rejected'=>'Rejected'][$lr['current_stage']]??$lr['current_stage'];
  $stageColor=['lm_review'=>'#d97706','hr_review'=>'#d97706','md_review'=>'#d97706','approved'=>'#059669','rejected'=>'#dc2626'][$lr['current_stage']]??'var(--g500)';
?>
<div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-bottom:1px solid var(--g100);">
  <div style="flex:1;min-width:0;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
      <span style="font-size:14px;font-weight:600;color:var(--g900);"><?=htmlspecialchars($lr['name'])?></span>
      <span class="hri-pill" style="font-size:11px;background:<?=$tc?>20;color:<?=$tc?>;"><?=ucfirst($lr['leave_type'])?> Leave</span>
      <span class="hri-pill" style="font-size:11px;background:<?=$stageColor?>20;color:<?=$stageColor?>;"><?=$stageLabel?></span>
      <span style="font-size:12px;color:var(--g400);"><?=date('d M',strtotime($lr['start_date']))?> – <?=date('d M Y',strtotime($lr['end_date']))?> (<?=$days?> day<?=$days!=1?'s':''?>)</span>
    </div>
    <div style="font-size:12px;color:var(--g500);"><?=htmlspecialchars($lr['email'])?> &middot; Applied: <?=date('d M Y',strtotime($lr['created_at']))?></div>
    <?php if($lr['reason']):?><div style="font-size:12px;color:var(--g700);margin-top:4px;"><strong>Reason:</strong> <?=htmlspecialchars($lr['reason'])?></div><?php endif;?>
    <?php if($lr['hr_comment']&&$lr['current_stage']==='rejected'):?><div style="font-size:12px;color:var(--red);margin-top:4px;"><strong>Rejected:</strong> <?=htmlspecialchars($lr['hr_comment'])?></div><?php endif;?>
  </div>
  <?php if(in_array($lr['current_stage'],['lm_review','hr_review','md_review'])):?>
  <div style="display:flex;gap:6px;flex-shrink:0;">
    <form method="POST" style="display:inline;">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="approve"/>
      <input type="hidden" name="id" value="<?=$lr['id']?>"/>
      <button class="hri-btn hri-btn-navy" style="padding:4px 12px;font-size:12px;">Approve</button>
    </form>
    <form method="POST" style="display:inline;" onsubmit="var r=prompt('Rejection reason:');if(!r)return false;this.querySelector('[name=reason]').value=r;return true;">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="reject"/>
      <input type="hidden" name="id" value="<?=$lr['id']?>"/>
      <input type="hidden" name="reason" value=""/>
      <button class="hri-btn" style="padding:4px 12px;font-size:12px;background:#fee2e2;color:var(--red);border:none;">Reject</button>
    </form>
  </div>
  <?php endif;?>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
</div>
<?php Layout::end();?>