<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/IrsFlow.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

$type        = $_POST['type']        ?? '';
$description = trim($_POST['description'] ?? '');
$amount      = (float)($_POST['amount'] ?? 0);
$department  = trim($_POST['department'] ?? $user['department'] ?? '');
$priority    = $_POST['priority']    ?? 'normal';
$notes       = trim($_POST['notes']  ?? '');

$validTypes = ['requisition','caution','payment','petty_cash','retirement'];
if (!in_array($type, $validTypes))   { echo json_encode(['ok'=>false,'error'=>'Invalid request type.']); exit; }
if ($description === '')             { echo json_encode(['ok'=>false,'error'=>'Description is required.']); exit; }
if ($amount <= 0)                    { echo json_encode(['ok'=>false,'error'=>'Amount must be greater than zero.']); exit; }
if (!in_array($priority, ['urgent','normal','low'])) $priority = 'normal';

// Payment Request is accountant-only
if ($type === 'payment' && !in_array($user['role'], IrsFlow::PAYMENT_RAISER_ROLES) && !Auth::isAdmin($user)) {
    echo json_encode(['ok'=>false,'error'=>'Payment Requests can only be submitted by the Accounts team.']); exit;
}

// Type-specific fields
$bank = $accountName = $accountNumber = $beneficiariesJson = null;
$relatedRef = $advanceAmount = $actualAmount = null;

// Capture beneficiary details for requisition, caution and payment (not retirement/petty_cash)
if (!in_array($type, ['retirement', 'petty_cash'])) {
    $rawBenef  = trim($_POST['beneficiaries_json'] ?? '');
    $benefRows = $rawBenef !== '' ? json_decode($rawBenef, true) : [];
    if (!is_array($benefRows)) $benefRows = [];

    // Drop rows the user left completely blank
    $benefRows = array_values(array_filter($benefRows, function($r) {
        return trim($r['bank'] ?? '') !== ''
            || trim($r['account_number'] ?? '') !== ''
            || trim($r['account_name'] ?? '') !== '';
    }));

    // Payment Request is exempt: the payee is identified by the journal entries
    // and supporting documents, and Accounts fill the bank details in later.
    if ($type !== 'payment') {
        if (empty($benefRows)) {
            echo json_encode(['ok'=>false,'error'=>'At least one beneficiary is required.']); exit;
        }
        if (trim($benefRows[0]['bank'] ?? '') === '') {
            echo json_encode(['ok'=>false,'error'=>'Beneficiary bank name is required.']); exit;
        }
    }

    // Store first row in individual columns (backward compat) + full JSON
    if (!empty($benefRows)) {
        $firstRow          = $benefRows[0];
        $bank              = trim($firstRow['bank']           ?? '') ?: null;
        $accountName       = trim($firstRow['account_name']   ?? '') ?: null;
        $accountNumber     = trim($firstRow['account_number'] ?? '') ?: null;
        $beneficiariesJson = json_encode($benefRows);
    }
}
if ($type === 'retirement') {
    $relatedRef   = trim($_POST['related_ref']   ?? '');
    $actualAmount = (float)($_POST['actual_amount'] ?? 0);
    if ($relatedRef === '') { echo json_encode(['ok'=>false,'error'=>'Original request reference is required for retirement.']); exit; }
    // Lookup advance amount from related request
    $refRow = $db->prepare("SELECT amount FROM irs_requests WHERE ref_number=? AND requester_id=? AND status='completed'");
    $refRow->execute([$relatedRef, $user['id']]);
    $refData = $refRow->fetch(PDO::FETCH_ASSOC);
    if (!$refData) {
        if (!Auth::isAdmin($user)) {
            echo json_encode(['ok'=>false,'error'=>'Related request not found or not yet completed.']); exit;
        }
        $refRow2 = $db->prepare("SELECT amount FROM irs_requests WHERE ref_number=? AND status='completed'");
        $refRow2->execute([$relatedRef]);
        $refData = $refRow2->fetch(PDO::FETCH_ASSOC);
        if (!$refData) { echo json_encode(['ok'=>false,'error'=>'Related request not found or not yet completed.']); exit; }
    }
    $advanceAmount = (float)$refData['amount'];
}

// Generate ref number
function generateIrsRef(PDO $db, string $type): string {
    $prefixes = ['requisition'=>'REQ','caution'=>'CAU','payment'=>'PAY','petty_cash'=>'PCT','retirement'=>'RET'];
    $prefix = $prefixes[$type];
    $year = date('Y');
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(ref_number,'-',-1) AS UNSIGNED)) FROM irs_requests WHERE type=? AND ref_number LIKE ?");
    $stmt->execute([$type, "$prefix-$year-%"]);
    $max = (int)($stmt->fetchColumn() ?? 0);
    return $prefix . '-' . $year . '-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

try {
    $ref           = generateIrsRef($db, $type);
    $initialStatus = IrsFlow::initialStage($type);

    // Try INSERT with beneficiaries_json column; fall back if column not yet added via migration
    $inserted = false;
    if ($beneficiariesJson !== null) {
        try {
            $ins = $db->prepare("INSERT INTO irs_requests
                (ref_number,type,status,priority,description,amount,department,notes,
                 bank,account_name,account_number,beneficiaries_json,
                 related_ref,advance_amount,actual_amount,requester_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $ref, $type, $initialStatus, $priority,
                $description, $amount, $department, $notes ?: null,
                $bank, $accountName, $accountNumber, $beneficiariesJson,
                $relatedRef, $advanceAmount, $actualAmount,
                $user['id']
            ]);
            $inserted = true;
        } catch (PDOException $e2) {
            // Column may not exist yet — fall through to INSERT without it
        }
    }
    if (!$inserted) {
        $ins = $db->prepare("INSERT INTO irs_requests
            (ref_number,type,status,priority,description,amount,department,notes,
             bank,account_name,account_number,
             related_ref,advance_amount,actual_amount,requester_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            $ref, $type, $initialStatus, $priority,
            $description, $amount, $department, $notes ?: null,
            $bank, $accountName, $accountNumber,
            $relatedRef, $advanceAmount, $actualAmount,
            $user['id']
        ]);
    }
    $requestId = (int)$db->lastInsertId();

    // For payment type: save journal entries submitted with the request
    $journalSaved = 0;
    $journalError = null;
    if ($type === 'payment') {
        // Accept either field name — irs-new.php posts journal_entries_json,
        // the in-flow forms (raise_payment / approve_journals) post journal_entries
        $journalJson = trim($_POST['journal_entries_json'] ?? $_POST['journal_entries'] ?? '');
        if ($journalJson !== '' && $journalJson !== '[]') {
            $jLines = json_decode($journalJson, true);
            if (is_array($jLines) && !empty($jLines)) {
                try {
                    $jIns = $db->prepare("INSERT INTO irs_journal_entries
                        (request_id, line_no, account_code, account_name, description, debit, credit, created_by)
                        VALUES (?,?,?,?,?,?,?,?)");
                    foreach ($jLines as $lineNo => $jl) {
                        $jAccName = trim($jl['account_name'] ?? '');
                        if ($jAccName === '') continue;
                        $jIns->execute([
                            $requestId, $lineNo + 1,
                            trim($jl['account_code'] ?? '') ?: null,
                            $jAccName,
                            trim($jl['description'] ?? '') ?: null,
                            max(0, (float)($jl['debit']  ?? 0)),
                            max(0, (float)($jl['credit'] ?? 0)),
                            $user['id']
                        ]);
                        $journalSaved++;
                    }
                } catch (Exception $je) {
                    // Do NOT swallow — surface it so the cause is visible immediately
                    $journalError = $je->getMessage();
                }
            } else {
                $journalError = 'Journal payload did not decode to an array.';
            }
        } else {
            $journalError = 'No journal data was received with the request.';
        }
    }

    // Audit log
    $aud = $db->prepare("INSERT INTO irs_audit_log (request_id,user_id,action,detail,ip_address) VALUES (?,?,?,?,?)");
    $aud->execute([$requestId, $user['id'], 'submitted',
        "Submitted {$type} request {$ref} for ₦" . number_format($amount, 2),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    Auth::auditLog($user['id'], 'irs_submit', "Submitted IRS {$ref} — {$type} — ₦" . number_format($amount, 2));

    if ($type === 'payment' && $journalSaved === 0) {
        Auth::auditLog($user['id'], 'irs_journal_fail', "{$ref} saved 0 journal lines — " . ($journalError ?? 'unknown'));
    }

    echo json_encode([
        'ok'             => true,
        'ref'            => $ref,
        'id'             => $requestId,
        'journals_saved' => $journalSaved,
        'journal_error'  => $journalError,
    ]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Submit failed: ' . $e->getMessage()]);
}
