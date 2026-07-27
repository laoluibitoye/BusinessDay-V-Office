<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$requestId = (int)($_POST['request_id'] ?? 0);
$label     = $_POST['label'] ?? 'other';

if (!$requestId) { echo json_encode(['ok'=>false,'error'=>'Invalid request.']); exit; }

$validLabels = ['journal_voucher','invoice','approval_memo','receipts','proof_of_refund','other'];
if (!in_array($label, $validLabels)) $label = 'other';

// Verify access: requester or accounts/admin
$row = $db->prepare("SELECT requester_id, type, status FROM irs_requests WHERE id=?");
$row->execute([$requestId]);
$req = $row->fetch(PDO::FETCH_ASSOC);
if (!$req) { echo json_encode(['ok'=>false,'error'=>'Request not found.']); exit; }

$isAccountsTeam = in_array($user['role'], IRS_ACCOUNTS_ROLES);
$isAdmin        = Auth::isAdmin($user);
$isRequester    = ($user['id'] === (int)$req['requester_id']);

if (!$isRequester && !$isAccountsTeam && !$isAdmin) {
    echo json_encode(['ok'=>false,'error'=>'Access denied.']); exit;
}

// Proof of refund: only requester can upload, only when status=refund_required
if ($label === 'proof_of_refund') {
    if (!$isRequester) { echo json_encode(['ok'=>false,'error'=>'Only the requester can upload proof of refund.']); exit; }
    if ($req['status'] !== 'refund_required') { echo json_encode(['ok'=>false,'error'=>'Proof of refund can only be uploaded when refund is required.']); exit; }
}

if (empty($_FILES['file'])) { echo json_encode(['ok'=>false,'error'=>'No file uploaded.']); exit; }

$file   = $_FILES['file'];
$size   = $file['size'];
$tmpPath = $file['tmp_name'];
$origName = basename($file['name']);
$mime   = mime_content_type($tmpPath) ?: 'application/octet-stream';

if ($size > MAX_UPLOAD_BYTES) {
    echo json_encode(['ok'=>false,'error'=>'File exceeds 25 MB limit.']); exit;
}
if (!in_array($mime, ALLOWED_ATTACHMENT_TYPES)) {
    echo json_encode(['ok'=>false,'error'=>'File type not allowed.']); exit;
}

$uploadDir = __DIR__ . '/../../uploads/irs/' . $requestId . '/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$storedName = uniqid('irs_', true) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
$destPath   = $uploadDir . $storedName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    echo json_encode(['ok'=>false,'error'=>'Failed to save file. Please try again.']); exit;
}

try {
    $ins = $db->prepare("INSERT INTO irs_attachments (request_id,user_id,filename,stored_name,file_path,mime_type,file_size,label)
        VALUES (?,?,?,?,?,?,?,?)");
    $ins->execute([$requestId,$user['id'],$origName,$storedName,
        'uploads/irs/'.$requestId.'/'.$storedName,$mime,$size,$label]);
    $attachId = (int)$db->lastInsertId();

    $aud = $db->prepare("INSERT INTO irs_audit_log (request_id,user_id,action,detail,ip_address) VALUES (?,?,?,?,?)");
    $aud->execute([$requestId,$user['id'],'attachment_uploaded',"Uploaded: {$origName} ({$label})",$_SERVER['REMOTE_ADDR']??null]);

    echo json_encode(['ok'=>true,'id'=>$attachId,'filename'=>$origName,'label'=>$label]);
} catch (Exception $e) {
    @unlink($destPath);
    echo json_encode(['ok'=>false,'error'=>'Database error saving attachment.']);
}
