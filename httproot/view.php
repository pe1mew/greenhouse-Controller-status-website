<?php
require __DIR__ . '/config.php';

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
        usort($out, fn($a, $b) => $b['mtime'] - $a['mtime']);
    }
    echo json_encode($out);
    exit;
}

echo '{}';
