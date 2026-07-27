<?php
// cron/sla-check.php — Daily cron (0 8 * * *)
// Checks expiring SLAs, compliance docs, and NDPC milestones; sends multi-milestone alerts
// cPanel Cron: 0 8 * * * php /home/hrindexx/mail.hrindexx.com/cron/sla-check.php

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('CRON', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

$db = getDB();
$mailHeaders = "From: HRI Mail <noreply@hrindexx.com>\r\nContent-Type: text/plain; charset=UTF-8\r\n";

// Multi-milestone thresholds — alert fires exactly on these days-remaining values
$MILESTONES = [60, 30, 14, 7, 1];

// ── SLAs expiring ──────────────────────────────────────────────────────────
$slas = $db->query("SELECT s.*, u.email, u.name as owner_name
    FROM sla_tracker s LEFT JOIN users u ON u.id=s.owner_id
    WHERE s.status IN ('active','expiring_soon')
    AND s.expiry_date >= CURDATE()")->fetchAll();

foreach ($slas as $s) {
    $days = (int)ceil((strtotime($s['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
    if (!$s['email']) continue;
    if (!in_array($days, $MILESTONES) && $days > 60) continue; // only fire on milestones or within 60d
    $urgency = $days <= 7 ? 'URGENT: ' : ($days <= 14 ? 'ACTION REQUIRED: ' : '');
    $body = "Dear {$s['owner_name']},\n\n"
          . "{$urgency}The following SLA expires in $days day(s):\n\n"
          . "SLA Name: {$s['name']}\n"
          . "Client:   {$s['client']}\n"
          . "Expiry:   " . date('d M Y', strtotime($s['expiry_date'])) . "\n\n"
          . "Please take action to renew or update the SLA.\n\n"
          . "View compliance tracker: " . APP_URL . "/compliance.php\n\n"
          . "HR Indexx Limited · Automated Alert";
    @mail($s['email'], "{$urgency}SLA Expiry Alert — {$s['name']} ({$days} days)", $body, $mailHeaders);
    if ($days <= 30) {
        $db->prepare("UPDATE sla_tracker SET status='expiring_soon' WHERE id=?")->execute([$s['id']]);
    }
}

// Mark expired SLAs
$db->query("UPDATE sla_tracker SET status='expired' WHERE status!='renewed' AND expiry_date < CURDATE()");

// ── Compliance docs expiring (including NDPC cert) ─────────────────────────
$docs = $db->query("SELECT d.*, u.email, u.name as owner_name
    FROM compliance_docs d LEFT JOIN users u ON u.id=d.owner_id
    WHERE d.status IN ('active','expiring_soon')
    AND d.expiry_date >= CURDATE()")->fetchAll();

// Also always alert the DPO (Lanre) for NDPC-related docs
$dpoUsers = $db->query("SELECT email, name FROM users WHERE role IN ('md','bdm','hr') AND is_active=1")->fetchAll();

foreach ($docs as $d) {
    $days = (int)ceil((strtotime($d['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
    if (!in_array($days, $MILESTONES) && $days > 60) continue;
    $urgency  = $days <= 7 ? 'URGENT: ' : ($days <= 14 ? 'ACTION REQUIRED: ' : '');
    $isNdpc   = (stripos($d['name'], 'NDPC') !== false || stripos($d['category'] ?? '', 'ndpc') !== false);
    $extraNote = $isNdpc
        ? "\nNOTE: This is an NDPC Data Protection Certificate. Failure to renew may result in regulatory penalties.\n"
          . "Renewal fee reference: ₦300,000 (prior renewal cost).\n"
          . "NDPC portal: https://ndpc.gov.ng\n"
        : '';
    $recipients = [];
    if ($d['email']) $recipients[] = ['email' => $d['email'], 'name' => $d['owner_name']];
    if ($isNdpc) {
        foreach ($dpoUsers as $dpo) {
            if ($dpo['email'] !== $d['email']) $recipients[] = $dpo;
        }
    }
    $body = "Dear [Name],\n\n"
          . "{$urgency}The following compliance document expires in $days day(s):\n\n"
          . "Document: {$d['name']}\n"
          . "Category: " . ucfirst($d['category'] ?? 'compliance') . "\n"
          . "Expiry:   " . date('d M Y', strtotime($d['expiry_date'])) . "\n"
          . $extraNote
          . "\nAction required to maintain compliance.\n\n"
          . "View compliance register: " . APP_URL . "/compliance.php\n\n"
          . "HR Indexx Limited · Automated Compliance Alert";
    foreach ($recipients as $r) {
        $personalBody = str_replace('[Name]', $r['name'], $body);
        @mail($r['email'], "{$urgency}Compliance Expiry — {$d['name']} ({$days} days)", $personalBody, $mailHeaders);
    }
    if ($days <= 30) {
        $db->prepare("UPDATE compliance_docs SET status='expiring_soon' WHERE id=?")->execute([$d['id']]);
    }
}

$db->query("UPDATE compliance_docs SET status='expired' WHERE status!='renewed' AND expiry_date < CURDATE()");

// ── Subscription renewal alerts ────────────────────────────────────────────
$subs = [];
try {
    $subs = $db->query("SELECT s.*, u.email, u.name AS owner_name
        FROM subscriptions s LEFT JOIN users u ON u.id=s.owner_id
        WHERE s.status IN ('active','pending_renewal')
        AND s.renewal_date >= CURDATE()")->fetchAll();
} catch (PDOException $e) { /* table not yet created */ }

foreach ($subs as $s) {
    $days = (int)ceil((strtotime($s['renewal_date']) - strtotime(date('Y-m-d'))) / 86400);
    // Fire on milestone days, OR within the subscription's own notify_days threshold
    if (!in_array($days, $MILESTONES) && $days > (int)$s['notify_days']) continue;
    if (!$s['email']) continue;

    $urgency  = $days <= 7 ? 'URGENT: ' : ($days <= 14 ? 'ACTION REQUIRED: ' : '');
    $costLine = $s['cost'] !== null ? "Cost:         {$s['currency']} " . number_format((float)$s['cost'], 2) . "\n" : '';
    $autoNote = $s['auto_renews']
        ? "This subscription AUTO-RENEWS — please verify the charge and confirm it should continue.\n"
        : "This subscription requires MANUAL renewal — action needed before the renewal date.\n";

    $body = "Dear {$s['owner_name']},\n\n"
          . "{$urgency}The following subscription is due for renewal in $days day(s):\n\n"
          . "Subscription: {$s['name']}\n"
          . ($s['vendor'] ? "Vendor:       {$s['vendor']}\n" : '')
          . "Category:     " . ucfirst($s['category']) . "\n"
          . $costLine
          . "Renewal Date: " . date('d M Y', strtotime($s['renewal_date'])) . "\n\n"
          . $autoNote
          . ($s['notes'] ? "Notes: {$s['notes']}\n\n" : "\n")
          . "Manage subscriptions: " . APP_URL . "/subscriptions.php\n\n"
          . "HR Indexx Limited · Automated Subscription Alert";

    @mail($s['email'], "{$urgency}Subscription Renewal — {$s['name']} ({$days} days)", $body, $mailHeaders);

    // Mark as pending_renewal when ≤14 days out; never go backwards
    if ($days <= 14) {
        $db->prepare("UPDATE subscriptions SET status='pending_renewal' WHERE id=? AND status='active'")->execute([$s['id']]);
    }
}

// Mark as expired if renewal_date has passed and not yet renewed
$db->query("UPDATE subscriptions SET status='expired' WHERE status IN ('active','pending_renewal') AND renewal_date < CURDATE()");

// ── Signing request expiry ─────────────────────────────────────────────────
$db->query("UPDATE sign_requests SET status='expired' WHERE status='pending' AND expires_at < NOW()");

echo date('Y-m-d H:i:s') . " — SLA/compliance/subscription check complete.\n";
