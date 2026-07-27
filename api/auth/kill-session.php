<?php
// api/auth/kill-session.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

$user  = Auth::require();
$db    = getDB();
$token = $_POST['token'] ?? '';

if ($token) {
    // Only kill own sessions
    $db->prepare("DELETE FROM sessions WHERE token=? AND user_id=?")->execute([$token, $user['id']]);
    Auth::auditLog($user['id'], 'session_killed', 'Ended a session');
}
header('Location: ' . APP_URL . '/my-stats.php');
exit;
