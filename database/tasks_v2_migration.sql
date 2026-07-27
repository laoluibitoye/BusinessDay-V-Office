-- Tasks v2 Migration — run in phpMyAdmin SQL tab
-- Extends tasks with assignment + collaboration + IT-request linking

SET FOREIGN_KEY_CHECKS = 0;

-- ── Extend tasks table ────────────────────────────────────────────
ALTER TABLE `tasks`
  ADD COLUMN `created_by`             INT UNSIGNED NULL         AFTER `user_id`,
  ADD COLUMN `assigned_to`            INT UNSIGNED NULL         AFTER `created_by`,
  ADD COLUMN `source`                 ENUM('manual','it_request','system') NOT NULL DEFAULT 'manual' AFTER `linked_email_uid`,
  ADD COLUMN `source_it_request_id`   INT UNSIGNED NULL         AFTER `source`,
  ADD COLUMN `completed_at`           DATETIME NULL             AFTER `updated_at`,
  ADD FOREIGN KEY fk_tasks_created_by  (`created_by`)           REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD FOREIGN KEY fk_tasks_assigned_to (`assigned_to`)          REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD FOREIGN KEY fk_tasks_it_request  (`source_it_request_id`) REFERENCES `it_requests`(`id`) ON DELETE SET NULL;

-- Backfill created_by for existing tasks (creator = owner)
UPDATE `tasks` SET `created_by` = `user_id` WHERE `created_by` IS NULL;

-- ── Task collaborators ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `task_collaborators` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id`           INT UNSIGNED NOT NULL,
    `user_id`           INT UNSIGNED NOT NULL,
    `role`              ENUM('collaborator','reviewer') DEFAULT 'collaborator',
    `input_required`    TINYINT(1) DEFAULT 0,
    `input_text`        TEXT NULL,
    `input_provided_at` DATETIME NULL,
    `added_by`          INT UNSIGNED NOT NULL,
    `added_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `task_user` (`task_id`, `user_id`),
    FOREIGN KEY (`task_id`)  REFERENCES `tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`added_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Task comments / activity log ──────────────────────────────────
CREATE TABLE IF NOT EXISTS `task_comments` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id`    INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `comment`    TEXT NOT NULL,
    `type`       ENUM('comment','input','status_change','assignment','it_update') DEFAULT 'comment',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
