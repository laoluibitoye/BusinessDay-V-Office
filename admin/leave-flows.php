<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['head_it','it_admin','md','bdm']);
$db   = getDB();

$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['requester_role'] ?? '';
    if ($role && array_key_exists($role, ROLES)) {
        $s1 = trim($_POST['stage1'] ?? '') ?: null;
        $s2 = trim($_POST['stage2'] ?? '') ?: null;
        $s3 = trim($_POST['stage3'] ?? '') ?: null;
        if ($s1 && !array_key_exists($s1, ROLES)) $s1 = null;
        if ($s2 && !array_key_exists($s2, ROLES)) $s2 = null;
        if ($s3 && !array_key_exists($s3, ROLES)) $s3 = null;
        try {
            $db->prepare("INSERT INTO leave_flow_rules
                    (requester_role, stage1_approver, stage2_approver, stage3_approver, updated_by)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    stage1_approver=VALUES(stage1_approver),
                    stage2_approver=VALUES(stage2_approver),
                    stage3_approver=VALUES(stage3_approver),
                    updated_by=VALUES(updated_by),
                    updated_at=NOW()")
               ->execute([$role, $s1, $s2, $s3, $user['id']]);
            Auth::auditLog($user['id'], 'leave_flow_updated', "Leave approval flow for $role updated");
            $msg = 'Flow for ' . (ROLES[$role]['label'] ?? $role) . ' saved.';
        } catch (PDOException $e) {
            $msg     = 'Error saving: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

$flows = [];
try {
    foreach ($db->query("SELECT * FROM leave_flow_rules")->fetchAll() as $row) {
        $flows[$row['requester_role']] = $row;
    }
} catch (PDOException $e) {
    $msg     = 'Table not found. Run database/leave_flows_migration.sql in phpMyAdmin first.';
    $msgType = 'error';
}

Layout::shell($user, 'leave_approvals', 0, 'Leave Flow Configuration');
?>
<style>
.lf-table{width:100%;border-collapse:collapse;}
.lf-table th{background:var(--g50);padding:9px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.lf-row{border-bottom:1px solid var(--g50);padding:12px 18px;}
.lf-row:last-child{border-bottom:none;}
.lf-role{min-width:170px;}
.lf-select{font-size:12px;padding:5px 8px;border-radius:7px;border:1.5px solid var(--g200);background:var(--g50);color:var(--g900);min-width:155px;font-family:'Inter',sans-serif;}
.lf-select:focus{outline:none;border-color:var(--green);background:var(--w);}
.arr{color:var(--g400);font-size:14px;flex-shrink:0;}
.chain-prev{font-size:11.5px;color:var(--g500);margin-top:6px;padding-left:2px;}
.chain-role{font-weight:600;color:var(--navy);}
.chain-auto{color:var(--g400);font-style:italic;}
.info-box{background:#f0f9ff;border-left:4px solid #0891b2;border-radius:0 10px 10px 0;padding:13px 16px;margin-bottom:16px;}
.info-box strong{color:#0e7490;}
.info-box p{font-size:12.5px;color:#0e7490;margin:4px 0 0;line-height:1.6;}
.msg-ok{background:#dcfce7;color:#166534;border-radius:9px;padding:11px 16px;font-size:13px;margin-bottom:14px;}
.msg-err{background:#fee2e2;color:var(--red);border-radius:9px;padding:11px 16px;font-size:13px;margin-bottom:14px;}
@media(max-width:700px){.lf-controls{flex-direction:column;}.lf-role{min-width:0;width:100%;}}
/* mobile-all-pages */
@media(max-width:768px){
    [class*='-grid'],[class*='-row']{grid-template-columns:1fr!important;}
}
</style>
<div class="hri-page" style="max-width:1600px;">
    <div class="hri-page-hd">
        <h1 class="hri-page-title">&#128260; Leave Approval Flow</h1>
        <a href="../leave-approvals.php" class="hri-btn hri-btn-outline">&#8592; Back to Approvals</a>
    </div>

    <?php if ($msg): ?>
    <div class="<?= $msgType==='error'?'msg-err':'msg-ok' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="info-box">
        <strong>&#9432; How the flow works</strong>
        <p>
            For each requester role, set up to 3 approver stages in order.<br>
            <strong>Stage 1</strong> = first approver (e.g. Line Manager) &rarr;
            <strong>Stage 2</strong> = second approver (e.g. HR) &rarr;
            <strong>Stage 3</strong> = final approver (e.g. MD).<br>
            Leave a stage blank to skip it. If all three are blank, leave is <em>auto-approved</em>.
        </p>
    </div>

    <div class="hri-card" style="overflow-x:auto;">
        <?php foreach (ROLES as $roleKey => $roleInfo):
            $f  = $flows[$roleKey] ?? ['stage1_approver'=>null,'stage2_approver'=>null,'stage3_approver'=>null];
            $s1 = $f['stage1_approver'] ?? '';
            $s2 = $f['stage2_approver'] ?? '';
            $s3 = $f['stage3_approver'] ?? '';

            $chain = array_filter([$s1,$s2,$s3]);
            if (empty($chain)) {
                $chainHtml = '<span class="chain-auto">Auto-approved on submission</span>';
            } else {
                $parts = ['<span class="chain-role">'.htmlspecialchars($roleInfo['label']).'</span>'];
                foreach ($chain as $r) {
                    $rl = ROLES[$r]['label'] ?? $r;
                    $parts[] = '<span class="chain-role">'.htmlspecialchars($rl).'</span>';
                }
                $chainHtml = implode(' <span class="arr">&#8594;</span> ', $parts);
            }
        ?>
        <form method="POST" class="lf-row">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="requester_role" value="<?= $roleKey ?>"/>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;" class="lf-controls">
                <div class="lf-role">
                    <span style="background:<?= $roleInfo['color'] ?>;color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap;display:inline-block;">
                        <?= $roleInfo['icon'] ?> <?= htmlspecialchars($roleInfo['label']) ?>
                    </span>
                </div>
                <select name="stage1" class="lf-select">
                    <option value="">&#8212; None (skip) &#8212;</option>
                    <?php foreach (ROLES as $rk => $ri): if ($rk===$roleKey) continue; ?>
                    <option value="<?= $rk ?>" <?= $s1===$rk?'selected':'' ?>><?= $ri['icon'] ?> <?= htmlspecialchars($ri['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="arr">&#8594;</span>
                <select name="stage2" class="lf-select">
                    <option value="">&#8212; None (skip) &#8212;</option>
                    <?php foreach (ROLES as $rk => $ri): if ($rk===$roleKey) continue; ?>
                    <option value="<?= $rk ?>" <?= $s2===$rk?'selected':'' ?>><?= $ri['icon'] ?> <?= htmlspecialchars($ri['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="arr">&#8594;</span>
                <select name="stage3" class="lf-select">
                    <option value="">&#8212; None (skip) &#8212;</option>
                    <?php foreach (ROLES as $rk => $ri): if ($rk===$roleKey) continue; ?>
                    <option value="<?= $rk ?>" <?= $s3===$rk?'selected':'' ?>><?= $ri['icon'] ?> <?= htmlspecialchars($ri['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="hri-btn hri-btn-navy" style="padding:5px 14px;font-size:12px;white-space:nowrap;">Save</button>
            </div>
            <div class="chain-prev">Flow: <?= $chainHtml ?></div>
        </form>
        <?php endforeach; ?>
    </div>
</div>
<?php Layout::end(); ?>
