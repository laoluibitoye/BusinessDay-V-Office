<?php
// cron/ooo-reply.php — Auto-activate/deactivate OOO based on date range
// cPanel Cron: 0 8 * * * php /home/hrindexx/mail.hrindexx.com/cron/ooo-reply.php

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('CRON', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

$db      = getDB();
$today   = date('Y-m-d');
$headers = "From: HRI Mail <noreply@hrindexx.com>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
$enabled = 0;
$disabled = 0;

// Auto-enable OOO when start_date arrives
$enStmt = $db->prepare("SELECT o.*, u.email, u.name
    FROM ooo_settings o JOIN users u ON u.id=o.user_id
    WHERE o.is_enabled=0 AND o.start_date IS NOT NULL AND o.start_date <= ?
    AND (o.end_date IS NULL OR o.end_date >= ?)");
$enStmt->execute([$today, $today]);
$toEnable = $enStmt->fetchAll();

foreach ($toEnable as $ooo) {
    $db->prepare("UPDATE ooo_settings SET is_enabled=1, updated_at=NOW() WHERE user_id=?")->execute([$ooo['user_id']]);
    $body = "Dear {$ooo['name']},\n\nYour Out of Office auto-reply is now ACTIVE.\n\n"
          . "Period: " . date('d M Y', strtotime($ooo['start_date'])) . " to " . ($ooo['end_date'] ? date('d M Y', strtotime($ooo['end_date'])) : 'unset') . "\n\n"
          . "To change your OOO settings: " . APP_URL . "/ooo.php\n\nHRI Mail";
    @mail($ooo['email'], "Your Out of Office is now active", $body, $headers);
    $enabled++;
}

// Auto-disable OOO when end_date has passed
$disStmt = $db->prepare("SELECT o.*, u.email, u.name
    FROM ooo_settings o JOIN users u ON u.id=o.user_id
    WHERE o.is_enabled=1 AND o.end_date IS NOT NULL AND o.end_date < ?");
$disStmt->execute([$today]);
$toDisable = $disStmt->fetchAll();

foreach ($toDisable as $ooo) {
    $db->prepare("UPDATE ooo_settings SET is_enabled=0, updated_at=NOW() WHERE user_id=?")->execute([$ooo['user_id']]);
    // Clean up old reply log entries for this user
    $db->prepare("DELETE FROM ooo_replies_sent WHERE ooo_user_id=? AND replied_date < ?")->execute([$ooo['user_id'], $today]);
    $body = "Dear {$ooo['name']},\n\nYour Out of Office auto-reply has been automatically turned OFF as your return date has passed.\n\n"
          . "Welcome back! To re-enable OOO in future: " . APP_URL . "/ooo.php\n\nHRI Mail";
    @mail($ooo['email'], "Your Out of Office has been turned off", $body, $headers);
    $disabled++;
}

// Clean up old reply log entries older than 30 days
$db->query("DELETE FROM ooo_replies_sent WHERE replied_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");

echo date('Y-m-d H:i:s') . " — OOO check: $enabled enabled, $disabled disabled.\n";
