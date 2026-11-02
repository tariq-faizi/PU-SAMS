<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
redirectLegacyDeanManagerRoute();
requireRole([ROLE_ATT_MGR, ROLE_ADMIN]);

$db        = getDB();
$roleId    = (int)($_SESSION['role_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$pageTitle = t('attendance');

$sessionId = (int)($_GET['session_id'] ?? 0);
$fromDate  = $_GET['from'] ?? date('Y-m-d', strtotime('-60 days'));
$toDate    = $_GET['to']   ?? date('Y-m-d');

/* ── POST: save attendance ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sessionId > 0) {
    verifyCsrf();

    /* Lock check: no future sessions */
    $sessChk = $db->prepare("SELECT session_date, start_time, status FROM attendance_sessions WHERE id=? LIMIT 1");
    $sessChk->execute([$sessionId]);
    $sessRow = $sessChk->fetch();
    if ($sessRow) {
        $today = date('Y-m-d');
        if ($sessRow['session_date'] > $today) {
            flashSet('danger', t('ui_pending_lock_msg'));
            redirect(BASE_URL.'/manager/attendance_edit.php?session_id='.$sessionId);
        }
        if ($sessRow['session_date'] === $today) {
            $startTs = strtotime($sessRow['session_date'].' '.$sessRow['start_time']);
            if ($startTs !== false && time() < $startTs) {
                flashSet('danger', t('ui_pending_lock_msg').' ('.substr((string)$sessRow['start_time'],0,5).')');
                redirect(BASE_URL.'/manager/attendance_edit.php?session_id='.$sessionId);
            }
        }
    }

    $statuses = $_POST['status'] ?? [];
    $reason   = trim($_POST['edit_reason'] ?? '');

    $reasonOptional = in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ADMIN], true);
    if (!$reasonOptional && empty($reason)) {
        flashSet('warning', t('ui_edit_reason_required'));
        redirect(BASE_URL.'/manager/attendance_edit.php?session_id='.$sessionId);
    }

    $map = [];
    foreach ($statuses as $sid => $st) { $map[(int)$sid] = (string)$st; }

    if (function_exists('saveTriStateAttendance')) {
        $res = saveTriStateAttendance($sessionId, $map, $userId, 'manager');
        $msg = '✅ '.t('ui_save_changes').' ('.(int)$res['saved'].')';
    } else {
        $compact = [];
        foreach ($map as $k => $v) { if ($v !== '') $compact[$k] = normalizeAttStatus($v); }
        $res = saveBulkAttendanceUnderManager($sessionId, $compact, $userId);
        $msg = '✅ '.t('ui_save_changes').' ('.(int)($res['saved'] ?? 0).')';
    }

    auditLog('attendance_edit','attendance',$sessionId,
             "Manager edit (reason: {$reason}); saved=".(int)($res['saved'] ?? 0));
    flashSet('success', $msg);
    if (function_exists('cacheBustDashboards')) cacheBustDashboards();
    redirect(BASE_URL.'/manager/attendance_edit.php?session_id='.$sessionId);
}

/* ── GET: load sessions + records ── */
$where  = "WHERE s.session_date BETWEEN ? AND ?";
$params = [$fromDate, $toDate];
if ($roleId !== ROLE_ADMIN) { $where .= " AND t.faculty_id = ?"; $params[] = $facultyId; }

$sessionsStmt = $db->prepare(
    "SELECT s.id, s.session_date, s.start_time, s.end_time, s.slot_label,
            c.name AS class_name, c.section, sub.name AS subject_name,
            u.full_name AS teacher_name, t.faculty_id
     FROM attendance_sessions s
     JOIN timetable t   ON t.id  = s.timetable_id
     JOIN classes   c   ON c.id  = s.class_id
     JOIN subjects  sub ON sub.id = s.subject_id
     JOIN users     u   ON u.id  = s.teacher_id
     $where
     ORDER BY s.session_date DESC, s.start_time DESC LIMIT 400"
);
$sessionsStmt->execute($params);
$availableSessions = $sessionsStmt->fetchAll();

$session = null; $records = [];
if ($sessionId > 0) {
    $sStmt = $db->prepare(
        "SELECT s.*, c.name AS class_name, c.section, sub.name AS subject_name,
                u.full_name AS teacher_name, t.faculty_id, COALESCE(r.name, s.room) AS room_name
         FROM attendance_sessions s
         JOIN timetable t   ON t.id  = s.timetable_id
         JOIN classes   c   ON c.id  = s.class_id
         JOIN subjects  sub ON sub.id = s.subject_id
         JOIN users     u   ON u.id  = s.teacher_id
         LEFT JOIN rooms r  ON r.id  = s.room_id
         WHERE s.id = ? LIMIT 1"
    );
    $sStmt->execute([$sessionId]);
    $session = $sStmt->fetch();

    if ($session && $roleId !== ROLE_ADMIN && (int)$session['faculty_id'] !== $facultyId) {
        flashSet('danger', 'Access denied.');
        redirect(BASE_URL.'/manager/attendance_edit.php');
    }

    if ($session) {
        $rStmt = $db->prepare(
            "SELECT u.id AS student_id, u.full_name, u.username, u.student_id AS roll, u.photo,
                    ar.status, ar.marked_at, ar.mark_method
             FROM users u
             LEFT JOIN attendance_records ar ON ar.session_id = ? AND ar.student_id = u.id
             WHERE u.role_id = ".ROLE_STUDENT." AND u.class_id = ? AND u.status = 'active'
               AND u.deleted_at IS NULL
             ORDER BY u.full_name"
        );
        $rStmt->execute([$sessionId, $session['class_id']]);
        $records = $rStmt->fetchAll();
    }
}

$present = count(array_filter($records, fn($r) => normalizeAttStatus((string)($r['status'] ?? '')) === 'present'));
$absent  = count(array_filter($records, fn($r) => normalizeAttStatus((string)($r['status'] ?? '')) === 'absent'));
$total   = count($records);
$pct     = $total ? round($present / $total * 100, 1) : 0;

/* Pending lock? */
$isPending     = false;
$isFutureDate  = false;
if ($session) {
    $today2 = date('Y-m-d');
    if ($session['session_date'] > $today2) {
        $isPending = $isFutureDate = true;
    } elseif ($session['session_date'] === $today2) {
        $st2 = strtotime($session['session_date'].' '.$session['start_time']);
        if ($st2 !== false && time() < $st2) $isPending = true;
    }
}

require_once __DIR__.'/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h5 class="mb-1"><i class="fas fa-pen-to-square me-2 text-warning"></i><?= t('ui_attendance_entry_for') ?></h5>
  <a href="<?= BASE_URL ?>/manager/attendance.php" class="btn btn-sm btn-outline-primary">
    <i class="fas fa-clock me-1"></i><?= t('attendance') ?>
  </a>
</div>

<!-- Date + session filter -->
<form method="GET" class="card mb-3"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('from') ?></label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= h($fromDate) ?>"/>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1"><?= t('to') ?></label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?= h($toDate) ?>"/>
    </div>
    <div class="col-md-6">
      <label class="form-label small mb-1"><?= t('ui_select_session') ?></label>
      <select name="session_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="0">— <?= t('select') ?> —</option>
        <?php foreach ($availableSessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $sessionId === (int)$s['id'] ? 'selected' : '' ?>>
            <?= h($s['session_date']) ?> · <?= h(substr($s['start_time'],0,5)) ?>–<?= h(substr($s['end_time'],0,5)) ?> ·
            <?= h($s['class_name'].' '.($s['section'] ?? '')) ?> · <?= h($s['subject_name']) ?> · <?= h($s['teacher_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-sm btn-primary w-100"><?= t('apply') ?></button>
    </div>
  </div>
</div></form>

<?php if ($sessionId > 0 && !$session): ?>
  <div class="alert alert-warning"><?= t('no_data_found') ?></div>
<?php elseif ($sessionId === 0): ?>
  <div class="pusams-empty-state"><i class="fas fa-calendar-check me-2"></i><?= t('ui_select_session') ?></div>
<?php else: ?>

  <!-- Session summary card -->
  <div class="card mb-3 border-0 shadow-sm">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3"
         style="background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border-radius:14px">
      <div>
        <h5 class="mb-1 text-dark">
          <i class="fas fa-pen-to-square me-2 text-warning"></i>
          <?= h($session['subject_name']) ?> — <?= h($session['class_name'].' '.($session['section'] ?? '')) ?>
        </h5>
        <div class="small text-dark">
          <i class="far fa-calendar me-1"></i><?= h($session['session_date']) ?> ·
          <i class="far fa-clock ms-2 me-1"></i><?= h(substr($session['start_time'],0,5)) ?>–<?= h(substr($session['end_time'],0,5)) ?> ·
          <i class="fas fa-chalkboard-teacher ms-2 me-1"></i><?= h($session['teacher_name']) ?> ·
          <i class="fas fa-door-open ms-2 me-1"></i><?= h($session['room_name'] ?? '—') ?>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <div class="badge bg-success p-2">✅ <?= t('ui_att_present') ?>: <?= $present ?></div>
        <div class="badge bg-danger  p-2">❌ <?= t('ui_att_absent') ?>:  <?= $absent ?></div>
        <div class="badge bg-<?= attendancePercentClass($pct) ?> p-2">
          <i class="fas fa-percent me-1"></i><?= t('ui_attendance_pct') ?>: <?= $pct ?>%
        </div>
      </div>
    </div>
  </div>

  <?php if ($isPending): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3"
         style="border-left:6px solid #f59e0b;border-radius:14px">
      <i class="fas fa-hourglass-half fa-lg"></i>
      <div>
        <strong><?= t('ui_pending_sessions') ?></strong>
        — <?= t('ui_pending_lock_msg') ?>
        <?php if (!$isFutureDate): ?>
          <span class="badge bg-warning text-dark ms-2">
            <i class="far fa-clock me-1"></i><?= h(substr((string)$session['start_time'],0,5)) ?>
          </span>
        <?php else: ?>
          <span class="badge bg-warning text-dark ms-2">
            <i class="far fa-calendar me-1"></i><?= h((string)$session['session_date']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════
       EDIT MODE TOGGLE — single button at the top
       Default: VIEW mode (cells are read-only badges)
       After clicking Edit: cells become clickable
  ═══════════════════════════════════════════════════════ -->

  <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <div class="small text-muted">
      <?= count($records) ?> student<?= count($records) !== 1 ? 's' : '' ?>
    </div>
    <?php if (!$isPending): ?>
      <button type="button" id="btnEnableEdit" class="btn btn-sm btn-warning">
        <i class="fas fa-pen me-1"></i>Edit Attendance
      </button>
      <button type="button" id="btnCancelEdit" class="btn btn-sm btn-secondary d-none">
        <i class="fas fa-times me-1"></i>Cancel
      </button>
    <?php endif; ?>
  </div>

  <form method="POST" class="card" id="mgrAttForm">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>"/>

    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead>
          <tr>
            <th style="width:46px">#</th>
            <th><?= t('student') ?></th>
            <th>ID</th>
            <th class="text-center"><?= t('ui_status') ?></th>
            <th><?= t('ui_time') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$records): ?>
            <tr><td colspan="5" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
          <?php foreach ($records as $i => $r):
              $rawSt = $r['status'] ?? null;
              $cur   = ($rawSt === null || trim((string)$rawSt) === '') ? 'present' : normalizeAttStatus((string)$rawSt);
          ?>
            <tr data-row-student="<?= (int)$r['student_id'] ?>">
              <td class="text-muted"><?= $i + 1 ?></td>
              <td>
                <div class="fw-semibold"><?= h($r['full_name']) ?></div>
                <div class="small text-muted">@<?= h($r['username']) ?></div>
              </td>
              <td><span class="badge bg-light text-dark"><?= h($r['roll'] ?? '—') ?></span></td>
              <td class="text-center">
                <?php if (!$isPending): ?>
                  <!-- VIEW badge (shown when not in edit mode) -->
                  <span class="att-view-badge badge bg-<?= $cur === 'present' ? 'success' : 'danger' ?> fs-6"
                        data-state="<?= h($cur) ?>">
                    <?= $cur === 'present' ? '✅' : '❌' ?>
                  </span>
                  <!-- Editable cell (hidden until edit mode activated) -->
                  <div class="att-edit-cell d-none">
                    <?= renderAttCell((int)$r['student_id'], (int)$session['id'], $cur, true, 'status') ?>
                  </div>
                  <script>
                  (function(){
                    var hid=document.getElementById('atthid_<?=(int)$r['student_id']?>_<?=(int)$session['id']?>');
                    if(hid){ hid.name='status[<?=(int)$r['student_id']?>]'; }
                  })();
                  </script>
                <?php else: ?>
                  <!-- Pending / locked – show read-only badge only -->
                  <span class="badge bg-<?= $cur === 'present' ? 'success' : 'danger' ?> fs-6">
                    <?= $cur === 'present' ? '✅' : '❌' ?>
                  </span>
                <?php endif; ?>
              </td>
              <td><small class="text-muted"><?= h($r['marked_at'] ?? '—') ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Save section – hidden until edit mode -->
    <?php if (!$isPending): ?>
    <div class="card-body border-top d-none" id="saveSection">
      <?php $reasonOptional = in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ADMIN], true); ?>
      <label class="form-label fw-semibold mb-1">
        <i class="fas fa-comment-dots me-1 text-warning"></i><?= t('ui_edit_reason') ?>
        <?php if (!$reasonOptional): ?>
          <span class="text-danger">*</span>
        <?php else: ?>
          <span class="text-muted small">(<?= t('optional') ?>)</span>
        <?php endif; ?>
      </label>
      <textarea name="edit_reason" rows="2" class="form-control"
                <?= $reasonOptional ? '' : 'required' ?>
                id="editReasonField"></textarea>
      <div class="d-flex justify-content-between flex-wrap gap-2 mt-3">
        <div></div>
        <div class="d-flex gap-2">
          <button class="btn btn-warning" type="submit">
            <i class="fas fa-save me-1"></i><?= t('ui_save_changes') ?>
          </button>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="card-body border-top">
      <button type="button" class="btn btn-warning" disabled>
        <i class="fas fa-lock me-1"></i><?= t('locked') ?>
      </button>
    </div>
    <?php endif; ?>
  </form>

  <?= function_exists('renderTristateScript') ? renderTristateScript() : '' ?>

  <?php if (!$isPending): ?>
  <script>
  (function(){
    var btnEnable  = document.getElementById('btnEnableEdit');
    var btnCancel  = document.getElementById('btnCancelEdit');
    var saveSection= document.getElementById('saveSection');
    var form       = document.getElementById('mgrAttForm');
    var editMode   = false;

    function enterEditMode() {
      editMode = true;
      // Show edit cells, hide view badges
      document.querySelectorAll('.att-view-badge').forEach(function(el){ el.classList.add('d-none'); });
      document.querySelectorAll('.att-edit-cell').forEach(function(el){ el.classList.remove('d-none'); });
      // Show save section
      if (saveSection) saveSection.classList.remove('d-none');
      // Swap buttons
      if (btnEnable) btnEnable.classList.add('d-none');
      if (btnCancel) btnCancel.classList.remove('d-none');
    }

    function exitEditMode() {
      editMode = false;
      document.querySelectorAll('.att-view-badge').forEach(function(el){ el.classList.remove('d-none'); });
      document.querySelectorAll('.att-edit-cell').forEach(function(el){ el.classList.add('d-none'); });
      if (saveSection) saveSection.classList.add('d-none');
      if (btnEnable) btnEnable.classList.remove('d-none');
      if (btnCancel) btnCancel.classList.add('d-none');
    }

    if (btnEnable) btnEnable.addEventListener('click', enterEditMode);
    if (btnCancel) btnCancel.addEventListener('click', exitEditMode);

    // Prevent accidental form submission when not in edit mode
    if (form) {
      form.addEventListener('submit', function(e){
        if (!editMode) { e.preventDefault(); return; }
        var reasonRequired = <?= (!$reasonOptional) ? 'true' : 'false' ?>;
        if (!reasonRequired) return;
        var ta = document.getElementById('editReasonField');
        if (ta && ta.value.trim() === '') {
          e.preventDefault();
          ta.classList.add('is-invalid');
          ta.focus();
          try { ta.scrollIntoView({ behavior:'smooth', block:'center' }); } catch(ex){}
        }
      });
    }

    var reasonField = document.getElementById('editReasonField');
    if (reasonField) {
      reasonField.addEventListener('input', function(){
        if (this.value.trim() !== '') this.classList.remove('is-invalid');
      });
    }
  })();
  </script>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
