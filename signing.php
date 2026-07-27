<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// signing.php — HRI Digital Signing Dashboard
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

$success = '';
$error   = '';

// ── CREATE SIGNING REQUEST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_request'])) {
    $title    = trim($_POST['title'] ?? '');
    $docType  = $_POST['doc_type'] ?? 'custom';
    $message  = trim($_POST['message'] ?? '');
    $expires  = !empty($_POST['expires_days'])
                ? date('Y-m-d H:i:s', strtotime('+' . (int)$_POST['expires_days'] . ' days'))
                : date('Y-m-d H:i:s', strtotime('+72 hours'));

    $signatories = $_POST['signatories'] ?? [];
    $extEmails   = $_POST['ext_emails']  ?? [];
    $extNames    = $_POST['ext_names']   ?? [];

    if (!$title) {
        $error = 'Document title is required.';
    } elseif (empty($signatories) && empty(array_filter($extEmails))) {
        $error = 'Add at least one signatory.';
    } else {
        // Handle uploaded document
        $docPath = null;
        if (!empty($_FILES['document']['name'])) {
            $uploadDir = __DIR__ . '/uploads/signing/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext      = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowedDocExts  = ['pdf','doc','docx'];
            $allowedDocMimes = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $detectedDocMime = mime_content_type($_FILES['document']['tmp_name']) ?: '';
            if (!in_array($ext, $allowedDocExts) || !in_array($detectedDocMime, $allowedDocMimes)) {
                $error = 'Only PDF, DOC, and DOCX files are accepted for signing documents.';
            } else {
            $safeName = uniqid('doc_') . '.' . $ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadDir . $safeName)) {
                $docPath = 'uploads/signing/' . $safeName;
            }
            } // end doc type check
        }

        // Insert signing request
        $db->prepare("INSERT INTO sign_requests (title, doc_type, doc_path, created_by, message, expires_at)
                      VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$title, $docType, $docPath, $user['id'], $message, $expires]);
        $requestId = $db->lastInsertId();

        $order = 1;

        // Internal staff signatories
        foreach ($signatories as $sigId) {
            $sigId = (int)$sigId;
            if (!$sigId) continue;
            $token = bin2hex(random_bytes(24));
            $db->prepare("INSERT INTO sign_signatories (request_id, user_id, token, order_num)
                          VALUES (?, ?, ?, ?)")
               ->execute([$requestId, $sigId, $token, $order++]);
            sendSigningInvite($sigId, null, null, $token, $title, $message, $db);
        }

        // External signatories
        foreach ($extEmails as $i => $extEmail) {
            $extEmail = trim($extEmail);
            $extName  = trim($extNames[$i] ?? '');
            if (!$extEmail || !filter_var($extEmail, FILTER_VALIDATE_EMAIL)) continue;
            $token = bin2hex(random_bytes(24));
            $db->prepare("INSERT INTO sign_signatories (request_id, user_id, external_email, external_name, token, order_num)
                          VALUES (?, NULL, ?, ?, ?, ?)")
               ->execute([$requestId, $extEmail, $extName, $token, $order++]);
            sendSigningInviteExternal($extEmail, $extName, $token, $title, $message);
        }

        Auth::auditLog($user['id'], 'sign_request_created', "Created signing request: $title");
        Auth::trackUsage($user['id'], 'docs_signed', 0);
        $success = "Signing request \"$title\" created and invitations sent.";
    }
}

function sendSigningInvite($userId, $extEmail, $extName, $token, $title, $message, $db) {
    $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) return;
    sendSigningInviteExternal($u['email'], $u['name'], $token, $title, $message);
}

function sendSigningInviteExternal($email, $name, $token, $title, $message) {
    $url     = APP_URL . '/sign.php?token=' . $token;
    $body    = "Dear $name,\n\n"
             . "You have been requested to sign a document: \"$title\"\n\n"
             . ($message ? "Message from sender:\n$message\n\n" : "")
             . "To review and sign the document, click the link below:\n$url\n\n"
             . "This link will expire in 72 hours. Please sign at your earliest convenience.\n\n"
             . "HR Indexx Limited · 12 Macarthy Street, Onikan, Lagos Island";
    $headers = "From: HRI Mail <noreply@hrindexx.com>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($email, "Document Signing Request — $title", $body, $headers);
}

// ── FETCH DATA ──────────────────────────────────────────────
// Requests I created
$myRequests = $db->prepare("
    SELECT sr.*,
           COUNT(ss.id) as total_signatories,
           SUM(CASE WHEN ss.status='signed' THEN 1 ELSE 0 END) as signed_count
    FROM sign_requests sr
    LEFT JOIN sign_signatories ss ON ss.request_id = sr.id
    WHERE sr.created_by = ?
    GROUP BY sr.id
    ORDER BY sr.created_at DESC
");
$myRequests->execute([$user['id']]);
$myReqs = $myRequests->fetchAll();

// Documents pending MY signature
$pendingMine = $db->prepare("
    SELECT ss.*, sr.title, sr.doc_type, sr.message, sr.expires_at, sr.created_at as req_created,
           u.name as created_by_name
    FROM sign_signatories ss
    JOIN sign_requests sr ON sr.id = ss.request_id
    JOIN users u ON u.id = sr.created_by
    WHERE ss.user_id = ? AND ss.status = 'pending' AND sr.status != 'expired'
    ORDER BY sr.created_at DESC
");
$pendingMine->execute([$user['id']]);
$pendingSigns = $pendingMine->fetchAll();

// Recently signed by me
$signedByMe = $db->prepare("
    SELECT ss.*, sr.title, sr.doc_type, u.name as created_by_name
    FROM sign_signatories ss
    JOIN sign_requests sr ON sr.id = ss.request_id
    JOIN users u ON u.id = sr.created_by
    WHERE ss.user_id = ? AND ss.status = 'signed'
    ORDER BY ss.signed_at DESC LIMIT 10
");
$signedByMe->execute([$user['id']]);
$recentSigned = $signedByMe->fetchAll();

// All staff for signatory picker
$allStaff = $db->query("SELECT id, name, email, role FROM users WHERE is_active=1 ORDER BY name")->fetchAll();

$docTypes = [
    'offer_letter'          => ['📄', 'Offer Letter'],
    'policy_acknowledgement'=> ['📋', 'Policy Acknowledgement'],
    'privacy_policy'        => ['🛡️', 'Privacy Policy Sign-off'],
    'sla'                   => ['📊', 'SLA / Agreement'],
    'contractor'            => ['🤝', 'Contractor Agreement'],
    'data_breach'           => ['🚨', 'Data Breach Acknowledgement'],
    'custom'                => ['📝', 'Custom Document'],
];

Layout::shell($user, 'signing', 0, 'Digital Signing');
?>
<style>
.page{max-width:1040px;margin:0 auto;padding:24px 16px 40px;overflow-y:auto;height:100%;}
.pgt{font-size:18px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.pgs{font-size:12.5px;color:var(--g400);margin-bottom:20px;}
.grid2{display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;}
/* ALERT */
.alert{padding:11px 16px;border-radius:9px;font-size:13px;display:flex;gap:8px;margin-bottom:16px;align-items:flex-start;}
.alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
/* CARD */
.card{background:var(--w);border-radius:13px;box-shadow:var(--sh);overflow:hidden;margin-bottom:16px;}
.chd{padding:13px 18px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;}
.chd-title{font-size:13.5px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:7px;}
.cbd{padding:18px;}
/* FORM */
.fg{margin-bottom:14px;}
.fg.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
input,select,textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:9px 12px;font-size:13.5px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);transition:border-color .15s;}
input:focus,select:focus,textarea:focus{border-color:var(--green);background:var(--w);}
textarea{resize:none;height:70px;}
.file-zone{border:2px dashed var(--g200);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:all .15s;background:var(--g50);}
.file-zone:hover{border-color:var(--green);background:var(--gl);}
.file-zone-ico{font-size:28px;margin-bottom:7px;}
.file-zone-txt{font-size:13px;color:var(--g500);}
.file-zone-sub{font-size:11.5px;color:var(--g400);margin-top:3px;}
/* Signatory picker */
.sig-list{display:flex;flex-direction:column;gap:5px;margin-top:8px;max-height:200px;overflow-y:auto;}
.sig-item{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:7px;background:var(--g50);cursor:pointer;transition:background .12s;}
.sig-item:hover{background:var(--nl);}
.sig-item input[type=checkbox]{width:auto;flex-shrink:0;}
.sig-av{width:26px;height:26px;border-radius:50%;background:var(--navy);color:#fff;font-size:9.5px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sig-name{font-size:12.5px;font-weight:500;color:var(--g900);flex:1;}
.sig-role{font-size:10.5px;color:var(--g400);}
/* Ext signatory */
.ext-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:7px;align-items:center;}
.ext-remove{width:28px;height:28px;border-radius:6px;background:var(--g100);border:none;color:var(--red);cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
/* SIGN REQUEST ITEMS */
.req-item{padding:14px 18px;border-bottom:1px solid var(--g50);display:flex;align-items:center;gap:12px;transition:background .1s;}
.req-item:last-child{border-bottom:none;}
.req-item:hover{background:var(--g50);}
.req-ico{font-size:22px;flex-shrink:0;}
.req-info{flex:1;}
.req-title{font-size:13.5px;font-weight:700;color:var(--g900);}
.req-meta{font-size:12px;color:var(--g500);margin-top:2px;}
.req-prog{margin-top:6px;display:flex;align-items:center;gap:8px;}
.prog-bar{flex:1;height:5px;background:var(--g100);border-radius:99px;overflow:hidden;}
.prog-fill{height:100%;border-radius:99px;background:var(--green);transition:width .3s;}
.prog-txt{font-size:11px;color:var(--g500);white-space:nowrap;}
/* Pending sign items */
.psign-item{padding:14px 18px;border-bottom:1px solid var(--g50);display:flex;align-items:center;gap:12px;}
.psign-item:last-child{border-bottom:none;}
.psign-info{flex:1;}
.psign-title{font-size:13.5px;font-weight:700;color:var(--g900);}
.psign-meta{font-size:12px;color:var(--g500);margin-top:2px;}
.psign-exp{font-size:11.5px;font-weight:600;}
/* Pills */
.pill{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.pill.completed{background:#dcfce7;color:#166534;}
.pill.partial{background:#fef3c7;color:#92400e;}
.pill.pending{background:var(--g100);color:var(--g500);}
.pill.expired{background:#fee2e2;color:var(--red);}
/* Btn */
.btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:all .15s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;}
.btn.gn{background:var(--green);color:#fff;}
.btn.gn:hover{background:var(--gd);}
.btn.pr{background:var(--navy);color:#fff;}
.btn.pr:hover{background:#001635;}
.btn.ol{background:transparent;border:1.5px solid var(--g200);color:var(--g700);}
.btn.ol:hover{border-color:var(--navy);color:var(--navy);}
.btn.sm{padding:5px 12px;font-size:12px;}
.empty{padding:24px;text-align:center;color:var(--g400);font-size:13px;}
/* Search */
.sig-search{margin-bottom:7px;}
::-webkit-scrollbar{width:4px;}::-webkit-scrollbar-thumb{background:var(--g200);border-radius:99px;}
@media(max-width:640px){.grid2{grid-template-columns:1fr;}.fg.row2{grid-template-columns:1fr;}.ext-row{grid-template-columns:1fr;}.page{padding:0 12px 40px;}}
</style>
<div class="page">
    <div class="pgt">&#9997; Digital Signing</div>
    <div class="pgs">Create, send and track document signing requests</div>

    <?php if ($success): ?>
    <div class="alert ok">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert er">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid2">
        <!-- LEFT -->
        <div>
            <!-- PENDING MY SIGNATURE -->
            <?php if (!empty($pendingSigns)): ?>
            <div class="card" style="border-top:3px solid var(--red);">
                <div class="chd">
                    <div class="chd-title">🔴 Documents Awaiting My Signature
                        <span style="background:var(--red);color:#fff;font-size:10px;padding:2px 7px;border-radius:20px;"><?= count($pendingSigns) ?></span>
                    </div>
                </div>
                <?php foreach ($pendingSigns as $ps):
                    $expDate = strtotime($ps['expires_at']);
                    $expDays = round(($expDate - time()) / 86400);
                    $expColor= $expDays < 1 ? 'var(--red)' : ($expDays < 2 ? 'var(--warn)' : 'var(--green)');
                    [$dtIco,$dtLabel] = $docTypes[$ps['doc_type']] ?? ['📝','Document'];
                ?>
                <div class="psign-item">
                    <div style="font-size:24px;"><?= $dtIco ?></div>
                    <div class="psign-info">
                        <div class="psign-title"><?= htmlspecialchars($ps['title']) ?></div>
                        <div class="psign-meta">From: <?= htmlspecialchars($ps['created_by_name']) ?> · <?= $dtLabel ?></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;margin-right:8px;">
                        <div class="psign-exp" style="color:<?= $expColor ?>">
                            <?= $expDays < 1 ? 'Expires today' : "Expires in {$expDays}d" ?>
                        </div>
                    </div>
                    <a class="btn gn sm" href="sign.php?token=<?= $ps['token'] ?>">✍️ Sign Now</a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- MY REQUESTS -->
            <div class="card">
                <div class="chd">
                    <div class="chd-title">📋 My Signing Requests</div>
                    <button class="btn gn sm" onclick="openModal('newReqModal')">+ New Request</button>
                </div>
                <?php if (empty($myReqs)): ?>
                <div class="empty">No signing requests created yet</div>
                <?php else: ?>
                <?php foreach ($myReqs as $req):
                    $pct = $req['total_signatories'] > 0
                         ? round(($req['signed_count'] / $req['total_signatories']) * 100)
                         : 0;
                    $statusClass = $req['status'] === 'completed' ? 'completed'
                                 : ($req['status'] === 'expired'   ? 'expired'
                                 : ($req['signed_count'] > 0       ? 'partial' : 'pending'));
                    [$dtIco,$dtLabel] = $docTypes[$req['doc_type']] ?? ['📝','Document'];
                ?>
                <div class="req-item">
                    <div class="req-ico"><?= $dtIco ?></div>
                    <div class="req-info">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="req-title"><?= htmlspecialchars($req['title']) ?></div>
                            <span class="pill <?= $statusClass ?>">
                                <?= $req['status'] === 'completed' ? '✅ Complete'
                                  : ($req['status'] === 'expired' ? '⏰ Expired'
                                  : ($req['signed_count'] > 0 ? '⏳ Partial' : '⏳ Pending')) ?>
                            </span>
                        </div>
                        <div class="req-meta">
                            <?= $dtLabel ?> · Created <?= date('d M Y', strtotime($req['created_at'])) ?>
                            · Expires <?= date('d M Y', strtotime($req['expires_at'])) ?>
                        </div>
                        <div class="req-prog">
                            <div class="prog-bar">
                                <div class="prog-fill" style="width:<?= $pct ?>%"></div>
                            </div>
                            <div class="prog-txt"><?= $req['signed_count'] ?>/<?= $req['total_signatories'] ?> signed</div>
                        </div>
                    </div>
                    <a class="btn ol sm" href="signing-detail.php?id=<?= $req['id'] ?>">View →</a>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- RECENTLY SIGNED -->
            <?php if (!empty($recentSigned)): ?>
            <div class="card">
                <div class="chd"><div class="chd-title">✅ Recently Signed by Me</div></div>
                <?php foreach ($recentSigned as $rs):
                    [$dtIco] = $docTypes[$rs['doc_type']] ?? ['📝'];
                ?>
                <div class="req-item">
                    <div class="req-ico"><?= $dtIco ?></div>
                    <div class="req-info">
                        <div class="req-title"><?= htmlspecialchars($rs['title']) ?></div>
                        <div class="req-meta">
                            From: <?= htmlspecialchars($rs['created_by_name']) ?>
                            · Signed <?= date('d M Y H:i', strtotime($rs['signed_at'])) ?>
                        </div>
                    </div>
                    <span class="pill completed">✅ Signed</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: NEW REQUEST FORM (also in modal) -->
        <div>
            <div class="card" style="border-top:3px solid var(--green);">
                <div class="chd"><div class="chd-title">✍️ New Signing Request</div></div>
                <div class="cbd">
                    <form method="POST" enctype="multipart/form-data" id="newReqForm">
                        <?= Auth::csrfField() ?>
                        <div class="fg">
                            <label>Document Title *</label>
                            <input type="text" name="title" placeholder="e.g. Offer Letter — Olanrewaju Oloritun" required/>
                        </div>
                        <div class="fg">
                            <label>Document Type</label>
                            <select name="doc_type">
                                <?php foreach ($docTypes as $k=>[$ico,$lbl]): ?>
                                <option value="<?= $k ?>"><?= $ico ?> <?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fg">
                            <label>Upload Document (optional — PDF or DOCX)</label>
                            <div class="file-zone" onclick="document.getElementById('docFile').click()">
                                <div class="file-zone-ico">📎</div>
                                <div class="file-zone-txt" id="fileZoneTxt">Click to upload document</div>
                                <div class="file-zone-sub">PDF or DOCX · max 20MB</div>
                            </div>
                            <input type="file" id="docFile" name="document" accept=".pdf,.docx"
                                   style="display:none" onchange="showFileName(this)"/>
                        </div>
                        <div class="fg">
                            <label>Message to Signatories (optional)</label>
                            <textarea name="message" placeholder="Any instructions or context…"></textarea>
                        </div>
                        <div class="fg">
                            <label>Expires After</label>
                            <select name="expires_days">
                                <option value="1">24 hours</option>
                                <option value="3" selected>72 hours (3 days)</option>
                                <option value="7">7 days</option>
                                <option value="14">14 days</option>
                                <option value="30">30 days</option>
                            </select>
                        </div>

                        <!-- INTERNAL SIGNATORIES -->
                        <div class="fg">
                            <label>Internal Staff Signatories</label>
                            <input class="sig-search" type="text" placeholder="Search staff…"
                                   oninput="filterSig(this.value)" style="margin-bottom:7px;"/>
                            <div class="sig-list" id="sigList">
                                <?php foreach ($allStaff as $s):
                                    if ($s['id'] == $user['id']) continue;
                                    $initials = strtoupper(substr($s['name'],0,2));
                                    $roleLabel = ROLES[$s['role']]['label'] ?? $s['role'];
                                ?>
                                <label class="sig-item" data-name="<?= strtolower($s['name']) ?>">
                                    <input type="checkbox" name="signatories[]" value="<?= $s['id'] ?>"/>
                                    <div class="sig-av"><?= $initials ?></div>
                                    <div class="sig-name"><?= htmlspecialchars($s['name']) ?></div>
                                    <div class="sig-role"><?= $roleLabel ?></div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- EXTERNAL SIGNATORIES -->
                        <div class="fg">
                            <label>External Signatories (clients, candidates etc)</label>
                            <div id="extList">
                                <div class="ext-row" id="ext0">
                                    <input type="text" name="ext_names[]" placeholder="Full name"/>
                                    <input type="email" name="ext_emails[]" placeholder="Email address"/>
                                    <button type="button" class="ext-remove" onclick="this.parentElement.remove()" title="Remove">×</button>
                                </div>
                            </div>
                            <button type="button" onclick="addExt()"
                                    style="margin-top:7px;padding:5px 11px;border-radius:6px;background:var(--g100);border:1px solid var(--g200);color:var(--g700);font-size:12px;cursor:pointer;font-family:'Inter',sans-serif;">
                                + Add Another External
                            </button>
                        </div>

                        <button type="submit" name="create_request" class="btn gn" style="width:100%;justify-content:center;">
                            ✍️ Create & Send Signing Request
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let extCount = 1;
function addExt() {
    const list = document.getElementById('extList');
    const row  = document.createElement('div');
    row.className = 'ext-row';
    row.id = 'ext' + extCount;
    row.innerHTML = `
        <input type="text" name="ext_names[]" placeholder="Full name"/>
        <input type="email" name="ext_emails[]" placeholder="Email address"/>
        <button type="button" class="ext-remove" onclick="this.parentElement.remove()">×</button>`;
    list.appendChild(row);
    extCount++;
}

function filterSig(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#sigList .sig-item').forEach(item => {
        item.style.display = item.dataset.name.includes(q) ? '' : 'none';
    });
}

function showFileName(inp) {
    if (inp.files[0]) {
        document.getElementById('fileZoneTxt').textContent = '📄 ' + inp.files[0].name;
    }
}
</script>
<?php Layout::end(); ?>
