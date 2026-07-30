<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// visitors.php — Visitor Log (Front Desk)
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_cso','front_desk']);
$db   = getDB();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_visitor'])) {
    $name    = trim($_POST['visitor_name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $host    = (int)($_POST['host_id'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $badge   = trim($_POST['badge_no'] ?? '');

    if (!$name || !$host) { $error = 'Visitor name and host are required.'; }
    else {
        $db->prepare("INSERT INTO visitors (name, company, phone, host_id, purpose, badge_no, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$name, $company, $phone, $host, $purpose, $badge, $user['id']]);
        $success = "Visitor \"$name\" logged successfully.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $vid = (int)$_POST['visitor_id'];
    $db->prepare("UPDATE visitors SET time_out=NOW() WHERE id=?")->execute([$vid]);
    $success = 'Visitor checked out.';
}

$today = $db->query("SELECT v.*, u.name as host_name FROM visitors v LEFT JOIN users u ON u.id=v.host_id WHERE DATE(v.time_in)=CURDATE() ORDER BY v.time_in DESC")->fetchAll();
$allStaff = $db->query("SELECT id, name FROM users WHERE is_active=1 ORDER BY name")->fetchAll();
$filterDate = $_GET['date'] ?? '';
$historyQ = $filterDate
    ? $db->prepare("SELECT v.*, u.name as host_name FROM visitors v LEFT JOIN users u ON u.id=v.host_id WHERE DATE(v.time_in)=? AND DATE(v.time_in)<>CURDATE() ORDER BY v.time_in DESC LIMIT 100")
    : $db->prepare("SELECT v.*, u.name as host_name FROM visitors v LEFT JOIN users u ON u.id=v.host_id WHERE DATE(v.time_in)<>CURDATE() ORDER BY v.time_in DESC LIMIT 50");
if ($filterDate) $historyQ->execute([$filterDate]); else $historyQ->execute();
$history = $historyQ->fetchAll();
$totalVisitors = (int)$db->query("SELECT COUNT(*) FROM visitors")->fetchColumn();

Layout::shell($user, 'visitors', 0, 'Visitor Log');
?>
<style>
.vpage{width:100%;max-width:1600px;margin:0 auto;padding:24px 16px 40px;display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;overflow-y:auto;height:100%;min-height:0;}
.vcard{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
.vchd{padding:13px 18px;border-bottom:1px solid var(--g100);font-size:13.5px;font-weight:700;color:var(--navy);display:flex;align-items:center;justify-content:space-between;}
.vtbl{width:100%;border-collapse:collapse;}
.vtbl th{background:var(--g50);padding:9px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.vtbl td{padding:11px 14px;border-bottom:1px solid var(--g50);font-size:13px;color:var(--g700);vertical-align:middle;}
.vtbl tr:last-child td{border-bottom:none;}
.vpill{padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;display:inline-block;}
.vpill.in{background:#dcfce7;color:#166534;}.vpill.out{background:var(--g100);color:var(--g500);}
.vbtn-sm{padding:4px 10px;border-radius:6px;border:1.5px solid var(--g200);background:transparent;color:var(--g700);font-size:11.5px;cursor:pointer;font-family:inherit;}
.vbtn-sm:hover{border-color:var(--navy);color:var(--navy);}
.valert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;}
.valert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.valert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.vfg{margin-bottom:12px;}
.vfg label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
.vfg input,.vfg select,.vfg textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:inherit;color:var(--g900);outline:none;background:var(--g50);}
.vfg input:focus,.vfg select:focus{border-color:var(--green);background:#fff;}
.vfg textarea{resize:none;height:60px;}
.vbtn{padding:9px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;background:var(--green);color:#fff;width:100%;display:flex;align-items:center;justify-content:center;gap:6px;}
.vbtn:hover{background:#4d7c10;}
.vempty{padding:24px;text-align:center;color:var(--g400);font-size:13px;}
.vhist-wrap{margin-top:18px;}
.vhist-hd{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--g100);}
@media(max-width:640px){.vpage{grid-template-columns:1fr;}}
@media(max-width:768px){.vtbl{font-size:11.5px;}.vtbl th,.vtbl td{padding:7px 8px;}}
/* mobile-wrapper-fix */
@media(max-width:768px){
    .vpage{height:auto;overflow:visible;padding:14px 12px 40px;}
}
/* mobile-all-pages */
@media(max-width:768px){
}
</style>
<div class="vpage">
    <div>
        <?php if ($success): ?><div class="valert ok">&#10003; <?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="valert er">&#9888; <?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="vcard">
            <div class="vchd">
                &#127962; Today's Visitors
                <span style="font-size:12px;color:var(--g400);"><?= count($today) ?> visitors</span>
            </div>
            <?php if (empty($today)): ?>
            <div class="vempty">No visitors logged today</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="vtbl">
                <thead><tr><th>Visitor</th><th>Company</th><th>Hosting</th><th>Purpose</th><th>Time In</th><th>Time Out</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($today as $v): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($v['name']) ?></strong><?php if($v['phone']): ?><br/><span style="font-size:11.5px;color:var(--g400);"><?= htmlspecialchars($v['phone']) ?></span><?php endif; ?></td>
                    <td><?= htmlspecialchars($v['company'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($v['host_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(substr($v['purpose'] ?? '',0,40)) ?></td>
                    <td><?= date('H:i', strtotime($v['time_in'])) ?></td>
                    <td><?= $v['time_out'] ? date('H:i', strtotime($v['time_out'])) : '—' ?></td>
                    <td><span class="vpill <?= $v['time_out']?'out':'in' ?>"><?= $v['time_out']?'Checked Out':'In Building' ?></span></td>
                    <td>
                        <?php if (!$v['time_out']): ?>
                        <form method="POST" style="margin:0;">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="visitor_id" value="<?= $v['id'] ?>"/>
                            <button type="submit" name="checkout" class="vbtn-sm">Check Out</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Visitor History -->
        <div class="vcard vhist-wrap">
            <div class="vhist-hd">
                <span style="font-size:13.5px;font-weight:700;color:var(--navy);">&#128198; Visitor History</span>
                <form method="GET" style="display:flex;align-items:center;gap:8px;margin-left:auto;">
                    <input type="date" name="date" value="<?=htmlspecialchars($filterDate)?>"
                           style="border:1.5px solid var(--g200);border-radius:6px;padding:5px 9px;font-size:12px;font-family:inherit;color:var(--g700);"
                           onchange="this.form.submit()"/>
                    <?php if($filterDate): ?><a href="visitors.php" style="font-size:12px;color:var(--red);">&#10005; Clear</a><?php endif; ?>
                </form>
                <span style="font-size:12px;color:var(--g400);"><?=count($history)?> records<?=$filterDate?' on '.date('d M Y',strtotime($filterDate)):(count($history)==50?' (last 50)':'')?>
                </span>
            </div>
            <?php if(empty($history)): ?>
            <div class="vempty">No past visitor records<?=$filterDate?' for this date':''?></div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="vtbl">
                <thead><tr><th>Visitor</th><th>Company</th><th>Hosting</th><th>Purpose</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach($history as $v): ?>
                <tr>
                    <td><strong><?=htmlspecialchars($v['name'])?></strong><?php if($v['phone']): ?><br/><span style="font-size:11.5px;color:var(--g400);"><?=htmlspecialchars($v['phone'])?></span><?php endif; ?></td>
                    <td><?=htmlspecialchars($v['company']??'—')?></td>
                    <td><?=htmlspecialchars($v['host_name']??'—')?></td>
                    <td><?=htmlspecialchars(substr($v['purpose']??'',0,35))?></td>
                    <td style="white-space:nowrap;"><?=date('d M Y',strtotime($v['time_in']))?></td>
                    <td><?=date('H:i',strtotime($v['time_in']))?></td>
                    <td><?=$v['time_out']?date('H:i',strtotime($v['time_out'])):'—'?></td>
                    <td><span class="vpill <?=$v['time_out']?'out':'in'?>"><?=$v['time_out']?'Left':'In Building'?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div>
        <div class="vcard" style="margin-bottom:14px;">
            <div class="vchd">&#128202; Log Stats</div>
            <div style="padding:12px 18px;display:flex;gap:20px;flex-wrap:wrap;">
                <div style="text-align:center;"><div style="font-size:22px;font-weight:800;color:var(--navy);"><?=count($today)?></div><div style="font-size:11px;color:var(--g400);font-weight:600;text-transform:uppercase;">Today</div></div>
                <div style="text-align:center;"><div style="font-size:22px;font-weight:800;color:var(--navy);"><?=$totalVisitors?></div><div style="font-size:11px;color:var(--g400);font-weight:600;text-transform:uppercase;">All Time</div></div>
            </div>
        </div>
        <div class="vcard" style="border-top:3px solid var(--green);">
            <div class="vchd">&#10133; Log New Visitor</div>
            <div style="padding:16px 18px;">
                <form method="POST">
                    <?= Auth::csrfField() ?>
                    <div class="vfg"><label>Visitor Name *</label><input type="text" name="visitor_name" placeholder="Full name" required/></div>
                    <div class="vfg"><label>Company</label><input type="text" name="company" placeholder="Company or organisation"/></div>
                    <div class="vfg"><label>Phone</label><input type="text" name="phone" placeholder="Mobile number"/></div>
                    <div class="vfg"><label>Hosting Staff *</label>
                        <select name="host_id" required>
                            <option value="">Who are they visiting?</option>
                            <?php foreach ($allStaff as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vfg"><label>Purpose of Visit</label><textarea name="purpose" placeholder="Reason for visit…"></textarea></div>
                    <div class="vfg"><label>Badge Number</label><input type="text" name="badge_no" placeholder="If applicable"/></div>
                    <button type="submit" name="log_visitor" class="vbtn">&#10133; Log Visitor</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php Layout::end(); ?>
