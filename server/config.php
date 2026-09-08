<?php
/**
 * Központi konfiguráció. Minden más fájl innen olvassa a beállításokat.
 */

return [
    // --- Elérési utak ---
    'db_path'      => dirname(__DIR__) . '/db/database.sqlite',
    'schema_path'  => __DIR__ . '/Model/schema.sql',
    'vendor_dir'   => dirname(__DIR__) . '/public/assets/vendor',

    // --- Pénz ---
    'currency'          => 'HUF',
    'usd_huf'           => 360.0,   // fix átváltás az importkor
    'eur_huf'           => 395.0,
    'default_capital'   => 10_000_000.0,
    'min_capital'       => 100_000.0,
    'max_capital'       => 1_000_000_000.0,

    // --- Idő ---
    // 1 valós másodperc = 1 szimulált másodperc.
    'minute_retention_days' => 3,      // ennyi napra tartunk 1 perces gyertyát
    'history_days'          => 730,    // ennyi nap valós múltat importálunk

    // Az előretekerő gombok (másodpercben).
    // A megőrzési ablaknál (3 nap) rövidebb ugrásokat percről percre
    // szimuláljuk, a hosszabbakat napról napra — lásd SimulationService.
    'horizons' => [
        '1h' => 3600,
        '1d' => 86400,
        '1w' => 7 * 86400,
        '1m' => 30 * 86400,
        '1y' => 365 * 86400,
    ],

    // --- Kereskedés ---
    'fee' => [
        'crypto' => 0.001,   // 0,1%
        'stock'  => 0.0025,  // 0,25%
    ],
    'spread' => [
        'crypto' => 0.0005,  // ±0,05% a mid ár körül
        'stock'  => 0.0015,  // ±0,15%
    ],
    'max_leverage'        => 5,
    'maintenance_margin'  => 0.20,  // a letét 80%-ának elvesztésekor likvidálunk

    // --- Árszimuláció ---
    'engine' => [
        // GARCH(1,1)-szerű volatilitás-klaszterezés.
        // alpha*kurtosis + beta < 1 kell, különben a volatilitás elszáll:
        // 0.08*1.243 + 0.85 = 0.949, tehát stabil, de lassan lecsengő.
        'garch_alpha' => 0.08,
        'garch_beta'  => 0.85,
        // A fatNormal szórásnégyzete. Ezzel skálázzuk vissza a célvolatilitást,
        // hogy a *realizált* napi szórás tényleg a sigma0 legyen, ne annál nagyobb.
        'eps_kurtosis' => 1.243,
        // A napi volatilitás megengedett sávja a saját alapszintjéhez képest
        'sigma_floor' => 0.35,
        'sigma_cap'   => 3.0,
        // A közös piaci faktor napi szórása
        'market_factor_vol' => 0.008,
        // Napi hozam levágása, hogy egy szélsőség ne tegye tönkre az egész pályát
        'max_daily_return'  => 0.35,
        // Intraday tartomány: a napi |hozam| ennyiszerese kerül a kanócokba
        'wick_factor' => 0.6,
        // Ráhagyás a napon belüli úton, hogy tényleg kitöltse a napi ársávot
        'intraday_gain' => 1.0,
    ],

    // --- Hírek ---
    'news' => [
        'asset_prob_per_day'  => 0.06,  // eszközönként és naponta ennyi eséllyel van hír
        'market_prob_per_day' => 0.12,  // piaci szintű hír esélye naponta
        'asset_impact'        => 0.12,  // max ±12% eszközspecifikus sokk
        'market_impact'       => 0.03,  // max ±3% a közös faktoron
    ],

    // --- Egyéb ---
    'session_name' => 'TOZSDESIM',
    'debug'        => true,
];
