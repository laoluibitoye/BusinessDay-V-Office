<?php
// api/mail/unread-count.php — Fast unread count for polling
// Called every 30s by layout_shell.php JS
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Full session validation without a redirect. This previously checked only
// that $_SESSION['user_id'] was set, so a session killed from Active Sessions
// carried on polling; apiUser() verifies the session row, its expiry, the
// account being active, and the idle timeout.
$u = Auth::apiUser();
if (!$u) {
    echo json_encode(['error' => 'unauthenticated', 'count' => 0]);
    exit;
}

$mp = Auth::mailPass();
if ($mp === '') {
    echo json_encode(['error' => 'unauthenticated', 'count' => 0]);
    exit;
}

// Release the lock before the IMAP work — see api/mail/poll.php. apiUser()
// has already written last_active, so nothing below touches $_SESSION.
$sessToken = $_SESSION['token'] ?? '';
session_write_close();

$db = getDB();

// Fast IMAP connection - just get unread count
try {
    @imap_timeout(IMAP_OPENTIMEOUT, 5);
    @imap_timeout(IMAP_READTIMEOUT, 8);
    $conn = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}INBOX';
    $mbox = @imap_open($conn, $u['email'], $mp, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    if (!$mbox) {
        echo json_encode(['count' => 0, 'error' => 'imap_failed']);
        exit;
    }
    $unread = imap_num_recent($mbox); // Fast - just recent/unseen count
    // Fallback to search if recent returns 0 but there are messages
    if ($unread === 0) {
        $unseen = @imap_search($mbox, 'UNSEEN') ?: [];
        $unread = count($unseen);
    }
    imap_close($mbox);
    
    // Update last_active in sessions
    $db->prepare("UPDATE sessions SET last_active=NOW() WHERE user_id=? AND token=?")
       ->execute([$u['id'], $sessToken]);
    
    echo json_encode([
        'count'   => (int)$unread,
        'user'    => $u['name'],
        'checked' => date('H:i:s'),
    ]);
} catch (Exception $e) {
    echo json_encode(['count' => 0, 'error' => 'exception']);
}
