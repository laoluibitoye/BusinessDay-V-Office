<?php
// api/ai/compose.php — AI Email Compose (Google Gemini)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');
$user = Auth::require();

$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (!$apiKey || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
    echo json_encode(['error' => 'AI unavailable. Contact your IT Admin.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$prompt_text = trim($input['prompt'] ?? '');
$to          = trim($input['to']     ?? '');
$subject     = trim($input['subject'] ?? '');

if (!$prompt_text) {
    echo json_encode(['error' => 'No prompt provided']);
    exit;
}

$prompt = "Write a professional email for HR Indexx Limited based on these instructions:\n"
        . "$prompt_text\n\n"
        . ($to      ? "To: $to\n"      : '')
        . ($subject ? "Subject: $subject\n" : '')
        . "\nSign it as: Olanrewaju Oloritun, IT Admin, HR Indexx Limited\n"
        . "Write only the email body, no subject line.";

$payload = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 500, 'thinkingConfig' => ['thinkingBudget' => 0]]
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

$data  = json_decode($response, true);
$email = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');

if (!$email || $httpCode !== 200) {
    echo json_encode(['error' => 'AI unavailable. Contact your IT Admin.']);
    exit;
}

echo json_encode(['email' => $email]);
