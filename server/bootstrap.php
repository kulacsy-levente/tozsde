<?php
/**
 * Közös indítófájl: autoloader, hibakezelés, konfiguráció.
 * Minden belépési pont (api.php, setup.php, index.php) ezt tölti be először.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('Ehhez a programhoz PHP 8.0 vagy újabb kell. Jelenlegi verzió: ' . PHP_VERSION);
}

define('APP_ROOT', dirname(__DIR__));
define('SERVER_ROOT', __DIR__);

// --- Egyszerű PSR-4 autoloader: App\Core\Db  ->  server/Core/Db.php ---
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = SERVER_ROOT . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// --- Konfiguráció (egyszer töltjük be) ---
function config(?string $key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = require SERVER_ROOT . '/config.php';
    }
    if ($key === null) {
        return $config;
    }
    // Pontozott elérés: config('fee.crypto')
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

// --- Hibakezelés: minden hiba kivétellé válik, hogy ne csússzon el csendben ---
error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

date_default_timezone_set('Europe/Budapest');
