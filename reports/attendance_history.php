<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();

$roleId = (int)($_SESSION['role_id'] ?? 0);
if ($roleId === ROLE_STUDENT) {
    flashSet('danger', '❌');
    redirect(BASE_URL.'/student/attendance.php');
}

$db = getDB();
$userId    = (int)($_SESSION['user_id'] ?? 0);
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$pageTitle = t('attendance_history');

$defaultFromDate = function_exists('getSemesterStartDate') ? getSemesterStartDate() : '';
if ($defaultFromDate === '') {
    $defaultFromDate = date('Y-m-d', strtotime('-120 days'));
}
$fromDate = $_GET['from'] ?? $defaultFromDate;
$toDate   = $_GET['to']   ?? date('Y-m-d');

if (function_exists('syncSessionsForDate'))      syncSessionsForDate(date('Y-m-d'));
if (function_exists('markMissedSessionsForDate')) markMissedSessionsForDate(date('Y-m-d'));
$classId    = (int)($_GET['class_id'] ?? 0);
$subjectId  = (int)($_GET['subject_id'] ?? 0);
$teacherId  = (int)($_GET['teacher_id'] ?? 0);
$status     = $_GET['status'] ?? '';

$where  = ["s.session_date BETWEEN ? AND ?"];
$params = [$fromDate, $toDate];

if ($roleId === ROLE_TEACHER) {
    $where[] = "s.teacher_id=?"; $params[] = $userId;
} elseif (in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ATT_MGR], true) && $facultyId) {
    $where[] = "t.faculty_id=?"; $params[] = $facultyId;
}

if ($classId)   { $where[] = "s.class_id=?";   $params[] = $classId; }
if ($subjectId) { $where[] = "s.subject_id=?"; $params[] = $subjectId; }
if ($teacherId && $roleId !== ROLE_TEACHER) { $where[] = "s.teacher_id=?"; $params[] = $teacherId; }

if ($status !== '') {
    if (function_exists('applyEffectiveStateFilter')) {
        applyEffectiveStateFilter($where, $params, 's.status', 's.start_time', 's.end_time', 's.session_date', $status);
    } else {
        $where[] = "s.status=?"; $params[] = $status;
    }
}

$whereSql = 'WHERE '.implode(' AND ', $where);

$rows = $db->prepare("SELECT s.id, s.session_date, s.start_time, s.end_time, s.slot_label, s.status,
                             s.timetable_id,
                             c.name AS class_name, c.section,
                             sub.name AS subject_name, sub.code AS subject_code,
                             u.full_name AS teacher_name, u.id AS teacher_id,
                             t.faculty_id, f.name AS faculty_name,
                             COUNT(ar.id) AS total_marks,
                             SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS present_cnt,
                             SUM(CASE WHEN ar.status='absent'  THEN 1 ELSE 0 END) AS absent_cnt,
                             SUM(CASE WHEN ar.status='حالی'   THEN 1 ELSE 0 END) AS hold_cnt,
                             (SELECT mark_method FROM attendance_records WHERE session_id=s.id ORDER BY id LIMIT 1) AS first_method
                      FROM attendance_sessions s
                      JOIN timetable t ON t.id=s.timetable_id
                      JOIN classes c   ON c.id=s.class_id
                      JOIN subjects sub ON sub.id=s.subject_id
                      JOIN users u     ON u.id=s.teacher_id
                      LEFT JOIN faculties f ON f.id=t.faculty_id
                      LEFT JOIN attendance_records ar ON ar.session_id=s.id
                      $whereSql
                      GROUP BY s.id
                      ORDER BY s.session_date DESC, s.start_time DESC
                      LIMIT 500");
$rows->execute($params);
$sessions = $rows->fetchAll();

$facultyFilter = $roleId === ROLE_ADMIN ? null : ($facultyId ?: null);
$options = reportFilterOptions(['faculty_id' => $facultyFilter]);

$teachers = [];
if (in_array($roleId, [ROLE_ADMIN, ROLE_DEPT_HEAD, ROLE_ATT_MGR], true)) {
    $tSql = "SELECT id, full_name FROM users WHERE role_id=".ROLE_TEACHER." AND deleted_at IS NULL AND status='active'";
    $tParams = [];
    if ($facultyFilter) { $tSql .= " AND faculty_id=?"; $tParams[] = $facultyFilter; }
    $tSql .= " ORDER BY full_name";
    $tStmt = $db->prepare($tSql); $tStmt->execute($tParams);
    $teachers = $tStmt->fetchAll();
}

require_once __DIR__.'/../includes/header.php';

/* FIX #1 (v23.1) — د استاد د تاریخچه deep-link دقیق چې راست هغه سیشن پاڼه
   پرانیسي (نه د عمومي خاضرۍ لیست). داسې جوړوو:
     teacher/sessions.php?view=<timetable_id>&date=<session_date>
   چې په تېرو ۷ Dayو پورې ایډیټ ترېسره کیږي (د sessionEditableByTeacher()
   له لارې). د manager/admin URL بدلون نه دی پې دې کې پاتې دی. */
function v14EditUrl(array $session, int $roleId): string {
    if ($roleId === ROLE_TEACHER) {
        $tt   = (int)($session['timetable_id'] ?? 0);
        $date = (string)($session['session_date'] ?? '');
        if ($tt > 0 && $date !== '') {
            return BASE_URL.'/teacher/sessions.php?view='.$tt.'&date='.urlencode($date);
        }
        // fallback — پخوانۍ چلند د session_id پر اساس (د teacher/sessions.php
        // دننۍ resolver دا دواړه پارامترونه ددی بیرته جوړوي).
        return BASE_URL.'/teacher/sessions.php?session_id='.(int)($session['id'] ?? 0);
    }
    return BASE_URL.'/manager/attendance_edit.php?session_id='.(int)($session['id'] ?? 0);
}

function v14MethodBadge(string $method): array {
    
    $m = strtolower(trim($method));
    if ($m === '' || $m === 'system') return ['system','system'];
    if ($m === 'manager')             return ['manager','manager'];
    if ($m === 'admin')               return ['admin','admin'];
    if ($m === 'dean')                return ['dean','dean'];
    return ['teacher','teacher'];
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="mb-1"><i class="fas fa-clock-rotate-left me-2 text-primary"></i><?= t('attendance_history') ?></h5>
    <?php if (function_exists('getSemesterStartDate')):
        $semStart = getSemesterStartDate();
        if ($semStart !== ''): ?>
      <div class="small text-muted"><i class="fas fa-calendar-alt me-1"></i><?= h(t('semester')) ?>: <?= h($semStart) ?></div>
    <?php endif; endif; ?>
  </div>
</div>

<form method="GET" class="card mb-3"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('from') ?></label>
      <input type="date" name="from" value="<?= h($fromDate) ?>" class="form-control form-control-sm"/>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('to') ?></label>
      <input type="date" name="to" value="<?= h($toDate) ?>" class="form-control form-control-sm"/>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('class') ?></label>
      <select name="class_id" class="form-select form-select-sm">
        <option value="">— <?= t('all') ?> —</option>
        <?php foreach ($options['classes'] as $cl): ?>
          <option value="<?= (int)$cl['id'] ?>" <?= $classId === (int)$cl['id'] ? 'selected' : '' ?>><?= h(trim($cl['name'].' '.($cl['section'] ?? ''))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('subject') ?></label>
      <select name="subject_id" class="form-select form-select-sm">
        <option value="">— <?= t('all') ?> —</option>
        <?php foreach ($options['subjects'] as $sj): ?>
          <option value="<?= (int)$sj['id'] ?>" <?= $subjectId === (int)$sj['id'] ? 'selected' : '' ?>><?= h($sj['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($teachers): ?>
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('teacher') ?></label>
      <select name="teacher_id" class="form-select form-select-sm">
        <option value="">— <?= t('all') ?> —</option>
        <?php foreach ($teachers as $te): ?>
          <option value="<?= (int)$te['id'] ?>" <?= $teacherId === (int)$te['id'] ? 'selected' : '' ?>><?= h($te['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-md-1">
      <label class="form-label small mb-1"><?= t('status') ?></label>
      <select name="status" class="form-select form-select-sm">
        <option value=""><?= t('all') ?></option>
        <?php
        
        $stateOptions = [
            'pending' => function_exists('t') ? t('status_pending') : 'Pending',
            'open'    => function_exists('t') ? t('status_open')    : 'Open',
            'closed'  => function_exists('t') ? t('status_closed')  : 'Closed',
            'missed'  => function_exists('t') ? t('status_missed')  : 'Missed',
        ];
        foreach ($stateOptions as $stKey => $stLabel): ?>
          <option value="<?= h($stKey) ?>" <?= $status === $stKey ? 'selected' : '' ?>><?= h($stLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1">
      <button class="btn btn-sm btn-primary w-100 mt-3"><i class="fas fa-filter"></i></button>
    </div>
  </div>
</div></form>

<div class="v14-past-sessions-card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list-check me-2"></i><?= count($sessions) ?> · <?= t('attendance_history') ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th><?= t('ui_date') ?></th>
          <th><?= t('ui_time') ?></th>
          <th><?= t('class') ?></th>
          <th><?= t('subject') ?></th>
          <th><?= t('teacher') ?></th>
          <th class="text-center" style="width:90px;" title="حاضر">✅</th>
          <th class="text-center" style="width:90px;" title="غیری حاضر">❌</th>
          
          <th><?= t('ui_status') ?></th>
          <th class="text-end"><?= t('actions') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$sessions): ?>
        <tr><td colspan="9" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($sessions as $s):
        $total = (int)$s['total_marks'];
        $p     = (int)$s['present_cnt'];
        $a     = (int)$s['absent_cnt'];
        
        [$mlabel,$mcls] = v14MethodBadge((string)($s['first_method'] ?? ''));

        $effState = function_exists('effectiveSessionState')
            ? effectiveSessionState($s)
            : (function_exists('normalizeSessionStatus') ? normalizeSessionStatus($s['status']) : $s['status']);
        $effLabel = function_exists('effectiveSessionStateLabel') ? effectiveSessionStateLabel($effState) : ucfirst($effState);
        $effBadgeClass = [
            'pending' => 'warning text-dark',
            'open'    => 'info text-dark',
            'closed'  => 'success',
            'missed'  => 'danger',
        ][$effState] ?? attendanceStatusClass($s['status']);
        $effIcon = [
            'pending' => 'fa-hourglass-half',
            'open'    => 'fa-circle-play',
            'closed'  => 'fa-check-double',
            'missed'  => 'fa-circle-xmark',
        ][$effState] ?? 'fa-clock';
      ?>
        <tr>
          <td><?= h($s['session_date']) ?></td>
          <td><small><?= h(substr($s['start_time'],0,5)) ?>–<?= h(substr($s['end_time'],0,5)) ?></small></td>
          <td>
            <div class="fw-semibold"><?= h(trim($s['class_name'].' '.($s['section'] ?? ''))) ?></div>
            <?php if ($roleId === ROLE_ADMIN): ?><div class="small text-muted"><?= h($s['faculty_name'] ?? '') ?></div><?php endif; ?>
          </td>
          <td>
            <div class="fw-semibold"><?= h($s['subject_name']) ?></div>
            <div class="small text-muted"><code><?= h($s['subject_code'] ?? '—') ?></code></div>
          </td>
          <td>
            <?= h($s['teacher_name']) ?>
            <div><span class="role-badge <?= h($mcls) ?>"><?= h($mlabel) ?></span></div>
          </td>
          <td class="text-center"><span class="badge bg-success-subtle text-success"><?= $p ?></span></td>
          <td class="text-center"><span class="badge bg-danger-subtle text-danger"><?= $a ?></span></td>
          <td><span class="badge bg-<?= h($effBadgeClass) ?>"><i class="fas <?= h($effIcon) ?> me-1"></i><?= h($effLabel) ?></span></td>
          <td class="text-end">
            <?php
              
              $__canEditHere = ($roleId !== ROLE_DEPT_HEAD) && ($effState !== 'pending');
              if ($roleId === ROLE_STUDENT) { $__canEditHere = false; }
            ?>
            <?php if ($__canEditHere): ?>
              <a href="<?= h(v14EditUrl($s, $roleId)) ?>" class="btn btn-sm btn-outline-warning"
                 title="<?= h(t('edit')) ?>"><i class="fas fa-pen-to-square"></i></a>
            <?php else: ?>
              <?php
                
                $__viewBase = '/manager/session_detail.php';
                if ($roleId === ROLE_DEPT_HEAD) { $__viewBase = '/dean/session_detail.php'; }
                elseif ($roleId === ROLE_ADMIN) { $__viewBase = '/admin/session_detail.php'; }
                $__viewUrl = BASE_URL . $__viewBase . '?id=' . (int)$s['id'];
              ?>
              <a href="<?= h($__viewUrl) ?>" class="btn btn-sm btn-outline-info"
                 title="<?= h(function_exists('t') ? t('view', [], 'View') : 'View') ?>"><i class="fas fa-eye"></i></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
