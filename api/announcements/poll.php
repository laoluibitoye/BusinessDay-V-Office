<?php
/**
 * api/announcements/poll.php — lightweight check for announcements the user
 * has not seen yet.
 *
 * An announcement posted while someone is already signed in used to be
 * invisible until they happened to reload a page. The shell polls this every
 * 60 seconds and raises a toast, so it reaches people wherever they are in the
 * platform.
 *
 * GET ?after=<id>  — highest announcement id the client has already shown.
 * Returns only newer ones, so the payload is empty in the normal case.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = Auth::apiUser();
if (!$user) { echo json_encode(['ok' => false, 'error' => 'unauthenticated']); exit; }

// Nothing below writes to the session — release the lock before querying so a
// 60s poll never serialises against the user's other requests.
session_write_close();

$after = max(0, (int)($_GET['after'] ?? 0));

try {
    $db = getDB();
    $st = $db->prepare(
        "SELECT id, title, body, priority, created_at
         FROM announcements
         WHERE is_active = 1
           AND id > ?
           AND (expires_at IS NULL OR expires_at > NOW())
           AND (target_role IS NULL OR target_role = '' OR target_role = 'all' OR target_role = ?)
         ORDER BY id ASC
         LIMIT 5");
    $st->execute([$after, $user['role']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $txt = trim(strip_tags((string)($r['body'] ?? '')));
        $out[] = [
            'id'       => (int)$r['id'],
            'title'    => (string)($r['title'] ?? 'Announcement'),
            'body'     => mb_substr($txt, 0, 180) . (mb_strlen($txt) > 180 ? '…' : ''),
            'priority' => (string)($r['priority'] ?? 'normal'),
        ];
    }

    // Highest id currently visible to this user — the client stores it so a
    // first-ever visit does not replay the entire history as toasts.
    $maxSt = $db->prepare(
        "SELECT COALESCE(MAX(id),0) FROM announcements
         WHERE is_active = 1
           AND (expires_at IS NULL OR expires_at > NOW())
           AND (target_role IS NULL OR target_role = '' OR target_role = 'all' OR target_role = ?)");
    $maxSt->execute([$user['role']]);

    echo json_encode(['ok' => true, 'items' => $out, 'max' => (int)$maxSt->fetchColumn()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'query_failed']);
}
