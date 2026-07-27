<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'No file or upload error']); exit;
}

$file    = $_FILES['file'];
$maxSize = 10 * 1024 * 1024;
if ($file['size'] > $maxSize) { echo json_encode(['ok'=>false,'error'=>'Max 10MB allowed']); exit; }

$allowed = [
    'image/jpeg','image/png','image/gif','image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain','text/csv','application/zip',
];
$mime = mime_content_type($file['tmp_name']);
if (!in_array($mime, $allowed)) { echo json_encode(['ok'=>false,'error'=>'File type not allowed']); exit; }

$dir = __DIR__ . '/../../uploads/chat/' . $user['id'] . '/';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    echo json_encode(['ok'=>false,'error'=>'Cannot create upload directory']); exit;
}

$ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$stored = bin2hex(random_bytes(12)) . ($ext ? '.'.$ext : '');
if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) {
    echo json_encode(['ok'=>false,'error'=>'Failed to save file']); exit;
}

Auth::auditLog($user['id'], 'chat_file_upload', $file['name'] . ' (' . round($file['size']/1024) . 'KB)');

echo json_encode([
    'ok'   => true,
    'name' => htmlspecialchars($file['name']),
    'url'  => APP_URL . '/uploads/chat/' . $user['id'] . '/' . $stored,
    'size' => $file['size'],
    'type' => $mime,
]);
