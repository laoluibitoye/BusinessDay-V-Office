-- leave_flows_migration.sql
-- Configurable leave approval chains per requester role
-- Run in phpMyAdmin on hrindexx_hri_webmail

CREATE TABLE IF NOT EXISTS leave_flow_rules (
    requester_role  VARCHAR(50) NOT NULL,
    stage1_approver VARCHAR(50) DEFAULT NULL COMMENT 'First approver role (LM slot). NULL = skip.',
    stage2_approver VARCHAR(50) DEFAULT NULL COMMENT 'Second approver role (HR slot). NULL = skip.',
    stage3_approver VARCHAR(50) DEFAULT NULL COMMENT 'Final approver role (MD slot). NULL = skip.',
    updated_by      INT DEFAULT NULL,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (requester_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed defaults matching existing CLAUDE.md approval chains
-- ON DUPLICATE KEY UPDATE is a no-op so re-running is safe
INSERT INTO leave_flow_rules (requester_role, stage1_approver, stage2_approver, stage3_approver) VALUES
    ('md',               NULL,                NULL,  NULL),
    ('bdm',              NULL,                NULL,  'md'),
    ('head_it',          NULL,                'hr',  'md'),
    ('head_outsourcing', NULL,                'hr',  'md'),
    ('head_compliance',  NULL,                'hr',  'md'),
    ('head_accounts',    NULL,                'hr',  'md'),
    ('hr',               NULL,                NULL,  'md'),
    ('head_training',    NULL,                'hr',  'md'),
    ('head_cso',         NULL,                'hr',  'md'),
    ('training_manager', 'head_training',     'hr',  'md'),
    ('cs_manager',       'head_outsourcing',  'hr',  'md'),
    ('cs_officer',       'cs_manager',        'hr',  'md'),
    ('accountant',       'head_accounts',     'hr',  'md'),
    ('it_admin',         'head_it',           'hr',  'md'),
    ('front_desk',       NULL,                'hr',  'md'),
    ('cs_ops',           NULL,                'hr',  'md'),
    ('cleaner',          NULL,                'hr',  'md')
ON DUPLICATE KEY UPDATE requester_role = requester_role;
