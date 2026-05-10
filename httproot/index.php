<?php require __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
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
    <h3 class="tile-title">Freshness</h3>
    <div class="fresh-track"><div id="fresh-fill" class="fresh-fill"></div></div>
    <div class="fresh-row">
      <span id="fresh-caption" class="muted">No data yet</span>
      <span id="fresh-offline" class="badge offline" hidden>OFFLINE</span>
    </div>
  </section>

  <section id="tile-climate" class="tile" hidden>
    <h3 class="tile-title">Climate</h3>
    <p class="big" id="cl-temp"><strong></strong> °C</p>
    <p class="big" id="cl-rh"><strong></strong> %</p>
  </section>

  <section id="tile-wind" class="tile" hidden>
    <h3 class="tile-title">Wind</h3>
    <p class="big" id="wd-main"><strong></strong> m/s <strong></strong>° <strong></strong></p>
  </section>

  <section id="tile-windows" class="tile tile-windows" hidden>
    <h3 class="tile-title">Windows</h3>
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
    <h3 class="tile-title">Mode</h3>
    <span id="md-current" class="pill"></span>
    <div id="md-flags" class="flags"></div>
  </section>

  <section id="tile-sun" class="tile" hidden>
    <h3 class="tile-title">Sun</h3>
    <p class="big"><span id="sun-icon">☀</span></p>
    <p class="muted">↑ <span id="sun-rise">—</span></p>
    <p class="muted">↓ <span id="sun-set">—</span></p>
  </section>

  <section id="tile-system" class="tile" hidden>
    <h3 class="tile-title">System</h3>
    <p class="muted"><span id="sys-ip">—</span> · <span id="sys-rssi">—</span> dBm · <span id="sys-ntp">—</span></p>
  </section>

</div>

<footer>
  <span>Greenhouse Controller Status &nbsp;&bull;&nbsp; fw <span id="sys-fw">—</span></span>
  <a href="https://github.com/pe1mew/-greenhouse-Controller-status-website"
     target="_blank" rel="noopener noreferrer">GitHub &nearr;</a>
</footer>

<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
</body>
</html>
