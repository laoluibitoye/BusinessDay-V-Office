-- IRS Flow Engine Migration
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- Adds configurable workflow tables + migrates existing status values

-- ══════════════════════════════════════════════════════════════════
-- 1. ALTER irs_requests: status ENUM → VARCHAR(50)
-- ══════════════════════════════════════════════════════════════════
ALTER TABLE irs_requests
    MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending_hod_accounts';

-- 2. Add new columns needed by the expanded flow
ALTER TABLE irs_requests
    ADD COLUMN IF NOT EXISTS payment_method      VARCHAR(50)   DEFAULT NULL AFTER actual_amount,
    ADD COLUMN IF NOT EXISTS payee_name          VARCHAR(150)  DEFAULT NULL AFTER payment_method,
    ADD COLUMN IF NOT EXISTS payment_raised_by   INT           DEFAULT NULL AFTER payee_name,
    ADD COLUMN IF NOT EXISTS payment_raised_at   DATETIME      DEFAULT NULL AFTER payment_raised_by,
    ADD COLUMN IF NOT EXISTS payment_approval_by INT           DEFAULT NULL AFTER payment_raised_at,
    ADD COLUMN IF NOT EXISTS payment_approval_at DATETIME      DEFAULT NULL AFTER payment_approval_by;

-- Also add journal_ref for Payment Request type
ALTER TABLE irs_requests
    ADD COLUMN IF NOT EXISTS journal_ref         VARCHAR(100)  DEFAULT NULL AFTER sage_ref;

-- 3. Migrate old status values → new stage codes
UPDATE irs_requests SET status = 'pending_hod_accounts' WHERE status IN ('pending_accounts');
UPDATE irs_requests SET status = 'pending_md'           WHERE status IN ('accounts_approved');
UPDATE irs_requests SET status = 'pending_accountant'   WHERE status IN ('pending_custodian');
UPDATE irs_requests SET status = 'pending_post'         WHERE status IN ('paid', 'disbursed', 'approved', 'refund_required', 'additional_payment');
UPDATE irs_requests SET status = 'completed'            WHERE status IN ('posted');
UPDATE irs_requests SET status = 'rejected'             WHERE status IN ('ineligible', 'exceeds_limit', 'returned');
-- pending_eligibility and pending_payment stay unchanged (same code, same meaning)
-- draft and rejected stay unchanged

-- ══════════════════════════════════════════════════════════════════
-- 4. CREATE irs_flow_stages
-- ══════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS irs_flow_stages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    request_type VARCHAR(50)  NOT NULL,
    stage_code   VARCHAR(50)  NOT NULL,
    stage_label  VARCHAR(120) NOT NULL,
    actor_roles  VARCHAR(500) NOT NULL DEFAULT '[]',
    stage_order  TINYINT      NOT NULL DEFAULT 0,
    is_initial   TINYINT      NOT NULL DEFAULT 0,
    is_terminal  TINYINT      NOT NULL DEFAULT 0,
    stage_color  VARCHAR(20)           DEFAULT '#64748b',
    UNIQUE KEY uk_type_stage (request_type, stage_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- 5. CREATE irs_flow_transitions
-- ══════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS irs_flow_transitions (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    request_type       VARCHAR(50)  NOT NULL,
    from_stage         VARCHAR(50)  NOT NULL,
    action_code        VARCHAR(50)  NOT NULL,
    action_label       VARCHAR(100) NOT NULL,
    to_stage           VARCHAR(50)  NOT NULL,
    btn_style          VARCHAR(20)  NOT NULL DEFAULT 'primary',
    requires_note      TINYINT      NOT NULL DEFAULT 0,
    requires_amount    TINYINT      NOT NULL DEFAULT 0,
    requires_sage_ref  TINYINT      NOT NULL DEFAULT 0,
    sort_order         TINYINT      NOT NULL DEFAULT 0,
    updated_by         INT                   DEFAULT NULL,
    updated_at         DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_transition (request_type, from_stage, action_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════
-- 6. SEED: REQUISITION
-- ══════════════════════════════════════════════════════════════════
INSERT IGNORE INTO irs_flow_stages (request_type, stage_code, stage_label, actor_roles, stage_order, is_initial, is_terminal, stage_color) VALUES
('requisition', 'draft',                    'Draft',                        '[]',                          0, 1, 0, '#94a3b8'),
('requisition', 'pending_hod_accounts',     'HOD Accounts Review',          '["head_accounts"]',           1, 0, 0, '#f59e0b'),
('requisition', 'pending_md',               'MD / BDM Approval',            '["md","bdm"]',                2, 0, 0, '#3b82f6'),
('requisition', 'pending_payment',          'Payment Raise',                 '["accountant"]',              3, 0, 0, '#8b5cf6'),
('requisition', 'pending_payment_approval', 'Payment Approval (MD / BDM)',   '["md","bdm"]',                4, 0, 0, '#0891b2'),
('requisition', 'pending_post',             'Post to Sage',                  '["accountant"]',              5, 0, 0, '#059669'),
('requisition', 'completed',               'Completed',                     '[]',                          6, 0, 1, '#059669'),
('requisition', 'rejected',                'Rejected / Withdrawn',          '[]',                          7, 0, 1, '#ef4444');

INSERT IGNORE INTO irs_flow_transitions (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order) VALUES
('requisition', 'pending_hod_accounts',     'approve',          'Approve',               'pending_md',               'success', 0, 0, 0, 1),
('requisition', 'pending_hod_accounts',     'reject',           'Reject',                'rejected',                 'danger',  1, 0, 0, 2),
('requisition', 'pending_md',               'approve',          'Approve',               'pending_payment',          'success', 0, 0, 0, 1),
('requisition', 'pending_md',               'reject',           'Reject',                'rejected',                 'danger',  1, 0, 0, 2),
('requisition', 'pending_payment',          'raise_payment',    'Raise Payment',         'pending_payment_approval', 'primary', 0, 1, 0, 1),
('requisition', 'pending_payment_approval', 'approve_payment',  'Approve (Paid)',         'pending_post',             'success', 0, 0, 0, 1),
('requisition', 'pending_payment_approval', 'return_payment',   'Return to Accounts',    'pending_payment',          'warning', 1, 0, 0, 2),
('requisition', 'pending_post',             'post',             'Post & Close',          'completed',                'success', 0, 0, 1, 1);

-- ══════════════════════════════════════════════════════════════════
-- 7. SEED: CAUTION
-- ══════════════════════════════════════════════════════════════════
INSERT IGNORE INTO irs_flow_stages (request_type, stage_code, stage_label, actor_roles, stage_order, is_initial, is_terminal, stage_color) VALUES
('caution', 'draft',                    'Draft',                       '[]',                                    0, 1, 0, '#94a3b8'),
('caution', 'pending_eligibility',      'Eligibility Check',           '["accountant","head_accounts"]',        1, 0, 0, '#f59e0b'),
('caution', 'pending_hod_accounts',     'HOD Accounts Review',         '["head_accounts"]',                     2, 0, 0, '#f97316'),
('caution', 'pending_md',               'MD / BDM Approval',           '["md","bdm"]',                          3, 0, 0, '#3b82f6'),
('caution', 'pending_payment',          'Payment Raise',                '["accountant"]',                        4, 0, 0, '#8b5cf6'),
('caution', 'pending_payment_approval', 'Payment Approval (MD / BDM)', '["md","bdm"]',                          5, 0, 0, '#0891b2'),
('caution', 'pending_post',             'Post to Sage',                 '["accountant"]',                        6, 0, 0, '#059669'),
('caution', 'completed',               'Completed',                    '[]',                                    7, 0, 1, '#059669'),
('caution', 'rejected',                'Rejected / Withdrawn',         '[]',                                    8, 0, 1, '#ef4444');

INSERT IGNORE INTO irs_flow_transitions (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order) VALUES
('caution', 'pending_eligibility',      'confirm_eligible', 'Confirm Eligible',      'pending_hod_accounts',     'success', 0, 0, 0, 1),
('caution', 'pending_eligibility',      'mark_ineligible',  'Mark Ineligible',       'rejected',                 'danger',  1, 0, 0, 2),
('caution', 'pending_hod_accounts',     'approve',          'Approve',               'pending_md',               'success', 0, 0, 0, 1),
('caution', 'pending_hod_accounts',     'reject',           'Reject',                'rejected',                 'danger',  1, 0, 0, 2),
('caution', 'pending_md',               'approve',          'Approve',               'pending_payment',          'success', 0, 0, 0, 1),
('caution', 'pending_md',               'reject',           'Reject',                'rejected',                 'danger',  1, 0, 0, 2),
('caution', 'pending_payment',          'raise_payment',    'Raise Payment',         'pending_payment_approval', 'primary', 0, 1, 0, 1),
('caution', 'pending_payment_approval', 'approve_payment',  'Approve (Paid)',         'pending_post',             'success', 0, 0, 0, 1),
('caution', 'pending_payment_approval', 'return_payment',   'Return to Accounts',    'pending_payment',          'warning', 1, 0, 0, 2),
('caution', 'pending_post',             'post',             'Post & Close',          'completed',                'success', 0, 0, 1, 1);

-- ══════════════════════════════════════════════════════════════════
-- 8. SEED: RETIREMENT
-- ══════════════════════════════════════════════════════════════════
INSERT IGNORE INTO irs_flow_stages (request_type, stage_code, stage_label, actor_roles, stage_order, is_initial, is_terminal, stage_color) VALUES
('retirement', 'draft',                'Draft',               '[]',                 0, 1, 0, '#94a3b8'),
('retirement', 'pending_hod_accounts', 'HOD Accounts Review', '["head_accounts"]',  1, 0, 0, '#f59e0b'),
('retirement', 'pending_md',           'MD / BDM Approval',   '["md","bdm"]',       2, 0, 0, '#3b82f6'),
('retirement', 'pending_post',         'Post to Sage',         '["accountant"]',     3, 0, 0, '#059669'),
('retirement', 'completed',           'Completed',            '[]',                 4, 0, 1, '#059669'),
('retirement', 'rejected',            'Rejected / Withdrawn', '[]',                 5, 0, 1, '#ef4444');

INSERT IGNORE INTO irs_flow_transitions (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order) VALUES
('retirement', 'pending_hod_accounts', 'approve', 'Approve',      'pending_md',   'success', 0, 0, 0, 1),
('retirement', 'pending_hod_accounts', 'reject',  'Reject',       'rejected',     'danger',  1, 0, 0, 2),
('retirement', 'pending_md',           'approve', 'Approve',      'pending_post', 'success', 0, 0, 0, 1),
('retirement', 'pending_md',           'reject',  'Reject',       'rejected',     'danger',  1, 0, 0, 2),
('retirement', 'pending_post',         'post',    'Post & Close', 'completed',    'success', 0, 0, 1, 1);

-- ══════════════════════════════════════════════════════════════════
-- 9. SEED: PAYMENT REQUEST (accountant-raised)
-- ══════════════════════════════════════════════════════════════════
INSERT IGNORE INTO irs_flow_stages (request_type, stage_code, stage_label, actor_roles, stage_order, is_initial, is_terminal, stage_color) VALUES
('payment', 'draft',                'Draft',               '[]',                 0, 1, 0, '#94a3b8'),
('payment', 'pending_hod_accounts', 'HOD Accounts Review', '["head_accounts"]',  1, 0, 0, '#f59e0b'),
('payment', 'pending_md',           'MD / BDM Approval',   '["md","bdm"]',       2, 0, 0, '#3b82f6'),
('payment', 'pending_post',         'Post to Sage',         '["accountant"]',     3, 0, 0, '#059669'),
('payment', 'completed',           'Completed',            '[]',                 4, 0, 1, '#059669'),
('payment', 'rejected',            'Rejected / Withdrawn', '[]',                 5, 0, 1, '#ef4444');

INSERT IGNORE INTO irs_flow_transitions (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order) VALUES
('payment', 'pending_hod_accounts', 'approve',          'Approve',           'pending_md',   'success', 0, 0, 0, 1),
('payment', 'pending_hod_accounts', 'reject',           'Reject',            'rejected',     'danger',  1, 0, 0, 2),
('payment', 'pending_md',           'approve_payment',  'Approve (Paid)',     'pending_post', 'success', 0, 0, 0, 1),
('payment', 'pending_md',           'reject',           'Reject',            'rejected',     'danger',  1, 0, 0, 2),
('payment', 'pending_post',         'post',             'Post & Close',      'completed',    'success', 0, 0, 1, 1);

-- ══════════════════════════════════════════════════════════════════
-- 10. SEED: PETTY CASH
-- ══════════════════════════════════════════════════════════════════
INSERT IGNORE INTO irs_flow_stages (request_type, stage_code, stage_label, actor_roles, stage_order, is_initial, is_terminal, stage_color) VALUES
('petty_cash', 'draft',              'Draft',               '[]',                               0, 1, 0, '#94a3b8'),
('petty_cash', 'pending_accountant', 'Accountant Review',   '["accountant","head_accounts"]',   1, 0, 0, '#f59e0b'),
('petty_cash', 'completed',         'Completed',            '[]',                               2, 0, 1, '#059669'),
('petty_cash', 'rejected',          'Rejected / Withdrawn', '[]',                               3, 0, 1, '#ef4444');

INSERT IGNORE INTO irs_flow_transitions (request_type, from_stage, action_code, action_label, to_stage, btn_style, requires_note, requires_amount, requires_sage_ref, sort_order) VALUES
('petty_cash', 'pending_accountant', 'process', 'Process & Disburse', 'completed', 'success', 0, 0, 0, 1),
('petty_cash', 'pending_accountant', 'reject',  'Reject',             'rejected',  'danger',  1, 0, 0, 2);
