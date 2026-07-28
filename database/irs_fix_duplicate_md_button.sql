-- irs_fix_duplicate_md_button.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
--
-- Problem: irs_workflow_revision.sql inserted an 'approve' transition at
-- (payment, pending_md) because its UPDATE targeted action_code='approve'
-- which didn't exist. The INSERT IGNORE then added a second row alongside
-- the correct 'approve_payment' row — giving MD two green buttons.
--
-- Fix: remove the spurious 'approve' row; keep only 'approve_payment'.

DELETE FROM irs_flow_transitions
WHERE request_type = 'payment'
  AND from_stage   = 'pending_md'
  AND action_code  = 'approve';

-- Verify: should return exactly 2 rows (approve_payment + reject)
SELECT action_code, action_label, to_stage, btn_style
FROM irs_flow_transitions
WHERE request_type = 'payment' AND from_stage = 'pending_md'
ORDER BY sort_order;
