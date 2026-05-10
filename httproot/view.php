<?php
require __DIR__ . '/config.php';
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
header('X-Robots-Tag: noindex, nofollow');
@header_remove('X-Powered-By');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    if (GH_DEBUG_RESPONSES) {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'method_not_allowed']);
    } else {
        http_response_code(204);
    }
    exit;
}

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$action = $_GET['action'] ?? 'status';

if ($action === 'status') {
    if (!is_file(GH_STATUS_FILE)) {
        echo '{}';
        exit;
    }
    $json = file_get_contents(GH_STATUS_FILE);
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        echo '{}';
        exit;
    }
    $received = (int) ($payload['received_at'] ?? 0);
    $payload['age_seconds'] = $received > 0 ? max(0, time() - $received) : null;
    echo json_encode($payload);
    exit;
}

if ($action === 'logs') {
    $out = [];
    if (is_dir(GH_LOG_DIR)) {
        foreach (glob(GH_LOG_DIR . '/*.{log,txt}', GLOB_BRACE) as $f) {
            if (is_file($f)) {
                $out[] = [
                    'name'  => basename($f),
                    'size'  => filesize($f),
                    'mtime' => filemtime($f),
                ];
            }
        }
        usort($out, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
    }
    echo json_encode($out);
    exit;
}

echo '{}';
