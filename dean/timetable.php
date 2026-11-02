<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_DEPT_HEAD]);

$db = getDB();
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$pageTitle = 'Faculty Timetable';
$options = reportFilterOptions(['faculty_id' => $facultyId]);
$days = DAYS_OF_WEEK;
$departmentId = (int)($_GET['department_id'] ?? 0);
$classId = (int)($_GET['class_id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$dayFilter = isset($_GET['day']) && $_GET['day']!=='' ? (int)$_GET['day'] : -1;

$where = ["t.faculty_id=?"]; $params = [$facultyId];
if ($departmentId) { $where[] = "c.department_id=?"; $params[] = $departmentId; }
if ($classId) { $where[] = "t.class_id=?"; $params[] = $classId; }
if ($subjectId) { $where[] = "t.subject_id=?"; $params[] = $subjectId; }
if ($statusFilter !== '') {
    $mapped = facultyTimetableStatusesForFilter($statusFilter);
    if ($mapped) {
        $where[] = "t.status IN (".implode(',', array_fill(0, count($mapped), '?')).")";
        $params = array_merge($params, $mapped);
    }
}
if ($dayFilter >= 0 && $dayFilter <= 6) { $where[] = "t.day_of_week=?"; $params[] = $dayFilter; }
$whereSql = implode(' AND ', $where);
$stmt = $db->prepare("SELECT t.*, c.name as class_name, c.section, d.name as department_name, sub.name as subject_name, u.full_name as teacher_name, sem.name as semester_name
    FROM timetable t
    JOIN classes c ON c.id=t.class_id
    LEFT JOIN departments d ON d.id=c.department_id
    JOIN subjects sub ON sub.id=t.subject_id
    JOIN users u ON u.id=t.teacher_id
    LEFT JOIN semesters sem ON sem.id=t.semester_id
    WHERE $whereSql
    ORDER BY FIELD(t.status,'draft','faculty_approved','approved','rejected'), t.day_of_week, t.start_time");
$stmt->execute($params); $rows = $stmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i><?= h($pageTitle) ?></h5>
  </div>
  <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2"><i class="fas fa-eye me-1"></i>Read only</span>
</div>
<form method="GET" class="row g-2 mb-3"><div class="col-md-3"><select name="department_id" data-cascade="department" class="form-select form-select-sm"><option value="">All departments</option><?php foreach ($options['departments'] as $department): ?><option value="<?= (int)$department['id'] ?>" <?= $departmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><select name="class_id" data-cascade="class" class="form-select form-select-sm"><option value="">All classes</option><?php foreach ($options['classes'] as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $classId === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><select name="subject_id" data-cascade="subject" class="form-select form-select-sm"><option value="">All subjects</option><?php foreach ($options['subjects'] as $subject): ?><option value="<?= (int)$subject['id'] ?>" <?= $subjectId === (int)$subject['id'] ? 'selected' : '' ?>><?= h($subject['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">All statuses</option><?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $key => $label): ?><option value="<?= h($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select name="day" class="form-select form-select-sm"><option value="">All days</option><?php foreach (DAYS_OF_WEEK as $idx => $d): ?><option value="<?= $idx ?>" <?= $dayFilter === $idx ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select></div><div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Go</button></div></form>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead><tr><th>Department</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Day</th><th>Time</th><th>Credits</th><th>Status</th><th>Views</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No timetable rows found.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?>
<tr>
  <td><?= h($row['department_name'] ?? '-') ?></td>
  <td><?= h(trim($row['class_name'].' '.($row['section'] ?? ''))) ?><div class="small text-muted"><?= h($row['semester_name'] ?? '') ?></div></td>
  <td><?= h($row['subject_name']) ?></td>
  <td><?= h($row['teacher_name']) ?></td>
  <td><?= h($days[(int)$row['day_of_week']] ?? '') ?></td>
  <td><?= h(substr($row['start_time'],0,5)) ?> - <?= h(substr($row['end_time'],0,5)) ?><div class="small text-muted"><?= h($row['room'] ?: '-') ?></div></td>
  <td><?= (int)$row['credit_units'] ?></td>
  <td><span class="badge bg-<?= facultyTimetableUiBadge((string)$row['status']) ?>"><?= h(facultyTimetableUiLabel((string)$row['status'])) ?></span></td>
  <td class="text-nowrap"><a href="<?= BASE_URL ?>/dean/attendance.php?class_id=<?= (int)$row['class_id'] ?>&subject_id=<?= (int)$row['subject_id'] ?>&date=<?= h(date('Y-m-d')) ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
