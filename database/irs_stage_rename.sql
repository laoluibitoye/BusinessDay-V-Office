-- irs_stage_rename.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- DO NOT upload this file to the web root.
--
-- Renames every IRS stage label to the "action + owner" system:
--   the label says WHAT HAPPENS; the department that owns it is rendered
--   separately by irs-detail.php from irs_flow_stages.actor_roles.
--
-- Why:
--   1. "HOD" is internal shorthand — the department name carries it without
--      the acronym.
--   2. pending_md read "MD / BDM Approval" and pending_payment_approval read
--      "Payment Approval (MD / BDM)" — two different stages, nearly identical
--      wording, stacked in a narrow sidebar. Now clearly distinct:
--      "Management Approval" vs "Payment Authorisation".
--
-- Labels are read at runtime by IrsFlow::stageLabel(), so no PHP change is
-- required. The defaultStageLabel() fallback map in lib/IrsFlow.php has been
-- updated to match — keep the two in sync if you edit either.

UPDATE irs_flow_stages SET stage_label = 'Returned for Correction'  WHERE stage_code = 'pending_corrections';
UPDATE irs_flow_stages SET stage_label = 'Eligibility Review'       WHERE stage_code = 'pending_eligibility';
UPDATE irs_flow_stages SET stage_label = 'Accounts Review'          WHERE stage_code IN ('pending_hod_accounts', 'pending_accounts');
UPDATE irs_flow_stages SET stage_label = 'Management Approval'      WHERE stage_code = 'pending_md';
UPDATE irs_flow_stages SET stage_label = 'Payment Prepared'         WHERE stage_code = 'pending_payment';
UPDATE irs_flow_stages SET stage_label = 'Payment Verification'     WHERE stage_code = 'pending_hod_accounts_payment';
UPDATE irs_flow_stages SET stage_label = 'Payment Authorisation'    WHERE stage_code = 'pending_payment_approval';
UPDATE irs_flow_stages SET stage_label = 'Cash Disbursement'        WHERE stage_code IN ('pending_accountant', 'pending_custodian');
UPDATE irs_flow_stages SET stage_label = 'Posted to Sage'           WHERE stage_code = 'pending_post';
UPDATE irs_flow_stages SET stage_label = 'Completed'                WHERE stage_code = 'completed';
UPDATE irs_flow_stages SET stage_label = 'Rejected'                 WHERE stage_code = 'rejected';
UPDATE irs_flow_stages SET stage_label = 'Draft'                    WHERE stage_code = 'draft';


-- ── Verify: every stage, per request type, in flow order ────────────────────
SELECT request_type, stage_order, stage_code, stage_label, actor_roles
FROM irs_flow_stages
ORDER BY request_type, stage_order;

-- ── Verify: nothing left with the old wording ──────────────────────────────
-- Should return zero rows.
SELECT request_type, stage_code, stage_label
FROM irs_flow_stages
WHERE stage_label LIKE '%HOD%'
   OR stage_label LIKE '%MD /%'
   OR stage_label LIKE '%BDM%';
