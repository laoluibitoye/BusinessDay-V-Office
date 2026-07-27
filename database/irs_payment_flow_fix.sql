-- irs_payment_flow_fix.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- Fixes Payment Request flow:
--   • Reverts pending_hod_accounts action from raise_payment → approve
--   • Routes directly to pending_md (no raise_payment stage for payment type)
-- DO NOT upload this file to the web root — run in phpMyAdmin then delete.

UPDATE irs_flow_transitions
  SET action_code  = 'approve',
      action_label = 'Approve & Forward to MD',
      to_stage     = 'pending_md'
  WHERE request_type = 'payment'
    AND from_stage   = 'pending_hod_accounts'
    AND action_code  = 'raise_payment';

-- Ensure the reject transition at pending_hod_accounts for payment goes to rejected
-- (If it already exists and is correct this is a no-op)
UPDATE irs_flow_transitions
  SET to_stage = 'rejected'
  WHERE request_type = 'payment'
    AND from_stage   = 'pending_hod_accounts'
    AND action_code  = 'reject'
    AND to_stage    <> 'rejected';

-- Ensure the reject transition at pending_md for payment also goes to rejected
UPDATE irs_flow_transitions
  SET to_stage = 'rejected'
  WHERE request_type = 'payment'
    AND from_stage   = 'pending_md'
    AND action_code  = 'reject'
    AND to_stage    <> 'rejected';
