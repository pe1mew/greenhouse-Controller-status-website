# Greenhouse Controller Status Website — Implementation Plan

| | |
|---|---|
| Document | Implementation Plan |
| Audience | Implementer (you), reviewers, and the future-you who comes back to verify it. |
| Companions | [functional-design.md](functional-design.md) — *what*<br>[technical-spec.md](technical-spec.md) — *how* |
| Version | 0.1 (draft) |
| Date | 2026-05-10 |

This plan sequences the work, lists deliverables per phase, and ties each phase back to the FR/TR matrix that already exists in the design documents. The Flask **mock controller** is built early (Phase 1) so every later phase can be verified against realistic traffic without needing the ESP32 hardware in the loop.

Read order: skim § 1–§ 3 for the shape of the work, then jump into the phase tables. The Flask mock's full specification lives in § 5.

## Table of contents

1. [Strategy and ordering rationale](#1-strategy-and-ordering-rationale)
2. [Prerequisites](#2-prerequisites)
3. [Phase summary](#3-phase-summary)
4. [Phase details](#4-phase-details)
5. [Flask mock controller — full spec](#5-flask-mock-controller--full-spec)
6. [Risks and mitigations](#6-risks-and-mitigations)
7. [Definition of done](#7-definition-of-done)
8. [Rough effort estimate](#8-rough-effort-estimate)

---

## 1. Strategy and ordering rationale

Three principles drive the phase order:

1. **Build the test harness before the thing under test.** Phase 1 is the Flask mock. Without it, the rest of the work has to wait on real-controller availability, which is out of scope for this session and on a different schedule.
2. **Vertical slice, then breadth.** A minimal end-to-end pipeline (mock → `api.php` → file → `view.php` → DevTools) is wired up in phases 1–3 before any frontend code is written. This catches integration mistakes early.
3. **Most observable first.** Within the frontend, the freshness tile and windows tile (the two visually distinctive deliverables) come *after* the boring tiles, so they are not blocked on the basics and can be polished without churn.

Each phase ends with a checklist of FR-IDs and TR-IDs from the design docs. A phase is "done" only when every cited requirement passes its verification.

---

## 2. Prerequisites

- A PHP 8.1+ host with Apache and `mod_rewrite`/`AllowOverride All` for the project directory.
- Local Python 3.10+ for the Flask mock.
- HTTPS in production (self-signed is acceptable for staging). Plain HTTP is acceptable in development as long as the production cutover step (§ 4 phase 11) flips it on.
- Read access to the firmware repository at `C:\Users\drasv\github\greenhouse-Controller` for cross-checking field names — but no firmware changes are made here.

---

## 3. Phase summary

| # | Phase | Deliverables | Blocks |
|---|---|---|---|
| 0 | Skeleton | Directory tree, empty config, `.htaccess` files, smoke-test page. | All later phases. |
| 1 | **Flask mock** | Working mock controller posting to `api.php`. Tiny control UI. | Verification of phases 2–10. |
| 2 | Backend ingest | `api.php` (POST status, POST log, silent-drop, debug toggle). | Phase 3 (read API has nothing to return until ingest writes). |
| 3 | Backend read | `view.php` (GET status, GET logs). | Phase 6+ (frontend has nothing to fetch). |
| 4 | Frontend shell | `index.php` skeleton, `GH_CFG` injection, empty tile containers. | Phases 5–8. |
| 5 | Theme + grid | `assets/style.css` base — variables, grid, card layout, stale-dim. | Phases 6–8. |
| 6 | Polling + simple tiles | `assets/app.js` polling loop, render show/hide, climate/wind/mode/sun/system tiles, logs tile, connection-lost banner. | Phase 9. |
| 7 | Freshness tile | Always-on heartbeat tile with anchor-based countdown, color thresholds, OFFLINE badge, dashboard-stale class wiring. | Phase 9. |
| 8 | Windows tile | Inline SVG, `renderWindows()`, state-to-color map, accessibility tooltips. | Phase 9. |
| 9 | Mobile QA | DevTools 360×800 walkthrough, tap-target audit, layout fixes. | Phase 10. |
| 10 | Security pass | `.htaccess` audits, XSS probe via mock, secret-in-browser check. | Phase 11. |
| 11 | Deployment | Production secret, HTTPS, debug-flag flip, Apache config, credentials handoff. | Phase 12. |
| 12 | Real-controller integration | Coordinate with the controller-side session; cutover from mock to ESP32; sign-off. | — |

---

## 4. Phase details

### Phase 0 — Skeleton

**Deliverables**

- Directory tree exactly as in [technical-spec.md § 1](technical-spec.md#1-directory-layout). All web-served files live under `httproot/`; Apache's `DocumentRoot` points there.
  - `httproot/config.php` with placeholder constants (real secret comes in phase 11).
  - `httproot/data/.htaccess` (`Require all denied`).
  - `httproot/log/logs/.htaccess` (extension whitelist + `Options -Indexes`).
  - Empty placeholder files: `httproot/api.php`, `httproot/view.php`, `httproot/index.php`, `httproot/assets/style.css`, `httproot/assets/app.js`.
- A one-line `httproot/index.php` that prints "skeleton ok" so we can confirm Apache reaches the project.

**Verification**

- `curl http://host/` → "skeleton ok".
- `curl http://host/data/status.json` → 403.
- `curl http://host/log/logs/` → 403.

**Maps to** TR-17, TR-18, TR-22 (constants exist).

---

### Phase 1 — Flask mock controller

This is the centrepiece of the testing strategy. Full spec in § 5.

**Deliverables**

- `mock/` directory at the project root (separate from the deployed website; gitignored as a dev tool).
  - `mock/app.py` — Flask app.
  - `mock/state.py` — sim state model.
  - `mock/scheduler.py` — periodic-push background thread.
  - `mock/templates/control.html` — control panel.
  - `mock/static/style.css` — same dark theme as `documentation/webguiExample/data/style.css`.
  - `mock/sample.log` — a 2 KB fixture used by the "upload log" button.
  - `mock/requirements.txt` — `flask`, `requests`.
  - `mock/README.md` — how to run it.
- A README section in the project root explaining `python -m flask --app mock.app run --port 5000`.

**Verification**

- Mock running locally; control panel renders at `http://localhost:5000/`.
- "Send one push" button → terminal log shows the push attempt and the response status from `api.php`.
- Panels for: live status fields, window states, top-level object toggles, scheduler on/off, send-malformed.
- The mock targets `http://localhost/api.php` by default, configurable via `MOCK_TARGET_BASE_URL`.

**Maps to** — the mock itself isn't covered by FR/TR, but it *implements the verification side* of every later phase.

---

### Phase 2 — Backend ingest (`api.php`)

**Deliverables**

- `api.php` per [technical-spec § 3](technical-spec.md#3-backend--controller-ingest-api-apiphp):
  - Method-not-POST → silent 204 / debug 405.
  - Header check → silent 204 / debug 401.
  - Status push: parse, attach `received_at`, atomic `.tmp` + `rename`.
  - Log upload: size cap, server-generated filename, retention sweep.
  - Debug-flag-driven response branching.

**Verification**

Drive the file with the Flask mock:

- Send 5 valid pushes; `data/status.json` reflects the latest one. (FR-01, TR-03)
- Toggle the mock secret to a wrong value; pushes have no effect; default mode returns 204. (FR-02, TR-02, TR-08)
- Flip `GH_DEBUG_RESPONSES = true`; same wrong-secret push now returns 401 with JSON. (FR-09, TR-09)
- Mock "send-malformed" button → silent 204 default, 400 in debug. (FR-08, FR-09)
- Upload a 6 MB log via the mock → silent 204 / debug 413. (FR-05, TR-06)
- Touch a fixture log file's mtime to 100 days ago, trigger a successful upload, observe the old file is gone. (FR-07, TR-07, TR-42)
- Touch the mtime to 30 days ago and trigger a non-upload path → file remains. (TR-07)

**Maps to** FR-01..FR-09, TR-01..TR-09, TR-21, TR-42.

---

### Phase 3 — Backend read (`view.php`)

**Deliverables**

- `view.php` per [technical-spec § 4](technical-spec.md#4-backend--browser-read-api-viewphp):
  - Non-GET → silent 204 / debug 405.
  - Default action: read `status.json`, attach `age_seconds`, return.
  - `?action=logs`: glob, sort by mtime desc, return JSON.
  - Always sets `Cache-Control: no-store` and `Content-Type: application/json; charset=utf-8`.

**Verification**

- `curl http://host/view.php` against an empty server → `200 {}`. (FR-14, TR-14)
- After a mock push, `curl` shows the payload + `age_seconds` ≥ 0. (FR-13, TR-15)
- Mock uploads three logs spaced 1 s apart; `curl http://host/view.php?action=logs` returns them newest first. (TR-16)
- Response headers contain `Cache-Control: no-store`. (TR-12)
- `curl -X POST http://host/view.php` → silent 204 / debug 405. (TR-10)
- Code review confirms `view.php` does not reference `GH_SECRET_TOKEN`. (FR-35, TR-11)

**Maps to** FR-10..FR-14, TR-10..TR-16.

---

### Phase 4 — Frontend shell (`index.php`)

**Deliverables**

- HTML skeleton with viewport meta, theme link, app.js script tag.
- Inline `<script>` declaring `window.GH_CFG = { pollMs, defaultIntervalS, windowNames }`.
- Tile containers in the prescribed order. `tile-freshness` not hidden; the others all carry `hidden`.
- Hard-coded `<div id="conn-banner" hidden>Connection lost</div>` near the top.

**Verification**

- View page source on `http://host/`; all required tile IDs present, `GH_CFG` populated. (TR-27, TR-28, TR-29)
- `tile-freshness` is not hidden; all other tiles have `hidden`. (FR-17, TR-29)

**Maps to** TR-27, TR-28, TR-29.

---

### Phase 5 — Theme + grid (`assets/style.css`)

**Deliverables**

- CSS variables block (theme variables from webguiExample plus the three new colors `--blue-light`, `--green-dark`, `--grey-muted`).
- `.tiles` grid with `repeat(auto-fit, minmax(160px, 1fr))`.
- `.tile` card styling.
- `.tile-freshness { grid-column: 1 / -1; }` and `.tile-windows { grid-column: span 2; min-width: 280px; }`.
- `.dashboard.stale .tile { opacity: 0.55; }` with the freshness exception.
- `[hidden] { display: none !important; }` so HTML `hidden` always wins over class-based `display` rules (e.g. `.badge`).
- Mobile rule collapsing `.tile-windows` to a single column at narrow viewport widths.

**Verification**

- Visual: open `http://host/` (still mostly empty) at 360×800 in DevTools; freshness tile spans full width even though empty; cards are dark-themed.

**Maps to** FR-43, FR-44.

---

### Phase 6 — Polling + simple tiles (`assets/app.js`)

**Deliverables**

- Polling loop with `cfg.pollMs` interval; `failCount` + connection-lost banner.
- `render(s)` that walks the `TILES` predicate map and toggles `hidden`.
- Field renderers for climate, wind, mode, sun, system tiles. All writes via `textContent`.
- Stub `renderWindows()` and `renderFreshness()` that no-op (filled in by phases 7–8).

The dashboard does **not** poll for log files. Logs are surfaced through the
standalone `httproot/log/index.php` page, which server-renders the list and
is reached only by direct URL.

**Verification**

- Drive with the mock:
  - Toggle the mock's `wind` object off → wind tile hides; back on → reappears. (FR-15, TR-25)
  - Toggle a single key inside `climate` → that line hides; tile remains. (FR-16)
  - Send a payload string containing `<img src=x onerror=alert(1)>` for `system.fw_ver`; dashboard renders it as text. (FR-39, TR-26)
  - Stop the mock scheduler; observe banner appears after three failed polls; restart; banner clears. (FR-40, FR-41)

**Maps to** FR-10, FR-15, FR-16, FR-39, FR-40, FR-41, TR-25, TR-26.

---

### Phase 7 — Freshness tile

**Deliverables**

- Inline HTML for the freshness tile inside `index.php` shell:
  ```html
  <section id="tile-freshness" class="tile tile-freshness">
    <div class="fresh-track"><div id="fresh-fill" class="fresh-fill"></div></div>
    <div class="fresh-row">
      <span id="fresh-caption" class="muted">No data yet</span>
      <span id="fresh-offline" class="badge offline" hidden>OFFLINE</span>
    </div>
  </section>
  ```
- `.fresh-track`, `.fresh-fill` styles in `style.css`.
- JS: anchor logic in `onPayload(s)` (set `lastPayload` and the `anchor` `{fetchedAtMono, ageAtFetch}`).
- JS: `renderFreshness()` 1 Hz redraw — fill width, fill background, caption, offline badge, `dashboard.stale` toggle.
- Use `cfg.defaultIntervalS` fallback when `update_interval_s` is absent and append "(assumed)".
- Initial `renderFreshness()` call so the tile is meaningful before the first fetch.

**Verification**

Drive entirely through the mock:

- Set mock `update_interval_s = 10` and start scheduler at the same interval. Observe bar drains at ≈ 75 % at 10 s, ≈ 50 % at 20 s, hits red at > 40 s when scheduler is paused. (FR-19, FR-20, FR-21)
- Pause the mock; at >40 s observe OFFLINE badge appears and other tiles dim. (FR-22, FR-23)
- Bar fill changes every second per visual inspection. (FR-24, TR-30)
- Drop `update_interval_s` from the mock payload; caption shows "(assumed)". (FR-25, TR-34)
- Wipe `data/status.json`, reload page; tile shows "No data yet" and red drained bar. (FR-26, TR-33)
- Adjust browser system clock by ±60 s; freshness display unaffected. (TR-31)

**Maps to** FR-17, FR-18..FR-26, TR-30..TR-34.

---

### Phase 8 — Windows tile

**Deliverables**

- Inline SVG inside `index.php` shell exactly per [technical-spec § 10](technical-spec.md#10-windows-tile-svg). N/S labels, three `<rect>`s with the prescribed dimensions, three `<text>` labels, three `<title>` accessibility tooltips.
- `renderWindows(windows)` JS that updates fills, labels, and titles.
- `shortState()` helper for narrow-display abbreviations.

**Verification**

Drive through the mock window state controls:

- Mock dropdown for M1 → cycle through OPEN, MOVING_OPEN, MOVING_CLOSE, CLOSED, UNKNOWN. Bar color changes per the mapping. (FR-30..FR-33, TR-39)
- Send `"M1": "WAT"` (an unrecognised state) → bar is muted grey. (FR-33, TR-39)
- Drop the `M2` key → that bar is muted grey but M1 and M3 are unaffected. (FR-33)
- Long-press the M1 bar on a touch device → tooltip shows full state name, not the abbreviation. (FR-34, TR-38)
- Visual inspection: M3 along the top edge, larger than M1/M2; N at top, S at bottom. (FR-27, FR-28, FR-29, TR-35, TR-36, TR-37)

**Maps to** FR-27..FR-34, TR-35..TR-39.

---

### Phase 9 — Mobile QA

**Deliverables**

- DevTools walkthrough at 360×800 with screenshots saved into `design/screenshots/` for the record.
- Tap-target audit: every `<a>` and `<button>` on the dashboard, plus every Download button on the standalone logs page (`/log/`), has computed size ≥ 44×44 CSS px.
- Any layout fixes required by the audit.

**Verification**

- FR-43, FR-44, FR-45 manual sign-off.

**Maps to** FR-43, FR-44, FR-45.

---

### Phase 10 — Security pass

**Deliverables**

- Run the mock's "send `<img onerror>`" probe one more time (paranoid double-check). (FR-39, TR-26)
- DevTools Network tab on a normal session: assert no request carries `sourceidentifier`. (FR-35, TR-25)
- Probe `/data/status.json`, `/log/logs/`, `/log/logs/x.php`, `/config.php` directly — all denied. (FR-36, FR-37, FR-38, TR-17, TR-18, TR-19)
- Verify `GH_SECRET_TOKEN` is at least 16 chars and not the placeholder. (TR-23)

**Maps to** FR-35..FR-39, TR-17..TR-21, TR-23.

---

### Phase 11 — Deployment

**Deliverables**

- Production secret generated (32+ chars from a CSPRNG); committed to `config.php` only on the production host, not the repo.
- HTTPS certificate in place (Let's Encrypt or whichever the host uses).
- `GH_DEBUG_RESPONSES = false` confirmed.
- Apache config:
  - `AllowOverride All` over the project directory so `.htaccess` files are honored.
  - HTTPS redirect from port 80.
- Hand off to the controller team: secret value, URL, expected JSON shape (Appendix A of functional design).

**Verification**

- All Phase 10 checks repeated against the production host over HTTPS.
- TR-24 confirmed (`GH_DEBUG_RESPONSES = false`).

**Maps to** TR-23, TR-24.

---

### Phase 12 — Real-controller integration

**Deliverables**

- Coordinate with the controller-side session (out of scope for this repo).
- Controller's first push lands in `data/status.json`; verified by reloading the dashboard.
- Mock controller stays available as a regression-test tool indefinitely.

**Verification**

- Walk the full FR matrix one more time against the live ESP32. Sign-off when every FR's verification passes.

---

## 5. Flask mock controller — full spec

### 5.1 Purpose

Pretend to be the greenhouse controller during development so the website can be exercised across every interesting state (every tile present/absent, every window state, malformed bodies, late and missing pushes) without needing the ESP32. The mock is a permanent regression tool, not a throwaway.

### 5.2 Layout

```
mock/
├── app.py                   # Flask app + routes + scheduler bootstrap
├── state.py                 # Mutable in-memory sim state with sane defaults
├── pusher.py                # Background thread that POSTs at the configured cadence
├── templates/
│   └── control.html         # Tiny dark-theme control panel
├── static/
│   └── style.css            # Borrowed from documentation/webguiExample
├── sample.log               # ~2 KB plaintext fixture for the upload-log button
├── requirements.txt         # flask>=3, requests>=2
└── README.md                # How to run; env vars; common scenarios
```

### 5.3 Configuration (env vars)

| Variable | Default | Purpose |
|---|---|---|
| `MOCK_TARGET_BASE_URL` | `http://localhost` | Where to send pushes (root of the PHP site). |
| `MOCK_SECRET` | `dev-secret-please-change` | Value of the `sourceidentifier` header on outbound pushes. Must match the website's `GH_SECRET_TOKEN`. |
| `MOCK_INTERVAL_S` | `10` | Auto-push cadence; also serves as the `update_interval_s` payload field by default. |
| `MOCK_BIND` | `127.0.0.1:5000` | Where the control panel listens. |

### 5.4 Sim state model (`state.py`)

A single module-level dict, protected by a lock, with these top-level keys:

```python
state = {
    'enabled_objects': {  # which top-level objects to include in pushes
        'climate': True, 'wind': True, 'windows': True,
        'mode':    True, 'sun':  True, 'system':  True,
    },
    'update_interval_s': 10,        # None to omit the field entirely
    'climate': {'temp_c': 24.5, 'rh_pct': 72},
    'wind':    {'speed_ms': 3.5, 'direction_deg': 180},
    'windows': {'M1': 'OPEN', 'M2': 'MOVING_OPEN', 'M3': 'CLOSED'},
    'mode':    {'current': 'AUTOMATIC', 'flags': []},
    'sun':     {'is_daytime': True, 'sunrise_min': 360, 'sunset_min': 1260},
    'system':  {'ntp_synced': True,
                'wifi_ip': '192.168.1.100', 'wifi_rssi_dbm': -45, 'fw_ver': '1.17.0'},
    'scheduler_running': True,
    'last_response': None,          # status code + ms latency of last push
}
```

Helpers:

- `build_payload()` returns a dict containing only the enabled top-level objects, plus `type='status'` and `update_interval_s` (if not None).
- `set_field(path, value)` accepts dotted paths like `wind.speed_ms`.
- `toggle_object(name)` flips inclusion.

### 5.5 Background pusher (`pusher.py`)

A `threading.Timer`-based loop (or `apscheduler`, both fine):

```python
def push_once():
    if not state['scheduler_running']:
        return
    payload = build_payload()
    r = requests.post(
        f"{TARGET}/api.php",
        json=payload,
        headers={'sourceidentifier': SECRET},
        timeout=5,
    )
    state['last_response'] = {'code': r.status_code, 'at': time.time()}
```

Schedule `push_once` every `MOCK_INTERVAL_S`. The scheduler starts automatically on app startup.

### 5.6 Control panel (`/`)

A single dark-theme page with these sections:

- **Status of the mock**: scheduler running yes/no; last push status code + how long ago.
- **Object toggles**: six checkboxes for climate, wind, windows, mode, sun, system. Each maps to `enabled_objects[<name>]`.
- **`update_interval_s`**: number input + a "send omitted" button to push without the field.
- **Window state pickers**: three dropdowns (M1, M2, M3) cycling through the five state strings + "(unrecognised)".
- **Climate / wind sliders**: temperature, humidity, wind speed, wind direction. Direct edits update the sim state on submit.
- **Mode flags**: multi-select with the seven decoded flag names.
- **Buttons**:
  - **Send one push now** — uses current sim state.
  - **Send malformed JSON** — POSTs `not-json` body to test silent-drop.
  - **Send wrong secret** — POSTs with a deliberately-wrong header.
  - **Upload sample log** — POSTs `sample.log` to `?action=log`.
  - **Pause / resume scheduler** — toggles the auto-push.

All actions are POSTed to a small set of helper endpoints on the mock itself; no JavaScript framework, just `<form>` submissions for simplicity.

### 5.7 Endpoints exposed by the mock

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Render the control panel. |
| POST | `/set` | Update a sim field (path + value, or object toggle). |
| POST | `/push` | Send one push using the current state. |
| POST | `/push-malformed` | Send a malformed JSON body. |
| POST | `/push-bad-secret` | Send with a wrong `sourceidentifier`. |
| POST | `/log/upload` | Send `sample.log` to `?action=log`. |
| POST | `/scheduler/<start\|stop>` | Toggle auto-push. |

### 5.8 What the mock does *not* do

- It does not run the website. The PHP site runs separately in Apache; the mock only POSTs to it.
- It does not record history or replay scenarios. (Listed in § 6 as a possible future extension.)
- It does not implement controller business logic. The window states, mode, and flags are whatever the operator picks.

---

## 6. Risks and mitigations

| Risk | Mitigation |
|---|---|
| `rename()` not atomic on a Windows dev box where source and dest end up on different volumes (mapped drives, network shares). | Detect during phase 2 verification: if a probe ever observes a partial JSON file, switch to `flock`-based locking inside `view.php` until production deploy. |
| Apache host on shared hosting refuses `Options -Indexes` in `.htaccess`. | Confirm during phase 0 with a probe; if blocked, ask the host to add `AllowOverride Options Indexes` or place an empty `index.html` in `log/logs/` as a backstop. |
| Browser caches `view.php` despite `Cache-Control: no-store`. | The `cache: 'no-store'` on the `fetch` call (technical-spec § 9.1) provides defense-in-depth. Verify by toggling DevTools "Disable cache" off and confirming poll behavior unchanged. |
| Mock and website become out of sync on the secret. | Mock loads `MOCK_SECRET` from env; document that updating one requires updating both. The mock's "Send wrong secret" button is the deliberate verification of the failure path. |
| Mock latency on first run after a long idle (Python import cost) skews freshness verification. | Run one warm-up `/push` after starting the mock before timing-sensitive tests. |
| Real controller's payload deviates from the nested schema in Appendix A. | Phase 12 includes a side-by-side payload comparison; deltas trigger a controller-session ticket rather than a website change. |

---

## 7. Definition of done

The website ships when:

- Every FR-01 through FR-45 in [functional-design.md § 14](functional-design.md#14-testable-requirements) passes its verification step.
- Every TR-01 through TR-42 in [technical-spec.md § 15](technical-spec.md#15-testable-requirements) passes its verification step.
- The mock is preserved at `mock/` with a working README so any future engineer can re-run the same verifications.
- A signed-off run of the FR matrix exists against the real ESP32 (Phase 12), with output captured under `design/sign-off-YYYY-MM-DD.md`.

### 7.1 Verification sign-off snapshot (test server, 2026-05-10)

Walked through phases 0–10 against the deployed test server at
`http://192.168.20.232/controller/`. Phases 11–12 are partially done or
out of scope.

| Area | Status | Notes |
|---|---|---|
| Phase 0 — Skeleton | ✅ done | `httproot/` tree exists with `.htaccess` files in place. |
| Phase 1 — Flask mock | ✅ done | Auto-loads target URL from `.deploy.env`; pushes every 10 s. |
| Phase 2 — Backend ingest | ✅ done | Auth + atomic write + retention sweep verified by curl probes. |
| Phase 3 — Backend read | ✅ done | `view.php` returns `{}` empty / payload + `age_seconds`; `Cache-Control: no-store`. |
| Phase 4 — Frontend shell | ✅ done | All tile containers + `GH_CFG` injected; freshness tile not initially `hidden`. |
| Phase 5 — Theme + grid | ✅ done | Dark theme variables + responsive grid + `dashboard.stale` dim with freshness exception. |
| Phase 6 — Polling + simple tiles | ✅ done | All five dynamic tiles populate; show/hide rule verified via mock toggles. (Logs tile removed; logs now at unlinked `/controller/log/`.) |
| Phase 7 — Freshness tile | ✅ done | 1 Hz redraw; `performance.now()` anchoring; green/amber/red thresholds. Late fix: `[hidden] { display: none !important; }` to make the OFFLINE pill respect the attribute. |
| Phase 8 — Windows tile | ✅ done | M3 above M2 above M1 (north on top); state-driven colors; bold labels matching OFFLINE pill; black text on light-blue OPEN. |
| Phase 9 — Mobile QA | ⏳ pending | Needs manual DevTools 360×800 walkthrough by the operator. |
| Phase 10 — Security pass | ◐ partial | See breakdown below. |
| Phase 11 — Deployment | ◐ partial | Deployed to `Shuttle2:/var/www/html/controller/`; deploy script auto-fixes perms. **HTTPS not yet enabled, secret not yet rotated.** |
| Phase 12 — Real-controller | ⛔ out of scope | Coordinated separately. |

### Phase 10 detail

| FR / TR | Verification | Result |
|---|---|---|
| FR-02 / TR-02 (auth) | POST with wrong secret → 204 silent, status.json untouched, `temp_c` stayed `24.5` instead of malicious `-999`. | ✅ pass |
| FR-08 / TR-08 (silent drop) | Wrong-secret + wrong-method probes all return 204. | ✅ pass |
| TR-10 (view.php POST rejected) | `POST /view.php` → 204. | ✅ pass |
| TR-01 (api.php GET rejected) | `GET /api.php` → 204. | ✅ pass |
| FR-39 / TR-26 (XSS-safe rendering) | `grep -nE "innerHTML\|outerHTML\|document.write"` in `app.js`: 0 matches. All payload writes use `textContent`. Push of `<img src=x onerror=alert(1)>` confirmed round-tripping as data, not executable HTML. | ✅ pass |
| FR-35 / TR-25 (no secret in browser) | `grep "sourceidentifier\|GH_SECRET\|dev-12345"` in `app.js`: 0 matches. | ✅ pass |
| TR-23 (token strength) | Length 42 chars (≥16 OK), but **still the placeholder** `dev-1234567890abcdef-please-rotate-in-prod`. | ⚠ blocker for non-LAN deploy |
| TR-24 (debug off in prod) | `GH_DEBUG_RESPONSES = false`. | ✅ pass |
| FR-36 / TR-17 (`data/` deny) | `GET /controller/data/status.json` → 200. **`.htaccess` is ignored** (`AllowOverride None` server-wide). | ⚠ deferred per operator |
| FR-37 / TR-19 (log filename whitelist) | Same `.htaccess` deferral. | ⚠ deferred |
| FR-38 / TR-18 (no log dir listing) | `GET /controller/log/logs/` → 200 (Apache lists files because `Options -Indexes` is in the ignored `.htaccess`). | ⚠ deferred |
| `config.php` HTTP exposure | `GET /controller/config.php` → 200, **0 bytes** (PHP processes pure `define()` to no output). Secret never leaves the server. | ✅ pass |

### Outstanding before non-test deploy

1. **Rotate `GH_SECRET_TOKEN`** in `httproot/config.php` AND set `MOCK_SECRET` in `.deploy.env` (or override `mock/pusher.py`'s default) to the new value. Redeploy. (TR-23 blocker.)
2. **Enable AllowOverride All** on the Apache server, so the existing `.htaccess` files take effect:
   ```bash
   sudo sed -i 's|AllowOverride None|AllowOverride All|' /etc/apache2/apache2.conf
   sudo apache2ctl configtest && sudo systemctl reload apache2
   ```
   Then re-run the FR-36/37/38 probes to confirm 403s. (Closes the deferred items.)
3. **Move to HTTPS** so the `sourceidentifier` header is not sent in plaintext on the wire.
4. **Phase 9 mobile pass** — manual walkthrough on a 360×800 viewport.
5. **Phase 12 real-controller cutover** — separate session.

---

## 8. Rough effort estimate

Sketch only — calibrate to your own pace.

| Phase | Effort |
|---|---|
| 0 Skeleton | 1 hr |
| 1 Flask mock | 4–6 hr |
| 2 Backend ingest | 3 hr |
| 3 Backend read | 1.5 hr |
| 4 Frontend shell | 1 hr |
| 5 Theme + grid | 1.5 hr |
| 6 Polling + simple tiles | 4 hr |
| 7 Freshness tile | 3 hr |
| 8 Windows tile | 2.5 hr |
| 9 Mobile QA | 1.5 hr |
| 10 Security pass | 1 hr |
| 11 Deployment | 2 hr (host-dependent) |
| 12 Real-controller integration | open-ended; depends on controller availability |
| **Total to phase 11** | **~26–30 hr** |

The mock (phase 1) is the largest single chunk. Treat it as an investment that pays back across phases 2–10.
