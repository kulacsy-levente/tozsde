<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;
use RuntimeException;

/**
 * Kereskedés: pozíciónyitás, zárás, likvidálás.
 *
 * ELSZÁMOLÁSI MODELL
 * ------------------
 * A fiók készpénze (`cash_huf`) mindig a *szabad* pénz. Nyitáskor a letét
 * levonódik belőle és a pozícióhoz kerül, záráskor a letét az eredménnyel
 * együtt tér vissza. Így a "szabad készpénz + lekötött letét + lebegő
 * eredmény" mindig kiadja a portfólió teljes értékét.
 *
 * Egy eszközre és irányra fióksonként egy nyitott pozíció létezik: az újabb
 * vétel a meglévőbe olvad be, súlyozott átlagárral. Ez sokkal áttekinthetőbb,
 * mint tucatnyi külön tétel ugyanabból a papírból.
 */
final class TradeService
{
    // =================================================================
    //  Árazás
    // =================================================================

    /**
     * Vételi és eladási ár a középárfolyam körül.
     * A spread az a rés, amin a "tőzsde" keres — ettől nem lehet nyereséget
     * termelni azzal, hogy másodpercenként oda-vissza kattintgatsz.
     */
    public static function quote(array $asset, ?float $mid = null): array
    {
        $mid    = $mid ?? PriceEngine::priceAt($asset, PriceEngine::simNow());
        $spread = (float) config('spread.' . $asset['type'], 0.001);

        return [
            'mid' => $mid,
            'ask' => $mid * (1.0 + $spread),   // ennyiért veszel
            'bid' => $mid * (1.0 - $spread),   // ennyiért adsz el
        ];
    }

    public static function feeRate(array $asset): float
    {
        return (float) config('fee.' . $asset['type'], 0.002);
    }

    /**
     * A likvidációs ár: az a szint, ahol a veszteség felemészti a letét
     * (1 - fenntartási követelmény) hányadát.
     */
    public static function liquidationPrice(string $side, float $avgPrice, float $qty, float $margin): float
    {
        if ($qty <= 0) {
            return 0.0;
        }

        $buffer   = $margin * (1.0 - (float) config('maintenance_margin'));
        $distance = $buffer / $qty;

        return $side === 'long'
            ? max(0.0, $avgPrice - $distance)
            : $avgPrice + $distance;
    }

    /** Lebegő eredmény egy pozíción az adott áron. */
    public static function unrealized(array $position, float $price): float
    {
        $qty = (float) $position['qty'];
        return $position['side'] === 'long'
            ? ($price - (float) $position['avg_price']) * $qty
            : ((float) $position['avg_price'] - $price) * $qty;
    }

    // =================================================================
    //  Nyitás
    // =================================================================

    /**
     * Pozíció nyitása vagy meglévő növelése.
     *
     * @param float|null $qty       darabszám (ha null, az amountHuf számít)
     * @param float|null $amountHuf mennyi forintot tegyünk bele letétként
     */
    public static function open(
        int $accountId,
        string $symbol,
        string $side,
        ?float $qty = null,
        ?float $amountHuf = null,
        float $leverage = 1.0
    ): array {
        $side = strtolower($side) === 'short' ? 'short' : 'long';

        $asset = PriceEngine::assetBySymbol($symbol);
        if ($asset === null) {
            throw new RuntimeException('Nincs ilyen eszköz: ' . $symbol);
        }

        $maxLev   = (float) config('max_leverage');
        $leverage = max(1.0, min($maxLev, round($leverage, 2)));

        $quote = self::quote($asset);
        $price = $side === 'long' ? $quote['ask'] : $quote['bid'];
        if ($price <= 0) {
            throw new RuntimeException('Erre az eszközre most nincs érvényes árfolyam.');
        }

        return Db::transaction(function () use ($accountId, $asset, $side, $qty, $amountHuf, $leverage, $price) {
            $account = Db::one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
            if ($account === null) {
                throw new RuntimeException('Nincs ilyen fiók.');
            }

            $cash    = (float) $account['cash_huf'];
            $feeRate = self::feeRate($asset);

            // --- Mennyiség meghatározása ---
            if ($qty === null || $qty <= 0) {
                if ($amountHuf === null || $amountHuf <= 0) {
                    throw new RuntimeException('Add meg a darabszámot vagy a befektetni kívánt összeget.');
                }
                // Az összeg a LETÉT, a díjjal együtt. Ebből:
                //   notional = letét * áttét,  díj = notional * díjkulcs
                //   összeg = letét + díj = notional/áttét + notional*díjkulcs
                $notional = $amountHuf / (1.0 / $leverage + $feeRate);
                $qty      = $notional / $price;
            }

            $qty = self::roundQty($asset, $qty);
            if ($qty < (float) $asset['min_qty']) {
                throw new RuntimeException(sprintf(
                    'A legkisebb köthető mennyiség %s %s.',
                    rtrim(rtrim(number_format((float) $asset['min_qty'], 4, ',', ' '), '0'), ','),
                    $asset['symbol']
                ));
            }

            $notional = $qty * $price;
            $margin   = $notional / $leverage;
            $fee      = $notional * $feeRate;
            $needed   = $margin + $fee;

            if ($needed > $cash + 0.005) {
                throw new RuntimeException(sprintf(
                    'Nincs elég szabad készpénzed: %s Ft kellene, %s Ft áll rendelkezésre.',
                    number_format($needed, 0, ',', ' '),
                    number_format($cash, 0, ',', ' ')
                ));
            }

            $now = PriceEngine::simNow();

            // --- Meglévő pozícióba olvasztás, vagy új nyitása ---
            $existing = Db::one(
                'SELECT * FROM positions WHERE account_id = ? AND asset_id = ? AND side = ? AND status = ?',
                [$accountId, $asset['id'], $side, 'open']
            );

            if ($existing !== null) {
                $oldQty    = (float) $existing['qty'];
                $newQty    = $oldQty + $qty;
                $newAvg    = ($oldQty * (float) $existing['avg_price'] + $qty * $price) / $newQty;
                $newMargin = (float) $existing['margin_huf'] + $margin;

                // A tényleges áttét a teljes pozícióra újraszámolva
                $newLev = $newMargin > 0 ? ($newQty * $newAvg) / $newMargin : 1.0;

                Db::update('positions', [
                    'qty'        => $newQty,
                    'avg_price'  => $newAvg,
                    'margin_huf' => $newMargin,
                    'leverage'   => $newLev,
                    'liq_price'  => self::liquidationPrice($side, $newAvg, $newQty, $newMargin),
                ], 'id = :id', ['id' => $existing['id']]);

                $positionId = (int) $existing['id'];
            } else {
                $positionId = Db::insert('positions', [
                    'account_id' => $accountId,
                    'asset_id'   => $asset['id'],
                    'side'       => $side,
                    'qty'        => $qty,
                    'avg_price'  => $price,
                    'leverage'   => $leverage,
                    'margin_huf' => $margin,
                    'liq_price'  => self::liquidationPrice($side, $price, $qty, $margin),
                    'opened_at'  => $now,
                    'status'     => 'open',
                ]);
            }

            Db::update('accounts', ['cash_huf' => $cash - $needed], 'id = :id', ['id' => $accountId]);

            Db::insert('trades', [
                'account_id'   => $accountId,
                'position_id'  => $positionId,
                'asset_id'     => $asset['id'],
                'action'       => 'open',
                'side'         => $side,
                'qty'          => $qty,
                'price'        => $price,
                'fee_huf'      => $fee,
                'realized_pnl' => 0.0,
                'ts'           => $now,
            ]);

            return [
                'position_id' => $positionId,
                'symbol'      => $asset['symbol'],
                'side'        => $side,
                'qty'         => $qty,
                'price'       => $price,
                'margin'      => $margin,
                'fee'         => $fee,
                'leverage'    => $leverage,
            ];
        });
    }

    // =================================================================
    //  Zárás
    // =================================================================

    /**
     * Pozíció zárása, részlegesen is.
     *
     * @param float|null $qty mennyit zárjunk (null = a teljes pozíciót)
     */
    public static function close(int $accountId, int $positionId, ?float $qty = null): array
    {
        return Db::transaction(function () use ($accountId, $positionId, $qty) {
            $position = Db::one(
                'SELECT * FROM positions WHERE id = ? AND account_id = ? AND status = ?',
                [$positionId, $accountId, 'open']
            );
            if ($position === null) {
                throw new RuntimeException('Ez a pozíció már nincs nyitva.');
            }

            $asset = PriceEngine::asset((int) $position['asset_id']);
            if ($asset === null) {
                throw new RuntimeException('Az eszköz nem található.');
            }

            $quote = self::quote($asset);
            // Zárásnál fordítva megyünk át a spreaden, mint nyitáskor
            $price = $position['side'] === 'long' ? $quote['bid'] : $quote['ask'];

            $openQty  = (float) $position['qty'];
            $closeQty = ($qty === null || $qty <= 0 || $qty >= $openQty)
                ? $openQty
                : self::roundQty($asset, $qty);

            if ($closeQty <= 0) {
                throw new RuntimeException('Érvénytelen zárási mennyiség.');
            }

            return self::settle($accountId, $position, $asset, $closeQty, $price, 'close', PriceEngine::simNow());
        });
    }

    /**
     * A zárás közös elszámolása (kézi zárásra és likvidálásra egyaránt).
     */
    private static function settle(
        int $accountId,
        array $position,
        array $asset,
        float $closeQty,
        float $price,
        string $action,
        int $ts
    ): array {
        $openQty  = (float) $position['qty'];
        $portion  = $closeQty / $openQty;
        $avg      = (float) $position['avg_price'];

        $pnl = $position['side'] === 'long'
            ? ($price - $avg) * $closeQty
            : ($avg - $price) * $closeQty;

        $marginBack = (float) $position['margin_huf'] * $portion;
        $fee        = $price * $closeQty * self::feeRate($asset);

        // Negatív egyenleg elleni védelem: a letétnél többet nem lehet
        // elbukni egy pozíción. A valódi brókereknél ez külön szolgáltatás,
        // itt alapból jár — enélkül egy hétvégi árrés mínuszba vinné a fiókot.
        $proceeds = max(0.0, $marginBack + $pnl - $fee);

        $account = Db::one('SELECT cash_huf FROM accounts WHERE id = ?', [$accountId]);
        Db::update(
            'accounts',
            ['cash_huf' => (float) $account['cash_huf'] + $proceeds],
            'id = :id',
            ['id' => $accountId]
        );

        $remaining = $openQty - $closeQty;

        if ($remaining > 1e-9) {
            Db::update('positions', [
                'qty'        => $remaining,
                'margin_huf' => (float) $position['margin_huf'] - $marginBack,
                'liq_price'  => self::liquidationPrice(
                    $position['side'],
                    $avg,
                    $remaining,
                    (float) $position['margin_huf'] - $marginBack
                ),
            ], 'id = :id', ['id' => $position['id']]);
        } else {
            Db::update('positions', [
                'qty'       => 0,
                'status'    => $action === 'liquidate' ? 'liquidated' : 'closed',
                'closed_at' => $ts,
            ], 'id = :id', ['id' => $position['id']]);
        }

        Db::insert('trades', [
            'account_id'   => $accountId,
            'position_id'  => $position['id'],
            'asset_id'     => $asset['id'],
            'action'       => $action,
            'side'         => $position['side'],
            'qty'          => $closeQty,
            'price'        => $price,
            'fee_huf'      => $fee,
            'realized_pnl' => $pnl - $fee,
            'ts'           => $ts,
        ]);

        return [
            'position_id'  => (int) $position['id'],
            'symbol'       => $asset['symbol'],
            'qty'          => $closeQty,
            'price'        => $price,
            'fee'          => $fee,
            'realized_pnl' => $pnl - $fee,
            'proceeds'     => $proceeds,
            'action'       => $action,
        ];
    }

    // =================================================================
    //  Likvidálás
    // =================================================================

    /**
     * Likvidálás egy konkrét áron és időpontban.
     * Az előretekerés ezt hívja, amikor a napi minimum/maximum átlépte a
     * likvidációs szintet.
     */
    public static function liquidate(int $accountId, array $position, array $asset, float $price, int $ts): array
    {
        return Db::transaction(fn() => self::settle(
            $accountId,
            $position,
            $asset,
            (float) $position['qty'],
            $price,
            'liquidate',
            $ts
        ));
    }

    /**
     * Valós idejű likvidációs ellenőrzés az aktuális árakon.
     * Minden tick-kérésnél lefut — olcsó, mert csak a nyitott pozíciókat nézi.
     *
     * @return array a likvidált pozíciók leírása
     */
    public static function checkLiquidations(int $accountId): array
    {
        $positions = Db::all(
            'SELECT * FROM positions WHERE account_id = ? AND status = ?',
            [$accountId, 'open']
        );
        if (!$positions) {
            return [];
        }

        $now  = PriceEngine::simNow();
        $done = [];

        foreach ($positions as $position) {
            $asset = PriceEngine::asset((int) $position['asset_id']);
            if ($asset === null) {
                continue;
            }

            $price = PriceEngine::priceAt($asset, $now);
            $liq   = (float) $position['liq_price'];

            $hit = $position['side'] === 'long' ? $price <= $liq : $price >= $liq;
            if ($hit) {
                $done[] = self::liquidate($accountId, $position, $asset, $liq, $now);
            }
        }

        return $done;
    }

    // =================================================================
    //  Segédek
    // =================================================================

    private static function roundQty(array $asset, float $qty): float
    {
        $step = (float) $asset['qty_step'];
        if ($step <= 0) {
            return $qty;
        }
        return floor($qty / $step + 1e-9) * $step;
    }

    /**
     * Előzetes számítás a kereskedési panelhez: mennyibe kerülne, mekkora
     * lenne a letét, hol lenne a likvidációs szint. Nem nyúl az adatbázishoz.
     */
    public static function preview(array $asset, string $side, float $qty, float $leverage): array
    {
        $quote    = self::quote($asset);
        $price    = $side === 'long' ? $quote['ask'] : $quote['bid'];
        $notional = $qty * $price;
        $margin   = $notional / max(1.0, $leverage);
        $fee      = $notional * self::feeRate($asset);

        return [
            'price'     => $price,
            'notional'  => $notional,
            'margin'    => $margin,
            'fee'       => $fee,
            'total'     => $margin + $fee,
            'liq_price' => self::liquidationPrice($side, $price, $qty, $margin),
        ];
    }
}
