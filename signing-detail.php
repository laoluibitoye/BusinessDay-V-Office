<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// signing-detail.php — View detail of a signing request
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);

// Load request — creator, super-admin, or a signatory on this request can view
$isSuperAdmin = in_array($user['role'], ['head_it','it_admin','md','bdm']) ? 1 : 0;
$reqStmt = $db->prepare("SELECT sr.*, u.name as creator_name
                          FROM sign_requests sr JOIN users u ON u.id = sr.created_by
                          WHERE sr.id = ?
                          AND (sr.created_by = ? OR 1 = ?
                               OR EXISTS (SELECT 1 FROM sign_signatories ss WHERE ss.request_id=sr.id AND ss.user_id=?))");
$reqStmt->execute([$id, $user['id'], $isSuperAdmin, $user['id']]);
$req = $reqStmt->fetch();

if (!$req) {
    header('Location: signing.php');
    exit;
}

// Load signatories
$sigsStmt = $db->prepare("
    SELECT ss.*, u.name as user_name, u.email as user_email
    FROM sign_signatories ss
    LEFT JOIN users u ON u.id = ss.user_id
    WHERE ss.request_id = ?
    ORDER BY ss.order_num ASC
");
$sigsStmt->execute([$id]);
$signatories = $sigsStmt->fetchAll();

$docTypes = [
    'offer_letter'          => ['&#128196;','Offer Letter'],
    'policy_acknowledgement'=> ['&#128203;','Policy Acknowledgement'],
    'privacy_policy'        => ['&#128737;&#65039;','Privacy Policy'],
    'sla'                   => ['&#128202;','SLA / Agreement'],
    'contractor'            => ['&#129309;','Contractor Agreement'],
    'data_breach'           => ['&#128680;','Data Breach Acknowledgement'],
    'custom'                => ['&#128221;','Document'],
];
[$dtIco,$dtLabel] = $docTypes[$req['doc_type']] ?? ['&#128221;','Document'];
$totalSigs  = count($signatories);
$signedSigs = count(array_filter($signatories, function($s){ return $s['status'] === 'signed'; }));
$pct        = $totalSigs > 0 ? round(($signedSigs/$totalSigs)*100) : 0;

Layout::shell($user, 'signing', 0, 'Signing Detail');
?>
<style>
.sdpage{width:100%;max-width:1600px;margin:0 auto;padding:24px 16px 40px;overflow-y:auto;height:100%;}
.sdcard{background:var(--w);border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:16px;}
.sdchd{padding:16px 20px;border-bottom:1px solid var(--g100);display:flex;align-items:flex-start;gap:12px;}
.sdchd-ico{font-size:26px;flex-shrink:0;}
.sdchd-title{font-size:15px;font-weight:700;color:var(--navy);}
.sdchd-sub{font-size:12px;color:var(--g400);margin-top:2px;}
.sdcbd{padding:18px 20px;}
.prog-wrap{margin-bottom:18px;}
.prog-bar{height:8px;background:var(--g100);border-radius:99px;overflow:hidden;margin-bottom:6px;}
.prog-fill{height:100%;border-radius:99px;background:var(--green);transition:width .4s;}
.prog-meta{display:flex;justify-content:space-between;font-size:12px;color:var(--g500);}
.sig-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--g50);}
.sig-row:last-child{border-bottom:none;}
.sav{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;flex-shrink:0;}
.sinfo{flex:1;}
.sname{font-size:13.5px;font-weight:600;color:var(--g900);}
.semail{font-size:12px;color:var(--g400);margin-top:1px;}
.sdate{font-size:11.5px;color:var(--g500);margin-top:2px;}
.sdpill{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.sdpill.signed{background:#dcfce7;color:#166534;}
.sdpill.pending{background:#fef3c7;color:#92400e;}
.sdpill.declined{background:#fee2e2;color:var(--red);}
.sig-preview{width:80px;height:36px;border:1px solid var(--g200);border-radius:6px;object-fit:contain;background:var(--g50);}
.resend-btn{padding:5px 11px;border-radius:6px;background:var(--g100);border:1px solid var(--g200);color:var(--g700);font-size:11.5px;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
.resend-btn:hover{border-color:var(--navy);color:var(--navy);}
.detail-item{padding:9px 0;border-bottom:1px solid var(--g50);display:flex;align-items:flex-start;justify-content:space-between;}
.detail-item:last-child{border-bottom:none;}
.dl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--g400);}
.dv{font-size:13px;color:var(--g700);text-align:right;}
</style>
<div class="sdpage">
    <div class="sdcard">
        <div class="sdchd">
            <div class="sdchd-ico"><?= $dtIco ?></div>
            <div style="flex:1;">
                <div class="sdchd-title"><?= htmlspecialchars($req['title']) ?></div>
                <div class="sdchd-sub"><?= $dtLabel ?> &middot; Created by <?= htmlspecialchars($req['creator_name']) ?> &middot; <?= date('d M Y', strtotime($req['created_at'])) ?></div>
            </div>
            <?php
            $statusClass = $req['status'] === 'completed' ? 'signed'
                         : ($req['status'] === 'expired'  ? 'declined' : 'pending');
            $statusLabel = ucfirst($req['status']);
            ?>
            <span class="sdpill <?= $statusClass ?>"><?= $statusLabel ?></span>
        </div>
        <div class="sdcbd">
            <div class="prog-wrap">
                <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
                <div class="prog-meta">
                    <span><?= $signedSigs ?> of <?= $totalSigs ?> signed</span>
                    <span><?= $pct ?>% complete</span>
                </div>
            </div>
            <div class="detail-item"><div class="dl">Expires</div><div class="dv"><?= date('d M Y H:i', strtotime($req['expires_at'])) ?></div></div>
            <?php if ($req['message']): ?>
            <div class="detail-item"><div class="dl">Message</div><div class="dv" style="text-align:right;max-width:300px;"><?= htmlspecialchars($req['message']) ?></div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="sdcard">
        <div class="sdchd" style="border-bottom:1px solid var(--g100);">
            <div style="font-size:13.5px;font-weight:700;color:var(--navy);">&#9997;&#65039; Signatories</div>
        </div>
        <div class="sdcbd">
            <?php
            $canRemind = ($user['id'] === (int)$req['created_by'] || $isSuperAdmin);
            foreach ($signatories as $s):
                $name    = $s['user_name'] ?? $s['external_name'] ?? 'Unknown';
                $email   = $s['user_email'] ?? $s['external_email'] ?? '';
                $initials= strtoupper(substr($name,0,2));
                $avColor = $s['status']==='signed' ? 'var(--green)' : ($s['status']==='declined' ? 'var(--red)' : 'var(--g400)');
            ?>
            <div class="sig-row">
                <div class="sav" style="background:<?= $avColor ?>"><?= $initials ?></div>
                <div class="sinfo">
                    <div class="sname"><?= htmlspecialchars($name) ?></div>
                    <div class="semail"><?= htmlspecialchars($email) ?></div>
                    <?php if ($s['signed_at']): ?>
                    <div class="sdate">&#9989; Signed <?= date('d M Y H:i', strtotime($s['signed_at'])) ?><?php if (Auth::isAdmin($user)): ?> &middot; IP: <?= htmlspecialchars($s['ip_address'] ?? '') ?><?php endif; ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($s['signature_data'] && str_starts_with($s['signature_data'], 'data:image')): ?>
                <img class="sig-preview" src="<?= htmlspecialchars($s['signature_data']) ?>" alt="Signature"/>
                <?php endif; ?>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                    <span class="sdpill <?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span>
                    <?php if ($s['status'] === 'pending' && $canRemind): ?>
                    <button class="resend-btn" onclick="sendReminder(<?= (int)$s['id'] ?>,this)">&#128231; Remind</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
function sendReminder(signatoryId, btn) {
    btn.disabled = true;
    btn.textContent = 'Sending…';
    var fd = new FormData();
    fd.append('signatory_id', signatoryId);
    fetch('api/signing/remind.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'X-CSRF-Token': window.CSRF_TOKEN},
        body: fd
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.ok) { btn.textContent = '✓ Sent'; }
        else { btn.disabled = false; btn.textContent = '⚠ Error'; alert(d.error || 'Could not send reminder.'); }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = '⚠ Error'; });
}
</script>
<?php Layout::end(); ?>
