<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();

$dashboards = [
    'md'              => __DIR__ . '/dashboard/management.php',
    'bdm'             => __DIR__ . '/dashboard/management.php',
    'head_it'         => __DIR__ . '/dashboard/superadmin.php',
    'head_outsourcing'=> __DIR__ . '/dashboard/hod.php',
    'head_compliance' => __DIR__ . '/dashboard/hod.php',
    'head_accounts'   => __DIR__ . '/dashboard/hod.php',
    'hr'              => __DIR__ . '/dashboard/hr.php',
    'head_training'   => __DIR__ . '/dashboard/hod.php',
    'head_cso'        => __DIR__ . '/dashboard/hod.php',
    'training_manager'=> __DIR__ . '/dashboard/manager.php',
    'cs_manager'      => __DIR__ . '/dashboard/manager.php',
    'it_admin'        => __DIR__ . '/dashboard/superadmin.php',
    'cs_officer'      => __DIR__ . '/dashboard/staff.php',
    'accountant'      => __DIR__ . '/dashboard/staff.php',
    'front_desk'      => __DIR__ . '/dashboard/staff.php',
    'cs_ops'          => __DIR__ . '/dashboard/staff.php',
    'cleaner'         => __DIR__ . '/dashboard/staff.php',
];

$dash = $dashboards[$user['role']] ?? __DIR__ . '/dashboard/staff.php';
require $dash;
