# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

The repository is in design phase — no implementation code has been written yet.

### Added — design (`design/`)
- `design/functional-design.md` (v0.2 draft, 2026-05-10) — externally-observable behaviour, contracts, and rules for a mobile-first, passive viewer of one ESP32-S3 greenhouse controller. Covers system context, components (public dashboard, controller ingest API, browser read API, log download), API contract with silent-drop authentication, status JSON schema, dashboard polling and tile show/hide rules, the eight-tile catalogue (freshness, climate, wind, windows, mode, sun, system, logs), the always-on freshness heartbeat tile, the plan-view windows tile (M1/M2/M3 with North up), mobile-first UI rules, security policy, error-handling policy, and 45 testable functional requirements (FR-01 through FR-45).
- `design/technical-spec.md` (v0.2 draft, 2026-05-10) — implementation brief: directory layout, `config.php` constants, `api.php` controller-ingest endpoint with silent-drop, `view.php` browser read endpoint, log-download via Apache, atomic status-file writes via `.tmp` + `rename()`, retention sweep on upload-success, `index.php` shell with injected `window.GH_CFG`, `assets/style.css` dark theme, `assets/app.js` polling loop and tile show/hide, inline windows-tile SVG, drift-resistant freshness-tile age tracking, Apache `.htaccess` hardening, verification plan, and 42 testable implementation requirements (TR-01 through TR-42).

### Added — documentation (`documentation/`)
- `documentation/webguiExample/` — reference web UI imported from the [greenhouse-Controller-Modbus-sensor-emulator](https://github.com/pe1mew/greenhouse-Controller-Modbus-sensor-emulator) project (`index.html`, `style.css`, `app.js`). Provides the dark-theme variables, `.card` styling, and live-fetch progress-bar pattern reused by the freshness tile.
- `documentation/phpAPIExample/api.php` — reference PHP authentication + cleanup pattern (shared-secret `sourceidentifier` header check, silent older-file pruning) that `api.php` will follow.

### Added — repository
- `LICENSE`, `license.md` — dual-license statement: source-available non-commercial for software, CC BY-NC-ND 4.0 for documentation and design.
- `README.md`, `contributing.md`, `code_of_conduct.md` — standard repository entry-point files.
