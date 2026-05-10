# Mock greenhouse controller

Simulates the ESP32-S3 controller during development of the status website.
Pushes status JSON to the website's `api.php` on a configurable interval and
provides a tiny control panel for manual scenarios.

## Run

From the project root:

```powershell
# Windows / PowerShell
python -m venv .venv
.venv\Scripts\Activate.ps1
python -m pip install -r mock/requirements.txt
python -m flask --app mock.app run --port 5000
```

```bash
# macOS / Linux
python3 -m venv .venv
source .venv/bin/activate
python -m pip install -r mock/requirements.txt
python -m flask --app mock.app run --port 5000
```

Then visit <http://127.0.0.1:5000/>.

The `python -m flask ...` form is preferred over a bare `flask` command because it doesn't rely on the venv's `Scripts/` (or `bin/`) directory being on `PATH`. This is especially important on Windows with the Microsoft Store build of Python, which puts user-installed scripts in a directory that isn't always on `PATH`.

## Configuration

The mock reads `.deploy.env` at the project root on startup. Anything set
there is picked up automatically — no env var dance per launch.

| Key | Default | Purpose |
|---|---|---|
| `MOCK_TARGET_BASE_URL` | _(no default — exits with an error if unset)_ | Where to send pushes (root of the PHP site, e.g. `http://192.168.20.232/controller`). |
| `MOCK_SECRET` | `dev-1234567890abcdef-please-rotate-in-prod` | Value of the `sourceidentifier` header. **Must match `GH_SECRET_TOKEN`** in `httproot/config.php`. |
| `MOCK_INTERVAL_S` | `10` | Auto-push cadence. Also used as the default `update_interval_s` in payloads. |

Precedence: an actual environment variable always wins over `.deploy.env`,
so you can override one key per-run without editing the file:

```powershell
$env:MOCK_INTERVAL_S = "3"
python -m flask --app mock.app run --port 5000
```

If `.deploy.env` is missing or `MOCK_TARGET_BASE_URL` is empty, the mock exits
immediately with an instructive message rather than silently pushing into the void.

## Common scenarios

- **Steady state.** Leave the scheduler running. The dashboard shows live tiles, the freshness bar drains green.
- **Stale / offline.** Pause the scheduler; after `2 × interval` the freshness bar turns amber, after `4 × interval` it turns red and the rest of the dashboard dims.
- **Tile show / hide (FR-15).** Toggle a top-level object OFF; the corresponding tile hides on the website.
- **Window state colors (FR-30..33).** Change M1/M2/M3 dropdowns and click "Send one push now".
- **Silent-drop verification (FR-08).** Click "Send malformed JSON" or "Send wrong secret". With `GH_DEBUG_RESPONSES=false` the website returns 204 silently.
- **Debug-mode error responses (FR-09).** Flip `GH_DEBUG_RESPONSES=true` in `httproot/config.php` and repeat the previous step; responses now carry verbose JSON.
- **Log upload (FR-03).** Click "Upload sample log"; the file appears under `httproot/log/logs/` and in the Logs tile.

## Layout

```
mock/
├── __init__.py
├── app.py             # Flask routes
├── state.py           # Thread-safe in-memory sim state
├── pusher.py          # Background thread + HTTP push helpers
├── templates/
│   └── control.html
├── static/
│   └── style.css
├── sample.log         # Fixture for the "Upload sample log" button
├── requirements.txt
└── README.md
```
