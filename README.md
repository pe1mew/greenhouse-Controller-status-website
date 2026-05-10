# Greenhouse Controller Status Website

A mobile-first, passive web dashboard for one ESP32-S3 [Greenhouse Controller](https://github.com/pe1mew/greenhouse-Controller). The controller pushes its current status (sensors, vent positions, mode) and uploads its event log over an authenticated HTTP REST API. The website stores the latest payload, renders a tiled dashboard, and offers logs for download from a separate page. The browser is read-only with respect to the greenhouse — no commands flow back.

> **Status — implemented and test-deployed.** Version 0.3 (2026-05-10).
> Phases 0–8 of the [implementation plan](design/implementation-plan.md) are
> done; the dashboard is live on a LAN test server with a Flask mock
> controller driving it. Phase 10 security pass complete. AllowOverride
> hardening and HTTPS are deferred to non-test deployment.

## Features

- **One-controller dashboard** — exactly one ESP32-S3 greenhouse controller is assumed; latest status only, no historical charting.
- **Two physically separate API surfaces** — a secret-gated controller-ingest endpoint for writes (`api.php`), and a public read-only endpoint that the browser polls (`view.php`). Hardening policies on the read path do not affect the controller-write path.
- **Silent-drop authentication** — the ingest endpoint acknowledges malformed or unauthenticated requests with an empty success-shaped response (HTTP 204), so internet-wide probing learns nothing. A debug flag flips this behaviour for commissioning.
- **Presence-driven tiles** — the controller's payload directly controls what is on screen. A missing top-level object hides its tile; a missing key hides its line. There are no "N/A" placeholders.
- **Always-on freshness tile** — a horizontal countdown bar that drains over four times the controller's configured `update_interval_s`. Green ≤ 2× interval, amber ≤ 4× interval, red and "OFFLINE" beyond. When red, the rest of the dashboard dims; the freshness tile stays bright. Updates at least once per second between polls, anchored against server-reported age (no dependency on browser↔server clock sync).
- **Plan-view windows tile** — the greenhouse seen from above with North at the top. Three coloured bars (M1, M2, M3) at the same width, M3 visibly taller. Window display names are configured website-side; renaming a vent does not require a controller change.
- **Mode pill coloured by severity** — `MOTOR_ALARM` red, `WIND_OVERRIDE`/`WINDOW_CAL` amber, `AUTOMATIC` blue, unknown muted.
- **Wind direction with cardinal label** — the dashboard derives N/NE/E/…/NW from `direction_deg` automatically.
- **Mobile-first** — designed for a 360 × 800 portrait phone. Larger viewports flow into more columns automatically. Always dark themed.
- **Authenticated log uploads** — controller-pushed logs are stored under a server-generated `YYYY-MM-DD_HHMMSS.log` name and offered for download from a **separate, unlinked** page at `/log/`. Logs older than the retention window are pruned silently on each successful upload.
- **Resilience** — three consecutive failed read fetches surface a "Connection lost" banner; a successful fetch clears it.

## Tech stack

- **Backend**: PHP 8.1+ (no framework). Two thin entrypoints — `api.php` for the controller, `view.php` for the browser, plus `index.php` (dashboard) and `log/index.php` (logs page).
- **Frontend**: server-rendered HTML shell + vanilla JavaScript and CSS. No build step. Cache-busting via `?v=<filemtime>` on every asset.
- **Storage**: a single JSON file for the latest status (atomic `.tmp` + `rename()`); a flat directory of `.log` files. No database.
- **Hosting**: any standard PHP-enabled Apache host (LAMP/LEMP). HTTPS strongly recommended in production.
- **Mock controller**: Python 3.10+ Flask app for development without the ESP32 in the loop.
- **Deploy**: Windows PowerShell over OpenSSH `scp` — uses `~/.ssh/config` for host/key resolution, no credentials in the repo.

## Repository structure

```
greenhouse-Controller-status-website/
├── httproot/                       ← Apache document root points here
│   ├── index.php                   ← Dashboard shell
│   ├── api.php                     ← Controller ingest (POST writes)
│   ├── view.php                    ← Browser read feed (GET reads)
│   ├── config_template.php         ← Tracked template
│   ├── config.php                  ← Real config — gitignored
│   ├── assets/{style.css, app.js}
│   ├── data/                       ← Latest status.json + .htaccess
│   ├── log/
│   │   ├── index.php               ← Separate logs page
│   │   └── logs/                   ← Uploaded log files (.htaccess-hardened)
│   └── logs/                       ← 301-redirect to /log/ (back-compat)
├── mock/                           ← Flask mock controller (dev only)
├── tools/
│   ├── deploy.ps1                  ← SCP-based deploy script
│   └── README.md                   ← Deploy / first-time setup
├── design/
│   ├── functional-design.md        ← What the system does (FR-01..FR-45)
│   ├── technical-spec.md           ← How it is built (TR-01..TR-42)
│   ├── implementation-plan.md      ← Phase-by-phase plan + verification sign-off
│   └── apiSpecification.md         ← Controller-side contract
├── documentation/
│   ├── webguiExample/              ← Reference web UI (theme + progress bar)
│   └── phpAPIExample/              ← Reference PHP auth pattern
├── .deploy.env                     ← Deploy host config — gitignored
├── .deploy.env.example             ← Template
├── .gitignore
└── README.md, LICENSE, …
```

## Getting started

### 1. Clone and configure

```powershell
git clone https://github.com/pe1mew/-greenhouse-Controller-status-website.git
cd -greenhouse-Controller-status-website

# 1a. Create the active config from the template, generate a real secret:
Copy-Item httproot\config_template.php httproot\config.php
$tok = -join ((48..57+65..90+97..122) | Get-Random -Count 32 | %{[char]$_})
(Get-Content httproot\config.php) -replace 'REPLACE_ME_BEFORE_DEPLOY', $tok | Set-Content httproot\config.php

# 1b. Copy the deploy env template and edit:
Copy-Item .deploy.env.example .deploy.env
# Then edit .deploy.env to point at your deploy host alias and document root.
# Set MOCK_SECRET to the same value you put in config.php.
```

Both `httproot/config.php` and `.deploy.env` are gitignored — the secret never enters the repo.

### 2. Deploy

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\deploy.ps1
```

The script does an SCP upload to the host alias in `.deploy.env`, normalises file modes (`0755` on dirs, `0644` on files, `2770` on `data/` and `log/logs/` so Apache's `www-data` can write), and refuses to deploy if `config.php` is missing or still has the placeholder secret.

See [tools/README.md](tools/README.md) for full deploy details and known limitations on hosts where `AllowOverride None` is set.

### 3. Drive the dashboard with the Flask mock

Without an ESP32 in the loop, run the mock to push status:

```powershell
python -m venv .venv
.venv\Scripts\Activate.ps1
python -m pip install -r mock/requirements.txt
python -m flask --app mock.app run --port 5000
```

The mock auto-loads target URL and secret from `.deploy.env`, pushes every 10 s by default, and offers a control panel at <http://127.0.0.1:5000/> for triggering scenarios (toggle top-level objects, change window states, send malformed JSON, send wrong secret, upload sample log).

See [mock/README.md](mock/README.md) for scenarios that map back to specific FR/TR identifiers in the design.

### 4. Real ESP32 integration

When you're ready to swap the mock for the real controller, see [design/apiSpecification.md](design/apiSpecification.md) — that document is self-contained and describes every byte the controller has to send.

## Documentation

| Document | Purpose |
|---|---|
| [design/functional-design.md](design/functional-design.md) | What the system does. Tile catalogue, freshness/windows tile spec, security policy, FR-01 through FR-45. Stakeholder-readable. |
| [design/technical-spec.md](design/technical-spec.md) | How it is built. Directory layout, endpoint code paths, storage model, frontend wiring, Apache hardening, TR-01 through TR-42. |
| [design/implementation-plan.md](design/implementation-plan.md) | Twelve-phase plan with effort estimates, risks, and a verification sign-off snapshot from the test-server walk-through. |
| [design/apiSpecification.md](design/apiSpecification.md) | The contract for the firmware engineer — endpoint signatures, JSON schema, retry policy, wire transcripts. |
| [tools/README.md](tools/README.md) | Deploy, first-time setup, permission normalisation. |
| [mock/README.md](mock/README.md) | Run the Flask mock controller; scenario-to-FR cross-reference. |
| [documentation/webguiExample/](documentation/webguiExample/) | Reference HTML/CSS/JS for theme variables and the live-fetch progress-bar pattern reused by the freshness tile. |
| [documentation/phpAPIExample/api.php](documentation/phpAPIExample/api.php) | Reference `sourceidentifier`-header authentication and silent-cleanup pattern. |
| [changelog.md](changelog.md) | Project history by date. |
| [contributing.md](contributing.md) | How to contribute. |
| [code_of_conduct.md](code_of_conduct.md) | Expected and unacceptable contributor behaviour. |

## License

See [license.md](license.md) for full details.

**Software** (PHP, JavaScript, CSS, all code in this repository): Source-available, non-commercial. Free to use and modify for personal/non-commercial purposes; redistribution and commercial use are not permitted.

**Documentation and design files**: Licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International License.

<a rel="license" href="https://creativecommons.org/licenses/by-nc-nd/4.0/"><img alt="Creative Commons License" style="border-width:0" src="https://i.creativecommons.org/l/by-nc-nd/4.0/88x31.png" /></a>

## Disclaimer

This project is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
