<?php


require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';

requireLogin();
requireRole([ROLE_ATT_MGR, ROLE_DEPT_HEAD, ROLE_ADMIN]);

$roleId = (int)($_SESSION['role_id'] ?? 0);


if ($roleId !== ROLE_ATT_MGR) {
    require __DIR__.'/../admin/classes.php';
    return;
}

$db = getDB();
$facultyScope = currentFacultyScopeId();
$selfUrl = currentSectionPageUrl('classes.php');
$pageTitle = t('page_classes');

$selectedFacultyId    = $facultyScope ?: (int)($_GET['faculty_id'] ?? 0);
$selectedDepartmentId = (int)($_GET['department_id'] ?? 0);

$options     = reportFilterOptions(['faculty_id' => $selectedFacultyId ?: $facultyScope]);
$faculties   = $options['faculties'];
$departments = $options['departments'];

$where  = "WHERE 1=1";
$params = [];
if ($selectedFacultyId)      { $where .= " AND c.faculty_id=?";    $params[] = $selectedFacultyId; }
elseif ($facultyScope)       { $where .= " AND c.faculty_id=?";    $params[] = $facultyScope; }
if ($selectedDepartmentId)   { $where .= " AND c.department_id=?"; $params[] = $selectedDepartmentId; }

$rowsStmt = $db->prepare(
    "SELECT c.*, f.name AS faculty_name, d.name AS department_name, sem.name AS semester_name
       FROM classes c
       JOIN faculties f ON f.id = c.faculty_id
       LEFT JOIN departments d ON d.id = c.department_id
       LEFT JOIN semesters  sem ON sem.id = c.semester_id
       $where
       ORDER BY f.name, d.name, c.name, c.section"
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-chalkboard me-2"></i><?= t('classes') ?></h5>
  </div>
</div>

<?php
echo renderClusterContextBanner([
    'faculty_id'    => $selectedFacultyId,
    'department_id' => $selectedDepartmentId,
    'current_label' => 'Classes',
]);
?>

<?php if (!$facultyScope): ?>
<form method="GET" class="row g-2 mb-3">
  <div class="col-md-4">
    <select name="faculty_id" data-cascade="faculty" class="form-select form-select-sm">
      <option value="">All faculties</option>
      <?php foreach ($faculties as $faculty): ?>
        <option value="<?= (int)$faculty['id'] ?>" <?= $selectedFacultyId === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <select name="department_id" data-cascade="department" class="form-select form-select-sm">
      <option value="">All departments</option>
      <?php foreach ($departments as $department): ?>
        <option value="<?= (int)$department['id'] ?>" <?= $selectedDepartmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <button class="btn btn-sm btn-primary">Filter</button>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>Faculty</th>
          <th>Department</th>
          <th>Class</th>
          <th>Section</th>
          <th>Semester</th>
          <th>Capacity</th>
          <th>Type</th>
          <th>Status</th>
          <th>View</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No classes found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= h($row['faculty_name']) ?></td>
            <td><?= h($row['department_name'] ?? '-') ?></td>
            <td><?= h($row['name']) ?></td>
            <td><?= h($row['section'] ?: '-') ?></td>
            <td><?= h(classSemesterPairLabel(null, (int)($row['semester_id'] ?? 0), (int)($row['faculty_id'] ?? 0))) ?></td>
            <td><?= (int)$row['capacity'] ?></td>
            <td><span class="badge bg-secondary"><?= h(ucfirst($row['class_type'])) ?></span></td>
            <td>
              <span class="badge bg-<?= $row['is_active'] ? 'success' : 'secondary' ?>">
                <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td class="text-nowrap">
              <a href="<?= h(attendanceHierarchyClassUrl((int)$row['id'], (int)($row['faculty_id'] ?? 0), (int)($row['department_id'] ?? 0))) ?>"
                 class="btn btn-sm btn-outline-info" title="View">
                <i class="fas fa-eye"></i> View
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
