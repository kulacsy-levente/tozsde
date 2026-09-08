<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;

/**
 * Hírek és piaci események.
 *
 * A hírek nem utólagos díszítések: a PriceEngine tőlük kapja a napi sokkokat,
 * tehát a hír *okozza* az elmozdulást, nem magyarázza. Mivel minden a seedből
 * számolódik, ugyanaz a nap újrafuttatva ugyanazt a hírt és ugyanazt az
 * árugrást adja — előretekerésnél is.
 */
final class NewsService
{
    // Kulcstér-elválasztók, hogy a hírek véletlenszámai ne ütközzenek
    // az árgenerálás számaival ugyanazon a seeden.
    private const K_ASSET  = 0x4E45571;
    private const K_MARKET = 0x4D4B5401;

    /** @var array<string, array{0:string,1:float}> pozitív eszközhírek */
    private const POSITIVE_STOCK = [
        ['A(z) %s gyorsjelentése jelentősen felülmúlta az elemzői várakozásokat', 1.0],
        ['Nagy volumenű felvásárlást jelentett be a(z) %s', 0.9],
        ['Új terméket mutatott be a(z) %s, kedvező piaci fogadtatás mellett', 0.7],
        ['Több elemzőház is vételre minősítette a(z) %s részvényeit', 0.6],
        ['Osztalékemelést jelentett be a(z) %s', 0.5],
        ['Jelentős új megrendelést nyert a(z) %s', 0.7],
        ['Költségcsökkentési programot indít a(z) %s, javuló marzsokat vár', 0.5],
    ];

    private const NEGATIVE_STOCK = [
        ['A(z) %s gyorsjelentése elmaradt a várakozásoktól', 1.0],
        ['Profitvárakozását csökkentette a(z) %s', 1.0],
        ['Hatósági vizsgálat indult a(z) %s ellen', 0.9],
        ['Lemondott a(z) %s vezérigazgatója', 0.7],
        ['Termékvisszahívást jelentett be a(z) %s', 0.8],
        ['Elemzők rontották a(z) %s célárát', 0.5],
        ['Ellátási lánc problémákról számolt be a(z) %s', 0.6],
    ];

    private const POSITIVE_CRYPTO = [
        ['Intézményi befektetők tömeges beáramlása a(z) %s piacán', 1.0],
        ['Nagy fizetési szolgáltató integrálta a(z) %s hálózatát', 0.9],
        ['Sikeresen lezajlott a(z) %s hálózati frissítése', 0.7],
        ['Kedvező szabályozói döntés született a(z) %s ügyében', 0.8],
        ['Rekordszintű hálózati aktivitás a(z) %s láncán', 0.5],
    ];

    private const NEGATIVE_CRYPTO = [
        ['Nagy tőzsdét ért támadás rántotta le a(z) %s árfolyamát', 1.0],
        ['Szabályozói szigorítást jelentettek be a(z) %s kapcsán', 0.9],
        ['Nagybefektetői eladási hullám a(z) %s piacán', 0.8],
        ['Hálózati fennakadás nehezíti a(z) %s tranzakcióit', 0.6],
        ['Likviditás szűkül a(z) %s kereskedésében', 0.5],
    ];

    private const POSITIVE_MARKET = [
        ['Kamatvágást jelentett be a jegybank, emelkednek a piacok', 1.0],
        ['A vártnál kedvezőbb inflációs adat érkezett', 0.8],
        ['Erős munkaerőpiaci adatok élénkítik a kockázatvállalást', 0.6],
        ['Enyhülnek a geopolitikai feszültségek', 0.7],
        ['Optimista kilátásokat közölt a jegybank', 0.5],
    ];

    private const NEGATIVE_MARKET = [
        ['Váratlan kamatemelés, esnek a piacok', 1.0],
        ['A vártnál magasabb inflációs adat rontotta a hangulatot', 0.8],
        ['Recessziós félelmek nyomják a tőzsdéket', 0.9],
        ['Fokozódó geopolitikai feszültség, menekülés a kockázatos eszközökből', 0.9],
        ['Gyengülő fogyasztási adatok érkeztek', 0.6],
    ];

    /**
     * Az adott eszköz adott napi híre (vagy null, ha nincs).
     *
     * @return array{headline:string, impact:float, severity:string}|null
     */
    public static function forAsset(array $asset, int $dayIndex): ?array
    {
        $seed = (int) $asset['seed'] ^ self::K_ASSET;

        if (!Rng::chance((float) config('news.asset_prob_per_day'), $seed, $dayIndex)) {
            return null;
        }

        $positive = Rng::chance(0.5, $seed, $dayIndex, 1);
        $isCrypto = $asset['type'] === 'crypto';

        $pool = $isCrypto
            ? ($positive ? self::POSITIVE_CRYPTO : self::NEGATIVE_CRYPTO)
            : ($positive ? self::POSITIVE_STOCK  : self::NEGATIVE_STOCK);

        [$template, $weight] = Rng::pick($pool, $seed, $dayIndex, 2);

        // A hatás nagysága: a sablon súlya × véletlen erősség × az eszköz saját
        // volatilitásához igazított skála. Egy nyugodt papírt egy hír sem ránt
        // ki 12%-kal, egy memecoint viszont igen.
        $maxImpact = (float) config('news.asset_impact');
        $volScale  = min(2.0, max(0.4, (float) $asset['sigma0'] / 0.02));
        $strength  = Rng::range(0.35, 1.0, $seed, $dayIndex, 3);

        $impact = $maxImpact * $weight * $strength * $volScale * ($positive ? 1 : -1);
        $impact = max(-$maxImpact * 2, min($maxImpact * 2, $impact));

        return [
            'headline' => sprintf($template, $asset['name']),
            'impact'   => $impact,
            'severity' => self::severity(abs($impact)),
        ];
    }

    /**
     * Az adott nap piaci szintű híre (vagy null).
     * Ez a közös faktoron keresztül minden papírt megmozgat, béta szerint.
     *
     * @return array{headline:string, impact:float, severity:string}|null
     */
    public static function forMarket(int $marketSeed, int $dayIndex): ?array
    {
        $seed = $marketSeed ^ self::K_MARKET;

        if (!Rng::chance((float) config('news.market_prob_per_day'), $seed, $dayIndex)) {
            return null;
        }

        $positive = Rng::chance(0.5, $seed, $dayIndex, 1);
        $pool     = $positive ? self::POSITIVE_MARKET : self::NEGATIVE_MARKET;

        [$headline, $weight] = Rng::pick($pool, $seed, $dayIndex, 2);

        $maxImpact = (float) config('news.market_impact');
        $strength  = Rng::range(0.4, 1.0, $seed, $dayIndex, 3);
        $impact    = $maxImpact * $weight * $strength * ($positive ? 1 : -1);

        return [
            'headline' => $headline,
            'impact'   => $impact,
            'severity' => self::severity(abs($impact) * 4),  // a piaci sokk skálája kisebb
        ];
    }

    private static function severity(float $magnitude): string
    {
        if ($magnitude >= 0.09) {
            return 'major';
        }
        if ($magnitude >= 0.04) {
            return 'normal';
        }
        return 'minor';
    }

    /** Hír mentése (ütközésmentesen, ha már létezik ugyanarra a napra). */
    public static function store(?int $assetId, int $ts, array $news): void
    {
        $exists = Db::value(
            'SELECT 1 FROM news WHERE ts = ? AND ' . ($assetId === null ? 'asset_id IS NULL' : 'asset_id = ?'),
            $assetId === null ? [$ts] : [$ts, $assetId]
        );
        if ($exists) {
            return;
        }

        Db::insert('news', [
            'asset_id'   => $assetId,
            'ts'         => $ts,
            'headline'   => $news['headline'],
            'impact_pct' => round($news['impact'] * 100, 2),
            'severity'   => $news['severity'],
        ]);
    }

    /** A legutóbbi hírek a hírsávhoz. */
    public static function recent(int $simNow, int $limit = 60, ?int $assetId = null): array
    {
        $sql = 'SELECT n.id, n.asset_id, n.ts, n.headline, n.impact_pct, n.severity,
                       a.symbol, a.name AS asset_name
                FROM news n
                LEFT JOIN assets a ON a.id = n.asset_id
                WHERE n.ts <= :now';
        $params = ['now' => $simNow];

        if ($assetId !== null) {
            $sql .= ' AND (n.asset_id = :aid OR n.asset_id IS NULL)';
            $params['aid'] = $assetId;
        }

        $sql .= ' ORDER BY n.ts DESC LIMIT ' . (int) $limit;

        return Db::all($sql, $params);
    }
}
