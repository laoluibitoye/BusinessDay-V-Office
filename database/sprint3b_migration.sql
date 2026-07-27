-- Sprint 3B Migration — Compliance Upgrades + SOPs + Subscriptions
-- Run in phpMyAdmin on hrindexx_hri_webmail BEFORE uploading PHP files
--
-- AFTER running this SQL, also create these directories on the server via cPanel File Manager:
--   /home/hrindexx/mail.hrindexx.com/uploads/compliance/
--   /home/hrindexx/mail.hrindexx.com/uploads/sops/
-- Then upload the .htaccess file into each (contents: "Deny from all")

-- ── 1. Add file upload columns to compliance_docs ─────────────────────────
-- Only run these ALTERs if the columns do not already exist.
-- Check first in phpMyAdmin: DESCRIBE compliance_docs;

ALTER TABLE compliance_docs
  ADD COLUMN stored_name  VARCHAR(255)  NULL AFTER description,
  ADD COLUMN file_path    VARCHAR(500)  NULL AFTER stored_name,
  ADD COLUMN mime_type    VARCHAR(100)  NULL AFTER file_path;

-- ── 2. Subscriptions table ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subscriptions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(255)   NOT NULL,
    vendor       VARCHAR(255)   NULL,
    category     ENUM('software','domain','service','insurance','membership','other') NOT NULL DEFAULT 'software',
    cost         DECIMAL(10,2)  NULL,
    currency     VARCHAR(10)    NOT NULL DEFAULT 'NGN',
    renewal_date DATE           NOT NULL,
    notify_days  INT            NOT NULL DEFAULT 30,
    auto_renews  TINYINT        NOT NULL DEFAULT 0,
    notes        TEXT           NULL,
    status       ENUM('active','cancelled','pending_renewal') NOT NULL DEFAULT 'active',
    owner_id     INT            NULL,
    created_by   INT            NULL,
    created_at   DATETIME       DEFAULT CURRENT_TIMESTAMP,
    KEY idx_renewal (renewal_date),
    KEY idx_status (status)
);

-- ── 3. SOP documents table ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sop_documents (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255)   NOT NULL,
    description  TEXT           NULL,
    category     VARCHAR(100)   NOT NULL DEFAULT 'General',
    version      VARCHAR(20)    NOT NULL DEFAULT '1.0',
    filename     VARCHAR(255)   NOT NULL,
    stored_name  VARCHAR(255)   NOT NULL,
    file_path    VARCHAR(500)   NOT NULL,
    mime_type    VARCHAR(100)   NOT NULL DEFAULT 'application/pdf',
    file_size    INT            NULL,
    is_active    TINYINT        NOT NULL DEFAULT 1,
    uploaded_by  INT            NOT NULL,
    created_at   DATETIME       DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_category (category),
    KEY idx_active (is_active)
);
