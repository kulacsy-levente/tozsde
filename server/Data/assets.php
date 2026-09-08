<?php
/**
 * A 75 kereskedhető eszköz alapdefiníciója.
 *
 * A `ref_price` az eredeti pénznemben (USD, EUR vagy HUF) értendő, és csak
 * akkor számít, ha a valós adat letöltése nem sikerül — ilyenkor innen indul a
 * szintetikus történet. Ha az import lefut, a valós záróár írja felül.
 *
 * A `sigma` napi volatilitás, a `beta` a közös piaci faktorra való érzékenység.
 * Sikeres import esetén mindkettőt a letöltött adatból becsüljük újra, tehát
 * ezek is csak kiindulási értékek.
 *
 * mezők: symbol, name, type, market, currency, source_ref, ref_price, sigma, beta, decimals
 */

declare(strict_types=1);

return [

    // =================================================================
    //  KRIPTOVALUTÁK (15) — forrás: Binance, jegyzés USD-ben
    // =================================================================
    ['BTC',  'Bitcoin',          'crypto', 'crypto', 'USD', 'BTCUSDT',  65000.0, 0.030, 1.00, 0],
    ['ETH',  'Ethereum',         'crypto', 'crypto', 'USD', 'ETHUSDT',   3200.0, 0.036, 1.15, 0],
    ['BNB',  'BNB',              'crypto', 'crypto', 'USD', 'BNBUSDT',    580.0, 0.038, 1.05, 0],
    ['SOL',  'Solana',           'crypto', 'crypto', 'USD', 'SOLUSDT',    150.0, 0.052, 1.35, 0],
    ['XRP',  'XRP',              'crypto', 'crypto', 'USD', 'XRPUSDT',      0.55, 0.048, 1.10, 2],
    ['ADA',  'Cardano',          'crypto', 'crypto', 'USD', 'ADAUSDT',      0.45, 0.047, 1.20, 2],
    ['DOGE', 'Dogecoin',         'crypto', 'crypto', 'USD', 'DOGEUSDT',     0.13, 0.060, 1.45, 3],
    ['AVAX', 'Avalanche',        'crypto', 'crypto', 'USD', 'AVAXUSDT',    30.0,  0.055, 1.30, 1],
    ['LINK', 'Chainlink',        'crypto', 'crypto', 'USD', 'LINKUSDT',    15.0,  0.050, 1.25, 1],
    ['DOT',  'Polkadot',         'crypto', 'crypto', 'USD', 'DOTUSDT',      6.50, 0.049, 1.20, 2],
    ['LTC',  'Litecoin',         'crypto', 'crypto', 'USD', 'LTCUSDT',     90.0,  0.040, 1.05, 0],
    ['BCH',  'Bitcoin Cash',     'crypto', 'crypto', 'USD', 'BCHUSDT',    400.0,  0.042, 1.10, 0],
    ['TRX',  'TRON',             'crypto', 'crypto', 'USD', 'TRXUSDT',      0.25, 0.038, 0.90, 1],
    ['ATOM', 'Cosmos',           'crypto', 'crypto', 'USD', 'ATOMUSDT',     6.0,  0.050, 1.25, 0],
    ['UNI',  'Uniswap',          'crypto', 'crypto', 'USD', 'UNIUSDT',      8.0,  0.052, 1.30, 0],

    // =================================================================
    //  NEMZETKÖZI RÉSZVÉNYEK (30) — forrás: Yahoo Finance, jegyzés USD-ben
    // =================================================================
    ['AAPL',  'Apple',                  'stock', 'intl', 'USD', 'AAPL',   220.0, 0.017, 1.05, 2],
    ['MSFT',  'Microsoft',              'stock', 'intl', 'USD', 'MSFT',   420.0, 0.016, 1.00, 2],
    ['NVDA',  'NVIDIA',                 'stock', 'intl', 'USD', 'NVDA',   120.0, 0.032, 1.60, 2],
    ['GOOGL', 'Alphabet',               'stock', 'intl', 'USD', 'GOOGL',  175.0, 0.018, 1.10, 2],
    ['AMZN',  'Amazon',                 'stock', 'intl', 'USD', 'AMZN',   185.0, 0.020, 1.15, 2],
    ['META',  'Meta Platforms',         'stock', 'intl', 'USD', 'META',   500.0, 0.023, 1.25, 2],
    ['TSLA',  'Tesla',                  'stock', 'intl', 'USD', 'TSLA',   240.0, 0.035, 1.70, 2],
    ['JPM',   'JPMorgan Chase',         'stock', 'intl', 'USD', 'JPM',    210.0, 0.014, 0.95, 2],
    ['V',     'Visa',                   'stock', 'intl', 'USD', 'V',      270.0, 0.013, 0.90, 2],
    ['WMT',   'Walmart',                'stock', 'intl', 'USD', 'WMT',     70.0, 0.011, 0.60, 2],
    ['KO',    'Coca-Cola',              'stock', 'intl', 'USD', 'KO',      65.0, 0.009, 0.50, 2],
    ['DIS',   'Walt Disney',            'stock', 'intl', 'USD', 'DIS',     95.0, 0.018, 1.05, 2],
    ['NFLX',  'Netflix',                'stock', 'intl', 'USD', 'NFLX',   640.0, 0.024, 1.20, 2],
    ['AMD',   'AMD',                    'stock', 'intl', 'USD', 'AMD',    145.0, 0.031, 1.55, 2],
    ['INTC',  'Intel',                  'stock', 'intl', 'USD', 'INTC',    22.0, 0.026, 1.20, 2],
    ['BA',    'Boeing',                 'stock', 'intl', 'USD', 'BA',     175.0, 0.022, 1.15, 2],
    ['MCD',   "McDonald's",             'stock', 'intl', 'USD', 'MCD',    285.0, 0.010, 0.55, 2],
    ['PFE',   'Pfizer',                 'stock', 'intl', 'USD', 'PFE',     29.0, 0.015, 0.65, 2],
    ['XOM',   'Exxon Mobil',            'stock', 'intl', 'USD', 'XOM',    115.0, 0.016, 0.80, 2],
    ['NKE',   'Nike',                   'stock', 'intl', 'USD', 'NKE',     78.0, 0.019, 1.00, 2],
    ['LLY',   'Eli Lilly',              'stock', 'intl', 'USD', 'LLY',   1143.0, 0.018, 0.80, 2],
    ['AVGO',  'Broadcom',               'stock', 'intl', 'USD', 'AVGO',   354.0, 0.026, 1.40, 2],
    ['COST',  'Costco',                 'stock', 'intl', 'USD', 'COST',   917.0, 0.012, 0.70, 2],
    ['HD',    'Home Depot',             'stock', 'intl', 'USD', 'HD',     320.0, 0.014, 0.90, 2],
    ['ORCL',  'Oracle',                 'stock', 'intl', 'USD', 'ORCL',   157.0, 0.022, 1.20, 2],
    ['ADBE',  'Adobe',                  'stock', 'intl', 'USD', 'ADBE',   269.0, 0.021, 1.15, 2],
    ['PEP',   'PepsiCo',                'stock', 'intl', 'USD', 'PEP',    137.0, 0.010, 0.50, 2],
    ['UBER',  'Uber',                   'stock', 'intl', 'USD', 'UBER',    76.0, 0.024, 1.30, 2],
    ['CAT',   'Caterpillar',            'stock', 'intl', 'USD', 'CAT',    809.0, 0.017, 1.05, 2],
    ['IBM',   'IBM',                    'stock', 'intl', 'USD', 'IBM',    235.0, 0.015, 0.85, 2],

    // =================================================================
    //  MAGYAR RÉSZVÉNYEK (30) — forrás: Yahoo Finance (.BD)
    //
    //  A currency itt csak a szintetikus tartalék esetén számít: sikeres
    //  letöltésnél a Yahoo által megadott pénznemmel váltunk forintra.
    //  (A Graphisoft Park például euróban jegyzett.)
    // =================================================================
    ['OTP',   'OTP Bank',               'stock', 'hu', 'HUF', 'OTP.BD',         19000.0, 0.017, 1.10, 0],
    ['MOL',   'MOL Nyrt.',              'stock', 'hu', 'HUF', 'MOL.BD',          2800.0, 0.016, 0.95, 0],
    ['RICHT', 'Richter Gedeon',         'stock', 'hu', 'HUF', 'RICHTER.BD',     10500.0, 0.014, 0.75, 0],
    ['MTEL',  'Magyar Telekom',         'stock', 'hu', 'HUF', 'MTELEKOM.BD',     1200.0, 0.013, 0.60, 0],
    ['4IG',   '4iG Nyrt.',              'stock', 'hu', 'HUF', '4IG.BD',           900.0, 0.024, 1.05, 0],
    ['OPUS',  'Opus Global',            'stock', 'hu', 'HUF', 'OPUS.BD',          300.0, 0.026, 1.00, 0],
    ['MASTP', 'Masterplast',            'stock', 'hu', 'HUF', 'MASTERPLAST.BD',   900.0, 0.028, 1.05, 0],
    ['WABER', "Waberer's",              'stock', 'hu', 'HUF', 'WABERERS.BD',     5000.0, 0.025, 0.95, 0],
    ['RABA',  'Rába',                   'stock', 'hu', 'HUF', 'RABA.BD',         1400.0, 0.027, 0.90, 0],
    ['ZWACK', 'Zwack Unicum',           'stock', 'hu', 'HUF', 'ZWACK.BD',       19000.0, 0.014, 0.45, 0],
    ['ANY',   'ANY Biztonsági Nyomda',  'stock', 'hu', 'HUF', 'ANY.BD',          1500.0, 0.022, 0.75, 0],
    ['ALTEO', 'Alteo Energia',          'stock', 'hu', 'HUF', 'ALTEO.BD',        3000.0, 0.024, 0.85, 0],
    ['CIGP',  'CIG Pannónia',           'stock', 'hu', 'HUF', 'CIGPANNONIA.BD',   300.0, 0.028, 0.90, 0],
    ['GSPARK','Graphisoft Park',        'stock', 'hu', 'EUR', 'GSPARK.BD',         15.5, 0.018, 0.60, 2],
    ['DHOUSE','Duna House',             'stock', 'hu', 'HUF', 'DUNAHOUSE.BD',    1200.0, 0.023, 0.80, 0],
    ['AWH',   'AutoWallis',             'stock', 'hu', 'HUF', 'AUTOWALLIS.BD',    130.0, 0.030, 1.00, 0],
    ['PANNE', 'PannErgy',               'stock', 'hu', 'HUF', 'PANNERGY.BD',     1300.0, 0.026, 0.85, 0],
    ['APPEN', 'Appeninn',               'stock', 'hu', 'HUF', 'APPENINN.BD',      350.0, 0.032, 0.95, 0],
    ['BIF',   'Budapesti Ingatlan',     'stock', 'hu', 'HUF', 'BIF.BD',           900.0, 0.021, 0.70, 0],
    ['DELTA', 'Delta Technologies',     'stock', 'hu', 'HUF', 'DELTA.BD',         300.0, 0.031, 0.95, 0],
    ['AKKO',  'Akko Invest',            'stock', 'hu', 'HUF', 'AKKO.BD',          246.0, 0.034, 0.90, 0],
    ['NUTEX', 'Nutex',                  'stock', 'hu', 'HUF', 'NUTEX.BD',          12.9, 0.036, 0.85, 2],
    ['ENEFI', 'EnviRo Energy',          'stock', 'hu', 'HUF', 'ENEFI.BD',         214.0, 0.033, 0.85, 0],
    ['ORMES', 'Ormester Vagyonvédelem', 'stock', 'hu', 'HUF', 'ORMESTER.BD',      406.0, 0.030, 0.75, 0],
    ['MEGAK', 'Megakrán',               'stock', 'hu', 'HUF', 'MEGAKRAN.BD',        3.6, 0.035, 0.80, 2],
    ['GOPD',  'Gopd',                   'stock', 'hu', 'HUF', 'GOPD.BD',         7750.0, 0.028, 0.70, 0],
    ['PENSU', 'Pensum Group',           'stock', 'hu', 'HUF', 'PENSUM.BD',       2260.0, 0.027, 0.75, 0],
    ['NAP',   'Naturtex',               'stock', 'hu', 'HUF', 'NAP.BD',          1390.0, 0.029, 0.70, 0],
    ['SUNDL', 'SunDell Estate',         'stock', 'hu', 'HUF', 'SUNDELL.BD',      3080.0, 0.030, 0.75, 0],
    ['AMIXA', 'Amixa',                  'stock', 'hu', 'HUF', 'AMIXA.BD',         236.0, 0.032, 0.80, 0],
];
