<?php
require __DIR__ . '/config.php';

function gh_fail(int $code, array $body): void {
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
