-- irs_workflow_revision.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- Implements revised IRS accounting workflow:
--   PAYMENT: HOD Accounts → MD → Accountant (post)
--   RETIREMENT: HOD Accounts (attaches journals) → MD → Accountant (post)
-- DO NOT upload this file to the web root.

-- ── PAYMENT REQUEST ────────────────────────────────────────────────────────

-- Ensure pending_hod_accounts → approve → pending_md for payment
-- (irs_payment_flow_fix.sql already does this; this is a safety net)
UPDATE irs_flow_transitions
  SET action_code='approve', action_label='Approve & Forward to MD', to_stage='pending_md'
  WHERE request_type='payment' AND from_stage='pending_hod_accounts' AND action_code IN ('approve','raise_payment');

-- MD approves → pending_post (accountant posts)
UPDATE irs_flow_transitions
  SET action_label='Approve Payment (Authorize)', to_stage='pending_post'
  WHERE request_type='payment' AND from_stage='pending_md' AND action_code='approve';

-- Insert if it doesn't exist yet
INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('payment', 'pending_md', 'approve', 'Approve Payment (Authorize)', 'pending_post', 'success', 0, 1),
('payment', 'pending_md', 'reject',  'Reject',                      'rejected',     'danger',  1, 2);

-- Ensure pending_post → post → completed for payment
INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('payment', 'pending_post', 'post', 'Post to Sage', 'completed', 'success', 0, 1);

-- Ensure reject at pending_hod_accounts for payment → rejected
INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('payment', 'pending_hod_accounts', 'reject', 'Reject Request', 'rejected', 'danger', 1, 2);


-- ── RETIREMENT ──────────────────────────────────────────────────────────────

-- HOD Accounts action changes from 'approve' to 'approve_journals'
-- so the journal entry form appears at this stage
UPDATE irs_flow_transitions
  SET action_code='approve_journals', action_label='Approve & Attach Journal Entries'
  WHERE request_type='retirement' AND from_stage='pending_hod_accounts' AND action_code='approve';

-- Ensure pending_hod_accounts reject → rejected for retirement
INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('retirement', 'pending_hod_accounts', 'reject', 'Reject Request', 'rejected', 'danger', 1, 2);

-- Ensure pending_md → approve → pending_post for retirement
UPDATE irs_flow_transitions
  SET action_label='Approve Retirement', to_stage='pending_post'
  WHERE request_type='retirement' AND from_stage='pending_md' AND action_code='approve';

INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('retirement', 'pending_md', 'approve', 'Approve Retirement', 'pending_post', 'success', 0, 1),
('retirement', 'pending_md', 'reject',  'Reject',             'rejected',     'danger',  1, 2);

-- Ensure pending_post → post → completed for retirement
INSERT IGNORE INTO irs_flow_transitions
  (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, sort_order) VALUES
('retirement', 'pending_post', 'post', 'Post to Sage', 'completed', 'success', 0, 1);


-- ── STAGE LABELS (ensure pending_post exists for retirement and payment) ──────
INSERT IGNORE INTO irs_flow_stages
  (request_type, stage_code, stage_label, actor_roles, stage_order, stage_color, is_initial, is_terminal) VALUES
('payment',    'pending_post', 'Post to Sage',     '["accountant","head_accounts","head_it"]', 80, '#059669', 0, 0),
('retirement', 'pending_post', 'Post to Sage',     '["accountant","head_accounts","head_it"]', 80, '#059669', 0, 0),
('payment',    'completed',    'Completed',         '[]', 90, '#059669', 0, 1),
('retirement', 'completed',    'Completed',         '[]', 90, '#059669', 0, 1);
