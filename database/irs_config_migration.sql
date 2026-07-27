-- IRS Runtime Configuration Table
-- Run this once in phpMyAdmin on hrindexx_hri_webmail

CREATE TABLE IF NOT EXISTS irs_config (
    `key`       VARCHAR(60)   NOT NULL PRIMARY KEY,
    value       TEXT          NOT NULL,
    label       VARCHAR(150)  NULL,
    updated_by  INT           NULL,
    updated_at  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed with defaults (matches config/app.php defaults)
INSERT INTO irs_config (`key`, value, label) VALUES
    ('accounts_roles',   '["head_accounts","accountant"]',                                                                          'Roles that perform Accounts review, custodian & Sage posting'),
    ('manager_roles',    '["md","bdm"]', 'Roles that perform final Manager approval (Level 1 Management only — MD and BDM)'),
    ('petty_cash_limit', '50000',                                                                                                    'Petty cash disbursement ceiling (₦)')
ON DUPLICATE KEY UPDATE value = VALUES(value);
