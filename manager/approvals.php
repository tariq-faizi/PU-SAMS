<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
redirectLegacyDeanManagerRoute();
requireRole([ROLE_ATT_MGR]);

$db = getDB();
$roleId = (int)($_SESSION['role_id'] ?? 0);
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$pageTitle = 'My Timetable Submissions';

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending','approved','rejected'], true)) {
    $statusFilter = 'pending';
}

$statusSqlMap = [
    'pending'  => "t.status IN ('draft','faculty_approved')",
    'approved' => "t.status = 'approved'",
    'rejected' => "t.status = 'rejected'",
];

$ttWhere = ['t.faculty_id=?', $statusSqlMap[$statusFilter]];
$ttParams = [$facultyId];
$ttSql = "SELECT t.*, c.name AS class_name, c.section, sub.name AS subject_name,
                 u.full_name AS teacher_name, d.name AS department_name
          FROM timetable t
          JOIN classes c   ON c.id=t.class_id
          JOIN subjects sub ON sub.id=t.subject_id
          JOIN users u     ON u.id=t.teacher_id
          LEFT JOIN departments d ON d.id=t.department_id
          WHERE ".implode(' AND ',$ttWhere)."
          ORDER BY t.created_at DESC LIMIT 200";
$ttStmt = $db->prepare($ttSql); $ttStmt->execute($ttParams); $ttRows = $ttStmt->fetchAll();

$counters = ['pending'=>0,'approved'=>0,'rejected'=>0];
$cPending  = $db->prepare("SELECT COUNT(*) FROM timetable WHERE faculty_id=? AND status IN ('draft','faculty_approved')");
$cPending->execute([$facultyId]);
$counters['pending']  = (int)$cPending->fetchColumn();
$cApproved = $db->prepare("SELECT COUNT(*) FROM timetable WHERE faculty_id=? AND status='approved'");
$cApproved->execute([$facultyId]);
$counters['approved'] = (int)$cApproved->fetchColumn();
$cRejected = $db->prepare("SELECT COUNT(*) FROM timetable WHERE faculty_id=? AND status='rejected'");
$cRejected->execute([$facultyId]);
$counters['rejected'] = (int)$cRejected->fetchColumn();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="fas fa-paper-plane me-2"></i>My Requested</h5>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge bg-warning text-dark">Pending <?= $counters['pending'] ?></span>
    <span class="badge bg-success">Approved <?= $counters['approved'] ?></span>
    <span class="badge bg-danger">Rejected <?= $counters['rejected'] ?></span>
  </div>
</div>

<!-- درې حالته فلټر pills (Pending / Approved / Rejected) -->
<ul class="nav nav-pills mb-3">
  <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $k=>$v): ?>
    <li class="nav-item">
      <a class="nav-link <?= $statusFilter===$k?'active':'' ?>" href="?status=<?= urlencode($k) ?>">
        <?= h($v) ?>
        <span class="badge bg-light text-dark ms-1"><?= (int)$counters[$k] ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<div class="card mb-4">
  <div class="card-header"><i class="fas fa-calendar-check me-2"></i>Timetable submissions
    <span class="badge bg-secondary ms-2"><?= count($ttRows) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr>
        <th>#</th><th>Department</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Day/Time</th><th>Status</th><th>Submitted</th>
      </tr></thead>
      <tbody>
        <?php if (!$ttRows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No <?= h($statusFilter) ?> timetable submissions.</td></tr>
        <?php else: foreach ($ttRows as $tt): ?>
          <tr id="tt-<?= (int)$tt['id'] ?>">
            <td><?= (int)$tt['id'] ?></td>
            <td><?= h($tt['department_name'] ?? '—') ?></td>
            <td><?= h($tt['class_name']) ?> <?= h($tt['section'] ?? '') ?></td>
            <td><?= h($tt['subject_name']) ?></td>
            <td><?= h($tt['teacher_name']) ?></td>
            <td>
              <?= h(DAYS_OF_WEEK[(int)$tt['day_of_week']] ?? '?') ?>
              <?= h(substr($tt['start_time'],0,5)) ?>–<?= h(substr($tt['end_time'],0,5)) ?>
            </td>
            <td>
              <?php
                $badge = ['draft'=>'secondary','faculty_approved'=>'warning text-dark','approved'=>'success','rejected'=>'danger'][$tt['status']] ?? 'secondary';
                $label = ucfirst(str_replace('_',' ',$tt['status']));
              ?>
              <span class="badge bg-<?= $badge ?>"><?= h($label) ?></span>
            </td>
            <td class="text-nowrap small text-muted"><?= h(date('d M Y H:i', strtotime($tt['created_at']))) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
