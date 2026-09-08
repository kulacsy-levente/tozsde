<?php
/**
 * A program egyetlen oldala.
 *
 * A PHP itt csak három dolgot csinál: ellenőrzi, hogy telepítve van-e a
 * program, kiadja a CSRF-tokent, és eldönti, hogy a front-end könyvtárak
 * helyi példányát vagy a CDN-t hivatkozza. Minden más a böngészőben történik.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/server/bootstrap.php';

use App\Core\Session;
use App\Service\Installer;

if (!Installer::isInstalled()) {
    header('Location: setup.php');
    exit;
}

Session::start();
$csrf = Session::csrfToken();

/** Helyi fájl, ha letöltöttük; különben CDN. */
function asset_url(string $relative, string $cdn): string
{
    $local = config('vendor_dir') . '/' . $relative;
    return is_file($local) && filesize($local) > 512
        ? 'assets/vendor/' . $relative
        : $cdn;
}

$bootstrapCss = asset_url('bootstrap/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');
$bootstrapJs  = asset_url('bootstrap/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js');
$faCss        = asset_url('fontawesome/css/all.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css');
$chartsJs     = asset_url('lightweight-charts/lightweight-charts.standalone.production.js',
    'https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js');
?>
<!doctype html>
<html lang="hu" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<title>Tőzsdeszimulátor</title>

<link rel="stylesheet" href="<?= htmlspecialchars($bootstrapCss, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($faCss, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="assets/css/style.css?v=1">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📈</text></svg>">
</head>
<body>

<!-- ================================================================
     Belépő képernyő
     ================================================================ -->
<div id="auth-screen" class="auth-screen">
  <div class="auth-card">
    <div class="auth-brand">
      <i class="fa-solid fa-chart-line"></i>
      <div>
        <h1>Tőzsdeszimulátor</h1>
        <p>Valós idejű piac, valós pénz nélkül</p>
      </div>
    </div>

    <ul class="nav nav-pills nav-fill mb-3" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-auth-tab="login" type="button">Belépés</button></li>
      <li class="nav-item"><button class="nav-link" data-auth-tab="register" type="button">Új fiók</button></li>
    </ul>

    <form id="auth-form" autocomplete="off" novalidate>
      <div class="mb-3">
        <label class="form-label" for="auth-username">Felhasználónév</label>
        <input class="form-control" id="auth-username" name="username" required maxlength="32" autocomplete="username">
      </div>

      <div class="mb-3">
        <label class="form-label" for="auth-password">Jelszó</label>
        <input class="form-control" id="auth-password" name="password" type="password" required
               autocomplete="current-password">
      </div>

      <div class="mb-3 register-only d-none">
        <label class="form-label" for="auth-capital">Kezdőtőke</label>
        <div class="input-group">
          <input class="form-control" id="auth-capital" name="capital" type="text" inputmode="numeric" value="10 000 000">
          <span class="input-group-text">Ft</span>
        </div>
        <div class="form-text">Ezzel indulsz. Bármikor létrehozhatsz másik portfóliót eltérő tőkével.</div>
      </div>

      <div id="auth-error" class="alert alert-danger py-2 px-3 d-none" role="alert"></div>

      <button class="btn btn-primary w-100 py-2" type="submit">
        <span class="label">Belépés</span>
      </button>
    </form>
  </div>
</div>

<!-- ================================================================
     Alkalmazás
     ================================================================ -->
<div id="app" class="app d-none">

  <!-- ---------------- Fejléc ---------------- -->
  <header class="topbar">
    <div class="topbar-brand">
      <i class="fa-solid fa-chart-line"></i>
      <span>Tőzsde</span>
    </div>

    <div class="clock" title="A szimuláció ideje">
      <i class="fa-regular fa-clock"></i>
      <span id="sim-clock">–</span>
    </div>

    <div class="stat-strip">
      <div class="stat">
        <span class="stat-label">Vagyon</span>
        <span class="stat-value" id="stat-equity">–</span>
      </div>
      <div class="stat">
        <span class="stat-label">Szabad készpénz</span>
        <span class="stat-value" id="stat-cash">–</span>
      </div>
      <div class="stat">
        <span class="stat-label">Lekötött letét</span>
        <span class="stat-value" id="stat-margin">–</span>
      </div>
      <div class="stat">
        <span class="stat-label">Eredmény</span>
        <span class="stat-value" id="stat-pnl">–</span>
      </div>
    </div>

    <div class="topbar-actions">
      <div class="ff-group" role="group" aria-label="Előretekerés">
        <span class="ff-label"><i class="fa-solid fa-forward"></i><span class="ff-text">Előretekerés</span></span>
        <button class="btn btn-sm btn-outline-secondary" data-ff="1h">1 óra</button>
        <button class="btn btn-sm btn-outline-secondary" data-ff="1d">1 nap</button>
        <button class="btn btn-sm btn-outline-secondary" data-ff="1w">1 hét</button>
        <button class="btn btn-sm btn-outline-secondary" data-ff="1m">1 hónap</button>
        <button class="btn btn-sm btn-outline-secondary" data-ff="1y">1 év</button>
      </div>

      <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
          <i class="fa-solid fa-user"></i> <span id="current-user">–</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><h6 class="dropdown-header">Portfóliók</h6></li>
          <li><div id="account-list"></div></li>
          <li><hr class="dropdown-divider"></li>
          <li><button class="dropdown-item" id="btn-new-account"><i class="fa-solid fa-plus fa-fw"></i> Új portfólió</button></li>
          <li><button class="dropdown-item" id="btn-reset"><i class="fa-solid fa-rotate-left fa-fw"></i> Visszaállítás…</button></li>
          <li><hr class="dropdown-divider"></li>
          <li><button class="dropdown-item text-danger" id="btn-logout"><i class="fa-solid fa-right-from-bracket fa-fw"></i> Kilépés</button></li>
        </ul>
      </div>
    </div>
  </header>

  <!-- ---------------- Fő elrendezés ---------------- -->
  <main class="layout">

    <!-- Bal: eszközlista -->
    <aside class="panel market-panel">
      <div class="panel-head">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
          <input class="form-control" id="asset-search" placeholder="Keresés…" autocomplete="off">
        </div>
        <div class="market-filters btn-group btn-group-sm w-100 mt-2">
          <button class="btn btn-outline-secondary active" data-filter="all">Mind</button>
          <button class="btn btn-outline-secondary" data-filter="crypto">Kripto</button>
          <button class="btn btn-outline-secondary" data-filter="intl">Nemzetközi</button>
          <button class="btn btn-outline-secondary" data-filter="hu">Magyar</button>
        </div>
      </div>
      <div class="asset-list" id="asset-list"></div>
    </aside>

    <!-- Közép: grafikon -->
    <section class="panel chart-panel">
      <div class="chart-head">
        <div class="chart-title">
          <h2 id="chart-symbol">–</h2>
          <span id="chart-name" class="text-secondary"></span>
          <span id="chart-source" class="badge"></span>
        </div>

        <div class="chart-price">
          <span id="chart-last">–</span>
          <span id="chart-change" class="change"></span>
        </div>

        <div class="chart-controls">
          <div class="btn-group btn-group-sm" role="group">
            <button class="btn btn-outline-secondary active" data-tf="1m">
              <i class="fa-solid fa-chart-simple"></i> Gyertya · 1 perc
            </button>
            <button class="btn btn-outline-secondary" data-tf="1d">
              <i class="fa-solid fa-chart-line"></i> Vonal · napi
            </button>
          </div>
        </div>
      </div>

      <div class="chart-body">
        <div id="chart"></div>
        <div id="chart-loading" class="chart-loading d-none">
          <div class="spinner-border spinner-border-sm"></div> Adatok betöltése…
        </div>
      </div>

      <div class="chart-foot">
        <span id="chart-hint" class="text-secondary small"></span>
      </div>
    </section>

    <!-- Jobb: kereskedés -->
    <aside class="panel trade-panel">
      <div class="trade-sides btn-group w-100">
        <button class="btn btn-buy active" data-side="long">
          <i class="fa-solid fa-arrow-trend-up"></i> Vétel <small>(long)</small>
        </button>
        <button class="btn btn-sell" data-side="short">
          <i class="fa-solid fa-arrow-trend-down"></i> Eladás <small>(short)</small>
        </button>
      </div>

      <div class="trade-body">
        <div class="mb-2">
          <label class="form-label small mb-1">Befektetett összeg (letét)</label>
          <div class="input-group input-group-sm">
            <input class="form-control" id="trade-amount" inputmode="numeric" value="500 000">
            <span class="input-group-text">Ft</span>
          </div>
          <div class="quick-amounts">
            <button class="btn btn-xs btn-outline-secondary" data-amount="100000">100e</button>
            <button class="btn btn-xs btn-outline-secondary" data-amount="500000">500e</button>
            <button class="btn btn-xs btn-outline-secondary" data-amount="1000000">1M</button>
            <button class="btn btn-xs btn-outline-secondary" data-amount="max">MAX</button>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label small mb-1 d-flex justify-content-between">
            <span>Tőkeáttét</span>
            <strong id="lev-value">1×</strong>
          </label>
          <input type="range" class="form-range" id="trade-leverage" min="1" max="5" step="1" value="1">
          <div class="lev-scale"><span>1×</span><span>2×</span><span>3×</span><span>4×</span><span>5×</span></div>
        </div>

        <dl class="trade-preview">
          <div><dt>Árfolyam</dt><dd id="pv-price">–</dd></div>
          <div><dt>Mennyiség</dt><dd id="pv-qty">–</dd></div>
          <div><dt>Pozíció mérete</dt><dd id="pv-notional">–</dd></div>
          <div><dt>Kereskedési díj</dt><dd id="pv-fee">–</dd></div>
          <div class="highlight"><dt>Likvidációs ár</dt><dd id="pv-liq">–</dd></div>
        </dl>

        <div id="trade-error" class="alert alert-danger py-2 px-3 small d-none"></div>

        <button class="btn btn-buy w-100 py-2" id="btn-trade">
          <i class="fa-solid fa-bolt"></i> <span id="btn-trade-label">Vétel</span>
        </button>
      </div>

      <div class="positions">
        <div class="positions-head">
          <span>Nyitott pozíciók</span>
          <span class="badge text-bg-secondary" id="positions-count">0</span>
        </div>
        <div id="positions-list" class="positions-list"></div>
      </div>
    </aside>
  </main>

  <!-- ---------------- Alsó fülek ---------------- -->
  <section class="bottom">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-tab="portfolio"><i class="fa-solid fa-wallet"></i> Portfólió</button></li>
      <li class="nav-item"><button class="nav-link" data-tab="history"><i class="fa-solid fa-clock-rotate-left"></i> Előzmények</button></li>
      <li class="nav-item"><button class="nav-link" data-tab="news"><i class="fa-solid fa-newspaper"></i> Hírek</button></li>
      <li class="nav-item"><button class="nav-link" data-tab="about"><i class="fa-solid fa-circle-info"></i> Névjegy</button></li>
      <li class="nav-item ms-auto"><button class="nav-link" id="btn-collapse-bottom" title="Összecsukás"><i class="fa-solid fa-chevron-down"></i></button></li>
    </ul>

    <div class="tab-body">
      <div class="tab-pane active" data-pane="portfolio">
        <div class="portfolio-grid">
          <div class="equity-chart-wrap">
            <div class="pane-title">Portfólió értéke</div>
            <div id="equity-chart"></div>
          </div>
          <div class="portfolio-stats" id="portfolio-stats"></div>
        </div>
      </div>

      <div class="tab-pane" data-pane="history">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Idő</th><th>Eszköz</th><th>Művelet</th><th class="text-end">Mennyiség</th>
                <th class="text-end">Árfolyam</th><th class="text-end">Díj</th><th class="text-end">Eredmény</th>
              </tr>
            </thead>
            <tbody id="history-body"></tbody>
          </table>
        </div>
      </div>

      <div class="tab-pane" data-pane="news">
        <div id="news-list" class="news-list"></div>
      </div>

      <div class="tab-pane" data-pane="about">
        <div class="about">
          <h3>Hogyan működik?</h3>
          <p>
            A piac <strong>1 valós másodperc = 1 szimulált másodperc</strong> ütemben halad, tehát
            egy perces gyertya 60 másodperc alatt épül fel. Az árfolyamok egy seedelt, determinisztikus
            modellből számolódnak: közös piaci faktor mozgatja együtt a papírokat, a volatilitás
            klaszterekben ingadozik, a hírek pedig valódi ugrásokat okoznak.
          </p>
          <p>
            Ezért nem kell háttérfolyamat: ha napokig nem nyitod meg, a következő indításkor
            <em>bepótolja a kimaradt időt</em>. Az előretekerő gombok ugyanezt a motort futtatják,
            csak nagyobb lépésben — élesben, tehát a pozícióid elszámolódnak és a likvidációk lefutnak.
          </p>
          <h3>Adatok</h3>
          <p id="about-data">
            Az induló árfolyamtörténet valós piaci adat (Binance és Yahoo Finance), forintra váltva.
            Innentől minden mozgás szimulált.
          </p>
          <h3>Kereskedés</h3>
          <p>
            A vételi és eladási ár között <strong>spread</strong> van, a kötésekre pedig
            <strong>jutalék</strong> jár. Tőkeáttéttel a letéted többszöröséért vehetsz pozíciót —
            de ha az ár eléri a likvidációs szintet, a pozíció automatikusan zárul, és a letét elveszik.
            A letétnél többet nem tudsz bukni.
          </p>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- ================================================================
     Párbeszédablakok
     ================================================================ -->
<div class="modal fade" id="modal-account" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Új portfólió</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="new-account-name">Név</label>
          <input class="form-control" id="new-account-name" maxlength="40" placeholder="pl. Kockázatos stratégia">
        </div>
        <div class="mb-1">
          <label class="form-label" for="new-account-capital">Kezdőtőke</label>
          <div class="input-group">
            <input class="form-control" id="new-account-capital" inputmode="numeric" value="10 000 000">
            <span class="input-group-text">Ft</span>
          </div>
        </div>
        <div id="account-error" class="alert alert-danger py-2 px-3 small mt-3 d-none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
        <button class="btn btn-primary" id="btn-create-account">Létrehozás</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-reset" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Visszaállítás</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="reset-option" data-scope="account">
          <div class="reset-icon"><i class="fa-solid fa-wallet"></i></div>
          <div>
            <strong>Fiók visszaállítása</strong>
            <p>A pozícióid és az előzményeid törlődnek, a készpénz visszaáll a kezdőtőkére.
               A piac megy tovább, az árfolyamok érintetlenek.</p>
          </div>
        </div>
        <div class="reset-option" data-scope="market">
          <div class="reset-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
          <div>
            <strong>Piac visszatekerése</strong>
            <p>A szimulált idő visszaugrik oda, ahol a valós történet véget ért.
               Ugyanaz a világ indul újra, ugyanazokkal az árpályákkal.</p>
          </div>
        </div>
        <div class="reset-option" data-scope="world">
          <div class="reset-icon"><i class="fa-solid fa-dice"></i></div>
          <div>
            <strong>Új világ</strong>
            <p>Új seedek: minden árfolyam más pályát jár be, a fiókod is nullázódik.
               A valós múlt helyét is generált történet veszi át.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="toasts" class="toast-stack"></div>

<div id="busy" class="busy d-none">
  <div class="busy-card">
    <div class="spinner-border"></div>
    <div id="busy-text">Szimuláció fut…</div>
  </div>
</div>

<script src="<?= htmlspecialchars($bootstrapJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($chartsJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<script type="module" src="assets/js/app.js?v=1"></script>
</body>
</html>
