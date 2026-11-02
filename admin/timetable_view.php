<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
require_once __DIR__.'/../includes/timetable_view_render.php';

requireLogin();
requirePermission('view_all_reports');

$timetableId = (int)($_GET['id'] ?? 0);
$date        = $_GET['date'] ?? date('Y-m-d');
if (!$timetableId) {
    flashSet('danger', 'Timetable id missing.');
    redirect(BASE_URL.'/admin/timetable.php');
}

$ctx = loadTimetableAttendanceContext($timetableId, $date, 0);
if (!$ctx) {
    flashSet('danger', 'Timetable entry not found or not approved.');
    redirect(BASE_URL.'/admin/timetable.php');
}

$viewRow      = $ctx['viewRow'];
$sessionSlots = $ctx['sessionSlots'];
$roster       = $ctx['roster'];

$pageTitle    = t('attendance_view_title') . ' — ' . ($viewRow['subject_name'] ?? '');
$canEdit      = userHasPermission('edit_attendance');
$backUrl      = BASE_URL . '/admin/timetable.php';
$editUrl      = '';
if ($canEdit && !empty($sessionSlots)) {
    
    $firstSlotId = (int)$sessionSlots[0]['id'];
    $editUrl     = BASE_URL . '/admin/session_detail.php?id=' . $firstSlotId . '&mode=edit';
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
