<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// admin/announcements.php — Company Announcements
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_outsourcing','head_training','head_cso']);
$db   = getDB();

// Create table if needed
try {
    $db->exec("CREATE TABLE IF NOT EXISTS announcements (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        title       VARCHAR(300) NOT NULL,
        body        TEXT NOT NULL,
        priority    ENUM('normal','high','urgent') DEFAULT 'normal',
        target_role VARCHAR(50) DEFAULT 'all',
        is_active   TINYINT(1) DEFAULT 1,
        expires_at  DATETIME,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
// Add columns that may be missing if table was created from schema.sql
foreach ([
    "ALTER TABLE announcements ADD COLUMN IF NOT EXISTS user_id INT NULL",
    "ALTER TABLE announcements ADD COLUMN IF NOT EXISTS body TEXT NULL",
    "ALTER TABLE announcements ADD COLUMN IF NOT EXISTS priority ENUM('normal','high','urgent') DEFAULT 'normal'",
    "ALTER TABLE announcements ADD COLUMN IF NOT EXISTS target_role VARCHAR(50) DEFAULT 'all'",
    "ALTER TABLE announcements MODIFY COLUMN created_by INT UNSIGNED NULL",
    "ALTER TABLE announcements MODIFY COLUMN message TEXT NULL",
    "UPDATE announcements SET user_id=created_by WHERE user_id IS NULL",
    "UPDATE announcements SET body=message WHERE body IS NULL AND message IS NOT NULL",
    "UPDATE announcements SET target_role=target_roles WHERE target_role IS NULL",
] as $_sql) { try { $db->exec($_sql); } catch (Exception $_e) {} }
unset($_sql, $_e);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title    = Auth::sanitiseString($_POST['title'] ?? '', 300);
        $body     = $_POST['body'] ?? '';
        $priority = in_array($_POST['priority']??'', ['normal','high','urgent']) ? $_POST['priority'] : 'normal';
        $target   = Auth::sanitiseString($_POST['target_role'] ?? 'all', 50);
        $expires  = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        if ($title && $body) {
            $db->prepare("INSERT INTO announcements (user_id,title,body,priority,target_role,expires_at) VALUES (?,?,?,?,?,?)")
               ->execute([$user['id'],$title,$body,$priority,$target,$expires]);
            Auth::auditLog($user['id'],'announcement_created',$title);
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE announcements SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM announcements WHERE id=?")->execute([$id]);
    }
    header('Location: announcements.php');
    exit;
}

$announcements = $db->query("
    SELECT a.*, u.name as author
    FROM announcements a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.created_at DESC
")->fetchAll();

$priorityColors = ['normal'=>'var(--g500)', 'high'=>'#d97706', 'urgent'=>'var(--red)'];
$priorityIcons  = ['normal'=>'📢', 'high'=>'⚠️', 'urgent'=>'🚨'];

Layout::shell($user, 'announcements', 0, 'Announcements');
?>
<div class="hri-page" style="max-width:900px;">
    <div class="hri-page-hd">
        <h1 class="hri-page-title">📢 Company Announcements</h1>
    </div>

    <style>.ann-grid{display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;}@media(max-width:640px){.ann-grid{grid-template-columns:1fr;}}</style>
    <div class="ann-grid">

        <!-- Announcement List -->
        <div>
            <?php if (empty($announcements)): ?>
            <div class="hri-card hri-empty">No announcements yet. Create one →</div>
            <?php else: ?>
            <?php foreach ($announcements as $ann): ?>
            <?php
                $expired = $ann['expires_at'] && strtotime($ann['expires_at']) < time();
                $active  = $ann['is_active'] && !$expired;
            ?>
            <div class="hri-card" style="margin-bottom:12px;padding:16px;opacity:<?=$active?'1':'0.6'?>;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                    <div style="flex:1;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <span style="font-size:16px;"><?=$priorityIcons[$ann['priority']]?></span>
                            <span style="font-size:14px;font-weight:700;color:var(--g900);"><?=htmlspecialchars($ann['title'])?></span>
                            <span class="hri-pill" style="font-size:10px;background:<?=$priorityColors[$ann['priority']]?>20;color:<?=$priorityColors[$ann['priority']]?>;">
                                <?=ucfirst($ann['priority'])?>
                            </span>
                            <?php if (!$active): ?>
                            <span class="hri-pill" style="font-size:10px;background:var(--g100);color:var(--g500);">
                                <?=$expired?'Expired':'Inactive'?>
                            </span>
                            <?php else: ?>
                            <span class="hri-pill hri-pill-success" style="font-size:10px;">Live</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:13px;color:var(--g700);line-height:1.5;white-space:pre-line;"><?=htmlspecialchars($ann['body'])?></div>
                        <div style="font-size:11px;color:var(--g400);margin-top:8px;">
                            By <?=htmlspecialchars($ann['author']??'Admin')?> · 
                            <?=date('d M Y H:i', strtotime($ann['created_at']))?>
                            <?php if ($ann['expires_at']): ?>
                             · Expires <?=date('d M Y', strtotime($ann['expires_at']))?>
                            <?php endif; ?>
                            · Target: <?=$ann['target_role']==='all'?'All Staff':ucfirst($ann['target_role'])?>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;flex-shrink:0;">
                        <form method="POST">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="toggle"/>
                            <input type="hidden" name="id" value="<?=$ann['id']?>"/>
                            <button class="hri-btn hri-btn-outline" style="padding:4px 10px;font-size:12px;">
                                <?=$active?'Deactivate':'Activate'?>
                            </button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Delete this announcement?')">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="delete"/>
                            <input type="hidden" name="id" value="<?=$ann['id']?>"/>
                            <button class="hri-btn" style="padding:4px 10px;font-size:12px;background:#fee2e2;color:var(--red);border:none;">✕</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Create form -->
        <div style="position:sticky;top:80px;">
            <div class="hri-card" style="padding:20px;">
                <div style="font-size:14px;font-weight:600;color:var(--navy);margin-bottom:16px;">New Announcement</div>
                <form method="POST">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="create"/>
                    <div class="hri-form-group">
                        <label class="hri-label">Title *</label>
                        <input type="text" name="title" class="hri-input" placeholder="e.g. Office Closure Notice" required/>
                    </div>
                    <div class="hri-form-group">
                        <label class="hri-label">Message *</label>
                        <textarea name="body" class="hri-textarea" rows="5" placeholder="Announcement content..." required></textarea>
                    </div>
                    <div class="hri-form-group">
                        <label class="hri-label">Priority</label>
                        <select name="priority" class="hri-select">
                            <option value="normal">📢 Normal</option>
                            <option value="high">⚠️ High</option>
                            <option value="urgent">🚨 Urgent</option>
                        </select>
                    </div>
                    <div class="hri-form-group">
                        <label class="hri-label">Target Audience</label>
                        <select name="target_role" class="hri-select">
                            <option value="all">All Staff</option>
                            <?php foreach (ROLES as $k => $r): ?>
                            <option value="<?=$k?>"><?=$r['label']?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hri-form-group">
                        <label class="hri-label">Expires (optional)</label>
                        <input type="date" name="expires_at" class="hri-input"/>
                    </div>
                    <button type="submit" class="hri-btn hri-btn-navy" style="width:100%;">Post Announcement</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php Layout::end(); ?>
