<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;

/**
 * Az árszimuláció motorja.
 *
 * KÉTSZINTŰ MODELL
 * ----------------
 * 1. Napi szint — minden nap hozama a seedből számolódik:
 *
 *        r_d = mu + beta * F_d + sigma_d * eps_d + shock_d
 *
 *    ahol F_d a közös piaci faktor (ettől mozognak együtt a papírok),
 *    sigma_d GARCH-szerűen ingadozik (ettől lesznek nyugodt és viharos
 *    időszakok), shock_d pedig a napi hír hatása.
 *
 * 2. Napon belüli szint — a nyitó és záró ár közötti utat egy Brown-híd
 *    adja, felező eltolással (midpoint displacement) építve.
 *
 * A felező eltolás azért fontos, mert ugyanaz a rekurzió használható
 * EGYETLEN időpont árának lekérdezésére (~11 lépés) és a teljes 1440 perces
 * út legyártására is — és a kettő pontosan ugyanazt a számot adja. Így a
 * bal oldali listában látott ár és a grafikon gyertyája sosem mond ellent
 * egymásnak, miközben az 50 eszköz árát másodpercenként újra tudjuk számolni.
 *
 * MIÉRT NEM KELL HÁTTÉRFOLYAMAT
 * -----------------------------
 * Mivel minden érték a seedből jön, semmit nem kell "menet közben" futtatni.
 * Ha egy hétig nem indítod el a programot, a következő megnyitáskor egyszerűen
 * legenerálódik a kimaradt hét — ugyanaz jön ki, mintha végig futott volna.
 */
final class PriceEngine
{
    public const DAY  = 86400;
    public const MIN  = 60;
    public const MINUTES_PER_DAY = 1440;

    private static ?array $state = null;
    private static ?array $assetCache = null;
    /** @var array<string, array> memoizált napi gyertyák egy kérésen belül */
    private static array $dayCache = [];

    // =================================================================
    //  Állapot és idő
    // =================================================================

    public static function state(): array
    {
        if (self::$state === null) {
            $row = Db::one('SELECT * FROM market_state WHERE id = 1');
            if ($row === null) {
                throw new \RuntimeException('A piac nincs inicializalva. Futtasd a setup.php-t.');
            }
            self::$state = $row;
        }
        return self::$state;
    }

    public static function forgetState(): void
    {
        self::$state = null;
        self::$assetCache = null;
        self::$dayCache = [];
    }

    /**
     * A szimulált "most". 1 valós másodperc = 1 szimulált másodperc, tehát
     * a valós időhöz képest csak egy eltolás van, amit az előretekerés növel.
     */
    public static function simNow(): int
    {
        return time() + (int) self::state()['sim_offset'];
    }

    public static function dayIndex(int $ts): int
    {
        return intdiv($ts, self::DAY);
    }

    public static function dayTs(int $dayIndex): int
    {
        return $dayIndex * self::DAY;
    }

    /** Kereskedési nap-e? A részvények hétvégén állnak, a kripto nem. */
    public static function isTradingDay(string $type, int $dayIndex): bool
    {
        if ($type === 'crypto') {
            return true;
        }
        // 1970-01-01 csütörtök volt -> (dayIndex + 4) % 7 : 0=vasárnap
        $dow = ($dayIndex + 4) % 7;
        return $dow !== 0 && $dow !== 6;
    }

    /** @return array<int, array> aktív eszközök id szerint indexelve */
    public static function assets(bool $fresh = false): array
    {
        if ($fresh || self::$assetCache === null) {
            $rows = Db::all('SELECT * FROM assets WHERE active = 1 ORDER BY sort_order, id');
            $byId = [];
            foreach ($rows as $r) {
                $byId[(int) $r['id']] = $r;
            }
            self::$assetCache = $byId;
        }
        return self::$assetCache;
    }

    public static function asset(int $id): ?array
    {
        return self::assets()[$id] ?? null;
    }

    public static function assetBySymbol(string $symbol): ?array
    {
        foreach (self::assets() as $a) {
            if (strcasecmp($a['symbol'], $symbol) === 0) {
                return $a;
            }
        }
        return null;
    }

    // =================================================================
    //  1. szint — napi gyertyák
    // =================================================================

    /**
     * Legenerálja a hiányzó napi gyertyákat a szimulált mai napig.
     * Idempotens és olcsó, ha nincs mit tenni — minden kérés elején hívható.
     *
     * @return int a legenerált napok száma (a leghosszabb eszközre nézve)
     */
    public static function ensureDaily(): int
    {
        $today  = self::dayIndex(self::simNow());
        $assets = self::assets(true);
        if (!$assets) {
            return 0;
        }

        $minLast = null;
        foreach ($assets as $a) {
            $ld = (int) $a['last_day'];
            $minLast = $minLast === null ? $ld : min($minLast, $ld);
        }
        if ($minLast >= $today) {
            return 0;
        }

        $state      = self::state();
        $marketSeed = (int) $state['market_seed'];
        $cfg        = config('engine');
        $alpha      = (float) $cfg['garch_alpha'];
        $betaG      = (float) $cfg['garch_beta'];
        $maxRet     = (float) $cfg['max_daily_return'];
        $wickFactor = (float) $cfg['wick_factor'];
        $mfVol      = (float) $cfg['market_factor_vol'];
        $kappa      = (float) $cfg['eps_kurtosis'];
        $sigFloor   = (float) $cfg['sigma_floor'];
        $sigCap     = (float) $cfg['sigma_cap'];

        $candleRows = [];
        $newsRows   = [];   // [assetId|null, ts, news]
        $generated  = 0;

        // Az eszközök állapotát memóriában görgetjük, a végén írjuk vissza egyszer.
        $live = [];
        foreach ($assets as $id => $a) {
            $live[$id] = [
                'sigma' => (float) $a['cur_sigma'],
                'lastR' => (float) $a['last_return'],
                'price' => (float) $a['last_price'],
                'day'   => (int) $a['last_day'],
            ];
        }

        for ($day = $minLast + 1; $day <= $today; $day++) {
            $dayTs = self::dayTs($day);

            // --- Közös piaci faktor ---
            // A volatilitás nagyjából háromhetes "rezsimekben" mozog: ettől
            // vannak hetekig tartó nyugodt és hetekig tartó ideges szakaszok.
            $regime = Rng::range(0.55, 1.85, $marketSeed, intdiv($day, 21), 0x5E61);
            $F = Rng::fatNormal($marketSeed, $day) * $mfVol * $regime;

            $marketNews = NewsService::forMarket($marketSeed, $day);
            if ($marketNews !== null) {
                $F += $marketNews['impact'];
                $newsRows[] = [null, $dayTs, $marketNews];
            }

            foreach ($assets as $id => $a) {
                if ($live[$id]['day'] >= $day) {
                    continue;   // ennek az eszköznek már megvan ez a nap
                }
                $live[$id]['day'] = $day;

                if (!self::isTradingDay($a['type'], $day)) {
                    continue;   // hétvége: nincs gyertya, az ár marad
                }

                $seed   = (int) $a['seed'];
                $sigma0 = (float) $a['sigma0'];
                $mu     = (float) $a['mu'];
                $betaM  = (float) $a['beta'];

                // --- GARCH(1,1): a volatilitás emlékszik a tegnapra ---
                //
                // A napi hozam szórásába nemcsak ez a tag számít bele, hanem a
                // hírsokkok és a piaci faktor is. Ha a "rendes" ingadozás
                // egymaga kitenné a becsült sigma0-t, a papír a valóságosnál
                // idegesebb lenne — egy alacsony volatilitású részvénynél a
                // hírek adnák a szórás felét. Ezért a többi forrás várható
                // szórásnégyzetét levonjuk, és csak a maradékot célozzuk meg.
                $targetVar = self::diffusiveTargetVar($sigma0, $betaM, $kappa);
                $omega     = $targetVar * max(0.02, 1.0 - $alpha * $kappa - $betaG);
                $base      = sqrt($targetVar);

                $sigma = sqrt($omega
                        + $alpha * $live[$id]['lastR'] ** 2
                        + $betaG * $live[$id]['sigma'] ** 2);
                $sigma = max($base * $sigFloor, min($base * $sigCap, $sigma));

                // --- Napi hír ---
                $shock = 0.0;
                $news  = NewsService::forAsset($a, $day);
                if ($news !== null) {
                    $shock = $news['impact'];
                    $newsRows[] = [$id, $dayTs, $news];
                }

                // --- A nap log-hozama ---
                // A GARCH-visszacsatolásba csak a "rendes" ingadozás megy
                // vissza, a hírsokk nem: egy egyszeri esemény nem tehet egy
                // papírt hetekre háromszor volatilisebbé.
                $diffusive = $sigma * Rng::fatNormal($seed, $day);

                $r = $mu + $betaM * $F + $diffusive + $shock;
                $r = max(-$maxRet, min($maxRet, $r));

                $open  = $live[$id]['price'];
                $close = $open * exp($r);

                // --- Kanócok ---
                $span   = abs($r) * $wickFactor + $sigma * 0.9;
                $upExt  = $span * Rng::range(0.15, 1.0, $seed, $day, 11);
                $dnExt  = $span * Rng::range(0.15, 1.0, $seed, $day, 12);
                $high   = max($open, $close) * exp($upExt);
                $low    = min($open, $close) * exp(-$dnExt);

                $vol = self::dailyVolume($a, $r, $day);

                $candleRows[] = [$id, $dayTs, $open, $high, $low, $close, $vol, 'sim'];

                $live[$id]['sigma'] = $sigma;
                $live[$id]['lastR'] = $diffusive;
                $live[$id]['price'] = $close;
                $generated++;
            }
        }

        // --- Kiírás egyetlen tranzakcióban ---
        Db::transaction(function () use ($candleRows, $newsRows, $live) {
            Db::bulkInsert(
                'candles_1d',
                ['asset_id', 'ts', 'o', 'h', 'l', 'c', 'v', 'src'],
                $candleRows
            );

            foreach ($newsRows as [$assetId, $ts, $news]) {
                NewsService::store($assetId, $ts, $news);
            }

            $stmt = Db::pdo()->prepare(
                'UPDATE assets SET cur_sigma = ?, last_return = ?, last_price = ?, last_day = ? WHERE id = ?'
            );
            foreach ($live as $id => $s) {
                $stmt->execute([$s['sigma'], $s['lastR'], $s['price'], $s['day'], $id]);
            }
        });

        self::$assetCache = null;
        self::$dayCache   = [];

        return $generated;
    }

    /**
     * Mekkora szórásnégyzetet célozzon a "rendes" napi ingadozás, hogy a
     * teljes napi hozam realizált szórása a becsült sigma0 legyen?
     *
     *     Var(r) = Var(diffúzió) + Var(hírsokk) + beta² * Var(piaci faktor)
     *
     * Innen a diffúzió célja a maradék. A hír- és faktortagok szórásnégyzete
     * zárt alakban kijön a konfigurációból, mert mindkettő ismert eloszlású:
     * a sablonsúlyok és az erősség egyenletes eloszlásúak, az esemény pedig
     * adott valószínűséggel következik be.
     */
    private static function diffusiveTargetVar(float $sigma0, float $betaM, float $kappa): float
    {
        // E[súly²] ≈ 0.54 és E[erősség²] ≈ 0.49 az eszközhírek sablonjaira
        static $newsCoef = null;
        static $factorVar = null;

        if ($newsCoef === null) {
            $newsCoef = (float) config('news.asset_prob_per_day')
                      * (float) config('news.asset_impact') ** 2
                      * 0.54 * 0.49;

            // A piaci faktor: alapzaj a rezsimszorzóval és a vastag farokkal,
            // plusz a piaci szintű hírek.  E[rezsim²] ≈ 1.58, E[súly²]·E[erősség²] ≈ 0.31
            $factorVar = (float) config('engine.market_factor_vol') ** 2 * 1.58 * $kappa
                       + (float) config('news.market_prob_per_day')
                       * (float) config('news.market_impact') ** 2 * 0.31;
        }

        // A hírsokk nagysága az eszköz volatilitásával skálázódik
        // (lásd NewsService::forAsset)
        $volScale = min(2.0, max(0.4, $sigma0 / 0.02));
        $newsVar  = $newsCoef * $volScale ** 2;

        $total = $sigma0 * $sigma0 / $kappa;

        // A diffúzió sosem eshet a teljes szórásnégyzet negyede alá: a
        // hírek önmagukban nem vehetik át a papír mozgásának szerepét.
        return max($total * 0.25, $total - $newsVar - $betaM * $betaM * $factorVar);
    }

    /** Napi forgalom — kozmetikai, de a hozam nagyságával együtt mozog. */
    private static function dailyVolume(array $a, float $r, int $day): float
    {
        $base = Rng::range(0.6, 1.6, (int) $a['seed'], 0xF0F0);
        $base *= $a['type'] === 'crypto' ? 4_000_000 : 800_000;
        $noise = Rng::range(0.55, 1.75, (int) $a['seed'], $day, 21);
        return round($base * $noise * (1.0 + 6.0 * abs($r)));
    }

    /** Egy adott nap gyertyája (kéréseken belül memoizálva). */
    public static function dailyCandle(int $assetId, int $dayIndex): ?array
    {
        $key = $assetId . ':' . $dayIndex;
        if (!array_key_exists($key, self::$dayCache)) {
            self::$dayCache[$key] = Db::one(
                'SELECT * FROM candles_1d WHERE asset_id = ? AND ts = ?',
                [$assetId, self::dayTs($dayIndex)]
            );
        }
        return self::$dayCache[$key];
    }

    /**
     * A legutolsó olyan nap gyertyája, amelyen $dayIndex-ig volt kereskedés.
     * Hétvégén ez adja a részvények "beragadt" árát.
     */
    public static function lastCandleUpTo(int $assetId, int $dayIndex): ?array
    {
        $c = self::dailyCandle($assetId, $dayIndex);
        if ($c !== null) {
            return $c;
        }
        return Db::one(
            'SELECT * FROM candles_1d WHERE asset_id = ? AND ts <= ? ORDER BY ts DESC LIMIT 1',
            [$assetId, self::dayTs($dayIndex)]
        );
    }

    // =================================================================
    //  2. szint — napon belüli út (Brown-híd, felező eltolással)
    // =================================================================

    /**
     * A napon belüli út per-lépés volatilitása úgy hangolva, hogy a bejárt
     * tartomány nagyjából a napi minimum–maximum sávot töltse ki.
     */
    private static function intradayStepVol(array $candle): float
    {
        $logRange = log(max($candle['h'], 1e-9) / max($candle['l'], 1e-9));
        $logBody  = abs(log(max($candle['c'], 1e-9) / max($candle['o'], 1e-9)));
        $extra    = max($logRange - $logBody, $logRange * 0.35);

        // Egy Brown-híd várható kilengése ~1.2533 * s * sqrt(N). Ez csak a
        // nagyságrendet állítja be — a pontos illesztést a pathBounds()
        // szerinti ráfeszítés végzi.
        $gain = (float) config('engine.intraday_gain', 1.0);

        return max(1e-7, $extra * $gain / (1.2533 * sqrt(self::MINUTES_PER_DAY)));
    }

    /**
     * A napon belüli NYERS út szélsőértékei log-térben.
     *
     * MIÉRT KELL EZ?
     * A napi gyertya minimuma/maximuma és a napon belüli út egymástól
     * függetlenül keletkezik, ezért soha nem esnének egybe: a perces út a
     * napi sávnak csak ~70%-át járta be, vagyis a perces grafikon laposabbnak
     * látszott, mint amit ugyanarra a napra a napi gyertya mutatott. Levágni
     * sem jó megoldás: attól a percek ötöde beragadt a sáv szélére, akár
     * több órán át tartó, természetellenesen vízszintes szakaszokat hagyva.
     *
     * Ehelyett a nyers utat RÁFESZÍTJÜK a napi sávra (lásd shape()). Ehhez
     * kell a nyers út két szélsőértéke, amit egyszer számolunk ki és a napi
     * gyertya mellé eltárolunk. Így a percenkénti pontlekérdezés és a teljes
     * út legyártása továbbra is bitre ugyanazt adja.
     *
     * @return array{0: float, 1: float} [min, max] log-térben
     */
    private static function pathBounds(array $asset, int $dayIndex, array $candle): array
    {
        if ($candle['bmin'] !== null && $candle['bmax'] !== null) {
            return [(float) $candle['bmin'], (float) $candle['bmax']];
        }

        $logO = log(max((float) $candle['o'], 1e-9));
        $logC = log(max((float) $candle['c'], 1e-9));
        $dev  = self::bridgeAll((int) $asset['seed'], $dayIndex, self::intradayStepVol($candle));

        $n   = self::MINUTES_PER_DAY;
        $min = INF;
        $max = -INF;

        for ($i = 0; $i <= $n; $i++) {
            $v = $logO + ($logC - $logO) * ($i / $n) + $dev[$i];
            if ($v < $min) { $min = $v; }
            if ($v > $max) { $max = $v; }
        }

        Db::run(
            'UPDATE candles_1d SET bmin = ?, bmax = ? WHERE asset_id = ? AND ts = ?',
            [$min, $max, $asset['id'], self::dayTs($dayIndex)]
        );

        // A kéréseken belüli gyorsítótár is frissül, hogy ne kérdezzünk újra
        $key = $asset['id'] . ':' . $dayIndex;
        if (isset(self::$dayCache[$key])) {
            self::$dayCache[$key]['bmin'] = $min;
            self::$dayCache[$key]['bmax'] = $max;
        }

        return [$min, $max];
    }

    /**
     * A nyers log-árat ráfeszíti a napi minimum–maximum sávra.
     *
     * Monoton, szakaszonként lineáris leképezés:
     *   - a nyitó és záró ár közötti sávot érintetlenül hagyja (így a gyertya
     *     teste pontosan ott marad, ahol a napi gyertya mondja),
     *   - a fölötte lévő kilengéseket a napi maximumig nyújtja,
     *   - az alatta lévőket a napi minimumig.
     */
    private static function shape(float $raw, array $candle, array $bounds): float
    {
        $logO = log(max((float) $candle['o'], 1e-9));
        $logC = log(max((float) $candle['c'], 1e-9));
        $logH = log(max((float) $candle['h'], 1e-9));
        $logL = log(max((float) $candle['l'], 1e-9));

        $bodyHi = max($logO, $logC);
        $bodyLo = min($logO, $logC);

        [$rawMin, $rawMax] = $bounds;

        if ($raw > $bodyHi) {
            $span = $rawMax - $bodyHi;
            // Ha a nyers út alig ment a test fölé, a nyújtás aránya elszállna
            // és egyetlen apró rezdülésből csinálna napi csúcsot — ezért
            // korlátozzuk. Ilyenkor a nap egyszerűen nem éri el a maximumát.
            $scale = $span > 1e-9 ? min(5.0, ($logH - $bodyHi) / $span) : 0.0;
            return min($logH, $bodyHi + ($raw - $bodyHi) * $scale);
        }

        if ($raw < $bodyLo) {
            $span  = $bodyLo - $rawMin;
            $scale = $span > 1e-9 ? min(5.0, ($bodyLo - $logL) / $span) : 0.0;
            return max($logL, $bodyLo - ($bodyLo - $raw) * $scale);
        }

        return $raw;
    }

    /**
     * A híd eltérése a lineáris pályától a nap $minute. percében.
     *
     * Felező eltolás: az intervallum közepét kisorsoljuk a két végpont
     * átlagából plusz egy zajból, majd abba a félbe lépünk, amelyikben a
     * keresett perc van. Így ~11 lépésből megvan bármelyik perc értéke,
     * és pontosan ugyanaz jön ki, mint a teljes út legyártásakor.
     */
    private static function bridgeDeviation(int $seed, int $day, int $minute, float $stepVol): float
    {
        $lo = 0;
        $hi = self::MINUTES_PER_DAY;
        $dLo = 0.0;
        $dHi = 0.0;

        while ($hi - $lo > 1) {
            $mid = ($lo + $hi) >> 1;
            $len = $hi - $lo;
            $dMid = ($dLo + $dHi) / 2.0
                  + Rng::normal($seed, $day, $lo, $hi) * $stepVol * sqrt((float) $len) / 2.0;

            if ($minute <= $mid) {
                $hi = $mid;
                $dHi = $dMid;
            } else {
                $lo = $mid;
                $dLo = $dMid;
            }
        }

        return $minute <= $lo ? $dLo : $dHi;
    }

    /** Ugyanaz a rekurzió, de az egész napra kiterítve (0..1440 perc). */
    private static function bridgeAll(int $seed, int $day, float $stepVol): array
    {
        $n = self::MINUTES_PER_DAY;
        $d = array_fill(0, $n + 1, 0.0);

        // Szélességi bejárás: minden szinten felezünk
        $segments = [[0, $n]];
        while ($segments) {
            $next = [];
            foreach ($segments as [$lo, $hi]) {
                if ($hi - $lo <= 1) {
                    continue;
                }
                $mid = ($lo + $hi) >> 1;
                $len = $hi - $lo;
                $d[$mid] = ($d[$lo] + $d[$hi]) / 2.0
                         + Rng::normal($seed, $day, $lo, $hi) * $stepVol * sqrt((float) $len) / 2.0;
                $next[] = [$lo, $mid];
                $next[] = [$mid, $hi];
            }
            $segments = $next;
        }

        return $d;
    }

    /**
     * Az eszköz ára egy konkrét időpillanatban.
     * Ez az egyetlen hiteles árforrás: a lista, a grafikon és a kereskedés
     * mind ezt használja.
     */
    public static function priceAt(array $asset, int $ts): float
    {
        $assetId = (int) $asset['id'];
        $day     = self::dayIndex($ts);

        $candle = self::dailyCandle($assetId, $day);

        if ($candle === null) {
            // Hétvége vagy a történet vége: az utolsó ismert záróár érvényes
            $prev = self::lastCandleUpTo($assetId, $day);
            return $prev !== null ? (float) $prev['c'] : (float) $asset['last_price'];
        }

        $minute = intdiv($ts - self::dayTs($day), self::MIN);
        $minute = max(0, min(self::MINUTES_PER_DAY, $minute));

        $logO = log(max((float) $candle['o'], 1e-9));
        $logC = log(max((float) $candle['c'], 1e-9));

        $linear = $logO + ($logC - $logO) * ($minute / self::MINUTES_PER_DAY);
        $dev    = self::bridgeDeviation((int) $asset['seed'], $day, $minute, self::intradayStepVol($candle));

        $bounds = self::pathBounds($asset, $day, $candle);
        $price  = exp(self::shape($linear + $dev, $candle, $bounds));

        // A napi sávon kívülre nem engedjük: a gyertya marad az igazság
        return max((float) $candle['l'], min((float) $candle['h'], $price));
    }

    /** Egy eszköz teljes perces útja egy napra: [minute => ár]. */
    public static function minutePath(array $asset, int $dayIndex): array
    {
        $candle = self::dailyCandle((int) $asset['id'], $dayIndex);
        if ($candle === null) {
            return [];
        }

        $logO = log(max((float) $candle['o'], 1e-9));
        $logC = log(max((float) $candle['c'], 1e-9));
        $lo   = (float) $candle['l'];
        $hi   = (float) $candle['h'];

        $dev    = self::bridgeAll((int) $asset['seed'], $dayIndex, self::intradayStepVol($candle));
        $bounds = self::pathBounds($asset, $dayIndex, $candle);

        $n = self::MINUTES_PER_DAY;
        $path = [];
        for ($i = 0; $i <= $n; $i++) {
            $raw = $logO + ($logC - $logO) * ($i / $n) + $dev[$i];
            $p   = exp(self::shape($raw, $candle, $bounds));
            $path[$i] = max($lo, min($hi, $p));
        }

        return $path;
    }

    /**
     * Perces gyertyák biztosítása egy eszközre a megadott időszakra.
     * Lustán fut: csak arra az eszközre, amit épp néznek, és csak azokra a
     * napokra, amelyekre még nincs adat.
     */
    public static function ensureMinutes(array $asset, int $fromTs, int $toTs): void
    {
        $assetId = (int) $asset['id'];
        $fromDay = self::dayIndex($fromTs);
        $toDay   = self::dayIndex($toTs);

        for ($day = $fromDay; $day <= $toDay; $day++) {
            $dayStart = self::dayTs($day);
            $dayEnd   = $dayStart + self::DAY - self::MIN;

            $candle = self::dailyCandle($assetId, $day);
            if ($candle === null) {
                continue;   // hétvége vagy nincs még ilyen nap
            }

            // Már megvan ez a nap?
            $have = (int) Db::value(
                'SELECT COUNT(*) FROM candles_1m WHERE asset_id = ? AND ts BETWEEN ? AND ?',
                [$assetId, $dayStart, $dayEnd],
                0
            );
            $expected = self::MINUTES_PER_DAY;
            if ($have >= $expected) {
                continue;
            }

            $path = self::minutePath($asset, $day);
            if (!$path) {
                continue;
            }

            $dayVol = (float) $candle['v'] / self::MINUTES_PER_DAY;
            $seed   = (int) $asset['seed'];
            $rows   = [];

            for ($i = 0; $i < self::MINUTES_PER_DAY; $i++) {
                $o = $path[$i];
                $c = $path[$i + 1];

                // Percen belüli apró kanócok, hogy a gyertyák ne legyenek laposak
                $wick = abs($c - $o) * 0.45 + $o * 1e-4;
                $h = max($o, $c) + $wick * Rng::uniform($seed, $day, $i, 1);
                $l = min($o, $c) - $wick * Rng::uniform($seed, $day, $i, 2);

                $h = min($h, (float) $candle['h']);
                $l = max($l, (float) $candle['l']);

                $rows[] = [
                    $assetId,
                    $dayStart + $i * self::MIN,
                    $o, $h, $l, $c,
                    round($dayVol * Rng::range(0.3, 2.2, $seed, $day, $i, 3)),
                ];
            }

            Db::bulkInsert('candles_1m', ['asset_id', 'ts', 'o', 'h', 'l', 'c', 'v'], $rows);
        }
    }

    /** A megőrzési ablakon kívüli perces gyertyák törlése. */
    public static function pruneMinutes(): int
    {
        $cutoff = self::simNow() - (int) config('minute_retention_days') * self::DAY;
        return Db::run('DELETE FROM candles_1m WHERE ts < ?', [$cutoff])->rowCount();
    }

    // =================================================================
    //  Összesített lekérdezések
    // =================================================================

    /**
     * Minden aktív eszköz aktuális ára és napi változása.
     * Ez megy vissza a másodpercenkénti tick-válaszban.
     */
    public static function snapshot(?int $ts = null): array
    {
        $ts   = $ts ?? self::simNow();
        $day  = self::dayIndex($ts);
        $out  = [];

        foreach (self::assets() as $id => $a) {
            $price  = self::priceAt($a, $ts);
            $candle = self::lastCandleUpTo($id, $day);
            $open   = $candle !== null ? (float) $candle['o'] : $price;

            $out[$a['symbol']] = [
                'symbol'     => $a['symbol'],
                'price'      => $price,
                'open'       => $open,
                'change'     => $price - $open,
                'change_pct' => $open > 0 ? ($price / $open - 1.0) * 100.0 : 0.0,
                'day_high'   => $candle !== null ? max((float) $candle['l'], (float) $candle['h']) : $price,
                'day_low'    => $candle !== null ? (float) $candle['l'] : $price,
            ];
        }

        return $out;
    }
}
