<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$stmt = $db->prepare("
    SELECT u.id, u.name, u.role, u.avatar_color, u.department,
           MAX(CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END) as is_online
    FROM users u
    LEFT JOIN sessions s ON s.user_id = u.id
        AND s.expires_at > NOW()
        AND COALESCE(s.last_active, s.created_at) > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    WHERE u.is_active = 1
    GROUP BY u.id, u.name, u.role, u.avatar_color, u.department
    ORDER BY is_online DESC, u.name ASC
");
$stmt->execute();
$people = $stmt->fetchAll();

$dmStmt = $db->prepare("
    SELECT c.id, c.slug FROM chat_channels c
    JOIN chat_members cm ON cm.channel_id=c.id AND cm.user_id=?
    WHERE c.type='direct' AND c.is_active=1
");
$dmStmt->execute([$user['id']]);
$dmMap = [];
foreach ($dmStmt->fetchAll() as $d) $dmMap[$d['slug']] = (int)$d['id'];

foreach ($people as &$p) {
    $p['is_me']         = ($p['id'] == $user['id']);
    $slug               = 'dm-' . min($user['id'],(int)$p['id']) . '-' . max($user['id'],(int)$p['id']);
    $p['dm_channel_id'] = $dmMap[$slug] ?? null;
    $p['role_label']    = ROLES[$p['role']]['label'] ?? $p['role'];
}
unset($p);

echo json_encode(['ok'=>true, 'people'=>$people, 'current_user_id'=>$user['id']]);
