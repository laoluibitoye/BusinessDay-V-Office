<?php
// config/db.php
require_once '/home/hrindexx/hri-secrets.php';

define('DB_HOST',    'localhost');
define('DB_NAME',    'hrindexx_hri_webmail');
define('DB_USER',    'hrindexx_hri_user');
define('DB_PASS',    _DB_PASS);
define('DB_CHARSET', 'utf8mb4');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Align MySQL session timezone with WAT (UTC+1)
            $pdo->exec("SET time_zone = '+01:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    return $pdo;
}
