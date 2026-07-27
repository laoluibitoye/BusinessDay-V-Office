<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::requireRole(['md','bdm','head_it','head_outsourcing','head_compliance','head_accounts','hr','head_training','head_cso','training_manager','cs_manager']);

while (ob_get_level() > 0) ob_end_clean();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="payslip_csv_' . date('Y-m') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

function hriCsvRow(array $fields) {
    $parts = [];
    foreach ($fields as $f) {
        $parts[] = '"' . str_replace('"', '""', (string)$f) . '"';
    }
    return implode(',', $parts) . "\r\n";
}

$headers = [
    'employee_name','email','employee_id','designation','location',
    'pay_period','pay_day','pfa','pension_pin','days_in_month','days_worked','tax_id',
    'basic_salary','housing','transportation','other_allowances','hmo_employer','psf_arrears',
    'hmo_employee','monthly_pension','monthly_tax','additional_hmo','loan_repayment','caution_fee',
];

$example = [
    'John Doe','john.doe@example.com','HRI-001','Client Service Officer','Lagos',
    date('F Y'),date('d M Y'),'NLPC','PEN123456789','31','31','TAX-00123',
    '150000','50000','30000','20000','0','0',
    '10000','18000','12000','0','0','0',
];

echo "\xEF\xBB\xBF";
echo hriCsvRow($headers);
echo hriCsvRow($example);
