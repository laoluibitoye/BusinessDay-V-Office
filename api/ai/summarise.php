<?php
// api/ai/summarise.php — AI Email Summary (Google Gemini)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/ImapHelper.php';

header('Content-Type: application/json');
$user = Auth::require();
$mp   = $_SESSION['mail_pass'] ?? '';

// Check Gemini API key
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (!$apiKey || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
    echo json_encode(['summary' => 'AI Summary unavailable. Contact your IT Admin.']);
    exit;
}

// Accept body directly from POST (preferred) or fall back to IMAP
$body = '';
if (!empty($_POST['body'])) {
    // Body passed directly from the page - fast path, no IMAP needed
    $body = trim(substr(strip_tags($_POST['body']), 0, 3000));
} else {
    // Fall back to IMAP fetch
    $uid    = (int)($_POST['uid'] ?? $_GET['uid'] ?? 0);
    $folder = $_POST['folder'] ?? $_GET['f'] ?? 'INBOX';
    if ($uid && $mp) {
        try {
            $mbox  = ImapHelper::open($user['email'], $mp, $folder);
            if ($mbox) {
                $msgNo = imap_msgno($mbox, $uid);
                $struct = imap_fetchstructure($mbox, $msgNo);
                // Prefer HTML body, fall back to plain
                if (!empty($struct->parts)) {
                    foreach ($struct->parts as $i => $part) {
                        $raw = imap_fetchbody($mbox, $msgNo, ($i+1));
                        $enc = $part->encoding ?? 0;
                        $text = $enc===3?base64_decode($raw):($enc===4?quoted_printable_decode($raw):$raw);
                        if ($part->subtype === 'HTML' && !$body) $body = strip_tags($text);
                        elseif ($part->subtype === 'PLAIN' && !$body) $body = $text;
                    }
                } else {
                    $raw = imap_body($mbox, $msgNo);
                    $enc = $struct->encoding ?? 0;
                    $body = $enc===3?base64_decode($raw):($enc===4?quoted_printable_decode($raw):$raw);
                    $body = strip_tags($body);
                }
                imap_close($mbox);
                $body = trim(substr(preg_replace('/\s+/',' ',imap_utf8($body)),0,3000));
            }
        } catch (Exception $e) {}
    }
}

if (!$body) {
    echo json_encode(['summary' => 'Email body is empty or could not be read.']);
    exit;
}

// Call Gemini
$prompt = "Summarise this email in 3-4 concise bullet points for an HR professional. "
        . "Focus on: main topic, action required, any deadlines or dates.\n\nEmail:\n\n" . $body;

$payload = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 300, 'thinkingConfig' => ['thinkingBudget' => 0]]
]);

$ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 20,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data    = json_decode($response, true);
$summary = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');

if (!$summary || $httpCode !== 200) {
    echo json_encode(['summary' => 'AI Summary unavailable. Contact your IT Admin.']);
    exit;
}

echo json_encode(['summary' => $summary]);
