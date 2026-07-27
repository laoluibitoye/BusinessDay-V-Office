<?php
// api/mail/smart-reply.php — AI Smart Replies using Google Gemini (Free)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');
$user = Auth::require();

$emailBody    = trim($_POST['body']    ?? '');
$emailSubject = trim($_POST['subject'] ?? '');
$emailFrom    = trim($_POST['from']    ?? '');

if (!$emailBody && !$emailSubject) {
    echo json_encode(['ok'=>false,'error'=>'No email content provided']);
    exit;
}

$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (!$apiKey || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
    echo json_encode(['ok'=>false,'error'=>'AI Smart Reply unavailable. Contact your IT Admin.']);
    exit;
}

// Clean email body
$clean = strip_tags($emailBody);
$clean = preg_replace('/\s+/', ' ', $clean);
$clean = trim(substr($clean, 0, 2000));

$prompt = "You are an email assistant for Olanrewaju Oloritun (IT Admin) at HR Indexx Limited, Lagos. "
        . "Generate exactly 3 short, professional email reply suggestions for the email below. "
        . "Each reply should be different in tone: one formal, one friendly, one brief. "
        . "Format as JSON array with keys: 'label' (e.g. 'Formal', 'Friendly', 'Brief') and 'body' (the reply text). "
        . "Sign each reply as: Regards,\nOlanrewaju Oloritun\nIT Admin | HR Indexx Limited\n\n"
        . "Email From: $emailFrom\n"
        . "Subject: $emailSubject\n"
        . "Body: $clean\n\n"
        . "Respond ONLY with valid JSON array, no markdown, no explanation.";

$payload = json_encode([
    'contents' => [[
        'parts' => [['text' => $prompt]]
    ]],
    'generationConfig' => [
        'temperature'     => 0.7,
        'maxOutputTokens' => 600,
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

if ($curlErr || $httpCode !== 200) {
    echo json_encode(['ok'=>false,'error'=>'AI service unavailable. Try again.']);
    exit;
}

$data = json_decode($response, true);
$text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');

if (!$text) {
    echo json_encode(['ok'=>false,'error'=>'AI Smart Reply unavailable. Contact your IT Admin.']);
    exit;
}

// Parse JSON response from Gemini
$text = preg_replace('/^```json\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', $text);
$replies = json_decode($text, true);

if (!$replies || !is_array($replies)) {
    // Fallback: return as single reply
    $replies = [
        ['label'=>'Reply',   'body'=>$text],
        ['label'=>'Formal',  'body'=>"Dear " . ($emailFrom ?: 'Sir/Ma') . ",\n\nThank you for your email. I have noted the contents and will revert accordingly.\n\nRegards,\nOlanrewaju Oloritun\nIT Admin | HR Indexx Limited"],
        ['label'=>'Brief',   'body'=>"Noted, thank you.\n\nRegards,\nOlanrewaju Oloritun"],
    ];
}

echo json_encode(['ok'=>true, 'replies'=>array_slice($replies, 0, 3)]);
