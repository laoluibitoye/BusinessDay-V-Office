<?php
// lib/layout_shell.php — HRI Mail shared layout (Gmail-inspired)

class Layout {
    private static $unreadCount   = 0;
    private static $taskCount     = 0;
    private static $sigsForJs     = [];
    private static $announcements = [];

    public static function shell(array $user, string $active = '', int $unread = 0, string $title = ''): void {
        self::$unreadCount = $unread;
        $role = ROLES[$user['role']] ?? null;
        if (!$role) {
            // Try custom_roles table for non-standard roles
            try {
                $crSt = getDB()->prepare("SELECT label, icon, level, color FROM custom_roles WHERE role_key=? LIMIT 1");
                $crSt->execute([$user['role']]);
                $crRow = $crSt->fetch();
                $role = $crRow
                    ? ['label'=>$crRow['label'],'icon'=>$crRow['icon']?:'👤','level'=>(int)$crRow['level'],'color'=>$crRow['color'],'permissions'=>[]]
                    : ['label'=>$user['role'],'icon'=>'👤','level'=>4,'permissions'=>[]];
            } catch (Exception $e) {
                $role = ['label'=>$user['role'],'icon'=>'👤','level'=>4,'permissions'=>[]];
            }
        }
        $ini       = strtoupper(substr($user['name'],0,1));
        if (strpos($user['name'],' ') !== false)
            $ini .= strtoupper(substr($user['name'], strpos($user['name'],' ')+1, 1));
        $userRole = $user['role'];

        // Permissions: Auth::can() handles DB-first + ROLES fallback with internal caching
        $canF           = function(string $feat) use ($user): bool { return Auth::can($user, $feat); };
        $isAdmin        = Auth::isAdmin($user);
        $canPayslip     = isset(ROLES[$userRole]) && ROLES[$userRole]['level'] <= 3;
        $isHR           = in_array($userRole, ['hr']) || $isAdmin;
        $isApproveLeave = $canF('leave_approve') || $isAdmin;
        $canCompliance  = $canF('compliance') || $isAdmin;
        $isFrontDesk    = ($userRole === 'front_desk');
        $logo      = APP_URL . '/hri-logo.png';
        $appUrl    = APP_URL;

        $_db = getDB();
        $staffJson = '[]';
        try {
            $st = $_db->query("SELECT name, email FROM users WHERE is_active=1 ORDER BY name");
            $staffJson = json_encode(array_values($st->fetchAll(PDO::FETCH_ASSOC))) ?: '[]';
        } catch (Exception $e) {}

        $sig = '';
        self::$sigsForJs = [];
        try {
            $ss = $_db->prepare("SELECT id, name, html, is_default FROM user_signatures WHERE user_id=? ORDER BY is_default DESC, id ASC");
            $ss->execute([$user['id']]);
            self::$sigsForJs = $ss->fetchAll(PDO::FETCH_ASSOC);
            foreach (self::$sigsForJs as $sr) {
                if ($sr['html']) {
                    $raw = $sr['html'];
                    $sig = strpos($raw, '<') !== false ? $raw : nl2br(htmlspecialchars($raw));
                    break;
                }
            }
        } catch (Exception $e) {}
        // Auto-generate HRI standard signature for users who haven't set one up
        if (!$sig) {
            $rl = ROLES[$user['role']]['label'] ?? ucfirst(str_replace('_',' ',$user['role']));
            $dp = !empty($user['department']) ? ' &mdash; '.htmlspecialchars($user['department']) : '';
            $ph = !empty($user['phone']) ? '<br><span style="color:#64748b;">&#9990; '.htmlspecialchars($user['phone']).'</span>' : '';
            $sig = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#334155;line-height:1.5;">'
                 . '<strong style="color:#002850;">'.htmlspecialchars($user['name']).'</strong><br>'
                 . '<span style="color:#64748b;">'.htmlspecialchars($rl).'</span>'.$dp.'<br>'
                 . '<a href="mailto:'.htmlspecialchars($user['email']).'" style="color:#002850;text-decoration:none;">'.htmlspecialchars($user['email']).'</a>'
                 . $ph
                 . '<br><img src="'.APP_URL.'/hri-logo.png" alt="HR Indexx Limited" style="height:28px;margin-top:7px;display:block;">'
                 . '<p style="font-size:10px;color:#94a3b8;margin:5px 0 0;line-height:1.4;">'
                 . 'HR Indexx Limited &bull; RC: 446051 &bull; NDPC/DCP/12819<br>'
                 . 'This message is confidential and intended for the named recipient only.'
                 . '</p></div>';
        }
        // Task notification badge count (my pending tasks)
        try {
            self::$taskCount = (int)$_db->query("SELECT COUNT(*) FROM tasks WHERE user_id=".(int)$user['id']." AND status!='done'")->fetchColumn();
        } catch (Exception $e) {}

        // Active announcements — stored statically so dashboard widgets can reuse without a second query
        self::$announcements = [];
        try {
            $_anSt = $_db->prepare("SELECT id, title, body, priority FROM announcements WHERE is_active=1 AND (expires_at IS NULL OR expires_at > NOW()) AND (target_role IS NULL OR target_role=? OR target_role='all') ORDER BY FIELD(priority,'urgent','high','normal') ASC, created_at DESC LIMIT 5");
            $_anSt->execute([$user['role']]);
            self::$announcements = $_anSt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
        $announcements = self::$announcements;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0"/>
<title><?= $title ? htmlspecialchars($title).' — ' : '' ?>HRI Mail</title>
<link rel="icon" type="image/svg+xml" href="<?= $appUrl ?>/favicon.svg"/>
<link rel="alternate icon" href="<?= $appUrl ?>/favicon.ico"/>
<link rel="manifest" href="<?= $appUrl ?>/manifest.webmanifest"/>
<meta name="theme-color" content="#002850"/>
<meta name="apple-mobile-web-app-capable" content="yes"/>
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
<meta name="apple-mobile-web-app-title" content="HRI Mail"/>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet"/>
<style><?php readfile(__DIR__.'/layout_shell.css'); ?></style>
</head>
<body>

<!-- TOPBAR -->
<header class="hri-topbar">
    <button class="hri-hamburger" id="hriHamburger">&#9776;</button>
    <a class="hri-logo" href="<?= $appUrl ?>/">
        <img src="<?= $logo ?>" alt="HRI" class="hri-logo-img" onerror="this.style.display='none'"/>
        <span class="hri-logo-txt">HRI <strong>Mail</strong></span>
    </a>
    <div class="hri-search-wrap">
        <span class="hri-search-ico">&#128269;</span>
        <input class="hri-search" id="hriSearchInp" type="text" placeholder="Search in mail"/>
    </div>
    <div class="hri-topbar-right">
        <a class="hri-tb-btn" href="<?= $appUrl ?>/mail.php" title="Inbox">
            <span>&#128236;</span>
            <?php if ($unread > 0): ?>
            <span class="hri-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
            <?php endif; ?>
        </a>
        <button class="hri-tb-btn" id="hriTbCompose" title="Compose (Ctrl+M)" onclick="if(window.hriOpenCompose)window.hriOpenCompose();">&#9998;</button>
        <a class="hri-tb-btn" href="<?= $appUrl ?>/tasks.php" title="Tasks"><span>&#9989;</span><?php if (self::$taskCount > 0): ?><span class="hri-badge hri-badge-task"><?= self::$taskCount > 99 ? '99+' : self::$taskCount ?></span><?php endif; ?></a>
        <div class="hri-tb-sep"></div>
        <div class="hri-av-wrap">
            <button class="hri-tb-av" id="hriTbAvatar" title="My Profile"><?= $ini ?></button>
            <div class="hri-av-drop" id="hriAvDrop">
                <div class="hri-av-drop-user">
                    <div class="hri-av-drop-ini"><?= $ini ?></div>
                    <div>
                        <div class="hri-av-drop-name"><?= htmlspecialchars($user['name']) ?></div>
                        <div class="hri-av-drop-role"><?= htmlspecialchars($role['label'] ?? $user['role']) ?></div>
                        <div class="hri-av-drop-email"><?= htmlspecialchars($user['email']) ?></div>
                    </div>
                </div>
                <div class="hri-av-drop-divider"></div>
                <a class="hri-av-drop-item" href="<?= $appUrl ?>/profile.php">
                    <span class="hri-av-ico">&#128100;</span> Update Profile
                </a>
                <a class="hri-av-drop-item" href="<?= $appUrl ?>/profile.php#password">
                    <span class="hri-av-ico">&#128273;</span> Change Password
                </a>
                <div class="hri-av-drop-divider"></div>
                <a class="hri-av-drop-item hri-av-drop-logout" href="<?= $appUrl ?>/logout.php">
                    <span class="hri-av-ico">&#128682;</span> Sign Out
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Sidebar overlay -->
<div class="hri-sb-overlay" id="hriSbOverlay"></div>

<!-- SIDEBAR -->
<nav class="hri-sidebar" id="hriSidebar">
    <button class="hri-sb-compose" id="hriSbCompose" onclick="if(window.hriOpenCompose)window.hriOpenCompose();">
        <span class="hri-sb-compose-ico">&#9998;</span>
        Compose
    </button>

    <?php
    $mailItems = [
        ['&#127968;', 'Dashboard',   $appUrl.'/',                    'dashboard'],
        ['&#128236;', 'Inbox',       $appUrl.'/mail.php',            'inbox', $unread],
        ['&#9733;',   'Starred',     $appUrl.'/mail.php?f=Starred',  'starred'],
        ['&#128140;', 'Sent',        $appUrl.'/mail.php?f=Sent',     'sent'],
        ['&#128221;', 'Drafts',      $appUrl.'/mail.php?f=Drafts',   'drafts'],
        ['&#128465;', 'Trash',       $appUrl.'/mail.php?f=Trash',    'trash'],
        ['&#128571;', 'Spam',        $appUrl.'/mail.php?f=Spam',     'spam'],
    ];
    // Append custom IMAP folders cached in session
    $customFolders = $_SESSION['imap_custom_folders'] ?? [];
    foreach ($customFolders as $cf) {
        $mailItems[] = ['&#128193;', htmlspecialchars($cf['display']), $appUrl.'/mail.php?f='.urlencode($cf['full']), 'folder_'.md5($cf['full'])];
    }
    $workItems = [];
    if ($canF('tasks'))                       $workItems[] = ['&#9989;',   'My Tasks',        $appUrl.'/tasks.php',          'tasks'];
    if ($canF('leave_request') || $isAdmin)   $workItems[] = ['&#127958;', 'Leave Request',   $appUrl.'/leave.php',          'leave'];
    $workItems[] = ['&#128196;', 'Internal Requests', $appUrl.'/irs.php', 'irs'];
    $workItems[] = ['&#9999;',   'My Signature',  $appUrl.'/signature.php',  'signature'];
    $workItems[] = ['&#127796;', 'Out of Office', $appUrl.'/ooo.php',        'ooo'];
    $workItems[] = ['&#128268;', 'Email Filters', $appUrl.'/filters.php',    'filters'];
    if ($isApproveLeave) $workItems[] = ['&#128203;', 'Leave Approvals', $appUrl.'/leave-approvals.php', 'leave_approvals'];

    $toolItems = [];
    if ($canF('directory') || $isAdmin)  $toolItems[] = ['&#128101;', 'Staff Directory', $appUrl.'/directory.php',  'directory'];
    $toolItems[] = ['&#128214;', 'SOPs',       $appUrl.'/sop.php',       'sop'];
    $toolItems[] = ['&#128197;', 'Contacts',   $appUrl.'/contacts.php',  'contacts'];
    $toolItems[] = ['&#128203;', 'Templates',  $appUrl.'/email-tpl.php', 'templates'];
    $toolItems[] = ['&#128193;', 'Folders',    $appUrl.'/folders.php',   'folders'];
    if ($canF('it_request') || $isAdmin) $toolItems[] = ['&#128295;', 'IT Support',      $appUrl.'/it-request.php', 'it_request'];
    if ($isFrontDesk || $isHR || $isAdmin || $canF('visitors')) {
        $toolItems[] = ['&#127962;', 'Visitor Log', $appUrl.'/visitors.php', 'visitors'];
    }

    $compItems = [];
    if ($canCompliance) {
        $compItems = [
            ['&#128203;', 'Compliance Tracker', $appUrl.'/compliance.php',             'compliance'],
            ['&#128225;', 'Announcements',       $appUrl.'/admin/announcements.php',    'announcements'],
            ['&#128308;', 'Breach Log',          $appUrl.'/breach.php',                 'breach'],
        ];
    }
    if ($canPayslip) {
        $compItems[] = ['&#128196;', 'Payslips', $appUrl.'/payslip.php', 'payslip'];
    }
    $canSubscriptions = in_array($userRole, ['head_it','it_admin','md','bdm','head_accounts']);
    if ($canSubscriptions) {
        $compItems[] = ['&#128260;', 'Subscriptions', $appUrl.'/subscriptions.php', 'subscriptions'];
    }

    $adminItems = $isAdmin ? [
        ['&#128737;', 'User Management',  $appUrl.'/admin/users.php',              'users'],
        ['&#128101;', 'Roles & Depts',    $appUrl.'/admin/roles.php',              'roles'],
        ['&#128197;', 'Work Schedules',   $appUrl.'/admin/work-schedules.php',     'work_schedules'],
        ['&#128226;', 'Broadcast Mail',   $appUrl.'/admin/broadcast.php',          'broadcast'],
        ['&#128214;', 'Audit Log',        $appUrl.'/admin/audit.php',              'audit'],
        ['&#128295;', 'IT Requests',      $appUrl.'/admin/it-requests.php',        'it_requests'],
        ['&#128202;', 'Analytics',        $appUrl.'/admin/usage.php',              'usage'],
        ['&#128250;', 'Active Sessions',  $appUrl.'/admin/sessions.php',           'sessions'],
        ['&#128221;', 'Leave Queue',      $appUrl.'/admin/leave-queue.php',        'leave_queue'],
        ['&#9881;',   'IRS Settings',     $appUrl.'/admin/irs-settings.php',       'irs_settings'],
        ['&#127987;', 'IRS Flow Config',  $appUrl.'/admin/irs-flows.php',          'irs_flows'],
    ] : [];

    // Auditor export. head_accounts needs this but is not an admin role, so it
    // is appended separately rather than living in the block above.
    if (in_array($user['role'] ?? '', ['md', 'bdm', 'head_accounts', 'head_it'], true)) {
        $adminItems[] = ['&#128202;', 'Auditor Export', $appUrl.'/audit-export.php', 'audit_export'];
    }

    $sections = [null => $mailItems, 'MY WORK' => $workItems, 'TOOLS' => $toolItems];
    if (!empty($compItems)) $sections['COMPLIANCE & HR'] = $compItems;
    if (!empty($adminItems)) $sections['ADMIN'] = $adminItems;

    foreach ($sections as $section => $items):
        if ($section === 'ADMIN'):
            $adminHasActive = false;
            foreach ($items as $_it) { if ($active === $_it[3]) { $adminHasActive = true; break; } }
            $adminOpen = $adminHasActive ? 'true' : 'false'; ?>
        <div class="hri-sb-divider"></div>
        <button class="hri-sb-toggle" id="hriAdminToggle" aria-expanded="<?= $adminOpen ?>"
            onclick="var o=this.getAttribute('aria-expanded')==='true';this.setAttribute('aria-expanded',o?'false':'true');document.getElementById('hriAdminNav').style.display=o?'none':'block';this.querySelector('.hri-sb-caret').style.transform=o?'':'rotate(180deg)';">
            <span>Admin</span>
            <span class="hri-sb-caret" style="transform:<?= $adminOpen==='true' ? 'rotate(180deg)' : '' ?>;">&#9660;</span>
        </button>
        <div id="hriAdminNav" style="display:<?= $adminOpen==='true' ? 'block' : 'none' ?>;">
        <?php foreach ($items as $item):
            $badge = $item[4] ?? 0;
            $isAct = ($active === $item[3]); ?>
        <a class="hri-sb-item<?= $isAct ? ' hri-active' : '' ?>" href="<?= $item[2] ?>">
            <span class="hri-sb-ico"><?= $item[0] ?></span>
            <span class="hri-sb-lbl"><?= $item[1] ?></span>
            <?php if ($badge > 0): ?><span class="hri-sb-badge"><?= $badge > 999 ? '999+' : $badge ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <?php if ($section): ?>
        <div class="hri-sb-divider"></div>
        <div class="hri-sb-section"><?= $section ?></div>
        <?php endif;
        foreach ($items as $item):
            $badge = $item[4] ?? 0;
            $isAct = ($active === $item[3]);
        ?>
        <a class="hri-sb-item<?= $isAct ? ' hri-active' : '' ?>" href="<?= $item[2] ?>">
            <span class="hri-sb-ico"><?= $item[0] ?></span>
            <span class="hri-sb-lbl"><?= $item[1] ?></span>
            <?php if ($badge > 0): ?>
            <span class="hri-sb-badge"><?= $badge > 999 ? '999+' : $badge ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach;
        endif;
    endforeach; ?>

    <div class="hri-sb-divider"></div>
    <div class="hri-sb-footer">
        <div style="font-size:12px;color:var(--g600);font-weight:500;"><?= htmlspecialchars($user['name']) ?></div>
        <div style="font-size:11px;color:var(--g400);margin:2px 0 8px;"><?= htmlspecialchars($user['email']) ?></div>
        <button class="hri-sb-logout" onclick="location.href='<?= $appUrl ?>/logout.php'">
            <span>&#128682;</span> Sign out
        </button>
        <div class="hri-sb-copyright">&copy; <?= date('Y') ?> HR Indexx Limited</div>
    </div>
</nav>

<!-- COMPOSE PANEL -->
<div class="hri-compose-panel" id="hriComposePanel">
    <!-- Minimised state -->
    <div class="hri-cp-min-bar" id="hriCpMinBar" style="display:none;">
        <span id="hriMinTitle">New Message</span>
        <div style="display:flex;gap:2px;">
            <button class="hri-cp-hbtn" id="hriCpMinRestore" onclick="if(window.hriRestoreCompose)window.hriRestoreCompose();event.stopPropagation();">&#11014;</button>
            <button class="hri-cp-hbtn" id="hriCpMinDiscard" onclick="if(window.hriDiscardCompose)window.hriDiscardCompose();event.stopPropagation();">&#10005;</button>
        </div>
    </div>
    <!-- Full state -->
    <div id="hriCpBody" style="display:flex;flex-direction:column;flex:1;background:#fff;overflow:hidden;">
        <div class="hri-cp-header" id="hriCpHeader">
            <span class="hri-cp-title">New Message</span>
            <div style="display:flex;gap:2px;">
                <button class="hri-cp-hbtn" id="hriCpMinBtn" onclick="if(window.hriMinimiseCompose)window.hriMinimiseCompose();event.stopPropagation();" title="Minimise">&#8213;</button>
                <button class="hri-cp-hbtn" id="hriCpExpandBtn" onclick="if(window.hriExpandCompose)window.hriExpandCompose();event.stopPropagation();" title="Full screen">&#10066;</button>
                <button class="hri-cp-hbtn" id="hriCpCloseBtn" onclick="if(window.hriDiscardCompose)window.hriDiscardCompose();event.stopPropagation();" title="Discard">&#10005;</button>
            </div>
        </div>
        <div class="hri-cp-fields">
            <div class="hri-cp-field" style="flex-wrap:wrap;align-items:flex-start;padding:6px 12px;">
                <span class="hri-cp-lbl" style="padding-top:6px;">To</span>
                <div style="flex:1;min-width:0;display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
                    <div id="hriToPills" style="display:contents;"></div>
                    <div style="position:relative;flex:1;min-width:120px;">
                        <input class="hri-cp-inp" id="hriToInp" autocomplete="off" placeholder="Recipients"
                               style="width:100%;min-width:120px;"/>
                        <div class="hri-ac-drop" id="hriAcDrop"></div>
                    </div>
                </div>
                <input type="hidden" id="hriToVal"/>
                <button class="hri-cp-cc-btn" id="hriCcToggle" style="padding-top:6px;">Cc Bcc</button>
            </div>
            <div class="hri-cp-field" id="hriCcRow" style="display:none;">
                <span class="hri-cp-lbl">Cc</span>
                <input class="hri-cp-inp" id="hriCcInp" style="flex:1;" placeholder=""/>
            </div>
            <div class="hri-cp-field" id="hriBccRow" style="display:none;">
                <span class="hri-cp-lbl">Bcc</span>
                <input class="hri-cp-inp" id="hriBccInp" style="flex:1;" placeholder=""/>
            </div>
            <div class="hri-cp-field">
                <input class="hri-cp-inp" id="hriSubInp" placeholder="Subject" style="flex:1;"/>
            </div>
        </div>
        <div class="hri-cp-body-wrap">
            <div class="hri-cp-body" id="hriBodyInp" contenteditable="true"
                 style="min-height:160px;outline:none;word-wrap:break-word;"
                 data-placeholder="Write your message here..."></div>
            <div id="hriSigBlock" class="hri-cp-sig" style="<?= $sig ? '' : 'display:none;' ?>">
                <div class="hri-cp-sig-line"></div>
                <div id="hriSigInner" style="line-height:1.6;"><?= $sig ?></div>
            </div>
        </div>
        <div class="hri-cp-att-list" id="hriAttList"></div>
        <div class="hri-cp-toolbar">
            <button class="hri-tb-fmt" data-cmd="bold" title="Bold"><b>B</b></button>
            <button class="hri-tb-fmt" data-cmd="italic" title="Italic"><i>I</i></button>
            <button class="hri-tb-fmt" data-cmd="underline" title="Underline"><u>U</u></button>
            <div class="hri-tb-sep"></div>
            <button class="hri-tb-fmt" data-cmd="insertUnorderedList" title="Bullet list">&#8226;&#8801;</button>
            <button class="hri-tb-fmt" data-cmd="insertOrderedList" title="Numbered list">1&#8801;</button>
            <div class="hri-tb-sep"></div>
            <button class="hri-tb-fmt" data-cmd="justifyLeft" title="Left">&#8676;</button>
            <button class="hri-tb-fmt" data-cmd="justifyCenter" title="Center">&#8803;</button>
            <button class="hri-tb-fmt" data-cmd="justifyRight" title="Right">&#8677;</button>
            <div class="hri-tb-sep"></div>
            <button class="hri-tb-fmt" id="hriLinkBtn" title="Link">&#128279;</button>
        </div>
        <!-- Schedule Send panel -->
        <div id="hriSchedulePanel" style="display:none;padding:10px 16px;border-top:1px solid var(--g200);background:var(--g50);">
            <div style="font-size:12px;font-weight:600;color:var(--g600);margin-bottom:8px;">Schedule Send</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                <button onclick="hriScheduleAt(8,'Tomorrow 8AM')" class="hri-sched-btn">Tomorrow 8AM</button>
                <button onclick="hriScheduleAt(0,'Monday 9AM',1)" class="hri-sched-btn">Monday 9AM</button>
                <button onclick="hriScheduleAt(0,'Friday 5PM',5)" class="hri-sched-btn">Friday 5PM</button>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="datetime-local" id="hriCustomDate" class="hri-cp-inp"
                       style="border:1px solid var(--g300);border-radius:4px;padding:4px 8px;font-size:12px;flex:1;"/>
                <button onclick="hriScheduleCustom()" class="hri-btn hri-btn-navy" style="padding:4px 12px;font-size:12px;">Schedule</button>
                <button onclick="document.getElementById('hriSchedulePanel').style.display='none'" style="background:none;border:none;cursor:pointer;color:var(--g400);font-size:16px;">&#10005;</button>
            </div>
        </div>
        <div class="hri-cp-footer" style="position:relative;">
            <button class="hri-cp-send" id="hriSendBtn">Send</button>
            <button class="hri-cp-icon-btn" id="hriAttBtn" title="Attach">&#128206;</button>
            <input type="file" id="hriAttInp" multiple style="display:none;"/>
            <button class="hri-cp-icon-btn" id="hriScheduleBtn" title="Schedule Send" onclick="hriShowSchedule()">&#128339;</button>
            <button class="hri-cp-icon-btn" id="hriSigPickerBtn" title="Change Signature" style="font-size:15px;">&#9999;</button>
            <div id="hriSigPickerDrop" style="display:none;position:absolute;bottom:calc(100% + 4px);right:48px;background:#fff;border:1.5px solid var(--g200);border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.14);min-width:210px;z-index:900;overflow:hidden;"></div>
            <div class="hri-draft-status" id="hriDraftStatus"></div>
            <button class="hri-cp-icon-btn" id="hriDiscardBtn" title="Discard">&#128465;</button>
        </div>
    </div>
</div>

<script>
/* Compose panel bootstrap — runs before IIFE, provides fallbacks for all panel controls */
(function(){
    function gp(){return document.getElementById('hriComposePanel');}
    function resetPanelPos(p){p.style.top='';p.style.left='';p.style.right='';p.style.bottom='';p.style.transform='';p.style.transition='';}

    // Accepts an optional prefill: {to, cc, subject, body}.
    // It previously took NO arguments, so every caller passing one was silently
    // ignored — email-tpl.php "Use" opened an empty compose, and contacts.php
    // "email this contact" opened one with no recipient.
    window.hriOpenCompose = window.hriOpenCompose || function(opts) {
        var p=gp(); if(!p) return;
        resetPanelPos(p);
        p.classList.add('hri-open'); p.classList.remove('hri-min');
        p.style.zIndex='';
        var cb=document.getElementById('hriCpBody');
        var cm=document.getElementById('hriCpMinBar');
        if(cb)cb.style.display='flex'; if(cm)cm.style.display='none';

        opts = opts || {};
        var to   = document.getElementById('hriToInp');
        var cc   = document.getElementById('hriCcInp');
        var subj = document.getElementById('hriSubInp');
        var body = document.getElementById('hriBodyInp');
        if (to   && opts.to      !== undefined) to.value   = opts.to;
        if (cc   && opts.cc      !== undefined) cc.value   = opts.cc;
        if (subj && opts.subject !== undefined) subj.value = opts.subject;
        if (body && opts.body    !== undefined) {
            // template bodies are plain text with newlines; keep the breaks
            body.innerHTML = String(opts.body)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                .replace(/\n/g,'<br>');
        }

        // focus the first field the caller did NOT fill in
        setTimeout(function(){
            var target = (!opts.to) ? to : ((!opts.subject) ? subj : body);
            if (target && target.focus) target.focus();
        },150);
    };
    window.hriMinimiseCompose = window.hriMinimiseCompose || function() {
        var p=gp(); if(!p) return;
        p.classList.add('hri-min');
        var cb=document.getElementById('hriCpBody');
        var cm=document.getElementById('hriCpMinBar');
        var sub=document.getElementById('hriSubInp');
        var tit=document.getElementById('hriMinTitle');
        if(cb)cb.style.display='none';
        if(cm){cm.style.display='flex';if(tit&&sub)tit.textContent=sub.value||'New Message';}
    };
    window.hriRestoreCompose = window.hriRestoreCompose || function() {
        var p=gp(); if(!p) return;
        p.classList.remove('hri-min');
        var cb=document.getElementById('hriCpBody');
        var cm=document.getElementById('hriCpMinBar');
        if(cb)cb.style.display='flex'; if(cm)cm.style.display='none';
    };
    window.hriDiscardCompose = window.hriDiscardCompose || function() {
        if(!confirm('Discard this message?')) return;
        var p=gp(); if(!p) return;
        p.classList.remove('hri-open'); p.classList.remove('hri-min');
        resetPanelPos(p); p.style.zIndex='';
        localStorage.removeItem('hri_draft');
    };
    window.hriExpandCompose = window.hriExpandCompose || function() {
        var p=gp(); if(!p) return;
        if(p.dataset.expanded==='1'){
            p.removeAttribute('data-expanded');
            p.style.cssText=''; p.classList.add('hri-open');
        } else {
            p.dataset.expanded='1';
            p.style.cssText='position:fixed;inset:0;width:100%;height:100vh;max-height:100vh;border-radius:0;z-index:850;transform:none;overflow:hidden;';
        }
    };

    /* Drag-to-move compose panel by its header */
    document.addEventListener('DOMContentLoaded', function(){
        var panel  = document.getElementById('hriComposePanel');
        var header = document.getElementById('hriCpHeader');
        if(!panel||!header) return;
        var sx=0, sy=0, dragging=false, moved=false;
        header.style.cursor='grab';
        header.addEventListener('mousedown', function(e){
            if(e.target.closest('.hri-cp-hbtn')) return;
            var r=panel.getBoundingClientRect();
            panel.style.transition='none'; panel.style.transform='none';
            panel.style.bottom='auto'; panel.style.right='auto';
            panel.style.top=r.top+'px'; panel.style.left=r.left+'px';
            panel.style.zIndex='820';
            sx=e.clientX-r.left; sy=e.clientY-r.top;
            dragging=true; moved=false;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function(e){
            if(!dragging) return;
            moved=true; header.style.cursor='grabbing';
            var nl=Math.max(0,Math.min(window.innerWidth-panel.offsetWidth,e.clientX-sx));
            var nt=Math.max(0,Math.min(window.innerHeight-panel.offsetHeight,e.clientY-sy));
            panel.style.left=nl+'px'; panel.style.top=nt+'px';
        });
        document.addEventListener('mouseup', function(){
            if(!dragging) return;
            dragging=false; header.style.cursor='grab';
            if(!moved && window.hriMinimiseCompose) window.hriMinimiseCompose();
            moved=false;
        });
    });

    /* Expose CSRF token early — fallback if main IIFE fails */
    if (!window.CSRF_TOKEN) window.CSRF_TOKEN = '<?= addslashes(Auth::csrfToken()) ?>';
})();
</script>

<!-- MOBILE BOTTOM NAV -->
<nav class="hri-mob-nav">
    <div class="hri-mob-nav-inner">
        <a class="hri-mob-item <?= $active==='dashboard'?'hri-mob-active':'' ?>" href="<?= $appUrl ?>/">
            <span>&#127968;</span>Home
        </a>
        <a class="hri-mob-item <?= $active==='inbox'?'hri-mob-active':'' ?>" href="<?= $appUrl ?>/mail.php">
            <span>&#128236;</span>Mail
            <?php if ($unread > 0): ?><span class="hri-mob-badge"><?= min($unread,99) ?></span><?php endif; ?>
        </a>
        <button class="hri-mob-compose" id="hriMobCompose" onclick="if(window.hriOpenCompose)window.hriOpenCompose();">&#9998;</button>
        <a class="hri-mob-item <?= $active==='tasks'?'hri-mob-active':'' ?>" href="<?= $appUrl ?>/tasks.php">
            <span>&#9989;</span>Tasks
            <?php if (self::$taskCount > 0): ?><span class="hri-mob-badge hri-mob-badge-task"><?= min(self::$taskCount,99) ?></span><?php endif; ?>
        </a>
        <a class="hri-mob-item" href="<?= $appUrl ?>/profile.php">
            <span>&#128100;</span>Profile
        </a>
    </div>
</nav>

<!-- MAIN -->
<div class="hri-main" id="hriMain">
<?php
    foreach ($announcements as $_ann) {
        $annId    = (int)$_ann['id'];
        $annBg    = $_ann['priority']==='urgent'?'#fee2e2':($_ann['priority']==='high'?'#fef3c7':'#eff6ff');
        $annBdr   = $_ann['priority']==='urgent'?'#ef4444':($_ann['priority']==='high'?'#f59e0b':'#3b82f6');
        $annIco   = $_ann['priority']==='urgent'?'&#128680;':($_ann['priority']==='high'?'&#9888;':'&#128226;');
        $annTxt   = strip_tags($_ann['body'] ?? '');
        $annShort = htmlspecialchars(mb_substr($annTxt, 0, 130));
        $annFull  = htmlspecialchars($_ann['body'] ?? '');
        $hasMore  = mb_strlen($annTxt) > 130;
        echo '<div id="hriAnn'.$annId.'" style="background:'.$annBg.';border:1px solid '.$annBdr.'40;border-left:4px solid '.$annBdr.';border-radius:8px;padding:10px 14px;margin:8px 16px 0;display:flex;align-items:flex-start;gap:10px;">';
        echo '<span style="font-size:16px;line-height:1.4;flex-shrink:0;">'.$annIco.'</span>';
        echo '<div style="flex:1;min-width:0;">';
        echo '<div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:2px;">'.htmlspecialchars($_ann['title']??'').'</div>';
        echo '<div style="font-size:12.5px;color:#334155;line-height:1.5;">';
        echo '<span id="hriAnnS'.$annId.'">'.$annShort.($hasMore?'&hellip;':'').'</span>';
        echo '<span id="hriAnnF'.$annId.'" style="display:none;white-space:pre-line;">'.$annFull.'</span>';
        if ($hasMore) echo '<button onclick="document.getElementById(\'hriAnnS'.$annId.'\').style.display=\'none\';document.getElementById(\'hriAnnF'.$annId.'\').style.display=\'\';this.style.display=\'none\';" style="border:none;background:none;color:#002850;font-size:12px;font-weight:700;cursor:pointer;padding:0 4px;">Read more</button>';
        echo '</div></div>';
        echo '<button onclick="document.getElementById(\'hriAnn'.$annId.'\').style.display=\'none\';try{localStorage.setItem(\'hri_ann_'.$annId.'\',\'1\');}catch(e){}" style="border:none;background:rgba(0,0,0,.06);border-radius:4px;cursor:pointer;font-size:14px;padding:2px 7px;color:#64748b;flex-shrink:0;line-height:1.4;">&#10005;</button>';
        echo '</div>';
        echo '<script>try{if(localStorage.getItem(\'hri_ann_'.$annId.'\')==="1")document.getElementById(\'hriAnn'.$annId.'\').style.display="none";}catch(e){}</script>';
    }
    } // end shell()

    public static function end(): void {
?>
    <footer class="hri-footer">
        &copy; <?= date('Y') ?> HR Indexx Limited &mdash; All Rights Reserved &middot;
        12 Macarthy Street, Onikan, Lagos Island &middot;
        <a href="<?= APP_URL ?>/it-request.php">IT Support</a>
    </footer>
</div>

<div id="hriToastWrap"></div>


<?php
// Prepare JS data safely before script tag
$_jsStaff = [];
try {
    $__db = $GLOBALS['db'] ?? (isset($db) ? $db : null);
    if ($_jsDb = $__db) {
        $__st = $_jsDb->query("SELECT name,email FROM users WHERE is_active=1 ORDER BY name");
        $_jsStaff = $__st ? array_values($__st->fetchAll(PDO::FETCH_ASSOC)) : [];
    }
} catch (Exception $__e) {}
$_jsCsrf = '';
try { $_jsCsrf = Auth::csrfToken(); } catch (Exception $__e) {}
?>
<script>
(function() {
'use strict';
window.CSRF_TOKEN = '<?= addslashes($_jsCsrf) ?>';
var CSRF_TOKEN = window.CSRF_TOKEN; // local alias for IIFE use
// Auto-inject CSRF into forms — covers both user-click and JS-triggered submission
function _hriInjectCsrf(f) {
    if (f && f.method && f.method.toLowerCase() === 'post' && !f.querySelector('[name="_csrf"]')) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = '_csrf'; inp.value = window.CSRF_TOKEN;
        f.appendChild(inp);
    }
}
// Natural submit (button click / Enter key)
document.addEventListener('submit', function(e) { _hriInjectCsrf(e.target); }, true);
// Programmatic form.submit() — does NOT fire the submit event in any browser
try {
    var _hriNativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function() { _hriInjectCsrf(this); _hriNativeSubmit.call(this); };
} catch(e) {}
var STAFF      = <?= json_encode($_jsStaff ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]' ?>;

/* ── SIDEBAR ── */
var sidebar   = document.getElementById('hriSidebar');
var sbOverlay = document.getElementById('hriSbOverlay');
var hamburger = document.getElementById('hriHamburger');

function openSidebar() {
    sidebar.classList.add('hri-open');
    sbOverlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeSidebar() {
    sidebar.classList.remove('hri-open');
    sbOverlay.classList.remove('show');
    document.body.style.overflow = '';
}
if (hamburger) hamburger.addEventListener('click', function() {
    sidebar.classList.contains('hri-open') ? closeSidebar() : openSidebar();
});
if (sbOverlay) sbOverlay.addEventListener('click', closeSidebar);

document.querySelectorAll('.hri-sb-item').forEach(function(el) {
    el.addEventListener('click', function() {
        if (window.innerWidth <= 768) closeSidebar();
    });
});

var tbAv   = document.getElementById('hriTbAvatar');
var avDrop = document.getElementById('hriAvDrop');
if (tbAv) tbAv.addEventListener('click', function(e) {
    e.stopPropagation();
    if (avDrop) avDrop.classList.toggle('show');
});
document.addEventListener('click', function() { if (avDrop) avDrop.classList.remove('show'); });
if (avDrop) avDrop.addEventListener('click', function(e) { e.stopPropagation(); });

var searchInp = document.getElementById('hriSearchInp');
if (searchInp) searchInp.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && this.value.trim())
        location.href = '<?= APP_URL ?>/search.php?q=' + encodeURIComponent(this.value.trim());
});

/* ── COMPOSE STATE ── */
var panel       = document.getElementById('hriComposePanel');
var cpBody      = document.getElementById('hriCpBody');
var cpMinBar    = document.getElementById('hriCpMinBar');
var toEmails    = [];
var draftTimer  = null;
var isOpen      = false;
var isMinimised = false;

function openCompose(opts) {
    if (!panel) return;
    opts = opts || {};
    isOpen = true;
    isMinimised = false;
    // Reset any drag-repositioning so panel returns to CSS bottom-right
    panel.style.top=''; panel.style.left=''; panel.style.right=''; panel.style.bottom='';
    panel.style.transform=''; panel.style.transition='';
    panel.removeAttribute('data-expanded');
    panel.classList.add('hri-open');
    panel.classList.remove('hri-min');
    panel.style.zIndex = ''; // restore CSS z-index (600); drag sets it to 820 temporarily
    if (cpBody)   cpBody.style.display = 'flex';
    if (cpMinBar) cpMinBar.style.display = 'none';

    if (opts.to) {
        opts.to.split(/[,;]+/).forEach(function(addr) {
            addr = addr.trim();
            if (addr) addPill(addr, addr);
        });
    }
    if (opts.cc)      { var ccInp = document.getElementById('hriCcInp'); if(ccInp) ccInp.value = opts.cc; }
    if (opts.subject) { var s = document.getElementById('hriSubInp'); if(s) s.value = opts.subject; }
    if (opts.body)    { var b = document.getElementById('hriBodyInp'); if(b) b.innerHTML = opts.body.replace(/\n/g,'<br/>'); }

    if (!opts.to && !opts.subject && !opts.body) restoreDraft();

    clearInterval(draftTimer);
    draftTimer = setInterval(function(){
        autosave();
        saveImapDraft();
    }, 60000); // 60s - local save every 15s via typing, IMAP save every 60s

    setTimeout(function() {
        var inp = document.getElementById('hriToInp');
        if (inp) inp.focus();
    }, 150);
}

function minimiseCompose() {
    if (!panel) return;
    isMinimised = true;
    panel.classList.add('hri-min');
    if (cpBody)   cpBody.style.display = 'none';
    if (cpMinBar) {
        cpMinBar.style.display = 'flex';
        var sub = document.getElementById('hriSubInp');
        var tit = document.getElementById('hriMinTitle');
        if (tit && sub) tit.textContent = sub.value || 'New Message';
    }
}

function restoreCompose() {
    if (!panel) return;
    isMinimised = false;
    panel.classList.remove('hri-min');
    if (cpBody)   cpBody.style.display = 'flex';
    if (cpMinBar) cpMinBar.style.display = 'none';
}

function closeCompose() {
    if (!panel) return;
    autosave();
    isOpen = false;
    isMinimised = false;
    panel.classList.remove('hri-open');
    panel.classList.remove('hri-min');
    panel.style.zIndex = '';
    panel.style.top=''; panel.style.left=''; panel.style.right=''; panel.style.bottom='';
    panel.style.transform=''; panel.style.transition='';
    panel.removeAttribute('data-expanded');
    clearInterval(draftTimer);
}

function discardCompose() {
    var sub  = document.getElementById('hriSubInp');
    var body = document.getElementById('hriBodyInp');
    // body is contenteditable div - use textContent not .value
    var subVal  = sub  ? (sub.value  || '').trim() : '';
    var bodyVal = body ? (body.textContent || body.innerHTML || '').trim() : '';
    var hasContent = toEmails.length > 0 || subVal || bodyVal;
    if (hasContent && !confirm('Discard this message?')) return;
    localStorage.removeItem('hri_draft');
    resetCompose();
    closeCompose();
}

function resetCompose() {
    toEmails = [];
    ['hriToPills','hriAttList'].forEach(function(id) {
        var el = document.getElementById(id); if(el) el.innerHTML = '';
    });
    ['hriToInp','hriToVal','hriSubInp','hriCcInp','hriBccInp'].forEach(function(id) {
        var el = document.getElementById(id); if(el) el.value = '';
    });
    var bodyEl = document.getElementById('hriBodyInp');
    if(bodyEl) bodyEl.innerHTML = '';
    var ds  = document.getElementById('hriDraftStatus');  if(ds) ds.textContent = '';
    var btn = document.getElementById('hriSendBtn');       if(btn) { btn.textContent = 'Send'; btn.disabled = false; }
}

/* Wire up compose open buttons (panel control buttons use onclick fallbacks + window.hriXxx globals) */
['hriSbCompose','hriTbCompose','hriMobCompose'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', function() { openCompose(); });
});

/* ── DRAFT SAVE ── */
function autosave() {
    var to   = document.getElementById('hriToVal');
    var sub  = document.getElementById('hriSubInp');
    var body = document.getElementById('hriBodyInp');
    if (!to || !sub || !body) return;
    var d = { to: to.value, subject: sub.value, body: body.innerHTML, ts: Date.now() };
    if (d.to || d.subject || d.body) {
        localStorage.setItem('hri_draft', JSON.stringify(d));
        var st = document.getElementById('hriDraftStatus');
        if (st) st.textContent = 'Saved ' + new Date().toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit',hour12:true});
    }
}
function saveImapDraft() {
    var to   = document.getElementById('hriToVal');
    var sub  = document.getElementById('hriSubInp');
    var body = document.getElementById('hriBodyInp');
    if (!sub || !sub.value.trim()) return; // Don't save empty drafts
    var fd = new FormData();
    fd.append('action',  'save_draft');
    fd.append('uid',     '0');
    fd.append('folder',  'Drafts');
    fd.append('to',      to ? to.value : '');
    fd.append('subject', sub.value);
    fd.append('body',    body ? (body.innerHTML||'') : '');
    fetch('<?= APP_URL ?>/api/mail/action.php', {
        method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-CSRF-Token': CSRF_TOKEN}
    }).then(function(r){return r.json();})
    .then(function(d){
        var st = document.getElementById('hriDraftStatus');
        if(st) st.textContent = d.ok ? 'Draft saved to server ' + new Date().toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}) : 'Draft saved locally';
    }).catch(function(){});
}

function restoreDraft() {
    try {
        var raw = localStorage.getItem('hri_draft');
        if (!raw) return;
        var d = JSON.parse(raw);
        if (Date.now() - d.ts > 86400000) { localStorage.removeItem('hri_draft'); return; }
        if (d.to) d.to.split(',').forEach(function(e) { e = e.trim(); if(e) addPill(e, e); });
        var sub = document.getElementById('hriSubInp');   if(sub && d.subject) sub.value = d.subject;
        var bod = document.getElementById('hriBodyInp');  if(bod && d.body)    bod.innerHTML = d.body;
        var st  = document.getElementById('hriDraftStatus'); if(st) st.textContent = 'Draft restored';
    } catch(e) {}
}

['hriSubInp'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', function() { clearTimeout(el._st); el._st = setTimeout(autosave, 3000); });
});
var bodyEl2 = document.getElementById('hriBodyInp');
if (bodyEl2) bodyEl2.addEventListener('input', function() { clearTimeout(bodyEl2._st); bodyEl2._st = setTimeout(autosave, 3000); });

/* ── AUTOCOMPLETE ── */
var acDrop = document.getElementById('hriAcDrop');
var toInp  = document.getElementById('hriToInp');
if (toInp) {
    toInp.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        if (!q || !acDrop) { if(acDrop) acDrop.style.display='none'; return; }
        var matches = STAFF.filter(function(s) {
            return s.name.toLowerCase().indexOf(q) !== -1 || s.email.toLowerCase().indexOf(q) !== -1;
        }).slice(0, 8);
        if (!matches.length) { acDrop.style.display='none'; return; }
        acDrop.innerHTML = matches.map(function(s) {
            var ini = s.name.substring(0,2).toUpperCase();
            return '<div class="hri-ac-item" data-email="'+s.email+'" data-name="'+s.name.replace(/"/g,'&quot;')+'">'
                + '<div class="hri-ac-av">'+ini+'</div>'
                + '<div><div class="hri-ac-name">'+s.name+'</div><div class="hri-ac-email">'+s.email+'</div></div>'
                + '</div>';
        }).join('');
        acDrop.style.display = 'block';
        acDrop.querySelectorAll('.hri-ac-item').forEach(function(item) {
            item.addEventListener('click', function() {
                addPill(this.dataset.email, this.dataset.name);
                toInp.value = '';
                acDrop.style.display = 'none';
                toInp.focus();
            });
        });
    });
    toInp.addEventListener('keydown', function(e) {
        if ((e.key==='Enter'||e.key===','||e.key===';'||e.key==='Tab') && this.value.trim()) {
            e.preventDefault();
            var v = this.value.trim().replace(/[,;]/g,'');
            if (v.indexOf('@') !== -1) { addPill(v, v); this.value = ''; }
        }
        if (e.key === 'Escape' && acDrop) acDrop.style.display = 'none';
    });
    toInp.addEventListener('blur', function() {
        var v = this.value.trim().replace(/[,;]/g,'');
        if (v && v.indexOf('@') !== -1) { addPill(v, v); this.value = ''; }
    });
}

function addPill(email, name) {
    if (toEmails.indexOf(email) !== -1) return;
    toEmails.push(email);
    syncTo();
    var pills = document.getElementById('hriToPills');
    if (!pills) return;
    var p = document.createElement('div');
    p.className = 'hri-pill-tag';
    p.dataset.email = email;
    p.innerHTML = '<span class="hri-pill-tag-name">' + (name !== email ? name : email) + '</span>'
        + '<span class="hri-pill-tag-x">&#215;</span>';
    p.querySelector('.hri-pill-tag-x').addEventListener('click', function() {
        var i = toEmails.indexOf(email);
        if (i > -1) toEmails.splice(i, 1);
        syncTo();
        p.remove();
    });
    pills.appendChild(p);
}

function syncTo() {
    var h = document.getElementById('hriToVal');
    if (h) h.value = toEmails.join(',');
}

document.addEventListener('click', function(e) {
    if (acDrop && !e.target.closest('#hriToInp') && !e.target.closest('#hriAcDrop'))
        acDrop.style.display = 'none';
});

/* ── CC/BCC ── */
var ccToggle = document.getElementById('hriCcToggle');
if (ccToggle) ccToggle.addEventListener('click', function() {
    ['hriCcRow','hriBccRow'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = el.style.display==='none' ? 'flex' : 'none';
    });
});

/* ── TOOLBAR ── */
document.querySelectorAll('.hri-tb-fmt[data-cmd]').forEach(function(btn) {
    btn.addEventListener('mousedown', function(e) {
        e.preventDefault(); // Don't blur the editor
        var editor = document.getElementById('hriBodyInp');
        if (editor) {
            editor.focus();
            document.execCommand(this.dataset.cmd, false, null);
        }
    });
});
var linkBtn = document.getElementById('hriLinkBtn');
if (linkBtn) linkBtn.addEventListener('click', function() {
    var url = prompt('Enter URL:', 'https://');
    if (url) {
        var ed = document.getElementById('hriBodyInp');
        if(ed) { ed.focus(); document.execCommand('createLink', false, url); }
    }
});

/* ── ATTACHMENTS ── */
var attBtn = document.getElementById('hriAttBtn');
var attInp = document.getElementById('hriAttInp');
if (attBtn && attInp) attBtn.addEventListener('click', function() { attInp.click(); });
if (attInp) attInp.addEventListener('change', function() {
    var list = document.getElementById('hriAttList');
    if (!list) return;
    list.innerHTML = Array.from(this.files).map(function(f) {
        return '<div class="hri-att-chip">&#128206; ' + f.name
            + ' <span style="color:var(--g400);font-size:11px;">(' + Math.round(f.size/1024) + 'KB)</span>'
            + '<span class="hri-att-chip-x">&#215;</span></div>';
    }).join('');
    list.querySelectorAll('.hri-att-chip-x').forEach(function(x) {
        x.addEventListener('click', function() { x.parentElement.remove(); });
    });
});

/* ── DISCARD ── */
var discardBtn = document.getElementById('hriDiscardBtn');
if (discardBtn) discardBtn.addEventListener('click', discardCompose);

/* ── SEND ── */
var sendBtn = document.getElementById('hriSendBtn');
if (sendBtn) sendBtn.addEventListener('click', function() {
    var to   = document.getElementById('hriToVal');
    var sub  = document.getElementById('hriSubInp');
    var body = document.getElementById('hriBodyInp');
    var cc   = document.getElementById('hriCcInp');
    var bcc  = document.getElementById('hriBccInp');
    // Auto-add any typed email in the input before sending
    var rawInp = document.getElementById('hriToInp');
    if (rawInp && rawInp.value.trim() && rawInp.value.indexOf('@') !== -1) {
        addPill(rawInp.value.trim(), rawInp.value.trim());
        rawInp.value = '';
    }
    if (!to || !to.value.trim()) { showToast('Please add a recipient', 'err'); return; }
    if (!sub || !sub.value.trim()) { showToast('Please enter a subject', 'err'); return; }
    this.textContent = 'Sending...';
    this.disabled = true;
    var fd = new FormData();
    fd.append('to', to.value);
    fd.append('subject', sub.value);
    // Collect body content from contenteditable
    var bodyContent = body ? (body.innerHTML || '') : '';
    
    // Append signature (separate block below editor)
    var sigInner = document.getElementById('hriSigInner');
    var sigBlock = document.getElementById('hriSigBlock');
    if (sigInner && sigBlock && sigBlock.style.display !== 'none' && sigInner.innerHTML.trim()) {
        bodyContent += '<br><div style="padding:8px 0 0;font-size:13px;color:#64748b;">'
            + '<div style="border-top:1px solid #cbd5e1;margin-bottom:8px;"></div>'
            + '<div style="line-height:1.6;">' + sigInner.innerHTML + '</div></div>';
    }
    
    // Wrap in full HTML email document
    var fullBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"/>'
        + '<style>body{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#222;}a{color:#002850;}</style>'
        + '</head><body style="max-width:700px;margin:0 auto;padding:20px;">'
        + bodyContent
        + '</body></html>';
    fd.append('body', fullBody);
    if (cc && cc.value)  fd.append('cc', cc.value);
    if (bcc && bcc.value) fd.append('bcc', bcc.value);
    if (attInp && attInp.files.length)
        Array.from(attInp.files).forEach(function(f) { fd.append('attachments[]', f); });
    // Send directly via PHPMailer SMTP (uses session auth — reliable on cPanel)
    fetch('<?= APP_URL ?>/api/mail/send.php', {
        method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-CSRF-Token': CSRF_TOKEN}
    })
    .then(function(r){return r.json();})
    .then(function(d){
        if (d.ok) {
            localStorage.removeItem('hri_draft');
            resetCompose();
            closeCompose();
            showToast('&#10003; Message sent','ok');
        } else {
            showToast('&#10007; '+(d.error||'Send failed'),'err');
            sendBtn.textContent='Send';
            sendBtn.disabled=false;
        }
    })
    .catch(function(e){
        showToast('&#10007; Network error — try again','err');
        sendBtn.textContent='Send';
        sendBtn.disabled=false;
    });
});

/* ── UNDO SEND TOAST ── */
function showUndoToast(queueId) {
    var wrap = document.getElementById('hriToastWrap');
    if (!wrap) return;
    var t = document.createElement('div');
    t.className = 'hri-toast';
    t.style.borderLeft = '4px solid var(--green)';
    var countdown = 10;
    t.innerHTML = '&#10003; Sending in <b id="hriCountdown">10</b>s &nbsp;<button onclick="hriUndoSend(' + queueId + ',this)" style="background:#fff;color:#002850;border:none;border-radius:4px;padding:3px 10px;cursor:pointer;font-weight:600;margin-left:6px;">Undo</button>';
    wrap.appendChild(t);
    var timer = setInterval(function() {
        countdown--;
        var el = document.getElementById('hriCountdown');
        if (el) el.textContent = countdown;
        if (countdown <= 0) {
            clearInterval(timer);
            t.style.opacity = '0';
            t.style.transition = 'opacity .3s';
            setTimeout(function(){ t.remove(); }, 350);
        }
    }, 1000);
    t._timer = timer;
}

function hriUndoSend(queueId, btn) {
    btn.textContent = 'Cancelling...';
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'cancel');
    fd.append('queue_id', queueId);
    fetch('<?= APP_URL ?>/api/mail/queue.php', {
        method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-CSRF-Token': CSRF_TOKEN}
    })
    .then(function(r){return r.json();})
    .then(function(d){
        var toast = btn.closest('.hri-toast');
        if (d.ok) {
            if (toast) { toast.style.borderLeft='4px solid var(--warn)'; toast.innerHTML='&#8617; Send cancelled &mdash; message not sent'; }
            setTimeout(function(){ if(toast) toast.remove(); }, 3000);
            // Restore compose with content
            showToast('Send cancelled. You can edit and resend.','warn');
        } else {
            if (toast) toast.innerHTML = '&#10007; Too late — already sent';
            setTimeout(function(){ if(toast) toast.remove(); }, 3000);
        }
    });
}

/* ── TOAST ── */
function showToast(msg, type) {
    var wrap = document.getElementById('hriToastWrap');
    if (!wrap) return;
    var t = document.createElement('div');
    t.className = 'hri-toast';
    t.innerHTML = msg;
    t.style.borderLeft = '4px solid ' + (type==='ok' ? '#1a7f37' : type==='warn' ? '#f59e0b' : '#d93025');
    wrap.appendChild(t);
    setTimeout(function() {
        t.style.opacity = '0';
        t.style.transition = 'opacity .3s';
        setTimeout(function() { t.remove(); }, 350);
    }, 3500);
}

function showMailToast(from, subj) {
    var wrap = document.getElementById('hriToastWrap');
    if (!wrap) return;
    var t = document.createElement('div');
    t.className = 'hri-toast';
    t.style.cssText = 'border-left:4px solid #0891b2;cursor:pointer;min-width:260px;max-width:340px;padding:10px 14px;';
    t.innerHTML = '<div style="display:flex;gap:9px;align-items:flex-start;">'
        + '<span style="font-size:18px;line-height:1.2;">&#128236;</span>'
        + '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:12.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + String(from||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>'
        + '<div style="font-size:12px;opacity:.75;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + String(subj||'(no subject)').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>'
        + '</div>'
        + '<span style="font-size:10px;opacity:.5;margin-left:4px;flex-shrink:0;">New mail</span>'
        + '</div>';
    t.addEventListener('click', function() { window.location.href = '<?= APP_URL ?>/mail.php'; });
    wrap.appendChild(t);
    setTimeout(function() {
        t.style.opacity = '0'; t.style.transition = 'opacity .3s';
        setTimeout(function() { t.remove(); }, 350);
    }, 7000);
}

/* ── CONTENTEDITABLE PLACEHOLDER ── */
var bodyEditor = document.getElementById('hriBodyInp');
if (bodyEditor) {
    function updatePlaceholder() {
        if (bodyEditor.textContent.trim() === '') {
            bodyEditor.setAttribute('data-empty', 'true');
        } else {
            bodyEditor.removeAttribute('data-empty');
        }
    }
    bodyEditor.addEventListener('input', updatePlaceholder);
    bodyEditor.addEventListener('focus', updatePlaceholder);
    updatePlaceholder();
}

/* ── KEYBOARD ── */
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if ((e.ctrlKey||e.metaKey) && e.key === 'm') { e.preventDefault(); openCompose(); }
    if (e.key === 'Escape' && isOpen && !isMinimised) minimiseCompose();
});

/* ── SCHEDULE SEND ── */
function hriShowSchedule() {
    var p = document.getElementById('hriSchedulePanel');
    if (p) p.style.display = p.style.display==='none'?'block':'none';
    // Set default datetime to tomorrow 8AM
    var d = new Date(); d.setDate(d.getDate()+1); d.setHours(8,0,0,0);
    var iso = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')+'T08:00';
    var inp = document.getElementById('hriCustomDate');
    if(inp && !inp.value) inp.value = iso;
}
function hriScheduleAt(hour, label, dayOffset) {
    var d = new Date();
    d.setDate(d.getDate() + (dayOffset !== undefined ? dayOffset : 1));
    d.setHours(hour||8, 0, 0, 0);
    var sendAt = Math.floor(d.getTime()/1000);
    var delay  = sendAt - Math.floor(Date.now()/1000);
    if (delay < 60) delay = 60;
    hriQueueEmail(delay, label);
}
function hriScheduleCustom() {
    var val = document.getElementById('hriCustomDate').value;
    if (!val) return;
    var d    = new Date(val);
    var delay = Math.floor((d.getTime() - Date.now()) / 1000);
    if (delay < 60) { alert('Please choose a future time (at least 1 minute from now)'); return; }
    hriQueueEmail(delay, 'Scheduled: ' + d.toLocaleString('en-GB'));
}
function hriQueueEmail(delay, label) {
    var to   = document.getElementById('hriToVal');
    var sub  = document.getElementById('hriSubInp');
    var body = document.getElementById('hriBodyInp');
    var cc   = document.getElementById('hriCcInp');
    var bcc  = document.getElementById('hriBccInp');
    if (!to||!to.value.trim()) { showToast('Please add a recipient','err'); return; }
    if (!sub||!sub.value.trim()) { showToast('Please add a subject','err'); return; }
    var fd = new FormData();
    fd.append('action','queue'); fd.append('to',to.value); fd.append('subject',sub.value);
    fd.append('body',body?body.innerHTML:''); fd.append('delay',delay);
    if(cc&&cc.value) fd.append('cc',cc.value);
    if(bcc&&bcc.value) fd.append('bcc',bcc.value);
    fetch('<?= APP_URL ?>/api/mail/queue.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':CSRF_TOKEN}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok){
            localStorage.removeItem('hri_draft');
            resetCompose(); closeCompose();
            document.getElementById('hriSchedulePanel') && (document.getElementById('hriSchedulePanel').style.display='none');
            showToast('&#128339; ' + label + ' — email scheduled', 'ok');
        } else showToast('Schedule failed: '+(d.error||'Unknown error'),'err');
    });
}

/* ── SIGNATURE PICKER ── */
window.HRI_SIGS = <?= json_encode(array_values(self::$sigsForJs), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' ?>;
(function() {
    var btn  = document.getElementById('hriSigPickerBtn');
    var drop = document.getElementById('hriSigPickerDrop');
    if (!btn || !drop) return;
    var sigs = window.HRI_SIGS || [];
    var html = '<div style="padding:6px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--g400);border-bottom:1px solid var(--g100);">Signature</div>';
    html += '<div class="hriSigOpt" onclick="window.hriSetSig(null)" style="padding:8px 14px;cursor:pointer;font-size:12.5px;color:var(--g500);">&#8709; No Signature</div>';
    if (!sigs.length) {
        html += '<div style="padding:8px 14px;font-size:12px;color:var(--g400);">No signatures saved. <a href="<?= APP_URL ?>/signature.php" style="color:var(--navy);">Create one</a></div>';
    }
    sigs.forEach(function(s) {
        html += '<div class="hriSigOpt" onclick="window.hriSetSig(' + parseInt(s.id) + ')" style="padding:8px 14px;cursor:pointer;font-size:12.5px;">'
            + (s.is_default ? '<span style="color:#d97706;">&#9733;</span> ' : '') + (s.name||'').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
    });
    drop.innerHTML = html;
    drop.querySelectorAll('.hriSigOpt').forEach(function(el) {
        el.addEventListener('mouseover', function(){ this.style.background='var(--g50)'; });
        el.addEventListener('mouseout',  function(){ this.style.background=''; });
    });
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        drop.style.display = drop.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', function() { if (drop) drop.style.display = 'none'; });
    drop.addEventListener('click', function(e) { e.stopPropagation(); });
})();

function hriSetSig(sigId) {
    var block = document.getElementById('hriSigBlock');
    var inner = document.getElementById('hriSigInner');
    var drop  = document.getElementById('hriSigPickerDrop');
    if (drop) drop.style.display = 'none';
    if (!block || !inner) return;
    if (sigId === null) {
        block.style.display = 'none';
        inner.innerHTML = '';
        return;
    }
    var sigs = window.HRI_SIGS || [];
    for (var i = 0; i < sigs.length; i++) {
        if (sigs[i].id == sigId) {
            inner.innerHTML = sigs[i].html;
            block.style.display = '';
            return;
        }
    }
}

/* ── PUBLIC API ── */
window.hriOpenCompose    = openCompose;
window.hriCloseCompose   = closeCompose;
window.hriMinimiseCompose = minimiseCompose;
window.hriRestoreCompose  = restoreCompose;
window.hriDiscardCompose  = discardCompose;
window.hriExpandCompose   = function() {
    if (!panel) return;
    if (panel.dataset.expanded === '1') {
        panel.removeAttribute('data-expanded');
        panel.style.cssText = ''; panel.classList.add('hri-open');
    } else {
        panel.dataset.expanded = '1';
        panel.style.cssText = 'position:fixed;inset:0;width:100%;max-height:100vh;border-radius:0;z-index:850;transform:none;';
    }
};
window.hriShowToast      = showToast;
window.hriAddRecipient   = addPill;
window.hriShowSchedule   = hriShowSchedule;
window.hriSetSig         = hriSetSig;

/* ── UNIFIED TAB TITLE ── */
window._hriMailUnread = 0;
window._hriBaseTitle  = document.title;
function _hriUpdateTitle() {
    document.title = window._hriMailUnread > 0
        ? '(' + window._hriMailUnread + ' mail) ' + window._hriBaseTitle
        : window._hriBaseTitle;
}

/* ── REAL-TIME INBOX POLLING ── */
(function() {
    var lastCount   = -1;
    var lastUid     = 0;
    var pollTimer   = null;
    var failCount   = 0;
    var POLL_ACTIVE = 12000;
    var POLL_HIDDEN = 60000;

    function updateBadges(unread) {
        document.querySelectorAll('.hri-badge, .hri-sb-badge, .hri-mob-badge').forEach(function(el) {
            if (unread > 0) {
                el.textContent = unread > 99 ? '99+' : unread;
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });
        window._hriMailUnread = unread;
        _hriUpdateTitle();
    }

    function playNewMailSound() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain); gain.connect(ctx.destination);
            osc.frequency.value = 520; gain.gain.value = 0.1;
            osc.start(); osc.stop(ctx.currentTime + 0.15);
        } catch(e) {}
    }

    function refreshMailList() {
        var listEl = document.getElementById('mailList');
        if (!listEl) return;
        var compose = document.getElementById('hriComposePanel');
        if (compose && compose.classList.contains('hri-open')) return;
        var url = window.location.href.split('#')[0];
        if (url.indexOf('mail.php') === -1) return;
        setTimeout(function() {
            fetch(url, {credentials: 'same-origin'})
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var nl = doc.getElementById('mailList');
                    if (nl) listEl.innerHTML = nl.innerHTML;
                })
                .catch(function() {});
        }, 800);
    }

    function scheduleNext() {
        clearTimeout(pollTimer);
        var delay = failCount > 3 ? POLL_ACTIVE * 5 : (document.hidden ? POLL_HIDDEN : POLL_ACTIVE);
        pollTimer = setTimeout(poll, delay);
    }

    function poll() {
        fetch('<?= APP_URL ?>/api/mail/poll.php?last=' + lastCount + '&lastUid=' + lastUid, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'X-CSRF-Token': CSRF_TOKEN}
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.ok) { failCount++; scheduleNext(); return; }
            failCount = 0;
            if (d.unread !== lastCount) {
                lastCount = d.unread;
                updateBadges(d.unread);
            }
            if (d.maxUid && d.maxUid > lastUid) {
                if (lastUid > 0 && d.hasNew && d.newMails && d.newMails.length) {
                    d.newMails.forEach(function(m) {
                        showMailToast(m.from, m.subj);
                    });
                    playNewMailSound();
                    refreshMailList();
                }
                lastUid = d.maxUid;
            }
            scheduleNext();
        })
        .catch(function() { failCount++; scheduleNext(); });
    }

    setTimeout(poll, 8000);

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) { clearTimeout(pollTimer); poll(); }
    });
})();

/* ── AUTO-OPEN FROM HASH ── */
if (location.hash === '#compose') {
    history.replaceState(null, '', location.pathname + location.search);
    openCompose();
}

    function hfcpLoadList() {
        var el=document.getElementById('hfcpList');
        if(el) el.innerHTML='<div class="hfcp-empty"><span style="font-size:24px;">&#128172;</span>Loading...</div>';
        var fCh=fetch(APP+'/api/chat/channels.php',{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
                .then(function(r){return r.json();}).catch(function(){return {channels:[]};});
        var fPp=fetch(APP+'/api/chat/people.php',{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
                .then(function(r){return r.json();}).catch(function(){return {people:[]};});
        Promise.all([fCh,fPp]).then(function(res){
            listData={channels:res[0].channels||[],people:res[1].people||[]};
            renderList(listData);
        }).catch(function(){
            if(el)el.innerHTML='<div class="hfcp-empty">Could not load. Please refresh.</div>';
        });
    }

    function renderList(data) {
        var q=(document.getElementById('hfcpSearch')||{}).value||'';
        q=q.toLowerCase();
        var html='';
        var chs=(data.channels||[]).filter(function(c){return c.type==='public'&&(!q||c.name.toLowerCase().indexOf(q)!==-1);});
        if(chs.length){
            html+='<div class="hfcp-section">Channels</div>';
            chs.forEach(function(c){
                var u=parseInt(c.unread||0);
                // Use data-* attributes (avoids double-quote clash in onclick HTML attributes)
                html+='<div class="hfcp-item hfcp-ch-item" data-chid="'+c.id+'" data-chname="'+esc(c.name)+'">'
                    +'<div class="hfcp-ch-ico">#</div>'
                    +'<div class="hfcp-item-info"><div class="hfcp-item-name">#'+esc(c.name)+'</div>'
                    +(c.last_body?'<div class="hfcp-item-sub">'+esc(String(c.last_body).substring(0,40))+'</div>':'')
                    +'</div>'+(u>0?'<span class="hfcp-item-badge">'+u+'</span>':'')+'</div>';
            });
        }
        var ppl=(data.people||[]).filter(function(p){return !p.is_me&&(!q||p.name.toLowerCase().indexOf(q)!==-1||(p.role_label||'').toLowerCase().indexOf(q)!==-1);});
        if(ppl.length){
            html+='<div class="hfcp-section">People ('+(ppl.filter(function(x){return parseInt(x.is_online);}).length)+' online)</div>';
            ppl.forEach(function(p){
                var col=p.avatar_color||'#64748b';
                var on=parseInt(p.is_online||0);
                html+='<div class="hfcp-item hfcp-pp-item"'
                    +' data-uid="'+esc(p.id)+'"'
                    +' data-name="'+esc(p.name)+'"'
                    +' data-col="'+esc(col)+'"'
                    +' data-on="'+on+'"'
                    +' data-dmid="'+esc(p.dm_channel_id||'')+'"'
                    +' data-role="'+esc(p.role_label||'')+'">'
                    +'<div class="hfcp-av" style="background:'+esc(col)+';">'+esc(ini(p.name))
                    +'<span class="hfcp-av-dot '+(on?'on':'off')+'"></span></div>'
                    +'<div class="hfcp-item-info"><div class="hfcp-item-name">'+esc(p.name)+'</div>'
                    +'<div class="hfcp-item-sub">'+esc(p.role_label||'')+(on?' &middot; <span style="color:#22c55e;font-weight:600;">Online</span>':'')+'</div>'
                    +'</div></div>';
            });
        }
        if(!html)html='<div class="hfcp-empty"><span style="font-size:28px;">&#128172;</span>'+(q?'No results':'No channels or people found')+'</div>';
        var el=document.getElementById('hfcpList');
        if(el)el.innerHTML=html;
    }

    window.hfcpFilter = function(){if(listData)renderList(listData);};

    // Event delegation — handles clicks on list items rendered with data-* attributes
    (function(){
        var listEl=document.getElementById('hfcpList');
        if(!listEl)return;
        listEl.addEventListener('click',function(e){
            var ch=e.target.closest('.hfcp-ch-item');
            if(ch){ window.hfcpOpenChannel(parseInt(ch.dataset.chid),ch.dataset.chname,'public'); return; }
            var pp=e.target.closest('.hfcp-pp-item');
            if(pp){
                var dmid=pp.dataset.dmid?parseInt(pp.dataset.dmid):null;
                window.hfcpOpenDM(parseInt(pp.dataset.uid),pp.dataset.name,pp.dataset.col,parseInt(pp.dataset.on),dmid,pp.dataset.role);
            }
        });
    })();

    window.hfcpOpenChannel = function(channelId, name, type) {
        curChannel=channelId; curColor='#002850'; lastMsgId=0; prevSender=null; prevDate=null;
        document.getElementById('hfcpListView').style.display='none';
        var mv=document.getElementById('hfcpMsgView');
        mv.style.display='flex'; mv.style.flexDirection='column';
        document.getElementById('hfcpMsgTitle').textContent=(type==='public'?'#':'')+name;
        var av=document.getElementById('hfcpMsgAv');
        av.textContent=type==='public'?'#':ini(name); av.style.background='#002850';
        document.getElementById('hfcpOnlineDot').style.display='none';
        var ep=document.getElementById('hfcpEmojiPick'); if(ep)ep.classList.remove('hfcp-ep-show');
        hfcpClearFile();
        loadMessages();
        clearInterval(panelTimer);
        panelTimer=setInterval(function(){if(panelOpen&&curChannel)pollMessages();},15000);
    };

    window.hfcpOpenDM = function(userId, name, color, online, dmChannelId, roleLabel) {
        curColor=color||'#64748b';
        if(dmChannelId){
            curChannel=dmChannelId; lastMsgId=0; prevSender=null; prevDate=null;
            document.getElementById('hfcpListView').style.display='none';
            var mv=document.getElementById('hfcpMsgView');
            mv.style.display='flex'; mv.style.flexDirection='column';
            document.getElementById('hfcpMsgTitle').textContent=name;
            var av=document.getElementById('hfcpMsgAv');
            av.textContent=ini(name); av.style.background=curColor;
            document.getElementById('hfcpOnlineDot').style.display=online?'':'none';
            var ep=document.getElementById('hfcpEmojiPick'); if(ep)ep.classList.remove('hfcp-ep-show');
            hfcpClearFile();
            loadMessages();
            clearInterval(panelTimer);
            panelTimer=setInterval(function(){if(panelOpen&&curChannel)pollMessages();},15000);
        } else {
            var fd=new FormData(); fd.append('user_id',userId);
            fetch(APP+'/api/chat/dm.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN},body:fd})
            .then(function(r){return r.json();})
            .then(function(d){
                if(d.ok){
                    if(listData){for(var i=0;i<listData.people.length;i++){if(listData.people[i].id==userId){listData.people[i].dm_channel_id=d.channel_id;break;}}}
                    window.hfcpOpenDM(userId,name,color,online,d.channel_id,roleLabel);
                }
            }).catch(function(){});
        }
    };

    window.hfcpShowList = function() {
        curChannel=null; lastMsgId=0; clearInterval(panelTimer);
        document.getElementById('hfcpMsgView').style.display='none';
        var lv=document.getElementById('hfcpListView');
        lv.style.display='flex'; lv.style.flexDirection='column';
        hfcpLoadList();
    };

    function loadMessages() {
        document.getElementById('hfcpMsgs').innerHTML='<div class="hfcp-empty"><span style="font-size:22px;">&#128172;</span>Loading...</div>';
        fetch(APP+'/api/chat/poll.php?channel_id='+curChannel+'&after=0',{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d.ok)return;
            var msgs=d.messages||[];
            document.getElementById('hfcpMsgs').innerHTML='';
            prevSender=null; prevDate=null;
            if(!msgs.length){
                document.getElementById('hfcpMsgs').innerHTML='<div class="hfcp-empty"><span style="font-size:22px;">&#128172;</span>No messages yet. Say hello!</div>';
            } else {
                renderMessages(msgs,false);
                lastMsgId=msgs[msgs.length-1].id;
                markRead();
            }
        }).catch(function(){});
    }

    function pollMessages() {
        if(!curChannel)return;
        fetch(APP+'/api/chat/poll.php?channel_id='+curChannel+'&after='+lastMsgId,{credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d.ok||!d.messages||!d.messages.length)return;
            var wasBottom=isAtBottom();
            renderMessages(d.messages,true);
            lastMsgId=d.messages[d.messages.length-1].id;
            markRead();
            if(wasBottom)scrollBottom();
        }).catch(function(){});
    }

    function renderMessages(msgs,append) {
        var container=document.getElementById('hfcpMsgs');
        var noMsg=container.querySelector('.hfcp-empty');
        if(noMsg&&append)noMsg.remove();
        if(!append){container.innerHTML='';prevSender=null;prevDate=null;}
        var frag=document.createDocumentFragment();
        msgs.forEach(function(m){
            var mDate=String(m.created_at||'').substring(0,10);
            if(mDate!==prevDate){
                var sep=document.createElement('div');
                sep.className='hfcp-day'; sep.textContent=fmtDay(m.created_at);
                frag.appendChild(sep); prevDate=mDate; prevSender=null;
            }
            var same=(m.user_id==prevSender);
            var row=document.createElement('div');
            row.className='hfcp-msg'+(same?' hfcp-same':'');
            var attHtml='';
            if(m.attachment){
                try{
                    var att=typeof m.attachment==='string'?JSON.parse(m.attachment):m.attachment;
                    if(att&&att.url){
                        if(isImg(att.type))attHtml='<img class="hfcp-msg-img" src="'+esc(att.url)+'" alt="'+esc(att.name)+'" onclick="window.open(this.src)"/>';
                        else{var kb=att.size?' ('+Math.round(att.size/1024)+'KB)':'';attHtml='<a class="hfcp-msg-file" href="'+esc(att.url)+'" target="_blank">&#128206; '+esc(att.name)+esc(kb)+'</a>';}
                    }
                }catch(e){}
            }
            row.innerHTML='<div class="hfcp-msg-av" style="background:'+esc(m.avatar_color||'#64748b')+';">'+(same?'':esc(ini(m.user_name||'?')))+'</div>'
                +'<div class="hfcp-msg-bd">'
                +(same?'':'<div class="hfcp-msg-meta"><span class="hfcp-msg-sender">'+esc(m.user_name)+'</span><span>'+fmtTime(m.created_at)+'</span></div>')
                +'<div class="hfcp-msg-text">'+esc(m.body||'')+(attHtml?'<br>'+attHtml:'')+'</div>'
                +'</div>';
            frag.appendChild(row);
            prevSender=m.user_id;
        });
        container.appendChild(frag);
        if(!append)scrollBottom();
    }

    function markRead(){
        if(!curChannel||!lastMsgId)return;
        var fd=new FormData(); fd.append('channel_id',curChannel); fd.append('last_id',lastMsgId);
        fetch(APP+'/api/chat/read.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN},body:fd}).catch(function(){});
    }
    function isAtBottom(){var el=document.getElementById('hfcpMsgs');return el&&(el.scrollHeight-el.scrollTop-el.clientHeight<60);}
    function scrollBottom(){var el=document.getElementById('hfcpMsgs');if(el)el.scrollTop=el.scrollHeight;}

    window.hfcpSend = function() {
        var inp=document.getElementById('hfcpInput');
        var body=inp?inp.value.trim():'';
        if(!body&&!pendingFile)return;
        if(!curChannel)return;
        var btn=document.getElementById('hfcpSendBtn');
        if(btn)btn.disabled=true;
        function doSend(attJson){
            var fd=new FormData();
            fd.append('channel_id',curChannel);
            fd.append('body',body||(pendingFile?pendingFile.name:''));
            if(attJson)fd.append('attachment',attJson);
            fetch(APP+'/api/chat/send.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN},body:fd})
            .then(function(r){return r.json();})
            .then(function(d){
                if(btn)btn.disabled=false;
                if(d.ok&&d.message){
                    var nm=document.getElementById('hfcpMsgs').querySelector('.hfcp-empty');
                    if(nm)nm.remove();
                    renderMessages([d.message],true);
                    lastMsgId=d.message.id;
                    scrollBottom();
                    if(inp){inp.value='';inp.style.height='auto';}
                    hfcpClearFile();
                }
            }).catch(function(){if(btn)btn.disabled=false;});
        }
        if(pendingFile){
            var ufd=new FormData(); ufd.append('file',pendingFile);
            fetch(APP+'/api/chat/upload.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':window.CSRF_TOKEN},body:ufd})
            .then(function(r){return r.json();})
            .then(function(u){
                if(u.ok)doSend(JSON.stringify({name:u.name,url:u.url,size:u.size,type:u.type}));
                else{if(btn)btn.disabled=false;alert(u.error||'Upload failed');}
            }).catch(function(){if(btn)btn.disabled=false;});
        } else { doSend(null); }
    };

    window.hfcpToggleEmoji = function() {
        var pick=document.getElementById('hfcpEmojiPick');
        if(!pick)return;
        if(!pick.classList.contains('hfcp-ep-show')){
            var tabs=document.getElementById('hfcpEmojiTabs');
            if(!tabs.innerHTML){
                EMOJIS.forEach(function(cat,i){
                    var btn=document.createElement('button');
                    btn.className='hfcp-emoji-tab'+(i===0?' hfcp-et-active':'');
                    btn.innerHTML=cat.t; btn.title='Category '+(i+1);
                    btn.onclick=function(){hfcpShowEmojiCat(i);};
                    tabs.appendChild(btn);
                });
                hfcpShowEmojiCat(0);
            }
        }
        pick.classList.toggle('hfcp-ep-show');
    };

    window.hfcpShowEmojiCat = function(idx) {
        document.querySelectorAll('.hfcp-emoji-tab').forEach(function(b,i){b.classList.toggle('hfcp-et-active',i===idx);});
        var grid=document.getElementById('hfcpEmojiGrid');
        if(!grid)return;
        grid.innerHTML='';
        (EMOJIS[idx]||EMOJIS[0]).e.forEach(function(emoji){
            var btn=document.createElement('button');
            btn.className='hfcp-emoji-btn'; btn.textContent=emoji;
            btn.onclick=function(){
                var inp=document.getElementById('hfcpInput');
                if(inp){var s=inp.selectionStart,e=inp.selectionEnd;inp.value=inp.value.substring(0,s)+emoji+inp.value.substring(e);inp.selectionStart=inp.selectionEnd=s+emoji.length;inp.focus();}
            };
            grid.appendChild(btn);
        });
    };

    window.hfcpFileSelected = function(inp) {
        if(!inp.files||!inp.files[0])return;
        pendingFile=inp.files[0];
        var prev=document.getElementById('hfcpFilePrev');
        var nm=document.getElementById('hfcpFileName');
        if(prev)prev.classList.add('hfcp-fp-show');
        if(nm)nm.textContent=pendingFile.name+' ('+Math.round(pendingFile.size/1024)+'KB)';
    };

    window.hfcpClearFile = function() {
        pendingFile=null;
        var prev=document.getElementById('hfcpFilePrev');
        if(prev)prev.classList.remove('hfcp-fp-show');
        var inp=document.getElementById('hfcpFileInp');
        if(inp)inp.value='';
    };


})(); // end IIFE
</script>

<!-- ── HRI TABLE UTILITIES (sort, filter, CSV export) ── injected globally by layout_shell -->
<style>
.hri-tbl-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;}
.hri-tbl-search{flex:1;min-width:140px;max-width:280px;border:1.5px solid #e2e8f0;border-radius:8px;padding:5px 10px;font-size:13px;font-family:inherit;outline:none;background:#f8fafc;color:#0f172a;}
.hri-tbl-search:focus{border-color:#002850;background:#fff;}
.hri-tbl-count{font-size:12px;color:#64748b;white-space:nowrap;}
.hri-tbl-export{border:1.5px solid #002850;background:#fff;color:#002850;border-radius:7px;padding:4px 11px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;transition:background .14s,color .14s;}
.hri-tbl-export:hover{background:#002850;color:#fff;}
.hri-tbl-wrap{overflow-x:auto;}
.hri-th-sort{cursor:pointer;user-select:none;white-space:nowrap;}
.hri-th-sort:hover{background:rgba(0,40,80,.06);}
.hri-th-sort::after{content:' \25BF';font-size:10px;color:#cbd5e1;margin-left:3px;}
.hri-th-sort.hri-sort-asc::after{content:' \25B2';color:#002850;}
.hri-th-sort.hri-sort-desc::after{content:' \25BC';color:#002850;}
@media(prefers-color-scheme:dark){
.hri-tbl-search{background:#1e293b;color:#e2e8f0;border-color:#334155;}
.hri-tbl-search:focus{border-color:#7dd3fc;background:#0f172a;}
.hri-tbl-export{border-color:#7dd3fc;color:#7dd3fc;background:transparent;}
.hri-tbl-export:hover{background:#7dd3fc;color:#0f172a;}
}
</style>
<script>
(function(){
'use strict';

function cellText(td) {
    return (td.dataset.sort !== undefined ? td.dataset.sort : td.textContent || td.innerText || '').trim().toLowerCase();
}

function buildToolbar(tbl, idx) {
    var toolbar = document.createElement('div');
    toolbar.className = 'hri-tbl-toolbar';

    var search = document.createElement('input');
    search.className = 'hri-tbl-search';
    search.type = 'text';
    search.placeholder = '🔍 Search table…';
    search.setAttribute('aria-label', 'Filter table');

    var count = document.createElement('span');
    count.className = 'hri-tbl-count';

    var exportBtn = document.createElement('button');
    exportBtn.className = 'hri-tbl-export';
    exportBtn.type = 'button';
    exportBtn.textContent = '↓ CSV';
    exportBtn.title = 'Export visible rows as CSV';

    toolbar.appendChild(search);
    toolbar.appendChild(count);
    toolbar.appendChild(exportBtn);

    var sortCol = -1, sortDir = 1;

    function getRows() {
        return Array.from(tbl.tBodies[0] ? tbl.tBodies[0].rows : []);
    }

    function updateCount() {
        var rows = getRows();
        var visible = rows.filter(function(r){ return r.style.display !== 'none'; }).length;
        count.textContent = visible === rows.length
            ? rows.length + ' row' + (rows.length === 1 ? '' : 's')
            : visible + ' of ' + rows.length + ' rows';
    }

    function filterRows() {
        var q = search.value.toLowerCase();
        getRows().forEach(function(row) {
            if (!q) { row.style.display = ''; return; }
            var text = Array.from(row.cells).map(function(td){ return cellText(td); }).join(' ');
            row.style.display = text.indexOf(q) !== -1 ? '' : 'none';
        });
        updateCount();
    }

    search.addEventListener('input', filterRows);

    function sortRows(colIdx, th) {
        // Toggle direction
        if (sortCol === colIdx) {
            sortDir = -sortDir;
        } else {
            sortCol = colIdx; sortDir = 1;
        }
        // Update header indicators
        Array.from(tbl.tHead.rows[0].cells).forEach(function(c){
            c.classList.remove('hri-sort-asc','hri-sort-desc');
        });
        th.classList.add(sortDir === 1 ? 'hri-sort-asc' : 'hri-sort-desc');

        var rows = getRows();
        rows.sort(function(a, b) {
            var av = cellText(a.cells[colIdx] || a.cells[0]);
            var bv = cellText(b.cells[colIdx] || b.cells[0]);
            // numeric detection
            var an = parseFloat(av.replace(/[^0-9.\-]/g,''));
            var bn = parseFloat(bv.replace(/[^0-9.\-]/g,''));
            if (!isNaN(an) && !isNaN(bn)) return (an - bn) * sortDir;
            return av.localeCompare(bv) * sortDir;
        });
        var tbody = tbl.tBodies[0];
        rows.forEach(function(r){ tbody.appendChild(r); });
    }

    // Wire up sortable headers
    if (tbl.tHead && tbl.tHead.rows.length > 0) {
        Array.from(tbl.tHead.rows[0].cells).forEach(function(th, i) {
            // Skip action-y columns (short header text or no text)
            var txt = (th.textContent || '').trim();
            if (txt.length === 0) return;
            th.classList.add('hri-th-sort');
            th.addEventListener('click', function(){ sortRows(i, th); });
        });
    }

    function escCsv(v) {
        v = String(v).replace(/"/g, '""');
        return '"' + v + '"';
    }

    exportBtn.addEventListener('click', function() {
        var lines = [];
        // Header row
        if (tbl.tHead && tbl.tHead.rows.length > 0) {
            lines.push(Array.from(tbl.tHead.rows[0].cells).map(function(th){
                return escCsv((th.textContent || '').trim());
            }).join(','));
        }
        // Visible body rows
        getRows().filter(function(r){ return r.style.display !== 'none'; }).forEach(function(row) {
            lines.push(Array.from(row.cells).map(function(td){
                return escCsv(cellText(td).replace(/\s+/g,' '));
            }).join(','));
        });
        var csv = '﻿' + lines.join('\r\n');
        var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'hri-export-' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a); a.click();
        setTimeout(function(){ document.body.removeChild(a); URL.revokeObjectURL(url); }, 500);
    });

    updateCount();
    return {toolbar: toolbar, updateCount: updateCount};
}

function initTables() {
    // Target <table> elements with both <thead> and <tbody> and at least 2 body rows
    // Skip tables already enhanced, tables inside another enhanced table, and tiny tables
    document.querySelectorAll('table').forEach(function(tbl, idx) {
        if (tbl.dataset.hriEnhanced) return;
        if (!tbl.tHead || !tbl.tBodies[0]) return;
        if (tbl.tBodies[0].rows.length < 2) return;
        // Skip tables explicitly opted out
        if (tbl.classList.contains('hri-no-enhance')) return;

        tbl.dataset.hriEnhanced = '1';

        // Wrap table in overflow-x:auto container if not already wrapped
        var parent = tbl.parentNode;
        var wrapper;
        if (parent && parent.classList && parent.classList.contains('hri-tbl-wrap')) {
            wrapper = parent;
        } else {
            wrapper = document.createElement('div');
            wrapper.className = 'hri-tbl-wrap';
            parent.insertBefore(wrapper, tbl);
            wrapper.appendChild(tbl);
        }

        var result = buildToolbar(tbl, idx);
        wrapper.parentNode.insertBefore(result.toolbar, wrapper);
    });
}

// Run after DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTables);
} else {
    initTables();
}

// Expose so pages can re-init after dynamic table updates
window.hriInitTables = initTables;

})();
</script>

<script>
/* ── ANNOUNCEMENT NOTIFICATIONS ───────────────────────────────────────────
   An announcement posted while someone is already signed in was invisible
   until they happened to reload. This lives in the shell, so it reaches
   people on ANY page of the platform, and raises a toast the moment one
   lands. The banner at the top of the page still shows it on next load. */
(function () {
    var KEY  = 'hriAnnSeen';
    var POLL = 60000;            // 60s — announcements are not time-critical
    var busy = false;

    function seen()      { return parseInt(localStorage.getItem(KEY) || '0', 10) || 0; }
    function setSeen(id) { try { localStorage.setItem(KEY, String(id)); } catch (e) {} }

    function toast(a) {
        var wrap = document.getElementById('hriToastWrap');
        if (!wrap) return;
        var col = a.priority === 'urgent' ? '#dc2626'
                : a.priority === 'high'   ? '#f59e0b' : '#3b82f6';
        var ico = a.priority === 'urgent' ? '&#128680;'
                : a.priority === 'high'   ? '&#9888;' : '&#128226;';

        var t = document.createElement('div');
        t.className = 'hri-toast';
        t.style.cssText += 'border-left:4px solid ' + col + ';max-width:420px;text-align:left;cursor:pointer;';
        t.innerHTML =
            '<div style="display:flex;gap:9px;align-items:flex-start;">' +
              '<span style="font-size:16px;flex-shrink:0;">' + ico + '</span>' +
              '<div style="flex:1;min-width:0;">' +
                '<div style="font-weight:700;margin-bottom:2px;">' + esc(a.title) + '</div>' +
                '<div style="font-size:12.5px;opacity:.85;line-height:1.45;">' + esc(a.body) + '</div>' +
              '</div>' +
              '<span style="opacity:.6;font-size:15px;flex-shrink:0;">&times;</span>' +
            '</div>';
        t.onclick = function () { t.remove(); };
        wrap.appendChild(t);
        // urgent stays until dismissed; the rest clear themselves
        if (a.priority !== 'urgent') {
            setTimeout(function () { if (t.parentNode) t.remove(); }, 12000);
        }
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function check() {
        if (busy || document.visibilityState === 'hidden') return;   // don't poll a background tab
        busy = true;
        fetch('api/announcements/poll.php?after=' + seen(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                busy = false;
                if (!d || !d.ok) return;
                if (seen() === 0) { setSeen(d.max || 0); return; }   // first visit: catch up silently
                (d.items || []).forEach(function (a) { toast(a); setSeen(a.id); });
            })
            .catch(function () { busy = false; });
    }

    // Prime on load, then poll. Also check straight away when the tab regains
    // focus, so someone returning to the app sees it without waiting.
    setTimeout(check, 3000);
    setInterval(check, POLL);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') check();
    });
})();
</script>

<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', {scope: '/'});
}
</script>
<?php
    } // end end()


    // ── LEGACY COMPATIBILITY METHODS (used by server pages) ──

    public static function topbar(array $user, string $title = 'HRI Mail', string $backUrl = ''): void {
            $role     = ROLES[$user['role']] ?? ['label'=>$user['role'],'icon'=>''];
            $initials = strtoupper(substr($user['name'],0,1));
            $pos      = strpos($user['name'],' ');
            if ($pos !== false) $initials .= strtoupper(substr($user['name'],$pos+1,1));
            $logoUrl  = APP_URL . '/hri-logo.png';
            ?>
            <link rel="preconnect" href="https://fonts.googleapis.com"/>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
            <style>
            *{box-sizing:border-box;margin:0;padding:0;}
            body{font-family:'Inter',sans-serif;background:#f1f5f9;color:#0f172a;font-size:14px;}
            .hri-topbar{background:#002850;height:52px;display:flex;align-items:center;padding:0 16px;gap:10px;position:sticky;top:0;z-index:500;box-shadow:0 2px 8px rgba(0,0,0,.2);}
            .hri-logo-wrap{display:flex;align-items:center;gap:9px;text-decoration:none;}
            .hri-logo-img{height:32px;width:auto;}
            .hri-logo-txt{color:#fff;font-weight:700;font-size:13px;letter-spacing:-.01em;}
            .hri-logo-sep{width:1px;height:18px;background:rgba(255,255,255,.2);}
            .hri-page-title{color:rgba(255,255,255,.65);font-size:13px;font-weight:500;}
            .hri-tb-r{margin-left:auto;display:flex;align-items:center;gap:4px;}
            .hri-tib{width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.55);font-size:14px;text-decoration:none;background:transparent;border:none;cursor:pointer;transition:all .15s;font-family:'Inter',sans-serif;}
            .hri-tib:hover{background:rgba(255,255,255,.1);color:#fff;}
            .hri-av{width:30px;height:30px;border-radius:50%;background:#64A014;color:#fff;font-weight:700;font-size:11px;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid rgba(255,255,255,.2);flex-shrink:0;text-decoration:none;}
            .hri-role-badge{padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:700;color:#fff;background:rgba(100,160,20,.35);border:1px solid rgba(100,160,20,.4);}
            </style>
            <div class="hri-topbar">
                <a class="hri-logo-wrap" href="<?= APP_URL ?>/">
                    <img class="hri-logo-img" src="<?= $logoUrl ?>" alt="HR Indexx"/>
                </a>
                <div class="hri-logo-sep"></div>
                <div class="hri-page-title"><?= htmlspecialchars($title) ?></div>
                <?php if ($backUrl): ?>
                <a class="hri-tib" href="<?= htmlspecialchars($backUrl) ?>" title="Back" style="font-size:16px;">&#8592;</a>
                <?php endif; ?>
                <div class="hri-tb-r">
                    <span class="hri-role-badge"><?= $role['icon'] ?> <?= htmlspecialchars($role['label']) ?></span>
                    <a class="hri-tib" href="<?= APP_URL ?>/mail.php" title="Inbox">&#128236;</a>
                    <a class="hri-tib" href="<?= APP_URL ?>/tasks.php" title="Tasks">&#9989;</a>
                    <a class="hri-tib" href="<?= APP_URL ?>/my-stats.php" title="My Stats">&#128202;</a>
                    <?php if ($user['role'] === 'it_admin'): ?>
                    <a class="hri-tib" href="<?= APP_URL ?>/admin/users.php" title="Admin">&#128737;</a>
                    <?php endif; ?>
                    <div style="width:1px;height:18px;background:rgba(255,255,255,.15);margin:0 3px;"></div>
                    <a class="hri-av" href="<?= APP_URL ?>/profile.php" title="<?= htmlspecialchars($user['name']) ?> — Profile"><?= $initials ?></a>
                    <a class="hri-tib" href="<?= APP_URL ?>/logout.php" title="Logout" style="color:rgba(220,100,100,.7);">&#128682;</a>
                </div>
            </div>
            <?php
        }

    public static function footer(): void {
            ?>
            <footer style="text-align:center;padding:18px 16px;font-size:11.5px;color:#94a3b8;border-top:1px solid #e2e8f0;margin-top:auto;">
                &copy; <?= date('Y') ?> HR Indexx Limited &mdash; All Rights Reserved &middot;
                12 Macarthy Street, Onikan, Lagos Island &middot;
                <a href="<?= APP_URL ?>/compliance.php" style="color:#94a3b8;">Compliance</a> &middot;
                <a href="<?= APP_URL ?>/it-request.php" style="color:#94a3b8;">IT Support</a> &middot;
                HRI Mail v1.0
            </footer>
            <?php
        }

    public static function pageStart(array $user, string $title, string $pageTitle = '', string $backUrl = ''): void {
            echo '<!DOCTYPE html><html lang="en"><head>';
            echo '<meta charset="UTF-8"/>';
            echo '<meta name="viewport" content="width=device-width,initial-scale=1.0"/>';
            echo '<title>' . htmlspecialchars($title) . ' — HRI Mail</title>';
            self::topbar($user, $pageTitle ?: $title, $backUrl);
        }

    public static function pageEnd(): void {
            self::footer();
            echo '</body></html>';
        }

    public static function getAnnouncements(): array {
            return self::$announcements;
        }

} // end class Layout
// Flush output buffer if we started one
if (ob_get_level() > 0) ob_end_flush();
