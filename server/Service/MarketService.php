<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;
use RuntimeException;

/**
 * A piaci adatok kiszolgálása a felület felé:
 * eszközlista, gyertyák, hírmarkerek.
 */
final class MarketService
{
    /**
     * Az eszközlista árral, napi változással és mini árgörbével.
     * Ez megy a bal oldali panelbe.
     */
    public static function assets(?array $snapshot = null): array
    {
        $now      = PriceEngine::simNow();
        $snapshot = $snapshot ?? PriceEngine::snapshot($now);
        $assets   = PriceEngine::assets();

        // A sparkline-okat egyetlen lekérdezéssel hozzuk le mind az 50
        // eszközre, nem ötven különállóval.
        $since  = PriceEngine::dayTs(PriceEngine::dayIndex($now) - 45);
        $spark  = [];
        foreach (Db::all('SELECT asset_id, c FROM candles_1d WHERE ts >= ? ORDER BY asset_id, ts', [$since]) as $r) {
            $spark[(int) $r['asset_id']][] = round((float) $r['c'], 4);
        }

        $out = [];
        foreach ($assets as $id => $a) {
            $s = $snapshot[$a['symbol']] ?? null;
            $series = array_slice($spark[$id] ?? [], -30);

            $out[] = [
                'symbol'      => $a['symbol'],
                'name'        => $a['name'],
                'type'        => $a['type'],
                'market'      => $a['market'],
                'decimals'    => (int) $a['decimals'],
                'min_qty'     => (float) $a['min_qty'],
                'qty_step'    => (float) $a['qty_step'],
                'data_source' => $a['data_source'],
                'price'       => $s ? $s['price'] : (float) $a['last_price'],
                'change_pct'  => $s ? $s['change_pct'] : 0.0,
                'spark'       => $series,
            ];
        }

        return $out;
    }

    /**
     * Gyertyák a grafikonhoz.
     *
     * @param string $tf '1m' vagy '1d'
     * @return array{candles:array, volume:array, markers:array, meta:array}
     */
    public static function candles(string $symbol, string $tf = '1m', int $limit = 1500): array
    {
        $asset = PriceEngine::assetBySymbol($symbol);
        if ($asset === null) {
            throw new RuntimeException('Nincs ilyen eszköz: ' . $symbol);
        }

        $now   = PriceEngine::simNow();
        $limit = max(50, min(5000, $limit));

        if ($tf === '1m') {
            // A perces gyertya csak a megőrzési ablakra létezik, és lustán,
            // az első megnyitáskor generálódik le erre az eszközre.
            $from = $now - (int) config('minute_retention_days') * PriceEngine::DAY;
            PriceEngine::ensureMinutes($asset, $from, $now);

            $rows = Db::all(
                'SELECT ts, o, h, l, c, v FROM candles_1m
                 WHERE asset_id = ? AND ts BETWEEN ? AND ?
                 ORDER BY ts',
                [$asset['id'], $from, $now]
            );
        } else {
            $rows = Db::all(
                'SELECT ts, o, h, l, c, v FROM candles_1d
                 WHERE asset_id = ? AND ts <= ?
                 ORDER BY ts DESC LIMIT ?',
                [$asset['id'], $now, $limit]
            );
            $rows = array_reverse($rows);
        }

        $candles = [];
        $volume  = [];

        foreach ($rows as $r) {
            $t = (int) $r['ts'];
            $o = (float) $r['o'];
            $c = (float) $r['c'];

            $candles[] = [
                'time'  => $t,
                'open'  => $o,
                'high'  => (float) $r['h'],
                'low'   => (float) $r['l'],
                'close' => $c,
            ];
            $volume[] = [
                'time'  => $t,
                'value' => (float) $r['v'],
                'color' => $c >= $o ? 'rgba(38,166,154,0.45)' : 'rgba(239,83,80,0.45)',
            ];
        }

        // A perces nézetben az utolsó gyertya még "él": a mostani másodperc
        // szerinti árral zárjuk, hogy a grafikon jobb széle valóban mozogjon.
        if ($tf === '1m' && $candles) {
            $last  = count($candles) - 1;
            $price = PriceEngine::priceAt($asset, $now);
            $candles[$last]['close'] = $price;
            $candles[$last]['high']  = max($candles[$last]['high'], $price);
            $candles[$last]['low']   = min($candles[$last]['low'], $price);
        }

        return [
            'candles' => $candles,
            'volume'  => $volume,
            'markers' => self::markers($asset, $candles ? $candles[0]['time'] : $now, $now, $tf),
            'meta'    => [
                'symbol'      => $asset['symbol'],
                'name'        => $asset['name'],
                'type'        => $asset['type'],
                'market'      => $asset['market'],
                'decimals'    => (int) $asset['decimals'],
                'min_qty'     => (float) $asset['min_qty'],
                'qty_step'    => (float) $asset['qty_step'],
                'data_source' => $asset['data_source'],
                'fee'         => TradeService::feeRate($asset),
                'spread'      => (float) config('spread.' . $asset['type']),
                'quote'       => TradeService::quote($asset),
                'timeframe'   => $tf,
                'sim_now'     => $now,
                // Az importált valós múlt és a szimulált jövő határa
                'epoch_start' => (int) PriceEngine::state()['epoch_start'],
            ],
        ];
    }

    /**
     * Hírmarkerek a grafikonra.
     * A napi nézetben a nap időbélyegére, a percesben a nap kezdetére kerülnek.
     */
    private static function markers(array $asset, int $from, int $to, string $tf): array
    {
        $rows = Db::all(
            'SELECT ts, headline, impact_pct, severity, asset_id
             FROM news
             WHERE ts BETWEEN ? AND ? AND (asset_id = ? OR (asset_id IS NULL AND severity = ?))
             ORDER BY ts',
            [$from, $to, $asset['id'], 'major']
        );

        $out = [];
        foreach ($rows as $r) {
            $up = (float) $r['impact_pct'] >= 0;

            $out[] = [
                'time'     => (int) $r['ts'],
                'position' => $up ? 'belowBar' : 'aboveBar',
                'color'    => $up ? '#26a69a' : '#ef5350',
                'shape'    => $up ? 'arrowUp' : 'arrowDown',
                'text'     => sprintf('%+.1f%%', (float) $r['impact_pct']),
                'headline' => $r['headline'],
            ];
        }

        return $out;
    }

    /**
     * A másodpercenkénti tick: minden ár, a szimulált idő, és a nézett
     * eszköz élő gyertyája.
     */
    public static function tick(?string $symbol = null): array
    {
        PriceEngine::ensureDaily();

        $now      = PriceEngine::simNow();
        $snapshot = PriceEngine::snapshot($now);

        $live = null;
        if ($symbol !== null && $symbol !== '') {
            $asset = PriceEngine::assetBySymbol($symbol);
            if ($asset !== null) {
                $minuteTs = intdiv($now, PriceEngine::MIN) * PriceEngine::MIN;
                $price    = PriceEngine::priceAt($asset, $now);

                $candle = Db::one(
                    'SELECT ts, o, h, l, c, v FROM candles_1m WHERE asset_id = ? AND ts = ?',
                    [$asset['id'], $minuteTs]
                );

                if ($candle !== null) {
                    $live = [
                        'time'  => $minuteTs,
                        'open'  => (float) $candle['o'],
                        'high'  => max((float) $candle['h'], $price),
                        'low'   => min((float) $candle['l'], $price),
                        'close' => $price,
                    ];
                } else {
                    // Új nap első percei: a gyertya még nincs legenerálva
                    $live = [
                        'time'  => $minuteTs,
                        'open'  => $price,
                        'high'  => $price,
                        'low'   => $price,
                        'close' => $price,
                    ];
                }
            }
        }

        return [
            'sim_now' => $now,
            'prices'  => $snapshot,
            'live'    => $live,
        ];
    }
}
