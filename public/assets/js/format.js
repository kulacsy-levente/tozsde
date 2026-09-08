/**
 * Magyar számformázás.
 *
 * Egy helyen, mert a tőzsdei felület tele van számokkal, és a legrosszabb,
 * ami történhet, hogy két panel másképp kerekít ugyanarra az értékre.
 */

const HU = 'hu-HU';

/** Forintösszeg. Nagy számoknál nincs tizedes — úgyis olvashatatlan lenne. */
export function ft(value, decimals = null) {
  const n = Number(value) || 0;
  const abs = Math.abs(n);

  if (decimals === null) {
    decimals = abs >= 1000 ? 0 : abs >= 10 ? 1 : 2;
  }

  return n.toLocaleString(HU, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }) + ' Ft';
}

/**
 * Rövidített forint: 12,4 M Ft.
 *
 * A `precise` kapcsoló grafikontengelyekhez való. Egy értékgörbe skálája
 * gyakran szűk sávot fog át (9,99 M – 10,01 M), és ott a kétjegyű kerekítés
 * három egyforma feliratot adna: mindegyik „10 M Ft" lenne.
 */
export function ftShort(value, precise = false) {
  const n = Number(value) || 0;
  const abs = Math.abs(n);
  const digits = precise ? 3 : 2;

  if (abs >= 1e9) return (n / 1e9).toLocaleString(HU, { maximumFractionDigits: digits }) + ' Mrd Ft';
  if (abs >= 1e6) return (n / 1e6).toLocaleString(HU, { maximumFractionDigits: digits }) + ' M Ft';
  if (abs >= 1e4) return (n / 1e3).toLocaleString(HU, { maximumFractionDigits: precise ? 1 : 0 }) + ' e Ft';
  return ft(n);
}

/** Árfolyam az eszköz saját tizedespontosságával. */
export function price(value, decimals = 0) {
  const n = Number(value) || 0;
  // Nagyon kicsi árnál (pl. DOGE) több tizedes kell, különben nullát mutatnánk
  const d = Math.abs(n) < 1 ? Math.max(decimals, 4) : decimals;

  return n.toLocaleString(HU, {
    minimumFractionDigits: d,
    maximumFractionDigits: d,
  });
}

/** Mennyiség — a felesleges nullák levágásával. */
export function qty(value, decimals = 4) {
  const n = Number(value) || 0;
  if (Number.isInteger(n)) return n.toLocaleString(HU);

  return n.toLocaleString(HU, { maximumFractionDigits: decimals });
}

/** Százalék, mindig előjellel. */
export function pct(value, decimals = 2) {
  const n = Number(value) || 0;
  const s = n.toLocaleString(HU, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  return (n > 0 ? '+' : '') + s + '%';
}

/** Előjeles forint. */
export function signedFt(value) {
  const n = Number(value) || 0;
  return (n > 0 ? '+' : '') + ft(n);
}

/** Az érték irányát jelző CSS-osztály. */
export function dirClass(value) {
  const n = Number(value) || 0;
  if (n > 0) return 'up';
  if (n < 0) return 'down';
  return '';
}

// --- Idő ------------------------------------------------------------

function d(ts) {
  return new Date(Number(ts) * 1000);
}

export function dateTime(ts) {
  return d(ts).toLocaleString(HU, {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  });
}

export function dateOnly(ts) {
  return d(ts).toLocaleDateString(HU, { year: 'numeric', month: '2-digit', day: '2-digit' });
}

export function shortDate(ts) {
  return d(ts).toLocaleDateString(HU, { month: '2-digit', day: '2-digit' });
}

export function timeOnly(ts) {
  return d(ts).toLocaleTimeString(HU, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

export function dateAndTime(ts) {
  return d(ts).toLocaleString(HU, {
    month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  });
}

// --- Beolvasás ------------------------------------------------------

/**
 * Felhasználói szám beolvasása.
 * Elfogadja a szóközzel tagolt és a vesszős tizedesű alakot is:
 * "10 000 000", "10000000", "1,5" mind működik.
 */
export function parseNumber(text) {
  if (typeof text === 'number') return text;

  const cleaned = String(text ?? '')
    .replace(/[\s  ]/g, '')
    .replace(/\./g, '')
    .replace(',', '.');

  const n = parseFloat(cleaned);
  return Number.isFinite(n) ? n : 0;
}

/** Beviteli mező élő formázása gépelés közben. */
export function attachNumberFormatting(input) {
  input.addEventListener('blur', () => {
    const n = parseNumber(input.value);
    input.value = n > 0 ? n.toLocaleString(HU, { maximumFractionDigits: 2 }) : '';
  });
}
