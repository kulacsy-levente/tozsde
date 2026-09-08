<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;

/**
 * A portfólió pillanatképe és értékgörbéje.
 *
 * A teljes vagyon három részből áll:
 *   szabad készpénz + lekötött letét + lebegő eredmény
 */
final class PortfolioService
{
    /**
     * Teljes pillanatkép a fiókról.
     *
     * @param array|null $snapshot előre kiszámolt árak (a tick-válaszból),
     *                             hogy ne kelljen kétszer számolni
     */
    public static function snapshot(int $accountId, ?array $snapshot = null): array
    {
        $account = Db::one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
        if ($account === null) {
            throw new \RuntimeException('Nincs ilyen fiók.');
        }

        $now      = PriceEngine::simNow();
        $snapshot = $snapshot ?? PriceEngine::snapshot($now);

        $positions   = [];
        $totalMargin = 0.0;
        $totalPnl    = 0.0;

        $rows = Db::all(
            'SELECT p.*, a.symbol, a.name, a.type, a.market, a.decimals
             FROM positions p
             JOIN assets a ON a.id = p.asset_id
             WHERE p.account_id = ? AND p.status = ?
             ORDER BY p.opened_at DESC',
            [$accountId, 'open']
        );

        foreach ($rows as $row) {
            $price = $snapshot[$row['symbol']]['price'] ?? (float) $row['avg_price'];
            $pnl   = TradeService::unrealized($row, $price);

            $margin   = (float) $row['margin_huf'];
            $notional = (float) $row['qty'] * $price;

            $totalMargin += $margin;
            $totalPnl    += $pnl;

            $positions[] = [
                'id'          => (int) $row['id'],
                'symbol'      => $row['symbol'],
                'name'        => $row['name'],
                'type'        => $row['type'],
                'market'      => $row['market'],
                'decimals'    => (int) $row['decimals'],
                'side'        => $row['side'],
                'qty'         => (float) $row['qty'],
                'avg_price'   => (float) $row['avg_price'],
                'price'       => $price,
                'leverage'    => (float) $row['leverage'],
                'margin'      => $margin,
                'notional'    => $notional,
                'liq_price'   => (float) $row['liq_price'],
                'pnl'         => $pnl,
                // A megtérülést a LETÉTHEZ mérjük, nem a pozíció értékéhez:
                // tőkeáttéttel ez mutatja meg, mit csinál a saját pénzed.
                'pnl_pct'     => $margin > 0 ? $pnl / $margin * 100.0 : 0.0,
                'opened_at'   => (int) $row['opened_at'],
            ];
        }

        $cash    = (float) $account['cash_huf'];
        $equity  = $cash + $totalMargin + $totalPnl;
        $initial = (float) $account['initial_capital'];

        return [
            'account' => [
                'id'              => (int) $account['id'],
                'name'            => $account['name'],
                'initial_capital' => $initial,
            ],
            'cash'          => $cash,
            'margin_used'   => $totalMargin,
            'unrealized'    => $totalPnl,
            'equity'        => $equity,
            'total_pnl'     => $equity - $initial,
            'total_pnl_pct' => $initial > 0 ? ($equity / $initial - 1.0) * 100.0 : 0.0,
            'positions'     => $positions,
            'sim_now'       => $now,
        ];
    }

    /** Csak a vagyon értéke — az értékgörbe írásához. */
    public static function equity(int $accountId, ?array $snapshot = null): float
    {
        $cash = (float) Db::value('SELECT cash_huf FROM accounts WHERE id = ?', [$accountId], 0.0);

        $rows = Db::all(
            'SELECT p.*, a.symbol FROM positions p JOIN assets a ON a.id = p.asset_id
             WHERE p.account_id = ? AND p.status = ?',
            [$accountId, 'open']
        );
        if (!$rows) {
            return $cash;
        }

        $snapshot = $snapshot ?? PriceEngine::snapshot();
        $equity   = $cash;

        foreach ($rows as $row) {
            $price   = $snapshot[$row['symbol']]['price'] ?? (float) $row['avg_price'];
            $equity += (float) $row['margin_huf'] + TradeService::unrealized($row, $price);
        }

        return $equity;
    }

    /**
     * Az értékgörbe egy pontjának rögzítése.
     * Percre kerekítve, hogy a másodpercenkénti frissítés ne fújja fel a táblát.
     */
    public static function recordEquity(int $accountId, ?float $equity = null, ?int $ts = null): void
    {
        $ts     = $ts ?? PriceEngine::simNow();
        $ts     = intdiv($ts, 60) * 60;
        $equity = $equity ?? self::equity($accountId);

        Db::run(
            'INSERT INTO equity_snapshots (account_id, ts, equity_huf) VALUES (?, ?, ?)
             ON CONFLICT(account_id, ts) DO UPDATE SET equity_huf = excluded.equity_huf',
            [$accountId, $ts, $equity]
        );
    }

    /** Az értékgörbe pontjai a vonalas grafikonhoz. */
    public static function equityCurve(int $accountId, int $limit = 2000): array
    {
        $rows = Db::all(
            'SELECT ts, equity_huf FROM equity_snapshots WHERE account_id = ? ORDER BY ts DESC LIMIT ?',
            [$accountId, $limit]
        );

        return array_reverse(array_map(
            fn($r) => ['time' => (int) $r['ts'], 'value' => round((float) $r['equity_huf'], 2)],
            $rows
        ));
    }

    /** Kötési előzmények. */
    public static function history(int $accountId, int $limit = 200): array
    {
        return Db::all(
            'SELECT t.*, a.symbol, a.name, a.decimals
             FROM trades t JOIN assets a ON a.id = t.asset_id
             WHERE t.account_id = ?
             ORDER BY t.ts DESC, t.id DESC
             LIMIT ?',
            [$accountId, $limit]
        );
    }

    /** Összesítő számok a portfólió fülre. */
    public static function stats(int $accountId): array
    {
        $row = Db::one(
            "SELECT
                COUNT(*)                                                AS trades,
                SUM(CASE WHEN action <> 'open' THEN 1 ELSE 0 END)       AS closed,
                SUM(CASE WHEN realized_pnl > 0 THEN 1 ELSE 0 END)       AS wins,
                SUM(CASE WHEN action = 'liquidate' THEN 1 ELSE 0 END)   AS liquidations,
                COALESCE(SUM(realized_pnl), 0)                          AS realized,
                COALESCE(SUM(fee_huf), 0)                               AS fees
             FROM trades WHERE account_id = ?",
            [$accountId]
        ) ?? [];

        $closed = (int) ($row['closed'] ?? 0);
        $wins   = (int) ($row['wins'] ?? 0);

        return [
            'trades'       => (int) ($row['trades'] ?? 0),
            'closed'       => $closed,
            'wins'         => $wins,
            'win_rate'     => $closed > 0 ? $wins / $closed * 100.0 : 0.0,
            'liquidations' => (int) ($row['liquidations'] ?? 0),
            'realized'     => (float) ($row['realized'] ?? 0),
            'fees'         => (float) ($row['fees'] ?? 0),
        ];
    }
}
