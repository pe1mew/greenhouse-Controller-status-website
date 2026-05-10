<?php
/*
 * ─── FIRST-TIME SETUP ────────────────────────────────────────────────────────
 *
 * This file is a TEMPLATE. The real config (httproot/config.php) is gitignored
 * so the secret never leaves your machine.
 *
 * On a fresh checkout:
 *
 *   1. Copy this file to httproot/config.php:
 *        cp httproot/config_template.php httproot/config.php
 *
 *   2. Replace GH_SECRET_TOKEN below with a long random string. Generate one,
 *      e.g. on Linux/macOS:
 *        openssl rand -hex 24
 *      ...or on Windows / PowerShell:
 *        -join ((48..57+65..90+97..122) | Get-Random -Count 32 | %{[char]$_})
 *
 *   3. Set the same secret on the controller side:
 *      - For the Flask mock: add MOCK_SECRET=<value> to .deploy.env, OR
 *      - For the real ESP32: rebuild the firmware with the matching constant.
 *
 *   4. Deploy normally with tools/deploy.ps1.
 *
 * If the secret here and the secret on the sender side ever drift, every push
 * is silently dropped (HTTP 204) and the dashboard stays empty.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('GH_SECRET_TOKEN', 'REPLACE_ME_BEFORE_DEPLOY'); // ≥16 chars, random
define('GH_DEBUG_RESPONSES', false);

define('GH_DATA_DIR',     __DIR__ . '/data');
define('GH_STATUS_FILE',  GH_DATA_DIR . '/status.json');

define('GH_LOG_DIR',            __DIR__ . '/log/logs');
define('GH_LOG_RETENTION_DAYS', 90);
define('GH_LOG_MAX_BYTES',      5 * 1024 * 1024);
define('GH_LOG_ALLOWED_EXT',    ['log', 'txt']);

define('GH_POLL_INTERVAL_MS',   5000);
define('GH_DEFAULT_INTERVAL_S', 30);

// Per-IP token-bucket rate limit on api.php (POST endpoints only).
// BUCKET = burst capacity, REFILL_PER_SEC = sustained rate.
// 60 / 0.2 ≈ 12 req/min sustained, 60 burst — comfortably above the legit
// controller's push rate, well below what an attacker would need to flood.
define('GH_RATE_LIMIT_BUCKET',         60);
define('GH_RATE_LIMIT_REFILL_PER_SEC', 0.2);

define('GH_WINDOW_NAMES', [
    'M1' => 'South roof',
    'M2' => 'North roof',
    'M3' => 'North wall',
]);
