<?php
class HriPayslip {

    const COMPANY   = 'HR INDEXX LIMITED';
    const ADDRESS   = '12 Macarthy Street, Onikan, Lagos Island';
    const RC        = 'RC: 446051';
    const NDPC      = 'NDPC/DCP/12819 (EHL)';
    const SIG_TITLE = 'Authorised Signatory / Employer';

    // Returns sender name from row, falling back to company
    private static function signatory(array $row): string {
        return !empty($row['sender_name']) ? $row['sender_name'] : 'HR Department';
    }

    // Returns logo as base64 data URI (safe for email clients)
    private static function logoDataUri(): string {
        $path = __DIR__ . '/../hri-logo.png';
        if (file_exists($path)) {
            $data = base64_encode(file_get_contents($path));
            return 'data:image/png;base64,' . $data;
        }
        return '';
    }

    public static function generate(array $row): array {
        // Priority 1: mPDF — converts the HTML layout to PDF (best output, same look as HTML)
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
            if (class_exists('\Mpdf\Mpdf')) {
                return self::mpdf($row);
            }
        }
        // Priority 2: FPDF fallback
        $fpdf = __DIR__ . '/fpdf.php';
        if (file_exists($fpdf)) {
            if (!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH', __DIR__ . '/font/');
            require_once $fpdf;
            if (class_exists('FPDF')) return self::pdf($row);
        }
        throw new RuntimeException('No PDF library available. Ensure lib/vendor/autoload.php exists (run composer install in lib/).');
    }

    // ── mPDF: render payslip using table-based HTML ───────────────────────────

    private static function mpdf(array $row): array {
        $html = self::mpdfHtml($row);

        // Try uploads/ as temp dir (more reliable on shared hosting than /tmp)
        $tmpDir = __DIR__ . '/../uploads/';
        if (!is_writable($tmpDir)) $tmpDir = sys_get_temp_dir();

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => [210, 200],
            'margin_left'   => 10,
            'margin_right'  => 10,
            'margin_top'    => 10,
            'margin_bottom' => 10,
            'default_font'  => 'dejavusans',
            'tempDir'       => $tmpDir,
        ]);
        $mpdf->SetCompression(true);
        $mpdf->SetWatermarkText('PAID', 0.07);
        $mpdf->showWatermarkText = true;
        $mpdf->watermark_font    = 'dejavusans';
        $mpdf->WriteHTML($html);
        $content = $mpdf->Output('', 'S');

        $empName = preg_replace('/[^a-zA-Z0-9 \-\.]/i', '', $row['employee_name'] ?: 'Employee');
        $period  = preg_replace('/[^a-zA-Z0-9 \-\.]/i', '', $row['pay_period'] ?: 'Payslip');
        return [
            'content'  => $content,
            'mime'     => 'application/pdf',
            'filename' => "Payslip - {$period} - {$empName}.pdf",
        ];
    }

    // mPDF-safe HTML: pure table layout, NO floats, NO CSS grid, NO flexbox
    private static function mpdfHtml(array $row): string {
        $r  = $row;
        $e  = function($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); };
        $na = 'N/A';

        $logo      = self::logoDataUri();
        $logoTag   = $logo ? '<img src="' . $logo . '" alt="HRI" style="height:38px;">' : '';
        $signatory = self::signatory($r);
        $titleLine = 'PAYSLIP  —  ' . strtoupper($r['pay_period'] ?? '');
        if (!empty($r['client_name'])) $titleLine .= '  —  ' . strtoupper($r['client_name']);
        $sentDate  = !empty($r['sent_at'])
            ? date('d F Y', strtotime($r['sent_at']))
            : date('d F Y');

        $h = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
body  { font-family: Arial, sans-serif; font-size: 9pt; color: #1a1a1a; margin: 0; padding: 0; }
table { border-collapse: collapse; }
td, th { padding: 0; margin: 0; }
</style>
</head><body>';

        // ── Outer border wrapper ────────────────────────────────────────────────
        $h .= '<table width="100%" style="border:2px solid #002850;"><tr><td style="padding:0;">';

        // ── Header: white logo cell | navy company info | ref top-right ─────────
        $h .= '<table width="100%" style="background:#002850;border-bottom:3px solid #64A014;">
<tr>
  <td width="88" style="background:#ffffff;padding:8px 10px;vertical-align:middle;text-align:center;">' . $logoTag . '</td>
  <td style="padding:10px 12px;vertical-align:middle;">
    <p style="color:#ffffff;font-size:13pt;font-weight:bold;margin:0;">' . self::COMPANY . '</p>
    <p style="color:#a8c8e8;font-size:7.5pt;margin:3px 0 0 0;">' . self::ADDRESS . ' &nbsp;|&nbsp; ' . self::RC . '</p>
  </td>
  <td width="110" style="padding:8px 12px;vertical-align:top;text-align:right;">
    <p style="color:#a8c8e8;font-size:6.5pt;margin:0;text-transform:uppercase;">Date Issued</p>
    <p style="color:#ffffff;font-size:8pt;font-weight:bold;margin:2px 0 0 0;">' . $e($sentDate) . '</p>
  </td>
</tr>
</table>';

        // ── Title band ─────────────────────────────────────────────────────────
        $h .= '<table width="100%" style="background:#64A014;">
<tr><td style="text-align:center;padding:6px 8px;color:#ffffff;font-size:11pt;font-weight:bold;">'
    . $e($titleLine) . '</td></tr>
</table>';

        // ── Employee name banner ────────────────────────────────────────────────
        $h .= '<table width="100%" style="background:#f0f4e8;border-bottom:1px solid #c8dfa8;">
<tr>
  <td style="padding:7px 12px;vertical-align:middle;">
    <p style="font-size:7pt;color:#5a7a20;text-transform:uppercase;margin:0;">Employee</p>
    <p style="font-size:14pt;font-weight:bold;color:#002850;margin:1px 0 0 0;">' . $e($r['employee_name'] ?? $na) . '</p>
  </td>
  <td width="130" style="padding:7px 12px;text-align:right;vertical-align:middle;">
    <p style="font-size:7pt;color:#5a7a20;text-transform:uppercase;margin:0;">Employee ID</p>
    <p style="font-size:10pt;font-weight:bold;color:#002850;margin:1px 0 0 0;">' . $e($r['employee_id'] ?: $na) . '</p>
  </td>
</tr>
</table>';

        // ── Employee detail grid (designation, location, pfa … days worked) ─────
        $fields = [
            ['Designation',   $r['designation']],    ['Location',     $r['location']],
            ['PFA',           $r['pfa']],            ['Pension PIN',  $r['pension_pin']],
            ['Pay Day',       $r['pay_day']],        ['Tax ID',       $r['tax_id']],
            ['Days in Month', $r['days_in_month']],  ['Days Worked',  $r['days_worked']],
        ];
        $h .= '<table width="100%" style="border-bottom:1px solid #dddddd;">';
        for ($i = 0; $i < count($fields); $i += 2) {
            $f1 = $fields[$i];
            $f2 = $fields[$i + 1] ?? ['', ''];
            $h .= '<tr>';
            $h .= '<td width="22%" style="padding:3px 10px;font-size:7pt;color:#777777;vertical-align:bottom;">' . $e($f1[0]) . '</td>';
            $h .= '<td width="28%" style="padding:3px 10px;font-size:9pt;font-weight:bold;color:#002850;">' . $e($f1[1] ?: $na) . '</td>';
            $h .= '<td width="22%" style="padding:3px 10px;font-size:7pt;color:#777777;vertical-align:bottom;">' . $e($f2[0]) . '</td>';
            $h .= '<td width="28%" style="padding:3px 10px;font-size:9pt;font-weight:bold;color:#002850;">' . $e($f2[1] ?: ($f2[0] ? $na : '')) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</table>';

        // ── Earnings / Deductions: 4 columns, coloured left accent on each row ──
        $earnings = [
            ['Basic Salary',      $r['basic_salary']],
            ['Housing Allowance', $r['housing']],
            ['Transportation',    $r['transportation']],
            ['Other Allowances',  $r['other_allowances']],
            ['HMO (Employer)',    $r['hmo_employer']],
            ['P.S.F. Arrears',   $r['psf_arrears']],
        ];
        $deductions = [
            ['HMO (Employee)',     $r['hmo_employee']],
            ['Monthly Pension',    $r['monthly_pension']],
            ['Monthly Tax (PAYE)', $r['monthly_tax']],
            ['Additional HMO',     $r['additional_hmo']],
            ['Loan Repayment',     $r['loan_repayment']],
            ['Caution Fee',        $r['caution_fee']],
        ];
        $maxRows = max(count($earnings), count($deductions));

        $h .= '<table width="100%" style="margin-top:4px;border-top:2px solid #002850;">';
        $h .= '<tr>
  <th colspan="2" width="50%" style="background:#002850;color:#ffffff;font-size:8pt;padding:5px 10px;text-align:left;">EARNINGS</th>
  <th colspan="2" width="50%" style="background:#002850;color:#ffffff;font-size:8pt;padding:5px 10px;text-align:left;border-left:2px solid #ffffff;">DEDUCTIONS</th>
</tr>';
        for ($i = 0; $i < $maxRows; $i++) {
            $bg     = ($i % 2 === 0) ? '#f7faf2' : '#ffffff';
            $accent = ($i % 2 === 0) ? '#64A014' : '#b8d880';
            $h .= '<tr style="background:' . $bg . ';">';
            if (isset($earnings[$i])) {
                $h .= '<td width="32%" style="padding:4px 8px;font-size:8.5pt;border-left:3px solid ' . $accent . ';">' . $e($earnings[$i][0]) . '</td>';
                $h .= '<td width="18%" style="padding:4px 10px;font-size:8.5pt;font-weight:bold;text-align:right;">&#8358;' . self::fmt((float)$earnings[$i][1]) . '</td>';
            } else {
                $h .= '<td width="32%" style="padding:4px 8px;border-left:3px solid ' . $accent . ';">&nbsp;</td><td width="18%">&nbsp;</td>';
            }
            if (isset($deductions[$i])) {
                $h .= '<td width="32%" style="padding:4px 8px;font-size:8.5pt;border-left:3px solid ' . $accent . ';">' . $e($deductions[$i][0]) . '</td>';
                $h .= '<td width="18%" style="padding:4px 10px;font-size:8.5pt;font-weight:bold;text-align:right;">&#8358;' . self::fmt((float)$deductions[$i][1]) . '</td>';
            } else {
                $h .= '<td width="32%" style="padding:4px 8px;border-left:3px solid ' . $accent . ';">&nbsp;</td><td width="18%">&nbsp;</td>';
            }
            $h .= '</tr>';
        }
        $h .= '<tr style="background:#eef4e0;">
  <td style="padding:5px 10px;font-size:8.5pt;font-weight:bold;border-top:2px solid #64A014;border-left:3px solid #64A014;">TOTAL EARNINGS</td>
  <td style="padding:5px 10px;font-size:8.5pt;font-weight:bold;text-align:right;border-top:2px solid #64A014;">&#8358;' . self::fmt((float)$r['total_earnings']) . '</td>
  <td style="padding:5px 10px;font-size:8.5pt;font-weight:bold;border-top:2px solid #64A014;border-left:3px solid #64A014;">TOTAL DEDUCTIONS</td>
  <td style="padding:5px 10px;font-size:8.5pt;font-weight:bold;text-align:right;border-top:2px solid #64A014;">&#8358;' . self::fmt((float)$r['total_deductions']) . '</td>
</tr>';
        $h .= '</table>';

        // ── Net pay: left label | right ₦ amount (green ₦, white number) ────────
        $h .= '<table width="100%" style="background:#002850;margin-top:2px;">
<tr>
  <td style="padding:10px 14px;vertical-align:middle;">
    <p style="color:#a8c8e8;font-size:7.5pt;text-transform:uppercase;margin:0;">Net Pay for ' . $e($r['pay_period'] ?? '') . '</p>
  </td>
  <td style="padding:10px 14px;text-align:right;vertical-align:middle;">
    <span style="color:#64A014;font-size:17pt;font-weight:bold;">&#8358;</span><span style="color:#ffffff;font-size:17pt;font-weight:bold;">' . self::fmt((float)$r['net_pay']) . '</span>
  </td>
</tr>
</table>';

        // ── Signatory ──────────────────────────────────────────────────────────
        $h .= '<table width="100%" style="margin-top:10px;">
<tr>
  <td width="55%">&nbsp;</td>
  <td width="45%" style="text-align:center;border-top:1px solid #aaaaaa;padding-top:4px;font-size:8pt;color:#555555;">
    ' . $e($signatory) . '<br>' . $e(self::SIG_TITLE) . '
  </td>
</tr>
</table>';

        // ── Footer: navy bar with one-line confidentiality notice ───────────────
        $h .= '<table width="100%" style="background:#002850;margin-top:8px;">
<tr><td style="padding:5px 12px;text-align:center;">
  <p style="color:#a8c8e8;font-size:6.5pt;margin:0;">CONFIDENTIAL — For ' . $e($r['employee_name']) . ' only &nbsp;|&nbsp; ' . self::COMPANY . ' &nbsp;|&nbsp; ' . self::NDPC . ' &nbsp;|&nbsp; NDPA 2023</p>
</td></tr>
</table>';

        // Close outer border wrapper
        $h .= '</td></tr></table>';
        $h .= '</body></html>';
        return $h;
    }

    private static function fmt(float $v): string {
        return number_format($v, 2);
    }

    // ── HTML payslip ──────────────────────────────────────────────────────────

    public static function html(array $row): array {
        $r  = $row;
        $fn = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $na = 'N/A';

        $logo       = self::logoDataUri();
        $logoTag    = $logo ? '<img src="' . $logo . '" alt="HRI" style="height:44px;">' : '';
        $signatory  = self::signatory($r);
        $clientName = !empty($r['client_name']) ? trim($r['client_name']) : '';
        $titleLine  = 'P A Y S L I P &nbsp;&mdash;&nbsp; ' . $fn($r['pay_period']);
        if ($clientName) $titleLine .= ' &nbsp;&mdash;&nbsp; ' . $fn($clientName);

        $h = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payslip — ' . $fn($r['employee_name']) . ' — ' . $fn($r['pay_period']) . '</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#1a1a1a;background:#f5f5f5;}
.wrap{max-width:720px;margin:20px auto;background:#fff;border:1px solid #ccc;padding:0;}
.logo-hd{display:flex;align-items:center;gap:16px;padding:14px 20px;background:#002850;color:#fff;}
.logo-hd .co{font-size:16pt;font-weight:700;line-height:1.2;}
.logo-hd .sub{font-size:8.5pt;opacity:.8;margin-top:3px;}
.slip-title{background:#64A014;color:#fff;text-align:center;font-size:13pt;font-weight:700;letter-spacing:.1em;padding:8px;}
.emp-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;padding:10px 20px;border-bottom:1px solid #ddd;}
.emp-item{padding:4px 6px;}
.emp-item .lbl{font-size:7.5pt;color:#666;text-transform:uppercase;letter-spacing:.05em;}
.emp-item .val{font-size:9.5pt;font-weight:600;color:#002850;margin-top:1px;}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:0;}
.col-hd{background:#002850;color:#fff;font-size:8.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:6px 12px;}
table.ptbl{width:100%;border-collapse:collapse;}
table.ptbl td{padding:4px 12px;font-size:9pt;border-bottom:1px solid #f0f0f0;}
table.ptbl td.lbl{color:#444;}
table.ptbl td.amt{text-align:right;font-weight:600;}
table.ptbl tr.total td{background:#f0f4e8;font-weight:700;border-top:2px solid #64A014;}
.net-row{background:#002850;color:#fff;text-align:center;padding:12px 20px;font-size:14pt;font-weight:700;letter-spacing:.04em;}
.sig-row{display:flex;align-items:flex-end;justify-content:flex-end;padding:14px 20px;gap:30px;border-top:1px solid #ddd;}
.sig-box{text-align:center;}
.sig-line{border-top:1px solid #555;width:200px;padding-top:4px;font-size:8pt;color:#555;}
.footer{background:#f8f8f8;padding:8px 20px;font-size:7.5pt;color:#888;text-align:center;border-top:1px solid #e0e0e0;}
@media print{body{background:#fff;}.wrap{border:none;max-width:100%;margin:0;}}
</style></head><body>
<div class="wrap">
<div class="logo-hd">
  ' . $logoTag . '
  <div>
    <div class="co">' . self::COMPANY . '</div>
    <div class="sub">' . self::ADDRESS . ' &nbsp;|&nbsp; ' . self::RC . '</div>
  </div>
</div>
<div class="slip-title">' . $titleLine . '</div>
<div class="emp-grid">
  <div class="emp-item"><div class="lbl">Employee Name</div><div class="val">' . $fn($r['employee_name'] ?: $na) . '</div></div>
  <div class="emp-item"><div class="lbl">Employee ID</div><div class="val">' . $fn($r['employee_id'] ?: $na) . '</div></div>
  <div class="emp-item"><div class="lbl">Designation / Role</div><div class="val">' . $fn($r['designation'] ?: $na) . '</div></div>
  <div class="emp-item"><div class="lbl">Location</div><div class="val">' . $fn($r['location'] ?: $na) . '</div></div>
  <div class="emp-item"><div class="lbl">PFA</div><div class="val">' . $fn($r['pfa'] ?: $na) . '</div></div>
  <div class="emp-item"><div class="lbl">Pension PIN</div><div class="val">' . $fn($r['pension_pin'] ?: $na) . '</div></div>
  <div class="emp-item"><div class="lbl">Pay Day</div><div class="val">' . $fn($r['pay_day'] ?: $na) . '</div></div>
  <div class="emp-item"><div class="lbl">Tax ID</div><div class="val">' . $fn($r['tax_id'] ?: $na) . '</div></div>
  <div class="emp-item"><div class="lbl">Days in Month</div><div class="val">' . $fn($r['days_in_month'] ?: '—') . '</div></div>
  <div class="emp-item"><div class="lbl">Days Worked</div><div class="val">' . $fn($r['days_worked'] ?: '—') . '</div></div>
</div>
<div class="cols">
  <div>
    <div class="col-hd">Earnings</div>
    <table class="ptbl">
      <tr><td class="lbl">Basic Salary</td><td class="amt">&#8358;' . self::fmt((float)$r['basic_salary']) . '</td></tr>
      <tr><td class="lbl">Housing Allowance</td><td class="amt">&#8358;' . self::fmt((float)$r['housing']) . '</td></tr>
      <tr><td class="lbl">Transportation</td><td class="amt">&#8358;' . self::fmt((float)$r['transportation']) . '</td></tr>
      <tr><td class="lbl">Other Allowances</td><td class="amt">&#8358;' . self::fmt((float)$r['other_allowances']) . '</td></tr>
      <tr><td class="lbl">HMO (Employer)</td><td class="amt">&#8358;' . self::fmt((float)$r['hmo_employer']) . '</td></tr>
      <tr><td class="lbl">P.S.F. Arrears</td><td class="amt">&#8358;' . self::fmt((float)$r['psf_arrears']) . '</td></tr>
      <tr class="total"><td class="lbl">TOTAL EARNINGS</td><td class="amt">&#8358;' . self::fmt((float)$r['total_earnings']) . '</td></tr>
    </table>
  </div>
  <div style="border-left:2px solid #002850;">
    <div class="col-hd">Deductions</div>
    <table class="ptbl">
      <tr><td class="lbl">HMO (Employee)</td><td class="amt">&#8358;' . self::fmt((float)$r['hmo_employee']) . '</td></tr>
      <tr><td class="lbl">Monthly Pension</td><td class="amt">&#8358;' . self::fmt((float)$r['monthly_pension']) . '</td></tr>
      <tr><td class="lbl">Monthly Tax (PAYE)</td><td class="amt">&#8358;' . self::fmt((float)$r['monthly_tax']) . '</td></tr>
      <tr><td class="lbl">Additional HMO</td><td class="amt">&#8358;' . self::fmt((float)$r['additional_hmo']) . '</td></tr>
      <tr><td class="lbl">Loan Repayment</td><td class="amt">&#8358;' . self::fmt((float)$r['loan_repayment']) . '</td></tr>
      <tr><td class="lbl">Caution Fee</td><td class="amt">&#8358;' . self::fmt((float)$r['caution_fee']) . '</td></tr>
      <tr class="total"><td class="lbl">TOTAL DEDUCTIONS</td><td class="amt">&#8358;' . self::fmt((float)$r['total_deductions']) . '</td></tr>
    </table>
  </div>
</div>
<div class="net-row">NET PAY &nbsp;&#8358;' . self::fmt((float)$r['net_pay']) . '</div>
<div class="sig-row">
  <div class="sig-box">
    <div class="sig-line">' . $fn($signatory) . '<br>' . $fn(self::SIG_TITLE) . '</div>
  </div>
</div>
<div class="footer">
  CONFIDENTIAL — This payslip is intended solely for ' . $fn($r['employee_name']) . ' and must not be shared with any third party.<br>
  ' . self::COMPANY . ' &nbsp;|&nbsp; ' . self::NDPC . ' &nbsp;|&nbsp; Data protected under NDPA 2023.
</div>
</div></body></html>';

        $empName  = preg_replace('/[^a-zA-Z0-9 \-\.]/i', '', $r['employee_name'] ?: 'Employee');
        $period   = preg_replace('/[^a-zA-Z0-9 \-\.]/i', '', $r['pay_period'] ?: 'Payslip');
        return [
            'content'  => $h,
            'mime'     => 'text/html',
            'filename' => "Payslip - {$period} - {$empName}.html",
        ];
    }

    // ── FPDF payslip (requires lib/fpdf.php) ──────────────────────────────────

    private static function pdf(array $row): array {
        $r          = $row;
        $signatory  = self::signatory($r);
        $clientName = !empty($r['client_name']) ? trim($r['client_name']) : '';
        $titleText  = 'P  A  Y  S  L  I  P    -    ' . strtoupper($r['pay_period'] ?? '');
        if ($clientName) $titleText .= '    -    ' . strtoupper($clientName);

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetCompression(true);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->SetMargins(15, 10, 15);

        $navy  = [0, 40, 80];
        $green = [100, 160, 20];

        // Header bar
        $pdf->SetFillColor(...$navy);
        $pdf->Rect(0, 0, 210, 28, 'F');

        // Logo — resize to ≤300px wide JPEG via GD to keep file small
        $logoPath   = __DIR__ . '/../hri-logo.png';
        $logoLoaded = false;
        $tmpLogo    = null;

        if (file_exists($logoPath)) {
            $embedPath = $logoPath; // default: embed original

            // GD resize + JPEG recompress (drops transparency, shrinks file)
            if (function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
                $src = @imagecreatefrompng($logoPath);
                if ($src) {
                    $ow = imagesx($src); $oh = imagesy($src);
                    $maxW = 300;
                    $nw   = min($ow, $maxW);
                    $nh   = (int)round($oh * ($nw / $ow));
                    $dst  = imagecreatetruecolor($nw, $nh);
                    $bg   = imagecolorallocate($dst, 255, 255, 255);
                    imagefill($dst, 0, 0, $bg);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
                    imagedestroy($src);
                    $tmpLogo = tempnam(sys_get_temp_dir(), 'hrilogo') . '.jpg';
                    imagejpeg($dst, $tmpLogo, 82);
                    imagedestroy($dst);
                    $embedPath = $tmpLogo;
                }
            }

            try {
                $pdf->Image($embedPath, 15, 4, 0, 20);
                $logoLoaded = true;
            } catch (Exception $imgEx) {
                // GD JPEG failed or not available — try original PNG
                if ($embedPath !== $logoPath) {
                    try { $pdf->Image($logoPath, 15, 4, 0, 20); $logoLoaded = true; } catch (Exception $imgEx2) {}
                }
            }

            if ($tmpLogo && file_exists($tmpLogo)) @unlink($tmpLogo);
        }

        if ($logoLoaded) {
            $pdf->SetXY(45, 7);
        } else {
            $pdf->SetXY(15, 7);
        }

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 7, self::COMPANY, 0, 1, 'L');
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetX(file_exists($logoPath) ? 45 : 15);
        $pdf->Cell(0, 5, self::ADDRESS . '  |  ' . self::RC, 0, 1, 'L');

        // Title band
        $pdf->SetFillColor(...$green);
        $pdf->Rect(0, 28, 210, 10, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY(0, 30);
        $pdf->Cell(210, 6, $titleText, 0, 0, 'C');

        // Employee info grid
        $pdf->SetTextColor(30, 30, 30);
        $y     = 43;
        $colW  = 90;
        $fields = [
            ['Employee Name', $r['employee_name']],  ['Employee ID',  $r['employee_id']],
            ['Designation',   $r['designation']],    ['Location',     $r['location']],
            ['PFA',           $r['pfa']],            ['Pension PIN',  $r['pension_pin']],
            ['Pay Day',       $r['pay_day']],        ['Tax ID',       $r['tax_id']],
            ['Days in Month', $r['days_in_month']],  ['Days Worked',  $r['days_worked']],
        ];
        foreach ($fields as $i => $pair) {
            $lbl = $pair[0]; $val = $pair[1];
            $col = ($i % 2);
            $rowIdx = (int)($i / 2);
            $x  = 15 + $col * $colW;
            $yi = $y + $rowIdx * 9;
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($x, $yi);
            $pdf->Cell($colW - 5, 4, strtoupper($lbl), 0, 0, 'L');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(0, 40, 80);
            $pdf->SetXY($x, $yi + 4);
            $pdf->Cell($colW - 5, 5, (string)($val ?: 'N/A'), 0, 0, 'L');
        }

        // Earnings / Deductions
        $tableY = $y + ceil(count($fields) / 2) * 9 + 4;

        $pdf->SetFillColor(...$navy);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(15, $tableY);
        $pdf->Cell(90, 8, 'EARNINGS', 1, 0, 'C', true);
        $pdf->SetXY(105, $tableY);
        $pdf->Cell(90, 8, 'DEDUCTIONS', 1, 1, 'C', true);

        $tableY += 8;
        $pdf->SetTextColor(30, 30, 30);

        $earnings   = [
            ['Basic Salary',      $r['basic_salary']],
            ['Housing Allowance', $r['housing']],
            ['Transportation',    $r['transportation']],
            ['Other Allowances',  $r['other_allowances']],
            ['HMO (Employer)',    $r['hmo_employer']],
            ['P.S.F. Arrears',   $r['psf_arrears']],
        ];
        $deductions = [
            ['HMO (Employee)',     $r['hmo_employee']],
            ['Monthly Pension',    $r['monthly_pension']],
            ['Monthly Tax (PAYE)', $r['monthly_tax']],
            ['Additional HMO',     $r['additional_hmo']],
            ['Loan Repayment',     $r['loan_repayment']],
            ['Caution Fee',        $r['caution_fee']],
        ];

        $maxRows = max(count($earnings), count($deductions));
        for ($i = 0; $i < $maxRows; $i++) {
            $pdf->SetFont('Arial', '', 9);
            $yi = $tableY + $i * 7;
            $bg = ($i % 2 === 0);
            $pdf->SetFillColor($bg ? 248 : 255, $bg ? 250 : 255, $bg ? 245 : 255);
            if (isset($earnings[$i])) {
                $pdf->SetXY(15, $yi);
                $pdf->Cell(55, 7, $earnings[$i][0], 'LR', 0, 'L', $bg);
                $pdf->Cell(35, 7, 'N' . self::fmt((float)$earnings[$i][1]), 'R', 0, 'R', $bg);
            } else {
                $pdf->SetXY(15, $yi); $pdf->Cell(90, 7, '', 'LR', 0, 'L', $bg);
            }
            if (isset($deductions[$i])) {
                $pdf->SetXY(105, $yi);
                $pdf->Cell(55, 7, $deductions[$i][0], 'LR', 0, 'L', $bg);
                $pdf->Cell(35, 7, 'N' . self::fmt((float)$deductions[$i][1]), 'R', 0, 'R', $bg);
            } else {
                $pdf->SetXY(105, $yi); $pdf->Cell(90, 7, '', 'LR', 0, 'L', $bg);
            }
        }

        $totY = $tableY + $maxRows * 7;
        $pdf->SetFillColor(240, 244, 232);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(15, $totY);
        $pdf->Cell(55, 8, 'TOTAL EARNINGS', 'LBR', 0, 'L', true);
        $pdf->Cell(35, 8, 'N' . self::fmt((float)$r['total_earnings']), 'BR', 0, 'R', true);
        $pdf->SetXY(105, $totY);
        $pdf->Cell(55, 8, 'TOTAL DEDUCTIONS', 'LBR', 0, 'L', true);
        $pdf->Cell(35, 8, 'N' . self::fmt((float)$r['total_deductions']), 'BR', 0, 'R', true);

        $netY = $totY + 12;
        $pdf->SetFillColor(...$navy);
        $pdf->Rect(15, $netY, 180, 12, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->SetXY(15, $netY + 2);
        $pdf->Cell(180, 8, 'NET PAY    N' . self::fmt((float)$r['net_pay']), 0, 0, 'C');

        $sigY = $netY + 24;
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Line(110, $sigY + 8, 195, $sigY + 8);
        $pdf->SetXY(110, $sigY + 9);
        $pdf->Cell(85, 5, $signatory, 0, 1, 'C');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, self::SIG_TITLE, 0, 0, 'C');

        $pdf->SetTextColor(140, 140, 140);
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetXY(15, 275);
        $pdf->Cell(0, 5, 'CONFIDENTIAL — For ' . ($r['employee_name'] ?? '') . ' only. ' . self::COMPANY . ' | ' . self::NDPC . ' | NDPA 2023', 0, 0, 'C');

        $content = $pdf->Output('S');
        $empName = preg_replace('/[^a-zA-Z0-9 \-\.]/i', '', $r['employee_name'] ?: 'Employee');
        $period  = preg_replace('/[^a-zA-Z0-9 \-\.]/i', '', $r['pay_period'] ?: 'Payslip');
        return [
            'content'  => $content,
            'mime'     => 'application/pdf',
            'filename' => "Payslip - {$period} - {$empName}.pdf",
        ];
    }

    // ── Shared email body builders (used by cron + test send) ─────────────────

    public static function buildEmailHtml(array $item, string $logoUrl = ''): string {
        $name       = $item['employee_name'] ?? '';
        $period     = $item['pay_period'] ?? date('F Y');
        $netFmt     = '&#8358;' . number_format((float)($item['net_pay'] ?? 0), 2);
        $sender     = self::signatory($item);
        $clientName = !empty($item['client_name']) ? trim($item['client_name']) : '';
        $clientLine = $clientName ? ' for <strong>' . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . '</strong>' : '';
        $esc        = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        if (!$logoUrl && defined('APP_URL')) $logoUrl = APP_URL . '/hri-logo.png';

        return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;}
.wrap{max-width:580px;margin:24px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);}
.hd{background:#002850;padding:20px 28px;text-align:left;}
.hd img{height:48px;vertical-align:middle;}
.body{padding:32px 28px;}
.body p{margin:0 0 16px;line-height:1.6;}
.net-box{background:#002850;color:#ffffff;border-radius:6px;padding:14px 20px;margin:20px 0;text-align:center;font-size:18px;font-weight:700;letter-spacing:.03em;}
.notice{background:#fffbeb;border:1px solid #fcd34d;border-radius:5px;padding:10px 14px;font-size:12px;color:#78350f;margin-top:20px;}
.sig{margin-top:28px;border-top:1px solid #e5e7eb;padding-top:18px;font-size:13px;color:#444;}
.sig strong{color:#002850;}
.ft{background:#f8f8f8;padding:14px 28px;font-size:11px;color:#888;border-top:1px solid #e5e7eb;text-align:center;line-height:1.6;}
</style></head>
<body>
<div class="wrap">
  <div class="hd"><img src="' . $logoUrl . '" alt="HR INDEXX LIMITED"></div>
  <div class="body">
    <p>Dear <strong>' . $esc($name) . '</strong>,</p>
    <p>Please see attached your payslip for <strong>' . $esc($period) . '</strong>' . $clientLine . '.</p>
    <p>Your net pay for this period is:</p>
    <div class="net-box">' . $netFmt . '</div>
    <p>Kindly review the attached payslip document for a full breakdown of your earnings and deductions.</p>
    <div class="notice">
      &#128274; <strong>Confidential:</strong> This payslip is intended solely for you. Please do not share it with any third party.
    </div>
    <div class="sig">
      <p>Regards,<br>
      <strong>' . $esc($sender) . '</strong><br>
      Human Resources &mdash; ' . self::COMPANY . '<br>
      ' . self::ADDRESS . '</p>
    </div>
  </div>
  <div class="ft">
    ' . self::COMPANY . ' &nbsp;|&nbsp; ' . self::RC . ' &nbsp;|&nbsp; ' . self::NDPC . '<br>
    Data protected under the Nigeria Data Protection Act (NDPA) 2023.
  </div>
</div>
</body></html>';
    }

    public static function buildEmailPlain(array $item): string {
        $name       = $item['employee_name'] ?? '';
        $period     = $item['pay_period'] ?? date('F Y');
        $clientName = !empty($item['client_name']) ? trim($item['client_name']) : '';
        $sender     = self::signatory($item);
        $net        = 'NGN ' . number_format((float)($item['net_pay'] ?? 0), 2);
        return "Dear {$name},\r\n\r\n"
            . "Please see attached your payslip for {$period}"
            . ($clientName ? " ({$clientName})" : '') . ".\r\n\r\n"
            . "Net Pay: {$net}\r\n\r\n"
            . "This payslip is confidential. Do not share it with others.\r\n\r\n"
            . "Regards,\r\n{$sender}\r\nHuman Resources — " . self::COMPANY . "\r\n" . self::ADDRESS;
    }
}
