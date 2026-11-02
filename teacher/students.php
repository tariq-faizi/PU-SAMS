<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_TEACHER]);
$db = getDB();
$uid = (int)$_SESSION['user_id'];
$pageTitle = 'My Students';
$selectedSubject = (int)($_GET['subject_id'] ?? 0);

$subjects = $db->prepare("SELECT DISTINCT sub.id, sub.name, sub.code
                          FROM teacher_subject_assignments tsa
                          JOIN subjects sub ON sub.id=tsa.subject_id
                          WHERE tsa.teacher_id=? AND tsa.is_active=1
                          ORDER BY sub.name");
$subjects->execute([$uid]); $subjects = $subjects->fetchAll();

$rows = teacherStudentsAttendance($uid, $selectedSubject ?: null);

$grouped = [];
foreach ($rows as $r) {
    $key = $r['subject_id'].'|'.$r['subject_name'].'|'.$r['subject_code'].'|'.$r['class_name'].' '.$r['section'];
    $grouped[$key][] = $r;
}

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="mb-1"><i class="fas fa-users me-2"></i>My students</h5>
    
  </div>
  <form method="GET" class="d-flex gap-2">
    <select name="subject_id" class="form-select form-select-sm">
      <option value="0">All subjects</option>
      <?php foreach ($subjects as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $selectedSubject===(int)$s['id']?'selected':'' ?>><?= h($s['name']) ?> [<?= h($s['code']) ?>]</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-primary">Filter</button>
  </form>
</div>

<?php if (!$grouped): ?>
  <div class="pusams-empty-state"><i class="fas fa-users-slash me-2"></i>No students linked yet.</div>
<?php else: ?>
  <?php foreach ($grouped as $head => $studs):
    [$sid, $sName, $sCode, $cName] = explode('|', $head, 4);
  ?>
    <div class="card mb-3">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fas fa-book-open me-2"></i><?= h($sName) ?> <small class="text-muted">[<?= h($sCode) ?>]</small> <span class="badge bg-light text-dark ms-2"><?= h($cName) ?></span></span>
        <span class="badge bg-secondary"><?= count($studs) ?> students</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light"><tr>
            <th>#</th><th>Student</th><th>Roll</th>
            <th class="text-center">✅ Present</th>
            <th class="text-center">❌ Absent</th>
            <th class="text-center">Attendance %</th>
            <th class="text-center">Absence %</th>
            <th class="text-center">View</th>
          </tr></thead>
          <tbody>
            <?php $i=1; foreach ($studs as $s): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= h($s['full_name']) ?></td>
                <td><span class="text-muted small">#<?= h($s['roll']) ?></span></td>
                <td class="text-center"><span class="badge bg-success"><?= (int)$s['present_cnt'] ?></span></td>
                <td class="text-center"><span class="badge bg-danger"><?= (int)$s['absent_cnt'] ?></span></td>
                <td class="text-center"><span class="badge bg-<?= attendancePercentClass((float)$s['attendance_pct']) ?>"><?= $s['attendance_pct'] ?>%</span></td>
                <td class="text-center"><span class="badge bg-<?= absencePercentClass((float)$s['absence_pct']) ?>"><?= $s['absence_pct'] ?>%</span></td>
                <td class="text-center"><a href="<?= BASE_URL ?>/teacher/students.php?subject_id=<?= (int)$s['subject_id'] ?>&student=<?= (int)$s['student_id'] ?>" class="btn btn-sm btn-outline-info" title="د جزئیاتو لیدل"><i class="fas fa-eye"></i></a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php

$detailStudent = (int)($_GET['student'] ?? 0);
if ($detailStudent && $selectedSubject) {
    $detail = $db->prepare("SELECT u.full_name, u.student_id, c.name AS class_name, c.section
                            FROM users u JOIN classes c ON c.id=u.class_id WHERE u.id=?");
    $detail->execute([$detailStudent]); $detail = $detail->fetch();
    $sessions = $db->prepare("SELECT s.session_date, s.slot_label, s.start_time, s.end_time, ar.status, ar.marked_at, ar.mark_method
                              FROM attendance_records ar
                              JOIN attendance_sessions s ON s.id=ar.session_id
                              WHERE ar.student_id=? AND s.subject_id=? AND s.teacher_id=?
                              ORDER BY s.session_date DESC, s.start_time DESC LIMIT 50");
    $sessions->execute([$detailStudent, $selectedSubject, $uid]); $sessions = $sessions->fetchAll();
    $sumPct = semesterAbsencePctForStudent($detailStudent, $selectedSubject);
?>
<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-user me-2"></i>Detail – <?= h($detail['full_name'] ?? '?') ?> (<?= h($detail['student_id'] ?? '') ?>)</span>
    <a href="<?= BASE_URL ?>/teacher/students.php?subject_id=<?= (int)$selectedSubject ?>" class="btn btn-sm btn-outline-secondary">Close</a>
  </div>
  <div class="card-body">
    <div class="row g-3 mb-3">
      <div class="col-md-3"><?= renderStatCard(['tone'=>'danger','icon'=>'fa-percent','label'=>'Semester absence %','value'=>$sumPct['pct'].'%','clickable'=>false]) ?></div>
      <div class="col-md-3"><?= renderStatCard(['tone'=>'warn','icon'=>'fa-circle-xmark','label'=>'Absent credits','value'=>$sumPct['absent'],'clickable'=>false]) ?></div>
      <div class="col-md-3"><?= renderStatCard(['tone'=>'info','icon'=>'fa-bullseye','label'=>'Target credits','value'=>$sumPct['target'],'clickable'=>false]) ?></div>
      <div class="col-md-3"><?= renderStatCard(['tone'=>'primary','icon'=>'fa-list-ol','label'=>'Last 50 sessions','value'=>count($sessions),'clickable'=>false]) ?></div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Slot</th><th>Time</th><th>Status</th><th>Marked at</th><th>Method</th></tr></thead>
        <tbody>
          <?php if (!$sessions): ?><tr><td colspan="6" class="text-center text-muted py-4">No records.</td></tr><?php endif; ?>
          <?php foreach ($sessions as $r): ?>
            <tr>
              <td><?= h($r['session_date']) ?></td>
              <td><?= h($r['slot_label']) ?></td>
              <td><?= h(substr($r['start_time'],0,5)) ?>–<?= h(substr($r['end_time'],0,5)) ?></td>
              <td><span class="badge bg-<?= attendanceStatusClass($r['status']) ?>"><?= h(ucfirst($r['status'])) ?></span></td>
              <td><?= h($r['marked_at'] ?? '—') ?></td>
              <td><?= h($r['mark_method']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php } ?>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
