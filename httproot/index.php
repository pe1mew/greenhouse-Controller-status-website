<?php
require __DIR__ . '/config.php';
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
header('X-Robots-Tag: noindex, nofollow');
@header_remove('X-Powered-By');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Greenhouse status</title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
<script>
  window.GH_CFG = {
    pollMs:           <?= (int) GH_POLL_INTERVAL_MS ?>,
    defaultIntervalS: <?= (int) GH_DEFAULT_INTERVAL_S ?>,
    windowNames:      <?= json_encode(GH_WINDOW_NAMES) ?>
  };
</script>
</head>
<body class="dashboard">

<div id="conn-banner" hidden>Connection lost</div>

<div class="tiles">

  <section id="tile-freshness" class="tile tile-freshness">
    <h3 class="tile-title" title="How recently the controller reported its status">Freshness</h3>
    <div class="fresh-track" title="Bar shrinks as the last update gets older. Green = fresh, yellow = late, red = stale (older than 4× the report interval).">
      <div id="fresh-fill" class="fresh-fill"></div>
    </div>
    <div class="fresh-row">
      <span id="fresh-caption" class="muted" title="Time of the last update, configured update interval, and current data age">No data yet</span>
      <span id="fresh-offline" class="badge offline" hidden title="Controller has not reported in more than 4× the expected interval">OFFLINE</span>
    </div>
  </section>

  <section id="tile-climate" class="tile" hidden>
    <h3 class="tile-title" title="Air temperature and humidity inside the greenhouse">Climate</h3>
    <p class="big" id="cl-temp" title="Current air temperature in degrees Celsius"><strong></strong> °C</p>
    <p class="big" id="cl-rh"   title="Current relative humidity (percentage of maximum at this temperature)"><strong></strong> %</p>
  </section>

  <section id="tile-wind" class="tile" hidden>
    <h3 class="tile-title" title="Wind conditions reported by the controller's wind sensor">Wind</h3>
    <p class="big" id="wd-main">
      <strong title="Wind speed in metres per second"></strong> m/s
      <strong title="Wind direction in degrees (0° = North, 90° = East, 180° = South, 270° = West)"></strong>°
      <strong title="Compass cardinal direction (N / NE / E / SE / S / SW / W / NW)"></strong>
    </p>
  </section>

  <section id="tile-windows" class="tile tile-windows" hidden>
    <h3 class="tile-title" title="Position of each greenhouse vent / window. Hover a window for its full state.">Windows</h3>
    <svg viewBox="0 0 200 140" role="img" aria-label="Window status" class="windows-svg">
      <rect x="2" y="2" width="196" height="136" rx="4" fill="none" stroke="var(--fg)" stroke-width="1"/>
      <text x="100" y="10"  text-anchor="middle" dominant-baseline="middle" font-size="6" fill="var(--muted)">N</text>
      <text x="100" y="130" text-anchor="middle" dominant-baseline="middle" font-size="6" fill="var(--muted)">S</text>

      <g>
        <title id="title-m3">M3 North wall: UNKNOWN</title>
        <rect id="rect-m3" x="14" y="18" width="172" height="34" rx="4" fill="var(--grey-muted)"/>
        <text id="lbl-m3" x="100" y="35" text-anchor="middle" dominant-baseline="middle" font-size="10" font-weight="bold" fill="var(--fg)">M3 North wall UNKNOWN</text>
      </g>

      <g>
        <title id="title-m2">M2 North roof: UNKNOWN</title>
        <rect id="rect-m2" x="14" y="66" width="172" height="22" rx="3" fill="var(--grey-muted)"/>
        <text id="lbl-m2" x="100" y="77" text-anchor="middle" dominant-baseline="middle" font-size="10" font-weight="bold" fill="var(--fg)">M2 North roof UNKNOWN</text>
      </g>

      <g>
        <title id="title-m1">M1 South roof: UNKNOWN</title>
        <rect id="rect-m1" x="14" y="100" width="172" height="22" rx="3" fill="var(--grey-muted)"/>
        <text id="lbl-m1" x="100" y="111" text-anchor="middle" dominant-baseline="middle" font-size="10" font-weight="bold" fill="var(--fg)">M1 South roof UNKNOWN</text>
      </g>
    </svg>
  </section>

  <section id="tile-mode" class="tile" hidden>
    <h3 class="tile-title" title="Current operating mode of the controller">Mode</h3>
    <span id="md-current" class="pill"></span>
    <div id="md-flags" class="flags" title="Active status flags reported by the controller"></div>
  </section>

  <section id="tile-sun" class="tile" hidden>
    <h3 class="tile-title" title="Daylight status and today's sunrise / sunset times">Sun</h3>
    <p class="big"><span id="sun-icon" title="Day / night indicator">☀</span></p>
    <p class="muted" title="Sunrise time today (HH:MM, local time)">↑ <span id="sun-rise">—</span></p>
    <p class="muted" title="Sunset time today (HH:MM, local time)">↓ <span id="sun-set">—</span></p>
  </section>

  <section id="tile-system" class="tile" hidden>
    <h3 class="tile-title" title="Controller connectivity and runtime status">System</h3>

    <div id="sys-rssi-row" class="sys-row" title="WiFi signal strength">
      <span class="sys-label">WiFi</span>
      <div class="sys-rssi-track"><div id="sys-rssi-fill" class="sys-rssi-fill"></div></div>
    </div>

    <div class="sys-row"><span id="sys-ntp" class="muted" title="Time synchronization status (NTP / RTC)">—</span></div>
    <div class="sys-row" title="Time since the controller last booted"><span class="muted">Uptime <span id="sys-uptime">—</span></span></div>
  </section>

</div>

<footer>
  <span title="Controller firmware version">Greenhouse Controller &nbsp;&bull;&nbsp; v<span id="sys-fw">—</span></span>
  <a href="https://github.com/pe1mew/-greenhouse-Controller-status-website"
     target="_blank" rel="noopener noreferrer">GitHub &nearr;</a>
</footer>

<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
</body>
</html>
