<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();

$db = getDB();
$pageTitle = 'Low Attendance Report';
$thresh = (int)getSetting('low_att_threshold',75);
$scope = sqlScopeForClassAlias('c','s','u');
$params = [];
$where = "WHERE 1=1{$scope['sql']}";
$params = array_merge($params, $scope['params']);

$sql = "SELECT u.id,u.full_name,u.student_id,d.name as dept_name,
    COUNT(r.id) as total,
    SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN r.status='absent' THEN 1 ELSE 0 END) as absent_count,
    ROUND(SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END)*100/NULLIF(COUNT(r.id),0),1) as pct
    FROM attendance_records r
    JOIN users u ON u.id=r.student_id
    JOIN attendance_sessions s ON s.id=r.session_id
    JOIN classes c ON c.id=s.class_id
    LEFT JOIN departments d ON d.id=u.department_id
    {$where}
    GROUP BY u.id
    HAVING pct < ? OR absent_count >= ?
    ORDER BY absent_count DESC, pct ASC";
$params[] = $thresh;
$params[] = deprivationAbsenceThreshold();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="mb-0"><i class="fas fa-exclamation-triangle text-warning me-2"></i>د تۍ حاضرۍ راپور</h5>
  </div>
  <!-- V21: Print + Excel + PDF buttons -->
  <div class="btn-group btn-group-sm">
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary"><i class="fas fa-print me-1"></i>Print</button>
    <a href="<?= BASE_URL ?>/reports/export_csv.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success"><i class="fas fa-file-excel me-1"></i>Excel</a>
    <a href="<?= BASE_URL ?>/reports/export_pdf.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-danger"><i class="fas fa-file-pdf me-1"></i>PDF</a>
  </div>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead><tr><th>Student</th><th>ID</th><th>Faculty</th><th>Credits</th><th>Present</th><th>Absences</th><th>Attendance %</th><th>Standing</th></tr></thead>
      <tbody>
      <?php if(!$students): ?><tr><td colspan="8" class="text-center text-muted py-4">No at-risk students were found.</td></tr><?php endif; ?>
      <?php foreach($students as $s): ?>
        <?php
          $riskBadge = attendanceRiskBadgeByAbsences((int)$s['absent_count']);
          $standing = (int)$s['absent_count'] >= deprivationAbsenceThreshold() ? 'Deprived' : ((int)$s['absent_count'] >= absenceWarningThreshold() ? 'Warning' : 'Monitor');
        ?>
        <tr class="<?=$riskBadge==='danger'?'table-danger':'table-warning'?>">
          <td><?=h($s['full_name'])?></td>
          <td><?=h($s['student_id'] ?? '-')?></td>
          <td><?=h($s['dept_name'] ?? '-')?></td>
          <td><?=$s['total']?></td>
          <td><?=$s['present_count']?></td>
          <td><span class="badge bg-<?=$riskBadge?>"><?=$s['absent_count']?></span></td>
          <td><span class="badge bg-danger"><?=$s['pct']?>%</span></td>
          <td><span class="badge bg-<?=$riskBadge?>"><?=$standing?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>