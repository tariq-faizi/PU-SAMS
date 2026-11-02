<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
requireLogin();
$roleId = (int)($_SESSION['role_id'] ?? 0);
$target = BASE_URL.'/index.php';
if ($roleId === ROLE_ADMIN)              $target = BASE_URL.'/admin/attendance.php';
elseif (in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ATT_MGR], true)) $target = BASE_URL.'/manager/attendance.php';
elseif ($roleId === ROLE_TEACHER)        $target = BASE_URL.'/teacher/sessions.php';
elseif ($roleId === ROLE_STUDENT)        $target = BASE_URL.'/student/attendance.php';
flashSet('info', 'د حضور تاریخچه له سیسټم څخه لرې شوې ده — اوس مستقیم د حضور پاڼې ته لاړل.');
redirect($target);
