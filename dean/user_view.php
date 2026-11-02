<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_DEPT_HEAD, ROLE_ADMIN]);

$db = getDB();
$viewerRoleId = (int)($_SESSION['role_id'] ?? 0);
$viewerFacultyId = (int)($_SESSION['faculty_id'] ?? 0);
$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) redirect(BASE_URL.'/dean/users.php');

$u = $db->prepare("SELECT u.*, f.name AS faculty_name, d.name AS department_name, c.name AS class_name, c.section
                   FROM users u
                   LEFT JOIN faculties f ON f.id=u.faculty_id
                   LEFT JOIN departments d ON d.id=u.department_id
                   LEFT JOIN classes c ON c.id=u.class_id
                   WHERE u.id=? LIMIT 1");
$u->execute([$userId]);
$user = $u->fetch();
if (!$user) {
    flashSet('danger','User not found.');
    redirect(BASE_URL.'/dean/users.php');
}
if ($viewerRoleId !== ROLE_ADMIN && (int)($user['faculty_id'] ?? 0) !== $viewerFacultyId) {
    flashSet('danger','That user is outside your faculty scope.');
    redirect(BASE_URL.'/dean/users.php');
}

$pageTitle = 'کارن: '.$user['full_name'];
$targetRoleId = (int)$user['role_id'];
if ($targetRoleId === ROLE_STUDENT && (int)($user['class_id'] ?? 0) > 0) {
    redirect(attendanceHierarchyUrl([
        'level' => 'student',
        'fid'   => (int)($user['faculty_id'] ?? 0),
        'did'   => (int)($user['department_id'] ?? 0),
        'cid'   => (int)$user['class_id'],
        'uid'   => $userId,
        'group' => 'semester',
    ]));
}
$summary = null; $subjects = []; $history = []; $taught = []; $teacherSessions = [];
if ($targetRoleId === ROLE_STUDENT) {
    try { $summary = preciseAbsencePct($userId); } catch (Throwable $e) { $summary = null; }
    try { $subjects = studentSubjectAttendanceSummary($userId); } catch (Throwable $e) { $subjects = []; }
    try { $history = historyGroupedByStudent($userId); } catch (Throwable $e) { $history = []; }
}
if ($targetRoleId === ROLE_TEACHER) {
    try {
        $st = $db->prepare("SELECT DISTINCT s.id, s.name, s.code, c.name AS class_name, c.section
                            FROM teacher_subject_assignments tsa
                            JOIN subjects s ON s.id=tsa.subject_id
                            LEFT JOIN timetable tt ON tt.subject_id=s.id AND tt.teacher_id=tsa.teacher_id AND tt.status IN ('draft','faculty_approved','approved')
                            LEFT JOIN classes c ON c.id=tt.class_id
                            WHERE tsa.teacher_id=? AND tsa.is_active=1 ORDER BY s.name");
        $st->execute([$userId]);
        $taught = $st->fetchAll();
    } catch (Throwable $e) {}
    try {
        $ts = $db->prepare("SELECT s.session_date, s.start_time, s.end_time, s.status, c.name AS class_name, c.section, sub.name AS subject_name
                            FROM attendance_sessions s
                            JOIN classes c ON c.id=s.class_id
                            JOIN subjects sub ON sub.id=s.subject_id
                            WHERE s.teacher_id=?
                            ORDER BY s.session_date DESC, s.start_time DESC
                            LIMIT 40");
        $ts->execute([$userId]);
        $teacherSessions = $ts->fetchAll();
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
      <a href="<?= BASE_URL ?>/dean/users.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="fas fa-arrow-right"></i> شاته</a>
      <h5 class="mb-2"><i class="fas fa-user me-2 text-primary"></i><?= h($user['full_name']) ?></h5>
      <div>
        <span class="user-chip"><i class="fas fa-user-tag"></i><?= h(roleDisplayName($targetRoleId)) ?></span>
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
        <?php if ($targetRoleId === ROLE_STUDENT): ?>
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

  <?php if ($targetRoleId === ROLE_STUDENT && $summary): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card soft-card h-100"><div class="card-body">
      <h6 class="mb-3"><i class="fas fa-chart-line me-1"></i>General Attendance Summary</h6>
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
        <?php if ($targetRoleId === ROLE_STUDENT): ?>
          <a href="<?= BASE_URL ?>/hierarchy.php?level=student&cid=<?= (int)$user['class_id'] ?>&uid=<?= (int)$user['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-sitemap me-1"></i>Student Full Attendance</a>
        <?php endif; ?>
        <?php if ($targetRoleId === ROLE_TEACHER): ?>
          <a href="<?= BASE_URL ?>/hierarchy.php?level=teacher_classes" class="btn btn-sm btn-outline-info"><i class="fas fa-sitemap me-1"></i>Teacher All Classes</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/reports/attendance_history.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-clock-rotate-left me-1"></i>Attendance History</a>
      </div>
    </div></div>
  </div>
</div>

<?php if ($targetRoleId === ROLE_STUDENT && $subjects): ?>
<div class="card soft-card mb-3">
  <div class="card-header"><i class="fas fa-book me-2"></i>Per-Subject Attendance</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Subject</th><th>Code</th><th>Present</th><th>Absent</th><th>Total</th><th>Absence %</th></tr></thead>
      <tbody>
      <?php foreach ($subjects as $sj): $pct = (float)($sj['absence_pct'] ?? 0); ?>
        <tr>
          <td><?= h($sj['subject_name']) ?></td>
          <td><code class="small"><?= h($sj['code'] ?? '') ?></code></td>
          <td><?= (int)$sj['present_count'] ?></td>
          <td><?= (int)$sj['absent_count'] ?></td>
          <td><?= (int)$sj['total_marked'] ?></td>
          <td><span class="badge bg-<?= absencePercentClass((float)$pct) ?>"><?= $pct ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($targetRoleId === ROLE_STUDENT && !empty($history['subjects'])): ?>
<div class="card soft-card mb-3">
  <div class="card-header"><i class="fas fa-timeline me-2"></i>Student Attendance Details</div>
  <div class="card-body">
    <?php foreach ($history['subjects'] as $subject): ?>
      <div class="border rounded-3 p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <div>
            <div class="fw-semibold"><?= h($subject['subject_name']) ?></div>
            <div class="small text-muted"><code><?= h($subject['subject_code']) ?></code></div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-success">Present: <?= (int)$subject['present_cnt'] ?></span>
            <span class="badge bg-danger">Absent: <?= (int)$subject['absent_cnt'] ?></span>
            <span class="badge bg-<?= absencePercentClass((float)$subject['absence_pct']) ?>"><?= h((string)$subject['absence_pct']) ?>% Absent</span>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr><th>Date</th><th>Time</th><th>Credit</th><th>Status</th><th>Marked at</th><th>Method</th></tr></thead>
            <tbody>
              <?php foreach ($subject['records'] as $record): ?>
              <tr>
                <td><?= h($record['session_date']) ?></td>
                <td><?= h(substr($record['start_time'],0,5)) ?> – <?= h(substr($record['end_time'],0,5)) ?></td>
                <td><?= h($record['slot_label'] ?? '—') ?></td>
                <td><span class="badge bg-<?= attendanceStatusClass((string)$record['status']) ?>"><?= h(attStatusLabel((string)$record['status'])) ?></span></td>
                <td><?= h($record['marked_at'] ?? '—') ?></td>
                <td><small><?= h($record['mark_method'] ?? 'manual') ?></small></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($targetRoleId === ROLE_TEACHER && $taught): ?>
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

<?php if ($targetRoleId === ROLE_TEACHER && $teacherSessions): ?>
<div class="card soft-card mb-3">
  <div class="card-header"><i class="fas fa-list-check me-2"></i>Teacher Recent Sessions</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Date</th><th>Time</th><th>Class</th><th>Subject</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($teacherSessions as $row): ?>
        <tr>
          <td><?= h($row['session_date']) ?></td>
          <td><?= h(substr($row['start_time'],0,5)) ?> – <?= h(substr($row['end_time'],0,5)) ?></td>
          <td><?= h(($row['class_name'] ?? '—').' '.($row['section'] ?? '')) ?></td>
          <td><?= h($row['subject_name'] ?? '—') ?></td>
          <td><span class="badge bg-<?= attendanceStatusClass((string)$row['status']) ?>"><?= h(ucfirst((string)$row['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
