<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$period = $_GET['period'] ?? 'day';
$roleId = (int)($_SESSION['role_id'] ?? 0);
$uid    = (int)($_SESSION['user_id'] ?? 0);
$myFaculty = (int)($_SESSION['faculty_id'] ?? 0);

$scope = [];
if ($roleId === ROLE_ADMIN) {
    if (!empty($_GET['faculty_id'])) $scope['faculty_id'] = (int)$_GET['faculty_id'];
    if (!empty($_GET['class_id']))   $scope['class_id']   = (int)$_GET['class_id'];
    if (!empty($_GET['subject_id'])) $scope['subject_id'] = (int)$_GET['subject_id'];
} elseif (in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ATT_MGR], true)) {
    $scope['faculty_id'] = $myFaculty;
    if (!empty($_GET['class_id']))   $scope['class_id']   = (int)$_GET['class_id'];
    if (!empty($_GET['subject_id'])) $scope['subject_id'] = (int)$_GET['subject_id'];
} elseif ($roleId === ROLE_TEACHER) {
    $scope['teacher_id'] = $uid;
    if (!empty($_GET['subject_id'])) $scope['subject_id'] = (int)$_GET['subject_id'];
} elseif ($roleId === ROLE_STUDENT) {
    $scope['student_id'] = $uid;
    if (!empty($_GET['subject_id'])) $scope['subject_id'] = (int)$_GET['subject_id'];
}

try {
    $data = chartSeries($period, $scope);
    echo json_encode(['ok'=>true] + $data, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
