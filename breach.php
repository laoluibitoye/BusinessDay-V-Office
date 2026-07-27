<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// breach.php — NDPC Data Breach Log (NDPA 2023 Compliance)
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_compliance']);
$db   = getDB();

// Create breach_log table if needed
try {
    $db->exec("CREATE TABLE IF NOT EXISTS breach_log (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        user_id             INT NOT NULL,
        breach_date         DATE NOT NULL,
        discovered_date     DATE NOT NULL,
        description         TEXT NOT NULL,
        data_affected       TEXT,
        subjects_affected   INT DEFAULT 0,
        severity            ENUM('low','medium','high','critical') DEFAULT 'medium',
        ndpc_notified       TINYINT(1) DEFAULT 0,
        ndpc_notified_date  DATE,
        ndpc_ref            VARCHAR(100),
        subjects_notified   TINYINT(1) DEFAULT 0,
        remediation         TEXT,
        status              ENUM('open','contained','resolved') DEFAULT 'open',
        logged_by           INT,
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(breach_date), INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
// Schema migrations removed — run database/breach_migration.sql in phpMyAdmin if breach_log columns are missing

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'log') {
        $data = [
            Auth::sanitiseString($_POST['breach_date']     ?? date('Y-m-d'), 10),
            Auth::sanitiseString($_POST['discovered_date'] ?? date('Y-m-d'), 10),
            $_POST['description']     ?? '',
            $_POST['data_affected']   ?? '',
            (int)($_POST['subjects_affected'] ?? 0),
            in_array($_POST['severity']??'', ['low','medium','high','critical']) ? $_POST['severity'] : 'medium',
            isset($_POST['ndpc_notified']) ? 1 : 0,
            !empty($_POST['ndpc_notified_date']) ? $_POST['ndpc_notified_date'] : null,
            Auth::sanitiseString($_POST['ndpc_ref'] ?? '', 100),
            isset($_POST['subjects_notified']) ? 1 : 0,
            $_POST['remediation'] ?? '',
            in_array($_POST['status']??'', ['open','contained','resolved']) ? $_POST['status'] : 'open',
            $user['id'],
            $user['id'],
        ];
        $db->prepare("INSERT INTO breach_log 
            (breach_date,discovered_date,description,data_affected,subjects_affected,
             severity,ndpc_notified,ndpc_notified_date,ndpc_ref,subjects_notified,
             remediation,status,logged_by,user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute($data);
        Auth::auditLog($user['id'], 'breach_logged', 'New breach on '.($_POST['breach_date']??''));
        header('Location: breach.php?logged=1');
        exit;
    } elseif ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status']??'', ['open','contained','resolved']) ? $_POST['status'] : 'open';
        $db->prepare("UPDATE breach_log SET status=? WHERE id=?")->execute([$status, $id]);
        Auth::auditLog($user['id'], 'breach_status_updated', "ID $id → $status");
        header('Location: breach.php');
        exit;
    }
}

$breaches = $db->query("
    SELECT b.*, u.name as logger_name
    FROM breach_log b
    LEFT JOIN users u ON u.id = b.logged_by
    ORDER BY b.breach_date DESC
")->fetchAll();

$sevColors = ['low'=>'#059669','medium'=>'#d97706','high'=>'#dc2626','critical'=>'#7c3aed'];
$sevBg     = ['low'=>'#dcfce7','medium'=>'#fef3c7','high'=>'#fee2e2','critical'=>'#ede9fe'];
$statColors= ['open'=>'#dc2626','contained'=>'#d97706','resolved'=>'#059669'];

$showForm = isset($_GET['new']) || isset($_GET['logged']) || empty($breaches);

Layout::shell($user, 'breach', 0, 'Data Breach Log');
?>
<div class="hri-page" style="max-width:1000px;">
    <div class="hri-page-hd">
        <h1 class="hri-page-title">🔴 Data Breach Log</h1>
        <div style="display:flex;gap:8px;align-items:center;">
            <span style="font-size:12px;color:var(--g400);">NDPA 2023 · NDPC Compliance</span>
            <a href="breach.php?new=1" class="hri-btn hri-btn-navy">+ Log Breach</a>
        </div>
    </div>

    <!-- NDPC Notice -->
    <div class="hri-card" style="padding:14px 16px;margin-bottom:16px;background:#fef3c7;border-left:4px solid #d97706;">
        <div style="font-size:13px;font-weight:600;color:#92400e;">⚠️ NDPC Legal Requirement</div>
        <div style="font-size:12px;color:#78350f;margin-top:4px;">
            Under the Nigeria Data Protection Act 2023, you must notify the NDPC within 
            <strong>72 hours</strong> of discovering a breach. Data subjects must be notified 
            without undue delay where the breach is likely to result in high risk to their rights.
            Certificate: NDPC/DCP/12819 (EHL)
        </div>
    </div>

    <?php if ($showForm): ?>
    <!-- Log Breach Form -->
    <div class="hri-card" style="padding:20px;margin-bottom:20px;">
        <div style="font-size:15px;font-weight:700;color:var(--navy);margin-bottom:16px;">📋 Log New Data Breach</div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="log"/>
            <style>.br-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}@media(max-width:640px){.br-form-grid{grid-template-columns:1fr;}}</style>
            <div class="br-form-grid">
                <div class="hri-form-group">
                    <label class="hri-label">Breach Date *</label>
                    <input type="date" name="breach_date" class="hri-input" value="<?=date('Y-m-d')?>" required/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Date Discovered *</label>
                    <input type="date" name="discovered_date" class="hri-input" value="<?=date('Y-m-d')?>" required/>
                </div>
                <div class="hri-form-group" style="grid-column:span 2;">
                    <label class="hri-label">Breach Description *</label>
                    <textarea name="description" class="hri-textarea" rows="3" required
                        placeholder="What happened? How was data breached?"></textarea>
                </div>
                <div class="hri-form-group" style="grid-column:span 2;">
                    <label class="hri-label">Categories of Data Affected</label>
                    <textarea name="data_affected" class="hri-textarea" rows="2"
                        placeholder="e.g. Names, emails, bank details, NIN numbers..."></textarea>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Approximate Number of Data Subjects</label>
                    <input type="number" name="subjects_affected" class="hri-input" value="0" min="0"/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Severity</label>
                    <select name="severity" class="hri-select">
                        <option value="low">🟢 Low</option>
                        <option value="medium" selected>🟡 Medium</option>
                        <option value="high">🔴 High</option>
                        <option value="critical">🟣 Critical</option>
                    </select>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label">Initial Status</label>
                    <select name="status" class="hri-select">
                        <option value="open">Open</option>
                        <option value="contained">Contained</option>
                        <option value="resolved">Resolved</option>
                    </select>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label" style="display:flex;gap:8px;align-items:center;">
                        <input type="checkbox" name="ndpc_notified"/> NDPC Notified
                    </label>
                    <input type="date" name="ndpc_notified_date" class="hri-input" style="margin-top:6px;"
                           placeholder="Notification date"/>
                    <input type="text" name="ndpc_ref" class="hri-input" style="margin-top:6px;"
                           placeholder="NDPC Reference number (if any)"/>
                </div>
                <div class="hri-form-group">
                    <label class="hri-label" style="display:flex;gap:8px;align-items:center;">
                        <input type="checkbox" name="subjects_notified"/> Data Subjects Notified
                    </label>
                </div>
                <div class="hri-form-group" style="grid-column:span 2;">
                    <label class="hri-label">Remediation Actions Taken</label>
                    <textarea name="remediation" class="hri-textarea" rows="2"
                        placeholder="What steps were taken to contain/resolve this breach?"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" class="hri-btn hri-btn-navy">Log Breach</button>
                <?php if (!empty($breaches)): ?>
                <a href="breach.php" class="hri-btn hri-btn-outline">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Breach List -->
    <?php if (!empty($breaches)): ?>
    <div class="hri-card">
        <div class="hri-card-hd">
            <span class="hri-card-title">Breach History (<?=count($breaches)?> records)</span>
        </div>
        <?php foreach ($breaches as $b): ?>
        <div style="padding:16px;border-bottom:1px solid var(--g100);">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                <div style="flex:1;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <span style="font-size:13px;font-weight:700;color:var(--g900);">
                            Breach: <?=date('d M Y', strtotime($b['breach_date']))?>
                        </span>
                        <span class="hri-pill" style="font-size:11px;background:<?=$sevBg[$b['severity']]?>;color:<?=$sevColors[$b['severity']]?>;">
                            <?=ucfirst($b['severity'])?>
                        </span>
                        <span class="hri-pill" style="font-size:11px;background:<?=$statColors[$b['status']]?>20;color:<?=$statColors[$b['status']]?>;">
                            <?=ucfirst($b['status'])?>
                        </span>
                        <?php if ($b['ndpc_notified']): ?>
                        <span class="hri-pill hri-pill-success" style="font-size:11px;">NDPC Notified</span>
                        <?php else: ?>
                        <span class="hri-pill" style="font-size:11px;background:#fee2e2;color:var(--red);">NDPC NOT Notified</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:13px;color:var(--g700);margin-bottom:6px;"><?=htmlspecialchars($b['description'])?></div>
                    <div style="font-size:11px;color:var(--g500);">
                        Discovered: <?=date('d M Y', strtotime($b['discovered_date']))?>
                        · <?=(int)$b['subjects_affected']?> subject<?=$b['subjects_affected']!=1?'s':''?> affected
                        <?php if ($b['ndpc_ref']): ?> · NDPC Ref: <?=htmlspecialchars($b['ndpc_ref'])?><?php endif; ?>
                        · Logged by <?=htmlspecialchars($b['logger_name']??'System')?>
                    </div>
                    <?php if ($b['remediation']): ?>
                    <div style="font-size:12px;color:var(--g600);margin-top:6px;padding:8px;background:var(--g50);border-radius:4px;">
                        <strong>Remediation:</strong> <?=htmlspecialchars($b['remediation'])?>
                    </div>
                    <?php endif; ?>
                </div>
                <form method="POST" style="flex-shrink:0;">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="update_status"/>
                    <input type="hidden" name="id" value="<?=$b['id']?>"/>
                    <select name="status" class="hri-select" style="font-size:12px;padding:4px 8px;"
                            onchange="this.form.submit()">
                        <option value="open"      <?=$b['status']==='open'?'selected':''?>>Open</option>
                        <option value="contained" <?=$b['status']==='contained'?'selected':''?>>Contained</option>
                        <option value="resolved"  <?=$b['status']==='resolved'?'selected':''?>>Resolved</option>
                    </select>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php Layout::end(); ?>
