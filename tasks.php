<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';
$user = Auth::require();
$db   = getDB();
$uid  = (int)$user['id'];
$isAdmin   = Auth::isAdmin($user);
$isManager = $isAdmin || in_array($user['role'], ['hr','head_outsourcing','head_compliance','head_accounts','head_training','head_cso','cs_manager','training_manager']);
$error = $success = '';
$tab    = in_array($_GET['tab']??'',['mine','delegated','collaborating','all']) ? $_GET['tab'] : 'mine';
$filter = in_array($_GET['f']??'',['pending','in_progress','done','all']) ? $_GET['f'] : 'pending';
if ($tab === 'all' && !$isAdmin && !$isManager) $tab = 'mine';

/* ═══════════════════════════════════════════════════════════════
   POST ACTIONS
═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Add task ── */
    if ($action === 'add') {
        $title    = trim($_POST['title'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $due      = $_POST['due_date'] ?? '';
        $prio     = in_array($_POST['priority']??'',['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
        $assignTo = (int)($_POST['assign_to'] ?? 0) ?: null;
        $collabs  = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['collaborators'] ?? [])))));
        $inputReqs = array_map('intval', (array)($_POST['collab_input_req'] ?? []));

        if (!$title) {
            $error = 'Task title is required.';
        } else {
            $ownerUid = $assignTo ?? $uid;
            $db->prepare("INSERT INTO tasks (user_id,created_by,assigned_to,title,description,due_date,priority,status,source)
                          VALUES (?,?,?,?,?,?,?,'pending','manual')")
               ->execute([$ownerUid, $uid, $assignTo, $title, $desc, $due ?: null, $prio]);
            $taskId = (int)$db->lastInsertId();
            Auth::trackUsage($uid, 'tasks_created');

            foreach ($collabs as $cid) {
                if ($cid && $cid !== $ownerUid) {
                    $ir = in_array($cid, $inputReqs) ? 1 : 0;
                    $db->prepare("INSERT IGNORE INTO task_collaborators (task_id,user_id,role,input_required,added_by) VALUES (?,?,'collaborator',?,?)")
                       ->execute([$taskId, $cid, $ir, $uid]);
                }
            }
            header('Location: tasks.php?tab='.$tab.'&f='.$filter.'&ok=1');
            exit;
        }
    }

    /* ── Update status (AJAX) ── */
    elseif ($action === 'status') {
        header('Content-Type: application/json');
        $tid    = (int)($_POST['task_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($tid && in_array($status, ['pending','in_progress','done'])) {
            $stm = $db->prepare("SELECT user_id,created_by,assigned_to FROM tasks WHERE id=?");
            $stm->execute([$tid]);
            $t  = $stm->fetch();
            $ok = $t && ($t['user_id']==$uid || $t['created_by']==$uid || $t['assigned_to']==$uid || $isAdmin);
            if (!$ok) {
                $c = $db->prepare("SELECT id FROM task_collaborators WHERE task_id=? AND user_id=?");
                $c->execute([$tid,$uid]);
                $ok = (bool)$c->fetch();
            }
            if ($ok) {
                $completedAt = $status === 'done' ? ',completed_at=NOW()' : ($status === 'pending' ? ',completed_at=NULL' : '');
                $db->prepare("UPDATE tasks SET status=?,updated_at=NOW()$completedAt WHERE id=?")->execute([$status,$tid]);
                if ($status === 'done') Auth::trackUsage($uid, 'tasks_completed');
                echo json_encode(['ok'=>true]); exit;
            }
        }
        echo json_encode(['ok'=>false]); exit;
    }

    /* ── Resolve IT request task ── */
    elseif ($action === 'resolve_it') {
        header('Content-Type: application/json');
        $tid = (int)($_POST['task_id'] ?? 0);
        if ($tid) {
            $stm = $db->prepare("SELECT user_id, assigned_to, source FROM tasks WHERE id=?");
            $stm->execute([$tid]);
            $t = $stm->fetch();
            if ($t && ($t['user_id']==$uid || $t['assigned_to']==$uid || $isAdmin)) {
                $db->prepare("UPDATE tasks SET status='done', progress=100, completed_at=NOW(), updated_at=NOW() WHERE id=?")
                   ->execute([$tid]);
                // Find and close the linked IT request (match by assignee + source)
                $db->prepare("UPDATE it_requests SET status='resolved' WHERE id=(
                    SELECT source_it_request_id FROM tasks WHERE id=?
                ) AND status != 'resolved'")->execute([$tid]);
                Auth::auditLog($uid, 'it_request_resolved', "Resolved IT task #$tid");
                Auth::trackUsage($uid, 'tasks_completed');
                echo json_encode(['ok'=>true]); exit;
            }
        }
        echo json_encode(['ok'=>false]); exit;
    }

    /* ── Delete task ── */
    elseif ($action === 'delete') {
        $tid = (int)($_POST['task_id'] ?? 0);
        if ($isAdmin) {
            // Admins can delete any task
            $db->prepare("DELETE FROM tasks WHERE id=?")->execute([$tid]);
        } else {
            // Regular users can only delete tasks they created (manual source only)
            $db->prepare("DELETE FROM tasks WHERE id=? AND (user_id=? OR created_by=?) AND source='manual'")->execute([$tid,$uid,$uid]);
        }
        header('Location: tasks.php?tab='.$tab.'&f='.$filter.'&ok=2');
        exit;
    }

    /* ── Provide collaborator input (AJAX) ── */
    elseif ($action === 'provide_input') {
        header('Content-Type: application/json');
        $tid  = (int)($_POST['task_id'] ?? 0);
        $text = trim($_POST['input_text'] ?? '');
        if ($tid && $text) {
            $db->prepare("UPDATE task_collaborators SET input_text=?,input_provided_at=NOW() WHERE task_id=? AND user_id=?")
               ->execute([$text,$tid,$uid]);
            $db->prepare("INSERT INTO task_comments (task_id,user_id,comment,type) VALUES (?,?,?,'input')")
               ->execute([$tid,$uid,$text]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── Add comment (AJAX) ── */
    elseif ($action === 'add_comment') {
        header('Content-Type: application/json');
        $tid     = (int)($_POST['task_id'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        if (mb_strlen($comment) > 2000) { echo json_encode(['ok'=>false,'error'=>'Comment too long (max 2000 characters).']); exit; }
        if ($tid && $comment) {
            $db->prepare("INSERT INTO task_comments (task_id,user_id,comment,type) VALUES (?,?,?,'comment')")
               ->execute([$tid,$uid,$comment]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── Update progress (AJAX) ── */
    elseif ($action === 'update_progress') {
        header('Content-Type: application/json');
        $tid  = (int)($_POST['task_id'] ?? 0);
        $prog = min(100, max(0, (int)($_POST['progress'] ?? 0)));
        if ($tid) {
            try {
                $stm = $db->prepare("SELECT user_id,created_by,assigned_to FROM tasks WHERE id=?");
                $stm->execute([$tid]); $t2 = $stm->fetch();
                $ok = $t2 && ($t2['user_id']==$uid || $t2['created_by']==$uid || $t2['assigned_to']==$uid || $isAdmin);
                if (!$ok) { $c=$db->prepare("SELECT id FROM task_collaborators WHERE task_id=? AND user_id=?"); $c->execute([$tid,$uid]); $ok=(bool)$c->fetch(); }
                if ($ok) {
                    $autoStatus = $prog===100 ? ",status='done',completed_at=NOW()" : ($prog>0 ? ",status='in_progress'" : '');
                    $db->prepare("UPDATE tasks SET progress=?,updated_at=NOW()$autoStatus WHERE id=?")->execute([$prog,$tid]);
                    $db->prepare("INSERT INTO task_comments (task_id,user_id,comment,type) VALUES (?,?,?,'status_change')")
                       ->execute([$tid,$uid,"Progress updated to {$prog}%"]);
                    if ($prog===100) Auth::trackUsage($uid,'tasks_completed');
                    echo json_encode(['ok'=>true,'progress'=>$prog]); exit;
                }
                echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
            } catch (Exception $e) {
                echo json_encode(['ok'=>false,'error'=>'DB error']); exit;
            }
        }
        echo json_encode(['ok'=>false]); exit;
    }

    /* ── Add checklist item (AJAX) ── */
    elseif ($action === 'add_checklist') {
        header('Content-Type: application/json');
        $tid  = (int)($_POST['task_id'] ?? 0);
        $item = trim($_POST['item'] ?? '');
        if (mb_strlen($item) > 200) { echo json_encode(['ok'=>false,'error'=>'Checklist item too long (max 200 characters).']); exit; }
        if ($tid && $item) {
            try {
                $db->prepare("INSERT INTO task_checklists (task_id,item,created_by) VALUES (?,?,?)")
                   ->execute([$tid,$item,$uid]);
                echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
            } catch (Exception $e) {}
        }
        echo json_encode(['ok'=>false]); exit;
    }

    /* ── Toggle checklist item (AJAX) ── */
    elseif ($action === 'toggle_checklist') {
        header('Content-Type: application/json');
        $cid  = (int)($_POST['checklist_id'] ?? 0);
        $done = (int)($_POST['is_done'] ?? 0);
        if ($cid) {
            // Verify user has access to the task this checklist item belongs to
            $scopeStmt = $db->prepare("SELECT 1 FROM task_checklists cl
                JOIN tasks t ON t.id=cl.task_id
                WHERE cl.id=? AND (t.user_id=? OR t.created_by=? OR t.assigned_to=?
                OR EXISTS (SELECT 1 FROM task_collaborators tc WHERE tc.task_id=t.id AND tc.user_id=?))");
            $scopeStmt->execute([$cid,$uid,$uid,$uid,$uid]);
            if (!$scopeStmt->fetch() && !$isAdmin) {
                echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
            }
            if ($done) {
                $db->prepare("UPDATE task_checklists SET is_done=1,done_by=?,done_at=NOW() WHERE id=?")->execute([$uid,$cid]);
            } else {
                $db->prepare("UPDATE task_checklists SET is_done=0,done_by=NULL,done_at=NULL WHERE id=?")->execute([$cid]);
            }
            echo json_encode(['ok'=>true]); exit;
        }
        echo json_encode(['ok'=>false]); exit;
    }

    /* ── Delete checklist item (AJAX) ── */
    elseif ($action === 'delete_checklist') {
        header('Content-Type: application/json');
        $cid = (int)($_POST['checklist_id'] ?? 0);
        if ($cid) {
            $db->prepare("DELETE FROM task_checklists WHERE id=? AND created_by=?")->execute([$cid,$uid]);
            echo json_encode(['ok'=>true]); exit;
        }
        echo json_encode(['ok'=>false]); exit;
    }

    /* ── Get task detail (AJAX) ── */
    elseif ($action === 'get_detail') {
        header('Content-Type: application/json');
        $tid = (int)($_POST['task_id'] ?? 0);
        $stm = $db->prepare("SELECT t.*,
            u_own.name as owner_name, u_cr.name as creator_name, u_as.name as assignee_name
            FROM tasks t
            LEFT JOIN users u_own ON u_own.id=t.user_id
            LEFT JOIN users u_cr  ON u_cr.id=t.created_by
            LEFT JOIN users u_as  ON u_as.id=t.assigned_to
            WHERE t.id=?");
        $stm->execute([$tid]);
        $t = $stm->fetch(PDO::FETCH_ASSOC);
        if (!$t) { echo json_encode(['ok'=>false]); exit; }

        $canSee = ($t['user_id']==$uid || $t['created_by']==$uid || $t['assigned_to']==$uid || $isAdmin || $isManager);
        if (!$canSee) {
            $c = $db->prepare("SELECT id FROM task_collaborators WHERE task_id=? AND user_id=?");
            $c->execute([$tid,$uid]);
            $canSee = (bool)$c->fetch();
        }
        if (!$canSee) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

        $cs = $db->prepare("SELECT tc.*,u.name,u.role as urole FROM task_collaborators tc JOIN users u ON u.id=tc.user_id WHERE tc.task_id=? ORDER BY tc.added_at");
        $cs->execute([$tid]);
        $collabs = $cs->fetchAll(PDO::FETCH_ASSOC);

        $coms = $db->prepare("SELECT tc.*,u.name FROM task_comments tc JOIN users u ON u.id=tc.user_id WHERE tc.task_id=? ORDER BY tc.created_at ASC");
        $coms->execute([$tid]);
        $comments = $coms->fetchAll(PDO::FETCH_ASSOC);

        $chkSt = $db->prepare("SELECT cl.*,u.name as created_by_name FROM task_checklists cl LEFT JOIN users u ON u.id=cl.created_by WHERE cl.task_id=? ORDER BY cl.created_at ASC");
        $chkSt->execute([$tid]);
        $checklists = $chkSt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok'=>true,'task'=>$t,'collaborators'=>$collabs,'comments'=>$comments,'checklists'=>$checklists,'uid'=>$uid]);
        exit;
    }
}

/* ═══════════════════════════════════════════════════════════════
   LOAD DATA
═══════════════════════════════════════════════════════════════ */

$fSql = $filter === 'all' ? '' : 'AND t.status=?';
$fPrm = $filter === 'all' ? [] : [$filter];
$ord  = "ORDER BY FIELD(t.priority,'urgent','high','normal','low'), CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC, t.created_at DESC";
$cols = "t.*, u_cr.name as creator_name, u_as.name as assignee_name,
         (SELECT COUNT(*) FROM task_collaborators tc WHERE tc.task_id=t.id) as collab_count,
         (SELECT COUNT(*) FROM task_collaborators tc WHERE tc.task_id=t.id AND tc.input_required=1 AND tc.input_provided_at IS NULL) as pending_inputs";

if ($tab === 'mine') {
    $q = "SELECT $cols FROM tasks t
          LEFT JOIN users u_cr ON u_cr.id=t.created_by
          LEFT JOIN users u_as ON u_as.id=t.assigned_to
          WHERE t.user_id=? $fSql $ord";
    $p = [$uid, ...$fPrm];
} elseif ($tab === 'delegated') {
    $q = "SELECT t.*, u_own.name as owner_name, u_cr.name as creator_name, u_as.name as assignee_name,
          (SELECT COUNT(*) FROM task_collaborators tc WHERE tc.task_id=t.id) as collab_count,
          (SELECT COUNT(*) FROM task_collaborators tc WHERE tc.task_id=t.id AND tc.input_required=1 AND tc.input_provided_at IS NULL) as pending_inputs
          FROM tasks t
          LEFT JOIN users u_own ON u_own.id=t.user_id
          LEFT JOIN users u_cr  ON u_cr.id=t.created_by
          LEFT JOIN users u_as  ON u_as.id=t.assigned_to
          WHERE t.created_by=? AND t.user_id!=? $fSql $ord";
    $p = [$uid, $uid, ...$fPrm];
} elseif ($tab === 'collaborating') {
    $q = "SELECT $cols,
          tc_me.input_required as my_input_req, tc_me.input_provided_at as my_input_done,
          u_own.name as owner_name
          FROM tasks t
          JOIN task_collaborators tc_me ON tc_me.task_id=t.id AND tc_me.user_id=?
          LEFT JOIN users u_own ON u_own.id=t.user_id
          LEFT JOIN users u_cr  ON u_cr.id=t.created_by
          LEFT JOIN users u_as  ON u_as.id=t.assigned_to
          WHERE 1=1 $fSql $ord";
    $p = [$uid, ...$fPrm];
} else {
    $q = "SELECT t.*, u_own.name as owner_name, u_cr.name as creator_name, u_as.name as assignee_name,
          (SELECT COUNT(*) FROM task_collaborators tc WHERE tc.task_id=t.id) as collab_count,
          (SELECT COUNT(*) FROM task_collaborators tc WHERE tc.task_id=t.id AND tc.input_required=1 AND tc.input_provided_at IS NULL) as pending_inputs
          FROM tasks t
          LEFT JOIN users u_own ON u_own.id=t.user_id
          LEFT JOIN users u_cr  ON u_cr.id=t.created_by
          LEFT JOIN users u_as  ON u_as.id=t.assigned_to
          WHERE 1=1 $fSql $ord";
    $p = [...$fPrm];
}
$stm = $db->prepare($q);
$stm->execute($p);
$tasks = $stm->fetchAll();

/* Stats — always your personal task totals (stable across all tabs) */
$ss = $db->prepare("SELECT SUM(status='pending') p, SUM(status='in_progress') i, SUM(status='done') d, SUM(due_date<CURDATE() AND status!='done') ov FROM tasks WHERE user_id=?");
$ss->execute([$uid]);
$ts = $ss->fetch();

/* Badge counts for tabs */
$dBadge = $db->prepare("SELECT COUNT(*) FROM tasks WHERE created_by=? AND user_id!=? AND status!='done'");
$dBadge->execute([$uid,$uid]);
$delegBadge = (int)$dBadge->fetchColumn();

$iBadge = $db->prepare("SELECT COUNT(*) FROM task_collaborators WHERE user_id=? AND input_required=1 AND input_provided_at IS NULL");
$iBadge->execute([$uid]);
$inputBadge = (int)$iBadge->fetchColumn();

$cBadge = $db->prepare("SELECT COUNT(*) FROM task_collaborators tc JOIN tasks t ON t.id=tc.task_id WHERE tc.user_id=? AND t.status!='done'");
$cBadge->execute([$uid]);
$collabBadge = (int)$cBadge->fetchColumn();

/* All users for dropdowns */
$auStmt = $db->prepare("SELECT id,name,role FROM users WHERE is_active=1 AND id!=? ORDER BY name");
$auStmt->execute([$uid]);
$allUsers = $auStmt->fetchAll();

$pc   = ['urgent'=>'#dc2626','high'=>'#f59e0b','normal'=>'#3b82f6','low'=>'#94a3b8'];
$pl   = ['urgent'=>'Urgent','high'=>'High','normal'=>'Normal','low'=>'Low'];
$rShort = array_map(function($r){ return $r['label']; }, ROLES);

Layout::shell($user, 'tasks', 0, 'Tasks');
?>
<style>
/* Layout */
.tkpage{max-width:1100px;margin:24px auto;padding:0 16px 60px;display:grid;grid-template-columns:1fr 310px;gap:18px;align-items:start;overflow-y:auto;height:100%;min-height:0;}

/* Stats */
.scg{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.sc{background:var(--w);border-radius:10px;padding:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center;border-top:3px solid transparent;}
.sc.pend{border-color:var(--warn);}.sc.inprog{border-color:#3b82f6;}.sc.done{border-color:var(--green);}.sc.over{border-color:var(--red);}
.scv{font-size:22px;font-weight:700;color:var(--navy);}
.scl{font-size:10.5px;color:var(--g400);margin-top:3px;}

/* Tabs */
.tabs{display:flex;gap:0;margin-bottom:14px;background:var(--w);border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:4px;overflow:hidden;}
.tab{padding:7px 14px;border-radius:7px;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--g500);transition:all .12s;white-space:nowrap;display:flex;align-items:center;gap:5px;}
.tab:hover{color:var(--navy);background:var(--g50);}
.tab.on{background:var(--navy);color:#fff;}
.badge{background:var(--red);color:#fff;border-radius:20px;font-size:10px;padding:1px 6px;font-weight:700;min-width:18px;text-align:center;}
.tab.on .badge{background:rgba(255,255,255,.3);}

/* Filter pills */
.filts{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;}
.filt{padding:5px 12px;border-radius:20px;font-size:12px;font-weight:500;text-decoration:none;border:1.5px solid var(--g200);color:var(--g700);transition:all .12s;}
.filt.on{background:var(--navy);color:#fff;border-color:var(--navy);}

/* Card */
.tkcard{background:var(--w);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
.tkchd{padding:12px 18px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.tkcht{font-size:13px;font-weight:700;color:var(--navy);}

/* Task items */
.ti{display:flex;align-items:flex-start;gap:10px;padding:12px 18px;border-bottom:1px solid var(--g50);transition:background .1s;}
.ti:hover{background:var(--g50);}
.ti.done-task{opacity:.55;}
.ti:last-child{border-bottom:none;}
.tcb{width:20px;height:20px;border-radius:50%;border:2px solid var(--g300);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:3px;transition:all .15s;background:transparent;}
.tcb.chk{background:var(--green);border-color:var(--green);color:#fff;font-size:10px;}
.tif{flex:1;min-width:0;cursor:pointer;}
.tif:hover .ttl{text-decoration:underline;text-underline-offset:2px;}
.ttl{font-size:13.5px;font-weight:600;color:var(--g900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.done-task .ttl{text-decoration:line-through;color:var(--g400);}
.tdc{font-size:12px;color:var(--g500);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tm{display:flex;align-items:center;gap:6px;margin-top:5px;flex-wrap:wrap;}
.pd{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.due{font-size:11px;font-weight:600;}
.due.ov{color:var(--red);}.due.so{color:var(--warn);}.due.ok{color:var(--green);}
.tpill{font-size:10.5px;padding:2px 7px;border-radius:20px;font-weight:600;white-space:nowrap;}
.tpill-ip{background:#dbeafe;color:#1e40af;}
.tpill-it{background:#fce7f3;color:#9d174d;}
.tpill-input{background:#fef3c7;color:#92400e;}
.tpill-from{background:var(--g100);color:var(--g500);}
.tpill-collab{background:#f3e8ff;color:#7c3aed;}
.ta{display:flex;gap:4px;flex-shrink:0;align-items:center;}
.tact{padding:4px 8px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;border:1.5px solid var(--g200);background:transparent;color:var(--g700);font-family:inherit;transition:all .1s;}
.tact:hover{border-color:var(--navy);color:var(--navy);}
.tact.rd:hover{border-color:var(--red);color:var(--red);}
.tact.start:hover{border-color:var(--green);color:var(--green);}
.tact.resolve-it{border-color:#059669;color:#059669;font-size:11px;padding:4px 8px;white-space:nowrap;}
.tact.resolve-it:hover{background:#059669;color:#fff;border-color:#059669;}

/* Add form */
.tkfg{margin-bottom:12px;}
.tkfg label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
.tkfg input,.tkfg select,.tkfg textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:inherit;color:var(--g900);outline:none;background:var(--g50);}
.tkfg input:focus,.tkfg select:focus,.tkfg textarea:focus{border-color:var(--green);background:var(--w);}
.tkfg textarea{resize:none;height:60px;}
.tkbtn{padding:9px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;background:var(--green);color:#fff;width:100%;display:flex;align-items:center;justify-content:center;gap:5px;transition:background .15s;}
.tkbtn:hover{background:#4d7c10;}
.tkbtn:disabled{background:var(--g300);cursor:not-allowed;}
.tkal{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;}
.filts.kanban-mode .filt{display:none;}
.prog-saved{font-size:11px;font-weight:600;color:var(--green);margin-left:6px;opacity:0;transition:opacity .2s;}
.tkal.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.tkal.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.tkempty{padding:32px;text-align:center;color:var(--g400);font-size:13px;}

/* Collaborator builder */
.collab-list{display:flex;flex-direction:column;gap:6px;margin-bottom:8px;}
.collab-item{display:flex;align-items:center;gap:7px;background:var(--g50);border-radius:7px;padding:6px 9px;font-size:12px;}
.collab-item-name{flex:1;font-weight:600;color:var(--g700);}
.collab-toggle{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--g500);white-space:nowrap;}
.collab-toggle input[type=checkbox]{width:14px;height:14px;margin:0;}
.collab-rm{background:none;border:none;cursor:pointer;color:var(--g400);font-size:14px;line-height:1;padding:0 2px;}
.collab-rm:hover{color:var(--red);}
.add-collab-row{display:flex;gap:6px;}
.add-collab-row select{flex:1;border:1.5px solid var(--g200);border-radius:8px;padding:7px 10px;font-size:12.5px;font-family:inherit;color:var(--g900);outline:none;background:var(--g50);}
.add-collab-btn{padding:7px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--green);background:transparent;color:var(--green);font-family:inherit;white-space:nowrap;}
.add-collab-btn:hover{background:var(--green);color:#fff;}

/* Detail panel */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;display:none;backdrop-filter:blur(2px);}
.panel{position:fixed;top:0;right:0;height:100%;width:480px;max-width:95vw;background:var(--w);box-shadow:0 4px 20px rgba(0,40,80,.15);z-index:101;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .25s ease;}
.panel.open{transform:translateX(0);}
.panel-hd{padding:16px 20px;border-bottom:1px solid var(--g100);display:flex;align-items:flex-start;gap:10px;}
.panel-title{flex:1;font-size:16px;font-weight:700;color:var(--navy);line-height:1.3;}
.panel-close{background:none;border:none;cursor:pointer;font-size:20px;color:var(--g400);line-height:1;padding:2px;}
.panel-close:hover{color:var(--g900);}
.panel-body{flex:1;overflow-y:auto;padding:16px 20px;}
.panel-sect{margin-bottom:18px;}
.panel-sect-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g400);margin-bottom:8px;}
.meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.meta-item{background:var(--g50);border-radius:8px;padding:8px 10px;}
.meta-label{font-size:10px;color:var(--g400);font-weight:600;text-transform:uppercase;letter-spacing:.05em;}
.meta-val{font-size:13px;font-weight:600;color:var(--g900);margin-top:2px;}
.collab-card{background:var(--g50);border-radius:8px;padding:10px 12px;margin-bottom:7px;display:flex;align-items:flex-start;gap:10px;}
.collab-avatar{width:30px;height:30px;border-radius:50%;background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;}
.collab-info{flex:1;}
.collab-name{font-size:13px;font-weight:600;}
.collab-meta{font-size:11.5px;color:var(--g400);margin-top:2px;}
.collab-input-text{background:var(--w);border-radius:6px;padding:7px 10px;font-size:12px;color:var(--g700);border:1px solid var(--g200);margin-top:6px;line-height:1.5;}
.comment-item{margin-bottom:12px;display:flex;gap:9px;}
.comment-av{width:28px;height:28px;border-radius:50%;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;margin-top:2px;}
.comment-av.input-av{background:#f59e0b;}
.comment-body{flex:1;}
.comment-name{font-size:12px;font-weight:600;color:var(--g700);}
.comment-time{font-size:11px;color:var(--g400);margin-left:6px;}
.comment-text{font-size:13px;color:var(--g900);margin-top:3px;line-height:1.5;}
.comment-type-badge{font-size:10px;font-weight:700;color:#d97706;margin-left:5px;}
.input-area{display:flex;gap:7px;margin-top:10px;}
.input-area textarea{flex:1;height:50px;resize:none;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:inherit;color:var(--g900);outline:none;background:var(--g50);}
.input-area textarea:focus{border-color:var(--green);background:var(--w);}
.send-btn{padding:7px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:var(--navy);color:#fff;font-family:inherit;align-self:flex-end;}
.send-btn:hover{background:var(--green);}
.provide-input-box{background:#fffbeb;border:1.5px solid #fcd34d;border-radius:10px;padding:12px;margin-bottom:12px;}
.provide-input-box h4{font-size:12px;font-weight:700;color:#92400e;margin-bottom:7px;}
.provide-input-box textarea{height:70px;background:var(--w);width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:inherit;color:var(--g900);outline:none;resize:none;}
.provide-input-btn{margin-top:6px;padding:7px 16px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:#d97706;color:#fff;font-family:inherit;}
.status-row{display:flex;gap:6px;margin-top:12px;}
.s-btn{padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--g200);background:transparent;color:var(--g700);font-family:inherit;transition:all .12s;}
.s-btn.active{background:var(--navy);color:#fff;border-color:var(--navy);}
.s-btn:not(.active):hover{border-color:var(--navy);color:var(--navy);}

/* Progress bar on cards */
.tprog{margin-top:6px;height:4px;border-radius:4px;background:var(--g100);overflow:hidden;}
.tprog-bar{height:100%;border-radius:4px;background:var(--green);transition:width .3s;}
/* Inline progress slider directly on card — no modal needed */
.card-prog{display:flex;align-items:center;gap:7px;margin-top:7px;padding:1px 0;cursor:default;}
.card-prog-slider{flex:1;height:5px;-webkit-appearance:none;appearance:none;background:linear-gradient(to right,var(--green) var(--pct,0%),var(--g150,#e2e8f0) var(--pct,0%));border-radius:4px;outline:none;cursor:pointer;transition:height .1s;}
.card-prog-slider::-webkit-slider-thumb{-webkit-appearance:none;width:13px;height:13px;border-radius:50%;background:var(--navy);border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.25);opacity:0;transition:opacity .15s;cursor:pointer;}
.ti:hover .card-prog-slider::-webkit-slider-thumb{opacity:1;}
.ti:hover .card-prog-slider{height:6px;}
.card-prog-pct{font-size:10.5px;font-weight:600;color:var(--g400);min-width:26px;text-align:right;transition:color .15s;}
.card-prog-pct.saving{color:var(--green);}

/* View toggle */
.view-toggle{display:inline-flex;gap:0;background:var(--w);border-radius:8px;border:1.5px solid var(--g200);overflow:hidden;margin-left:auto;}
.vt-btn{padding:5px 10px;border:none;background:transparent;cursor:pointer;font-size:13px;color:var(--g500);font-family:inherit;transition:all .12s;line-height:1;}
.vt-btn.active{background:var(--navy);color:#fff;}

/* Kanban */
.kanban-board{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;}
.kanban-col{background:var(--g50);border-radius:12px;min-height:300px;display:flex;flex-direction:column;}
.kanban-col-hd{padding:10px 14px;font-size:12.5px;font-weight:700;color:var(--g600);border-bottom:2px solid var(--g200);display:flex;align-items:center;justify-content:space-between;}
.kanban-col.k-pend .kanban-col-hd{border-color:#f59e0b;color:#92400e;}
.kanban-col.k-ip .kanban-col-hd{border-color:#3b82f6;color:#1e40af;}
.kanban-col.k-done .kanban-col-hd{border-color:var(--green);color:#166534;}
.kanban-items{padding:10px;flex:1;display:flex;flex-direction:column;gap:8px;min-height:200px;}
.kanban-items.drag-over{background:#f0fdf4;border-radius:0 0 12px 12px;}
.kanban-card{background:var(--w);border-radius:8px;padding:10px 12px;box-shadow:0 1px 3px rgba(0,0,0,.08);cursor:pointer;border-left:3px solid #94a3b8;transition:box-shadow .15s;}
.kanban-card:hover{box-shadow:0 3px 8px rgba(0,0,0,.14);}
.kanban-card.dragging{opacity:.4;cursor:grabbing;}
.kanban-card[data-priority=urgent]{border-left-color:#dc2626;}
.kanban-card[data-priority=high]{border-left-color:#f59e0b;}
.kanban-card[data-priority=normal]{border-left-color:#3b82f6;}
.kc-title{font-size:13px;font-weight:600;color:var(--g900);margin-bottom:4px;line-height:1.3;}
.kc-meta{font-size:11px;color:var(--g400);}
/* Kanban progress — interactive */
.kc-prog-wrap{margin-top:8px;}
.kc-prog-header{display:flex;align-items:center;gap:6px;cursor:pointer;user-select:none;}
.kc-prog-bar-bg{flex:1;height:5px;border-radius:5px;background:var(--g150);overflow:hidden;transition:height .1s;}
.kc-prog-bar-fill{height:100%;border-radius:5px;transition:width .25s;}
.kc-prog-header:hover .kc-prog-bar-bg{height:7px;}
.kc-prog-pct{font-size:10.5px;font-weight:700;color:var(--g500);min-width:28px;text-align:right;transition:color .15s;}
.kc-prog-header:hover .kc-prog-pct{color:var(--navy);}
.kc-prog-slider-row{padding:5px 0 2px;display:none;}
.kc-prog-slider-row input[type=range]{width:100%;cursor:pointer;height:4px;-webkit-appearance:none;appearance:none;background:var(--g150);border-radius:4px;outline:none;}
.kc-prog-slider-row input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:14px;height:14px;border-radius:50%;background:var(--navy);border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.25);cursor:pointer;}
/* Sync button in detail panel checklist section */
.sync-prog-btn{background:none;border:1px solid var(--green);color:var(--green);border-radius:6px;font-size:10.5px;font-weight:600;padding:2px 8px;cursor:pointer;font-family:inherit;vertical-align:middle;}
.sync-prog-btn:hover{background:var(--green);color:#fff;}

/* Progress slider in panel */
.prog-slider-wrap{margin-top:8px;}
.prog-slider{width:100%;height:6px;-webkit-appearance:none;appearance:none;border-radius:6px;background:var(--g200);outline:none;cursor:pointer;}
.prog-slider::-webkit-slider-thumb{-webkit-appearance:none;width:18px;height:18px;border-radius:50%;background:var(--navy);cursor:pointer;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.2);}
.prog-pct-display{font-size:24px;font-weight:700;color:var(--navy);}
.prog-bar-wrap{height:8px;border-radius:8px;background:var(--g200);overflow:hidden;margin:8px 0;}
.prog-bar-fill{height:100%;border-radius:8px;background:var(--green);transition:width .2s;}

/* Checklist in panel */
.checklist-item{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--g100);}
.checklist-item:last-child{border-bottom:none;}
.checklist-cb{width:18px;height:18px;border-radius:4px;border:2px solid var(--g300);cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all .12s;background:transparent;font-size:10px;line-height:1;}
.checklist-cb.done{background:var(--green);border-color:var(--green);color:#fff;}
.checklist-text{flex:1;font-size:13px;color:var(--g900);}
.checklist-text.done{text-decoration:line-through;color:var(--g400);}
.checklist-del{background:none;border:none;cursor:pointer;color:var(--g300);font-size:14px;padding:0 2px;line-height:1;}
.checklist-del:hover{color:var(--red);}
.checklist-add-row{display:flex;gap:6px;margin-top:8px;}
.checklist-add-row input{flex:1;border:1.5px solid var(--g200);border-radius:7px;padding:6px 10px;font-size:12.5px;font-family:inherit;color:var(--g900);outline:none;background:var(--g50);}
.checklist-add-row input:focus{border-color:var(--green);background:var(--w);}
.checklist-add-btn{padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--green);background:transparent;color:var(--green);font-family:inherit;white-space:nowrap;}
.checklist-add-btn:hover{background:var(--green);color:#fff;}
.checklist-summary{font-size:11px;color:var(--g400);margin-bottom:6px;}

@media(max-width:720px){
    .tkpage{grid-template-columns:1fr;}
    .scg{grid-template-columns:repeat(2,1fr);}
    .tabs{overflow-x:auto;}
    .panel{width:100%;max-width:100%;}
    .kanban-board{grid-template-columns:1fr;}
}
</style>

<div class="tkpage">
<div>
<!-- Stats -->
<div class="scg">
    <div class="sc pend"><div class="scv" id="st_p"><?=($ts['p']??0)?></div><div class="scl">Pending</div></div>
    <div class="sc inprog"><div class="scv" id="st_i"><?=($ts['i']??0)?></div><div class="scl">In Progress</div></div>
    <div class="sc done"><div class="scv" id="st_d"><?=($ts['d']??0)?></div><div class="scl">Done</div></div>
    <div class="sc over"><div class="scv" id="st_ov"><?=($ts['ov']??0)?></div><div class="scl">Overdue</div></div>
</div>

<!-- Tabs -->
<div class="tabs">
    <a class="tab <?=$tab==='mine'?'on':''?>" href="tasks.php?tab=mine&f=<?=$filter?>">&#128203; My Tasks</a>
    <a class="tab <?=$tab==='delegated'?'on':''?>" href="tasks.php?tab=delegated&f=<?=$filter?>">
        &#128228; Delegated<?php if($delegBadge>0):?> <span class="badge"><?=$delegBadge?></span><?php endif;?>
    </a>
    <a class="tab <?=$tab==='collaborating'?'on':''?>" href="tasks.php?tab=collaborating&f=<?=$filter?>">
        &#129309; Collaborating<?php if($collabBadge>0):?> <span class="badge"><?=$inputBadge>0?$inputBadge:$collabBadge?></span><?php endif;?>
    </a>
    <?php if ($isAdmin || $isManager): ?>
    <a class="tab <?=$tab==='all'?'on':''?>" href="tasks.php?tab=all&f=<?=$filter?>">&#127962; All</a>
    <?php endif; ?>
</div>

<!-- Filter pills + view toggle -->
<div class="filts" style="align-items:center;">
    <?php foreach(['pending'=>'&#9203; Pending','in_progress'=>'&#128260; In Progress','done'=>'&#9989; Done','all'=>'&#128203; All'] as $f=>$l): ?>
    <a class="filt <?=$filter===$f?'on':''?>" href="tasks.php?tab=<?=$tab?>&f=<?=$f?>"><?=$l?></a>
    <?php endforeach; ?>
    <div class="view-toggle">
        <button class="vt-btn active" data-view="list" onclick="toggleView('list')" title="List view">&#9776;</button>
        <button class="vt-btn" data-view="kanban" onclick="toggleView('kanban')" title="Kanban board">&#9646;&#9646;&#9646;</button>
    </div>
</div>

<!-- Task list (list view) — hidden until JS shows the correct view -->
<div id="listView" style="display:none;">
<div class="tkcard">
    <div class="tkchd">
        <div>
            <div class="tkcht">
                <?php echo $tab==='mine'?'My Tasks':($tab==='delegated'?'Tasks I Delegated':($tab==='collaborating'?'Collaborating On':'All Tasks')); ?>
            </div>
            <?php if($tab==='delegated'): ?>
            <div style="font-size:11px;color:var(--g400);margin-top:2px;">Tasks you created and assigned to others</div>
            <?php elseif($tab==='collaborating'): ?>
            <div style="font-size:11px;color:var(--g400);margin-top:2px;">Tasks where you are listed as a collaborator</div>
            <?php elseif($tab==='all'): ?>
            <div style="font-size:11px;color:var(--g400);margin-top:2px;">All tasks across the organisation</div>
            <?php endif; ?>
        </div>
        <span style="font-size:12px;color:var(--g400);"><?=count($tasks)?> task<?=count($tasks)!==1?'s':''?></span>
    </div>
    <?php if(empty($tasks)): ?>
    <div class="tkempty">No <?=$filter==='all'?'':$filter?> tasks here &#10003;</div>
    <?php else: foreach($tasks as $t):
        $isDone  = $t['status']==='done';
        $isIP    = $t['status']==='in_progress';
        $dTs     = $t['due_date'] ? strtotime($t['due_date']) : null;
        $tod     = strtotime(date('Y-m-d'));
        $dc=$dt='';
        if ($dTs) {
            $diff = ($dTs - $tod)/86400;
            if ($diff < 0)      { $dc='ov'; $dt='&#9888; Overdue'; }
            elseif ($diff == 0) { $dc='so'; $dt='Due today'; }
            elseif ($diff <= 2) { $dc='so'; $dt='Due '.date('d M',$dTs); }
            else                { $dc='ok'; $dt='Due '.date('d M',$dTs); }
        }
        $isMine    = ($t['user_id']==$uid);
        $isCreator = ($t['created_by']==$uid || ($t['created_by']===null && $t['user_id']==$uid));
        $assignedBy = (!$isCreator && isset($t['creator_name'])) ? $t['creator_name'] : null;
        $assignedTo = $t['assigned_to'] ? ($t['assignee_name']??null) : null;
    ?>
    <div class="ti <?=$isDone?'done-task':''?>" id="ti<?=$t['id']?>">
        <div class="tcb <?=$isDone?'chk':''?>" onclick="event.stopPropagation();toggleTask(<?=$t['id']?>,<?=$isDone?'false':'true'?>)"><?=$isDone?'&#10003;':''?></div>
        <div class="tif" onclick="openDetail(<?=$t['id']?>)">
            <div class="ttl"><?=htmlspecialchars($t['title'])?></div>
            <?php if($t['description']): ?><div class="tdc"><?=htmlspecialchars(substr($t['description'],0,90))?></div><?php endif; ?>
            <div class="tm">
                <div class="pd" style="background:<?=$pc[$t['priority']]??'#94a3b8'?>"></div>
                <span style="font-size:11px;color:var(--g500);"><?=$pl[$t['priority']]??''?></span>
                <?php if($dt): ?><span class="due <?=$dc?>"><?=$dt?></span><?php endif; ?>
                <?php if($isIP): ?><span class="tpill tpill-ip">In Progress</span><?php endif; ?>
                <?php if(($t['source']??'manual')==='it_request'): ?><span class="tpill tpill-it">IT Request</span><?php endif; ?>
                <?php if($assignedBy): ?><span class="tpill tpill-from">From <?=htmlspecialchars($assignedBy)?></span><?php endif; ?>
                <?php if($assignedTo && $tab==='delegated'): ?><span class="tpill tpill-from">&rarr; <?=htmlspecialchars($assignedTo)?></span><?php endif; ?>
                <?php if(($t['collab_count']??0)>0): ?><span class="tpill tpill-collab">&#128101; <?=$t['collab_count']?></span><?php endif; ?>
                <?php if(($t['pending_inputs']??0)>0): ?><span class="tpill tpill-input">&#9889; <?=$t['pending_inputs']?> input needed</span><?php endif; ?>
                <?php if($tab==='collaborating' && ($t['my_input_req']??0) && !($t['my_input_done']??false)): ?>
                <span class="tpill tpill-input">&#9889; Your input needed</span>
                <?php endif; ?>
                <?php if($tab==='collaborating' && isset($t['owner_name'])): ?>
                <span class="tpill tpill-from">By <?=htmlspecialchars($t['owner_name'])?></span>
                <?php endif; ?>
            </div>
            <?php $prog = (int)($t['progress']??0); if(!$isDone): ?>
            <div class="card-prog" onclick="event.stopPropagation()">
                <input type="range" min="0" max="100" step="5" value="<?=$prog?>"
                       class="card-prog-slider" id="cps<?=$t['id']?>"
                       style="--pct:<?=$prog?>%"
                       oninput="cpLive(<?=$t['id']?>,this.value)"
                       onchange="cpSave(<?=$t['id']?>,parseInt(this.value))">
                <span class="card-prog-pct" id="cpp<?=$t['id']?>"><?=$prog?>%</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="ta" onclick="event.stopPropagation()">
            <?php if(!$isDone && !$isIP): ?>
            <button class="tact start" onclick="setStatus(<?=$t['id']?>,'in_progress')">&#9654;</button>
            <?php endif; ?>
            <?php if(!$isDone && $isIP): ?>
            <button class="tact start" onclick="setStatus(<?=$t['id']?>,'done')">&#10003;</button>
            <?php endif; ?>
            <?php if(!$isDone && ($t['source']??'manual')==='it_request' && ($t['user_id']==$uid || $t['assigned_to']==$uid || $isAdmin)): ?>
            <button class="tact resolve-it" title="Mark Resolved &amp; close ticket"
                    onclick="resolveIT(<?=$t['id']?>)">&#10003; Resolve</button>
            <?php endif; ?>
            <?php if($isCreator || $isAdmin): ?>
            <?php if(($t['source']??'manual')==='manual' || $isAdmin): ?>
            <form method="POST" style="margin:0">
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="task_id" value="<?=$t['id']?>"/>
                <?= Auth::csrfField() ?>
                <button type="submit" class="tact rd" onclick="return confirm('Delete this task?')">&#128465;</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div><!-- /tkcard -->
</div><!-- /listView -->

<!-- Kanban board (kanban view) -->
<div id="kanbanView" style="display:none;">
<div class="kanban-board">
    <div class="kanban-col k-pend">
        <div class="kanban-col-hd">&#9203; Pending <span id="kc_pend_count" style="font-size:11px;font-weight:400;color:var(--g400);"></span></div>
        <div class="kanban-items" id="kc_pending"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="kDrop(event,'pending')"></div>
    </div>
    <div class="kanban-col k-ip">
        <div class="kanban-col-hd">&#128260; In Progress <span id="kc_ip_count" style="font-size:11px;font-weight:400;color:var(--g400);"></span></div>
        <div class="kanban-items" id="kc_in_progress"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="kDrop(event,'in_progress')"></div>
    </div>
    <div class="kanban-col k-done">
        <div class="kanban-col-hd">&#9989; Done <span id="kc_done_count" style="font-size:11px;font-weight:400;color:var(--g400);"></span></div>
        <div class="kanban-items" id="kc_done"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="kDrop(event,'done')"></div>
    </div>
</div>
</div><!-- /kanbanView -->
<script>
/* Decide which view to show — runs synchronously before browser paints */
try {
    var _savedView = localStorage.getItem('hri_task_view') || 'list';
    if (_savedView === 'kanban') {
        var _sp = new URLSearchParams(window.location.search);
        if (_sp.get('f') !== 'all') {
            /* Force f=all so kanban always has all tasks */
            _sp.set('f', 'all');
            window.location.replace('tasks.php?' + _sp.toString());
        } else {
            /* Show kanban immediately — listView stays display:none */
            document.getElementById('kanbanView').style.display = 'block';
            var _ff = document.querySelector('.filts');
            if (_ff) _ff.classList.add('kanban-mode');
            document.querySelectorAll('.vt-btn').forEach(function(b){
                b.classList.toggle('active', b.dataset.view === 'kanban');
            });
        }
    } else {
        /* Show list view */
        document.getElementById('listView').style.display = 'block';
    }
} catch(e) {
    /* Failsafe — show list */
    var _lv = document.getElementById('listView');
    if (_lv) _lv.style.display = 'block';
}
</script>

</div><!-- /left column -->

<!-- Sidebar -->
<div>
    <?php $ok=intval($_GET['ok']??0); if($ok===1): ?><div class="tkal ok">&#9989; Task created.</div><?php elseif($ok===2): ?><div class="tkal ok">&#9989; Task deleted.</div><?php endif; ?>
    <?php if($error):   ?><div class="tkal er">&#9888;&#65039; <?=htmlspecialchars($error)?></div><?php endif; ?>

    <div class="tkcard" style="border-top:3px solid var(--green);">
        <div class="tkchd"><div class="tkcht">&#10133; New Task</div></div>
        <div style="padding:14px 18px;">
        <form method="POST" id="addForm">
            <input type="hidden" name="action" value="add"/>
            <?= Auth::csrfField() ?>
            <div class="tkfg"><label>Title *</label><input type="text" name="title" placeholder="What needs to be done?" required/></div>
            <div class="tkfg"><label>Description</label><textarea name="description" placeholder="Optional detail&hellip;"></textarea></div>
            <div class="tkfg"><label>Due Date</label><input type="date" name="due_date" min="<?=date('Y-m-d')?>"/></div>
            <div class="tkfg"><label>Priority</label>
                <select name="priority">
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div class="tkfg">
                <label>Assign To</label>
                <select name="assign_to" id="assignTo">
                    <option value="">Myself</option>
                    <?php foreach($allUsers as $u): ?>
                    <option value="<?=$u['id']?>"><?=htmlspecialchars($u['name'])?> (<?=$rShort[$u['role']]??$u['role']?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tkfg">
                <label>Collaborators</label>
                <div class="collab-list" id="collabList"></div>
                <div class="add-collab-row">
                    <select id="collabPick">
                        <option value="">Add collaborator&hellip;</option>
                        <?php foreach($allUsers as $u): ?>
                        <option value="<?=$u['id']?>" data-name="<?=htmlspecialchars($u['name'])?>"><?=htmlspecialchars($u['name'])?> (<?=$rShort[$u['role']]??$u['role']?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="add-collab-btn" onclick="addCollab()">+ Add</button>
                </div>
            </div>
            <button type="submit" class="tkbtn">&#10133; Create Task</button>
        </form>
        </div>
    </div>
</div>
</div>

<!-- Detail overlay -->
<div class="overlay" id="overlay" onclick="closePanel()"></div>
<div class="panel" id="panel">
    <div class="panel-hd">
        <div class="panel-title" id="panelTitle">Loading&hellip;</div>
        <button class="panel-close" onclick="closePanel()">&times;</button>
    </div>
    <div class="panel-body" id="panelBody"></div>
</div>

<?php
$_kanbanTasks = array_map(function($t){ return [
    'id'       => (int)$t['id'],
    'title'    => $t['title'],
    'priority' => $t['priority'],
    'status'   => $t['status'],
    'due_date' => $t['due_date'] ?? null,
    'progress' => (int)($t['progress'] ?? 0),
    'assignee' => $t['assignee_name'] ?? ($t['owner_name'] ?? null),
]; }, $tasks);
?>
<script>
/* ── Constants (must be first — used by functions called on load) ── */
const TASK_DATA      = <?=json_encode($_kanbanTasks, JSON_HEX_TAG|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?: '[]'?>;
const CURRENT_TAB    = <?=json_encode($tab)?>;
const priorityColors = {urgent:'#dc2626',high:'#f59e0b',normal:'#3b82f6',low:'#94a3b8'};
const priorityLabels = {urgent:'Urgent',high:'High',normal:'Normal',low:'Low'};
const statusLabels   = {pending:'Pending',in_progress:'In Progress',done:'Done'};
const typeIcons      = {comment:'💬',input:'⚡',status_change:'🔄',assignment:'👤',it_update:'🔧'};
const collabData     = {};
let _viewMode = localStorage.getItem('hri_task_view') || 'list';
let _taskDataModified = false;

/* ── Live stats refresh — only updates when TASK_DATA matches personal stats (mine tab) ── */
function refreshStats() {
    if (CURRENT_TAB !== 'mine') return;
    var p=0, i=0, d=0, ov=0;
    var today = new Date(); today.setHours(0,0,0,0);
    TASK_DATA.forEach(function(t) {
        if      (t.status==='pending')     p++;
        else if (t.status==='in_progress') i++;
        else if (t.status==='done')        d++;
        if (t.due_date && t.status!=='done') {
            if (new Date(t.due_date+'T00:00:00') < today) ov++;
        }
    });
    var map = {st_p:p, st_i:i, st_d:d, st_ov:ov};
    Object.keys(map).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.textContent = map[id];
    });
}

/* ── View toggle ── */
function toggleView(mode) {
    localStorage.setItem('hri_task_view', mode);
    _viewMode = mode;

    if (mode === 'kanban') {
        var sp = new URLSearchParams(window.location.search);
        if (sp.get('f') !== 'all') {
            sp.set('f', 'all');
            window.location.href = 'tasks.php?' + sp.toString();
            return;
        }
    }

    if (mode === 'list' && _taskDataModified) {
        window.location.reload();
        return;
    }

    document.getElementById('listView').style.display   = mode==='list'   ? 'block' : 'none';
    document.getElementById('kanbanView').style.display = mode==='kanban' ? 'block' : 'none';
    document.querySelectorAll('.vt-btn').forEach(b => b.classList.toggle('active', b.dataset.view===mode));
    var filtEl = document.querySelector('.filts');
    if (filtEl) filtEl.classList.toggle('kanban-mode', mode==='kanban');
    if (mode==='kanban') buildKanban();
}

/* ── Kanban builder ── */
function buildKanban() {
    const cols = {pending:[], in_progress:[], done:[]};
    TASK_DATA.forEach(t => { if (cols[t.status]) cols[t.status].push(t); });
    const colMap = {pending:'pending', in_progress:'in_progress', done:'done'};
    const countMap = {pending:'kc_pend_count', in_progress:'kc_ip_count', done:'kc_done_count'};
    Object.keys(colMap).forEach(s => {
        const el = document.getElementById('kc_'+colMap[s]);
        const cnt = document.getElementById(countMap[s]);
        if (!el) return;
        const items = cols[s];
        if (cnt) cnt.textContent = items.length ? '('+items.length+')' : '';
        el.innerHTML = items.map(t => `
            <div class="kanban-card" data-id="${t.id}" data-priority="${t.priority||'normal'}"
                 onclick="openDetail(${t.id})"
                 draggable="true" ondragstart="kDragStart(event,${t.id})" ondragend="kDragEnd(event)">
                <div class="kc-title">${esc(t.title)}</div>
                <div class="kc-meta">${priorityLabels[t.priority]||'Normal'}${t.due_date?' · Due '+t.due_date.slice(5).replace('-','/'):''}${t.assignee?' · '+esc(t.assignee):''}</div>
                <div class="kc-prog-wrap" onclick="event.stopPropagation()">
                    <div class="kc-prog-header" onclick="kcToggleEdit(event,${t.id})" title="Click to update progress">
                        <div class="kc-prog-bar-bg"><div class="kc-prog-bar-fill" id="kcbar${t.id}" style="width:${t.progress}%;background:${t.progress>0?'var(--green)':'var(--g300)'}"></div></div>
                        <span class="kc-prog-pct" id="kcpct${t.id}">${t.progress}%</span>
                    </div>
                    <div class="kc-prog-slider-row" id="kce${t.id}">
                        <input type="range" min="0" max="100" step="5" value="${t.progress}"
                               oninput="kcpLive(${t.id},this.value)" onchange="kcpSave(${t.id},parseInt(this.value))">
                    </div>
                </div>
            </div>
        `).join('') || '<div style="text-align:center;padding:30px 10px;color:var(--g300);font-size:12px;">Drop tasks here</div>';
        el.classList.remove('drag-over');
    });
}

let _dragId = null;
function kDragStart(e, id) { _dragId=id; e.target.classList.add('dragging'); }
function kDragEnd(e)       { e.target.classList.remove('dragging'); }
function kDrop(e, status)  {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    if (!_dragId) return;
    const t = TASK_DATA.find(x => x.id==_dragId);
    if (t && t.status !== status) {
        t.status = status;
        if (status==='done') t.progress = 100;
        _taskDataModified = true;
        buildKanban();
        refreshStats();
        post({action:'status', task_id:_dragId, status});
    }
    _dragId = null;
}

/* ── Collaborator builder ── */
function addCollab() {
    const sel = document.getElementById('collabPick');
    const id  = sel.value, name = sel.options[sel.selectedIndex]?.dataset?.name;
    if (!id || collabData[id]) { sel.value=''; return; }
    collabData[id] = {name};
    renderCollabs();
    sel.value = '';
}
function removeCollab(id) {
    delete collabData[id];
    renderCollabs();
}
function renderCollabs() {
    const list = document.getElementById('collabList');
    list.innerHTML = '';
    for (const [id, d] of Object.entries(collabData)) {
        list.insertAdjacentHTML('beforeend', `
        <div class="collab-item">
            <input type="hidden" name="collaborators[]" value="${id}"/>
            <span class="collab-item-name">${d.name}</span>
            <label class="collab-toggle">
                <input type="checkbox" name="collab_input_req[]" value="${id}"/>
                Needs input
            </label>
            <button type="button" class="collab-rm" onclick="removeCollab(${id})">&times;</button>
        </div>`);
    }
}

/* ── Status actions ── */
function toggleTask(id, markDone) {
    const s = markDone ? 'done' : 'pending';
    post({action:'status',task_id:id,status:s}, () => location.reload());
}
function setStatus(id, status) {
    post({action:'status',task_id:id,status}, () => location.reload());
}

/* ── Detail panel ── */
function openDetail(id) {
    document.getElementById('overlay').style.display='block';
    document.getElementById('panel').classList.add('open');
    document.getElementById('panelTitle').textContent = 'Loading…';
    document.getElementById('panelBody').innerHTML = '<div style="padding:20px;color:#94a3b8">Loading task…</div>';
    post({action:'get_detail',task_id:id}, null, true).then(r => r.text()).then(txt => {
        let data; try { data=JSON.parse(txt); } catch(e){ return; }
        if (!data.ok) return;
        renderPanel(data);
    });
}
function closePanel() {
    document.getElementById('overlay').style.display='none';
    document.getElementById('panel').classList.remove('open');
}

function renderPanel(data) {
    const t = data.task, uid = data.uid;
    document.getElementById('panelTitle').textContent = t.title;
    const isOwner = (t.user_id==uid || t.created_by==uid || t.assigned_to==uid);
    const myCollab = data.collaborators.find(c => c.user_id==uid);
    const needsMyInput = myCollab && myCollab.input_required==1 && !myCollab.input_provided_at;

    let dueHtml = '—';
    if (t.due_date) {
        const d = new Date(t.due_date+'T00:00:00'), today = new Date(); today.setHours(0,0,0,0);
        const diff = Math.round((d-today)/86400000);
        const cls  = diff<0?'#dc2626':diff<=2?'#f59e0b':'#64A014';
        const lbl  = diff<0?'Overdue':diff==0?'Today':'Due '+d.toLocaleDateString('en-GB',{day:'numeric',month:'short'});
        dueHtml = `<span style="color:${cls};font-weight:700;">${lbl}</span>`;
    }

    let statusBtns = '';
    if (isOwner) {
        ['pending','in_progress','done'].forEach(s => {
            const ac = t.status===s?'class="s-btn active"':'class="s-btn"';
            statusBtns += `<button ${ac} onclick="changeStatus(${t.id},'${s}')">${statusLabels[s]}</button>`;
        });
    }

    let collabHtml = '';
    data.collaborators.forEach(c => {
        const initials = c.name.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
        const inputHtml = c.input_required==1
            ? (c.input_provided_at
                ? `<div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--green);font-weight:600;">✓ Input provided <span style="color:var(--g400);font-weight:400;">${new Date(c.input_provided_at).toLocaleDateString('en-GB',{day:'numeric',month:'short'})}</span></div>
                   <div class="collab-input-text">${esc(c.input_text)}</div>`
                : '<div style="font-size:11px;font-weight:600;color:#f59e0b;">⏳ Input pending</div>')
            : '';
        collabHtml += `
        <div class="collab-card">
            <div class="collab-avatar">${initials}</div>
            <div class="collab-info">
                <div class="collab-name">${esc(c.name)}</div>
                <div class="collab-meta">${c.role}${c.input_required==1?' &middot; Input required':''}</div>
                ${inputHtml}
            </div>
        </div>`;
    });

    let commentsHtml = '';
    data.comments.forEach(c => {
        const initials = c.name.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
        const icon = typeIcons[c.type]||'💬';
        const time = new Date(c.created_at).toLocaleDateString('en-GB',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'});
        commentsHtml += `
        <div class="comment-item">
            <div class="comment-av ${c.type==='input'?'input-av':''}">${initials}</div>
            <div class="comment-body">
                <span class="comment-name">${esc(c.name)}</span>
                <span class="comment-time">${time}</span>
                ${c.type!=='comment'?`<span class="comment-type-badge">${icon} ${c.type.replace('_',' ')}</span>`:''}
                <div class="comment-text">${esc(c.comment).replace(/\n/g,'<br>')}</div>
            </div>
        </div>`;
    });

    const from = t.creator_name && t.creator_name !== t.owner_name ? t.creator_name : null;
    const assignedTo = t.assignee_name || t.owner_name || '—';
    const sourceTag  = t.source==='it_request' ? '<span class="tpill tpill-it" style="font-size:11px;">IT Request</span>' : '';

    /* Build checklist HTML */
    const chks = data.checklists || [];
    const chkDone = chks.filter(c=>c.is_done==1).length;
    let checklistHtml = '';
    if (chks.length) {
        checklistHtml = chks.map(c => `
        <div class="checklist-item" id="chk_${c.id}">
            <div class="checklist-cb ${c.is_done==1?'done':''}" onclick="toggleChecklist(${c.id},${c.is_done==1?0:1},${t.id})">${c.is_done==1?'✓':''}</div>
            <span class="checklist-text ${c.is_done==1?'done':''}">${esc(c.item)}</span>
            <button class="checklist-del" onclick="deleteChecklist(${c.id},${t.id})" title="Remove">&times;</button>
        </div>`).join('');
    }

    const prog = parseInt(t.progress)||0;

    document.getElementById('panelBody').innerHTML = `
    ${needsMyInput ? `
    <div class="provide-input-box">
        <h4>⚡ Your input is needed on this task</h4>
        <textarea id="myInput" placeholder="Type your input…"></textarea>
        <button class="provide-input-btn" onclick="submitInput(${t.id})">Submit Input</button>
    </div>` : ''}

    <div class="panel-sect">
        <div class="panel-sect-title">Progress <span class="prog-saved" id="panelProgSaved" style="font-size:11px;font-weight:400;margin-left:8px;opacity:0;transition:opacity .2s;color:var(--green);">Saved ✓</span></div>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
            <div class="prog-pct-display" id="panelProgDisp">${prog}%</div>
        </div>
        <div class="prog-bar-wrap"><div class="prog-bar-fill" id="panelProgFill" style="width:${prog}%"></div></div>
        ${isOwner ? `
        <div class="prog-slider-wrap">
            <input type="range" min="0" max="100" step="5" value="${prog}" class="prog-slider"
                   oninput="liveProgress(${t.id},this.value)" onchange="updateProgress(${t.id},parseInt(this.value))">
            <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--g400);margin-top:2px;"><span>0%</span><span>50%</span><span>100%</span></div>
        </div>
        <div style="font-size:11px;color:var(--g400);margin-top:4px;">Drag to update · reaching 100% marks task done</div>` : ''}
    </div>

    <div class="panel-sect">
        <div class="panel-sect-title">Status ${sourceTag}</div>
        ${isOwner ? `<div class="status-row">${statusBtns}</div>` : `<div style="font-size:13px;font-weight:600;margin-top:4px;">${statusLabels[t.status]||t.status}</div>`}
    </div>

    <div class="panel-sect">
        <div class="panel-sect-title">Checklist
            ${chks.length ? `<span style="font-size:11px;font-weight:400;color:var(--g400);margin-left:6px;">${chkDone}/${chks.length} done</span>` : ''}
            ${isOwner && chks.length ? `<button class="sync-prog-btn" onclick="syncProgFromChecklist(${t.id},${chkDone},${chks.length})" title="Auto-calculate progress from checklist" style="float:right;">&#8679; Set progress</button>` : ''}
        </div>
        <div id="checklistItems">${checklistHtml || '<div style="font-size:12px;color:var(--g400);">No checklist items yet.</div>'}</div>
        <div class="checklist-add-row">
            <input type="text" id="checklistInput" placeholder="Add checklist item…" onkeydown="if(event.key==='Enter'){event.preventDefault();addChecklist(${t.id});}"/>
            <button class="checklist-add-btn" onclick="addChecklist(${t.id})">+ Add</button>
        </div>
    </div>

    <div class="panel-sect">
        <div class="panel-sect-title">Details</div>
        <div class="meta-grid">
            <div class="meta-item"><div class="meta-label">Priority</div><div class="meta-val" style="color:${priorityColors[t.priority]||'#94a3b8'}">${priorityLabels[t.priority]||t.priority}</div></div>
            <div class="meta-item"><div class="meta-label">Due Date</div><div class="meta-val">${dueHtml}</div></div>
            <div class="meta-item"><div class="meta-label">Assigned To</div><div class="meta-val">${esc(assignedTo)}</div></div>
            ${from?`<div class="meta-item"><div class="meta-label">Created By</div><div class="meta-val">${esc(from)}</div></div>`:''}
        </div>
        ${t.description ? `<div style="margin-top:10px;font-size:13px;color:var(--g700);line-height:1.6;background:var(--g50);padding:10px;border-radius:8px;">${esc(t.description).replace(/\n/g,'<br>')}</div>` : ''}
    </div>

    ${data.collaborators.length ? `
    <div class="panel-sect">
        <div class="panel-sect-title">Collaborators (${data.collaborators.length})</div>
        ${collabHtml}
    </div>` : ''}

    <div class="panel-sect">
        <div class="panel-sect-title">Activity &amp; Comments</div>
        <div id="commentsList">${commentsHtml || '<div style="font-size:12px;color:var(--g400);">No comments yet.</div>'}</div>
        <div class="input-area">
            <textarea id="commentInput" placeholder="Add a comment…"></textarea>
            <button class="send-btn" onclick="sendComment(${t.id})">Send</button>
        </div>
    </div>`;

    window._openTaskId = t.id;
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function changeStatus(id, status) {
    post({action:'status',task_id:id,status}, () => {
        location.reload();
    });
}

function submitInput(id) {
    const text = document.getElementById('myInput')?.value?.trim();
    if (!text) return;
    post({action:'provide_input',task_id:id,input_text:text}, () => {
        document.querySelector('.provide-input-box').innerHTML = '<div style="color:var(--green);font-weight:600;font-size:13px;">✅ Input submitted. Thank you!</div>';
        setTimeout(() => location.reload(), 1200);
    });
}

function sendComment(id) {
    const inp = document.getElementById('commentInput');
    const txt = inp?.value?.trim();
    if (!txt) return;
    inp.value = '';
    post({action:'add_comment',task_id:id,comment:txt}, () => openDetail(id));
}

/* ── Inline card progress (no modal needed) ── */
function cpLive(taskId, val) {
    const slider = document.getElementById('cps'+taskId);
    const pct    = document.getElementById('cpp'+taskId);
    if (slider) slider.style.setProperty('--pct', val+'%');
    if (pct)    pct.textContent = val+'%';
}
function cpSave(taskId, prog) {
    const pct = document.getElementById('cpp'+taskId);
    if (pct) { pct.classList.add('saving'); pct.textContent = prog+'%'; }
    updateProgress(taskId, prog, function() {
        if (pct) { setTimeout(()=>pct.classList.remove('saving'), 1000); }
    });
}

/* ── Progress (panel + card sync) ── */
function liveProgress(taskId, val) {
    const disp = document.getElementById('panelProgDisp');
    const fill = document.getElementById('panelProgFill');
    if (disp) disp.textContent = val + '%';
    if (fill) fill.style.width = val + '%';
    cpLive(taskId, val);
}
function updateProgress(taskId, prog, cb) {
    const savedEl = document.getElementById('panelProgSaved');
    post({action:'update_progress', task_id:taskId, progress:prog}, function() {
        /* Show saved confirmation */
        if (savedEl) { savedEl.style.opacity='1'; setTimeout(()=>savedEl.style.opacity='0', 1800); }
        /* Update TASK_DATA + kanban */
        const t = TASK_DATA.find(x => x.id==taskId);
        if (t) {
            t.progress = prog;
            if (prog===100) { t.status='done'; _taskDataModified=true; }
            else if (prog>0 && t.status==='pending') { t.status='in_progress'; _taskDataModified=true; }
            if (_viewMode==='kanban') buildKanban();
            refreshStats();
        }
        /* Sync inline card slider + kanban bar */
        cpLive(taskId, prog);
        kcpLive(taskId, prog);
        const cardSlider = document.getElementById('cps'+taskId);
        if (cardSlider) cardSlider.value = prog;
        const card = document.getElementById('ti'+taskId);
        if (card && prog===100) {
            card.classList.add('done-task');
            const chkBtn = card.querySelector('.tcb');
            if (chkBtn) { chkBtn.classList.add('chk'); chkBtn.innerHTML='&#10003;'; }
            /* Hide slider when done */
            const cp = card.querySelector('.card-prog');
            if (cp) cp.style.display = 'none';
        }
        if (cb) cb();
    });
}

/* ── Checklists ── */
function addChecklist(taskId) {
    const inp = document.getElementById('checklistInput');
    const item = inp ? inp.value.trim() : '';
    if (!item) return;
    inp.value = '';
    inp.disabled = true;
    post({action:'add_checklist', task_id:taskId, item:item}, function() {
        inp.disabled = false;
        openDetail(taskId);
    });
}
function toggleChecklist(cid, isDone, taskId) {
    post({action:'toggle_checklist', checklist_id:cid, is_done:isDone}, function() {
        openDetail(taskId);
    });
}
function deleteChecklist(cid, taskId) {
    if (!confirm('Remove this item?')) return;
    post({action:'delete_checklist', checklist_id:cid}, function() {
        openDetail(taskId);
    });
}

/* ── Kanban inline progress ── */
function kcToggleEdit(e, id) {
    e.stopPropagation();
    var row = document.getElementById('kce'+id);
    if (!row) return;
    /* Close any other open sliders first */
    document.querySelectorAll('.kc-prog-slider-row').forEach(function(r){ if (r.id !== 'kce'+id) r.style.display='none'; });
    row.style.display = row.style.display === 'block' ? 'none' : 'block';
}
function kcpLive(id, val) {
    var bar = document.getElementById('kcbar'+id);
    var pct = document.getElementById('kcpct'+id);
    val = parseInt(val);
    if (bar) { bar.style.width=val+'%'; bar.style.background=val>0?'var(--green)':'var(--g300)'; }
    if (pct) pct.textContent=val+'%';
}
function kcpSave(id, prog) {
    updateProgress(id, prog, function() {
        var row = document.getElementById('kce'+id);
        if (row) row.style.display='none';
    });
}

/* ── Sync progress from checklist completion ── */
function syncProgFromChecklist(taskId, done, total) {
    if (!total) return;
    var prog = Math.round(done/total*100);
    liveProgress(taskId, prog);
    var sl = document.querySelector('.prog-slider');
    if (sl) sl.value = prog;
    updateProgress(taskId, prog, null);
}

/* ── Resolve IT request task ── */
function resolveIT(taskId) {
    if (!confirm('Mark this task as resolved and close the IT ticket?')) return;
    const btn = document.querySelector('[onclick="resolveIT('+taskId+')"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Resolving…'; }
    post({action:'resolve_it', task_id:taskId}, null, true)
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (!res || !res.ok) {
                alert('Could not resolve — please try again.');
                if (btn) { btn.disabled=false; btn.textContent='✓ Resolve'; }
                return;
            }
            const card = document.getElementById('ti'+taskId);
            if (card) {
                card.classList.add('done-task');
                const chkBtn = card.querySelector('.tcb');
                if (chkBtn) { chkBtn.classList.add('chk'); chkBtn.innerHTML='&#10003;'; }
                const cp = card.querySelector('.card-prog');
                if (cp) cp.style.display='none';
                if (btn) btn.style.display='none';
            }
            const t = TASK_DATA.find(function(x){ return x.id==taskId; });
            if (t) { t.status='done'; t.progress=100; _taskDataModified=true; }
            if (_viewMode==='kanban') buildKanban();
            refreshStats();
        })
        .catch(function() {
            alert('Could not resolve — please try again.');
            if (btn) { btn.disabled=false; btn.textContent='✓ Resolve'; }
        });
}

/* ── Generic POST helper ── */
function post(data, cb, returnFetch=false) {
    const body = Object.entries(data).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');
    const p = fetch('tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':window.CSRF_TOKEN},body});
    if (returnFetch) return p;
    p.then(()=>cb&&cb());
}

/* ── Restore saved view (must run last; flash script already showed correct div) ── */
if (_viewMode === 'kanban') {
    toggleView('kanban'); /* handles redirect-if-needed + buildKanban() */
} else {
    document.getElementById('listView').style.display = 'block'; /* ensure visible */
}

/* ── Prevent double-submit on Create Task form ── */
(function() {
    var form = document.getElementById('addForm');
    if (!form) return;
    form.addEventListener('submit', function() {
        var btn = form.querySelector('[type=submit]');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Creating…';
        }
    });
})();

/* ── In kanban mode, tab links should use f=all so all tasks load ── */
(function() {
    if (_viewMode !== 'kanban') return;
    document.querySelectorAll('a.tab').forEach(function(a) {
        var href = a.getAttribute('href') || '';
        href = href.replace(/[?&]f=[^&]*/,'');
        href += (href.indexOf('?')>=0 ? '&' : '?') + 'f=all';
        a.setAttribute('href', href);
    });
})();
</script>
<?php Layout::end(); ?>
