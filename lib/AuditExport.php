<?php
// lib/AuditExport.php — builds the single-file auditor export.
//
// Shape: one row per journal line, transaction details repeated across the
// lines of a transaction. txn_amount and the per-transaction totals appear on
// the FIRST line only, so an auditor's SUM() never double-counts a multi-line
// journal. Filtering to "txn_amount is not blank" collapses the file into a
// transaction register — one file, both views.

class AuditExport {

    // Roles allowed to run the export. This is a personal-data disclosure
    // (payee names, bank accounts) so it stays tight.
    const EXPORT_ROLES = ['md', 'bdm', 'head_accounts', 'head_it'];

    // Financial year start month. HRI runs the calendar year; change this one
    // number if the year-end ever moves.
    const FY_START_MONTH = 1;

    /** Column groups, in file order. 'locked' groups cannot be switched off. */
    public static function groups(): array {
        return [
            'txn'    => ['name' => 'The request',      'locked' => true,
                         'desc' => 'Which request this is. Repeats on every line of the transaction.',
                         'cols' => ['txn_ref','txn_date','txn_type','status','description','requested_by']],
            'dept'   => ['name' => 'Department',       'locked' => false,
                         'desc' => 'Which department the spend belongs to.',
                         'cols' => ['department']],
            'money'  => ['name' => 'Amount & bank',    'locked' => true,
                         'desc' => 'How much, and which account the money left. The amount sits on the first line only so totals never double-count.',
                         'cols' => ['txn_amount','originating_bank','payment_method']],
            'payee'  => ['name' => 'Who was paid',     'locked' => false,
                         'desc' => 'Beneficiary name, bank and account number.',
                         'cols' => ['payee_name','payee_bank','payee_account']],
            'jrnl'   => ['name' => 'Journal entries',  'locked' => true,
                         'desc' => 'The double entry passed for the transaction, one row per line.',
                         'cols' => ['jrnl_line','line_type','jrnl_code','jrnl_account','jrnl_narration','debit','credit']],
            'totals' => ['name' => 'Journal totals',   'locked' => false,
                         'desc' => 'Totals per transaction, so the auditor can see at a glance that it balances.',
                         'cols' => ['txn_total_debit','txn_total_credit','txn_balanced']],
            'sage'   => ['name' => 'Sage reference',   'locked' => false,
                         'desc' => 'The reference used to find the same entry in Sage.',
                         'cols' => ['sage_ref','posted_by','date_posted']],
            'appr'   => ['name' => 'Who approved it',  'locked' => false,
                         'desc' => 'The full approval trail in one readable column.',
                         'cols' => ['approval_chain']],
            'dates'  => ['name' => 'Key dates',        'locked' => false,
                         'desc' => 'When it was raised, approved and paid.',
                         'cols' => ['date_requested','date_approved','date_paid']],
            'evid'   => ['name' => 'Supporting documents', 'locked' => false,
                         'desc' => 'Invoices and receipts attached to the request.',
                         'cols' => ['attachment_count','attachment_names']],
            'retire' => ['name' => 'Retirement link',  'locked' => false,
                         'desc' => 'For retirements — which advance it settles, and any over or under spend.',
                         'cols' => ['related_ref','advance_amount','variance']],
            'ctrl'   => ['name' => 'Returns history',  'locked' => false,
                         'desc' => 'How many times the request was sent back, and the last reason given.',
                         'cols' => ['pushback_count','last_rejection_reason']],
        ];
    }

    /**
     * Human column headings. These go into the CSV as well as on screen — the
     * file is read by people in Excel, not by a script, so snake_case field
     * names help nobody.
     */
    public static function labels(): array {
        return [
            'txn_ref'          => 'Reference',
            'txn_date'         => 'Date',
            'txn_type'         => 'Request Type',
            'status'           => 'Status',
            'description'      => 'Purpose',
            'requested_by'     => 'Requested By',
            'department'       => 'Department',
            'txn_amount'       => 'Amount',
            'originating_bank' => 'Paid From',
            'payment_method'   => 'Method',
            'payee_name'       => 'Payee',
            'payee_bank'       => 'Payee Bank',
            'payee_account'    => 'Payee Account',
            'jrnl_line'        => 'Line',
            'line_type'        => 'Line Type',
            'jrnl_code'        => 'Account Code',
            'jrnl_account'     => 'Account Name',
            'jrnl_narration'   => 'Narration',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'txn_total_debit'  => 'Total Debit',
            'txn_total_credit' => 'Total Credit',
            'txn_balanced'     => 'Balanced',
            'sage_ref'         => 'Sage Ref',
            'posted_by'        => 'Posted By',
            'date_posted'      => 'Date Posted',
            'approval_chain'   => 'Approved By',
            'date_requested'   => 'Date Requested',
            'date_approved'    => 'Date Approved',
            'date_paid'        => 'Date Paid',
            'attachment_count' => 'Documents',
            'attachment_names' => 'Document Names',
            'related_ref'      => 'Settles Reference',
            'advance_amount'   => 'Advance',
            'variance'         => 'Variance',
            'pushback_count'   => 'Times Returned',
            'last_rejection_reason' => 'Last Return Reason',
        ];
    }

    /** Ordered human headings for the given internal column keys. */
    public static function labelsFor(array $cols): array {
        $map = self::labels();
        $out = [];
        foreach ($cols as $c) $out[] = $map[$c] ?? $c;
        return $out;
    }

    /** Current financial year as [start, end] Y-m-d. */
    public static function currentFY(?int $offsetYears = 0): array {
        $m = self::FY_START_MONTH;
        $y = (int)date('Y');
        // Before the FY start month we are still in the FY that began last year
        if ((int)date('n') < $m) $y--;
        $y += (int)$offsetYears;
        $start = sprintf('%04d-%02d-01', $y, $m);
        $end   = date('Y-m-t', strtotime($start . ' +11 months'));
        return [$start, $end];
    }

    /**
     * Classify a COA code. The bank/cash range is what makes the originating
     * bank derivable — when money leaves, a bank or cash account is credited.
     */
    public static function lineType(?string $code): string {
        $c = (int)preg_replace('/\D/', '', (string)$code);
        if ($c === 0)                      return 'Other';
        if ($c >= 10000 && $c <= 18550)    return 'Bank/Cash';
        if ($c >= 18600 && $c <= 18999)    return 'Receivable';
        if ($c >= 21000 && $c <= 28999)    return 'Liability';
        if ($c >= 40000 && $c <= 49999)    return 'Income';
        if ($c >= 50000)                   return 'Expense';
        return 'Other';
    }

    /** Resolve the ordered column list for the selected groups. */
    public static function columnsFor(array $groupIds): array {
        $cols = [];
        foreach (self::groups() as $gid => $g) {
            if ($g['locked'] || in_array($gid, $groupIds, true)) {
                foreach ($g['cols'] as $c) $cols[] = $c;
            }
        }
        return $cols;
    }

    /**
     * Fetch every journal line in range, ordered so a transaction's lines are
     * contiguous. LEFT JOIN keeps journal-less transactions visible.
     */
    public static function fetch(PDO $db, string $from, string $to, bool $postedOnly, bool $includeNoJournal): array {
        $sql = "SELECT
                    r.id, r.ref_number, r.type, r.status, r.description, r.amount, r.actual_amount,
                    r.department, r.bank, r.account_name, r.account_number, r.payment_method,
                    r.paying_bank, r.related_ref, r.advance_amount, r.rejection_reason,
                    r.sage_ref, r.sage_posted_by, r.sage_posted_at, r.created_at,
                    r.requester_id, r.eligibility_reviewer_id, r.eligibility_reviewed_at,
                    r.accounts_reviewer_id, r.accounts_reviewed_at,
                    r.manager_approver_id, r.manager_approved_at,
                    r.payment_raised_by, r.payment_raised_at,
                    r.hod_payment_approved_by, r.hod_payment_approved_at,
                    r.payment_approval_by, r.payment_approval_at,
                    r.custodian_id, r.custodian_actioned_at,
                    COALESCE(r.sage_posted_at, r.created_at) AS txn_date_eff,
                    j.line_no, j.account_code, j.account_name AS j_account,
                    j.description AS j_narration, j.debit, j.credit,
                    a.att_count, a.att_names,
                    COALESCE(pb.pb_count, 0) AS pb_count
                FROM irs_requests r
                LEFT JOIN irs_journal_entries j ON j.request_id = r.id
                LEFT JOIN (SELECT request_id, COUNT(*) att_count,
                                  GROUP_CONCAT(filename ORDER BY id SEPARATOR '; ') att_names
                           FROM irs_attachments GROUP BY request_id) a ON a.request_id = r.id
                LEFT JOIN (SELECT request_id, COUNT(*) pb_count
                           FROM irs_audit_log WHERE action = 'reject' GROUP BY request_id) pb ON pb.request_id = r.id
                WHERE DATE(COALESCE(r.sage_posted_at, r.created_at)) BETWEEN ? AND ?
                  AND r.status NOT IN ('draft')";
        $args = [$from, $to];

        if ($postedOnly) {
            $sql .= " AND r.status = 'completed' AND r.sage_ref IS NOT NULL AND r.sage_ref <> ''";
        }
        if (!$includeNoJournal) {
            $sql .= " AND j.id IS NOT NULL";
        }
        $sql .= " ORDER BY COALESCE(r.sage_posted_at, r.created_at) ASC, r.ref_number ASC, j.line_no ASC";

        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** id => name map for every user referenced by the rows. */
    public static function userMap(PDO $db): array {
        $map = [];
        try {
            foreach ($db->query("SELECT id, name FROM users") as $u) $map[(int)$u['id']] = $u['name'];
        } catch (Exception $e) {}
        return $map;
    }

    /** "Chidi Okafor (Accounts) → Mrs. Ogunlusi (Management)" */
    public static function approvalChain(array $r, array $users): string {
        $stages = [
            ['eligibility_reviewer_id',  'eligibility_reviewed_at',  'Eligibility'],
            ['accounts_reviewer_id',     'accounts_reviewed_at',     'Accounts'],
            ['manager_approver_id',      'manager_approved_at',      'Management'],
            ['payment_raised_by',        'payment_raised_at',        'Payment Raised'],
            ['hod_payment_approved_by',  'hod_payment_approved_at',  'Payment Verified'],
            ['payment_approval_by',      'payment_approval_at',      'Payment Authorised'],
            ['custodian_id',             'custodian_actioned_at',    'Disbursed'],
            ['sage_posted_by',           'sage_posted_at',           'Posted'],
        ];
        $parts = [];
        foreach ($stages as [$idCol, $atCol, $label]) {
            $uid = (int)($r[$idCol] ?? 0);
            if (!$uid || empty($r[$atCol])) continue;
            $parts[] = ($users[$uid] ?? 'Unknown') . ' (' . $label . ')';
        }
        return implode(' → ', $parts);
    }

    /**
     * Group flat rows into transactions and compute the derived per-transaction
     * values: totals, balance, and the originating bank.
     */
    public static function buildTransactions(array $rows, array $users): array {
        $txns = [];
        foreach ($rows as $r) {
            $ref = $r['ref_number'];
            if (!isset($txns[$ref])) {
                $txns[$ref] = ['head' => $r, 'lines' => [], 'dr' => 0.0, 'cr' => 0.0];
            }
            if ($r['line_no'] !== null) {
                $txns[$ref]['lines'][] = $r;
                $txns[$ref]['dr'] += (float)$r['debit'];
                $txns[$ref]['cr'] += (float)$r['credit'];
            }
        }
        foreach ($txns as $ref => &$t) {
            $t['chain'] = self::approvalChain($t['head'], $users);
            $t['bank']  = self::originatingBank($t['head'], $t['lines']);
            $t['balanced'] = empty($t['lines'])
                ? 'No journals'
                : (abs($t['dr'] - $t['cr']) < 0.01 ? 'Yes' : 'Variance ' . number_format(abs($t['dr'] - $t['cr']), 2, '.', ''));
        }
        unset($t);
        return $txns;
    }

    /**
     * The bank the money actually left. paying_bank is only ever written by
     * raise_payment (requisition and caution), so for payment, petty cash and
     * retirement it is derived from the journal: the credited bank/cash line.
     */
    public static function originatingBank(array $head, array $lines): string {
        $best = null; $bestAmt = -1;
        foreach ($lines as $l) {
            if (self::lineType($l['account_code']) !== 'Bank/Cash') continue;
            $cr = (float)$l['credit'];
            if ($cr > $bestAmt) { $bestAmt = $cr; $best = $l['j_account']; }
        }
        if ($best !== null && $bestAmt > 0) return $best;
        if (!empty($head['paying_bank']))   return $head['paying_bank'];
        if ($best !== null)                 return $best;
        return '(not recorded)';
    }

    /** Build one output row. $isFirst controls the line-1-only fields. */
    public static function row(array $cols, array $t, ?array $line, bool $isFirst, int $lineCount, int $lineIdx, array $users): array {
        $h    = $t['head'];
        $type = ['requisition'=>'Requisition','caution'=>'Caution Fee','payment'=>'Payment Request',
                 'petty_cash'=>'Petty Cash','retirement'=>'Retirement'][$h['type']] ?? $h['type'];
        $amt  = $h['actual_amount'] !== null && (float)$h['actual_amount'] > 0
                ? (float)$h['actual_amount'] : (float)$h['amount'];
        $adv  = $h['advance_amount'] !== null ? (float)$h['advance_amount'] : null;

        $v = [
            'txn_ref'          => $h['ref_number'],
            'txn_date'         => $h['txn_date_eff'] ? date('Y-m-d', strtotime($h['txn_date_eff'])) : '',
            'txn_type'         => $type,
            'status'           => $h['status'],
            'description'      => $h['description'],
            'requested_by'     => $users[(int)$h['requester_id']] ?? '',
            'department'       => $h['department'],
            'txn_amount'       => $isFirst ? number_format($amt, 2, '.', '') : '',
            'originating_bank' => $t['bank'],
            'payment_method'   => $h['payment_method'] ?? '',
            'payee_name'       => $h['account_name'] ?? '',
            'payee_bank'       => $h['bank'] ?? '',
            'payee_account'    => $h['account_number'] ?? '',
            'jrnl_line'        => $line ? ($lineIdx . ' of ' . $lineCount) : '—',
            'line_type'        => $line ? self::lineType($line['account_code']) : '—',
            'jrnl_code'        => $line ? ($line['account_code'] ?? '') : '',
            'jrnl_account'     => $line ? $line['j_account'] : '',
            'jrnl_narration'   => $line ? ($line['j_narration'] ?? '') : '',
            'debit'            => $line ? number_format((float)$line['debit'], 2, '.', '')  : '',
            'credit'           => $line ? number_format((float)$line['credit'], 2, '.', '') : '',
            'txn_total_debit'  => $isFirst ? number_format($t['dr'], 2, '.', '') : '',
            'txn_total_credit' => $isFirst ? number_format($t['cr'], 2, '.', '') : '',
            'txn_balanced'     => $isFirst ? $t['balanced'] : '',
            'sage_ref'         => $h['sage_ref'] ?? '',
            'posted_by'        => !empty($h['sage_posted_by']) ? ($users[(int)$h['sage_posted_by']] ?? '') : '',
            'date_posted'      => $h['sage_posted_at'] ? date('Y-m-d H:i', strtotime($h['sage_posted_at'])) : '',
            'approval_chain'   => $t['chain'],
            'date_requested'   => $h['created_at'] ? date('Y-m-d H:i', strtotime($h['created_at'])) : '',
            'date_approved'    => !empty($h['manager_approved_at']) ? date('Y-m-d H:i', strtotime($h['manager_approved_at'])) : '',
            'date_paid'        => !empty($h['payment_approval_at']) ? date('Y-m-d H:i', strtotime($h['payment_approval_at']))
                                  : (!empty($h['custodian_actioned_at']) ? date('Y-m-d H:i', strtotime($h['custodian_actioned_at'])) : ''),
            'attachment_count' => (string)(int)($h['att_count'] ?? 0),
            'attachment_names' => $h['att_names'] ?? '',
            'related_ref'      => $h['related_ref'] ?? '',
            'advance_amount'   => $adv !== null ? number_format($adv, 2, '.', '') : '',
            'variance'         => ($adv !== null && $isFirst) ? number_format($amt - $adv, 2, '.', '') : '',
            'pushback_count'   => (string)(int)($h['pb_count'] ?? 0),
            'last_rejection_reason' => $h['rejection_reason'] ?? '',
        ];

        $out = [];
        foreach ($cols as $c) $out[] = $v[$c] ?? '';
        return $out;
    }
}
