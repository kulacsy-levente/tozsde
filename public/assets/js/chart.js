/**
 * Grafikonok a TradingView Lightweight Charts könyvtárral.
 *
 * Két nézet ugyanabban a konténerben:
 *   - gyertyás, 1 perces bontás, alatta volumen (a rövid táv)
 *   - területi vonal, napi bontás (a hosszú táv)
 *
 * IDŐZÓNA
 * -------
 * A könyvtár a UNIX időbélyegeket UTC szerint feliratozza. A fejlécben viszont
 * budapesti idő van. Hogy a kettő ne mondjon ellent egymásnak, minden
 * időbélyeget eltolunk a böngésző időzónájával, mielőtt a grafikonnak adnánk —
 * és visszatoljuk, amikor a tooltiphez formázunk. Az eltolás egyetlen helyen,
 * itt történik, így nem tud elcsúszni.
 */

import * as fmt from './format.js';

const LWC = window.LightweightCharts;

/** Másodpercben: mennyivel tér el a helyi idő a UTC-től. */
function tzOffset() {
  return -new Date().getTimezoneOffset() * 60;
}

export const toChartTime   = (ts) => Number(ts) + tzOffset();
export const fromChartTime = (t)  => Number(t) - tzOffset();

const THEME = {
  layout: {
    background: { type: 'solid', color: '#11151f' },
    textColor: '#7d899c',
    fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
  },
  grid: {
    vertLines: { color: 'rgba(35,44,61,0.55)' },
    horzLines: { color: 'rgba(35,44,61,0.55)' },
  },
  crosshair: {
    mode: 1,
    vertLine: { color: '#4c8dff', width: 1, style: 3, labelBackgroundColor: '#4c8dff' },
    horzLine: { color: '#4c8dff', width: 1, style: 3, labelBackgroundColor: '#4c8dff' },
  },
  rightPriceScale: { borderColor: '#232c3d', scaleMargins: { top: 0.08, bottom: 0.25 } },
  timeScale: { borderColor: '#232c3d', timeVisible: true, secondsVisible: false },
};

/**
 * A fő árfolyamgrafikon.
 */
export class PriceChart {
  constructor(container, { onCrosshair = null } = {}) {
    this.container = container;
    this.onCrosshair = onCrosshair;

    this.timeframe = '1m';
    this.decimals = 0;
    this.markersByTime = new Map();
    this.priceLines = [];

    this.chart = LWC.createChart(container, {
      ...THEME,
      autoSize: true,
      localization: {
        locale: 'hu-HU',
        priceFormatter: (p) => fmt.price(p, this.decimals),
      },
    });

    this.candleSeries = this.chart.addCandlestickSeries({
      upColor: '#26a69a', downColor: '#ef5350',
      borderUpColor: '#26a69a', borderDownColor: '#ef5350',
      wickUpColor: '#26a69a', wickDownColor: '#ef5350',
      priceFormat: { type: 'price', precision: 0, minMove: 0.01 },
    });

    this.volumeSeries = this.chart.addHistogramSeries({
      priceFormat: { type: 'volume' },
      priceScaleId: 'volume',
      lastValueVisible: false,
      priceLineVisible: false,
    });
    this.chart.priceScale('volume').applyOptions({
      scaleMargins: { top: 0.82, bottom: 0 },
      visible: false,
    });

    this.areaSeries = this.chart.addAreaSeries({
      lineColor: '#4c8dff',
      topColor: 'rgba(76,141,255,0.28)',
      bottomColor: 'rgba(76,141,255,0.02)',
      lineWidth: 2,
      priceFormat: { type: 'price', precision: 0, minMove: 0.01 },
      visible: false,
    });

    this.#buildTooltip();
    this.chart.subscribeCrosshairMove((param) => this.#handleCrosshair(param));
  }

  // ------------------------------------------------------------------
  //  Adatbetöltés
  // ------------------------------------------------------------------

  /**
   * @param {object} payload a candles végpont válasza
   */
  load(payload) {
    const { candles, volume, markers, meta } = payload;

    this.timeframe = meta.timeframe;
    this.decimals = meta.decimals ?? 0;
    this.symbol = meta.symbol;

    const isMinute = this.timeframe === '1m';

    this.candleSeries.applyOptions({ visible: isMinute });
    this.volumeSeries.applyOptions({ visible: isMinute });
    this.areaSeries.applyOptions({ visible: !isMinute });

    this.chart.applyOptions({
      timeScale: { timeVisible: isMinute, secondsVisible: false },
    });

    const shifted = candles.map((c) => ({ ...c, time: toChartTime(c.time) }));

    if (isMinute) {
      this.candleSeries.setData(shifted);
      this.volumeSeries.setData(volume.map((v) => ({ ...v, time: toChartTime(v.time) })));
      this.areaSeries.setData([]);
    } else {
      this.areaSeries.setData(shifted.map((c) => ({ time: c.time, value: c.close })));
      this.candleSeries.setData([]);
      this.volumeSeries.setData([]);
    }

    // Hírmarkerek — a tooltip is ezekből olvas
    this.markersByTime = new Map();
    const target = isMinute ? this.candleSeries : this.areaSeries;
    const visible = [];

    for (const m of markers ?? []) {
      // Percesnél a hír a nap elejére esik; csak akkor rakjuk ki, ha
      // tényleg beleesik a látható tartományba
      const t = toChartTime(m.time);
      this.markersByTime.set(t, m);
      visible.push({
        time: t,
        position: m.position,
        color: m.color,
        shape: m.shape,
        text: m.text,
      });
    }

    // A napi nézetben megjelöljük, hol ér véget az importált valós történet
    // és hol kezdődik a szimuláció. Enélkül nem lehetne tudni, meddig
    // tükrözi a grafikon a valóságot.
    if (!isMinute && meta.epoch_start && shifted.length) {
      const epoch = toChartTime(meta.epoch_start);
      const first = shifted[0].time;
      const last = shifted[shifted.length - 1].time;

      if (epoch > first && epoch <= last) {
        // A legközelebbi tényleges adatpontra igazítjuk, mert a könyvtár
        // csak létező időbélyegre tesz markert
        const anchor = shifted.reduce(
          (best, c) => (Math.abs(c.time - epoch) < Math.abs(best - epoch) ? c.time : best),
          first
        );

        visible.push({
          time: anchor,
          position: 'inBar',
          color: '#7d899c',
          shape: 'circle',
          text: 'szimuláció innen',
        });
      }
    }

    target.setMarkers(visible.sort((a, b) => a.time - b.time));

    this.#clearPriceLines();
    this.lastCandle = shifted.length ? shifted[shifted.length - 1] : null;
    this.chart.timeScale().fitContent();
  }

  /** Az élő (még nyitott) perces gyertya frissítése. */
  updateLive(candle) {
    if (this.timeframe !== '1m' || !candle) return;

    const point = { ...candle, time: toChartTime(candle.time) };
    this.candleSeries.update(point);
    this.lastCandle = point;
  }

  /** A napi nézet utolsó pontjának frissítése az aktuális árral. */
  updateLast(price) {
    if (this.timeframe !== '1d' || !this.lastCandle) return;
    this.areaSeries.update({ time: this.lastCandle.time, value: price });
  }

  // ------------------------------------------------------------------
  //  Árszintvonalak (belépő ár, likvidációs szint)
  // ------------------------------------------------------------------

  /**
   * A vonalakat azzal a sorozattal együtt tároljuk, amelyikre kirajzoltuk.
   * Nézetváltáskor ugyanis már a *másik* sorozat az aktuális, és ha onnan
   * próbálnánk törölni, a régi vonalak örökre ott ragadnának.
   */
  #clearPriceLines() {
    for (const { series, line } of this.priceLines) {
      try { series.removePriceLine(line); } catch (e) { /* már eltűnt */ }
    }
    this.priceLines = [];
  }

  /**
   * A megjelenített eszközhöz tartozó pozíciók szintjeinek kirajzolása.
   * @param {Array} positions a portfólió pozíciói
   */
  showPositions(positions) {
    this.#clearPriceLines();

    const series = this.timeframe === '1m' ? this.candleSeries : this.areaSeries;
    const mine = positions.filter((p) => p.symbol === this.symbol);

    for (const p of mine) {
      const isLong = p.side === 'long';

      this.priceLines.push({
        series,
        line: series.createPriceLine({
          price: p.avg_price,
          color: isLong ? '#26a69a' : '#ef5350',
          lineWidth: 1,
          lineStyle: 2,
          axisLabelVisible: true,
          title: (isLong ? 'Vétel' : 'Short') + ' ' + fmt.qty(p.qty),
        }),
      });

      this.priceLines.push({
        series,
        line: series.createPriceLine({
          price: p.liq_price,
          color: '#e3b341',
          lineWidth: 1,
          lineStyle: 1,
          axisLabelVisible: true,
          title: 'Likvidáció',
        }),
      });
    }
  }

  // ------------------------------------------------------------------
  //  Tooltip
  // ------------------------------------------------------------------

  #buildTooltip() {
    this.tooltip = document.createElement('div');
    this.tooltip.className = 'chart-tooltip';
    this.tooltip.style.display = 'none';
    this.container.appendChild(this.tooltip);
  }

  #handleCrosshair(param) {
    const hide = () => { this.tooltip.style.display = 'none'; };

    if (!param.point || !param.time || param.point.x < 0 || param.point.y < 0) {
      hide();
      if (this.onCrosshair) this.onCrosshair(null);
      return;
    }

    const series = this.timeframe === '1m' ? this.candleSeries : this.areaSeries;
    const data = param.seriesData.get(series);
    if (!data) { hide(); return; }

    const ts = fromChartTime(param.time);
    const news = this.markersByTime.get(param.time);
    const d = this.decimals;

    let rows;
    if (this.timeframe === '1m') {
      rows = `
        <div class="t-row"><span>Nyitó</span><span>${fmt.price(data.open, d)}</span></div>
        <div class="t-row"><span>Max</span><span>${fmt.price(data.high, d)}</span></div>
        <div class="t-row"><span>Min</span><span>${fmt.price(data.low, d)}</span></div>
        <div class="t-row"><span>Záró</span><span>${fmt.price(data.close, d)}</span></div>`;
    } else {
      rows = `<div class="t-row"><span>Árfolyam</span><span>${fmt.price(data.value, d)} Ft</span></div>`;
    }

    this.tooltip.innerHTML = `
      <div class="t-time">${this.timeframe === '1m' ? fmt.dateTime(ts) : fmt.dateOnly(ts)}</div>
      ${rows}
      ${news ? `<div class="t-news"><i class="fa-solid fa-newspaper"></i> ${news.headline} (${news.text})</div>` : ''}`;

    // A tooltipet a kurzor mellé tesszük, de nem lógatjuk ki a panelből
    const box = this.container.getBoundingClientRect();
    const tw = this.tooltip.offsetWidth || 180;
    const th = this.tooltip.offsetHeight || 90;

    let left = param.point.x + 18;
    if (left + tw > box.width) left = param.point.x - tw - 18;

    let top = param.point.y + 18;
    if (top + th > box.height) top = param.point.y - th - 18;

    this.tooltip.style.left = Math.max(4, left) + 'px';
    this.tooltip.style.top = Math.max(4, top) + 'px';
    this.tooltip.style.display = 'block';

    if (this.onCrosshair) this.onCrosshair({ time: ts, data });
  }

  destroy() {
    this.chart.remove();
  }
}

/**
 * A portfólió értékgörbéje — ugyanaz a könyvtár, sokkal kevesebb dísszel.
 */
export class EquityChart {
  constructor(container) {
    this.chart = LWC.createChart(container, {
      ...THEME,
      autoSize: true,
      rightPriceScale: { borderColor: '#232c3d', scaleMargins: { top: 0.15, bottom: 0.1 } },
      timeScale: { borderColor: '#232c3d', timeVisible: false, secondsVisible: false },
      localization: { locale: 'hu-HU', priceFormatter: (p) => fmt.ftShort(p, true) },
      handleScroll: false,
      handleScale: false,
    });

    this.series = this.chart.addAreaSeries({
      lineColor: '#4c8dff',
      topColor: 'rgba(76,141,255,0.3)',
      bottomColor: 'rgba(76,141,255,0.02)',
      lineWidth: 2,
    });

    this.baseline = null;
  }

  /**
   * @param {Array} points  [{time, value}]
   * @param {number} initial a kezdőtőke — ezt húzzuk be viszonyítási vonalnak
   */
  load(points, initial) {
    // Egy időbélyeghez csak egy pont tartozhat, különben a könyvtár hibát dob
    const seen = new Set();
    const data = [];

    for (const p of points) {
      const t = toChartTime(p.time);
      if (seen.has(t)) {
        data[data.length - 1].value = p.value;
        continue;
      }
      seen.add(t);
      data.push({ time: t, value: p.value });
    }

    this.series.setData(data);

    if (this.baseline) {
      try { this.series.removePriceLine(this.baseline); } catch (e) { /* nincs */ }
    }
    if (initial > 0) {
      this.baseline = this.series.createPriceLine({
        price: initial,
        color: '#7d899c',
        lineWidth: 1,
        lineStyle: 2,
        axisLabelVisible: true,
        title: 'Kezdőtőke',
      });
    }

    this.chart.timeScale().fitContent();
  }
}
