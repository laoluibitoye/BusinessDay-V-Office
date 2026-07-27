<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::requireRole(['md','bdm','head_it','head_outsourcing','head_compliance','head_accounts','hr','head_training','head_cso','training_manager','cs_manager']);
$db   = getDB();

// ── PDF diagnostic (GET ?action=check-pdf) — admin only ─────────────────────
if (($_GET['action'] ?? '') === 'check-pdf') {
    header('Content-Type: application/json');
    $diag = ['engine'=>'none','mpdf_available'=>false,
             'fpdf_found'=>false,'font_dir_found'=>false,'font_files'=>[],
             'fpdf_class'=>false,'logo_found'=>false,'logo_png_ok'=>false,
             'full_pdf_ok'=>false,'full_pdf_size'=>null,'error'=>null];
    $fpdfPath = __DIR__ . '/lib/fpdf.php';
    // Check mPDF
    $autoload = __DIR__ . '/lib/vendor/autoload.php';
    if (file_exists($autoload)) { require_once $autoload; }
    $diag['mpdf_available'] = class_exists('\Mpdf\Mpdf');
    $diag['engine'] = $diag['mpdf_available'] ? 'mPDF' : (file_exists($fpdfPath) ? 'FPDF' : 'none');

    // Check FPDF
    $diag['fpdf_found'] = file_exists($fpdfPath);
    $fontDir = __DIR__ . '/lib/font/';
    $diag['font_dir_found'] = is_dir($fontDir);
    if ($diag['font_dir_found']) {
        $diag['font_files'] = array_values(array_filter(scandir($fontDir), function($f){ return $f !== '.' && $f !== '..'; }));
    }

    // Logo check
    $logoPath = __DIR__ . '/hri-logo.png';
    $diag['logo_found'] = file_exists($logoPath);

    // Full PDF test
    try {
        require_once __DIR__ . '/lib/payslip-pdf.php';
        $dummy = array_fill_keys(['employee_name','employee_id','designation','location',
            'pfa','pension_pin','pay_day','tax_id','days_in_month','days_worked',
            'pay_period','basic_salary','housing','transportation','other_allowances',
            'hmo_employer','psf_arrears','total_earnings','hmo_employee','monthly_pension',
            'monthly_tax','additional_hmo','loan_repayment','caution_fee',
            'total_deductions','net_pay','client_name','sender_name'], null);
        $dummy['employee_name']    = 'Test Employee';
        $dummy['pay_period']       = date('F Y');
        $dummy['net_pay']          = 150000;
        $dummy['total_earnings']   = 150000;
        $dummy['total_deductions'] = 0;
        $dummy['basic_salary']     = 150000;
        $result = HriPayslip::generate($dummy);
        $diag['full_pdf_ok']   = $result['mime'] === 'application/pdf';
        $diag['full_pdf_size'] = strlen($result['content']);
    } catch (Throwable $fe) {
        $diag['error'] = $fe->getMessage();
    }
    echo json_encode($diag, JSON_PRETTY_PRINT);
    exit;
}

// ── Retry failed items in a batch ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry_failed') {
    header('Content-Type: application/json');
    $batchId = (int)($_POST['batch_id'] ?? 0);
    if (!$batchId) { echo json_encode(['ok'=>false,'error'=>'batch_id required.']); exit; }
    try {
        $upd = $db->prepare("UPDATE payslip_queue SET status='pending', attempts=0, error_msg=NULL WHERE batch_id=? AND status='failed'");
        $upd->execute([$batchId]);
        $retried = $upd->rowCount();
        $db->prepare("UPDATE payslip_batches SET status='queued', failed=0 WHERE id=? AND status IN ('partial','completed')")->execute([$batchId]);
        try { $db->prepare("UPDATE payslip_batches SET notified=0 WHERE id=?")->execute([$batchId]); } catch (Exception $e2) {}
        Auth::auditLog($user['id'], 'payslip_retry', "Retried $retried failed items in batch #$batchId");
        echo json_encode(['ok'=>true,'retried'=>$retried]);
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ── Resend a single queue item ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_item') {
    header('Content-Type: application/json');
    $queueId = (int)($_POST['queue_id'] ?? 0);
    if (!$queueId) { echo json_encode(['ok'=>false,'error'=>'queue_id required.']); exit; }
    try {
        $rq = $db->prepare("SELECT batch_id FROM payslip_queue WHERE id=? AND status='failed'");
        $rq->execute([$queueId]);
        $rqRow = $rq->fetch(PDO::FETCH_ASSOC);
        if (!$rqRow) { echo json_encode(['ok'=>false,'error'=>'Item not found or not in failed state.']); exit; }
        $db->prepare("UPDATE payslip_queue SET status='pending', attempts=0, error_msg=NULL WHERE id=?")->execute([$queueId]);
        $db->prepare("UPDATE payslip_batches SET status='queued' WHERE id=? AND status IN ('partial','completed')")->execute([$rqRow['batch_id']]);
        try { $db->prepare("UPDATE payslip_batches SET notified=0 WHERE id=?")->execute([$rqRow['batch_id']]); } catch (Exception $e2) {}
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ── Test send — first row of batch to uploader's own email ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_send') {
    header('Content-Type: application/json');
    $batchId = (int)($_POST['batch_id'] ?? 0);
    if (!$batchId) { echo json_encode(['ok'=>false,'error'=>'batch_id required.']); exit; }
    try {
        require_once __DIR__ . '/config/mail.php';
        require_once __DIR__ . '/lib/payslip-pdf.php';
        require_once __DIR__ . '/lib/vendor/autoload.php';

        $stmt = $db->prepare(
            "SELECT q.*, b.client_name, b.sender_name, b.pay_period AS batch_period
             FROM payslip_queue q JOIN payslip_batches b ON b.id=q.batch_id
             WHERE q.batch_id=? ORDER BY q.row_num ASC LIMIT 1"
        );
        $stmt->execute([$batchId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) { echo json_encode(['ok'=>false,'error'=>'No items found in batch.']); exit; }

        // Override recipient — send to logged-in user
        $item['employee_email'] = $user['email'];
        $item['employee_name']  = '[TEST] ' . $item['employee_name'];

        $attach = HriPayslip::generate($item); // throws if FPDF missing — message shown to user

        $period     = $item['pay_period'] ?: ($item['batch_period'] ?? date('F Y'));
        $clientName = !empty($item['client_name']) ? $item['client_name'] : '';
        $logoUrl    = APP_URL . '/hri-logo.png';
        $htmlBody   = HriPayslip::buildEmailHtml($item, $logoUrl);
        $plainBody  = HriPayslip::buildEmailPlain($item);

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = NOTIFY_MAIL_USER;
        $mail->Password   = NOTIFY_MAIL_PASS;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        if (!SMTP_VERIFY_SSL) {
            $mail->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        }
        $mail->setFrom(NOTIFY_MAIL_USER, HriPayslip::COMPANY . ' — Payroll Test');
        $mail->addAddress($user['email'], $user['name']);
        $clientSfx   = $clientName ? ' — ' . $clientName : '';
        $mail->Subject = '[TEST] Payslip — ' . $period . $clientSfx . ' — ' . HriPayslip::COMPANY;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;
        if ($attach) {
            $mail->addStringAttachment($attach['content'], $attach['filename'], 'base64', $attach['mime']);
        }
        $mail->send();

        Auth::auditLog($user['id'], 'payslip_test_send', "Test payslip for batch #$batchId sent to {$user['email']}");
        echo json_encode(['ok'=>true,'message'=>'Test payslip sent to ' . $user['email']]);
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        echo json_encode(['ok'=>false,'error'=>'Mail error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Report JSON (replaces api/payslip/report.php) ────────────────────────────
if (($_GET['action'] ?? '') === 'report') {
    header('Content-Type: application/json');
    $batchId = (int)($_GET['batch_id'] ?? 0);
    if (!$batchId) { echo json_encode(['ok'=>false,'error'=>'batch_id required.']); exit; }
    try {
        $bRow = $db->prepare("SELECT b.*, u.name uploader FROM payslip_batches b JOIN users u ON u.id=b.uploaded_by WHERE b.id=?");
        $bRow->execute([$batchId]);
        $b = $bRow->fetch(PDO::FETCH_ASSOC);
        if (!$b) { echo json_encode(['ok'=>false,'error'=>'Batch not found.']); exit; }
        $c = $db->prepare("SELECT SUM(status='pending') pending, SUM(status='sending') sending,
            SUM(status='sent') sent, SUM(status='failed') failed, COUNT(*) total
            FROM payslip_queue WHERE batch_id=?");
        $c->execute([$batchId]);
        $counts = $c->fetch(PDO::FETCH_ASSOC);
        $rows = $db->prepare("SELECT id,row_num,employee_name,employee_email,employee_id,
            designation,status,attempts,sent_at,error_msg
            FROM payslip_queue WHERE batch_id=? ORDER BY row_num ASC");
        $rows->execute([$batchId]);
        $items = $rows->fetchAll(PDO::FETCH_ASSOC);
        $pending = (int)$counts['pending'];
        $eta = null;
        if ($pending > 0) {
            $sentInHour = (int)$db->query("SELECT COUNT(*) FROM payslip_queue WHERE status='sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)")->fetchColumn();
            $eta = max(0, 70 - $sentInHour) > 0
                ? date('H:i', time() + (int)ceil($pending / 70 * 3600))
                : 'Rate limit reached — resumes within the hour';
        }
        echo json_encode(['ok'=>true,'batch'=>array_merge($b,[
            'live_total'   => (int)$counts['total'],
            'live_sent'    => (int)$counts['sent'],
            'live_failed'  => (int)$counts['failed'],
            'live_pending' => (int)$counts['pending'],
            'live_sending' => (int)$counts['sending'],
            'eta'          => $eta,
        ]),'rows'=>$items]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>'Query error: '.$e->getMessage()]);
    }
    exit;
}

// ── CSV template download ─────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'csv') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payslip_csv_' . date('Y-m') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $cols = ['employee_name','email','employee_id','designation','location',
        'pay_period','pay_day','pfa','pension_pin','days_in_month','days_worked','tax_id',
        'basic_salary','housing','transportation','other_allowances','hmo_employer','psf_arrears',
        'hmo_employee','monthly_pension','monthly_tax','additional_hmo','loan_repayment','caution_fee'];
    $eg   = ['John Doe','john.doe@example.com','HRI-001','Client Service Officer','Lagos',
        date('F Y'),date('d M Y'),'NLPC','PEN123456789','31','31','TAX-00123',
        '150000','50000','30000','20000','0','0','10000','18000','12000','0','0','0'];
    $row  = function(array $f) { $p=[]; foreach($f as $v) $p[]='"'.str_replace('"','""',(string)$v).'"'; return implode(',',$p)."\r\n"; };
    echo "\xEF\xBB\xBF" . $row($cols) . $row($eg);
    exit;
}

$totalBatches = $totalSent = $totalFailed = $totalPending = 0;
$batches = [];

try {
    $stats = $db->query("SELECT
        COUNT(DISTINCT b.id) batches,
        COALESCE(SUM(b.sent),0) sent,
        COALESCE(SUM(b.failed),0) failed,
        COALESCE((SELECT COUNT(*) FROM payslip_queue WHERE status='pending'),0) pending
        FROM payslip_batches b")->fetch(PDO::FETCH_ASSOC);
    $totalBatches = (int)$stats['batches'];
    $totalSent    = (int)$stats['sent'];
    $totalFailed  = (int)$stats['failed'];
    $totalPending = (int)$stats['pending'];

    $bStmt = $db->query("SELECT b.*, u.name uploader_name
        FROM payslip_batches b JOIN users u ON u.id=b.uploaded_by
        ORDER BY b.created_at DESC LIMIT 100");
    $batches = $bStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Rate budget
$rateLeft = 70;
try {
    $rSt = $db->query("SELECT COUNT(*) FROM payslip_queue WHERE status='sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
    $rateLeft = max(0, 70 - (int)$rSt->fetchColumn());
} catch (Exception $e) {}

Layout::shell($user, 'payslip', 0, 'Payslip Distribution');
?>
<style>
.ps-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.875rem;margin-bottom:1.5rem;}
.ps-stat{background:var(--w);border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:.875rem 1rem;border-left:4px solid;}
.ps-stat-n{font-size:1.6rem;font-weight:800;line-height:1;}
.ps-stat-l{font-size:.78rem;color:var(--g400);margin-top:.25rem;text-transform:uppercase;letter-spacing:.05em;}
.upload-drop{border:2px dashed #cbd5e1;border-radius:.75rem;padding:2rem 1.5rem;text-align:center;cursor:pointer;transition:all .15s;background:#fafafa;}
.upload-drop:hover,.upload-drop.drag{border-color:var(--navy);background:#eff6ff;}
.upload-drop.has-file{border-color:var(--green);background:#f0fdf4;}
.upload-icon{font-size:2.5rem;margin-bottom:.5rem;}
.ps-batch{background:var(--w);border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:.75rem;overflow:hidden;}
.ps-batch-hd{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;flex-wrap:wrap;}
.ps-ref{font-family:monospace;font-weight:700;color:var(--navy);font-size:.9rem;}
.ps-period{font-size:.8rem;background:#e0f2fe;color:#0369a1;padding:.15rem .5rem;border-radius:9999px;font-weight:500;}
.ps-bar-wrap{padding:.4rem 1rem .6rem;background:#f8fafc;}
.ps-bar-bg{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;}
.ps-bar-fill{height:100%;border-radius:3px;transition:width .3s;}
.ps-bar-meta{display:flex;gap:1.25rem;font-size:.78rem;color:var(--g400);margin-top:.3rem;flex-wrap:wrap;}
.ps-status{font-size:.75rem;padding:.2rem .55rem;border-radius:9999px;font-weight:600;white-space:nowrap;}
.ps-st-queued{background:#e0f2fe;color:#0369a1;}
.ps-st-sending{background:#fef3c7;color:#92400e;}
.ps-st-completed{background:#dcfce7;color:#166534;}
.ps-st-partial{background:#fee2e2;color:#dc2626;}
.ps-st-cancelled{background:#f1f5f9;color:#64748b;}
/* Report modal */
.rpt-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:700;align-items:center;justify-content:center;padding:1rem;}
.rpt-modal.open{display:flex;}
.rpt-box{background:var(--w);border-radius:14px;box-shadow:0 8px 32px rgba(0,40,80,.18);width:100%;max-width:780px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;}
.rpt-hd{padding:1rem 1.25rem;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.rpt-bd{overflow-y:auto;flex:1;}
.rpt-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;padding:1rem 1.25rem;background:#f8fafc;border-bottom:1px solid var(--g100);}
.rpt-sitem{text-align:center;}
.rpt-sitem .n{font-size:1.4rem;font-weight:800;}
.rpt-sitem .l{font-size:.72rem;color:var(--g400);text-transform:uppercase;letter-spacing:.05em;}
.rpt-row{display:flex;align-items:center;gap:.75rem;padding:.55rem 1.25rem;border-bottom:1px solid var(--g50);font-size:.83rem;}
.rpt-icon{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0;}
.icon-sent{background:#dcfce7;color:#166534;}
.icon-fail{background:#fee2e2;color:#dc2626;}
.icon-pend{background:#fef3c7;color:#92400e;}
.icon-send{background:#dbeafe;color:#1e40af;}
@media(max-width:640px){.ps-stat-grid{grid-template-columns:1fr 1fr;}.rpt-summary{grid-template-columns:1fr 1fr;}}
</style>

<div class="hri-page">
<div class="hri-page-hd">
  <h1 class="hri-page-title">&#128196; Payslip Distribution</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <a href="payslip.php?action=csv" class="hri-btn hri-btn-outline" style="font-size:.85rem;">&#8659; Download CSV Template</a>
    <button onclick="openUpload()" class="hri-btn hri-btn-navy">&#43; New Distribution</button>
  </div>
</div>

<!-- Stats row -->
<div class="ps-stat-grid">
  <div class="ps-stat" style="border-color:var(--navy);">
    <div class="ps-stat-n" style="color:var(--navy);"><?= $totalBatches ?></div>
    <div class="ps-stat-l">Total Batches</div>
  </div>
  <div class="ps-stat" style="border-color:#059669;">
    <div class="ps-stat-n" style="color:#059669;"><?= number_format($totalSent) ?></div>
    <div class="ps-stat-l">Total Sent</div>
  </div>
  <div class="ps-stat" style="border-color:#dc2626;">
    <div class="ps-stat-n" style="color:#dc2626;"><?= number_format($totalFailed) ?></div>
    <div class="ps-stat-l">Failed</div>
  </div>
  <div class="ps-stat" style="border-color:#f59e0b;">
    <div class="ps-stat-n" style="color:#f59e0b;"><?= number_format($totalPending) ?></div>
    <div class="ps-stat-l">Queued / Pending</div>
  </div>
  <div class="ps-stat" style="border-color:#64748b;">
    <div class="ps-stat-n" style="color:#64748b;"><?= $rateLeft ?><span style="font-size:1rem;">/70</span></div>
    <div class="ps-stat-l">Rate Budget (this hour)</div>
  </div>
</div>

<?php if (empty($batches)): ?>
<div class="hri-card" style="padding:2.5rem;text-align:center;color:var(--g400);">
  <div style="font-size:3rem;margin-bottom:.75rem;">&#128196;</div>
  <div style="font-size:1rem;font-weight:600;color:var(--navy);margin-bottom:.5rem;">No payslip batches yet</div>
  <div style="font-size:.875rem;margin-bottom:1.25rem;">Upload a filled CSV template to start distributing payslips.</div>
  <button onclick="openUpload()" class="hri-btn hri-btn-navy">Upload CSV Template</button>
</div>
<?php else: ?>
<div class="hri-card" style="margin-bottom:1rem;">
  <div class="hri-card-hd"><h2 class="hri-card-title">Distribution Batches</h2></div>
</div>
<?php foreach ($batches as $b):
    $pct  = $b['total'] > 0 ? round(($b['sent'] + $b['failed']) / $b['total'] * 100) : 0;
    $stCl = 'ps-st-' . $b['status'];
    $barColor = $b['failed'] > 0 ? '#dc2626' : ($b['status']==='completed' ? '#059669' : '#f59e0b');
?>
<div class="ps-batch">
  <div class="ps-batch-hd">
    <span class="ps-ref"><?= htmlspecialchars($b['batch_ref']) ?></span>
    <span class="ps-period">&#128197; <?= htmlspecialchars($b['pay_period']) ?></span>
    <span class="ps-status <?= $stCl ?>"><?= ucfirst($b['status']) ?></span>
    <?php if (!empty($b['client_name'])): ?>
    <span style="font-size:.8rem;background:#f0fdf4;color:#166534;border:1px solid #86efac;border-radius:9999px;padding:.15rem .6rem;font-weight:600;">&#127970; <?= htmlspecialchars($b['client_name']) ?></span>
    <?php endif; ?>
    <span style="font-size:.8rem;color:var(--g400);margin-left:auto;"><?= htmlspecialchars($b['uploader_name']) ?> &bull; <?= date('d M Y, H:i', strtotime($b['created_at'])) ?></span>
    <button onclick="openReport(<?= (int)$b['id'] ?>, <?= htmlspecialchars(json_encode($b['batch_ref']), ENT_QUOTES, 'UTF-8') ?>)" class="hri-btn hri-btn-outline" style="font-size:.78rem;padding:.25rem .7rem;">
      &#128202; Report
    </button>
    <button onclick="testSend(<?= (int)$b['id'] ?>, this)" class="hri-btn hri-btn-outline" style="font-size:.78rem;padding:.25rem .7rem;" title="Send first row to your email as a test">&#9993; Test</button>
    <?php if ($b['status'] === 'queued' || $b['status'] === 'sending'): ?>
    <button onclick="cancelBatch(<?= $b['id'] ?>,this)" class="hri-btn" style="font-size:.78rem;padding:.25rem .7rem;background:#fee2e2;color:#dc2626;border:none;">Cancel</button>
    <?php endif; ?>
  </div>
  <div class="ps-bar-wrap">
    <div class="ps-bar-bg"><div class="ps-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div></div>
    <div class="ps-bar-meta">
      <span>&#9989; Sent: <strong><?= $b['sent'] ?></strong></span>
      <span>&#10060; Failed: <strong><?= $b['failed'] ?></strong></span>
      <span>&#9203; Pending: <strong><?= max(0, $b['total'] - $b['sent'] - $b['failed']) ?></strong></span>
      <span>Total: <strong><?= $b['total'] ?></strong></span>
      <span style="margin-left:auto;"><?= $pct ?>% processed</span>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ═══ UPLOAD MODAL ═══ -->
<div class="rpt-modal" id="uploadModal">
  <div class="rpt-box" style="max-width:560px;">
    <div class="rpt-hd">
      <strong style="color:var(--navy);font-size:1rem;">&#128196; Upload Payslip CSV</strong>
      <button onclick="closeUpload()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:var(--g400);">&times;</button>
    </div>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
      <div style="font-size:.83rem;color:var(--g600);background:#fffbeb;border:1px solid #fcd34d;border-radius:.4rem;padding:.6rem .875rem;">
        &#9888; Ensure your CSV file uses the correct column headers.
        <a href="payslip.php?action=csv" style="color:var(--navy);font-weight:600;">Download the template</a> to fill in employee data.
      </div>
      <div>
        <label class="hri-label">Client / Company Name <span style="color:var(--g400);font-weight:400;">(optional)</span></label>
        <input type="text" id="upClientName" class="hri-input" placeholder="e.g. Zenith Bank, MTN Nigeria">
        <div style="font-size:.78rem;color:var(--g400);margin-top:.25rem;">Appears on the payslip title and email subject to identify the client.</div>
      </div>
      <div>
        <label class="hri-label">Pay Period (for whole batch)</label>
        <input type="text" id="upPayPeriod" class="hri-input" placeholder="e.g. July 2026" value="<?= date('F Y') ?>">
        <div style="font-size:.78rem;color:var(--g400);margin-top:.25rem;">Overridden per-row if your CSV has a pay_period column.</div>
      </div>
      <div>
        <div class="upload-drop" id="uploadDrop" onclick="document.getElementById('csvFileInput').click()">
          <div class="upload-icon">&#128196;</div>
          <div style="font-weight:600;color:var(--navy);margin-bottom:.25rem;" id="dropLabel">Click to choose CSV file</div>
          <div style="font-size:.8rem;color:var(--g400);">or drag &amp; drop here &mdash; .csv files only, max 5MB</div>
        </div>
        <input type="file" id="csvFileInput" accept=".csv,text/csv" style="display:none;" onchange="handleFileSelect(this)">
      </div>
      <div id="uploadError" style="display:none;background:#fee2e2;color:#dc2626;border-radius:.4rem;padding:.6rem .875rem;font-size:.84rem;"></div>
      <div style="display:flex;gap:.75rem;justify-content:flex-end;">
        <button onclick="closeUpload()" class="hri-btn hri-btn-outline">Cancel</button>
        <button onclick="doUpload()" class="hri-btn hri-btn-navy" id="uploadBtn" disabled>&#128229; Upload &amp; Queue</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ REPORT MODAL ═══ -->
<div class="rpt-modal" id="reportModal">
  <div class="rpt-box">
    <div class="rpt-hd">
      <div>
        <div style="font-weight:700;color:var(--navy);" id="rptTitle">Delivery Report</div>
        <div style="font-size:.78rem;color:var(--g400);" id="rptEta"></div>
      </div>
      <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
        <button id="rptRetryBtn" onclick="retryFailed()" class="hri-btn" style="display:none;font-size:.78rem;padding:.3rem .75rem;background:#fff3cd;color:#92400e;border:1.5px solid #fbbf24;">&#128260; Retry Failed</button>
        <button onclick="exportReportCsv()" class="hri-btn hri-btn-outline" style="font-size:.78rem;padding:.3rem .75rem;">&#8659; Export CSV</button>
        <button onclick="closeReport()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:var(--g400);padding:0 .25rem;">&times;</button>
      </div>
    </div>
    <div class="rpt-summary" id="rptSummary"></div>
    <div class="rpt-bd" id="rptBody"><div style="padding:2rem;text-align:center;color:var(--g400);">Loading...</div></div>
  </div>
</div>

<script>
var selectedFile = null;
var reportRefreshTimer = null;
var currentReportBatch = null;
var currentReportData  = null;   // cached rows for CSV export

// ── Upload modal ──────────────────────────────────────────────────────────────

function openUpload() {
    document.getElementById('uploadModal').classList.add('open');
}
function closeUpload() {
    document.getElementById('uploadModal').classList.remove('open');
    selectedFile = null;
    document.getElementById('uploadBtn').disabled = true;
    document.getElementById('dropLabel').textContent = 'Click to choose CSV file';
    document.getElementById('uploadDrop').className = 'upload-drop';
    document.getElementById('uploadError').style.display = 'none';
    document.getElementById('upClientName').value = '';
}

var dropArea = document.getElementById('uploadDrop');
['dragenter','dragover'].forEach(function(ev) {
    dropArea.addEventListener(ev, function(e){ e.preventDefault(); dropArea.classList.add('drag'); });
});
['dragleave','drop'].forEach(function(ev) {
    dropArea.addEventListener(ev, function(e){ e.preventDefault(); dropArea.classList.remove('drag'); });
});
dropArea.addEventListener('drop', function(e) {
    var files = e.dataTransfer.files;
    if (files.length) setFile(files[0]);
});

function handleFileSelect(inp) { if (inp.files.length) setFile(inp.files[0]); }
function setFile(f) {
    if (!f.name.toLowerCase().endsWith('.csv')) {
        showUploadError('Only CSV files are accepted.');
        return;
    }
    selectedFile = f;
    document.getElementById('dropLabel').textContent = '&#10003; ' + f.name + ' (' + (f.size/1024).toFixed(1) + ' KB)';
    document.getElementById('uploadDrop').classList.add('has-file');
    document.getElementById('uploadBtn').disabled = false;
    document.getElementById('uploadError').style.display = 'none';
}

function showUploadError(msg) {
    var el = document.getElementById('uploadError');
    el.textContent = msg;
    el.style.display = '';
}

function doUpload() {
    if (!selectedFile) { showUploadError('Please select a CSV file first.'); return; }
    var btn = document.getElementById('uploadBtn');
    btn.disabled = true; btn.textContent = 'Uploading...';

    var fd = new FormData();
    fd.append('template', selectedFile);
    fd.append('pay_period', document.getElementById('upPayPeriod').value.trim());
    fd.append('client_name', document.getElementById('upClientName').value.trim());

    fetch('/api/payslip/upload.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'X-CSRF-Token': window.CSRF_TOKEN},
        body: fd
    })
    .then(function(r){ return r.json(); })
    .then(function(d) {
        if (!d.ok) { showUploadError(d.error || 'Upload failed.'); btn.disabled=false; btn.textContent='Upload & Queue'; return; }
        closeUpload();
        window.location.reload();
    })
    .catch(function(){ showUploadError('Network error. Please try again.'); btn.disabled=false; btn.textContent='Upload & Queue'; });
}

// ── Report modal ──────────────────────────────────────────────────────────────

function openReport(batchId, batchRef) {
    currentReportBatch = batchId;
    document.getElementById('rptTitle').textContent = 'Delivery Report — ' + batchRef;
    document.getElementById('rptEta').textContent = '';
    document.getElementById('rptSummary').innerHTML = '';
    document.getElementById('rptBody').innerHTML = '<div style="padding:2rem;text-align:center;color:#94a3b8;">Loading...</div>';
    document.getElementById('reportModal').classList.add('open');
    loadReport(batchId);
    // Auto-refresh every 20s while modal is open
    reportRefreshTimer = setInterval(function(){ if(currentReportBatch) loadReport(currentReportBatch); }, 20000);
}

function closeReport() {
    document.getElementById('reportModal').classList.remove('open');
    currentReportBatch = null;
    if (reportRefreshTimer) clearInterval(reportRefreshTimer);
}

function loadReport(batchId) {
    fetch('/payslip.php?action=report&batch_id=' + batchId, {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d) {
        if (!d.ok) { document.getElementById('rptBody').innerHTML='<div style="padding:1rem;color:var(--red);">' + (d.error||'Error') + '</div>'; return; }
        var b = d.batch;
        currentReportData = d;

        // Show/hide Retry button
        var retryBtn = document.getElementById('rptRetryBtn');
        if (retryBtn) retryBtn.style.display = b.live_failed > 0 ? '' : 'none';

        var statusColors = {sent:'#059669',failed:'#dc2626',pending:'#f59e0b',sending:'#3b82f6'};
        document.getElementById('rptSummary').innerHTML =
            '<div class="rpt-sitem"><div class="n" style="color:#059669;">' + b.live_sent + '</div><div class="l">Sent</div></div>' +
            '<div class="rpt-sitem"><div class="n" style="color:#dc2626;">' + b.live_failed + '</div><div class="l">Failed</div></div>' +
            '<div class="rpt-sitem"><div class="n" style="color:#f59e0b;">' + b.live_pending + '</div><div class="l">Pending</div></div>' +
            '<div class="rpt-sitem"><div class="n">' + b.live_total + '</div><div class="l">Total</div></div>';
        if (b.eta) document.getElementById('rptEta').textContent = 'Est. completion: ' + b.eta;

        var rows = d.rows || [];
        if (!rows.length) { document.getElementById('rptBody').innerHTML='<div style="padding:1.5rem;text-align:center;color:#94a3b8;">No items.</div>'; return; }
        var html = '';
        rows.forEach(function(row) {
            var icoClass = {sent:'icon-sent',failed:'icon-fail',pending:'icon-pend',sending:'icon-send'}[row.status] || 'icon-pend';
            var icoText  = {sent:'&#10003;',failed:'&#10007;',pending:'&#8230;',sending:'&#9654;'}[row.status] || '?';
            var errHtml  = row.error_msg ? '<div style="color:#dc2626;font-size:.75rem;margin-top:.15rem;">' + escHtml(row.error_msg) + '</div>' : '';
            var sentAt   = row.sent_at ? ' &bull; ' + row.sent_at.replace('T',' ').substring(0,16) : '';
            var resendBtn = row.status === 'failed'
                ? '<button onclick="resendItem(' + row.id + ',this)" style="font-size:.72rem;padding:.2rem .5rem;border:1.5px solid #dc2626;background:#fff;color:#dc2626;border-radius:5px;cursor:pointer;white-space:nowrap;margin-top:.2rem;" title="Resend this payslip">&#8635; Resend</button>'
                : '';
            html += '<div class="rpt-row" id="qrow-' + row.id + '">' +
                '<div class="rpt-icon ' + icoClass + '">' + icoText + '</div>' +
                '<div style="flex:1;min-width:0;">' +
                  '<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(row.employee_name) + '</div>' +
                  '<div style="color:var(--g400);font-size:.78rem;">' + escHtml(row.employee_email) + sentAt + '</div>' +
                  errHtml + resendBtn +
                '</div>' +
            '</div>';
        });
        document.getElementById('rptBody').innerHTML = html;
    })
    .catch(function(){ document.getElementById('rptBody').innerHTML='<div style="padding:1rem;color:var(--red);">Network error.</div>'; });
}

// ── Retry all failed in current batch ─────────────────────────────────────────

function retryFailed() {
    if (!currentReportBatch) return;
    var btn = document.getElementById('rptRetryBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Retrying…'; }
    fetch('/payslip.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN,'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=retry_failed&batch_id=' + currentReportBatch
    }).then(function(r){ return r.json(); })
    .then(function(d) {
        if (d.ok) {
            loadReport(currentReportBatch);
            if (btn) { btn.disabled=false; btn.textContent='&#128260; Retry Failed'; }
        } else {
            alert(d.error || 'Retry failed.');
            if (btn) { btn.disabled=false; btn.textContent='&#128260; Retry Failed'; }
        }
    }).catch(function(){
        alert('Network error.');
        if (btn) { btn.disabled=false; btn.textContent='&#128260; Retry Failed'; }
    });
}

// ── Resend a single failed item ───────────────────────────────────────────────

function resendItem(queueId, btnEl) {
    btnEl.disabled = true; btnEl.textContent = 'Queued…';
    fetch('/payslip.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN,'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=resend_item&queue_id=' + queueId
    }).then(function(r){ return r.json(); })
    .then(function(d) {
        if (d.ok) {
            btnEl.textContent = '&#10003; Queued';
            btnEl.style.borderColor = '#059669'; btnEl.style.color = '#059669';
            setTimeout(function(){ if(currentReportBatch) loadReport(currentReportBatch); }, 800);
        } else {
            alert(d.error || 'Resend failed.');
            btnEl.disabled = false; btnEl.textContent = '&#8635; Resend';
        }
    }).catch(function(){
        alert('Network error.');
        btnEl.disabled = false; btnEl.textContent = '&#8635; Resend';
    });
}

// ── Export current report as CSV ──────────────────────────────────────────────

function exportReportCsv() {
    if (!currentReportData || !currentReportData.rows) { alert('No report data to export.'); return; }
    var b     = currentReportData.batch;
    var rows  = currentReportData.rows;
    var esc   = function(v) { return '"' + String(v||'').replace(/"/g,'""') + '"'; };
    var cols  = ['#','Name','Email','Employee ID','Designation','Pay Period','Status','Sent At','Error'];
    var lines = [cols.map(esc).join(',')];
    rows.forEach(function(r) {
        lines.push([
            r.row_num, r.employee_name, r.employee_email, r.employee_id||'',
            r.designation||'', r.pay_period||'',
            r.status, r.sent_at||'', r.error_msg||''
        ].map(esc).join(','));
    });
    var csv  = '﻿' + lines.join('\r\n');
    var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href   = url;
    a.download = 'report-' + (b.batch_ref||'payslip').replace(/[^a-z0-9\-]/gi,'_') + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(function(){ document.body.removeChild(a); URL.revokeObjectURL(url); }, 500);
}

// ── Test send ─────────────────────────────────────────────────────────────────

function testSend(batchId, btnEl) {
    btnEl.disabled = true; btnEl.textContent = 'Sending…';
    fetch('/payslip.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN,'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=test_send&batch_id=' + batchId
    }).then(function(r){ return r.json(); })
    .then(function(d) {
        btnEl.disabled = false; btnEl.innerHTML = '&#9993; Test';
        if (d.ok) alert('&#10003; ' + (d.message || 'Test payslip sent to your email.'));
        else alert('Error: ' + (d.error || 'Test send failed.'));
    }).catch(function(){
        btnEl.disabled = false; btnEl.innerHTML = '&#9993; Test';
        alert('Network error. Please try again.');
    });
}

// ── Cancel batch ──────────────────────────────────────────────────────────────

function cancelBatch(id, btn) {
    if (!confirm('Cancel this batch? Pending emails will not be sent.')) return;
    btn.disabled = true;
    fetch('/api/payslip/cancel.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token':window.CSRF_TOKEN,'Content-Type':'application/x-www-form-urlencoded'},
        body:'id='+id
    }).then(function(r){return r.json();}).then(function(d){
        if(d.ok) window.location.reload();
        else { alert(d.error||'Cancel failed.'); btn.disabled=false; }
    }).catch(function(){ btn.disabled=false; });
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Close modals on backdrop click
['uploadModal','reportModal'].forEach(function(id){
    document.getElementById(id).addEventListener('click', function(e){ if(e.target===this) { id==='uploadModal'?closeUpload():closeReport(); } });
});

// Keyboard close
document.addEventListener('keydown', function(e){
    if(e.key==='Escape'){
        if(document.getElementById('reportModal').classList.contains('open')) closeReport();
        if(document.getElementById('uploadModal').classList.contains('open')) closeUpload();
    }
});
</script>
<?php Layout::end(); ?>
