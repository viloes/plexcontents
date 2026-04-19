<?php
// src/Auth.php
namespace App;

class Auth {
    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
            Logger::debug('Session started');
        }
    }

    public static function login($username, $password) {
        self::startSession();
        Logger::info('Login attempt', [
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        $pdo = DB::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['language'] = $user['language'] ?? 'es';
            Logger::info('Login success', [
                'user_id' => $user['id'],
                'username' => $user['username'],
                'is_admin' => (int) $user['is_admin']
            ]);
            return true;
        }

        Logger::error('Login failed', [
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        return false;
    }

    public static function isLoggedIn() {
        self::startSession();
        return isset($_SESSION['user_id']);
    }

    public static function isAdmin() {
        self::startSession();
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
    }

    public static function logout() {
        self::startSession();
        Logger::info('Logout', [
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null
        ]);
        session_unset();
        session_destroy();
    }

    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            Logger::error('Access denied: login required', [
                'path' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            Flash::add('warning', getLangMessage('error_login_required'));
            header("Location: login.php");
            exit;
        }
    }

    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            Logger::error('Access denied: admin required', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'username' => $_SESSION['username'] ?? null,
                'path' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            die(getLangMessage('error_admin_required'));
        }
    }
}