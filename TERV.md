# Tőzsdeszimulátor — részletes terv

## 0. Rögzített döntések

| Kérdés | Döntés |
|---|---|
| Futtatókörnyezet | XAMPP, PHP 8 + SQLite (PDO). Nincs framework, nincs composer, nincs npm. |
| Szimuláció helye | Szerveroldal az egyetlen igazságforrás. |
| Historikus adat | Valós adat letöltése első indításkor, szintetikus fallbackkel. |
| Időskála | 1 valós másodperc = 1 szimulált másodperc. Egy perces gyertya 60 mp alatt épül fel. |
| Előszimuláció | Élesben előretekeri az időt, a pozíciók elszámolódnak. |
| Fiókok | Több felhasználó, név + jelszó belépéssel. |
| Pénznem | Minden HUF-ban, fix átváltással az importkor. |
| Adatmegőrzés | 1 perces gyertya: **3 nap**. Annál régebben: **csak napi** gyertya. |
| Extrák | Díj + spread, shortolás + tőkeáttét, hírek/piaci események. |
| Előretekerés | 1 óra / 1 nap / 1 hét / 1 hónap / 1 év. A 3 napnál rövidebb ugrás percről percre fut. |
| Kezdőtőke | Alapértelmezés 10 000 000 Ft, fiók létrehozásakor felülírható. |
| Külső könyvtárak | Lightweight Charts (grafikon), Bootstrap 5, Font Awesome — helyben tárolva. |
| Eszközök | 15 kripto + 30 nemzetközi + 30 magyar részvény = 75 eszköz. |

---

## 1. Az árszimuláció motorja (`PriceEngine`)

A központi tervezési kényszer: XAMPP-on **nincs folyamatosan futó háttérfolyamat**. Nem lehet
tehát olyan motort írni, ami magától ketyeg. Helyette a modell **determinisztikus és
visszamenőleg kiszámolható**: bármely nap ára kiszámolható a seedből, függetlenül attól,
hogy futott-e közben a program. Ha három napig nem indítod el, nem „áll meg az idő" —
a következő indításkor bepótolja a kihagyott időszakot.

### 1.1 Kétszintű modell

**Felső szint — napi hozamok.** Minden eszközhöz tartozik egy `seed`. A `d` sorszámú
szimulált napra determinisztikusan (seed + d hash-eléséből) generálódik:

```
r_d  =  mu  +  beta * F_d  +  sigma_d * eps_d  +  shock_d
```

- `mu` — az eszköz sodródása (drift), az importált valós történetből becsülve
- `F_d` — **közös piaci faktor**: egyetlen, az egész piacra ható napi hozam. Ettől mozognak
  együtt a papírok, ahogy a valóságban is (`beta` eszközönként különböző)
- `sigma_d` — **volatilitás-klaszterezés** (GARCH-szerű): `sigma_d² = w + a*r²_(d-1) + b*sigma²_(d-1)`.
  Ez adja a valósághűséget: a nyugodt szakaszokat nyugodt, a viharosakat viharos napok követik
- `eps_d` — normális eloszlású zaj (Box–Muller a seedelt PRNG-ből)
- `shock_d` — híresemény hatása, ha aznap van (lásd 4. pont)

A napi gyertya OHLC-je a hozamból és egy seedelt intraday-tartományból áll össze.
Kriptónál magasabb alapvolatilitás és 24/7 kereskedés; részvénynél alacsonyabb, és
hétvégén nincs mozgás (a napi gyertya kimarad).

**Alsó szint — perces út.** Egy adott napon belüli 1440 perces gyertya egy
**Brown-híddal** készül: a nap nyitóárából indul, a záróárba érkezik, és menet közben
érinti a napi minimumot/maximumot. Szintén seedelt, tehát ugyanaz a nap újraszámolva
mindig ugyanazt a perces képet adja.

Miért fontos ez a kétszintűség: egy év előretekerése így **365 lépés eszközönként**,
nem 525 600. Az egy éves gomb ~0,2 mp alatt lefut ahelyett, hogy percekig őrölne.

**Másodperces tick.** A kliens másodpercenként kap egy árat. A perces gyertyán belüli
60 másodperc a szerver által küldött perc-kezdő és perc-vég ár közti seedelt mikro-út,
így a nyíló gyertya folyamatosan, hihetően rezeg, és a perc végén pontosan a
szerver által ismert értéken zár.

### 1.2 Miért nem elég egy sima véletlen bolyongás

Egy naiv `ár += random()` felismerhetően hamis: nincsenek benne trendek, nincsenek csendes
és pánikos időszakok, és nem mozognak együtt a papírok. A fenti három elem (közös faktor,
volatilitás-klaszterezés, hírsokkok) az, amitől a grafikon „tőzsdének néz ki".

---

## 2. Adatmodell (SQLite)

```sql
assets(id, symbol, name, type[crypto|stock], seed, mu, sigma0, beta,
       tick_size, min_qty, active)

candles_1m(asset_id, ts, o, h, l, c, v)   -- PK(asset_id, ts), csak 3 napra visszamenőleg
candles_1d(asset_id, ts, o, h, l, c, v)   -- PK(asset_id, ts), teljes történet

market_state(id=1, sim_now, epoch_start, market_seed, last_advance)

users(id, username UNIQUE, pass_hash, created_at)
accounts(id, user_id, name, cash_huf, initial_capital, created_at)

positions(id, account_id, asset_id, side[long|short], qty, avg_price,
          leverage, margin_huf, liq_price, opened_at, closed_at, status)
trades(id, account_id, asset_id, action[open|close|liquidate], side, qty,
       price, fee_huf, realized_pnl, ts)
equity_snapshots(account_id, ts, equity_huf)   -- a portfólió vonalas grafikonjához

news(id, asset_id NULL, ts, headline, impact_pct)
settings(key, value)
```

Indexek: `candles_1m(asset_id, ts)`, `candles_1d(asset_id, ts)`,
`positions(account_id, status)`, `trades(account_id, ts)`.
SQLite WAL módban, hogy a párhuzamos olvasás ne akadjon el az íráson.

**Megőrzési szabály.** Minden tick-kérés végén:
`DELETE FROM candles_1m WHERE ts < sim_now - 3 nap`.
Perces gyertya ráadásul **csak arra az eszközre** generálódik, amit épp néz valaki (lazy),
így nem hízik feleslegesen az adatbázis.

---

## 3. Szerveroldali szerkezet

```
public/
  index.php          app shell (belépve: SPA, kilépve: login képernyő)
  api.php            egyetlen JSON végpont, ?action=... alapú útválasztás
  setup.php          első indítás: séma + valós adat import, haladásjelzővel
  .htaccess          a db/ és server/ közvetlen elérésének tiltása
  assets/css/style.css
  assets/js/
    app.js           állapot, 1 mp-es ciklus, panelek, eseménykezelés
    chart.js         a Lightweight Charts burkolója (gyertyás + vonalas + equity)
    api.js           fetch-burkoló, CSRF, hibakezelés
    format.js        magyar szám- és dátumformázás
  assets/vendor/     Bootstrap, Font Awesome, Lightweight Charts (helyben)
server/
  bootstrap.php      autoloader, konfiguráció, hibakezelés
  config.php
  Core/       Db  Http  Request  Response  Session
  Data/       assets.php  (az 50 eszköz definíciója)
  Model/      schema.sql
  Service/    PriceEngine  MarketService  TradeService  PortfolioService
              SimulationService  NewsService  HistoryImporter  AuthService
              Installer  Rng
db/database.sqlite
```

### API végpontok (mind `api.php?action=`)

| Action | Metódus | Leírás |
|---|---|---|
| `register`, `login`, `logout`, `me` | POST/GET | Munkamenet-kezelés |
| `assets` | GET | Eszközlista aktuális árral, napi változással, sparkline-adattal |
| `tick` | GET | Előrehozza a szimulált időt, visszaadja az árakat és a nyitott perc gyertyát |
| `candles` | GET | `symbol`, `tf` (1m/1d), `from`, `to` — a grafikon adatai |
| `portfolio` | GET | Készpénz, pozíciók, lebegő P&L, összérték, equity-görbe |
| `trade` | POST | `symbol`, `side`, `qty` vagy `amount_huf`, `leverage` — nyitás |
| `close` | POST | `position_id`, `qty` (részleges zárás is) |
| `simulate` | POST | `horizon` = `1h` \| `1d` \| `1w` \| `1m` \| `1y` — élesben előretekerés |
| `news` | GET | Hírfolyam |
| `reset` | POST | Világ és/vagy fiók visszaállítása |

Minden állapotváltoztató kérés POST + CSRF-token, a pénzügyi műveletek egyetlen
SQLite-tranzakcióban, hogy fél-végrehajtott trade ne maradhasson.

---

## 4. Hírek és piaci események

Minden szimulált naphoz seedelt módon 0–3 esemény generálódik. Két típus:

- **Eszközspecifikus**: „A(z) {cég} gyorsjelentése {felül/alul}múlta a várakozásokat",
  „Új terméket jelentett be a(z) {cég}", kriptónál szabályozói hír vagy hálózati frissítés.
  Hatás: −12%…+12% az adott napi hozamra.
- **Piaci szintű**: kamatdöntés, inflációs adat, geopolitikai feszültség.
  Hatás a közös `F_d` faktoron keresztül minden papírra, béta szerint eltérő mértékben.

Mivel seedeltek, előretekeréskor is ugyanazok jönnek elő, és a grafikonon
**markerként megjelennek** azon a napon, ahol hatottak — így utólag látszik, mi mozgatta az árat.

---

## 5. Kereskedés, díjak, tőkeáttét

- **Spread**: a mid ár körül vételi/eladási ár. Kripto ±0,05%, részvény ±0,15%.
- **Jutalék**: kripto 0,1%, részvény 0,25%, nyitáskor és záráskor is.
- **Tőkeáttét**: 1x–5x. Letét = pozícióérték / áttét, ez a készpénzből **lekötésre kerül**.
- **Likvidációs ár**: az az árszint, ahol a veszteség eléri a letét 80%-át. A pozíciónál
  kiírva, a grafikonon vonallal jelölve.
- **Likvidáció kiértékelése**: minden ticknél az aktuális ár ellen; előretekeréskor pedig
  **napról napra, a napi minimum/maximum ellen**, kronológiai sorrendben — hogy egy közben
  bekövetkező likvidáció ne maradjon le csak azért, mert a nap végére visszajött az ár.
- Short pozíciónál a P&L előjele fordított, a likvidáció felfelé történik.

---

## 6. Grafikonok és felület (külső könyvtárakkal)

**Grafikonkönyvtár: TradingView Lightweight Charts.** Pontosan erre a feladatra készült:
gyertyás és vonalas/területi sorozat, crosshair, tooltip, zoom, pásztázás, vízszintes
árszintvonalak és időmarkerek — mind beépítve, ~45 KB, framework-független.
A saját canvas-rajzoló helyett ezt használjuk, így a csiszolás a szimulációra és a
kereskedési logikára megy, nem a tengelyfeliratok pixelezgetésére.

**Gyertyás nézet** — 1 perces bontás, 3 napra visszamenőleg, alatta volumen-hisztogram.
A jobb szélső gyertya „élő": másodpercenként frissül (`series.update()`), a perc végén rögzül.
Rárajzolva `createPriceLine()`-nal a belépési árad és a likvidációs szinted, `setMarkers()`-szel
a híresemények.

**Vonalas nézet** — napi bontás, területi (area) sorozat a teljes történettel: az importált
valós múlt és a szimulált jövő egyben, az „itt kezdődik a szimuláció" határ markerrel jelölve.
Ugyanez a komponens rajzolja a **portfólió-értékgörbét** az `equity_snapshots`-ból.

**Felület: Bootstrap 5 + Font Awesome**, sötét témával. Elrendezés:

- **fejléc** — szimulált dátum/idő, szabad készpénz, portfólió összértéke, napi P&L,
  az előretekerő gombok (1 nap / 1 hét / 1 hónap / 1 év) és a reset
- **bal oszlop** — eszközlista kereséssel és típusszűrővel (kripto / nemzetközi / magyar),
  ár, napi %, mini sparkline
- **közép** — a grafikon, nézetváltóval (gyertya / vonal) és időkeret-választóval
- **jobb oszlop** — kereskedési panel (long/short, összeg vagy darabszám, tőkeáttét-csúszka,
  becsült díj, likvidációs ár), alatta a nyitott pozíciók
- **alsó fülek** — Portfólió / Előzmények / Hírek / Beállítások

Mindhárom könyvtár **helyben tárolva** (`public/assets/vendor/`): a `setup.php` letölti őket,
de ha nincs net, CDN-hivatkozásra vált. Így a program offline is használható marad.

---

## 7. Valós historikus adat importja (`HistoryImporter`)

Első indításkor a `setup.php` letölti a valós múltat, **kulcs nélküli, publikus forrásokból**:

- **Kripto**: Binance publikus klines végpont (napi gyertyák, ~2 év) → ha nem elérhető,
  CoinGecko `market_chart` → ha az sem, szintetikus.
- **Részvény**: Yahoo Finance chart API (`query1.finance.yahoo.com/v8/finance/chart/AAPL`),
  kulcs nélkül ad napi OHLC-t, a magyar papírokat is (`OTP.BD`, `MOL.BD`), és ráadásul
  megmondja a jegyzés pénznemét is — ez kell, mert pl. a Graphisoft Park euróban jegyzett.
  *(A terv eredetileg Stooq-ot írt, de az időközben JavaScript-alapú botvédelmet kapott,
  így géppel nem letölthető. A Yahoo mind az 50 eszközre működik.)*
- **Átváltás**: egyszeri, fix USD/HUF kurzus a beállításokból (alap: 360); az importált
  árak ezzel szorzódnak. A magyar papírok eleve forintosak.

A letöltés cURL-lel, `allow_url_fopen` fallbackkel, **eszközönként külön hibakezeléssel**:
ha egy papír nem jön le, csak az az egy megy szintetikusra, a többi valós marad.
A setup képernyő eszközönként kiírja, hogy valós vagy generált adatot kapott.

Az importált utolsó zárónapból indul a szimuláció — tehát a „jelen" onnan folytatódik,
ahol a valós történet véget ért. A `mu` / `sigma0` / `beta` paraméterek is az importált
adatból becsülődnek, ettől lesz minden papírnak saját, valósághű karaktere.

**Eszközkészlet (50):**

- **Kripto (15)**: BTC, ETH, BNB, SOL, XRP, ADA, DOGE, AVAX, LINK, DOT, LTC, BCH, TRX, ATOM, UNI
- **Nemzetközi részvény (30)**: AAPL, MSFT, NVDA, GOOGL, AMZN, META, TSLA, JPM, V, WMT,
  KO, DIS, NFLX, AMD, INTC, BA, MCD, PFE, XOM, NKE, LLY, AVGO, COST, HD, ORCL, ADBE,
  PEP, UBER, CAT, IBM
- **Magyar részvény (30)**: OTP, MOL, Richter, Magyar Telekom, 4iG, Opus Global, Masterplast,
  Waberer's, Rába, Zwack, ANY Nyomda, Alteo, CIG Pannónia, Graphisoft Park, Duna House,
  AutoWallis, PannErgy, Appeninn, Budapesti Ingatlan, Delta Technologies, Akko Invest,
  Nutex, EnviRo Energy, Ormester, Megakrán, Gopd, Pensum, Naturtex, SunDell, Amixa

**Őszinte megjegyzés a magyar papírokról:** a négy nagy (OTP, MOL, Richter, Telekom) megbízhatóan
letölthető a Stooq-ról. A kisebb BÉT-es papíroknál viszont a Stooq lefedettsége hézagos, ezért
egy részük valószínűleg szintetikus történetet fog kapni. Ez nem hiba, hanem a beépített
fallback: ilyenkor egy kézzel megadott, valósághű induló árszintről és volatilitásból indul a
generálás, és a felületen jelölve lesz, melyik papír adata valós és melyik generált.

---

## 8. Reset

Három szint, mind tranzakcióban, megerősítő párbeszéddel:

1. **Fiók visszaállítása** — pozíciók és előzmények törlése, készpénz vissza a kezdőtőkére. A piac megy tovább.
2. **Piac visszatekerése** — a szimulált idő visszaáll az import végére, a generált gyertyák törlődnek. Ugyanaz a világ újra.
3. **Új világ** — új seedek, teljesen más árpályák. Opcionálisan új adatimporttal.

---

## 9. Megvalósítási sorrend

1. Váz, `Db`, séma, `setup.php`, valós adat import fallbackkel
2. `PriceEngine` (napi szint + Brown-híd) és a `tick` végpont
3. Gyertyás canvas grafikon, élő utolsó gyertyával
4. Belépés, fiókok, egyszerű long kereskedés díjjal és spreaddel
5. Short, tőkeáttét, letét, likvidáció
6. Előretekerés (1n/1h/1hó/1év) + equity-görbe + vonalas grafikon
7. Hírek, reset, csiszolás
