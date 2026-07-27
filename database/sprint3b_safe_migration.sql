-- Sprint 3B Safe Migration — Compliance Upgrades + SOPs + Subscriptions
-- Database: hrindexx_hri_webmail
-- Safe to run even if columns already exist — uses IF NOT EXISTS checks
-- Run in phpMyAdmin: Import tab → select this file → Go


-- ── 1. Add file upload columns to compliance_docs (safe — skips if exists) ──

DROP PROCEDURE IF EXISTS hri_sprint3b;

DELIMITER //
CREATE PROCEDURE hri_sprint3b()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'compliance_docs'
          AND COLUMN_NAME  = 'stored_name'
    ) THEN
        ALTER TABLE compliance_docs
            ADD COLUMN stored_name VARCHAR(255) NULL AFTER description;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'compliance_docs'
          AND COLUMN_NAME  = 'file_path'
    ) THEN
        ALTER TABLE compliance_docs
            ADD COLUMN file_path VARCHAR(500) NULL AFTER stored_name;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'compliance_docs'
          AND COLUMN_NAME  = 'mime_type'
    ) THEN
        ALTER TABLE compliance_docs
            ADD COLUMN mime_type VARCHAR(100) NULL AFTER file_path;
    END IF;
END //
DELIMITER ;

CALL hri_sprint3b();
DROP PROCEDURE IF EXISTS hri_sprint3b;


-- ── 2. Subscriptions table ────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS subscriptions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(200) NOT NULL,
    vendor       VARCHAR(200),
    category     ENUM('software','domain','service','insurance','membership','other') DEFAULT 'software',
    cost         DECIMAL(12,2),
    currency     VARCHAR(10) DEFAULT 'NGN',
    renewal_date DATE NOT NULL,
    notify_days  INT DEFAULT 30,
    auto_renews  TINYINT(1) DEFAULT 0,
    notes        TEXT,
    status       ENUM('active','cancelled','pending_renewal') DEFAULT 'active',
    owner_id     INT,
    created_by   INT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);


-- ── 3. SOP Documents table ────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS sop_documents (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(300) NOT NULL,
    description  TEXT,
    category     VARCHAR(100) DEFAULT 'General',
    version      VARCHAR(20) DEFAULT '1.0',
    filename     VARCHAR(255),
    stored_name  VARCHAR(255),
    file_path    VARCHAR(500),
    mime_type    VARCHAR(100),
    file_size    INT,
    is_active    TINYINT(1) DEFAULT 1,
    uploaded_by  INT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);
