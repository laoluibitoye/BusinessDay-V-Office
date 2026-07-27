<?php
// cron/retention.php — Run daily via cPanel cron
// Auto-deletes old cached records and cleans expired sessions
// cPanel Cron: 0 2 * * * php /home/username/public_html/mail/cron/retention.php

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('CRON', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

$db = getDB();

// Delete expired sessions and sessions idle past IDLE_TIMEOUT (2h)
$db->query("DELETE FROM sessions WHERE expires_at < NOW() OR last_active < DATE_SUB(NOW(), INTERVAL ".IDLE_TIMEOUT." SECOND)");

// Clean old mail cache (keep last 90 days)
$db->query("DELETE FROM mail_cache WHERE cached_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

// Keep audit log for 365 days (NDPA 2023 compliance — 12-month minimum)
$db->query("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)");

echo date('Y-m-d H:i:s') . " — Retention cleanup complete.\n";
