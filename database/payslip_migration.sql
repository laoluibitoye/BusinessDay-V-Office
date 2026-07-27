-- HRI Mail — Payslip Distribution Tables
-- Run in phpMyAdmin on hrindexx_hri_webmail

CREATE TABLE IF NOT EXISTS payslip_batches (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    batch_ref       VARCHAR(30)  NOT NULL UNIQUE,   -- e.g. PSL-2026-0001
    uploaded_by     INT          NOT NULL,
    original_file   VARCHAR(255) NOT NULL DEFAULT '',
    pay_period      VARCHAR(40)  NOT NULL DEFAULT '', -- e.g. "July 2026"
    total           INT          NOT NULL DEFAULT 0,
    sent            INT          NOT NULL DEFAULT 0,
    failed          INT          NOT NULL DEFAULT 0,
    status          ENUM('queued','sending','completed','partial','cancelled') DEFAULT 'queued',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    INDEX (uploaded_by),
    INDEX (status),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payslip_queue (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    batch_id            INT          NOT NULL,
    row_num             SMALLINT     NOT NULL DEFAULT 0,
    -- Employee details
    employee_name       VARCHAR(200) NOT NULL,
    employee_email      VARCHAR(200) NOT NULL,
    employee_id         VARCHAR(50)  DEFAULT '',
    designation         VARCHAR(150) DEFAULT '',
    location            VARCHAR(150) DEFAULT '',
    pay_period          VARCHAR(40)  DEFAULT '',
    pay_day             VARCHAR(40)  DEFAULT '',
    pfa                 VARCHAR(100) DEFAULT '',
    pension_pin         VARCHAR(50)  DEFAULT '',
    days_in_month       TINYINT UNSIGNED DEFAULT 0,
    days_worked         TINYINT UNSIGNED DEFAULT 0,
    tax_id              VARCHAR(50)  DEFAULT '',
    -- Earnings
    basic_salary        DECIMAL(14,2) DEFAULT 0.00,
    housing             DECIMAL(14,2) DEFAULT 0.00,
    transportation      DECIMAL(14,2) DEFAULT 0.00,
    other_allowances    DECIMAL(14,2) DEFAULT 0.00,
    hmo_employer        DECIMAL(14,2) DEFAULT 0.00,
    psf_arrears         DECIMAL(14,2) DEFAULT 0.00,
    total_earnings      DECIMAL(14,2) DEFAULT 0.00,
    -- Deductions
    hmo_employee        DECIMAL(14,2) DEFAULT 0.00,
    monthly_pension     DECIMAL(14,2) DEFAULT 0.00,
    monthly_tax         DECIMAL(14,2) DEFAULT 0.00,
    additional_hmo      DECIMAL(14,2) DEFAULT 0.00,
    loan_repayment      DECIMAL(14,2) DEFAULT 0.00,
    caution_fee         DECIMAL(14,2) DEFAULT 0.00,
    total_deductions    DECIMAL(14,2) DEFAULT 0.00,
    net_pay             DECIMAL(14,2) DEFAULT 0.00,
    -- Queue state
    pdf_path            VARCHAR(500) NULL,
    status              ENUM('pending','sending','sent','failed') DEFAULT 'pending',
    attempts            TINYINT UNSIGNED DEFAULT 0,
    sent_at             DATETIME NULL,
    error_msg           VARCHAR(500) NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (batch_id),
    INDEX (status),
    INDEX (employee_email),
    INDEX (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
