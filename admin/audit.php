<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// admin/audit.php — Full audit log
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin']);
$db   = getDB();

$search = trim($_GET['q'] ?? '');
$uid    = (int)($_GET['uid'] ?? 0);
$page   = max(1,(int)($_GET['p'] ?? 1));
$perPage= 30;

$where  = '1=1';
$params = [];
if ($search) { $where .= " AND (al.action LIKE ? OR al.detail LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($uid)    { $where .= " AND al.user_id = ?"; $params[] = $uid; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_log al WHERE $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $perPage);
$offset = ($page-1)*$perPage;

$logStmt = $db->prepare("SELECT al.*, u.name as user_name FROM audit_log al LEFT JOIN users u ON u.id=al.user_id WHERE $where ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset");
$logStmt->execute($params);
$logs = $logStmt->fetchAll();

$allStaff = $db->query("SELECT id, name FROM users WHERE is_active=1 ORDER BY name")->fetchAll();

Layout::shell($user, 'audit', 0, 'Audit Log');
?>
<style>
.filter-bar{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;}
.fi{border:1.5px solid var(--g200);border-radius:8px;padding:8px 12px;font-size:13px;font-family:'Inter',sans-serif;outline:none;color:var(--g900);background:var(--w);}
.fi:focus{border-color:var(--green);}
.card{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.tbl{width:100%;border-collapse:collapse;}
.tbl th{background:var(--g50);padding:9px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.tbl td{padding:9px 14px;border-bottom:1px solid var(--g50);font-size:12.5px;color:var(--g700);vertical-align:top;}
.tbl tr:last-child td{border-bottom:none;}
.act-badge{padding:2px 8px;border-radius:5px;font-size:10.5px;font-weight:600;background:#e8f0fb;color:var(--navy);display:inline-block;font-family:monospace;}
.pgn{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--g100);}
.pgn-info{font-size:12px;color:var(--g400);}
.pgn-btns{display:flex;gap:5px;}
.pgn-btn{padding:4px 10px;border-radius:6px;border:1.5px solid var(--g200);background:var(--w);color:var(--g700);font-size:12px;cursor:pointer;text-decoration:none;font-family:'Inter',sans-serif;}
.pgn-btn:hover{border-color:var(--navy);color:var(--navy);}
.pgn-btn.on{background:var(--navy);color:#fff;border-color:var(--navy);}
.pgt{font-size:17px;font-weight:700;color:var(--navy);margin-bottom:14px;}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">
    <div class="pgt">📋 System Audit Log</div>
    <form method="GET">
        <div class="filter-bar">
            <input class="fi" type="text" name="q" placeholder="Search action or detail…" value="<?= htmlspecialchars($search) ?>" style="flex:1;min-width:200px;"/>
            <select class="fi" name="uid">
                <option value="">All Staff</option>
                <?php foreach ($allStaff as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $uid==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" style="padding:8px 16px;border-radius:8px;background:var(--navy);color:#fff;border:none;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">Filter</button>
            <a href="audit.php" style="padding:8px 13px;border-radius:8px;border:1.5px solid var(--g200);color:var(--g700);font-size:13px;text-decoration:none;">Reset</a>
        </div>
    </form>
    <div class="card">
        <table class="tbl">
            <thead><tr><th>Date & Time</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td style="white-space:nowrap;color:var(--g400);font-size:12px;"><?= date('d M Y H:i:s', strtotime($log['created_at'])) ?></td>
                <td style="font-weight:500;"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                <td><span class="act-badge"><?= htmlspecialchars($log['action']) ?></span></td>
                <td><?= htmlspecialchars($log['detail'] ?? '—') ?></td>
                <td style="font-family:monospace;font-size:11.5px;color:var(--g400);"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?><tr><td colspan="5" style="text-align:center;padding:24px;color:var(--g400);">No audit entries found</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div class="pgn">
            <div class="pgn-info"><?= number_format($total) ?> total entries · Page <?= $page ?> of <?= $totalPages ?></div>
            <div class="pgn-btns">
                <?php if ($page>1): ?><a class="pgn-btn" href="?p=<?= $page-1 ?>&q=<?= urlencode($search) ?>&uid=<?= $uid ?>">← Prev</a><?php endif; ?>
                <span class="pgn-btn on"><?= $page ?></span>
                <?php if ($page<$totalPages): ?><a class="pgn-btn" href="?p=<?= $page+1 ?>&q=<?= urlencode($search) ?>&uid=<?= $uid ?>">Next →</a><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php Layout::end(); ?>
