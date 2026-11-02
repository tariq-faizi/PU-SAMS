<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'unauthorized']));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    
    $raw = file_get_contents('php://input');
    if ($raw && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $body = json_decode($raw, true) ?: [];
        $token = $body['_csrf_token'] ?? $body[CSRF_TOKEN_NAME] ?? '';
    } else {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_POST['_csrf_token'] ?? '';
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => 'csrf_invalid']));
    }
}

try {
    $stmt = getDB()->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $count = $stmt->rowCount();
    auditLog('notifications_mark_read', 'notifications', null, "marked {$count} as read");
} catch (Throwable $e) {
    $count = 0;
}

if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'marked' => $count]);
    exit;
}

flashSet('success', 'ټولې خبرتیاوې لوستل شوي په نښه شوې.');
header('Location: ' . BASE_URL . '/notifications/');
exit;
