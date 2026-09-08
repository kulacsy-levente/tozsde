# Tőzsdeszimulátor

Valós idejű tőzsdeszimuláció XAMPP-ra. Framework nélküli PHP + SQLite a szerveroldalon,
vanilla JavaScript a böngészőben. 75 kereskedhető eszköz (15 kripto, 30 nemzetközi és
30 magyar részvény), valós historikus árfolyamokkal indulva.

---

## Telepítés

### 1. A mappa helye

Másold a projektmappát a XAMPP `htdocs` könyvtárába:

```
C:\xampp\htdocs\tozsde\
```

Két módon futtathatod:

**A) Egyszerű mód** — semmit nem kell beállítani:

```
http://localhost/tozsde/public/setup.php
```

**B) Tisztább mód** — a `public/` mappa lesz a gyökér. A `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
fájlba vedd fel:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/tozsde/public"
    ServerName tozsde.local
    <Directory "C:/xampp/htdocs/tozsde/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

majd a `C:\Windows\System32\drivers\etc\hosts` fájlba: `127.0.0.1  tozsde.local`.

A `db/` és a `server/` mappát mindkét esetben `.htaccess` védi a közvetlen letöltéstől.

### 2. Telepítő futtatása

Nyisd meg a `setup.php`-t böngészőben. Ez létrehozza az adatbázist, letölti a front-end
könyvtárakat helyi tárolásra, és lehozza az 50 eszköz valós árfolyamtörténetét.
Nagyjából 20–30 másodperc.

Parancssorból is megy:

```
C:\xampp\php\php.exe public\setup.php
```

Újratelepítés (a meglévő világ és fiókok törlésével): `setup.php?force=1`, illetve
parancssorból `--force`.

### 3. Használat

```
http://localhost/tozsde/public/
```

Regisztrálj egy felhasználót, add meg a kezdőtőkét (alapból 10 millió forint), és kezdheted.

**Követelmények:** PHP 8.0+ (a XAMPP alapból hoz újabbat), bekapcsolt `pdo_sqlite`
(alapértelmezés szerint be van), és az első telepítéshez internetkapcsolat. Ha nincs net,
a program akkor is települ, csak generált árfolyamtörténettel.

---

## Hogyan működik?

### Az idő

**1 valós másodperc = 1 szimulált másodperc.** Egy perces gyertya tehát 60 másodperc alatt
épül fel, és közben a szemed előtt mozog. A hosszabb távot az előretekerő gombok adják
(**1 óra** / 1 nap / 1 hét / 1 hónap / 1 év), amelyek élesben tekerik előre az órát: a
pozícióid elszámolódnak, a likvidációk lefutnak, és ez lesz az új jelen.

Az előretekerés kétféle felbontásban dolgozik:

| Ugrás | Felbontás | Mit jelent |
|---|---|---|
| 1 óra, 1 nap | **percről percre** | Az órán belüli mozgás is számít: a likvidáció perc pontossággal fut le, és az értékgörbe percenként kap pontot, tehát látszik, mit csinált a piac az ugrás alatt. |
| 1 hét, 1 hónap, 1 év | napról napra | A likvidálást a napi minimum/maximum ellen nézzük. Egy évre 525 600 percet végigjárni értelmetlen lenne, és perces gyertyát úgysem tartunk ilyen régre. |

A határ a perces gyertyák megőrzési ideje (alapból 3 nap): ameddig perces adatunk van,
addig perces felbontásban is szimulálunk.

### Miért nincs háttérfolyamat?

XAMPP-on nincs mit folyamatosan futtatni. A megoldás az, hogy a modell **determinisztikus**:
minden nap árfolyama kiszámolható a seedből, függetlenül attól, hogy futott-e közben a
program. Ha egy hétig nem nyitod meg, a következő indításkor egyszerűen legenerálódik a
kimaradt hét — ugyanaz, mintha végig futott volna.

### Az árak

Kétszintű modell. A napi hozam:

```
r = sodródás + béta × piaci_faktor + volatilitás × zaj + hírsokk
```

- a **közös piaci faktor** miatt mozognak együtt a papírok, ahogy a valóságban is
- a **volatilitás GARCH-szerűen klaszterezik**: nyugodt szakaszokat nyugodt, viharosakat
  viharos napok követnek
- a **zaj vastag farkú**, tehát a nagy zuhanások gyakoribbak, mint amit a normális
  eloszlás jósolna — akárcsak az igazi tőzsdén
- a **hírek valódi ugrásokat okoznak**, és a grafikonon markerként meg is jelennek

A napon belüli percenkénti mozgás egy Brown-híd a nyitó és a záró ár között, felező
eltolással építve. Ugyanaz a rekurzió adja egyetlen perc árát (~11 lépésből) és a teljes
1440 perces utat is, tehát a lista és a grafikon sosem mond ellent egymásnak.

A napi minimum/maximum és a napon belüli út külön keletkezik, ezért az utat még
**rá is feszítjük** a napi ársávra: a nyitó és záró ár közötti rész változatlan marad, a
fölötte-alatta lévő kilengések pedig pontosan a napi maximumig, illetve minimumig
nyúlnak. Enélkül a perces grafikon laposabbnak látszana, mint amit ugyanarra a napra a
napi gyertya mutat.

A modell a valós adatból becsült paraméterekkel indul: minden papír a saját tényleges
volatilitását és piaci együttmozgását örökli. A várható hozamot viszont erősen a nulla
felé húzzuk — két év adatából megjósolni, mi fog hozni, statisztikailag reménytelen, és
enélkül a múltbeli nyertesek örökre szárnyalnának.

### Adattárolás

| | Megőrzés |
|---|---|
| 1 perces gyertya | 3 nap, és csak arra az eszközre, amit épp nézel |
| Napi gyertya | teljes történet (2 év valós múlt + a szimulált jövő) |

Ezért marad az adatbázis pár megabájt akkor is, ha egy évet tekersz előre.

### Kereskedés

- **Spread**: kripto ±0,05%, részvény ±0,15% a középárfolyam körül
- **Jutalék**: kripto 0,1%, részvény 0,25%, nyitáskor és záráskor is
- **Tőkeáttét**: 1×–5×. A letét lekötésre kerül a szabad készpénzből.
- **Short**: lefelé is lehet spekulálni
- **Likvidáció**: ha az ár eléri azt a szintet, ahol a veszteség a letét 80%-át eléri,
  a pozíció automatikusan zárul. A letétnél többet nem lehet bukni.

Egy eszközre és irányra fiókonként egy pozíció létezik; az újabb vétel súlyozott
átlagárral olvad bele a meglévőbe.

### Visszaállítás

A felhasználói menü **Visszaállítás** pontja alatt három szint:

1. **Fiók visszaállítása** — pozíciók és előzmények törlése, készpénz vissza a kezdőtőkére.
   A piac megy tovább.
2. **Piac visszatekerése** — a szimulált idő visszaáll oda, ahol a valós történet véget ért.
   Ugyanaz a világ indul újra.
3. **Új világ** — új seedek, teljesen más árpályák.

---

## Adatforrások

| Eszköztípus | Forrás | Megjegyzés |
|---|---|---|
| Kripto | Binance klines API | kulcs nélküli, publikus |
| Részvény | Yahoo Finance chart API | kulcs nélküli, a pénznemet is megadja |

Az árak forintra váltva kerülnek tárolásra (`server/config.php`: `usd_huf`, `eur_huf`).
Ha egy papír nem tölthető le, csak az az egy vált generált történetre — a többi valós
adattal indul. A felületen a szimbólum mellett látszik, melyik eszköz kapott valós adatot.

**Két igazítás a letöltött adaton.** A ritkán forgó BÉT-es kispapíroknál (SunDell, Gopd,
Pensum, Ormester, Naturtex, Amixa) a napok többségén a maximum pontosan egyenlő a
nyitó- vagy záróárral, mert alig kötnek aznap. Emiatt a napon belüli útnak nem lenne hova
mennie, és a perces grafikon vízszintes vonal lenne — ezért 0,15%-os minimális kanócot
garantálunk. Ugyanezért kap a becsült napi volatilitás egy 0,6%-os padlót: enélkül ezek a
papírok örökre mozdulatlanok maradnának. Mindkettő a napi gyertya képén észrevehetetlen.

---

## Felépítés

```
public/
  index.php            az alkalmazás egyetlen oldala
  api.php              JSON végpont (?action=...)
  setup.php            telepítő
  assets/js/
    app.js             állapot, események, panelek
    chart.js           Lightweight Charts burkoló
    api.js             fetch réteg CSRF-fel
    format.js          magyar szám- és dátumformázás
  assets/vendor/       Bootstrap, Font Awesome, Lightweight Charts (helyben)
server/
  Core/                Db, Http, Request, Response, Session
  Service/
    PriceEngine        az árszimuláció motorja
    HistoryImporter    valós adat letöltése
    TradeService       nyitás, zárás, likvidálás
    PortfolioService   pillanatkép, értékgörbe, statisztika
    SimulationService  előretekerés
    NewsService        hírek és piaci események
    MarketService      eszközlista, gyertyák, tick
    AuthService        felhasználók és fiókok
    Installer          telepítés és visszaállítás
    Rng                determinisztikus véletlengenerátor
  Data/assets.php      az 50 eszköz definíciója
  Model/schema.sql     adatbázisséma
db/database.sqlite
```

## Beállítások

A `server/config.php` fájlban állítható többek között a kezdőtőke, a díjak és a spread,
a maximális tőkeáttét, a perces gyertyák megőrzési ideje, az átváltási árfolyamok, valamint
az árszimuláció paraméterei.

Új eszköz felvételéhez elég egy sort hozzáadni a `server/Data/assets.php` fájlhoz
(szimbólum, név, típus, piac, pénznem, forrás-ticker, referenciaár, volatilitás, béta,
tizedesjegyek), majd újratelepíteni.
