<?php
// api/audit/export.php — streams the auditor export as a single CSV.
//
// ?from=YYYY-MM-DD &to=YYYY-MM-DD &groups=dept,totals,sage,...
// &posted=1  (only transactions posted to Sage)
// &nojrnl=1  (include transactions with no journal entries)
// &count=1   (return JSON counts instead of the file — powers the live preview)

if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/AuditExport.php';

$user = Auth::requireRole(AuditExport::EXPORT_ROLES);

// Nothing below writes to the session, and a full-year export can take a
// while — release the lock so it does not stall the user's other requests.
session_write_close();

$db = getDB();

[$fyStart, $fyEnd] = AuditExport::currentFY();
$from = $_GET['from'] ?? $fyStart;
$to   = $_GET['to']   ?? $fyEnd;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $fyStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $fyEnd;
if ($from > $to) { [$from, $to] = [$to, $from]; }

$validGroups = array_keys(AuditExport::groups());
$groups      = array_values(array_intersect(explode(',', (string)($_GET['groups'] ?? '')), $validGroups));

$postedOnly       = !empty($_GET['posted']);
$includeNoJournal = !empty($_GET['nojrnl']);
$countOnly        = !empty($_GET['count']);

$cols = AuditExport::columnsFor($groups);
$rows = AuditExport::fetch($db, $from, $to, $postedOnly, $includeNoJournal);
$users = AuditExport::userMap($db);
$txns  = AuditExport::buildTransactions($rows, $users);

// ── Live preview: counts, control totals, and the first N rows ───────────────
if ($countOnly) {
    header('Content-Type: application/json');

    $limit  = (int)($_GET['limit'] ?? 60);
    if ($limit < 1 || $limit > 500) $limit = 60;
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $outRows = 0; $dr = 0.0; $cr = 0.0; $noJrnl = 0; $unbalanced = 0; $noSage = 0;
    foreach ($txns as $t) {
        $n = count($t['lines']);
        $outRows += max(1, $n);
        $dr += $t['dr']; $cr += $t['cr'];
        if ($n === 0) $noJrnl++;
        elseif (abs($t['dr'] - $t['cr']) >= 0.01) $unbalanced++;
        if (empty($t['head']['sage_ref'])) $noSage++;
    }

    // Exactly the rows the CSV would contain, sliced to the requested page.
    // A journal-less transaction still emits one row, hence the [null] fallback.
    $sample = []; $idx = 0;
    foreach ($txns as $t) {
        $lines = $t['lines'];
        $n     = count($lines);
        foreach (($lines ?: [null]) as $i => $line) {
            if ($idx >= $offset) {
                if (count($sample) >= $limit) break 2;
                $sample[] = [
                    'first' => $i === 0,
                    'cells' => AuditExport::row($cols, $t, $line, $i === 0, $n, $line ? $i + 1 : 0, $users),
                ];
            }
            $idx++;
        }
    }
    $taken = count($sample);

    echo json_encode([
        'ok'           => true,
        'transactions' => count($txns),
        'rows'         => $outRows,
        'columns'      => count($cols),
        'total_debit'  => round($dr, 2),
        'total_credit' => round($cr, 2),
        'balanced'     => abs($dr - $cr) < 0.01,
        'no_journal'   => $noJrnl,
        'unbalanced'   => $unbalanced,
        'no_sage_ref'  => $noSage,
        'from'         => $from,
        'to'           => $to,
        'header'       => $cols,
        'labels'       => AuditExport::labelsFor($cols),
        'sample'       => $sample,
        'offset'       => $offset,
        'limit'        => $limit,
        'shown'        => $taken,
    ]);
    exit;
}

// ── The file ────────────────────────────────────────────────────────────────
Auth::auditLog($user['id'], 'audit_export',
    "Auditor export {$from} to {$to} — " . count($txns) . " transactions, " . count($cols) . " columns"
    . ($postedOnly ? ", posted only" : "") . ($includeNoJournal ? ", incl. journal-less" : ""));

$filename = 'HRI-IRS-Audit-' . $from . '-to-' . $to . '.csv';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// UTF-8 BOM — without it Excel reads the file as Windows-1252 and mangles the
// naira sign and any accented name.
fwrite($out, "\xEF\xBB\xBF");

// Readable headings — an auditor opens this in Excel, not a parser
fputcsv($out, AuditExport::labelsFor($cols));

foreach ($txns as $t) {
    $lines = $t['lines'];
    $n     = count($lines);
    if ($n === 0) {
        // Journal-less transaction still gets one row so it stays visible
        fputcsv($out, AuditExport::row($cols, $t, null, true, 0, 0, $users));
        continue;
    }
    foreach ($lines as $i => $line) {
        fputcsv($out, AuditExport::row($cols, $t, $line, $i === 0, $n, $i + 1, $users));
    }
}

fclose($out);
exit;
