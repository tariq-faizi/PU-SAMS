<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/auth.php';
requireLogin();
requireRole([ROLE_TEACHER]);

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = BASE_URL.'/teacher/sessions.php';
if ($query !== '') {
    $target .= '?'.$query;
}
redirect($target);
