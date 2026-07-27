-- IRS: Internal Request System Migration
-- Run once in phpMyAdmin on hrindexx_hri_webmail
-- Safe to run multiple times (IF NOT EXISTS guards).

CREATE TABLE IF NOT EXISTS irs_requests (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    ref_number              VARCHAR(20)  NOT NULL UNIQUE,
    type                    ENUM('requisition','caution','payment','petty_cash','retirement') NOT NULL,
    status                  ENUM(
                                'draft',
                                'pending_eligibility',
                                'pending_custodian',
                                'pending_accounts',
                                'accounts_approved',
                                'approved',
                                'paid',
                                'disbursed',
                                'refund_required',
                                'additional_payment',
                                'posted',
                                'rejected',
                                'returned',
                                'ineligible',
                                'exceeds_limit'
                            ) NOT NULL DEFAULT 'pending_accounts',
    priority                ENUM('urgent','normal','low') NOT NULL DEFAULT 'normal',
    description             TEXT        NOT NULL,
    amount                  DECIMAL(15,2) NOT NULL,
    department              VARCHAR(100) NOT NULL,
    notes                   TEXT,

    -- Payment Request: bank details
    bank                    VARCHAR(100),
    account_name            VARCHAR(150),
    account_number          VARCHAR(30),

    -- Retirement: link back to original request
    related_ref             VARCHAR(20),
    advance_amount          DECIMAL(15,2),
    actual_amount           DECIMAL(15,2),
    reconciliation_status   ENUM('exact','refund_required','additional_payment'),
    reconciliation_note     TEXT,

    -- Requester
    requester_id            INT NOT NULL,

    -- Accounts/eligibility review (stage 1)
    accounts_reviewer_id    INT,
    accounts_reviewed_at    DATETIME,
    accounts_comment        TEXT,

    -- Eligibility review (caution only — same accounts team)
    eligibility_reviewer_id INT,
    eligibility_reviewed_at DATETIME,
    eligibility_comment     TEXT,

    -- Manager approval
    manager_approver_id     INT,
    manager_approved_at     DATETIME,
    manager_comment         TEXT,

    -- Custodian (petty cash)
    custodian_id            INT,
    custodian_actioned_at   DATETIME,
    custodian_comment       TEXT,

    -- Payment execution
    paid_by                 INT,
    paid_at                 DATETIME,

    -- Refund confirmation (retirement)
    refund_confirmed_by     INT,
    refund_confirmed_at     DATETIME,

    -- Rejection
    rejected_by             INT,
    rejected_at             DATETIME,
    rejection_reason        TEXT,

    -- Sage posting
    sage_ref                VARCHAR(100),
    sage_posted_by          INT,
    sage_posted_at          DATETIME,

    created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_irs_requester  (requester_id),
    INDEX idx_irs_status     (status),
    INDEX idx_irs_type       (type),
    INDEX idx_irs_created    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS irs_audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    request_id  INT          NOT NULL,
    user_id     INT          NOT NULL,
    action      VARCHAR(60)  NOT NULL,
    detail      TEXT,
    ip_address  VARCHAR(45),
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_irs_audit_req     (request_id),
    INDEX idx_irs_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS irs_attachments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    request_id   INT          NOT NULL,
    user_id      INT          NOT NULL,
    filename     VARCHAR(255) NOT NULL,
    stored_name  VARCHAR(255) NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    mime_type    VARCHAR(100),
    file_size    INT,
    label        ENUM('journal_voucher','invoice','approval_memo','receipts','proof_of_refund','other') DEFAULT 'other',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_irs_attach_req (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create uploads/irs directory placeholder (create physically on server)
-- mkdir -p /home/hrindexx/mail.hrindexx.com/uploads/irs
