<?php
require_once __DIR__ . '/Env.php';

class Auth {
    public static function init() {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }

        if (!headers_sent()) {
            header("X-Frame-Options: DENY");
            header("X-Content-Type-Options: nosniff");
            header("Referrer-Policy: strict-origin-when-cross-origin");
        }
    }

    public static function login($username, $password) {
        self::init();
        
        $adminUser = Env::get('AUTH_USERNAME');
        $adminHash = Env::get('AUTH_PASSWORD_HASH');

        if (!$adminUser || !$adminHash) {
            return false;
        }

        if ($username === $adminUser && password_verify($password, $adminHash)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['user'] = $username;
            $_SESSION['last_activity'] = time();
            return true;
        }
        
        return false;
    }

    public static function check() {
        if (php_sapi_name() === 'cli') {
            return true;
        }

        self::init();
        
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }

        if (time() - ($_SESSION['last_activity'] ?? 0) > 7200) {
            self::logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function requireLogin() {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function logout() {
        self::init();
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }

    public static function csrfToken() {
        self::init();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf($token) {
        self::init();
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
