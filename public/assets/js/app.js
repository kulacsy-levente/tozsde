/**
 * A felület vezérlése.
 *
 * Felépítés:
 *   - state          : minden, amit a képernyő mutat
 *   - boot()         : indulás, belépés vagy alkalmazás
 *   - startLoop()    : a másodpercenkénti tick
 *   - render*()      : a panelek kirajzolása
 *
 * A tick minden másodpercben egyetlen kérést küld, és abból frissül a fejléc,
 * az eszközlista, a grafikon élő gyertyája és a pozíciók eredménye. Az
 * eszközsorokat nem építjük újra, csak a szövegüket írjuk át — ötven sor
 * másodpercenkénti újragenerálása feleslegesen dolgoztatná a böngészőt.
 */

import { api, onUnauthorized, ApiError } from './api.js';
import * as fmt from './format.js';
import { PriceChart, EquityChart } from './chart.js';

// =====================================================================
//  Állapot
// =====================================================================

const state = {
  user: null,
  accounts: [],
  accountId: null,
  config: {},

  assets: [],
  assetBySymbol: new Map(),
  filter: 'all',
  search: '',

  symbol: localStorage.getItem('symbol') || 'BTC',
  timeframe: localStorage.getItem('timeframe') || '1m',

  side: 'long',
  leverage: 1,

  portfolio: null,
  prices: {},
  simNow: 0,

  tab: 'portfolio',
  loopId: null,
  busy: false,
};

let priceChart = null;
let equityChart = null;
const rowRefs = new Map();     // szimbólum -> a listasor DOM-elemei

const $  = (sel) => document.querySelector(sel);
const $$ = (sel) => Array.from(document.querySelectorAll(sel));

// =====================================================================
//  Indulás
// =====================================================================

async function boot() {
  onUnauthorized(() => showAuth());
  bindStaticEvents();

  try {
    const me = await api.me();

    if (!me.installed) {
      window.location.href = 'setup.php';
      return;
    }

    state.config = me.config ?? {};

    if (me.user) {
      state.user = me.user;
      state.accounts = me.accounts ?? [];
      state.accountId = me.account_id;
      await showApp();
    } else {
      showAuth();
    }
  } catch (e) {
    if (e.code === 'not_installed') {
      window.location.href = 'setup.php';
      return;
    }
    showAuth();
    authError(e.message);
  }
}

// =====================================================================
//  Belépés
// =====================================================================

let authMode = 'login';

function showAuth() {
  stopLoop();
  $('#auth-screen').style.display = '';
  $('#app').classList.add('d-none');
}

function authError(message) {
  const box = $('#auth-error');
  box.textContent = message;
  box.classList.toggle('d-none', !message);
}

function setAuthMode(mode) {
  authMode = mode;

  $$('[data-auth-tab]').forEach((b) => b.classList.toggle('active', b.dataset.authTab === mode));
  $$('.register-only').forEach((el) => el.classList.toggle('d-none', mode !== 'register'));
  $('#auth-form .label').textContent = mode === 'login' ? 'Belépés' : 'Fiók létrehozása';
  $('#auth-password').autocomplete = mode === 'login' ? 'current-password' : 'new-password';

  authError('');
}

async function submitAuth(event) {
  event.preventDefault();
  authError('');

  const username = $('#auth-username').value.trim();
  const password = $('#auth-password').value;
  const button = $('#auth-form button[type=submit]');

  button.disabled = true;

  try {
    if (authMode === 'login') {
      await api.login(username, password);
    } else {
      await api.register(username, password, fmt.parseNumber($('#auth-capital').value), '');
    }

    const me = await api.me();
    state.user = me.user;
    state.accounts = me.accounts ?? [];
    state.accountId = me.account_id;
    state.config = me.config ?? {};

    $('#auth-form').reset();
    await showApp();
  } catch (e) {
    authError(e.message);
  } finally {
    button.disabled = false;
  }
}

// =====================================================================
//  Az alkalmazás megjelenítése
// =====================================================================

async function showApp() {
  $('#auth-screen').style.display = 'none';
  $('#app').classList.remove('d-none');

  $('#current-user').textContent = state.user?.username ?? '–';
  renderAccounts();

  // A tőkeáttét felső határa a szerverből jön
  const maxLev = state.config.max_leverage ?? 5;
  $('#trade-leverage').max = String(maxLev);
  $('.lev-scale').innerHTML = Array.from({ length: maxLev }, (_, i) => `<span>${i + 1}×</span>`).join('');

  if (!priceChart) {
    priceChart = new PriceChart($('#chart'));
    equityChart = new EquityChart($('#equity-chart'));
  }

  await loadAssets();
  await selectAsset(state.symbol, true);
  await refreshPortfolio();
  await loadNews();

  startLoop();
}

// =====================================================================
//  Eszközlista
// =====================================================================

async function loadAssets() {
  const data = await api.assets();
  state.assets = data.assets;
  state.assetBySymbol = new Map(state.assets.map((a) => [a.symbol, a]));

  if (!state.assetBySymbol.has(state.symbol)) {
    state.symbol = state.assets[0]?.symbol ?? 'BTC';
  }

  renderAssetList();
}

/** Mini árgörbe SVG-ként — könyvtár nélkül, néhány sorból. */
function sparkline(values, up) {
  if (!values || values.length < 2) return '';

  const w = 56, h = 24, pad = 2;
  const min = Math.min(...values);
  const max = Math.max(...values);
  const span = max - min || 1;

  const points = values.map((v, i) => {
    const x = pad + (i / (values.length - 1)) * (w - pad * 2);
    const y = h - pad - ((v - min) / span) * (h - pad * 2);
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');

  const color = up ? '#26a69a' : '#ef5350';
  return `<svg class="asset-spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
            <polyline points="${points}" fill="none" stroke="${color}" stroke-width="1.4"
                      stroke-linejoin="round" stroke-linecap="round"/>
          </svg>`;
}

const MARKET_LABEL = { crypto: 'kripto', intl: 'nemzetközi', hu: 'magyar' };

function visibleAssets() {
  const q = state.search.toLowerCase();

  return state.assets.filter((a) => {
    if (state.filter !== 'all' && a.market !== state.filter) return false;
    if (!q) return true;
    return a.symbol.toLowerCase().includes(q) || a.name.toLowerCase().includes(q);
  });
}

function renderAssetList() {
  const list = $('#asset-list');
  const items = visibleAssets();

  rowRefs.clear();

  if (!items.length) {
    list.innerHTML = '<div class="empty-state"><i class="fa-solid fa-magnifying-glass"></i>Nincs találat</div>';
    return;
  }

  list.innerHTML = items.map((a) => {
    const up = a.change_pct >= 0;
    return `
      <div class="asset-row ${a.symbol === state.symbol ? 'active' : ''}" data-symbol="${a.symbol}">
        <div class="asset-id">
          <div class="asset-symbol">
            ${a.symbol}
            <span class="asset-badge">${MARKET_LABEL[a.market] ?? a.market}</span>
          </div>
          <div class="asset-name">${a.name}</div>
        </div>
        ${sparkline(a.spark, up)}
        <div class="asset-figures">
          <div class="asset-price num">${fmt.price(a.price, a.decimals)}</div>
          <div class="asset-change num ${fmt.dirClass(a.change_pct)}">${fmt.pct(a.change_pct)}</div>
        </div>
      </div>`;
  }).join('');

  // Az elemek eltárolása, hogy a tick csak a szöveget írja át
  for (const row of list.querySelectorAll('.asset-row')) {
    rowRefs.set(row.dataset.symbol, {
      row,
      price: row.querySelector('.asset-price'),
      change: row.querySelector('.asset-change'),
      lastPrice: null,
    });
  }
}

/** Csak az árakat frissíti, a DOM újraépítése nélkül. */
function updateAssetPrices(prices) {
  for (const [symbol, ref] of rowRefs) {
    const p = prices[symbol];
    if (!p) continue;

    const asset = state.assetBySymbol.get(symbol);
    if (asset) {
      asset.price = p.price;
      asset.change_pct = p.change_pct;
    }

    ref.price.textContent = fmt.price(p.price, asset?.decimals ?? 0);
    ref.change.textContent = fmt.pct(p.change_pct);
    ref.change.className = 'asset-change num ' + fmt.dirClass(p.change_pct);

    // Rövid villanás, ha mozdult az ár — ettől él a lista
    if (ref.lastPrice !== null && p.price !== ref.lastPrice) {
      const cls = p.price > ref.lastPrice ? 'flash-up' : 'flash-down';
      ref.price.classList.remove('flash-up', 'flash-down');
      void ref.price.offsetWidth;
      ref.price.classList.add(cls);
    }
    ref.lastPrice = p.price;
  }
}

// =====================================================================
//  Grafikon
// =====================================================================

async function selectAsset(symbol, force = false) {
  if (!force && symbol === state.symbol) return;

  state.symbol = symbol;
  localStorage.setItem('symbol', symbol);

  $$('.asset-row').forEach((r) => r.classList.toggle('active', r.dataset.symbol === symbol));

  await loadChart();
  updateTradePreview();
  await loadNews();
}

async function loadChart() {
  $('#chart-loading').classList.remove('d-none');

  try {
    const data = await api.candles(state.symbol, state.timeframe, 1500);
    const meta = data.meta;

    state.meta = meta;
    priceChart.load(data);

    if (state.portfolio) {
      priceChart.showPositions(state.portfolio.positions);
    }

    $('#chart-symbol').textContent = meta.symbol;
    $('#chart-name').textContent = meta.name;

    const source = $('#chart-source');
    source.textContent = meta.data_source === 'real' ? 'valós múlt' : 'generált múlt';
    source.title = meta.data_source === 'real'
      ? 'Az induló árfolyamtörténet valós piaci adat'
      : 'Ehhez az eszközhöz nem sikerült valós adatot letölteni, a történet generált';

    const hint = state.timeframe === '1m'
      ? `1 perces gyertyák, ${state.config.retention_days ?? 3} napra visszamenőleg · a jobb szélső gyertya élőben épül`
      : 'Napi záróárak · a szaggatott vonalak a pozícióid szintjei';
    $('#chart-hint').textContent = hint;

    updateChartHeader();
  } catch (e) {
    toast('Nem sikerült betölteni a grafikont', e.message, 'error');
  } finally {
    $('#chart-loading').classList.add('d-none');
  }
}

function updateChartHeader() {
  const p = state.prices[state.symbol];
  if (!p) return;

  const decimals = state.meta?.decimals ?? 0;
  $('#chart-last').textContent = fmt.price(p.price, decimals) + ' Ft';

  const change = $('#chart-change');
  change.textContent = `${fmt.signedFt(p.change)} (${fmt.pct(p.change_pct)})`;
  change.className = 'change num ' + fmt.dirClass(p.change_pct);
}

// =====================================================================
//  A másodpercenkénti ciklus
// =====================================================================

function startLoop() {
  stopLoop();
  tick();
  state.loopId = setInterval(tick, 1000);
}

function stopLoop() {
  if (state.loopId) clearInterval(state.loopId);
  state.loopId = null;
}

let tickFailures = 0;

async function tick() {
  if (state.busy || document.hidden) return;

  try {
    const data = await api.tick(state.symbol);
    tickFailures = 0;

    state.simNow = data.sim_now;
    state.prices = data.prices;

    $('#sim-clock').textContent = fmt.dateTime(data.sim_now);

    updateAssetPrices(data.prices);
    updateChartHeader();

    if (state.timeframe === '1m') {
      priceChart.updateLive(data.live);
    } else if (data.prices[state.symbol]) {
      priceChart.updateLast(data.prices[state.symbol].price);
    }

    if (data.portfolio) {
      state.portfolio = data.portfolio;
      renderHeaderStats();
      renderPositions();
      updateTradePreview();
    }

    if (data.liquidated?.length) {
      for (const l of data.liquidated) {
        toast('Likvidálás', `${l.symbol} pozíciód automatikusan lezárult ${fmt.ft(l.price)} árfolyamon.`, 'error');
      }
      await refreshPortfolio();
    }
  } catch (e) {
    tickFailures++;
    // Egy-két kimaradt tick nem érdekes, de ha tartósan nincs kapcsolat,
    // ne bombázzuk tovább a szervert másodpercenként
    if (tickFailures === 5) {
      toast('Megszakadt a kapcsolat', e.message, 'error');
      stopLoop();
      setTimeout(startLoop, 5000);
    }
  }
}

// =====================================================================
//  Fejléc
// =====================================================================

function renderHeaderStats() {
  const p = state.portfolio;
  if (!p) return;

  $('#stat-equity').textContent = fmt.ft(p.equity);
  $('#stat-cash').textContent = fmt.ft(p.cash);
  $('#stat-margin').textContent = fmt.ft(p.margin_used);

  const pnl = $('#stat-pnl');
  pnl.textContent = `${fmt.signedFt(p.total_pnl)} (${fmt.pct(p.total_pnl_pct)})`;
  pnl.className = 'stat-value num ' + fmt.dirClass(p.total_pnl);
}

function renderAccounts() {
  $('#account-list').innerHTML = state.accounts.map((a) => `
    <button class="dropdown-item ${a.id == state.accountId ? 'active' : ''}" data-account="${a.id}">
      <i class="fa-solid ${a.id == state.accountId ? 'fa-circle-check' : 'fa-circle'} fa-fw"></i>
      ${a.name}
      <small class="text-secondary d-block ms-4">${fmt.ftShort(a.initial_capital)} kezdőtőke</small>
    </button>`).join('');
}

// =====================================================================
//  Pozíciók
// =====================================================================

function renderPositions() {
  const list = $('#positions-list');
  const positions = state.portfolio?.positions ?? [];

  $('#positions-count').textContent = String(positions.length);

  if (!positions.length) {
    list.innerHTML = `<div class="empty-state">
        <i class="fa-solid fa-inbox"></i>
        Még nincs nyitott pozíciód.<br>Válassz eszközt és köss egyet.
      </div>`;
    return;
  }

  list.innerHTML = positions.map((p) => `
    <div class="position" data-position="${p.id}">
      <div class="position-top">
        <span class="position-symbol" data-goto="${p.symbol}">${p.symbol}</span>
        <span class="side-tag ${p.side}">${p.side === 'long' ? 'long' : 'short'}</span>
        ${p.leverage > 1.01 ? `<span class="lev-tag">${p.leverage.toFixed(1)}×</span>` : ''}
        <span class="position-pnl ${fmt.dirClass(p.pnl)}">${fmt.signedFt(p.pnl)}</span>
      </div>
      <div class="position-meta">
        <span>${fmt.qty(p.qty)} db</span>
        <span>átlag ${fmt.price(p.avg_price, p.decimals)}</span>
        <span>most ${fmt.price(p.price, p.decimals)}</span>
        <span class="${fmt.dirClass(p.pnl)}">${fmt.pct(p.pnl_pct)}</span>
      </div>
      <div class="position-meta">
        <span>letét ${fmt.ft(p.margin)}</span>
        <span style="color:var(--warn)">likvidáció ${fmt.price(p.liq_price, p.decimals)}</span>
      </div>
      <div class="position-actions">
        <button class="btn btn-xs btn-outline-secondary" data-close="${p.id}" data-part="0.5">Fele</button>
        <button class="btn btn-xs btn-outline-danger" data-close="${p.id}" data-part="1">Zárás</button>
      </div>
    </div>`).join('');
}

async function closePosition(positionId, part) {
  const position = state.portfolio.positions.find((p) => p.id === Number(positionId));
  if (!position) return;

  const qty = part >= 1 ? 0 : position.qty * part;

  try {
    const result = await api.close(Number(positionId), qty);
    state.portfolio = result.portfolio;

    const pnl = result.trade.realized_pnl;
    toast(
      'Pozíció zárva',
      `${result.trade.symbol} · ${fmt.qty(result.trade.qty)} db · eredmény ${fmt.signedFt(pnl)}`,
      pnl >= 0 ? 'ok' : 'warn'
    );

    renderHeaderStats();
    renderPositions();
    priceChart.showPositions(state.portfolio.positions);
    refreshPortfolioTab();
  } catch (e) {
    toast('Nem sikerült zárni', e.message, 'error');
  }
}

// =====================================================================
//  Kereskedési panel
// =====================================================================

function setSide(side) {
  state.side = side;

  $$('.trade-sides .btn').forEach((b) => b.classList.toggle('active', b.dataset.side === side));

  const button = $('#btn-trade');
  button.classList.toggle('btn-buy', side === 'long');
  button.classList.toggle('btn-sell', side === 'short');
  $('#btn-trade-label').textContent = side === 'long' ? 'Vétel (long)' : 'Eladás (short)';

  updateTradePreview();
}

function tradeAmount() {
  return fmt.parseNumber($('#trade-amount').value);
}

/**
 * Előnézet: mennyiség, méret, díj és likvidációs ár.
 * Ugyanazzal a képlettel számol, mint a szerver — a végleges értékeket
 * persze mindig a szerver mondja ki.
 */
function updateTradePreview() {
  const meta = state.meta;
  const p = state.prices[state.symbol];

  if (!meta || !p) return;

  const amount = tradeAmount();
  const leverage = state.leverage;
  const spread = meta.spread ?? 0;
  const fee = meta.fee ?? 0;
  const decimals = meta.decimals ?? 0;

  const execPrice = state.side === 'long' ? p.price * (1 + spread) : p.price * (1 - spread);

  // összeg = letét + díj = notional/áttét + notional*díjkulcs
  const notional = amount > 0 ? amount / (1 / leverage + fee) : 0;
  const step = meta.qty_step || 1;
  const rawQty = execPrice > 0 ? notional / execPrice : 0;
  const qty = Math.floor(rawQty / step + 1e-9) * step;

  const realNotional = qty * execPrice;
  const margin = realNotional / leverage;
  const feeHuf = realNotional * fee;

  const buffer = margin * 0.8;   // a fenntartási követelmény 20%
  const distance = qty > 0 ? buffer / qty : 0;
  const liq = state.side === 'long'
    ? Math.max(0, execPrice - distance)
    : execPrice + distance;

  $('#pv-price').textContent = fmt.price(execPrice, decimals) + ' Ft';
  $('#pv-qty').textContent = qty > 0 ? `${fmt.qty(qty)} ${meta.symbol}` : '–';
  $('#pv-notional').textContent = fmt.ft(realNotional);
  $('#pv-fee').textContent = fmt.ft(feeHuf);
  $('#pv-liq').textContent = qty > 0 ? fmt.price(liq, decimals) + ' Ft' : '–';

  const valid = qty >= (meta.min_qty ?? 0) && qty > 0;
  $('#btn-trade').disabled = !valid;

  const error = $('#trade-error');
  if (amount > 0 && !valid) {
    error.textContent = `Ez az összeg kevés: a legkisebb köthető mennyiség ${fmt.qty(meta.min_qty)} ${meta.symbol}.`;
    error.classList.remove('d-none');
  } else if (state.portfolio && amount > state.portfolio.cash) {
    error.textContent = `Ennyi szabad készpénzed nincs (${fmt.ft(state.portfolio.cash)}).`;
    error.classList.remove('d-none');
  } else {
    error.classList.add('d-none');
  }
}

async function submitTrade() {
  const button = $('#btn-trade');
  button.disabled = true;

  try {
    const result = await api.trade({
      symbol: state.symbol,
      side: state.side,
      amount: tradeAmount(),
      leverage: state.leverage,
    });

    state.portfolio = result.portfolio;
    const t = result.trade;

    toast(
      state.side === 'long' ? 'Pozíció nyitva' : 'Short pozíció nyitva',
      `${t.symbol} · ${fmt.qty(t.qty)} db · ${fmt.ft(t.price)} · letét ${fmt.ft(t.margin)}`,
      'ok'
    );

    renderHeaderStats();
    renderPositions();
    priceChart.showPositions(state.portfolio.positions);
    refreshPortfolioTab();
  } catch (e) {
    toast('A kötés nem ment végbe', e.message, 'error');
  } finally {
    button.disabled = false;
    updateTradePreview();
  }
}

// =====================================================================
//  Előretekerés
// =====================================================================

const HORIZON_LABEL = { '1h': '1 óra', '1d': '1 nap', '1w': '1 hét', '1m': '1 hónap', '1y': '1 év' };

async function fastForward(horizon) {
  if (state.busy) return;

  setBusy(true, `${HORIZON_LABEL[horizon]} szimulálása…`);

  try {
    const result = await api.simulate(horizon);
    const sim = result.simulation;

    state.portfolio = result.portfolio;

    await loadAssets();
    await loadChart();
    await refreshPortfolio();
    await loadNews();

    // Egy órás ugrásnál a „0 nap telt el" félrevezető lenne, és a pontos
    // időpont is érdekesebb, mint a puszta dátum
    const short = sim.seconds < 86400;

    const parts = [
      short
        ? `${Math.round(sim.seconds / 3600)} óra telt el`
        : `${sim.days} nap telt el`,
      short ? fmt.dateTime(sim.to) : `az új dátum ${fmt.dateOnly(sim.to)}`,
    ];
    if (sim.resolution === 'minute') {
      parts.push('percről percre szimulálva');
    }
    if (sim.liquidations.length) {
      parts.push(`${sim.liquidations.length} pozíció likvidálódott`);
    }

    toast(
      `Előretekerés: ${HORIZON_LABEL[horizon]}`,
      parts.join(' · '),
      sim.liquidations.length ? 'warn' : 'ok'
    );

    for (const l of sim.liquidations) {
      toast('Likvidálás', `${l.symbol} · ${l.date} · ${fmt.ft(l.price)}`, 'error');
    }
  } catch (e) {
    toast('A szimuláció megszakadt', e.message, 'error');
  } finally {
    setBusy(false);
  }
}

function setBusy(busy, text = '') {
  state.busy = busy;
  $('#busy').classList.toggle('d-none', !busy);
  if (text) $('#busy-text').textContent = text;
  $$('[data-ff]').forEach((b) => { b.disabled = busy; });
}

// =====================================================================
//  Alsó fülek
// =====================================================================

async function refreshPortfolio() {
  try {
    const data = await api.portfolio();
    state.portfolio = data.portfolio;
    state.stats = data.stats;
    state.equityCurve = data.equity;

    renderHeaderStats();
    renderPositions();
    renderPortfolioTab();

    if (priceChart) priceChart.showPositions(state.portfolio.positions);
  } catch (e) {
    if (!(e instanceof ApiError) || e.code !== 'unauthorized') {
      toast('A portfólió nem töltődött be', e.message, 'error');
    }
  }
}

/** Csak akkor tölt újra, ha a portfólió fül épp látszik. */
function refreshPortfolioTab() {
  if (state.tab === 'portfolio') refreshPortfolio();
  if (state.tab === 'history') loadHistory();
}

function renderPortfolioTab() {
  const p = state.portfolio;
  const s = state.stats;
  if (!p || !s) return;

  equityChart.load(state.equityCurve ?? [], p.account.initial_capital);

  const rows = [
    ['Kezdőtőke', fmt.ft(p.account.initial_capital)],
    ['Jelenlegi vagyon', fmt.ft(p.equity)],
    ['Eredmény', `<span class="${fmt.dirClass(p.total_pnl)}">${fmt.signedFt(p.total_pnl)}</span>`],
    ['Hozam', `<span class="${fmt.dirClass(p.total_pnl)}">${fmt.pct(p.total_pnl_pct)}</span>`],
    ['Szabad készpénz', fmt.ft(p.cash)],
    ['Lekötött letét', fmt.ft(p.margin_used)],
    ['Lebegő eredmény', `<span class="${fmt.dirClass(p.unrealized)}">${fmt.signedFt(p.unrealized)}</span>`],
    ['Nyitott pozíciók', String(p.positions.length)],
    ['Kötések száma', String(s.trades)],
    ['Nyereséges zárások', s.closed > 0 ? `${s.wins} / ${s.closed} (${s.win_rate.toFixed(0)}%)` : '–'],
    ['Likvidálások', String(s.liquidations)],
    ['Fizetett díjak', fmt.ft(s.fees)],
  ];

  $('#portfolio-stats').innerHTML = rows
    .map(([label, value]) => `<div class="pstat"><span>${label}</span><span>${value}</span></div>`)
    .join('');
}

async function loadHistory() {
  try {
    const data = await api.history(200);
    const body = $('#history-body');

    if (!data.history.length) {
      body.innerHTML = '<tr><td colspan="7" class="text-center text-secondary py-4">Még nincs egyetlen kötésed sem.</td></tr>';
      return;
    }

    const ACTION = {
      open: '<span class="badge text-bg-primary">nyitás</span>',
      close: '<span class="badge text-bg-secondary">zárás</span>',
      liquidate: '<span class="badge text-bg-danger">likvidálás</span>',
    };

    body.innerHTML = data.history.map((t) => `
      <tr>
        <td class="text-secondary">${fmt.dateAndTime(t.ts)}</td>
        <td><strong>${t.symbol}</strong>
            <span class="side-tag ${t.side}">${t.side}</span></td>
        <td>${ACTION[t.action] ?? t.action}</td>
        <td class="text-end">${fmt.qty(t.qty)}</td>
        <td class="text-end">${fmt.price(t.price, t.decimals)}</td>
        <td class="text-end text-secondary">${fmt.ft(t.fee_huf)}</td>
        <td class="text-end ${fmt.dirClass(t.realized_pnl)}">
          ${t.action === 'open' ? '–' : fmt.signedFt(t.realized_pnl)}
        </td>
      </tr>`).join('');
  } catch (e) {
    toast('Az előzmények nem töltődtek be', e.message, 'error');
  }
}

async function loadNews() {
  try {
    const data = await api.news('', 60);
    const list = $('#news-list');

    if (!data.news.length) {
      list.innerHTML = `<div class="empty-state">
          <i class="fa-solid fa-newspaper"></i>
          Még nem történt semmi említésre méltó.<br>
          Tekerj előre egy hetet, és jönni fognak a hírek.
        </div>`;
      return;
    }

    list.innerHTML = data.news.map((n) => `
      <div class="news-item">
        <span class="news-date">${fmt.dateOnly(n.ts)}</span>
        <span class="news-tag">${n.symbol ?? 'PIAC'}</span>
        <span>${n.headline}</span>
        <span class="news-impact ${fmt.dirClass(n.impact_pct)}">${fmt.pct(n.impact_pct, 1)}</span>
      </div>`).join('');
  } catch (e) {
    /* a hírek nem kritikusak, csendben hagyjuk */
  }
}

function switchTab(tab) {
  state.tab = tab;

  $$('.bottom .nav-link[data-tab]').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
  $$('.tab-pane').forEach((p) => p.classList.toggle('active', p.dataset.pane === tab));

  if (tab === 'portfolio') refreshPortfolio();
  if (tab === 'history') loadHistory();
  if (tab === 'news') loadNews();
}

// =====================================================================
//  Fiókok és visszaállítás
// =====================================================================

async function createAccount() {
  const name = $('#new-account-name').value.trim();
  const capital = fmt.parseNumber($('#new-account-capital').value);
  const error = $('#account-error');

  try {
    await api.createAccount(name, capital);

    const me = await api.me();
    state.accounts = me.accounts;
    state.accountId = me.account_id;

    bootstrap.Modal.getInstance($('#modal-account'))?.hide();
    renderAccounts();
    await refreshPortfolio();

    toast('Új portfólió', `„${name || 'Portfólió'}" létrehozva ${fmt.ft(capital)} kezdőtőkével.`, 'ok');
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('d-none');
  }
}

async function switchAccount(accountId) {
  try {
    await api.switchAccount(accountId);
    state.accountId = Number(accountId);

    renderAccounts();
    await refreshPortfolio();
    await loadHistory();

    const account = state.accounts.find((a) => a.id == accountId);
    toast('Portfólióváltás', `Aktív: ${account?.name ?? ''}`, 'ok');
  } catch (e) {
    toast('Nem sikerült a váltás', e.message, 'error');
  }
}

const RESET_CONFIRM = {
  account: 'Biztosan visszaállítod a fiókot? A pozícióid és az előzményeid törlődnek.',
  market: 'Biztosan visszatekered a piacot oda, ahol a valós történet véget ért?',
  world: 'Biztosan új világot indítasz? Minden árfolyampálya megváltozik, és a fiókod is nullázódik.',
};

async function doReset(scope) {
  if (!confirm(RESET_CONFIRM[scope])) return;

  bootstrap.Modal.getInstance($('#modal-reset'))?.hide();
  setBusy(true, 'Visszaállítás…');

  try {
    await api.reset(scope);

    await loadAssets();
    await loadChart();
    await refreshPortfolio();
    await loadHistory();
    await loadNews();

    const LABEL = { account: 'Fiók visszaállítva', market: 'Piac visszatekerve', world: 'Új világ indult' };
    toast(LABEL[scope], 'Kész.', 'ok');
  } catch (e) {
    toast('A visszaállítás nem sikerült', e.message, 'error');
  } finally {
    setBusy(false);
  }
}

// =====================================================================
//  Értesítések
// =====================================================================

function toast(title, message, kind = 'info') {
  const el = document.createElement('div');
  el.className = `toast-msg ${kind}`;
  el.innerHTML = `<strong>${title}</strong>${message ?? ''}`;

  $('#toasts').appendChild(el);

  setTimeout(() => {
    el.classList.add('fade-out');
    setTimeout(() => el.remove(), 260);
  }, kind === 'error' ? 7000 : 4200);
}

// =====================================================================
//  Eseménykezelők
// =====================================================================

function bindStaticEvents() {
  // --- Belépő ---
  $$('[data-auth-tab]').forEach((b) => b.addEventListener('click', () => setAuthMode(b.dataset.authTab)));
  $('#auth-form').addEventListener('submit', submitAuth);

  // --- Eszközlista ---
  $('#asset-search').addEventListener('input', (e) => {
    state.search = e.target.value.trim();
    renderAssetList();
  });

  $$('[data-filter]').forEach((b) => b.addEventListener('click', () => {
    state.filter = b.dataset.filter;
    $$('[data-filter]').forEach((x) => x.classList.toggle('active', x === b));
    renderAssetList();
  }));

  $('#asset-list').addEventListener('click', (e) => {
    const row = e.target.closest('.asset-row');
    if (row) selectAsset(row.dataset.symbol);
  });

  // --- Grafikon ---
  $$('[data-tf]').forEach((b) => b.addEventListener('click', async () => {
    state.timeframe = b.dataset.tf;
    localStorage.setItem('timeframe', state.timeframe);
    $$('[data-tf]').forEach((x) => x.classList.toggle('active', x === b));
    await loadChart();
  }));

  // --- Kereskedés ---
  $$('.trade-sides .btn').forEach((b) => b.addEventListener('click', () => setSide(b.dataset.side)));

  $('#trade-amount').addEventListener('input', updateTradePreview);
  fmt.attachNumberFormatting($('#trade-amount'));

  $$('[data-amount]').forEach((b) => b.addEventListener('click', () => {
    const value = b.dataset.amount === 'max'
      ? Math.floor((state.portfolio?.cash ?? 0))
      : Number(b.dataset.amount);
    $('#trade-amount').value = value.toLocaleString('hu-HU');
    updateTradePreview();
  }));

  $('#trade-leverage').addEventListener('input', (e) => {
    state.leverage = Number(e.target.value);
    $('#lev-value').textContent = state.leverage + '×';
    updateTradePreview();
  });

  $('#btn-trade').addEventListener('click', submitTrade);

  $('#positions-list').addEventListener('click', (e) => {
    const closeBtn = e.target.closest('[data-close]');
    if (closeBtn) {
      closePosition(closeBtn.dataset.close, Number(closeBtn.dataset.part));
      return;
    }
    const goto = e.target.closest('[data-goto]');
    if (goto) selectAsset(goto.dataset.goto);
  });

  // --- Előretekerés ---
  $$('[data-ff]').forEach((b) => b.addEventListener('click', () => fastForward(b.dataset.ff)));

  // --- Fülek ---
  $$('.bottom .nav-link[data-tab]').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));

  $('#btn-collapse-bottom').addEventListener('click', () => {
    const bottom = $('.bottom');
    bottom.classList.toggle('collapsed');
    const icon = $('#btn-collapse-bottom i');
    icon.className = bottom.classList.contains('collapsed')
      ? 'fa-solid fa-chevron-up'
      : 'fa-solid fa-chevron-down';
  });

  // --- Fiókok ---
  $('#btn-new-account').addEventListener('click', () => {
    $('#account-error').classList.add('d-none');
    new bootstrap.Modal($('#modal-account')).show();
  });
  $('#btn-create-account').addEventListener('click', createAccount);

  $('#account-list').addEventListener('click', (e) => {
    const item = e.target.closest('[data-account]');
    if (item) switchAccount(item.dataset.account);
  });

  $('#btn-reset').addEventListener('click', () => new bootstrap.Modal($('#modal-reset')).show());
  $$('.reset-option').forEach((el) => el.addEventListener('click', () => doReset(el.dataset.scope)));

  $('#btn-logout').addEventListener('click', async () => {
    stopLoop();
    await api.logout();
    window.location.reload();
  });

  // Ha visszatérünk a fülre, azonnal frissítünk, ne várjunk a ciklusra
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && state.user) tick();
  });
}

// =====================================================================

boot();
