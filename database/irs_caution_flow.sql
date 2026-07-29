-- irs_caution_flow.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- DO NOT upload this file to the web root.
--
-- Collapses the caution flow from seven stages to four.
--
-- OLD (after irs_hod_payment_migration.sql was applied):
--   1 Eligibility Check          accountant / head_accounts
--   2 HOD Accounts Review        head_accounts        <-- approves
--   3 MD / BDM Approval          md / bdm             <-- approves
--   4 Payment Raise              accountant
--   5 HOD Accounts: Payment      head_accounts        <-- approves AGAIN
--   6 Payment Approval MD/BDM    md / bdm             <-- approves AGAIN
--   7 Post to Sage               accountant
--
-- Head Accounts signed off twice and Management signed off twice, on the same
-- request, for the same money.
--
-- NEW:
--   1 Eligibility & Payment      accountant     confirms eligibility AND raises
--                                               the payment with journal entries
--                                               in a single step
--   2 Accounts Review            head_accounts  reviews and approves
--   3 Management Approval        md / bdm       approves and pays
--   4 Posted to Sage             accountant     posts and closes
--
-- The requester states on submission whether the candidate is eligible, so the
-- accountant is confirming that advice rather than starting a fresh assessment
-- — which is why it collapses into the same step as raising the payment.
--
-- Stages pending_payment, pending_hod_accounts_payment and
-- pending_payment_approval are retired for caution only. They are left in place
-- for requisition. Any caution request currently sitting in one of them is
-- migrated at the foot of this file.

-- ── STAGES ──────────────────────────────────────────────────────────────────

UPDATE irs_flow_stages
   SET stage_label = 'Eligibility & Payment',
       actor_roles = '["accountant","head_accounts"]',
       stage_order = 1
 WHERE request_type = 'caution' AND stage_code = 'pending_eligibility';

UPDATE irs_flow_stages
   SET stage_label = 'Accounts Review',
       actor_roles = '["head_accounts"]',
       stage_order = 2
 WHERE request_type = 'caution' AND stage_code = 'pending_hod_accounts';

UPDATE irs_flow_stages
   SET stage_label = 'Management Approval',
       actor_roles = '["md","bdm"]',
       stage_order = 3
 WHERE request_type = 'caution' AND stage_code = 'pending_md';

UPDATE irs_flow_stages
   SET stage_label = 'Posted to Sage',
       actor_roles = '["accountant","head_accounts","head_it"]',
       stage_order = 4
 WHERE request_type = 'caution' AND stage_code = 'pending_post';

UPDATE irs_flow_stages SET stage_order = 5 WHERE request_type = 'caution' AND stage_code = 'completed';
UPDATE irs_flow_stages SET stage_order = 6 WHERE request_type = 'caution' AND stage_code = 'rejected';

-- Migrate any in-flight caution requests off the retired stages BEFORE the
-- stage rows are removed, so nothing is stranded on a stage that no longer
-- exists. Mapped to the nearest equivalent in the new flow.
UPDATE irs_requests SET status = 'pending_eligibility'
 WHERE type = 'caution' AND status = 'pending_payment';
UPDATE irs_requests SET status = 'pending_hod_accounts'
 WHERE type = 'caution' AND status = 'pending_hod_accounts_payment';
UPDATE irs_requests SET status = 'pending_md'
 WHERE type = 'caution' AND status = 'pending_payment_approval';

DELETE FROM irs_flow_stages
 WHERE request_type = 'caution'
   AND stage_code IN ('pending_payment', 'pending_hod_accounts_payment', 'pending_payment_approval');


-- ── TRANSITIONS ─────────────────────────────────────────────────────────────

-- Clear every caution transition, then rebuild — simpler and safer than
-- patching around the retired stages one by one.
DELETE FROM irs_flow_transitions WHERE request_type = 'caution';

INSERT INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order)
VALUES
  -- 1. Accountant: confirm the eligibility advice and raise the payment with journals.
  --    raise_payment is reused because it already carries the amount, payment
  --    method, originating bank and journal entry form.
  ('caution', 'pending_eligibility', 'raise_payment',   'Confirm Eligible & Raise Payment', 'pending_hod_accounts', 'success', 0, 1, 0, 1),
  ('caution', 'pending_eligibility', 'mark_ineligible', 'Mark Ineligible',                  'rejected',             'danger',  1, 0, 0, 2),

  -- 2. Head Accounts reviews the payment and journals
  ('caution', 'pending_hod_accounts', 'approve', 'Approve & Forward to Management', 'pending_md',          'success', 0, 0, 0, 1),
  ('caution', 'pending_hod_accounts', 'reject',  'Return to Accounts',              'pending_eligibility', 'danger',  1, 0, 0, 2),

  -- 3. Management approves and releases payment
  ('caution', 'pending_md', 'approve_payment', 'Approve & Pay',      'pending_post',         'success', 0, 0, 0, 1),
  ('caution', 'pending_md', 'reject',          'Return for Review',  'pending_hod_accounts', 'danger',  1, 0, 0, 2),

  -- 4. Posting clerk closes it out
  ('caution', 'pending_post', 'post', 'Post to Sage', 'completed', 'success', 0, 0, 1, 1),

  -- Requester resubmits after being sent back
  ('caution', 'pending_corrections', 'submit_correction', 'Resubmit', 'pending_eligibility', 'primary', 0, 0, 0, 1);


-- ── VERIFY ──────────────────────────────────────────────────────────────────

-- Expect exactly 4 working stages plus completed / rejected / corrections
SELECT stage_order, stage_code, stage_label, actor_roles
FROM irs_flow_stages
WHERE request_type = 'caution'
ORDER BY stage_order;

SELECT from_stage, action_code, action_label, to_stage, requires_amount, requires_sage_ref
FROM irs_flow_transitions
WHERE request_type = 'caution'
ORDER BY FIELD(from_stage,'pending_eligibility','pending_hod_accounts','pending_md','pending_post','pending_corrections'), sort_order;

-- Should return zero rows: nothing left on a retired stage
SELECT ref_number, status FROM irs_requests
WHERE type = 'caution'
  AND status IN ('pending_payment','pending_hod_accounts_payment','pending_payment_approval');
