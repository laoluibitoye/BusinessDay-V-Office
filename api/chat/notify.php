<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user  = Auth::require();
$db    = getDB();
$after = (int)($_GET['after'] ?? -1); // -1 = init (return max_id, no toasts)

// On first visit: auto-catch-up channels where last_read_id=0 so historical
// messages don't flood the badge. Only catches up channels with NO prior reads.
if ($after < 0) {
    $db->prepare("
        UPDATE chat_members cm
        JOIN (
            SELECT channel_id, COALESCE(MAX(id),0) AS max_id
            FROM chat_messages WHERE is_deleted=0 GROUP BY channel_id
        ) lm ON lm.channel_id = cm.channel_id
        SET cm.last_read_id = lm.max_id
        WHERE cm.user_id = ? AND cm.last_read_id = 0
    ")->execute([$user['id']]);
}

// Total unread across all user's channels (excluding own messages)
$uq = $db->prepare("
    SELECT COALESCE(SUM(
        (SELECT COUNT(*) FROM chat_messages m
         WHERE m.channel_id = cm.channel_id
           AND m.id > cm.last_read_id
           AND m.is_deleted = 0
           AND m.user_id != ?)
    ), 0) FROM chat_members cm WHERE cm.user_id = ?
");
$uq->execute([$user['id'], $user['id']]);
$totalUnread = (int)$uq->fetchColumn();

// Highest message ID across all user's channels
$mq = $db->prepare("
    SELECT COALESCE(MAX(m.id), 0)
    FROM chat_messages m
    JOIN chat_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?
    WHERE m.is_deleted = 0
");
$mq->execute([$user['id']]);
$maxId = (int)$mq->fetchColumn();

// New messages since 'after' — only for non-init calls
$newMessages = [];
if ($after >= 0 && $maxId > $after) {
    $stmt = $db->prepare("
        SELECT m.id, m.channel_id, m.body, m.created_at,
               u.name AS user_name,
               c.name AS channel_name, c.type AS channel_type
        FROM chat_messages m
        JOIN users u ON u.id = m.user_id
        JOIN chat_channels c ON c.id = m.channel_id
        JOIN chat_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?
        WHERE m.id > ? AND m.is_deleted = 0 AND m.user_id != ?
        ORDER BY m.id ASC
        LIMIT 5
    ");
    $stmt->execute([$user['id'], $after, $user['id']]);
    $newMessages = $stmt->fetchAll();
}

echo json_encode([
    'ok'           => true,
    'total_unread' => $totalUnread,
    'new_messages' => $newMessages,
    'max_id'       => $maxId,
]);
