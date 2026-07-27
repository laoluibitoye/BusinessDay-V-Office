-- irs_benef_payment_migration.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- 1. Adds beneficiaries_json column for multi-row beneficiary support
-- 2. Changes Payment Request flow at pending_hod_accounts to use raise_payment action
--    so the journal-entry form appears (same as Requisition / Caution)
-- DO NOT upload this file to the web root — run it in phpMyAdmin then delete.

-- ── 1. New column on irs_requests ─────────────────────────────────────────
ALTER TABLE irs_requests
  ADD COLUMN beneficiaries_json TEXT NULL AFTER account_number;

-- ── 2. Payment Request: change action_code at pending_hod_accounts ─────────
-- This makes the "Raise Payment" form (journal entries + originating bank)
-- appear for head_accounts when reviewing a Payment Request, exactly like
-- it already appears for accountants on Requisition and Caution.
UPDATE irs_flow_transitions
  SET action_code = 'raise_payment',
      action_label = 'Raise Payment & Post Journals'
  WHERE request_type = 'payment'
    AND from_stage   = 'pending_hod_accounts'
    AND action_code  = 'approve';
