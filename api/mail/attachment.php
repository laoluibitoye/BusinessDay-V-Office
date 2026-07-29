<?php
// api/mail/attachment.php — Serve email attachments (Security Hardened)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/ImapHelper.php';

$user = Auth::require();
$mp   = Auth::mailPass();

// Strict parameter validation
$uid     = Auth::sanitiseInt($_GET['uid']    ?? 0);
$partNum = preg_replace('/[^0-9.]/', '', $_GET['part'] ?? '');
$folder  = Auth::sanitiseString($_GET['folder'] ?? 'INBOX', 50);
$fname   = Auth::sanitiseString(basename($_GET['name'] ?? 'attachment'), 255);
$fname   = preg_replace('/[^a-zA-Z0-9._\-\s]/', '_', $fname);
$inline  = isset($_GET['inline']);

if (!$uid || !$partNum || strlen($partNum) > 20) {
    http_response_code(400);
    exit('Invalid request.');
}

// Whitelist folder names
$allowedFolders = ['INBOX','INBOX.Sent','INBOX.Trash','INBOX.Drafts','INBOX.Junk E-mail','Sent','Trash','Drafts'];
if (!in_array($folder, $allowedFolders)) $folder = 'INBOX';

if (!$mp) {
    http_response_code(401);
    exit('Session expired. Please log in again.');
}

try {
    $mbox = ImapHelper::open($user['email'], $mp, $folder);
    if (!$mbox) {
        http_response_code(503);
        exit('Could not connect to mailbox.');
    }

    $msgNo = imap_msgno($mbox, $uid);
    if (!$msgNo) {
        http_response_code(404);
        exit('Message not found.');
    }

    $raw    = imap_fetchbody($mbox, $msgNo, $partNum);
    $struct = imap_fetchstructure($mbox, $msgNo);
    imap_close($mbox);

    // Decode based on encoding
    function getPartEncoding($struct, $partNum) {
        $parts   = explode('.', $partNum);
        $current = $struct;
        foreach ($parts as $p) {
            $idx = (int)$p - 1;
            if (isset($current->parts[$idx])) $current = $current->parts[$idx];
        }
        return $current->encoding ?? 0;
    }
    $enc = getPartEncoding($struct, $partNum);
    switch ($enc) {
        case 3: $data = base64_decode($raw); break;
        case 4: $data = quoted_printable_decode($raw); break;
        default: $data = $raw;
    }

    // Determine MIME type from content (not just extension — more secure)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($data);

    // Previewable types (safe to display inline)
    $previewable = [
        'application/pdf' => true,
        'image/jpeg'      => true,
        'image/png'       => true,
        'image/gif'       => true,
        'image/webp'      => true,
        'text/plain'      => true,
    ];

    // Log access
    Auth::auditLog($user['id'], 'attachment_accessed', "$fname from UID $uid");

    // Send with appropriate headers
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    if ($inline && isset($previewable[$mime])) {
        header('Content-Disposition: inline; filename="' . $fname . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $fname . '"');
    }

    // Block dangerous types from inline display
    if (in_array($mime, ['text/html', 'application/javascript', 'text/javascript'])) {
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('X-Download-Options: noopen');
    }

    echo $data;

} catch (Exception $e) {
    http_response_code(500);
    exit('Error serving attachment.');
}
