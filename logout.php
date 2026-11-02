<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/helpers.php';
require_once __DIR__.'/includes/auth.php';
if (isLoggedIn()) {
    auditLog('logout', 'auth', $_SESSION['user_id'] ?? null, 'User logged out');
    destroySession();
}
header('Location: '.BASE_URL.'/login.php');
exit;
