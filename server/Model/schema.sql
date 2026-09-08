-- =====================================================================
--  Tőzsdeszimulátor — adatbázisséma (SQLite)
--  Minden utasítás IF NOT EXISTS, tehát a fájl bármikor újrafuttatható.
-- =====================================================================

-- ---------------------------------------------------------------------
--  Eszközök
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assets (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol        TEXT    NOT NULL UNIQUE,          -- BTC, AAPL, OTP ...
    name          TEXT    NOT NULL,
    type          TEXT    NOT NULL,                 -- crypto | stock
    market        TEXT    NOT NULL,                 -- crypto | intl | hu
    currency_src  TEXT    NOT NULL DEFAULT 'USD',   -- az eredeti jegyzés pénzneme
    data_source   TEXT    NOT NULL DEFAULT 'pending', -- real | synthetic | pending
    source_ref    TEXT,                             -- pl. 'BTCUSDT' vagy 'aapl.us'

    -- A szimuláció paraméterei (importkor becsülve, vagy a seed-listából)
    seed          INTEGER NOT NULL,
    mu            REAL    NOT NULL DEFAULT 0.0,     -- napi sodródás
    sigma0        REAL    NOT NULL DEFAULT 0.02,    -- alap napi volatilitás
    beta          REAL    NOT NULL DEFAULT 1.0,     -- érzékenység a közös piaci faktorra

    -- Élő állapot: a GARCH-rekurzióhoz és a gyors árlekérdezéshez
    cur_sigma     REAL    NOT NULL DEFAULT 0.02,
    last_return   REAL    NOT NULL DEFAULT 0.0,
    last_price    REAL    NOT NULL DEFAULT 0.0,     -- HUF
    last_day      INTEGER NOT NULL DEFAULT 0,       -- eddig a napig van napi gyertya (unix nap)

    -- Kereskedési szabályok
    min_qty       REAL    NOT NULL DEFAULT 1.0,
    qty_step      REAL    NOT NULL DEFAULT 1.0,
    decimals      INTEGER NOT NULL DEFAULT 2,       -- ármegjelenítés tizedesjegyei

    active        INTEGER NOT NULL DEFAULT 1,
    sort_order    INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_assets_market ON assets(market, sort_order);

-- ---------------------------------------------------------------------
--  Gyertyák
--  Az árak MIND forintban vannak tárolva.
-- ---------------------------------------------------------------------

-- Napi gyertya: a teljes történet (importált valós múlt + szimulált jövő).
CREATE TABLE IF NOT EXISTS candles_1d (
    asset_id  INTEGER NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    ts        INTEGER NOT NULL,          -- a nap 00:00 UTC unix időbélyege
    o         REAL    NOT NULL,
    h         REAL    NOT NULL,
    l         REAL    NOT NULL,
    c         REAL    NOT NULL,
    v         REAL    NOT NULL DEFAULT 0,
    src       TEXT    NOT NULL DEFAULT 'sim',   -- real | sim

    -- A napon belüli nyers út szélsőértékei (log-térben), lustán kiszámolva.
    -- Ezekkel feszítjük rá a perces utat pontosan a napi minimum–maximum
    -- sávra, és ettől adja a percenkénti pontlekérdezés ugyanazt, mint a
    -- teljes út legyártása. Lásd PriceEngine::pathBounds().
    bmin      REAL,
    bmax      REAL,

    PRIMARY KEY (asset_id, ts)
) WITHOUT ROWID;

-- Perces gyertya: csak a legutóbbi 3 napra, és csak a megnyitott eszközökre.
CREATE TABLE IF NOT EXISTS candles_1m (
    asset_id  INTEGER NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    ts        INTEGER NOT NULL,          -- a perc kezdetének unix időbélyege
    o         REAL    NOT NULL,
    h         REAL    NOT NULL,
    l         REAL    NOT NULL,
    c         REAL    NOT NULL,
    v         REAL    NOT NULL DEFAULT 0,
    PRIMARY KEY (asset_id, ts)
) WITHOUT ROWID;

CREATE INDEX IF NOT EXISTS idx_candles_1m_ts ON candles_1m(ts);

-- ---------------------------------------------------------------------
--  A piac globális állapota (egyetlen sor, id = 1)
--
--  sim_now() = time() + sim_offset
--  Az előretekerés csak megnöveli a sim_offset-et, egyébként a szimulált idő
--  1:1-ben követi a valós időt.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS market_state (
    id           INTEGER PRIMARY KEY CHECK (id = 1),
    epoch_start  INTEGER NOT NULL,       -- itt ért véget a valós történet, innen szimulálunk
    sim_offset   INTEGER NOT NULL DEFAULT 0,
    market_seed  INTEGER NOT NULL,
    created_at   INTEGER NOT NULL
);

-- ---------------------------------------------------------------------
--  Felhasználók és fiókok
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    pass_hash   TEXT    NOT NULL,
    created_at  INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS accounts (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name             TEXT    NOT NULL,
    cash_huf         REAL    NOT NULL,      -- szabad készpénz (a letét NEM része)
    initial_capital  REAL    NOT NULL,
    created_at       INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_accounts_user ON accounts(user_id);

-- ---------------------------------------------------------------------
--  Pozíciók és kötések
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS positions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    asset_id    INTEGER NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    side        TEXT    NOT NULL,          -- long | short
    qty         REAL    NOT NULL,
    avg_price   REAL    NOT NULL,
    leverage    REAL    NOT NULL DEFAULT 1,
    margin_huf  REAL    NOT NULL,          -- a lekötött letét
    liq_price   REAL    NOT NULL,
    opened_at   INTEGER NOT NULL,
    closed_at   INTEGER,
    status      TEXT    NOT NULL DEFAULT 'open'   -- open | closed | liquidated
);

CREATE INDEX IF NOT EXISTS idx_positions_account ON positions(account_id, status);
CREATE INDEX IF NOT EXISTS idx_positions_open    ON positions(status, asset_id);

CREATE TABLE IF NOT EXISTS trades (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id    INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    position_id   INTEGER,
    asset_id      INTEGER NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    action        TEXT    NOT NULL,        -- open | close | liquidate
    side          TEXT    NOT NULL,        -- long | short
    qty           REAL    NOT NULL,
    price         REAL    NOT NULL,
    fee_huf       REAL    NOT NULL DEFAULT 0,
    realized_pnl  REAL    NOT NULL DEFAULT 0,
    ts            INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_trades_account ON trades(account_id, ts DESC);

-- ---------------------------------------------------------------------
--  A portfólió értékgörbéje (a vonalas grafikonhoz)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equity_snapshots (
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    ts          INTEGER NOT NULL,
    equity_huf  REAL    NOT NULL,
    PRIMARY KEY (account_id, ts)
) WITHOUT ROWID;

-- ---------------------------------------------------------------------
--  Hírek / piaci események
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS news (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id    INTEGER REFERENCES assets(id) ON DELETE CASCADE,  -- NULL = piaci szintű
    ts          INTEGER NOT NULL,
    headline    TEXT    NOT NULL,
    impact_pct  REAL    NOT NULL,
    severity    TEXT    NOT NULL DEFAULT 'normal'   -- minor | normal | major
);

CREATE INDEX IF NOT EXISTS idx_news_ts    ON news(ts DESC);
CREATE INDEX IF NOT EXISTS idx_news_asset ON news(asset_id, ts DESC);

-- ---------------------------------------------------------------------
--  Beállítások
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    key    TEXT PRIMARY KEY,
    value  TEXT NOT NULL
) WITHOUT ROWID;
