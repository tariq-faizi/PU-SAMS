<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_once __DIR__.'/includes/lang.php';
requireLogin();
header('Location: '.roleHomeUrl((int)($_SESSION['role_id'] ?? 0)));
exit;
