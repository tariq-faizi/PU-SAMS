<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
$db=getDB(); $pageTitle='Absence List';
$date=$_GET['date']??date('Y-m-d');
$scope = sqlScopeForClassAlias('c','s','u');
$where="WHERE r.status='absent' AND s.session_date=?{$scope['sql']}"; $params=array_merge([$date], $scope['params']);
$rows=$db->prepare("SELECT DISTINCT u.full_name,u.student_id,c.name as class_name,sub.name as sub_name
    FROM attendance_records r JOIN attendance_sessions s ON s.id=r.session_id
    JOIN classes c ON c.id=s.class_id JOIN subjects sub ON sub.id=s.subject_id JOIN users u ON u.id=r.student_id
    $where ORDER BY u.full_name");
$rows->execute($params); $rows=$rows->fetchAll();
require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between mb-3">
  <h5 class="mb-0"><i class="fas fa-times-circle text-danger me-2"></i>د غیر-حاضرینو لیست</h5>
  <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i>Print</button>
</div>
<form class="row g-2 mb-3" method="GET">
  <div class="col-auto"><input type="date" name="date" class="form-control form-control-sm" value="<?=h($date)?>"/></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Filter</button></div>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-sm mb-0">
    <thead><tr><th>Student</th><th>ID</th><th>Class</th><th>Subject</th></tr></thead>
    <tbody>
    <?php if(!$rows):?><tr><td colspan="4" class="text-center py-4 text-muted">No absences on this date.</td></tr><?php endif;?>
    <?php foreach($rows as $r):?><tr>
      <td><?=h($r['full_name'])?></td><td><?=h($r['student_id']??'-')?></td><td><?=h($r['class_name'])?></td><td><?=h($r['sub_name'])?></td>
    </tr><?php endforeach;?>
    </tbody>
  </table>
</div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
