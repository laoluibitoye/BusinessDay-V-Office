-- HRI Chat Migration
-- Run in phpMyAdmin before uploading chat files

CREATE TABLE IF NOT EXISTS chat_channels (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL,
    slug        VARCHAR(100)  NOT NULL,
    description VARCHAR(255)  DEFAULT NULL,
    type        ENUM('public','direct') NOT NULL DEFAULT 'public',
    created_by  INT           NOT NULL DEFAULT 1,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_members (
    channel_id  INT      NOT NULL,
    user_id     INT      NOT NULL,
    last_read_id INT     NOT NULL DEFAULT 0,
    joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    channel_id   INT      NOT NULL,
    user_id      INT      NOT NULL,
    body         TEXT     NOT NULL,
    reply_to_id  INT      DEFAULT NULL,
    is_deleted   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel (channel_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default channels
INSERT IGNORE INTO chat_channels (name, slug, description, type, created_by) VALUES
('General',    'general',    'Company-wide conversation — everyone is here', 'public', 1),
('HR',         'hr',         'HR team discussions',                          'public', 1),
('IT Support', 'it-support', 'Tech issues, IT team updates',                 'public', 1);

-- Add all active users to #general automatically
INSERT IGNORE INTO chat_members (channel_id, user_id)
SELECT c.id, u.id
FROM chat_channels c, users u
WHERE c.slug = 'general' AND u.is_active = 1;
