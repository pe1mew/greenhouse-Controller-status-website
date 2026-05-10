# Greenhouse Controller — Status Website API Specification

| | |
|---|---|
| Document | API Specification |
| Audience | Implementer of the **controller-side** task that pushes to the website |
| Companions | [functional-design.md](functional-design.md) — *what* the dashboard shows<br>[technical-spec.md](technical-spec.md) — *how* the website is built |
| Version | 1.0 |
| Date | 2026-05-10 |

This is the contract between the greenhouse controller (ESP32-S3 firmware) and the status website. It describes every byte the controller sends and every response code it can expect. Once this contract is implemented on the controller, the website can be developed and deployed independently and the two will interoperate as long as the contract is honoured.

The controller pushes; it never reads. The website is read-only with respect to the greenhouse — no commands flow back over this API.

## Table of contents

1. [Overview](#1-overview)
2. [Authentication](#2-authentication)
3. [Endpoint summary](#3-endpoint-summary)
4. [`POST /api.php` — push status](#4-post-apiphp--push-status)
5. [`POST /api.php?action=log` — upload log](#5-post-apiphpactionlog--upload-log)
6. [Status JSON schema](#6-status-json-schema)
7. [Cadence and retry policy](#7-cadence-and-retry-policy)
8. [Operational recommendations](#8-operational-recommendations)
9. [Wire transcripts](#9-wire-transcripts)
10. [Versioning and forward compatibility](#10-versioning-and-forward-compatibility)

---

## 1. Overview

The status website exposes two write endpoints and zero read endpoints to the controller. Both are HTTP `POST`. The controller calls them; the website never initiates a connection.

```
                       ┌────────────────────┐
                       │   Status website   │
                       │     (httproot/)    │
                       └──────┬─────────────┘
        controller pushes →   │ api.php (this doc)
        browser reads only    │ view.php (out of scope here)
                              │
                              ▼
                       data/status.json
                       log/logs/<dated>.log
```

**Things the controller does** (governed by this document):
- Periodically `POST` a status JSON object describing the greenhouse state.
- Occasionally `POST` an event-log file.

**Things the controller MUST NOT do**:
- Read `view.php` or the dashboard's own data. Anything you want to know about the website's current opinion is irrelevant — the controller is the source of truth.
- Send commands. The website has no notion of writing back.

---

## 2. Authentication

A single shared secret carried in an HTTP header on every controller request.

| | |
|---|---|
| Header name | `sourceidentifier` |
| Header value | `<GH_SECRET_TOKEN>` — see "Configuration" below |
| Algorithm | Plain string equality. No HMAC, no nonce, no replay protection. |

### 2.1 Configuration

Both ends of the connection must agree on the secret.

- **Server side**: defined in `httproot/config.php` as the constant `GH_SECRET_TOKEN`. The file is gitignored. The deploy script refuses to ship a placeholder value.
- **Controller side**: compiled into the firmware, or stored in NVS / config flash. Treated as a credential — never logged in plaintext, never echoed in serial output.

### 2.2 Rotation

There is no on-the-wire rotation mechanism. To rotate:

1. Update `GH_SECRET_TOKEN` in `httproot/config.php`. Redeploy.
2. Update the controller's compiled / configured value. Reflash or update NVS.
3. Both must be live before the next push, or pushes will be silently dropped (HTTP 204) until they re-sync.

The order matters: bring the new server up first; the controller's old pushes will be dropped briefly. Then bring the controller up. Worst-case, one or two pushes are lost during the transition.

### 2.3 What happens on auth failure

Default mode (`GH_DEBUG_RESPONSES = false`): the server returns **HTTP 204 No Content** with an empty body. The response is **indistinguishable from a successful push**. This is the silent-drop behaviour and it is intentional — it stops a rogue scanner on the network from learning whether their guess was right.

This means the controller must treat a 204 as "the request was accepted; the server is not telling me anything more." A 204 does **not** prove the payload was stored; if the controller sent the wrong secret or a malformed body, the result is also 204.

For commissioning, the operator can flip `GH_DEBUG_RESPONSES = true` on the server temporarily, after which the server returns explicit `401`/`400`/`413` JSON errors. Switch back to `false` before exposing the site to anything beyond the LAN.

---

## 3. Endpoint summary

The controller-callable surface is two paths on one PHP file:

| Verb | Path | Purpose | Auth | Body |
|---|---|---|---|---|
| `POST` | `/api.php` | Replace the latest stored status with a fresh one | required | JSON status object |
| `POST` | `/api.php?action=log` | Upload an event-log file | required | raw bytes (`text/plain`) |
| `GET` | `/api.php` | — | rejected (`405` / silent `204`) | — |
| `POST` | `/view.php` | — | rejected (`405` / silent `204`) | — |

The `<base>` URL the controller targets depends on the deployment. Examples:

| Deployment | Base URL |
|---|---|
| Apache vhost dedicated to the website | `https://greenhouse.example.com` |
| Subdirectory deploy (this test server) | `http://192.168.20.232/controller` |
| Local dev | `http://localhost` |

Throughout this document the placeholder `<base>` stands for whichever you configured.

---

## 4. `POST /api.php` — push status

### 4.1 Request

```
POST <base>/api.php HTTP/1.1
Host:               <host>
sourceidentifier:   <GH_SECRET_TOKEN>
Content-Type:       application/json
Content-Length:     <N>

{ … status JSON object … }
```

- **Method**: `POST`. Anything else returns `405` in debug mode, silent `204` in default mode.
- **Header `sourceidentifier`**: required, exact-match against `GH_SECRET_TOKEN`. Mismatch → silent `204`.
- **Header `Content-Type`**: `application/json` is the canonical value. The server uses `php://input` and `json_decode`, so it does not strictly require this header, but you should still send it.
- **Body**: a single JSON object. Top-level arrays, scalars, or `null` are rejected.
- **Body size**: no explicit cap on this endpoint, but PHP's `post_max_size` (typically 8 MB) is the upper bound. Real payloads are < 1 KB.

### 4.2 Response

| Condition | Default mode | Debug mode (`GH_DEBUG_RESPONSES = true`) |
|---|---|---|
| Method ≠ POST | `204` empty | `405 {"error":"method_not_allowed"}` |
| Wrong / missing `sourceidentifier` | `204` empty | `401 {"error":"unauthorized"}` |
| Body is not valid JSON | `204` empty | `400 {"error":"bad_json","detail":"…"}` |
| Body is not a JSON object (top-level array, etc.) | `204` empty | `400 {"error":"bad_json","detail":"payload must be a JSON object"}` |
| Successful write | `204` empty | `200 {"ok":true,"received_at":<unix-ts>}` |

**Always**: TCP connection succeeds, HTTP request gets a response within ≈ 1 second on a healthy LAN. If you see no response at all, the network is the problem — not the API.

### 4.3 Server-side side effects

On a successful, authenticated, well-formed push:

1. Server attaches `received_at` (Unix epoch seconds, server time) to the payload.
2. Server writes the result atomically to `httproot/data/status.json` (`.tmp` + `rename`).
3. The next browser GET reflects the new payload within `view.php`'s next return.

The controller does **not** see `received_at` echoed back in the default-mode response.

### 4.4 Idempotency

Each `POST` replaces the stored status entirely. There is no merge, append, or partial update. If you send `{"climate":{"temp_c":24.5}}`, the previous payload's `wind`, `windows`, etc. **disappear** from the dashboard until you send them again.

This is by design — it lets the controller turn parts of the dashboard off by simply omitting their top-level object.

---

## 5. `POST /api.php?action=log` — upload log

### 5.1 Request

```
POST <base>/api.php?action=log HTTP/1.1
Host:               <host>
sourceidentifier:   <GH_SECRET_TOKEN>
Content-Type:       text/plain
Content-Length:     <N>

<raw log file bytes — NDJSON, CSV, plain text, whatever the controller produces>
```

- **Body**: raw bytes. **Not** multipart, **not** base64. The server reads `php://input` directly and writes it to disk as-is.
- **Body size**: hard cap `GH_LOG_MAX_BYTES` = `5 * 1024 * 1024` (5 MiB). The server checks `Content-Length` **before** reading the body and rejects without buffering if oversized.
- **Filename**: the server generates the filename. **Whatever the controller suggests is ignored.** Use `Content-Disposition: filename=…` if you want, it doesn't matter.

### 5.2 Server-generated filename

`YYYY-MM-DD_HHMMSS.log` from server time at the moment of the write. Examples:

```
2026-05-10_143022.log
2026-05-11_080015.log
```

Strict whitelist on the download side limits filenames to `^[0-9A-Za-z._-]+\.(log|txt)$`. The server-generated names always match.

### 5.3 Response

| Condition | Default mode | Debug mode |
|---|---|---|
| Method ≠ POST | `204` | `405 {"error":"method_not_allowed"}` |
| Wrong / missing secret | `204` | `401 {"error":"unauthorized"}` |
| `Content-Length` ≤ 0 or > 5 MiB | `204` | `413 {"error":"too_large_or_empty","bytes":<n>}` |
| Successful upload | `204` | `200 {"ok":true,"name":"<filename>","bytes":<n>}` |

### 5.4 Retention

After every successful upload the server scans `log/logs/` and silently deletes any file whose `mtime` is older than `GH_LOG_RETENTION_DAYS` (default 90).

The retention sweep is the **only** thing the server does proactively. The controller does not need to delete or list files. If the website is offline for a month and the controller pushes a single log when it recovers, the server prunes back to ≤ 90 days at that moment.

### 5.5 Recommended cadence

One upload per 24 hours, scheduled to run during the controller's daily quiet period (e.g. early morning over AP). At one upload per day with 90-day retention, steady-state file count is ≈ 90.

Multiple uploads per day are accepted — there is no rate limit — but each one triggers a retention sweep which scans the directory. Don't spam.

---

## 6. Status JSON schema

The controller decides which top-level objects to include. **A missing top-level object hides its dashboard tile.** A missing key inside a present object hides only that line. There are no "N/A" placeholders. This direct presence-to-display mapping is the website's only mechanism for selectively showing things — the controller is the only voice.

### 6.1 Canonical example

```json
{
  "type": "status",
  "update_interval_s": 30,

  "climate": {
    "temp_c":   24.5,
    "rh_pct":   72
  },
  "wind": {
    "speed_ms":      3.5,
    "direction_deg": 180
  },
  "windows": {
    "M1": "OPEN",
    "M2": "MOVING_OPEN",
    "M3": "CLOSED"
  },
  "mode": {
    "current": "AUTOMATIC",
    "flags":   ["wind_override", "calibrating"]
  },
  "sun": {
    "is_daytime":   true,
    "sunrise_min":  360,
    "sunset_min":   1260
  },
  "system": {
    "ntp_synced":    true,
    "wifi_ip":       "192.168.1.100",
    "wifi_rssi_dbm": -45,
    "fw_ver":        "1.17.0"
  }
}
```

### 6.2 Top-level fields

| Field | Type | Required | Description |
|---|---|---|---|
| `type` | string | optional, by convention `"status"` | Marker for future schema versioning. Currently ignored by the website. |
| `update_interval_s` | integer | **REQUIRED** | The cadence at which the controller intends to push status. The dashboard uses this directly to colour the freshness bar (green ≤ 2×, amber ≤ 4×, red beyond). If you push every 30 s, send 30. If you omit this field, the dashboard falls back to a configured default and labels the value `(assumed)` to flag the missing data. |

### 6.3 `climate` (optional)

Tile shows iff this object is present.

| Field | Type | Unit | Range / format | Required if `climate` present? |
|---|---|---|---|---|
| `temp_c` | number | °C | typical -20 to +60 | optional — line hides if absent |
| `rh_pct` | integer | % | 0 – 100 | optional — line hides if absent |

Either field alone is enough to populate the climate tile. If both are absent and the `climate` object is empty `{}`, the tile renders empty (the title shows but no readings).

### 6.4 `wind` (optional)

Tile shows iff this object is present.

| Field | Type | Unit | Range / format | Required if `wind` present? |
|---|---|---|---|---|
| `speed_ms` | number | m/s | ≥ 0, typical 0 – 50 | optional |
| `direction_deg` | integer | ° | 0 – 359 | optional |

The dashboard derives the cardinal label (N, NE, E, …) from `direction_deg` automatically. The controller does not send the cardinal — only the degrees.

### 6.5 `windows` (optional)

Tile shows iff this object is present.

| Field | Type | Required if `windows` present? |
|---|---|---|
| `M1` | enum string | optional — bar shows UNKNOWN if absent |
| `M2` | enum string | optional — bar shows UNKNOWN if absent |
| `M3` | enum string | optional — bar shows UNKNOWN if absent |

**State vocabulary** — the controller MUST send exactly one of these strings (or omit the key, which is treated as UNKNOWN):

| State | Meaning | Bar colour on dashboard |
|---|---|---|
| `OPEN` | Travel timer expired in the open direction | light blue, black text |
| `MOVING_OPEN` | Relay energised in the open direction | amber |
| `MOVING_CLOSE` | Relay energised in the close direction | amber |
| `CLOSED` | At the close end-switch | dark green |
| `UNKNOWN` | Position not yet established (before CLOSE_ALL calibration) | muted grey |

Any other string is treated as UNKNOWN by the dashboard, but you should not rely on that — send canonical values.

### 6.6 `mode` (optional)

Tile shows iff this object is present.

| Field | Type | Required? |
|---|---|---|
| `current` | enum string | optional — pill shows empty if absent |
| `flags` | array of strings | optional — no badges if absent or empty |

**`mode.current` vocabulary**:

| Value | Pill colour |
|---|---|
| `AUTOMATIC` | accent blue (normal operation) |
| `WIND_OVERRIDE` | amber (wind safety active) |
| `WINDOW_CAL` | amber (calibration in progress) |
| `MOTOR_ALARM` | red (emergency stop active) |
| anything else | muted grey |

**`mode.flags` vocabulary** — the controller decodes its internal `EG1` bitmask before sending. Each set bit becomes one string in the array. Empty array (or omitted field) → no flag badges.

| Flag | Meaning | Severity |
|---|---|---|
| `wind_override` | Wind safety has forced all windows closed | caution (amber badge) |
| `calibrating` | Window calibration in progress | caution (amber badge) |
| `sensor_fault_temp` | Temperature sensor read error | alarm (red badge) |
| `sensor_fault_rh` | Humidity sensor read error | alarm (red badge) |
| `sensor_fault_wind` | Wind sensor read error | alarm (red badge) |
| `motor_alarm` | RRK-3 emergency stop active | alarm (red badge) |
| `ota_in_progress` | Firmware update underway | informational (light-blue badge) |

Unknown flag strings render as muted-grey badges. Don't send them — keep the vocabulary aligned.

### 6.7 `sun` (optional)

Tile shows iff this object is present.

| Field | Type | Unit | Notes |
|---|---|---|---|
| `is_daytime` | boolean | — | `true` between sunrise and sunset; flips the icon between ☀ and ☾. |
| `sunrise_min` | integer | minutes from local midnight | e.g. 360 = 06:00 local. |
| `sunset_min` | integer | minutes from local midnight | e.g. 1260 = 21:00 local. |

These are **local clock minutes**, not UTC. The controller's TZ is the authoritative reference; the website displays the values verbatim. (Earlier drafts of the schema named these `sunrise_utc_min` / `sunset_utc_min`. They have been renamed to drop the misleading `_utc_` infix. If the controller still sends the old names, the dashboard will silently ignore them — no harm, but the times won't show.)

### 6.8 `system` (optional)

Tile shows iff this object is present.

| Field | Type | Unit | Notes |
|---|---|---|---|
| `ntp_synced` | boolean | — | shown as "NTP ok" / "NTP pending" |
| `wifi_ip` | string | dotted decimal IPv4 | e.g. `"192.168.1.100"` |
| `wifi_rssi_dbm` | integer | dBm | typically negative |
| `fw_ver` | string | semver-ish | shown in the dashboard footer (not in the system tile itself) |

`fw_ver` is rendered in the page footer rather than the system tile. The controller still sends it in `system.fw_ver`.

### 6.9 Server-added fields — DO NOT SEND

The website appends `received_at` (Unix epoch seconds) before storing, and `age_seconds` to read responses. Both are computed server-side. **Do not send them from the controller** — even if you do, they will be overwritten.

### 6.10 Field omission rules

The controller is encouraged to **omit** any field or any top-level object whose value is unknown or temporarily unreliable. Do not send placeholder values like `null`, `-1`, `""`, or `0` to indicate "no data" — they will be displayed literally.

For example, if the wind sensor is faulted:

- Set `mode.flags` to include `"sensor_fault_wind"` (so the user sees an alarm badge).
- **Omit** the `wind` top-level object entirely. The wind tile hides, the user understands at a glance that wind data is unavailable, and the alarm badge explains why.

---

## 7. Cadence and retry policy

### 7.1 Push cadence

Pick a cadence that matches the data's freshness requirements and the controller's network duty cycle. Typical: every 10 – 60 seconds.

The cadence you choose must equal `update_interval_s` in the payload. The dashboard uses that exact value to compute its freshness thresholds. Pushing every 30 s while sending `update_interval_s: 10` will leave the dashboard amber/red continuously; pushing every 5 s while sending `update_interval_s: 30` is wasted bandwidth.

If the cadence is variable (e.g. event-driven), set `update_interval_s` to the **expected maximum gap** between consecutive pushes during normal operation.

### 7.2 Retry policy on push failures

The controller cannot tell from a 204 whether the push succeeded or was silently dropped. Treat any HTTP response (any status code) as "the network is up; trust the website's silent-drop semantics." Do **not** retry on receiving a 204, 200, 4xx, or 5xx — just wait until the next scheduled push.

The cases where the controller **should** retry:

- **DNS failure / connection refused / TCP RST**: the website is not reachable. Retry with exponential back-off, capped at e.g. 60 s. Skip pushes during back-off; do not queue them.
- **TLS handshake failure**: same as above.
- **Read timeout**: same as above.

Do **not** queue or batch payloads. The dashboard wants the *current* state, not a backlog. If the website is unreachable for an hour, the controller should resume pushing fresh state when connectivity returns and let the website's `received_at` reflect that — there is no value in the controller replaying stale snapshots.

### 7.3 Log upload retry policy

Same rules as status push, with one addition: the controller may retain the log content locally for a bounded window (e.g. one daily file) and retry **once** on the next scheduled upload window if the previous attempt failed at the network level. Don't retry across days.

### 7.4 Connection reuse

Pushes are infrequent enough that connection reuse buys little. Open a fresh TCP/TLS connection per push. Set:

- Connect timeout: 5 s
- Total transaction timeout: 10 s

---

## 8. Operational recommendations

- **Use HTTPS in production.** The shared secret is plaintext on the wire; on plain HTTP, anyone on the LAN can read it from `tcpdump` / browser dev tools / etc.
- **Never log the secret.** Not in serial output, not in OTA logs, not in MQTT, not in NVS dumps.
- **NTP-sync the controller before pushing.** The controller's wall-clock is its only authoritative time source for `system.fw_ver` build dates, log filename hints, etc. The server timestamps the payload with its own clock (`received_at`), so a bad controller clock won't break the dashboard, but the firmware logs and event-log files will be hard to correlate.
- **Don't send debug-style payloads to the production website** even temporarily. There is no DEBUG mode for the controller side; everything you push lands in `data/status.json` and is publicly readable on the dashboard.
- **Match the schema field names exactly.** The dashboard reads keys by name. `temp_C`, `temperature`, `tempC` etc. are all silently ignored — the tile will hide.

---

## 9. Wire transcripts

Concrete examples for testing the controller-side implementation against `curl` first, then plumbing the same bytes through your HTTP client.

### 9.1 Successful status push (default mode)

```
$ curl -i -X POST \
       -H 'sourceidentifier: <SECRET>' \
       -H 'Content-Type: application/json' \
       --data '{"type":"status","update_interval_s":30,"climate":{"temp_c":24.5,"rh_pct":72}}' \
       http://192.168.20.232/controller/api.php

HTTP/1.1 204 No Content
Date: Sun, 10 May 2026 14:30:22 GMT
Server: Apache/2.4.58 (Ubuntu)
```

The dashboard at `http://192.168.20.232/controller/` will display the climate tile within one polling cycle (≤ 5 s).

### 9.2 Same push in debug mode

```
$ curl -i -X POST \
       -H 'sourceidentifier: <SECRET>' \
       -H 'Content-Type: application/json' \
       --data '{"type":"status","update_interval_s":30,"climate":{"temp_c":24.5,"rh_pct":72}}' \
       http://192.168.20.232/controller/api.php

HTTP/1.1 200 OK
Content-Type: application/json
Content-Length: 41

{"ok":true,"received_at":1747066222}
```

### 9.3 Wrong secret (default mode)

```
$ curl -i -X POST \
       -H 'sourceidentifier: WRONG' \
       --data '{"climate":{"temp_c":-999}}' \
       http://192.168.20.232/controller/api.php

HTTP/1.1 204 No Content
```

The previous `temp_c` value remains on the dashboard — the malicious push was rejected silently.

### 9.4 Log upload

```
$ printf 'ts,evt,val\n2026-05-10T14:30:22,SENSOR,235\n' | \
  curl -i -X POST \
       -H 'sourceidentifier: <SECRET>' \
       -H 'Content-Type: text/plain' \
       --data-binary @- \
       'http://192.168.20.232/controller/api.php?action=log'

HTTP/1.1 204 No Content
```

The file appears at `http://192.168.20.232/controller/log/logs/2026-05-10_HHMMSS.log` and in the dashboard's separate `/log/` listing page within seconds.

### 9.5 Method rejection

```
$ curl -i http://192.168.20.232/controller/api.php          # GET, not POST

HTTP/1.1 204 No Content
```

(In debug mode this is `405 {"error":"method_not_allowed"}`.)

---

## 10. Versioning and forward compatibility

The schema is currently un-versioned. The `type` field exists for future use but the website ignores it.

### 10.1 What the website tolerates

The dashboard ignores fields it does not understand. Adding new keys to a top-level object, or adding new top-level objects, will not break the website. Specifically:

- **Extra fields in `system`, `climate`, `wind`, `mode`, `sun`** — silently ignored.
- **Extra top-level objects** (e.g. `irrigation`, `lights`) — silently ignored.
- **Extra `mode.flags` strings** — render as muted-grey badges.
- **Extra `windows.*` keys** beyond M1/M2/M3 — silently ignored.
- **Old-style field names** (e.g. `temp_avg_c`, `sunrise_utc_min`) — silently ignored.

So the controller can lead the schema and the dashboard can catch up later without breaking pushes.

### 10.2 What the website does NOT tolerate

- **Wrong types** (e.g. `temp_c` as a string instead of number) — the line shows the literal value, which can render strangely (e.g. `"24.5" °C`). Send numbers as JSON numbers.
- **Top-level array** instead of object — entire push rejected.
- **Invalid JSON** (trailing commas, unquoted keys, etc.) — entire push rejected.

### 10.3 If the schema needs a breaking change

If a renamed field or new required structure ever needs to land:

1. Bump the controller's `system.fw_ver` to a new major version simultaneously with deploying the website that accepts the new schema.
2. The website may briefly accept both old and new field names during the transition; the implementation is free to drop the legacy alias once the firmware fleet is fully on the new version.
3. Keep `update_interval_s` always at the top level — that field is load-bearing for the freshness tile and would be very expensive to relocate.

---

## Appendix — Quick reference card

```
ENDPOINT            : POST <base>/api.php
HEADER              : sourceidentifier: <GH_SECRET_TOKEN>
BODY                : application/json, top-level object
RESPONSE (default)  : 204 No Content (silent on success or any failure)
RESPONSE (debug)    : 200 / 401 / 400 with {"ok"|"error":...}

SCHEMA              :
  {
    "type": "status",
    "update_interval_s": <int>,        ← REQUIRED
    "climate":  { "temp_c", "rh_pct" },
    "wind":     { "speed_ms", "direction_deg" },
    "windows":  { "M1", "M2", "M3" },  ← values: OPEN / MOVING_OPEN / MOVING_CLOSE / CLOSED / UNKNOWN
    "mode":     { "current", "flags": [...] },
    "sun":      { "is_daytime", "sunrise_min", "sunset_min" },
    "system":   { "ntp_synced", "wifi_ip", "wifi_rssi_dbm", "fw_ver" }
  }

LOG ENDPOINT        : POST <base>/api.php?action=log
HEADER              : sourceidentifier: <GH_SECRET_TOKEN>
BODY                : raw bytes, text/plain, ≤ 5 MiB
RESPONSE            : same silent-drop / debug pattern as status push
FILENAME            : server-generated, YYYY-MM-DD_HHMMSS.log

CADENCE             : push status every <update_interval_s> seconds
                      upload log once per 24 hours
RETRY               : only on network-level failure, exponential back-off, no payload queue
```
