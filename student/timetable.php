<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin(); requireRole([ROLE_STUDENT]);
$db = getDB(); $pageTitle = t('timetable');
$dayFilter = isset($_GET['day']) && $_GET['day']!=='' ? (int)$_GET['day'] : -1;
$where = "t.class_id=? AND t.status='approved'"; $params = [(int)$_SESSION['class_id']];
if ($dayFilter >= 0 && $dayFilter <= 6) { $where .= " AND t.day_of_week=?"; $params[] = $dayFilter; }
$stmt = $db->prepare("SELECT t.*, sub.name as subject_name, u.full_name as teacher_name, sem.name as semester_name, COALESCE(r.name,t.room) as room_name FROM timetable t JOIN subjects sub ON sub.id=t.subject_id JOIN users u ON u.id=t.teacher_id LEFT JOIN semesters sem ON sem.id=t.semester_id LEFT JOIN rooms r ON r.id=t.room_id WHERE $where ORDER BY t.day_of_week, t.start_time");
$stmt->execute($params); $rows = $stmt->fetchAll();
require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i><?=t('timetable')?></h5>
  <form method="GET" class="d-flex gap-2 align-items-center">
    <label class="form-label mb-0 small me-1"><?=t('day_filter')?>:</label>
    <select name="day" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">All days</option>
      <?php foreach (DAYS_OF_WEEK as $idx => $d): ?>
        <option value="<?=$idx?>" <?=$dayFilter===$idx?'selected':''?>><?=h($d)?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Day</th><th>Time</th><th><?=t('subject')?></th><th><?=t('teacher')?></th><th>Semester</th><th>Room</th><th>Credits</th></tr></thead>
      <tbody>
      <?php if(!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No approved timetable found.</td></tr><?php endif; ?>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><?=h(DAYS_OF_WEEK[(int)$r['day_of_week']] ?? '')?></td>
        <td><?=h(substr($r['start_time'],0,5))?>–<?=h(substr($r['end_time'],0,5))?></td>
        <td><?=h($r['subject_name'])?></td>
        <td><?=h($r['teacher_name'])?></td>
        <td><?=h($r['semester_name'] ?? '-')?></td>
        <td><?=h($r['room_name'])?></td>
        <td><?=$r['credit_units']?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
