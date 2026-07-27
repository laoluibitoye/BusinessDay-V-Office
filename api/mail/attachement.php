<?php
// api/mail/attachment.php — Serve email attachment for preview or download
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/ImapHelper.php';

$user = Auth::require();
$mp   = $_SESSION['mail_pass'] ?? '';

if (!$mp) { http_response_code(401); die('Session expired — please log in again.'); }

$uid     = (int)($_GET['uid']    ?? 0);
$partNum = preg_replace('/[^0-9.]/', '', $_GET['part'] ?? '');
$folder  = $_GET['folder']  ?? 'INBOX';
$inline  = isset($_GET['inline']);
$fname   = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', basename($_GET['name'] ?? 'attachment'));

if (!$uid || !$partNum) { http_response_code(400); die('Missing parameters.'); }

try {
    $mbox = ImapHelper::open($user['email'], $mp, $folder);
    if (!$mbox) { http_response_code(503); die('Mailbox unavailable: ' . imap_last_error()); }

    $msgNo = imap_msgno($mbox, $uid);
    if (!$msgNo) { http_response_code(404); die('Message not found.'); }

    // Fetch raw part
    $raw    = imap_fetchbody($mbox, $msgNo, $partNum);
    $struct = imap_fetchstructure($mbox, $msgNo);
    imap_close($mbox);

    // Find encoding for this part
    function getPartEnc($struct, $partNum) {
        if (strpos($partNum, '.') === false) {
            $idx = (int)$partNum - 1;
            return $struct->parts[$idx]->encoding ?? $struct->encoding ?? 0;
        }
        $parts   = explode('.', $partNum);
        $current = $struct;
        foreach ($parts as $p) {
            $idx = (int)$p - 1;
            if (isset($current->parts[$idx])) $current = $current->parts[$idx];
        }
        return $current->encoding ?? 0;
    }

    $enc = getPartEnc($struct, $partNum);
    switch ($enc) {
        case 3: $data = base64_decode($raw); break;
        case 4: $data = quoted_printable_decode($raw); break;
        default: $data = $raw;
    }

    // MIME type map
    $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    $mimes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',  'gif'  => 'image/gif',
        'webp' => 'image/webp', 'svg'  => 'image/svg+xml',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt'  => 'text/plain', 'csv' => 'text/csv',
        'zip'  => 'application/zip', 'rar' => 'application/x-rar',
        'eml'  => 'message/rfc822',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    $previewable = ['application/pdf','image/jpeg','image/png','image/gif','image/webp','image/svg+xml','text/plain'];

    Auth::auditLog($user['id'], 'attachment_download', "$fname (UID:$uid)");

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    if ($inline && in_array($mime, $previewable)) {
        header('Content-Disposition: inline; filename="' . $fname . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $fname . '"');
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    echo $data;

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error serving attachment: ' . htmlspecialchars($e->getMessage());
}
