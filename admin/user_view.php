<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('manage_users');

$db = getDB();
$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) { redirect(BASE_URL.'/admin/users.php'); }

$u = $db->prepare("SELECT u.*, f.name AS faculty_name, d.name AS department_name, c.name AS class_name, c.section
                   FROM users u
                   LEFT JOIN faculties   f ON f.id=u.faculty_id
                   LEFT JOIN departments d ON d.id=u.department_id
                   LEFT JOIN classes     c ON c.id=u.class_id
                   WHERE u.id=? LIMIT 1");
$u->execute([$userId]);
$user = $u->fetch();
if (!$user) { flashSet('danger','User not found.'); redirect(BASE_URL.'/admin/users.php'); }

$pageTitle = 'کارن: '.$user['full_name'];
$roleId    = (int)$user['role_id'];
if ($roleId === ROLE_STUDENT && (int)($user['class_id'] ?? 0) > 0) {
    redirect(attendanceHierarchyUrl([
        'level' => 'student',
        'fid'   => (int)($user['faculty_id'] ?? 0),
        'did'   => (int)($user['department_id'] ?? 0),
        'cid'   => (int)$user['class_id'],
        'uid'   => $userId,
        'group' => 'semester',
    ]));
}

$summary = null; $subjects = []; $taught = [];
if ($roleId === ROLE_STUDENT) {
    try { $summary = preciseAbsencePct($userId); } catch (Throwable $e) { $summary = null; }
    try { $subjects = studentSubjectAttendanceSummary($userId); } catch (Throwable $e) {}
}
if ($roleId === ROLE_TEACHER) {
    try {
        $st = $db->prepare("SELECT DISTINCT s.id, s.name, s.code, c.name AS class_name, c.section
                            FROM teacher_subject_assignments tsa
                            JOIN subjects s ON s.id=tsa.subject_id
                            LEFT JOIN classes c ON c.id=tsa.class_id
                            WHERE tsa.teacher_id=? AND tsa.is_active=1 ORDER BY s.name");
        $st->execute([$userId]);
        $taught = $st->fetchAll();
    } catch (Throwable $e) {}
}

require_once __DIR__.'/../includes/header.php';
?>
<style>
.user-hero{background:linear-gradient(135deg,#f8fbff 0%,#eef6ff 60%,#ffffff 100%);border:1px solid #dbeafe;border-radius:18px;padding:1.1rem 1.25rem;box-shadow:0 12px 28px rgba(15,23,42,.05)}
.user-chip{display:inline-flex;align-items:center;gap:.45rem;padding:.4rem .8rem;border:1px solid #e5e7eb;border-radius:999px;background:#fff;font-size:.85rem;margin:.2rem .3rem .2rem 0}.user-chip i{color:#2563eb}.soft-card{border-radius:16px;border-color:#e5e7eb;box-shadow:0 10px 26px rgba(15,23,42,.04)}
</style>
<div class="user-hero mb-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div>
      <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="fas fa-arrow-right"></i> شاته</a>
      <h5 class="mb-2"><i class="fas fa-user me-2 text-primary"></i><?= h($user['full_name']) ?></h5>
      <div>
        <span class="user-chip"><i class="fas fa-user-tag"></i><?= h(roleDisplayName($roleId)) ?></span>
        <span class="user-chip"><i class="fas fa-circle-check"></i><?= h($user['status']) ?></span>
        <span class="user-chip"><i class="fas fa-building"></i><?= h($user['faculty_name'] ?? '—') ?></span>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6 col-lg-4">
    <div class="card soft-card h-100"><div class="card-body">
      <h6 class="mb-3"><i class="fas fa-id-card me-1"></i>Basic Information</h6>
      <ul class="list-unstyled small mb-0">
        <li><strong>Username:</strong> @<?= h($user['username']) ?></li>
        <li><strong>Email:</strong> <?= h($user['email'] ?? '—') ?></li>
        <li><strong>Phone:</strong> <?= h($user['phone'] ?? '—') ?></li>
        <li><strong>Faculty:</strong> <?= h($user['faculty_name'] ?? '—') ?></li>
        <li><strong>Department:</strong> <?= h($user['department_name'] ?? '—') ?></li>
        <?php if ($roleId === ROLE_STUDENT): ?>
          <li><strong>Class:</strong> <?= h(($user['class_name'] ?? '—').' '.($user['section'] ?? '')) ?></li>
          <li><strong>Student ID:</strong> <?= h($user['student_id'] ?? '—') ?></li>
        <?php endif; ?>
        <?php if (!empty($user['employee_id'])): ?>
          <li><strong>Employee ID:</strong> <?= h($user['employee_id']) ?></li>
        <?php endif; ?>
        <li><strong>Last Login:</strong> <?= h($user['last_login'] ?? '—') ?></li>
      </ul>
    </div></div>
  </div>

  <?php if ($roleId === ROLE_STUDENT && $summary): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card soft-card h-100"><div class="card-body">
      <h6 class="mb-3"><i class="fas fa-chart-line me-1"></i>Attendance Summary</h6>
      <div class="row g-2 text-center">
        <div class="col-4"><div class="badge bg-success w-100 py-2"><?= (int)$summary['present_credits'] ?><br><small>Present</small></div></div>
        <div class="col-4"><div class="badge bg-danger w-100 py-2"><?= (int)$summary['absent_credits'] ?><br><small>Absent</small></div></div>
        <div class="col-4"><div class="badge bg-secondary w-100 py-2"><?= (int)$summary['total_credits'] ?><br><small>Total</small></div></div>
      </div>
      <hr/>
      <div class="text-center">
        <span class="badge bg-<?= absencePercentClass((float)$summary['absence_pct']) ?> p-2 fs-6"><?= h((string)$summary['absence_pct']) ?>% Absent</span>
      </div>
    </div></div>
  </div>
  <?php endif; ?>

  <div class="col-md-6 col-lg-4">
    <div class="card soft-card h-100"><div class="card-body">
      <h6 class="mb-3"><i class="fas fa-link me-1"></i>Quick Links</h6>
      <div class="d-grid gap-2">
        <a href="<?= BASE_URL ?>/admin/users.php?action=edit&id=<?= $userId ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit me-1"></i>سمول</a>
        <?php if ($roleId === ROLE_STUDENT): ?>
          <a href="<?= BASE_URL ?>/admin/attendance.php?student_id=<?= $userId ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-clock me-1"></i>Attendance History</a>
        <?php endif; ?>
        <?php if ($roleId === ROLE_TEACHER): ?>
          <a href="<?= BASE_URL ?>/admin/timetable.php?teacher_id=<?= $userId ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-calendar me-1"></i>مهالویش</a>
        <?php endif; ?>
      </div>
    </div></div>
  </div>
</div>

<?php if ($roleId === ROLE_STUDENT && $subjects): ?>
<div class="card soft-card mb-3">
  <div class="card-header"><i class="fas fa-book me-2"></i>Per-Subject Attendance</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Subject</th><th>Code</th><th>Present</th><th>Absent</th><th>Absence %</th></tr></thead>
      <tbody>
      <?php foreach ($subjects as $sj):
          $pct = (float)($sj['absence_pct'] ?? 0); ?>
        <tr>
          <td><?= h($sj['subject_name']) ?></td>
          <td><code class="small"><?= h($sj['code'] ?? '') ?></code></td>
          <td><?= (int)$sj['present_count'] ?></td>
          <td><?= (int)$sj['absent_count'] ?></td>
          <td><span class="badge bg-<?= absencePercentClass((float)$pct) ?>"><?= $pct ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($roleId === ROLE_TEACHER && $taught): ?>
<div class="card soft-card mb-3">
  <div class="card-header"><i class="fas fa-chalkboard-user me-2"></i>Teacher Subjects</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Subject</th><th>Code</th><th>Class</th></tr></thead>
      <tbody>
      <?php foreach ($taught as $t): ?>
        <tr>
          <td><?= h($t['name']) ?></td>
          <td><code class="small"><?= h($t['code'] ?? '') ?></code></td>
          <td><?= h(($t['class_name'] ?? '—').' '.($t['section'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
