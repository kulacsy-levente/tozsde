<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;
use App\Core\Http;

/**
 * Első telepítés és visszaállítás.
 *
 * A telepítés lépései:
 *   1. adatbázisséma
 *   2. front-end könyvtárak letöltése helyi tárolásra (Bootstrap, Font Awesome,
 *      Lightweight Charts) — ha nem megy, a program CDN-re vált
 *   3. valós historikus árfolyamok importja
 *   4. a piac órájának beállítása oda, ahol a valós történet véget ért
 *   5. a hiányzó napok legenerálása a szimulált "mai" napig
 */
final class Installer
{
    private const VENDOR_FILES = [
        [
            'lightweight-charts/lightweight-charts.standalone.production.js',
            'https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js',
        ],
        [
            'bootstrap/bootstrap.min.css',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        ],
        [
            'bootstrap/bootstrap.bundle.min.js',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
        ],
        [
            'fontawesome/css/all.min.css',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
        ],
        [
            'fontawesome/webfonts/fa-solid-900.woff2',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-solid-900.woff2',
        ],
        [
            'fontawesome/webfonts/fa-regular-400.woff2',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-regular-400.woff2',
        ],
        [
            'fontawesome/webfonts/fa-brands-400.woff2',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-brands-400.woff2',
        ],
    ];

    public static function isInstalled(): bool
    {
        if (!Db::isInstalled()) {
            return false;
        }
        return Db::value('SELECT COUNT(*) FROM market_state', [], 0) > 0
            && Db::value('SELECT COUNT(*) FROM assets', [], 0) > 0;
    }

    /**
     * A teljes telepítés lefuttatása.
     *
     * @param callable(string, string):void $progress  (üzenet, szint)
     */
    public static function run(callable $progress): array
    {
        $say = fn(string $m, string $l = 'info') => $progress($m, $l);

        // --- 1. Séma ---
        $say('Adatbázis létrehozása...', 'step');
        Db::install();
        $say('A séma elkészült.', 'ok');

        // --- 2. Front-end könyvtárak ---
        $say('Front-end könyvtárak letöltése (Bootstrap, Font Awesome, Lightweight Charts)...', 'step');
        $vendorOk = self::downloadVendor($say);
        Db::setSetting('vendor_local', $vendorOk ? '1' : '0');
        $say($vendorOk
            ? 'Minden könyvtár helyben elérhető — a program internet nélkül is megnyílik.'
            : 'Néhány könyvtár nem jött le, ezekre CDN-hivatkozás lesz érvényben.',
            $vendorOk ? 'ok' : 'warn');

        // --- 3. Árfolyamtörténet ---
        $say('Historikus árfolyamok betöltése (50 eszköz)...', 'step');
        $importer = new HistoryImporter($say);
        $result   = $importer->run();
        $say(sprintf(
            'Kész: %d eszköz valós adattal, %d generált történettel.',
            $result['real'],
            $result['synthetic']
        ), 'ok');

        // --- 4. A piac órája ---
        $say('A piaci óra beállítása...', 'step');
        $epochStart = PriceEngine::dayTs($result['last_day'] + 1);
        self::writeMarketState($epochStart, Rng::newSeed());
        PriceEngine::forgetState();
        $say('A szimuláció innen folytatódik: ' . date('Y-m-d', $epochStart), 'ok');

        // --- 5. A hiányzó napok legenerálása ---
        $say('Hiányzó napok generálása...', 'step');
        $generated = PriceEngine::ensureDaily();
        $say("Legenerálva $generated napi gyertya.", 'ok');

        Db::setSetting('installed_at', (string) time());

        return $result + ['generated' => $generated, 'vendor' => $vendorOk];
    }

    private static function downloadVendor(callable $say): bool
    {
        $base = config('vendor_dir');
        $allOk = true;

        foreach (self::VENDOR_FILES as [$relative, $url]) {
            $target = $base . '/' . $relative;

            if (is_file($target) && filesize($target) > 512) {
                $say(basename($relative) . ' — már megvan', 'ok');
                continue;
            }

            if (Http::download($url, $target, 40)) {
                $say(basename($relative) . ' — letöltve', 'ok');
            } else {
                $say(basename($relative) . ' — nem sikerült', 'warn');
                $allOk = false;
            }
        }

        return $allOk;
    }

    private static function writeMarketState(int $epochStart, int $marketSeed): void
    {
        // sim_now() = time() + sim_offset, tehát az eltolás úgy áll be, hogy a
        // szimulált idő pontosan ott kezdődjön, ahol a valós történet véget ért.
        $offset = $epochStart - time();

        Db::run('DELETE FROM market_state WHERE id = 1');
        Db::insert('market_state', [
            'id'          => 1,
            'epoch_start' => $epochStart,
            'sim_offset'  => $offset,
            'market_seed' => $marketSeed,
            'created_at'  => time(),
        ]);
    }

    // =================================================================
    //  Visszaállítás
    // =================================================================

    /**
     * Egy fiók visszaállítása: pozíciók, kötések és értékgörbe törlése,
     * a készpénz vissza a kezdőtőkére. A piac megy tovább.
     */
    public static function resetAccount(int $accountId): void
    {
        Db::transaction(function () use ($accountId) {
            $account = Db::one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
            if ($account === null) {
                throw new \RuntimeException('Nincs ilyen fiók.');
            }

            Db::run('DELETE FROM positions        WHERE account_id = ?', [$accountId]);
            Db::run('DELETE FROM trades           WHERE account_id = ?', [$accountId]);
            Db::run('DELETE FROM equity_snapshots WHERE account_id = ?', [$accountId]);

            Db::update('accounts', ['cash_huf' => $account['initial_capital']], 'id = :id', ['id' => $accountId]);
        });
    }

    /**
     * A piac visszatekerése oda, ahol a valós történet véget ért.
     *
     * @param bool $newSeeds új seedek is -> teljesen más árpályák ("új világ")
     */
    public static function resetWorld(bool $newSeeds = false): void
    {
        Db::transaction(function () use ($newSeeds) {
            $state      = Db::one('SELECT * FROM market_state WHERE id = 1');
            $epochStart = (int) $state['epoch_start'];
            $epochDay   = PriceEngine::dayIndex($epochStart);

            // A szimulált (nem valós) gyertyák törlése
            Db::run('DELETE FROM candles_1d WHERE src = ? OR ts >= ?', ['sim', $epochStart]);
            Db::run('DELETE FROM candles_1m');
            Db::run('DELETE FROM news WHERE ts >= ?', [$epochStart]);

            // Minden eszköz visszaállítása az utolsó megmaradt gyertyájára
            foreach (Db::all('SELECT * FROM assets') as $asset) {
                $last = Db::one(
                    'SELECT * FROM candles_1d WHERE asset_id = ? ORDER BY ts DESC LIMIT 1',
                    [$asset['id']]
                );

                $update = [
                    'cur_sigma'   => $asset['sigma0'],
                    'last_return' => 0.0,
                ];

                if ($last !== null) {
                    $update['last_price'] = $last['c'];
                    $update['last_day']   = PriceEngine::dayIndex((int) $last['ts']);
                } else {
                    // Szintetikus eszköz: vissza a történet elejére
                    $update['last_day'] = $epochDay - (int) config('history_days');
                }

                if ($newSeeds) {
                    $update['seed'] = Rng::newSeed();
                }

                Db::update('assets', $update, 'id = :id', ['id' => $asset['id']]);
            }

            $marketSeed = $newSeeds ? Rng::newSeed() : (int) $state['market_seed'];

            // Új világnál a valós múlt is elveszti az értelmét: ilyenkor a
            // szintetikus generálás írja felül a teljes történetet.
            if ($newSeeds) {
                Db::run('DELETE FROM candles_1d');
                Db::run(
                    'UPDATE assets SET last_day = ?, last_price = CASE WHEN last_price > 0 THEN last_price ELSE 1000 END',
                    [$epochDay - (int) config('history_days')]
                );
            }

            self::writeMarketState($epochStart, $marketSeed);
        });

        PriceEngine::forgetState();
        PriceEngine::ensureDaily();
    }
}
