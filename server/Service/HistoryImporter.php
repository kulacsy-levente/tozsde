<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;
use App\Core\Http;

/**
 * Valós historikus árfolyamok letöltése az első indításkor.
 *
 * Két publikus, kulcs nélküli forrás:
 *   - kripto:   Binance klines (napi gyertyák)
 *   - részvény: Yahoo Finance chart API (napi gyertyák, a pénznemmel együtt)
 *
 * A hibatűrés eszközönkénti: ha egy papír nem jön le, csak az az egy vált
 * szintetikus történetre, a többi valós adattal indul. Ha egyáltalán nincs
 * internet, az egész készlet szintetikusra vált, és a program ettől még
 * ugyanúgy működik — csak a kiinduló árak lesznek közelítőek.
 */
final class HistoryImporter
{
    private const BINANCE_HOSTS = [
        'https://data-api.binance.vision',
        'https://api.binance.com',
        'https://api1.binance.com',
    ];

    private const YAHOO_HOSTS = [
        'https://query1.finance.yahoo.com',
        'https://query2.finance.yahoo.com',
    ];

    /** @var callable|null haladásjelző visszahívás */
    private $progress;

    private int $historyDays;

    /** @var array<string, array<int, float>> szimbólum => [nap => log-hozam], a béta becsléshez */
    private array $returnSeries = [];

    public function __construct(?callable $progress = null)
    {
        $this->progress    = $progress;
        $this->historyDays = (int) config('history_days');
    }

    private function say(string $message, string $level = 'info'): void
    {
        if ($this->progress !== null) {
            ($this->progress)($message, $level);
        }
    }

    /** Átváltási szorzó forintra. */
    private function fx(string $currency): float
    {
        switch (strtoupper($currency)) {
            case 'HUF': return 1.0;
            case 'EUR': return (float) config('eur_huf');
            case 'USD':
            default:    return (float) config('usd_huf');
        }
    }

    // =================================================================
    //  Fő belépési pont
    // =================================================================

    /**
     * Az eszközök létrehozása és a történet betöltése.
     *
     * @return array{real:int, synthetic:int, last_day:int}
     */
    public function run(): array
    {
        $definitions = require SERVER_ROOT . '/Data/assets.php';

        $this->say('Kapcsolat ellenőrzése...');
        $online = $this->checkConnectivity();
        $this->say($online
            ? 'Van internetkapcsolat, valós adatokat töltünk le.'
            : 'Nincs elérhető adatforrás — minden eszköz generált történetet kap.',
            $online ? 'ok' : 'warn');

        $realCount   = 0;
        $synthCount  = 0;
        $lastRealDay = 0;
        $assetIds    = [];

        foreach ($definitions as $order => $def) {
            [$symbol, $name, $type, $market, $currency, $sourceRef, , $sigma, $beta, $decimals] = $def;

            $assetId = $this->upsertAsset([
                'symbol'       => $symbol,
                'name'         => $name,
                'type'         => $type,
                'market'       => $market,
                'currency_src' => $currency,
                'source_ref'   => $sourceRef,
                'sigma0'       => $sigma,
                'beta'         => $beta,
                'decimals'     => $decimals,
                'sort_order'   => $order,
            ]);
            $assetIds[$symbol] = $assetId;

            $fetched = null;
            if ($online) {
                $fetched = $type === 'crypto'
                    ? $this->fetchBinance($sourceRef)
                    : $this->fetchYahoo($sourceRef);
            }

            if ($fetched !== null && count($fetched['candles']) >= 60) {
                $this->storeRealSeries($assetId, $symbol, $fetched['candles'], $fetched['currency']);
                $realCount++;
                $lastRealDay = max($lastRealDay, PriceEngine::dayIndex((int) array_key_last($fetched['candles'])));
                $this->say(sprintf(
                    '%-7s %-24s valós adat — %d nap, %s',
                    $symbol,
                    $name,
                    count($fetched['candles']),
                    $fetched['currency']
                ), 'ok');
            } else {
                $synthCount++;
                $this->say(sprintf('%-7s %-24s generált történet', $symbol, $name), 'warn');
            }
        }

        // Ha semmi valós adat nincs, a tegnapi naptól indulunk visszafelé
        if ($lastRealDay === 0) {
            $lastRealDay = PriceEngine::dayIndex(time()) - 1;
        }

        // A szintetikus eszközöket a történet elejére állítjuk: a múltjukat
        // ugyanaz a motor generálja le, ami a jövőt is fogja.
        $this->seedSyntheticAssets($definitions, $assetIds, $lastRealDay);

        if ($this->returnSeries) {
            $this->estimateBetas($assetIds);
        }

        return [
            'real'      => $realCount,
            'synthetic' => $synthCount,
            'last_day'  => $lastRealDay,
        ];
    }

    // =================================================================
    //  Eszközök
    // =================================================================

    private function upsertAsset(array $a): int
    {
        $existing = Db::one('SELECT id FROM assets WHERE symbol = ?', [$a['symbol']]);

        $data = [
            'name'         => $a['name'],
            'type'         => $a['type'],
            'market'       => $a['market'],
            'currency_src' => $a['currency_src'],
            'source_ref'   => $a['source_ref'],
            'sigma0'       => $a['sigma0'],
            'cur_sigma'    => $a['sigma0'],
            'beta'         => $a['beta'],
            'decimals'     => $a['decimals'],
            'sort_order'   => $a['sort_order'],
            'min_qty'      => $a['type'] === 'crypto' ? 0.0001 : 1.0,
            'qty_step'     => $a['type'] === 'crypto' ? 0.0001 : 1.0,
        ];

        if ($existing !== null) {
            Db::update('assets', $data, 'id = :id', ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        $data['symbol'] = $a['symbol'];
        $data['seed']   = Rng::newSeed();
        return Db::insert('assets', $data);
    }

    /**
     * A valós sorozat elmentése napi gyertyaként, forintra váltva.
     *
     * @param array<int, array{o:float,h:float,l:float,c:float,v:float}> $series nap-időbélyeg => OHLCV
     */
    private function storeRealSeries(int $assetId, string $symbol, array $series, string $currency): void
    {
        $fx = $this->fx($currency);

        $rows      = [];
        $returns   = [];
        $prevClose = null;

        // A kevéssé forgalmazott papírok (több BÉT-es kispapír) napjainak
        // többségén a maximum pontosan egyenlő a nyitó- vagy záróárral: nincs
        // kanóc, mert alig kötöttek aznap. Ez a napi gyertyán rendben van, a
        // napon belüli útnak viszont nem hagy hova mennie, és a perces
        // grafikon vízszintes vonallá válna. Ezért minimális kanócot
        // garantálunk — 0,15%, vagyis a napi gyertya képén észrevehetetlen.
        $minWick = 0.0015;

        foreach ($series as $ts => $c) {
            $open  = $c['o'] * $fx;
            $close = $c['c'] * $fx;
            $high  = $c['h'] * $fx;
            $low   = $c['l'] * $fx;

            $bodyHi = max($open, $close);
            $bodyLo = min($open, $close);

            $high = max($high, $bodyHi * (1 + $minWick));
            $low  = min($low,  max(1e-9, $bodyLo * (1 - $minWick)));

            $rows[] = [$assetId, $ts, $open, $high, $low, $close, $c['v'], 'real'];

            if ($prevClose !== null && $prevClose > 0) {
                $returns[PriceEngine::dayIndex($ts)] = log($close / $prevClose);
            }
            $prevClose = $close;
        }

        Db::bulkInsert('candles_1d', ['asset_id', 'ts', 'o', 'h', 'l', 'c', 'v', 'src'], $rows);

        $this->returnSeries[$symbol] = $returns;

        // A szimuláció paramétereinek becslése a valós adatból
        $values = array_values($returns);
        $mean   = $values ? array_sum($values) / count($values) : 0.0;
        $sigma  = $this->stddev($values, $mean);

        // A drift zsugorítása a nulla felé.
        //
        // Két év hozamából a várható hozamot megbecsülni statisztikailag
        // reménytelen: a zaj sokszorosa a jelnek. Ha viszont a becslést
        // nyersen átvennénk, a múltbeli nyertesek örökre szárnyalnának, a
        // vesztesek pedig a nullába tartanának — ami se nem valósághű, se
        // nem érdekes játék. Ezért a becslés harmadát vesszük csak át, és
        // napi ±0,06%-ban (kb. évi ±25%) maximáljuk.
        $mu = max(-0.0006, min(0.0006, $mean * 0.35));

        $lastTs    = (int) array_key_last($series);
        $lastClose = (float) $series[$lastTs]['c'] * $fx;

        // Volatilitás-padló. A ritkán forgó papíroknál a napok többségén nulla
        // a hozam, így a becsült szórás gyakorlatilag nulla lenne — a papír
        // örökre mozdulatlan maradna, és értelmetlen lenne kereskedni vele.
        // A padló épp annyi, hogy legyen mit nézni rajta.
        $sigma = max($sigma, 0.006);

        Db::update('assets', [
            'data_source' => 'real',
            'currency_src' => strtoupper($currency),
            'mu'          => $mu,
            'sigma0'      => $sigma,
            'cur_sigma'   => $sigma,
            'last_return' => $values ? end($values) : 0.0,
            'last_price'  => $lastClose,
            'last_day'    => PriceEngine::dayIndex($lastTs),
        ], 'id = :id', ['id' => $assetId]);
    }

    /**
     * A szintetikus eszközök beállítása: a történet elejére tesszük őket egy
     * kiinduló árral, a többit a PriceEngine generálja ugyanazzal a modellel,
     * amit a jövőre is használ.
     */
    private function seedSyntheticAssets(array $definitions, array $assetIds, int $lastRealDay): void
    {
        $startDay = $lastRealDay - $this->historyDays;

        foreach ($definitions as $def) {
            [$symbol, , , , $currency, , $refPrice, $sigma, , ] = $def;
            $assetId = $assetIds[$symbol];

            $row = Db::one('SELECT data_source, seed FROM assets WHERE id = ?', [$assetId]);
            if ($row === null || $row['data_source'] === 'real') {
                continue;
            }

            $seed = (int) $row['seed'];

            // A referenciaár a történet *végére* vonatkozik, ezért a kiindulás
            // lejjebb kerül, hogy a generált pálya nagyjából oda érkezzen.
            // A szórás miatt persze bárhova kilyukadhat — ez így életszerű.
            $startPrice = $refPrice * $this->fx($currency) * Rng::range(0.55, 0.95, $seed, 0xA11);

            Db::update('assets', [
                'data_source' => 'synthetic',
                'mu'          => Rng::range(0.0000, 0.0009, $seed, 0xA12),
                'sigma0'      => $sigma,
                'cur_sigma'   => $sigma,
                'last_return' => 0.0,
                'last_price'  => $startPrice,
                'last_day'    => $startDay,
            ], 'id = :id', ['id' => $assetId]);
        }
    }

    /**
     * Béta becslése: mennyire mozog együtt a papír az egész piaccal.
     * A "piac" itt az összes letöltött sorozat napi átlaghozama.
     */
    private function estimateBetas(array $assetIds): void
    {
        $sum = [];
        $cnt = [];
        foreach ($this->returnSeries as $returns) {
            foreach ($returns as $day => $r) {
                $sum[$day] = ($sum[$day] ?? 0.0) + $r;
                $cnt[$day] = ($cnt[$day] ?? 0) + 1;
            }
        }

        $market = [];
        foreach ($sum as $day => $s) {
            if ($cnt[$day] >= 5) {
                $market[$day] = $s / $cnt[$day];
            }
        }
        if (count($market) < 60) {
            return;
        }

        $mMean = array_sum($market) / count($market);
        $mVar  = 0.0;
        foreach ($market as $r) {
            $mVar += ($r - $mMean) ** 2;
        }
        $mVar /= max(1, count($market) - 1);
        if ($mVar < 1e-12) {
            return;
        }

        foreach ($this->returnSeries as $symbol => $returns) {
            $pairs = [];
            foreach ($returns as $day => $r) {
                if (isset($market[$day])) {
                    $pairs[] = [$r, $market[$day]];
                }
            }
            if (count($pairs) < 60) {
                continue;
            }

            $aMean = array_sum(array_column($pairs, 0)) / count($pairs);
            $cov   = 0.0;
            foreach ($pairs as [$ra, $rm]) {
                $cov += ($ra - $aMean) * ($rm - $mMean);
            }
            $cov /= max(1, count($pairs) - 1);

            // Ésszerű sávba szorítva, hogy egy fura sorozat ne csináljon
            // 6-os bétájú papírt
            $beta = max(0.2, min(2.2, $cov / $mVar));

            Db::update('assets', ['beta' => $beta], 'id = :id', ['id' => $assetIds[$symbol]]);
        }
    }

    private function stddev(array $values, float $mean): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return sqrt($sum / ($n - 1));
    }

    // =================================================================
    //  Adatforrások
    // =================================================================

    private function checkConnectivity(): bool
    {
        foreach (self::BINANCE_HOSTS as $host) {
            if (Http::get($host . '/api/v3/ping', 6) !== null) {
                return true;
            }
        }
        foreach (self::YAHOO_HOSTS as $host) {
            if (Http::get($host . '/v8/finance/chart/AAPL?range=5d&interval=1d', 10) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Binance napi gyertyák.
     * @return array{currency:string, candles:array<int, array>}|null
     */
    private function fetchBinance(string $pair): ?array
    {
        $limit = min(1000, $this->historyDays);

        foreach (self::BINANCE_HOSTS as $host) {
            $body = Http::get("$host/api/v3/klines?symbol=" . urlencode($pair) . "&interval=1d&limit=$limit", 25);
            if ($body === null) {
                continue;
            }

            $data = json_decode($body, true);
            if (!is_array($data) || !$data || !is_array($data[0] ?? null)) {
                continue;
            }

            $out = [];
            foreach ($data as $k) {
                // [0]=nyitó idő ms, [1]=o, [2]=h, [3]=l, [4]=c, [5]=volumen
                $ts = PriceEngine::dayTs(PriceEngine::dayIndex(intdiv((int) $k[0], 1000)));
                $out[$ts] = [
                    'o' => (float) $k[1],
                    'h' => (float) $k[2],
                    'l' => (float) $k[3],
                    'c' => (float) $k[4],
                    'v' => (float) $k[5],
                ];
            }

            ksort($out);
            // A Binance USDT-ben jegyez, amit dollárnak veszünk (1 USDT ≈ 1 USD)
            return $out ? ['currency' => 'USD', 'candles' => $out] : null;
        }

        return null;
    }

    /**
     * Yahoo Finance chart API. A választ a pénznemmel együtt adja vissza,
     * így a magyar (HUF) és az euróban jegyzett papírokat is jól váltjuk át.
     *
     * @return array{currency:string, candles:array<int, array>}|null
     */
    private function fetchYahoo(string $ticker): ?array
    {
        $range = $this->historyDays > 365 ? '2y' : '1y';

        foreach (self::YAHOO_HOSTS as $host) {
            $url  = "$host/v8/finance/chart/" . rawurlencode($ticker) . "?range=$range&interval=1d";
            $body = Http::get($url, 25);
            if ($body === null) {
                continue;
            }

            $json   = json_decode($body, true);
            $result = $json['chart']['result'][0] ?? null;
            if (!is_array($result)) {
                continue;
            }

            $stamps = $result['timestamp'] ?? null;
            $quote  = $result['indicators']['quote'][0] ?? null;
            if (!is_array($stamps) || !is_array($quote)) {
                continue;
            }

            $currency = strtoupper((string) ($result['meta']['currency'] ?? 'USD'));
            $out = [];

            foreach ($stamps as $i => $ts) {
                $c = $quote['close'][$i] ?? null;
                // A Yahoo ünnepnapokra és hiányzó adatra null-t ad — ezeket kihagyjuk
                if ($c === null || (float) $c <= 0) {
                    continue;
                }

                $o = $quote['open'][$i]  ?? $c;
                $h = $quote['high'][$i]  ?? $c;
                $l = $quote['low'][$i]   ?? $c;
                $v = $quote['volume'][$i] ?? 0;

                $day = PriceEngine::dayTs(PriceEngine::dayIndex((int) $ts));
                $out[$day] = [
                    'o' => (float) ($o ?: $c),
                    'h' => (float) ($h ?: $c),
                    'l' => (float) ($l ?: $c),
                    'c' => (float) $c,
                    'v' => (float) ($v ?: 0),
                ];
            }

            ksort($out);

            if (count($out) > $this->historyDays) {
                $out = array_slice($out, -$this->historyDays, null, true);
            }

            if (count($out) >= 60) {
                return ['currency' => $currency, 'candles' => $out];
            }
        }

        return null;
    }
}
