<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON válaszok egységes formában.
 *
 * A kliens mindig ugyanazt a szerkezetet kapja:
 *   { "ok": true,  "data": {...} }
 *   { "ok": false, "error": "üzenet", "code": "..." }
 *
 * Így a front-end egyetlen helyen tud hibát kezelni.
 */
final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    public static function ok(array $data = []): void
    {
        self::json(['ok' => true, 'data' => $data]);
    }

    public static function fail(string $message, int $status = 400, string $code = 'error'): void
    {
        self::json(['ok' => false, 'error' => $message, 'code' => $code], $status);
    }

    /** 401 — nincs bejelentkezve. A kliens ilyenkor a belépőt mutatja. */
    public static function unauthorized(string $message = 'Ehhez be kell jelentkezni.'): void
    {
        self::fail($message, 401, 'unauthorized');
    }

    /** 409 — a program még nincs telepítve. */
    public static function notInstalled(): void
    {
        self::fail('A program még nincs telepítve. Futtasd a setup.php-t.', 409, 'not_installed');
    }
}
