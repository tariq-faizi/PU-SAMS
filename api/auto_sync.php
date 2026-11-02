<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

if (function_exists('pusamsRateLimit') && !pusamsRateLimit('auto_sync', 30, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'message' => 'Too many requests']);
    exit;
}

$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$raw   = file_get_contents('php://input');
$data  = [];
if (strpos($contentType, 'application/json') !== false) {
    $decoded = json_decode($raw ?: '{}', true);
    if (is_array($decoded)) $data = $decoded;
}

$token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $data[CSRF_TOKEN_NAME] ?? $_POST[CSRF_TOKEN_NAME] ?? '');
if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'CSRF token invalid']);
    exit;
}

try {
    $result = function_exists('pusamsAutoSync') ? pusamsAutoSync(true) : ['synced' => false, 'dates' => []];

    $dataVersion = function_exists('pusamsComputeDataVersion') ? pusamsComputeDataVersion() : '0';
    echo json_encode([
        'ok'           => true,
        'synced'       => !empty($result['synced']),
        'dates'        => $result['dates'] ?? [],
        'data_version' => $dataVersion,
        'server_time'  => date('c'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Sync failed']);
}
