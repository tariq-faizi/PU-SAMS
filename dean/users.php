<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_DEPT_HEAD]);

$db = getDB();
$roleId = (int)($_SESSION['role_id'] ?? 0);
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$pageTitle = 'Faculty Users';
$action = $_GET['action'] ?? 'list';

function managerUsersCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

$allowedRoleIds = [ROLE_DEPT_HEAD, ROLE_ATT_MGR, ROLE_TEACHER, ROLE_STUDENT];
$allowedRoleSql = implode(',', array_map('intval', $allowedRoleIds));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $targetRoleId = (int)($_POST['role_id'] ?? 0);
        if (!in_array($targetRoleId, $allowedRoleIds, true) || !userCanManageTargetRole($targetRoleId)) {
            flashSet('danger', 'You are not allowed to manage the selected role.');
            redirect(BASE_URL.'/dean/users.php');
        }

        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
        $classId = (int)($_POST['class_id'] ?? 0) ?: null;
        $employeeId = trim($_POST['employee_id'] ?? '');
        $studentId = trim($_POST['student_id'] ?? '');
        $semesterId = null;

        $backAction = $id ? 'edit&id='.$id : 'add';
        $backUrl = BASE_URL.'/dean/users.php?action='.$backAction;
        if (!$id) {
            $_SESSION['user_form_data'] = [
                'username' => $username, 'full_name' => $fullName, 'email' => $email, 'phone' => $phone,
                'role_id' => $targetRoleId, 'department_id' => $departmentId,
                'class_id' => $classId, 'status' => $status,
                'employee_id' => $employeeId, 'student_id' => $studentId,
            ];
        }

        if ($username === '' || $fullName === '') {
            flashSet('danger', '⚠️ Username and Full Name are required.');
            redirect($backUrl);
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
            flashSet('danger', '⚠️ Username must be 3–60 characters: letters, numbers, _ . - only.');
            redirect($backUrl);
        }

        $dupCheck = $db->prepare("SELECT id FROM users WHERE username=? AND deleted_at IS NULL AND id<>? LIMIT 1");
        $dupCheck->execute([$username, $id]);
        if ($dupCheck->fetchColumn()) {
            flashSet('danger', '⚠️ Username «'.h($username).'» already exists. Please choose another.');
            redirect($backUrl);
        }
        if ($studentId !== '') {
            $dupStu = $db->prepare("SELECT id FROM users WHERE student_id=? AND deleted_at IS NULL AND id<>? LIMIT 1");
            $dupStu->execute([$studentId, $id]);
            if ($dupStu->fetchColumn()) {
                flashSet('danger', '⚠️ Student ID «'.h($studentId).'» is already registered.');
                redirect($backUrl);
            }
        }
        if ($employeeId !== '') {
            $dupEmp = $db->prepare("SELECT id FROM users WHERE employee_id=? AND deleted_at IS NULL AND id<>? LIMIT 1");
            $dupEmp->execute([$employeeId, $id]);
            if ($dupEmp->fetchColumn()) {
                flashSet('danger', '⚠️ Employee ID «'.h($employeeId).'» is already registered.');
                redirect($backUrl);
            }
        }

        if ($targetRoleId === ROLE_STUDENT) {
            if (!$classId) {
                flashSet('danger', 'Please assign a class for the student.');
                redirect(BASE_URL.'/dean/users.php?action=' . ($id ? 'edit&id='.$id : 'add'));
            }
            $classStmt = $db->prepare("SELECT faculty_id, department_id FROM classes WHERE id=? AND faculty_id=? LIMIT 1");
            $classStmt->execute([$classId, $facultyId]);
            $classRow = $classStmt->fetch();
            if (!$classRow) {
                flashSet('danger', 'Selected class is outside your faculty scope.');
                redirect(BASE_URL.'/dean/users.php');
            }
            $departmentId = (int)$classRow['department_id'] ?: null;
            $semesterId = resolveSemesterForClass($classId, (int)($_POST['semester_id'] ?? 0));
            if (!$semesterId) {
                flashSet('danger', 'Please pick one of the two valid semesters for the selected class.');
                redirect(BASE_URL.'/dean/users.php?action=' . ($id ? 'edit&id='.$id : 'add'));
            }
            $employeeId = '';
        } else {
            $classId = null;
            $studentId = '';
        }

        if ($departmentId) {
            $depStmt = $db->prepare("SELECT id FROM departments WHERE id=? AND faculty_id=? LIMIT 1");
            $depStmt->execute([$departmentId, $facultyId]);
            if (!$depStmt->fetchColumn()) {
                flashSet('danger', 'Selected department is outside your faculty scope.');
                redirect(BASE_URL.'/dean/users.php');
            }
        }

        try {
            if ($id > 0) {
                $owned = $db->prepare("SELECT role_id FROM users WHERE id=? AND faculty_id=? AND deleted_at IS NULL LIMIT 1");
                $owned->execute([$id, $facultyId]);
                $existing = $owned->fetch();
                if (!$existing || !in_array((int)$existing['role_id'], $allowedRoleIds, true)) {
                    flashSet('danger', 'The selected user is outside your authority.');
                    redirect(BASE_URL.'/dean/users.php');
                }
                $db->prepare("UPDATE users SET username=?, full_name=?, email=?, phone=?, role_id=?, faculty_id=?, department_id=?, class_id=?, semester_id=?, status=?, employee_id=?, student_id=?, updated_at=NOW() WHERE id=?")
                   ->execute([$username,$fullName,$email,$phone,$targetRoleId,$facultyId,$departmentId,$classId,$semesterId,$status,$employeeId,$studentId,$id]);
                if (!empty($_FILES['photo']['name'])) {
                    $old = $db->prepare("SELECT photo FROM users WHERE id=?");
                    $old->execute([$id]);
                    $oldPhoto = (string)($old->fetchColumn() ?: 'default.png');
                    $photoErr = '';
                    $fn = uploadPhoto($_FILES['photo'], $oldPhoto, $photoErr);
                    if ($fn) {
                        $db->prepare("UPDATE users SET photo=? WHERE id=?")->execute([$fn,$id]);
                    } elseif ($photoErr) {
                        flashSet('warning', '⚠️ Photo: '.$photoErr);
                    }
                }
                auditLog('faculty_user_update','users',$id,'Faculty-scoped user record updated');
                flashSet('success', '✅ User updated successfully.');
                unset($_SESSION['user_form_data']);
                if (function_exists('cacheBustDashboards')) cacheBustDashboards();
            } else {
                $password = trim($_POST['password'] ?? '');
                $errors = validatePasswordStrength($password);
                if ($errors) {
                    flashSet('danger', '⚠️ Password error: '.implode(' ', $errors));
                    redirect($backUrl);
                }
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("INSERT INTO users (username,password_hash,role_id,faculty_id,department_id,class_id,semester_id,full_name,email,phone,student_id,employee_id,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$username,$hash,$targetRoleId,$facultyId,$departmentId,$classId,$semesterId,$fullName,$email,$phone,$studentId,$employeeId,$status,$_SESSION['user_id']]);
                $newId = (int)$db->lastInsertId();
                if (!empty($_FILES['photo']['name'])) {
                    $photoErr = '';
                    $fn = uploadPhoto($_FILES['photo'], '', $photoErr);
                    if ($fn) {
                        $db->prepare("UPDATE users SET photo=? WHERE id=?")->execute([$fn,$newId]);
                    } elseif ($photoErr) {
                        flashSet('warning', '⚠️ Photo: '.$photoErr);
                    }
                }
                auditLog('faculty_user_create','users',$newId,'Faculty-scoped user created');
                flashSet('success', '✅ User created successfully.');
                unset($_SESSION['user_form_data']);
                if (function_exists('cacheBustDashboards')) cacheBustDashboards();
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate entry') !== false && stripos($msg, 'username') !== false) {
                flashSet('danger', '⚠️ This username already exists. Please choose another.');
            } elseif (stripos($msg, 'Duplicate entry') !== false) {
                flashSet('danger', '⚠️ Duplicate value: '.h($msg));
            } elseif (stripos($msg, 'foreign key') !== false || stripos($msg, 'constraint') !== false) {
                flashSet('danger', '⚠️ Database constraint — Faculty/Department/Class ID is invalid.');
            } else {
                flashSet('danger', '❌ Error saving user: '.h($msg));
            }
            redirect($backUrl);
        }
        redirect(BASE_URL.'/dean/users.php');
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $hard = (int)($_POST['hard'] ?? 0) === 1;
        $owned = $db->prepare("SELECT role_id FROM users WHERE id=? AND faculty_id=? AND role_id IN ($allowedRoleSql) AND deleted_at IS NULL LIMIT 1");
        $owned->execute([$id, $facultyId]);
        $existing = $owned->fetch();
        if ($existing) {
            $targetRole = (int)$existing['role_id'];
            if ($hard || $targetRole === ROLE_STUDENT) {
                try {
                    $db->beginTransaction();
                    $db->prepare("DELETE FROM attendance_records WHERE student_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM manager_attendance_edits WHERE student_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM session_tokens WHERE user_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM edit_requests WHERE requested_by=? OR reviewed_by=?")->execute([$id, $id]);
                    $db->prepare("DELETE FROM teacher_subject_assignments WHERE teacher_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM users WHERE id=? AND faculty_id=? AND role_id<>?")->execute([$id, $facultyId, ROLE_ADMIN]);
                    $db->commit();
                    auditLog('faculty_user_hard_delete','users',$id,'PUSAMS REQ 2.3: cascade delete from class/faculty/dept');
                    flashSet('success', '✅ User permanently removed.');
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    flashSet('danger', '❌ Delete error: '.$e->getMessage());
                }
            } else {
                $stmt = $db->prepare("UPDATE users SET deleted_at=NOW(), status='archived' WHERE id=? AND faculty_id=? AND role_id IN ($allowedRoleSql) AND deleted_at IS NULL");
                $stmt->execute([$id, $facultyId]);
                auditLog('faculty_user_delete','users',$id,'Faculty-scoped user archived');
                flashSet('success', '✅ User archived successfully.');
            }
        }
        redirect(BASE_URL.'/dean/users.php');
    }
}

$editUser = null;
if ($action === 'edit' && ($editId = (int)($_GET['id'] ?? 0))) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id=? AND faculty_id=? AND role_id IN ($allowedRoleSql) AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$editId, $facultyId]);
    $editUser = $stmt->fetch();
}

$isAddMode = ($action === 'add') || (($action === 'edit') && !$editUser);
if (($action === 'add' || $action === 'edit') && !empty($_SESSION['user_form_data']) && !$editUser) {
    $editUser = $_SESSION['user_form_data'];
    unset($_SESSION['user_form_data']);
}

$q = trim($_GET['q'] ?? '');
$filterRole = (int)($_GET['role'] ?? 0);
$filterDepartment = (int)($_GET['department'] ?? 0);
$filterClass = (int)($_GET['class_id'] ?? ($_GET['class'] ?? 0));
$filterStatus = $_GET['status'] ?? '';

$where = "WHERE u.deleted_at IS NULL AND u.faculty_id=? AND u.role_id IN ($allowedRoleSql)";
$params = [$facultyId];
if ($q !== '') {
    $where .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ? OR u.employee_id LIKE ?)";
    $like = "%{$q}%";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($filterRole && in_array($filterRole, $allowedRoleIds, true)) { $where .= " AND u.role_id=?"; $params[] = $filterRole; }
if ($filterDepartment) { $where .= " AND u.department_id=?"; $params[] = $filterDepartment; }
if ($filterClass) { $where .= " AND u.class_id=?"; $params[] = $filterClass; }
if ($filterStatus !== '') { $where .= " AND u.status=?"; $params[] = $filterStatus; }

$usersStmt = $db->prepare("SELECT u.*, r.label as role_label, d.name as department_name, c.name as class_name, c.section as class_section
    FROM users u
    JOIN roles r ON r.id=u.role_id
    LEFT JOIN departments d ON d.id=u.department_id
    LEFT JOIN classes c ON c.id=u.class_id
    $where
    ORDER BY u.full_name ASC");
$usersStmt->execute($params);
$users = $usersStmt->fetchAll();
$users = relabelRoleRows($users, 'role_id', 'role_label');

$roleRows = $db->query("SELECT id,label FROM roles WHERE id IN ($allowedRoleSql) ORDER BY id")->fetchAll();
$roleRows = relabelRoleRows($roleRows);
$departments = $db->prepare("SELECT id,name FROM departments WHERE faculty_id=? AND is_active=1 ORDER BY name");
$departments->execute([$facultyId]);
$departments = $departments->fetchAll();
$classSql = "SELECT c.id,c.name,c.section,d.name as department_name FROM classes c LEFT JOIN departments d ON d.id=c.department_id WHERE c.faculty_id=? AND c.is_active=1";
$classParams = [$facultyId];
if ($filterDepartment) { $classSql .= " AND c.department_id=?"; $classParams[] = $filterDepartment; }
$classSql .= " ORDER BY c.name,c.section";
$classes = $db->prepare($classSql);
$classes->execute($classParams);
$classes = $classes->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-users me-2"></i><?= h($pageTitle) ?></h5>
  </div>
  <?= renderActionDropdown([
        'add_url'  => '?action=add',
        'base_url' => BASE_URL.'/dean/users.php',
  ]) ?>
</div>
<?= renderModeBanner() ?>
<?php
$_roleLabelMap = roleOptionMap();
echo renderClusterContextBanner([
    'faculty_id'    => $facultyId,
    'department_id' => $filterDepartment,
    'class_id'      => $filterClass,
    'role'          => $filterRole,
    'role_label'    => $_roleLabelMap[$filterRole] ?? '',
    'status'        => $filterStatus,
    'current_label' => 'Users',
]);
?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card mb-4"><div class="card-body">
  <form method="POST" enctype="multipart/form-data">
    <?php managerUsersCsrf(); ?><input type="hidden" name="act" value="save"/><input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)uploadPhotoMaxBytes() ?>"/>
    <?php if ($editUser && !empty($editUser['id'])): ?><input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>"/><?php endif; ?>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Username *</label><input name="username" class="form-control" required value="<?= h($editUser['username'] ?? '') ?>"/></div>
      <div class="col-md-4"><label class="form-label">Full name<?= $editUser ? ' <small class="text-muted">(locked)</small>' : ' *' ?></label><input name="full_name" class="form-control" <?= $editUser ? '' : 'required' ?> value="<?= h($editUser['full_name'] ?? '') ?>"<?= $editUser ? ' readonly title="Personal data cannot be changed here — user must submit a profile edit request."' : '' ?>/></div>
      <div class="col-md-4"><label class="form-label">Email<?= $editUser ? ' <small class="text-muted">(locked)</small>' : '' ?></label><input name="email" type="email" class="form-control" value="<?= h($editUser['email'] ?? '') ?>"<?= $editUser ? ' readonly' : '' ?>/></div>
      <div class="col-md-3"><label class="form-label">Phone<?= $editUser ? ' <small class="text-muted">(locked)</small>' : '' ?></label><input name="phone" class="form-control" value="<?= h($editUser['phone'] ?? '') ?>"<?= $editUser ? ' readonly' : '' ?>/></div>
      <div class="col-md-2"><label class="form-label">Role</label>
        <select name="role_id" class="form-select form-select-sm role-select-compact" required>
          <?php foreach ($roleRows as $row): ?>
          <option value="<?= (int)$row['id'] ?>" <?= (int)($editUser['role_id'] ?? ($roleId === ROLE_ATT_MGR ? ROLE_STUDENT : ROLE_TEACHER)) === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label">Department</label>
        <select id="deanUserFormDepartment" name="department_id" class="form-select" data-cascade="department" data-cascade-target="deanUserFormClass" data-preselect="<?= h((string)($editUser['department_id'] ?? 0)) ?>" data-placeholder="-- None --">
          <option value="">-- None --</option>
          <?php foreach ($departments as $department): ?>
          <option value="<?= (int)$department['id'] ?>" <?= (int)($editUser['department_id'] ?? 0) === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label">Class</label>
        <select id="deanUserFormClass" name="class_id" class="form-select" data-cascade="class" data-cascade-source="deanUserFormDepartment" data-cascade-target="deanUserFormSemester" data-preselect="<?= h((string)($editUser['class_id'] ?? 0)) ?>" data-placeholder="-- None --">
          <option value="">-- None --</option>
          <?php foreach ($classes as $class): ?>
          <option value="<?= (int)$class['id'] ?>" <?= (int)($editUser['class_id'] ?? 0) === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim(($class['department_name'] ? $class['department_name'].' / ' : '').$class['name'].' '.($class['section'] ?? ''))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label">Semester</label>
        <select id="deanUserFormSemester" name="semester_id" class="form-select" data-cascade="semester" data-cascade-source="deanUserFormClass" data-preselect="<?= h((string)($editUser['semester_id'] ?? 0)) ?>" data-placeholder="-- None --">
          <option value="">-- None --</option>
          <?php foreach (listSemestersByClass((int)($editUser['class_id'] ?? 0)) as $sem):
            /* (v24) د سمیستر واضح لیبل */
            $semLbl = $sem['name'] . (!empty($sem['semester_no']) ? ' — Semester '.(int)$sem['semester_no'] : '');
          ?>
          <option value="<?= (int)$sem['id'] ?>" <?= (int)($editUser['semester_id'] ?? 0) === (int)$sem['id'] ? 'selected' : '' ?>><?= h($semLbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach (['active','inactive','suspended','archived'] as $status): ?>
          <option value="<?= h($status) ?>" <?= ($editUser['status'] ?? 'active') === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label">Employee ID</label><input name="employee_id" class="form-control" value="<?= h($editUser['employee_id'] ?? '') ?>"/></div>
      <div class="col-md-3"><label class="form-label">Student ID</label><input name="student_id" class="form-control" value="<?= h($editUser['student_id'] ?? '') ?>"/></div>
      <?php if ($isAddMode): ?><div class="col-md-3"><label class="form-label">Password *</label><input name="password" type="password" class="form-control" required placeholder="Strong password"/></div><?php endif; ?>
      <div class="col-md-3"><label class="form-label">Photo <small class="text-muted">(JPG/PNG/WEBP, max 2MB)</small></label><input name="photo" type="file" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"/></div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save</button><a href="<?= BASE_URL ?>/dean/users.php" class="btn btn-secondary">Cancel</a></div>
    </div>
  </form>
</div></div>
<?php endif; ?>

<form method="GET" class="row g-2 mb-3">
  <input type="hidden" name="action" value="list"/>
  <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="Search users" value="<?= h($q) ?>"/></div>
  <div class="col-md-2"><select name="role" class="form-select form-select-sm"><option value="">All roles</option><?php foreach ($roleRows as $row): ?><option value="<?= (int)$row['id'] ?>" <?= $filterRole === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select name="department" data-cascade="department" class="form-select form-select-sm"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>" <?= $filterDepartment === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select name="class_id" data-cascade="class" class="form-select form-select-sm"><option value="">All classes</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $filterClass === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim(($class['department_name'] ? $class['department_name'].' / ' : '').$class['name'].' '.($class['section'] ?? ''))) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-1"><select name="status" class="form-select form-select-sm"><option value="">All status</option><?php foreach (['active','inactive','suspended','archived'] as $status): ?><option value="<?= h($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
</form>

<div class="card"><div class="table-responsive">
  <table class="table table-hover table-sm mb-0 align-middle">
    <thead><tr><th>#</th><th>Name</th><th>Role</th><th>Department</th><th>Class</th><th>Status</th><th>Identifiers</th><th>Views</th></tr></thead>
    <tbody>
    <?php if (!$users): ?><tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?>
    <?php foreach ($users as $user):
        $delForm = '<form method="POST" class="d-inline"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/><input type="hidden" name="act" value="delete"/><input type="hidden" name="id" value="'.(int)$user['id'].'"/><button class="btn btn-sm btn-danger" data-confirm="Archive this user?"><i class="fas fa-archive me-1"></i>Delete</button></form>';
    ?>
    <tr>
      <td><?= (int)$user['id'] ?></td>
      <td><?= h($user['full_name']) ?><div class="small text-muted">@<?= h($user['username']) ?></div></td>
      <td><span class="badge bg-info text-dark"><?= h($user['role_label']) ?></span></td>
      <td><?= h($user['department_name'] ?? '-') ?></td>
      <td><?= h($user['class_name'] ?? '-') ?><?= !empty($user['class_section']) ? ' / '.h($user['class_section']) : '' ?></td>
      <td><span class="badge bg-<?= attendanceStatusClass($user['status']) ?>"><?= h(ucfirst($user['status'])) ?></span></td>
      <td><small><?= h($user['employee_id'] ?: ($user['student_id'] ?: '-')) ?></small></td>
      <td class="text-nowrap">
        <?= renderRowActions([
            'view_url'    => attendanceFocusedUserViewUrl($user, BASE_URL.'/dean/user_view.php?id='.(int)$user['id']),
            'view_label'  => 'View',
            'edit_url'    => '?action=edit&id='.(int)$user['id'],
            'delete_form' => $delForm,
        ]) ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
