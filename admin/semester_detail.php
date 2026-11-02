<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();

requireRole([ROLE_ADMIN, ROLE_DEPT_HEAD]);

$db = getDB();
$roleId       = (int)($_SESSION['role_id'] ?? 0);
$facultyScope = ($roleId === ROLE_DEPT_HEAD) ? currentFacultyScopeId() : null;

$semesterId = (int)($_GET['semester_id'] ?? 0);
if ($semesterId <= 0) { redirect(currentSectionPageUrl('semesters.php')); }

$semSql = "SELECT s.*, f.name AS faculty_name FROM semesters s
           JOIN faculties f ON f.id=s.faculty_id
           WHERE s.id=?" . ($facultyScope ? " AND s.faculty_id=?" : "") . " LIMIT 1";
$semParams = $facultyScope ? [$semesterId, $facultyScope] : [$semesterId];
$semStmt = $db->prepare($semSql);
$semStmt->execute($semParams);
$semester = $semStmt->fetch();
if (!$semester) {
    flashSet('danger', 'Semester not found or outside your scope.');
    redirect(currentSectionPageUrl('semesters.php'));
}

$pageTitle = t('semesters').' — '.$semester['name'];

$semFacultyId = (int)$semester['faculty_id'];

$subjStmt = $db->prepare(
    "SELECT sj.id, sj.name, sj.code, sj.default_weekly_credits, sj.is_active,
            d.name AS department_name
       FROM subjects sj
       LEFT JOIN departments d ON d.id=sj.department_id
      WHERE sj.semester_id=?
        AND sj.faculty_id=?
   ORDER BY sj.name"
);
$subjStmt->execute([$semesterId, $semFacultyId]);
$subjects = $subjStmt->fetchAll();

$clsStmt = $db->prepare(
    "SELECT c.id, c.name, c.section, c.is_active, d.name AS department_name
       FROM classes c
       LEFT JOIN departments d ON d.id=c.department_id
      WHERE c.semester_id=?
        AND c.faculty_id=?
   ORDER BY d.name, c.name"
);
$clsStmt->execute([$semesterId, $semFacultyId]);
$classesInSemester = $clsStmt->fetchAll();

$stuStmt = $db->prepare(
    "SELECT u.id, u.full_name, u.username, u.student_id, u.status,
            c.name AS class_name, c.section, d.name AS department_name
       FROM users u
       LEFT JOIN classes c ON c.id=u.class_id
       LEFT JOIN departments d ON d.id=COALESCE(u.department_id, c.department_id)
      WHERE (
              u.semester_id = ?
              OR (u.semester_id IS NULL AND c.semester_id = ?)
            )
        AND COALESCE(u.faculty_id, c.faculty_id) = ?
        AND u.role_id=".ROLE_STUDENT."
        AND u.status='active'
        AND u.deleted_at IS NULL
   ORDER BY d.name, c.name, u.full_name"
);
$stuStmt->execute([$semesterId, $semesterId, $semFacultyId]);
$students = $stuStmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<style>
.sem-hero{background:linear-gradient(135deg,#f8fbff 0%,#eef6ff 60%,#ffffff 100%);border:1px solid #dbeafe;border-radius:18px;padding:1.1rem 1.25rem;box-shadow:0 12px 28px rgba(15,23,42,.05)}
.sem-chip{display:inline-flex;align-items:center;gap:.45rem;padding:.4rem .8rem;border:1px solid #e5e7eb;border-radius:999px;background:#fff;font-size:.85rem;margin:.2rem .3rem .2rem 0}.sem-chip i{color:#2563eb}.soft-card{border-radius:16px;border-color:#e5e7eb;box-shadow:0 10px 26px rgba(15,23,42,.04)}
</style>

<div class="sem-hero mb-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div>
      <a href="<?= h(currentSectionPageUrl('semesters.php')) ?>" class="btn btn-sm btn-outline-secondary mb-2"><i class="fas fa-arrow-right"></i> شاته</a>
      <h5 class="mb-2"><i class="fas fa-calendar-week me-2 text-primary"></i><?= h($semester['name']) ?></h5>
      <div>
        <span class="sem-chip"><i class="fas fa-building"></i><?= h($semester['faculty_name']) ?></span>
        <span class="sem-chip"><i class="fas fa-circle-check"></i><?= $semester['is_active'] ? 'Active' : 'Inactive' ?></span>
        <span class="sem-chip"><i class="fas fa-book"></i><?= count($subjects) ?> Subjects</span>
        <span class="sem-chip"><i class="fas fa-user-graduate"></i><?= count($students) ?> Students</span>
      </div>
    </div>
  </div>
</div>

<div class="card soft-card mb-3">
  <div class="card-header"><i class="fas fa-book me-2"></i>مضامین</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Name</th><th>Code</th><th>Department</th><th>Credits</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$subjects): ?>
        <tr><td colspan="5" class="text-center text-muted py-3">No subjects found for this semester.</td></tr>
      <?php endif; ?>
      <?php foreach ($subjects as $sj): ?>
        <tr>
          <td><?= h($sj['name']) ?></td>
          <td><code class="small"><?= h($sj['code']) ?></code></td>
          <td><?= h($sj['department_name'] ?? '—') ?></td>
          <td><?= (int)$sj['default_weekly_credits'] ?></td>
          <td><span class="badge bg-<?= $sj['is_active'] ? 'success' : 'secondary' ?>"><?= $sj['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card soft-card mb-3">
  <div class="card-header"><i class="fas fa-chalkboard me-2"></i>ټولګي</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Class</th><th>Department</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$classesInSemester): ?>
        <tr><td colspan="3" class="text-center text-muted py-3">No classes found for this semester.</td></tr>
      <?php endif; ?>
      <?php foreach ($classesInSemester as $cls): ?>
        <tr>
          <td><?= h(trim($cls['name'].' '.($cls['section'] ?? ''))) ?></td>
          <td><?= h($cls['department_name'] ?? '—') ?></td>
          <td><span class="badge bg-<?= $cls['is_active'] ? 'success' : 'secondary' ?>"><?= $cls['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card soft-card mb-3">
  <div class="card-header"><i class="fas fa-user-graduate me-2"></i>Active Students</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>#</th><th>Full Name</th><th>Username</th><th>Student ID</th><th>Class</th><th>Department</th></tr></thead>
      <tbody>
      <?php if (!$students): ?>
        <tr><td colspan="6" class="text-center text-muted py-3">No active students found for this semester.</td></tr>
      <?php endif; ?>
      <?php foreach ($students as $i => $stu): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><a href="<?= BASE_URL ?>/admin/user_view.php?id=<?= (int)$stu['id'] ?>"><?= h($stu['full_name']) ?></a></td>
          <td>@<?= h($stu['username']) ?></td>
          <td><?= h($stu['student_id'] ?? '—') ?></td>
          <td><?= h(trim($stu['class_name'].' '.($stu['section'] ?? ''))) ?></td>
          <td><?= h($stu['department_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
