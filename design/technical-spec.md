# Greenhouse Controller Status Website — Technical Specification

| | |
|---|---|
| Document | Technical Specification |
| Audience | Implementer of the website |
| Companion | [functional-design.md](functional-design.md) — *what* the system does |
| Version | 0.2 (draft) |
| Date | 2026-05-10 |
| Status | For implementation |

This document is the implementation brief. It describes file layout, configuration constants, endpoint code paths, storage recipes, frontend wiring, and verification steps. The functional rules it implements are defined in [functional-design.md](functional-design.md); read that first.

The reference examples in `documentation/webguiExample/` and `documentation/phpAPIExample/` are followed closely; pull patterns from there before writing new ones.

## Table of contents

1. [Directory layout](#1-directory-layout)
2. [Configuration (`config.php`)](#2-configuration-configphp)
3. [Backend — controller ingest API (`api.php`)](#3-backend--controller-ingest-api-apiphp)
4. [Backend — browser read API (`view.php`)](#4-backend--browser-read-api-viewphp)
5. [Backend — log download (`log/logs/`)](#5-backend--log-download-loglogs)
6. [Storage model](#6-storage-model)
7. [Frontend — `index.php` shell](#7-frontend--indexphp-shell)
8. [Frontend — `assets/style.css`](#8-frontend--assetsstylecss)
9. [Frontend — `assets/app.js`](#9-frontend--assetsappjs)
10. [Windows tile SVG](#10-windows-tile-svg)
11. [Freshness tile rendering](#11-freshness-tile-rendering)
12. [HTTP status codes and silent-drop flow](#12-http-status-codes-and-silent-drop-flow)
13. [Apache configuration (`.htaccess`)](#13-apache-configuration-htaccess)
14. [Verification plan](#14-verification-plan)
15. [Testable requirements](#15-testable-requirements)

---

## 1. Directory layout

```
greenhouse-Controller-status-website/
├── httproot/                       # Apache document root points here
│   ├── index.php                   # Public HTML shell (dashboard)
│   ├── api.php                     # Controller ingest (POST writes, secret-gated)
│   ├── view.php                    # Browser read feed (GET reads, public)
│   ├── config_template.php         # Tracked template — committed
│   ├── config.php                  # Real config — gitignored, holds GH_SECRET_TOKEN
│   ├── assets/
│   │   ├── style.css
│   │   └── app.js
│   ├── data/
│   │   ├── .htaccess               # Require all denied
│   │   └── status.json             # Latest payload + server-added received_at
│   └── log/
│       ├── index.php               # Separate, unlinked page that lists uploaded log files
│       └── logs/
│           ├── .htaccess           # Extension whitelist, no listing
│           └── YYYY-MM-DD_HHMMSS.log
├── tools/
│   ├── deploy.ps1                  # SCP-based deploy script (PowerShell)
│   └── README.md
├── mock/                           # Flask mock controller (dev tool, not deployed)
├── design/
│   ├── functional-design.md
│   ├── technical-spec.md
│   └── implementation-plan.md
├── .deploy.env                     # Gitignored — host alias, doc root, MOCK_SECRET
├── .deploy.env.example             # Tracked template
└── documentation/
    ├── webguiExample/
    └── phpAPIExample/
```

Notes:

- **Apache's `DocumentRoot` (or virtual host root) must be set to `httproot/`.** Nothing outside `httproot/` is served — `mock/`, `design/`, `tools/`, and `documentation/` stay private even on a misconfigured host.
- `httproot/data/` is never reachable over HTTP (its `.htaccess` denies all). Only `view.php` reads from it.
- `httproot/log/logs/` is served directly by Apache for downloads.
- `httproot/log/index.php` is a separate, unlinked page that lists log files. It server-renders the list — no JS, no fetch.
- `config.php` defines constants only. It is **gitignored** so the production secret never enters the repo. A tracked template, `config_template.php`, is the canonical source for first-time setup. PHP processes `config.php` to no output, so even a direct `GET` returns 0 bytes.
- The two API files are deliberately split. `api.php` rejects GET; `view.php` rejects POST.

---

## 2. Configuration

Two files at `httproot/`:

- **`config_template.php`** — tracked in git, holds placeholder values, includes a banner comment that explains the first-time-setup procedure. Production secrets never appear here.
- **`config.php`** — gitignored. Created by copying the template and editing `GH_SECRET_TOKEN` to a real random value. Both `api.php` and `view.php` `require __DIR__ . '/config.php'`.

The deploy script (`tools/deploy.ps1`) runs a pre-flight check: it refuses
to deploy if `config.php` is missing or still contains the
`REPLACE_ME_BEFORE_DEPLOY` template marker, and prints a non-fatal warning
if the older fallback `dev-…` placeholder is still in place (acceptable
on a LAN test server).

The constants the file must define:

```php
<?php
define('GH_SECRET_TOKEN',        '<long random string, identical on controller>');
define('GH_DEBUG_RESPONSES',     false);

define('GH_DATA_DIR',            __DIR__ . '/data');
define('GH_STATUS_FILE',         GH_DATA_DIR . '/status.json');

define('GH_LOG_DIR',             __DIR__ . '/log/logs');
define('GH_LOG_RETENTION_DAYS',  90);
define('GH_LOG_MAX_BYTES',       5 * 1024 * 1024);
define('GH_LOG_ALLOWED_EXT',     ['log', 'txt']);

define('GH_POLL_INTERVAL_MS',    5000);
define('GH_DEFAULT_INTERVAL_S',  30);

define('GH_WINDOW_NAMES', [
    'M1' => 'South roof',
    'M2' => 'North roof',
    'M3' => 'North wall',
]);
```

Rotation: change `GH_SECRET_TOKEN` here and the corresponding compiled value on the controller, then redeploy both. There is no runtime rotation.

---

## 3. Backend — controller ingest API (`api.php`)

Accepts only `POST`. Dispatches by `$_GET['action']` (default action = status).

### 3.1 Common entry checks (in order)

1. `if ($_SERVER['REQUEST_METHOD'] !== 'POST')` → silent 204 (or `405 {"error":"method_not_allowed"}` in debug). Exit.
2. `if (($_SERVER['HTTP_SOURCEIDENTIFIER'] ?? '') !== GH_SECRET_TOKEN)` → silent 204 (or `401 {"error":"unauthorized"}` in debug). Exit.

### 3.2 Push status (no `action` query parameter)

After the common checks:

```php
try {
    $body = file_get_contents('php://input');
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new RuntimeException('not an object');
} catch (Throwable $e) {
    if (GH_DEBUG_RESPONSES) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'bad_json', 'detail' => $e->getMessage()]);
    } else {
        http_response_code(204);
    }
    exit;
}

$payload['received_at'] = time();

$tmp = GH_STATUS_FILE . '.tmp';
file_put_contents($tmp, json_encode($payload), LOCK_EX);
rename($tmp, GH_STATUS_FILE);

if (GH_DEBUG_RESPONSES) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'received_at' => $payload['received_at']]);
} else {
    http_response_code(204);
}
```

### 3.3 Upload log (`?action=log`)

After the common checks:

```php
$len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($len <= 0 || $len > GH_LOG_MAX_BYTES) {
    if (GH_DEBUG_RESPONSES) {
        http_response_code(413);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'too_large_or_empty', 'bytes' => $len]);
    } else {
        http_response_code(204);
    }
    exit;
}

$body = file_get_contents('php://input');
$name = date('Y-m-d_His') . '.log';
$path = GH_LOG_DIR . '/' . $name;
file_put_contents($path, $body, LOCK_EX);
chmod($path, 0644);

// Silent retention sweep
foreach (glob(GH_LOG_DIR . '/*.{log,txt}', GLOB_BRACE) as $f) {
    if (is_file($f) && (time() - filemtime($f)) > GH_LOG_RETENTION_DAYS * 86400) {
        @unlink($f);
    }
}

if (GH_DEBUG_RESPONSES) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'name' => $name, 'bytes' => $len]);
} else {
    http_response_code(204);
}
```

The retention sweep runs only on this success path. It is intentionally silent.

---

## 4. Backend — browser read API (`view.php`)

Accepts only `GET`. Dispatches by `$_GET['action']`.

### 4.1 Common entry checks

```php
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    if (GH_DEBUG_RESPONSES) {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'method_not_allowed']);
    } else {
        http_response_code(204);
    }
    exit;
}

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
```

`view.php` is the only browser-facing PHP entrypoint. Hardening hooks live here:

- `Cache-Control: no-store` to prevent stale CDN copies.
- (Optional, future) IP allowlist or token-bucket rate limit at the top of the file.
- (Optional, future) Basic Auth via `.htaccess` on `view.php` only.

### 4.2 Read status (no `action` query parameter)

```php
if (!is_file(GH_STATUS_FILE)) {
    echo '{}';
    exit;
}

$json = file_get_contents(GH_STATUS_FILE);
$payload = json_decode($json, true);
if (!is_array($payload)) {
    echo '{}';
    exit;
}

$received = (int) ($payload['received_at'] ?? 0);
$payload['age_seconds'] = $received > 0 ? max(0, time() - $received) : null;
echo json_encode($payload);
```

### 4.3 List logs (`?action=logs`)

```php
$out = [];
foreach (glob(GH_LOG_DIR . '/*.{log,txt}', GLOB_BRACE) as $f) {
    if (is_file($f)) {
        $out[] = [
            'name'  => basename($f),
            'size'  => filesize($f),
            'mtime' => filemtime($f),
        ];
    }
}
usort($out, fn($a, $b) => $b['mtime'] - $a['mtime']);
echo json_encode($out);
```

---

## 5. Backend — log download (`log/logs/`)

Files are served directly by Apache. No PHP script wraps them. Security is enforced by the directory `.htaccess` (§ 13).

A successful request:

- `GET /log/logs/2026-05-10_120030.log` → 200 with `Content-Type: text/plain`, file content as body.

A blocked request:

- `GET /log/logs/` → 403 (directory listing disabled).
- `GET /log/logs/anything.php` → 403 (extension not whitelisted).
- `GET /log/logs/../config.php` → handled by Apache's path normalisation and the directory whitelist; never reaches `config.php`.

---

## 6. Storage model

### 6.1 Latest status

A single file at `data/status.json` containing the most recent payload plus a server-added `received_at` (Unix epoch seconds). No history, no rotation.

### 6.2 Atomic write

All writes go through a `.tmp` file and are renamed onto the destination:

```php
$tmp = GH_STATUS_FILE . '.tmp';
file_put_contents($tmp, json_encode($payload), LOCK_EX);
rename($tmp, GH_STATUS_FILE);
```

`rename()` is atomic on POSIX filesystems and on Windows when source and destination are on the same volume. `view.php` reads with a single `file_get_contents()` and never sees a partial file.

### 6.3 Log retention

The retention sweep runs only on the success path of `POST /api.php?action=log`. It deletes files matching `*.log`/`*.txt` in `GH_LOG_DIR` whose `mtime` is older than `GH_LOG_RETENTION_DAYS` days. With one upload per 24 h and a 90-day retention, the steady-state file count is ≈ 90.

Failures during sweep (e.g. permission denied on `unlink`) are swallowed via `@unlink` so they cannot fail the upload response.

---

## 7. Frontend — `index.php` shell

Server-rendered HTML. Responsibilities, in order:

1. `<!doctype html>` skeleton.
2. `<meta name="viewport" content="width=device-width, initial-scale=1">`.
3. Inject runtime config:
   ```html
   <script>
     window.GH_CFG = {
       pollMs:           <?= (int) GH_POLL_INTERVAL_MS ?>,
       defaultIntervalS: <?= (int) GH_DEFAULT_INTERVAL_S ?>,
       windowNames:      <?= json_encode(GH_WINDOW_NAMES) ?>
     };
   </script>
   ```
4. Render tile containers with stable IDs:
   - `#tile-freshness` — visible immediately, contains the bar track, fill, caption and `<span id="fresh-offline" hidden>`.
   - `#tile-climate`, `#tile-wind`, `#tile-windows`, `#tile-mode`, `#tile-sun`, `#tile-system` — start with the `hidden` attribute.
   - There is **no** `#tile-logs` on the dashboard. Logs live on a separate page; see § 7.1 below.
5. Include `assets/style.css` and `assets/app.js`. Both `<link>` and `<script>` tags carry a `?v=<filemtime>` cache-buster so a fresh deploy invalidates the browser cache without requiring a hard refresh.
6. The shell never renders dynamic data server-side. Everything dynamic flows through `GET /view.php`.

### 7.1 Separate logs page (`httproot/log/index.php`)

A self-contained, server-rendered page. Not linked from `index.php`,
not referenced from `app.js` — only findable by knowing the URL
`/<deploy-prefix>/log/`. The page lives in the same `log/` directory
that holds the `logs/` storage subdirectory, so download links resolve
relatively to `logs/<name>`. Responsibilities:

1. `require __DIR__ . '/../config.php'` (one level up).
2. `glob(GH_LOG_DIR . '/*.{log,txt}', GLOB_BRACE)` and sort newest-first by mtime.
3. Render rows with the filename + size + date on the left and an explicit Download button (`<a class="btn-download" href="logs/<name>" download>Download</a>`) on the right. Filenames are `htmlspecialchars()`-escaped.
4. Reuse `assets/style.css` (also via `?v=<filemtime>` for cache busting).
5. Reuse the dashboard footer for visual consistency.

Removing the dashboard's logs tile separated "operator-relevant live data" from "diagnostic file dump", so the dashboard stays clean while the files remain available when needed.

---

## 8. Frontend — `assets/style.css`

Inherits the dark-theme variables from `documentation/webguiExample/data/style.css` and adds three colors used by the windows tile.

```css
:root {
  /* from webguiExample */
  --bg:     #1a1a2e;
  --card:   #16213e;
  --accent: #0f3460;
  --fg:     #e0e0e0;
  --muted:  #999;
  --green:  #4caf50;
  --red:    #f44336;
  --yellow: #ff9800;

  /* added for the windows tile */
  --blue-light: #7ec8e3;
  --green-dark: #1f5132;
  --grey-muted: #5a6275;
}

/* Force the HTML `hidden` attribute to win over class-based display rules.
   Without this, e.g. `.badge { display: inline-block }` overrides
   `[hidden] { display: none }` from the user-agent stylesheet, and the
   OFFLINE pill stays visible regardless of what app.js does. */
[hidden] { display: none !important; }

.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  max-width: 900px;
  margin: 0 auto;
  padding: 12px;
}
.tile { background: var(--card); border-radius: 8px; padding: 12px; }

.tile-freshness { grid-column: 1 / -1; }      /* full row, top of grid */
.tile-windows   { grid-column: span 2; min-width: 280px; }

.dashboard.stale .tile { opacity: 0.55; }
.dashboard.stale .tile-freshness { opacity: 1; }   /* heartbeat stays bright */
```

Freshness bar styling (mirrors the `live-fetch-track` pattern from `webguiExample`):

```css
.fresh-track {
  height: 14px;
  background: var(--bg);
  border: 1px solid var(--accent);
  border-radius: 4px;
  overflow: hidden;
}
.fresh-fill {
  height: 100%;
  width: 100%;
  border-radius: 4px;
  transition: width 1s linear, background-color 200ms linear;
}
```

Mobile-specific rule (reduces the windows tile back to a single column on narrow screens so its three bars stay legible):

```css
@media (max-width: 480px) {
  .tile-windows { grid-column: 1 / -1; }
}
```

---

## 9. Frontend — `assets/app.js`

### 9.1 Polling loop

```js
const cfg = window.GH_CFG;
let lastPayload = null;
let failCount = 0;
let anchor = null;       // see § 11.1

async function tick() {
  try {
    const r = await fetch('view.php', { cache: 'no-store' });
    if (!r.ok) throw new Error('http ' + r.status);
    const s = await r.json();
    failCount = 0;
    onPayload(s);
    render(s);
  } catch (e) {
    if (++failCount >= 3) showConnLostBanner();
  }
}
setInterval(tick, cfg.pollMs);
tick();
```

`app.js` calls **only** `view.php`, using a **relative** URL so the site
works under any URL prefix (e.g. when deployed at `/controller/`). It
never calls `api.php`. There is no log-list polling on the dashboard;
logs are surfaced through the standalone `/<prefix>/log/` page (§ 7.1).

### 9.2 `render(s)` show/hide

For each tile, check the presence predicate; toggle the `hidden` attribute. Then for visible tiles, write `textContent` for each line if its key is present, hide that line otherwise.

Predicates:

```js
const TILES = {
  'tile-climate':  s => s.climate  != null,
  'tile-wind':     s => s.wind     != null,
  'tile-windows':  s => s.windows  != null,
  'tile-mode':     s => s.mode     != null,
  'tile-sun':      s => s.sun      != null,
  'tile-system':   s => s.system   != null,
  // tile-freshness is always visible — not in this map
};
```

All payload-derived strings are written via `textContent`, never `innerHTML`.

### 9.3 Connection-lost banner

A `<div id="conn-banner" hidden>` at the top of the body. `showConnLostBanner()` removes its `hidden`. A subsequent successful `tick()` calls `hideConnLostBanner()` (omitted from the snippet for brevity).

---

## 10. Windows tile SVG

Inline SVG, `viewBox="0 0 200 140"`, embedded in `index.php`. North at top, with M3 along the top edge.

```html
<svg viewBox="0 0 200 140" role="img" aria-label="Window status" class="windows-svg">
  <rect x="10" y="10" width="180" height="120" rx="4"
        fill="none" stroke="var(--fg)" stroke-width="1"/>

  <text x="100" y="17"  text-anchor="middle" dominant-baseline="middle" font-size="6" fill="var(--muted)">N</text>
  <text x="100" y="123" text-anchor="middle" dominant-baseline="middle" font-size="6" fill="var(--muted)">S</text>

  <g>
    <title id="title-m3">M3 North wall: UNKNOWN</title>
    <rect id="rect-m3" x="20" y="24" width="160" height="30" rx="4" fill="var(--grey-muted)"/>
    <text id="lbl-m3"  x="100" y="39" text-anchor="middle" dominant-baseline="middle"
          font-size="7" font-weight="bold" fill="var(--fg)">M3 North wall UNKNOWN</text>
  </g>

  <g>
    <title id="title-m2">M2 North roof: UNKNOWN</title>
    <rect id="rect-m2" x="20" y="68" width="160" height="18" rx="3" fill="var(--grey-muted)"/>
    <text id="lbl-m2"  x="100" y="77" text-anchor="middle" dominant-baseline="middle"
          font-size="7" font-weight="bold" fill="var(--fg)">M2 North roof UNKNOWN</text>
  </g>

  <g>
    <title id="title-m1">M1 South roof: UNKNOWN</title>
    <rect id="rect-m1" x="20" y="98" width="160" height="18" rx="3" fill="var(--grey-muted)"/>
    <text id="lbl-m1"  x="100" y="107" text-anchor="middle" dominant-baseline="middle"
          font-size="7" font-weight="bold" fill="var(--fg)">M1 South roof UNKNOWN</text>
  </g>
</svg>
```

All three bars share the same 160-unit width; only their heights differ.
M3 is 30 tall (`y=24..54`), M2 and M1 are each 18 tall (`y=68..86` and
`y=98..116` respectively). The vertical gap from the top border to M3
is 14, the gap M3→M2 is also 14, and the gap M1→bottom is 14, giving a
balanced layout. Label text is `font-size="7"` and `font-weight="bold"`
so it visually matches the OFFLINE pill.

JS update:

```js
const W = ['M1', 'M2', 'M3'];
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
// OPEN's light-blue background needs dark text for legibility; everything
// else stays on the foreground colour.
function textColorFor(state) {
  return state === 'OPEN' ? '#000' : 'var(--fg)';
}
function renderWindows(windows) {
  for (const id of W) {
    const state = (windows && windows[id]) || 'UNKNOWN';
    const rect  = document.getElementById('rect-'  + id.toLowerCase());
    const lbl   = document.getElementById('lbl-'   + id.toLowerCase());
    const title = document.getElementById('title-' + id.toLowerCase());
    rect.setAttribute('fill', COLOR[state] || COLOR.UNKNOWN);
    lbl.setAttribute('fill', textColorFor(state));
    lbl.textContent = `${id} ${cfg.windowNames[id]} ${shortState(state)}`;
    title.textContent = `${id} ${cfg.windowNames[id]}: ${state}`;
  }
}
```

---

## 11. Freshness tile rendering

### 11.1 Drift-resistant age tracking

`view.php` polls happen every 5 s but the bar must redraw every second. The browser anchors against the server-reported `age_seconds` at fetch time and uses its own monotonic clock for in-between updates:

```js
function onPayload(s) {
  lastPayload = s;
  anchor = {
    fetchedAtMono: performance.now(),
    ageAtFetch:    Number.isFinite(s.age_seconds) ? s.age_seconds : Infinity,
  };
}
function currentAgeS() {
  if (!anchor) return Infinity;
  return anchor.ageAtFetch + (performance.now() - anchor.fetchedAtMono) / 1000;
}
```

This avoids any dependency on browser↔server clock sync.

### 11.2 1 Hz redraw loop

```js
function renderFreshness() {
  const interval = (lastPayload && lastPayload.update_interval_s) || cfg.defaultIntervalS;
  const age      = currentAgeS();
  const fillFrac = Math.max(0, Math.min(1, 1 - age / (4 * interval)));
  const color    =
    age <= 2 * interval ? 'var(--green)'  :
    age <= 4 * interval ? 'var(--yellow)' :
                          'var(--red)';

  const bar = document.getElementById('fresh-fill');
  bar.style.width = (fillFrac * 100) + '%';
  bar.style.background = color;

  document.getElementById('fresh-caption').textContent =
    formatCaption(lastPayload, age, interval);

  document.getElementById('fresh-offline').hidden = age <= 4 * interval;

  // Drive the dashboard-wide stale dim from the freshness tile state
  document.querySelector('.dashboard').classList.toggle('stale', age > 4 * interval);
}
setInterval(renderFreshness, 1000);
renderFreshness();   // initial render so the tile isn't blank before first fetch
```

`formatCaption` emits:
- "No data yet" if `lastPayload` is null or `received_at` is missing.
- Otherwise `"Last update HH:MM:SS · interval Ns · age Ns"`. Append "(assumed)" after the interval value if `update_interval_s` was missing from the payload.

### 11.3 No-data startup

Before the first successful fetch:
- `lastPayload` is `null`, `anchor` is `null`, `currentAgeS()` returns `Infinity`.
- The bar renders 0 % wide and red, "OFFLINE" badge visible.
- Caption reads "No data yet".

This is the desired behavior: an empty dashboard immediately shows red on the heartbeat.

---

## 12. HTTP status codes and silent-drop flow

```
Per POST endpoint (api.php):
1. method != POST                              → 204 (debug: 405)
2. header sourceidentifier != GH_SECRET_TOKEN  → 204 (debug: 401)
3. body invalid (parse / size / shape)         → 204 (debug: 400 or 413)
4. side effect succeeds                        → 204 (debug: 200 + JSON body)

Per GET endpoint (view.php):
1. method != GET                               → 204 (debug: 405)
2. always returns a useful body, even when status is missing ({})
```

Cross-method requests on the wrong file are rejected by step 1 of the corresponding endpoint. This makes a misrouted browser fetch fail loudly in debug mode.

---

## 13. Apache configuration (`.htaccess`)

### 13.1 `data/.htaccess`

```apache
Require all denied
```

### 13.2 `log/logs/.htaccess`

```apache
Options -Indexes
<FilesMatch "^[0-9A-Za-z._-]+\.(log|txt)$">
    ForceType text/plain
    Header set Content-Disposition "attachment"
</FilesMatch>
<FilesMatch "^(?!^[0-9A-Za-z._-]+\.(log|txt)$).*$">
    Require all denied
</FilesMatch>
```

This:
- disables directory listings,
- restricts what files are servable (alphanumerics, dot, underscore, dash; `.log` or `.txt`),
- forces a plaintext content type and download disposition,
- denies anything not matching the whitelist.

### 13.3 Optional: gate `view.php` with Basic Auth

Drop into the project root if dashboard privacy is later wanted:

```apache
<Files "view.php">
    AuthType Basic
    AuthName "Greenhouse status"
    AuthUserFile /path/to/.htpasswd
    Require valid-user
</Files>
```

This affects only the read API. The controller-write path (`api.php`) is untouched.

---

## 14. Verification plan

After the implementation session, run through this list manually.

### 14.1 Backend

- [ ] `curl -X POST -H "sourceidentifier: <secret>" --data-binary @sample.json https://host/api.php` → 204 (or 200 in debug). `data/status.json` exists, contains `received_at`.
- [ ] Same call without the header → 204. `data/status.json` is unchanged.
- [ ] `curl https://host/view.php` → 200 JSON containing the payload plus `age_seconds`.
- [ ] `curl -X POST https://host/view.php` → 204 (or 405 in debug). No state change.
- [ ] `curl -X GET https://host/api.php` → 204 (or 405 in debug).
- [ ] `curl -X POST -H "sourceidentifier: <secret>" --data-binary @log.txt "https://host/api.php?action=log"` → file appears in `log/logs/` with a `YYYY-MM-DD_HHMMSS.log` name.
- [ ] `curl https://host/view.php?action=logs` → newest-first JSON list including the new file.
- [ ] `curl https://host/log/logs/<file>` → file contents.
- [ ] `curl https://host/log/logs/` → 403 (directory listing disabled).
- [ ] `curl https://host/log/logs/whatever.php` → 403.
- [ ] Touch a log file's mtime to 100 days ago, then re-POST a new log → old file is gone.

### 14.2 Frontend

- [ ] Open `https://host/` in Chrome DevTools at 360×800. All tiles populate within `GH_POLL_INTERVAL_MS`.
- [ ] DevTools Network tab confirms **no** `sourceidentifier` header on any browser request.
- [ ] Drop the `wind` object from the payload → wind tile hides. Drop just `wind.direction_deg` → only that line hides.
- [ ] Set the three M1/M2/M3 states in turn (OPEN, MOVING_OPEN, CLOSED) → SVG bars change color (light blue / amber / dark green) with M3 at the top.
- [ ] Send `update_interval_s: 10`. Wait 25 seconds without further updates. Freshness tile turns amber. Wait 45 s total. Tile turns red and the rest of the dashboard dims.
- [ ] On a fresh fetch after a long gap, the "Connection lost" banner clears, the freshness tile and dashboard recover.
- [ ] Toggle `GH_DEBUG_RESPONSES = true`. POSTs return verbose JSON; cross-method requests return 405 with a JSON error.

### 14.3 Mobile sanity

- [ ] Phone-sized viewport: freshness tile spans full width at top; windows tile keeps its three bars legible; logs tile spans full width at the bottom.
- [ ] Tap targets are large enough — log download links don't need a stylus.

---

## 15. Testable requirements

Each requirement is an implementation-level check that the spec is followed. IDs are stable. The "Implements" column traces each TR back to one or more functional requirements in [functional-design.md § 14](functional-design.md#14-testable-requirements). The "Verification" column describes a concrete test.

### 15.1 Backend — `api.php` (controller ingest)

| ID | Requirement | Implements | Verification |
|---|---|---|---|
| TR-01 | `api.php` rejects any request whose method is not `POST`. | FR-01, FR-02 | `curl -X GET https://host/api.php` → 204 (or 405 in debug). No state change. |
| TR-02 | `api.php` verifies `HTTP_SOURCEIDENTIFIER === GH_SECRET_TOKEN` before any state mutation. | FR-02, FR-04 | Send POST with wrong header → no file written, response is silent 204. |
| TR-03 | `api.php` writes `status.json` via a `.tmp` file followed by `rename()`. | FR-01 | Inspect filesystem during a long-running write or set up a probe to read mid-write; never observes a partial file. |
| TR-04 | `api.php` generates log filenames as `YYYY-MM-DD_HHMMSS.log` from server time. | FR-03 | Upload a log; resulting filename matches `^\d{4}-\d{2}-\d{2}_\d{6}\.log$`. |
| TR-05 | `api.php` discards any client-supplied filename. | FR-03, FR-37 | POST log with custom `Content-Disposition: filename="evil.php"`; resulting filename is server-generated and ends in `.log`. |
| TR-06 | `api.php` rejects log uploads exceeding `GH_LOG_MAX_BYTES` before reading the request body. | FR-05 | POST with `Content-Length` larger than the cap → no file stored, response is silent 204 (or 413 in debug). |
| TR-07 | `api.php` runs the retention sweep only on the upload-success path. | FR-07 | Touch a log file's mtime to the past; trigger a non-upload path (status push, malformed upload). The old file remains. |
| TR-08 | `api.php` returns HTTP 204 with empty body on every error path when `GH_DEBUG_RESPONSES = false`. | FR-08 | Send malformed and unauthorized requests; response status is 204 and body length is 0 in all cases. |
| TR-09 | `api.php` returns HTTP 4xx with a JSON error body on error paths when `GH_DEBUG_RESPONSES = true`. | FR-09 | Same triggers as TR-08 with debug on; responses carry a 4xx code and a JSON `{"error":...}` body. |

### 15.2 Backend — `view.php` (browser read)

| ID | Requirement | Implements | Verification |
|---|---|---|---|
| TR-10 | `view.php` rejects any request whose method is not `GET`. | FR-10 | `curl -X POST https://host/view.php` → 204 (or 405 in debug). |
| TR-11 | `view.php` does not read or accept the value of `GH_SECRET_TOKEN`. | FR-35 | Code review: `view.php` contains no reference to `GH_SECRET_TOKEN`; log analysis confirms no `sourceidentifier` lookup on the read path. |
| TR-12 | `view.php` sets `Cache-Control: no-store` on all responses. | — | Read response headers; `Cache-Control: no-store` is present. |
| TR-13 | `view.php` sets `Content-Type: application/json; charset=utf-8` on all responses. | — | Read response headers. |
| TR-14 | `view.php` returns `{}` with HTTP 200 when `GH_STATUS_FILE` does not exist. | FR-14 | Wipe `data/status.json`; GET → 200, body `{}`. |
| TR-15 | Successful read responses include an `age_seconds` field. | FR-13 | GET after a recent push; the response contains `age_seconds` ≥ 0. |
| TR-16 | The log list is sorted by `mtime` descending. | — | Upload three logs over time; the list returns them newest first. |

### 15.3 Storage and Apache configuration

| ID | Requirement | Implements | Verification |
|---|---|---|---|
| TR-17 | `data/.htaccess` issues `Require all denied`. | FR-36 | `curl https://host/data/status.json` → 403. |
| TR-18 | `log/logs/.htaccess` disables directory listings. | FR-38 | `curl https://host/log/logs/` → 403. |
| TR-19 | `log/logs/.htaccess` permits only filenames matching `^[0-9A-Za-z._-]+\.(log\|txt)$`. | FR-37 | Place a file named `bad name.exe` in the directory; URL access is denied. |
| TR-20 | `log/logs/` files served carry `Content-Type: text/plain`. | — | Read response headers on a download. |
| TR-21 | Status writes are atomic via `.tmp` + `rename()`. | FR-01 | Same as TR-03. |

### 15.4 Configuration

| ID | Requirement | Implements | Verification |
|---|---|---|---|
| TR-22 | `config.php` defines all constants listed in § 2 of this document. | — | Static check: every constant referenced in `api.php`, `view.php`, `index.php` is defined in `config.php`. |
| TR-23 | `GH_SECRET_TOKEN` is a string of at least 16 characters drawn from a non-trivial alphabet. | FR-02, FR-04 | Inspect `config.php`; reject deployments where the token is empty, default, or under 16 characters. |
| TR-24 | `GH_DEBUG_RESPONSES` defaults to `false`. | FR-08 | Inspect `config.php` in production. |

### 15.5 Frontend wiring

| ID | Requirement | Implements | Verification |
|---|---|---|---|
| TR-25 | `assets/app.js` issues fetches only to `view.php`, using a **relative** path so the site is portable to any URL prefix (e.g. `/controller/`). It never fetches `api.php`. | FR-35 | DevTools Network tab during normal operation shows requests to `view.php` resolved against the current page; never `api.php`. |
| TR-26 | `assets/app.js` writes payload-derived strings via `textContent`. It never assigns to `innerHTML` from payload data. | FR-39 | Code review and a probe: send a status field containing `<img src=x onerror=alert(1)>`; the dashboard displays the literal text without firing the script. |
| TR-27 | `index.php` injects `window.GH_CFG` with `pollMs`, `defaultIntervalS`, and `windowNames`. | FR-25, FR-44 | View page source; the inline `<script>` declares all three keys. |
| TR-28 | All tile container DOM IDs match the spec list (`tile-freshness`, `tile-climate`, `tile-wind`, `tile-windows`, `tile-mode`, `tile-sun`, `tile-system`). There is no `tile-logs`; logs are served by the standalone `/<prefix>/log/` page. | FR-15, FR-17 | Inspect rendered HTML. |
| TR-29 | `tile-freshness` does not carry the `hidden` attribute on initial render; all other tile containers do. | FR-17 | Inspect rendered HTML before the first fetch completes. |

### 15.6 Frontend — freshness tile

| ID | Requirement | Implements | Verification |
|---|---|---|---|
| TR-30 | The freshness tile re-renders at intervals no greater than 1 second. | FR-24 | Set a debug counter on the redraw function; observe ≥ 1 invocation per second over a 10-second window. |
| TR-31 | Age is anchored against the server-reported `age_seconds` at fetch time and advanced using `performance.now()` between fetches. | FR-13, FR-19, FR-20, FR-21 | Code review of the anchor/redraw logic. Adjust the browser system clock by ±60 s; freshness colour and age caption are unaffected. |
| TR-32 | The dashboard root element receives the `stale` class iff the freshness state is red. | FR-23 | Inspect `<body class>` (or dashboard root) at known ages on either side of the 4× interval threshold. |
| TR-33 | The freshness tile renders the literal string `"No data yet"` when the read API returns `{}`. | FR-26 | Wipe stored status; reload the dashboard; observe the caption. |
| TR-34 | When `update_interval_s` is missing from the payload, the caption appends the literal string `"(assumed)"` after the interval value. | FR-25 | Send a payload without `update_interval_s`; reload; observe the caption. |

### 15.7 Frontend — windows tile

| ID | Requirement | Implements | Verification |
|---|---|---|---|
| TR-35 | The windows SVG uses `viewBox="0 0 200 140"`. | FR-27 | Inspect SVG markup. |
| TR-36 | M1, M2, M3 rects all have `width="160"`. M3 has `height="30"`; M1 and M2 each have `height="18"`. | FR-29 | Inspect SVG markup. |
| TR-37 | The compass labels `N` and `S` appear at the top and bottom of the SVG respectively. | FR-27 | Inspect SVG markup. |
| TR-38 | Each window bar's `<title>` element carries the full unabbreviated state name. | FR-34 | Send `"M1":"MOVING_OPEN"`; the `<title>` text is `M1 South roof: MOVING_OPEN`, not `MOV OPEN`. |
| TR-39 | The state-to-fill mapping in JS produces `var(--blue-light)` for OPEN, `var(--yellow)` for MOVING_*, `var(--green-dark)` for CLOSED, `var(--grey-muted)` for UNKNOWN/missing/unrecognised. | FR-30, FR-31, FR-32, FR-33 | Code review of the `COLOR` map and `renderWindows()` fallback. |

### 15.8 Cross-cutting

| ID | Requirement | Implements | Verification |
|---|---|---|---|
| TR-40 | HTTP 204 vs 4xx dispatch is governed exclusively by `GH_DEBUG_RESPONSES`. | FR-08, FR-09 | Toggle the flag; verify TR-08/TR-09/TR-10 behaviors flip in unison. |
| TR-41 | All endpoints that emit JSON do so via `json_encode()` with no manual string concatenation of payload data. | FR-39 | Code review. |
| TR-42 | The retention sweep uses `mtime` (not `ctime` or `atime`) for the age comparison. | FR-06, FR-07 | Touch a file's `mtime` only; the sweep treats it as the determining timestamp. |

---

## Appendix — Reference files

- `documentation/phpAPIExample/api.php` — auth pattern, silent cleanup pattern, JSON response shape.
- `documentation/webguiExample/data/style.css` — theme variables, `.grid4` grid, `.card` styling, badge styling.
- `documentation/webguiExample/data/index.html` — tile/section HTML structure to emulate.
- `documentation/webguiExample/data/app.js` — fetch + DOM-update pattern (the live-fetch progress bar in particular maps directly onto the freshness tile).
