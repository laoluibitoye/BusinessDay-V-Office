<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/IrsFlow.php';

$user = Auth::require();
$db   = getDB();

$attachId = (int)($_GET['id'] ?? 0);
if (!$attachId) { http_response_code(400); exit('Invalid request.'); }

$row = $db->prepare("SELECT a.*, r.requester_id FROM irs_attachments a
    JOIN irs_requests r ON r.id=a.request_id WHERE a.id=?");
$row->execute([$attachId]);
$att = $row->fetch(PDO::FETCH_ASSOC);

if (!$att) { http_response_code(404); exit('Attachment not found.'); }

// Same authority as irs-detail.php. If you cannot open the request, you cannot
// download what is attached to it — previously this endpoint made its own
// decision from static constants and could disagree with the page.
if (!IrsFlow::canView($user, $att) && !Auth::isAdmin($user)) {
    Auth::auditLog($user['id'], 'irs_attachment_denied',
        'Denied attachment #' . $attachId . ' on request #' . (int)$att['request_id']);
    http_response_code(403); exit('Access denied.');
}

// Defence in depth: file_path is written by upload.php from a sanitised name,
// but resolve it and confirm it is still inside the uploads tree before
// reading. Any future writer that forgets to sanitise cannot escape.
$uploadRoot = realpath(__DIR__ . '/../../uploads');
$filePath   = realpath(__DIR__ . '/../../' . $att['file_path']);
if ($uploadRoot === false || $filePath === false || strpos($filePath, $uploadRoot . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404); exit('File not found on server.');
}
if (!is_file($filePath)) { http_response_code(404); exit('File not found on server.'); }

$mime = $att['mime_type'] ?: 'application/octet-stream';
ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
$disposition = !empty($_GET['dl']) ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($att['filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
