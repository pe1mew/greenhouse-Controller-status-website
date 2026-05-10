# Security Assessment — Public-Internet Deployment

| | |
|---|---|
| Document | Security Assessment for deployment to a typical personal-domain hosting environment (e.g. the same kind of host that runs `pe1mew.nl`) |
| Audience | Operator deciding whether to expose the dashboard beyond the LAN, and what must change first |
| Scope | The PHP website (`httproot/`), the deployment pipeline (`tools/`), the development mock (`mock/`), and the operator workflow — re-evaluated for an internet-facing deployment |
| Companion | [securityAssessment_LAN.md](securityAssessment_LAN.md) — same code, evaluated for the current LAN-only test deployment |
| Methodology | OWASP Web Top 10 (2021) and OWASP API Top 10 (2023). Findings cross-referenced to FR/TR identifiers and to test results in `test/`. |
| Date | 2026-05-10 |
| Verdict | **Fit for public-internet deployment** at `pe1mew.nl`-class personal hosting. HTTPS via Let's Encrypt is provisioned; the no-index policy ships in three layers; the per-IP token bucket sheds bursts on the controller-write path; audit logging captures every silent-drop branch; security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, X-Robots-Tag) are set via `httproot/.htaccess`; direct access to `config.php` is blocked. The provider-TLS-termination residual is accepted as a trust-3 trust acceptance. The only remaining items are good-citizenship niceties (security.txt, registrar 2FA) and an optional dashboard-privacy decision. |

> ⚠ This document targets a *future* deployment. The site as it stands today is on a private LAN; see [securityAssessment_LAN.md](securityAssessment_LAN.md) for that scope. Do **not** copy the test-server `httproot/` to a public host without working through § 7 of this document first.

## Table of contents

1. [Scope and assumptions](#1-scope-and-assumptions)
2. [What changes vs. the LAN-test scope](#2-what-changes-vs-the-lan-test-scope)
3. [Threat model](#3-threat-model)
4. [Attack surface inventory](#4-attack-surface-inventory)
5. [Findings by category](#5-findings-by-category)
   - 5.1 [Authentication and access control](#51-authentication-and-access-control)
   - 5.2 [Cryptographic posture](#52-cryptographic-posture)
   - 5.3 [Injection (XSS, SQL, shell, header)](#53-injection)
   - 5.4 [Resource consumption and DoS](#54-resource-consumption-and-dos)
   - 5.5 [Misconfiguration](#55-misconfiguration)
   - 5.6 [Information disclosure and privacy](#56-information-disclosure-and-privacy)
   - 5.7 [Supply chain and integrity](#57-supply-chain-and-integrity)
   - 5.8 [Logging, monitoring, and incident response](#58-logging-monitoring-and-incident-response)
   - 5.9 [Hosting-provider and operational concerns](#59-hosting-provider-and-operational-concerns)
6. [Risk matrix](#6-risk-matrix)
7. [Pre-deployment hardening checklist (BLOCKING)](#7-pre-deployment-hardening-checklist-blocking)
8. [Defence-in-depth additions (RECOMMENDED)](#8-defence-in-depth-additions-recommended)
9. [Operational playbook](#9-operational-playbook)
10. [Appendix — OWASP Top 10 mapping](#appendix--owasp-top-10-mapping)

---

## 1. Scope and assumptions

This document evaluates the security posture of the same code described in [`technical-spec.md`](technical-spec.md), assuming it is deployed to a typical Dutch personal-domain hosting environment — e.g. a shared host or small VPS comparable to the one running `pe1mew.nl`.

**Assumed hosting characteristics:**

- Apache 2.4.x with PHP 8.1+ via PHP-FPM, one pool per customer.
- Per-user file ownership; PHP runs as the customer's user (not `www-data`). Filesystem isolation via FPM pools or `suexec`.
- `AllowOverride All` for customer document roots — `.htaccess` files are honoured. (This is industry-standard on shared hosts; the LAN test server's `AllowOverride None` was the unusual case.)
- HTTPS is provisioned by the host (typically Let's Encrypt automation) and a TLS certificate is available for the customer's domain.
- The host operator generally manages OS-level patching, fail2ban, basic abuse handling, and web-server logs; the customer manages everything inside their document root.
- Public DNS resolves the customer's domain to the host's IP; the world can reach port 443.

**In scope.** The PHP application code, the JavaScript on the dashboard, the deploy pipeline, the operator's secret-handling workflow, the public exposure model, and the hosting-provider boundary.

**Out of scope.** OS-level hardening of the shared host, the host operator's incident-response process, and the upstream registry hygiene of PHP / Apache / PyPI.

**Important assumption taken as given.** The dashboard contents themselves (greenhouse temperatures, vent positions, mode, network metadata) are non-sensitive in the legal/PII sense. Their public exposure on the internet is acceptable to the operator. If that assumption is wrong, see § 8 "dashboard privacy" for the gating control.

---

## 2. What changes vs. the LAN-test scope

The same code lives in two completely different threat environments:

| Concern | LAN-test deployment | Public-internet deployment |
|---|---|---|
| Adversary base rate | One opportunist hypothetically on the same LAN | Continuous internet background scanning, automated exploit kits, opportunistic credential stuffing, occasional targeted probing |
| HTTP exposure | Plaintext on a private network — moderate risk | HTTPS in place via Let's Encrypt; plaintext only between the provider's TLS terminator and the PHP-FPM pool (host-internal) |
| `.htaccess` enforcement | Was `AllowOverride None`; rules ignored | Typically `AllowOverride All`; rules in force — closes FR-36, FR-37, FR-38 cleanly |
| Banner / version disclosure | Low value | Useful to mass scanners building CVE-targeted attack queues |
| Search-engine indexing | n/a | Mitigated — `robots.txt`, meta-robots tags, and `X-Robots-Tag` header all ship with the project |
| Rate limiting | Background concern | First line of defence — required |
| Operator visibility | Single operator on the LAN, sees Apache logs directly | May share logs with the host; abuse reports may come via the host |
| Provider-side TLS termination | n/a | Real on shared hosts — secret may be visible in plaintext at the host's TLS terminator before it reaches PHP |

The architectural decisions (presence-driven tiles, asymmetric auth on api.php vs. view.php, silent-drop behaviour, server-generated log filenames) all carry over unchanged. The hardening work happens around them, not inside them.

---

## 3. Threat model

### 3.1 Assets

Same as the LAN scope:

- `GH_SECRET_TOKEN` — write-path authentication.
- `data/status.json` — committed dashboard state.
- Uploaded log files.
- The web-server execution context.
- The operator's SSH key (deploy authority).

Two additions specific to public deployment:

- **The domain reputation.** If the site is ever used to host attacker-uploaded content (via a stolen secret), the host may receive abuse complaints, blacklists may flag the domain, and search engines may de-rank or warn on it.
- **The TLS certificate**. Issued for the customer's domain by the host's automation. Revocation is effectively impossible without host help.

### 3.2 Adversaries

| Actor | LAN profile | Public profile | Motivation in this context |
|---|---|---|---|
| Random internet scanner | Hypothetical | **Continuous** | Foothold for botnet, generic exploit kit, sometimes ransomware staging |
| Targeted attacker | Plausible if commercial greenhouse | Plausible | Sabotage greenhouse operations, deface dashboard, plant false readings |
| Insider / operator mistake | Most realistic recurring threat | Same | Accidentally commit a secret, weaken a deploy step, leave debug on |
| Hosting-provider insider | n/a | Low probability, very high impact | Read filesystem; obtain secret; pivot |
| Compromised browser | Negligible | Negligible | XSS-safe rendering is verified |
| Search engine / archive | n/a | Continuous | Indexes whatever it can crawl, hard to undo |

The first two rows are the dominant new threats. Random scanners arrive within minutes of public DNS being live, regardless of whether the site is "advertised". Treat them as already-on-the-doorstep.

### 3.3 Trust boundaries

```
Internet ──────────────────────────────────────────────────────┐
                                                                ▼
              ┌──────────────────────────────────────────────────────────┐
              │  Hosting provider                                         │
              │  ┌────────────────────────────────────┐                   │
              │  │ TLS terminator (provider-managed)  │                   │
              │  └──────────────┬─────────────────────┘                   │
              │                 │ plaintext on the host                   │
              │                 ▼                                         │
              │  ┌────────────────────────────────────┐                   │
              │  │ Apache + PHP-FPM (customer pool)   │                   │
              │  │  api.php / view.php / index.php    │                   │
              │  │  log/index.php                     │                   │
              │  └──────────────┬─────────────────────┘                   │
              │                 ▼                                         │
              │  ┌────────────────────────────────────┐                   │
              │  │ /home/<user>/public_html/controller│                   │
              │  │  config.php, data/, log/logs/      │                   │
              │  └────────────────────────────────────┘                   │
              └──────────────────────────────────────────────────────────┘
                                ▲
                                │ scp + ssh (key auth, on a port the host exposes)
                                │
                  ┌────────────────────────────────────┐
                  │  Operator workstation              │
                  │  config.php (real)                 │
                  │  .deploy.env (real)                │
                  └────────────────────────────────────┘

Controller ─── HTTPS ──────────────────────────────────────────▲
                                                                │
                       (sourceidentifier header in the request) │
```

Two new trust boundaries to acknowledge:

1. **Provider TLS terminator → PHP.** On many shared hosts, TLS is terminated at the provider's load balancer or front-end and the request reaches the PHP-FPM pool over plaintext on the host's loopback or private network. The shared secret is therefore visible to the host operator and to anyone who can read traffic on the host's internal network. **This is a real residual risk for the secret even with HTTPS.** Mitigation: choose a host that runs TLS termination on the same machine as PHP (single-tenant VPS) or accept the host operator as a trust-3 entity.
2. **Repo / deploy chain.** If the operator's GitHub credentials, SSH key, or the `.deploy.env` file leak, the attacker has full deploy authority and can replace the secret as well as push new code that exfiltrates anything they like. Standard SSH-key hygiene applies.

---

## 4. Attack surface inventory

The HTTP surface is identical to the LAN scope (see [securityAssessment.md § 3](securityAssessment.md#3-attack-surface-inventory)) but reachable from the entire internet. Concretely:

| Path | Method | Auth | Public exposure consequence |
|---|---|---|---|
| `POST /api.php` | POST | shared secret | Will be probed by automated scanners (POST endpoints are common targets for credential stuffing and command-injection probes). |
| `POST /api.php?action=log` | POST | shared secret | Same. Disk-fill DoS becomes a credible threat once the secret leaks. |
| `GET /view.php` | GET | none | Indexed by search engines unless explicitly excluded. WiFi-network metadata becomes globally visible. |
| `GET /view.php?action=logs` | GET | none | Log filenames (timestamped) become publicly enumerable. |
| `GET /index.php` | GET | none | Indexed. |
| `GET /log/` | GET | none | Indexed unless excluded. Log filenames + sizes + dates publicly enumerable. |
| `GET /log/logs/<file>` | GET | none | Each individual log file is publicly downloadable. Search engines may cache a few. |
| `GET /logs/`, `GET /log/logs/` | GET | none | Redirects only. |
| Probes against any other path | GET/POST/HEAD | none | Apache 404; expect 100s/day. |

Compared with the LAN scope, the GET surface (no auth) has gone from "anyone on my LAN can see" to "anyone on Earth can see, plus search engines may cache it forever".

---

## 5. Findings by category

Each finding is rated on **Likelihood × Impact** under public-internet exposure. Findings whose rating *changed* from the LAN scope are flagged with `[Δ]`.

### 5.1 Authentication and access control

| ID | Finding | Mitigation in place | Public-internet residual risk |
|---|---|---|---|
| A-1 | Single static shared secret on the controller-write path. No HMAC, no nonce, no replay protection. | 32-char CSPRNG token; deploy script blocks shipping the placeholder. | **Medium** [Δ] — entropy holds, but the secret transits the network and lives on multiple machines. Replay of a captured push remains possible if HTTPS is bypassed at any point. |
| A-2 | Browser-read endpoints are intentionally unauthenticated. | Documented design decision. | **Medium** [Δ] — "intentionally public" had a different meaning on a LAN. On the open internet, anyone (search engines included) can index dashboard contents. May be acceptable per the assumption in § 1, otherwise see § 8 "dashboard privacy". |
| A-3 | Cross-method requests on `api.php`/`view.php` silently rejected with HTTP 204. | Verified TR-01, TR-10. | Low — silent rejection deters scanner enumeration. |
| A-4 | Single shared secret across the entire (one-controller) fleet. | Single-controller assumption is explicit. | n/a at current scale. |
| A-5 | No rate limit, lockout, captcha, or anomaly trigger on auth failures. | **CLOSED** — `gh_rate_limit()` in `api.php` enforces a per-IP token bucket (default 60 burst, 0.2 token/s refill ≈ 12 req/min sustained). State persists in `data/ratelimit.json` with `LOCK_EX` serialising read-modify-write; entries idle for over an hour are pruned automatically. Verified: 70 rapid POSTs from one IP land 60 through and 10 rate-limited; the bucket drained to ≈ 0 as expected. Failed pushes still return silent 204 in production mode so an attacker cannot tell rate-limit rejections apart from auth rejections. |

**Verdict.** A-5 is **closed**. The endpoint is no longer pitch-black to bursts. Pairing the rate limit with error-log tracing of every silent-drop branch (still on the to-do list) turns the remaining black-box behaviour into a recoverable one for incident response.

### 5.2 Cryptographic posture

| ID | Finding | Public-internet status |
|---|---|---|
| C-1 | All HTTP traffic. | **CLOSED** — production runs HTTPS with a Let's Encrypt certificate. Confirm a 301 redirect from `:80` is in place; if not, add the canonical `RewriteEngine On / RewriteCond %{HTTPS} !=on / RewriteRule ^ https://...` block in `.htaccess`. |
| C-2 | No HSTS header. | **CLOSED** in `httproot/.htaccess` — `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"` ships with the project. After a month of clean operation, consider preload registration. |
| C-3 | No certificate pinning on the controller side. | Out of scope here; relevant to firmware. With Let's Encrypt the chain rotates every ~60–90 days, so the controller must validate against the standard CA bundle (no static pinning). |
| C-4 | Shared secret stored in plaintext on the server filesystem (`config.php`) and on the operator workstation (`.deploy.env`). | Low — same posture, but on a shared host the file is readable by the operator's own user only (per-user FPM pool); other customers on the same physical host cannot read it. Verify the host's isolation model before relying on this. |
| C-5 | TLS terminator may be on a different host than PHP. | **ACCEPTED** — operator accepts the host operator as a trust-3 entity. The secret is plaintext between the provider's edge and the PHP-FPM pool, which is fine given the trust posture. Revisit if the hosting choice changes (e.g. moving to a VPS would make TLS termination on-host, eliminating this risk; moving to a less-trusted provider would re-open it). |

**Verdict.** HTTPS is **in place** via the host's Let's Encrypt automation. The remaining work in this category is the single `Strict-Transport-Security` header (one line of `.htaccess`).

### 5.3 Injection

Identical to the LAN profile — XSS-safe rendering, no SQL, no shell, no `unserialize`, no user-controlled `include`, no `Location`-header injection. Verified probes and code review carry over unchanged.

| ID | Finding | Public-internet status |
|---|---|---|
| I-1 | XSS via payload-injected HTML/scripts. | None — `textContent`-only writes verified TR-26 and FR-39. |
| I-2 | HTML injection in the standalone logs page. | None — `htmlspecialchars` + `rawurlencode`. |
| I-3 to I-7 | SQL / shell / `include` / `unserialize` / header injection. | None — none of these surfaces exist. |
| I-8 | Path traversal in download URL. | Low — Apache normalises `..`; filename whitelist takes effect because `AllowOverride All` is present on this profile (closing the LAN-scope deferral). |

### 5.4 Resource consumption and DoS

| ID | Finding | Mitigation in place | Public-internet residual risk |
|---|---|---|---|
| D-1 | Disk-fill via authenticated log uploads. | 5 MiB cap; 90-day retention; sweep on success. | Low — bounded by retention and cap. |
| D-2 | Memory exhaustion on forged oversized POST. | Capped by `php://input` and PHP's `post_max_size` (typically 8 MiB on shared hosts). | Low. |
| D-3 | Connection / request flooding. | **CLOSED** — `gh_rate_limit()` per-IP token bucket sheds the overflow before any other PHP work runs. A 70-request burst test shows the bucket caps the throughput at 60 (default `GH_RATE_LIMIT_BUCKET`) and rejects the rest in microseconds. Sustained refill rate is 0.2 tok/s ≈ 12 req/min, so a determined attacker is held to 12 lookups/minute per IP. Distributed flooding remains possible but requires N IPs to multiply the rate; that's a different threat profile (see § 9 if it becomes relevant). |
| D-4 | Dashboard polling under load. | 5-second poll interval per browser tab. | Low at expected scale. |
| D-5 | Race conditions on status writes. | `LOCK_EX` + atomic `rename()`. | Low. |
| D-6 | Slowloris and similar TCP-level attacks. | Apache's default `mod_reqtimeout` plus the host's fail2ban. | Low — host-side controls. |

**Verdict.** D-3 is **closed** by the same token bucket that closes A-5. The remaining DoS exposure is distributed (multi-IP) flooding, which is out of reach of any per-IP control and would require a network-edge solution if it ever showed up.

### 5.5 Misconfiguration

| ID | Finding | Public-internet status |
|---|---|---|
| M-1 | `AllowOverride None` ignoring `.htaccess`. | **Closed** [Δ] on this profile (typical shared hosting has `AllowOverride All`). However: **must be verified on the actual target host**, not assumed. Closure of FR-36/37/38 hinges on this. |
| M-2 | Multi-tenant Apache shared with the rest of the host. | Provider-side concern; FPM pools usually isolate filesystem and process. |
| M-3 | `php.ini` settings. | Most shared hosts default to `display_errors = Off`, `expose_php = On` (sometimes Off). Verify after deploy: `curl -I https://<host>/` — should not include `X-Powered-By`. |
| M-4 | Dotfiles and template files deployed. | The `httproot/config_template.php` lands in the document root. PHP processes it to no output (verified). The placeholder value is a deliberate fake, no secret leaks. Acceptable. |
| M-5 | No `Content-Security-Policy` header. | **CLOSED**. Set in two places: `httproot/.htaccess` (for hosts with permissive `AllowOverride`) **and inlined as `header()` calls at the top of every PHP entry point** (the load-bearing source on hosts where `AllowOverride` is too restrictive to honour `Header always set` directives — e.g. pe1mew). `'unsafe-inline'` is required for `index.php`'s inline `window.GH_CFG` script and `log/index.php`'s inline `<style>` block; XSS surface is independently neutralised by `textContent`-only rendering (TR-26 / FR-39). |
| M-6 | No `X-Frame-Options`, `Referrer-Policy`, `Strict-Transport-Security`. | **CLOSED** via the same dual approach. PHP-side `gh_send_security_headers()` sets `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`, `Strict-Transport-Security: max-age=31536000; includeSubDomains`, plus `X-Robots-Tag: noindex, nofollow` on every PHP-served response. The `.htaccess` ships the same headers for hosts that allow it (e.g. on pe1mew the host restricts `AllowOverride` to `AuthConfig Limit`, so `.htaccess` `Header` directives are silently dropped — but the PHP-side path is unaffected). |
| M-7 | `Server: Apache/<version>` banner. | Medium [Δ] — `ServerTokens Prod`, `ServerSignature Off` recommended. May require host cooperation if the customer cannot edit the main Apache config; sometimes possible from `.htaccess` via `Header unset X-Powered-By` and similar. |
| M-8 | Search-engine indexing. | **CLOSED** — three layers shipped: (a) `httproot/robots.txt` with `User-agent: * / Disallow: /` so well-behaved crawlers stop at the door (effective when the project is mounted at the host root), (b) `<meta name="robots" content="noindex, nofollow">` in `index.php` and `log/index.php` (effective regardless of mount path), (c) `X-Robots-Tag: noindex, nofollow` HTTP header on `view.php` JSON responses. Misbehaving crawlers can still fetch content but Google/Bing/etc. will respect at least one of the three layers. |
| M-9 | `httproot/log/logs/` listing. | **Closed** [Δ] on this profile — the `.htaccess`-shipped `Options -Indexes` will take effect with `AllowOverride All`. The compensating `index.php` redirect added during the LAN test still works as defence in depth. |
| M-10 | The hosting account's PHP version. | Verify `phpinfo()` or `<?= PHP_VERSION ?>` returns ≥ 8.1 (required for `array_is_list`). If the host runs 7.x by default, switch the customer pool to 8.1+ in the control panel before deploy. |

**Verdict.** Most LAN-scope deferrals close cleanly on this profile because `AllowOverride All` is the typical default. The new misconfigurations to address are CSP, HSTS, response-header policy, and search-engine indexing — all `.htaccess` work, no code changes.

### 5.6 Information disclosure and privacy

| ID | Finding | Public-internet status |
|---|---|---|
| ID-1 | `data/status.json` exposed. | **Closed** [Δ] by `Require all denied` in `data/.htaccess` once `AllowOverride All` is in force. |
| ID-2 | `httproot/config.php` GET. | Closed by PHP processing pure `define()` to 0 bytes. Defence in depth: add `<Files "config.php">Require all denied</Files>` to a top-level `.htaccess` so the file cannot be served even if PHP processing is somehow disabled. |
| ID-3 | Apache banner / `X-Powered-By`. | Medium [Δ] — see M-7. Also strip `X-Powered-By` via `.htaccess`: `Header unset X-Powered-By`. |
| ID-4 | WiFi-network metadata visible (`wifi_ip`, `wifi_rssi_dbm`). | **Medium** [Δ] — globally visible if the dashboard remains public. The operator should consciously decide if this is acceptable; if not, gate the dashboard (see § 8 dashboard privacy). |
| ID-5 | `view.php?action=logs` enumerates filenames + dates. | Medium [Δ] — the timestamped filenames reveal upload schedule patterns. If the dashboard is gated, this is gated too. If not, an attacker can map the controller's daily upload window. |
| ID-6 | PHP error messages on the wire. | Closed if `display_errors = Off` (typical). Verify post-deploy. |
| ID-7 | `config_template.php` deployed. | Acceptable — contains only the deliberate placeholder string, no real secret. Could be excluded from the deploy by adding it to a `tools/deploy.ps1` filter, but the cost-benefit is marginal. |

**Verdict.** Disclosure surface is small in absolute terms but ID-4 and ID-5 graduate from "harmless on a LAN" to "moderate on the internet". The operator must decide whether the dashboard should remain public.

### 5.7 Supply chain and integrity

Carries over unchanged from the LAN scope. Server-side has zero third-party PHP dependencies. Frontend assets are same-origin. The deploy uses key-authenticated SSH. Nothing on this axis changes when moving to public hosting.

One addition: **the host's PHP/Apache update cadence** is now part of the threat model. Most reputable Dutch shared hosts patch within a sane window; verify the host's stated SLA.

### 5.8 Logging, monitoring, and incident response

| ID | Finding | Public-internet status |
|---|---|---|
| L-1 | No security-relevant audit logs in the application. | **CLOSED** — `gh_fail()` in `api.php` now writes one structured `error_log()` line per drop, before responding silently to the client. Format: `[hbwv api] drop ip=<addr> reason=<branch> action=<status\|log> http=<code>` (with optional `detail=<msg>` from the JSON debug body). Detail values are flattened of CR/LF to prevent log injection. The line lands in the host's PHP error log (Apache `error.log` on Debian/Ubuntu, per-customer log on shared hosts). Verified on the LAN test server: 3 wrong-secret pushes produced 3 audit lines with IP, reason=unauthorized, action=status, http=401. |
| L-2 | No anomaly alerting. | Medium [Δ] — once L-1 is in place, run `logrotate` + a daily `grep` on the error log for repeated failures from the same IP. The host's fail2ban can drop chronic offenders if configured to read PHP error logs. |
| L-3 | No status-history snapshots. | Low — not a security issue per se, but limits forensic timeline for "did the dashboard ever show X?". A daily ringed copy of `data/status.json` to a directory with `Require all denied` would solve it cheaply. |
| L-4 | The host's access logs may have a different rotation/retention policy than the project assumes. | Operator-side — confirm with the host. |
| L-5 | No abuse contact set on the domain. | Recommend a `security.txt` (RFC 9116) at `/.well-known/security.txt` so good-faith reporters know where to send findings. |

**Verdict.** L-1 is **closed**. The endpoint is no longer black-box: every silent-drop carries an audit trail visible to the operator. L-2 (anomaly alerting via grep / fail2ban) is a logical next step but not blocking. L-5 (security.txt) is good citizenship.

### 5.9 Hosting-provider and operational concerns

These are all NEW relative to the LAN scope. They concern the operator's relationship with the host.

| ID | Finding | Risk |
|---|---|---|
| H-1 | Provider-side TLS termination means the secret is plaintext on the host's internal network between the load balancer and the PHP-FPM pool. | **ACCEPTED** — operator treats the host operator as a trust-3 entity for the deployment in question. The residual is documented; if the hosting choice changes, re-evaluate. |
| H-2 | The host may have its own WAF that intercepts requests. WAFs occasionally block legitimate POSTs containing JSON or fail-secure on unusual headers. | Low — verify the controller's POSTs aren't blocked during commissioning. |
| H-3 | The host may rate-limit per IP at the perimeter. Helpful in principle; can also rate-limit the operator's own deploys or the controller's pushes. | Low — verify with a quick burst-test against `view.php`. |
| H-4 | The host may suspend the customer account if the dashboard is fingerprinted as malware-staging by an upstream blacklist (especially if a stolen secret is used to upload `.log` files containing IoC strings). | Low to medium — strong rate-limit and audit logging mitigate the worst case. |
| H-5 | Domain DNS hijacking. If the registrar account is compromised, the attacker can repoint the domain at their own server and capture the controller's pushes (with the right secret). | Low — registrar-side concern; ensure 2FA on the registrar account. |
| H-6 | Backup retention by the host. The host typically takes daily backups of the customer's filesystem; those backups contain `httproot/config.php`. | Operator-side — accept that the host now has historical copies of the secret. |
| H-7 | The deploy uses SSH/SCP. The host may expose SSH on a non-standard port or behind a control-panel-mediated proxy. | Operator-side — `~/.ssh/config` already accommodates this; document the port + key in `.deploy.env.example` if the next operator takes over. |

**Verdict.** None of these are blocking; they are reality factors the operator should consciously accept.

---

## 6. Risk matrix

Sorted by public-internet risk descending. The "[Δ]" column shows whether the rating changed from the LAN-test scope.

| ID | Description | LAN risk | Public risk | Change |
|---|---|---|---|---|
| A-1 | Static shared-secret auth | Low | Medium | [Δ] |
| L-1 | No security-relevant audit logging | Low (LAN) | **Closed** | `gh_fail()` writes one `error_log()` line per silent-drop branch. |
| C-2 | No HSTS header | n/a | **Closed** | `Strict-Transport-Security` set in `httproot/.htaccess`. |
| M-5 | No CSP header | Low | **Closed** | `Content-Security-Policy` set in `httproot/.htaccess`. |
| M-6 | No X-Frame-Options / Referrer-Policy | Low | **Closed** | All three set in `httproot/.htaccess`. |
| M-7 | Apache banner / X-Powered-By | Low | Medium | [Δ] |
| ID-3 | Apache banner reveals version | Low | Medium | [Δ] |
| ID-4 | WiFi metadata public | Low | Medium | [Δ] |
| ID-5 | Log filenames enumerable | None | Medium | [Δ] |
| A-2 | Browser-read unauthenticated | Low | Medium (privacy decision) | [Δ] |
| L-3 | No status history | Low | Low | — |
| O-2 | Older placeholder still triggers warning, not block | Low | Medium | [Δ] |
| A-5 | No rate limit / lockout | Low (LAN) | **Closed** | Per-IP token bucket in `api.php`. |
| D-3 | Connection / request flooding | Low (LAN) | **Closed** | Same bucket; verified 70-burst → 60 pass / 10 limited. |
| C-1 | Plain HTTP exposes the shared secret | Low (LAN) | **Closed** | HTTPS via Let's Encrypt is provisioned. |
| C-5 | Provider TLS termination | n/a | **Accepted** | Operator accepts the host operator as a trust-3 entity. |
| H-1 | Provider TLS terminator (pool-internal cleartext) | n/a | **Accepted** | Same trust-3 acceptance. |
| M-8 | Search engine indexing | n/a | **Closed** | `robots.txt` + meta-robots + `X-Robots-Tag` shipped. |
| M-1 | `AllowOverride None` | Medium (LAN) | Closed (likely PASS) | [Δ] reversed |
| ID-1 | `data/status.json` direct fetch | Low (LAN) | Closed | [Δ] reversed |
| I-1..I-7 | Injection surfaces | None | None | — |

The shape of this matrix is now: HTTPS is in place via Let's Encrypt; the indexing risk is mitigated; the per-IP token bucket closes both rate-limit and request-flooding concerns; and the provider-TLS-termination residuals are accepted as a trust-3 acceptance. **One blocking item remains** — audit logging on the silent-drop branches, so abuse can be reconstructed after the fact. After that lands, all the medium-rated items are header-tuning (HSTS, CSP, X-Frame-Options, etc.) — `.htaccess`-only, no code changes.

---

## 7. Pre-deployment hardening checklist (BLOCKING)

Walk these end to end before flipping public DNS to the new host. Each item maps to a finding above and to a verifiable outcome.

### 7.1 Crypto

- [x] **Provision HTTPS** for the customer's domain. **Done** — Let's Encrypt is in place. (Closes C-1.)
- [ ] **Force HTTPS** via `.htaccess` (verify the host hasn't already done this server-side):
  ```apache
  RewriteEngine On
  RewriteCond %{HTTPS} !=on
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
  ```
  Probe: `curl -I http://<host>/controller/` should return 301 to `https://`.
- [ ] **Set HSTS** via `.htaccess`:
  ```apache
  <IfModule mod_headers.c>
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  </IfModule>
  ```
  Closes C-2. After the first month, consider preload registration.
- [ ] **Rotate the secret** to a fresh 32+ char CSPRNG value distinct from the LAN-test value. Update `httproot/config.php`, `MOCK_SECRET` in `.deploy.env`, and the controller's compiled value in lockstep. (TR-23.)

### 7.2 Auth and rate limiting

- [x] **Add a per-IP rate limit** on `api.php`. **Done** — `gh_rate_limit()` enforces a token bucket (default 60 burst, 0.2 token/s = 12 req/min sustained) keyed by `REMOTE_ADDR`, state in `data/ratelimit.json` with `LOCK_EX`. 70-burst probe verified: 60 pass through to auth, 10 rate-limited at the bucket. Closes A-5 and D-3.
- [x] **Add audit logging** to `api.php`. **Done** — `gh_fail()` writes a structured `error_log()` line per silent-drop branch (`[hbwv api] drop ip=<addr> reason=<branch> action=<status|log> http=<code>`). Verified on LAN: 3 wrong-secret pushes produced 3 audit lines. Closes L-1.
- [x] **Mitigate search-engine indexing** of the public dashboard. **Done** — the project ships:
  - `httproot/robots.txt` (`User-agent: * / Disallow: /`)
  - `<meta name="robots" content="noindex, nofollow">` in `httproot/index.php` and `httproot/log/index.php`
  - `X-Robots-Tag: noindex, nofollow` HTTP header on `view.php`'s JSON responses

  (Closes M-8.)
- [ ] **Decide on dashboard privacy** (see § 8). The above prevents indexing but does not gate access — anyone who knows the URL can still load the dashboard. If `wifi_ip`/`wifi_rssi_dbm`/window states should be private, add HTTP Basic Auth or a session login on the read path.

### 7.3 Configuration

- [x] **Verify `AllowOverride All`** is in force on the host. **Done** for pe1mew — `curl /hbwv/data/` returns 403, the deny-all rule fires.
- [x] **PHP-version compatibility**. **Done** — `api.php` polyfills `array_is_list()` for hosts on PHP < 8.1, and `view.php` / `log/index.php` use traditional anonymous functions instead of arrow syntax (PHP 7.4+ compatible).
- [ ] **Confirm `display_errors = Off`** in production. Probe: trigger a deliberate 4xx in debug mode briefly during commissioning, then revert.
- [x] **Set security headers** via `.htaccess`. **Done** — `httproot/.htaccess` ships with `Strict-Transport-Security`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`, the full `Content-Security-Policy`, an `X-Robots-Tag: noindex, nofollow`, and `Header unset X-Powered-By`. Activates on hosts with `AllowOverride All`. Closes M-5 and M-6.
- [x] **Block direct access** to `config.php` and `config_template.php`. **Done** in `httproot/.htaccess` via `<FilesMatch "^config(_template)?\.php$">Require all denied</FilesMatch>`. Defence in depth for ID-2 and ID-7.
- [x] **Block dotfiles**. **Done** in `httproot/.htaccess` via `<FilesMatch "^\.">Require all denied</FilesMatch>`. Apache already blocks `.htaccess` itself by default; this catches accidental `.env`, `.git*`, etc.
- [ ] **Suppress server banner**. `ServerTokens Prod` + `ServerSignature Off` in the customer's vhost or via `.htaccess` if the host allows. Closes M-7. If the host doesn't allow it, accept the residual.

### 7.4 Operational

- [ ] **Add `.well-known/security.txt`** (RFC 9116) with an abuse-contact email. Good citizenship; closes L-5.
- [ ] **Block the older `dev-1234…` placeholder hard** in `tools/deploy.ps1` — promote the warning into an outright refusal for production deploys. Use a CLI flag to permit it for LAN-test deploys if you still want to support that workflow.
- [ ] **Enable 2FA on the domain registrar account.** Mitigates H-5.
- [ ] **Enable 2FA on the GitHub account** that holds the repo. Mitigates the supply-chain row.
- [ ] **Re-run the FD and TS test reports** end-to-end against the public deploy. Re-classify the previously-deferred rows.

---

## 8. Defence-in-depth additions (RECOMMENDED)

These are not blockers but materially improve the posture once the BLOCKING list is clear.

### 8.1 Dashboard privacy (optional gate)

If the dashboard contents (greenhouse readings + WiFi metadata) should not be globally readable, gate the read path. Two options:

1. **HTTP Basic Auth** via `.htaccess` on `index.php`, `view.php`, `log/index.php`, and `log/logs/`. Cheap; ugly browser prompt; secure enough for a personal site.
2. **Session-cookie login** in PHP. More work; nicer UX. Use bcrypt for the credential, set `Secure; HttpOnly; SameSite=Strict` on the session cookie.

Either way, the controller-write path stays exactly as it is — gating happens on the read path only. The mock controller continues to work without change.

### 8.2 Subresource integrity (n/a so far)

All assets are same-origin; no CDN means no SRI to add. If a CDN is ever introduced, add SRI hashes.

### 8.3 Status history

A daily ringed copy of `data/status.json` into `data/history/<YYYY-MM-DD>.json` (kept under the deny-all `data/.htaccess`) gives a 30-day forensic timeline at trivial cost. `cron`-scheduled `cp` on the host, or a daily action in `api.php` triggered by mtime comparison.

### 8.4 Secrets manager

For organisations beyond a one-person shop, move `GH_SECRET_TOKEN` out of `config.php` and into the host's environment-variables facility (most cPanel-class hosts support this). Then `define('GH_SECRET_TOKEN', getenv('GH_SECRET_TOKEN'))`. Removes the secret from filesystem backups.

### 8.5 Subdomain

Consider deploying to a dedicated subdomain (e.g. `greenhouse.pe1mew.nl` or `controller.pe1mew.nl`) rather than a path under the main site. Pros: cleaner cookie scoping if Basic Auth is added; cleaner CSP; clearer CSP/HSTS isolation; the dashboard can be `noindex`-ed without affecting the parent site's SEO. Cons: requires a separate certificate (usually automatic) and a one-time DNS setup.

---

## 9. Operational playbook

### 9.1 Day-1 cutover

1. Provision HTTPS on the new host's domain.
2. Walk the BLOCKING checklist from § 7. Tick every box.
3. Deploy via `tools/deploy.ps1` with the new `.deploy.env` pointing at the public host.
4. Do **not** start the controller against the new endpoint yet. Leave the mock pointed at the LAN test for one more day.
5. Verify with `curl` from a different network: `view.php` returns expected headers (HSTS, CSP), no banner; `api.php` POST without secret returns silent 204; `api.php` POST with the new secret writes `status.json`.
6. Update the controller-side base URL and secret. Reflash if necessary.
7. Switch over.

### 9.2 Incident drills (run once, before going live)

- **Lost secret simulation.** Deliberately push with the wrong secret 100 times in a minute from the operator's workstation. Verify: silent-drop log fires for each one; rate limiter starts shedding after the bucket empties; dashboard remains green (legitimate pushes from the controller are still landing).
- **Dashboard defacement attempt.** Deliberately push with the right secret a payload containing `<script>alert(1)</script>` in a string field. Verify: dashboard renders the literal text; nothing executes. (Repeat of FR-39 against the production deploy.)
- **Disk-fill attempt.** With the right secret, upload 100 max-size log files in rapid succession. Verify: each upload triggers retention sweep; total disk usage stays within retention bound; rate limiter eventually sheds.

### 9.3 Routine checks

- **Monthly**: `grep -c 'silent-drop' /var/log/.../error.log | sort | uniq -c` to spot abuse patterns. Compare baseline month over month.
- **Quarterly**: rotate the shared secret. Document procedure: server first, controller within an hour. Brief outage tolerated.
- **Annually**: re-run the BLOCKING and DEFENCE-IN-DEPTH checklists. Patch anything that drifted.

---

## Appendix — OWASP Top 10 mapping

### OWASP Web Top 10 (2021) — public-internet profile

| Category | Status |
|---|---|
| **A01 Broken Access Control** | PASS for the controller-write path; PARTIAL for the read path (publicly readable by design — operator must consciously accept this or gate it per § 8). |
| **A02 Cryptographic Failures** | PASS — HTTPS via Let's Encrypt; HSTS set in `httproot/.htaccess`. |
| **A03 Injection** | PASS — XSS-safe rendering verified; no SQL/shell/`include`-of-user-input vectors. |
| **A04 Insecure Design** | PASS — design documented; trust boundaries explicit. |
| **A05 Security Misconfiguration** | PASS — CSP, HSTS, X-Frame-Options, Referrer-Policy, X-Content-Type-Options, X-Robots-Tag all set via `httproot/.htaccess`. Banner suppression (M-7) deferred — host-side responsibility, low residual. |
| **A06 Vulnerable and Outdated Components** | PASS — minimal dependency footprint; verify host PHP/Apache versions are current. |
| **A07 Identification and Authentication Failures** | PARTIAL — single shared-secret model with no MFA, no lockout, no rotation tooling beyond manual. Acceptable given single-controller scope, **provided** the rate-limit + audit-log items are closed. |
| **A08 Software and Data Integrity Failures** | PASS — atomic writes, no auto-update, key-authenticated deploys. |
| **A09 Security Logging and Monitoring Failures** | PASS — `gh_fail()` writes a structured `error_log()` line on every silent-drop branch (`[hbwv api] drop ip=… reason=… action=… http=…`). Anomaly alerting via grep / fail2ban (L-2) is the next step but not blocking. |
| **A10 SSRF** | n/a. |

### OWASP API Top 10 (2023) — public-internet profile

| Category | Status |
|---|---|
| **API1 BOLA** | n/a. |
| **API2 Broken Authentication** | PARTIAL — see A07. |
| **API3 BOPLA** | n/a. |
| **API4 Unrestricted Resource Consumption** | PASS — `gh_rate_limit()` per-IP token bucket sheds bursts; 5 MiB cap and retention sweep already bound disk-fill. |
| **API5 Broken Function Level Authorization** | PASS — cross-method rejection in place. |
| **API6 Sensitive Business Flows** | n/a. |
| **API7 SSRF** | n/a. |
| **API8 Security Misconfiguration** | PASS — same as A05. |
| **API9 Improper Inventory Management** | PASS — small surface, fully documented. |
| **API10 Unsafe Consumption of APIs** | n/a. |

### Summary

The system's **architectural** security posture is solid — the controls that took thought to design (asymmetric auth, presence-driven UI, atomic writes, XSS-safe rendering, server-generated log filenames, silent-drop semantics) all carry over to the public-internet deployment unchanged. The work that remains is **configuration**: rate limit, audit logs, HSTS plus a few security-header `.htaccess` lines. HTTPS (Let's Encrypt) and the no-index policy (`robots.txt` + meta + header) are already in place.

All previously-blocking items have closed. The dashboard is fit for `pe1mew.nl`-class personal hosting and stays within the same defensive envelope the LAN deployment enjoyed. Remaining items are good-citizenship niceties (security.txt, host-side banner suppression, registrar/repo 2FA) and an optional dashboard-privacy decision.
