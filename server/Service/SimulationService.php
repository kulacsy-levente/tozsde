<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;
use RuntimeException;

/**
 * Előretekerés: 1 óra / 1 nap / 1 hét / 1 hónap / 1 év.
 *
 * Élesben tekeri előre az időt — a nyitott pozíciók elszámolódnak, a
 * likvidációk lefutnak, és ez lesz az új jelen.
 *
 * KÉT FELBONTÁS
 * -------------
 * A likvidálást nem elég a végállapotban ellenőrizni: ha a papír közben
 * mélyrepülésbe megy, majd a végére visszajön, a pozíciód a valóságban már rég
 * megszűnt volna. Ezért mindig végigsétálunk az eltelt időn — csak nem mindig
 * ugyanolyan lépésben:
 *
 *   - **Rövid ugrás** (legfeljebb annyi, amennyire perces gyertyát tartunk):
 *     percről percre. Így az órán belüli mozgás is számít, és az értékgörbe
 *     is megtelik pontokkal — látszik, mit csinált a piac az ugrás alatt.
 *   - **Hosszú ugrás**: napról napra, a napi minimum/maximum ellen. Egy évre
 *     525 600 perc végigjárása értelmetlen lenne, ráadásul perces gyertyát
 *     úgysem tartunk ennyi időre visszamenőleg.
 */
final class SimulationService
{
    /** Az értékgörbére legfeljebb ennyi pontot írunk egy rövid ugrás alatt. */
    private const MAX_EQUITY_POINTS = 240;

    /**
     * @param string $horizon 1h | 1d | 1w | 1m | 1y
     */
    public static function fastForward(int $accountId, string $horizon): array
    {
        $horizons = config('horizons');
        if (!isset($horizons[$horizon])) {
            throw new RuntimeException('Ismeretlen időtáv: ' . $horizon);
        }

        $seconds = (int) $horizons[$horizon];
        $started = microtime(true);
        $fromTs  = PriceEngine::simNow();

        // --- 1. Az óra előretekerése és a napi gyertyák legenerálása ---
        Db::run('UPDATE market_state SET sim_offset = sim_offset + ? WHERE id = 1', [$seconds]);
        PriceEngine::forgetState();
        PriceEngine::ensureDaily();

        $toTs = PriceEngine::simNow();

        // --- 2. Végigsétálás az eltelt időn ---
        $retention = (int) config('minute_retention_days') * PriceEngine::DAY;
        $fine      = $seconds <= $retention;

        $liquidations = $fine
            ? self::walkMinutes($accountId, $fromTs, $toTs)
            : self::walkDays($accountId, PriceEngine::dayIndex($fromTs), PriceEngine::dayIndex($toTs));

        // --- 3. A perces ablak igazítása az új jelenhez ---
        PriceEngine::pruneMinutes();

        PortfolioService::recordEquity($accountId, null, $toTs);

        return [
            'horizon'      => $horizon,
            'from'         => $fromTs,
            'to'           => $toTs,
            'days'         => PriceEngine::dayIndex($toTs) - PriceEngine::dayIndex($fromTs),
            'seconds'      => $seconds,
            'resolution'   => $fine ? 'minute' : 'day',
            'liquidations' => $liquidations,
            'news'         => NewsService::recent($toTs, 40),
            'elapsed_ms'   => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    // =================================================================
    //  Rövid ugrás — percről percre
    // =================================================================

    private static function walkMinutes(int $accountId, int $fromTs, int $toTs): array
    {
        $positions = Db::all(
            'SELECT * FROM positions WHERE account_id = ? AND status = ?',
            [$accountId, 'open']
        );

        $first = intdiv($fromTs, PriceEngine::MIN) * PriceEngine::MIN + PriceEngine::MIN;
        $last  = intdiv($toTs,   PriceEngine::MIN) * PriceEngine::MIN;
        if ($last < $first) {
            return [];
        }

        $minutes = intdiv($last - $first, PriceEngine::MIN) + 1;
        $stride  = max(1, (int) ceil($minutes / self::MAX_EQUITY_POINTS));

        // --- A szükséges perces gyertyák előállítása és beolvasása ---
        $candles = [];   // assetId => [ts => [h, l, c]]
        foreach ($positions as $p) {
            $assetId = (int) $p['asset_id'];
            if (isset($candles[$assetId])) {
                continue;
            }

            $asset = PriceEngine::asset($assetId);
            if ($asset === null) {
                continue;
            }

            PriceEngine::ensureMinutes($asset, $fromTs, $toTs);

            $candles[$assetId] = [];
            foreach (Db::all(
                'SELECT ts, h, l, c FROM candles_1m WHERE asset_id = ? AND ts BETWEEN ? AND ? ORDER BY ts',
                [$assetId, $first, $last]
            ) as $row) {
                $candles[$assetId][(int) $row['ts']] = [
                    'h' => (float) $row['h'],
                    'l' => (float) $row['l'],
                    'c' => (float) $row['c'],
                ];
            }
        }

        $cash         = (float) Db::value('SELECT cash_huf FROM accounts WHERE id = ?', [$accountId], 0.0);
        $open         = $positions;
        $liquidations = [];
        $equityRows   = [];
        $step         = 0;

        for ($ts = $first; $ts <= $last; $ts += PriceEngine::MIN, $step++) {
            // --- Likvidációk ebben a percben ---
            foreach ($open as $key => $position) {
                $assetId = (int) $position['asset_id'];
                $candle  = $candles[$assetId][$ts] ?? null;
                if ($candle === null) {
                    continue;   // hétvégén a részvényeknek nincs perces gyertyája
                }

                $liq = (float) $position['liq_price'];
                $hit = $position['side'] === 'long'
                    ? $candle['l'] <= $liq
                    : $candle['h'] >= $liq;

                if ($hit) {
                    $asset  = PriceEngine::asset($assetId);
                    $result = TradeService::liquidate($accountId, $position, $asset, $liq, $ts);

                    $result['date'] = date('Y-m-d H:i', $ts);
                    $liquidations[] = $result;

                    unset($open[$key]);
                    $cash = (float) Db::value('SELECT cash_huf FROM accounts WHERE id = ?', [$accountId], 0.0);
                }
            }

            // --- Az értékgörbe pontja ---
            if ($step % $stride === 0 || $ts === $last) {
                $equity = $cash;
                foreach ($open as $position) {
                    $candle = $candles[(int) $position['asset_id']][$ts] ?? null;
                    $price  = $candle !== null ? $candle['c'] : (float) $position['avg_price'];
                    $equity += (float) $position['margin_huf'] + TradeService::unrealized($position, $price);
                }
                $equityRows[] = [$accountId, $ts, $equity];
            }
        }

        if ($equityRows) {
            Db::bulkInsert('equity_snapshots', ['account_id', 'ts', 'equity_huf'], $equityRows);
        }

        return $liquidations;
    }

    // =================================================================
    //  Hosszú ugrás — napról napra
    // =================================================================

    private static function walkDays(int $accountId, int $fromDay, int $toDay): array
    {
        $liquidations = [];
        $equityRows   = [];

        $hasPositions = Db::value(
            'SELECT COUNT(*) FROM positions WHERE account_id = ? AND status = ?',
            [$accountId, 'open'],
            0
        ) > 0;

        for ($day = $fromDay + 1; $day <= $toDay; $day++) {
            $dayTs = PriceEngine::dayTs($day);

            if ($hasPositions) {
                $open = Db::all(
                    'SELECT * FROM positions WHERE account_id = ? AND status = ?',
                    [$accountId, 'open']
                );

                foreach ($open as $position) {
                    $asset = PriceEngine::asset((int) $position['asset_id']);
                    if ($asset === null) {
                        continue;
                    }

                    $candle = PriceEngine::dailyCandle((int) $position['asset_id'], $day);
                    if ($candle === null) {
                        continue;   // hétvége: nem történik semmi
                    }

                    $liq = (float) $position['liq_price'];
                    $hit = $position['side'] === 'long'
                        ? (float) $candle['l'] <= $liq
                        : (float) $candle['h'] >= $liq;

                    if ($hit) {
                        $result = TradeService::liquidate($accountId, $position, $asset, $liq, $dayTs);
                        $result['date'] = date('Y-m-d', $dayTs);
                        $liquidations[] = $result;
                    }
                }
            }

            $equityRows[] = [$accountId, $dayTs, self::equityAt($accountId, $day)];
        }

        if ($equityRows) {
            Db::bulkInsert('equity_snapshots', ['account_id', 'ts', 'equity_huf'], $equityRows);
        }

        return $liquidations;
    }

    /** A fiók vagyona egy adott nap záróárain. */
    private static function equityAt(int $accountId, int $dayIndex): float
    {
        $cash = (float) Db::value('SELECT cash_huf FROM accounts WHERE id = ?', [$accountId], 0.0);

        $rows = Db::all(
            'SELECT * FROM positions WHERE account_id = ? AND status = ?',
            [$accountId, 'open']
        );

        foreach ($rows as $row) {
            $candle = PriceEngine::lastCandleUpTo((int) $row['asset_id'], $dayIndex);
            $price  = $candle !== null ? (float) $candle['c'] : (float) $row['avg_price'];
            $cash  += (float) $row['margin_huf'] + TradeService::unrealized($row, $price);
        }

        return $cash;
    }
}
