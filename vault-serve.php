<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// vault-serve.php — Securely serve vault files to authenticated users only
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';

$user = Auth::require();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
$dl = isset($_GET['dl']); // force download

if (!$id) { http_response_code(404); die('File not found.'); }

// Only serve files owned by this user
$stmt = $db->prepare("SELECT * FROM vault_files WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user['id']]);
$file = $stmt->fetch();

if (!$file) { http_response_code(403); die('Access denied.'); }

$fullPath = __DIR__ . '/' . ltrim($file['file_path'], '/');

if (!file_exists($fullPath)) {
    http_response_code(404);
    die('File no longer exists on server.');
}

$mime     = $file['mime_type'] ?: mime_content_type($fullPath) ?: 'application/octet-stream';
$filename = $file['filename'];

// Security: block PHP files from being served
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
if (in_array($ext, ['php','php3','php4','php5','phtml','pl','py','cgi','sh'])) {
    http_response_code(403);
    die('File type not permitted.');
}

// Log access
Auth::auditLog($user['id'], 'vault_view', "Viewed: $filename");

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));

$safeFilename = rawurlencode(preg_replace('/[\x00-\x1F\x7F]/', '_', $filename));
if ($dl) {
    header("Content-Disposition: attachment; filename*=UTF-8''$safeFilename");
} else {
    header("Content-Disposition: inline; filename*=UTF-8''$safeFilename");
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

readfile($fullPath);
exit;
