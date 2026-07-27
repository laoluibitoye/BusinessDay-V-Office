<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

// GET — return channel list with unread counts
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.slug, c.description, c.type,
               cm.last_read_id,
               (SELECT COUNT(*) FROM chat_messages m
                WHERE m.channel_id=c.id AND m.id > cm.last_read_id
                  AND m.is_deleted=0 AND m.user_id != ?) as unread,
               (SELECT m2.body FROM chat_messages m2
                WHERE m2.channel_id=c.id AND m2.is_deleted=0
                ORDER BY m2.id DESC LIMIT 1) as last_body,
               (SELECT u2.name FROM chat_messages m2
                JOIN users u2 ON u2.id=m2.user_id
                WHERE m2.channel_id=c.id AND m2.is_deleted=0
                ORDER BY m2.id DESC LIMIT 1) as last_sender,
               (SELECT m2.created_at FROM chat_messages m2
                WHERE m2.channel_id=c.id AND m2.is_deleted=0
                ORDER BY m2.id DESC LIMIT 1) as last_at,
               (SELECT MAX(m2.id) FROM chat_messages m2
                WHERE m2.channel_id=c.id AND m2.is_deleted=0) as last_id
        FROM chat_channels c
        JOIN chat_members cm ON cm.channel_id=c.id AND cm.user_id=?
        WHERE c.is_active=1
        ORDER BY c.type ASC, last_at DESC, c.name ASC
    ");
    $stmt->execute([$user['id'], $user['id']]);
    $channels = $stmt->fetchAll();

    // For direct channels, get the other user's name and color
    foreach ($channels as &$ch) {
        if ($ch['type'] === 'direct') {
            $other = $db->prepare("
                SELECT u.id, u.name, u.avatar_color FROM chat_members cm
                JOIN users u ON u.id=cm.user_id
                WHERE cm.channel_id=? AND cm.user_id != ? LIMIT 1
            ");
            $other->execute([$ch['id'], $user['id']]);
            $o = $other->fetch();
            $ch['other_id']    = $o['id']    ?? null;
            $ch['other_name']  = $o['name']  ?? 'Unknown';
            $ch['other_color'] = $o['avatar_color'] ?? '#64748b';
        }
    }
    unset($ch);

    echo json_encode(['ok'=>true, 'channels'=>$channels]);
    exit;
}

// POST — create a new public channel
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $canCreate = in_array($user['role'], ['head_it','it_admin','md','bdm','hr','head_outsourcing','head_compliance','head_accounts','head_training','head_cso']);
    if (!$canCreate) { echo json_encode(['ok'=>false,'error'=>'Permission denied']); exit; }

    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Channel name required']); exit; }

    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
    $slug = trim($slug, '-');

    // Ensure unique slug
    $exists = $db->prepare("SELECT id FROM chat_channels WHERE slug=?");
    $exists->execute([$slug]);
    if ($exists->fetch()) {
        $slug .= '-' . substr(uniqid(), -4);
    }

    $db->prepare("INSERT INTO chat_channels (name, slug, description, type, created_by) VALUES (?,?,?,'public',?)")
       ->execute([$name, $slug, $desc, $user['id']]);
    $channelId = (int)$db->lastInsertId();

    // Auto-add creator as member
    $db->prepare("INSERT IGNORE INTO chat_members (channel_id, user_id) VALUES (?,?)")
       ->execute([$channelId, $user['id']]);

    Auth::auditLog($user['id'], 'chat_channel_created', "Created channel: $name");
    echo json_encode(['ok'=>true, 'channel_id'=>$channelId, 'slug'=>$slug, 'name'=>$name]);
    exit;
}
