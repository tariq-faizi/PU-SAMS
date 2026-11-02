<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
redirectLegacyDeanManagerRoute();
requireRole([ROLE_DEPT_HEAD, ROLE_ATT_MGR, ROLE_ADMIN]);

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);

$__canEditByRole = function_exists('userCanEditAttendance') ? userCanEditAttendance($roleId) : in_array($roleId, [ROLE_ADMIN, ROLE_ATT_MGR], true);
if (!$id) redirect(BASE_URL.'/manager/attendance.php');

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
if (!$sess) { flashSet('danger','Session not found.'); redirect(BASE_URL.'/manager/attendance.php'); }
if ($roleId !== ROLE_ADMIN && (int)$sess['tt_faculty_id'] !== $facultyId) {
    flashSet('danger','That session belongs to another faculty.');
    redirect(BASE_URL.'/manager/attendance.php');
}
$pageTitle = 'Session: '.$sess['class_name'].' – '.$sess['subject_name'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    
    if (!$__canEditByRole) {
        flashSet('danger', 'Dean has view-only access — attendance editing not allowed.');
        redirect(BASE_URL.'/manager/session_detail.php?id='.$id);
    }
    if (!userHasPermission('edit_attendance') && !userHasPermission('manager_edit_attendance')) {
        flashSet('danger','You do not have permission to edit attendance.');
        redirect(BASE_URL.'/manager/session_detail.php?id='.$id);
    }
    
    $__today = date('Y-m-d');
    if (!empty($sess['session_date']) && $sess['session_date'] > $__today) {
        flashSet('danger', function_exists('t') ? t('ui_pending_lock_msg', [], 'Attendance is locked until the scheduled start time is reached.') : 'Attendance is locked — upcoming session.');
        redirect(BASE_URL.'/manager/session_detail.php?id='.$id);
    }
    if (!empty($sess['session_date']) && $sess['session_date'] === $__today && !empty($sess['start_time'])) {
        $__startTs = strtotime($sess['session_date'].' '.$sess['start_time']);
        if ($__startTs !== false && time() < $__startTs) {
            flashSet('danger', (function_exists('t') ? t('ui_pending_lock_msg', [], 'Attendance is locked until the scheduled start time is reached.') : 'د خاضرۍ ثبتول قفل دي').' ('.substr((string)$sess['start_time'],0,5).')');
            redirect(BASE_URL.'/manager/session_detail.php?id='.$id);
        }
    }
    $sid = (int)($_POST['student_id'] ?? 0);
    $status = normalizeAttStatus($_POST['status'] ?? 'absent');
    $reason = trim($_POST['edit_reason'] ?? '');

    $prev = $db->prepare("SELECT status FROM attendance_records WHERE session_id=? AND student_id=? LIMIT 1");
    $prev->execute([$id,$sid]); $oldStatus = $prev->fetchColumn();

    $db->prepare("INSERT INTO attendance_records(session_id,student_id,status,marked_at,mark_method,ip_address)
                  VALUES (?,?,?,NOW(),'manager',?)
                  ON DUPLICATE KEY UPDATE status=VALUES(status), marked_at=NOW(), mark_method='manager'")
       ->execute([$id,$sid,$status,$_SERVER['REMOTE_ADDR'] ?? '']);

    writeAttendanceHistory($id, $sid, $status, 'manager_session_detail', 'Manager/dean adjusted attendance.', (int)$_SESSION['user_id']);
    auditLog('manager_attendance_edit','attendance',$id,"role={$roleId}; student={$sid}; status={$status}");
    try {
        $db->prepare("INSERT INTO manager_attendance_edits(session_id,student_id,old_status,new_status,edited_by,actor_role,edit_reason)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$id,$sid,$oldStatus ?: null,$status,(int)$_SESSION['user_id'],$roleId,$reason]);
    } catch (Throwable $e) {}
    cacheBustDashboards();
    flashSet('success','Attendance updated and logged.');
    redirect(BASE_URL.'/manager/session_detail.php?id='.$id);
}

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

try {
    $db->prepare("DELETE FROM manager_attendance_edits WHERE DATE(created_at) < CURDATE()")->execute();
} catch (Throwable $e) {}
$editLog = $db->prepare("SELECT m.*, u.full_name AS edited_by_name, st.full_name AS student_name
                         FROM manager_attendance_edits m
                         LEFT JOIN users u ON u.id=m.edited_by
                         LEFT JOIN users st ON st.id=m.student_id
                         WHERE m.session_id=? AND DATE(m.created_at) = CURDATE()
                         ORDER BY m.created_at DESC LIMIT 30");
try { $editLog->execute([$id]); $editLog = $editLog->fetchAll(); } catch (Throwable $e) { $editLog = []; }

$__sdToday  = date('Y-m-d');
$__sdIsUpcoming = false;
if (!empty($sess['session_date']) && $sess['session_date'] > $__sdToday) {
    $__sdIsUpcoming = true;
} elseif (!empty($sess['session_date']) && $sess['session_date'] === $__sdToday && !empty($sess['start_time'])) {
    $__sdStartTs = strtotime($sess['session_date'].' '.$sess['start_time']);
    if ($__sdStartTs !== false && time() < $__sdStartTs) { $__sdIsUpcoming = true; }
}
$__sdCanEdit = $__canEditByRole && !$__sdIsUpcoming;

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="fas fa-list me-2"></i><?= h($pageTitle) ?></h5>
  <a href="<?= BASE_URL ?>/manager/attendance.php" class="btn btn-sm btn-secondary"><?= t('back') ?></a>
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
  <div class="col-md-3"><div class="card text-center"><div class="card-body"><div class="text-muted small"><i class="far fa-calendar me-1"></i><?= t('ui_date') ?></div><div class="h6 mb-0"><?= h($sess['session_date']) ?></div></div></div></div>
  <div class="col-md-3"><div class="card text-center"><div class="card-body"><div class="text-muted small"><i class="fas fa-users me-1"></i><?= t('class') ?></div><div class="h6 mb-0"><?= h($sess['class_name']) ?> <?= h($sess['section']) ?></div></div></div></div>
  <div class="col-md-3"><div class="card text-center"><div class="card-body"><div class="text-muted small"><i class="fas fa-user-tie me-1"></i><?= t('teacher') ?></div><div class="h6 mb-0"><?= h($sess['teacher_name']) ?></div></div></div></div>
  <div class="col-md-3">
    <div class="card text-center">
      <div class="card-body">
        <div class="text-muted small"><i class="fas fa-percent me-1"></i><?= t('ui_attendance_pct') ?></div>
        <div class="d-flex justify-content-center align-items-center gap-1 flex-wrap mt-1">
          <span class="badge bg-<?= attendancePercentClass($pct) ?>" title="<?= h(t('ui_attendance_pct')) ?>"><i class="fas fa-percent me-1"></i><?= $pct ?>%</span>
          <span class="badge bg-success" title="<?= h(t('ui_att_present')) ?>">✅ <?= $present ?></span>
          <span class="badge bg-danger" title="<?= h(t('ui_att_absent')) ?>">❌ <?= $absent ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($__sdIsUpcoming): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 mb-3" role="alert" style="border-left:6px solid #f59e0b;border-radius:14px">
    <i class="fas fa-hourglass-half fa-lg"></i>
    <div>
      <strong><?= h(function_exists('t') ? t('ui_pending_sessions', [], 'Pending') : 'Pending') ?></strong>
    </div>
  </div>
<?php elseif (!$__canEditByRole): ?>
  <div class="alert alert-info d-flex align-items-center gap-2 mb-3" role="alert" style="border-left:6px solid #0ea5e9;border-radius:14px">
    <i class="fas fa-eye fa-lg"></i>
    <div>
      <strong><?= h(function_exists('t') ? t('view_only', [], 'View only') : 'View only') ?></strong>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><i class="fas <?= $__sdCanEdit ? 'fa-pen-to-square' : 'fa-eye' ?> me-2"></i>PUSAMS attendance <?= $__sdCanEdit ? 'editor' : 'view' ?></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle ros-roster-table">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Student</th><th>Roll</th><th class="text-center">✅ / ❌</th>
          <?php if ($__sdCanEdit): ?><th>Reason</th><th></th><?php else: ?><th>Marked at</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$records): ?>
          <tr><td colspan="<?= $__sdCanEdit ? 6 : 5 ?>" class="text-center text-muted py-4">No students enrolled in this class.</td></tr>
        <?php else: foreach ($records as $i => $r):
            
            $rawStatus = $r['status'] ?? null;
            if ($rawStatus === null || trim((string)$rawStatus) === '') {
                $cur = 'present';
            } else {
                $cur = normalizeAttStatus((string)$rawStatus);
            }
        ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><?= h($r['full_name']) ?></td>
            <td><span class="badge bg-light text-dark"><?= h($r['student_id'] ?? '') ?></span></td>
            <?php if ($__sdCanEdit): ?>
              <td class="text-center">
                <form method="POST" class="d-flex gap-2 align-items-center justify-content-center flex-wrap" onsubmit="return confirm('Change attendance for <?= h(addslashes($r['full_name'])) ?>?');">
                  <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>"/>
                  <input type="hidden" name="student_id" value="<?= (int)$r['user_id'] ?>"/>
                  <?= renderAttCell((int)$r['user_id'], $id, (string)$cur, true, 'mgr_row_status') ?>
                  <script>
                    (function(){
                      var hid = document.getElementById('atthid_<?=(int)$r['user_id']?>_<?=$id?>');
                      if (hid) { hid.name = 'status'; }
                    })();
                  </script>
              </td>
              <td><input type="text" name="edit_reason" class="form-control form-control-sm" placeholder="Why?" /></td>
              <td><button class="btn btn-sm btn-primary"><i class="fas fa-save"></i></button></form></td>
            <?php else: ?>
              <td class="text-center"><?= renderAttCell((int)$r['user_id'], $id, (string)$cur, false) ?></td>
              <td><small class="text-muted"><?= h($r['marked_at'] ?? '—') ?></small></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= renderTristateScript() ?>

<?php if ($editLog): ?>
<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-history me-2"></i>Today's edit log (<?= h(date('d M Y')) ?>)</span>
    
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>When</th><th>Edited by</th><th>Student</th><th>Old → New</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($editLog as $e): ?>
        <tr>
          <td><?= h(date('d M Y H:i', strtotime($e['created_at']))) ?></td>
          <td><?= h($e['edited_by_name'] ?? '—') ?></td>
          <td><?= h($e['student_name'] ?? '—') ?></td>
          <td><?php if (!empty($e['old_status'])): ?><?= renderAttStatusBadge((string)$e['old_status']) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?> <span class="mx-1">→</span> <?= renderAttStatusBadge((string)$e['new_status']) ?></td>
          <td class="small text-muted"><?= h($e['edit_reason']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
