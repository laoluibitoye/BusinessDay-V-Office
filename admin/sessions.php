<?php
if(ob_get_level()===0)ob_start();
if(session_status()===PHP_SESSION_NONE)session_start();
// admin/sessions.php — Active Sessions Viewer
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';
$user=Auth::requireRole(['md','bdm','head_it','it_admin']);
$db=getDB();
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['kill'])){
    $token=$_POST['kill'];
    if($token!==($_SESSION['token']??'')){$db->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);Auth::auditLog($user['id'],'session_killed','Token:'.substr($token,0,8));}
    header('Location: sessions.php');exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['kill_all'])){
    $db->prepare("DELETE FROM sessions WHERE user_id!=?")->execute([$user['id']]);
    Auth::auditLog($user['id'],'sessions_killed_all','All terminated');
    header('Location: sessions.php');exit;
}
$sessions=$db->query("
    SELECT s.*, u.email, u.name, u.role,
           TIMESTAMPDIFF(MINUTE,s.last_active,NOW()) as idle_mins,
           TIMESTAMPDIFF(MINUTE,s.created_at,NOW()) as age_mins
    FROM sessions s JOIN users u ON u.id=s.user_id
    WHERE s.expires_at>NOW() ORDER BY s.last_active DESC
")->fetchAll();
$myToken=$_SESSION['token']??'';
Layout::shell($user,'sessions',0,'Active Sessions');
?>
<div class="hri-page" style="max-width:920px;">
<div class="hri-page-hd">
  <h1 class="hri-page-title">🖥️ Active Sessions</h1>
  <form method="POST" onsubmit="return confirm('Kill ALL other sessions?')">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="kill_all" value="1"/>
    <button class="hri-btn" style="background:#fee2e2;color:var(--red);border:none;">⚠️ Kill All Other Sessions</button>
  </form>
</div>
<div class="hri-card">
  <div class="hri-card-hd"><span class="hri-card-title"><?=count($sessions)?> Active Session<?=count($sessions)!=1?'s':''?></span>
  <span style="font-size:12px;color:var(--g400);">Auto-refreshes every 30s</span></div>
<?php if(empty($sessions)):?>
  <div class="hri-empty">No active sessions found.</div>
<?php else:?>
  <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">
  <thead><tr style="background:var(--g50);border-bottom:2px solid var(--g200);">
    <th style="padding:10px 14px;text-align:left;color:var(--g600);">User</th>
    <th style="padding:10px 14px;text-align:left;color:var(--g600);">Device</th>
    <th style="padding:10px 14px;text-align:left;color:var(--g600);">IP Address</th>
    <th style="padding:10px 14px;text-align:left;color:var(--g600);">Last Active</th>
    <th style="padding:10px 14px;text-align:left;color:var(--g600);">Idle</th>
    <th style="padding:10px 14px;text-align:left;color:var(--g600);">Age</th>
    <th style="padding:10px 14px;text-align:left;color:var(--g600);">Action</th>
  </tr></thead><tbody>
<?php foreach($sessions as $s):
  $isMe=($s['token']===$myToken);
  $idle=(int)$s['idle_mins'];
  $idleColor=$idle<5?'var(--green)':($idle<30?'var(--g600)':'var(--red)');
  $staleLabel=$idle>=60?'<span style="font-size:10px;padding:1px 6px;border-radius:99px;background:#fee2e2;color:#dc2626;font-weight:700;margin-left:4px;">STALE</span>':'';
  $age=(int)$s['age_mins'];
  $ageStr=$age<60?$age.'m':($age<1440?round($age/60).'h':round($age/1440).'d');
  $roleLabel=ROLES[$s['role']]['label']??$s['role'];
  $deviceIcon=$s['device']==='mobile'?'📱':($s['device']==='tablet'?'📲':'🖥️');
?>
  <tr style="border-bottom:1px solid var(--g100);<?=$isMe?'background:var(--g50);':''?>">
    <td style="padding:10px 14px;">
      <div style="font-weight:600;color:var(--g900);"><?=htmlspecialchars($s['name'])?><?php if($isMe):?> <span class="hri-pill hri-pill-info" style="font-size:10px;">You</span><?php endif;?></div>
      <div style="font-size:11px;color:var(--g500);"><?=htmlspecialchars($s['email'])?> &middot; <?=$roleLabel?></div>
    </td>
    <td style="padding:10px 14px;"><?=$deviceIcon?> <?=ucfirst($s['device']??'desktop')?></td>
    <td style="padding:10px 14px;font-family:monospace;font-size:12px;"><?=htmlspecialchars($s['ip_address']??'—')?></td>
    <td style="padding:10px 14px;color:var(--g600);"><?=date('d M H:i',strtotime($s['last_active']))?></td>
    <td style="padding:10px 14px;font-weight:600;color:<?=$idleColor?>"><?=$idle<1?'Just now':($idle>=60?round($idle/60).'h '.($idle%60).'m ago':$idle.'m ago')?><?=$staleLabel?></td>
    <td style="padding:10px 14px;color:var(--g500);"><?=$ageStr?></td>
    <td style="padding:10px 14px;">
<?php if(!$isMe):?>
      <form method="POST" onsubmit="return confirm('Kill this session?')" style="display:inline;">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="kill" value="<?=htmlspecialchars($s['token'])?>"/>
        <button class="hri-btn" style="padding:4px 10px;font-size:12px;background:#fee2e2;color:var(--red);border:none;">Kill</button>
      </form>
<?php else:?><span style="color:var(--g400);font-size:12px;">Current</span><?php endif;?>
    </td>
  </tr>
<?php endforeach;?>
  </tbody></table></div>
<?php endif;?>
</div>
<div style="font-size:12px;color:var(--g400);margin-top:12px;text-align:center;">
  Sessions expire after <?=SESSION_LIFETIME/3600?>h of inactivity &middot; <?=date('H:i:s')?>
</div>
</div>
<script>setTimeout(function(){location.reload();},30000);</script>
<?php Layout::end();?>