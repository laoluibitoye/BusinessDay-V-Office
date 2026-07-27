<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';

// Email-link approval has been removed for security.
// Approvers must log in to HRI Mail to action leave requests.
header('Location: ' . APP_URL . '/login.php?msg=' . urlencode('Please log in to approve leave requests. Go to Leave Approvals from the menu.'));
exit;
