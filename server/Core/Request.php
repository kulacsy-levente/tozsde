<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A bejövő kérés paraméterei, egységes típuskonverzióval.
 * A törzs JSON-ként és form-adatként is érkezhet.
 */
final class Request
{
    private static ?array $body = null;

    public static function action(): string
    {
        return (string) ($_GET['action'] ?? $_POST['action'] ?? '');
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** A kérés törzse tömbként (JSON vagy form). */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }

        $raw  = file_get_contents('php://input') ?: '';
        $type = $_SERVER['CONTENT_TYPE'] ?? '';

        if ($raw !== '' && stripos($type, 'json') !== false) {
            $decoded = json_decode($raw, true);
            self::$body = is_array($decoded) ? $decoded : [];
        } elseif ($raw !== '' && !$_POST) {
            parse_str($raw, $parsed);
            self::$body = is_array($parsed) ? $parsed : [];
        } else {
            self::$body = $_POST;
        }

        return self::$body;
    }

    /** Érték a törzsből, majd a query stringből. */
    public static function get(string $key, $default = null)
    {
        $body = self::body();
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }
        return $_GET[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::get($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $v = self::get($key, $default);
        if (is_string($v)) {
            // A magyar tizedesvessző és az ezres szóköz elfogadása
            $v = str_replace([' ', ' ', ','], ['', '', '.'], $v);
        }
        return is_numeric($v) ? (float) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on', 'igen'], true);
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }
}
