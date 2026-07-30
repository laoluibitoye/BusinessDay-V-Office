<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// Email filter rules engine — manage automatic email filtering rules
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$success = '';
$error   = '';

// Check if email_filters table exists
$filtersReady = false;
try { $db->query("SELECT 1 FROM email_filters LIMIT 1"); $filtersReady = true; } catch (PDOException $e) {}

// ── ADD RULE ──────────────────────────────────────────────────
if ($filtersReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'add') {
    $name     = trim($_POST['name']     ?? '');
    $field    = $_POST['field']    ?? '';
    $operator = $_POST['operator'] ?? '';
    $value    = trim($_POST['value']    ?? '');
    $action   = $_POST['action']   ?? '';
    $param    = trim($_POST['action_param'] ?? '');

    $validFields    = ['from','to','subject','body'];
    $validOperators = ['contains','equals','starts_with','ends_with'];
    $validActions   = ['move','star','mark_read','delete'];

    if (!$name || !$value) {
        $error = 'Name and match value are required.';
    } elseif (!in_array($field, $validFields) || !in_array($operator, $validOperators) || !in_array($action, $validActions)) {
        $error = 'Invalid rule configuration.';
    } elseif ($action === 'move' && !$param) {
        $error = 'Please enter a destination folder for the Move action.';
    } else {
        $maxOrder = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM email_filters WHERE user_id=?");
        $maxOrder->execute([$user['id']]);
        $nextOrder = (int)$maxOrder->fetchColumn();

        $db->prepare("INSERT INTO email_filters (user_id, name, field, operator, value, action, action_param, is_active, sort_order)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)")
           ->execute([$user['id'], $name, $field, $operator, $value, $action, $param ?: null, $nextOrder]);

        Auth::auditLog($user['id'], 'filter_added', "Email filter added: $name");
        $success = "Filter rule \"$name\" created.";
    }
}

// ── TOGGLE RULE ───────────────────────────────────────────────
if ($filtersReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'toggle') {
    $fid = (int)($_POST['filter_id'] ?? 0);
    $db->prepare("UPDATE email_filters SET is_active = NOT is_active WHERE id=? AND user_id=?")->execute([$fid, $user['id']]);
    header('Location: filters.php');
    exit;
}

// ── DELETE RULE ───────────────────────────────────────────────
if ($filtersReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    $fid = (int)($_POST['filter_id'] ?? 0);
    $chk = $db->prepare("SELECT name FROM email_filters WHERE id=? AND user_id=?");
    $chk->execute([$fid, $user['id']]);
    $row = $chk->fetch();
    if ($row) {
        $db->prepare("DELETE FROM email_filters WHERE id=? AND user_id=?")->execute([$fid, $user['id']]);
        Auth::auditLog($user['id'], 'filter_deleted', "Email filter deleted: {$row['name']}");
        $success = "Filter rule deleted.";
    }
}

// Load all rules for this user
$rules = [];
if ($filtersReady) {
    $rulesStmt = $db->prepare("SELECT * FROM email_filters WHERE user_id=? ORDER BY sort_order, id");
    $rulesStmt->execute([$user['id']]);
    $rules = $rulesStmt->fetchAll();
}

// Load IMAP folders for the "Move to" dropdown
$folders = [];
try {
    $imap_folders_stmt = $db->query("SELECT DISTINCT folder_path FROM imap_folders ORDER BY folder_path");
    if ($imap_folders_stmt) {
        $folders = $imap_folders_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {}
if (empty($folders)) {
    $folders = ['INBOX', 'INBOX.Sent', 'INBOX.Trash', 'INBOX.Drafts', 'INBOX.Junk E-mail'];
}

$actionLabels = ['move'=>'Move to folder','star'=>'Star it','mark_read'=>'Mark as read','delete'=>'Delete'];
$fieldLabels  = ['from'=>'From','to'=>'To','subject'=>'Subject','body'=>'Body contains'];
$opLabels     = ['contains'=>'contains','equals'=>'equals','starts_with'=>'starts with','ends_with'=>'ends with'];

Layout::shell($user, 'filters', 0, 'Email Filters');
?>
<style>
.filters-layout{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;}
.pgt{font-size:17px;font-weight:700;color:var(--navy);margin-bottom:14px;}
.card{background:var(--w);border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;}
.chd{padding:14px 18px;border-bottom:1px solid var(--g100);font-size:13.5px;font-weight:700;color:var(--navy);}
.cbd{padding:18px;}
.fg{margin-bottom:13px;}
label{display:block;font-size:11px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
input[type=text],select{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;}
input[type=text]:focus,select:focus{border-color:var(--green);background:var(--w);}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.btn{padding:8px 16px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:all .15s;display:inline-flex;align-items:center;gap:5px;}
.btn-green{background:var(--green);color:#fff;width:100%;justify-content:center;}
.btn-green:hover{background:#4d7c10;}
.btn-sm{padding:5px 10px;font-size:11.5px;border-radius:6px;}
.btn-off{background:var(--g100);color:var(--g500);border:1.5px solid var(--g200);}
.btn-off:hover{border-color:var(--g400);}
.btn-on{background:#dbeafe;color:#1e40af;border:1.5px solid #93c5fd;}
.btn-del{background:#fee2e2;color:var(--red);border:1.5px solid #fca5a5;}
.btn-del:hover{background:#fecaca;}
.alert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:14px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.rule-item{padding:13px 18px;border-bottom:1px solid var(--g50);display:flex;align-items:center;gap:10px;}
.rule-item:last-child{border-bottom:none;}
.rule-item.off{opacity:.55;}
.rule-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.rule-dot.on{background:#22c55e;}
.rule-dot.off{background:var(--g400);}
.rule-info{flex:1;}
.rule-name{font-size:13px;font-weight:700;color:var(--g900);}
.rule-desc{font-size:12px;color:var(--g500);margin-top:2px;}
.rule-action{font-size:11.5px;font-weight:600;color:var(--navy);margin-top:3px;}
.rule-acts{display:flex;gap:5px;flex-shrink:0;}
.empty{padding:28px;text-align:center;color:var(--g400);font-size:13px;}
#folder-field{display:none;}
/* mobile-grid-fix */
@media(max-width:1024px){.filters-layout{grid-template-columns:1fr;}}
/* mobile-all-pages */
@media(max-width:768px){
    [class*='-grid']{grid-template-columns:1fr;}
}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">
    <div class="pgt">🔀 Email Filter Rules</div>
    <div class="filters-layout">
        <!-- Rules list -->
        <div>
            <?php if ($success): ?><div class="alert ok">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

            <?php if (!$filtersReady): ?>
            <div class="alert er">
                ⚠️ Email filters require the sprint3 database migration.<br>
                Run <strong>database/sprint3_migration.sql</strong> in phpMyAdmin to enable this feature.
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="chd">📋 My Filter Rules (<?= count($rules) ?>)</div>
                <?php if (empty($rules)): ?>
                <div class="empty">No filter rules yet.<br/>Create your first rule using the form →</div>
                <?php else: ?>
                <?php foreach ($rules as $r): ?>
                <div class="rule-item <?= $r['is_active'] ? '' : 'off' ?>">
                    <div class="rule-dot <?= $r['is_active'] ? 'on' : 'off' ?>"></div>
                    <div class="rule-info">
                        <div class="rule-name"><?= htmlspecialchars($r['name']) ?></div>
                        <div class="rule-desc">
                            If <strong><?= $fieldLabels[$r['field']] ?? $r['field'] ?></strong>
                            <?= $opLabels[$r['operator']] ?? $r['operator'] ?>
                            "<em><?= htmlspecialchars($r['value']) ?></em>"
                        </div>
                        <div class="rule-action">
                            → <?= $actionLabels[$r['action']] ?? $r['action'] ?>
                            <?= $r['action_param'] ? ': <strong>' . htmlspecialchars($r['action_param']) . '</strong>' : '' ?>
                        </div>
                    </div>
                    <div class="rule-acts">
                        <form method="POST" style="display:inline;">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="act" value="toggle"/>
                            <input type="hidden" name="filter_id" value="<?= $r['id'] ?>"/>
                            <button type="submit" class="btn btn-sm <?= $r['is_active'] ? 'btn-on' : 'btn-off' ?>">
                                <?= $r['is_active'] ? '✓ On' : 'Off' ?>
                            </button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this filter rule?')">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="act" value="delete"/>
                            <input type="hidden" name="filter_id" value="<?= $r['id'] ?>"/>
                            <button type="submit" class="btn btn-sm btn-del">✕</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top:14px;">
                <div class="chd">ℹ️ How Filters Work</div>
                <div class="cbd" style="color:var(--g500);font-size:13px;line-height:1.7;">
                    <p>Rules are applied when new mail arrives in your inbox (every 30 seconds). Rules run in order — the first matching rule wins.</p>
                    <br/>
                    <p><strong>Move:</strong> Moves the email to the specified IMAP folder.<br/>
                    <strong>Star:</strong> Flags the email as starred/important.<br/>
                    <strong>Mark as read:</strong> Removes the unread indicator.<br/>
                    <strong>Delete:</strong> Moves to Trash.</p>
                </div>
            </div>
        </div>

        <!-- Add rule form -->
        <div class="card">
            <div class="chd">➕ Add Filter Rule</div>
            <div class="cbd">
                <form method="POST">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="act" value="add"/>

                    <div class="fg">
                        <label>Rule Name</label>
                        <input type="text" name="name" placeholder="e.g. Move newsletters" required/>
                    </div>

                    <div class="fg">
                        <label>When</label>
                        <select name="field" id="field-sel">
                            <option value="from">From (sender)</option>
                            <option value="subject">Subject</option>
                            <option value="to">To (recipient)</option>
                            <option value="body">Body</option>
                        </select>
                    </div>

                    <div class="fg">
                        <label>Condition</label>
                        <select name="operator">
                            <option value="contains">contains</option>
                            <option value="equals">equals exactly</option>
                            <option value="starts_with">starts with</option>
                            <option value="ends_with">ends with</option>
                        </select>
                    </div>

                    <div class="fg">
                        <label>Match Value</label>
                        <input type="text" name="value" placeholder="e.g. newsletter@example.com" required/>
                    </div>

                    <div class="fg">
                        <label>Then</label>
                        <select name="action" id="action-sel" onchange="toggleFolderField(this.value)">
                            <option value="move">Move to folder</option>
                            <option value="star">Star the email</option>
                            <option value="mark_read">Mark as read</option>
                            <option value="delete">Delete (move to Trash)</option>
                        </select>
                    </div>

                    <div class="fg" id="folder-field">
                        <label>Destination Folder</label>
                        <select name="action_param">
                            <?php foreach ($folders as $f): ?>
                            <option value="<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-green">➕ Create Rule</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleFolderField(action) {
    document.getElementById('folder-field').style.display = action === 'move' ? 'block' : 'none';
}
// Show folder field by default since 'move' is the first option
toggleFolderField('move');
</script>
<?php Layout::end(); ?>
