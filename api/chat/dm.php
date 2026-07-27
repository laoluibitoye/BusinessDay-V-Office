<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$otherId = (int)($_POST['user_id'] ?? 0);
if (!$otherId || $otherId === $user['id']) {
    echo json_encode(['ok'=>false,'error'=>'Invalid user']); exit;
}

// Check the other user exists
$other = $db->prepare("SELECT id, name FROM users WHERE id=? AND is_active=1");
$other->execute([$otherId]);
$other = $other->fetch();
if (!$other) { echo json_encode(['ok'=>false,'error'=>'User not found']); exit; }

// Check if DM channel already exists between these two users
$existing = $db->prepare("
    SELECT c.id FROM chat_channels c
    JOIN chat_members cm1 ON cm1.channel_id=c.id AND cm1.user_id=?
    JOIN chat_members cm2 ON cm2.channel_id=c.id AND cm2.user_id=?
    WHERE c.type='direct' AND c.is_active=1
    LIMIT 1
");
$existing->execute([$user['id'], $otherId]);
$row = $existing->fetch();

if ($row) {
    echo json_encode(['ok'=>true, 'channel_id'=>(int)$row['id'], 'created'=>false]);
    exit;
}

// Create new DM channel
$slug = 'dm-' . min($user['id'], $otherId) . '-' . max($user['id'], $otherId);
$db->prepare("INSERT INTO chat_channels (name, slug, type, created_by) VALUES (?,?,'direct',?)")
   ->execute([$other['name'], $slug, $user['id']]);
$channelId = (int)$db->lastInsertId();

// Add both users as members
$db->prepare("INSERT IGNORE INTO chat_members (channel_id, user_id) VALUES (?,?),(?,?)")
   ->execute([$channelId, $user['id'], $channelId, $otherId]);

echo json_encode(['ok'=>true, 'channel_id'=>$channelId, 'created'=>true]);
