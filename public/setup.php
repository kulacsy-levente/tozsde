<?php
/**
 * Telepítő. Böngészőből és parancssorból is futtatható:
 *
 *     http://localhost/tozsde/public/setup.php
 *     php public/setup.php
 *
 * A haladást élőben írja ki, mert az 50 eszköz letöltése eltarthat egy percig.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/server/bootstrap.php';

use App\Core\Db;
use App\Service\Installer;

$isCli = PHP_SAPI === 'cli';
$force = $isCli
    ? in_array('--force', $argv ?? [], true)
    : isset($_GET['force']);

@set_time_limit(600);
ignore_user_abort(true);

// --- Kimenet ---
if ($isCli) {
    $emit = function (string $message, string $level = 'info'): void {
        $prefix = ['ok' => '  [ok] ', 'warn' => '  [!!] ', 'step' => "\n== ", 'error' => '  [XX] '][$level] ?? '  ';
        echo $prefix . $message . PHP_EOL;
    };
} else {
    // A kimeneti pufferek kikapcsolása, hogy tényleg soronként érkezzen
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');

    echo <<<'HTML'
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tőzsdeszimulátor — telepítés</title>
<style>
  :root { color-scheme: dark; }
  body { background:#0d1117; color:#c9d1d9; font:14px/1.6 ui-monospace,"Cascadia Code",Consolas,monospace;
         margin:0; padding:2rem; }
  h1 { font-size:1.4rem; color:#e6edf3; margin:0 0 .25rem; font-family:system-ui,sans-serif; }
  p.lead { color:#8b949e; margin:0 0 1.5rem; font-family:system-ui,sans-serif; }
  #log { white-space:pre-wrap; }
  .step  { color:#79c0ff; font-weight:700; display:block; margin-top:1rem; }
  .ok    { color:#7ee787; }
  .warn  { color:#e3b341; }
  .error { color:#ff7b72; }
  .info  { color:#8b949e; }
  .done { margin-top:2rem; padding:1rem 1.25rem; border:1px solid #2ea043; border-radius:8px;
          background:#0f2417; font-family:system-ui,sans-serif; }
  .done a { color:#7ee787; font-weight:600; }
  .fail { margin-top:2rem; padding:1rem 1.25rem; border:1px solid #da3633; border-radius:8px;
          background:#25100f; font-family:system-ui,sans-serif; color:#ffa198; }
</style>
</head>
<body>
<h1>Tőzsdeszimulátor — telepítés</h1>
<p class="lead">Adatbázis létrehozása és a valós árfolyamtörténet letöltése. Ez eltarthat egy percig.</p>
<div id="log">
HTML;

    $emit = function (string $message, string $level = 'info'): void {
        $class = in_array($level, ['ok', 'warn', 'error', 'step'], true) ? $level : 'info';
        echo '<span class="' . $class . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</span>\n";
        flush();
    };
}

// --- Már telepítve? ---
try {
    if (Installer::isInstalled() && !$force) {
        $emit('A program már telepítve van.', 'warn');
        $emit($isCli
            ? 'Újratelepítéshez: php public/setup.php --force  (ez törli a meglévő világot)'
            : 'Újratelepítéshez add hozzá a ?force=1 paramétert. Ez törli a meglévő piacot és fiókokat.',
            'info');

        if (!$isCli) {
            echo '</div><div class="done">Kész. <a href="index.php">Tovább a kereskedéshez →</a></div></body></html>';
        }
        exit(0);
    }

    if ($force) {
        $emit('Kényszerített újratelepítés: a meglévő adatbázis törlése...', 'step');

        $dbPath = config('db_path');

        // Az ellenőrzés már megnyitotta az adatbázist, és Windowson a nyitott
        // fájl nem törölhető — előbb el kell engedni a kapcsolatot.
        Db::close();

        foreach ([$dbPath . '-wal', $dbPath . '-shm', $dbPath] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Ha valaki más (pl. egy futó Apache) is nyitva tartja, a törlés akkor
        // is elbukik — csendben. Ilyenkor a régi adatokon dolgoznánk tovább
        // anélkül, hogy bárki észrevenné, ezért a tartalmat táblánként ürítjük.
        if (is_file($dbPath)) {
            $emit('A fájl zárolva van (fut az Apache?) — a táblák ürítése helyette.', 'warn');

            Db::install();   // a séma meglétét biztosítjuk az ürítés előtt
            $tables = Db::all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

            Db::transaction(function () use ($tables) {
                foreach ($tables as $t) {
                    Db::run('DELETE FROM ' . $t['name']);
                }
            });

            $emit(count($tables) . ' tábla kiürítve.', 'ok');
        } else {
            $emit('Törölve.', 'ok');
        }
    }

    $started = microtime(true);
    $result  = Installer::run($emit);
    $seconds = round(microtime(true) - $started, 1);

    $emit(sprintf(
        'Telepítés kész %s másodperc alatt — %d valós, %d generált eszköz.',
        $seconds,
        $result['real'],
        $result['synthetic']
    ), 'step');

    if (!$isCli) {
        echo '</div><div class="done">Minden készen áll. <a href="index.php">Tovább a kereskedéshez →</a></div></body></html>';
    }
} catch (\Throwable $e) {
    $emit('Hiba: ' . $e->getMessage(), 'error');
    $emit($e->getFile() . ':' . $e->getLine(), 'error');

    if (!$isCli) {
        echo '</div><div class="fail">A telepítés megszakadt. A fenti hibaüzenet mondja meg, hol.</div></body></html>';
    }
    exit(1);
}
