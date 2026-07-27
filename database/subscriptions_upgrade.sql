-- subscriptions_upgrade.sql (MySQL 5.7 compatible)
-- Run in phpMyAdmin on hrindexx_hri_webmail
-- Run each ALTER TABLE separately if one fails — each is independent.

-- ── 1. Add cycle column ───────────────────────────────────────────────────
ALTER TABLE subscriptions
  ADD COLUMN cycle ENUM('monthly','quarterly','biannual','annual') NULL AFTER category;

-- ── 2. Add start_date column ──────────────────────────────────────────────
ALTER TABLE subscriptions
  ADD COLUMN start_date DATE NULL AFTER cycle;

-- ── 3. Add account_ref column ─────────────────────────────────────────────
ALTER TABLE subscriptions
  ADD COLUMN account_ref VARCHAR(200) NULL AFTER vendor;

-- ── 4. Add alert_30_sent column ───────────────────────────────────────────
ALTER TABLE subscriptions
  ADD COLUMN alert_30_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_days;

-- ── 5. Add alert_7_sent column ────────────────────────────────────────────
ALTER TABLE subscriptions
  ADD COLUMN alert_7_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER alert_30_sent;

-- ── 6. Add updated_at column ──────────────────────────────────────────────
ALTER TABLE subscriptions
  ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- ── 7. Expand category ENUM to include internet and hosting ───────────────
ALTER TABLE subscriptions
  MODIFY COLUMN category ENUM('internet','software','domain','hosting','service','insurance','membership','other') NOT NULL DEFAULT 'software';

-- ── 8. Create subscription_renewals table ─────────────────────────────────
CREATE TABLE IF NOT EXISTS subscription_renewals (
    id                INT          AUTO_INCREMENT PRIMARY KEY,
    subscription_id   INT          NOT NULL,
    user_id           INT          NOT NULL,
    renewed_at        DATE         NOT NULL,
    amount_paid       DECIMAL(15,2),
    irs_ref           VARCHAR(20),
    invoice_ref       VARCHAR(100),
    notes             TEXT,
    next_renewal_date DATE         NOT NULL,
    created_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subren_sub  (subscription_id),
    INDEX idx_subren_date (renewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
