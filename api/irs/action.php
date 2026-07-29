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

$action    = $_POST['action']    ?? '';
$requestId = (int)($_POST['id'] ?? 0);
$comment   = trim($_POST['comment'] ?? '');

if (!$requestId) { echo json_encode(['ok'=>false,'error'=>'Invalid request.']); exit; }

// Fetch request
$req = null;
try {
    $row = $db->prepare("SELECT r.*, u.name AS requester_name, u.email AS requester_email
        FROM irs_requests r JOIN users u ON u.id=r.requester_id WHERE r.id=?");
    $row->execute([$requestId]);
    $req = $row->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (!$req) { echo json_encode(['ok'=>false,'error'=>'Request not found.']); exit; }

// Access: can this user see this request at all?
if (!IrsFlow::canView($user, $req)) { echo json_encode(['ok'=>false,'error'=>'Access denied.']); exit; }

$ip          = $_SERVER['REMOTE_ADDR'] ?? null;
$isRequester = ((int)$user['id'] === (int)$req['requester_id']);
$isAdmin     = Auth::isAdmin($user);

function irsAudit(PDO $db, int $requestId, int $userId, string $action, string $detail, ?string $ip): void {
    try {
        $s = $db->prepare("INSERT INTO irs_audit_log (request_id,user_id,action,detail,ip_address) VALUES (?,?,?,?,?)");
        $s->execute([$requestId, $userId, $action, $detail, $ip]);
    } catch (Exception $_e) {}
}

try {

    // ── SPECIAL: Withdraw (requester-only) ────────────────────────────────────
    if ($action === 'withdraw') {
        if (!$isRequester && !$isAdmin) { echo json_encode(['ok'=>false,'error'=>'Only the requester can withdraw this request.']); exit; }
        if (!in_array($req['status'], IrsFlow::WITHDRAWABLE_STAGES)) {
            echo json_encode(['ok'=>false,'error'=>'This request cannot be withdrawn at its current stage.']); exit;
        }
        $db->prepare("UPDATE irs_requests SET status='rejected',
            rejected_by=?,rejected_at=NOW(),rejection_reason='Withdrawn by requester',updated_at=NOW()
            WHERE id=?")->execute([$user['id'], $requestId]);
        irsAudit($db, $requestId, $user['id'], 'withdrawn', 'Request withdrawn by requester.', $ip);
        Auth::auditLog($user['id'], 'irs_withdrawn', $req['ref_number'].' withdrawn by requester');
        echo json_encode(['ok'=>true,'message'=>'Request withdrawn.']);
        exit;
    }

    // ── SPECIAL: Resubmit (requester-only, from terminal rejected/draft) ─────
    if ($action === 'resubmit') {
        if (!$isRequester && !$isAdmin) { echo json_encode(['ok'=>false,'error'=>'Only the requester can resubmit this request.']); exit; }
        if (!in_array($req['status'], ['rejected', 'draft'])) { echo json_encode(['ok'=>false,'error'=>'This request cannot be resubmitted from its current status.']); exit; }
        $newStage = IrsFlow::initialStage($req['type']);
        $db->prepare("UPDATE irs_requests SET status=?,
            rejection_reason=NULL, rejected_by=NULL, rejected_at=NULL,
            accounts_reviewer_id=NULL, accounts_reviewed_at=NULL, accounts_comment=NULL,
            manager_approver_id=NULL, manager_approved_at=NULL, manager_comment=NULL,
            eligibility_reviewer_id=NULL, eligibility_reviewed_at=NULL, eligibility_comment=NULL,
            payment_raised_by=NULL, payment_raised_at=NULL,
            payment_approval_by=NULL, payment_approval_at=NULL,
            updated_at=NOW() WHERE id=?")->execute([$newStage, $requestId]);
        irsAudit($db, $requestId, $user['id'], 'resubmitted', 'Resubmitted. '.($comment ?: ''), $ip);
        echo json_encode(['ok'=>true,'message'=>'Request resubmitted for review.']);
        exit;
    }

    // ── SPECIAL: submit_correction (requester-only, from pending_corrections) ─
    if ($action === 'submit_correction') {
        if (!$isRequester && !$isAdmin) { echo json_encode(['ok'=>false,'error'=>'Only the requester can submit a correction.']); exit; }
        if ($req['status'] !== 'pending_corrections') { echo json_encode(['ok'=>false,'error'=>'Corrections can only be submitted from the corrections stage.']); exit; }

        // Snapshot old values BEFORE any change (for audit diff)
        $oldDesc      = $req['description']       ?? '';
        $oldNotes     = $req['notes']             ?? '';
        $oldAmt       = (float)($req['amount']    ?? 0);
        $oldBank      = $req['bank']              ?? '';
        $oldAcctNo    = $req['account_number']    ?? '';
        $oldAcctName  = $req['account_name']      ?? '';
        $oldBenefJson = $req['beneficiaries_json'] ?? '';

        // Update editable request fields (fall back to current values if not supplied)
        $newDesc  = trim($_POST['description'] ?? '') ?: $req['description'];
        $newNotes = trim($_POST['notes'] ?? '') ?: null;
        $newAmt   = (float)($_POST['amount'] ?? 0);
        if ($newAmt <= 0) $newAmt = (float)$req['amount'];

        // Derive beneficiary bank fields from JSON (first row) if supplied
        $newBenefJson = trim($_POST['beneficiaries_json'] ?? '');
        if ($newBenefJson === '' || $newBenefJson === '[]') $newBenefJson = $req['beneficiaries_json'] ?? null;
        $newBank     = $req['bank']           ?? null;
        $newAcctNo   = $req['account_number'] ?? null;
        $newAcctName = $req['account_name']   ?? null;
        if (!empty($newBenefJson)) {
            $bf = json_decode($newBenefJson, true) ?: [];
            if (!empty($bf[0])) {
                if (trim($bf[0]['bank']           ?? '') !== '') $newBank     = trim($bf[0]['bank']);
                if (trim($bf[0]['account_number'] ?? '') !== '') $newAcctNo   = trim($bf[0]['account_number']);
                if (trim($bf[0]['account_name']   ?? '') !== '') $newAcctName = trim($bf[0]['account_name']);
            }
        }

        $newStage = IrsFlow::initialStage($req['type']);

        // NOTE: rejection_reason is deliberately NOT cleared here. It is the record
        // of why this request came back, and Approval Progress renders it on the
        // corrections row. Clearing it erased that history at the exact moment the
        // next reviewer needed it. rejected_by/rejected_at still reset so the
        // push-back banner does not fire on the fresh pass.
        $db->prepare("UPDATE irs_requests SET
            status=?, description=?, notes=?, amount=?,
            beneficiaries_json=?, bank=?, account_number=?, account_name=?,
            rejected_by=NULL, rejected_at=NULL,
            accounts_reviewer_id=NULL, accounts_reviewed_at=NULL, accounts_comment=NULL,
            manager_approver_id=NULL, manager_approved_at=NULL, manager_comment=NULL,
            eligibility_reviewer_id=NULL, eligibility_reviewed_at=NULL, eligibility_comment=NULL,
            payment_raised_by=NULL, payment_raised_at=NULL, actual_amount=NULL,
            payment_approval_by=NULL, payment_approval_at=NULL,
            hod_payment_approved_by=NULL, hod_payment_approved_at=NULL, hod_payment_comment=NULL,
            updated_at=NOW() WHERE id=?")
           ->execute([$newStage, $newDesc, $newNotes, $newAmt,
                      $newBenefJson, $newBank, $newAcctNo, $newAcctName,
                      $requestId]);

        // Payment Request: replace journal entries if provided with corrections
        if ($req['type'] === 'payment') {
            $newJournalJson = trim($_POST['journal_entries_json'] ?? '');
            if ($newJournalJson !== '' && $newJournalJson !== '[]') {
                $jLines = json_decode($newJournalJson, true);
                if (is_array($jLines) && !empty($jLines)) {
                    try {
                        $db->prepare("DELETE FROM irs_journal_entries WHERE request_id=?")->execute([$requestId]);
                        $jIns = $db->prepare("INSERT INTO irs_journal_entries (request_id, line_no, account_code, account_name, description, debit, credit, created_by) VALUES (?,?,?,?,?,?,?,?)");
                        foreach ($jLines as $lineNo => $jl) {
                            $jAccName = trim($jl['account_name'] ?? '');
                            if ($jAccName === '') continue;
                            $jIns->execute([$requestId, $lineNo + 1, trim($jl['account_code'] ?? '') ?: null, $jAccName, trim($jl['description'] ?? '') ?: null, max(0, (float)($jl['debit'] ?? 0)), max(0, (float)($jl['credit'] ?? 0)), $user['id']]);
                        }
                    } catch (Exception $je) {}
                }
            }
        }

        // Build before/after diff for audit trail
        $changes = [];
        if ($newDesc !== $oldDesc) {
            $snap = function($s) { $s = trim($s); return mb_strlen($s) > 80 ? mb_substr($s,0,80).'…' : $s; };
            $changes[] = 'Description: "'.$snap($oldDesc).'" → "'.$snap($newDesc).'"';
        }
        if (abs($newAmt - $oldAmt) > 0.005) {
            $changes[] = 'Amount: ₦'.number_format($oldAmt,2).' → ₦'.number_format($newAmt,2);
        }
        if (trim($newNotes ?? '') !== trim($oldNotes)) {
            $changes[] = 'Notes updated';
        }
        // Beneficiary: compare normalised first-row fields
        $benefChanged = ($newBank !== $oldBank) || ($newAcctNo !== $oldAcctNo) || ($newAcctName !== $oldAcctName);
        if (!$benefChanged && !empty($newBenefJson) && $newBenefJson !== $oldBenefJson) {
            $benefChanged = true; // multi-row change
        }
        if ($benefChanged) {
            $oldBenefSnap = $oldBank ? $oldBank.'/'.$oldAcctNo.'/'.$oldAcctName : '(none)';
            $newBenefSnap = $newBank ? $newBank.'/'.$newAcctNo.'/'.$newAcctName : '(none)';
            if ($oldBenefSnap !== $newBenefSnap) {
                $changes[] = 'Beneficiary: "'.$oldBenefSnap.'" → "'.$newBenefSnap.'"';
            } else {
                $changes[] = 'Beneficiary rows updated';
            }
        }

        $corrNote  = trim($_POST['correction_note'] ?? '');
        $diffLine  = empty($changes) ? 'No field changes.' : implode(' | ', $changes);
        $auditMsg  = 'Corrections submitted. '.$diffLine.($corrNote ? ' Note: '.$corrNote : '');

        irsAudit($db, $requestId, $user['id'], 'submit_correction', $auditMsg, $ip);
        Auth::auditLog($user['id'], 'irs_correction', $req['ref_number'].' corrections submitted');
        echo json_encode(['ok'=>true,'message'=>'Corrections submitted. Request is back under review.']);
        exit;
    }

    // ── FLOW-DRIVEN ACTIONS ───────────────────────────────────────────────────

    // 1. Look up the transition in the DB
    $transition = IrsFlow::getTransition($db, $req['type'], $req['status'], $action);
    if (!$transition) {
        echo json_encode(['ok'=>false,'error'=>'Action "'.$action.'" is not available at this stage.']);
        exit;
    }

    // 2. Check actor role
    if (!IrsFlow::isActor($db, $user, $req['type'], $req['status']) && !$isAdmin) {
        echo json_encode(['ok'=>false,'error'=>'You do not have permission to perform this action.']);
        exit;
    }

    // 3. Validate required fields
    if ($transition['requires_note'] && $comment === '') {
        echo json_encode(['ok'=>false,'error'=>'A comment or reason is required for this action.']);
        exit;
    }

    $toStage   = $transition['to_stage'];
    $fromStage = $req['status'];

    // 4. Pre-validate action-specific required fields BEFORE any DB write
    if ($action === 'raise_payment') {
        $preAmt = (float)($_POST['actual_amount'] ?? $req['amount'] ?? 0);
        if ($preAmt <= 0) { echo json_encode(['ok'=>false,'error'=>'Payment amount is required.']); exit; }
    }
    if ($action === 'post') {
        $preSage = trim($_POST['sage_ref'] ?? '');
        if ($preSage === '') { echo json_encode(['ok'=>false,'error'=>'Sage reference number is required.']); exit; }
    }

    // 5. Base status update (only runs after all pre-validation passes)
    $db->prepare("UPDATE irs_requests SET status=?, updated_at=NOW() WHERE id=?")->execute([$toStage, $requestId]);

    // 6. Stage-specific extra field updates
    switch ($action) {

        // ── Approve & attach journal entries (retirement: HOD Accounts stage)
        case 'approve_journals':
            $db->prepare("UPDATE irs_requests SET accounts_reviewer_id=?,accounts_reviewed_at=NOW(),accounts_comment=? WHERE id=?")
               ->execute([$user['id'], $comment ?: null, $requestId]);
            // Retirement: HOD confirms reconciliation and posts journals
            $recStatus = trim($_POST['reconciliation_status'] ?? '');
            $recAmt    = (float)($_POST['actual_amount'] ?? 0);
            $validRec  = ['exact','refund_required','additional_payment'];
            if (in_array($recStatus, $validRec)) {
                $db->prepare("UPDATE irs_requests SET reconciliation_status=?, actual_amount=? WHERE id=?")
                   ->execute([$recStatus, $recAmt > 0 ? $recAmt : null, $requestId]);
            }
            // Save journal entries attached by HOD
            $jrnJson = trim($_POST['journal_entries'] ?? '');
            if ($jrnJson !== '' && $jrnJson !== '[]') {
                $jLines = json_decode($jrnJson, true);
                if (is_array($jLines) && !empty($jLines)) {
                    $db->prepare("DELETE FROM irs_journal_entries WHERE request_id=?")->execute([$requestId]);
                    $jIns = $db->prepare("INSERT INTO irs_journal_entries
                        (request_id,line_no,account_code,account_name,description,debit,credit,created_by)
                        VALUES (?,?,?,?,?,?,?,?)");
                    foreach ($jLines as $lineNo => $jl) {
                        $jAccName = trim($jl['account_name'] ?? '');
                        if ($jAccName === '') continue;
                        $jIns->execute([
                            $requestId, $lineNo + 1,
                            trim($jl['account_code'] ?? '') ?: null, $jAccName,
                            trim($jl['description'] ?? '') ?: null,
                            max(0, (float)($jl['debit']  ?? 0)),
                            max(0, (float)($jl['credit'] ?? 0)),
                            $user['id']
                        ]);
                    }
                }
            }
            break;

        // ── Approve at HOD Accounts stage
        case 'approve':
            if ($fromStage === 'pending_hod_accounts') {
                $db->prepare("UPDATE irs_requests SET accounts_reviewer_id=?,accounts_reviewed_at=NOW(),accounts_comment=? WHERE id=?")
                   ->execute([$user['id'], $comment ?: null, $requestId]);
            } elseif ($fromStage === 'pending_hod_accounts_payment') {
                $db->prepare("UPDATE irs_requests SET hod_payment_approved_by=?,hod_payment_approved_at=NOW(),hod_payment_comment=? WHERE id=?")
                   ->execute([$user['id'], $comment ?: null, $requestId]);
            } elseif ($fromStage === 'pending_md') {
                $db->prepare("UPDATE irs_requests SET manager_approver_id=?,manager_approved_at=NOW(),manager_comment=? WHERE id=?")
                   ->execute([$user['id'], $comment ?: null, $requestId]);
            }
            break;

        // ── Approve payment (payment_request: MD approves at pending_md; others at pending_payment_approval)
        case 'approve_payment':
            $db->prepare("UPDATE irs_requests SET payment_approval_by=?,payment_approval_at=NOW() WHERE id=?")
               ->execute([$user['id'], $requestId]);
            break;

        // ── Eligibility approval (caution)
        case 'confirm_eligible':
            $db->prepare("UPDATE irs_requests SET eligibility_reviewer_id=?,eligibility_reviewed_at=NOW(),eligibility_comment=? WHERE id=?")
               ->execute([$user['id'], $comment ?: null, $requestId]);
            break;

        // ── Mark ineligible (caution) — always requires note (enforced above)
        case 'mark_ineligible':
            $db->prepare("UPDATE irs_requests SET
                eligibility_reviewer_id=?,eligibility_reviewed_at=NOW(),eligibility_comment=?,
                rejected_by=?,rejected_at=NOW(),rejection_reason=?,updated_at=NOW() WHERE id=?")
               ->execute([$user['id'], $comment, $user['id'], $comment, $requestId]);
            break;

        // ── Reject at any stage — push back to previous stage for corrections
        case 'reject':
            // Record who rejected and why (preserved for display in corrections banner)
            $db->prepare("UPDATE irs_requests SET rejected_by=?,rejected_at=NOW(),rejection_reason=?,updated_at=NOW() WHERE id=?")
               ->execute([$user['id'], $comment, $requestId]);
            if ($fromStage === 'pending_hod_accounts') {
                $db->prepare("UPDATE irs_requests SET accounts_reviewer_id=?,accounts_reviewed_at=NOW(),accounts_comment=? WHERE id=?")
                   ->execute([$user['id'], $comment, $requestId]);
            } elseif ($fromStage === 'pending_hod_accounts_payment') {
                $db->prepare("UPDATE irs_requests SET hod_payment_approved_by=?,hod_payment_approved_at=NOW(),hod_payment_comment=? WHERE id=?")
                   ->execute([$user['id'], $comment, $requestId]);
            } elseif ($fromStage === 'pending_md') {
                $db->prepare("UPDATE irs_requests SET manager_approver_id=?,manager_approved_at=NOW(),manager_comment=? WHERE id=?")
                   ->execute([$user['id'], $comment, $requestId]);
            }

            // Push back to previous stage instead of terminal 'rejected'
            $pushbackMap = [
                'payment:pending_hod_accounts'            => 'pending_corrections',
                'payment:pending_md'                      => 'pending_hod_accounts',
                'payment:pending_post'                    => 'pending_md',
                'retirement:pending_hod_accounts'         => 'pending_corrections',
                'retirement:pending_md'                   => 'pending_hod_accounts',
                'retirement:pending_post'                 => 'pending_md',
                'requisition:pending_hod_accounts'        => 'pending_corrections',
                'requisition:pending_hod_accounts_payment'=> 'pending_payment',
                'requisition:pending_payment_approval'    => 'pending_hod_accounts_payment',
                'requisition:pending_post'                => 'pending_payment_approval',
                // Caution runs a 4-stage flow: Eligibility & Payment -> Accounts
                // Review -> Management -> Post. Each rejection steps back one.
                'caution:pending_eligibility'             => 'pending_corrections',
                'caution:pending_hod_accounts'            => 'pending_eligibility',
                'caution:pending_md'                      => 'pending_hod_accounts',
                'caution:pending_post'                    => 'pending_md',
                'petty_cash:pending_accountant'           => 'pending_corrections',
                'petty_cash:pending_md'                   => 'pending_accountant',
            ];
            $pushbackStage = $pushbackMap[$req['type'].':'.$fromStage] ?? 'pending_corrections';

            // Override the status the base update wrote (was 'rejected', now push-back stage)
            $db->prepare("UPDATE irs_requests SET status=? WHERE id=?")->execute([$pushbackStage, $requestId]);
            $toStage = $pushbackStage; // keep $toStage accurate for the audit log below

            // Clear the approver data at the push-back stage so they must re-act
            if ($pushbackStage === 'pending_hod_accounts') {
                $db->prepare("UPDATE irs_requests SET accounts_reviewer_id=NULL,accounts_reviewed_at=NULL WHERE id=?")
                   ->execute([$requestId]);
            } elseif ($pushbackStage === 'pending_hod_accounts_payment') {
                $db->prepare("UPDATE irs_requests SET hod_payment_approved_by=NULL,hod_payment_approved_at=NULL WHERE id=?")
                   ->execute([$requestId]);
            } elseif ($pushbackStage === 'pending_payment') {
                $db->prepare("UPDATE irs_requests SET payment_raised_by=NULL,payment_raised_at=NULL,actual_amount=NULL,payment_method=NULL,paying_bank=NULL WHERE id=?")
                   ->execute([$requestId]);
            } elseif ($pushbackStage === 'pending_md') {
                $db->prepare("UPDATE irs_requests SET manager_approver_id=NULL,manager_approved_at=NULL WHERE id=?")
                   ->execute([$requestId]);
            } elseif ($pushbackStage === 'pending_accountant') {
                $db->prepare("UPDATE irs_requests SET custodian_id=NULL,custodian_actioned_at=NULL WHERE id=?")
                   ->execute([$requestId]);
            }
            break;

        // ── Return payment to accounts — store note
        case 'return_payment':
            $db->prepare("UPDATE irs_requests SET accounts_comment=CONCAT(COALESCE(accounts_comment,''),' | Returned: ',?) WHERE id=?")
               ->execute([$comment, $requestId]);
            break;

        // ── Accountant raises payment (requisition / caution)
        case 'raise_payment':
            $actualAmt     = (float)($_POST['actual_amount']   ?? $req['amount'] ?? 0);
            $payMethod     = trim($_POST['payment_method']      ?? '');
            $originBank    = trim($_POST['originating_bank']    ?? '');
            $validOrigins  = ['Access Bank','GT Bank','Lotus Bank','Sterling Bank','Keystone Bank','Fidelity Bank','First Bank'];
            if (!in_array($originBank, $validOrigins)) { echo json_encode(['ok'=>false,'error'=>'Please select a valid originating bank.']); exit; }
            // Beneficiary bank/account fields are captured at submission time and NOT updated here
            $db->prepare("UPDATE irs_requests SET
                payment_raised_by=?,payment_raised_at=NOW(),
                actual_amount=?,payment_method=?,paying_bank=?,
                accounts_comment=?,updated_at=NOW() WHERE id=?")
               ->execute([$user['id'], $actualAmt, $payMethod ?: null, $originBank, $comment ?: null, $requestId]);

            // Caution raises the payment straight from the eligibility stage —
            // the accountant is confirming the requester's eligibility advice and
            // raising payment in one step, so record the eligibility sign-off too.
            if ($fromStage === 'pending_eligibility') {
                $db->prepare("UPDATE irs_requests SET eligibility_reviewer_id=?,eligibility_reviewed_at=NOW(),eligibility_comment=? WHERE id=?")
                   ->execute([$user['id'], $comment ?: null, $requestId]);
            }
            // Save journal entries — clear existing first, then insert new lines
            $journalJson = trim($_POST['journal_entries'] ?? '');
            if ($journalJson !== '' && $journalJson !== '[]') {
                $jLines = json_decode($journalJson, true);
                if (is_array($jLines) && !empty($jLines)) {
                    $db->prepare("DELETE FROM irs_journal_entries WHERE request_id=?")->execute([$requestId]);
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
                    }
                }
            }
            break;

        // ── Petty cash: accountant enters the journal and hands over the cash.
        //    No prior approval stage — the cash is physically in the box — so the
        //    double entry is captured here, at the moment of disbursement, and the
        //    request moves on to a posting clerk.
        case 'process':
            $disbursedAmt = (float)($_POST['actual_amount'] ?? $req['amount'] ?? 0);
            if ($disbursedAmt <= 0) $disbursedAmt = (float)$req['amount'];
            $db->prepare("UPDATE irs_requests SET custodian_id=?,custodian_actioned_at=NOW(),custodian_comment=?,actual_amount=? WHERE id=?")
               ->execute([$user['id'], $comment ?: null, $disbursedAmt, $requestId]);

            // Journal entries — clear existing first, then insert the new lines
            $pcJson = trim($_POST['journal_entries'] ?? '');
            if ($pcJson !== '' && $pcJson !== '[]') {
                $pcLines = json_decode($pcJson, true);
                if (is_array($pcLines) && !empty($pcLines)) {
                    $db->prepare("DELETE FROM irs_journal_entries WHERE request_id=?")->execute([$requestId]);
                    $pcIns = $db->prepare("INSERT INTO irs_journal_entries
                        (request_id, line_no, account_code, account_name, description, debit, credit, created_by)
                        VALUES (?,?,?,?,?,?,?,?)");
                    foreach ($pcLines as $lineNo => $jl) {
                        $jAccName = trim($jl['account_name'] ?? '');
                        if ($jAccName === '') continue;
                        $pcIns->execute([
                            $requestId, $lineNo + 1,
                            trim($jl['account_code'] ?? '') ?: null,
                            $jAccName,
                            trim($jl['description'] ?? '') ?: null,
                            max(0, (float)($jl['debit']  ?? 0)),
                            max(0, (float)($jl['credit'] ?? 0)),
                            $user['id']
                        ]);
                    }
                }
            }
            break;

        // ── Post to Sage
        case 'post':
            $sageRef = trim($_POST['sage_ref'] ?? '');
            $db->prepare("UPDATE irs_requests SET sage_ref=?,sage_posted_by=?,sage_posted_at=NOW() WHERE id=?")
               ->execute([$sageRef, $user['id'], $requestId]);
            Auth::auditLog($user['id'], 'irs_posted', $req['ref_number'].' posted to Sage ref '.$sageRef);
            break;
    }

    $auditDetail = $transition['action_label'].' → '.$toStage.($comment ? '. '.$comment : '');
    irsAudit($db, $requestId, $user['id'], $action, $auditDetail, $ip);

    echo json_encode(['ok'=>true,'message'=>htmlspecialchars($transition['action_label']).' — done.']);

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Operation failed. Please try again.']);
}
