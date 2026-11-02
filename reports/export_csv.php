<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
requireLogin();

$db      = getDB();
$roleId  = (int)($_SESSION['role_id'] ?? 0);
$scopeFacultyId = currentFacultyScopeId();

$period = $_GET['period'] ?? 'month';
[$fromDate, $toDate] = reportPeriodDateRange($period, $_GET['from'] ?? null, $_GET['to'] ?? null);

$facultyId    = $scopeFacultyId ?: (int)($_GET['faculty_id'] ?? 0);
$departmentId = (int)($_GET['department_id'] ?? 0);
$classId      = (int)($_GET['class_id'] ?? 0);
$subjectId    = (int)($_GET['subject_id'] ?? 0);
$semesterId   = (int)($_GET['semester_id'] ?? 0);
$dayOfWeek    = $_GET['day_of_week'] ?? '';
$riskPct      = max(1, min(100, (int)($_GET['risk_pct'] ?? attendanceWarningPercentThreshold())));
$riskMode     = $_GET['risk_mode'] ?? 'all';

$where  = ["s.session_date BETWEEN ? AND ?"];
$params = [$fromDate, $toDate];
if ($facultyId)        { $where[] = "t.faculty_id=?";    $params[] = $facultyId; }
elseif ($scopeFacultyId){ $where[] = "t.faculty_id=?";    $params[] = $scopeFacultyId; }
if ($departmentId)     { $where[] = "c.department_id=?"; $params[] = $departmentId; }
if ($classId)          { $where[] = "s.class_id=?";      $params[] = $classId; }
if ($subjectId)        { $where[] = "s.subject_id=?";    $params[] = $subjectId; }
if ($semesterId)       { $where[] = "t.semester_id=?";   $params[] = $semesterId; }
if ($dayOfWeek !== '' && $dayOfWeek !== 'all') { $where[] = "DAYOFWEEK(s.session_date)-1=?"; $params[] = (int)$dayOfWeek; }
if ($roleId === ROLE_STUDENT) { $where[] = "s.class_id=?";   $params[] = (int)($_SESSION['class_id'] ?? 0); }
if ($roleId === ROLE_TEACHER) { $where[] = "s.teacher_id=?"; $params[] = (int)($_SESSION['user_id'] ?? 0); }
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT u.id as student_uid, u.full_name, u.student_id,
        f.name as faculty_name, d.name as department_name,
        c.id as class_id, c.name as class_name, c.section,
        s.subject_id, sub.name as subject_name,
        SUM(CASE WHEN r.status='absent'  THEN 1 ELSE 0 END) as absent_marks,
        SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) as present_marks,
        COUNT(r.id) as total_marks
    FROM attendance_sessions s
    JOIN timetable t   ON t.id=s.timetable_id
    JOIN faculties f   ON f.id=t.faculty_id
    JOIN classes c     ON c.id=s.class_id
    LEFT JOIN departments d ON d.id=c.department_id
    JOIN subjects sub  ON sub.id=s.subject_id
    JOIN attendance_records r ON r.session_id=s.id
    JOIN users u       ON u.id=r.student_id
    WHERE $whereSql
    GROUP BY u.id, s.subject_id
    ORDER BY u.full_name ASC");
$stmt->execute($params);

$rows = [];
foreach ($stmt->fetchAll() as $row) {
    $semesterCredits = semesterCreditsForClassSubject((int)$row['class_id'], (int)$row['subject_id']);
    $row['semester_credits'] = $semesterCredits;
    $row['absence_pct']      = absencePercentFromSemesterCredits((int)$row['absent_marks'], $semesterCredits);
    
    if ((float)$row['absence_pct'] >= $riskPct) $rows[] = $row;
}

if ($riskMode === 'warning') {
    $rows = array_values(array_filter($rows, fn($r) =>
        (float)$r['absence_pct'] >= attendanceWarningPercentThreshold() &&
        (float)$r['absence_pct'] <  attendanceDeprivationPercentThreshold()));
} elseif ($riskMode === 'deprived') {
    $rows = array_values(array_filter($rows, fn($r) =>
        (float)$r['absence_pct'] >= attendanceDeprivationPercentThreshold()));
}

usort($rows, fn($a, $b) =>
    (($b['absence_pct'] <=> $a['absence_pct'])
     ?: strcmp((string)$a['full_name'], (string)$b['full_name'])));

$statusLabel = function(float $pct): string {
    if ($pct >= attendanceDeprivationPercentThreshold()) return 'Deprived';
    if ($pct >= attendanceWarningPercentThreshold())    return 'Warning';
    return 'At-risk';
};

$filename = 'students_at_risk_'.$riskPct.'pct_'.date('Ymd_His').'.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');

fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['PUSAMS — Students at Risk Report']);
fputcsv($out, ['Period', $fromDate.'  →  '.$toDate]);
fputcsv($out, ['Risk threshold (absence %)', '>= '.$riskPct.'%']);
fputcsv($out, ['Status mode', ucfirst($riskMode)]);
fputcsv($out, ['Generated', date('Y-m-d H:i')]);
fputcsv($out, ['Total students matched', count($rows)]);
fputcsv($out, []); 

fputcsv($out, [
    '#','Student Name','Student ID','Class','Section','Subject','Presents','Absences','Absence %'
]);

$i = 0;
foreach ($rows as $r) {
    $i++;
    $pct = (float)$r['absence_pct'];
    fputcsv($out, [
        $i,
        $r['full_name'],
        $r['student_id'] ?? '',
        $r['class_name'] ?? '',
        $r['section'] ?? '',
        $r['subject_name'] ?? '',
        (int)$r['present_marks'],
        (int)$r['absent_marks'],
        $pct.'%',
    ]);
}

if (!$rows) {
    fputcsv($out, ['', 'No students matched the selected filter.']);
}

fclose($out);
auditLog('export_csv','reports',null,
    'Students-at-risk CSV exported (risk_pct='.$riskPct.'%, mode='.$riskMode.', rows='.count($rows).')');
