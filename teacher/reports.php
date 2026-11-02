<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_TEACHER]);

$db = getDB();
$uid = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = 'Teacher Reports';

$view = $_GET['view'] ?? 'students';
$period = $_GET['period'] ?? 'month';
[$fromDate, $toDate] = reportPeriodDateRange($period, $_GET['from'] ?? null, $_GET['to'] ?? null);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$classId = (int)($_GET['class_id'] ?? 0);

$riskPct  = max(1, min(100, (int)($_GET['risk_pct']  ?? 1)));

// د حاضرۍ حالت (Attendance Status) — درې انتخابونه: warning / deprived / all
// (V20 fix: "all" اضافه شو ترڅو استاد وکولای شي د خپلې خوښۍ سره
//  د تولو Studentsو رپوت وکټي — د حد تر ثبتو پرته.)
$allowedRiskModes = ['warning', 'deprived', 'all'];
$riskMode = $_GET['risk_mode'] ?? 'all';
if (!in_array($riskMode, $allowedRiskModes, true)) $riskMode = 'all';

$subjectOptionsStmt = $db->prepare("SELECT DISTINCT s.id,s.name FROM timetable t JOIN subjects s ON s.id=t.subject_id WHERE t.teacher_id=? AND t.status='approved' ORDER BY s.name");
$subjectOptionsStmt->execute([$uid]);
$subjectOptions = $subjectOptionsStmt->fetchAll();

$classOptionsStmt = $db->prepare("SELECT DISTINCT c.id,c.name,c.section FROM timetable t JOIN classes c ON c.id=t.class_id WHERE t.teacher_id=? AND t.status='approved' ORDER BY c.name,c.section");
$classOptionsStmt->execute([$uid]);
$classOptions = $classOptionsStmt->fetchAll();

$where = ["t.teacher_id=?", "s.session_date BETWEEN ? AND ?"];
$params = [$uid, $fromDate, $toDate];
if ($subjectId) { $where[] = "s.subject_id=?"; $params[] = $subjectId; }
if ($classId)   { $where[] = "s.class_id=?";   $params[] = $classId; }
$whereSql = implode(' AND ', $where);

$subjectStmt = $db->prepare("SELECT s.subject_id, c.id as class_id, sub.name as subject_name, c.name as class_name, c.section,
    SUM(CASE WHEN r.status='absent' THEN 1 ELSE 0 END) as absent_marks, COUNT(r.id) as total_marks
    FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id
    JOIN classes c ON c.id=s.class_id JOIN subjects sub ON sub.id=s.subject_id
    LEFT JOIN attendance_records r ON r.session_id=s.id
    WHERE $whereSql GROUP BY s.subject_id, s.class_id ORDER BY sub.name, c.name");
$subjectStmt->execute($params);
$subjectRows = $subjectStmt->fetchAll();
foreach ($subjectRows as &$row) {
    $semesterCredits = semesterCreditsForClassSubject((int)$row['class_id'], (int)$row['subject_id']);
    $row['semester_credits'] = $semesterCredits;
    $row['absence_pct'] = absencePercentFromSemesterCredits((int)$row['absent_marks'], $semesterCredits);
}
unset($row);
usort($subjectRows, fn($a, $b) => (($b['absence_pct'] <=> $a['absence_pct']) ?: strcmp((string)$a['subject_name'], (string)$b['subject_name'])));

$studentStmt = $db->prepare("SELECT u.full_name, u.student_id, s.subject_id, c.id as class_id, sub.name as subject_name, c.name as class_name, c.section,
    SUM(CASE WHEN r.status='absent' THEN 1 ELSE 0 END) as absent_marks, COUNT(r.id) as total_marks
    FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id
    JOIN classes c ON c.id=s.class_id JOIN subjects sub ON sub.id=s.subject_id
    JOIN attendance_records r ON r.session_id=s.id JOIN users u ON u.id=r.student_id
    WHERE $whereSql GROUP BY u.id, s.subject_id ORDER BY u.full_name ASC");
$studentStmt->execute($params);
$studentRows = [];
foreach ($studentStmt->fetchAll() as $row) {
    $semesterCredits = semesterCreditsForClassSubject((int)$row['class_id'], (int)$row['subject_id']);
    $row['semester_credits'] = $semesterCredits;
    $row['absence_pct'] = absencePercentFromSemesterCredits((int)$row['absent_marks'], $semesterCredits);
    if ((float)$row['absence_pct'] >= $riskPct) $studentRows[] = $row;
}
$studentRows = reportApplyNotificationFilter($studentRows, $riskMode);
usort($studentRows, fn($a, $b) => (($b['absence_pct'] <=> $a['absence_pct']) ?: strcmp((string)$a['full_name'], (string)$b['full_name'])));

require_once __DIR__.'/../includes/header.php';
?>
<!-- ===== د راپور Title، د لید بدلولو، او د پرنټ/ایکسپورټ تڼۍ ===== -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="mb-0"><i class="fas fa-chart-bar me-2 text-primary"></i><?= t('reports') ?></h5>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <div class="btn-group btn-group-sm" role="group">
      <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'students'])) ?>" class="btn btn-outline-primary <?= $view === 'students' ? 'active' : '' ?>">د محصل پر بنسټ</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'subjects'])) ?>" class="btn btn-outline-primary <?= $view === 'subjects' ? 'active' : '' ?>">د مضمون پر بنسټ</a>
    </div>
    <!-- V21: د استاد د راپور لپاره Print + Excel + PDF افشنونه اضافه شول -->
    <div class="btn-group btn-group-sm" role="group">
      <button type="button" onclick="window.print()" class="btn btn-outline-secondary" title="Print"><i class="fas fa-print me-1"></i>Print</button>
      <a href="<?= BASE_URL ?>/reports/export_csv.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success" title="Excel (CSV)"><i class="fas fa-file-excel me-1"></i>Excel</a>
      <a href="<?= BASE_URL ?>/reports/export_pdf.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-danger" title="PDF"><i class="fas fa-file-pdf me-1"></i>PDF</a>
    </div>
  </div>
</div>

<!-- ===== د فلټر فورمه ===== -->
<form method="GET" class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <input type="hidden" name="view" value="<?= h($view) ?>"/>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">Period</label>
        <select name="period" class="form-select form-select-sm">
          <?php foreach (['day'=>'Day','week'=>'week','month'=>'month','semester'=>'semester','custom'=>'custom'] as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= h($fromDate) ?>"/>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= h($toDate) ?>"/>
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Subject</label>
        <select name="subject_id" class="form-select form-select-sm">
          <option value="">ټول Subjects</option>
          <?php foreach ($subjectOptions as $subject): ?>
            <option value="<?= (int)$subject['id'] ?>" <?= $subjectId === (int)$subject['id'] ? 'selected' : '' ?>><?= h($subject['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">Class</label>
        <select name="class_id" class="form-select form-select-sm">
          <option value="">All Classes</option>
          <?php foreach ($classOptions as $class): ?>
            <option value="<?= (int)$class['id'] ?>" <?= $classId === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1"> Percentage %</label>
        <input type="number" min="1" max="100" name="risk_pct" class="form-control form-control-sm" value="<?= (int)$riskPct ?>"/>
      </div>
      <div class="col-md-2">
        <!-- V21: درې حالتونه — All ، Warning (≥20% او <25%) ، Deprived (≥25%) -->
   
        </select>
      </div>
      <div class="col-md-1">
        <button class="btn btn-sm btn-primary w-100"><i class="fas fa-filter"></i></button>
      </div>
    </div>
  </div>
</form>

<!-- ===== د راپور جدول - یوازې د غیر حاضرۍ تمرکز ===== -->
<?php if ($view === 'subjects'): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white"><i class="fas fa-book me-2"></i>د مضمون پر بنسټ د غیر حاضرۍ راپور</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Subject</th>
          <th>Class</th>
          <th>Absence %</th>
          <th>Risk Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$subjectRows): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No data found.</td></tr>
        <?php endif; ?>
        <?php foreach ($subjectRows as $row): $pct = (float)$row['absence_pct']; ?>
          <tr>
            <td><?= h($row['subject_name']) ?></td>
            <td><?= h(trim($row['class_name'].' '.($row['section'] ?? ''))) ?></td>
            <td><span class="badge bg-<?= absencePercentClass($pct) ?> fs-6"><?= h((string)$row['absence_pct']) ?>%</span></td>
            <td>
              <?php if ($pct >= attendanceDeprivationPercentThreshold()): ?>
                <span class="badge bg-danger">Deprived</span>
              <?php elseif ($pct >= attendanceWarningPercentThreshold()): ?>
                <span class="badge bg-warning text-dark">At Risk</span>
              <?php else: ?>
                <span class="badge bg-success">Safe</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white"><i class="fas fa-user-graduate me-2"></i>د محصل پر بنسټ د غیر حاضرۍ راپور</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Student</th>
          <th>Class</th>
          <th>Subject</th>
          <th>Absence %</th>
          <th>Risk Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$studentRows): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No data found.</td></tr>
        <?php endif; ?>
        <?php foreach ($studentRows as $row): $pct = (float)$row['absence_pct'];
            
            $nameOut = (string)$row['full_name'];
            $sidOut  = (string)($row['student_id'] ?? '');
        ?>
          <tr>
            <td>
              <?= h($nameOut) ?>
              <div class="small text-muted"><?= h($sidOut) ?></div>
            </td>
            <td><?= h(trim($row['class_name'].' '.($row['section'] ?? ''))) ?></td>
            <td><?= h($row['subject_name']) ?></td>
            <td><span class="badge bg-<?= absencePercentClass($pct) ?> fs-6"><?= h((string)$row['absence_pct']) ?>%</span></td>
            <td>
              <?php if ($pct >= attendanceDeprivationPercentThreshold()): ?>
                <span class="badge bg-danger">Deprived</span>
              <?php elseif ($pct >= attendanceWarningPercentThreshold()): ?>
                <span class="badge bg-warning text-dark">At Risk</span>
              <?php else: ?>
                <span class="badge bg-success">Safe</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
