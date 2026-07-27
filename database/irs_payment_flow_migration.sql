-- IRS Payment Flow Enhancement Migration
-- Run once in phpMyAdmin on hrindexx_hri_webmail
-- Adds 'pending_payment' status for the Requisition payment processing stage

-- Step 1: Extend the status ENUM to include pending_payment
ALTER TABLE irs_requests MODIFY COLUMN status ENUM(
    'draft',
    'pending_eligibility',
    'pending_custodian',
    'pending_accounts',
    'accounts_approved',
    'approved',
    'pending_payment',
    'paid',
    'disbursed',
    'refund_required',
    'additional_payment',
    'posted',
    'rejected',
    'returned',
    'ineligible',
    'exceeds_limit'
) NOT NULL DEFAULT 'pending_accounts';

-- Step 2: Add columns to track who confirmed the payment (accounts team)
ALTER TABLE irs_requests
    ADD COLUMN IF NOT EXISTS payment_confirmed_by INT NULL AFTER paid_at,
    ADD COLUMN IF NOT EXISTS payment_confirmed_at DATETIME NULL AFTER payment_confirmed_by;

-- Verify
SELECT 'Migration complete. pending_payment status and payment confirmation columns added.' AS result;
