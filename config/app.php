<?php
// config/app.php — HRI Mail Configuration
// NOTE: Session is handled by php.ini / .htaccess — NOT ini_set()
// LiteSpeed sends headers before PHP runs, so ini_set for session fails
// Session settings are in .user.ini and .htaccess instead

// Timezone — WAT (West Africa Time) = UTC+1, no DST
date_default_timezone_set('Africa/Lagos');

// Start session if not already active (settings come from .user.ini)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Application
define('APP_URL',           'https://mail.hrindexx.com');
define('APP_NAME',          'HRI Mail');
define('SESSION_LIFETIME',   28800);
define('IDLE_TIMEOUT',       7200);
define('MAX_LOGIN_ATTEMPTS', 5);
define('MAX_UPLOAD_BYTES',   25 * 1024 * 1024);
define('MAX_UPLOAD_MB',      MAX_UPLOAD_BYTES / 1024 / 1024);

// API keys and notification credentials — values live in .user.ini as env[] vars,
// never hardcoded in source. Set them there before first use.
require_once '/home/hrindexx/hri-secrets.php';
define('GEMINI_API_KEY',     _GEMINI_API_KEY);
define('NOTIFY_MAIL_USER',   'noreply@hrindexx.com');
define('NOTIFY_MAIL_PASS',   _NOTIFY_MAIL_PASS);
define('APP_ENCRYPTION_KEY', _APP_KEY);

define('ALLOWED_ATTACHMENT_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg','image/png','image/gif','image/webp',
    'text/plain','text/csv','application/zip',
]);

define('ROLES', [
    // ── Management (level 1) ── full system access
    'md'               => ['label'=>'MD',                      'level'=>1,'icon'=>'👑','color'=>'#002850','permissions'=>['all']],
    'bdm'              => ['label'=>'Business Dev Manager',    'level'=>1,'icon'=>'💼','color'=>'#002850','permissions'=>['all']],
    // ── HODs (level 2) ──
    'head_it'          => ['label'=>'Head IT Admin',           'level'=>2,'icon'=>'🛡️','color'=>'#0891b2','permissions'=>['all']],
    'head_outsourcing' => ['label'=>'Head Outsourcing',        'level'=>2,'icon'=>'🤝','color'=>'#7c3aed','permissions'=>['leave_approve','signing','vault','tasks','broadcast','compliance','directory','roster']],
    'head_compliance'  => ['label'=>'Head Compliance',         'level'=>2,'icon'=>'📋','color'=>'#0891b2','permissions'=>['signing','tasks','vault','compliance','roster']],
    'head_accounts'    => ['label'=>'Head Accounts',           'level'=>2,'icon'=>'💰','color'=>'#059669','permissions'=>['vault','tasks','roster']],
    'hr'               => ['label'=>'Human Resources',         'level'=>2,'icon'=>'👥','color'=>'#7c3aed','permissions'=>['leave_approve','signing','vault','tasks','broadcast','compliance','directory','roster']],
    'head_training'    => ['label'=>'Head Training',           'level'=>2,'icon'=>'🎓','color'=>'#d97706','permissions'=>['leave_approve','signing','tasks','vault','directory','roster']],
    'head_cso'         => ['label'=>'Head Client Svc Ops',     'level'=>2,'icon'=>'📞','color'=>'#db2777','permissions'=>['leave_approve','tasks','directory','roster']],
    // ── Managers (level 3) ──
    'training_manager' => ['label'=>'Training Manager',        'level'=>3,'icon'=>'🎓','color'=>'#d97706','permissions'=>['leave_approve','tasks','directory','roster']],
    'cs_manager'       => ['label'=>'Client Service Manager',  'level'=>3,'icon'=>'📞','color'=>'#db2777','permissions'=>['leave_approve','tasks','directory','roster']],
    // ── Staff (level 4) ──
    'cs_officer'       => ['label'=>'Client Service Officer',  'level'=>4,'icon'=>'👤','color'=>'#64748b','permissions'=>['leave_request','tasks','vault','it_request']],
    'accountant'       => ['label'=>'Accountant',              'level'=>4,'icon'=>'💰','color'=>'#059669','permissions'=>['vault','tasks','it_request']],
    'it_admin'         => ['label'=>'IT Admin',                'level'=>2,'icon'=>'🖥️','color'=>'#0891b2','permissions'=>['all']],
    'front_desk'       => ['label'=>'Front Desk',              'level'=>4,'icon'=>'🏢','color'=>'#db2777','permissions'=>['visitors','directory','tasks','it_request']],
    'cs_ops'           => ['label'=>'Client Service Ops',      'level'=>4,'icon'=>'📞','color'=>'#db2777','permissions'=>['tasks','it_request']],
    'cleaner'          => ['label'=>'Cleaner',                 'level'=>4,'icon'=>'🧹','color'=>'#94a3b8','permissions'=>['tasks','it_request']],
]);

// ── IRS: Internal Request System ────────────────────────────────────────────
// Default constants — overridden at runtime by getIrsConfig() which reads irs_config table.
// Use getIrsConfig() in all IRS pages instead of these constants directly.
define('IRS_ACCOUNTS_ROLES_DEFAULT', ['head_accounts', 'accountant']);
define('IRS_MANAGER_ROLES_DEFAULT',  ['md','bdm']);
define('IRS_PETTY_CASH_LIMIT_DEFAULT', 50000);
// Back-compat aliases (used by older code still referencing constants directly)
define('IRS_ACCOUNTS_ROLES',   IRS_ACCOUNTS_ROLES_DEFAULT);
define('IRS_MANAGER_ROLES',    IRS_MANAGER_ROLES_DEFAULT);
define('IRS_PETTY_CASH_LIMIT', IRS_PETTY_CASH_LIMIT_DEFAULT);

/**
 * Returns effective IRS configuration — defaults overridden by irs_config table.
 * Call after getDB() is available (i.e. after config/db.php is loaded).
 */
function getIrsConfig(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [
        'accounts_roles'   => IRS_ACCOUNTS_ROLES_DEFAULT,
        'manager_roles'    => IRS_MANAGER_ROLES_DEFAULT,
        'petty_cash_limit' => IRS_PETTY_CASH_LIMIT_DEFAULT,
        'notify_enabled'   => true,
    ];
    if (function_exists('getDB')) {
        try {
            $rows = getDB()->query("SELECT `key`, value FROM irs_config")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                switch ($r['key']) {
                    case 'accounts_roles':
                        $v = json_decode($r['value'], true);
                        if (is_array($v) && $v) $cfg['accounts_roles'] = $v;
                        break;
                    case 'manager_roles':
                        $v = json_decode($r['value'], true);
                        if (is_array($v) && $v) $cfg['manager_roles'] = $v;
                        break;
                    case 'petty_cash_limit':
                        if (is_numeric($r['value'])) $cfg['petty_cash_limit'] = (float)$r['value'];
                        break;
                    case 'notify_enabled':
                        $cfg['notify_enabled'] = (bool)$r['value'];
                        break;
                }
            }
        } catch (Exception $e) { /* table may not exist yet — use defaults */ }
    }
    return $cfg;
}
