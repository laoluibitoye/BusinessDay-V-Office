<?php
// cron/send-queue.php — Process email send queue (run every minute via cPanel cron)
// Cron command: * * * * * php /home/hrindexx/public_html/mail/cron/send-queue.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

if (file_exists(__DIR__ . '/../lib/vendor/autoload.php')) {
    require_once __DIR__ . '/../lib/vendor/autoload.php';
}

$db = getDB();

// Get emails due to send
$stmt = $db->prepare("
    SELECT q.*, u.email as user_email, u.name as user_name
    FROM email_queue q
    JOIN users u ON u.id = q.user_id
    WHERE q.status = 'pending' AND q.send_at <= NOW()
    LIMIT 20
");
$stmt->execute();
$due = $stmt->fetchAll();

if (!$due) {
    echo date('H:i:s') . " - No emails due\n";
    exit;
}

echo date('H:i:s') . " - Processing " . count($due) . " queued emails\n";

foreach ($due as $email) {
    try {
        // Get user's mail password from active session (can't do this in cron)
        // Instead, use SMTP with stored credentials approach
        // For now: use PHP mail() as fallback, PHPMailer if credentials available
        
        $sent = false;
        
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            // Try PHPMailer - needs SMTP password
            // Since we can't get session password in cron, use PHP mail()
        }

        // PHP mail() fallback
        $headers  = "From: {$email['user_name']} <{$email['user_email']}>\r\n";
        $headers .= "Reply-To: {$email['user_email']}\r\n";
        if ($email['cc'])  $headers .= "Cc: {$email['cc']}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        $toList = array_filter(array_map('trim', explode(',', $email['to'])));
        $toStr  = implode(', ', $toList);

        $sent = @mail($toStr, $email['subject'], $email['body'], $headers);

        if ($sent) {
            $db->prepare("UPDATE email_queue SET status='sent', sent_at=NOW() WHERE id=?")
               ->execute([$email['id']]);
            echo " ✓ Sent QID {$email['id']} to {$email['to']}\n";
        } else {
            $db->prepare("UPDATE email_queue SET status='failed', error='php_mail() returned false' WHERE id=?")
               ->execute([$email['id']]);
            echo " ✗ Failed QID {$email['id']}\n";
        }

    } catch (Exception $e) {
        $db->prepare("UPDATE email_queue SET status='failed', error=? WHERE id=?")
           ->execute([substr($e->getMessage(),0,255), $email['id']]);
        echo " ✗ Exception QID {$email['id']}: {$e->getMessage()}\n";
    }
}
echo date('H:i:s') . " - Done\n";
