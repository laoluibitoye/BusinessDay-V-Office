<?php
/**
 * HRI Payslip Distribution Cron
 * Run every minute: * * * * * php /home/hrindexx/mail.hrindexx.com/cron/payslip-send.php
 *
 * Rate limit: 15 emails per hour (cPanel SMTP safe limit)
 * On each run: checks how many sent in last 60 min, sends up to (15 - count) pending items.
 */

if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../lib/payslip-pdf.php';
require_once __DIR__ . '/../lib/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$db = getDB();
$log = function(string $msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
    error_log("[payslip-send] $msg");
};

// ── Rate check ───────────────────────────────────────────────────────────────

try {
    $rateStmt = $db->query(
        "SELECT COUNT(*) FROM payslip_queue
         WHERE status='sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
    );
    $sentInHour = (int)$rateStmt->fetchColumn();
} catch (Exception $e) {
    $log("DB error on rate check: " . $e->getMessage());
    exit(1);
}

$hourlyBudget = 70;
$perRunLimit  = 15; // max per cron run to avoid PHP timeout
$canSend = min($perRunLimit, max(0, $hourlyBudget - $sentInHour));
if ($canSend <= 0) {
    $log("Rate limit: {$sentInHour}/{$hourlyBudget} sent in last hour. Waiting for next window.");
    exit(0);
}

$log("Rate: {$sentInHour}/{$hourlyBudget} sent in last hour. Sending up to {$canSend} this run.");

// ── Fetch pending items ───────────────────────────────────────────────────────

try {
    $fetch = $db->prepare(
        "SELECT q.*, b.batch_ref, b.pay_period AS batch_period,
                b.client_name, b.sender_name
         FROM payslip_queue q
         JOIN payslip_batches b ON b.id = q.batch_id
         WHERE q.status = 'pending'
           AND q.attempts < 3
           AND b.status NOT IN ('cancelled')
         ORDER BY q.id ASC
         LIMIT ?"
    );
    $fetch->execute([$canSend]);
    $items = $fetch->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $log("DB error fetching queue: " . $e->getMessage());
    exit(1);
}

if (empty($items)) {
    $log("No pending payslips in queue.");
    // Check if all active batches are complete
    try {
        $db->exec("UPDATE payslip_batches b SET
            b.sent    = (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=b.id AND q.status='sent'),
            b.failed  = (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=b.id AND q.status='failed'),
            b.status  = CASE
                WHEN (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=b.id AND q.status='pending')=0
                     AND (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=b.id AND q.status='failed')=0
                     THEN 'completed'
                WHEN (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=b.id AND q.status='pending')=0
                     THEN 'partial'
                ELSE b.status
            END,
            b.completed_at = CASE
                WHEN (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=b.id AND q.status='pending')=0 THEN NOW()
                ELSE b.completed_at
            END
            WHERE b.status IN ('queued','sending')");
    } catch (Exception $e) {}
    exit(0);
}

// ── Mark batch as sending ─────────────────────────────────────────────────────

$batchIds = array_unique(array_column($items, 'batch_id'));
foreach ($batchIds as $bid) {
    try {
        $db->prepare("UPDATE payslip_batches SET status='sending' WHERE id=? AND status='queued'")->execute([$bid]);
    } catch (Exception $e) {}
}

// ── Send each payslip ─────────────────────────────────────────────────────────

$sentOk = 0; $sentFail = 0;

foreach ($items as $item) {
    $qid  = (int)$item['id'];
    $name = $item['employee_name'];
    $email = $item['employee_email'];

    // Mark as sending (prevents double-send if cron overlaps)
    try {
        $upd = $db->prepare("UPDATE payslip_queue SET status='sending', attempts=attempts+1 WHERE id=? AND status='pending'");
        $upd->execute([$qid]);
        if ($upd->rowCount() === 0) continue; // another process grabbed it
    } catch (Exception $e) { continue; }

    // ── Generate payslip attachment (PDF required — no HTML fallback) ───────────

    $attach = null;
    try {
        $attach = HriPayslip::generate($item);
    } catch (Exception $e) {
        $errMsg = 'PDF generation failed: ' . $e->getMessage();
        $log("FAIL: #{$qid} $email — $errMsg");
        $newStatus = ($item['attempts'] >= 2) ? 'failed' : 'pending';
        try {
            $db->prepare("UPDATE payslip_queue SET status=?, error_msg=? WHERE id=?")->execute([$newStatus, substr($errMsg,0,490), $qid]);
        } catch (Exception $dbE) {}
        continue; // skip sending — don't deliver HTML when PDF is expected
    }

    // ── Build email ───────────────────────────────────────────────────────────

    $period     = $item['pay_period'] ?: $item['batch_period'] ?: date('F Y');
    $clientName = !empty($item['client_name']) ? $item['client_name'] : '';
    $logoUrl    = APP_URL . '/hri-logo.png';

    $htmlBody  = HriPayslip::buildEmailHtml($item, $logoUrl);
    $plainBody = HriPayslip::buildEmailPlain($item);

    // ── Send via PHPMailer ────────────────────────────────────────────────────

    $sent = false;
    $errMsg = '';
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = NOTIFY_MAIL_USER;
        $mail->Password   = NOTIFY_MAIL_PASS;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        if (!SMTP_VERIFY_SSL) {
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }

        $mail->setFrom(NOTIFY_MAIL_USER, HriPayslip::COMPANY . ' — Payroll');
        $mail->addAddress($email, $name);
        $clientSuffix = $clientName ? ' — ' . $clientName : '';
        $mail->Subject = "Payslip — {$period}{$clientSuffix} — " . HriPayslip::COMPANY;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;

        if ($attach) {
            $mail->addStringAttachment(
                $attach['content'],
                $attach['filename'],
                'base64',
                $attach['mime']
            );
        }

        $mail->send();
        $sent = true;
        $log("SENT: {$item['batch_ref']} | #{$qid} | $name <$email>");
    } catch (MailException $e) {
        $errMsg = substr($e->getMessage(), 0, 490);
        $log("FAIL: #{$qid} $email — $errMsg");
    } catch (Exception $e) {
        $errMsg = substr($e->getMessage(), 0, 490);
        $log("FAIL: #{$qid} $email — $errMsg");
    }

    // ── Update queue status ───────────────────────────────────────────────────

    try {
        if ($sent) {
            $db->prepare("UPDATE payslip_queue SET status='sent', sent_at=NOW(), error_msg=NULL WHERE id=?")
               ->execute([$qid]);
            $sentOk++;
        } else {
            $newStatus = ($item['attempts'] >= 2) ? 'failed' : 'pending';
            $db->prepare("UPDATE payslip_queue SET status=?, error_msg=? WHERE id=?")
               ->execute([$newStatus, $errMsg, $qid]);
            $sentFail++;
        }
    } catch (Exception $e) {
        $log("DB update error for #{$qid}: " . $e->getMessage());
    }

    // Small delay between sends (SMTP relay friendliness)
    if (count($items) > 1) usleep(500000); // 0.5s
}

// ── Update batch summary counts ───────────────────────────────────────────────

foreach ($batchIds as $bid) {
    try {
        $db->prepare("UPDATE payslip_batches b SET
            b.sent   = (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=? AND q.status='sent'),
            b.failed = (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=? AND q.status='failed'),
            b.status = CASE
                WHEN (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=? AND q.status='pending')=0
                     AND (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=? AND q.status='failed')=0
                     THEN 'completed'
                WHEN (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=? AND q.status='pending')=0
                     THEN 'partial'
                ELSE 'sending'
            END,
            b.completed_at = CASE
                WHEN (SELECT COUNT(*) FROM payslip_queue q WHERE q.batch_id=? AND q.status='pending')=0
                     THEN NOW() ELSE b.completed_at END
            WHERE b.id=?")->execute([$bid,$bid,$bid,$bid,$bid,$bid,$bid]);
    } catch (Exception $e) {
        $log("Batch update error $bid: " . $e->getMessage());
    }
}

$remaining = max(0, $hourlyBudget - $sentInHour - $sentOk);
$log("Done. Sent: $sentOk, Failed: $sentFail. Hourly budget remaining: {$remaining}/{$hourlyBudget}.");

// ── Completion notification to uploader ────────────────────────────────────────

// Ensure notified column exists (runs once silently, ignored thereafter)
try {
    $colCheck = $db->query("SELECT notified FROM payslip_batches LIMIT 1");
} catch (Exception $e) {
    try { $db->exec("ALTER TABLE payslip_batches ADD COLUMN notified TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e2) {}
}

foreach ($batchIds as $bid) {
    try {
        $bInfo = $db->prepare(
            "SELECT b.*, u.email uploader_email, u.name uploader_name
             FROM payslip_batches b JOIN users u ON u.id=b.uploaded_by
             WHERE b.id=? AND b.status IN ('completed','partial') AND b.notified=0"
        );
        $bInfo->execute([$bid]);
        $bData = $bInfo->fetch(PDO::FETCH_ASSOC);
        if (!$bData) continue;

        // Mark notified first to prevent double-send if cron overlaps
        $db->prepare("UPDATE payslip_batches SET notified=1 WHERE id=?")->execute([$bid]);

        $toEmail  = $bData['uploader_email'];
        $toName   = $bData['uploader_name'];
        $batchRef = $bData['batch_ref'];
        $period   = $bData['pay_period'];
        $total    = (int)$bData['total'];
        $sent     = (int)$bData['sent'];
        $failed   = (int)$bData['failed'];
        $pending  = max(0, $total - $sent - $failed);
        $client   = !empty($bData['client_name']) ? $bData['client_name'] : '';
        $logoUrl  = APP_URL . '/hri-logo.png';
        $appUrl   = APP_URL;
        $esc      = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

        $statusLine = $bData['status'] === 'completed'
            ? '<span style="color:#059669;font-weight:700;">&#9989; Fully Completed</span>'
            : '<span style="color:#dc2626;font-weight:700;">&#9888; Partially Completed — ' . $failed . ' failed</span>';

        $summaryHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>body{margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;}
.wrap{max-width:540px;margin:24px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);}
.hd{background:#002850;padding:16px 24px;display:flex;align-items:center;gap:12px;}
.hd img{height:40px;}.hd span{color:#fff;font-size:15px;font-weight:700;}
.bd{padding:24px;}
.bd p{margin:0 0 14px;line-height:1.6;}
.row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:13px;}
.row .lbl{color:#64748b;}.row .val{font-weight:700;}
.cta{display:inline-block;margin-top:18px;background:#002850;color:#fff;padding:10px 22px;border-radius:7px;text-decoration:none;font-weight:600;font-size:13px;}
.ft{background:#f8f8f8;padding:12px 24px;font-size:11px;color:#888;text-align:center;border-top:1px solid #e5e7eb;}
</style></head><body>
<div class="wrap">
  <div class="hd"><img src="' . $logoUrl . '" alt="HRI"><span>Payslip Batch Complete</span></div>
  <div class="bd">
    <p>Hi <strong>' . $esc($toName) . '</strong>,</p>
    <p>Your payslip distribution batch <strong>' . $esc($batchRef) . '</strong> has finished processing.' . ($client ? ' Client: <strong>' . $esc($client) . '</strong>.' : '') . '</p>
    <div class="row"><span class="lbl">Status</span><span class="val">' . $statusLine . '</span></div>
    <div class="row"><span class="lbl">Pay Period</span><span class="val">' . $esc($period) . '</span></div>
    <div class="row"><span class="lbl">Total Recipients</span><span class="val">' . $total . '</span></div>
    <div class="row"><span class="lbl">&#9989; Sent Successfully</span><span class="val" style="color:#059669;">' . $sent . '</span></div>
    <div class="row"><span class="lbl">&#10060; Failed</span><span class="val" style="color:' . ($failed > 0 ? '#dc2626' : '#64748b') . ';">' . $failed . '</span></div>
    <div class="row"><span class="lbl">&#9203; Still Pending</span><span class="val" style="color:#f59e0b;">' . $pending . '</span></div>'
    . ($failed > 0 ? '<p style="margin-top:14px;color:#dc2626;font-size:13px;">&#9888; There are ' . $failed . ' failed items. You can retry them from the Payslip page.</p>' : '')
    . '<a class="cta" href="' . $appUrl . '/payslip.php">View Report &amp; Retry</a>
  </div>
  <div class="ft">' . HriPayslip::COMPANY . ' &nbsp;|&nbsp; ' . HriPayslip::RC . ' &nbsp;|&nbsp; ' . HriPayslip::NDPC . '</div>
</div></body></html>';

        $summaryPlain = "Hi {$toName},\r\n\r\n"
            . "Your payslip batch {$batchRef} has finished.\r\n"
            . "Status: " . ucfirst($bData['status']) . "\r\n"
            . "Period: {$period}\r\n"
            . "Sent: {$sent} / Failed: {$failed} / Pending: {$pending}\r\n\r\n"
            . ($failed > 0 ? "There are {$failed} failed items. Visit the Payslip page to retry them.\r\n\r\n" : '')
            . "View report: {$appUrl}/payslip.php\r\n\r\n"
            . "Regards,\r\nHRI Mail System\r\n" . HriPayslip::COMPANY;

        $notifMail = new PHPMailer(true);
        $notifMail->isSMTP();
        $notifMail->Host       = SMTP_HOST;
        $notifMail->SMTPAuth   = true;
        $notifMail->Username   = NOTIFY_MAIL_USER;
        $notifMail->Password   = NOTIFY_MAIL_PASS;
        $notifMail->SMTPSecure = SMTP_ENCRYPTION;
        $notifMail->Port       = SMTP_PORT;
        $notifMail->CharSet    = 'UTF-8';
        if (!SMTP_VERIFY_SSL) {
            $notifMail->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        }
        $notifMail->setFrom(NOTIFY_MAIL_USER, HriPayslip::COMPANY . ' — HRI Mail');
        $notifMail->addAddress($toEmail, $toName);
        $notifMail->Subject = "Payslip Batch Complete — {$batchRef} — {$period} — " . HriPayslip::COMPANY;
        $notifMail->isHTML(true);
        $notifMail->Body    = $summaryHtml;
        $notifMail->AltBody = $summaryPlain;
        $notifMail->send();
        $log("Completion notification sent to {$toEmail} for batch {$batchRef}");
    } catch (Exception $e) {
        $log("Completion notify error for batch $bid: " . $e->getMessage());
    }
}
