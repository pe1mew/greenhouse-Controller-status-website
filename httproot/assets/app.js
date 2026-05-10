(function () {
'use strict';

const cfg = window.GH_CFG;
let lastPayload = null;
let anchor = null;
let failCount = 0;

function $(id) { return document.getElementById(id); }

const banner = $('conn-banner');
function showBanner() { banner.hidden = false; }
function hideBanner() { banner.hidden = true; }

function fmtNum(n, d) {
  if (typeof n !== 'number' || !isFinite(n)) return '';
  return d != null ? n.toFixed(d) : String(n);
}

function setText(id, value) {
  const el = $(id);
  if (el) el.textContent = value == null ? '' : String(value);
}

function setHidden(id, hidden) {
  const el = $(id);
  if (el) el.hidden = hidden;
}

function clearChildren(el) {
  while (el.firstChild) el.removeChild(el.firstChild);
}

function renderClimate(c) {
  const tile = $('tile-climate');
  if (!c) { tile.hidden = true; return; }
  tile.hidden = false;

  const tempEl = $('cl-temp');
  if ('temp_c' in c) {
    tempEl.hidden = false;
    tempEl.querySelector('strong').textContent = fmtNum(c.temp_c, 1);
  } else {
    tempEl.hidden = true;
  }

  const rhEl = $('cl-rh');
  if ('rh_pct' in c) {
    rhEl.hidden = false;
    rhEl.querySelector('strong').textContent = fmtNum(c.rh_pct, 0);
  } else {
    rhEl.hidden = true;
  }
}

const COMPASS_8 = ['N','NE','E','SE','S','SW','W','NW'];
function cardinalFromDeg(deg) {
  if (typeof deg !== 'number' || !isFinite(deg)) return '';
  const idx = Math.round((((deg % 360) + 360) % 360) / 45) % 8;
  return COMPASS_8[idx];
}

function renderWind(w) {
  const tile = $('tile-wind');
  if (!w) { tile.hidden = true; return; }
  tile.hidden = false;

  const main = $('wd-main');
  const strongs = main.querySelectorAll('strong');
  strongs[0].textContent = 'speed_ms' in w ? fmtNum(w.speed_ms, 1) : '—';
  strongs[1].textContent = 'direction_deg' in w ? fmtNum(w.direction_deg, 0) : '—';
  strongs[2].textContent = 'direction_deg' in w ? cardinalFromDeg(w.direction_deg) : '';
}

const FLAG_CLASS = {
  wind_override:     'flag-warn',
  calibrating:       'flag-warn',
  ota_in_progress:   'flag-info',
  motor_alarm:       'flag-alarm',
  sensor_fault_temp: 'flag-alarm',
  sensor_fault_rh:   'flag-alarm',
  sensor_fault_wind: 'flag-alarm',
};

const MODE_CLASS = {
  AUTOMATIC:     'mode-ok',
  WIND_OVERRIDE: 'mode-warn',
  WINDOW_CAL:    'mode-warn',
  MOTOR_ALARM:   'mode-alarm',
};

function renderMode(m) {
  const tile = $('tile-mode');
  if (!m) { tile.hidden = true; return; }
  tile.hidden = false;

  const pill = $('md-current');
  pill.textContent = m.current || '';
  pill.className = 'pill ' + (m.current && MODE_CLASS[m.current] ? MODE_CLASS[m.current] : 'mode-mute');

  const flagsEl = $('md-flags');
  clearChildren(flagsEl);
  if (Array.isArray(m.flags)) {
    for (const f of m.flags) {
      const span = document.createElement('span');
      span.className = 'badge ' + (FLAG_CLASS[f] || 'flag-mute');
      span.textContent = f;
      flagsEl.appendChild(span);
    }
  }
}

function fmtMin(min) {
  if (typeof min !== 'number') return '—';
  const h = Math.floor(min / 60), mm = min % 60;
  return String(h).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
}

function renderSun(s) {
  const tile = $('tile-sun');
  if (!s) { tile.hidden = true; return; }
  tile.hidden = false;
  setText('sun-icon', s.is_daytime ? '☀' : '☾');
  setText('sun-rise', 'sunrise_min' in s ? fmtMin(s.sunrise_min) : '—');
  setText('sun-set',  'sunset_min'  in s ? fmtMin(s.sunset_min)  : '—');
}

function renderSystem(s) {
  const tile = $('tile-system');
  if (!s) { tile.hidden = true; return; }
  tile.hidden = false;
  setText('sys-ip',   s.wifi_ip || '—');
  setText('sys-rssi', 'wifi_rssi_dbm' in s ? s.wifi_rssi_dbm : '—');
  setText('sys-ntp',  s.ntp_synced ? 'NTP ok' : 'NTP pending');
  setText('sys-fw',   s.fw_ver || '—');
}

const W_IDS = ['M1', 'M2', 'M3'];
const COLOR = {
  OPEN:         'var(--blue-light)',
  MOVING_OPEN:  'var(--yellow)',
  MOVING_CLOSE: 'var(--yellow)',
  CLOSED:       'var(--green-dark)',
  UNKNOWN:      'var(--grey-muted)',
};
function shortState(s) {
  return ({ MOVING_OPEN: 'MOV OPEN', MOVING_CLOSE: 'MOV CLOSE' }[s]) || s || 'UNKNOWN';
}
function textColorFor(state) {
  // Light-blue (OPEN) background needs dark text for legibility.
  return state === 'OPEN' ? '#000' : 'var(--fg)';
}
function renderWindows(windows) {
  const tile = $('tile-windows');
  if (!windows) { tile.hidden = true; return; }
  tile.hidden = false;

  for (const id of W_IDS) {
    const state = windows[id] || 'UNKNOWN';
    const fill  = (state in COLOR) ? COLOR[state] : COLOR.UNKNOWN;
    const lo = id.toLowerCase();
    $('rect-'  + lo).setAttribute('fill', fill);
    const lbl  = $('lbl-' + lo);
    lbl.setAttribute('fill', textColorFor(state));
    lbl.textContent = id + ' ' + cfg.windowNames[id] + ' ' + shortState(state);
    $('title-' + lo).textContent = id + ' ' + cfg.windowNames[id] + ': ' + state;
  }
}

function onPayload(s) {
  lastPayload = s;
  anchor = {
    fetchedAtMono: performance.now(),
    ageAtFetch:    typeof s.age_seconds === 'number' ? s.age_seconds : Infinity,
  };
}

function currentAgeS() {
  if (!anchor) return Infinity;
  return anchor.ageAtFetch + (performance.now() - anchor.fetchedAtMono) / 1000;
}

function fmtClock(epoch) {
  const d = new Date(epoch * 1000);
  const pad = n => String(n).padStart(2, '0');
  return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}

function renderFreshness() {
  const interval = (lastPayload && typeof lastPayload.update_interval_s === 'number')
    ? lastPayload.update_interval_s
    : cfg.defaultIntervalS;
  const assumed = !(lastPayload && typeof lastPayload.update_interval_s === 'number');
  const age = currentAgeS();

  const fillFrac = Math.max(0, Math.min(1, 1 - age / (4 * interval)));
  const color =
    age <= 2 * interval ? 'var(--green)'  :
    age <= 4 * interval ? 'var(--yellow)' :
                          'var(--red)';

  const fill = $('fresh-fill');
  fill.style.width = (fillFrac * 100) + '%';
  fill.style.background = color;

  const cap = $('fresh-caption');
  if (!lastPayload || !lastPayload.received_at) {
    cap.textContent = 'No data yet';
  } else {
    const last = fmtClock(lastPayload.received_at);
    const intLabel = interval + 's' + (assumed ? ' (assumed)' : '');
    cap.textContent = 'Last update ' + last + ' · interval ' + intLabel + ' · age ' + Math.floor(age) + 's';
  }

  setHidden('fresh-offline', age <= 4 * interval);
  document.body.classList.toggle('stale', age > 4 * interval);
}

const DYNAMIC_TILES = ['tile-climate', 'tile-wind', 'tile-windows', 'tile-mode', 'tile-sun', 'tile-system'];

function hideAllDynamicTiles() {
  for (const id of DYNAMIC_TILES) setHidden(id, true);
}

function render(s) {
  onPayload(s);
  renderClimate(s.climate);
  renderWind(s.wind);
  renderWindows(s.windows);
  renderMode(s.mode);
  renderSun(s.sun);
  renderSystem(s.system);
  renderFreshness();
}

async function tick() {
  try {
    const r = await fetch('view.php', { cache: 'no-store' });
    if (!r.ok) throw new Error('http ' + r.status);
    const s = await r.json();
    failCount = 0;
    hideBanner();
    if (s && Object.keys(s).length > 0) {
      render(s);
    } else {
      lastPayload = null;
      anchor = null;
      hideAllDynamicTiles();
      renderFreshness();
    }
  } catch (e) {
    if (++failCount >= 3) showBanner();
  }
}

setInterval(renderFreshness, 1000);
renderFreshness();

setInterval(tick, cfg.pollMs);
tick();

})();
