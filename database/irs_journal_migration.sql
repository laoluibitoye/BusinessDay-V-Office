-- IRS Journal Entries Migration
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail

CREATE TABLE IF NOT EXISTS irs_journal_entries (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    request_id   INT           NOT NULL,
    line_no      TINYINT       NOT NULL DEFAULT 1,
    account_code VARCHAR(50)   DEFAULT NULL,
    account_name VARCHAR(150)  NOT NULL,
    description  VARCHAR(250)  DEFAULT NULL,
    debit        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_by   INT           NOT NULL,
    created_at   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    KEY idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
