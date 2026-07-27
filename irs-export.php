<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/IrsFlow.php';

$user = Auth::require();
$db   = getDB();

$canViewAll   = IrsFlow::canViewAll($user);
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';
$filterView   = $_GET['view']   ?? ($canViewAll ? 'all' : 'mine');
$viewAll      = $canViewAll && $filterView === 'all';
$showClosed   = !empty($_GET['closed']);

$where  = $viewAll ? '1=1' : 'r.requester_id=?';
$params = $viewAll ? [] : [$user['id']];
if ($filterStatus !== '') { $where .= ' AND r.status=?';  $params[] = $filterStatus; }
if ($filterType   !== '') { $where .= ' AND r.type=?';    $params[] = $filterType; }
if (!$showClosed && !in_array($filterStatus, ['completed','rejected'])) {
    $where .= " AND r.status NOT IN ('completed','rejected')";
}

$rows = [];
try {
    $s = $db->prepare("SELECT r.ref_number, r.type, u.name requester_name, u.department,
        r.description, r.notes, r.amount, r.actual_amount, r.priority, r.status,
        r.bank, r.account_name, r.account_number, r.sage_ref, r.payment_method,
        r.created_at, r.updated_at
        FROM irs_requests r
        JOIN users u ON u.id=r.requester_id
        WHERE $where
        ORDER BY r.created_at DESC");
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$typeLabels = ['requisition'=>'Requisition','caution'=>'Caution Payment','payment'=>'Payment Request','petty_cash'=>'Petty Cash','retirement'=>'Retirement'];

ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="IRS_Export_'.date('Y-m-d').'.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel UTF-8

fputcsv($out, [
    'Reference','Type','Requester','Department','Description',
    'Amount Requested (NGN)','Actual Amount (NGN)','Priority','Status',
    'Bank','Account Name','Account No.','Payment Method','Sage Ref',
    'Submitted','Last Updated',
]);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['ref_number'],
        $typeLabels[$r['type']] ?? $r['type'],
        $r['requester_name'],
        $r['department'],
        $r['description'],
        number_format((float)$r['amount'], 2, '.', ''),
        $r['actual_amount'] !== null ? number_format((float)$r['actual_amount'], 2, '.', '') : '',
        ucfirst($r['priority']),
        IrsFlow::defaultStageLabel($r['status']),
        $r['bank'] ?? '',
        $r['account_name'] ?? '',
        $r['account_number'] ?? '',
        $r['payment_method'] ?? '',
        $r['sage_ref'] ?? '',
        date('d M Y, g:ia', strtotime($r['created_at'])),
        $r['updated_at'] ? date('d M Y, g:ia', strtotime($r['updated_at'])) : '',
    ]);
}
fclose($out);
exit;
