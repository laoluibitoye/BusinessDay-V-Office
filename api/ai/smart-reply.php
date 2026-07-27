<?php
// api/ai/smart-reply.php — Smart Replies (Google Gemini)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');
$user = Auth::require();

$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (!$apiKey || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
    echo json_encode(['ok'=>false,'replies'=>[],'error'=>'AI unavailable. Contact IT Admin.']);
    exit;
}

$body    = strip_tags(trim($_POST['body']    ?? ''));
$subject = trim($_POST['subject'] ?? '');
$from    = trim($_POST['from']    ?? '');
$body    = preg_replace('/\s+/', ' ', substr($body, 0, 2000));

$prompt = "Generate 3 short professional email reply suggestions for this email.\n"
        . "Return ONLY a JSON array with objects having 'label' and 'body' keys.\n"
        . "Labels: 'Formal', 'Friendly', 'Brief'\n"
        . "Sign each: Regards,\\nOlanrewaju Oloritun\\nIT Admin | HR Indexx Limited\n\n"
        . "From: $from\nSubject: $subject\nBody: $body\n\n"
        . "Return only valid JSON, no markdown.";

$payload = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 600, 'thinkingConfig' => ['thinkingBudget' => 0]]
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

$data = json_decode($response, true);
$text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
$text = preg_replace('/^```json\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', trim($text));
$replies = json_decode($text, true);

if (!is_array($replies)) {
    $replies = [
        ['label'=>'Formal',   'body'=>"Dear $from,\n\nThank you for your email. I have noted the contents and will respond accordingly.\n\nRegards,\nOlanrewaju Oloritun\nIT Admin | HR Indexx Limited"],
        ['label'=>'Friendly', 'body'=>"Hi,\n\nThanks for reaching out. I'll get back to you shortly.\n\nRegards,\nOlanrewaju Oloritun"],
        ['label'=>'Brief',    'body'=>"Noted, thank you.\n\nRegards,\nOlanrewaju Oloritun"],
    ];
}

echo json_encode(['ok'=>true, 'replies'=>array_slice($replies, 0, 3)]);
