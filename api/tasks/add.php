<?php
// api/tasks/add.php — Add task via AJAX
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');
$user = Auth::require();

$input = json_decode(file_get_contents('php://input'), true);
$title = trim($input['title'] ?? '');

if (!$title) { echo json_encode(['ok'=>false,'error'=>'No title']); exit; }

try {
    $db = getDB();
    $db->prepare("INSERT INTO tasks (user_id, created_by, title, priority, status, source) VALUES (?, ?, ?, 'normal', 'pending', 'manual')")
       ->execute([$user['id'], $user['id'], $title]);
    Auth::trackUsage($user['id'], 'tasks_created');
    echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
}
