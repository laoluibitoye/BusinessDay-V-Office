-- irs_petty_cash_flow.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- DO NOT upload this file to the web root.
--
-- Rebuilds the petty cash flow to match how it actually works:
--
--   OLD:  Accountant Review --(process)--> Completed
--         Two stages, no journal entries, and the 50,000 figure was
--         enforced as a PER-REQUEST CAP ("amounts above this will be
--         rejected").
--
--   NEW:  Cash Disbursement --(disburse)--> Posted to Sage --(post)--> Completed
--         The accountant enters the double entry and hands over the cash in
--         one step (no prior approval -- the cash is physically there), then
--         a posting clerk posts it to Sage.
--
-- The 50,000 is the MONTHLY PETTY CASH FLOAT, not a per-request limit. It is
-- not enforced here: irs-detail.php shows the month's usage and warns when a
-- disbursement would exceed the float, but still allows it. The figure stays
-- in admin/irs-settings.php (irs_config.petty_cash_limit).
--
-- Consequence for the audit export: petty cash now carries journal entries, so
-- it appears in the journal-line file alongside every other request type.

-- ── STAGES ──────────────────────────────────────────────────────────────────

-- Disbursement stage: accountant passes the journal and hands over the cash
UPDATE irs_flow_stages
   SET stage_label = 'Cash Disbursement',
       actor_roles = '["accountant","head_accounts"]',
       stage_order = 1,
       stage_color = '#f59e0b'
 WHERE request_type = 'petty_cash' AND stage_code = 'pending_accountant';

-- New posting stage between disbursement and completion
INSERT INTO irs_flow_stages
  (request_type, stage_code, stage_label, actor_roles, stage_order, is_initial, is_terminal, stage_color)
VALUES
  ('petty_cash', 'pending_post', 'Posted to Sage', '["accountant","head_accounts","head_it"]', 2, 0, 0, '#059669')
ON DUPLICATE KEY UPDATE
  stage_label = VALUES(stage_label),
  actor_roles = VALUES(actor_roles),
  stage_order = VALUES(stage_order),
  stage_color = VALUES(stage_color);

-- Corrections stage, so a rejected petty cash request pushes back to the
-- requester rather than dying in a terminal state
INSERT INTO irs_flow_stages
  (request_type, stage_code, stage_label, actor_roles, stage_order, is_initial, is_terminal, stage_color)
VALUES
  ('petty_cash', 'pending_corrections', 'Returned for Correction', '[]', 0, 0, 0, '#f59e0b')
ON DUPLICATE KEY UPDATE
  stage_label = VALUES(stage_label);

-- Push completion past the new posting stage
UPDATE irs_flow_stages SET stage_order = 3 WHERE request_type = 'petty_cash' AND stage_code = 'completed';
UPDATE irs_flow_stages SET stage_order = 4 WHERE request_type = 'petty_cash' AND stage_code = 'rejected';


-- ── TRANSITIONS ─────────────────────────────────────────────────────────────

-- Disbursement now routes to posting instead of straight to completed.
-- requires_amount=1 so the actual cash handed over is captured.
UPDATE irs_flow_transitions
   SET action_label    = 'Disburse Cash & Enter Journals',
       to_stage        = 'pending_post',
       btn_style       = 'success',
       requires_amount = 1,
       sort_order      = 1
 WHERE request_type = 'petty_cash'
   AND from_stage   = 'pending_accountant'
   AND action_code  = 'process';

-- Safety net if the row above was never seeded
INSERT INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order)
VALUES
  ('petty_cash', 'pending_accountant', 'process', 'Disburse Cash & Enter Journals', 'pending_post', 'success', 0, 1, 0, 1)
ON DUPLICATE KEY UPDATE
  action_label    = VALUES(action_label),
  to_stage        = VALUES(to_stage),
  requires_amount = VALUES(requires_amount);

-- Posting clerk posts to Sage and closes the request
INSERT INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order)
VALUES
  ('petty_cash', 'pending_post', 'post', 'Post to Sage', 'completed', 'success', 0, 0, 1, 1)
ON DUPLICATE KEY UPDATE
  action_label      = VALUES(action_label),
  to_stage          = VALUES(to_stage),
  requires_sage_ref = VALUES(requires_sage_ref);

-- Reject at disbursement returns the request to the requester for correction
UPDATE irs_flow_transitions
   SET action_label = 'Return for Correction', to_stage = 'pending_corrections', sort_order = 2
 WHERE request_type = 'petty_cash'
   AND from_stage   = 'pending_accountant'
   AND action_code  = 'reject';

-- Requester resubmits after correcting
INSERT INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order)
VALUES
  ('petty_cash', 'pending_corrections', 'submit_correction', 'Resubmit', 'pending_accountant', 'primary', 0, 0, 0, 1)
ON DUPLICATE KEY UPDATE
  to_stage = VALUES(to_stage);


-- ── VERIFY ──────────────────────────────────────────────────────────────────

SELECT stage_order, stage_code, stage_label, actor_roles, is_terminal
FROM irs_flow_stages
WHERE request_type = 'petty_cash'
ORDER BY stage_order;

SELECT from_stage, action_code, action_label, to_stage, requires_amount, requires_sage_ref
FROM irs_flow_transitions
WHERE request_type = 'petty_cash'
ORDER BY from_stage, sort_order;
