<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

$db    = getDB();
$token = trim($_GET['token'] ?? '');
if (!$token) die('<div style="font-family:Inter,sans-serif;padding:40px;text-align:center;color:#dc2626;">Invalid signing link.</div>');

$sigStmt = $db->prepare("SELECT ss.*, sr.title, sr.doc_type, sr.message, sr.expires_at, sr.doc_path, sr.created_by, sr.id as request_id, sr.status as req_status, u.name as created_by_name, u.email as created_by_email, su.name as signer_name, su.email as signer_email FROM sign_signatories ss JOIN sign_requests sr ON sr.id = ss.request_id JOIN users u ON u.id = sr.created_by LEFT JOIN users su ON su.id = ss.user_id WHERE ss.token = ?");
$sigStmt->execute([$token]);
$sig = $sigStmt->fetch();
if (!$sig) die('<div style="font-family:Inter,sans-serif;padding:40px;text-align:center;">This signing link is invalid.</div>');

if (strtotime($sig['expires_at']) < time()) {
    $db->prepare("UPDATE sign_requests SET status='expired' WHERE id=?")->execute([$sig['request_id']]);
    die('<div style="font-family:Inter,sans-serif;padding:40px;text-align:center;color:#dc2626;">This signing link has expired.</div>');
}

$alreadySigned = $sig['status'] === 'signed';
$signerName    = $sig['signer_name']  ?? $sig['external_name']  ?? 'Signatory';
$signerEmail   = $sig['signer_email'] ?? $sig['external_email'] ?? '';
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadySigned) {
    $signatureData = $_POST['signature_data'] ?? '';
    $signerNameInp = trim($_POST['signer_name'] ?? $signerName);
    $agreed        = isset($_POST['agreed']);
    $signMethod    = $_POST['sign_method'] ?? 'draw';

    if ($signMethod === 'upload' && !empty($_FILES['sig_upload']['tmp_name'])) {
        $imgData = file_get_contents($_FILES['sig_upload']['tmp_name']);
        $mime    = $_FILES['sig_upload']['type'] ?? 'image/png';
        $signatureData = 'data:' . $mime . ';base64,' . base64_encode($imgData);
    }

    if (!$signatureData || $signatureData === 'data:,') {
        $error = 'Please provide your signature.';
    } elseif (!$agreed) {
        $error = 'Please confirm your agreement.';
    } elseif (!$signerNameInp) {
        $error = 'Please enter your full name.';
    } else {
        $ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $signedAtH = date('d M Y H:i:s T');

        // Remove white background from uploaded signature
        if ($signMethod === 'upload' && extension_loaded('gd')) {
            $signatureData = removeBg($signatureData);
        }

        $db->prepare("UPDATE sign_signatories SET status='signed', signature_data=?, signed_at=NOW(), ip_address=? WHERE token=?")
           ->execute([$signatureData, $ip, $token]);

        // Generate signing certificate HTML
        $certPath = generateCert($sig, $signerNameInp, $signerEmail, $signatureData, $ip, $signedAtH, $db);

        // Save certificate to vault for internal user
        if ($sig['user_id'] && $certPath) {
            $relPath = 'uploads/signing/certs/' . basename($certPath);
            $db->prepare("INSERT INTO vault_files (user_id, filename, stored_name, file_path, category, source, mime_type) VALUES (?, ?, ?, ?, 'signed', 'signed_doc', 'text/html')")
               ->execute([$sig['user_id'], $sig['title'] . ' — Signing Certificate.html', basename($certPath), $relPath]);
        }

        $pendingStmt = $db->prepare("SELECT COUNT(*) FROM sign_signatories WHERE request_id=? AND status='pending'");
        $pendingStmt->execute([$sig['request_id']]);
        $remaining = $pendingStmt->fetchColumn();

        if ($remaining == 0) {
            $db->prepare("UPDATE sign_requests SET status='completed' WHERE id=?")->execute([$sig['request_id']]);
            // Save to creator vault too
            if ($certPath) {
                $relPath = 'uploads/signing/certs/' . basename($certPath);
                try {
                    $db->prepare("INSERT INTO vault_files (user_id, filename, stored_name, file_path, category, source, mime_type) VALUES (?, ?, ?, ?, 'signed', 'signed_doc', 'text/html')")
                       ->execute([$sig['created_by'], $sig['title'] . ' — Fully Signed.html', basename($certPath), $relPath]);
                } catch(Exception $e) {}
            }
            $body = "Dear {$sig['created_by_name']},\n\nAll parties have signed: \"{$sig['title']}\"\n\nCompleted: " . date('d M Y H:i') . "\n\nThe certificate is saved in your Document Vault:\n" . APP_URL . "/vault.php?cat=signed\n\nHR Indexx Limited";
            @mail($sig['created_by_email'], "All Signed — {$sig['title']}", $body, "From: HRI Mail <noreply@hrindexx.com>\r\n");
        } else {
            $db->prepare("UPDATE sign_requests SET status='partial' WHERE id=? AND status='pending'")->execute([$sig['request_id']]);
        }

        require_once __DIR__ . '/lib/Auth.php';
        Auth::auditLog($sig['user_id'], 'document_signed', "Signed: {$sig['title']}");
        if ($sig['user_id']) Auth::trackUsage($sig['user_id'], 'docs_signed');

        $alreadySigned = true;
        $success = 'Document signed. Certificate saved to your Document Vault.';

        if ($signerEmail) {
            $body = "Dear $signerNameInp,\n\nYou have signed: \"{$sig['title']}\"\nTime: $signedAtH\nIP: $ip\n\nYour signing certificate is in your Document Vault.\n\nHR Indexx Limited";
            @mail($signerEmail, "Signed — {$sig['title']}", $body, "From: HRI Mail <noreply@hrindexx.com>\r\n");
        }
    }
}

function removeBg($dataUrl) {
    try {
        $b64  = preg_replace('/^data:image\/\w+;base64,/', '', $dataUrl);
        $img  = imagecreatefromstring(base64_decode($b64));
        if (!$img) return $dataUrl;
        $w = imagesx($img); $h = imagesy($img);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false); imagesavealpha($out, true);
        $trans = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefill($out, 0, 0, $trans);
        imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
        for ($x=0;$x<$w;$x++) for ($y=0;$y<$h;$y++) {
            $c = imagecolorat($out,$x,$y);
            $r = ($c>>16)&0xFF; $g = ($c>>8)&0xFF; $b = $c&0xFF;
            if ($r>220 && $g>220 && $b>220) imagesetpixel($out,$x,$y,$trans);
        }
        ob_start(); imagepng($out); $data=ob_get_clean();
        imagedestroy($img); imagedestroy($out);
        return 'data:image/png;base64,' . base64_encode($data);
    } catch(Exception $e) { return $dataUrl; }
}

function generateCert($sig, $signerName, $signerEmail, $signatureData, $ip, $signedAt, $db) {
    $dir = __DIR__ . '/uploads/signing/certs/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . 'cert_' . $sig['request_id'] . '_' . $sig['id'] . '.html';
    $allSigsStmt = $db->prepare("SELECT ss.*, u.name as uname FROM sign_signatories ss LEFT JOIN users u ON u.id=ss.user_id WHERE ss.request_id=?");
    $allSigsStmt->execute([$sig['request_id']]);
    $allSigs = $allSigsStmt->fetchAll();
    $verifyHash = strtoupper(substr(hash('sha256', $sig['title'].$signerName.$signedAt.$ip), 0, 32));
    $docHash    = strtoupper(hash('sha256', $sig['title'].$sig['request_id'].$sig['created_by']));
    $sigImgHtml = $signatureData && str_starts_with($signatureData,'data:image')
        ? '<img src="' . htmlspecialchars($signatureData) . '" style="max-width:300px;max-height:100px;object-fit:contain;" alt="Signature"/>'
        : '';
    $sigsHtml = '';
    foreach ($allSigs as $s) {
        $sn = htmlspecialchars($s['uname'] ?? $s['external_name'] ?? 'Unknown');
        $st = $s['status']==='signed' ? '✅ Signed '.($s['signed_at']?date('d M Y H:i',strtotime($s['signed_at'])):'') : '⏳ Pending';
        $sigsHtml .= "<tr><td>$sn</td><td>$st</td></tr>";
    }
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"/><title>Signing Certificate</title>
<style>body{font-family:Georgia,serif;background:#f5f5f0;margin:0;padding:30px;}
.cert{width:100%;background:#fff;max-width:1600px;margin:0 auto;padding:50px;border:2px solid #002850;}
.hdr{text-align:center;border-bottom:3px solid #002850;padding-bottom:20px;margin-bottom:28px;}
.logo{display:inline-flex;align-items:center;gap:12px;margin-bottom:10px;}
.lbox{width:48px;height:48px;background:#002850;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:14px;font-family:Arial;}
.co{font-size:20px;font-weight:700;color:#002850;}
.cosub{font-size:12px;color:#64748b;}
.cert-title{font-size:24px;font-weight:700;color:#002850;margin:10px 0 4px;}
.cert-sub{font-size:13px;color:#64748b;font-style:italic;}
.badge{display:inline-block;background:#64A014;color:#fff;padding:4px 14px;border-radius:20px;font-size:13px;font-weight:700;margin-top:8px;}
h3{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#002850;border-bottom:1px solid #e2e8f0;padding-bottom:6px;margin:22px 0 12px;}
.row{display:flex;margin-bottom:8px;}
.lbl{width:180px;font-size:13px;font-weight:600;color:#64748b;flex-shrink:0;}
.val{font-size:13px;color:#0f172a;}
.sig-box{border:1px solid #e2e8f0;border-radius:8px;padding:16px;text-align:center;background:#f8fafc;margin-bottom:10px;}
.sig-name{font-size:20px;color:#002850;font-style:italic;margin-top:8px;}
.sig-meta{font-size:11px;color:#94a3b8;margin-top:4px;}
table{width:100%;border-collapse:collapse;margin-top:8px;}
th{background:#f8fafc;padding:7px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #e2e8f0;}
td{padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;}
.hash{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;font-family:Courier New,monospace;font-size:12px;word-break:break-all;color:#334155;margin-bottom:8px;}
.footer{border-top:2px solid #002850;margin-top:28px;padding-top:14px;text-align:center;font-size:12px;color:#64748b;}
@media print{body{padding:0;background:#fff;}}</style></head>
<body><div class="cert">
<div class="hdr">
<div class="logo"><div class="lbox">HRI</div><div><div class="co">HR Indexx Limited</div><div class="cosub">12 Macarthy Street, Onikan, Lagos Island</div></div></div>
<div class="cert-title">Electronic Signature Certificate</div>
<div class="cert-sub">Legal evidence of electronic signing — Nigerian Electronic Transactions Act</div>
<div class="badge">✓ SIGNED &amp; CERTIFIED</div>
</div>
<h3>Document Details</h3>
<div class="row"><div class="lbl">Document Title</div><div class="val"><strong>'.htmlspecialchars($sig['title']).'</strong></div></div>
<div class="row"><div class="lbl">Document Type</div><div class="val">'.ucwords(str_replace('_',' ',$sig['doc_type'])).'</div></div>
<div class="row"><div class="lbl">Requested By</div><div class="val">'.htmlspecialchars($sig['created_by_name']).'</div></div>
<div class="row"><div class="lbl">Request ID</div><div class="val">#'.$sig['request_id'].'</div></div>
<h3>Signatory Details</h3>
<div class="row"><div class="lbl">Full Name</div><div class="val"><strong>'.htmlspecialchars($signerName).'</strong></div></div>
'.($signerEmail?'<div class="row"><div class="lbl">Email</div><div class="val">'.htmlspecialchars($signerEmail).'</div></div>':'').'
<div class="row"><div class="lbl">Signed At</div><div class="val"><strong>'.htmlspecialchars($signedAt).'</strong></div></div>
<div class="row"><div class="lbl">IP Address</div><div class="val">'.htmlspecialchars($ip).'</div></div>
<div class="row"><div class="lbl">Platform</div><div class="val">HRI Mail Electronic Signing Platform</div></div>
<h3>Signature</h3>
<div class="sig-box">'.$sigImgHtml.'<div class="sig-name">'.htmlspecialchars($signerName).'</div><div class="sig-meta">Signed electronically on '.htmlspecialchars($signedAt).'</div></div>
'.( count($allSigs)>1 ? '<h3>All Signatories ('.count($allSigs).')</h3><table><thead><tr><th>Name</th><th>Status</th></tr></thead><tbody>'.$sigsHtml.'</tbody></table>' : '').'
<h3>Verification &amp; Integrity</h3>
<div style="font-size:11px;color:#94a3b8;margin-bottom:4px;">Document Reference Hash (SHA-256)</div>
<div class="hash">'.$docHash.'</div>
<div style="font-size:11px;color:#94a3b8;margin-bottom:4px;">Session Verification Code</div>
<div class="hash">'.$verifyHash.'</div>
<div style="font-size:12px;color:#94a3b8;margin-top:8px;">These hashes verify the authenticity of this signing event. Generated: '.date('d M Y H:i:s T').'</div>
<div class="footer"><strong>HR Indexx Limited</strong> · 12 Macarthy Street, Onikan, Lagos Island<br/>This certificate is generated by HRI Mail and is legally binding under the Nigerian Electronic Transactions Act.<br/>🔒 Secured · IP Logged · Tamper-evident hash included</div>
</div></body></html>';
    file_put_contents($file, $html);
    return $file;
}

$docTypes=['offer_letter'=>['📄','Offer Letter'],'policy_acknowledgement'=>['📋','Policy Acknowledgement'],'privacy_policy'=>['🛡️','Privacy Policy'],'sla'=>['📊','SLA / Agreement'],'contractor'=>['🤝','Contractor Agreement'],'data_breach'=>['🚨','Data Breach Acknowledgement'],'custom'=>['📝','Document']];
[$dtIco,$dtLabel]=$docTypes[$sig['doc_type']]??['📝','Document'];
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Sign — <?=htmlspecialchars($sig['title'])?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
:root{--navy:#002850;--green:#64A014;--gd:#4d7c10;--red:#dc2626;--w:#fff;--g50:#f8fafc;--g100:#f1f5f9;--g200:#e2e8f0;--g400:#94a3b8;--g500:#64748b;--g700:#334155;--g900:#0f172a;--gl:#f0f7e6;--nl:#e8f0fb;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#001a35,#002850);min-height:100vh;padding:20px;display:flex;flex-direction:column;align-items:center;}
.hdr{text-align:center;margin-bottom:18px;color:#fff;}
.logo{display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;}
.lbox{width:36px;height:36px;background:var(--green);border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;}
.ltxt{font-weight:700;font-size:14px;}
.page-sub{font-size:13px;color:rgba(255,255,255,.55);}
.card{background:var(--w);border-radius:18px;width:100%;max-width:600px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.3);margin-bottom:20px;}
.chd{background:var(--navy);padding:18px 24px;color:#fff;display:flex;gap:12px;}
.chd-ico{font-size:26px;flex-shrink:0;}
.chd-title{font-size:15px;font-weight:700;margin-bottom:3px;}
.chd-sub{font-size:12px;color:rgba(255,255,255,.6);}
.cbd{padding:22px 24px;}
.doc-detail{background:var(--g50);border-radius:10px;padding:14px 16px;margin-bottom:18px;}
.dd-row{display:flex;align-items:flex-start;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--g100);}
.dd-row:last-child{border-bottom:none;}
.dd-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--g400);flex-shrink:0;margin-right:12px;}
.dd-val{font-size:13px;color:var(--g700);text-align:right;}
.msg-box{background:var(--g100);border-left:3px solid var(--green);border-radius:0 8px 8px 0;padding:11px 14px;margin-bottom:18px;font-size:13px;color:var(--g700);line-height:1.6;}
.alert{padding:11px 16px;border-radius:9px;font-size:13px;display:flex;gap:8px;margin-bottom:14px;}
.alert.er{background:#fee2e2;border:1px solid #fca5a5;color:var(--red);}
.fg{margin-bottom:14px;}
label{display:block;font-size:10.5px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
input[type=text]{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:9px 12px;font-size:13.5px;font-family:'Inter',sans-serif;color:var(--g900);outline:none;background:var(--g50);}
input[type=text]:focus{border-color:var(--green);background:var(--w);}
.method-tabs{display:flex;gap:7px;margin-bottom:12px;}
.mtab{flex:1;padding:9px;border-radius:8px;border:2px solid var(--g200);background:transparent;color:var(--g700);font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;text-align:center;}
.mtab.on{background:var(--navy);color:#fff;border-color:var(--navy);}
.sig-wrap{border:2px solid var(--g200);border-radius:10px;overflow:hidden;background:#fff;margin-bottom:8px;}
.sig-wrap.active{border-color:var(--navy);}
#sigCanvas{display:block;width:100%;height:160px;cursor:crosshair;touch-action:none;}
.sig-toolbar{display:flex;align-items:center;justify-content:space-between;padding:6px 10px;background:var(--g50);border-top:1px solid var(--g100);}
.sig-hint{font-size:11px;color:var(--g400);}
.sig-clear{padding:4px 11px;border-radius:6px;background:var(--g100);border:1px solid var(--g200);color:var(--g700);font-size:12px;cursor:pointer;font-family:'Inter',sans-serif;}
.upload-area{border:2px dashed var(--g200);border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:all .15s;background:var(--g50);margin-bottom:8px;}
.upload-area:hover{border-color:var(--green);background:var(--gl);}
.upload-area.has-file{border-color:var(--navy);background:var(--nl);}
.upload-ico{font-size:30px;margin-bottom:7px;}
.upload-txt{font-size:13px;color:var(--g500);}
.upload-sub{font-size:11.5px;color:var(--g400);margin-top:3px;}
.preview-sig{max-width:100%;max-height:100px;object-fit:contain;margin-top:8px;border-radius:5px;}
.bg-notice{background:#f0f7e6;border:1px solid #c6e89a;border-radius:7px;padding:8px 12px;font-size:12px;color:#4d7c10;margin-top:6px;}
.agree-row{display:flex;align-items:flex-start;gap:10px;background:var(--g50);border-radius:8px;padding:11px 13px;margin-bottom:16px;cursor:pointer;}
.agree-row input[type=checkbox]{width:auto;margin-top:2px;flex-shrink:0;accent-color:var(--navy);}
.agree-txt{font-size:12.5px;color:var(--g700);line-height:1.55;}
.btn{width:100%;padding:12px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;border:none;font-family:'Inter',sans-serif;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;gap:7px;transition:background .15s;}
.btn:hover{background:var(--gd);}
.doc-link{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:var(--g100);border:1.5px solid var(--g200);border-radius:8px;color:var(--navy);font-size:13px;font-weight:600;text-decoration:none;margin-bottom:16px;transition:all .15s;}
.doc-link:hover{background:var(--nl);border-color:var(--navy);}
.success-card{text-align:center;padding:32px 24px;}
.success-ico{font-size:56px;margin-bottom:12px;}
.success-title{font-size:20px;font-weight:700;color:var(--g900);margin-bottom:6px;}
.success-sub{font-size:13.5px;color:var(--g500);line-height:1.6;}
.meta-row{display:flex;align-items:center;justify-content:center;gap:20px;margin-top:18px;padding-top:16px;border-top:1px solid var(--g100);}
.meta-item{text-align:center;}
.meta-val{font-size:13px;font-weight:600;color:var(--navy);}
.meta-lbl{font-size:10.5px;color:var(--g400);margin-top:2px;}
.cert-link{display:inline-flex;align-items:center;gap:6px;margin-top:14px;padding:9px 18px;background:var(--navy);color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;}
.sec-note{margin-top:14px;font-size:11.5px;color:var(--g400);}
</style></head><body>
<div class="hdr"><div class="logo"><div class="lbox">HRI</div><div class="ltxt">HRI Mail · Digital Signing</div></div><div class="page-sub">HR Indexx Limited · Secure Electronic Signing Platform</div></div>
<div class="card">
<div class="chd"><div class="chd-ico"><?=$dtIco?></div><div><div class="chd-title"><?=htmlspecialchars($sig['title'])?></div><div class="chd-sub"><?=$dtLabel?> · From <?=htmlspecialchars($sig['created_by_name'])?></div></div></div>
<?php if ($alreadySigned): ?>
<div class="success-card">
<div class="success-ico">✅</div>
<div class="success-title"><?=$success?'Document Signed!':'Already Signed'?></div>
<div class="success-sub"><?=$success?'Your signature has been recorded and a signing certificate generated. It has been saved to your Document Vault.':'You have already signed this document.'?></div>
<?php if ($success): ?>
<div class="meta-row">
<div class="meta-item"><div class="meta-val"><?=date('d M Y')?></div><div class="meta-lbl">Date Signed</div></div>
<div class="meta-item"><div class="meta-val"><?=date('H:i T')?></div><div class="meta-lbl">Time</div></div>
<div class="meta-item"><div class="meta-val">#<?=$sig['request_id']?></div><div class="meta-lbl">Request ID</div></div>
</div>
<?php if ($sig['user_id']): ?>
<a class="cert-link" href="<?=APP_URL?>/vault.php?cat=signed">📄 View Signing Certificate in Vault →</a>
<?php endif; ?>
<?php endif; ?>
<div class="sec-note">🔒 IP logged · Tamper-evident hash · Certificate auto-generated</div>
</div>
<?php else: ?>
<div class="cbd">
<div class="doc-detail">
<div class="dd-row"><div class="dd-lbl">Document</div><div class="dd-val"><?=htmlspecialchars($sig['title'])?></div></div>
<div class="dd-row"><div class="dd-lbl">Type</div><div class="dd-val"><?=$dtLabel?></div></div>
<div class="dd-row"><div class="dd-lbl">Requested By</div><div class="dd-val"><?=htmlspecialchars($sig['created_by_name'])?></div></div>
<div class="dd-row"><div class="dd-lbl">Signing As</div><div class="dd-val"><strong><?=htmlspecialchars($signerName)?></strong></div></div>
<div class="dd-row"><div class="dd-lbl">Expires</div><div class="dd-val" style="color:<?=(strtotime($sig['expires_at'])-time())<86400?'var(--red)':'var(--g700)'?>"><?=date('d M Y H:i',strtotime($sig['expires_at']))?></div></div>
</div>
<?php if ($sig['doc_path'] && file_exists(__DIR__.'/'.$sig['doc_path'])): ?>
<a class="doc-link" href="<?=APP_URL.'/'.htmlspecialchars($sig['doc_path'])?>" target="_blank">📄 View Document Before Signing →</a>
<?php endif; ?>
<?php if ($sig['message']): ?><div class="msg-box"><strong style="color:var(--navy);">Message:</strong><br/><?=nl2br(htmlspecialchars($sig['message']))?></div><?php endif; ?>
<?php if ($error): ?><div class="alert er">⚠️ <?=htmlspecialchars($error)?></div><?php endif; ?>
<form method="POST" enctype="multipart/form-data" id="signForm">
<?= Auth::csrfField() ?>
<input type="hidden" name="signature_data" id="sigData"/>
<input type="hidden" name="sign_method" id="signMethod" value="draw"/>
<div class="fg"><label>Your Full Name *</label><input type="text" name="signer_name" value="<?=htmlspecialchars($signerName)?>" required/></div>
<div class="fg">
<label>Signature Method</label>
<div class="method-tabs">
<button type="button" class="mtab on" id="tabDraw" onclick="switchTab('draw')">✏️ Draw Signature</button>
<button type="button" class="mtab" id="tabUpload" onclick="switchTab('upload')">📎 Upload Signature Image</button>
</div>
<div id="paneDraw">
<div class="sig-wrap" id="sigWrap"><canvas id="sigCanvas"></canvas>
<div class="sig-toolbar"><span class="sig-hint">Draw using mouse or finger</span><button type="button" class="sig-clear" onclick="clearCanvas()">Clear</button></div></div>
</div>
<div id="paneUpload" style="display:none;">
<div class="upload-area" id="uploadArea" onclick="document.getElementById('sigFile').click()">
<div class="upload-ico" id="uploadIco">📷</div>
<div class="upload-txt" id="uploadTxt">Click to upload signature image</div>
<div class="upload-sub">PNG, JPG · White background will be auto-removed</div>
<img id="sigPreview" class="preview-sig" style="display:none;" alt="Preview"/>
</div>
<input type="file" id="sigFile" name="sig_upload" accept="image/*" style="display:none;" onchange="handleUpload(this)"/>
<div class="bg-notice">🎨 White/light backgrounds are automatically removed so your signature looks clean on any document.</div>
</div>
</div>
<label class="agree-row">
<input type="checkbox" name="agreed" id="agreeChk"/>
<div class="agree-txt">I, <strong id="agreeNameTxt"><?=htmlspecialchars($signerName)?></strong>, confirm I am signing "<strong><?=htmlspecialchars($sig['title'])?></strong>" electronically. This signature is legally binding under the Nigerian Electronic Transactions Act.</div>
</label>
<button type="button" class="btn" onclick="submitSignature()">✍️ Sign Document &amp; Generate Certificate</button>
</form>
<div class="sec-note" style="text-align:center;margin-top:14px;">🔒 SSL · IP &amp; timestamp recorded · Signing certificate auto-generated · HR Indexx Limited</div>
</div>
<?php endif; ?>
</div>
<script>
function switchTab(m){
document.getElementById('tabDraw').classList.toggle('on',m==='draw');
document.getElementById('tabUpload').classList.toggle('on',m==='upload');
document.getElementById('paneDraw').style.display=m==='draw'?'':'none';
document.getElementById('paneUpload').style.display=m==='upload'?'':'none';
document.getElementById('signMethod').value=m;
if(m==='draw')resizeCanvas();
}
const canvas=document.getElementById('sigCanvas');
const ctx=canvas.getContext('2d');
const wrap=document.getElementById('sigWrap');
let drawing=false,hasDrawn=false;
function resizeCanvas(){const r=window.devicePixelRatio||1;canvas.width=canvas.offsetWidth*r;canvas.height=canvas.offsetHeight*r;ctx.scale(r,r);ctx.strokeStyle='#002850';ctx.lineWidth=2.5;ctx.lineCap='round';ctx.lineJoin='round';}
resizeCanvas();window.addEventListener('resize',resizeCanvas);
function getPos(e){const rect=canvas.getBoundingClientRect();if(e.touches)return{x:e.touches[0].clientX-rect.left,y:e.touches[0].clientY-rect.top};return{x:e.clientX-rect.left,y:e.clientY-rect.top};}
canvas.addEventListener('mousedown',e=>{drawing=true;ctx.beginPath();const p=getPos(e);ctx.moveTo(p.x,p.y);wrap.classList.add('active');});
canvas.addEventListener('mousemove',e=>{if(!drawing)return;const p=getPos(e);ctx.lineTo(p.x,p.y);ctx.stroke();hasDrawn=true;});
canvas.addEventListener('mouseup',()=>drawing=false);
canvas.addEventListener('mouseleave',()=>drawing=false);
canvas.addEventListener('touchstart',e=>{e.preventDefault();drawing=true;ctx.beginPath();const p=getPos(e);ctx.moveTo(p.x,p.y);wrap.classList.add('active');},{passive:false});
canvas.addEventListener('touchmove',e=>{e.preventDefault();if(!drawing)return;const p=getPos(e);ctx.lineTo(p.x,p.y);ctx.stroke();hasDrawn=true;},{passive:false});
canvas.addEventListener('touchend',e=>{e.preventDefault();drawing=false;},{passive:false});
function clearCanvas(){ctx.clearRect(0,0,canvas.width,canvas.height);hasDrawn=false;wrap.classList.remove('active');}
function handleUpload(inp){if(!inp.files[0])return;const r=new FileReader();r.onload=e=>{const p=document.getElementById('sigPreview');p.src=e.target.result;p.style.display='block';document.getElementById('uploadIco').style.display='none';document.getElementById('uploadTxt').textContent=inp.files[0].name;document.getElementById('uploadArea').classList.add('has-file');};r.readAsDataURL(inp.files[0]);}
const nameInp=document.querySelector('[name="signer_name"]');
if(nameInp)nameInp.addEventListener('input',()=>{const el=document.getElementById('agreeNameTxt');if(el)el.textContent=nameInp.value||'I';});
function submitSignature(){
const method=document.getElementById('signMethod').value;
if(method==='draw'){if(!hasDrawn){alert('Please draw your signature.');return;}document.getElementById('sigData').value=canvas.toDataURL('image/png');}
else{const p=document.getElementById('sigPreview');if(!p.src||p.style.display==='none'){alert('Please upload a signature image.');return;}document.getElementById('sigData').value=p.src;}
if(!document.getElementById('agreeChk').checked){alert('Please tick the agreement checkbox.');return;}
const btn=document.querySelector('.btn');btn.textContent='⏳ Generating certificate…';btn.disabled=true;
document.getElementById('signForm').submit();}
</script>
</body></html>
