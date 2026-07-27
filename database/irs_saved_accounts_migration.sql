-- irs_saved_accounts_migration.sql
-- Run ONCE in phpMyAdmin on hrindexx_hri_webmail
-- Creates per-user saved beneficiary account book for IRS requests.
-- DO NOT upload this file to the web root — run it in phpMyAdmin then delete.

CREATE TABLE IF NOT EXISTS irs_saved_accounts (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    bank           VARCHAR(100) NOT NULL,
    account_number VARCHAR(20)  NOT NULL,
    account_name   VARCHAR(150) NOT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_acct (user_id, account_number),
    KEY idx_user (user_id)
);
