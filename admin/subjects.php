<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('manage_subjects');

$db = getDB();
$facultyScope = currentFacultyScopeId();
$selfUrl = currentSectionPageUrl('subjects.php');
$pageTitle = t('page_subjects');
$action = $_GET['action'] ?? 'list';
$selectedFacultyId = $facultyScope ?: (int)($_GET['faculty_id'] ?? 0);
$selectedDepartmentId = (int)($_GET['department_id'] ?? 0);
$selectedClassContextId = (int)($_GET['class_id'] ?? 0);

function subjectCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $facultyId = $facultyScope ?: (int)($_POST['faculty_id'] ?? 0);
        $departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
        $classContextId = (int)($_POST['class_id'] ?? 0) ?: null;
        $semesterId = (int)($_POST['semester_id'] ?? 0) ?: null;
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $credits = max(1, (int)($_POST['default_weekly_credits'] ?? 3));
        if (!$facultyId || $name === '' || $code === '') {
            flashSet('danger', 'Faculty, subject name, and code are required.');
            redirect($selfUrl.'?action=' . ($id ? 'edit&id='.$id : 'add'));
        }
        if ($departmentId) {
            $stmt = $db->prepare("SELECT id FROM departments WHERE id=? AND faculty_id=? LIMIT 1");
            $stmt->execute([$departmentId, $facultyId]);
            if (!$stmt->fetchColumn()) {
                flashSet('danger', 'Selected department is outside the selected faculty.');
                redirect($selfUrl);
            }
        }
        if ($classContextId) {
            $classStmt = $db->prepare("SELECT id, department_id FROM classes WHERE id=? AND faculty_id=? AND is_active=1 LIMIT 1");
            $classStmt->execute([$classContextId, $facultyId]);
            $classRow = $classStmt->fetch();
            if (!$classRow) {
                flashSet('danger', 'Selected class is outside the selected faculty.');
                redirect($selfUrl);
            }
            if ($departmentId && (int)$classRow['department_id'] > 0 && (int)$classRow['department_id'] !== (int)$departmentId) {
                flashSet('danger', 'Selected class does not belong to the selected department.');
                redirect($selfUrl);
            }
            $semesterId = resolveSemesterForClass($classContextId, $semesterId);
            if (!$semesterId) {
                flashSet('danger', 'Please pick one of the two valid semesters for the selected class.');
                redirect($selfUrl);
            }
        } elseif ($semesterId) {
            $stmt = $db->prepare("SELECT id FROM semesters WHERE id=? AND faculty_id=? LIMIT 1");
            $stmt->execute([$semesterId, $facultyId]);
            if (!$stmt->fetchColumn()) $semesterId = null;
        }
        if ($id > 0) {
            if ($facultyScope) {
                $ownedStmt = $db->prepare("SELECT id FROM subjects WHERE id=? AND faculty_id=? LIMIT 1");
                $ownedStmt->execute([$id, $facultyScope]);
                if (!$ownedStmt->fetchColumn()) {
                    flashSet('danger', 'This subject is outside your faculty scope.');
                    redirect($selfUrl);
                }
            }
            $db->prepare("UPDATE subjects SET faculty_id=?, department_id=?, semester_id=?, name=?, code=?, default_weekly_credits=? WHERE id=?")
               ->execute([$facultyId,$departmentId,$semesterId,$name,$code,$credits,$id]);
            auditLog('subject_update','subjects',$id,'Subject updated');
        } else {
            $db->prepare("INSERT INTO subjects (faculty_id,department_id,semester_id,name,code,default_weekly_credits,is_active) VALUES (?,?,?,?,?,?,1)")
               ->execute([$facultyId,$departmentId,$semesterId,$name,$code,$credits]);
            $id = (int)$db->lastInsertId();
            auditLog('subject_create','subjects',$id,'Subject created');
        }
        flashSet('success', 'Subject saved successfully.');
        redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $delSql = $facultyScope ? "UPDATE subjects SET is_active=0 WHERE id=? AND faculty_id=?" : "UPDATE subjects SET is_active=0 WHERE id=?";
        $delParams = $facultyScope ? [$id, $facultyScope] : [$id];
        $stmt = $db->prepare($delSql);
        $stmt->execute($delParams);
        if ($facultyScope && $stmt->rowCount() === 0) {
            flashSet('danger', 'This subject is outside your faculty scope.');
            redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
        }
        auditLog('subject_delete','subjects',$id,'Subject deactivated');
        flashSet('success', 'Subject deactivated.');
        redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
    }
}

$editRow = null;
if ($action === 'edit' && ($editId = (int)($_GET['id'] ?? 0))) {
    $editSql = "SELECT * FROM subjects WHERE id=?" . ($facultyScope ? " AND faculty_id=?" : "") . " LIMIT 1";
    $editParams = $facultyScope ? [$editId, $facultyScope] : [$editId];
    $stmt = $db->prepare($editSql);
    $stmt->execute($editParams);
    $editRow = $stmt->fetch();
}

$options = reportFilterOptions(['faculty_id' => $selectedFacultyId ?: $facultyScope]);
$faculties = $options['faculties'];
$departments = $options['departments'];
$semesters = $options['semesters'];
$subjectFormClassId = $selectedClassContextId;
if (!$subjectFormClassId && $editRow) {
    $subjectFormClassId = (int)(inferClassIdForDepartmentSemester((int)($editRow['department_id'] ?? 0), (int)($editRow['semester_id'] ?? 0), (int)($editRow['faculty_id'] ?? 0)) ?? 0);
}
$subjectSemesterOptions = $subjectFormClassId
    ? listSemestersByClass($subjectFormClassId)
    : (($selectedFacultyId || $facultyScope) ? listSemestersByFaculty((int)($selectedFacultyId ?: $facultyScope)) : []);

$where = "WHERE 1=1"; $params = [];
if ($selectedFacultyId) { $where .= " AND s.faculty_id=?"; $params[] = $selectedFacultyId; }
elseif ($facultyScope) { $where .= " AND s.faculty_id=?"; $params[] = $facultyScope; }
if ($selectedDepartmentId) { $where .= " AND s.department_id=?"; $params[] = $selectedDepartmentId; }
$rowsStmt = $db->prepare("SELECT s.*, f.name as faculty_name, d.name as department_name, sem.name as semester_name
    FROM subjects s
    JOIN faculties f ON f.id=s.faculty_id
    LEFT JOIN departments d ON d.id=s.department_id
    LEFT JOIN semesters sem ON sem.id=s.semester_id
    $where
    ORDER BY f.name,d.name,s.name");
$rowsStmt->execute($params); $rows = $rowsStmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-book me-2"></i><?= t('subjects') ?></h5>
  </div>
  <?= renderActionDropdown([
        'add_url'  => '?action=add'.($selectedFacultyId ? '&faculty_id='.$selectedFacultyId : ''),
        'base_url' => $selfUrl,
        'extra_query' => $selectedFacultyId ? 'faculty_id='.$selectedFacultyId : '',
  ]) ?>
</div>
<?= renderModeBanner() ?>
<?php
echo renderClusterContextBanner([
    'faculty_id'    => $selectedFacultyId,
    'department_id' => $selectedDepartmentId,
    'current_label' => 'Subjects',
]);
?>
<?php if (!$facultyScope): ?>
<form method="GET" class="row g-2 mb-3"><div class="col-md-4"><select name="faculty_id" data-cascade="faculty" class="form-select form-select-sm"><option value="">All faculties</option><?php foreach ($faculties as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= $selectedFacultyId === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><select name="department_id" data-cascade="department" class="form-select form-select-sm"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>" <?= $selectedDepartmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-sm btn-primary">Filter</button></div></form>
<?php endif; ?>
<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card mb-4"><div class="card-body"><form method="POST"><?php subjectCsrf(); ?><input type="hidden" name="act" value="save"/><?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"/><?php endif; ?><div class="row g-3"><div class="col-md-3"><label class="form-label">Faculty *</label><select id="adminSubjectFormFaculty" name="faculty_id" class="form-select" data-cascade="faculty" data-cascade-target="adminSubjectFormDepartment" data-preselect="<?= h((string)($editRow['faculty_id'] ?? ($selectedFacultyId ?: $facultyScope ?: 0))) ?>" <?= $facultyScope ? 'disabled' : '' ?>><?php foreach ($faculties as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= (int)($editRow['faculty_id'] ?? ($selectedFacultyId ?: $facultyScope ?: 0)) === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select><?php if ($facultyScope): ?><input type="hidden" name="faculty_id" value="<?= (int)$facultyScope ?>"/><?php endif; ?></div><div class="col-md-3"><label class="form-label">Department</label><select id="adminSubjectFormDepartment" name="department_id" class="form-select" data-cascade="department" data-cascade-source="adminSubjectFormFaculty" data-cascade-target="adminSubjectFormClass" data-preselect="<?= h((string)($editRow['department_id'] ?? 0)) ?>" data-placeholder="-- Shared / None --"><option value="">-- Shared / None --</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>" <?= (int)($editRow['department_id'] ?? 0) === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Class context</label><select id="adminSubjectFormClass" name="class_id" class="form-select" data-cascade="class" data-cascade-source="adminSubjectFormDepartment" data-cascade-target="adminSubjectFormSemester" data-preselect="<?= h((string)$subjectFormClassId) ?>" data-placeholder="Select class"><option value="">-- Optional class --</option><?php foreach ($options['classes'] as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $subjectFormClassId === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Semester</label><select id="adminSubjectFormSemester" name="semester_id" class="form-select" data-cascade="semester" data-cascade-source="adminSubjectFormClass" data-preselect="<?= h((string)($editRow['semester_id'] ?? 0)) ?>"><option value="">-- None --</option><?php foreach ($subjectSemesterOptions as $semester): ?><option value="<?= (int)$semester['id'] ?>" <?= (int)($editRow['semester_id'] ?? 0) === (int)$semester['id'] ? 'selected' : '' ?>><?= h($semester['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Default weekly credits</label><input name="default_weekly_credits" type="number" min="1" max="6" class="form-control" value="<?= h((string)($editRow['default_weekly_credits'] ?? 3)) ?>"/></div><div class="col-md-5"><label class="form-label">Subject name *</label><input name="name" class="form-control" required value="<?= h($editRow['name'] ?? '') ?>"/></div><div class="col-md-3"><label class="form-label">Code *</label><input name="code" class="form-control" required value="<?= h($editRow['code'] ?? '') ?>"/></div><div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save</button><a href="<?= h($selfUrl) ?><?= $selectedFacultyId ? '?faculty_id='.$selectedFacultyId : '' ?>" class="btn btn-secondary">Cancel</a></div></div></form></div></div>
<?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead><tr><th>Faculty</th><th>Department</th><th>Subject</th><th>Code</th><th>Semester</th><th>Weekly credits</th><th>Status</th><th>Views</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No subjects found.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $delForm = '<form method="POST" class="d-inline"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/><input type="hidden" name="act" value="delete"/><input type="hidden" name="id" value="'.(int)$row['id'].'"/><button class="btn btn-sm btn-danger" data-confirm="Deactivate this subject?"><i class="fas fa-archive me-1"></i>Delete</button></form>';
    $editUrl = '?action=edit&id='.(int)$row['id'].($selectedFacultyId ? '&faculty_id='.$selectedFacultyId : '');
?>
<tr>
  <td><?= h($row['faculty_name']) ?></td>
  <td><?= h($row['department_name'] ?? '-') ?></td>
  <td><?= h($row['name']) ?></td>
  <td><code><?= h($row['code']) ?></code></td>
  <td><?= h($row['semester_name'] ?? '-') ?></td>
  <td><?= (int)$row['default_weekly_credits'] ?></td>
  <td><span class="badge bg-<?= $row['is_active'] ? 'success' : 'secondary' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span></td>
  <td class="text-nowrap">
    <?= renderRowActions([
        'view_url'    => currentSectionPageUrl('timetable.php').'?subject_id='.(int)$row['id'],
        'view_label'  => 'View',
        'edit_url'    => $editUrl,
        'delete_form' => $delForm,
    ]) ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
