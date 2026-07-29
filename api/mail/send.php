<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// api/mail/send.php — Send email via SMTP (Security Hardened)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');

// Auth required (Auth::require() validates CSRF internally for all POST requests)
$user = Auth::require();
$db   = getDB();
$mp   = Auth::mailPass();

// Input validation
$to      = Auth::sanitiseString($_POST['to']      ?? '', 500);
$subject = Auth::sanitiseString($_POST['subject'] ?? '', 255);
$body    = trim($_POST['body']    ?? '');
$cc      = Auth::sanitiseString($_POST['cc']      ?? '', 500);
$bcc     = Auth::sanitiseString($_POST['bcc']     ?? '', 500);

if (!$to) {
    echo json_encode(['ok'=>false,'error'=>'Recipient required.']);
    exit;
}
if (!$subject) {
    echo json_encode(['ok'=>false,'error'=>'Subject required.']);
    exit;
}
if (strlen($body) > 500000) { // 500KB body limit
    echo json_encode(['ok'=>false,'error'=>'Message body is too large.']);
    exit;
}

// Auto-save external recipients as contacts after successful send
function _autoSaveContacts(PDO $db, int $userId, string $to, string $cc): void {
    $raw = array_filter(array_map('trim', explode(',', $to . ',' . $cc)));
    $stmt = $db->prepare("INSERT IGNORE INTO contacts (user_id, name, email, `group`) VALUES (?, ?, ?, 'general')");
    foreach ($raw as $addr) {
        $email = strtolower(trim(preg_replace('/.*<(.+?)>/', '$1', $addr)));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if (str_ends_with($email, '@hrindexx.com')) continue;
        $name = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
        try { $stmt->execute([$userId, $name, $email]); } catch (Exception $e) {}
    }
}

// Validate all recipient addresses
function validateEmails(string $addresses): bool {
    foreach (explode(',', $addresses) as $addr) {
        $addr = trim($addr);
        if ($addr && !filter_var($addr, FILTER_VALIDATE_EMAIL)) return false;
    }
    return true;
}
if (!validateEmails($to) || ($cc && !validateEmails($cc)) || ($bcc && !validateEmails($bcc))) {
    echo json_encode(['ok'=>false,'error'=>'One or more email addresses are invalid.']);
    exit;
}

// Attachment size and type validation
$_maxBytes = defined('MAX_UPLOAD_BYTES') ? MAX_UPLOAD_BYTES : 25 * 1024 * 1024;
$_maxMb    = defined('MAX_UPLOAD_MB')    ? MAX_UPLOAD_MB    : 25;
$_allowedTypes = defined('ALLOWED_ATTACHMENT_TYPES') ? ALLOWED_ATTACHMENT_TYPES : [
    'application/pdf','application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg','image/png','image/gif','image/webp',
    'text/plain','text/csv','application/zip',
];
if (!empty($_FILES['attachments']['tmp_name'])) {
    $totalSize = 0;
    foreach ($_FILES['attachments']['tmp_name'] as $i => $tmp) {
        if (!$tmp || $_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $totalSize += $_FILES['attachments']['size'][$i];
        if ($totalSize > $_maxBytes) {
            echo json_encode(['ok'=>false,'error'=>'Attachments exceed '.$_maxMb.'MB limit.']);
            exit;
        }
        // MIME type check using finfo (more reliable than extension)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp);
        if (!in_array($mime, $_allowedTypes)) {
            $name = basename($_FILES['attachments']['name'][$i]);
            echo json_encode(['ok'=>false,'error'=>"File type not allowed: $name ($mime)"]);
            exit;
        }
    }
}

// Send
$mailerAvailable = file_exists(__DIR__ . '/../../lib/vendor/autoload.php');
if ($mailerAvailable) require_once __DIR__ . '/../../lib/vendor/autoload.php';

if ($mailerAvailable) {
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = SMTP_HOST;
        $mail->SMTPAuth    = true;
        $mail->Username    = $user['email'];
        $mail->Password    = $mp;
        $mail->SMTPSecure  = SMTP_ENCRYPTION;
        $mail->Port        = SMTP_PORT;
        if (!SMTP_VERIFY_SSL) {
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }

        $mail->setFrom($user['email'], $user['name']);
        foreach (explode(',', $to) as $t) {
            $t = trim($t);
            if ($t && filter_var($t, FILTER_VALIDATE_EMAIL)) $mail->addAddress($t);
        }
        if ($cc) foreach (explode(',', $cc) as $c) {
            $c = trim($c);
            if ($c && filter_var($c, FILTER_VALIDATE_EMAIL)) $mail->addCC($c);
        }
        if ($bcc) foreach (explode(',', $bcc) as $b) {
            $b = trim($b);
            if ($b && filter_var($b, FILTER_VALIDATE_EMAIL)) $mail->addBCC($b);
        }

        // Attachments
        if (!empty($_FILES['attachments']['tmp_name'])) {
            foreach ($_FILES['attachments']['tmp_name'] as $i => $tmp) {
                if ($tmp && $_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                    $safeName = basename($_FILES['attachments']['name'][$i]);
                    $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $safeName);
                    $mail->addAttachment($tmp, $safeName);
                }
            }
        }

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>', '</li>'],
            "\n", $body
        ));
        $mail->CharSet = 'UTF-8';
        $mail->send();

        // Save copy to IMAP Sent folder so it appears in Sent without server-side Dovecot copy
        if ($mp) {
            try {
                $sentPath = '{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}INBOX.Sent';
                $sentConn = @imap_open($sentPath, $user['email'], $mp, 0, 1, ['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
                if ($sentConn) {
                    @imap_append($sentConn, $sentPath, $mail->getSentMIMEMessage(), '\\Seen');
                    @imap_close($sentConn);
                }
            } catch (Exception $ex) {} // non-fatal — message was already sent
        }

        Auth::trackUsage($user['id'], 'emails_sent');
        Auth::auditLog($user['id'], 'email_sent', "To: $to | Subject: $subject");
        _autoSaveContacts($db, $user['id'], $to, $cc);
        echo json_encode(['ok' => true]);

    } catch (Exception $e) {
        $err = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
        Auth::auditLog($user['id'], 'email_send_failed', "Error: $err | To: $to");
        echo json_encode(['ok' => false, 'error' => 'Send failed: ' . $err]);
    }

} else {
    // PHP mail() fallback with HTML
    $headers  = "From: {$user['name']} <{$user['email']}>\r\n";
    $headers .= "Reply-To: {$user['email']}\r\n";
    if ($cc) $headers .= "Cc: $cc\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    if (@mail($to, $subject, $body, $headers)) {
        Auth::trackUsage($user['id'], 'emails_sent');
        Auth::auditLog($user['id'], 'email_sent', "To: $to | Subject: $subject (php_mail)");
        _autoSaveContacts($db, $user['id'], $to, $cc);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'PHPMailer not installed. Install it to send mail reliably.']);
    }
}
