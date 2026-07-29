<?php
// api/mail/summary.php — AI Email Summary using Google Gemini (Free)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/ImapHelper.php';

header('Content-Type: application/json');
$user = Auth::require();
$mp   = Auth::mailPass();

$uid    = Auth::sanitiseInt($_GET['uid']    ?? 0);
$folder = Auth::sanitiseString($_GET['folder'] ?? 'INBOX', 60);

if (!$uid || !$mp) {
    echo json_encode(['ok'=>false,'error'=>'Missing parameters']);
    exit;
}

// Check API key
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (!$apiKey || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
    echo json_encode(['ok'=>false,'error'=>'AI Summary unavailable. Contact your IT Admin.']);
    exit;
}

// Fetch email body from IMAP
try {
    $mbox = ImapHelper::open($user['email'], $mp, $folder);
    if (!$mbox) { echo json_encode(['ok'=>false,'error'=>'Cannot connect to mailbox']); exit; }

    $msgNo   = imap_msgno($mbox, $uid);
    $struct  = imap_fetchstructure($mbox, $msgNo);
    $body    = '';

    // Try to get plain text body first, then HTML
    if ($struct->type === 0) {
        $raw = imap_fetchbody($mbox, $msgNo, '1');
        $enc = $struct->encoding ?? 0;
        $body = $enc === 3 ? base64_decode($raw) : ($enc === 4 ? quoted_printable_decode($raw) : $raw);
    } else {
        // Multipart - look for text/plain then text/html
        if (!empty($struct->parts)) {
            foreach ($struct->parts as $i => $part) {
                $raw = imap_fetchbody($mbox, $msgNo, ($i + 1));
                $enc = $part->encoding ?? 0;
                $text = $enc === 3 ? base64_decode($raw) : ($enc === 4 ? quoted_printable_decode($raw) : $raw);
                if ($part->subtype === 'PLAIN' && !$body) $body = $text;
                if ($part->subtype === 'HTML'  && !$body) $body = strip_tags($text);
            }
        }
        if (!$body) {
            $raw  = imap_fetchbody($mbox, $msgNo, '1');
            $body = strip_tags($raw);
        }
    }
    imap_close($mbox);

    // Clean up body
    $body = imap_utf8($body);
    $body = strip_tags($body);
    $body = preg_replace('/\s+/', ' ', $body);
    $body = trim(substr($body, 0, 3000)); // Limit to 3000 chars

    if (!$body) {
        echo json_encode(['ok'=>false,'error'=>'Email body is empty or unreadable']);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'Could not read email: ' . $e->getMessage()]);
    exit;
}

// Call Google Gemini API
$prompt = "You are an email assistant for HR Indexx Limited, a Nigerian HR firm. "
        . "Summarise the following email in 3-4 clear bullet points. "
        . "Be concise and professional. Focus on: what it's about, any action required, "
        . "any deadlines or dates mentioned.\n\nEmail content:\n\n" . $body;

$payload = json_encode([
    'contents' => [[
        'parts' => [['text' => $prompt]]
    ]],
    'generationConfig' => [
        'temperature'     => 0.3,
        'maxOutputTokens' => 300,
    ]
]);

$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    Auth::auditLog($user['id'], 'ai_summary_error', "cURL error: $curlErr");
    echo json_encode(['ok'=>false,'error'=>'AI service connection failed. Try again.']);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200 || empty($data['candidates'][0]['content']['parts'][0]['text'])) {
    $errMsg = $data['error']['message'] ?? "HTTP $httpCode";
    Auth::auditLog($user['id'], 'ai_summary_error', "Gemini error: $errMsg");
    echo json_encode(['ok'=>false,'error'=>'AI Summary unavailable. Contact your IT Admin.']);
    exit;
}

$summary = trim($data['candidates'][0]['content']['parts'][0]['text']);
Auth::auditLog($user['id'], 'ai_summary', "UID: $uid Folder: $folder");
Auth::trackUsage($user['id'], 'emails_read', 0); // just track usage

echo json_encode(['ok'=>true, 'summary'=>$summary]);
