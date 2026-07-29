<?php
// api/mail/action.php — Mail actions: read, unread, star, unstar, move, draft-save
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/ImapHelper.php';

header('Content-Type: application/json');
$user = Auth::require();
$mp   = Auth::mailPass();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid    = Auth::sanitiseInt($_POST['uid'] ?? $_GET['uid'] ?? 0);
$folder = Auth::sanitiseString($_POST['folder'] ?? $_GET['folder'] ?? 'INBOX', 60);

// Allow standard folders plus any custom INBOX.* folder
$stdAllowed = ['INBOX','INBOX.Sent','INBOX.Trash','INBOX.Drafts','INBOX.Junk E-mail','Sent','Trash','Drafts','Spam'];
if (!in_array($folder, $stdAllowed) && !preg_match('/^INBOX\.[A-Za-z0-9_\- ]+$/', $folder)) {
    $folder = 'INBOX';
}

if (!$mp || !$uid || !$action) {
    echo json_encode(['ok'=>false,'error'=>'Missing parameters']); exit;
}

try {
    $mbox = ImapHelper::open($user['email'], $mp, $folder);
    if (!$mbox) { echo json_encode(['ok'=>false,'error'=>'Cannot connect to mailbox']); exit; }

    switch ($action) {

        case 'mark_read':
            imap_setflag_full($mbox, (string)$uid, '\\Seen', ST_UID);
            break;

        case 'mark_unread':
            imap_clearflag_full($mbox, (string)$uid, '\\Seen', ST_UID);
            break;

        case 'star':
            imap_setflag_full($mbox, (string)$uid, '\\Flagged', ST_UID);
            break;

        case 'unstar':
            imap_clearflag_full($mbox, (string)$uid, '\\Flagged', ST_UID);
            break;

        case 'move':
            $dest = Auth::sanitiseString($_POST['dest'] ?? '', 80);
            $destOk = in_array($dest, $stdAllowed) || preg_match('/^INBOX\.[A-Za-z0-9_\- ]+$/', $dest);
            if (!$destOk) {
                echo json_encode(['ok'=>false,'error'=>'Invalid destination']);
                imap_close($mbox); exit;
            }
            // Resolve short names (Sent, Trash, etc.) to full IMAP paths; custom INBOX.* are already full paths
            $destFolder = (strpos($dest,'INBOX') === 0) ? $dest : ImapHelper::resolveFolder($user['email'], $mp, $dest);
            @imap_mail_move($mbox, (string)$uid, $destFolder, CP_UID);
            imap_expunge($mbox);
            break;

        case 'save_draft':
            // Save draft to IMAP Drafts folder (Sprint 1 ⑥)
            $subject  = $_POST['subject'] ?? '(no subject)';
            $to       = $_POST['to']      ?? '';
            $body     = $_POST['body']    ?? '';
            $draftMsg = "From: {$user['name']} <{$user['email']}>\r\n"
                      . "To: $to\r\n"
                      . "Subject: $subject\r\n"
                      . "Date: " . date('r') . "\r\n"
                      . "MIME-Version: 1.0\r\n"
                      . "Content-Type: text/html; charset=UTF-8\r\n"
                      . "X-Mailer: HRI Mail\r\n"
                      . "\r\n"
                      . $body;
            imap_close($mbox);
            $draftFolder = ImapHelper::resolveFolder($user['email'], $mp, 'Drafts');
            $draftConn   = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}' . $draftFolder;
            $dm = @imap_open($draftConn, $user['email'], $mp, 0, 1, ['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
            if ($dm) {
                $appended = imap_append($dm, $draftConn, $draftMsg, '\\Draft \\Seen');
                imap_close($dm);
                echo json_encode(['ok'=>$appended!==false]);
            } else {
                echo json_encode(['ok'=>false,'error'=>'Cannot open Drafts folder']);
            }
            exit;

        case 'vault':
            // Save email to document vault
            require_once __DIR__ . '/../../lib/ImapHelper.php';
            $dir = __DIR__ . '/../../uploads/vault/' . $user['id'] . '/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Fetch the raw email
            $msgNo = imap_msgno($mbox, $uid);
            if (!$msgNo) {
                imap_close($mbox);
                echo json_encode(['ok'=>false,'error'=>'Email not found']);
                exit;
            }
            $raw = imap_fetchheader($mbox, $msgNo) . imap_body($mbox, $msgNo);

            // Get subject for filename
            $headers  = imap_headerinfo($mbox, $msgNo);
            $subject  = isset($headers->subject) ? imap_utf8($headers->subject) : 'email';
            $subject  = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $subject);
            $subject  = trim(substr($subject, 0, 60)) ?: 'email';
            $fname    = date('Ymd-His') . '-' . $uid . '-' . str_replace(' ', '_', $subject) . '.eml';

            file_put_contents($dir . $fname, $raw);

            // Save record to DB
            try {
                $db = getDB();
                $db->prepare("INSERT IGNORE INTO vault_files 
                    (user_id, filename, stored_name, file_path, category, source, mime_type, created_at)
                    VALUES (?,?,?,?,?,?,?,NOW())")
                   ->execute([
                       $user['id'],
                       $subject . '.eml',
                       $fname,
                       'uploads/vault/' . $user['id'] . '/' . $fname,
                       'email',
                       'inbox',
                       'message/rfc822'
                   ]);
            } catch (Exception $e) {}

            imap_close($mbox);
            Auth::auditLog($user['id'], 'vault_save', "UID $uid from $folder");
            echo json_encode(['ok'=>true, 'message'=>'Email saved to Vault']);
            exit;

        default:
            imap_close($mbox);
            echo json_encode(['ok'=>false,'error'=>"Unknown action: $action"]);
            exit;
    }

    imap_close($mbox);
    echo json_encode(['ok'=>true]);

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
