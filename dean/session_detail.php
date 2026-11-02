<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_DEPT_HEAD, ROLE_ADMIN]);

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);
if (!$id) redirect(BASE_URL.'/dean/attendance.php');

$sql = "SELECT s.*, c.name AS class_name, c.section, sub.name AS subject_name,
               u.full_name AS teacher_name, t.faculty_id AS tt_faculty_id
        FROM attendance_sessions s
        JOIN classes c ON c.id=s.class_id
        JOIN subjects sub ON sub.id=s.subject_id
        JOIN users u ON u.id=s.teacher_id
        JOIN timetable t ON t.id=s.timetable_id
        WHERE s.id=? LIMIT 1";
$stmt = $db->prepare($sql); $stmt->execute([$id]);
$sess = $stmt->fetch();
if (!$sess) { flashSet('danger','Session not found.'); redirect(BASE_URL.'/dean/attendance.php'); }
if ($roleId !== ROLE_ADMIN && (int)$sess['tt_faculty_id'] !== $facultyId) {
    flashSet('danger','That session belongs to another faculty.');
    redirect(BASE_URL.'/dean/attendance.php');
}
$pageTitle = 'Session: '.$sess['class_name'].' – '.$sess['subject_name'];

$records = $db->prepare("SELECT u.id AS user_id, u.full_name, u.student_id, r.status, r.marked_at, r.mark_method
                         FROM users u
                         LEFT JOIN attendance_records r ON r.student_id=u.id AND r.session_id=?
                         WHERE u.role_id=? AND u.class_id=? AND u.deleted_at IS NULL
                         ORDER BY u.full_name");
$records->execute([$id, ROLE_STUDENT, $sess['class_id']]);
$records = $records->fetchAll();
$present = count(array_filter($records, fn($r)=>($r['status'] ?? 'absent')==='present'));
$absent  = count(array_filter($records, fn($r)=>($r['status'] ?? 'absent')==='absent'));
$total = count($records);
$pct = $total ? round($present/$total*100,1) : 0;

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="fas fa-list me-2"></i><?= h($pageTitle) ?></h5>
  <a href="<?= BASE_URL ?>/dean/attendance.php" class="btn btn-sm btn-secondary"><?= t('back') ?></a>
</div>

<?= renderLiveAttendanceBanner($sess, [
    'present_count' => $present,
    'absent_count'  => $absent,
    'total_count'   => $total,
    'teacher_name'  => $sess['teacher_name'] ?? '',
    'show_stats'    => true,
]) ?>
<?= renderLiveAttendanceJS() ?>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card text-center"><div class="card-body"><div class="text-muted small">Date</div><div class="h6 mb-0"><?= h($sess['session_date']) ?></div></div></div></div>
  <div class="col-md-3"><div class="card text-center"><div class="card-body"><div class="text-muted small">Class</div><div class="h6 mb-0"><?= h($sess['class_name']) ?> <?= h($sess['section']) ?></div></div></div></div>
  <div class="col-md-3"><div class="card text-center"><div class="card-body"><div class="text-muted small">Teacher</div><div class="h6 mb-0"><?= h($sess['teacher_name']) ?></div></div></div></div>
  <div class="col-md-3"><div class="card text-center"><div class="card-body"><div class="text-muted small">Class attendance %</div><div class="h6 mb-0"><span class="badge bg-<?= attendancePercentClass($pct) ?>"><?= $pct ?>%</span> · <span class="badge bg-success">✅ <?= $present ?></span> <span class="badge bg-danger">❌ <?= $absent ?></span></div></div></div></div>
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-eye me-2"></i>PUSAMS attendance view</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle ros-roster-table">
      <thead class="table-light"><tr><th>#</th><th>Student</th><th>Roll</th><th class="text-center">✅ / ❌</th><th>Marked at</th><th>Method</th></tr></thead>
      <tbody>
        <?php if (!$records): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No students enrolled in this class.</td></tr>
        <?php else: foreach ($records as $i => $r):
            
            $rawStatus = $r['status'] ?? null;
            $cur = ($rawStatus === null || trim((string)$rawStatus) === '') ? 'present' : (string)$rawStatus; ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><?= h($r['full_name']) ?></td>
            <td><span class="badge bg-light text-dark"><?= h($r['student_id'] ?? '') ?></span></td>
            <td class="text-center"><?= renderAttCell((int)$r['user_id'], $id, (string)$cur, false) ?></td>
            <td><?= h($r['marked_at'] ?? '—') ?></td>
            <td><small><?= h($r['mark_method'] ?? 'manual') ?></small></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= renderTristateScript() ?>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
