-- Phase 4 Performance Indexes
-- Run once in phpMyAdmin on hrindexx_hri_webmail
-- Each index uses IF NOT EXISTS equivalent (CREATE INDEX ... IGNORE errors)
-- Safe to run multiple times.

-- Sessions: token lookup on every authenticated request
ALTER TABLE sessions
    ADD INDEX IF NOT EXISTS idx_sessions_token       (token(64)),
    ADD INDEX IF NOT EXISTS idx_sessions_user_active (user_id, expires_at, last_active);

-- Leave requests: stage filtering used on every dashboard
ALTER TABLE leave_requests
    ADD INDEX IF NOT EXISTS idx_leave_stage      (current_stage),
    ADD INDEX IF NOT EXISTS idx_leave_user_year  (user_id, start_date),
    ADD INDEX IF NOT EXISTS idx_leave_dates      (start_date, end_date);

-- Role permissions: loaded on every page for every user
ALTER TABLE role_permissions
    ADD INDEX IF NOT EXISTS idx_rp_role_key (role_key);

-- Audit log: ordered DESC on every admin page load — without index = full scan
ALTER TABLE audit_log
    ADD INDEX IF NOT EXISTS idx_audit_created (created_at);

-- Login history: failed-login rate-limit check on every login attempt
ALTER TABLE login_history
    ADD INDEX IF NOT EXISTS idx_login_status_time (status, created_at),
    ADD INDEX IF NOT EXISTS idx_login_ip_time     (ip_address, created_at);

-- Tasks: user task count badge loaded on every page via layout_shell
ALTER TABLE tasks
    ADD INDEX IF NOT EXISTS idx_tasks_user_status (user_id, status);

-- Users: role-based queries on dashboards and leave flows
ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_role   (role),
    ADD INDEX IF NOT EXISTS idx_users_active (is_active);

-- Announcements: loaded on every page via layout_shell
ALTER TABLE announcements
    ADD INDEX IF NOT EXISTS idx_ann_active_expires (is_active, expires_at);
