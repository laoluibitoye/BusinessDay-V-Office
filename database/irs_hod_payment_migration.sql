-- irs_hod_payment_migration.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- Adds HOD Accounts payment-approval stage to IRS workflows.
-- DO NOT upload this file to the web root — run it in phpMyAdmin then delete.

-- ── 1. New columns on irs_requests ───────────────────────────────────────────
ALTER TABLE irs_requests
  ADD COLUMN hod_payment_approved_by  INT      NULL,
  ADD COLUMN hod_payment_approved_at  DATETIME NULL,
  ADD COLUMN hod_payment_comment      TEXT     NULL;

-- ── 2. Space out existing stage_orders to leave room for the new stage ────────
-- (current orders are small integers 0-8; multiply by 10 to leave gaps)
UPDATE irs_flow_stages SET stage_order = stage_order * 10
  WHERE request_type IN ('requisition', 'caution', 'payment');

-- ── 3. Insert new stage rows ──────────────────────────────────────────────────
-- REQUISITION: new stage sits between pending_payment (30) and pending_payment_approval (40)
-- CAUTION:     new stage sits between pending_payment (40) and pending_payment_approval (50)
-- PAYMENT:     new stage sits between pending_hod_accounts (10) and pending_md (20)
INSERT IGNORE INTO irs_flow_stages
  (request_type, stage_code, stage_label, actor_roles, stage_order, stage_color) VALUES
('requisition', 'pending_hod_accounts_payment', 'HOD Accounts: Payment Approval', '["head_accounts"]', 35, '#d97706'),
('caution',     'pending_hod_accounts_payment', 'HOD Accounts: Payment Approval', '["head_accounts"]', 45, '#d97706'),
('payment',     'pending_hod_accounts_payment', 'HOD Accounts: Payment Approval', '["head_accounts"]', 15, '#d97706');

-- ── 4. Re-route REQUISITION: pending_payment → NEW (was pending_payment_approval) ──
UPDATE irs_flow_transitions
  SET to_stage = 'pending_hod_accounts_payment'
  WHERE request_type = 'requisition' AND from_stage = 'pending_payment' AND action_code = 'raise_payment';

INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('requisition', 'pending_hod_accounts_payment', 'approve', 'Approve Payment & Journals', 'pending_payment_approval', 'success', 0, 1),
('requisition', 'pending_hod_accounts_payment', 'reject',  'Return for Correction',       'pending_payment',          'danger',  1, 2);

-- ── 5. Re-route CAUTION: pending_payment → NEW (was pending_payment_approval) ────
UPDATE irs_flow_transitions
  SET to_stage = 'pending_hod_accounts_payment'
  WHERE request_type = 'caution' AND from_stage = 'pending_payment' AND action_code = 'raise_payment';

INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('caution', 'pending_hod_accounts_payment', 'approve', 'Approve Payment & Journals', 'pending_payment_approval', 'success', 0, 1),
('caution', 'pending_hod_accounts_payment', 'reject',  'Return for Correction',       'pending_payment',          'danger',  1, 2);

-- ── 6. Re-route PAYMENT REQUEST: pending_hod_accounts approve → NEW (was pending_md) ─
UPDATE irs_flow_transitions
  SET to_stage = 'pending_hod_accounts_payment'
  WHERE request_type = 'payment' AND from_stage = 'pending_hod_accounts' AND action_code = 'approve';

INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('payment', 'pending_hod_accounts_payment', 'approve', 'Approve Payment & Journals', 'pending_md',            'success', 0, 1),
('payment', 'pending_hod_accounts_payment', 'reject',  'Return for Correction',       'pending_hod_accounts', 'danger',  1, 2);
