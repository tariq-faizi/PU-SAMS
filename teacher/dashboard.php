<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin(); requireRole([ROLE_TEACHER]);

$db        = getDB();
$uid       = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = t('dashboard');
$today     = date('Y-m-d');
syncSessionsForDate($today, $uid);
markMissedSessionsForDate($today);

$creditsView = teacherCreditsTaughtVsRemaining($uid);

$todaySlots = $db->prepare("SELECT s.*, c.name as class_name, c.section, sub.name as subject_name, COALESCE(r.name,s.room) as room_name FROM attendance_sessions s JOIN classes c ON c.id=s.class_id JOIN subjects sub ON sub.id=s.subject_id LEFT JOIN rooms r ON r.id=s.room_id WHERE s.teacher_id=? AND s.session_date=? ORDER BY s.start_time, s.credit_slot_no");
$todaySlots->execute([$uid,$today]);
$todaySlots = $todaySlots->fetchAll();

$summary = [
  'today_total'   => count($todaySlots),
  'today_closed'  => count(array_filter($todaySlots, fn($x) => isCompletedSessionStatus($x['status'] ?? ''))),
  'today_missed'  => count(array_filter($todaySlots, fn($x) => ($x['status'] ?? '') === 'missed')),
  'today_live'    => 0,
  'today_pending' => 0,
];
foreach ($todaySlots as $__sl) {
    $__st = computeAttSessionLiveState($__sl);
    if ($__st['state'] === 'live')        $summary['today_live']++;
    elseif ($__st['state'] === 'pending') $summary['today_pending']++;
}

$summary['today_open'] = $summary['today_live'];

$myClasses = $db->prepare("SELECT COUNT(DISTINCT t.class_id) FROM timetable t WHERE t.teacher_id=? AND t.status='approved'");
$myClasses->execute([$uid]);
$myClassCount = (int)$myClasses->fetchColumn();

$mySubjects = $db->prepare("SELECT COUNT(DISTINCT t.subject_id) FROM timetable t WHERE t.teacher_id=? AND t.status='approved'");
$mySubjects->execute([$uid]);
$mySubjectCount = (int)$mySubjects->fetchColumn();

$myStudents = $db->prepare("SELECT COUNT(DISTINCT u.id) FROM timetable t JOIN users u ON u.class_id=t.class_id AND u.role_id=" . ROLE_STUDENT . " AND u.deleted_at IS NULL WHERE t.teacher_id=? AND t.status='approved'");
$myStudents->execute([$uid]);
$myStudentCount = (int)$myStudents->fetchColumn();

$riskRows = $db->prepare("SELECT DISTINCT u.id AS student_id, c.id AS class_id, s.id AS subject_id
                          FROM timetable t
                          JOIN classes c ON c.id=t.class_id
                          JOIN subjects s ON s.id=t.subject_id
                          JOIN users u ON u.class_id=c.id AND u.role_id=" . ROLE_STUDENT . " AND u.deleted_at IS NULL AND u.status='active'
                          WHERE t.teacher_id=? AND t.status='approved'");
$riskRows->execute([$uid]);
$riskCandidates = $riskRows->fetchAll();
$warningCount = 0; $deprivedCount = 0;
foreach ($riskCandidates as $rc) {
    $rec = studentSubjectAbsenceRecord((int)$rc['student_id'], (int)$rc['class_id'], (int)$rc['subject_id']);
    if ($rec['is_deprived']) $deprivedCount++;
    elseif ($rec['is_warning']) $warningCount++;
}

$teacherLiveSession = null;
foreach ($todaySlots as $sl) {
    $st = computeAttSessionLiveState($sl);
    if ($st['state'] === 'live') { $teacherLiveSession = $sl; break; }
}

$slotStates = [];
foreach ($todaySlots as $sl) {
    $slotStates[(int)$sl['id']] = computeAttSessionLiveState($sl);
}
require_once __DIR__.'/../includes/header.php';

$hTeach = BASE_URL.'/hierarchy.php?level=teacher_classes';
?>

<div class="hub-hero" style="background:linear-gradient(135deg, #0f766e 0%, #14b8a6 60%, #2dd4bf 100%);">
  <div class="hub-hero-row">
    <div>
      <h1 class="hub-hero-title">
        <span class="hub-hero-icon"><i class="fas fa-chalkboard-user"></i></span>
        <?= t('ui_welcome') ?>, <?= h($_SESSION['full_name'] ?? '') ?>
      </h1>
      <p class="hub-hero-sub"><?= t('ui_teacher_role') ?></p>
      <div class="hub-hero-meta">
        <span class="hub-hero-chip"><i class="fas fa-calendar"></i> <?= h(date('Y-m-d')) ?></span>
        <span class="hub-hero-chip"><i class="fas fa-list-check"></i> <?= $summary['today_total'] ?></span>
        <span class="hub-hero-chip"><i class="fas fa-user-graduate"></i> <?= $myStudentCount ?></span>
      </div>
    </div>
  </div>
</div>

<?php if ($teacherLiveSession): ?>
  <?= renderLiveAttendanceBanner($teacherLiveSession, ['show_stats'=>false,'teacher_name'=>$_SESSION['full_name'] ?? '']) ?>
<?php endif; ?>

<!-- My teaching — every academic card drills into the most relevant teacher page (V20 fix #2):
     • My Classes   → hierarchy (class drill-down works there)
     • My Subjects  → teacher/students.php (subject filter is built-in)
     • My Students  → teacher/students.php (groups by subject + per-student %)
     • Today Credits→ teacher/sessions.php (already correct)
-->
<div class="hub-section-title">
  <span class="hub-st-icon" style="background:linear-gradient(135deg,#14b8a6,#0d9488)"><i class="fas fa-graduation-cap"></i></span>
  <div><h5><?= t('ui_my_subjects') ?></h5></div>
</div>
<div class="hub-cluster-grid">
  <?= renderClusterCard(['tone'=>'teal',   'icon'=>'fa-chalkboard',    'label'=>t('ui_my_classes'),    'value'=>$myClassCount,         'href'=>$hTeach]) ?>
  <?= renderClusterCard(['tone'=>'amber',  'icon'=>'fa-book',          'label'=>t('ui_my_subjects'),   'value'=>$mySubjectCount,       'href'=>BASE_URL.'/teacher/students.php']) ?>
  <?= renderClusterCard(['tone'=>'emerald','icon'=>'fa-user-graduate', 'label'=>t('ui_my_students'),   'value'=>$myStudentCount,       'href'=>BASE_URL.'/teacher/students.php']) ?>
  <?= renderClusterCard(['tone'=>'primary','icon'=>'fa-list-check',    'label'=>t('ui_today_credits'), 'value'=>$summary['today_total'],'href'=>BASE_URL.'/teacher/sessions.php?date='.$today]) ?>
</div>

<!-- Today + risk -->

<div class="hub-section-title">
  <span class="hub-st-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-exclamation-triangle"></i></span>
  <div><h5><?= t('ui_today_ops') ?></h5></div>
</div>
<div class="hub-cluster-grid">
  <?= renderClusterCard(['tone'=>'warn',   'icon'=>'fa-hourglass-half','label'=>t('ui_pending_sessions'), 'value'=>$summary['today_pending'],'href'=>BASE_URL.'/teacher/sessions.php?date='.$today]) ?>
  <?= renderClusterCard(['tone'=>'info',   'icon'=>'fa-circle-play',   'label'=>t('ui_live_sessions'),    'value'=>$summary['today_live'],   'href'=>BASE_URL.'/teacher/sessions.php?date='.$today]) ?>
  <?= renderClusterCard(['tone'=>'success','icon'=>'fa-check-double',  'label'=>t('ui_completed_credits'),'value'=>$summary['today_closed'], 'href'=>BASE_URL.'/teacher/sessions.php?date='.$today.'&status=completed']) ?>
  <?= 
      renderClusterCard(['tone'=>'rose',   'icon'=>'fa-circle-xmark',  'label'=>t('ui_missed_today'),     'value'=>$summary['today_missed'], 'href'=>BASE_URL.'/missed/index.php?from='.$today.'&to='.$today]) ?>
  <?= renderClusterCard(['tone'=>'danger', 'icon'=>'fa-shield-halved', 'label'=>t('ui_at_risk'),          'value'=>$deprivedCount.' / '.$warningCount,'href'=>BASE_URL.'/teacher/reports.php?view=students&risk_mode=deprived']) ?>
</div>

<!-- Charts -->
<div class="row g-3 mb-3">
  <div class="col-lg-12">
    <div class="card">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fas fa-chart-line me-2"></i><?= t('ui_attendance_trend') ?></span>
        <div class="btn-group btn-group-sm" role="group">
          <button type="button" class="btn btn-outline-primary active" data-period="day"><?= t('day') ?></button>
          <button type="button" class="btn btn-outline-primary" data-period="week"><?= t('week') ?></button>
          <button type="button" class="btn btn-outline-primary" data-period="month"><?= t('month') ?></button>
          <button type="button" class="btn btn-outline-primary" data-period="semester"><?= t('semester') ?></button>
        </div>
      </div>
      <div class="card-body"><div class="chart-wrapper" style="position:relative;min-height:300px"><canvas id="teacherTrendChart"></canvas><div id="teacherTrendLoader" class="position-absolute top-50 start-50 translate-middle text-muted"><i class="fas fa-spinner fa-spin me-2"></i><?= t('loading') ?></div></div></div>
    </div>
  </div>
</div>

<!-- Today slots + credits view -->
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-list-check me-2"></i><?= t('ui_today_credits') ?></div>
      <div class="tt-today-wrap">
        <table class="table table-hover mb-0 att-classic-table">
          <thead><tr><th><?= t('ui_time') ?></th><th><?= t('class') ?></th><th><?= t('subject') ?></th><th><?= t('credit') ?></th><th><?= t('ui_status') ?></th><th></th></tr></thead>
          <tbody>
          <?php if(!$todaySlots): ?><tr class="tt-empty-row"><td colspan="6"><?= t('ui_no_today') ?></td></tr><?php endif; ?>
          <?php foreach($todaySlots as $slot):

              $slState = $slotStates[(int)$slot['id']]['state'] ?? 'pending';
          ?>
            <tr<?= $slState === 'pending' ? ' class="opacity-75"' : '' ?>>
              <td data-label="<?= h(t('ui_time')) ?>"><?=h(substr($slot['start_time'],0,5))?>–<?=h(substr($slot['end_time'],0,5))?></td>
              <td data-label="<?= h(t('class')) ?>"><?=h($slot['class_name'])?> <?=h($slot['section'])?></td>
              <td data-label="<?= h(t('subject')) ?>"><?=h($slot['subject_name'])?></td>
              <td data-label="<?= h(t('credit')) ?>"><?=h($slot['slot_label'])?></td>
              <td data-label="<?= h(t('ui_status')) ?>">
                <?php if($slState === 'live'): ?>
                  <span class="badge bg-success"><i class="fas fa-circle-play me-1"></i><?= h(t('ui_live') ?: 'LIVE') ?></span>
                <?php elseif($slState === 'pending'): ?>
                  <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i><?= h(t('waiting')) ?></span>
                <?php else:  ?>
                  <span class="badge bg-<?=attendanceStatusClass($slot['status'])?>"><?=h(sessionStatusLabel($slot['status']))?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if($slState === 'pending'): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="<?= h(t('ui_pending_msg') ?: 'Attendance can only be recorded once the exact scheduled start time has been reached.') ?>">
                    <i class="fas fa-lock me-1"></i><?= h(t('locked') ?: 'Locked') ?>
                  </button>
                <?php elseif($slState === 'live'): ?>
                  <a href="<?=BASE_URL?>/teacher/sessions.php?view=<?=$slot['timetable_id']?>&date=<?=$today?>" class="btn btn-sm btn-primary"><i class="fas fa-pen me-1"></i><?= t('open') ?></a>
                <?php else: ?>
                  <a href="<?=BASE_URL?>/teacher/sessions.php?view=<?=$slot['timetable_id']?>&date=<?=$today?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye me-1"></i><?= t('view') ?></a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fas fa-chart-column me-2"></i><?= t('teaching_progress') ?></span>
        <?php
          // Overall teaching progress summary across all subjects (header badge):
          $sumTotal = 0; $sumTaught = 0; $sumRemain = 0;
          foreach ($creditsView as $r) {
              $sumTotal  += (int)$r['total'];
              $sumTaught += (int)$r['taught'];
              $sumRemain += (int)$r['remaining'];
          }
          $overallPct = $sumTotal > 0 ? round($sumTaught * 100 / $sumTotal, 1) : 0.0;
          $remainPct  = $sumTotal > 0 ? round($sumRemain * 100 / $sumTotal, 1) : 0.0;
        ?>
        <?php if ($creditsView): ?>
          <span class="badge bg-<?= creditProgressClass($overallPct) ?>" title="<?= h(t('taught_credits')) ?>">
            <i class="fas fa-check me-1"></i><?= (int)$sumTaught ?>/<?= (int)$sumTotal ?> · <?= $overallPct ?>%
          </span>
        <?php endif; ?>
      </div>

      <?php if ($creditsView): ?>
      <!-- Aggregate teaching-progress summary across all subjects -->
      <div class="px-3 pt-3">
        <div class="d-flex justify-content-between small mb-1">
          <span class="text-success fw-semibold"><i class="fas fa-graduation-cap me-1"></i><?= h(t('taught_credits')) ?>: <strong><?= (int)$sumTaught ?></strong> <?= h(t('credits')) ?> (<?= $overallPct ?>%)</span>
          <span class="text-secondary fw-semibold"><i class="fas fa-hourglass-half me-1"></i><?= h(t('remaining_credits')) ?>: <strong><?= (int)$sumRemain ?></strong> <?= h(t('credits')) ?> (<?= $remainPct ?>%)</span>
        </div>
        <div class="progress" style="height:10px;border-radius:6px">
          <div class="progress-bar bg-success" role="progressbar"
               style="width: <?= $overallPct ?>%"
               aria-valuenow="<?= $overallPct ?>" aria-valuemin="0" aria-valuemax="100">
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="table-responsive mt-3">
        <table class="table table-sm mb-0 att-classic-table align-middle">
          <thead>
            <tr>
              <th><?= t('subject') ?></th>
              <th><?= t('class') ?></th>
              <th class="text-center"><?= h(t('lab_total')) ?></th>
              <th class="text-center text-success"><?= h(t('taught_credits')) ?></th>
              <th class="text-center text-secondary"><?= h(t('remaining_credits')) ?></th>
              <th style="min-width:140px"><?= h(t('teaching_progress')) ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$creditsView): ?>
            <tr><td colspan="6" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
          <?php foreach($creditsView as $row):
              $taughtPct = (float)$row['progress_pct'];
              $remPct    = max(0, round(100 - $taughtPct, 1));
              $total     = (int)$row['total'];
          ?>
            <tr>
              <td><?=h($row['subject_name'])?></td>
              <td><span class="small text-muted"><?=h($row['class_name'])?> <?=h($row['section'])?></span></td>
              <td class="text-center"><span class="badge bg-light text-dark border"><?= $total ?></span></td>
              <td class="text-center">
                <span class="badge bg-success"><?= (int)$row['taught'] ?></span>
                <div class="small text-success fw-semibold"><?= $taughtPct ?>%</div>
              </td>
              <td class="text-center">
                <span class="badge bg-secondary"><?= (int)$row['remaining'] ?></span>
                <div class="small text-muted"><?= $remPct ?>%</div>
              </td>
              <td>
                <div class="progress" style="height:8px;border-radius:4px" title="<?= h(t('taught_credits')) ?> <?= $taughtPct ?>% · <?= h(t('remaining_credits')) ?> <?= $remPct ?>%">
                  <div class="progress-bar bg-<?= creditProgressClass($taughtPct) ?>" role="progressbar"
                       style="width: <?= $taughtPct ?>%"
                       aria-valuenow="<?= $taughtPct ?>" aria-valuemin="0" aria-valuemax="100">
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?= renderLiveAttendanceJS() ?>
<script>
(function(){
  let trend;
  const tL = document.getElementById('teacherTrendLoader');
  function tCfg(d){return{type:'line',data:{labels:d.labels,datasets:[
    {label:'<?= t('ui_att_present') ?>',data:d.present_counts,borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.12)',fill:false,tension:.3,pointRadius:3,pointHoverRadius:5},
    {label:'<?= t('ui_att_absent') ?>',data:d.absent_counts,borderColor:'#dc2626',backgroundColor:'rgba(220,38,38,.10)',fill:false,tension:.3,pointRadius:3,pointHoverRadius:5}]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{beginAtZero:true,ticks:{precision:0}}},plugins:{legend:{position:'bottom'},tooltip:{callbacks:{afterBody:(items)=>{const i=items[0]?.dataIndex ?? 0;return ['<?= t('attendance_pct') ?>: '+(d.attendance[i] ?? 0)+'%','<?= t('absence_pct') ?>: '+(d.absence[i] ?? 0)+'%'];}}}}}};}
  function load(period){
    tL.style.display='';
    fetch(BASE_URL + '/api/charts.php?period=' + encodeURIComponent(period), {credentials:'same-origin'})
      .then(r=>r.json()).then(j=>{
        if(!j.ok) throw new Error(j.error||'load fail');
        const tcfg=tCfg(j);
        const tCtx=document.getElementById('teacherTrendChart').getContext('2d');
        if(trend){trend.data=tcfg.data;trend.options=tcfg.options;trend.update();}else{trend=new Chart(tCtx,tcfg);}
      }).catch(e=>{console.error(e);})
      .finally(()=>{tL.style.display='none';});
  }
  document.querySelectorAll('[data-period]').forEach(b=>{
    b.addEventListener('click',()=>{document.querySelectorAll('[data-period]').forEach(x=>x.classList.remove('active'));b.classList.add('active');load(b.dataset.period);});
  });
  if('requestIdleCallback' in window) requestIdleCallback(()=>load('day'),{timeout:800});
  else setTimeout(()=>load('day'),100);
})();
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
