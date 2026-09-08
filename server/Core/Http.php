<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimális HTTP GET, cURL-lel és file_get_contents fallbackkel.
 * XAMPP-on a kettő közül legalább az egyik szinte mindig elérhető.
 */
final class Http
{
    public static function get(string $url, int $timeout = 15): ?string
    {
        if (function_exists('curl_init')) {
            $body = self::viaCurl($url, $timeout);
            if ($body !== null) {
                return $body;
            }
        }

        if (ini_get('allow_url_fopen')) {
            return self::viaStream($url, $timeout);
        }

        return null;
    }

    /** Letöltés fájlba. A könyvtárszerkezetet szükség szerint létrehozza. */
    public static function download(string $url, string $target, int $timeout = 30): bool
    {
        $body = self::get($url, $timeout);
        if ($body === null || $body === '') {
            return false;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($target, $body) !== false;
    }

    private static function viaCurl(string $url, int $timeout): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; TozsdeSim/1.0)',
            CURLOPT_ENCODING       => '',
            // XAMPP-ról gyakran hiányzik a CA-csomag. Csak publikus, nyilvános
            // árfolyamadatot és CDN-ről könyvtárakat töltünk le — nincs titok,
            // amit védeni kellene.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        //curl_close($ch);

        return (is_string($body) && $body !== '' && $code >= 200 && $code < 300) ? $body : null;
    }

    private static function viaStream(string $url, int $timeout): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'       => $timeout,
                'user_agent'    => 'Mozilla/5.0 (compatible; TozsdeSim/1.0)',
                'ignore_errors' => false,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        return (is_string($body) && $body !== '') ? $body : null;
    }
}
