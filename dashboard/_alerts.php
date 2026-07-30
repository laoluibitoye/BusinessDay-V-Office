<?php
/**
 * Band 4 — "Alerts". Shared dashboard widget.
 * Expects: $user (array), $db (PDO).
 *
 * Only things that are going wrong or about to expire. Each row is a link to
 * where it gets fixed. The whole card is hidden when everything is fine — it is
 * never a permanent fixture, so its presence alone means something needs doing.
 *
 * Scoped by role: money and contract alerts go to those who can act on them,
 * failed logins only to the platform admins.
 */
if (!isset($user) || !isset($db)) return;

$__role      = $user['role'] ?? '';
$__isAdmin   = in_array($__role, ['head_it', 'it_admin'], true);
$__isMgmt    = in_array($__role, ['md', 'bdm'], true);
$__isHR      = ($__role === 'hr');
$__isAccts   = in_array($__role, ['head_accounts', 'accountant'], true);
$__seesMoney = $__isAdmin || $__isMgmt || $__isAccts;

$_al = [];   // ['sev'=>'high|med', 'icon'=>, 'text'=>, 'href'=>, 'meta'=>]

try {
    // ── Requests stuck at one stage ───────────────────────────────────────────
    if ($__seesMoney || $__isHR) {
        $__sq = $db->query("SELECT COUNT(*) AS n, MAX(DATEDIFF(NOW(), COALESCE(updated_at, created_at))) AS worst
            FROM irs_requests
            WHERE status NOT IN ('completed','rejected','draft','pending_corrections')
              AND DATEDIFF(NOW(), COALESCE(updated_at, created_at)) >= 5");
        $__s = $__sq->fetch(PDO::FETCH_ASSOC);
        if (!empty($__s['n'])) {
            $_al[] = ['sev'=>'high', 'icon'=>'&#8987;',
                'text'=>$__s['n'] . ' request' . ($__s['n'] > 1 ? 's' : '') . ' stalled 5+ days',
                'meta'=>'oldest ' . (int)$__s['worst'] . ' days at one stage',
                'href'=>'irs-approvals.php'];
        }
    }

    // ── Compliance documents expiring ─────────────────────────────────────────
    if ($__isHR || $__isAdmin || $__isMgmt) {
        $__cq = $db->query("SELECT name, expiry_date, DATEDIFF(expiry_date, CURDATE()) AS d
            FROM compliance_docs
            WHERE status <> 'archived' AND expiry_date IS NOT NULL
              AND expiry_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY expiry_date ASC LIMIT 4");
        foreach ($__cq->fetchAll(PDO::FETCH_ASSOC) as $__c) {
            $__d = (int)$__c['d'];
            $_al[] = ['sev'=>$__d < 0 ? 'high' : 'med', 'icon'=>'&#128203;',
                'text'=>htmlspecialchars($__c['name']) . ($__d < 0 ? ' has EXPIRED' : ' expires in ' . $__d . ' days'),
                'meta'=>date('j M Y', strtotime($__c['expiry_date'])),
                'href'=>'compliance.php'];
        }
    }

    // ── SLA / client contracts expiring ───────────────────────────────────────
    if ($__seesMoney || $__isMgmt) {
        $__lq = $db->query("SELECT name, client, expiry_date, DATEDIFF(expiry_date, CURDATE()) AS d
            FROM sla_tracker
            WHERE status <> 'closed' AND expiry_date IS NOT NULL
              AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY expiry_date ASC LIMIT 4");
        foreach ($__lq->fetchAll(PDO::FETCH_ASSOC) as $__l) {
            $_al[] = ['sev'=>'med', 'icon'=>'&#128196;',
                'text'=>htmlspecialchars($__l['name']) . ' expires in ' . (int)$__l['d'] . ' days',
                'meta'=>$__l['client'] ? htmlspecialchars($__l['client']) : '',
                'href'=>'compliance.php'];
        }
    }

    // ── Subscriptions due for renewal ─────────────────────────────────────────
    if ($__isAdmin || $__seesMoney) {
        $__bq = $db->query("SELECT name, vendor, renewal_date, DATEDIFF(renewal_date, CURDATE()) AS d
            FROM subscriptions
            WHERE status = 'active' AND renewal_date IS NOT NULL
              AND renewal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY renewal_date ASC LIMIT 4");
        foreach ($__bq->fetchAll(PDO::FETCH_ASSOC) as $__b) {
            $_al[] = ['sev'=>'med', 'icon'=>'&#128260;',
                'text'=>htmlspecialchars($__b['name']) . ' renews in ' . (int)$__b['d'] . ' days',
                'meta'=>$__b['vendor'] ? htmlspecialchars($__b['vendor']) : '',
                'href'=>'subscriptions.php'];
        }
    }

    // ── Probation ending — employment_start lives in user_profiles ────────────
    if ($__isHR || $__isMgmt) {
        $__pq = $db->query("SELECT u.name, p.employment_start,
                   DATEDIFF(DATE_ADD(p.employment_start, INTERVAL 6 MONTH), CURDATE()) AS d
            FROM user_profiles p JOIN users u ON u.id = p.user_id
            WHERE u.is_active = 1 AND p.employment_start IS NOT NULL
              AND DATE_ADD(p.employment_start, INTERVAL 6 MONTH)
                  BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY p.employment_start ASC LIMIT 4");
        foreach ($__pq->fetchAll(PDO::FETCH_ASSOC) as $__p) {
            $_al[] = ['sev'=>'med', 'icon'=>'&#127381;',
                'text'=>htmlspecialchars($__p['name']) . ' &mdash; probation ends in ' . (int)$__p['d'] . ' days',
                'meta'=>'started ' . date('j M Y', strtotime($__p['employment_start'])),
                'href'=>'directory.php'];
        }
    }

    // Failed logins are deliberately NOT here — superadmin.php has a dedicated
    // card listing the accounts and IPs, which is more use than a count. Adding
    // it here would show the same thing twice on the only dashboard that sees it.
} catch (Throwable $_e) { return; }

if (empty($_al)) return;   // nothing wrong — render nothing at all

usort($_al, function ($a, $b) { return ($a['sev'] === 'high' ? 0 : 1) <=> ($b['sev'] === 'high' ? 0 : 1); });
$_hi = 0;
foreach ($_al as $__a) if ($__a['sev'] === 'high') $_hi++;
?>
<div class="card" style="margin-bottom:16px;border-left:4px solid <?= $_hi > 0 ? '#dc2626' : '#f59e0b' ?>;">
    <div class="chd">
        <span class="cht">&#9888; Needs Attention
            <span style="background:<?= $_hi > 0 ? '#dc2626' : '#f59e0b' ?>;color:#fff;font-size:10px;padding:2px 8px;border-radius:99px;margin-left:6px;font-weight:700;"><?= count($_al) ?></span>
        </span>
    </div>
    <?php foreach ($_al as $__a):
        $__c = $__a['sev'] === 'high' ? '#dc2626' : '#b45309';
        $__b = $__a['sev'] === 'high' ? '#fef2f2' : '#fffbeb';
    ?>
    <a href="<?= $__a['href'] ?>" style="display:flex;align-items:center;gap:9px;padding:8px 14px;border-bottom:1px solid #f1f5f9;background:<?= $__b ?>;text-decoration:none;">
        <span style="font-size:14px;flex-shrink:0;"><?= $__a['icon'] ?></span>
        <span style="flex:1;min-width:0;font-size:12.5px;font-weight:600;color:<?= $__c ?>;"><?= $__a['text'] ?></span>
        <?php if (!empty($__a['meta'])): ?>
        <span style="font-size:11px;color:#94a3b8;white-space:nowrap;flex-shrink:0;"><?= $__a['meta'] ?></span>
        <?php endif; ?>
        <span style="font-size:11px;color:<?= $__c ?>;flex-shrink:0;">&#8594;</span>
    </a>
    <?php endforeach; ?>
</div>
