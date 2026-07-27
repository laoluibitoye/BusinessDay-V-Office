<?php
// api/mail/queue.php — Undo Send + Schedule Send queue manager
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');
$user = Auth::require();
$db   = getDB();

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS email_queue (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        `to`        TEXT NOT NULL,
        cc          TEXT,
        bcc         TEXT,
        subject     VARCHAR(500),
        body        LONGTEXT,
        send_at     DATETIME NOT NULL,
        status      ENUM('pending','sent','cancelled','failed') DEFAULT 'pending',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        sent_at     DATETIME NULL,
        error       TEXT NULL,
        INDEX(user_id), INDEX(status), INDEX(send_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'queue': // Queue email for delayed send (undo send / schedule send)
        $to      = Auth::sanitiseString($_POST['to']      ?? '', 500);
        $subject = Auth::sanitiseString($_POST['subject'] ?? '', 255);
        $body    = $_POST['body']    ?? '';
        $cc      = Auth::sanitiseString($_POST['cc']  ?? '', 500);
        $bcc     = Auth::sanitiseString($_POST['bcc'] ?? '', 500);
        $delay   = (int)($_POST['delay'] ?? 10); // seconds - default 10s undo window
        $sendAt  = date('Y-m-d H:i:s', time() + $delay);

        if (!$to || !$subject) {
            echo json_encode(['ok'=>false,'error'=>'Missing to or subject']); exit;
        }

        $db->prepare("INSERT INTO email_queue (user_id, `to`, cc, bcc, subject, body, send_at) VALUES (?,?,?,?,?,?,?)")
           ->execute([$user['id'], $to, $cc, $bcc, $subject, $body, $sendAt]);
        $qid = $db->lastInsertId();
        Auth::auditLog($user['id'], 'email_queued', "QID $qid To: $to Subject: $subject SendAt: $sendAt");
        echo json_encode(['ok'=>true, 'queue_id'=>$qid, 'send_at'=>$sendAt, 'delay'=>$delay]);
        break;

    case 'cancel': // Cancel queued email (undo send)
        $qid = Auth::sanitiseInt($_POST['queue_id'] ?? 0);
        $stmt = $db->prepare("UPDATE email_queue SET status='cancelled' WHERE id=? AND user_id=? AND status='pending' AND send_at > NOW()");
        $stmt->execute([$qid, $user['id']]);
        if ($stmt->rowCount() > 0) {
            Auth::auditLog($user['id'], 'email_cancelled', "QID $qid cancelled via undo");
            echo json_encode(['ok'=>true,'message'=>'Email cancelled']);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Email already sent or not found']);
        }
        break;

    case 'list': // List pending scheduled emails
        $rows = $db->prepare("SELECT id, `to`, subject, send_at, status FROM email_queue WHERE user_id=? AND status='pending' ORDER BY send_at ASC");
        $rows->execute([$user['id']]);
        echo json_encode(['ok'=>true,'queue'=>$rows->fetchAll()]);
        break;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}
