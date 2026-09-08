<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Munkamenet- és CSRF-kezelés.
 *
 * A CSRF-token azért kell, mert az összes pénzmozgás egyszerű POST-tal
 * történik: enélkül egy másik oldal a böngésződ nevében nyithatna pozíciót.
 * Játék ide vagy oda, ezt olcsó jól csinálni.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        session_name((string) config('session_name'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function accountId(): ?int
    {
        self::start();
        return isset($_SESSION['account_id']) ? (int) $_SESSION['account_id'] : null;
    }

    public static function login(int $userId, int $accountId): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id']    = $userId;
        $_SESSION['account_id'] = $accountId;
    }

    public static function selectAccount(int $accountId): void
    {
        self::start();
        $_SESSION['account_id'] = $accountId;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$started = false;
    }

    // --- CSRF ---

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): bool
    {
        self::start();
        $sent = Request::header('X-CSRF-Token') ?? Request::str('csrf');
        $have = $_SESSION['csrf'] ?? '';
        return $sent !== '' && $have !== '' && hash_equals((string) $have, $sent);
    }
}
