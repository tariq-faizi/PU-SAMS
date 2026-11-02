<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();

$db = getDB();
$roleId = (int)($_SESSION['role_id'] ?? 0);
$pageTitle = t('reports');

$scopeFacultyId = currentFacultyScopeId();
$options = reportFilterOptions(['faculty_id' => $scopeFacultyId]);

$period = $_GET['period'] ?? 'month';
[$fromDate, $toDate] = reportPeriodDateRange($period, $_GET['from'] ?? null, $_GET['to'] ?? null);

$facultyId    = $scopeFacultyId ?: (int)($_GET['faculty_id'] ?? 0);
$departmentId = (int)($_GET['department_id'] ?? 0);
$classId      = (int)($_GET['class_id'] ?? 0);
$subjectId    = (int)($_GET['subject_id'] ?? 0);
$semesterId   = (int)($_GET['semester_id'] ?? 0);
$dayOfWeek    = $_GET['day_of_week'] ?? '';
$riskPct      = max(1, min(100, (int)($_GET['risk_pct'] ?? attendanceWarningPercentThreshold())));
$riskMode     = $_GET['risk_mode'] ?? 'all';

$where = ["s.session_date BETWEEN ? AND ?"]; $params = [$fromDate, $toDate];
if ($facultyId)     { $where[] = "t.faculty_id=?";     $params[] = $facultyId; }
elseif ($scopeFacultyId) { $where[] = "t.faculty_id=?"; $params[] = $scopeFacultyId; }
if ($departmentId)  { $where[] = "c.department_id=?";  $params[] = $departmentId; }
if ($classId)       { $where[] = "s.class_id=?";       $params[] = $classId; }
if ($subjectId)     { $where[] = "s.subject_id=?";     $params[] = $subjectId; }
if ($semesterId)    { $where[] = "t.semester_id=?";    $params[] = $semesterId; }
if ($dayOfWeek !== '' && $dayOfWeek !== 'all') { $where[] = "DAYOFWEEK(s.session_date)-1=?"; $params[] = (int)$dayOfWeek; }

if ($roleId === ROLE_STUDENT) { $where[] = "s.class_id=?"; $params[] = (int)($_SESSION['class_id'] ?? 0); }

if ($roleId === ROLE_TEACHER) { $where[] = "s.teacher_id=?"; $params[] = (int)($_SESSION['user_id'] ?? 0); }

$whereSql = implode(' AND ', $where);

$classStmt = $db->prepare("SELECT c.id as class_id, c.name as class_name, c.section, d.name as department_name,
    SUM(CASE WHEN r.status='absent' THEN 1 ELSE 0 END) as absent_marks,
    COUNT(r.id) as total_marks
    FROM attendance_sessions s
    JOIN timetable t ON t.id=s.timetable_id
    JOIN classes c ON c.id=s.class_id
    LEFT JOIN departments d ON d.id=c.department_id
    LEFT JOIN attendance_records r ON r.session_id=s.id
    WHERE $whereSql
    GROUP BY c.id
    ORDER BY c.name");
$classStmt->execute($params);
$classRows = $classStmt->fetchAll();
foreach ($classRows as &$row) {
    $semesterCredits = semesterCreditsForClassTotal((int)$row['class_id']) * classActiveStudentCount((int)$row['class_id']);
    $row['semester_credits'] = $semesterCredits;
    $row['absence_pct'] = absencePercentFromSemesterCredits((int)$row['absent_marks'], $semesterCredits);
}
unset($row);
usort($classRows, fn($a, $b) => (($b['absence_pct'] <=> $a['absence_pct']) ?: strcmp((string)$a['class_name'], (string)$b['class_name'])));

$riskStmt = $db->prepare("SELECT u.full_name, u.student_id, c.id as class_id, c.name as class_name, c.section, s.subject_id, sub.name as subject_name,
    SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) as present_marks,
    SUM(CASE WHEN r.status='absent' THEN 1 ELSE 0 END) as absent_marks,
    COUNT(r.id) as total_marks
    FROM attendance_sessions s
    JOIN timetable t ON t.id=s.timetable_id
    JOIN classes c ON c.id=s.class_id
    JOIN subjects sub ON sub.id=s.subject_id
    JOIN attendance_records r ON r.session_id=s.id
    JOIN users u ON u.id=r.student_id
    WHERE $whereSql
    GROUP BY u.id, s.subject_id
    ORDER BY u.full_name ASC");
$riskStmt->execute($params);
$riskRows = [];
foreach ($riskStmt->fetchAll() as $row) {
    $semesterCredits = semesterCreditsForClassSubject((int)$row['class_id'], (int)$row['subject_id']);
    $row['semester_credits'] = $semesterCredits;
    $row['absence_pct'] = absencePercentFromSemesterCredits((int)$row['absent_marks'], $semesterCredits);
    if ((float)$row['absence_pct'] >= $riskPct) $riskRows[] = $row;
}
if ($riskMode === 'warning') {
    $riskRows = array_values(array_filter($riskRows, fn($row) => (float)$row['absence_pct'] >= attendanceWarningPercentThreshold() && (float)$row['absence_pct'] < attendanceDeprivationPercentThreshold()));
} elseif ($riskMode === 'deprived') {
    $riskRows = array_values(array_filter($riskRows, fn($row) => (float)$row['absence_pct'] >= attendanceDeprivationPercentThreshold()));
}
usort($riskRows, fn($a, $b) => (($b['absence_pct'] <=> $a['absence_pct']) ?: strcmp((string)$a['full_name'], (string)$b['full_name'])));

require_once __DIR__.'/../includes/header.php';
?>
<div class="v6-page-title">
  <div>
    <h4><i class="fas fa-chart-bar me-2 text-primary"></i><?= t('reports') ?></h4>
    <div class="v6-subtitle">
      <?php if($roleId===ROLE_STUDENT): ?>
        ستاسو شخصي د غیر حاضرۍ راپور
      <?php elseif($roleId===ROLE_TEACHER): ?>
        ستاسو د تدریس د غیر حاضرۍ راپور
      <?php else: ?>
        د پوهنځۍ / پوهنتون راپورونه
      <?php endif; ?>
    </div>
  </div>
  <div class="btn-group btn-group-sm">
    <!-- V21: Print تڼۍ هم اضافه شوه -->
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary"><i class="fas fa-print me-1"></i>Print</button>
    <a href="<?= BASE_URL ?>/reports/export_csv.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success"><i class="fas fa-file-csv me-1"></i>CSV</a>
    <a href="<?= BASE_URL ?>/reports/export_pdf.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-danger"><i class="fas fa-file-pdf me-1"></i>PDF</a>
  </div>
</div>

<!-- ─── د فلترونو پټه (Filters) ─── -->
<div class="v6-filter-card">
  <form method="GET" class="row g-2">
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('period') ?></label>
      <select name="period" class="form-select form-select-sm">
        <?php foreach (['day'=>t('day'),'week'=>t('week'),'month'=>t('month'),'semester'=>t('semester'),'custom'=>t('custom')] as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label small mb-1"><?= t('from') ?></label><input type="date" name="from" class="form-control form-control-sm" value="<?= h($fromDate) ?>"/></div>
    <div class="col-md-2"><label class="form-label small mb-1"><?= t('to') ?></label><input type="date" name="to" class="form-control form-control-sm" value="<?= h($toDate) ?>"/></div>
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('day_filter') ?></label>
      <select name="day_of_week" class="form-select form-select-sm">
        <option value=""><?= t('all') ?></option>
        <?php foreach(DAYS_OF_WEEK as $i=>$d): ?><option value="<?= $i ?>" <?= $dayOfWeek!==''&&(int)$dayOfWeek===$i?'selected':'' ?>><?= h($d) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($roleId !== ROLE_STUDENT && $roleId !== ROLE_TEACHER): ?>
      <?php if (!$scopeFacultyId): ?>
        <div class="col-md-2"><label class="form-label small mb-1"><?= t('faculties') ?></label>
          <select name="faculty_id" data-cascade="faculty" class="form-select form-select-sm">
            <option value=""><?= t('all') ?></option>
            <?php foreach ($options['faculties'] as $faculty): ?>
              <option value="<?= (int)$faculty['id'] ?>" <?= $facultyId === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-2"><label class="form-label small mb-1"><?= t('departments') ?></label>
        <select name="department_id" data-cascade="department" class="form-select form-select-sm">
          <option value=""><?= t('all') ?></option>
          <?php foreach ($options['departments'] as $department): ?>
            <option value="<?= (int)$department['id'] ?>" <?= $departmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label small mb-1"><?= t('class') ?></label>
        <select id="reportFilterClass" name="class_id" data-cascade="class" data-cascade-target="reportFilterSemester" class="form-select form-select-sm">
          <option value=""><?= t('all') ?></option>
          <?php foreach ($options['classes'] as $class): ?>
            <option value="<?= (int)$class['id'] ?>" <?= $classId === (int)$class['id'] ? 'selected' : '' ?>><?= h(trim($class['name'].' '.($class['section'] ?? ''))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label small mb-1"><?= t('semester') ?></label>
        <select id="reportFilterSemester" name="semester_id" data-cascade="semester" data-cascade-source="reportFilterClass" data-preselect="<?= h((string)$semesterId) ?>" class="form-select form-select-sm">
          <option value=""><?= t('all') ?></option>
          <?php foreach (($classId ? listSemestersByClass($classId) : $options['semesters']) as $sem): ?>
            <option value="<?= (int)$sem['id'] ?>" <?= $semesterId === (int)$sem['id'] ? 'selected' : '' ?>><?= h($sem['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-2"><label class="form-label small mb-1"><?= t('subject') ?></label>
      <select name="subject_id" data-cascade="subject" class="form-select form-select-sm">
        <option value=""><?= t('all') ?></option>
        <?php foreach ($options['subjects'] as $subject): ?>
          <option value="<?= (int)$subject['id'] ?>" <?= $subjectId === (int)$subject['id'] ? 'selected' : '' ?>><?= h($subject['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label small mb-1"><?= t('risk_pct') ?></label>
      <input type="number" min="1" max="100" name="risk_pct" class="form-control form-control-sm" value="<?= (int)$riskPct ?>"/>
    </div>
    <div class="col-md-2"><label class="form-label small mb-1"><?= t('') ?></label>
      <!-- V21: درې حالتونه — All ، Warning (≥20% او <25%) ، Deprived (≥25%) -->
 
    </div>
    <div class="col-md-2 align-self-end">
      <button class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i><?= t('apply') ?></button>
    </div>
  </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     د اوسط، ټول کریډیټ، او د حضور سلنه کارتونه له دې صفحې پاک شول
     (REQ #1 – Removed average / total credits / attendance% cards)
     ═══════════════════════════════════════════════════════════════ -->

<div class="row g-3">
  <!-- د کلاس په کچه د غیر حاضرۍ راپور -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-school me-2"></i><?= t('absence_pct') ?> · <?= t('class') ?></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th><?= t('departments') ?></th><th><?= t('class') ?></th><th><?= t('absence_pct') ?></th></tr>
          </thead>
          <tbody>
            <?php if (!$classRows): ?>
              <tr><td colspan="3" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
            <?php else: foreach ($classRows as $row): ?>
              <tr>
                <td><?= h($row['department_name'] ?? '-') ?></td>
                <td><?= h(trim($row['class_name'].' '.($row['section'] ?? ''))) ?></td>
                <td><span class="badge bg-<?= absencePercentClass((float)$row['absence_pct']) ?>"><?= h((string)$row['absence_pct']) ?>%</span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- د خطر په حالت کې Students -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-user-shield me-2"></i><?= t('students') ?> ≥ <?= (int)$riskPct ?>% <?= t('absence_pct') ?></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th><?= t('student') ?></th><th><?= t('class') ?></th><th><?= t('subject') ?></th><th>حاضري</th><th>غیري</th><th><?= t('absence_pct') ?></th></tr>
          </thead>
          <tbody>
            <?php if (!$riskRows): ?>
              <tr><td colspan="6" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
            <?php else: foreach ($riskRows as $row): $pct = (float)$row['absence_pct']; ?>
              <tr>
                <td><?= h($row['full_name']) ?><div class="small text-muted"><?= h($row['student_id']) ?></div></td>
                <td><?= h(trim($row['class_name'].' '.($row['section'] ?? ''))) ?></td>
                <td><?= h($row['subject_name']) ?></td>
                <td><?= (int)($row['present_marks'] ?? 0) ?></td>
                <td><?= (int)$row['absent_marks'] ?></td>
                <td><span class="badge bg-<?= absencePercentClass($pct) ?>"><?= h((string)$row['absence_pct']) ?>%</span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
