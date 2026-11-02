<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
redirectLegacyDeanManagerRoute();
requireRole([ROLE_DEPT_HEAD, ROLE_ATT_MGR]);

$db = getDB();
$roleId    = (int)($_SESSION['role_id'] ?? 0);
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$pageTitle = t('attendance');
$options   = reportFilterOptions(['faculty_id' => $facultyId]);

$date         = $_GET['date'] ?? date('Y-m-d');
$departmentId = (int)($_GET['department_id'] ?? 0);
$classId      = (int)($_GET['class_id'] ?? 0);
$subjectId    = (int)($_GET['subject_id'] ?? 0);
$status       = normalizeSessionStatus($_GET['status'] ?? '');

$state        = (string)($_GET['state'] ?? '');

$where  = ["t.faculty_id=?", "s.session_date=?"]; $params = [$facultyId, $date];
if ($departmentId) { $where[] = "c.department_id=?"; $params[] = $departmentId; }
if ($classId)      { $where[] = "s.class_id=?";      $params[] = $classId; }
if ($subjectId)    { $where[] = "s.subject_id=?";    $params[] = $subjectId; }
applySessionStatusFilterClause($where, $params, 's.status', $status);
if (function_exists('applyEffectiveStateFilter')) {
    applyEffectiveStateFilter($where, $params, 's.status', 's.start_time', 's.end_time', 's.session_date', $state);
}
$sqlWhere = implode(' AND ', $where);

$stmt = $db->prepare("SELECT s.*, c.name as class_name, c.section, d.name as department_name, sub.name as subject_name, u.full_name as teacher_name,
    COUNT(r.id) as total_records,
    SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) as present_records,
    SUM(CASE WHEN r.status='absent'  THEN 1 ELSE 0 END) as absent_records
    FROM attendance_sessions s
    JOIN timetable t ON t.id=s.timetable_id
    JOIN classes c   ON c.id=s.class_id
    LEFT JOIN departments d ON d.id=c.department_id
    JOIN subjects sub ON sub.id=s.subject_id
    JOIN users u      ON u.id=s.teacher_id
    LEFT JOIN attendance_records r ON r.session_id=s.id
    WHERE $sqlWhere
    GROUP BY s.id
    ORDER BY s.start_time, s.credit_slot_no, c.name");
$stmt->execute($params); $rows = $stmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="mb-1"><i class="fas fa-clock me-2"></i><?= t('attendance') ?></h5>
  </div>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-2"><input type="date" name="date" class="form-control form-control-sm" value="<?= h($date) ?>"/></div>
  <div class="col-md-3"><select name="department_id" data-cascade="department" class="form-select form-select-sm"><option value="">— <?= t('departments') ?> —</option><?php foreach ($options['departments'] as $department): ?><option value="<?= (int)$department['id'] ?>" <?= $departmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select name="class_id" data-cascade="class" class="form-select form-select-sm"><option value="">— <?= t('classes') ?> —</option><?php foreach ($options['classes'] as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $classId === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select name="subject_id" data-cascade="subject" class="form-select form-select-sm"><option value="">— <?= t('subjects') ?> —</option><?php foreach ($options['subjects'] as $subject): ?><option value="<?= (int)$subject['id'] ?>" <?= $subjectId === (int)$subject['id'] ? 'selected' : '' ?>><?= h($subject['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-1"><select name="status" class="form-select form-select-sm"><option value=""><?= t('all') ?></option><?php foreach (sessionStatusFilterOptions() as $item => $label): ?><option value="<?= h($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-1"><button class="btn btn-sm btn-primary w-100"><?= t('filter') ?></button></div>
</form>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle ros-roster-table">
  <thead><tr>
    <th><i class="far fa-clock me-1"></i><?= t('ui_time') ?></th>
    <th><i class="fas fa-building me-1"></i><?= t('departments') ?></th>
    <th><i class="fas fa-users me-1"></i><?= t('class') ?></th>
    <th><i class="fas fa-book me-1"></i><?= t('subject') ?></th>
    <th><i class="fas fa-user-tie me-1"></i><?= t('teacher') ?></th>
    <th class="text-center"><i class="fas fa-check text-success me-1"></i><?= t('ui_att_present') ?></th>
    <th class="text-center"><i class="fas fa-xmark text-danger me-1"></i><?= t('ui_att_absent') ?></th>
    <th class="text-center"><i class="fas fa-percent me-1"></i><?= t('ui_attendance_pct') ?></th>
    <th><?= t('ui_status') ?></th>
    <th></th>
  </tr></thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="10" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $row):
      $pct = (int)$row['total_records'] > 0 ? round(((int)$row['present_records'] / (int)$row['total_records']) * 100, 1) : 0;

      $__rowState = function_exists('effectiveSessionState') ? effectiveSessionState($row) : '';
      $__isUpcoming = ($__rowState === 'pending'); ?>
      <tr>
        <td><?= h(substr($row['start_time'],0,5)) ?>–<?= h(substr($row['end_time'],0,5)) ?>
            <div class="small text-muted"><?= h($row['slot_label'] ?? '') ?></div></td>
        <td><?= h($row['department_name'] ?? '—') ?></td>
        <td><?= h(trim($row['class_name'].' '.($row['section'] ?? ''))) ?></td>
        <td><?= h($row['subject_name']) ?></td>
        <td><?= h($row['teacher_name']) ?></td>
        <td class="text-center"><span class="badge bg-success" title="<?= h(t('ui_att_present')) ?>">✅ <?= (int)$row['present_records'] ?></span></td>
        <td class="text-center"><span class="badge bg-<?= ((int)$row['absent_records'] > 0 ? 'danger' : 'secondary') ?>" title="<?= h(t('ui_att_absent')) ?>">❌ <?= (int)$row['absent_records'] ?></span></td>
        <td class="text-center"><span class="badge bg-<?= attendancePercentClass($pct) ?>" title="<?= h(t('ui_attendance_pct')) ?>"><i class="fas fa-percent me-1"></i><?= $pct ?>%</span></td>
        
        <td><?= function_exists('renderEffectiveSessionBadge') ? renderEffectiveSessionBadge($row) : '<span class="badge bg-'.attendanceStatusClass($row['status']).'">'.h(sessionStatusLabel($row['status'])).'</span>' ?></td>
        <td>
          <a href="<?= BASE_URL ?>/manager/session_detail.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
          <?php if (!$__isUpcoming): ?>
            <a href="<?= BASE_URL ?>/manager/attendance_edit.php?session_id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-pen-to-square"></i></a>
          <?php else: ?>
            <span class="btn btn-sm btn-outline-secondary disabled" title="<?= h(function_exists('t') ? t('ui_pending_lock_msg', [], 'Upcoming — edit locked') : 'Upcoming — edit locked') ?>" aria-disabled="true" style="pointer-events:none;opacity:.55"><i class="fas fa-lock"></i></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table></div></div>

<?php if ($rows): ?>
<?php endif; ?>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
