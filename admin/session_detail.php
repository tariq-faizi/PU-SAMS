<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/lang.php';
requireLogin();
requirePermission('view_all_reports');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(BASE_URL . '/admin/attendance.php'); }

$sess = $db->prepare("
    SELECT s.*, c.name AS class_name, c.section, sub.name AS subject_name,
           u.full_name AS teacher_name, u.id AS teacher_id, t.faculty_id
    FROM attendance_sessions s
    JOIN classes c   ON c.id=s.class_id
    JOIN subjects sub ON sub.id=s.subject_id
    JOIN users u     ON u.id=s.teacher_id
    LEFT JOIN timetable t ON t.id=s.timetable_id
    WHERE s.id=?
");
$sess->execute([$id]);
$sess = $sess->fetch();
if (!$sess) { flashSet('danger','Session not found.'); redirect(BASE_URL . '/admin/attendance.php'); }

$pageTitle = 'Session: ' . $sess['class_name'] . ' – ' . $sess['subject_name'];
$attendanceBackUrl = ((int)($_SESSION['role_id'] ?? 0) === ROLE_ATT_MGR) ? BASE_URL . '/manager/attendance.php' : BASE_URL . '/admin/attendance.php';

$__adminToday = date('Y-m-d');
$__adminIsUpcoming = false;
if (!empty($sess['session_date']) && $sess['session_date'] > $__adminToday) {
    $__adminIsUpcoming = true;
} elseif (!empty($sess['session_date']) && $sess['session_date'] === $__adminToday && !empty($sess['start_time'])) {
    $__adminStartTs = strtotime($sess['session_date'].' '.$sess['start_time']);
    if ($__adminStartTs !== false && time() < $__adminStartTs) { $__adminIsUpcoming = true; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__adminIsUpcoming) {
    flashSet('danger', function_exists('t') ? t('ui_pending_lock_msg', [], 'Attendance is locked until the scheduled start time is reached.') : 'Attendance is locked — upcoming session.');
    redirect(BASE_URL . '/admin/session_detail.php?id=' . $id);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && userHasPermission('edit_attendance')) {
    verifyCsrf();
    $act = $_POST['act'] ?? 'single';

    if ($act === 'single') {
        $sid    = (int)($_POST['student_id'] ?? 0);
        $status = normalizeAttStatus($_POST['status'] ?? 'absent');
        $db->prepare("INSERT INTO attendance_records (session_id,student_id,status,marked_at,mark_method,ip_address)
                      VALUES (?,?,?,NOW(),'manager',?)
                      ON DUPLICATE KEY UPDATE status=VALUES(status), marked_at=NOW(), mark_method='manager'")
           ->execute([$id, $sid, $status, $_SERVER['REMOTE_ADDR'] ?? '']);
        auditLog('attendance_edit', 'attendance', $id, "Manager/VC updated student {$sid} to {$status}");
        writeAttendanceHistory($id, $sid, $status, 'session_detail', 'Attendance adjusted from session detail screen.', (int)($_SESSION['user_id'] ?? 0));
        flashSet('success', 'Attendance updated.');
        cacheBustDashboards();
        redirect(BASE_URL . '/admin/session_detail.php?id=' . $id);
    }

    if ($act === 'bulk') {
        $statuses = $_POST['student_status'] ?? []; 
        $applied = 0;
        foreach ($statuses as $sid => $status) {
            $sid = (int)$sid;
            $status = normalizeAttStatus($status);
            $db->prepare("INSERT INTO attendance_records (session_id,student_id,status,marked_at,mark_method,ip_address)
                          VALUES (?,?,?,NOW(),'manager',?)
                          ON DUPLICATE KEY UPDATE status=VALUES(status), marked_at=NOW(), mark_method='manager'")
               ->execute([$id, $sid, $status, $_SERVER['REMOTE_ADDR'] ?? '']);
            writeAttendanceHistory($id, $sid, $status, 'session_detail_bulk', 'Bulk save by manager/VC', (int)($_SESSION['user_id'] ?? 0));
            $applied++;
        }
        auditLog('attendance_bulk_edit', 'attendance', $id, "Manager/VC bulk save: {$applied} students");
        flashSet('success', "Saved {$applied} attendance records.");
        cacheBustDashboards();
        redirect(BASE_URL . '/admin/session_detail.php?id=' . $id);
    }
}

$records = $db->prepare("
    SELECT u.id AS user_id, u.full_name, u.student_id, r.status, r.marked_at, r.mark_method
    FROM users u
    LEFT JOIN attendance_records r ON r.student_id=u.id AND r.session_id=?
    WHERE u.role_id=? AND u.class_id=? AND u.deleted_at IS NULL
    ORDER BY u.full_name
");
$records->execute([$id, ROLE_STUDENT, $sess['class_id']]);
$records = $records->fetchAll();
$present = count(array_filter($records, fn($r) => ($r['status'] ?? 'absent') === 'present'));
$absent  = count(array_filter($records, fn($r) => ($r['status'] ?? 'absent') === 'absent'));
$total   = count($records);
$pct     = $total ? round($present / $total * 100) : 0;
function csrfField(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fas fa-list me-2"></i><?= h($pageTitle) ?></h5>
  <div>
    <a href="<?= BASE_URL ?>/reports/session_export.php?id=<?= $id ?>" class="btn btn-sm btn-outline-success me-1"><i class="fas fa-file-excel me-1"></i>Export</a>
    <a href="<?= h($attendanceBackUrl) ?>" class="btn btn-sm btn-secondary"><?= h(t('back')) ?></a>
  </div>
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
  <div class="col-md-3"><div class="v7-stat-card v6-stat-card primary card h-100"><div class="card-body"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-label">Students</div><div class="stat-value"><?= $total ?></div></div></div></div>
  <div class="col-md-3"><div class="v7-stat-card v6-stat-card success card h-100"><div class="card-body"><div class="stat-icon"><i class="fas fa-check"></i></div><div class="stat-label">✅</div><div class="stat-value"><?= $present ?></div></div></div></div>
  <div class="col-md-3"><div class="v7-stat-card v6-stat-card danger card h-100"><div class="card-body"><div class="stat-icon"><i class="fas fa-times"></i></div><div class="stat-label">❌</div><div class="stat-value"><?= $absent ?></div></div></div></div>
  <div class="col-md-3"><div class="v7-stat-card v6-stat-card <?= attendancePercentClass($pct) ?> card h-100"><div class="card-body"><div class="stat-icon"><i class="fas fa-percent"></i></div><div class="stat-label">Present Rate</div><div class="stat-value"><?= $pct ?>%</div></div></div></div>
</div>

<div class="card mb-3"><div class="card-body small">
  <div class="row g-2">
    <div class="col-sm-3"><strong>Class:</strong> <?= h($sess['class_name']) ?> <?= h($sess['section'] ?? '') ?></div>
    <div class="col-sm-3"><strong>Subject:</strong> <?= h($sess['subject_name']) ?></div>
    <div class="col-sm-3"><strong>Teacher:</strong> <?= h($sess['teacher_name']) ?></div>
    <div class="col-sm-3"><strong>Date:</strong> <?= h($sess['session_date']) ?> <?= h(substr($sess['start_time'], 0, 5)) ?>–<?= h(substr($sess['end_time'], 0, 5)) ?></div>
  </div>
</div></div>

<?php if ($__adminIsUpcoming): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 mb-3" role="alert" style="border-left:6px solid #f59e0b;border-radius:14px">
    <i class="fas fa-hourglass-half fa-lg"></i>
    <div>
      <strong><?= h(function_exists('t') ? t('ui_pending_sessions', [], 'Pending') : 'Pending') ?></strong>
    </div>
  </div>
<?php endif; ?>
<?php if (userHasPermission('edit_attendance') && !$__adminIsUpcoming): ?>
<form method="POST" class="card mb-3" id="bulkEditForm">
  <?php csrfField(); ?>
  <input type="hidden" name="act" value="bulk"/>
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="fas fa-pen-to-square me-2"></i>PUSAMS attendance editor</span>
    <div class="btn-group btn-group-sm">
      <button type="button" class="btn btn-outline-success" data-roster-bulk="present">✅</button>
      <button type="button" class="btn btn-outline-danger" data-roster-bulk="absent">❌</button>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle ros-roster-table">
      <thead><tr><th>#</th><th>Student</th><th>ID</th><th class="text-center">✅ / ❌</th><th>Marked at</th><th>Method</th></tr></thead>
      <tbody>
        <?php if (!$records): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No students are linked to this class.</td></tr>
        <?php else: $i = 1; foreach ($records as $r):
            
            $rawStatus = $r['status'] ?? null;
            if ($rawStatus === null || trim((string)$rawStatus) === '') {
                $cur = 'present';
            } else {
                $cur = normalizeAttStatus((string)$rawStatus);
            }
        ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= h($r['full_name']) ?></td>
            <td><?= h($r['student_id'] ?? '-') ?></td>
            <td class="text-center">
              <?= renderAttCell((int)$r['user_id'], $id, (string)$cur, true, 'student_status') ?>
              <script>
                (function(){
                  var hid = document.getElementById('atthid_<?=(int)$r['user_id']?>_<?=$id?>');
                  if (hid) { hid.name = 'student_status[<?=(int)$r['user_id']?>]'; }
                })();
              </script>
            </td>
            <td class="text-muted small"><?= $r['marked_at'] ? h(date('d M H:i', strtotime($r['marked_at']))) : '-' ?></td>
            <td><span class="badge bg-secondary"><?= h($r['mark_method'] ?? '-') ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</form>
<?= renderTristateScript() ?>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle ros-roster-table">
      <thead><tr><th>Student</th><th>ID</th><th class="text-center">✅ / ❌</th><th>Marked at</th></tr></thead>
      <tbody>
        <?php if (!$records): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No students.</td></tr>
        <?php else: foreach ($records as $r): $st = normalizeAttStatus($r['status'] ?? 'absent'); ?>
          <tr>
            <td><?= h($r['full_name']) ?></td>
            <td><?= h($r['student_id'] ?? '-') ?></td>
            <td class="text-center"><?= renderAttCell((int)$r['user_id'], $id, (string)$st, false) ?></td>
            <td><?= $r['marked_at'] ? h(date('d M H:i', strtotime($r['marked_at']))) : '-' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
