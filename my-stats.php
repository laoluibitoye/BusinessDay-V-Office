<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// my-stats.php — Personal usage statistics
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

// Last 30 days usage
$usage = $db->prepare("SELECT * FROM usage_stats WHERE user_id=? AND date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY) ORDER BY date DESC");
$usage->execute([$user['id']]);
$usageRows = $usage->fetchAll();

// Totals
$totals = $db->prepare("SELECT
    COALESCE(SUM(emails_sent),0) as total_sent,
    COALESCE(SUM(emails_read),0) as total_read,
    COALESCE(SUM(ai_summaries),0) as total_summaries,
    COALESCE(SUM(ai_replies),0) as total_replies,
    COALESCE(SUM(ai_compose),0) as total_compose,
    COALESCE(SUM(docs_signed),0) as total_signed,
    COALESCE(SUM(tasks_created),0) as total_tasks_created,
    COALESCE(SUM(tasks_completed),0) as total_tasks_completed,
    COALESCE(SUM(logins),0) as total_logins
    FROM usage_stats WHERE user_id=? AND date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
$totals->execute([$user['id']]);
$tot = $totals->fetch();

// Login history
$logins = $db->prepare("SELECT * FROM login_history WHERE user_id=? ORDER BY created_at DESC LIMIT 15");
$logins->execute([$user['id']]);
$loginHistory = $logins->fetchAll();

// Active sessions
$sessions = $db->prepare("SELECT * FROM sessions WHERE user_id=? AND expires_at > NOW() ORDER BY last_active DESC");
$sessions->execute([$user['id']]);
$activeSessions = $sessions->fetchAll();

Layout::shell($user, 'profile', 0, 'My Stats');
?>
<style>
.mspage{width:100%;max-width:1600px;margin:0 auto;padding:24px 16px 40px;overflow-y:auto;height:100%;}
.mspgt{font-size:17px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.mspgs{font-size:12.5px;color:var(--g400);margin-bottom:20px;}
.msscg{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;}
.mssc{background:var(--w);border-radius:11px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid var(--navy);}
.mssc.gn{border-top-color:var(--green);}.mssc.pu{border-top-color:#8b5cf6;}.mssc.or{border-top-color:#f97316;}.mssc.rd{border-top-color:var(--red);}
.sc-ico{font-size:18px;margin-bottom:5px;}
.sc-lbl{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g400);margin-bottom:4px;}
.sc-val{font-size:22px;font-weight:700;color:var(--g900);}
.sc-sub{font-size:11px;color:var(--g500);margin-top:3px;}
.msgrid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.mscard{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:16px;}
.mschd{padding:12px 18px;border-bottom:1px solid var(--g100);font-size:13px;font-weight:700;color:var(--navy);}
.mstbl{width:100%;border-collapse:collapse;}
.mstbl th{background:var(--g50);padding:8px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.mstbl td{padding:9px 14px;border-bottom:1px solid var(--g50);font-size:12.5px;color:var(--g700);vertical-align:middle;}
.mstbl tr:last-child td{border-bottom:none;}
.mspill{padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:600;display:inline-block;}
.mspill.ok{background:#dcfce7;color:#166534;}
.mspill.fail{background:#fee2e2;color:var(--red);}
.kill-btn{padding:3px 8px;border:1.5px solid var(--red);color:var(--red);border-radius:5px;font-size:10.5px;font-weight:700;cursor:pointer;background:transparent;font-family:inherit;}
.kill-btn:hover{background:var(--red);color:#fff;}
.msempty{padding:20px;text-align:center;color:var(--g400);font-size:13px;}
.mini-chart{display:flex;align-items:flex-end;gap:4px;height:60px;padding:14px 18px;}
.bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;}
.bar{width:100%;background:var(--navy);border-radius:3px 3px 0 0;min-height:2px;transition:height .3s;}
.bar-lbl{font-size:9px;color:var(--g400);}
@media(max-width:640px){.msscg{grid-template-columns:repeat(2,1fr);}.msgrid2{grid-template-columns:1fr;}}
</style>
<div class="mspage">
    <div class="mspgt">&#128202; My Usage &mdash; Last 30 Days</div>
    <div class="mspgs"><?= htmlspecialchars($user['name']) ?> &middot; <?= htmlspecialchars($user['email']) ?> &middot; <?= ROLES[$user['role']]['label'] ?></div>

    <div class="msscg">
        <div class="mssc"><div class="sc-ico">&#128228;</div><div class="sc-lbl">Emails Sent</div><div class="sc-val"><?= number_format($tot['total_sent']) ?></div><div class="sc-sub">Last 30 days</div></div>
        <div class="mssc gn"><div class="sc-ico">&#128139;</div><div class="sc-lbl">Emails Read</div><div class="sc-val"><?= number_format($tot['total_read']) ?></div><div class="sc-sub">Last 30 days</div></div>
        <div class="mssc pu"><div class="sc-ico">&#129302;</div><div class="sc-lbl">AI Assists</div><div class="sc-val"><?= number_format($tot['total_summaries']+$tot['total_replies']+$tot['total_compose']) ?></div><div class="sc-sub">Summaries, replies, compose</div></div>
        <div class="mssc or"><div class="sc-ico">&#9997;&#65039;</div><div class="sc-lbl">Docs Signed</div><div class="sc-val"><?= number_format($tot['total_signed']) ?></div><div class="sc-sub">Last 30 days</div></div>
    </div>
    <div class="msscg">
        <div class="mssc"><div class="sc-ico">&#9989;</div><div class="sc-lbl">Tasks Created</div><div class="sc-val"><?= number_format($tot['total_tasks_created']) ?></div></div>
        <div class="mssc gn"><div class="sc-ico">&#127937;</div><div class="sc-lbl">Tasks Completed</div><div class="sc-val"><?= number_format($tot['total_tasks_completed']) ?></div></div>
        <div class="mssc"><div class="sc-ico">&#128272;</div><div class="sc-lbl">Logins</div><div class="sc-val"><?= number_format($tot['total_logins']) ?></div></div>
        <div class="mssc rd"><div class="sc-ico">&#9889;</div><div class="sc-lbl">AI Summaries</div><div class="sc-val"><?= number_format($tot['total_summaries']) ?></div></div>
    </div>

    <?php if (!empty($usageRows)): ?>
    <div class="mscard" style="margin-bottom:16px;">
        <div class="mschd">&#128200; Emails Sent &mdash; Last <?= count($usageRows) ?> Days</div>
        <div class="mini-chart">
            <?php
            $maxSent = max(1, max(array_column($usageRows, 'emails_sent')));
            $recent  = array_reverse(array_slice($usageRows, 0, 14));
            foreach ($recent as $row):
                $h = max(2, round(($row['emails_sent'] / $maxSent) * 55));
            ?>
            <div class="bar-wrap">
                <div class="bar" style="height:<?= $h ?>px;" title="<?= $row['emails_sent'] ?> sent on <?= $row['date'] ?>"></div>
                <div class="bar-lbl"><?= date('d', strtotime($row['date'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="msgrid2">
        <div class="mscard">
            <div class="mschd">&#128272; Login History</div>
            <?php if (empty($loginHistory)): ?>
            <div class="msempty">No login history</div>
            <?php else: ?>
            <table class="mstbl">
                <thead><tr><th>Date &amp; Time</th><th>Device</th><th>IP</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($loginHistory as $l): ?>
                <tr>
                    <td><?= date('d M Y H:i', strtotime($l['created_at'])) ?></td>
                    <td><?= htmlspecialchars($l['device'] ?? '—') ?></td>
                    <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
                    <td><span class="mspill <?= $l['status']==='success'?'ok':'fail' ?>"><?= ucfirst($l['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="mscard">
            <div class="mschd">&#128241; Active Sessions</div>
            <?php if (empty($activeSessions)): ?>
            <div class="msempty">No other active sessions</div>
            <?php else: ?>
            <table class="mstbl">
                <thead><tr><th>Device</th><th>IP</th><th>Last Active</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($activeSessions as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['device'] ?? 'Unknown') ?></td>
                    <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($s['ip_address'] ?? '—') ?></td>
                    <td><?= date('d M H:i', strtotime($s['last_active'])) ?></td>
                    <td>
                        <form method="POST" action="api/auth/kill-session.php">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($s['token']) ?>"/>
                            <button type="submit" class="kill-btn">End</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php Layout::end(); ?>
