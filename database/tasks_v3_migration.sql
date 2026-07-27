-- HRI Mail — Task Management v3 Migration
-- Adds: progress %, checklist/subtasks
-- Run once in phpMyAdmin SQL tab

-- 1. Progress percentage on each task (0-100)
ALTER TABLE tasks ADD COLUMN progress TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- 2. Checklist items / subtasks per task
CREATE TABLE IF NOT EXISTS task_checklists (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    task_id     INT NOT NULL,
    item        VARCHAR(500) NOT NULL,
    is_done     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_by  INT NOT NULL,
    done_by     INT DEFAULT NULL,
    done_at     DATETIME DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
