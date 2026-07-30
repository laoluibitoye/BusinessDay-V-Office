<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin']);
$db   = getDB();

$success = '';
$error   = '';

$tableReady = false;
try {
    $db->query("SELECT 1 FROM work_schedules LIMIT 1");
    $tableReady = true;
} catch (PDOException $e) {}

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $name      = trim($_POST['name'] ?? '');
        $days      = (int)($_POST['days_per_week'] ?? 5);
        $leave     = (int)($_POST['annual_leave_days'] ?? 15);
        $desc      = trim($_POST['description'] ?? '');
        if (!$name || $days < 1 || $days > 7) {
            $error = 'Schedule name and valid days per week (1–7) required.';
        } else {
            $db->prepare("INSERT INTO work_schedules (name, days_per_week, annual_leave_days, description) VALUES (?,?,?,?)")
               ->execute([$name, $days, $leave, $desc]);
            Auth::auditLog($user['id'], 'schedule_added', "Work schedule added: $name");
            $success = "Schedule \"$name\" added.";
        }
    }

    if ($act === 'edit') {
        $id    = (int)($_POST['schedule_id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $days  = (int)($_POST['days_per_week'] ?? 5);
        $leave = (int)($_POST['annual_leave_days'] ?? 15);
        $desc  = trim($_POST['description'] ?? '');
        if ($id && $name) {
            $db->prepare("UPDATE work_schedules SET name=?, days_per_week=?, annual_leave_days=?, description=? WHERE id=?")
               ->execute([$name, $days, $leave, $desc, $id]);
            Auth::auditLog($user['id'], 'schedule_updated', "Work schedule updated: $name");
            $success = "Schedule updated.";
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['schedule_id'] ?? 0);
        // Check if any staff are on this schedule
        $cnt = $db->prepare("SELECT COUNT(*) FROM staff_schedules WHERE schedule_id=?");
        $cnt->execute([$id]);
        if ($cnt->fetchColumn() > 0) {
            $error = 'Cannot delete — staff are assigned to this schedule. Reassign them first.';
        } else {
            $db->prepare("DELETE FROM work_schedules WHERE id=?")->execute([$id]);
            Auth::auditLog($user['id'], 'schedule_deleted', "Work schedule ID $id deleted");
            $success = "Schedule deleted.";
        }
    }

    if ($act === 'assign') {
        $userId     = (int)($_POST['user_id'] ?? 0);
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $workDays   = $_POST['working_days'] ?? '1,2,3,4,5';
        $effDate    = $_POST['effective_from'] ?: null;
        if ($userId && $scheduleId) {
            $db->prepare("INSERT INTO staff_schedules (user_id, schedule_id, working_days, effective_from)
                          VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE schedule_id=?, working_days=?, effective_from=?")
               ->execute([$userId, $scheduleId, $workDays, $effDate, $scheduleId, $workDays, $effDate]);
            // Update leave balance
            $wsRow = $db->prepare("SELECT annual_leave_days FROM work_schedules WHERE id=?");
            $wsRow->execute([$scheduleId]);
            $wsr = $wsRow->fetch();
            if ($wsr) {
                $db->prepare("INSERT INTO leave_balance (user_id, year, entitled_days) VALUES (?,?,?) ON DUPLICATE KEY UPDATE entitled_days=?")
                   ->execute([$userId, date('Y'), $wsr['annual_leave_days'], $wsr['annual_leave_days']]);
            }
            Auth::auditLog($user['id'], 'schedule_assigned', "Schedule $scheduleId assigned to user $userId");
            $success = "Schedule assigned.";
        }
    }
}

// Fetch data
$schedules = [];
$staffWithSchedule = [];
$staffNoSchedule   = [];

if ($tableReady) {
    $schedules = $db->query("SELECT w.*, COUNT(ss.user_id) as staff_count
        FROM work_schedules w
        LEFT JOIN staff_schedules ss ON ss.schedule_id = w.id
        GROUP BY w.id
        ORDER BY w.id")->fetchAll();

    $staffWithSchedule = $db->query("SELECT u.id, u.name, u.email, u.role, u.department,
        w.name as schedule_name, w.id as schedule_id, ss.working_days, ss.effective_from
        FROM users u
        JOIN staff_schedules ss ON ss.user_id = u.id
        JOIN work_schedules w ON w.id = ss.schedule_id
        WHERE u.is_active=1
        ORDER BY u.name")->fetchAll();

    $staffNoSchedule = $db->query("SELECT u.id, u.name, u.email, u.role, u.department
        FROM users u
        LEFT JOIN staff_schedules ss ON ss.user_id = u.id
        WHERE u.is_active=1 AND ss.user_id IS NULL
        ORDER BY u.name")->fetchAll();
}

$dayNames = ['1'=>'Mon','2'=>'Tue','3'=>'Wed','4'=>'Thu','5'=>'Fri','6'=>'Sat','7'=>'Sun'];

function parseDays($csv, $dayNames) {
    if (!$csv) return '—';
    $parts = explode(',', $csv);
    return implode(', ', array_map(function($d) use ($dayNames) {
        return $dayNames[trim($d)] ?? $d;
    }, $parts));
}

Layout::shell($user, 'work_schedules', 0, 'Work Schedules');
?>
<style>
.alert{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:18px;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.ph{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.pt{font-size:18px;font-weight:700;color:var(--navy);}
.ps{font-size:12.5px;color:var(--g400);margin-top:2px;}
.sch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin-bottom:24px;}
.sch-card{background:var(--w);border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:18px;border-top:4px solid var(--navy);position:relative;}
.sch-name{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.sch-meta{font-size:12px;color:var(--g500);margin-bottom:12px;}
.sch-stat{display:flex;gap:12px;}
.sch-s{text-align:center;}
.sch-sv{font-size:20px;font-weight:700;color:var(--g900);}
.sch-sl{font-size:10px;color:var(--g400);text-transform:uppercase;letter-spacing:.05em;}
.sch-actions{display:flex;gap:6px;margin-top:14px;}
.card{background:var(--w);border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:20px;}
.card-hd{padding:14px 18px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.card-ttl{font-size:13.5px;font-weight:700;color:var(--navy);}
.tbl{width:100%;border-collapse:collapse;}
.tbl th{background:var(--g50);padding:9px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g400);border-bottom:1px solid var(--g100);}
.tbl td{padding:10px 14px;border-bottom:1px solid var(--g50);font-size:13px;color:var(--g700);vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.btn{padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:all .15s;display:inline-flex;align-items:center;gap:5px;}
.btn.gn{background:var(--green);color:#fff;}
.btn.gn:hover{background:#4d7c10;}
.btn.pr{background:var(--navy);color:#fff;}
.btn.pr:hover{background:#003a72;}
.btn.rd{background:#dc2626;color:#fff;}
.btn.sm{padding:4px 10px;font-size:11.5px;}
.btn.ol{background:transparent;border:1.5px solid var(--g200);color:var(--g700);}
.fg{margin-bottom:12px;}
label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
input,select,textarea{border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);width:100%;}
input:focus,select:focus{border-color:var(--green);}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.pill{padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.rbadge{padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;color:#fff;display:inline-block;}
.notice{background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:14px 18px;font-size:13px;color:#92400e;margin-bottom:20px;}
/* Modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;display:none;align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.modal{background:var(--w);border-radius:16px;box-shadow:0 8px 32px rgba(0,40,80,.14);width:100%;max-width:520px;overflow:hidden;}
.modal-hd{padding:16px 20px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.modal-ttl{font-size:15px;font-weight:700;color:var(--navy);}
.modal-close{cursor:pointer;color:var(--g400);font-size:18px;line-height:1;background:none;border:none;}
.modal-bd{padding:20px;}
.modal-ft{padding:14px 20px;border-top:1px solid var(--g100);display:flex;gap:8px;justify-content:flex-end;}
.day-checks{display:flex;flex-wrap:wrap;gap:8px;}
.day-cb{display:flex;align-items:center;gap:5px;font-size:13px;color:var(--g700);cursor:pointer;}
.day-cb input{width:15px;height:15px;cursor:pointer;accent-color:var(--green);}
/* mobile-all-pages */
@media(max-width:768px){
    .fg2{grid-template-columns:1fr;}
    .sch-grid{grid-template-columns:1fr;}
}
</style>
<div style="padding:20px 24px;overflow-y:auto;flex:1;min-height:0;height:100%;">

    <?php if ($success): ?>
    <div class="alert ok">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="ph">
        <div>
            <div class="pt">📅 Work Schedules</div>
            <div class="ps">Define schedules and assign them to staff. Determines annual leave entitlement.</div>
        </div>
        <?php if ($tableReady): ?>
        <button class="btn gn" onclick="openModal('addModal')">+ Add Schedule</button>
        <?php endif; ?>
    </div>

    <?php if (!$tableReady): ?>
    <div class="notice">
        ⚠️ The work schedules tables do not exist yet.<br>
        Run <strong>database/sprint4a_migration.sql</strong> in phpMyAdmin to create them, then reload this page.<br>
        The migration also seeds the 4 default schedules (Full Time, 4-Day, 3-Day, 2-Day).
    </div>

    <?php else: ?>

    <!-- ── SCHEDULE CARDS ── -->
    <div class="sch-grid">
        <?php foreach ($schedules as $s): ?>
        <div class="sch-card">
            <div class="sch-name"><?= htmlspecialchars($s['name']) ?></div>
            <div class="sch-meta"><?= htmlspecialchars($s['description'] ?: 'No description') ?></div>
            <div class="sch-stat">
                <div class="sch-s">
                    <div class="sch-sv"><?= $s['days_per_week'] ?></div>
                    <div class="sch-sl">Days/Week</div>
                </div>
                <div class="sch-s">
                    <div class="sch-sv"><?= $s['annual_leave_days'] ?></div>
                    <div class="sch-sl">Leave Days/Yr</div>
                </div>
                <div class="sch-s">
                    <div class="sch-sv"><?= $s['staff_count'] ?></div>
                    <div class="sch-sl">Staff</div>
                </div>
            </div>
            <div class="sch-actions">
                <button class="btn pr sm" onclick='openEdit(<?= json_encode($s) ?>)'>✏️ Edit</button>
                <?php if ($s['staff_count'] == 0): ?>
                <form method="POST" onsubmit="return confirm('Delete this schedule?')" style="margin:0;">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="delete"/>
                    <input type="hidden" name="schedule_id" value="<?= $s['id'] ?>"/>
                    <button type="submit" class="btn rd sm">Delete</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($schedules)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--g400);">No schedules yet. Add one above.</div>
        <?php endif; ?>
    </div>

    <!-- ── STAFF WITHOUT SCHEDULE ── -->
    <?php if (!empty($staffNoSchedule)): ?>
    <div class="card">
        <div class="card-hd">
            <div class="card-ttl">⚠️ Staff Without a Schedule (<?= count($staffNoSchedule) ?>)</div>
        </div>
        <table class="tbl">
            <thead>
                <tr><th>Staff Member</th><th>Role</th><th>Department</th><th>Assign Schedule</th></tr>
            </thead>
            <tbody>
            <?php foreach ($staffNoSchedule as $s): ?>
            <?php $rc = ROLES[$s['role']] ?? ['label'=>$s['role'],'icon'=>'👤','color'=>'#64748b']; ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?= htmlspecialchars($s['name']) ?></div>
                    <div style="font-size:11.5px;color:var(--g400);"><?= htmlspecialchars($s['email']) ?></div>
                </td>
                <td><span class="rbadge" style="background:<?= $rc['color'] ?>"><?= $rc['icon'] ?> <?= htmlspecialchars($rc['label']) ?></span></td>
                <td><?= $s['department'] ? htmlspecialchars($s['department']) : '<span style="color:var(--g400);">—</span>' ?></td>
                <td>
                    <button class="btn gn sm" onclick='openAssign(<?= json_encode(['id'=>$s['id'],'name'=>$s['name']]) ?>)'>
                        Assign Schedule
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── STAFF WITH SCHEDULE ── -->
    <div class="card">
        <div class="card-hd">
            <div class="card-ttl">✅ Staff Schedule Assignments (<?= count($staffWithSchedule) ?>)</div>
        </div>
        <?php if (empty($staffWithSchedule)): ?>
        <div style="padding:24px;text-align:center;color:var(--g400);">No staff have been assigned schedules yet.</div>
        <?php else: ?>
        <table class="tbl">
            <thead>
                <tr><th>Staff Member</th><th>Role</th><th>Schedule</th><th>Working Days</th><th>Effective From</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($staffWithSchedule as $s): ?>
            <?php $rc = ROLES[$s['role']] ?? ['label'=>$s['role'],'icon'=>'👤','color'=>'#64748b']; ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?= htmlspecialchars($s['name']) ?></div>
                    <div style="font-size:11.5px;color:var(--g400);"><?= htmlspecialchars($s['email']) ?></div>
                </td>
                <td><span class="rbadge" style="background:<?= $rc['color'] ?>"><?= $rc['icon'] ?> <?= htmlspecialchars($rc['label']) ?></span></td>
                <td style="font-weight:600;"><?= htmlspecialchars($s['schedule_name']) ?></td>
                <td style="font-size:12px;"><?= parseDays($s['working_days'], $dayNames) ?></td>
                <td style="font-size:12px;color:var(--g400);"><?= $s['effective_from'] ? date('d M Y', strtotime($s['effective_from'])) : '—' ?></td>
                <td>
                    <button class="btn ol sm" onclick='openAssign(<?= json_encode(['id'=>$s['id'],'name'=>$s['name'],'schedule_id'=>$s['schedule_id'],'working_days'=>$s['working_days']]) ?>)'>
                        Change
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php endif; // tableReady ?>

</div>

<!-- ADD SCHEDULE MODAL -->
<div class="modal-bg" id="addModal">
    <div class="modal">
        <div class="modal-hd">
            <div class="modal-ttl">+ Add Work Schedule</div>
            <button class="modal-close" onclick="closeModal('addModal')">✕</button>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="add"/>
            <div class="modal-bd">
                <div class="fg"><label>Schedule Name *</label><input type="text" name="name" placeholder="e.g. 5-Day Week (Mon–Fri)" required/></div>
                <div class="fg2">
                    <div class="fg"><label>Days Per Week *</label><input type="number" name="days_per_week" min="1" max="7" value="5" required/></div>
                    <div class="fg"><label>Annual Leave Days *</label><input type="number" name="annual_leave_days" min="0" max="365" value="15" required/></div>
                </div>
                <div class="fg"><label>Description</label><input type="text" name="description" placeholder="Brief description of this schedule"/></div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn gn">Add Schedule</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT SCHEDULE MODAL -->
<div class="modal-bg" id="editModal">
    <div class="modal">
        <div class="modal-hd">
            <div class="modal-ttl">✏️ Edit Schedule</div>
            <button class="modal-close" onclick="closeModal('editModal')">✕</button>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="edit"/>
            <input type="hidden" name="schedule_id" id="editId"/>
            <div class="modal-bd">
                <div class="fg"><label>Schedule Name *</label><input type="text" name="name" id="editName" required/></div>
                <div class="fg2">
                    <div class="fg"><label>Days Per Week *</label><input type="number" name="days_per_week" id="editDays" min="1" max="7" required/></div>
                    <div class="fg"><label>Annual Leave Days *</label><input type="number" name="annual_leave_days" id="editLeave" min="0" required/></div>
                </div>
                <div class="fg"><label>Description</label><input type="text" name="description" id="editDesc"/></div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn pr">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ASSIGN SCHEDULE MODAL -->
<div class="modal-bg" id="assignModal">
    <div class="modal">
        <div class="modal-hd">
            <div class="modal-ttl">📅 Assign Schedule</div>
            <button class="modal-close" onclick="closeModal('assignModal')">✕</button>
        </div>
        <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="assign"/>
            <input type="hidden" name="user_id" id="assignUserId"/>
            <div class="modal-bd">
                <p style="font-size:13px;color:var(--g700);margin-bottom:14px;">
                    Assigning schedule to: <strong id="assignUserName"></strong>
                </p>
                <div class="fg">
                    <label>Work Schedule *</label>
                    <select name="schedule_id" id="assignSchedId" required>
                        <option value="">Select schedule…</option>
                        <?php foreach ($schedules as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> — <?= $s['annual_leave_days'] ?> days leave/yr</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Working Days</label>
                    <div class="day-checks">
                        <?php foreach ($dayNames as $num => $name): ?>
                        <label class="day-cb">
                            <input type="checkbox" name="days[]" value="<?= $num ?>" class="day-check"
                                   <?= in_array($num, [1,2,3,4,5]) ? 'checked' : '' ?>/>
                            <?= $name ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="working_days" id="assignWorkDays" value="1,2,3,4,5"/>
                </div>
                <div class="fg">
                    <label>Effective From</label>
                    <input type="date" name="effective_from" id="assignEffDate"/>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn ol" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn gn">Assign Schedule</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openEdit(s) {
    document.getElementById('editId').value    = s.id;
    document.getElementById('editName').value  = s.name;
    document.getElementById('editDays').value  = s.days_per_week;
    document.getElementById('editLeave').value = s.annual_leave_days;
    document.getElementById('editDesc').value  = s.description || '';
    openModal('editModal');
}

function openAssign(u) {
    document.getElementById('assignUserId').value   = u.id;
    document.getElementById('assignUserName').textContent = u.name;
    if (u.schedule_id) document.getElementById('assignSchedId').value = u.schedule_id;
    // Set working days checkboxes
    var current = (u.working_days || '1,2,3,4,5').split(',');
    document.querySelectorAll('.day-check').forEach(function(cb) {
        cb.checked = current.indexOf(cb.value) !== -1;
    });
    updateWorkDays();
    openModal('assignModal');
}

function updateWorkDays() {
    var checked = [];
    document.querySelectorAll('.day-check:checked').forEach(function(cb) { checked.push(cb.value); });
    document.getElementById('assignWorkDays').value = checked.join(',');
}
document.querySelectorAll('.day-check').forEach(function(cb) {
    cb.addEventListener('change', updateWorkDays);
});

// Close modals on backdrop click
document.querySelectorAll('.modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if (e.target === bg) bg.classList.remove('open'); });
});
</script>
<?php Layout::end(); ?>
