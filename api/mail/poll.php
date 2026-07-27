<?php
// api/mail/poll.php — Real-time inbox polling (lightweight)
// Called every 30 seconds by the JS client
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/ImapHelper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = Auth::require();
$mp   = $_SESSION['mail_pass'] ?? '';
$db   = getDB();

if (!$mp) {
    echo json_encode(['ok'=>false,'error'=>'no_session']);
    exit;
}

$lastUnread = (int)($_GET['last']    ?? -1);
$lastUid    = (int)($_GET['lastUid'] ?? 0);

try {
    @imap_timeout(IMAP_OPENTIMEOUT, 5);
    @imap_timeout(IMAP_READTIMEOUT, 8);

    $mbox = @imap_open(
        '{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}INBOX',
        $user['email'], $mp, 0, 1,
        ['DISABLE_AUTHENTICATOR'=>'GSSAPI']
    );

    if (!$mbox) {
        echo json_encode(['ok'=>false,'error'=>'imap_failed']);
        exit;
    }

    $total  = imap_num_msg($mbox);
    $unseen = @imap_search($mbox, 'UNSEEN') ?: [];
    $unread = count($unseen);

    // Always fetch last 3 messages — gives us maxUid + data for OOO/filter processing
    $maxUid = 0;
    $ovs    = [];
    if ($total > 0) {
        $startSeq = max(1, $total - 2);
        $ovs = @imap_fetch_overview($mbox, "{$startSeq}:{$total}") ?: [];
        foreach ($ovs as $o) {
            if (((int)($o->uid ?? 0)) > $maxUid) $maxUid = (int)$o->uid;
        }
    }

    // UID-based detection (primary — reliable regardless of read/unread state)
    $hasNew = ($lastUid > 0 && $maxUid > $lastUid);
    // Count-based fallback on first poll when lastUid is not yet known
    if (!$hasNew && $lastUid === 0) {
        $hasNew = ($lastUnread >= 0 && $unread > $lastUnread);
    }

    $newMails = [];
    if ($hasNew && !empty($ovs)) {
        foreach (array_reverse($ovs) as $o) {
            if ($lastUid > 0 && ((int)($o->uid ?? 0)) <= $lastUid) continue;
            $from = $o->from ?? '';
            if (preg_match('/"?([^"<]+)"?\s*<?/', $from, $m)) $from = trim($m[1], '" ');
            $newMails[] = [
                'uid'  => $o->uid ?? $o->msgno,
                'from' => mb_substr(imap_utf8($from), 0, 30),
                'subj' => mb_substr(imap_utf8($o->subject ?? '(no subject)'), 0, 60),
                'time' => $o->date ? date('H:i', strtotime($o->date)) : date('H:i'),
            ];
            if (count($newMails) >= 3) break;
        }
    }

    // OOO auto-reply: queue replies for new messages if user has active OOO
    if ($hasNew && isset($ovs) && !empty($ovs)) {
        $oooStmt = $db->prepare("SELECT * FROM ooo_settings WHERE user_id=? AND is_enabled=1");
        $oooStmt->execute([$user['id']]);
        $ooo = $oooStmt->fetch();
        if ($ooo) {
            $today = date('Y-m-d');
            foreach ($ovs as $ov) {
                $rawFrom = $ov->from ?? '';
                if (preg_match('/<([^>]+)>/', $rawFrom, $em)) {
                    $senderEmail = strtolower(trim($em[1]));
                } else {
                    $senderEmail = strtolower(trim($rawFrom));
                }
                if (!$senderEmail || strpos($senderEmail, '@') === false) continue;
                if ($senderEmail === strtolower($user['email'])) continue;
                if (preg_match('/no.?reply|mailer.?daemon|postmaster/i', $senderEmail)) continue;

                $chk = $db->prepare("SELECT id FROM ooo_replies_sent WHERE ooo_user_id=? AND sender_email=? AND replied_date=?");
                $chk->execute([$user['id'], $senderEmail, $today]);
                if ($chk->fetch()) continue;

                $inSubject   = imap_utf8($ov->subject ?? '(no subject)');
                $replySubject = $ooo['subject'] . ' (Re: ' . mb_substr($inSubject, 0, 60) . ')';
                $db->prepare("INSERT INTO email_queue (user_id, to_email, subject, body, send_at, sent)
                              VALUES (?, ?, ?, ?, NOW(), 0)")
                   ->execute([$user['id'], $senderEmail, $replySubject, $ooo['message']]);

                $db->prepare("INSERT IGNORE INTO ooo_replies_sent (ooo_user_id, sender_email, replied_date) VALUES (?,?,?)")
                   ->execute([$user['id'], $senderEmail, $today]);
            }
        }
    }

    // Email filter rules: apply to new messages
    if ($hasNew && isset($ovs) && !empty($ovs)) {
        $filterStmt = $db->prepare("SELECT * FROM email_filters WHERE user_id=? AND is_active=1 ORDER BY sort_order, id");
        $filterStmt->execute([$user['id']]);
        $filterRules = $filterStmt->fetchAll();

        if (!empty($filterRules)) {
            $needsBody = false;
            foreach ($filterRules as $fr) {
                if ($fr['field'] === 'body') { $needsBody = true; break; }
            }
            $expungeNeeded = false;

            foreach ($ovs as $ov) {
                $msgno = $ov->msgno;
                $processed = false;
                $bodyText  = null;

                $fieldValues = [
                    'from'    => imap_utf8($ov->from ?? ''),
                    'to'      => imap_utf8($ov->to ?? ''),
                    'subject' => imap_utf8($ov->subject ?? ''),
                ];
                if ($needsBody) {
                    $bodyText = @imap_fetchbody($mbox, $msgno, '1') ?: @imap_body($mbox, $msgno) ?: '';
                    $fieldValues['body'] = $bodyText;
                }

                foreach ($filterRules as $rule) {
                    if ($processed) break;
                    $fval = $fieldValues[$rule['field']] ?? '';
                    $rval = $rule['value'];
                    $match = false;
                    switch ($rule['operator']) {
                        case 'contains':    $match = (stripos($fval, $rval) !== false); break;
                        case 'equals':      $match = (strtolower($fval) === strtolower($rval)); break;
                        case 'starts_with': $match = (stripos($fval, $rval) === 0); break;
                        case 'ends_with':   $match = (substr_compare(strtolower($fval), strtolower($rval), -strlen($rval)) === 0); break;
                    }
                    if (!$match) continue;

                    switch ($rule['action']) {
                        case 'star':
                            @imap_setflag_full($mbox, (string)$msgno, '\\Flagged');
                            break;
                        case 'mark_read':
                            @imap_setflag_full($mbox, (string)$msgno, '\\Seen');
                            break;
                        case 'move':
                            $dest = $rule['action_param'] ?: 'INBOX';
                            @imap_mail_move($mbox, (string)$msgno, $dest);
                            $expungeNeeded = true;
                            $processed = true;
                            break;
                        case 'delete':
                            @imap_mail_move($mbox, (string)$msgno, 'INBOX.Trash');
                            $expungeNeeded = true;
                            $processed = true;
                            break;
                    }
                }
            }
            if ($expungeNeeded) @imap_expunge($mbox);
        }
    }

    imap_close($mbox);

    echo json_encode([
        'ok'      => true,
        'unread'  => $unread,
        'total'   => $total,
        'maxUid'  => $maxUid,
        'hasNew'  => $hasNew,
        'newMails'=> $newMails,
        'ts'      => time(),
    ]);

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
