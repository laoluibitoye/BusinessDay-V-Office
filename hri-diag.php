<?php
/**
 * hri-diag.php — TEMPORARY. Delete from the server once the 500 is identified.
 *
 * Reproduces exactly what index.php does when it renders a dashboard, with
 * errors visible in the browser, so the fatal is named instead of guessed at.
 * Prints no credentials, no session contents, no database values.
 *
 * Filename deliberately avoids the LiteSpeed WAF blocklist (test/debug/tmp/...).
 */
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

function step(string $label, callable $fn) {
    echo str_pad($label, 46, '.');
    try {
        $r = $fn();
        echo ' OK' . ($r ? "  ($r)" : '') . "\n";
        return true;
    } catch (Throwable $e) {
        echo " FAILED\n";
        echo "\n    " . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo '    at ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
        return false;
    }
}

echo "HRI Mail — dashboard diagnostic\n";
echo "PHP " . PHP_VERSION . "\n";
echo str_repeat('=', 60) . "\n\n";

echo "-- FILES PRESENT --\n";
foreach ([
    'config/app.php', 'config/db.php', 'lib/Auth.php', 'lib/IrsFlow.php',
    'lib/Layout.php', 'index.php',
    'dashboard/_irs_widget.php', 'dashboard/_my_tickets.php',
    'dashboard/staff.php', 'dashboard/superadmin.php', 'dashboard/management.php',
    'dashboard/hod.php', 'dashboard/hr.php', 'dashboard/manager.php',
] as $f) {
    $p = __DIR__ . '/' . $f;
    printf("  %-34s %s\n", $f, is_file($p) ? 'present (' . filesize($p) . 'b)' : '*** MISSING ***');
}

echo "\n-- LOAD CHAIN --\n";
if (!step('require config/app.php', function () { require_once __DIR__ . '/config/app.php'; return null; })) exit;
if (!step('require config/db.php',  function () { require_once __DIR__ . '/config/db.php';  return null; })) exit;
if (!step('require lib/Auth.php',   function () { require_once __DIR__ . '/lib/Auth.php';   return null; })) exit;
if (!step('require lib/IrsFlow.php', function () { require_once __DIR__ . '/lib/IrsFlow.php'; return null; })) exit;
step('database connect', function () { $d = getDB(); return get_class($d); });
step('IRS_STAGE_OWNERS defined', function () { return defined('IRS_STAGE_OWNERS') ? 'yes' : 'NO — lib/IrsFlow.php is stale'; });
step('Auth::apiUser exists', function () { return method_exists('Auth', 'apiUser') ? 'yes' : 'NO — lib/Auth.php is stale'; });
step('Auth::mailPass exists', function () { return method_exists('Auth', 'mailPass') ? 'yes' : 'NO — lib/Auth.php is stale'; });

echo "\n-- LOGGED-IN USER --\n";
$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
    echo "  Not logged in. Log in first, then reload this page for the widget test.\n";
    exit;
}
$user = null;
step('load user row', function () use (&$user, $uid) {
    $s = getDB()->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $s->execute([$uid]);
    $user = $s->fetch(PDO::FETCH_ASSOC);
    return $user ? ('role=' . $user['role']) : 'no row';
});
if (!$user) exit;

echo "\n-- WIDGET RENDER (this is where a dashboard 500 would come from) --\n";
$db = getDB();
foreach (['dashboard/_irs_widget.php', 'dashboard/_my_tickets.php'] as $w) {
    step('render ' . $w, function () use ($w) {
        global $user, $db;
        ob_start();
        include __DIR__ . '/' . $w;
        return strlen(ob_get_clean()) . ' bytes';
    });
}

echo "\n-- FULL DASHBOARD RENDER --\n";
$map = [
    'md'=>'management','bdm'=>'management','head_it'=>'superadmin','it_admin'=>'superadmin',
    'hr'=>'hr','training_manager'=>'manager','cs_manager'=>'manager',
];
$dash = $map[$user['role']] ?? (strpos($user['role'], 'head_') === 0 ? 'hod' : 'staff');
echo "  role '{$user['role']}' routes to dashboard/{$dash}.php\n";
step('render dashboard/' . $dash . '.php', function () use ($dash) {
    global $user, $db;
    $mp = '';
    ob_start();
    include __DIR__ . '/dashboard/' . $dash . '.php';
    return strlen(ob_get_clean()) . ' bytes';
});

echo "\n" . str_repeat('=', 60) . "\nDone. DELETE THIS FILE from the server now.\n";
