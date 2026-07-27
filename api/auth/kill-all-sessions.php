<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
$user = Auth::require();
$db   = getDB();
$db->prepare("DELETE FROM sessions WHERE user_id=? AND token!=?")->execute([$user['id'], $_SESSION['token']]);
Auth::auditLog($user['id'], 'sessions_killed', 'Ended all other sessions');
header('Location: ' . APP_URL . '/profile.php?success=1');
exit;
