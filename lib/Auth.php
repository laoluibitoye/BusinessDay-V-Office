<?php
// lib/Auth.php — HRI Mail Authentication
// CRITICAL: session_start() must come before any header() call

class Auth {

    private static $permCache = [];

    // ── LOGIN ────────────────────────────────────────────────
    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    public static function check(): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userId = $_SESSION['user_id'] ?? null;
        $token  = $_SESSION['token']   ?? null;
        if (!$userId || !$token) return false;
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT id FROM sessions WHERE token=? AND user_id=? AND expires_at>NOW() LIMIT 1");
            $stmt->execute([$token, $userId]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) { return false; }
    }

    public static function login(string $email, string $password) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $db   = getDB();
        $ip   = self::getIP();

        // Rate limit — separate email and IP checks so spoofed IPs cannot bypass per-email lockout
        try {
            $rEmail = $db->prepare("SELECT COUNT(*) FROM login_history WHERE email=? AND status='failed' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $rEmail->execute([$email]);
            if ((int)$rEmail->fetchColumn() >= MAX_LOGIN_ATTEMPTS) {
                http_response_code(429);
                return false;
            }
            $rIp = $db->prepare("SELECT COUNT(*) FROM login_history WHERE ip_address=? AND status='failed' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $rIp->execute([$ip]);
            if ((int)$rIp->fetchColumn() >= MAX_LOGIN_ATTEMPTS * 3) {
                http_response_code(429);
                return false;
            }
        } catch (Exception $e) {}

        $stmt = $db->prepare("SELECT * FROM users WHERE email=? AND is_active=1 LIMIT 1");
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();

        $hash  = $user['password_hash'] ?? '$2y$10$invalidsaltinvalidsaltinvalidsalt12';
        $valid = password_verify($password, $hash);

        if (!$user || !$valid) {
            self::logLogin(null, $email, 'failed');
            return false;
        }

        session_regenerate_id(true);
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        $device  = self::detectDevice();

        try {
            $db->prepare("INSERT INTO sessions (user_id,token,ip_address,user_agent,device,expires_at,last_active,created_at) VALUES (?,?,?,?,?,?,NOW(),NOW())")
               ->execute([$user['id'],$token,$ip,substr($_SERVER['HTTP_USER_AGENT']??'',0,255),$device,$expires]);
        } catch (Exception $e) {}

        $_SESSION['user_id']     = $user['id'];
        $_SESSION['token']       = $token;
        $_SESSION['login_time']  = time();
        $_SESSION['last_active'] = time();

        try { $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]); } catch (Exception $e) {}
        self::logLogin($user['id'], $email, 'success');
        self::auditLog($user['id'], 'login', "IP: $ip");
        return $user;
    }

    // ── REQUIRE AUTH ─────────────────────────────────────────
    public static function require(): array {
        // *** SESSION FIRST — before any header() calls ***
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Security headers — AFTER session_start()
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        $userId = $_SESSION['user_id'] ?? null;
        $token  = $_SESSION['token']   ?? null;

        if (!$userId || !$token) {
            self::redirect(APP_URL . '/login.php');
        }

        $db = getDB();
        try {
            $stmt = $db->prepare("SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.user_id=? AND s.expires_at>NOW() LIMIT 1");
            $stmt->execute([$token, $userId]);
            $user = $stmt->fetch();
            if (!$user) self::redirect(APP_URL . '/login.php');

            // Enforce idle timeout (IDLE_TIMEOUT seconds of inactivity = auto-logout)
            $lastActive = $_SESSION['last_active'] ?? time();
            if ((time() - $lastActive) > IDLE_TIMEOUT) {
                self::logout('Session timed out due to inactivity');
            }

            // Update last active
            $_SESSION['last_active'] = time();
            try { $db->prepare("UPDATE sessions SET last_active=NOW() WHERE token=?")->execute([$token]); } catch (Exception $e) {}
        } catch (Exception $e) {
            self::redirect(APP_URL . '/login.php');
        }

        // Validate CSRF for all POST requests (runs after confirming the session is valid)
        self::validateCsrf();

        return $user;
    }

    // ── REQUIRE ROLE ─────────────────────────────────────────
    // Like require() but also checks the user has one of the given roles
    public static function requireRole(array $roles): array {
        $user = self::require();
        if (!in_array($user['role'], $roles)) {
            http_response_code(403);
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;">'
              . '<h2>&#128683; Access Restricted</h2>'
              . '<p>You do not have permission to access this page.</p>'
              . '<p>Required role: ' . implode(' or ', array_map('htmlspecialchars', $roles)) . '</p>'
              . '<a href="' . APP_URL . '/" style="color:#002850;">Return to Dashboard</a>'
              . '</div>');
        }
        return $user;
    }

    // ── CSRF ─────────────────────────────────────────────────
    public static function csrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrfToken()) . '"/>';
    }

    public static function validateCsrf(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (session_status() === PHP_SESSION_NONE) session_start();
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        $submitted    = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$sessionToken || !$submitted || !hash_equals($sessionToken, $submitted)) {
            http_response_code(403);
            $wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                      || isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                      || isset($_SERVER['HTTP_X_CSRF_TOKEN']);
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'CSRF token invalid. Refresh and try again.']);
            } else {
                echo '<div style="font-family:sans-serif;padding:40px;text-align:center;">'
                   . '<h2>&#128683; Request Rejected</h2>'
                   . '<p>Security token mismatch. Please go back and try again.</p>'
                   . '<a href="javascript:history.back()">Go back</a></div>';
            }
            exit;
        }
    }

    // ── LOGOUT ───────────────────────────────────────────────
    public static function logout(string $reason = ''): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userId = $_SESSION['user_id'] ?? null;
        $token  = $_SESSION['token']   ?? null;
        if ($userId) {
            self::auditLog($userId, 'logout', $reason ?: 'logout');
            try {
                $db = getDB();
                if ($token) $db->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
            } catch (Exception $e) {}
        }
        session_unset();
        session_destroy();
        self::redirect(APP_URL . '/login.php' . ($reason ? '?msg='.urlencode($reason) : ''));
    }

    // ── AUDIT / SECURITY LOGGING ─────────────────────────────
    public static function auditLog(int $userId, string $action, string $detail = ''): void {
        try {
            $db = getDB();
            $db->prepare("INSERT INTO audit_log (user_id,action,detail,ip_address,created_at) VALUES (?,?,?,?,NOW())")
               ->execute([$userId, $action, substr($detail,0,500), self::getIP()]);
        } catch (Exception $e) {}
    }

    public static function securityLog(string $event, string $detail = ''): void {
        try {
            $db = getDB();
            $db->prepare("INSERT INTO audit_log (user_id,action,detail,ip_address,created_at) VALUES (NULL,?,?,?,NOW())")
               ->execute([$event, substr($detail,0,500), self::getIP()]);
        } catch (Exception $e) {}
    }

    public static function trackUsage(int $userId, string $metric, int $amount = 1): void {
        static $allowed = ['tasks_created','tasks_completed','vault_uploads','docs_signed',
                           'emails_sent','emails_read','it_requests','leave_requests'];
        if (!in_array($metric, $allowed, true)) return;
        try {
            $db = getDB();
            $db->prepare("INSERT INTO usage_stats (user_id,date,$metric) VALUES (?,CURDATE(),?) ON DUPLICATE KEY UPDATE $metric=$metric+?")
               ->execute([$userId, $amount, $amount]);
        } catch (Exception $e) {}
    }

    // ── SANITISATION ─────────────────────────────────────────
    public static function sanitiseString(string $input, int $maxLen = 255): string {
        return substr(trim(strip_tags($input)), 0, $maxLen);
    }

    public static function sanitiseInt($input): int {
        return (int)filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    }

    // ── FIELD-LEVEL ENCRYPTION ───────────────────────────────
    // AES-256-CBC for PII at rest (NIN, bank account numbers).
    // Key: add  env[APP_ENCRYPTION_KEY]=<64-char random hex>  in .user.ini
    // Generate key: php -r "echo bin2hex(random_bytes(32));"
    // Falls back to plaintext if no key is configured (backwards-compatible).
    public static function encrypt(string $plaintext): string {
        $key = getenv('APP_ENCRYPTION_KEY');
        if (!$key || $plaintext === '') return $plaintext;
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        return $enc !== false ? base64_encode($iv . $enc) : $plaintext;
    }

    public static function decrypt(string $ciphertext): string {
        $key = getenv('APP_ENCRYPTION_KEY');
        if (!$key || $ciphertext === '') return $ciphertext;
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) < 17) return $ciphertext; // not encrypted — legacy plaintext
        $iv  = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $dec = openssl_decrypt($enc, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        return $dec !== false ? $dec : $ciphertext;
    }

    // ── MAILBOX CREDENTIAL ───────────────────────────────────
    // The user's IMAP password has to survive the session so mail can be read
    // without re-prompting, but it was held in $_SESSION as plaintext. On
    // shared hosting the session file is not exclusively ours, so it is now
    // encrypted at rest and only decrypted in memory for the request that
    // needs it. Always go through these two methods — never touch the session
    // key directly.
    // These two are the ONLY places that may touch the raw session keys.
    private const MP_ENC    = 'mail_pass_enc';
    private const MP_LEGACY = 'mail_pass';

    public static function setMailPass(string $plain): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION[self::MP_ENC] = self::encrypt($plain);
        unset($_SESSION[self::MP_LEGACY]); // drop any legacy plaintext copy
    }

    public static function mailPass(): string {
        if (isset($_SESSION[self::MP_ENC])) {
            return self::decrypt((string)$_SESSION[self::MP_ENC]);
        }
        // Sessions created before this change still hold plaintext. Read it,
        // upgrade it in place, and it is gone on the next request.
        if (isset($_SESSION[self::MP_LEGACY])) {
            $plain = (string)$_SESSION[self::MP_LEGACY];
            if ($plain !== '') self::setMailPass($plain);
            return $plain;
        }
        return '';
    }

    // ── PERMISSIONS ──────────────────────────────────────────
    private static function loadRolePerms(string $role): array {
        if (!array_key_exists($role, self::$permCache)) {
            self::$permCache[$role] = [];
            try {
                $stmt = getDB()->prepare("SELECT feature, access FROM role_permissions WHERE role_key=?");
                $stmt->execute([$role]);
                foreach ($stmt->fetchAll() as $p) {
                    self::$permCache[$role][$p['feature']] = $p['access'];
                }
            } catch (Exception $e) {}
        }
        return self::$permCache[$role];
    }

    // Returns true if the user has admin-level access (all permissions).
    // Checks DB first so custom roles granted 'admin' in the permissions matrix also qualify.
    public static function isAdmin(array $user): bool {
        static $adminRoles = ['md', 'bdm', 'head_it', 'it_admin'];
        return in_array($user['role'] ?? '', $adminRoles) || self::can($user, 'admin');
    }

    // Returns true if the role has read or write access to $permission.
    // Checks role_permissions table first; falls back to ROLES constant if no DB rows exist.
    public static function can(array $user, string $permission): bool {
        $role = $user['role'] ?? '';
        if (!$role) return false;
        $dbPerms = self::loadRolePerms($role);
        if (!empty($dbPerms)) {
            return ($dbPerms[$permission] ?? 'none') !== 'none';
        }
        $roleCfg = ROLES[$role] ?? null;
        if (!$roleCfg) return false;
        $perms = $roleCfg['permissions'] ?? [];
        return in_array('all', $perms) || in_array($permission, $perms);
    }

    // Returns true only if the role has write access (not just read).
    public static function canWrite(array $user, string $permission): bool {
        $role = $user['role'] ?? '';
        if (!$role) return false;
        $dbPerms = self::loadRolePerms($role);
        if (!empty($dbPerms)) {
            return ($dbPerms[$permission] ?? 'none') === 'write';
        }
        $roleCfg = ROLES[$role] ?? null;
        if (!$roleCfg) return false;
        $perms = $roleCfg['permissions'] ?? [];
        return in_array('all', $perms) || in_array($permission, $perms);
    }

    public static function requirePermission(array $user, string $permission): void {
        if (!self::can($user, $permission)) {
            http_response_code(403);
            die('<h1 style="font-family:sans-serif">403 — Access Denied</h1><a href="/">Dashboard</a>');
        }
    }

    // ── PRIVATE HELPERS ──────────────────────────────────────
    private static function redirect(string $url): void {
        if (!headers_sent()) header('Location: ' . $url);
        else echo '<script>location.href="'.addslashes($url).'"</script>';
        exit;
    }

    private static function logLogin(?int $userId, string $email, string $status): void {
        try {
            $db = getDB();
            $db->prepare("INSERT INTO login_history (user_id,email,ip_address,user_agent,status,created_at) VALUES (?,?,?,?,?,NOW())")
               ->execute([$userId,$email,self::getIP(),substr($_SERVER['HTTP_USER_AGENT']??'',0,255),$status]);
        } catch (Exception $e) {}
    }

    private static function getIP(): string {
        // Only trust Cloudflare's real-IP header when the CF country co-header is also present
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        // Use the direct connection address — never trust X-Forwarded-For on shared hosting
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    private static function detectDevice(): string {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (strpos($ua,'mobile')!==false||strpos($ua,'android')!==false) return 'mobile';
        if (strpos($ua,'tablet')!==false||strpos($ua,'ipad')!==false)    return 'tablet';
        return 'desktop';
    }
}
