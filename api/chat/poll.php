<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();

// Chat polls every 15 seconds per user. Holding the session lock for each poll
// serialises it against every other request from that user, so release it now —
// nothing below reads or writes $_SESSION.
session_write_close();

$db   = getDB();

$channelId = (int)($_GET['channel_id'] ?? 0);
$after     = (int)($_GET['after'] ?? 0);

if (!$channelId) { echo json_encode(['ok'=>false,'error'=>'No channel']); exit; }

// Verify membership
$mem = $db->prepare("SELECT 1 FROM chat_members WHERE channel_id=? AND user_id=?");
$mem->execute([$channelId, $user['id']]);
if (!$mem->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not a member']); exit; }

// Fetch new messages since last poll
$stmt = $db->prepare("
    SELECT m.id, m.channel_id, m.user_id, m.body, m.reply_to_id, m.attachment, m.created_at,
           u.name as user_name, u.avatar_color,
           r.body as reply_body, ru.name as reply_user_name
    FROM chat_messages m
    JOIN users u ON u.id = m.user_id
    LEFT JOIN chat_messages r ON r.id = m.reply_to_id AND r.is_deleted = 0
    LEFT JOIN users ru ON ru.id = r.user_id
    WHERE m.channel_id = ? AND m.id > ? AND m.is_deleted = 0
    ORDER BY m.id ASC LIMIT 50
");
$stmt->execute([$channelId, $after]);
$messages = $stmt->fetchAll();

// Total unread across all user's channels (for nav badge)
$uq = $db->prepare("
    SELECT COALESCE(SUM(
        (SELECT COUNT(*) FROM chat_messages msg
         WHERE msg.channel_id = cm.channel_id
           AND msg.id > cm.last_read_id
           AND msg.is_deleted = 0
           AND msg.user_id != ?)
    ), 0)
    FROM chat_members cm WHERE cm.user_id = ?
");
$uq->execute([$user['id'], $user['id']]);
$totalUnread = (int)$uq->fetchColumn();

echo json_encode(['ok'=>true, 'messages'=>$messages, 'total_unread'=>$totalUnread]);
