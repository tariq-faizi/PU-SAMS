<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('manage_faculties');
$db = getDB();

// Ensure dean_user_id column exists (auto-migrate)
try {
    $db->query("SELECT dean_user_id FROM faculties LIMIT 1");
} catch (Throwable $e) {
    $db->exec("ALTER TABLE faculties ADD COLUMN dean_user_id INT UNSIGNED DEFAULT NULL AFTER description");
}

$pageTitle = t('page_faculties');
$action = $_GET['action'] ?? 'list';
function facultyCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $universityId = (int)($_POST['university_id'] ?? 1) ?: 1;
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $deanUserId = (int)($_POST['dean_user_id'] ?? 0) ?: null;
        if ($name === '' || $code === '') {
            flashSet('danger', 'Faculty name and code are required.');
            redirect(BASE_URL.'/admin/faculties.php?action=' . ($id ? 'edit&id='.$id : 'add'));
        }
        if ($id > 0) {
            $db->prepare("UPDATE faculties SET university_id=?, name=?, code=?, description=?, dean_user_id=?, is_active=1 WHERE id=?")
               ->execute([$universityId,$name,$code,$description,$deanUserId,$id]);
            auditLog('faculty_update','faculties',$id,'Faculty updated');
        } else {
            $db->prepare("INSERT INTO faculties (university_id,name,code,description,dean_user_id,is_active) VALUES (?,?,?,?,?,1)")
               ->execute([$universityId,$name,$code,$description,$deanUserId]);
            $id = (int)$db->lastInsertId();
            auditLog('faculty_create','faculties',$id,'Faculty created');
        }
        flashSet('success', 'Faculty saved successfully.');
        redirect(BASE_URL.'/admin/faculties.php');
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE faculties SET is_active=0 WHERE id=?")->execute([$id]);
        auditLog('faculty_delete','faculties',$id,'Faculty deactivated');
        flashSet('success', 'Faculty deactivated.');
        redirect(BASE_URL.'/admin/faculties.php');
    }
}
$editRow = null;
if ($action === 'edit' && ($editId = (int)($_GET['id'] ?? 0))) {
    $stmt = $db->prepare("SELECT * FROM faculties WHERE id=? LIMIT 1");
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch();
}
$universities = $db->query("SELECT id,name FROM universities WHERE is_active=1 ORDER BY name")->fetchAll();
// Load all deans (role_id = ROLE_DEPT_HEAD = 2) for the dean dropdown
$deans = $db->query("SELECT id, full_name, username FROM users WHERE role_id=".ROLE_DEPT_HEAD." AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll();

$rows = $db->query("SELECT f.*, u.name as university_name,
    d.full_name as dean_name,
    (SELECT COUNT(*) FROM departments dp WHERE dp.faculty_id=f.id AND dp.is_active=1) as department_count,
    (SELECT COUNT(*) FROM classes c WHERE c.faculty_id=f.id AND c.is_active=1) as class_count,
    (SELECT COUNT(*) FROM subjects s WHERE s.faculty_id=f.id AND s.is_active=1) as subject_count
    FROM faculties f
    LEFT JOIN universities u ON u.id=f.university_id
    LEFT JOIN users d ON d.id=f.dean_user_id
    ORDER BY f.name")->fetchAll();
require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-building-columns me-2"></i><?= t('faculties') ?></h5>
  </div>
  <?= renderActionDropdown([
        'add_url'  => '?action=add',
        'base_url' => BASE_URL.'/admin/faculties.php',
  ]) ?>
</div>
<?= renderModeBanner() ?>
<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card mb-4"><div class="card-body">
<form method="POST">
  <?php facultyCsrf(); ?>
  <input type="hidden" name="act" value="save"/>
  <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"/><?php endif; ?>
  <div class="row g-3">
    <div class="col-md-3">
      <label class="form-label">University</label>
      <select name="university_id" class="form-select">
        <?php foreach ($universities as $university): ?>
          <option value="<?= (int)$university['id'] ?>" <?= (int)($editRow['university_id'] ?? 1) === (int)$university['id'] ? 'selected' : '' ?>><?= h($university['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Faculty name <span class="text-danger">*</span></label>
      <input name="name" class="form-control" required value="<?= h($editRow['name'] ?? '') ?>"/>
    </div>
    <div class="col-md-2">
      <label class="form-label">Code <span class="text-danger">*</span></label>
      <input name="code" class="form-control" required value="<?= h($editRow['code'] ?? '') ?>"/>
    </div>
    <div class="col-md-3">

      
    </div>
    <div class="col-md-12">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="3"><?= h($editRow['description'] ?? '') ?></textarea>
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Save</button>
      <a href="<?= BASE_URL ?>/admin/faculties.php" class="btn btn-secondary">Cancel</a>
    </div>
  </div>
</form>
</div></div>
<?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead><tr><th>University</th><th>Faculty</th><th>Code</th><th>Dean / Head</th><th>Departments</th><th>Classes</th><th>Subjects</th><th>Status</th><th>Views</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No faculties found.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $delForm = '<form method="POST" class="d-inline"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/><input type="hidden" name="act" value="delete"/><input type="hidden" name="id" value="'.(int)$row['id'].'"/><button class="btn btn-sm btn-danger" data-confirm="Deactivate this faculty?"><i class="fas fa-archive me-1"></i>Delete</button></form>';
?>
<tr>
  <td><?= h($row['university_name'] ?? '-') ?></td>
  <td><div class="fw-semibold"><?= h($row['name']) ?></div><div class="small text-muted"><?= h($row['description'] ?? '') ?></div></td>
  <td><?= h($row['code']) ?></td>
  <td><?= $row['dean_name'] ? '<span class="badge bg-info text-dark">'.h($row['dean_name']).'</span>' : '<span class="text-muted">—</span>' ?></td>
  <td><?= (int)$row['department_count'] ?></td>
  <td><?= (int)$row['class_count'] ?></td>
  <td><?= (int)$row['subject_count'] ?></td>
  <td><span class="badge bg-<?= $row['is_active'] ? 'success' : 'secondary' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span></td>
  <td class="text-nowrap">
    <?= renderRowActions([
        'view_url'    => attendanceHierarchyFacultyUrl((int)$row['id']),
        'view_label'  => 'View',
        'edit_url'    => '?action=edit&id='.(int)$row['id'],
        'delete_form' => $delForm,
    ]) ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
