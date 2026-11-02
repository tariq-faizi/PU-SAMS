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

// build WHERE (mirrors reports/index.php exactly)
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


// ─── 3) Per-student aggregate (used both for at-risk list AND fallback) ──
$stmt = $db->prepare("SELECT u.id AS student_uid, u.full_name, u.student_id,
        c.id AS class_id, c.name AS class_name, c.section, s.subject_id, sub.name AS subject_name,
        SUM(CASE WHEN r.status='absent'  THEN 1 ELSE 0 END) AS absent_marks,
        SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) AS present_marks,
        COUNT(r.id) AS total_marks
    FROM attendance_sessions s
    JOIN timetable t   ON t.id=s.timetable_id
    JOIN classes c     ON c.id=s.class_id
    JOIN subjects sub  ON sub.id=s.subject_id
    JOIN attendance_records r ON r.session_id=s.id
    JOIN users u       ON u.id=r.student_id
    WHERE $whereSql
    GROUP BY u.id, c.id, s.subject_id
    ORDER BY u.full_name ASC");
$stmt->execute($params);

// Per-student aggregation across subjects (for fallback summary).
$byStudent = [];
// Per-student-per-subject (for at-risk list, matches index.php).
$riskRowsAll = [];
foreach ($stmt->fetchAll() as $row) {
    $studentKey = (int)$row['student_uid'];
    if (!isset($byStudent[$studentKey])) {
        $byStudent[$studentKey] = [
            'student_uid'      => $studentKey,
            'full_name'        => $row['full_name'],
            'student_id'       => $row['student_id'],
            'class_name'       => trim(($row['class_name'] ?? '').' '.($row['section'] ?? '')),
            'present_marks'    => 0,
            'absent_marks'     => 0,
            'semester_credits' => 0,
        ];
    }
    $semCredits = semesterCreditsForClassSubject((int)$row['class_id'], (int)$row['subject_id']);
    $byStudent[$studentKey]['present_marks']    += (int)$row['present_marks'];
    $byStudent[$studentKey]['absent_marks']     += (int)$row['absent_marks'];
    $byStudent[$studentKey]['semester_credits'] += $semCredits;

    $riskRowsAll[] = [
        'full_name'    => $row['full_name'],
        'student_id'   => $row['student_id'],
        'class_name'   => trim(($row['class_name'] ?? '').' '.($row['section'] ?? '')),
        'subject_name' => $row['subject_name'],
        'present_marks'=> (int)$row['present_marks'],
        'absent_marks' => (int)$row['absent_marks'],
        'absence_pct'  => absencePercentFromSemesterCredits((int)$row['absent_marks'], $semCredits),
    ];
}

// Apply risk filter for the at-risk section.
$riskRows = array_values(array_filter($riskRowsAll, fn($r) => (float)$r['absence_pct'] >= $riskPct));
if ($riskMode === 'warning') {
    $riskRows = array_values(array_filter($riskRows, fn($r) =>
        (float)$r['absence_pct'] >= attendanceWarningPercentThreshold() &&
        (float)$r['absence_pct'] <  attendanceDeprivationPercentThreshold()));
} elseif ($riskMode === 'deprived') {
    $riskRows = array_values(array_filter($riskRows, fn($r) =>
        (float)$r['absence_pct'] >= attendanceDeprivationPercentThreshold()));
}
usort($riskRows, fn($a, $b) =>
    (($b['absence_pct'] <=> $a['absence_pct'])
     ?: strcmp((string)$a['full_name'], (string)$b['full_name'])));

// Fallback: all students summary (when filters yield zero at-risk rows).
$allStudentRows = array_values($byStudent);
foreach ($allStudentRows as &$row) {
    $row['absence_pct'] = absencePercentFromSemesterCredits((int)$row['absent_marks'], (int)$row['semester_credits']);
}
unset($row);
usort($allStudentRows, fn($a, $b) =>
    (($b['absence_pct'] <=> $a['absence_pct']) ?: strcmp((string)$a['full_name'], (string)$b['full_name'])));

$appName = getSetting('app_name','PUSAMS');
$instName = getSetting('institution_name', defined('INSTITUTION_NAME') ? INSTITUTION_NAME : 'Paktia University');
$riskModeLabel = ['all'=>'All at-risk','warning'=>'Warning band','deprived'=>'Deprivation band'][$riskMode] ?? 'All';
$generatedBy = (string)($_SESSION['full_name'] ?? '-');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"/><title><?=h($appName)?> — Attendance Report</title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,"Segoe UI",sans-serif;font-size:12px;margin:18px;color:#1e293b}
  h2{color:#1a73e8;margin:0 0 4px;font-size:20px}
  h3{color:#0f172a;margin:18px 0 6px;font-size:14px;border-bottom:2px solid #e2e8f0;padding-bottom:4px}
  .subtitle{color:#475569;margin-bottom:8px;font-size:11.5px}
  .meta{background:#f1f5f9;border-left:4px solid #1a73e8;padding:8px 12px;margin-bottom:12px;font-size:11px;border-radius:4px}
  .meta b{color:#1a73e8}

  .summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:8px 0 14px}
  .sumcard{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #1a73e8;border-radius:6px;padding:8px 10px}
  .sumcard.ok      {border-left-color:#10b981}
  .sumcard.warn    {border-left-color:#f59e0b}
  .sumcard.danger  {border-left-color:#ef4444}
  .sumcard.info    {border-left-color:#06b6d4}
  .sumcard .lbl{display:block;color:#64748b;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px}
  .sumcard .val{font-size:18px;font-weight:700;color:#0f172a;line-height:1.2}
  .sumcard .sub{font-size:10px;color:#94a3b8}

  table{width:100%;border-collapse:collapse;margin-bottom:12px;background:#fff}
  thead th{background:#1a73e8;color:#fff;padding:7px 8px;text-align:left;font-size:11px;font-weight:600}
  tbody td{padding:6px 8px;border-bottom:1px solid #eef2f7;font-size:11.5px}
  tbody tr:nth-child(even){background:#f8fafc}
  .pct{font-weight:700}
  .pct.ok{color:#059669}
  .pct.warn{color:#d97706}
  .pct.danger{color:#dc2626}
  .empty{padding:18px;text-align:center;color:#94a3b8;font-style:italic;background:#f8fafc;border-radius:6px}
  .footer{margin-top:14px;color:#94a3b8;font-size:10px;text-align:center;border-top:1px solid #e2e8f0;padding-top:8px}
  .badge-mini{display:inline-block;padding:2px 6px;font-size:10px;border-radius:10px;background:#e0e7ff;color:#3730a3;font-weight:600}
  .badge-mini.open      {background:#dbeafe;color:#1d4ed8}
  .badge-mini.done      {background:#dcfce7;color:#166534}
  .badge-mini.missed    {background:#fee2e2;color:#991b1b}
  .badge-mini.cancelled {background:#e5e7eb;color:#374151}

  @media print{
    button{display:none}
    body{margin:10mm}
    h3{page-break-after:avoid}
    table{page-break-inside:auto}
    tr{page-break-inside:avoid;page-break-after:auto}
    thead{display:table-header-group}
    .meta,.sumcard{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
</style></head><body>

<button onclick="window.print()" style="margin-bottom:12px;padding:8px 18px;background:#1a73e8;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px">🖨 Print / Save as PDF</button>

<h2><?=h($appName)?> — Attendance Report</h2>
<div class="subtitle">
  <?=h($instName)?> ·
  Period: <b><?=h($fromDate)?></b> → <b><?=h($toDate)?></b> ·
  Generated: <?=date('d M Y H:i')?> · By: <?=h($generatedBy)?>
</div>

<div class="meta">
  <b>Filters applied:</b>
  Absence% ≥ <b><?=$riskPct?>%</b> ·
  Risk band: <b><?=h($riskModeLabel)?></b>
  <?php if($dayOfWeek!=='' && $dayOfWeek!=='all'): ?> · Day of week: <b><?=h(DAYS_OF_WEEK[(int)$dayOfWeek] ?? '-')?></b><?php endif; ?>
  <?php if($classId): ?> · Class ID: <b><?=$classId?></b><?php endif; ?>
  <?php if($subjectId): ?> · Subject ID: <b><?=$subjectId?></b><?php endif; ?>
</div>


<!-- ─── 3) Students at risk (matches index.php) ─────────────────── -->
<?php if (!$riskRows): ?>
  <div class="empty">
    No students matched the selected risk filter (Absence% ≥ <?=$riskPct?>%).
    Showing all students with attendance records in this period below ↓
  </div>

  <!-- Fallback: full student list so the PDF is never empty -->
  <h3>📋 All students in period / ټول محصلین</h3>
  <?php if (!$allStudentRows): ?>
    <div class="empty">No attendance records found for this period.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th>#</th><th>Student</th><th>Student ID</th><th>Class</th>
      <th>Presents</th><th>Absences</th><th>Absence %</th>
    </tr></thead>
    <tbody>
    <?php $i=0; foreach($allStudentRows as $r): $i++;
      $pct=(float)$r['absence_pct'];
      $pctCls = $pct >= attendanceDeprivationPercentThreshold() ? 'danger' : ($pct >= attendanceWarningPercentThreshold() ? 'warn' : 'ok');
    ?>
      <tr>
        <td><?=$i?></td>
        <td><?=h($r['full_name'])?></td>
        <td><?=h((string)($r['student_id'] ?? '-'))?></td>
        <td><?=h((string)($r['class_name'] ?? '-'))?></td>
        <td><?=(int)$r['present_marks']?></td>
        <td><?=(int)$r['absent_marks']?></td>
        <td class="pct <?=$pctCls?>"><?=number_format($pct,2)?>%</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

<?php else: ?>
<table>
  <thead><tr>
    <th>#</th><th>Student</th><th>Student ID</th><th>Class</th>
    <th>Subject</th><th>Presents</th><th>Absences</th><th>Absence %</th>
  </tr></thead>
  <tbody>
  <?php $i=0; foreach($riskRows as $r): $i++;
    $pct=(float)$r['absence_pct'];
    $pctCls = $pct >= attendanceDeprivationPercentThreshold() ? 'danger' : ($pct >= attendanceWarningPercentThreshold() ? 'warn' : 'ok');
  ?>
    <tr>
      <td><?=$i?></td>
      <td><?=h($r['full_name'])?></td>
      <td><?=h((string)($r['student_id'] ?? '-'))?></td>
      <td><?=h((string)($r['class_name'] ?? '-'))?></td>
      <td><?=h((string)($r['subject_name'] ?? '-'))?></td>
      <td><?=(int)$r['present_marks']?></td>
      <td><?=(int)$r['absent_marks']?></td>
      <td class="pct <?=$pctCls?>"><?=number_format($pct,2)?>%</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="footer">
  <?=h($appName)?> · <?=h($instName)?> · Printable attendance report ·
  Total rows: <?= count($riskRows) ?: count($allStudentRows) ?>
</div>

</body></html>
<?php auditLog('export_pdf','reports',null,
    'Attendance PDF exported (risk_pct='.$riskPct.'%, mode='.$riskMode.', sessions='.$totalSessions.', students='.$distinctStudents.', at_risk='.count($riskRows).')'); ?>
