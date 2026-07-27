<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$channelId  = (int)($_POST['channel_id'] ?? 0);
$body       = trim($_POST['body'] ?? '');
$replyToId  = (int)($_POST['reply_to_id'] ?? 0) ?: null;
$attachment = trim($_POST['attachment'] ?? '') ?: null;

if (!$channelId || ($body === '' && !$attachment)) {
    echo json_encode(['ok'=>false,'error'=>'Missing channel or message']); exit;
}
if (mb_strlen($body) > 4000) {
    echo json_encode(['ok'=>false,'error'=>'Message too long (max 4000 chars)']); exit;
}

// Verify membership
$mem = $db->prepare("SELECT 1 FROM chat_members WHERE channel_id=? AND user_id=?");
$mem->execute([$channelId, $user['id']]);
if (!$mem->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not a member']); exit; }

// Insert message
$db->prepare("INSERT INTO chat_messages (channel_id, user_id, body, reply_to_id, attachment) VALUES (?,?,?,?,?)")
   ->execute([$channelId, $user['id'], $body, $replyToId, $attachment]);
$msgId = (int)$db->lastInsertId();

// Mark sender as having read up to this message
$db->prepare("UPDATE chat_members SET last_read_id=? WHERE channel_id=? AND user_id=?")
   ->execute([$msgId, $channelId, $user['id']]);

// Return full message object so sender can render it immediately
$row = $db->prepare("
    SELECT m.id, m.channel_id, m.user_id, m.body, m.reply_to_id, m.attachment, m.created_at,
           u.name as user_name, u.avatar_color,
           r.body as reply_body, ru.name as reply_user_name
    FROM chat_messages m
    JOIN users u ON u.id = m.user_id
    LEFT JOIN chat_messages r ON r.id = m.reply_to_id AND r.is_deleted = 0
    LEFT JOIN users ru ON ru.id = r.user_id
    WHERE m.id = ?
");
$row->execute([$msgId]);
$message = $row->fetch();

echo json_encode(['ok'=>true, 'message'=>$message]);
