<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$signatoryId = (int)($_POST['signatory_id'] ?? 0);
if (!$signatoryId) { echo json_encode(['ok'=>false,'error'=>'Invalid request.']); exit; }

// Load the signatory and verify the caller created the request or is admin
$stmt = $db->prepare("SELECT ss.*, sr.title, sr.created_by, sr.id as request_id
    FROM sign_signatories ss
    JOIN sign_requests sr ON sr.id = ss.request_id
    WHERE ss.id = ? AND ss.status = 'pending'");
$stmt->execute([$signatoryId]);
$row = $stmt->fetch();

if (!$row) { echo json_encode(['ok'=>false,'error'=>'Signatory not found or already signed.']); exit; }

$isSuperAdmin = in_array($user['role'], ['head_it','it_admin','md','bdm']);
if ((int)$row['created_by'] !== $user['id'] && !$isSuperAdmin) {
    echo json_encode(['ok'=>false,'error'=>'Only the document creator can send reminders.']); exit;
}

// Get recipient details
$email = $row['user_email'] ?? $row['external_email'] ?? '';
$name  = $row['user_name']  ?? $row['external_name']  ?? 'Signatory';

if (!$email) { echo json_encode(['ok'=>false,'error'=>'No email address for this signatory.']); exit; }

// Build the sign URL from the token stored in DB — never exposed in HTML
$signUrl = APP_URL . '/sign.php?token=' . $row['token'];

$subject = 'Reminder: Please sign — ' . $row['title'];
$body    = '<p>Dear ' . htmlspecialchars($name) . ',</p>'
         . '<p>This is a friendly reminder to sign the document: <strong>' . htmlspecialchars($row['title']) . '</strong>.</p>'
         . '<p><a href="' . $signUrl . '" style="background:#002850;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block;">Sign Document</a></p>'
         . '<p style="color:#64748b;font-size:12px;">If the button above does not work, copy this link into your browser:<br>'
         . htmlspecialchars($signUrl) . '</p>'
         . '<p style="color:#94a3b8;font-size:11px;">HR Indexx Limited · HRI Mail</p>';

sendSystemMail($email, $name, $subject, $body);

Auth::auditLog($user['id'], 'sign_reminder_sent', "Reminder sent for signatory ID $signatoryId on request '{$row['title']}'");
echo json_encode(['ok'=>true]);
