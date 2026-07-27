<?php
// config/mail.php
// HRI Webmail — Mail Server Configuration

define('IMAP_HOST', 'hrindexx.com');
define('IMAP_PORT', 993);
define('IMAP_ENCRYPTION', 'SSL');
define('IMAP_VALIDATE_CERT', false);

define('SMTP_HOST', 'hrindexx.com');
define('SMTP_PORT', 465);
define('SMTP_ENCRYPTION', 'ssl');

define('MAIL_FROM_NAME', 'HRI Mail');
define('MAIL_DOMAIN', 'hrindexx.com');

// Whether to verify the mail server's SSL certificate.
// Set to true after installing a valid Let's Encrypt cert on cPanel for hrindexx.com.
// Affects both outgoing SMTP (sendSystemMail) and outgoing compose (api/mail/send.php).
define('SMTP_VERIFY_SSL', false);

// Build IMAP connection string for a user
function imapString($folder = 'INBOX') {
    $flags = '/imap/ssl/novalidate-cert';
    return '{' . IMAP_HOST . ':' . IMAP_PORT . $flags . '}' . $folder;
}

// Shared transactional mailer — used by leave.php, broadcast.php, and any
// server-initiated notification. Sends FROM noreply@hrindexx.com via SMTP.
// Returns true on success, false on failure (never throws).
function sendSystemMail(string $to, string $toName, string $subject, string $body): bool {
    $autoload = __DIR__ . '/../lib/vendor/autoload.php';
    if (!file_exists($autoload)) {
        $headers = "From: " . MAIL_FROM_NAME . " <" . NOTIFY_MAIL_USER . ">\r\n"
                 . "Reply-To: " . NOTIFY_MAIL_USER . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";
        return @mail($to, $subject, $body, $headers);
    }
    require_once $autoload;
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = NOTIFY_MAIL_USER;
        $mail->Password   = NOTIFY_MAIL_PASS;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        if (!SMTP_VERIFY_SSL) {
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }

        $mail->setFrom(NOTIFY_MAIL_USER, MAIL_FROM_NAME);
        $mail->addAddress($to, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(false);
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log("sendSystemMail to $to failed: " . $e->getMessage());
        return false;
    }
}
