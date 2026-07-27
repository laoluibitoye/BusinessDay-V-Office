<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// compose.php — redirects to inbox with compose panel open
// The compose panel is now built into layout_shell.php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/lib/Auth.php';
Auth::require();

// Build pre-fill params to pass via URL hash/query
$to      = urlencode($_GET['to']      ?? '');
$subject = urlencode($_GET['subject'] ?? '');
$body    = urlencode($_GET['body']    ?? '');
$reply   = (int)($_GET['reply']       ?? 0);
$forward = (int)($_GET['forward']     ?? 0);
$folder  = urlencode($_GET['f']       ?? 'INBOX');

// Redirect to mail.php (or dashboard) with compose flag
$base = APP_URL . '/mail.php';
if ($reply)   $base .= '?f=' . $folder . '&uid=' . $reply;
elseif ($forward) $base .= '?f=' . $folder . '&uid=' . $forward;

header('Location: ' . $base . '#compose');
exit;
