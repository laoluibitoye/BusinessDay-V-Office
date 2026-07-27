-- Sprint 3 Migration — HR Automation
-- Run in phpMyAdmin on hrindexx_hri_webmail BEFORE uploading PHP files

-- Out of Office settings (one row per user)
CREATE TABLE IF NOT EXISTS ooo_settings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL UNIQUE,
    is_enabled  TINYINT NOT NULL DEFAULT 0,
    start_date  DATE NULL,
    end_date    DATE NULL,
    subject     VARCHAR(255) NOT NULL DEFAULT 'Out of Office',
    message     TEXT,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Tracks which senders have already received an OOO auto-reply per day
-- Prevents sending duplicate OOO replies to the same person
CREATE TABLE IF NOT EXISTS ooo_replies_sent (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ooo_user_id  INT NOT NULL,
    sender_email VARCHAR(255) NOT NULL,
    replied_date DATE NOT NULL,
    UNIQUE KEY uq_ooo_reply (ooo_user_id, sender_email, replied_date)
);

-- Email filter rules
CREATE TABLE IF NOT EXISTS email_filters (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    name         VARCHAR(100) NOT NULL,
    field        ENUM('from','to','subject','body') NOT NULL DEFAULT 'from',
    operator     ENUM('contains','equals','starts_with','ends_with') NOT NULL DEFAULT 'contains',
    value        VARCHAR(255) NOT NULL,
    action       ENUM('move','star','mark_read','delete') NOT NULL DEFAULT 'move',
    action_param VARCHAR(255) NULL,       -- folder name for 'move', ignored for others
    is_active    TINYINT NOT NULL DEFAULT 1,
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id)
);
