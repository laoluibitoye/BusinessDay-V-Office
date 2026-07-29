<?php
// api/mail/preview.php
// Returns last 5 inbox emails as JSON for dashboard widget

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');

$user = Auth::require();

$avatarColors = ['#002850','#64A014','#8b5cf6','#f97316','#3b82f6','#f59e0b','#dc2626','#0891b2'];

try {
    $mailbox = imap_open(
        imapString('INBOX'),
        $user['email'],
        // NOTE: Staff log in with their cPanel email password
        // Password must be passed from session — stored encrypted at login
        // For Phase 1 we read from session
        Auth::mailPass(),
        0, 1,
        ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
    );

    if (!$mailbox) {
        echo json_encode(['error' => 'Could not connect to mailbox']);
        exit;
    }

    $emails = imap_search($mailbox, 'ALL', SE_UID);
    if (!$emails) { echo json_encode([]); exit; }

    rsort($emails);
    $emails = array_slice($emails, 0, 5);
    $result = [];

    foreach ($emails as $uid) {
        $header = imap_fetchstructure($mailbox, $uid, FT_UID);
        $overview = imap_fetch_overview($mailbox, $uid, FT_UID);
        if (empty($overview)) continue;
        $ov = $overview[0];

        $fromName  = isset($ov->from) ? imap_utf8($ov->from) : 'Unknown';
        $fromName  = preg_replace('/<.*?>/', '', $fromName);
        $fromName  = trim($fromName, ' "\'');
        $subject   = isset($ov->subject) ? imap_utf8($ov->subject) : '(no subject)';
        $date      = isset($ov->date) ? date('H:i', strtotime($ov->date)) : '';
        $initials  = strtoupper(substr($fromName, 0, 1) . (strpos($fromName, ' ') !== false ? substr($fromName, strpos($fromName, ' ') + 1, 1) : ''));
        $color     = $avatarColors[ord($initials[0] ?? 'A') % count($avatarColors)];

        $result[] = [
            'uid'       => $uid,
            'from_name' => $fromName,
            'subject'   => $subject,
            'time'      => $date,
            'unread'    => !$ov->seen,
            'initials'  => $initials ?: '?',
            'color'     => $color,
        ];

        // Cache in DB
        $db = getDB();
        $db->prepare("INSERT INTO mail_cache (user_id, uid, folder, from_name, subject, is_read, date_sent)
                      VALUES (?, ?, 'INBOX', ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE from_name=VALUES(from_name), subject=VALUES(subject), is_read=VALUES(is_read)")
           ->execute([$user['id'], $uid, $fromName, $subject, $ov->seen ? 1 : 0, date('Y-m-d H:i:s', strtotime($ov->date ?? 'now'))]);
    }

    imap_close($mailbox);
    Auth::trackUsage($user['id'], 'emails_read', 0); // just a page view, not a read

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
