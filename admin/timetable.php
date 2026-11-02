<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('manage_timetable');

$db = getDB();
$pageTitle = t('page_timetable');
$action = $_GET['action'] ?? 'list';
$days = DAYS_OF_WEEK;
$facultyId = (int)($_GET['faculty_id'] ?? 0);
$departmentId = (int)($_GET['department_id'] ?? 0);
$classId = (int)($_GET['class_id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$semesterId = (int)($_GET['semester_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$dayFilter = isset($_GET['day']) && $_GET['day']!=='' ? (int)$_GET['day'] : -1;
$ttAdminFilterQS = http_build_query(array_filter([
    'faculty_id'    => $facultyId ?: null,
    'department_id' => $departmentId ?: null,
    'class_id'      => $classId ?: null,
    'semester_id'   => $semesterId ?: null,
    'subject_id'    => $subjectId ?: null,
    'status'        => $statusFilter ?: null,
    'day'           => $dayFilter >= 0 ? $dayFilter : null,
], fn($v) => $v !== null && $v !== ''));
$ttAdminFilterQS = $ttAdminFilterQS ? '&'.$ttAdminFilterQS : '';
$options = reportFilterOptions(['faculty_id' => $facultyId ?: null]);

function adminTimetableCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

$teacherSql = "SELECT id,full_name,faculty_id FROM users WHERE role_id=? AND status='active' AND deleted_at IS NULL";
$teacherParams = [ROLE_TEACHER];
if ($facultyId) { $teacherSql .= " AND faculty_id=?"; $teacherParams[] = $facultyId; }
$teacherSql .= " ORDER BY full_name";
$teacherStmt = $db->prepare($teacherSql); $teacherStmt->execute($teacherParams); $teachers = $teacherStmt->fetchAll();
$roomSql = "SELECT id,name,room_type,faculty_id FROM rooms WHERE is_active=1"; $roomParams = [];
if ($facultyId) { $roomSql .= " AND faculty_id=?"; $roomParams[] = $facultyId; }
$roomSql .= " ORDER BY name";
$roomStmt = $db->prepare($roomSql); $roomStmt->execute($roomParams); $rooms = $roomStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $classIdPost = (int)($_POST['class_id'] ?? 0);
        $subjectIdPost = (int)($_POST['subject_id'] ?? 0);
        $semesterIdPost = (int)($_POST['semester_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $roomId = (int)($_POST['room_id'] ?? 0) ?: null;
        $roomText = trim($_POST['room'] ?? '');
        $dayOfWeek = (int)($_POST['day_of_week'] ?? 0);
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $creditUnits = max(1, (int)($_POST['credit_units'] ?? 1));
        $statusValue = in_array($_POST['status'] ?? 'draft', ['draft','faculty_approved','approved','rejected'], true) ? $_POST['status'] : 'draft';
        $notes = trim($_POST['notes'] ?? '');

        $metaStmt = $db->prepare("SELECT c.faculty_id, c.department_id, c.semester_id, s.department_id as subject_department_id, s.semester_id as subject_semester_id
            FROM classes c JOIN subjects s ON s.id=? WHERE c.id=? LIMIT 1");
        $metaStmt->execute([$subjectIdPost,$classIdPost]);
        $meta = $metaStmt->fetch();
        if (!$meta || !$teacherId || !$startTime || !$endTime || $startTime >= $endTime) {
            flashSet('danger', 'Please complete all required timetable data.');
            redirect(BASE_URL.'/admin/timetable.php'.($id ? '?action=edit&id='.$id : ''));
        }
        $facultyIdValue = (int)$meta['faculty_id'];
        $departmentIdValue = (int)$meta['department_id'] ?: null;
        $semesterIdValue = resolveSemesterForClass($classIdPost, $semesterIdPost);
        if (!$semesterIdValue) {
            flashSet('danger', 'Please pick one of the two valid semesters for the selected class.');
            redirect(BASE_URL.'/admin/timetable.php'.($id ? '?action=edit&id='.$id : ''));
        }
        if (!empty($meta['subject_semester_id']) && (int)$meta['subject_semester_id'] !== (int)$semesterIdValue) {
            flashSet('danger', 'Selected subject does not belong to the chosen semester.');
            redirect(BASE_URL.'/admin/timetable.php'.($id ? '?action=edit&id='.$id : ''));
        }

        $teacherCheck = $db->prepare("SELECT id FROM users WHERE id=? AND role_id=? AND status='active' AND deleted_at IS NULL LIMIT 1");
        $teacherCheck->execute([$teacherId, ROLE_TEACHER]);
        if (!$teacherCheck->fetchColumn()) {
            flashSet('danger', 'Selected teacher is invalid.');
            redirect(BASE_URL.'/admin/timetable.php?action=list'.$ttAdminFilterQS);
        }

        $roomName = $roomText;
        if ($roomId) {
            $roomCheck = $db->prepare("SELECT name FROM rooms WHERE id=? LIMIT 1");
            $roomCheck->execute([$roomId]);
            $roomName = (string)($roomCheck->fetchColumn() ?: $roomText);
        }

        $conflicts = detectTimetableConflicts($classIdPost, $teacherId, $roomName, $dayOfWeek, $startTime, $endTime, $id, (int)$roomId);
        if ($conflicts) {
            flashSet('danger', 'Schedule conflict detected: '.implode(' | ', $conflicts));
            redirect(BASE_URL.'/admin/timetable.php'.($id ? '?action=edit&id='.$id : ''));
        }

        if ($id > 0) {
            $db->prepare("UPDATE timetable SET faculty_id=?, department_id=?, semester_id=?, class_id=?, subject_id=?, teacher_id=?, room_id=?, room=?, day_of_week=?, start_time=?, end_time=?, credit_units=?, status=?, approved_by=?, notes=? WHERE id=?")
               ->execute([$facultyIdValue,$departmentIdValue,$semesterIdValue,$classIdPost,$subjectIdPost,$teacherId,$roomId,$roomName,$dayOfWeek,$startTime,$endTime,$creditUnits,$statusValue,$_SESSION['user_id'],$notes,$id]);
            auditLog('vice_timetable_update','timetable',$id,'Vice-Chancellor updated timetable row');
            flashSet('success', 'Timetable entry updated successfully.');
            if (function_exists('cacheBustDashboards')) cacheBustDashboards();
        } else {
            $db->prepare("INSERT INTO timetable (faculty_id,department_id,semester_id,class_id,subject_id,teacher_id,room_id,room,day_of_week,start_time,end_time,credit_units,status,approved_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$facultyIdValue,$departmentIdValue,$semesterIdValue,$classIdPost,$subjectIdPost,$teacherId,$roomId,$roomName,$dayOfWeek,$startTime,$endTime,$creditUnits,$statusValue,$_SESSION['user_id'],$notes]);
            $newId = (int)$db->lastInsertId();
            auditLog('vice_timetable_create','timetable',$newId,'Vice-Chancellor created timetable row');
            flashSet('success', 'Timetable entry created successfully.');
            if (function_exists('cacheBustDashboards')) cacheBustDashboards();
        }
        redirect(BASE_URL.'/admin/timetable.php?action=list'.$ttAdminFilterQS);
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM timetable WHERE id=?")->execute([$id]);
        auditLog('vice_timetable_delete','timetable',$id,'Vice-Chancellor deleted timetable row');
        flashSet('success', 'Timetable entry deleted.');
        if (function_exists('cacheBustDashboards')) cacheBustDashboards();
        redirect(BASE_URL.'/admin/timetable.php?action=list'.$ttAdminFilterQS);
    }
}

$editRow = null;
if ($action === 'edit' && ($editId = (int)($_GET['id'] ?? 0))) {
    $stmt = $db->prepare("SELECT * FROM timetable WHERE id=? LIMIT 1");
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch();
}

$where = ["1=1"]; $params = [];
if ($facultyId) { $where[] = "t.faculty_id=?"; $params[] = $facultyId; }
if ($departmentId) { $where[] = "c.department_id=?"; $params[] = $departmentId; }
if ($classId) { $where[] = "t.class_id=?"; $params[] = $classId; }
if ($subjectId) { $where[] = "t.subject_id=?"; $params[] = $subjectId; }
if ($semesterId) { $where[] = "t.semester_id=?"; $params[] = $semesterId; }
if ($statusFilter !== '') { $where[] = "t.status=?"; $params[] = $statusFilter; }
if ($dayFilter >= 0 && $dayFilter <= 6) { $where[] = "t.day_of_week=?"; $params[] = $dayFilter; }
$whereSql = implode(' AND ', $where);
$stmt = $db->prepare("SELECT t.*, f.name as faculty_name, d.name as department_name, c.name as class_name, c.section, sub.name as subject_name, u.full_name as teacher_name, sem.name as semester_name, ap.full_name as approved_by_name
    FROM timetable t
    JOIN faculties f ON f.id=t.faculty_id
    JOIN classes c ON c.id=t.class_id
    LEFT JOIN departments d ON d.id=c.department_id
    JOIN subjects sub ON sub.id=t.subject_id
    JOIN users u ON u.id=t.teacher_id
    LEFT JOIN semesters sem ON sem.id=t.semester_id
    LEFT JOIN users ap ON ap.id=t.approved_by
    WHERE $whereSql
    ORDER BY FIELD(t.status,'draft','faculty_approved','approved','rejected'), f.name, t.day_of_week, t.start_time");
$stmt->execute($params); $rows = $stmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i><?= t('timetable') ?></h5>
  </div>
  <?= renderActionDropdown([
        'add_url'  => '?action=add',
        'base_url' => BASE_URL.'/admin/timetable.php',
  ]) ?>
</div>
<?= renderModeBanner() ?>
<form method="GET" class="row g-2 mb-3"><div class="col-md-2"><select name="faculty_id" data-cascade="faculty" class="form-select form-select-sm"><option value="">All faculties</option><?php foreach ($options['faculties'] as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= $facultyId === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select name="department_id" data-cascade="department" class="form-select form-select-sm"><option value="">All departments</option><?php foreach ($options['departments'] as $department): ?><option value="<?= (int)$department['id'] ?>" <?= $departmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select id="adminTtFilterClass" name="class_id" data-cascade="class" data-cascade-target="adminTtFilterSemester" class="form-select form-select-sm"><option value="">All classes</option><?php foreach ($options['classes'] as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $classId === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select id="adminTtFilterSemester" name="semester_id" data-cascade="semester" data-cascade-source="adminTtFilterClass" data-preselect="<?= h((string)$semesterId) ?>" class="form-select form-select-sm"><option value="">All semesters</option><?php foreach (($classId ? listSemestersByClass($classId) : $options['semesters']) as $sem): ?><option value="<?= (int)$sem['id'] ?>" <?= $semesterId === (int)$sem['id'] ? 'selected' : '' ?>><?= h($sem['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select name="subject_id" data-cascade="subject" class="form-select form-select-sm"><option value="">All subjects</option><?php foreach ($options['subjects'] as $subject): ?><option value="<?= (int)$subject['id'] ?>" <?= $subjectId === (int)$subject['id'] ? 'selected' : '' ?>><?= h($subject['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">All statuses</option><?php foreach (['draft'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $key => $label): ?><option value="<?= h($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div><div class="col-md-1"><select name="day" class="form-select form-select-sm"><option value="">All days</option><?php foreach (DAYS_OF_WEEK as $idx => $d): ?><option value="<?= $idx ?>" <?= $dayFilter === $idx ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select></div><div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Go</button></div></form>
<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card mb-4"><div class="card-body"><form method="POST"><?php adminTimetableCsrf(); ?><input type="hidden" name="act" value="save"/><?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"/><?php endif; ?><div class="row g-3"><div class="col-md-4"><label class="form-label">Class *</label><select id="adminTtFormClass" name="class_id" class="form-select" required data-cascade="class" data-cascade-target="adminTtFormSemester" data-preselect="<?= h((string)($editRow['class_id'] ?? 0)) ?>" data-placeholder="Select class"><option value="">Select class</option><?php foreach ($options['classes'] as $class): ?><option value="<?= (int)$class['id'] ?>" <?= (int)($editRow['class_id'] ?? 0) === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Semester *</label><select id="adminTtFormSemester" name="semester_id" class="form-select" required data-cascade="semester" data-cascade-source="adminTtFormClass" data-cascade-target="adminTtFormSubject" data-preselect="<?= h((string)($editRow['semester_id'] ?? 0)) ?>" data-placeholder="Select semester"><option value="">Select semester</option><?php foreach (listSemestersByClass((int)($editRow['class_id'] ?? 0)) as $sem): ?><option value="<?= (int)$sem['id'] ?>" <?= (int)($editRow['semester_id'] ?? 0) === (int)$sem['id'] ? 'selected' : '' ?>><?= h($sem['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Subject *</label><select id="adminTtFormSubject" name="subject_id" class="form-select" required data-cascade="subject" data-cascade-source="adminTtFormSemester" data-preselect="<?= h((string)($editRow['subject_id'] ?? 0)) ?>" data-placeholder="Select subject"><option value="">Select subject</option><?php foreach ($options['subjects'] as $subject): ?><option value="<?= (int)$subject['id'] ?>" <?= (int)($editRow['subject_id'] ?? 0) === (int)$subject['id'] ? 'selected' : '' ?>><?= h($subject['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Teacher *</label><select name="teacher_id" class="form-select" required><option value="">Select teacher</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int)$teacher['id'] ?>" <?= (int)($editRow['teacher_id'] ?? 0) === (int)$teacher['id'] ? 'selected' : '' ?>><?= h($teacher['full_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Day *</label><select name="day_of_week" class="form-select"><?php foreach ($days as $index => $label): ?><option value="<?= (int)$index ?>" <?= (int)($editRow['day_of_week'] ?? 0) === (int)$index ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Start *</label><input type="time" name="start_time" class="form-control" required value="<?= h($editRow['start_time'] ?? '08:00') ?>"/></div><div class="col-md-2"><label class="form-label">End *</label><input type="time" name="end_time" class="form-control" required value="<?= h($editRow['end_time'] ?? '09:30') ?>"/></div><div class="col-md-2"><label class="form-label">Credits</label><input type="number" min="1" name="credit_units" class="form-control" value="<?= h((string)($editRow['credit_units'] ?? 1)) ?>"/></div><div class="col-md-2"><label class="form-label">Room</label><select name="room_id" class="form-select"><option value="">-- Free text --</option><?php foreach ($rooms as $room): ?><option value="<?= (int)$room['id'] ?>" <?= (int)($editRow['room_id'] ?? 0) === (int)$room['id'] ? 'selected' : '' ?>><?= h($room['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Room (text)</label><input name="room" class="form-control" value="<?= h($editRow['room'] ?? '') ?>"/></div><div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (['draft'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $key => $label): ?><option value="<?= h($key) ?>" <?= ($editRow['status'] ?? 'draft') === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div><div class="col-md-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($editRow['notes'] ?? '') ?></textarea></div><div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save</button><a href="<?= BASE_URL ?>/admin/timetable.php?action=list<?= h($ttAdminFilterQS) ?>" class="btn btn-secondary">Cancel</a></div></div></form></div></div>
<?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead><tr><th>Faculty</th><th>Department</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Day</th><th>Time</th><th>Status</th><th>Approved by</th><th>Views</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No timetable rows found.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $delForm = '<form method="POST" class="d-inline"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/><input type="hidden" name="act" value="delete"/><input type="hidden" name="id" value="'.(int)$row['id'].'"/><button class="btn btn-sm btn-danger" data-confirm="Delete this row?"><i class="fas fa-trash me-1"></i>Delete</button></form>';
?>
<tr>
  <td><?= h($row['faculty_name']) ?></td>
  <td><?= h($row['department_name'] ?? '-') ?></td>
  <td><?= h(trim($row['class_name'].' '.($row['section'] ?? ''))) ?><div class="small text-muted"><?= h($row['semester_name'] ?? '') ?></div></td>
  <td><?= h($row['subject_name']) ?></td>
  <td><?= h($row['teacher_name']) ?></td>
  <td><?= h($days[(int)$row['day_of_week']] ?? '') ?></td>
  <td><?= h(substr($row['start_time'],0,5)) ?> - <?= h(substr($row['end_time'],0,5)) ?><div class="small text-muted"><?= h($row['room'] ?: '-') ?></div></td>
  <td><span class="badge bg-<?= ['draft'=>'warning','approved'=>'success','rejected'=>'danger'][$row['status']] ?? 'secondary' ?>"><?= h(str_replace('_',' ',ucfirst($row['status']))) ?></span></td>
  <td><?= h($row['approved_by_name'] ?? '-') ?></td>
  <td class="text-nowrap">
    <?= renderRowActions([
        'view_url'    => BASE_URL.'/admin/attendance.php?date='.h(date('Y-m-d')).'&class_id='.(int)$row['class_id'].'&subject_id='.(int)$row['subject_id'],
        'view_label'  => 'View',
        'edit_url'    => '?action=edit&id='.(int)$row['id'],
        'delete_form' => $delForm,
    ]) ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
