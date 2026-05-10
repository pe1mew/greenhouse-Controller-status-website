# Security Assessment

| | |
|---|---|
| Document | Security Assessment of the implementation as deployed to the test server |
| Audience | Operator + reviewer evaluating whether the system is fit for purpose at its current trust level (LAN test deployment), and what would need to change before any wider deployment |
| Scope | The PHP website (`httproot/`), the deployment pipeline (`tools/`), the development mock (`mock/`), and the operator workflow that ties them together. The greenhouse controller firmware itself is out of scope. |
| Methodology | OWASP Web Top 10 (2021) and OWASP API Top 10 (2023) used as the reference frameworks; specific findings cross-referenced to FR/TR identifiers in the design documents and to the live test results in `test/`. |
| Run date | 2026-05-10 |
| Verdict | **Fit for LAN test deployment**, with **three blocking items** before any deployment beyond a trusted LAN: enable HTTPS, enable `AllowOverride All` on the Apache instance, and add a basic per-IP rate limit on the controller-write path. |

## Table of contents

1. [Scope and assumptions](#1-scope-and-assumptions)
2. [Threat model](#2-threat-model)
3. [Attack surface inventory](#3-attack-surface-inventory)
4. [Findings by category](#4-findings-by-category)
   - 4.1 [Authentication and access control](#41-authentication-and-access-control)
   - 4.2 [Cryptographic posture](#42-cryptographic-posture)
   - 4.3 [Injection (XSS, SQL, shell, header)](#43-injection-xss-sql-shell-header)
   - 4.4 [Resource consumption and DoS](#44-resource-consumption-and-dos)
   - 4.5 [Misconfiguration](#45-misconfiguration)
   - 4.6 [Information disclosure](#46-information-disclosure)
   - 4.7 [Software integrity and supply chain](#47-software-integrity-and-supply-chain)
   - 4.8 [Logging, monitoring, and incident response](#48-logging-monitoring-and-incident-response)
   - 4.9 [Operator workflow and secret hygiene](#49-operator-workflow-and-secret-hygiene)
5. [Risk matrix](#5-risk-matrix)
6. [Outstanding items](#6-outstanding-items)
7. [Production-deployment checklist](#7-production-deployment-checklist)
8. [Appendix — OWASP Top 10 mapping](#appendix--owasp-top-10-mapping)

---

## 1. Scope and assumptions

This assessment covers the system as it stands on **2026-05-10**, deployed to:

- `Shuttle2` (`192.168.20.232`) on a private LAN segment, plain HTTP, Apache 2.4.58 on Ubuntu, document root `/var/www/html/controller/`.
- Driven by the Flask mock controller (`mock/`) running on the operator's workstation.
- The shared secret has been rotated from the in-repo placeholder to a 32-character random value held in `httproot/config.php` (gitignored) and `.deploy.env` (gitignored).

**In scope.** The PHP application code, the JavaScript on the dashboard, the Apache hardening (`.htaccess` files), the deploy pipeline (PowerShell + `scp`/`ssh`), and the operator's secret-handling workflow.

**Explicitly out of scope.** The greenhouse controller firmware (assessed separately when it lands), the LAN's perimeter security, the upstream registry hygiene of PyPI / Apache / PHP itself, and any post-compromise blast radius beyond the four targets enumerated in §3.

**Assumptions taken as given.**

- The LAN is operator-controlled and not internet-exposed at this stage.
- The operator's workstation is trusted (no need to defend the local `.deploy.env` from the local user).
- Apache is patched via the distro's normal update channel.
- The website does not handle PII, payment data, or anything beyond greenhouse telemetry. Privacy impact is therefore minimal regardless of disclosure.

---

## 2. Threat model

### 2.1 Assets

| Asset | What it is | Why it matters |
|---|---|---|
| `GH_SECRET_TOKEN` | Shared secret authenticating controller-to-server pushes | Whoever holds it can forge status updates and upload arbitrary log content. |
| `data/status.json` | Latest committed greenhouse state | Integrity matters (operator decisions could be made from it); confidentiality is irrelevant — same content is served via `view.php`. |
| Uploaded log files | Daily event-log dumps from the controller | Operator may use them for diagnostic decisions. Bulk size matters for disk-fill DoS. |
| Apache execution context (www-data) | Underlying PHP runtime | RCE here would let an attacker run arbitrary code on the host. |
| Operator's SSH key | Drives all deploys | Compromise gives full control of the deployed code. |

### 2.2 Adversaries

| Actor | Capability | Goal | Motivation in this context |
|---|---|---|---|
| **Random internet scanner** (assumed not currently reachable) | Passive HTTP probing, banner-grabbing, generic exploits | Foothold or defacement | Low — no money in greenhouse status. Treat as background noise. |
| **LAN-local opportunist** | Pcap, ARP spoof, browser inspection | Read or forge status, exfiltrate the secret | Low — same network as the operator; mostly hypothetical given the user base. |
| **Targeted attacker** | All of the above plus social engineering, supply-chain access | Sabotage greenhouse operations | Plausible in agriculture if the greenhouse is commercial. Risk profile may rise with deployment scale. |
| **Insider / operator** | Full server access, code commit rights | Mistake more likely than malice — accidentally commit a secret, weaken a deploy step | Most realistic recurring threat. |
| **Compromised browser** (XSS via injected payload) | Anything the dashboard's JS can do | Pivot from a forged push to operator-side compromise | Negligible after Phase 10's XSS-safe rendering verification. |

### 2.3 Trust boundaries

```
┌──────────────┐  shared      ┌────────────────────┐  HTTPS-not-yet  ┌──────────┐
│  Controller  ├─secret push─▶│   api.php (write)  │◀──────unauth────┤ Browser  │
└──────────────┘              │   view.php (read)  │                 └──────────┘
                              │   index.php / log/ │
                              └─────────┬──────────┘
                                        │
                                        ▼
                            ┌──────────────────────┐
                            │ Apache (www-data)    │
                            │ data/, log/logs/     │
                            └──────────────────────┘
                                        ▲
                                        │ scp + ssh (key auth)
                                        │
                            ┌──────────────────────┐
                            │  Operator workstation│
                            │  config.php (real)   │
                            │  .deploy.env (real)  │
                            └──────────────────────┘
```

The two strong boundaries: the **shared secret on POSTs** (controller↔server) and the **SSH key on deploys** (operator↔server). The browser-server boundary is intentionally weak by design because the dashboard is public on the LAN.

---

## 3. Attack surface inventory

| Surface | Method(s) | Auth | Notes |
|---|---|---|---|
| `POST /api.php` | POST JSON body | shared secret | Status push. Atomic write to `data/status.json`. |
| `POST /api.php?action=log` | POST raw body | shared secret | Log upload. 5 MiB cap. Server-generated filename. Triggers retention sweep on success. |
| `GET /view.php` | GET | none | Dashboard's polling target. Returns latest status JSON. |
| `GET /view.php?action=logs` | GET | none | Log file listing as JSON. |
| `GET /index.php` | GET | none | Dashboard HTML shell. |
| `GET /log/` | GET | none | Standalone logs page (server-rendered). |
| `GET /log/logs/<file>` | GET | none | Apache-served raw log download. |
| `GET /logs/` | GET | none | 301 redirect to `/log/`. |
| `GET /log/logs/` | GET | none | 301 redirect to `/log/` (suppresses dir listing). |
| All other Apache-served paths | GET | none | `data/`, `config.php` direct GETs. See §4.5/4.6 for outcomes. |

The HTTP API has no `PUT`, `DELETE`, or other verbs — `api.php` and `view.php` reject anything that isn't their respective POST/GET.

---

## 4. Findings by category

Each finding is rated as **Risk = Likelihood × Impact** on the LAN-test profile. A second column shows the rating if the deployment moved beyond LAN to the public internet — this matters because several risks change category sharply.

### 4.1 Authentication and access control

| ID | Finding | Mitigation in place | Residual risk (LAN) | Residual risk (Internet) |
|---|---|---|---|---|
| A-1 | The controller-write path is secured by a single static shared secret in an HTTP header (`sourceidentifier`). No HMAC, no nonce, no replay protection. | Rotation procedure documented; deploy script blocks shipping the placeholder secret; secret is 32 chars from a CSPRNG. | Low | **High** — interception over plain HTTP is trivial; replay is unbounded. |
| A-2 | The browser-read path (`view.php`, `log/index.php`) is intentionally unauthenticated; this is by design (dashboard is meant to be browseable on the LAN). | Documented as a design decision in functional-design.md § 7. | Low | Medium — would warrant Basic Auth or IP allowlist before public exposure. |
| A-3 | Cross-method requests (GET on `api.php`, POST on `view.php`) are silently rejected with HTTP 204 in default mode, not loudly with 405. | Behaviour documented; debug-mode override available; verified in TR-01 / TR-10. | Low | Low — silent rejection is the desired behaviour. |
| A-4 | No per-controller identity. If the firmware fleet ever grew beyond one device, all controllers would share a single secret. | Single-controller assumption is explicit in functional-design.md § 1. | None for now | Would block multi-controller deployment. |
| A-5 | No account lockout, no rate limit, no captcha on auth failures. | Silent-drop behaviour is the only deterrent. | Low (LAN, single attacker) | **High** — secret is 32 chars random ≈ 187 bits of entropy, brute force is infeasible, but flooding the endpoint to extract timing or to fill logs is unmitigated. |

**Verdict.** The shared-secret model is an accepted weakness for a one-controller, LAN-only deployment. It does not scale to public exposure or to multi-tenant scenarios without significant rework — at minimum HTTPS plus a rate limit, more ambitiously per-controller signing keys.

### 4.2 Cryptographic posture

| ID | Finding | Mitigation in place | Residual risk (LAN) | Residual risk (Internet) |
|---|---|---|---|---|
| C-1 | All HTTP traffic is plain HTTP. The shared secret is therefore plaintext-on-the-wire. | Documented as deferred in implementation-plan.md § 7.1; flagged in `tools/README.md`. | Low (LAN) | **Critical** — the secret is exposed to anyone on path. |
| C-2 | No certificate pinning on the controller side. (Out of scope for this doc; relevant to firmware.) | n/a | n/a | Worth flagging for the firmware engineer. |
| C-3 | The shared secret is stored in plaintext on the server filesystem (`httproot/config.php`) and on the operator workstation (`.deploy.env`). | Both files are gitignored. Server-side file is `0644` owned by `remko:www-data`; only those two can read. | Low | Low — same posture, just more important to actually secure those filesystems. |

**Verdict.** Plain HTTP is the defining cryptographic risk. It is acceptable on a private LAN where the threat model excludes wire-tapping; it is **unacceptable** anywhere else. Move to HTTPS (Let's Encrypt or self-signed) before any further-reaching deployment.

### 4.3 Injection (XSS, SQL, shell, header)

| ID | Finding | Mitigation in place | Residual risk |
|---|---|---|---|
| I-1 | XSS via payload-injected HTML / scripts. | All payload-derived strings are written via `textContent` (TR-26). Code review: zero `innerHTML`/`outerHTML`/`document.write`/`insertAdjacentHTML` usages. Probe in Phase 10 with `<img onerror>`, `<script>`, and `<svg onload>` confirms literal-text rendering. | None observed. |
| I-2 | HTML injection in the standalone logs page. | Filenames and timestamps pass through `htmlspecialchars($val, ENT_QUOTES)` and URLs through `rawurlencode`. Filenames are server-generated and conform to a strict character set; even so, escaping is applied. | None. |
| I-3 | SQL injection. | Not applicable — no SQL anywhere in the system. Storage is flat files. | n/a |
| I-4 | OS command injection / shell exec. | Not applicable — no `system`, `exec`, `passthru`, `shell_exec`, `popen`, `proc_open`, or backticks anywhere. | n/a |
| I-5 | PHP `include` of untrusted paths. | Two `require __DIR__ . '/config.php'` calls, both with constants. No user input flows to `require`/`include`/`include_once`. | None. |
| I-6 | PHP `unserialize`. | Not used. JSON is the only deserialiser, via `json_decode(..., true, 64, JSON_THROW_ON_ERROR)`. Returns assoc arrays only, no object instantiation. | None. |
| I-7 | HTTP response splitting / header injection. | Only static strings are passed to `header()`. The `Location` header in the redirect shims uses a constant relative path. | None. |
| I-8 | Path traversal in download URL. | Apache normalises `..`. The `log/logs/.htaccess` filename whitelist would close the rest, but is ignored on the test server (see M-1). The actual upload path is server-generated, so an attacker cannot place files with traversal-friendly names. | Low. |

**Verdict.** Injection surfaces are well-handled. The XSS-safe rendering choice (`textContent` only) is the load-bearing control here and was explicitly tested.

### 4.4 Resource consumption and DoS

| ID | Finding | Mitigation in place | Residual risk |
|---|---|---|---|
| D-1 | Disk-fill via repeated authenticated log uploads. With the secret in hand, an attacker can upload up to `5 MiB × N` files before the next retention sweep takes effect. The sweep runs only on upload-success, so the attacker can spike usage but not infinitely; steady state is bounded by `GH_LOG_RETENTION_DAYS × 5 MiB ≈ 450 MiB` once they stop. | 5 MiB cap per upload; 90-day retention prunes silently on next upload. | Low (requires secret). |
| D-2 | Memory exhaustion on a forged oversized POST (status path). | No explicit cap in code — relies on PHP's `post_max_size` (typically 8 MiB). `php://input` reads the entire body before parsing. | Low — bounded by PHP runtime. |
| D-3 | Connection / request flooding to either endpoint. | None. | Low (LAN, no observed flooding); **Medium** on internet — would need fail2ban or Apache `mod_ratelimit`. |
| D-4 | The dashboard polls `view.php` every 5 s by default, multiplied by every open browser tab. | Nothing — relies on a small user base. | Low at expected scale. |
| D-5 | Status file lock contention if multiple writers race. | `file_put_contents(..., LOCK_EX)` plus `rename()`. PHP's `LOCK_EX` is advisory, so a misbehaving second writer could overlap, but `rename()` is atomic on the filesystem so no partial reads result. | Low. |

**Verdict.** Single-controller-scale DoS is well-bounded by the 5 MiB cap and retention sweep. A network-level rate limit would be the right next control before public exposure.

### 4.5 Misconfiguration

| ID | Finding | Mitigation in place | Residual risk |
|---|---|---|---|
| M-1 | Apache `AllowOverride None` on the test server silently disables every `.htaccess` file shipped with this project. Concrete impact: `data/.htaccess` (`Require all denied`) is ignored — `data/status.json` is publicly fetchable; `log/logs/.htaccess` (filename whitelist + `Options -Indexes`) is ignored — directory listing was previously exposed (mitigated post-hoc with a 301-redirect `index.php` in that directory); files of any extension placed in `log/logs/` would be served. | Documented in `tools/README.md` "Known limitations". One-line fix `sudo sed -i 's\|AllowOverride None\|AllowOverride All\|' /etc/apache2/apache2.conf` (still requires sudo). Compensating control: log filenames are server-generated and conform to the safe character set even without the whitelist. | **Medium**, only because the operator deferred the fix knowingly. The compensating controls hold for the current threat model. |
| M-2 | The deployed `httproot/` is on the same Apache instance as the rest of the host's web content. Other vhosts may have different exposure profiles. | Out of scope. | Operator-side. |
| M-3 | `php.ini` settings (`expose_php`, `display_errors`, `error_log` location) are not pinned by the project. | Apache + PHP defaults on Ubuntu 24.04 do the right thing (`display_errors` off, `error_log` to `/var/log/apache2/error.log`). | Low. |
| M-4 | Dotfiles in `httproot/` (e.g. `data/.htaccess`, `log/logs/.htaccess`) are deployed correctly but with mode 0644, not 0600. They contain no secrets so this is fine. | n/a | None. |
| M-5 | The `httproot/config_template.php` is also deployed (it ends up in `/var/www/html/controller/config_template.php`). PHP processes it to no output. It contains no real secret — but if it ever did, this would be a leak. | The template uses a deliberate fake placeholder (`REPLACE_ME_BEFORE_DEPLOY`); no real secret can land there. | None. |
| M-6 | No `Content-Security-Policy` header is set on the dashboard or any page. | Compensating control: `textContent`-only writes mean no inline-script injection vectors. | Low — would close a defence-in-depth gap. |
| M-7 | No `X-Frame-Options` / `Referrer-Policy` / `Strict-Transport-Security` headers. | Apache defaults. | Low (LAN). Worth setting before public exposure. |

**Verdict.** The largest single security improvement available right now is enabling `AllowOverride All`. It would close M-1 and silently re-engage the filename whitelist and directory-listing block. The operator deferred this knowingly.

### 4.6 Information disclosure

| ID | Finding | Risk |
|---|---|---|
| ID-1 | `data/status.json` is publicly fetchable on the test server (M-1). The same content is already served by `view.php` to anyone, so this is not an incremental disclosure. The file does carry an internal `received_at` field which is server-time-of-last-write — leaks the server's clock to within a second, which is irrelevant. | None incremental. |
| ID-2 | `httproot/config.php` is publicly fetchable but PHP processes it to a 0-byte body. Verified by Phase 10 probe. The secret is never on the wire from a direct GET. | None. |
| ID-3 | Apache's default banner advertises `Server: Apache/2.4.58 (Ubuntu)`. This reveals the major+minor version and distro. Useful for an attacker fingerprinting CVEs. | Low. To fix: `ServerTokens Prod` + `ServerSignature Off`. |
| ID-4 | The dashboard exposes WiFi-network metadata (`system.wifi_ip`, `system.wifi_rssi_dbm`). On a LAN-only deployment this is harmless; on a public deployment it would leak the controller's local IP. | Low (LAN); Medium (public). |
| ID-5 | The `view.php?action=logs` endpoint discloses log filenames (timestamped) and sizes. This is by design — the standalone `/log/` page consumes the same data. | None. |
| ID-6 | PHP error messages, when `display_errors=off` (the default), do not reach the client. PHP's `error_log` (server-side) may capture stack traces from misconfigurations. | None on the wire. |

**Verdict.** Disclosure surface is intentional and small. Tighten Apache banner before any non-LAN deploy.

### 4.7 Software integrity and supply chain

| ID | Finding | Mitigation | Residual risk |
|---|---|---|---|
| S-1 | The PHP application uses no third-party dependencies. Only the PHP standard library and Apache. | n/a | None. |
| S-2 | The Flask mock has two PyPI dependencies: `flask>=3.0`, `requests>=2.31`. | Pinned by minimum version in `mock/requirements.txt`. The mock does not ship to production; supply-chain risk is bounded to the operator's workstation. | Low. |
| S-3 | The deployed assets (PHP, JS, CSS) are deployed by `scp` over a key-authenticated SSH connection. No HTTPS-bootstrap risk. | n/a | None. |
| S-4 | Frontend assets are all same-origin. No CDN, no SRI needed. | n/a | None. |
| S-5 | OS package updates (Apache, PHP, OpenSSL, OpenSSH) are the operator's responsibility via `apt`. | Out of scope; mention it for the operator. | Operator-side. |
| S-6 | Repository integrity. Anyone with push access to GitHub `pe1mew/-greenhouse-Controller-status-website` can ship a malicious commit that the next deploy will pick up. | The deploy script doesn't `git pull` automatically — the operator clones / pulls manually before deploying — so a poisoned commit only lands if the operator chooses to. | Low. Could harden with branch protection + signed commits if scaled. |

**Verdict.** Minimal-dependency posture buys the project a clean supply-chain story.

### 4.8 Logging, monitoring, and incident response

| ID | Finding | Risk |
|---|---|---|
| L-1 | The PHP application does not write security-relevant audit logs. Apache's access log records all requests including the request path; the `sourceidentifier` header value is **not** logged by default — which is good — but neither are auth failures. | Medium — a successful or failed forge attempt cannot be reconstructed after the fact beyond IP and timing. |
| L-2 | No anomaly alerting. Repeated wrong-secret pushes look identical to legitimate ones at the network layer and trigger no alarm. | Low (LAN); Medium (internet). |
| L-3 | No rotation of Apache access logs is configured by the project (relies on distro defaults — `logrotate` does this for `/var/log/apache2/`). | Operator-side. |
| L-4 | The `data/status.json` file has no version history. After a successful forge, the legitimate state would be restored within one push (≤ 30 s default), and the forged state vanishes — but so does the evidence. | Low. |

**Verdict.** Logging is thin but appropriate for the current scale. If the deployment graduates from "test server on the LAN" to "production at a commercial greenhouse", consider adding:
- A `error_log()` line on every silent-drop branch in `api.php`, capturing source IP, timestamp, and which check failed.
- A daily ringed copy of `data/status.json` for forensic timeline reconstruction.

### 4.9 Operator workflow and secret hygiene

| ID | Finding | Mitigation | Residual risk |
|---|---|---|---|
| O-1 | The shared secret is stored in plaintext on two machines (server + operator workstation). | Both files are gitignored. Server file is owner-readable + group-readable (www-data). Operator file is the operator's own machine. | Low — same posture as any non-vault secret. |
| O-2 | The deploy script cannot easily detect if `httproot/config.php` has the *original* hardcoded placeholder. It blocks only the `REPLACE_ME_BEFORE_DEPLOY` template marker; the older `dev-1234567890abcdef-please-rotate-in-prod` value triggers a yellow warning but the deploy proceeds. | Documented; warning visible in deploy output. | Low — the operator did rotate to a real CSPRNG value; the warning serves as a check-the-rotation reminder. |
| O-3 | A password (`7321jh36`) was shared in chat history early in this session before SSH key auth was confirmed. The password may or may not be valid on the server, but it lives in operator-side conversation logs. | Recommended in-session that the operator rotate it server-side regardless. | Operator-side. |
| O-4 | `git status` could in theory still see a freshly-committed `.deploy.env` if the gitignore were ever damaged. The deploy script has no pre-commit hook to backstop this. | The current `.gitignore` line `.deploy.env` is on line 2; verified with `git check-ignore -v`. | Low. |
| O-5 | Mock controller secret (`MOCK_SECRET` in `.deploy.env`) is the same value as the server's `GH_SECRET_TOKEN`. Rotation must happen in two places. | Documented in mock/README.md and config_template.php. | Low. |
| O-6 | The deploy uses `ssh` against an SSH-config alias (`Shuttle2`) which itself names an `IdentityFile`. The operator's private key is the highest-impact secret in the chain. | Standard SSH key hygiene applies. | Operator-side. |

**Verdict.** The operator-side workflow has clear handles (gitignore, pre-flight check, template marker) for the most likely failure modes (committing a secret, deploying the placeholder). It is not vault-grade but matches the deployment scale.

---

## 5. Risk matrix

Each finding is plotted on Likelihood × Impact for the **LAN-test profile** (the current deployment) and for a **public-internet profile** (illustrative). Values shift in the public-internet column primarily because plain HTTP and absence of rate limiting become much more consequential.

| ID | Description | LAN risk | Public-internet risk |
|---|---|---|---|
| C-1 | Plain HTTP exposes shared secret | Low | **Critical** |
| A-5 | No rate limit / lockout | Low | **High** |
| A-1 | Static shared-secret auth | Low | **High** |
| M-1 | `AllowOverride None` ignores `.htaccess` | Medium (deferred) | Medium |
| ID-3 | Apache banner reveals version | Low | Low–Medium |
| L-1 | No security-relevant audit logging | Low | Medium |
| D-1 | Disk-fill via authenticated log uploads | Low | Low (cap holds) |
| O-2 | Deploy doesn't block older `dev-1234…` placeholder hard | Low | Medium |
| ID-4 | WiFi-network metadata in dashboard | Low | Medium |
| I-1 | XSS | None | None |
| I-3 | SQL injection | n/a | n/a |
| I-4 | OS command injection | n/a | n/a |
| I-6 | Insecure deserialisation | n/a | n/a |

The shape of this matrix shows the system in its current state is acceptably safe for its intended LAN-only role. The same code shipped to an internet-facing host without further hardening would have **three to four critical/high items** at once.

---

## 6. Outstanding items

These are the open security items that the project knows about but has not closed. Numbered for stable reference.

### 6.1 BLOCKING for non-LAN deployment

1. **HTTPS not enabled.** Required to keep the shared secret confidential on the wire. (C-1.) Closure: configure Let's Encrypt or pin a self-signed certificate; redirect `:80` → `:443`; update controller-side base URL.
2. **`AllowOverride None` still active.** Activates the existing `.htaccess` files that the project ships. (M-1.) Closure: one-line `sed` + `apache2ctl configtest && systemctl reload apache2` documented in `tools/README.md`.
3. **No rate limit on the controller-write path.** A leaked secret turns into an unbounded forge stream until manually noticed. (A-5.) Closure: `mod_ratelimit` on `api.php` or a token-bucket in PHP keyed by `REMOTE_ADDR`.

### 6.2 NICE-TO-HAVE before non-LAN deployment

4. **Banner suppression.** `ServerTokens Prod`, `ServerSignature Off`. Cheap; no compatibility risk. (ID-3.)
5. **Hardening response headers.** `Content-Security-Policy`, `X-Frame-Options`, `Referrer-Policy`, `Strict-Transport-Security`. The CSP is the most useful one — even with `textContent`-only rendering, CSP is defence-in-depth. (M-6, M-7.)
6. **Audit logging on the silent-drop branches in `api.php`.** A single `error_log()` line per branch with `$_SERVER['REMOTE_ADDR']`, the failed-check reason, and a timestamp would massively improve incident reconstruction. (L-1.)
7. **Block the older placeholder hard.** Promote the deploy-script warning for `dev-1234…` into an outright refusal. (O-2.)

### 6.3 ACCEPTED for the foreseeable future

8. **Single shared secret.** Adequate for a one-controller deployment. Rework only if the fleet grows.
9. **Public read path.** The dashboard is meant to be browseable. Adding HTTP Basic Auth to `view.php`+`log/` would close this if the operator ever wants the dashboard private.
10. **No backups of `data/status.json` or log files.** Status is current-state-only by design; logs have a 90-day retention which is itself the most-recent-N policy.

---

## 7. Production-deployment checklist

Before flipping the website from "LAN test" to anything more public, walk this list end to end. Each item maps back to a finding above.

- [ ] **Rotate the shared secret** to a fresh CSPRNG-generated 32+ char string. Update `httproot/config.php` and `MOCK_SECRET` in `.deploy.env`. (A-1, O-2.)
- [ ] **Provision a TLS certificate** (Let's Encrypt or internal CA). Configure Apache `<VirtualHost *:443>`, redirect 80→443, set `Strict-Transport-Security`. (C-1.)
- [ ] **Enable `AllowOverride All`** on the project's `<Directory>` block. Confirm `.htaccess` files take effect by re-running TR-17, TR-18, TR-19 from `test/ts-requirements.md`. (M-1.)
- [ ] **Add a rate limit** on `api.php` POST: `mod_ratelimit` or a small token-bucket in PHP keyed by `REMOTE_ADDR`. Verify a 30-req-per-second flood from one IP gets shed. (A-5.)
- [ ] **Suppress Apache banner**: `ServerTokens Prod`, `ServerSignature Off`. (ID-3.)
- [ ] **Set CSP and friends**: `Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'` (the inline `<script>` for `GH_CFG` requires `'unsafe-inline'` unless refactored to a separate file with a nonce); `X-Frame-Options: DENY`; `Referrer-Policy: same-origin`. (M-6, M-7.)
- [ ] **Add silent-drop logging** in `api.php`: each failed branch writes one line to `error_log` with IP + reason + timestamp. (L-1.)
- [ ] **Decide on dashboard privacy.** If the dashboard should not be world-readable, add HTTP Basic Auth or an IP allowlist on `view.php`, `index.php`, and `log/index.php`. (A-2.)
- [ ] **Verify `php.ini`** has `display_errors = Off`, `expose_php = Off`. (M-3.)
- [ ] **Ensure firewall** restricts inbound to ports 80/443 on the public interface only. Operator-side; not part of the project but the deployment depends on it.
- [ ] **Re-run the FD and TS test reports** end-to-end after the above changes. Replace the `⚠ DEFERRED` rows with `✅ PASS`.

---

## Appendix — OWASP Top 10 mapping

### OWASP Web Top 10 (2021)

| Category | Status |
|---|---|
| **A01 Broken Access Control** | PASS for the controller-write path (secret-gated). PASS for the read path (intentionally public, documented). |
| **A02 Cryptographic Failures** | DEFERRED — plain HTTP. No data-at-rest encryption (none needed; nothing sensitive stored beyond the secret in `config.php`). |
| **A03 Injection** | PASS — XSS-safe rendering verified; no SQL/shell/include-of-user-input vectors exist; JSON-only deserialisation. |
| **A04 Insecure Design** | PASS — design documents reviewed; trust boundaries explicit; presence-driven UI is a feature, not a bug. |
| **A05 Security Misconfiguration** | PARTIAL — `AllowOverride None` deferred; banner not suppressed; no security headers. All bounded by the LAN-only profile. |
| **A06 Vulnerable and Outdated Components** | PASS — minimal third-party dependency footprint (zero on the server, two pinned-by-min on the dev-only mock). |
| **A07 Identification and Authentication Failures** | PARTIAL — single static shared secret with no MFA, no lockout, no rotation tooling. Acceptable for the scale. |
| **A08 Software and Data Integrity Failures** | PASS — atomic-write recipe; no auto-update; deploys are key-authenticated. |
| **A09 Security Logging and Monitoring Failures** | PARTIAL — Apache access log only; no security-relevant audit. |
| **A10 SSRF** | n/a — server makes no outbound HTTP requests. |

### OWASP API Top 10 (2023)

| Category | Status |
|---|---|
| **API1 Broken Object Level Authorization** | n/a — no object-level authz model. |
| **API2 Broken Authentication** | PARTIAL — shared-secret weakness as above. |
| **API3 Broken Object Property Level Authorization** | n/a. |
| **API4 Unrestricted Resource Consumption** | PARTIAL — 5 MiB cap and retention sweep present; no rate limit. |
| **API5 Broken Function Level Authorization** | PASS — `api.php` rejects GET; `view.php` rejects POST; cross-method access blocked at the entrypoint. |
| **API6 Unrestricted Access to Sensitive Business Flows** | n/a. |
| **API7 SSRF** | n/a. |
| **API8 Security Misconfiguration** | PARTIAL — same `AllowOverride None` story. |
| **API9 Improper Inventory Management** | PASS — small surface, two endpoints, fully documented. |
| **API10 Unsafe Consumption of APIs** | n/a — server does not consume external APIs. |

### Summary

The system is **a small, well-bounded attack surface with a clear, single-purpose trust model.** The dominant risk on a non-LAN deployment is plaintext HTTP (cryptographic failure). The next-biggest is the absence of a rate limit. The misconfiguration items are all known and have one-line fixes. The injection, supply-chain, and integrity stories are clean.

The verdict is **fit for current LAN-test deployment** and the path to non-LAN deployment is short, well-defined, and listed in §7.
