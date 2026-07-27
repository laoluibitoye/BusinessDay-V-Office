<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::requireRole(['md','bdm','head_it','head_outsourcing','head_compliance','head_accounts','hr','head_training','head_cso','training_manager','cs_manager']);
$db   = getDB();

// Ensure payslips upload directory exists
$uploadDir = __DIR__ . '/../../uploads/payslips/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0750, true);
    file_put_contents($uploadDir . '.htaccess', "Order Deny,Allow\nDeny from all\n");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST required.']); exit;
}

$file = $_FILES['template'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'No file uploaded or upload error.']); exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv'])) {
    echo json_encode(['ok'=>false,'error'=>'Only CSV files are accepted.']); exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['ok'=>false,'error'=>'File exceeds 5MB limit.']); exit;
}

// ── Parse CSV ────────────────────────────────────────────────────────────────

$fh = fopen($file['tmp_name'], 'r');
if (!$fh) { echo json_encode(['ok'=>false,'error'=>'Cannot read file.']); exit; }

// Skip BOM if present
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($fh);

$headers = fgetcsv($fh);
if (!$headers) { fclose($fh); echo json_encode(['ok'=>false,'error'=>'Empty or invalid CSV.']); exit; }

// Normalise headers
$headers = array_map(fn($h) => strtolower(trim(preg_replace('/\s+/', '_', $h))), $headers);

$required = ['employee_name','email'];
foreach ($required as $req) {
    if (!in_array($req, $headers)) {
        fclose($fh);
        echo json_encode(['ok'=>false,'error'=>"Missing required column: \"$req\". Check the template."]); exit;
    }
}

// Read rows
$rows = [];
$lineNum = 1;
while (($cols = fgetcsv($fh)) !== false) {
    $lineNum++;
    if (count($cols) === 1 && trim($cols[0]) === '') continue; // blank line
    $row = array_combine(array_slice($headers, 0, count($cols)), array_slice($cols, 0, count($headers)));
    if (empty(trim($row['employee_name'] ?? '')) && empty(trim($row['email'] ?? ''))) continue;
    if (empty(trim($row['email'] ?? ''))) {
        fclose($fh);
        echo json_encode(['ok'=>false,'error'=>"Row $lineNum: email is required."]); exit;
    }
    if (!filter_var(trim($row['email']), FILTER_VALIDATE_EMAIL)) {
        fclose($fh);
        echo json_encode(['ok'=>false,'error'=>"Row $lineNum: invalid email \"" . htmlspecialchars($row['email']) . '"']); exit;
    }
    $rows[] = $row;
}
fclose($fh);

if (empty($rows)) {
    echo json_encode(['ok'=>false,'error'=>'No data rows found in file.']); exit;
}

// ── Detect pay_period and client_name ────────────────────────────────────────

$payPeriod  = trim($_POST['pay_period'] ?? '');
$clientName = trim($_POST['client_name'] ?? '');
$senderName = $user['name'];
if ($payPeriod === '' && !empty($rows[0]['pay_period'])) {
    $payPeriod = trim($rows[0]['pay_period']);
}
if ($payPeriod === '') $payPeriod = date('F Y');

// ── Generate batch ref ────────────────────────────────────────────────────────

$year = date('Y');
$maxRef = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(batch_ref,'-',-1) AS UNSIGNED)) FROM payslip_batches WHERE batch_ref LIKE ?");
$maxRef->execute(["PSL-$year-%"]);
$nextNum = (int)($maxRef->fetchColumn() ?? 0) + 1;
$batchRef = 'PSL-' . $year . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

// ── Insert batch ──────────────────────────────────────────────────────────────

try {
    $db->prepare("INSERT INTO payslip_batches (batch_ref,uploaded_by,original_file,pay_period,client_name,sender_name,total,status)
                  VALUES (?,?,?,?,?,?,?,'queued')")
       ->execute([$batchRef, $user['id'], basename($file['name']), $payPeriod, $clientName, $senderName, count($rows)]);
    $batchId = (int)$db->lastInsertId();
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Failed to create batch. Ensure payslip_migration.sql has been run.']); exit;
}

// ── Field mapping helper ──────────────────────────────────────────────────────

function csvNum(array $row, string $key): float {
    $v = $row[$key] ?? '';
    return (float)preg_replace('/[^0-9.\-]/', '', $v);
}
function csvStr(array $row, string $key): string {
    return trim($row[$key] ?? '');
}

// ── Insert queue rows ─────────────────────────────────────────────────────────

$ins = $db->prepare("INSERT INTO payslip_queue
    (batch_id,row_num,employee_name,employee_email,employee_id,designation,location,
     pay_period,pay_day,pfa,pension_pin,days_in_month,days_worked,tax_id,
     basic_salary,housing,transportation,other_allowances,hmo_employer,psf_arrears,total_earnings,
     hmo_employee,monthly_pension,monthly_tax,additional_hmo,loan_repayment,caution_fee,
     total_deductions,net_pay)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$queuedCount = 0;
foreach ($rows as $i => $row) {
    $basic    = csvNum($row, 'basic_salary');
    $housing  = csvNum($row, 'housing');
    $trans    = csvNum($row, 'transportation');
    $other    = csvNum($row, 'other_allowances');
    $hmoEmp   = csvNum($row, 'hmo_employer');
    $psf      = csvNum($row, 'psf_arrears');
    $totalEarnings = $basic + $housing + $trans + $other + $hmoEmp + $psf;

    $hmoDed   = csvNum($row, 'hmo_employee');
    $pension  = csvNum($row, 'monthly_pension');
    $tax      = csvNum($row, 'monthly_tax');
    $addHmo   = csvNum($row, 'additional_hmo');
    $loan     = csvNum($row, 'loan_repayment');
    $caution  = csvNum($row, 'caution_fee');
    $totalDed = $hmoDed + $pension + $tax + $addHmo + $loan + $caution;
    $netPay   = $totalEarnings - $totalDed;

    $rowPeriod = csvStr($row, 'pay_period') ?: $payPeriod;

    try {
        $ins->execute([
            $batchId, $i + 1,
            csvStr($row, 'employee_name'),
            strtolower(trim($row['email'])),
            csvStr($row, 'employee_id'),
            csvStr($row, 'designation'),
            csvStr($row, 'location'),
            $rowPeriod,
            csvStr($row, 'pay_day'),
            csvStr($row, 'pfa'),
            csvStr($row, 'pension_pin'),
            (int)csvNum($row, 'days_in_month'),
            (int)csvNum($row, 'days_worked'),
            csvStr($row, 'tax_id'),
            $basic, $housing, $trans, $other, $hmoEmp, $psf, $totalEarnings,
            $hmoDed, $pension, $tax, $addHmo, $loan, $caution,
            $totalDed, $netPay,
        ]);
        $queuedCount++;
    } catch (Exception $e) {
        // Skip duplicate/bad rows silently, batch continues
    }
}

// Update actual queued count
$db->prepare("UPDATE payslip_batches SET total=? WHERE id=?")->execute([$queuedCount, $batchId]);

Auth::auditLog($user['id'], 'payslip_batch_upload',
    "Uploaded payslip batch $batchRef — $queuedCount recipients — $payPeriod");

echo json_encode([
    'ok'         => true,
    'batch_id'   => $batchId,
    'batch_ref'  => $batchRef,
    'queued'     => $queuedCount,
    'pay_period' => $payPeriod,
    'message'    => "$queuedCount payslip(s) queued. Sending will start within 1 minute (max 15/hour).",
]);
