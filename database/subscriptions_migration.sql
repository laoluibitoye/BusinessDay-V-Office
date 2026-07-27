-- Subscriptions Tracker Migration
-- Run once in phpMyAdmin on hrindexx_hri_webmail
-- Schema matches cron/sla-check.php column references exactly.

CREATE TABLE IF NOT EXISTS subscriptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    category        ENUM('internet','software','domain','hosting','other') NOT NULL,
    vendor          VARCHAR(150),
    account_ref     VARCHAR(200),           -- account ID / login hint (no passwords)
    cycle           ENUM('monthly','quarterly','biannual','annual') NOT NULL,
    cost            DECIMAL(15,2),
    currency        VARCHAR(10)  NOT NULL DEFAULT 'NGN',
    start_date      DATE         NOT NULL,
    renewal_date    DATE         NOT NULL,  -- next renewal due date
    auto_renews     TINYINT(1)   NOT NULL DEFAULT 0,
    notify_days     INT          NOT NULL DEFAULT 30,  -- send first alert this many days out
    notes           TEXT,
    owner_id        INT,                   -- user_id responsible for renewal
    status          ENUM('active','pending_renewal','expired','cancelled') NOT NULL DEFAULT 'active',
    alert_30_sent   TINYINT(1)   NOT NULL DEFAULT 0,
    alert_7_sent    TINYINT(1)   NOT NULL DEFAULT 0,
    created_by      INT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sub_renewal  (renewal_date),
    INDEX idx_sub_status   (status),
    INDEX idx_sub_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_renewals (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id  INT          NOT NULL,
    user_id          INT          NOT NULL,
    renewed_at       DATE         NOT NULL,
    amount_paid      DECIMAL(15,2),
    irs_ref          VARCHAR(20),          -- linked IRS payment request ref (optional)
    invoice_ref      VARCHAR(100),
    notes            TEXT,
    next_renewal_date DATE        NOT NULL, -- new renewal_date after this renewal
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subren_sub (subscription_id),
    INDEX idx_subren_date (renewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed with common HRI subscriptions (adjust costs / dates to match actuals)
-- Uncomment and edit before running if you want to pre-populate:
/*
INSERT INTO subscriptions (name,category,vendor,cycle,cost,currency,start_date,renewal_date,auto_renews,notify_days,notes,status) VALUES
('Internet Subscription',   'internet', 'ISP',          'monthly', 0, 'NGN', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH),  0, 14, 'Monthly internet bandwidth renewal', 'active'),
('Microsoft 365',           'software', 'Microsoft',    'annual',  0, 'NGN', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR),   1, 30, 'Microsoft 365 Business plan',         'active'),
('Domain - hrindexx.com',   'domain',   'Registrar',    'annual',  0, 'NGN', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR),   0, 60, 'Primary domain name',                 'active'),
('RackNerd Hosting',        'hosting',  'RackNerd',     'annual',  0, 'NGN', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR),   0, 60, 'Shared cPanel hosting',               'active');
*/
