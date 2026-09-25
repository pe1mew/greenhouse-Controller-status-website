# Status page update — firmware contract 2.0 (up to fw 2.14.0)

| | |
|---|---|
| Document | Implementation plan |
| Audience | Dashboard implementer |
| Companion | [`technical-spec-statusWebsite.md`](technical-spec-statusWebsite.md) — the firmware-side contract this plan aligns to |
| Date | 2026-09-25 |
| Status | Approved — Phase 3.2 (heap row) and 3.3 (bus tile) skipped by operator decision. |

## Scope in one sentence

Bring the public status page from firmware contract 1.0 (fw 2.0.0-a.6.35.7) to contract 2.0 (fw 2.14.0), with priority on the one visible regression against the fleet (`PART_OPEN` M3 windows drawn as UNKNOWN grey since 2.12.0).

## Where we stand

Ten items were already done in earlier sessions and are safe under the new contract: adaptive freshness caption, footer `unit_id` (TR-45), SD-card synthetic badge, `STANDBY` in `MODE_CLASS`, `--blue` CSS var, `FLAG_LABEL`/`FLAG_DESC` for badges, silent-drop of unknown flags (TR-48), `sd_mounted` in mock, dashboard 2 MiB probe path via `GH_LOG_MAX_BYTES = 5·1024·1024` (already ≥ TR-49's 2 MiB — no change needed). Six new firmware-side additions remain unhandled.

## Deferred by operator decision

- **Phase 3.2 — System-tile heap row** (`heap_free_kb` / `heap_largest_kb`). Reason: at-a-glance operator view, not a diagnostic panel. Local GUI already shows heap.
- **Phase 3.3 — `bus` tile / row for per-slave Modbus counters.** Reason: same. The dashboard viewer can't act on cumulative-since-boot counters. TR-54 is met at code level: `bus` is not consumed, so its absence never errors.

Both remain callable as follow-up work; `TR-55` (never treat `heap_min_kb` as available memory) is trivially satisfied while `heap_*` is unconsumed.

## Phase 1 — the visible regression

Contract 2.0's single "affects a 1.0 site" change: `PART_OPEN` (2.12.0). Any fleet member on ≥ 2.12 that stops M3 part-way shows grey UNKNOWN today.

### 1.1 `PART_OPEN` as a normal state
- **File:** `httproot/assets/app.js` — `COLOR` map, `shortState()`, `textColorFor()`.
- **Change:** `PART_OPEN: 'var(--blue-light)'`; `shortState('PART_OPEN') → 'PART'`; `textColorFor` returns black for `PART_OPEN` (like OPEN).
- **TR:** TR-50.

### 1.2 M3 opening percentage
- **File:** same, `renderWindows()`.
- **Change:** helper `m3Percent(windows)` reads `M3_percent_x10`, clamps to `0..1000`, appends ` <N>%` to the M3 label. Missing key → no percentage (must be distinguishable from "0 %").
- **TR:** TR-51.

### 1.3 Unknown state strings render with their raw text
- **File:** same, `renderWindows()`.
- **Change:** the raw payload value flows through to `title` and label (via `shortState(raw) || raw`), not collapsed to `'UNKNOWN'`. Fill uses `COLOR[raw] || COLOR.UNKNOWN`.
- **TR:** TR-52. Also matches the widened stability guarantee in § 3.4 (unknown values of known keys, not only unknown keys).

## Phase 2 — the six missing badges

Firmware 2.7.1 → 2.12.0 added six flag strings the dashboard silently drops today. Total contract-2.0 flag set is 16 known + `sd_not_mounted` synthetic.

### 2.1 Add rows to `FLAG_CLASS` / `FLAG_LABEL` / `FLAG_DESC`
- **File:** `httproot/assets/app.js`.
- **Change:** append entries for `standby` (warn), `rota_update_pending` (info), `sensor_fault_position` (warn), `m3_not_confirmed` (warn), `m3_travel_short` (warn), `m3_travel_long` (warn). Labels straight from § 9.4's table so the dashboard word-matches the controller's own GUI.
- **TR:** TR-47 (updated) + TR-53.
- **Note:** keep `net_backoff_active` even though § 9.4 says it's defined-but-never-emitted-yet.

### 2.2 Dedup STANDBY pill vs `standby` flag
- **File:** same, `MODE_FLAG_DUPE`.
- **Change:** add `STANDBY: 'standby'` alongside the existing three dedup rows.

## Phase 3.1 — M3 control-law tooltip

Zero screen real estate, satisfies TR-56's "render both" bit.

- **File:** `httproot/assets/app.js` `renderWindows()`; M3 `<title>` element in `httproot/index.php` needs no markup change (already present).
- **Change:** when `M3_ctrl_mode` is present, extend the M3 hover-title from `M3 South wall: PART_OPEN` to `M3 South wall: PART_OPEN — LINEAR (setting, ok)` (mode / reason / gate).
- **TR:** TR-56.

## Phase 4 — mock, docs, changelog, verification

### 4.1 Mock updates
- **`mock/state.py`:** valid windows states gain `PART_OPEN`; add optional M3 fields (`M3_ctrl_mode`, `M3_ctrl_reason`, `M3_pos_gate`, `M3_percent_x10`, `M3_mm_x10`, `M3_at_end_sensor`) with sensible defaults.
- **`mock/templates/control.html`:** `PART_OPEN` in the M3 dropdown; a slider for `M3_percent_x10` (0–1200 to exercise the >100 % overshoot mentioned in § 3.4); toggle buttons for each of the six new flags, mirroring the existing `sd_mounted` / `flags` pattern.

Heap and `bus` are deliberately NOT added to the mock — the dashboard doesn't consume them, so a control for them would confuse.

### 4.2 Documentation
- **`design/functional-design.md`:** field table gains rows for `windows.PART_OPEN`, `M3_percent_x10`, `M3_ctrl_mode`. Note the widened stability guarantee (unknown *values* of known keys render neutrally with raw text).
- **`design/apiSpecification.md`:** same additions to the `windows` table.
- **`design/technical-spec.md` § 9.4:** flag catalogue grows from 10 to 16 entries (contract-side reflection of what the dashboard now handles).
- **`manual/userManual.md` § 3.4 (Ramen):** a row for `PART_OPEN` (lichtblauw, "gedeeltelijk open — normale toestand van M3 onder lineaire regeling"). **§ 3.5 (Modus):** six new rows in the flag-badge table.
- **`changelog.md`:** one `### Changed` entry dated 2026-09-25 summarising Phase 1–3.1 + mock/docs.

### 4.3 Verification (map every applicable TR-49..56 to a probe)
Run against Shuttle2 after deploy, driven by the mock's control panel:

| TR | Probe | Notes |
|---|---|---|
| TR-49 | 1 048 620-byte body via curl → 204; 5 242 881-byte body → 413 or PHP reject. | Already met by `GH_LOG_MAX_BYTES = 5 MiB`. |
| TR-50 | Set M3=`PART_OPEN` → light-blue fill, black `PART` text. | Phase 1.1. |
| TR-51 | `M3_percent_x10 = 252` → label `25%`; `= 1137` → `100%`; cleared → no percentage. | Phase 1.2. |
| TR-52 | M3=`GLARBIT` → label reads `GLARBIT`, neutral-grey fill, no console error. | Phase 1.3. |
| TR-53 | Toggle each new flag → badge appears with the § 9.4 label. | Phase 2.1. |
| TR-54 | Absence of `bus` key never errors. | Not consumed today; passes trivially. |
| TR-55 | Code review — `heap_min_kb` never assigned to a "free memory" element. | Not consumed today; passes trivially. |
| TR-56 | Toggle each M3 control-law field individually → tile keeps rendering; tooltip picks them up. | Phase 3.1. |

## Execution order

1. Phase 1 (three sub-items, single-file diff on `app.js`).
2. Phase 2 (same file, new tables + dedup row).
3. Phase 3.1 (same file, tooltip build).
4. Phase 4.1 mock updates.
5. Phase 4.2 docs + changelog + user manual.
6. Deploy to Shuttle2 (`tools/deploy.ps1`).
7. Run the probes in 4.3.

## Size

Phase 1 ~15 LOC. Phase 2 ~30 LOC. Phase 3.1 ~10 LOC. Phase 4.1 mock ~60 LOC. Docs ~40 LOC. No CSS, no PHP, no schema changes. One afternoon.

## Non-scope

- **Firmware defect: sign lost between −0.9 and −0.1 °C (§ 3.4 climate).** Cannot be repaired on the site.
- **Heap and bus surfacing.** Deferred (see above).
- **The 5C88 / 2344 log-upload halt** currently under investigation. Firmware-side; unrelated to this plan.
