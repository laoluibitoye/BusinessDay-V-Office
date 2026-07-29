<?php
// api/mail/check.php — Real-time inbox check (lightweight polling)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/ImapHelper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$user = Auth::require();
$mp   = Auth::mailPass();

// Lock released before the IMAP work — see api/mail/poll.php for why.
session_write_close();

if (!$mp) {
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

// Get last known unread count from client
$lastCount = (int)($_GET['last'] ?? -1);

try {
    @imap_timeout(IMAP_OPENTIMEOUT, 5);
    @imap_timeout(IMAP_READTIMEOUT, 8);

    $conn = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}INBOX';
    $mbox = @imap_open($conn, $user['email'], $mp, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

    if (!$mbox) {
        echo json_encode(['ok' => false, 'error' => 'imap_failed']);
        exit;
    }

    $total        = imap_num_msg($mbox);
    $unseenArr    = @imap_search($mbox, 'UNSEEN') ?: [];
    $unreadCount  = count($unseenArr);
    $hasNew       = ($lastCount >= 0 && $unreadCount > $lastCount);

    // Get newest message info if there are new ones
    $newMails = [];
    if ($hasNew && !empty($unseenArr)) {
        // Get the most recent unseen messages (up to 3)
        $recentUids = array_slice(array_reverse($unseenArr), 0, 3);
        $ovs = @imap_fetch_overview($mbox, implode(',', $recentUids), FT_UID) ?: [];
        foreach ($ovs as $o) {
            $from = $o->from ?? '';
            // Clean from name
            if (preg_match('/"?([^"<]+)"?\s*<?/', $from, $m)) $from = trim($m[1], '" ');
            $newMails[] = [
                'uid'     => $o->uid ?? 0,
                'from'    => mb_substr(imap_utf8($from), 0, 40),
                'subject' => mb_substr(ImapHelper::decodeSubject($o->subject ?? '(no subject)'), 0, 60),
                'time'    => isset($o->date) ? date('H:i', strtotime($o->date)) : '',
            ];
        }
    }

    imap_close($mbox);

    // Track usage
    Auth::trackUsage($user['id'], 'inbox_checks');

    echo json_encode([
        'ok'      => true,
        'unread'  => $unreadCount,
        'total'   => $total,
        'has_new' => $hasNew,
        'new'     => $newMails,
        'ts'      => time(),
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
