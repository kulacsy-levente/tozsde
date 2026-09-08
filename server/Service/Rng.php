<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Determinisztikus, számláló-alapú véletlengenerátor.
 *
 * Nem folyam-, hanem *hash*-alapú: nincs belső állapota, minden érték
 * közvetlenül a kulcsaiból (seed + koordináták) számolódik ki. Ez az egész
 * szimuláció alapköve — ettől lehet bármely nap árát kiszámolni anélkül,
 * hogy az összes korábbi napot végig kellene futtatni, és ettől ad ugyanaz
 * a seed mindig ugyanazt a világot.
 *
 *     Rng::uniform($asset['seed'], $dayIndex, 3)
 *
 * mindig ugyanazt a számot adja, akárhányszor és akármilyen sorrendben hívod.
 *
 * Az aritmetika végig 32 bites: két 32 bites szám szorzata belefér a PHP
 * 64 bites egészébe, tehát nincs csendes float-tá alakulás.
 */
final class Rng
{
    private const MASK = 0xFFFFFFFF;

    /**
     * 32 bites szorzás túlcsordulás nélkül.
     *
     * Két 32 bites szám szorzata 64 bit is lehet, ami átlépi a PHP egész
     * határát (~9.22e18) és csendben float-tá alakul — a float pedig elveszti
     * az alsó biteket, azaz pont azt, amire a keverésnél szükség van.
     * Ezért 16 bites félszavakra bontva szorzunk: így minden részszorzat
     * bőven belefér az egészbe.
     */
    private static function mul32(int $a, int $b): int
    {
        $a &= self::MASK;
        $b &= self::MASK;

        $aLo = $a & 0xFFFF;
        $aHi = ($a >> 16) & 0xFFFF;

        // A felső fél hozzájárulásából csak az alsó 16 bit marad a 32 bites
        // eredményben, a fölötte lévő rész úgyis levágódna. A két részt
        // ÖSSZEADNI kell, nem VAGY-olni: a VAGY elnyelné az átvitelt, és
        // felfelé torzítaná az eredményt (a bitek "beragadnának" egyesre).
        return (((($aHi * $b) & 0xFFFF) << 16) + ($aLo * $b)) & self::MASK;
    }

    /** Egy 32 bites egész alapos elkeverése (Murmur-szerű finalizer). */
    public static function mix(int $x): int
    {
        $x &= self::MASK;
        $x = self::mul32($x ^ ($x >> 16), 0x7FEB352D);
        $x = self::mul32($x ^ ($x >> 15), 0x846CA68B);
        return ($x ^ ($x >> 16)) & self::MASK;
    }

    /** Több kulcs összekeverése egyetlen 32 bites hash-be. */
    public static function hash(int $seed, int ...$keys): int
    {
        $h = self::mix($seed);
        foreach ($keys as $k) {
            // A negatív kulcsok (pl. múltbeli napindex) is kezelhetők
            $h = self::mix((($h ^ self::mix($k & self::MASK)) + 0x9E3779B9) & self::MASK);
        }
        return $h;
    }

    /** Egyenletes eloszlású valós szám a [0, 1) tartományban. */
    public static function uniform(int $seed, int ...$keys): float
    {
        return self::hash($seed, ...$keys) / 4294967296.0;
    }

    /** Egyenletes eloszlású valós szám [$min, $max) között. */
    public static function range(float $min, float $max, int $seed, int ...$keys): float
    {
        return $min + ($max - $min) * self::uniform($seed, ...$keys);
    }

    /** Egész szám [$min, $max] között (mindkét vég beleértve). */
    public static function int(int $min, int $max, int $seed, int ...$keys): int
    {
        if ($max <= $min) {
            return $min;
        }
        return $min + (int) floor(self::uniform($seed, ...$keys) * ($max - $min + 1));
    }

    /**
     * Standard normális eloszlású szám (Box–Muller).
     * A tőzsdei hozamokhoz ez kell, nem az egyenletes eloszlás.
     */
    public static function normal(int $seed, int ...$keys): float
    {
        // Két független egyenletes érték, két külön kulcstérből
        $u1 = self::uniform($seed, ...array_merge($keys, [0x5EED1]));
        $u2 = self::uniform($seed, ...array_merge($keys, [0x5EED2]));

        // A log(0) elkerülése
        if ($u1 < 1e-12) {
            $u1 = 1e-12;
        }

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * Student-t jellegű, "vastag farkú" normális.
     *
     * A valódi tőzsdei hozamok szélsőségei sokkal gyakoribbak, mint amit a
     * normális eloszlás jósol: a normális szerint egy 5 szórásnyi nap
     * évmilliókban mérhető ritkaságú lenne, a valóságban évente látunk ilyet.
     * Ezért a normális értéket egy véletlen skálázóval nyújtjuk meg, így
     * néha jönnek igazi zuhanások és kilövések is.
     */
    public static function fatNormal(int $seed, int ...$keys): float
    {
        $z = self::normal($seed, ...$keys);
        $u = self::uniform($seed, ...array_merge($keys, [0x7A11]));

        // ~4% eséllyel megnyújtjuk a napot
        if ($u < 0.04) {
            $z *= self::range(1.8, 3.5, $seed, ...array_merge($keys, [0x7A12]));
        }

        return $z;
    }

    /** Igaz $p valószínűséggel. */
    public static function chance(float $p, int $seed, int ...$keys): bool
    {
        return self::uniform($seed, ...$keys) < $p;
    }

    /** Véletlen elem egy tömbből. */
    public static function pick(array $items, int $seed, int ...$keys)
    {
        $values = array_values($items);
        return $values[self::int(0, count($values) - 1, $seed, ...$keys)];
    }

    /** Kriptográfiailag alkalmas új seed (új világ létrehozásához). */
    public static function newSeed(): int
    {
        return random_int(1, 0x7FFFFFFF);
    }
}
