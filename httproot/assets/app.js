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

  // Temperature setpoint sub-line. Single value (currently-active T-max,
  // controller already chose day / night based on sun.is_daytime).
  const tempSp = $('cl-temp-sp');
  if (typeof c.temp_max_active === 'number' && isFinite(c.temp_max_active)) {
    tempSp.hidden = false;
    setText('cl-temp-sp-max', fmtNum(c.temp_max_active, 1));
  } else {
    tempSp.hidden = true;
  }

  const rhEl = $('cl-rh');
  if ('rh_pct' in c) {
    rhEl.hidden = false;
    rhEl.querySelector('strong').textContent = fmtNum(c.rh_pct, 0);
  } else {
    rhEl.hidden = true;
  }

  // RH setpoint sub-line. When rh_ctrl_enabled === false, the controller
  // omits rh_min_active / rh_max_active per the API contract; the line is
  // dimmed and shows em-dashes.
  const rhSp = $('cl-rh-sp');
  if (c.rh_ctrl_enabled === false) {
    rhSp.hidden = false;
    rhSp.classList.add('disabled');
    setText('cl-rh-sp-min', '—');
    setText('cl-rh-sp-max', '—');
    rhSp.title = 'Humidity control is disabled; setpoints inactive';
  } else if (typeof c.rh_min_active === 'number' || typeof c.rh_max_active === 'number') {
    rhSp.hidden = false;
    rhSp.classList.remove('disabled');
    setText('cl-rh-sp-min', typeof c.rh_min_active === 'number' ? fmtNum(c.rh_min_active, 0) : '—');
    setText('cl-rh-sp-max', typeof c.rh_max_active === 'number' ? fmtNum(c.rh_max_active, 0) : '—');
    rhSp.title = 'Currently active humidity setpoints (min / max)';
  } else {
    rhSp.hidden = true;
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

  $('wd-speed').querySelector('strong').textContent =
    'speed_ms' in w ? fmtNum(w.speed_ms, 1) : '—';

  const dirStrongs = $('wd-dir').querySelectorAll('strong');
  dirStrongs[0].textContent = 'direction_deg' in w ? fmtNum(w.direction_deg, 0) : '—';
  dirStrongs[1].textContent = 'direction_deg' in w ? cardinalFromDeg(w.direction_deg) : '';
}

// Mirrors the firmware's emission set documented in
// design/technical-spec-statusWebsite.md § 9.4 / TR-47. Each flag maps to a
// CSS badge class: red = alarm/fault, yellow = warn/transient, blue = info.
// Removing 'sensor_fault_rh' — no longer emitted by firmware 2.0.0-a.6.35+;
// per TR-48, if it ever shows up it is silently dropped.
const FLAG_CLASS = {
  wind_override:      'flag-alarm',
  motor_alarm:        'flag-alarm',
  sensor_fault_temp:  'flag-warn',
  sensor_fault_wind:  'flag-warn',
  ota_in_progress:    'flag-warn',
  calibrating:        'flag-warn',
  net_backoff_active: 'flag-warn',
  wind_protect_off:   'flag-warn',
  humidity_ctrl_off:  'flag-info',
  coredump_available: 'flag-info',
  // Synthetic flag derived client-side from system.sd_mounted === false
  // (see renderMode below). If the firmware ever emits this string directly
  // in mode.flags, the dedup in renderMode keeps it from being shown twice.
  sd_not_mounted:     'flag-warn',
};

// Human-readable badge text per spec § 9.4 FLAG_LABEL.
const FLAG_LABEL = {
  wind_override:      'WIND',
  motor_alarm:        'MOTOR ALARM',
  sensor_fault_temp:  'T/RH fault',
  sensor_fault_wind:  'Wind fault',
  ota_in_progress:    'OTA active',
  calibrating:        'Calibrating',
  net_backoff_active: 'Net backoff',
  wind_protect_off:   'Wind protect off',
  humidity_ctrl_off:  'Humidity ctrl off',
  coredump_available: 'Coredump available',
  sd_not_mounted:     'SD-card',
};

const FLAG_DESC = {
  wind_override:      'Wind speed exceeded the safety threshold; windows forced closed',
  motor_alarm:        'A window motor reported a fault',
  sensor_fault_temp:  'Temperature / RH sensor is not reporting valid data',
  sensor_fault_wind:  'Wind sensor is not reporting valid data',
  ota_in_progress:    'Firmware / asset over-the-air update in progress',
  calibrating:        'Window position calibration in progress',
  net_backoff_active: 'Network backoff: status POSTs paused after consecutive failures',
  wind_protect_off:   'Operator disabled wind protection — windows will NOT close on high wind',
  humidity_ctrl_off:  'Operator disabled humidity-driven control',
  coredump_available: 'Panic dump waiting in flash; admin can retrieve via local GUI',
  sd_not_mounted:     'SD card is not mounted — event logs and persisted state unavailable',
};

const MODE_CLASS = {
  AUTOMATIC:     'mode-ok',
  STANDBY:       'mode-mute',
  WIND_OVERRIDE: 'mode-warn',
  WINDOW_CAL:    'mode-warn',
  MOTOR_ALARM:   'mode-alarm',
};

const MODE_DESC = {
  AUTOMATIC:     'Normal automatic operation — windows controlled by climate rules',
  STANDBY:       'Controller is in standby — no active control loop',
  WIND_OVERRIDE: 'High wind detected — windows forced closed for safety',
  WINDOW_CAL:    'Calibrating window positions — automatic control suspended',
  MOTOR_ALARM:   'Motor fault detected — manual intervention required',
};

// Mode-to-flag aliases: when the mode pill already shows one of these states,
// the corresponding flag in mode.flags is redundant and is suppressed in the
// badge row so each state surfaces exactly once.
const MODE_FLAG_DUPE = {
  WIND_OVERRIDE: 'wind_override',
  WINDOW_CAL:    'calibrating',
  MOTOR_ALARM:   'motor_alarm',
};

function renderMode(m, sys) {
  const tile = $('tile-mode');
  if (!m) { tile.hidden = true; return; }
  tile.hidden = false;

  const pill = $('md-current');
  pill.textContent = m.current || '';
  pill.className = 'pill ' + (m.current && MODE_CLASS[m.current] ? MODE_CLASS[m.current] : 'mode-mute');
  pill.title = MODE_DESC[m.current] || 'Operating mode reported by the controller';

  // Start with the controller-emitted flag list, then synthesise extra badges
  // from system-level state that should also surface in the mode tile. Today
  // that's just `sd_not_mounted` derived from system.sd_mounted === false;
  // future system-derived flags can be appended in the same way.
  const flags = Array.isArray(m.flags) ? m.flags.slice() : [];
  if (sys && sys.sd_mounted === false && !flags.includes('sd_not_mounted')) {
    flags.push('sd_not_mounted');
  }

  const flagsEl = $('md-flags');
  clearChildren(flagsEl);
  const dupe = MODE_FLAG_DUPE[m.current];
  for (const f of flags) {
    if (f === dupe) continue; // already represented by the mode pill
    const cls = FLAG_CLASS[f];
    if (!cls) continue;       // TR-48: unknown flag — silently drop
    const span = document.createElement('span');
    span.className = 'badge ' + cls;
    span.textContent = FLAG_LABEL[f] || f;
    span.title = FLAG_DESC[f] || 'Status flag from the controller';
    flagsEl.appendChild(span);
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
  const icon = $('sun-icon');
  icon.textContent = s.is_daytime ? '☀' : '☾';
  icon.title       = s.is_daytime ? 'Currently daytime' : 'Currently night-time';
  setText('sun-rise', 'sunrise_min' in s ? fmtMin(s.sunrise_min) : '—');
  setText('sun-set',  'sunset_min'  in s ? fmtMin(s.sunset_min)  : '—');
}

function fmtUptime(sec) {
  if (typeof sec !== 'number' || !isFinite(sec) || sec < 0) return '—';
  sec = Math.floor(sec);
  const d = Math.floor(sec / 86400);
  const h = Math.floor((sec % 86400) / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (d > 0) return d + 'd ' + h + 'h ' + m + 'm';
  if (h > 0) return h + 'h ' + m + 'm';
  if (m > 0) return m + 'm ' + s + 's';
  return s + 's';
}

function rssiToPct(dbm) {
  return Math.max(0, Math.min(100, ((dbm + 90) / 60) * 100));
}

function rssiQuality(dbm) {
  if (dbm >= -54) return 'good';
  if (dbm >= -72) return 'fair';
  return 'weak';
}

function renderSystem(s) {
  const tile = $('tile-system');
  if (!s) { tile.hidden = true; return; }
  tile.hidden = false;

  const fill = $('sys-rssi-fill');
  const row  = $('sys-rssi-row');
  if (typeof s.wifi_rssi_dbm === 'number' && isFinite(s.wifi_rssi_dbm)) {
    const pct = rssiToPct(s.wifi_rssi_dbm);
    fill.style.width = pct + '%';
    fill.style.background =
      pct >= 60 ? 'var(--green)'  :
      pct >= 30 ? 'var(--yellow)' :
                  'var(--red)';
    row.title = 'WiFi signal strength: ' + s.wifi_rssi_dbm + ' dBm (' + rssiQuality(s.wifi_rssi_dbm) + ')';
  } else {
    fill.style.width = '0%';
    fill.style.background = 'var(--red)';
    row.title = 'WiFi signal strength: no data';
  }

  const ntpEl = $('sys-ntp');
  if (s.ntp_synced) {
    ntpEl.textContent = 'NTP ok';
    ntpEl.title       = 'Controller clock is synchronized via NTP';
  } else {
    ntpEl.textContent = 'NTP pending';
    ntpEl.title       = 'Controller clock is not yet synchronized via NTP';
  }

  setText('sys-uptime', 'uptime_s' in s ? fmtUptime(s.uptime_s) : '—');
  setText('sys-fw',     s.fw_ver  || '—');
  setText('sys-unit',   s.unit_id || '—');
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

function fmtDateTime(epoch) {
  const d = new Date(epoch * 1000);
  const pad = n => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
    + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
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
    const last = fmtDateTime(lastPayload.received_at);
    const intLabel = interval + 's' + (assumed ? ' (assumed)' : '');
    cap.textContent = 'Last update ' + last + ' · interval ' + intLabel + ' · age ' + fmtUptime(age);
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
  renderMode(s.mode, s.system);
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
