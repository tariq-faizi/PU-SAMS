<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('view_all_reports');

$db = getDB();
$pageTitle = t('attendance');
$options = reportFilterOptions();
$date = $_GET['date'] ?? date('Y-m-d');
$facultyId    = (int)($_GET['faculty_id'] ?? 0);
$departmentId = (int)($_GET['department_id'] ?? 0);
$classId      = (int)($_GET['class_id'] ?? 0);
$subjectId    = (int)($_GET['subject_id'] ?? 0);
$status       = normalizeSessionStatus($_GET['status'] ?? '');

$state        = (string)($_GET['state'] ?? '');
$page         = max(1,(int)($_GET['p'] ?? 1));
$perPage      = 25;

$where  = ["s.session_date=?"]; $params = [$date];
if ($facultyId)    { $where[] = 'tt.faculty_id=?';   $params[] = $facultyId; }
if ($departmentId) { $where[] = 'c.department_id=?'; $params[] = $departmentId; }
if ($classId)      { $where[] = 's.class_id=?';     $params[] = $classId; }
if ($subjectId)    { $where[] = 's.subject_id=?';   $params[] = $subjectId; }
applySessionStatusFilterClause($where, $params, 's.status', $status);

if (function_exists('applyEffectiveStateFilter')) {
    applyEffectiveStateFilter($where, $params, 's.status', 's.start_time', 's.end_time', 's.session_date', $state);
}
$whereSql = 'WHERE '.implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(DISTINCT s.id) FROM attendance_sessions s JOIN timetable tt ON tt.id=s.timetable_id JOIN classes c ON c.id=s.class_id $whereSql");
$totalStmt->execute($params); $total = (int)$totalStmt->fetchColumn();
$pag = paginate($total,$page,$perPage);

$sessions = $db->prepare("SELECT s.*, f.name as faculty_name, d.name as department_name, c.name as class_name, c.section, sub.name as sub_name, u.full_name as teacher_name,
                                 COUNT(r.id) as total_students,
                                 SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) as present_count,
                                 SUM(CASE WHEN r.status='absent'  THEN 1 ELSE 0 END) as absent_count
                          FROM attendance_sessions s
                          JOIN timetable tt ON tt.id=s.timetable_id
                          JOIN faculties f  ON f.id=tt.faculty_id
                          JOIN classes c    ON c.id=s.class_id
                          LEFT JOIN departments d ON d.id=c.department_id
                          JOIN subjects sub ON sub.id=s.subject_id
                          JOIN users u      ON u.id=s.teacher_id
                          LEFT JOIN attendance_records r ON r.session_id=s.id
                          $whereSql
                          GROUP BY s.id
                          ORDER BY s.session_date DESC, s.start_time DESC
                          LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$sessions->execute($params); $sessions = $sessions->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="mb-1"><i class="fas fa-clock me-2"></i><?= t('attendance') ?></h5>
  </div>
</div>

<form class="row g-2 mb-3" method="GET">
  <div class="col-md-2"><input name="date" type="date" class="form-control form-control-sm" value="<?=h($date)?>"/></div>
  <div class="col-md-2"><select name="faculty_id" data-cascade="faculty" class="form-select form-select-sm"><option value="">— <?= t('faculties') ?> —</option><?php foreach($options['faculties'] as $faculty): ?><option value="<?=$faculty['id']?>" <?=$facultyId==(int)$faculty['id']?'selected':''?>><?=h($faculty['name'])?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select name="department_id" data-cascade="department" class="form-select form-select-sm"><option value="">— <?= t('departments') ?> —</option><?php foreach($options['departments'] as $department): ?><option value="<?=$department['id']?>" <?=$departmentId==(int)$department['id']?'selected':''?>><?=h($department['name'])?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select name="class_id" data-cascade="class" class="form-select form-select-sm"><option value="">— <?= t('classes') ?> —</option><?php foreach($options['classes'] as $class): ?><option value="<?=$class['id']?>" <?=$classId==(int)$class['id']?'selected':''?>><?=h(trim($class['name'].' '.($class['section'] ?? '')))?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select name="subject_id" data-cascade="subject" class="form-select form-select-sm"><option value="">— <?= t('subjects') ?> —</option><?php foreach($options['subjects'] as $subject): ?><option value="<?=$subject['id']?>" <?=$subjectId==(int)$subject['id']?'selected':''?>><?=h($subject['name'])?></option><?php endforeach; ?></select></div>
  <div class="col-md-1"><select name="status" class="form-select form-select-sm"><option value=""><?= t('all') ?></option><?php foreach(sessionStatusFilterOptions() as $item => $label): ?><option value="<?=h($item)?>" <?=$status===$item?'selected':''?>><?=h($label)?></option><?php endforeach; ?></select></div>
  <div class="col-md-1"><button class="btn btn-sm btn-primary w-100"><?= t('filter') ?></button></div>
</form>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle ros-roster-table">
  <thead><tr>
    <th><?= t('ui_date') ?></th>
    <th><?= t('faculties') ?></th>
    <th><?= t('departments') ?></th>
    <th><?= t('class') ?></th>
    <th><?= t('subject') ?></th>
    <th><?= t('teacher') ?></th>
    <th><?= t('credit') ?></th>
    <th><?= t('ui_attendance_pct') ?></th>
    <th><?= t('ui_status') ?></th>
    <th></th>
  </tr></thead>
  <tbody>
    <?php if(!$sessions): ?>
      <tr><td colspan="10" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
    <?php endif; ?>
    <?php foreach($sessions as $s):
        $pct = (int)$s['total_students'] > 0 ? round(((int)$s['present_count'] / (int)$s['total_students']) * 100, 1) : 0;
        
        $__rowState  = function_exists('effectiveSessionState') ? effectiveSessionState($s) : '';
        $__isUpcoming = ($__rowState === 'pending'); ?>
      <tr>
        <td><?=h($s['session_date'])?> <div class="small text-muted"><?=h(substr($s['start_time'],0,5))?>–<?=h(substr($s['end_time'],0,5))?></div></td>
        <td><?=h($s['faculty_name'])?></td>
        <td><?=h($s['department_name'] ?? '—')?></td>
        <td><?=h($s['class_name'])?> <?=h($s['section'])?></td>
        <td><?=h($s['sub_name'])?></td>
        <td><?=h($s['teacher_name'])?></td>
        <td><?=h($s['slot_label'])?></td>
        <td><span class="badge bg-<?=attendancePercentClass($pct)?>"><?=$pct?>%</span>
            <div class="small text-muted"><?= (int)$s['absent_count'] ?> <?= t('ui_att_absent') ?></div></td>
        
        <td><?= function_exists('renderEffectiveSessionBadge') ? renderEffectiveSessionBadge($s) : '<span class="badge bg-'.attendanceStatusClass($s['status']).'">'.h(sessionStatusLabel($s['status'])).'</span>' ?></td>
        <td>
          <a href="<?=BASE_URL?>/admin/session_detail.php?id=<?=$s['id']?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
          <?php if (!$__isUpcoming): ?>
            <a href="<?=BASE_URL?>/manager/attendance_edit.php?session_id=<?=$s['id']?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-pen-to-square"></i></a>
          <?php else: ?>
            <span class="btn btn-sm btn-outline-secondary disabled" title="<?= h(function_exists('t') ? t('ui_pending_lock_msg', [], 'Upcoming — edit locked') : 'Upcoming — edit locked') ?>" aria-disabled="true" style="pointer-events:none;opacity:.55"><i class="fas fa-lock"></i></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table></div></div>

<?php if(($pag['pages'] ?? 1) > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center mb-0">
  <?php for($i=1; $i<=$pag['pages']; $i++): ?>
    <li class="page-item <?=$i===$pag['page']?'active':''?>">
      <a class="page-link" href="?<?=http_build_query(array_merge($_GET,['p'=>$i]))?>"><?=$i?></a>
    </li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
