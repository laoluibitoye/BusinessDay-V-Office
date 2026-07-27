<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// admin/usage.php — System-wide usage analytics
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin']);
$db   = getDB();

// Today totals
$today = $db->query("SELECT
    COALESCE(SUM(emails_sent),0) as sent,
    COALESCE(SUM(emails_read),0) as read_,
    COALESCE(SUM(ai_summaries+ai_replies+ai_compose),0) as ai_total,
    COALESCE(SUM(docs_signed),0) as signed,
    COALESCE(SUM(logins),0) as logins
    FROM usage_stats WHERE date=CURDATE()")->fetch();

// Per-user today
$perUser = $db->query("SELECT u.name, u.role, us.*
    FROM usage_stats us JOIN users u ON u.id=us.user_id
    WHERE us.date=CURDATE() ORDER BY us.emails_sent DESC")->fetchAll();

// Last 7 days totals
$weekly = $db->query("SELECT date,
    SUM(emails_sent) as sent,
    SUM(ai_summaries+ai_replies+ai_compose) as ai,
    SUM(logins) as logins
    FROM usage_stats WHERE date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)
    GROUP BY date ORDER BY date DESC")->fetchAll();

// Session durations — last 30 days
$sessionLog = $db->query("
    SELECT u.name, u.role, u.avatar_color,
           s.created_at as login_at,
           COALESCE(s.last_active, s.created_at) as last_seen,
           CASE WHEN s.expires_at > NOW()
                 AND COALESCE(s.last_active,s.created_at) > DATE_SUB(NOW(), INTERVAL 2 HOUR)
                THEN 'active' ELSE 'ended' END as status,
           GREATEST(0, TIMESTAMPDIFF(MINUTE, s.created_at,
               COALESCE(s.last_active, s.expires_at))) as dur_min
    FROM sessions s
    JOIN users u ON u.id = s.user_id
    WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY s.created_at DESC
    LIMIT 300
")->fetchAll();

// Per-user total hours (last 30 days)
$userHours = $db->query("
    SELECT u.name, u.role, u.avatar_color,
           COUNT(*) as sessions,
           SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, s.created_at,
               COALESCE(s.last_active, s.expires_at)))) as total_min
    FROM sessions s
    JOIN users u ON u.id = s.user_id
    WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY u.id, u.name, u.role, u.avatar_color
    ORDER BY total_min DESC
")->fetchAll();

Layout::shell($user, 'usage', 0, 'Usage Analytics');
?>
<style>
.scg{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px;}
.sc{background:var(--w);border-radius:11px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid var(--navy);}
.sc.gn{border-top-color:var(--green);}.sc.pu{border-top-color:#8b5cf6;}.sc.or{border-top-color:#f97316;}
.sc-ico{font-size:18px;margin-bottom:5px;}
.sc-lbl{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g400);margin-bottom:3px;}
.sc-val{font-size:22px;font-weight:700;color:var(--g900);}
.card{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:16px;}
.chd{padding:12px 18px;border-bottom:1px solid var(--g100);font-size:13px;font-weight:700;color:var(--navy);}
.tbl{width:100%;border-collapse:collapse;}
.tbl th{background:var(--g50);padding:8px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.tbl td{padding:9px 14px;border-bottom:1px solid var(--g50);font-size:12.5px;color:var(--g700);}
.tbl tr:last-child td{border-bottom:none;}
.rbadge{padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:600;color:#fff;display:inline-block;}
.empty{padding:20px;text-align:center;color:var(--g400);font-size:13px;}
.pgt{font-size:17px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.pgs{font-size:12.5px;color:var(--g400);margin-bottom:18px;}
@media(max-width:640px){.scg{grid-template-columns:repeat(2,1fr);}}
@media(max-width:380px){.scg{grid-template-columns:1fr;}}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">
    <div class="pgt">📈 System Usage Analytics</div>
    <div class="pgs">Today · <?= date('d F Y') ?></div>
    <div class="scg">
        <div class="sc"><div class="sc-ico">📤</div><div class="sc-lbl">Emails Sent</div><div class="sc-val"><?= $today['sent'] ?></div></div>
        <div class="sc gn"><div class="sc-ico">📬</div><div class="sc-lbl">Emails Read</div><div class="sc-val"><?= $today['read_'] ?></div></div>
        <div class="sc pu"><div class="sc-ico">🤖</div><div class="sc-lbl">AI Assists</div><div class="sc-val"><?= $today['ai_total'] ?></div></div>
        <div class="sc or"><div class="sc-ico">✍️</div><div class="sc-lbl">Docs Signed</div><div class="sc-val"><?= $today['signed'] ?></div></div>
        <div class="sc"><div class="sc-ico">🔐</div><div class="sc-lbl">Logins</div><div class="sc-val"><?= $today['logins'] ?></div></div>
    </div>

    <!-- Per user today -->
    <div class="card" style="margin-bottom:16px;">
        <div class="chd">👥 Activity by Staff — Today</div>
        <?php if (empty($perUser)): ?>
        <div class="empty">No activity recorded today yet</div>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>Staff</th><th>Role</th><th>Sent</th><th>Read</th><th>AI</th><th>Signed</th><th>Logins</th></tr></thead>
            <tbody>
            <?php foreach ($perUser as $u):
                $rc = ROLES[$u['role']] ?? ['label'=>$u['role'],'color'=>'#64748b','icon'=>''];
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                <td><span class="rbadge" style="background:<?= $rc['color'] ?>"><?= $rc['icon'] ?> <?= $rc['label'] ?></span></td>
                <td><?= $u['emails_sent'] ?></td>
                <td><?= $u['emails_read'] ?></td>
                <td><?= $u['ai_summaries']+$u['ai_replies']+$u['ai_compose'] ?></td>
                <td><?= $u['docs_signed'] ?></td>
                <td><?= $u['logins'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- 7-day summary -->
    <div class="card">
        <div class="chd">📅 Last 7 Days Summary</div>
        <?php if (empty($weekly)): ?>
        <div class="empty">No data yet</div>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>Date</th><th>Emails Sent</th><th>AI Assists</th><th>Logins</th></tr></thead>
            <tbody>
            <?php foreach ($weekly as $w): ?>
            <tr>
                <td><?= date('D, d M Y', strtotime($w['date'])) ?></td>
                <td><?= $w['sent'] ?></td>
                <td><?= $w['ai'] ?></td>
                <td><?= $w['logins'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Per-user total hours -->
    <div class="card" style="margin-top:16px;">
        <div class="chd">🕐 Staff Login Time — Last 30 Days</div>
        <?php if (empty($userHours)): ?>
        <div class="empty">No session data</div>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>Staff</th><th>Role</th><th>Sessions</th><th>Total Time Online</th><th>Avg per Session</th></tr></thead>
            <tbody>
            <?php foreach ($userHours as $uh):
                $rc      = ROLES[$uh['role']] ?? ['label'=>$uh['role'],'color'=>'#64748b','icon'=>''];
                $totMin  = (int)$uh['total_min'];
                $totH    = floor($totMin / 60);
                $totM    = $totMin % 60;
                $avgMin  = $uh['sessions'] > 0 ? round($totMin / $uh['sessions']) : 0;
                $avgH    = floor($avgMin / 60);
                $avgM    = $avgMin % 60;
                $totStr  = $totH > 0 ? "{$totH}h {$totM}m" : "{$totM}m";
                $avgStr  = $avgH > 0 ? "{$avgH}h {$avgM}m" : "{$avgM}m";
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($uh['name']) ?></strong></td>
                <td><span class="rbadge" style="background:<?= $rc['color'] ?>"><?= $rc['icon'] ?> <?= $rc['label'] ?></span></td>
                <td><?= $uh['sessions'] ?></td>
                <td><strong><?= $totStr ?></strong></td>
                <td style="color:var(--g500);"><?= $avgStr ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Detailed session log -->
    <div class="card" style="margin-top:16px;">
        <div class="chd">📋 Session Log — Last 30 Days (most recent first)</div>
        <?php if (empty($sessionLog)): ?>
        <div class="empty">No sessions found</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="tbl">
            <thead><tr><th>Staff</th><th>Role</th><th>Logged In</th><th>Last Active</th><th>Duration</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($sessionLog as $sl):
                $rc     = ROLES[$sl['role']] ?? ['label'=>$sl['role'],'color'=>'#64748b','icon'=>''];
                $durMin = (int)$sl['dur_min'];
                $durH   = floor($durMin / 60);
                $durM   = $durMin % 60;
                $durStr = $durH > 0 ? "{$durH}h {$durM}m" : ($durM > 0 ? "{$durM}m" : '<1m');
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($sl['name']) ?></strong></td>
                <td><span class="rbadge" style="background:<?= $rc['color'] ?>"><?= $rc['icon'] ?> <?= $rc['label'] ?></span></td>
                <td style="white-space:nowrap;"><?= date('d M Y, H:i', strtotime($sl['login_at'])) ?></td>
                <td style="white-space:nowrap;color:var(--g500);"><?= date('d M Y, H:i', strtotime($sl['last_seen'])) ?></td>
                <td><strong><?= $durStr ?></strong></td>
                <td>
                    <?php if ($sl['status'] === 'active'): ?>
                    <span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;">&#9679; Active</span>
                    <?php else: ?>
                    <span style="background:var(--g100);color:var(--g500);padding:2px 10px;border-radius:20px;font-size:11px;">Ended</span>
                    <?php endif; ?>
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
