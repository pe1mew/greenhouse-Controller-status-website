# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

Implementation phases 0–8 of the [implementation plan](design/implementation-plan.md) are complete and the dashboard is deployed to a LAN test server. Phase 10 security pass walked through; a per-IP rate limiter and a layered no-index policy have since been added in preparation for a public-internet deployment. Phase 9 mobile QA in progress (operator-driven, iterative). Phase 12 (real ESP32 integration) deferred to a separate session.

### Changed — Freshness tile caption: full date + adaptive age (2026-05-19)
- `fmtClock(epoch)` in `httproot/assets/app.js` renamed to `fmtDateTime(epoch)` and extended to emit `YYYY-MM-DD HH:MM:SS` instead of `HH:MM:SS`. Rationale: when the controller has been offline overnight or longer, a bare clock time is ambiguous — the operator needs to see at a glance that the last reading is from a previous day.
- `age` in the same caption is now formatted with the existing `fmtUptime()` so it adapts across the same `Ns` / `Nm Ns` / `Nh Nm` / `Nd Nh Nm` buckets as the System-tile uptime. The caption used to render `age 633674s` after a week-long outage; it now reads `age 7d 8h 21m`. The two adaptive fields share one formatter so they can't drift apart.
- The format is fixed (no locale-sensitive variants) so the caption stays parseable across timezones / browsers.
- Spec text updated to match in `design/functional-design.md` § 8.2 / § 8.3 (caption template + ASCII sketches) and `design/technical-spec.md` § 11.2 (`formatCaption` emit rules).
- `fmtUptime()` confirmed against the buckets `Ns` / `Nm Ns` / `Nh Nm` / `Nd Nh Nm`; no code change needed (the May 10 `added uptime` commit already produces this format — e.g. `59s`, `23m 59s`, `23h 59m`, `1d 4h 23m`). If a deployment still shows raw seconds, redeploy `httproot/assets/app.js` and bust the browser cache.

### Added — User manual (Dutch) for the public status page (2026-05-24)
- `manual/userManual.md` — Dutch-language, operator-facing manual covering every tile (Versheid, Klimaat, Wind, Ramen, Modus, Daglicht, Systeem), all 10 mode-flag badges with severity and meaning, the connection-lost banner, the stale-data dim, mobile use, and a troubleshooting reference keyed by "wat u ziet → wat er aan de hand is". Targets a non-technical reader; no schema or code references. Cross-links (with parenthetical "Engelstalig" markers) to `design/functional-design.md`, `design/technical-spec.md`, and `design/apiSpecification.md` for deeper material.
- UI strings rendered by the dashboard (mode pill labels, flag-badge text, `Last update`, `No data yet`, `Connection lost`, `OFFLINE`, window state names, etc.) are kept in their original English wording so the manual matches what the operator literally sees on screen. Each is paired with a Dutch explanation.
- New top-level directory `manual/` for end-user documentation, separate from the technical `design/` directory.
- Deliberately omitted from this revision (by operator's request): the per-badge "what to do" column on the flag-badge table, the "first thing to try" column on the troubleshooting table, the dedicated section on the separate `/log/` page, and the privacy section's bullets about `noindex`/search-engine behaviour, CSV-log exposure, and Basic-Auth gating. Manual is intentionally scoped to "what each thing means", not "what to do about it".
- Images added under `manual/images/`. The full-dashboard screenshot (`userManualFrontPage.png`) sits unnumbered above the header table as a title-page illustration. Six numbered figures follow: Versheid (Figuur 1), Klimaat + Wind (2), Ramen (3), Modus + Daglicht (4), Systeem (5), Voettekst (6). Figuren 1 and 3 replace the previous ASCII sketches of the Versheid bar and Ramen schematic; the rest are placed at the start of each subsection.

### Changed — `manual/md2pdf.py`: accept markdown-table version metadata (2026-05-24)
- The existing version-extraction regex matched only the inline bold form (`**Versie:** X.Y`). The Dutch manual carries its metadata in a markdown table (`| Versie | 1.0 |`), which previously fell through and produced a `v?` header label. Added a second regex (`_VERSION_RE_TABLE`) and turned `extract_version()` into a try-each-pattern loop. Bold form still wins if both appear, so existing docs are unaffected.
- Verified by running `python manual/md2pdf.py manual/userManual.md` end-to-end. Output: `manual/userManual.pdf` (18 pages, ~1.6 MiB), rendered via Edge headless (Skia/PDF m148), header now correctly reads `Kas Controller - Herenboeren Wenumseveld … v1.0`.

### Changed — Mode-tile badges aligned with firmware 2.0.0-a.6.35.x JSON contract (2026-05-19)
- Updated `FLAG_CLASS` in `httproot/assets/app.js` to the 10-flag set documented in `../greenhouse-Controller/design/technical-spec-statusWebsite.md` § 9.4 / TR-47:
  - **Reclassified**: `wind_override` warn → alarm; `sensor_fault_temp`, `sensor_fault_wind` alarm → warn; `ota_in_progress` info → warn.
  - **Removed**: `sensor_fault_rh` (no longer emitted by the firmware; if it ever shows up TR-48 silently drops it).
  - **New flags**: `net_backoff_active` (warn), `wind_protect_off` (warn), `humidity_ctrl_off` (info), `coredump_available` (info).
- Added `FLAG_LABEL` lookup so badges render with human-readable text (`WIND`, `MOTOR ALARM`, `T/RH fault`, `Wind fault`, `OTA active`, `Calibrating`, `Net backoff`, `Wind protect off`, `Humidity ctrl off`, `Coredump available`) instead of the raw underscore identifiers. `FLAG_DESC` (tooltip) refreshed to match.
- `renderMode()` now follows TR-48: a flag whose string is not in `FLAG_CLASS` is silently dropped (no `flag-mute` fallback, no console noise). Forward-compatible with future firmware that emits flags this dashboard hasn't been built for yet.
- Added `STANDBY` to `MODE_CLASS` / `MODE_DESC` (mapped to the existing `mode-mute` styling). The five pill states the firmware can emit per § 3.4 are now all explicitly handled.
- `httproot/assets/style.css`: added `--blue: #2196f3` to `:root`, and switched `.flag-info` from `var(--blue-light)` + black text to `var(--blue)` + white text per § 9.4. The pre-existing `--blue-light` stays in place — it's still used by the OPEN-window-tile fill.
- **Mock**: `mode.flags` is now editable from the control panel. `mock/state.py` gains a `toggle_flag(name)` helper, `mock/app.py` exposes `/mode/flag/<name>`, and the `Mode` section of `mock/templates/control.html` renders a button per known flag (plus an `__unknown__` button used to exercise TR-48). The "Editing the flags array is not implemented in v1" placeholder has been removed.

### Added — Mock controller: uptime override widget (2026-05-19)
- `mock/state.py` gains a new `uptime_override_s` field (default `None`). When set to a non-negative integer, `build_payload()` emits that value as `system.uptime_s` instead of computing the live tick from `time.monotonic() - _STARTED_AT`. Clearing the field (blank in the form) reverts to live-tick behaviour.
- `mock/templates/control.html` gains a new *System / uptime override* section so the operator can pin the value from the browser. Used to verify the dashboard's four `fmtUptime()` buckets in a single sitting (`59` → `59s`, `1439` → `23m 59s`, `86340` → `23h 59m`, `101580` → `1d 4h 23m`) instead of leaving the mock running for ~24 hours.

### Changed — Climate / System / Wind / Daytime / Mode tile UI iteration (2026-05-10)
- **System tile**: removed `system.wifi_ip` from the rendered output (operationally noise on a LAN dashboard). Replaced the `wifi_rssi_dbm` numeric text with a horizontal signal-strength bar labelled **WiFi**, modelled on the freshness bar — linear map −90 dBm → 0 %, −30 dBm → 100 %, with green ≥ −54 dBm, yellow ≥ −72 dBm, red below. Added an **Uptime** row sourced from `system.uptime_s` (formatted as `1d 2h 3m` / `5h 12m` / `2m 3s` / `Ns`). NTP/RTC status kept. All three rows now stacked vertically and left-aligned. New CSS rules (`.sys-row`, `.sys-label`, `.sys-rssi-track`, `.sys-rssi-fill`) modelled on the existing freshness-bar pattern in `httproot/assets/style.css`. New JS helpers `fmtUptime()`, `rssiToPct()`, `rssiQuality()` in `httproot/assets/app.js`.
- **Daytime tile** (was *Sun*): heading renamed for clarity. Internal id (`tile-sun`) and JS handles unchanged so wiring stays stable.
- **Wind tile**: speed and direction split onto separate `<p class="big">` lines (`wd-speed` for `m/s`, `wd-dir` for `°` + cardinal) instead of one wrapping line. `renderWind()` updated accordingly. Climate tile already had its two values on separate lines — no structural change.
- **Tooltips on every field and value**: native `title`-attribute mouseovers added across the dashboard. Static tooltips for tile headings, units, and field meanings are in `httproot/index.php`; value-dependent tooltips (RSSI quality + dBm value, NTP synced/pending, day/night, per-mode and per-flag descriptions) are set in `httproot/assets/app.js` via new `MODE_DESC` and `FLAG_DESC` dictionaries plus the `rssiQuality()` helper. Per-window SVG `<title>` elements in the windows tile already existed and are unchanged.
- **Climate tile setpoints**: new sub-line under each value (smaller, non-bold, muted-colour via the new `.setpoint` class) showing the currently-active setpoints from the controller-side fields `climate.temp_max_active`, `climate.rh_max_active`, `climate.rh_min_active`. When `climate.rh_ctrl_enabled === false`, the RH setpoint line gets the `disabled` modifier and is dimmed to opacity 0.45 with em-dash placeholders (the controller omits the values per the API contract). `renderClimate()` updated accordingly.
- **Mode tile deduplication**: when `mode.current` is `WIND_OVERRIDE` / `WINDOW_CAL` / `MOTOR_ALARM` and the corresponding flag (`wind_override` / `calibrating` / `motor_alarm`) is also present in `mode.flags`, the duplicate flag badge is now suppressed — each state surfaces exactly once. New `MODE_FLAG_DUPE` lookup table; filter applied in the `renderMode()` flag loop.
- **Mock controller** (`mock/state.py`, `mock/pusher.py`, `mock/app.py`, `mock/templates/control.html`, `mock/README.md`):
  - `build_payload()` injects a live `system.uptime_s` computed from the mock process's start time (via `time.monotonic()`). Lets the operator watch the dashboard's new Uptime row tick across the `Ns` / `Nm Ns` / `Nh Nm` / `Nd Nh Nm` formatter buckets without restarting.
  - Climate defaults extended with `temp_max_active`, `rh_max_active`, `rh_min_active`, `rh_ctrl_enabled`. `build_payload()` omits `rh_min_active` / `rh_max_active` when `rh_ctrl_enabled` is `False`, matching the controller-side API contract.
  - Control panel gained four new climate widgets: setpoint editors for T-max / RH-min / RH-max and a one-click ON/OFF toggle for RH control — enough to exercise the dashboard's grayout end-to-end.
  - **Push target editable at runtime.** `pusher.TARGET` (module constant) refactored into `pusher.get_target()` / `pusher.set_target(url)`, both lock-protected. `MOCK_TARGET_BASE_URL` still bootstraps the initial value; a new *Push target* widget at the top of the control panel and the `POST /target` Flask route let the operator flip the destination on the fly (e.g. between a LAN test box and `https://pe1mew.nl/hbwv`) without restarting the Flask app. Validation: must start with `http://` or `https://`; trailing slashes are stripped. Reverts to the env value on next restart.

### Updated — design documentation
- **`design/apiSpecification.md`**: canonical example refreshed; section 6.3 `climate` extended with `temp_max_active`, `rh_max_active`, `rh_min_active`, `rh_ctrl_enabled` (and the omission rule when control is disabled); section 6.4 `wind` adds `direction_variation_deg`; section 6.6 `mode` adds `STANDBY` to the pill vocabulary and documents the duplicate-state suppression rule; section 6.8 `system` extended with `asset_version`, `uptime_s`, `ts_unix`, `time_iso`, `eg1`, and notes that `wifi_ip` is accepted but no longer rendered. Quick-reference card at the bottom of the file updated to match.
- **`design/functional-design.md`**: tile catalogue (§ 7) updated — Climate row mentions the setpoint sub-line and the RH dim/grey-out; Wind row notes the two-line layout; Mode row notes duplicate-state suppression; Sun row renamed to **Daytime** with a note on the heading-rename history (Sun → Daylight → Daytime; internal id `tile-sun` unchanged); System row rewritten to describe the WiFi bar + uptime layout. Appendix A status JSON sample and field table refreshed with every new key.

### Added — controller contract (`design/`)
- `design/apiSpecification.md` (v1.0, 2026-05-10) — the contract for the firmware engineer. Endpoint signatures, authentication procedure, full status JSON schema with field-by-field tables for every top-level object, log upload protocol, cadence and retry policy, wire transcripts (curl examples for each path), versioning policy, and a one-page quick-reference card. Self-contained — the firmware engineer doesn't need to read the functional/technical specs to implement against this.

### Added — security assessments (`design/`)
- `design/securityAssessment_LAN.md` — security posture of the system as deployed to the LAN test server. OWASP Web Top 10 (2021) and API Top 10 (2023) walk-through, threat model, attack surface inventory, findings by category, risk matrix, outstanding items, and pre-non-LAN-deploy checklist. Captures the actual evidence collected during Phase 10.
- `design/securityAssessment.md` — security posture for a public-internet deployment on personal-domain hosting (e.g. the same kind of environment that runs `pe1mew.nl`). Re-rates each finding for the public-exposure profile. Originally listed five blocking items; reduced to one by subsequent mitigations (see Verified — security hardening, below).

### Added — test reports (`test/`)
- `test/fd-requirements.md` — test results for FR-01 through FR-45. Status legend (PASS / DEFERRED / IN PROGRESS / NOT TESTED), per-row evidence linked to Phase 10 probes or to integration-time observations, anomalies and noteworthy findings, sign-off summary.
- `test/ts-requirements.md` — same shape for TR-01 through TR-42, plus an "Implements" column tracing each TR back to the FRs it satisfies.

### Verified — production deploy (pe1mew.nl/hbwv/)
- Site live over HTTPS with a Let's Encrypt certificate.
- All six security headers fire on every PHP-served response (`Strict-Transport-Security`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Content-Security-Policy: default-src 'self'; …`, `X-Robots-Tag: noindex, nofollow`).
- `Cache-Control: no-store` on `view.php` JSON responses.
- Wrong-secret POSTs return silent 204 and write a `[hbwv api] drop ip=… reason=… action=… http=…` line to the host's PHP error log.
- The driver pushes status every ~10 s; dashboard populates within one cycle.
- `https://pe1mew.nl/hbwv/.well-known/security.txt` returns the RFC 9116 abuse contact.

### Changed — security headers moved from `.htaccess` to PHP-inline
On the production host, `AllowOverride` is restricted to `AuthConfig Limit` (typical for shared hosting) — `Header always set`, `<FilesMatch>`, and `RewriteRule` directives in `.htaccess` are silently dropped. The site shipped a top-level `httproot/.htaccess` with the right hardening, but nothing applied. Switched the security-header layer to PHP `header()` calls **inlined into every entry point** (`index.php`, `api.php`, `view.php`, `log/index.php`, plus the two redirect shims), so the headers fire regardless of `AllowOverride`. The original `.htaccess` stays in the project as a no-op on this host but as defence-in-depth on hosts where `AllowOverride` is permissive — duplicated headers are harmless.

### Added — PHP-7.x compatibility
The production host runs PHP 7.x (Apache 2.4.38 on Debian 10). Two compatibility fixes:
- `array_is_list()` polyfill at the top of `httproot/api.php` — function-not-found on hosts running PHP < 8.1.
- Replaced arrow functions (`fn(...) =>`) with traditional anonymous functions in `httproot/view.php` and `httproot/log/index.php` — parse error on PHP < 7.4.

### Changed — footer wording
- Dashboard footer now reads `Greenhouse Controller • v<version>` (was `Greenhouse Controller Status • fw <version>`).
- Logs-page footer now reads `Greenhouse Controller • logs` (was `Greenhouse Controller Status • logs`).
- Schema field name (`system.fw_ver`) unchanged — only the rendered label changed.

### Changed — UI polish during Phase 9 close-out
- Removed stale CSS rules: `.big small` (averages were dropped in an earlier iteration) and the `.tile-logs` mobile rule (logs tile was moved off the dashboard to its own page).
- Footer GitHub link gained an explicit `min-height: 44px` tap target so it meets the mobile guideline without disturbing the visual position.
- Wind tile values use `white-space: nowrap` so `180°` and `m/s` aren't broken across lines at narrow widths.

### Added — security hardening (production-grade)
- **Audit logging on every silent-drop branch.** `gh_fail()` in `httproot/api.php` now writes one structured `error_log()` line per drop: `[hbwv api] drop ip=<addr> reason=<branch> action=<status|log> http=<code>` plus an optional `detail=` field (CR/LF stripped to prevent log injection). Verified on the LAN test server: 3 wrong-secret pushes produce 3 audit lines. Closes L-1 of the production-profile security assessment.
- **Site-wide security headers** in `httproot/.htaccess`. `Strict-Transport-Security` (HSTS, 1 year, includeSubDomains), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, full `Content-Security-Policy` (`default-src 'self'`, with `'unsafe-inline'` for the inline `GH_CFG` script and `log/index.php`'s style block), `X-Robots-Tag: noindex, nofollow` on every response, plus `Header unset X-Powered-By`. Closes C-2, M-5, M-6 of the production-profile security assessment.
- **Defensive HTTPS-force redirect** in `httproot/.htaccess` (the host already does this upstream, but the `RewriteRule` is there as defence in depth).
- **Direct config-file blocks** in `httproot/.htaccess` — `<FilesMatch "^config(_template)?\.php$">` and `<FilesMatch "^\.">` deny direct GETs. Defence in depth for ID-2 and ID-7; Apache's PHP processing already produces 0-byte responses for those files, but this stops them at the access-control layer.

### Added — PHP-version compatibility
- **`array_is_list()` polyfill** in `httproot/api.php` for hosts running PHP < 8.1.
- **Arrow functions replaced** with traditional anonymous functions in `httproot/view.php` and `httproot/log/index.php` so the code parses on PHP 7.4+. The dashboard now runs on the Debian 10 / PHP 7.x host that hosts pe1mew.nl as well as on PHP 8.x.

### Added — security mitigations
- **Per-IP rate limit** on the controller-write path. `gh_rate_limit()` in `httproot/api.php` enforces a token bucket keyed by `REMOTE_ADDR` (default 60 burst, 0.2 token/s refill ≈ 12 req/min sustained). State persists in `httproot/data/ratelimit.json` under `LOCK_EX`; idle entries (> 1 hour) pruned automatically. Configurable via the new `GH_RATE_LIMIT_BUCKET` and `GH_RATE_LIMIT_REFILL_PER_SEC` constants in `config.php` / `config_template.php`. Closes A-5 and D-3 of the production-profile security assessment.
- **Layered no-index policy** to keep the dashboard out of search engines:
  - `httproot/robots.txt` (`User-agent: * / Disallow: /`).
  - `<meta name="robots" content="noindex, nofollow">` in `httproot/index.php` and `httproot/log/index.php`.
  - `X-Robots-Tag: noindex, nofollow` HTTP header on `httproot/view.php`'s JSON responses.
  Closes M-8 of the production-profile security assessment.

### Changed — repository plumbing
- `.gitignore` extended to cover `httproot/data/ratelimit.json`.

### Verified — security hardening (production-profile)
- Rate limiter exercised with a 70-request burst from one IP against the deployed `api.php`: 60 requests passed the bucket and got auth-rejected, 10 were rate-limited. Bucket drained from 60 to ≈ 0 with the expected slow refill during the loop.
- All three no-index layers verified live: `curl /controller/robots.txt` returns the disallow rule; `curl /controller/` and `curl /controller/log/` show the meta tag in the HTML; `curl -D - /controller/view.php` returns the `X-Robots-Tag` header.

### Accepted — production-profile residual risks
- **Provider-side TLS termination** (C-5, H-1). The host's TLS terminator is on a different machine than the PHP-FPM pool, so the shared secret is plaintext on the host's internal hop. Operator accepts the host operator as a trust-3 entity for the deployment in question. To revisit: only if the hosting choice changes (a single-tenant VPS would eliminate this; a less-trusted provider would re-open it).

### Project documentation
- `README.md` rewritten to match the deployed-and-test-verified state (the previous version still described the project as "design phase, no code yet"). Now lists the actual repo structure, getting-started commands, and per-document purpose table.


### Added — implementation (`httproot/`)
- `httproot/index.php` — server-rendered dashboard shell with cache-busted asset URLs (`?v=<filemtime>`), inline `window.GH_CFG` (poll interval, default freshness interval, window names), seven tile containers (freshness always-on, climate / wind / windows / mode / sun / system hidden by default), and a footer carrying the firmware version and a GitHub link.
- `httproot/api.php` — controller-ingest endpoint. POST-only, `sourceidentifier`-header gated, atomic status write (`.tmp` + `rename`), log-upload action with size cap and silent retention sweep. Default mode returns HTTP 204 on every path; debug mode returns explicit `4xx` / `200` JSON.
- `httproot/view.php` — browser-read endpoint. GET-only, no auth, `Cache-Control: no-store`, attaches `age_seconds` to the read response, returns `{}` when no status has been received.
- `httproot/log/index.php` — separate, unlinked logs page that server-renders the list with explicit per-row Download buttons, the same dark theme, and the same footer as the dashboard. Reachable only at `/<prefix>/log/`.
- `httproot/log/logs/index.php` — directory-listing suppressor that 301-redirects `/<prefix>/log/logs/` to the logs page (workaround for hosts where `AllowOverride None` ignores `Options -Indexes`).
- `httproot/logs/index.php` — backward-compat 301 redirect from the original `/<prefix>/logs/` location to the new `/<prefix>/log/`.
- `httproot/assets/style.css` — dark theme variables (extended with `--blue-light`, `--green-dark`, `--grey-muted`), responsive grid (`auto-fit minmax(160px, 1fr)`), `[hidden] { display: none !important; }` to make the HTML `hidden` attribute win over class-based display rules, freshness bar styling, mode pill colour modifiers (`mode-ok`, `mode-warn`, `mode-alarm`, `mode-mute`), download-button styling, and a footer matching the reference webguiExample.
- `httproot/assets/app.js` — drift-resistant freshness tile (1 Hz redraw anchored against `performance.now()`), tile show/hide via predicate map, payload-derived strings written via `textContent` only, windows-tile renderer with state-to-colour and state-to-text-colour maps, mode pill colouring keyed off `mode.current`, wind cardinal direction (N, NE, E, …) derived from `direction_deg`.
- `httproot/config_template.php` — tracked template with first-time-setup banner explaining how to copy to `config.php` and rotate the secret.
- `httproot/{data,log/logs}/.htaccess` — deny-all and extension-whitelist rules for hosts where `AllowOverride` permits them.

### Added — mock controller (`mock/`)
- `mock/app.py`, `mock/state.py`, `mock/pusher.py` — Flask app + thread-safe sim state + background pusher. The pusher loads target URL, secret, and interval from `.deploy.env` at startup and refuses to start if `MOCK_TARGET_BASE_URL` is unset.
- `mock/templates/control.html` — control panel for toggling each top-level object, editing climate/wind values, choosing window states for M1/M2/M3, picking a mode, scheduling pushes, sending malformed JSON, sending a wrong secret, and uploading the sample log file.
- `mock/static/style.css` — dark theme borrowed from webguiExample.
- `mock/sample.log`, `mock/requirements.txt`, `mock/README.md`, `mock/__init__.py` — fixture log file, `flask>=3.0` + `requests>=2.31`, run instructions with scenario-to-FR mapping, package marker.

### Added — deploy tooling (`tools/`)
- `tools/deploy.ps1` — Windows PowerShell deploy script using OpenSSH `scp` and `ssh`. Reads `.deploy.env` for the SSH host alias and document root. Pre-flight check refuses to deploy if `httproot/config.php` is missing or still contains the `REPLACE_ME_BEFORE_DEPLOY` template marker. Pre-creates remote directories, runs `scp -r` (without `-p` to avoid Windows-source mode bits leaking), then normalises modes (`find … -type d -exec chmod 755`, files `0644`, then `chmod 2770` on `data/`, `log/`, and `log/logs/` so Apache's `www-data` can write).
- `tools/README.md` — first-time setup walkthrough, run instructions, permission notes, and "known limitations on this test server (deferred)" section documenting the `AllowOverride None` situation and the one-line fix when the operator is ready to enable it.

### Added — design (`design/`)
- `design/functional-design.md` (v0.2 draft, 2026-05-10) — externally-observable behaviour: system context, components, API operations, status JSON schema, dashboard polling and tile show/hide rules, the always-on freshness tile, the plan-view windows tile, mobile-first UI rules, security policy, error-handling policy, FR-01 through FR-45.
- `design/technical-spec.md` (v0.2 draft, 2026-05-10) — implementation brief: directory layout, configuration template + gitignored runtime split, `api.php` and `view.php` PHP, atomic write recipe, log retention sweep, frontend wiring, windows-tile SVG markup with current dimensions (160 × 30 / 18 / 18 — later updated to 172 × 34 / 22 / 22), Apache `.htaccess` blocks, verification plan, TR-01 through TR-42.
- `design/implementation-plan.md` (v0.1 draft, 2026-05-10) — twelve-phase plan with effort estimates, risks, definition of done, and a verification sign-off snapshot covering phases 0–10 against the test server.
- `design/apiSpecification.md` (v1.0, 2026-05-10) — controller-side contract: endpoint signatures, authentication, status JSON schema with field-by-field tables, log upload protocol, cadence and retry policy, wire transcripts (curl examples), versioning policy, and a one-page quick-reference card.

### Added — repository plumbing
- `.gitignore` — excludes `.deploy.env`, `.env`, virtual envs, OS artefacts, and runtime state (`httproot/data/status.json`, `httproot/log/logs/*.{log,txt}`, `httproot/config.php`).
- `.deploy.env.example` — tracked template with `DEPLOY_HOST_ALIAS`, `DEPLOY_DOC_ROOT`, `MOCK_TARGET_BASE_URL`, `MOCK_INTERVAL_S`, `MOCK_SECRET`.

### Added — documentation (`documentation/`)
- `documentation/webguiExample/` — reference web UI imported from the [greenhouse-Controller-Modbus-sensor-emulator](https://github.com/pe1mew/greenhouse-Controller-Modbus-sensor-emulator) project (`index.html`, `style.css`, `app.js`). Provides the dark-theme variables, `.card` styling, and live-fetch progress-bar pattern reused by the freshness tile.
- `documentation/phpAPIExample/api.php` — reference PHP authentication + cleanup pattern (shared-secret `sourceidentifier` header check, silent older-file pruning).

### Added — repository
- `LICENSE`, `license.md` — dual-license statement: source-available non-commercial for software, CC BY-NC-ND 4.0 for documentation and design.
- `README.md`, `contributing.md`, `code_of_conduct.md` — standard repository entry-point files.

### Changed — schema iterations during mobile QA
- **Removed** `climate.temp_avg_c`, `climate.rh_avg_pct`, `wind.speed_avg_ms`, `wind.direction_avg_deg` (sliding-average fields) — operator preferred the cleaner two-line tile without averages.
- **Removed** `system.time` (controller-reported clock) — moved out of the system tile entirely; firmware version moved to the page footer.
- **Renamed** `sun.sunrise_utc_min` → `sun.sunrise_min`, `sun.sunset_utc_min` → `sun.sunset_min` — the controller sends these in local clock minutes, not UTC, so the field names were misleading. The dashboard silently ignores the legacy `_utc_*` aliases.

### Changed — UI iterations during mobile QA
- Windows tile geometry rebalanced multiple times: outer rect now `x=2 y=2 w=196 h=136` (2-unit margins from the SVG viewBox edges); bars are all `width=172` with M3 `height=34` and M1/M2 `height=22`; bar gaps tuned so M3↔M2 gap equals M1↔outer-bottom gap; label `font-size` walked up from 5/6 to 7 to 8 to 10 with `font-weight="bold"` to match the OFFLINE pill; OPEN bars use black text on light blue for contrast.
- Wind tile dropped the `@` character between speed and direction and gained an 8-point cardinal label (N, NE, E, …, NW) appended after the degrees.
- Mode pill is now coloured by severity (`AUTOMATIC` blue, `WIND_OVERRIDE`/`WINDOW_CAL` amber, `MOTOR_ALARM` red, unknown muted) instead of always being accent-blue.
- Logs tile removed from the dashboard; logs surface only via the standalone `/log/` page (later moved from `/logs/` with a 301 redirect for back-compat).
- Footer added carrying the project name + firmware version + GitHub link, mirroring the reference webguiExample pattern.

### Verified — Phase 10 security pass (test server, 2026-05-10)
- `POST /api.php` with wrong `sourceidentifier` → 204 silent, status.json untouched, malicious payload rejected.
- `POST /api.php?action=log` with wrong header → 204 silent, no file stored.
- `GET /api.php` and `POST /view.php` → 204 silent (cross-method rejection).
- `app.js` code review: zero `innerHTML` writes from payload data, zero references to `sourceidentifier` or to the secret token literal.
- `GET /controller/config.php` → 200 with **0-byte** body (PHP processes pure `define()` to no output; secret never reaches the wire).
- XSS probe: `<img src=x onerror=alert(1)>` and `<script>alert(2)</script>` injected into payload string fields render as literal text on the dashboard, not as executable HTML.
- `GH_DEBUG_RESPONSES = false` confirmed in production config.

### Deferred — outstanding before non-test deployment
- Enable `AllowOverride All` on the Apache server so the existing `httproot/data/.htaccess` and `httproot/log/logs/.htaccess` rules take effect (closes FR-36, FR-37, FR-38).
- Move from HTTP to HTTPS so the `sourceidentifier` header is not sent in plaintext.
- Phase 12 — real-ESP32 integration. The [API specification](design/apiSpecification.md) is the contract.

---

## Earlier draft (superseded)

### v0.2 (design draft, 2026-05-10)
Functional design and technical specification first published. No implementation code at that point.
