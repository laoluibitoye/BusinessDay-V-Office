<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/IrsFlow.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$requestId = (int)($_POST['request_id'] ?? 0);
$note      = trim($_POST['note'] ?? '');

if (!$requestId || $note === '') {
    echo json_encode(['ok'=>false,'error'=>'Note cannot be empty.']);
    exit;
}
if (mb_strlen($note) > 1000) {
    echo json_encode(['ok'=>false,'error'=>'Note too long (max 1000 characters).']);
    exit;
}

$req = null;
try {
    $s = $db->prepare("SELECT id, requester_id FROM irs_requests WHERE id=?");
    $s->execute([$requestId]);
    $req = $s->fetch(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

if (!$req || !IrsFlow::canView($user, $req)) {
    echo json_encode(['ok'=>false,'error'=>'Access denied.']);
    exit;
}

try {
    $ins = $db->prepare("INSERT INTO irs_audit_log (request_id, user_id, action, detail, ip_address) VALUES (?,?,?,?,?)");
    $ins->execute([$requestId, $user['id'], 'note', $note, $_SERVER['REMOTE_ADDR'] ?? null]);
    echo json_encode([
        'ok'   => true,
        'name' => htmlspecialchars($user['name']),
        'note' => htmlspecialchars($note),
        'time' => date('d M Y, g:ia'),
    ]);
} catch(Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Could not save note.']);
}
