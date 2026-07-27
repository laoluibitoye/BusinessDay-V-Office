<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

$user = Auth::require();
$db   = getDB();

$attachId = (int)($_GET['id'] ?? 0);
if (!$attachId) { http_response_code(400); exit('Invalid request.'); }

$row = $db->prepare("SELECT a.*, r.requester_id FROM irs_attachments a
    JOIN irs_requests r ON r.id=a.request_id WHERE a.id=?");
$row->execute([$attachId]);
$att = $row->fetch(PDO::FETCH_ASSOC);

if (!$att) { http_response_code(404); exit('Attachment not found.'); }

$isAccountsTeam = in_array($user['role'], IRS_ACCOUNTS_ROLES);
$isManager      = in_array($user['role'], IRS_MANAGER_ROLES);
$isAdmin        = Auth::isAdmin($user);
$isRequester    = ($user['id'] === (int)$att['requester_id']);

if (!$isRequester && !$isAccountsTeam && !$isManager && !$isAdmin) {
    http_response_code(403); exit('Access denied.');
}

$filePath = __DIR__ . '/../../' . $att['file_path'];
if (!file_exists($filePath)) { http_response_code(404); exit('File not found on server.'); }

$mime = $att['mime_type'] ?: 'application/octet-stream';
ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
$disposition = !empty($_GET['dl']) ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($att['filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
