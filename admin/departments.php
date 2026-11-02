<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('manage_departments');

$db = getDB();
$roleId = (int)($_SESSION['role_id'] ?? 0);
$facultyScope = currentFacultyScopeId();
$selfUrl = currentSectionPageUrl('departments.php');
$pageTitle = t('page_departments');
$action = $_GET['action'] ?? 'list';

function departmentCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

$selectedFacultyId = $facultyScope ?: (int)($_GET['faculty_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $facultyId = $facultyScope ?: (int)($_POST['faculty_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $headId = (int)($_POST['head_id'] ?? 0) ?: null;
        if (!$facultyId || $name === '' || $code === '') {
            flashSet('danger', 'Faculty, name, and code are required.');
            redirect($selfUrl.'?action=' . ($id ? 'edit&id='.$id : 'add'));
        }
        if ($headId) {
            $headStmt = $db->prepare("SELECT id FROM users WHERE id=? AND faculty_id=? AND role_id=? AND deleted_at IS NULL LIMIT 1");
            $headStmt->execute([$headId, $facultyId, ROLE_DEPT_HEAD]);
            if (!$headStmt->fetchColumn()) $headId = null;
        }
        if ($id > 0) {
            if ($facultyScope) {
                $ownedStmt = $db->prepare("SELECT id FROM departments WHERE id=? AND faculty_id=? LIMIT 1");
                $ownedStmt->execute([$id, $facultyScope]);
                if (!$ownedStmt->fetchColumn()) {
                    flashSet('danger', 'This department is outside your faculty scope.');
                    redirect($selfUrl);
                }
            }
            $db->prepare("UPDATE departments SET faculty_id=?, name=?, code=?, head_id=? WHERE id=?")->execute([$facultyId,$name,$code,$headId,$id]);
            auditLog('department_update','departments',$id,'Department updated');
        } else {
            $db->prepare("INSERT INTO departments (faculty_id,name,code,head_id,is_active) VALUES (?,?,?,?,1)")->execute([$facultyId,$name,$code,$headId]);
            $id = (int)$db->lastInsertId();
            auditLog('department_create','departments',$id,'Department created');
        }
        flashSet('success', 'Department saved successfully.');
        redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $delSql = $facultyScope ? "UPDATE departments SET is_active=0 WHERE id=? AND faculty_id=?" : "UPDATE departments SET is_active=0 WHERE id=?";
        $delParams = $facultyScope ? [$id, $facultyScope] : [$id];
        $stmt = $db->prepare($delSql);
        $stmt->execute($delParams);
        if ($facultyScope && $stmt->rowCount() === 0) {
            flashSet('danger', 'This department is outside your faculty scope.');
            redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
        }
        auditLog('department_delete','departments',$id,'Department deactivated');
        flashSet('success', 'Department deactivated.');
        redirect($selfUrl.($selectedFacultyId ? '?faculty_id='.$selectedFacultyId : ''));
    }
}

$editRow = null;
if ($action === 'edit' && ($editId = (int)($_GET['id'] ?? 0))) {
    $editSql = "SELECT * FROM departments WHERE id=?" . ($facultyScope ? " AND faculty_id=?" : "") . " LIMIT 1";
    $editParams = $facultyScope ? [$editId, $facultyScope] : [$editId];
    $stmt = $db->prepare($editSql);
    $stmt->execute($editParams);
    $editRow = $stmt->fetch();
}

$facultySql = "SELECT id,name FROM faculties WHERE is_active=1";
$facultyParams = [];
if ($facultyScope) { $facultySql .= " AND id=?"; $facultyParams[] = $facultyScope; }
$facultySql .= " ORDER BY name";
$facultiesStmt = $db->prepare($facultySql); $facultiesStmt->execute($facultyParams); $faculties = $facultiesStmt->fetchAll();

$headsSql = "SELECT id,full_name,faculty_id FROM users WHERE role_id=? AND status='active' AND deleted_at IS NULL";
$headsParams = [ROLE_DEPT_HEAD];
if ($facultyScope) { $headsSql .= " AND faculty_id=?"; $headsParams[] = $facultyScope; }
$headsSql .= " ORDER BY full_name";
$headsStmt = $db->prepare($headsSql); $headsStmt->execute($headsParams); $heads = $headsStmt->fetchAll();

$where = "WHERE 1=1"; $params = [];
if ($selectedFacultyId) { $where .= " AND d.faculty_id=?"; $params[] = $selectedFacultyId; }
elseif ($facultyScope) { $where .= " AND d.faculty_id=?"; $params[] = $facultyScope; }
$rowsStmt = $db->prepare("SELECT d.*, f.name as faculty_name, u.full_name as head_name
    FROM departments d
    JOIN faculties f ON f.id=d.faculty_id
    LEFT JOIN users u ON u.id=d.head_id
    $where
    ORDER BY f.name,d.name");
$rowsStmt->execute($params); $rows = $rowsStmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-building me-2"></i><?= t('departments') ?></h5>
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
    'current_label' => 'Departments',
]);
?>

<?php if (!$facultyScope): ?>
<form method="GET" class="row g-2 mb-3"><div class="col-md-4"><select name="faculty_id" data-cascade="faculty" class="form-select form-select-sm"><option value="">All faculties</option><?php foreach ($faculties as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= $selectedFacultyId === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-sm btn-primary">Filter</button></div></form>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card mb-4"><div class="card-body">
  <form method="POST">
    <?php departmentCsrf(); ?><input type="hidden" name="act" value="save"/>
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"/><?php endif; ?>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Faculty *</label><select name="faculty_id" class="form-select" <?= $facultyScope ? 'disabled' : '' ?>><?php foreach ($faculties as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= (int)($editRow['faculty_id'] ?? ($selectedFacultyId ?: $facultyScope ?: 0)) === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select><?php if ($facultyScope): ?><input type="hidden" name="faculty_id" value="<?= (int)$facultyScope ?>"/><?php endif; ?></div>
      <div class="col-md-4"><label class="form-label">Department name *</label><input name="name" class="form-control" required value="<?= h($editRow['name'] ?? '') ?>"/></div>
      <div class="col-md-2"><label class="form-label">Code *</label><input name="code" class="form-control" required value="<?= h($editRow['code'] ?? '') ?>"/></div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save</button><a href="<?= h($selfUrl) ?><?= $selectedFacultyId ? '?faculty_id='.$selectedFacultyId : '' ?>" class="btn btn-secondary">Cancel</a></div>
    </div>
  </form>
</div></div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
<thead><tr><th>#</th><th>Faculty</th><th>Name</th><th>Code</th><th>Dean</th><th>Status</th><th>Views</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No departments found.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $delForm = '<form method="POST" class="d-inline"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/><input type="hidden" name="act" value="delete"/><input type="hidden" name="id" value="'.(int)$row['id'].'"/><button class="btn btn-sm btn-danger" data-confirm="Deactivate this department?"><i class="fas fa-archive me-1"></i>Delete</button></form>';
    $editUrl = '?action=edit&id='.(int)$row['id'].($selectedFacultyId ? '&faculty_id='.$selectedFacultyId : '');
?>
<tr>
  <td><?= (int)$row['id'] ?></td>
  <td><?= h($row['faculty_name']) ?></td>
  <td><?= h($row['name']) ?></td>
  <td><code><?= h($row['code']) ?></code></td>
  <td><?= h($row['head_name'] ?? '-') ?></td>
  <td><span class="badge bg-<?= $row['is_active'] ? 'success' : 'secondary' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span></td>
  <td class="text-nowrap">
    <?= renderRowActions([
        'view_url'    => attendanceHierarchyDepartmentUrl((int)($row['faculty_id'] ?? 0), (int)$row['id']),
        'view_label'  => 'View',
        'edit_url'    => $editUrl,
        'delete_form' => $delForm,
    ]) ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
