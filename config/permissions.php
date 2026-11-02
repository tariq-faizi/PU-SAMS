<?php

require_once __DIR__ . '/database.php';

function userHasPermission(string $permission, int $roleId = 0): bool {
    if ($roleId === 0) {
        $roleId = $_SESSION['role_id'] ?? 0;
    }
    if ($roleId === ROLE_ADMIN) return true; 

    static $cache = [];
    $key = "{$roleId}:{$permission}";
    if (isset($cache[$key])) return $cache[$key];

    $db = getDB();
    $stmt = $db->prepare("SELECT `granted` FROM `permissions` WHERE `role_id`=? AND `permission`=?");
    $stmt->execute([$roleId, $permission]);
    $row = $stmt->fetch();
    $cache[$key] = $row && $row['granted'] == 1;
    return $cache[$key];
}

function requirePermission(string $permission): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    if (!userHasPermission($permission)) {
        http_response_code(403);
        require BASE_PATH . '/includes/403.php';
        exit;
    }
}

function requireRole(array $roles): void {
    $roleId = $_SESSION['role_id'] ?? 0;
    if (!in_array($roleId, $roles)) {
        http_response_code(403);
        require BASE_PATH . '/includes/403.php';
        exit;
    }
}

function currentUserDeptId(): ?int {
    return $_SESSION['department_id'] ?? null;
}

function isDeptScoped(): bool {
    return in_array($_SESSION['role_id'] ?? 0, [ROLE_DEPT_HEAD, ROLE_TEACHER, ROLE_STUDENT]);
}
