-- Sprint 4A Migration — Departments, Work Schedules, Leave Balance
-- Database: hrindexx_hri_webmail
-- Safe to re-run — all uses IF NOT EXISTS
-- Run in phpMyAdmin: Import tab → select this file → Go


-- ── 1. Departments ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS departments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    head_role  VARCHAR(50),
    color      VARCHAR(20) DEFAULT '#64748b',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dept_name (name)
);

INSERT IGNORE INTO departments (name, head_role, color) VALUES
    ('IT',                        'head_it',          '#0891b2'),
    ('Human Resources',           'hr',               '#7c3aed'),
    ('Outsourcing',               'head_outsourcing', '#7c3aed'),
    ('Compliance',                'head_compliance',  '#0891b2'),
    ('Accounts',                  'head_accounts',    '#059669'),
    ('Training',                  'head_training',    '#d97706'),
    ('Client Service Operations', 'head_cso',         '#db2777'),
    ('Business Development',      'bdm',              '#002850');


-- ── 2. Work Schedules ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS work_schedules (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(100) NOT NULL,
    days_per_week     INT NOT NULL,
    annual_leave_days INT NOT NULL DEFAULT 15,
    description       VARCHAR(300),
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO work_schedules (id, name, days_per_week, annual_leave_days, description) VALUES
    (1, 'Full Time (Every Day)',  7, 21, 'Works Monday to Sunday — 21 days annual leave entitlement'),
    (2, '4-Day Week',            4, 15, 'Works any 4 days per week — 15 days annual leave'),
    (3, '3-Day Week',            3, 15, 'Works any 3 days per week — 15 days annual leave'),
    (4, '2-Day Week',            2, 15, 'Works any 2 days per week — 15 days annual leave');


-- ── 3. Staff Schedules (1 row per staff member) ──────────────────────────

CREATE TABLE IF NOT EXISTS staff_schedules (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    schedule_id    INT NOT NULL,
    working_days   VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
    effective_from DATE,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_schedule (user_id)
);


-- ── 4. Leave Balance (per user per year) ─────────────────────────────────

CREATE TABLE IF NOT EXISTS leave_balance (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    year          INT NOT NULL,
    entitled_days INT NOT NULL DEFAULT 15,
    used_days     INT NOT NULL DEFAULT 0,
    pending_days  INT NOT NULL DEFAULT 0,
    carried_over  INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_user_year (user_id, year)
);


-- ── 5. Update Lanre's role to head_it (the correct 16-role system role) ──
-- This only fires if the email matches and role is still the old 'it_admin'
-- Safe to run even after migration — the WHERE clause prevents double update

UPDATE users
SET role = 'head_it'
WHERE email = 'ooloritun@hrindexx.com'
  AND role  = 'it_admin';
