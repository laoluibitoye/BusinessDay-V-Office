-- HRI Webmail — Full Database Schema
-- Run this in cPanel → phpMyAdmin → hrindexx_hri_webmail → SQL tab

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`            VARCHAR(120) NOT NULL,
    `email`           VARCHAR(160) NOT NULL UNIQUE,
    `password_hash`   VARCHAR(255) NOT NULL,
    `role`            ENUM('it_admin','compliance','accounts','client_service','hr','front_desk') NOT NULL DEFAULT 'front_desk',
    `line_manager_id` INT UNSIGNED NULL,
    `department`      VARCHAR(80) NULL,
    `phone`           VARCHAR(30) NULL,
    `avatar_color`    VARCHAR(10) DEFAULT '#002850',
    `is_active`       TINYINT(1) DEFAULT 1,
    `last_login`      DATETIME NULL,
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`line_manager_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SESSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `sessions` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `token`      VARCHAR(255) NOT NULL UNIQUE,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `device`     VARCHAR(120) NULL,
    `location`   VARCHAR(120) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    `last_active` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- AUDIT LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NULL,
    `action`     VARCHAR(100) NOT NULL,
    `detail`     TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- USAGE TRACKING (per user, per day)
-- ============================================================
CREATE TABLE IF NOT EXISTS `usage_stats` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `date`            DATE NOT NULL,
    `emails_sent`     INT DEFAULT 0,
    `emails_read`     INT DEFAULT 0,
    `ai_summaries`    INT DEFAULT 0,
    `ai_replies`      INT DEFAULT 0,
    `ai_compose`      INT DEFAULT 0,
    `docs_signed`     INT DEFAULT 0,
    `vault_uploads`   INT DEFAULT 0,
    `tasks_created`   INT DEFAULT 0,
    `tasks_completed` INT DEFAULT 0,
    `logins`          INT DEFAULT 0,
    UNIQUE KEY `user_date` (`user_id`, `date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LOGIN HISTORY
-- ============================================================
CREATE TABLE IF NOT EXISTS `login_history` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `device`     VARCHAR(120) NULL,
    `user_agent` TEXT NULL,
    `status`     ENUM('success','failed') DEFAULT 'success',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MAIL CACHE
-- ============================================================
CREATE TABLE IF NOT EXISTS `mail_cache` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `uid`         VARCHAR(50) NOT NULL,
    `folder`      VARCHAR(80) DEFAULT 'INBOX',
    `message_id`  VARCHAR(255) NULL,
    `subject`     VARCHAR(500) NULL,
    `from_name`   VARCHAR(200) NULL,
    `from_email`  VARCHAR(200) NULL,
    `to_email`    TEXT NULL,
    `cc_email`    TEXT NULL,
    `body_plain`  LONGTEXT NULL,
    `body_html`   LONGTEXT NULL,
    `is_read`     TINYINT(1) DEFAULT 0,
    `is_starred`  TINYINT(1) DEFAULT 0,
    `has_attach`  TINYINT(1) DEFAULT 0,
    `ai_summary`  TEXT NULL,
    `auto_tags`   VARCHAR(255) NULL,
    `thread_id`   VARCHAR(255) NULL,
    `date_sent`   DATETIME NULL,
    `cached_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `user_uid_folder` (`user_id`, `uid`, `folder`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TASKS
-- ============================================================
CREATE TABLE IF NOT EXISTS `tasks` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `title`           VARCHAR(300) NOT NULL,
    `description`     TEXT NULL,
    `due_date`        DATE NULL,
    `priority`        ENUM('low','normal','high','urgent') DEFAULT 'normal',
    `status`          ENUM('pending','in_progress','done') DEFAULT 'pending',
    `linked_email_uid` VARCHAR(50) NULL,
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LEAVE REQUESTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `leave_requests` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `leave_type`   ENUM('annual','sick','compassionate','unpaid','maternity','paternity') NOT NULL,
    `start_date`   DATE NOT NULL,
    `end_date`     DATE NOT NULL,
    `days`         INT NOT NULL,
    `reason`       TEXT NULL,
    `cover_staff`  VARCHAR(120) NULL,
    `current_stage` ENUM('submitted','lm_review','hr_review','md_review','approved','rejected') DEFAULT 'submitted',
    `lm_status`    ENUM('pending','approved','rejected') DEFAULT 'pending',
    `lm_comment`   TEXT NULL,
    `lm_actioned_by` INT UNSIGNED NULL,
    `lm_actioned_at` DATETIME NULL,
    `hr_status`    ENUM('pending','approved','rejected') DEFAULT 'pending',
    `hr_comment`   TEXT NULL,
    `hr_actioned_by` INT UNSIGNED NULL,
    `hr_actioned_at` DATETIME NULL,
    `md_status`    ENUM('pending','approved','rejected') DEFAULT 'pending',
    `md_comment`   TEXT NULL,
    `md_actioned_by` INT UNSIGNED NULL,
    `md_actioned_at` DATETIME NULL,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DIGITAL SIGNING
-- ============================================================
CREATE TABLE IF NOT EXISTS `sign_requests` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`          VARCHAR(300) NOT NULL,
    `doc_type`       ENUM('offer_letter','policy_acknowledgement','privacy_policy','sla','contractor','data_breach','custom') NOT NULL,
    `doc_path`       VARCHAR(500) NULL,
    `created_by`     INT UNSIGNED NOT NULL,
    `message`        TEXT NULL,
    `status`         ENUM('pending','partial','completed','expired') DEFAULT 'pending',
    `expires_at`     DATETIME NULL,
    `signed_pdf_path` VARCHAR(500) NULL,
    `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sign_signatories` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_id`     INT UNSIGNED NOT NULL,
    `user_id`        INT UNSIGNED NULL,
    `external_email` VARCHAR(200) NULL,
    `external_name`  VARCHAR(200) NULL,
    `token`          VARCHAR(255) NOT NULL UNIQUE,
    `status`         ENUM('pending','signed','declined') DEFAULT 'pending',
    `signature_data` LONGTEXT NULL,
    `signed_at`      DATETIME NULL,
    `ip_address`     VARCHAR(45) NULL,
    `order_num`      INT DEFAULT 1,
    FOREIGN KEY (`request_id`) REFERENCES `sign_requests`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DOCUMENT VAULT
-- ============================================================
CREATE TABLE IF NOT EXISTS `vault_files` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `filename`     VARCHAR(300) NOT NULL,
    `stored_name`  VARCHAR(300) NOT NULL,
    `file_path`    VARCHAR(500) NOT NULL,
    `file_size`    INT UNSIGNED NULL,
    `mime_type`    VARCHAR(100) NULL,
    `category`     ENUM('compliance','finance','hr','it','client','payslip','signed','general') DEFAULT 'general',
    `source`       ENUM('email','upload','signed_doc') DEFAULT 'upload',
    `source_email` VARCHAR(255) NULL,
    `is_shared`    TINYINT(1) DEFAULT 0,
    `shared_roles` VARCHAR(255) NULL,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SLA TRACKER (Compliance)
-- ============================================================
CREATE TABLE IF NOT EXISTS `sla_tracker` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(300) NOT NULL,
    `client`      VARCHAR(200) NULL,
    `description` TEXT NULL,
    `start_date`  DATE NULL,
    `expiry_date` DATE NOT NULL,
    `owner_id`    INT UNSIGNED NULL,
    `status`      ENUM('active','expiring_soon','expired','renewed') DEFAULT 'active',
    `notify_days` INT DEFAULT 30,
    `created_by`  INT UNSIGNED NULL,
    `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- COMPLIANCE DOCUMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `compliance_docs` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`         VARCHAR(300) NOT NULL,
    `category`     ENUM('regulatory','policy','certification','audit','data_protection','it','finance','hr') NOT NULL,
    `description`  TEXT NULL,
    `expiry_date`  DATE NULL,
    `owner_id`     INT UNSIGNED NULL,
    `status`       ENUM('active','expiring_soon','expired','renewed','pending') DEFAULT 'active',
    `notify_days`  INT DEFAULT 30,
    `file_path`    VARCHAR(500) NULL,
    `created_by`   INT UNSIGNED NULL,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- POLICY ACKNOWLEDGEMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `policy_acks` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_name` VARCHAR(300) NOT NULL,
    `policy_file` VARCHAR(500) NULL,
    `sent_by`     INT UNSIGNED NOT NULL,
    `sent_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    `deadline`    DATE NULL,
    `status`      ENUM('active','closed') DEFAULT 'active',
    FOREIGN KEY (`sent_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `policy_ack_responses` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_id`   INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `status`      ENUM('pending','acknowledged') DEFAULT 'pending',
    `acked_at`    DATETIME NULL,
    `ip_address`  VARCHAR(45) NULL,
    FOREIGN KEY (`policy_id`) REFERENCES `policy_acks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ESCALATION LOG (Client Service)
-- ============================================================
CREATE TABLE IF NOT EXISTS `escalation_log` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `client_name` VARCHAR(200) NOT NULL,
    `subject`     VARCHAR(300) NOT NULL,
    `description` TEXT NULL,
    `priority`    ENUM('low','normal','high','critical') DEFAULT 'normal',
    `status`      ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    `resolved_at` DATETIME NULL,
    `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- VISITOR LOG (Front Desk)
-- ============================================================
CREATE TABLE IF NOT EXISTS `visitors` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(200) NOT NULL,
    `company`     VARCHAR(200) NULL,
    `phone`       VARCHAR(30) NULL,
    `host_id`     INT UNSIGNED NULL,
    `purpose`     TEXT NULL,
    `time_in`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    `time_out`    DATETIME NULL,
    `badge_no`    VARCHAR(20) NULL,
    `created_by`  INT UNSIGNED NULL,
    FOREIGN KEY (`host_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MEETING ROOMS (Front Desk)
-- ============================================================
CREATE TABLE IF NOT EXISTS `meeting_rooms` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(100) NOT NULL,
    `capacity`   INT DEFAULT 10,
    `is_active`  TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `room_bookings` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `room_id`    INT UNSIGNED NOT NULL,
    `booked_by`  INT UNSIGNED NOT NULL,
    `title`      VARCHAR(300) NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time`   DATETIME NOT NULL,
    `attendees`  TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`room_id`) REFERENCES `meeting_rooms`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`booked_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ANNOUNCEMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `announcements` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`        VARCHAR(300) NOT NULL,
    `message`      TEXT NOT NULL,
    `created_by`   INT UNSIGNED NOT NULL,
    `target_roles` VARCHAR(255) DEFAULT 'all',
    `expires_at`   DATETIME NULL,
    `is_active`    TINYINT(1) DEFAULT 1,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- IT REQUESTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `it_requests` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `issue_type`   VARCHAR(100) NOT NULL,
    `priority`     ENUM('normal','urgent') DEFAULT 'normal',
    `description`  TEXT NOT NULL,
    `status`       ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    `assigned_to`  INT UNSIGNED NULL,
    `resolved_at`  DATETIME NULL,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EMAIL TEMPLATES
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_templates` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`         VARCHAR(200) NOT NULL,
    `subject`      VARCHAR(500) NOT NULL,
    `body`         LONGTEXT NOT NULL,
    `category`     VARCHAR(100) NULL,
    `allowed_roles` VARCHAR(255) DEFAULT 'all',
    `created_by`   INT UNSIGNED NULL,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DATA BREACH LOG (IT Admin)
-- ============================================================
CREATE TABLE IF NOT EXISTS `breach_log` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`         VARCHAR(300) NOT NULL,
    `description`   TEXT NOT NULL,
    `severity`      ENUM('low','medium','high','critical') DEFAULT 'medium',
    `data_affected` TEXT NULL,
    `reported_by`   INT UNSIGNED NOT NULL,
    `status`        ENUM('open','investigating','contained','resolved','reported_ndpc') DEFAULT 'open',
    `ndpc_notified` TINYINT(1) DEFAULT 0,
    `ndpc_notified_at` DATETIME NULL,
    `resolved_at`   DATETIME NULL,
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default meeting rooms
INSERT INTO `meeting_rooms` (`name`, `capacity`) VALUES
('Board Room', 20),
('Meeting Room A', 8),
('Meeting Room B', 6);

-- Default IT Admin user (Lanre)
-- Password: HRI@Admin2026 (change immediately after first login)
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `department`, `avatar_color`) VALUES
('Lanre Oloritun', 'ooloritun@hrindexx.com',
 '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uFutB/', 
 'it_admin', 'IT', '#002850');

-- Default email templates
INSERT INTO `email_templates` (`name`, `subject`, `body`, `category`, `allowed_roles`) VALUES
('Offer Letter', 'Job Offer — HR Indexx Limited', 
 'Dear [NAME],\n\nWe are pleased to offer you the position of [ROLE] at HR Indexx Limited...\n\nKind regards,\nHR Department\nHR Indexx Limited', 
 'hr', 'hr,it_admin'),
('DPO Notice', 'Data Protection Notice — HR Indexx Limited', 
 'Dear [NAME],\n\nThis notice is issued pursuant to the Nigeria Data Protection Act 2023...\n\nRegards,\nLanre Oloritun\nData Protection Officer\nHR Indexx Limited', 
 'compliance', 'it_admin,compliance'),
('Leave Approval', 'Leave Application — Approved', 
 'Dear [NAME],\n\nYour leave application for [DATES] has been approved.\n\nHR Department', 
 'hr', 'hr,it_admin'),
('SLA Expiry Notice', 'SLA Expiry Alert — Action Required', 
 'Dear [NAME],\n\nThis is to notify you that the SLA with [CLIENT] is due to expire on [DATE]...\n\nCompliance Team\nHR Indexx Limited', 
 'compliance', 'compliance,it_admin');

SET FOREIGN_KEY_CHECKS = 1;
