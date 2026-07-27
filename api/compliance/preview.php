<?php
// api/compliance/preview.php — Serve compliance docs inline (no download)
// Authenticated endpoint: only head_compliance, head_it, compliance, it_admin
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

$user = Auth::requireRole(['md','bdm','head_it','it_admin','hr','head_compliance','head_outsourcing','head_accounts','head_training','head_cso']);
$db   = getDB();

$id   = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'compliance';

if (!$id) { http_response_code(400); exit('Invalid request.'); }

if ($type === 'compliance') {
    $stmt = $db->prepare("SELECT * FROM compliance_docs WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    if (!$doc || !$doc['stored_name'] || !$doc['file_path']) {
        http_response_code(404); exit('Document not found or no file attached.');
    }
    $filePath = $doc['file_path'];
    $mime     = $doc['mime_type'] ?: 'application/octet-stream';
    $title    = $doc['name'];
} else {
    http_response_code(400); exit('Unknown type.');
}

if (!file_exists($filePath)) {
    http_response_code(404); exit('File missing from server. Please re-upload.');
}

// Serve inline — no download header, auth-gated only
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode(basename($title)) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
