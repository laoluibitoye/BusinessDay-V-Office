<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$channelId = (int)($_POST['channel_id'] ?? 0);
$lastId    = (int)($_POST['last_id'] ?? 0);

if (!$channelId || !$lastId) { echo json_encode(['ok'=>false]); exit; }

$db->prepare("UPDATE chat_members SET last_read_id=GREATEST(last_read_id,?) WHERE channel_id=? AND user_id=?")
   ->execute([$lastId, $channelId, $user['id']]);

echo json_encode(['ok'=>true]);
