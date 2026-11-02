<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin(); requireRole([ROLE_TEACHER]);
$db = getDB();
$uid = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = t('attendance_entry');

/* ─────────────────────────────────────────────────────────────────────────────
 *  FIX #1 (v23.1) — د attendance_history دقیق سیشن لپاره deep-link ملاتړ.
 *  کله چې استاد د reports/attendance_history.php څخه «edit» کلیک کوي، URL
 *  داسې راځي: teacher/sessions.php?session_id=<attendance_sessions.id>.
 *  دلته دا session_id د timetable_id + session_date ته بدلوو (که د همدې استاد
 *  وي) او بیا ورته دقیق view URL ته redirect کوو ترڅو هغه ځانګړې خاضري
 *  پاڼه پرانیستل شي، نه عمومي لیست.
 *
 *  مهمه: کوم نور حالتونه (د عمومي لیست لیدل، تېر `view=` پارامتر) نه بدلیږي.
 * ─────────────────────────────────────────────────────────────────────────── */
$incomingSessionId = (int)($_GET['session_id'] ?? 0);
if ($incomingSessionId > 0 && !isset($_GET['view'])) {
    $resolveStmt = $db->prepare("SELECT s.timetable_id, s.session_date
                                 FROM attendance_sessions s
                                 JOIN timetable t ON t.id = s.timetable_id
                                 WHERE s.id = ?
                                   AND t.teacher_id = ?
                                   AND t.status = 'approved'
                                 LIMIT 1");
    $resolveStmt->execute([$incomingSessionId, $uid]);
    $resolved = $resolveStmt->fetch();
    if ($resolved) {
        $target = BASE_URL.'/teacher/sessions.php?view='.(int)$resolved['timetable_id']
                .'&date='.urlencode((string)$resolved['session_date']);
        redirect($target);
    }
    // که سیشن د دې استاد نه وي یا موجود نه وي → په عمومي لیست کې پرېږدو او
    // یوه روښانه پیغام ښیې.
    flashSet('warning', 'Session not found or does not belong to you.');
}

$date = $_GET['date'] ?? date('Y-m-d');
$viewId = (int)($_GET['view'] ?? 0);

function csrfFieldTeacher(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

syncSessionsForDate($date, $uid, $viewId ?: 0);
markMissedSessionsForDate($date);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'save_group') {
        $timetableId = (int)($_POST['timetable_id'] ?? 0);
        $sessionDate = $_POST['session_date'] ?? date('Y-m-d');
        $ttCheck = $db->prepare("SELECT id, day_of_week, start_time FROM timetable WHERE id=? AND teacher_id=? AND status='approved'");
        $ttCheck->execute([$timetableId, $uid]);
        $ttRow = $ttCheck->fetch();
        if (!$ttRow) {
            flashSet('danger', 'Timetable entry not found.');
            redirect(BASE_URL.'/teacher/sessions.php');
        }
        if (!sessionEditableByTeacher($sessionDate)) {
            flashSet('danger', 'Attendance can only be edited within the 7-day teacher edit window.');
            redirect(BASE_URL.'/teacher/sessions.php?view='.$timetableId.'&date='.$sessionDate);
        }

        

        
        
        if ($sessionDate === date('Y-m-d')) {
            
            $slotsCheckStmt = $db->prepare("SELECT id, start_time, end_time, status FROM attendance_sessions WHERE timetable_id=? AND session_date=? ORDER BY credit_slot_no");
            $slotsCheckStmt->execute([$timetableId, $sessionDate]);
            $slotsCheck = $slotsCheckStmt->fetchAll();
            $hasStartedSlot = false;
            if (!empty($slotsCheck)) {
                foreach ($slotsCheck as $sc) {
                    $info = computeAttSessionLiveState($sc + ['session_date' => $sessionDate]);
                    if ($info['state'] !== 'pending') { $hasStartedSlot = true; break; }
                }
            } else {
                
                $now = time();
                $sessionStart = strtotime($sessionDate.' '.$ttRow['start_time']);
                if ($sessionStart !== false && $now >= $sessionStart) $hasStartedSlot = true;
            }
            if (!$hasStartedSlot) {
                flashSet('danger', 'Attendance is locked — class starts at ('.substr($ttRow['start_time'],0,5).') لا نه دی رارسېدلی. یوازې هغه سیشن چې status یې Open وي خاضري ثبت کېدای شي.');
                redirect(BASE_URL.'/teacher/sessions.php?view='.$timetableId.'&date='.$sessionDate);
            }
        }
        $attendanceMap = $_POST['attendance_status'] ?? [];

        /* ── QR-lock guard: refuse to save manual attendance on any slot that
           has already been claimed by QR. Historical rows are attendance_method='pending'
           and pass through untouched. This block only ADDS validation — the actual save
           call below is unchanged. ── */
        $lockedSlots = [];
        if (function_exists('claimAttendanceMethod')) {
            $slotChk = $db->prepare("SELECT id, credit_slot_no FROM attendance_sessions WHERE timetable_id=? AND session_date=? ORDER BY credit_slot_no");
            $slotChk->execute([$timetableId, $sessionDate]);
            foreach ($slotChk->fetchAll() as $sl) {
                $claim = claimAttendanceMethod((int)$sl['id'], 'manual', $db);
                if (!$claim['ok']) {
                    $lockedSlots[] = 'Credit '.(int)$sl['credit_slot_no'].': '.$claim['message'];
                }
            }
        }
        if (!empty($lockedSlots)) {
            flashSet('warning', implode(' | ', $lockedSlots));
            redirect(BASE_URL.'/teacher/sessions.php?view='.$timetableId.'&date='.$sessionDate);
        }

        $result = saveGroupedAttendance($timetableId, $sessionDate, $attendanceMap, $uid, 'manual');
        auditLog('teacher_group_attendance_save', 'attendance', $timetableId, 'Saved grouped credit attendance');
        flashSet('success', 'Attendance saved. Present: '.$result['present'].' | Absent: '.$result['absent']);
        if (function_exists('cacheBustDashboards')) cacheBustDashboards();
        redirect(BASE_URL.'/teacher/sessions.php?view='.$timetableId.'&date='.$sessionDate);
    }
    if ($act === 'mark_missed') {
        $timetableId = (int)($_POST['timetable_id'] ?? 0);
        $sessionDate = $_POST['session_date'] ?? date('Y-m-d');
        $reason = trim($_POST['missed_reason'] ?? '');
        $ttRow = $db->prepare("SELECT t.*, sub.name as sname, c.name as cname FROM timetable t JOIN subjects sub ON sub.id=t.subject_id JOIN classes c ON c.id=t.class_id WHERE t.id=? AND t.teacher_id=? AND t.status='approved'");
        $ttRow->execute([$timetableId,$uid]);
        $tt = $ttRow->fetch();
        if (!$tt) { flashSet('danger','Timetable not found'); redirect(BASE_URL.'/teacher/sessions.php'); }

        
        if ($sessionDate === date('Y-m-d')) {
            $now = time();
            $sessionStart = strtotime($sessionDate.' '.$tt['start_time']);
            if ($sessionStart !== false && $now < $sessionStart) {
                flashSet('danger','Class start time ('.substr($tt['start_time'],0,5).') has not yet been reached — marking as missed is blocked.');
                redirect(BASE_URL.'/teacher/sessions.php?view='.$timetableId.'&date='.$sessionDate);
            }
        }

        syncSessionsForDate($sessionDate, $uid, $timetableId);
        $sessions = $db->prepare("SELECT s.*, t.faculty_id, t.department_id, c.name as class_name, sub.name as subject_name FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id JOIN classes c ON c.id=s.class_id JOIN subjects sub ON sub.id=s.subject_id WHERE s.timetable_id=? AND s.session_date=? ORDER BY s.credit_slot_no");
        $sessions->execute([$timetableId,$sessionDate]);

        foreach ($sessions->fetchAll() as $session) {
            try {
                $db->beginTransaction();
                ensureMissedClassLedgerEntry($session, $uid, $reason);
                creditPresentForSessionStudents($session, $uid, 'teacher_manual_missed', 'Class marked as missed by teacher; students credited present.');
                $db->prepare("UPDATE attendance_sessions SET status='missed', is_missed=1, closed_by=?, closed_at=NOW() WHERE id=?")
                   ->execute([$uid, (int)$session['id']]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
        auditLog('teacher_marked_missed','missed_classes',$timetableId,"Missed class on $sessionDate, reason: $reason");
        if (function_exists('cacheBustDashboards')) cacheBustDashboards();
        flashSet('success','Missed-class recorded; students credited as present.');
        redirect(BASE_URL.'/teacher/sessions.php?view='.$timetableId.'&date='.$sessionDate);
    }
}

$viewRow = null; $sessionSlots = []; $roster = []; $canEdit = false;
if ($viewId > 0) {
    $tt = $db->prepare("SELECT t.*, c.name as class_name, c.section, sub.name as subject_name, sem.name as semester_name, COALESCE(r.name,t.room) as room_name FROM timetable t JOIN classes c ON c.id=t.class_id JOIN subjects sub ON sub.id=t.subject_id LEFT JOIN semesters sem ON sem.id=t.semester_id LEFT JOIN rooms r ON r.id=t.room_id WHERE t.id=? AND t.teacher_id=? AND t.status='approved'");
    $tt->execute([$viewId,$uid]);
    $viewRow = $tt->fetch();
    if ($viewRow) {
        syncSessionsForDate($date, $uid, $viewId);
        $slotsStmt = $db->prepare("SELECT * FROM attendance_sessions WHERE timetable_id=? AND session_date=? ORDER BY credit_slot_no");
        $slotsStmt->execute([$viewId,$date]);
        $sessionSlots = $slotsStmt->fetchAll();
        $rosterStmt = $db->prepare("SELECT u.id, u.full_name, u.student_id
                                   FROM users u
                                   WHERE u.role_id=? AND u.class_id=? AND u.status='active' AND u.deleted_at IS NULL
                                   ORDER BY u.full_name");
        $rosterStmt->execute([ROLE_STUDENT, $viewRow['class_id']]);
        $students = $rosterStmt->fetchAll();
        $existing = [];
        if ($sessionSlots) {
            $ids = array_column($sessionSlots, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $markStmt = $db->prepare("SELECT session_id, student_id, status FROM attendance_records WHERE session_id IN ($in)");
            $markStmt->execute($ids);
            foreach ($markStmt->fetchAll() as $mk) $existing[$mk['student_id']][$mk['session_id']] = $mk['status'];
        }
        foreach ($students as $student) {
            $student['marks'] = [];
            foreach ($sessionSlots as $slot) {

                
                $student['marks'][$slot['id']] = $existing[$student['id']][$slot['id']] ?? 'present';
            }
            
            $rec = studentSubjectAbsenceRecord((int)$student['id'], (int)$viewRow['class_id'], (int)$viewRow['subject_id']);
            $student['absence_pct'] = $rec['absence_pct'];
            $roster[] = $student;
        }
        // د ۷-Dayو پوره ې د ایډیټ کړکۍ: تر ۷ Dayو پورې استاد خپلې
        // تیرې خاضرۍ (د Closed/Missed په شمول) ایډیټ کولی شي.
        // له ۷ Dayو وروسته sessionEditableByTeacher() په خپله false بیرته کړي.
        $canEdit = sessionEditableByTeacher($date);

        $allSlotsPending = !empty($sessionSlots);
        $anySlotMissed   = false;
        $allSlotsClosed  = !empty($sessionSlots);
        foreach ($sessionSlots as $slot) {
            $info = computeAttSessionLiveState($slot + ['session_date' => $date]);
            if ($info['state'] !== 'pending') $allSlotsPending = false;
            if ($info['state'] !== 'closed' && $info['state'] !== 'missed') $allSlotsClosed = false;
            if ($info['state'] === 'missed') $anySlotMissed = true;
        }
        // pending سلاتونه (لا دقیق پیل وخت ته نه دي رارسېدلي) تل بلاک.
        if ($allSlotsPending) $canEdit = false;
        // پخوانۍ $allSlotsClosed lock لرې شو — تر ۷ Dayو پورې د closed/missed
        // سلاتونو د ایډیټ اجازه ورکول کیږي (sessionEditableByTeacher() د ۷ Dayو
        // حد په خپله پلي کوي).
    }
}

$listStmt = $db->prepare("SELECT t.*, c.name as class_name, c.section, sub.name as subject_name, COALESCE(r.name,t.room) as room_name, COUNT(s.id) as generated_slots, SUM(CASE WHEN s.status IN ('completed','closed') THEN 1 ELSE 0 END) as closed_slots, SUM(CASE WHEN s.status='missed' THEN 1 ELSE 0 END) as missed_slots, MIN(s.start_time) AS first_slot_start, MAX(s.end_time) AS last_slot_end, MIN(s.status) AS any_status FROM timetable t JOIN classes c ON c.id=t.class_id JOIN subjects sub ON sub.id=t.subject_id LEFT JOIN rooms r ON r.id=t.room_id LEFT JOIN attendance_sessions s ON s.timetable_id=t.id AND s.session_date=? WHERE t.teacher_id=? AND t.status='approved' AND t.day_of_week=? GROUP BY t.id ORDER BY t.start_time");
$listStmt->execute([$date, $uid, sessionWeekday($date)]);
$list = $listStmt->fetchAll();

$todaySessions = [];
foreach ($list as $row) {
    $probe = [
        'session_date' => $date,
        'start_time'   => $row['first_slot_start'] ?: $row['start_time'],
        'end_time'     => $row['last_slot_end']    ?: $row['end_time'],
        'status'       => ((int)($row['generated_slots'] ?? 0) > 0
                            && ((int)$row['generated_slots'] === (int)$row['closed_slots'] + (int)$row['missed_slots']))
                          ? ((int)$row['missed_slots'] > 0 && (int)$row['closed_slots'] === 0 ? 'missed' : 'completed')
                          : 'open',
    ];
    $info = computeAttSessionLiveState($probe);
    
    $effState = $info['state'] === 'live' ? 'open' : $info['state'];
    $todaySessions[] = ['row' => $row, 'info' => $info, 'state' => $effState];
}

$stateOrder = ['open' => 0, 'pending' => 1, 'closed' => 2, 'missed' => 3];
usort($todaySessions, function($a, $b) use ($stateOrder) {
    $oa = $stateOrder[$a['state']] ?? 4;
    $ob = $stateOrder[$b['state']] ?? 4;
    if ($oa !== $ob) return $oa <=> $ob;
    return strcmp((string)$a['row']['start_time'], (string)$b['row']['start_time']);
});

$todayStateCounts = ['open' => 0, 'pending' => 0, 'closed' => 0, 'missed' => 0];
foreach ($todaySessions as $entry) {
    if (isset($todayStateCounts[$entry['state']])) $todayStateCounts[$entry['state']]++;
}

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="mb-0"><i class="fas fa-list-check me-2"></i><?= t('attendance_entry') ?></h5>
  </div>
  <form method="GET" class="d-flex gap-2">
    <?php if($viewId): ?><input type="hidden" name="view" value="<?=$viewId?>"/><?php endif; ?>
    <input type="date" name="date" class="form-control form-control-sm" value="<?=h($date)?>"/>
    <button class="btn btn-sm btn-outline-secondary"><?=t('apply')?></button>
  </form>
</div>

<?php if($viewRow):
  $liveStats = ['present'=>0,'absent'=>0,'total'=>count($roster)];
  foreach ($roster as $st) {
    foreach (($st['marks'] ?? []) as $mk) {
      if ($mk === 'present') $liveStats['present']++;
      elseif ($mk === 'absent') $liveStats['absent']++;
    }
  }
  $primarySlot = $sessionSlots[0] ?? null;
  if ($primarySlot) {
    $bannerSession = array_merge($primarySlot, [
      'class_name'   => $viewRow['class_name'],
      'section'      => $viewRow['section'],
      'subject_name' => $viewRow['subject_name'],
      'teacher_name' => $_SESSION['full_name'] ?? '',
      'session_date' => $date,
      'start_time'   => $primarySlot['start_time'] ?? $viewRow['start_time'],
      'end_time'     => $primarySlot['end_time']   ?? $viewRow['end_time'],
    ]);
    echo renderLiveAttendanceBanner($bannerSession, [
      'present_count' => $liveStats['present'],
      'absent_count'  => $liveStats['absent'],
      'total_count'   => count($roster) * max(1, count($sessionSlots)),
      'show_stats'    => true,
    ]);
  }
?>
<div class="card mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between gap-3 align-items-center">
    <div>
      <h5 class="mb-1"><?=h($viewRow['subject_name'])?> — <?=h($viewRow['class_name'])?> <?=h($viewRow['section'])?></h5>
      <div class="small text-muted">
        <?= t('ui_date') ?>: <?=h($date)?> ·
        <?= t('ui_time') ?>: <?=h(substr($viewRow['start_time'],0,5))?>–<?=h(substr($viewRow['end_time'],0,5))?> ·
        <?= t('ui_room') ?>: <?=h($viewRow['room_name'])?> ·
        <?= t('ui_credits_today') ?>: <?=$viewRow['credit_units']?>
      </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <a href="<?=BASE_URL?>/teacher/sessions.php?date=<?=h($date)?>" class="btn btn-sm btn-secondary"><?=t('ui_back')?></a>
      <span class="badge bg-<?=$canEdit?'success':'warning text-dark'?> p-2"><?= $canEdit ? t('ui_editable_window') : t('ui_read_only') ?></span>
      <?php if($canEdit): ?>
      <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#missedModal"><i class="fas fa-circle-xmark me-1"></i><?= t('ui_mark_missed') ?></button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
/* ── QR Attendance switcher bar (added; safe if columns missing) ──
   Renders one row per credit-slot with:
     [ Manual ]  [ Generate QR ]
   Buttons auto-disable based on attendance_method and existing records.
   All state comes from the already-loaded $sessionSlots — no extra queries. */
if ($viewRow && !empty($sessionSlots) && $canEdit):
?>
<div class="card mb-3 qr-att-switcher">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><i class="fas fa-qrcode me-2 text-primary"></i><?= h(t('qr_live_panel')) ?></span>
    <span class="badge bg-light text-dark border"><?= h(t('scan_attendance')) ?></span>
  </div>
  <div class="card-body py-3">
    <div class="row g-2">
      <?php foreach ($sessionSlots as $idx => $slot):
          $method    = (string)($slot['attendance_method'] ?? 'pending');
          $hasRecs   = sessionHasAttendanceRecords((int)$slot['id']);
          $liveState = computeAttSessionLiveState($slot + ['session_date' => $date]);
          $stateStr  = $liveState['state'] ?? 'open';
          $isMissed  = $stateStr === 'missed';
          $isClosed  = $stateStr === 'closed';
          $isPending = $stateStr === 'pending';

          // Both buttons disabled when: session missed, already fully closed with records,
          // or the slot has not yet reached its live window.
          $manualDisabled = $hasRecs && $method === 'qr'  || $isMissed || $isPending;
          $qrDisabled     = $hasRecs && $method === 'manual' || $isMissed || $isPending;
          if ($method === 'manual' && !$hasRecs) $qrDisabled = true;
          if ($method === 'qr'     && !$hasRecs) $manualDisabled = true;
      ?>
      <div class="col-12 col-lg-6">
        <div class="border rounded p-2 d-flex flex-wrap align-items-center gap-2 h-100">
          <div class="flex-grow-1">
            <div class="fw-semibold">
              <?= h($slot['slot_label'] ?: ('Credit '.($idx+1))) ?>
              <span class="small text-muted ms-2"><?= h(substr((string)$slot['start_time'],0,5)) ?>–<?= h(substr((string)$slot['end_time'],0,5)) ?></span>
            </div>
            <div class="small">
              <?php if ($method === 'manual'): ?>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="fas fa-check me-1"></i><?= h(t('manual_attendance')) ?></span>
              <?php elseif ($method === 'qr'): ?>
                <span class="badge bg-info-subtle text-info border border-info-subtle"><i class="fas fa-qrcode me-1"></i>QR</span>
              <?php else: ?>
                <span class="badge bg-light text-muted border"><i class="fas fa-hourglass me-1"></i>pending</span>
              <?php endif; ?>
              <?php if ($isMissed): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1"><i class="fas fa-circle-xmark me-1"></i><?= h(t('status_missed')) ?></span><?php endif; ?>
            </div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="tooltip"
                  title="<?= $manualDisabled && $method==='qr' ? h(t('credit_locked_qr')) : h(t('manual_attendance')) ?>"
                  <?= $manualDisabled ? 'disabled' : '' ?>
                  onclick="document.getElementById('attForm').scrollIntoView({behavior:'smooth'})">
            <i class="fas fa-check me-1"></i><?= h(t('manual_attendance')) ?>
          </button>
          <button type="button" class="btn btn-sm btn-primary qr-open-btn"
                  data-session-id="<?= (int)$slot['id'] ?>"
                  data-slot-label="<?= h($slot['slot_label'] ?: ('Credit '.($idx+1))) ?>"
                  data-bs-toggle="tooltip"
                  title="<?= $qrDisabled && $method==='manual' ? h(t('credit_locked_manual')) : h(t('generate_qr')) ?>"
                  <?= $qrDisabled ? 'disabled' : '' ?>>
            <i class="fas fa-qrcode me-1"></i><?= h(t('generate_qr')) ?>
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Live QR modal (enhanced with fullscreen, print, and live scan feed) -->
<div class="modal fade" id="qrLiveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          <i class="fas fa-qrcode me-2"></i><?= h(t('qr_live_panel')) ?>
          <span class="ms-2 opacity-75 fs-6" id="qrLiveSlotLabel"></span>
        </h5>
        <div class="ms-auto d-flex gap-2">
          <button type="button" id="qrFullscreenBtn" class="btn btn-sm btn-light" title="<?= h(t('qr_fullscreen')) ?>">
            <i class="fas fa-expand"></i>
          </button>
          <button type="button" id="qrPrintBtn" class="btn btn-sm btn-light" title="<?= h(t('qr_print')) ?>">
            <i class="fas fa-print"></i>
          </button>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body" id="qrLiveModalBody">
        <p class="text-muted small mb-3"><?= h(t('qr_teacher_ready')) ?></p>
        <div class="row g-3">
          <!-- Left: QR canvas + backup code -->
          <div class="col-lg-6">
            <div class="text-center">
              <div id="qrCanvasBox" class="d-inline-block p-2 bg-white border rounded shadow-sm" style="min-width:260px;min-height:260px;"></div>
              <div class="mt-2 small">
                <span class="text-muted"><?= h(t('qr_refresh_countdown')) ?>:</span>
                <span id="qrCountdown" class="fw-bold">–</span> <?= h(t('qr_seconds_suffix')) ?>
                <div class="progress mt-1" style="height:4px;max-width:260px;margin:0 auto;">
                  <div id="qrCountdownBar" class="progress-bar bg-primary" role="progressbar" style="width:100%"></div>
                </div>
              </div>
            </div>
            <div class="mt-3 text-center">
              <div class="small text-muted"><?= h(t('qr_backup_code')) ?></div>
              <div id="qrBackupCode" class="display-5 fw-bold text-primary text-monospace" style="letter-spacing:.35rem">––––––</div>
              <button type="button" id="qrCopyCodeBtn" class="btn btn-sm btn-outline-secondary mt-1" title="<?= h(t('qr_copy_code')) ?>">
                <i class="fas fa-copy me-1"></i><?= h(t('qr_copy_code')) ?>
              </button>
            </div>
          </div>

          <!-- Right: stats + live scan feed -->
          <div class="col-lg-6">
            <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
              <span class="badge bg-success-subtle text-success border border-success-subtle fs-6">
                <i class="fas fa-user-check me-1"></i>
                <span id="qrMarkedCount">0</span> / <span id="qrTotalCount">0</span>
                <?= h(t('qr_students_marked')) ?>
              </span>
              <span class="badge bg-info-subtle text-info border border-info-subtle fs-6" id="qrProgressBadge">
                <i class="fas fa-chart-line me-1"></i>
                <span id="qrProgressPct">0</span>%
              </span>
            </div>
            <div class="card">
              <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span class="small fw-semibold"><i class="fas fa-clock-rotate-left me-1 text-primary"></i><?= h(t('qr_recent_scans')) ?></span>
                <span class="small text-muted" id="qrLastUpdated">–</span>
              </div>
              <div class="card-body p-0" style="max-height:320px;overflow-y:auto;">
                <ul class="list-group list-group-flush" id="qrScansList">
                  <li class="list-group-item text-center text-muted small py-4" id="qrScansEmpty">
                    <i class="fas fa-hourglass-half me-1"></i><?= h(t('qr_waiting_scans')) ?>
                  </li>
                </ul>
              </div>
            </div>
            <div class="d-flex gap-2 flex-wrap mt-3">
              <button type="button" id="qrCancelBtn" class="btn btn-outline-danger">
                <i class="fas fa-xmark me-1"></i><?= h(t('cancel_qr')) ?>
              </button>
              <button type="button" class="btn btn-secondary ms-auto" data-bs-dismiss="modal">
                <i class="fas fa-flag-checkered me-1"></i><?= h(t('close_finalize')) ?>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Hidden print sheet, populated dynamically before window.print() -->
<div id="qrPrintSheet" style="display:none"></div>
<?php endif; ?>

<form method="POST" id="attForm">
  <?php csrfFieldTeacher(); ?>
  <input type="hidden" name="act" value="save_group"/>
  <input type="hidden" name="timetable_id" value="<?=$viewId?>"/>
  <input type="hidden" name="session_date" value="<?=h($date)?>"/>

  <?php if(!$canEdit && !empty($sessionSlots)): ?>
  <?php
    if (!empty($allSlotsPending)) {
        $firstStart = substr((string)($sessionSlots[0]['start_time'] ?? ''), 0, 5);
        echo '<div class="alert alert-warning d-flex align-items-center gap-2 mb-3" role="alert">'
           . '<i class="fas fa-hourglass-half fa-lg"></i>'
           . '<div>'
           . '<strong>'.h(t('ui_pending_title')).'</strong>'
           . ' <span class="badge bg-warning text-dark ms-1"><i class="far fa-clock me-1"></i>'.h($firstStart).'</span>'
           . '</div></div>';
    } else {
        // د ۷ Dayو له حده بهر (تیر سیشن) — د ایډیټ اجازه نشته.
        echo '<div class="alert alert-secondary d-flex align-items-center gap-2 mb-3" role="alert">'
           . '<i class="fas fa-lock fa-lg"></i>'
           . '<div>'
           . '<strong>'.h(t('ui_read_only')).'</strong>'
           . ' <span class="small text-muted ms-1">د ایډیټ کړکۍ (۷ Dayې) تیره شوې ده — دا سیشن اوس یوازې د لیدلو لپاره دي.</span>'
           . ' <a href="'.BASE_URL.'/reports/attendance_history.php" class="btn btn-sm btn-outline-secondary ms-2"><i class="fas fa-eye me-1"></i>'.h(t('view')).'</a>'
           . '</div></div>';
    }
  ?>
  <?php endif; ?>
  <?php if($canEdit): ?>
  <?= function_exists('renderBulkBar') ? renderBulkBar(true) : '' ?>
  <?php endif; ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 ros-roster-table">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th><?=t('student')?></th>
            <th>ID</th>
            <th><?=t('absence_pct')?></th>
            <?php foreach($sessionSlots as $idx => $slot): ?>
              <th class="text-center">
                <div><?=h($slot['slot_label'] ?: 'Credit '.($idx+1))?></div>
                <div class="small text-muted mb-2"><?=h(substr($slot['start_time'],0,5))?></div>
                <?php if($canEdit): ?>
                  <button type="button" class="ros-att-cell ros-col-toggle" data-roster-col-toggle="<?=$slot['id']?>" data-state="present" title="✅" aria-label="✅"></button>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if(!$roster): ?>
            <tr><td colspan="<?=4 + count($sessionSlots)?>" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
          <?php foreach($roster as $i => $student): ?>
          <tr>
            <td class="text-muted"><?=$i+1?></td>
            <td>
              <div class="fw-semibold"><?=h($student['full_name'])?></div>
            </td>
            <td><span class="badge bg-light text-dark"><?=h($student['student_id'])?></span></td>
            <td><span class="badge bg-<?=absencePercentClass((float)$student['absence_pct'])?>"><?=(float)$student['absence_pct']?>%</span></td>
            <?php foreach($sessionSlots as $slot):
                
                $cur = $student['marks'][$slot['id']] ?? 'present';
                if ($cur !== 'present' && $cur !== 'absent') $cur = 'present';
            ?>
            <td class="text-center">
              <?= renderAttCell((int)$student['id'], (int)$slot['id'], (string)$cur, $canEdit) ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if($canEdit): ?>
    <div class="ros-save-bar">
      <div></div>
      <div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= t('ui_save_attendance') ?></button>
      </div>
    </div>
    <?php endif; ?>
  </div>
</form>

<!-- Missed-class modal -->
<div class="modal fade" id="missedModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content">
  <?php csrfFieldTeacher(); ?>
  <input type="hidden" name="act" value="mark_missed"/>
  <input type="hidden" name="timetable_id" value="<?=$viewId?>"/>
  <input type="hidden" name="session_date" value="<?=h($date)?>"/>
  <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-circle-xmark me-2"></i><?= t('ui_mark_missed') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <label class="form-label"><?= t('ui_missed_reason') ?></label>
    <textarea name="missed_reason" class="form-control" rows="3"></textarea>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?=t('cancel')?></button>
    <button type="submit" class="btn btn-danger"><?= t('ui_confirm_missed') ?></button>
  </div>
</form></div></div>

<?= renderTristateScript() ?>
<?= renderLiveAttendanceJS() ?>

<?php if ($viewRow && !empty($sessionSlots) && $canEdit): ?>
<!-- ── QR Attendance client script (self-contained; no external deps besides Bootstrap already loaded) ── -->
<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
(function(){
  const CSRF   = <?= json_encode(generateCsrfToken()) ?>;
  const CSRF_H = <?= json_encode(CSRF_TOKEN_NAME) ?>;
  const API_STATE = <?= json_encode(BASE_URL.'/api/session_qr_state.php') ?>;
  const API_SCANS = <?= json_encode(BASE_URL.'/api/session_qr_scans.php') ?>;
  const LABELS = {
    expired: <?= json_encode(t('qr_expired')) ?>,
    locked : <?= json_encode(t('credit_locked_manual')) ?>,
    notopen: <?= json_encode(t('session_not_open')) ?>,
    waiting: <?= json_encode(t('qr_waiting_scans')) ?>,
    copied : <?= json_encode(t('qr_copied')) ?>,
    justNow: <?= json_encode(t('qr_just_now')) ?>
  };

  let liveSessionId = 0;
  let refreshTimer  = null;
  let scansTimer    = null;
  let countdownTimer= null;
  let countdownMax  = 30;
  let qrRenderer    = null;
  let modalEl       = null;
  let bsModal       = null;
  let lastQrPayload = '';
  let lastBackup    = '';
  let lastSlotLbl   = '';

  function el(id){ return document.getElementById(id); }

  function renderQr(text){
    const box = el('qrCanvasBox');
    if (!box) return;
    box.innerHTML = '';
    try {
      qrRenderer = new QRCode(box, { text: text, width: 260, height: 260, correctLevel: QRCode.CorrectLevel.M });
    } catch(e){
      box.innerHTML = '<div class="text-danger small">QR render error: '+ e.message +'</div>';
    }
  }

  function startCountdown(seconds){
    if (countdownTimer) clearInterval(countdownTimer);
    countdownMax = Math.max(1, seconds);
    let remaining = seconds;
    const cd  = el('qrCountdown');
    const bar = el('qrCountdownBar');
    if (cd)  cd.textContent = remaining;
    if (bar) bar.style.width = '100%';
    countdownTimer = setInterval(function(){
      remaining--;
      if (cd)  cd.textContent = Math.max(remaining, 0);
      if (bar) bar.style.width = (Math.max(remaining, 0) / countdownMax * 100).toFixed(1) + '%';
      if (remaining <= 0) { clearInterval(countdownTimer); fetchState(true); }
    }, 1000);
  }

  function fetchState(forceRefresh){
    if (!liveSessionId) return;
    const fd = new FormData();
    fd.append(CSRF_H, CSRF);
    fd.append('session_id', liveSessionId);
    if (forceRefresh) fd.append('refresh', '1');

    fetch(API_STATE, { method:'POST', body: fd, credentials:'same-origin' })
      .then(r => r.json())
      .then(function(data){
        if (!data || !data.success) {
          const msg = (data && data.message) ? data.message : 'Failed to load QR.';
          const box = el('qrCanvasBox'); if (box) box.innerHTML = '<div class="text-danger small p-3">'+ msg +'</div>';
          return;
        }
        if (data.qr_url)      { renderQr(data.qr_url); lastQrPayload = data.qr_url; }
        if (data.backup_code) { el('qrBackupCode').textContent = data.backup_code; lastBackup = data.backup_code; }
        const marked = (typeof data.marked_count === 'number') ? data.marked_count : 0;
        const total  = (typeof data.total_students === 'number') ? data.total_students : 0;
        el('qrMarkedCount').textContent = marked;
        el('qrTotalCount').textContent  = total;
        const pct = total > 0 ? Math.round((marked/total)*100) : 0;
        const pctEl = el('qrProgressPct'); if (pctEl) pctEl.textContent = pct;
        const refresh = Math.max(5, parseInt(data.refresh_sec || 30, 10));
        startCountdown(refresh);
      })
      .catch(function(err){
        const box = el('qrCanvasBox'); if (box) box.innerHTML = '<div class="text-danger small p-3">'+ err.message +'</div>';
      });
  }

  function fmtRelTime(iso){
    if (!iso) return '';
    const t = new Date(iso.replace(' ', 'T')).getTime();
    const diff = Math.max(0, Math.round((Date.now() - t) / 1000));
    if (diff < 5)  return LABELS.justNow;
    if (diff < 60) return diff + 's';
    if (diff < 3600) return Math.floor(diff/60) + 'm';
    return Math.floor(diff/3600) + 'h';
  }

  function fetchScans(){
    if (!liveSessionId) return;
    const fd = new FormData();
    fd.append(CSRF_H, CSRF);
    fd.append('session_id', liveSessionId);
    fetch(API_SCANS, { method:'POST', body: fd, credentials:'same-origin' })
      .then(r => r.json())
      .then(function(data){
        if (!data || !data.success) return;
        const list  = el('qrScansList');
        const empty = el('qrScansEmpty');
        const upd   = el('qrLastUpdated');
        if (upd) upd.textContent = new Date().toLocaleTimeString();
        if (!list) return;
        // Rebuild list (small N; safe)
        if (!data.scans || data.scans.length === 0) {
          list.innerHTML = '<li class="list-group-item text-center text-muted small py-4">'
                         + '<i class="fas fa-hourglass-half me-1"></i>' + LABELS.waiting + '</li>';
          return;
        }
        const html = data.scans.map(function(s, idx){
          const initial = (s.full_name || '?').charAt(0).toUpperCase();
          const fresh   = idx === 0 ? ' qr-scan-fresh' : '';
          return '<li class="list-group-item d-flex align-items-center py-2' + fresh + '">'
               +   '<div class="qr-avatar me-2">' + initial + '</div>'
               +   '<div class="flex-grow-1">'
               +     '<div class="fw-semibold small">' + escapeHtml(s.full_name) + '</div>'
               +     '<div class="text-muted" style="font-size:.75rem">' + escapeHtml(s.student_code) + '</div>'
               +   '</div>'
               +   '<div class="small text-muted text-nowrap">' + escapeHtml(s.time_short) + '</div>'
               + '</li>';
        }).join('');
        list.innerHTML = html;
      })
      .catch(function(){ /* silent poll failure */ });
  }

  function escapeHtml(s){
    return String(s == null ? '' : s).replace(/[&<>\"']/g, function(c){
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '\"':'&quot;', "'":'&#39;' }[c];
    });
  }

  function openQrForSession(sessionId, label){
    liveSessionId = sessionId;
    lastSlotLbl   = label || '';
    el('qrLiveSlotLabel').textContent = label ? '— '+ label : '';
    el('qrBackupCode').textContent = '––––––';
    el('qrMarkedCount').textContent = '0';
    el('qrTotalCount').textContent  = '0';
    const pctEl = el('qrProgressPct'); if (pctEl) pctEl.textContent = '0';
    el('qrCanvasBox').innerHTML = '<div class="text-muted small py-5">…</div>';
    const list = el('qrScansList');
    if (list) list.innerHTML = '<li class="list-group-item text-center text-muted small py-4">'
                              + '<i class="fas fa-hourglass-half me-1"></i>' + LABELS.waiting + '</li>';
    if (!bsModal) {
      modalEl = el('qrLiveModal');
      bsModal = new bootstrap.Modal(modalEl);
      modalEl.addEventListener('hidden.bs.modal', function(){
        if (refreshTimer)   { clearInterval(refreshTimer);   refreshTimer = null; }
        if (scansTimer)     { clearInterval(scansTimer);     scansTimer   = null; }
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
        liveSessionId = 0;
      });
    }
    bsModal.show();
    fetchState(true);                          // initial
    fetchScans();                              // initial scans list
    // Piggy-back a live-count poll every 5s so teacher sees scans arrive.
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(function(){ fetchState(false); }, 5000);
    if (scansTimer) clearInterval(scansTimer);
    scansTimer = setInterval(fetchScans, 4000);
  }

  // Wire up all "Generate QR" buttons
  document.querySelectorAll('.qr-open-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = parseInt(btn.getAttribute('data-session-id'), 10) || 0;
      if (!id) return;
      openQrForSession(id, btn.getAttribute('data-slot-label') || '');
    });
  });

  // Cancel-QR handler
  const cancelBtn = el('qrCancelBtn');
  if (cancelBtn) cancelBtn.addEventListener('click', function(){
    if (!liveSessionId) return;
    if (!confirm('Cancel QR for this credit? This is only possible if nobody has scanned yet.')) return;
    const fd = new FormData();
    fd.append(CSRF_H, CSRF);
    fd.append('session_id', liveSessionId);
    fd.append('action', 'cancel');
    fetch(API_STATE, { method:'POST', body: fd, credentials:'same-origin' })
      .then(r => r.json())
      .then(function(data){
        if (data && data.success) {
          if (bsModal) bsModal.hide();
          window.location.reload();
        } else {
          alert((data && data.message) ? data.message : 'Cannot cancel: students may have already scanned.');
        }
      });
  });

  // Enable Bootstrap tooltips on switcher buttons
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(t){
    if (window.bootstrap && bootstrap.Tooltip) new bootstrap.Tooltip(t);
  });

  // ── Copy backup code to clipboard ──
  const copyBtn = el('qrCopyCodeBtn');
  if (copyBtn) copyBtn.addEventListener('click', function(){
    if (!lastBackup) return;
    const doCopy = function(text){
      if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text);
      // Fallback for older browsers
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch(_){}
      document.body.removeChild(ta);
      return Promise.resolve();
    };
    doCopy(lastBackup).then(function(){
      const orig = copyBtn.innerHTML;
      copyBtn.innerHTML = '<i class="fas fa-check me-1"></i>' + LABELS.copied;
      copyBtn.classList.add('btn-success'); copyBtn.classList.remove('btn-outline-secondary');
      setTimeout(function(){
        copyBtn.innerHTML = orig;
        copyBtn.classList.remove('btn-success'); copyBtn.classList.add('btn-outline-secondary');
      }, 1600);
    });
  });

  // ── Fullscreen toggle for the modal body ──
  const fsBtn = el('qrFullscreenBtn');
  if (fsBtn) fsBtn.addEventListener('click', function(){
    const body = el('qrLiveModalBody');
    if (!body) return;
    const isFs = document.fullscreenElement || document.webkitFullscreenElement;
    if (isFs) {
      (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      fsBtn.innerHTML = '<i class="fas fa-expand"></i>';
    } else {
      const req = body.requestFullscreen || body.webkitRequestFullscreen;
      if (req) req.call(body);
      fsBtn.innerHTML = '<i class="fas fa-compress"></i>';
    }
  });

  // ── Print current QR + backup code on a clean sheet ──
  const printBtn = el('qrPrintBtn');
  if (printBtn) printBtn.addEventListener('click', function(){
    if (!lastQrPayload || !lastBackup) return;
    const sheet = el('qrPrintSheet');
    if (!sheet) return;
    sheet.innerHTML = ''
      + '<div id="qrPrintInner" style="font-family:sans-serif;text-align:center;padding:24px">'
      +   '<h2 style="margin:0 0 8px"><?= h(addslashes(t('qr_live_panel'))) ?></h2>'
      +   '<div style="color:#555;font-size:14px;margin-bottom:12px">' + escapeHtml(lastSlotLbl) + '</div>'
      +   '<div id="qrPrintCanvas" style="display:inline-block"></div>'
      +   '<div style="margin-top:16px"><div style="font-size:12px;color:#666"><?= h(addslashes(t('qr_backup_code'))) ?></div>'
      +     '<div style="font-size:36px;font-weight:700;letter-spacing:.35rem;color:#0d6efd">' + escapeHtml(lastBackup) + '</div>'
      +   '</div>'
      +   '<div style="margin-top:10px;font-size:11px;color:#888"><?= h(addslashes(t('qr_scan_intro'))) ?></div>'
      + '</div>';
    try {
      new QRCode(document.getElementById('qrPrintCanvas'), {
        text: lastQrPayload, width: 320, height: 320, correctLevel: QRCode.CorrectLevel.M
      });
    } catch(e){}
    // Trigger print on the sheet only via a print stylesheet in CSS.
    document.body.classList.add('qr-print-mode');
    sheet.style.display = 'block';
    setTimeout(function(){
      window.print();
      setTimeout(function(){
        sheet.style.display = 'none';
        sheet.innerHTML = '';
        document.body.classList.remove('qr-print-mode');
      }, 300);
    }, 200);
  });
})();
</script>
<?php endif; ?>

<?php else: ?>

<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span><i class="fas fa-list-check me-2"></i><?= t('attendance_entry') ?> — <?=h($date)?></span>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="badge bg-light text-dark border">
        <i class="fas fa-bolt me-1 text-success"></i><?= (int)$todayStateCounts['open'] ?> <?= h(t('status_open')) ?>
        &middot; <i class="fas fa-hourglass-half ms-1 me-1 text-warning"></i><?= (int)$todayStateCounts['pending'] ?> <?= h(t('status_pending')) ?>
        &middot; <i class="fas fa-check-double ms-1 me-1 text-primary"></i><?= (int)$todayStateCounts['closed'] ?> <?= h(t('status_closed')) ?>
        &middot; <i class="fas fa-circle-xmark ms-1 me-1 text-danger"></i><?= (int)$todayStateCounts['missed'] ?> <?= h(t('status_missed')) ?>
      </span>
      <a href="<?=BASE_URL?>/reports/attendance_history.php" class="btn btn-sm btn-outline-secondary" title="<?= h(t('attendance_history')) ?>">
        <i class="fas fa-clock-rotate-left me-1"></i><?= h(t('attendance_history')) ?>
      </a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th><?= t('ui_time') ?></th><th><?=t('class')?></th><th><?=t('subject')?></th><th><?= t('ui_room') ?></th><th><?= t('credit') ?></th><th><?= t('ui_status') ?></th><th></th></tr></thead>
      <tbody>
      <?php if(!$todaySessions): ?>
        <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox me-2 opacity-50"></i><?= t('ui_no_today') ?></td></tr>
      <?php endif; ?>
      <?php foreach($todaySessions as $entry):
          $row   = $entry['row'];
          $state = $entry['state'];
          
          $rowClass = '';
          $badge    = '';
          $action   = '';
          if ($state === 'open') {
              $rowClass = 'table-success-soft';
              $badge    = '<span class="badge bg-success"><span class="live-dot me-1"></span><i class="fas fa-circle-play me-1"></i>'.h(t('status_open')).'</span>';
              $action   = '<a href="?view='.(int)$row['id'].'&date='.h($date).'" class="btn btn-sm btn-primary"><i class="fas fa-pen me-1"></i>'.h(t('open')).'</a>';
          } elseif ($state === 'pending') {
              $badge  = '<span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>'.h(t('status_pending')).'</span>';
              $action = '<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="'.h(t('ui_pending_msg')).'"><i class="fas fa-lock me-1"></i>'.h(t('locked') ?: 'قفل').'</button>';
          } elseif ($state === 'missed') {
              $badge  = '<span class="badge bg-danger"><i class="fas fa-circle-xmark me-1"></i>'.h(t('status_missed')).'</span>';
              $action = '<a href="?view='.(int)$row['id'].'&date='.h($date).'" class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye me-1"></i>'.h(t('view')).'</a>';
          } else { 
              $badge  = '<span class="badge bg-success"><i class="fas fa-check-double me-1"></i>'.h(t('status_closed')).'</span>';
              $action = '<a href="?view='.(int)$row['id'].'&date='.h($date).'" class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye me-1"></i>'.h(t('view')).'</a>';
          }
      ?>
      <tr<?= $rowClass !== '' ? ' class="'.h($rowClass).'"' : '' ?>>
        <td><strong><?=h(substr($row['start_time'],0,5))?>–<?=h(substr($row['end_time'],0,5))?></strong></td>
        <td><?=h($row['class_name'])?> <?=h($row['section'])?></td>
        <td><?=h($row['subject_name'])?></td>
        <td><?=h($row['room_name'])?></td>
        <td><?=$row['credit_units']?></td>
        <td><?= $badge ?></td>
        <td><?= $action ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
