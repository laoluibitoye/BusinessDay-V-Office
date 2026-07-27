<?php
// api/sop/preview.php — Serve SOP files inline (no download)
// Accessible by all authenticated users — view only
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

$user = Auth::require();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Invalid request.'); }

$stmt = $db->prepare("SELECT * FROM sop_documents WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$sop = $stmt->fetch();

if (!$sop) { http_response_code(404); exit('SOP not found or unavailable.'); }
if (!$sop['file_path'] || !file_exists($sop['file_path'])) {
    http_response_code(404); exit('File missing from server. Please contact IT Admin.');
}

// Serve inline — no download, auth-gated
header('Content-Type: ' . ($sop['mime_type'] ?: 'application/pdf'));
header('Content-Disposition: inline; filename="' . rawurlencode($sop['title']) . '.pdf"');
header('Content-Length: ' . filesize($sop['file_path']));
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

readfile($sop['file_path']);
exit;
