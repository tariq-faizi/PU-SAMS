<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('manage_users');

$db = getDB();
$pageTitle = 'Users';
$action = $_GET['action'] ?? 'list';
$selectedFacultyId = (int)($_GET['faculty_id'] ?? 0);
$selectedDepartmentId = (int)($_GET['department_id'] ?? 0);
$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedRoleId = (int)($_GET['role'] ?? 0);
$selectedStatus = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
// Build filter query string for redirect-back-to-list after CRUD
$filterQS = http_build_query(array_filter([
    'faculty_id'    => $selectedFacultyId ?: null,
    'department_id' => $selectedDepartmentId ?: null,
    'class_id'      => $selectedClassId ?: null,
    'role'          => $selectedRoleId ?: null,
    'status'        => $selectedStatus ?: null,
    'q'             => $q ?: null,
], fn($v) => $v !== null && $v !== ''));
$filterQS = $filterQS ? '&'.$filterQS : '';
$options = reportFilterOptions([
    'faculty_id' => $selectedFacultyId ?: null,
    'department_id' => $selectedDepartmentId ?: null,
    'class_id' => $selectedClassId ?: null,
]);

function adminUsersCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);
        if (!userCanManageTargetRole($roleId)) {
            flashSet('danger', 'Vice-Chancellor page cannot assign another Vice-Chancellor account from here.');
            redirect(BASE_URL.'/admin/users.php?action=list'.$filterQS);
        }
        $facultyId = (int)($_POST['faculty_id'] ?? 0) ?: null;
        $departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
        $classId = (int)($_POST['class_id'] ?? 0) ?: null;
        $semesterId = (int)($_POST['semester_id'] ?? 0) ?: null;
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $employeeId = trim($_POST['employee_id'] ?? '');
        $studentId = trim($_POST['student_id'] ?? '');

        
        $backAction = $id ? 'edit&id='.$id : 'add';
        $backUrl = BASE_URL.'/admin/users.php?action='.$backAction;
        if (!$id) {
            
            $_SESSION['user_form_data'] = [
                'username' => $username, 'full_name' => $fullName, 'email' => $email, 'phone' => $phone,
                'role_id' => $roleId, 'faculty_id' => $facultyId, 'department_id' => $departmentId,
                'class_id' => $classId, 'semester_id' => $semesterId, 'status' => $status,
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
        if (!$roleId) {
            flashSet('danger', '⚠️ Please select a user role.');
            redirect($backUrl);
        }

        if ($roleId === ROLE_STUDENT) {
            if (!$classId) {
                flashSet('danger', 'Please assign a class to the student.');
                redirect($backUrl);
            }
            $classStmt = $db->prepare("SELECT faculty_id,department_id FROM classes WHERE id=? LIMIT 1");
            $classStmt->execute([$classId]);
            $classRow = $classStmt->fetch();
            if ($classRow) {
                $facultyId = (int)$classRow['faculty_id'] ?: $facultyId;
                $departmentId = (int)$classRow['department_id'] ?: $departmentId;
                $semesterId = resolveSemesterForClass($classId, $semesterId);
            }
            if (!$semesterId) {
                flashSet('danger', 'Please pick one of the two valid semesters for the selected class.');
                redirect($backUrl);
            }
            $employeeId = '';
        } else {
            $classId = null;
            $studentId = '';
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

        try {
            if ($id > 0) {
                // Personal data (full_name/email/phone) are locked; only user can change via profile edit request
                $db->prepare("UPDATE users SET username=?, role_id=?, faculty_id=?, department_id=?, class_id=?, semester_id=?, status=?, employee_id=?, student_id=?, updated_at=NOW() WHERE id=?")
                   ->execute([$username,$roleId,$facultyId,$departmentId,$classId,$semesterId,$status,$employeeId,$studentId,$id]);
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
                auditLog('user_update','users',$id,'User updated by Vice-Chancellor');
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
                   ->execute([$username,$hash,$roleId,$facultyId,$departmentId,$classId,$semesterId,$fullName,$email,$phone,$studentId,$employeeId,$status,$_SESSION['user_id']]);
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
                auditLog('user_create','users',$newId,'User created by Vice-Chancellor');
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
        redirect(BASE_URL.'/admin/users.php?action=list'.$filterQS);
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $hard = (int)($_POST['hard'] ?? 0) === 1;
        $roleStmt = $db->prepare("SELECT role_id FROM users WHERE id=? LIMIT 1");
        $roleStmt->execute([$id]);
        $targetRole = (int)($roleStmt->fetchColumn() ?: 0);
        if ($id > 0 && $targetRole !== ROLE_ADMIN) {
            if ($hard || $targetRole === ROLE_STUDENT) {
                try {
                    $db->beginTransaction();
                    $db->prepare("DELETE FROM attendance_records WHERE student_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM attendance_history WHERE student_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM manager_attendance_edits WHERE student_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM session_tokens WHERE user_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM password_reset_tokens WHERE user_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM edit_requests WHERE requested_by=? OR reviewed_by=?")->execute([$id, $id]);
                    $db->prepare("DELETE FROM teacher_subject_assignments WHERE teacher_id=?")->execute([$id]);
                    $db->prepare("DELETE FROM users WHERE id=? AND role_id<>?")->execute([$id, ROLE_ADMIN]);
                    $db->commit();
                    auditLog('user_hard_delete','users',$id,'PUSAMS REQ 2.3: cascade delete from class/faculty/dept');
                    flashSet('success', '✅ User permanently removed.');
                    if (function_exists('cacheBustDashboards')) cacheBustDashboards();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    flashSet('danger', '❌ Delete error: '.$e->getMessage());
                }
            } else {
                $db->prepare("UPDATE users SET deleted_at=NOW(), status='archived' WHERE id=? AND role_id<>? AND deleted_at IS NULL")->execute([$id, ROLE_ADMIN]);
                auditLog('user_delete','users',$id,'User archived by Vice-Chancellor');
                flashSet('success', '✅ User archived successfully.');
                if (function_exists('cacheBustDashboards')) cacheBustDashboards();
            }
        }
        redirect(BASE_URL.'/admin/users.php?action=list'.$filterQS);
    }
}

$editRow = null;
if ($action === 'edit' && ($editId = (int)($_GET['id'] ?? 0))) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch();
}

$isAddMode = ($action === 'add') || (($action === 'edit') && !$editRow);
if (($action === 'add' || $action === 'edit') && !empty($_SESSION['user_form_data']) && !$editRow) {
    $editRow = $_SESSION['user_form_data'];
    unset($_SESSION['user_form_data']);
}

$where = "WHERE u.deleted_at IS NULL"; $params = [];
if ($q !== '') { $where .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ? OR u.employee_id LIKE ?)"; $like = "%{$q}%"; array_push($params,$like,$like,$like,$like,$like); }
if ($selectedFacultyId) { $where .= " AND u.faculty_id=?"; $params[] = $selectedFacultyId; }
if ($selectedDepartmentId) { $where .= " AND u.department_id=?"; $params[] = $selectedDepartmentId; }
if ($selectedClassId) { $where .= " AND u.class_id=?"; $params[] = $selectedClassId; }
if ($selectedRoleId) { $where .= " AND u.role_id=?"; $params[] = $selectedRoleId; }
if ($selectedStatus !== '') { $where .= " AND u.status=?"; $params[] = $selectedStatus; }
$stmt = $db->prepare("SELECT u.*, r.label as role_label, f.name as faculty_name, d.name as department_name, c.name as class_name, c.section as class_section, sem.name as semester_name
    FROM users u
    JOIN roles r ON r.id=u.role_id
    LEFT JOIN faculties f ON f.id=u.faculty_id
    LEFT JOIN departments d ON d.id=u.department_id
    LEFT JOIN classes c ON c.id=u.class_id
    LEFT JOIN semesters sem ON sem.id=u.semester_id
    $where
    ORDER BY u.created_at DESC");
$stmt->execute($params); $rows = $stmt->fetchAll();
$rows = relabelRoleRows($rows, 'role_id', 'role_label');
$roles = $db->query("SELECT id,label FROM roles WHERE id<>".ROLE_ADMIN." ORDER BY id")->fetchAll();
$roles = relabelRoleRows($roles);
require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-users me-2"></i><?= t('users') ?></h5>
  </div>
  <?= renderActionDropdown([
        'add_url'  => '?action=add',
        'base_url' => BASE_URL.'/admin/users.php',
  ]) ?>
</div>
<?= renderModeBanner() ?>
<?php
$_roleLabelMap = roleOptionMap();
echo renderClusterContextBanner([
    'faculty_id'    => $selectedFacultyId,
    'department_id' => $selectedDepartmentId,
    'class_id'      => $selectedClassId,
    'role'          => $selectedRoleId,
    'role_label'    => $_roleLabelMap[$selectedRoleId] ?? '',
    'status'        => $selectedStatus,
    'current_label' => 'Users',
]);
?>
<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card mb-4"><div class="card-body"><form method="POST" enctype="multipart/form-data"><?php adminUsersCsrf(); ?><input type="hidden" name="act" value="save"/><input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)uploadPhotoMaxBytes() ?>"/><?php if ($editRow && !empty($editRow['id'])): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"/><?php endif; ?><div class="row g-3"><div class="col-md-3"><label class="form-label">Username *</label><input name="username" class="form-control" required value="<?= h($editRow['username'] ?? '') ?>"/></div><div class="col-md-3"><label class="form-label">Full name *</label><input name="full_name" class="form-control" required value="<?= h($editRow['full_name'] ?? '') ?>"<?= $editRow ? ' readonly title="Cannot be changed here — user must submit a profile edit request"' : '' ?>/><?php if ($editRow): ?><?php endif; ?></div><div class="col-md-3"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="<?= h($editRow['email'] ?? '') ?>"<?= $editRow ? ' readonly' : '' ?>/></div><div class="col-md-3"><label class="form-label">Phone</label><input name="phone" class="form-control" value="<?= h($editRow['phone'] ?? '') ?>"<?= $editRow ? ' readonly' : '' ?>/></div><div class="col-md-2"><label class="form-label">Role</label><select name="role_id" class="form-select form-select-sm role-select-compact"><?php foreach ($roles as $role): ?><option value="<?= (int)$role['id'] ?>" <?= (int)($editRow['role_id'] ?? ROLE_TEACHER) === (int)$role['id'] ? 'selected' : '' ?>><?= h($role['label']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Faculty</label><select id="adminUserFormFaculty" name="faculty_id" class="form-select" data-cascade="faculty" data-cascade-target="adminUserFormDepartment" data-preselect="<?= h((string)($editRow['faculty_id'] ?? 0)) ?>" data-placeholder="Select faculty"><option value="">-- None --</option><?php foreach ($options['faculties'] as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= (int)($editRow['faculty_id'] ?? 0) === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Department</label><select id="adminUserFormDepartment" name="department_id" class="form-select" data-cascade="department" data-cascade-source="adminUserFormFaculty" data-cascade-target="adminUserFormClass" data-preselect="<?= h((string)($editRow['department_id'] ?? 0)) ?>" data-placeholder="Select department"><option value="">-- None --</option><?php foreach ($options['departments'] as $department): ?><option value="<?= (int)$department['id'] ?>" <?= (int)($editRow['department_id'] ?? 0) === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Class (students only)</label><select id="adminUserFormClass" name="class_id" class="form-select" data-cascade="class" data-cascade-source="adminUserFormDepartment" data-cascade-target="adminUserFormSemester" data-preselect="<?= h((string)($editRow['class_id'] ?? 0)) ?>" data-placeholder="Select class"><option value="">-- None --</option><?php foreach ($options['classes'] as $class): ?><option value="<?= (int)$class['id'] ?>" <?= (int)($editRow['class_id'] ?? 0) === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Semester</label><select id="adminUserFormSemester" name="semester_id" class="form-select" data-cascade="semester" data-cascade-source="adminUserFormClass" data-preselect="<?= h((string)($editRow['semester_id'] ?? 0)) ?>" data-placeholder="Select semester"><option value="">-- None --</option><?php foreach (listSemestersByClass((int)($editRow['class_id'] ?? 0)) as $sem): /* (v24) د سمیستر واضح لیبل */ $semLbl = $sem['name'] . (!empty($sem['semester_no']) ? ' — Semester '.(int)$sem['semester_no'] : ''); ?><option value="<?= (int)$sem['id'] ?>" <?= (int)($editRow['semester_id'] ?? 0) === (int)$sem['id'] ? 'selected' : '' ?>><?= h($semLbl) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Employee ID</label><input name="employee_id" class="form-control" value="<?= h($editRow['employee_id'] ?? '') ?>"/></div><div class="col-md-3"><label class="form-label">Student ID</label><input name="student_id" class="form-control" value="<?= h($editRow['student_id'] ?? '') ?>"/></div><div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (['active','inactive','suspended','archived'] as $s): ?><option value="<?= h($s) ?>" <?= ($editRow['status'] ?? 'active')===$s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div><?php if ($isAddMode): ?><div class="col-md-3"><label class="form-label">Password *</label><input name="password" type="password" class="form-control" required/></div><?php endif; ?><div class="col-md-3"><label class="form-label">Photo <small class="text-muted">(JPG/PNG/WEBP, max 2MB)</small></label><input name="photo" type="file" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"/></div><div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save</button><a href="<?= BASE_URL ?>/admin/users.php?action=list<?= h($filterQS) ?>" class="btn btn-secondary">Cancel</a></div></div></form></div></div>
<?php endif; ?>
<form method="GET" class="row g-2 mb-3"><input type="hidden" name="action" value="list"/><div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="Search users" value="<?= h($q) ?>"/></div><div class="col-md-2"><select id="adminUserFilterFaculty" name="faculty_id" class="form-select form-select-sm" data-cascade="faculty" data-cascade-target="adminUserFilterDepartment" data-preselect="<?= h((string)$selectedFacultyId) ?>" data-placeholder="All faculties"><option value="">All faculties</option><?php foreach ($options['faculties'] as $faculty): ?><option value="<?= (int)$faculty['id'] ?>" <?= $selectedFacultyId === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select id="adminUserFilterDepartment" name="department_id" class="form-select form-select-sm" data-cascade="department" data-cascade-source="adminUserFilterFaculty" data-cascade-target="adminUserFilterClass" data-preselect="<?= h((string)$selectedDepartmentId) ?>" data-placeholder="All departments"><option value="">All departments</option><?php foreach ($options['departments'] as $department): ?><option value="<?= (int)$department['id'] ?>" <?= $selectedDepartmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select id="adminUserFilterClass" name="class_id" class="form-select form-select-sm" data-cascade="class" data-cascade-source="adminUserFilterDepartment" data-preselect="<?= h((string)$selectedClassId) ?>" data-placeholder="All classes"><option value="">All classes</option><?php foreach ($options['classes'] as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $selectedClassId === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option><?php endforeach; ?></select></div><div class="col-md-1"><select name="role" class="form-select form-select-sm"><option value="">All roles</option><?php foreach ($roles as $role): ?><option value="<?= (int)$role['id'] ?>" <?= $selectedRoleId === (int)$role['id'] ? 'selected' : '' ?>><?= h($role['label']) ?></option><?php endforeach; ?></select></div><div class="col-md-1"><select name="status" class="form-select form-select-sm"><option value="">All status</option><?php foreach (['active','inactive','suspended','archived'] as $status): ?><option value="<?= h($status) ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select></div><div class="col-md-1 d-grid"><button class="btn btn-sm btn-primary">Go</button></div></form>

<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead><tr><th>#</th><th>Name</th><th>Role</th><th>Faculty</th><th>Department</th><th>Class</th><th>Status</th><th>Identifiers</th><th>Views</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $delForm = '';
    if ((int)$row['role_id'] !== ROLE_ADMIN) {
        $delForm = '<form method="POST" class="d-inline"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/><input type="hidden" name="act" value="delete"/><input type="hidden" name="id" value="'.(int)$row['id'].'"/><button class="btn btn-sm btn-danger" data-confirm="Archive/Remove this user?"><i class="fas fa-archive me-1"></i>Delete</button></form>';
    }
?>
<tr>
  <td><?= (int)$row['id'] ?></td>
  <td><?= h($row['full_name']) ?><div class="small text-muted">@<?= h($row['username']) ?></div></td>
  <td><span class="badge bg-info text-dark"><?= h($row['role_label']) ?></span></td>
  <td><?= h($row['faculty_name'] ?? '-') ?></td>
  <td><?= h($row['department_name'] ?? '-') ?></td>
  <td><?= h($row['class_name'] ?? '-') ?><?= !empty($row['class_section']) ? ' / '.h($row['class_section']) : '' ?></td>
  <td><span class="badge bg-<?= attendanceStatusClass($row['status']) ?>"><?= h(ucfirst($row['status'])) ?></span></td>
  <td><small><?= h($row['employee_id'] ?: ($row['student_id'] ?: '-')) ?></small></td>
  <td class="text-nowrap">
    <?= renderRowActions([
        'view_url'    => attendanceFocusedUserViewUrl($row, BASE_URL.'/admin/user_view.php?id='.(int)$row['id']),
        'view_label'  => 'View',
        'edit_url'    => '?action=edit&id='.(int)$row['id'],
        'delete_form' => $delForm,
    ]) ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
