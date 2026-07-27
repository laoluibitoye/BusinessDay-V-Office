<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// login.php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';

Auth::init();

// Already logged in
if (Auth::check()) {
    header('Location: ' . APP_URL . '/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $user = Auth::login($email, $password);
        if ($user) {
            // Store IMAP password in session for mail operations
            $_SESSION['mail_pass'] = $password;
            header('Location: ' . APP_URL . '/');
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Login — HRI Mail</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#001a35 0%,#002850 50%,#003a72 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.card{background:#fff;border-radius:20px;padding:44px 40px;width:100%;max-width:420px;box-shadow:0 24px 64px rgba(0,0,0,.3);}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:32px;}
.logo-box{width:44px;height:44px;background:#64A014;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff;flex-shrink:0;}
.logo-txt{line-height:1.2;}
.logo-name{font-size:18px;font-weight:700;color:#002850;}
.logo-sub{font-size:11.5px;color:#94a3b8;}
h1{font-size:22px;font-weight:700;color:#002850;margin-bottom:6px;}
.sub{font-size:13.5px;color:#64748b;margin-bottom:28px;}
.grp{margin-bottom:18px;}
label{display:block;font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
input{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:11px 14px;font-size:14px;font-family:'Inter',sans-serif;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s;background:#f8fafc;}
input:focus{border-color:#64A014;box-shadow:0 0 0 3px rgba(100,160,20,.12);background:#fff;}
.err{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#dc2626;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
button{width:100%;background:#002850;color:#fff;border:none;border-radius:9px;padding:12px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:background .15s;margin-top:4px;}
button:hover{background:#003a72;}
.footer{text-align:center;margin-top:24px;font-size:12px;color:#94a3b8;}
.footer a{color:#64A014;text-decoration:none;font-weight:600;}
.secure{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;font-size:11.5px;color:#94a3b8;}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <div class="logo-box">HRI</div>
        <div class="logo-txt">
            <div class="logo-name">HRI Mail</div>
            <div class="logo-sub">HR Indexx Limited</div>
        </div>
    </div>
    <h1>Welcome back</h1>
    <p class="sub">Sign in with your HRI email to continue</p>

    <?php if ($error): ?>
    <div class="err">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="on">
        <div class="grp">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="you@hrindexx.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus/>
        </div>
        <div class="grp">
            <label>Password</label>
            <input type="password" name="password" placeholder="Enter your password" required/>
        </div>
        <button type="submit">Sign In →</button>
    </form>

    <div class="secure">🔒 Secured with SSL · HRI internal use only</div>
    <div class="footer">
        Problems signing in? Contact <a href="mailto:ooloritun@hrindexx.com">IT Support</a>
    </div>
</div>
</body>
</html>
