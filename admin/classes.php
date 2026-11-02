<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('manage_classes');

$db = getDB();
$facultyScope = currentFacultyScopeId();
$selfUrl = currentSectionPageUrl('classes.php');
$pageTitle = t('page_classes');
$action = $_GET['action'] ?? 'list';
$selectedFacultyId = $facultyScope ?: (int)($_GET['faculty_id'] ?? 0);
$selectedDepartmentId = (int)($_GET['department_id'] ?? 0);

function classCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $facultyId = $facultyScope ?: (int)($_POST['faculty_id'] ?? 0);
        $departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
        // ── (v25) د ټولګي د جوړولو په فورم کې د سمیستر تټه حذف شوې،
        // سمیستر به د سمیستر جوړولو پر مهال د ټولګي سره وتړل شي. د Edit په حال کې
        // موجوده د ټولګي semester_id خوندي کړو ترځو داتا خراب نه شي.
        $semesterId = null;
        if ($id > 0) {
            $semStmt = $db->prepare("SELECT semester_id FROM classes WHERE id=? LIMIT 1");
            $semStmt->execute([$id]);
            $semesterId = (int)($semStmt->fetchColumn() ?: 0) ?: null;
        }
        $name = trim($_POST['name'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $capacity = max(0, (int)($_POST['capacity'] ?? 0));
        $classType = in_array($_POST['class_type'] ?? 'department', ['shared','department'], true) ? $_POST['class_type'] : 'department';
        if (!$facultyId || $name === '') {
            flashSet('danger', 'Faculty and class name are required.');
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
        if ($id > 0) {
            if ($facultyScope) {
                $ownedStmt = $db->prepare("SELECT id FROM classes WHERE id=? AND faculty_id=? LIMIT 1");
                $ownedStmt->execute([$id, $facultyScope]);
                if (!$ownedStmt->fetchColumn()) {
                    flashSet('danger', 'This class is outside your faculty scope.');
                    redirect($selfUrl);
                }
            }
            $db->prepare("UPDATE classes SET faculty_id=?, department_id=?, semester_id=?, name=?, section=?, capacity=?, class_type=? WHERE id=?")
               ->execute([$facultyId,$departmentId,$semesterId,$name,$section,$capacity,$classType,$id]);
            auditLog('class_update','classes',$id,'Class updated');
        } else {
            $db->prepare("INSERT INTO classes (faculty_id,department_id,semester_id,name,section,capacity,class_type,is_active) VALUES (?,?,?,?,?,?,?,1)")
               ->execute([$facultyId,$departmentId,$semesterId,$name,$section,$capacity,$classType]);
            $id = (int)$db->lastInsertId();
            auditLog('class_create','classes',$id,'Class created');
        }
        flashSet('success', 'Class saved successfully.');
        redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $delSql = $facultyScope ? "UPDATE classes SET is_active=0 WHERE id=? AND faculty_id=?" : "UPDATE classes SET is_active=0 WHERE id=?";
        $delParams = $facultyScope ? [$id, $facultyScope] : [$id];
        $stmt = $db->prepare($delSql);
        $stmt->execute($delParams);
        if ($facultyScope && $stmt->rowCount() === 0) {
            flashSet('danger', 'This class is outside your faculty scope.');
            redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
        }
        auditLog('class_delete','classes',$id,'Class deactivated');
        flashSet('success', 'Class deactivated.');
        redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
    }
}

$editRow = null;
if ($action === 'edit' && ($editId = (int)($_GET['id'] ?? 0))) {
    $editSql = "SELECT * FROM classes WHERE id=?" . ($facultyScope ? " AND faculty_id=?" : "") . " LIMIT 1";
    $editParams = $facultyScope ? [$editId, $facultyScope] : [$editId];
    $stmt = $db->prepare($editSql);
    $stmt->execute($editParams);
    $editRow = $stmt->fetch();
}

$options = reportFilterOptions(['faculty_id' => $selectedFacultyId ?: $facultyScope]);
$faculties = $options['faculties'];
$departments = $options['departments'];
$semesters = listClassStartSemestersByFaculty((int)($selectedFacultyId ?: $facultyScope ?: ($editRow['faculty_id'] ?? 0)));

$where = "WHERE 1=1"; $params = [];
if ($selectedFacultyId) { $where .= " AND c.faculty_id=?"; $params[] = $selectedFacultyId; }
elseif ($facultyScope) { $where .= " AND c.faculty_id=?"; $params[] = $facultyScope; }
if ($selectedDepartmentId) { $where .= " AND c.department_id=?"; $params[] = $selectedDepartmentId; }
$rowsStmt = $db->prepare("SELECT c.*, f.name as faculty_name, d.name as department_name, sem.name as semester_name
    FROM classes c
    JOIN faculties f ON f.id=c.faculty_id
    LEFT JOIN departments d ON d.id=c.department_id
    LEFT JOIN semesters sem ON sem.id=c.semester_id
    $where
    ORDER BY f.name,d.name,c.name,c.section");
$rowsStmt->execute($params); $rows = $rowsStmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-chalkboard me-2"></i><?= t('classes') ?></h5>
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
    'current_label' => 'Classes',
]);
?>
<?php if (!$facultyScope): ?>
<form method="GET" class="row g-2 mb-3"><div class="col-md-4"><select name="faculty_id" data-cascade="faculty" class="form-select form-select-sm"><option value="">All faculties</option><?php foreach ($faculties as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= $selectedFacultyId === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><select name="department_id" data-cascade="department" class="form-select form-select-sm"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>" <?= $selectedDepartmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-sm btn-primary">Filter</button></div></form>
<?php endif; ?>
<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card mb-4"><div class="card-body"><form method="POST"><?php classCsrf(); ?><input type="hidden" name="act" value="save"/><?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"/><?php endif; ?><div class="row g-3"><div class="col-md-3"><label class="form-label">Faculty *</label><select id="adminClassFormFaculty" name="faculty_id" class="form-select" data-cascade="faculty" data-cascade-target="adminClassFormDepartment" data-preselect="<?= h((string)($editRow['faculty_id'] ?? ($selectedFacultyId ?: $facultyScope ?: 0))) ?>" <?= $facultyScope ? 'disabled' : '' ?>><?php foreach ($faculties as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= (int)($editRow['faculty_id'] ?? ($selectedFacultyId ?: $facultyScope ?: 0)) === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select><?php if ($facultyScope): ?><input type="hidden" name="faculty_id" value="<?= (int)$facultyScope ?>"/><?php endif; ?></div><div class="col-md-3"><label class="form-label">Department</label><select id="adminClassFormDepartment" name="department_id" class="form-select" data-cascade="department" data-cascade-source="adminClassFormFaculty" data-preselect="<?= h((string)($editRow['department_id'] ?? 0)) ?>" data-placeholder="-- Shared / None --"><option value="">-- Shared / None --</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>" <?= (int)($editRow['department_id'] ?? 0) === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div><?php /* (v25) د ټولګي جوړولو په فورم کې د سمیستر فیلڈ حذف شویه، سمیستر به د Add Semester په وخت کې وتړل شي */ ?><div class="col-md-3"><label class="form-label">Capacity</label><input name="capacity" type="number" min="0" class="form-control" value="<?= h((string)($editRow['capacity'] ?? 0)) ?>"/></div><div class="col-md-4"><label class="form-label">Class name *</label><input name="name" class="form-control" required value="<?= h($editRow['name'] ?? '') ?>"/></div><div class="col-md-2"><label class="form-label">Section</label><input name="section" class="form-control" value="<?= h($editRow['section'] ?? '') ?>"/></div><div class="col-md-3"><label class="form-label">Class type</label><select name="class_type" class="form-select"><?php foreach (['department'=>'Department','shared'=>'Shared'] as $key=>$label): ?><option value="<?= h($key) ?>" <?= ($editRow['class_type'] ?? 'department') === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div><div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save</button><a href="<?= h($selfUrl) ?><?= $selectedFacultyId ? '?faculty_id='.$selectedFacultyId : '' ?>" class="btn btn-secondary">Cancel</a></div></div></form></div></div>
<?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead><tr><th>Faculty</th><th>Department</th><th>Class</th><th>Section</th><th>Semester</th><th>Capacity</th><th>Type</th><th>Status</th><th>Views</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No classes found.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $delForm = '<form method="POST" class="d-inline"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/><input type="hidden" name="act" value="delete"/><input type="hidden" name="id" value="'.(int)$row['id'].'"/><button class="btn btn-sm btn-danger" data-confirm="Deactivate this class?"><i class="fas fa-archive me-1"></i>Delete</button></form>';
    $editUrl = '?action=edit&id='.(int)$row['id'].($selectedFacultyId ? '&faculty_id='.$selectedFacultyId : '');
?>
<tr>
  <td><?= h($row['faculty_name']) ?></td>
  <td><?= h($row['department_name'] ?? '-') ?></td>
  <td><?= h($row['name']) ?></td>
  <td><?= h($row['section'] ?: '-') ?></td>
  <td><?= h(classSemesterPairLabel(null, (int)($row['semester_id'] ?? 0), (int)($row['faculty_id'] ?? 0))) ?></td>
  <td><?= (int)$row['capacity'] ?></td>
  <td><span class="badge bg-secondary"><?= h(ucfirst($row['class_type'])) ?></span></td>
  <td><span class="badge bg-<?= $row['is_active'] ? 'success' : 'secondary' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span></td>
  <td class="text-nowrap">
    <?= renderRowActions([
        'view_url'    => attendanceHierarchyClassUrl((int)$row['id'], (int)($row['faculty_id'] ?? 0), (int)($row['department_id'] ?? 0)),
        'view_label'  => 'View',
        'edit_url'    => $editUrl,
        'delete_form' => $delForm,
    ]) ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
