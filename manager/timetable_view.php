<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
require_once __DIR__.'/../includes/timetable_view_render.php';

requireLogin();
requireRole([ROLE_ATT_MGR, ROLE_ADMIN]);

$timetableId = (int)($_GET['id'] ?? 0);
$date        = $_GET['date'] ?? date('Y-m-d');
$facultyId   = (int)($_SESSION['faculty_id'] ?? 0);
$roleId      = (int)($_SESSION['role_id'] ?? 0);

if (!$timetableId) {
    flashSet('danger', 'Timetable id missing.');
    redirect(BASE_URL.'/manager/timetable.php');
}

$scope = ($roleId === ROLE_ADMIN) ? 0 : $facultyId;
$ctx   = loadTimetableAttendanceContext($timetableId, $date, $scope);
if (!$ctx) {
    flashSet('danger', 'Timetable entry not found, not approved, or outside your faculty.');
    redirect(BASE_URL.'/manager/timetable.php');
}

$viewRow      = $ctx['viewRow'];
$sessionSlots = $ctx['sessionSlots'];
$roster       = $ctx['roster'];

$pageTitle    = t('attendance_view_title') . ' — ' . ($viewRow['subject_name'] ?? '');
$canEdit      = userHasPermission('edit_attendance') || userHasPermission('manager_edit_attendance');
$backUrl      = BASE_URL . '/manager/timetable.php';
$editUrl      = '';
if ($canEdit && !empty($sessionSlots)) {
    $firstSlotId = (int)$sessionSlots[0]['id'];
    $editUrl     = BASE_URL . '/manager/session_detail.php?id=' . $firstSlotId . '&mode=edit';
}

require_once __DIR__.'/../includes/header.php';

echo renderTimetableAttendanceView(
    $viewRow,
    $sessionSlots,
    $roster,
    $date,
    $backUrl,
    $editUrl,
    $canEdit
);

require_once __DIR__.'/../includes/footer.php';
