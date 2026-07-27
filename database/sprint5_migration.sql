-- sprint5_migration.sql — Sprint 5: User Profiles + Role Permissions
-- Run in phpMyAdmin BEFORE deploying profile.php changes
-- Safe to run multiple times (uses IF NOT EXISTS / INSERT IGNORE)

-- Extended HR profile fields per user
CREATE TABLE IF NOT EXISTS user_profiles (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNIQUE NOT NULL,
    display_name     VARCHAR(100),
    date_of_birth    DATE,
    bio              TEXT,
    address          VARCHAR(300),
    national_id      VARCHAR(50),
    employment_start DATE,
    qualifications   TEXT,
    -- Next of kin
    nok_name         VARCHAR(100),
    nok_phone        VARCHAR(30),
    nok_relationship VARCHAR(50),
    nok_email        VARCHAR(150),
    nok_address      VARCHAR(300),
    -- Emergency contact
    emg_name         VARCHAR(100),
    emg_phone        VARCHAR(30),
    emg_relationship VARCHAR(50),
    emg_email        VARCHAR(150),
    -- Banking (optional)
    bank_name        VARCHAR(100),
    bank_account     VARCHAR(50),
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Frontend-configurable role permissions per feature
CREATE TABLE IF NOT EXISTS role_permissions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    role_key   VARCHAR(50) NOT NULL,
    feature    VARCHAR(50) NOT NULL,
    access     ENUM('none','read','write') NOT NULL DEFAULT 'none',
    UNIQUE KEY uq_role_feat (role_key, feature),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Seed default permissions from ROLES constant
INSERT IGNORE INTO role_permissions (role_key, feature, access) VALUES
('md','mail','write'),('md','tasks','write'),('md','leave','write'),
('md','signing','write'),('md','vault','write'),('md','compliance','write'),
('md','directory','write'),('md','roster','write'),('md','broadcast','write'),
('md','it_request','write'),('md','visitors','write'),('md','admin','write'),
('bdm','mail','write'),('bdm','tasks','write'),('bdm','leave','write'),
('bdm','signing','write'),('bdm','vault','write'),('bdm','compliance','write'),
('bdm','directory','write'),('bdm','roster','write'),('bdm','broadcast','write'),
('bdm','it_request','write'),('bdm','visitors','write'),('bdm','admin','write'),
('head_it','mail','write'),('head_it','tasks','write'),('head_it','leave','write'),
('head_it','signing','write'),('head_it','vault','write'),('head_it','compliance','write'),
('head_it','directory','write'),('head_it','roster','write'),('head_it','broadcast','write'),
('head_it','it_request','write'),('head_it','visitors','write'),('head_it','admin','write'),
('it_admin','mail','write'),('it_admin','tasks','write'),('it_admin','leave','write'),
('it_admin','signing','write'),('it_admin','vault','write'),('it_admin','compliance','write'),
('it_admin','directory','write'),('it_admin','roster','write'),('it_admin','broadcast','write'),
('it_admin','it_request','write'),('it_admin','visitors','write'),('it_admin','admin','write'),
('hr','mail','write'),('hr','tasks','write'),('hr','leave','write'),
('hr','signing','write'),('hr','vault','write'),('hr','compliance','write'),
('hr','directory','write'),('hr','roster','write'),('hr','broadcast','write'),
('hr','it_request','read'),('hr','visitors','read'),('hr','admin','none'),
('head_outsourcing','mail','write'),('head_outsourcing','tasks','write'),('head_outsourcing','leave','write'),
('head_outsourcing','signing','write'),('head_outsourcing','vault','write'),('head_outsourcing','compliance','read'),
('head_outsourcing','directory','write'),('head_outsourcing','roster','write'),('head_outsourcing','broadcast','write'),
('head_outsourcing','it_request','read'),('head_outsourcing','visitors','none'),('head_outsourcing','admin','none'),
('head_compliance','mail','write'),('head_compliance','tasks','write'),('head_compliance','leave','write'),
('head_compliance','signing','write'),('head_compliance','vault','write'),('head_compliance','compliance','write'),
('head_compliance','directory','read'),('head_compliance','roster','write'),('head_compliance','broadcast','none'),
('head_compliance','it_request','read'),('head_compliance','visitors','none'),('head_compliance','admin','none'),
('head_accounts','mail','write'),('head_accounts','tasks','write'),('head_accounts','leave','write'),
('head_accounts','signing','read'),('head_accounts','vault','write'),('head_accounts','compliance','read'),
('head_accounts','directory','read'),('head_accounts','roster','write'),('head_accounts','broadcast','none'),
('head_accounts','it_request','read'),('head_accounts','visitors','none'),('head_accounts','admin','none'),
('head_training','mail','write'),('head_training','tasks','write'),('head_training','leave','write'),
('head_training','signing','write'),('head_training','vault','write'),('head_training','compliance','read'),
('head_training','directory','write'),('head_training','roster','write'),('head_training','broadcast','none'),
('head_training','it_request','read'),('head_training','visitors','none'),('head_training','admin','none'),
('head_cso','mail','write'),('head_cso','tasks','write'),('head_cso','leave','write'),
('head_cso','signing','none'),('head_cso','vault','none'),('head_cso','compliance','none'),
('head_cso','directory','write'),('head_cso','roster','write'),('head_cso','broadcast','none'),
('head_cso','it_request','read'),('head_cso','visitors','none'),('head_cso','admin','none'),
('training_manager','mail','write'),('training_manager','tasks','write'),('training_manager','leave','write'),
('training_manager','signing','none'),('training_manager','vault','none'),('training_manager','compliance','none'),
('training_manager','directory','write'),('training_manager','roster','write'),('training_manager','broadcast','none'),
('training_manager','it_request','read'),('training_manager','visitors','none'),('training_manager','admin','none'),
('cs_manager','mail','write'),('cs_manager','tasks','write'),('cs_manager','leave','write'),
('cs_manager','signing','none'),('cs_manager','vault','none'),('cs_manager','compliance','none'),
('cs_manager','directory','write'),('cs_manager','roster','write'),('cs_manager','broadcast','none'),
('cs_manager','it_request','read'),('cs_manager','visitors','none'),('cs_manager','admin','none'),
('cs_officer','mail','write'),('cs_officer','tasks','write'),('cs_officer','leave','write'),
('cs_officer','signing','read'),('cs_officer','vault','write'),('cs_officer','compliance','none'),
('cs_officer','directory','none'),('cs_officer','roster','none'),('cs_officer','broadcast','none'),
('cs_officer','it_request','write'),('cs_officer','visitors','none'),('cs_officer','admin','none'),
('accountant','mail','write'),('accountant','tasks','write'),('accountant','leave','write'),
('accountant','signing','read'),('accountant','vault','write'),('accountant','compliance','none'),
('accountant','directory','none'),('accountant','roster','none'),('accountant','broadcast','none'),
('accountant','it_request','write'),('accountant','visitors','none'),('accountant','admin','none'),
('front_desk','mail','write'),('front_desk','tasks','write'),('front_desk','leave','write'),
('front_desk','signing','none'),('front_desk','vault','none'),('front_desk','compliance','none'),
('front_desk','directory','write'),('front_desk','roster','none'),('front_desk','broadcast','none'),
('front_desk','it_request','write'),('front_desk','visitors','write'),('front_desk','admin','none'),
('cs_ops','mail','write'),('cs_ops','tasks','write'),('cs_ops','leave','write'),
('cs_ops','signing','none'),('cs_ops','vault','none'),('cs_ops','compliance','none'),
('cs_ops','directory','none'),('cs_ops','roster','none'),('cs_ops','broadcast','none'),
('cs_ops','it_request','write'),('cs_ops','visitors','none'),('cs_ops','admin','none'),
('cleaner','mail','write'),('cleaner','tasks','write'),('cleaner','leave','write'),
('cleaner','signing','none'),('cleaner','vault','none'),('cleaner','compliance','none'),
('cleaner','directory','none'),('cleaner','roster','none'),('cleaner','broadcast','none'),
('cleaner','it_request','write'),('cleaner','visitors','none'),('cleaner','admin','none');

-- Add name to users table if missing a display override column
ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name VARCHAR(100) DEFAULT NULL AFTER name;
