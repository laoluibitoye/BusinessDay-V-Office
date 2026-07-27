<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

// Level-4 staff (cleaner, cs_ops, etc.) see name/role/email/online only — no phone or org-chart info
$viewerLevel = ROLES[$user['role']]['level'] ?? 4;

$staff = $db->query("SELECT u.*, lm.name as lm_name,
    (SELECT last_active FROM sessions WHERE user_id=u.id AND expires_at > NOW() ORDER BY last_active DESC LIMIT 1) as last_active
    FROM users u LEFT JOIN users lm ON lm.id=u.line_manager_id
    WHERE u.is_active=1 ORDER BY u.name")->fetchAll();

$avColors = ['#002850','#64A014','#8b5cf6','#f97316','#3b82f6','#f59e0b','#0891b2','#dc2626'];

Layout::shell($user, 'directory', 0, 'Staff Directory');
?>
<style>
.dir-hd{display:flex;align-items:center;gap:12px;margin-bottom:18px;}
.dir-title{font-size:17px;font-weight:700;color:#002850;flex:1;}
.search-inp{border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:13px;font-family:'Inter',sans-serif;outline:none;color:#0f172a;width:240px;transition:border-color .15s;}
.search-inp:focus{border-color:#64A014;}
.dir-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px;}
.scard{background:#fff;border-radius:13px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.08);display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;transition:box-shadow .15s;}
.scard:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);}
.av{width:54px;height:54px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;position:relative;flex-shrink:0;}
.on-dot{width:13px;height:13px;border-radius:50%;background:#22c55e;border:2px solid #fff;position:absolute;bottom:1px;right:1px;}
.off-dot{width:13px;height:13px;border-radius:50%;background:#cbd5e1;border:2px solid #fff;position:absolute;bottom:1px;right:1px;}
.sname{font-size:14px;font-weight:700;color:#0f172a;}
.srole{font-size:11.5px;color:#94a3b8;}
.semail{font-size:12px;color:#002850;font-weight:500;}
.slm{font-size:11.5px;color:#64748b;}
.mail-btn{padding:7px 14px;border-radius:7px;background:#64A014;color:#fff;font-size:12px;font-weight:600;border:none;cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:5px;transition:background .15s;}
.mail-btn:hover{background:#4d7c10;}
.you-tag{font-size:11.5px;color:#94a3b8;font-style:italic;}
</style>
<div class="hri-page" style="max-width:1100px;">
    <div class="dir-hd">
        <div class="dir-title">&#128101; Staff Directory — <?= count($staff) ?> members</div>
        <input class="search-inp" type="text" placeholder="Search by name or role…" oninput="filterDir(this.value)" id="dirSearch"/>
    </div>
    <div class="dir-grid" id="dirGrid">
        <?php foreach ($staff as $s):
            $initials = strtoupper(substr($s['name'],0,1).(strpos($s['name'],' ')!==false?substr($s['name'],strpos($s['name'],' ')+1,1):''));
            $color    = $avColors[ord($initials[0]??'A') % count($avColors)];
            $online   = $s['last_active'] && (time()-strtotime($s['last_active'])) < 900;
            $roleLabel= ROLES[$s['role']]['label'] ?? $s['role'];
            $roleIcon = ROLES[$s['role']]['icon'] ?? '';
            $isMe     = ($s['id'] === $user['id']);
        ?>
        <div class="scard" data-name="<?= strtolower($s['name']) ?>" data-role="<?= strtolower($roleLabel) ?>" data-dept="<?= strtolower($s['department']??'') ?>">
            <div class="av" style="background:<?= $color ?>">
                <?= $initials ?>
                <?php if (!$isMe): ?>
                <div class="<?= $online?'on-dot':'off-dot' ?>"></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="sname"><?= htmlspecialchars($s['name']) ?></div>
                <div class="srole"><?= $roleIcon ?> <?= $roleLabel ?></div>
                <?php if ($s['department']): ?><div class="srole"><?= htmlspecialchars($s['department']) ?></div><?php endif; ?>
            </div>
            <div class="semail"><?= htmlspecialchars($s['email']) ?></div>
            <?php if ($s['lm_name'] && $viewerLevel <= 3): ?><div class="slm">Reports to: <?= htmlspecialchars($s['lm_name']) ?></div><?php endif; ?>
            <?php if ($s['phone'] && $viewerLevel <= 3): ?><div class="slm">&#128222; <?= htmlspecialchars($s['phone']) ?></div><?php endif; ?>
            <?php if (!$isMe): ?>
            <button class="mail-btn" onclick="hriOpenCompose({to:<?=htmlspecialchars(json_encode($s['email']), ENT_QUOTES)?>})">&#9998; Email</button>
            <?php else: ?>
            <span class="you-tag">— You —</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
function filterDir(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#dirGrid .scard').forEach(function(c) {
        var match = !q || c.dataset.name.includes(q) || c.dataset.role.includes(q) || c.dataset.dept.includes(q);
        c.style.display = match ? '' : 'none';
    });
}
document.getElementById('dirSearch').addEventListener('keydown', function(e){
    if(e.key==='Escape') { this.value=''; filterDir(''); }
});
</script>
<?php Layout::end(); ?>
