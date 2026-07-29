<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// search.php — Full IMAP email search (Sprint 1 ①)
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/ImapHelper.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();
$mp   = Auth::mailPass();

$q       = trim($_GET['q'] ?? '');
$folder  = $_GET['folder'] ?? 'ALL'; // ALL = search all folders
$results = [];
$error   = '';
$searched = false;

if ($q && $mp) {
    $searched = true;
    $searchFolders = $folder === 'ALL'
        ? ['INBOX', 'Sent', 'Drafts', 'Trash']
        : [$folder];

    foreach ($searchFolders as $sf) {
        try {
            @imap_timeout(1, 8);
            @imap_timeout(2, 10);
            $mbox = ImapHelper::open($user['email'], $mp, $sf);
            if (!$mbox) continue;

            // Build IMAP search string
            // Search subject, from, body - IMAP OR combines criteria
            $sq = mb_convert_encoding($q, 'UTF-8');
            $searchStr = 'OR OR SUBJECT "' . addslashes($sq) . '" FROM "' . addslashes($sq) . '" BODY "' . addslashes($sq) . '"';
            $msgs = @imap_search($mbox, $searchStr, SE_UID) ?: [];

            // Also search with simpler TEXT fallback
            if (empty($msgs)) {
                $msgs = @imap_search($mbox, 'TEXT "' . addslashes($sq) . '"', SE_UID) ?: [];
            }

            if (!empty($msgs)) {
                $msgs = array_slice(array_reverse($msgs), 0, 30);
                $ovs  = @imap_fetch_overview($mbox, implode(',', $msgs), FT_UID) ?: [];
                foreach ($ovs as $o) {
                    $from = $o->from ?? '';
                    if (preg_match('/"?([^"<]+)"?\s*<([^>]+)>/', $from, $m)) {
                        $fromName  = trim($m[1], '" ');
                        $fromEmail = trim($m[2]);
                    } else {
                        $fromName  = $from;
                        $fromEmail = $from;
                    }
                    $ini = strtoupper(substr(strip_tags($fromName ?: $fromEmail), 0, 1));
                    $results[] = [
                        'uid'        => $o->uid ?? $o->msgno,
                        'folder'     => $sf,
                        'from'       => imap_utf8($fromName ?: $fromEmail),
                        'from_email' => $fromEmail,
                        'ini'        => $ini,
                        'subject'    => imap_utf8($o->subject ?? '(no subject)'),
                        'date'       => $o->date ?? '',
                        'time'       => $o->date ? date('d M Y H:i', strtotime($o->date)) : '',
                        'seen'       => !empty($o->seen),
                        'flagged'    => !empty($o->flagged),
                    ];
                }
            }
            imap_close($mbox);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    // Sort all results by date desc
    usort($results, function($a,$b){ return strtotime($b['date']) - strtotime($a['date']); });
    $results = array_slice($results, 0, 100);
    Auth::auditLog($user['id'], 'email_search', "Query: $q Results: " . count($results));
}

$avColors = ['#002850','#64A014','#8b5cf6','#f97316','#3b82f6','#f59e0b','#0891b2','#dc2626'];
function av($str) { global $avColors; $idx=abs(crc32($str))%count($avColors); return $avColors[$idx]; }

Layout::shell($user, 'search', 0, 'Search');
?>
<div class="hri-page">

<!-- Search Header -->
<div style="max-width:720px;margin:0 auto 24px;">
    <form method="GET" action="/search.php" style="display:flex;gap:10px;align-items:center;">
        <div style="flex:1;position:relative;">
            <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--g400);">🔍</span>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                   placeholder="Search emails — subject, sender, body..."
                   autofocus
                   style="width:100%;padding:12px 16px 12px 44px;border:1px solid var(--g300);border-radius:8px;
                          font-size:15px;outline:none;background:var(--w);"
                   onfocus="this.style.borderColor='var(--navy)';this.style.boxShadow='0 0 0 3px rgba(0,40,80,.08)'"
                   onblur="this.style.borderColor='var(--g300)';this.style.boxShadow='none'"/>
        </div>
        <select name="folder" style="padding:12px;border:1px solid var(--g300);border-radius:8px;font-size:14px;background:var(--w);outline:none;">
            <option value="ALL" <?= $folder==='ALL'?'selected':'' ?>>All Folders</option>
            <option value="INBOX" <?= $folder==='INBOX'?'selected':'' ?>>Inbox</option>
            <option value="Sent" <?= $folder==='Sent'?'selected':'' ?>>Sent</option>
            <option value="Drafts" <?= $folder==='Drafts'?'selected':'' ?>>Drafts</option>
            <option value="Trash" <?= $folder==='Trash'?'selected':'' ?>>Trash</option>
        </select>
        <button type="submit" class="hri-btn hri-btn-navy">Search</button>
    </form>
</div>

<?php if ($error): ?>
<div class="hri-alert hri-alert-warn">⚠️ <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($searched): ?>
<!-- Results -->
<div style="max-width:860px;margin:0 auto;">
    <div style="font-size:13px;color:var(--g500);margin-bottom:12px;">
        <?php if (count($results)): ?>
            Found <strong><?= count($results) ?></strong> result<?= count($results)!==1?'s':'' ?>
            for <strong>"<?= htmlspecialchars($q) ?>"</strong>
            <?= $folder!=='ALL'?' in '.htmlspecialchars($folder):' across all folders' ?>
        <?php else: ?>
            No results found for <strong>"<?= htmlspecialchars($q) ?>"</strong>
            — try different keywords or search a specific folder
        <?php endif; ?>
    </div>

    <?php if ($results): ?>
    <div class="hri-card">
        <?php foreach ($results as $r):
            $highlight = function($text) use ($q) {
                return preg_replace('/('.preg_quote(htmlspecialchars($q),'/').')/i',
                    '<mark style="background:#fff3cd;padding:0 2px;border-radius:2px;">$1</mark>',
                    htmlspecialchars($text));
            };
        ?>
        <a href="/mail.php?f=<?= urlencode($r['folder']) ?>&uid=<?= $r['uid'] ?>"
           style="display:flex;align-items:center;gap:14px;padding:12px 16px;
                  border-bottom:1px solid var(--g100);text-decoration:none;
                  background:<?= $r['seen']?'var(--w)':'#f0f4ff' ?>;
                  font-weight:<?= $r['seen']?'400':'600' ?>;"
           onmouseover="this.style.background='var(--g50)'"
           onmouseout="this.style.background='<?= $r['seen']?'var(--w)':'#f0f4ff' ?>'">

            <!-- Avatar -->
            <div style="width:40px;height:40px;border-radius:50%;flex-shrink:0;
                        background:<?= av($r['from_email']) ?>;
                        color:#fff;font-size:14px;font-weight:700;
                        display:flex;align-items:center;justify-content:center;">
                <?= htmlspecialchars($r['ini']) ?>
            </div>

            <!-- Content -->
            <div style="flex:1;min-width:0;">
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;">
                    <span style="font-size:14px;color:var(--g900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;">
                        <?= $highlight($r['from']) ?>
                    </span>
                    <span style="font-size:12px;color:var(--g400);flex-shrink:0;"><?= htmlspecialchars($r['time']) ?></span>
                </div>
                <div style="font-size:14px;color:var(--g700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?= $highlight($r['subject']) ?>
                </div>
                <div style="font-size:12px;color:var(--g500);margin-top:2px;">
                    📁 <?= htmlspecialchars($r['folder']) ?>
                    <?= $r['flagged']?' ⭐':''; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<!-- Empty state -->
<div style="text-align:center;padding:60px 20px;color:var(--g400);">
    <div style="font-size:48px;margin-bottom:16px;">🔍</div>
    <div style="font-size:18px;font-weight:500;color:var(--g600);margin-bottom:8px;">Search your mail</div>
    <div style="font-size:14px;">Search by sender, subject or keywords across all folders</div>
    <div style="font-size:13px;margin-top:20px;color:var(--g300);">Tips: Try sender name, email domain, or key words from the subject</div>
</div>
<?php endif; ?>
</div>
<?php Layout::end(); ?>
