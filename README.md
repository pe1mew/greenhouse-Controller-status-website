# Greenhouse Controller Status Website

A mobile-first, passive web dashboard for one ESP32-S3 [Greenhouse Controller](https://github.com/pe1mew/greenhouse-Controller). The controller pushes its current status (sensors, vent positions, mode) and uploads its event log over an authenticated HTTP REST API. The website stores the latest payload, renders a tiled dashboard, and lists log files for download. The browser is read-only with respect to the greenhouse — no commands flow back.

> **Status — design draft.** Version 0.2 (2026-05-10). The functional design and technical specification are complete and under review; no PHP code has been written yet. See [design/](design/) for the full specifications and [changelog.md](changelog.md) for the latest state.

## Features

Drawn from the [functional design](design/functional-design.md):

- **One-controller dashboard** — exactly one ESP32-S3 greenhouse controller is assumed; latest status only, no historical charting.
- **Two physically separate API surfaces** — a secret-gated controller-ingest endpoint for writes, and a public read-only endpoint that the browser polls. Hardening policies on the read path do not affect the controller-write path.
- **Silent-drop authentication** — the ingest endpoint acknowledges malformed or unauthenticated requests with an empty success-shaped response, so internet-wide probing learns nothing. A debug flag flips this behaviour for commissioning.
- **Presence-driven tiles** — the controller's payload directly controls what is on screen. A missing top-level object hides its tile; a missing key hides its line. There are no "N/A" placeholders.
- **Always-on freshness tile** — a horizontal countdown bar that drains over four times the controller's configured update interval. Green ≤ 2× interval, amber ≤ 4× interval, red and "OFFLINE" beyond. When red, the rest of the dashboard dims; the freshness tile stays bright. Updates at least once per second between polls, anchored against server-reported age (no dependency on browser↔server clock sync).
- **Plan-view windows tile** — the greenhouse seen from above with North at the top. Three coloured bars: M3 (north wall, large), M2 (north roof, small), M1 (south roof, small). Window display names are configured website-side; renaming a vent does not require a controller change.
- **Mobile-first** — designed for a 360 × 800 portrait phone. Larger viewports flow into more columns automatically. Always dark themed.
- **Authenticated log uploads** — controller-pushed logs are stored under a server-generated `YYYY-MM-DD_HHMMSS.log` name and offered for download from a directory hardened to a strict filename whitelist. Logs older than the retention window are pruned silently on each successful upload.
- **Resilience** — three consecutive failed read fetches surface a "Connection lost" banner; a successful fetch clears it.

## Tech stack

- **Backend**: PHP (no framework). Two thin entrypoints — `api.php` for the controller, `view.php` for the browser.
- **Frontend**: server-rendered HTML shell (`index.php`) plus vanilla JavaScript and CSS. No build step.
- **Storage**: a single JSON file for the latest status (atomic `.tmp` + `rename()`); a flat directory of `.log` files for downloads. No database.
- **Hosting**: any standard PHP-enabled Apache host (LAMP/LEMP). HTTPS required in production.

## Repository structure

Today (design phase, no code yet):

```
greenhouse-Controller-status-website/
├── design/
│   ├── functional-design.md     ← What the system does (v0.2 draft)
│   └── technical-spec.md        ← How it is built (v0.2 draft)
├── documentation/
│   ├── webguiExample/           ← Reference web UI (dark-theme variables, .card styling, live-fetch progress bar)
│   └── phpAPIExample/           ← Reference PHP auth + cleanup pattern
├── README.md
├── LICENSE
├── license.md
├── changelog.md
├── contributing.md
└── code_of_conduct.md
```

Planned per [technical-spec.md § 1](design/technical-spec.md#1-directory-layout):

```
├── index.php                    ← Public HTML shell
├── api.php                      ← Controller ingest (POST writes, secret-gated)
├── view.php                     ← Browser read feed (GET reads, public)
├── config.php                   ← Constants only
├── assets/
│   ├── style.css
│   └── app.js
├── data/
│   ├── .htaccess                ← Require all denied
│   └── status.json              ← Latest payload + server-added received_at
└── log/
    └── logs/
        ├── .htaccess            ← Filename whitelist, no listing
        └── YYYY-MM-DD_HHMMSS.log
```

## Getting started

There is no runnable code yet. To follow along with the design or contribute to it:

```
git clone https://github.com/pe1mew/greenhouse-Controller-status-website.git
cd greenhouse-Controller-status-website
```

Then read:

1. [design/functional-design.md](design/functional-design.md) — what the system does, with 45 testable functional requirements.
2. [design/technical-spec.md](design/technical-spec.md) — how it will be built, with 42 testable implementation requirements traced back to the functional ones.
3. [documentation/webguiExample/webGuiExample.md](documentation/webguiExample/webGuiExample.md) — pointer to the reference web UI imported from the [Modbus sensor emulator](https://github.com/pe1mew/greenhouse-Controller-Modbus-sensor-emulator).
4. [documentation/phpAPIExample/api.php](documentation/phpAPIExample/api.php) — the reference PHP authentication and cleanup pattern.

### Future deployment recipe

Once `api.php`, `view.php`, `index.php`, `config.php`, and the `assets/` directory exist, deployment will be:

1. Copy the project to the document root of a PHP-enabled Apache host.
2. Set `GH_SECRET_TOKEN` in `config.php` to a long random string. Set the same value on the controller side.
3. Ensure `data/` is writable by the web-server user.
4. Ensure `log/logs/` is writable by the web-server user.
5. Verify HTTPS is enforced (the shared secret is plaintext-equivalent on the wire).
6. Open the dashboard at `https://your-host/`.

## Documentation

| Document | Purpose |
|---|---|
| [design/functional-design.md](design/functional-design.md) | Externally-observable behaviour, API contract, tile catalogue, freshness tile spec, windows tile spec, security policy, FR-01 … FR-45. |
| [design/technical-spec.md](design/technical-spec.md) | Directory layout, `config.php`, `api.php`, `view.php`, storage model, frontend wiring, Apache hardening, verification plan, TR-01 … TR-42. |
| [documentation/webguiExample/](documentation/webguiExample/) | Reference HTML/CSS/JS for theme variables and the live-fetch progress bar pattern reused by the freshness tile. |
| [documentation/phpAPIExample/api.php](documentation/phpAPIExample/api.php) | Reference `sourceidentifier`-header authentication and silent-cleanup pattern. |
| [changelog.md](changelog.md) | Project state by date. |
| [contributing.md](contributing.md) | How to contribute. |
| [code_of_conduct.md](code_of_conduct.md) | Expected and unacceptable contributor behaviour. |

## License

See [license.md](license.md) for full details.

**Software** (PHP, JavaScript, CSS, all code in this repository): Source-available, non-commercial. Free to use and modify for personal/non-commercial purposes; redistribution and commercial use are not permitted.

**Documentation and design files**: Licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International License.

<a rel="license" href="https://creativecommons.org/licenses/by-nc-nd/4.0/"><img alt="Creative Commons License" style="border-width:0" src="https://i.creativecommons.org/l/by-nc-nd/4.0/88x31.png" /></a>

## Disclaimer

This project is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
