/**
 * Az api.php burkolója.
 *
 * Egyetlen helyen kezeli a CSRF-tokent, a hibákat és a kiesett munkamenetet,
 * hogy a hívó kód csak az adattal foglalkozzon.
 */

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** Ha lejár a munkamenet, az app.js erre iratkozik fel és visszadob a belépőre. */
const listeners = { unauthorized: [] };

export function onUnauthorized(fn) {
  listeners.unauthorized.push(fn);
}

export class ApiError extends Error {
  constructor(message, code, status) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

async function request(action, { method = 'GET', params = {}, body = null } = {}) {
  const url = new URL('api.php', window.location.href);
  url.searchParams.set('action', action);

  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value));
    }
  }

  const options = {
    method,
    headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
    credentials: 'same-origin',
  };

  if (body !== null) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(url, options);
  } catch (e) {
    throw new ApiError('Nem érhető el a szerver. Fut a XAMPP?', 'network', 0);
  }

  let payload;
  try {
    payload = await response.json();
  } catch (e) {
    // Ilyenkor jellemzően PHP-hiba került a válaszba HTML-ként
    throw new ApiError('A szerver érvénytelen választ adott (' + response.status + ').', 'parse', response.status);
  }

  if (!payload.ok) {
    if (payload.code === 'unauthorized') {
      listeners.unauthorized.forEach((fn) => fn());
    }
    throw new ApiError(payload.error || 'Ismeretlen hiba.', payload.code, response.status);
  }

  return payload.data;
}

const get  = (action, params)      => request(action, { params });
const post = (action, body = {})   => request(action, { method: 'POST', body });

export const api = {
  // Munkamenet
  me:            ()                    => get('me'),
  login:         (username, password)  => post('login', { username, password }),
  register:      (username, password, capital, account_name) =>
                                          post('register', { username, password, capital, account_name }),
  logout:        ()                    => post('logout'),
  accounts:      ()                    => get('accounts'),
  createAccount: (name, capital)       => post('account_create', { name, capital }),
  switchAccount: (account_id)          => post('account_switch', { account_id }),

  // Piac
  assets:  ()                          => get('assets'),
  tick:    (symbol)                    => get('tick', { symbol }),
  candles: (symbol, tf, limit)         => get('candles', { symbol, tf, limit }),
  news:    (symbol, limit)             => get('news', { symbol, limit }),

  // Kereskedés
  portfolio: ()                        => get('portfolio'),
  history:   (limit)                   => get('history', { limit }),
  trade:     (payload)                 => post('trade', payload),
  close:     (position_id, qty)        => post('close', { position_id, qty }),

  // Szimuláció
  simulate: (horizon)                  => post('simulate', { horizon }),
  reset:    (scope)                    => post('reset', { scope }),
};
