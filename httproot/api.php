<?php
require __DIR__ . '/config.php';
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
header('X-Robots-Tag: noindex, nofollow');
@header_remove('X-Powered-By');

// Polyfill for hosts running PHP < 8.1. array_is_list() is PHP 8.1+.
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool {
        if ($arr === []) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

function gh_fail(int $code, array $body): void {
    // Audit log on every silent-drop / error branch — the only signal the
    // operator gets, since the wire response is empty in production.
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '?';
    $action = $_GET['action'] ?? 'status';
    $reason = $body['error'] ?? 'unknown';
    $line   = "[hbwv api] drop ip={$ip} reason={$reason} action={$action} http={$code}";
    if (isset($body['detail'])) {
        $detail = str_replace(["\r", "\n"], ' ', (string) $body['detail']);
        $line  .= " detail={$detail}";
    }
    error_log($line);

    if (GH_DEBUG_RESPONSES) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($body);
    } else {
        http_response_code(204);
    }
    exit;
}

function gh_ok(array $body): void {
    if (GH_DEBUG_RESPONSES) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true] + $body);
    } else {
        http_response_code(204);
    }
    exit;
}

// Per-IP token-bucket rate limit. Returns true if the request gets a token,
// false if the bucket was empty. State persists in data/ratelimit.json with
// LOCK_EX serialising read-modify-write across concurrent requests. Stale
// entries (idle > 1 hour) are pruned on every call so the file does not grow
// without bound.
function gh_rate_limit(string $key, int $capacity, float $refill_per_sec): bool {
    if (!is_dir(GH_DATA_DIR)) @mkdir(GH_DATA_DIR, 0755, true);
    $path = GH_DATA_DIR . '/ratelimit.json';
    $fp = @fopen($path, 'c+');
    if (!$fp) return true;  // fail-open on filesystem error
    flock($fp, LOCK_EX);

    $now = microtime(true);
    $raw = stream_get_contents($fp);
    $state = $raw ? json_decode($raw, true) : [];
    if (!is_array($state)) $state = [];

    foreach ($state as $k => $e) {
        if (!is_array($e) || ($now - ($e['last_refill'] ?? 0)) > 3600) {
            unset($state[$k]);
        }
    }

    $entry  = $state[$key] ?? ['tokens' => (float) $capacity, 'last_refill' => $now];
    $elapsed = max(0.0, $now - (float) $entry['last_refill']);
    $entry['tokens']      = min((float) $capacity, (float) $entry['tokens'] + $elapsed * $refill_per_sec);
    $entry['last_refill'] = $now;

    $allow = $entry['tokens'] >= 1.0;
    if ($allow) $entry['tokens'] -= 1.0;
    $state[$key] = $entry;

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $allow;
}

$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!gh_rate_limit($client_ip, GH_RATE_LIMIT_BUCKET, GH_RATE_LIMIT_REFILL_PER_SEC)) {
    gh_fail(429, ['error' => 'rate_limited']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gh_fail(405, ['error' => 'method_not_allowed']);
}

if (($_SERVER['HTTP_SOURCEIDENTIFIER'] ?? '') !== GH_SECRET_TOKEN) {
    gh_fail(401, ['error' => 'unauthorized']);
}

$action = $_GET['action'] ?? 'status';

if ($action === 'status') {
    try {
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || (!empty($payload) && array_is_list($payload))) {
            throw new RuntimeException('payload must be a JSON object');
        }
    } catch (Throwable $e) {
        gh_fail(400, ['error' => 'bad_json', 'detail' => $e->getMessage()]);
    }

    $payload['received_at'] = time();

    if (!is_dir(GH_DATA_DIR)) {
        @mkdir(GH_DATA_DIR, 0755, true);
    }

    $tmp = GH_STATUS_FILE . '.tmp';
    file_put_contents($tmp, json_encode($payload), LOCK_EX);
    rename($tmp, GH_STATUS_FILE);

    gh_ok(['received_at' => $payload['received_at']]);
}

if ($action === 'log') {
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0 || $len > GH_LOG_MAX_BYTES) {
        gh_fail(413, ['error' => 'too_large_or_empty', 'bytes' => $len]);
    }

    if (!is_dir(GH_LOG_DIR)) {
        @mkdir(GH_LOG_DIR, 0755, true);
    }

    $body = file_get_contents('php://input');
    $name = date('Y-m-d_His') . '.log';
    $path = GH_LOG_DIR . '/' . $name;
    file_put_contents($path, $body, LOCK_EX);
    @chmod($path, 0644);

    $cutoff = time() - GH_LOG_RETENTION_DAYS * 86400;
    foreach (glob(GH_LOG_DIR . '/*.{log,txt}', GLOB_BRACE) as $f) {
        if (is_file($f) && filemtime($f) < $cutoff) {
            @unlink($f);
        }
    }

    gh_ok(['name' => $name, 'bytes' => $len]);
}

gh_fail(404, ['error' => 'unknown_action', 'action' => $action]);
