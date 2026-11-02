<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin(); requireRole([ROLE_STUDENT]);
$db        = getDB();
$uid       = (int)$_SESSION['user_id'];
$pageTitle = t('dashboard');
$today     = date('Y-m-d');
syncSessionsForDate($today);

if (function_exists('markMissedSessionsForDate')) markMissedSessionsForDate($today);

$summary  = studentAbsenceSummary($uid);
$subjects = studentSubjectAttendanceSummary($uid);
$classId  = (int)($_SESSION['class_id'] ?? 0);

$todayTimetable = $db->prepare("SELECT t.*, sub.name as subject_name, COALESCE(r.name,t.room) as room_name FROM timetable t JOIN subjects sub ON sub.id=t.subject_id LEFT JOIN rooms r ON r.id=t.room_id WHERE t.class_id=? AND t.status='approved' AND t.day_of_week=? ORDER BY t.start_time");
$todayTimetable->execute([$classId, sessionWeekday($today)]);
$todayTimetable = $todayTimetable->fetchAll();

foreach ($subjects as &$sub) {
    $rec = studentSubjectAbsenceRecord($uid, (int)$sub['class_id'], (int)$sub['subject_id']);
    $sub['absence_pct']       = $rec['absence_pct'];
    $sub['absent_count']      = $rec['absent_credits'];
    $sub['present_count']     = $rec['present_credits'];
    $sub['held_credits']      = $rec['marked_credits'];
    $sub['semester_credits']  = $rec['total_semester'];
    $sub['warning_reached']   = $rec['is_warning'] || $rec['is_deprived'];
    $sub['deprived']          = $rec['is_deprived'];
    $sub['absent_to_deprive'] = $rec['absent_to_deprive'];
}
unset($sub);

$deprivedSubs = array_filter($subjects, fn($s) => !empty($s['deprived']));
$warningSubs  = array_filter($subjects, fn($s) => !empty($s['warning_reached']) && empty($s['deprived']));

$totalAbsent = array_sum(array_map(fn($s) => (int)$s['absent_count'], $subjects));
$totalSemCredits = array_sum(array_map(fn($s) => (int)$s['semester_credits'], $subjects));
$overallAbsencePct = $totalSemCredits > 0 ? round(($totalAbsent / $totalSemCredits) * 100, 2) : 0;
$maxAbs = 0; foreach ($subjects as $s) $maxAbs = max($maxAbs, (float)$s['absence_pct']);

$myTodaySessions = $db->prepare("SELECT s.*, c.name AS class_name, c.section, sub.name AS subject_name, u.full_name AS teacher_name
                                 FROM attendance_sessions s
                                 JOIN classes c ON c.id=s.class_id
                                 JOIN subjects sub ON sub.id=s.subject_id
                                 JOIN users u ON u.id=s.teacher_id
                                 WHERE s.class_id=? AND s.session_date=?
                                 ORDER BY s.start_time");
$myTodaySessions->execute([$classId, $today]);
$myTodaySessions = $myTodaySessions->fetchAll();

$liveSession = null;
foreach ($myTodaySessions as $s2) {
    $st = computeAttSessionLiveState($s2);
    if ($st['state'] === 'live') { $liveSession = $s2; break; }
}

$warnAt = (float)attendanceWarningPercentThreshold();
$depAt  = (float)attendanceDeprivationPercentThreshold();

require_once __DIR__.'/../includes/header.php';

$heroTone = !empty($deprivedSubs) ? 'linear-gradient(135deg, #991b1b 0%, #dc2626 60%, #f87171 100%)' :
            (!empty($warningSubs) ? 'linear-gradient(135deg, #b45309 0%, #f59e0b 60%, #fbbf24 100%)' :
                                    'linear-gradient(135deg, #15803d 0%, #16a34a 60%, #4ade80 100%)');
?>
<div class="hub-hero" style="background:<?= $heroTone ?>;">
  <div class="hub-hero-row">
    <div>
      <h1 class="hub-hero-title">
        <span class="hub-hero-icon"><i class="fas fa-user-graduate"></i></span>
        <?= t('ui_welcome') ?>, <?= h($_SESSION['full_name'] ?? '') ?>
      </h1>
      <p class="hub-hero-sub"><?= t('ui_student_role') ?></p>
      <div class="hub-hero-meta">
        <span class="hub-hero-chip"><i class="fas fa-calendar"></i> <?= h(date('Y-m-d')) ?></span>
        <span class="hub-hero-chip"><i class="fas fa-book"></i> <?= count($subjects) ?></span>
        <span class="hub-hero-chip"><i class="fas fa-percent"></i> <?= $overallAbsencePct ?>%</span>
      </div>
    </div>
  </div>
</div>

<?php if ($liveSession): ?>
  <?= renderLiveAttendanceBanner($liveSession, ['show_stats' => false]) ?>
<?php endif; ?>

<?php
/* ── QR Attendance Quick Action ──
   Shown to students whenever the 'scan_qr' permission is granted for them.
   It links straight to /student/scan.php. When a live session is running,
   the tile is highlighted with a subtle pulsing badge to draw attention. */
if (function_exists('userHasPermission') && userHasPermission('scan_qr', ROLE_STUDENT)):
?>
<a class="qr-quick-action-tile mb-3" href="<?= BASE_URL ?>/student/scan.php">
    <span class="qr-qa-icon"><i class="fas fa-qrcode"></i></span>
    <div>
      <div class="qr-qa-title"><?= h(t('scan_attendance')) ?></div>
      <div class="qr-qa-sub">
        <?= h(t('qr_scan_intro')) ?>
      </div>
    </div>
    <span class="qr-qa-cta">
      <?php if ($liveSession): ?>
        <i class="fas fa-bolt me-1"></i><?= h(t('qr_scan_now')) ?>
      <?php else: ?>
        <i class="fas fa-arrow-right me-1"></i><?= h(t('open')) ?>
      <?php endif; ?>
    </span>
</a>
<?php endif; ?>

<?php if ($deprivedSubs): ?>
  <div class="alert alert-danger" style="border-left:6px solid #b91c1c;border-radius:14px;padding:18px 22px">
    <h5 style="margin:0 0 8px 0;color:#991b1b;font-weight:800"><i class="fas fa-ban me-2"></i><?= t('risk_deprived_title') ?></h5>
    <ul class="mb-0">
      <?php foreach ($deprivedSubs as $sj): ?>
        <li>
          <strong><?= h($sj['subject_name']) ?></strong>
          (<code><?= h($sj['code']) ?></code>) — <?= t('absence_pct') ?>: <strong><?= (float)$sj['absence_pct'] ?>%</strong>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($warningSubs): ?>
  <div class="alert alert-warning" style="border-left:6px solid #d97706;border-radius:14px;padding:18px 22px">
    <h5 style="margin:0 0 8px 0;color:#92400e;font-weight:800"><i class="fas fa-triangle-exclamation me-2"></i><?= t('risk_warning_title') ?></h5>
    <ul class="mb-0">
      <?php foreach ($warningSubs as $sj): ?>
        <li>
          <strong><?= h($sj['subject_name']) ?></strong>
          (<code><?= h($sj['code']) ?></code>) — <?= t('absence_pct') ?>: <strong><?= (float)$sj['absence_pct'] ?>%</strong>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- Overview cluster — every card uses unique tone -->
<div class="hub-section-title">
  <span class="hub-st-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i class="fas fa-chart-line"></i></span>
  <div><h5><?= t('ui_my_attendance') ?></h5></div>
</div>
<div class="hub-cluster-grid">
  <?= renderClusterCard(['tone'=>'primary','icon'=>'fa-book',                'label'=>t('ui_my_subjects'),       'value'=>count($subjects),     'href'=>BASE_URL.'/student/attendance.php']) ?>
  <?= renderClusterCard(['tone'=>($overallAbsencePct >= $depAt ? 'danger' : ($overallAbsencePct >= $warnAt ? 'warn' : 'emerald')),'icon'=>'fa-percent','label'=>t('ui_overall_absence'),'value'=>$overallAbsencePct.'%','href'=>BASE_URL.'/student/attendance.php']) ?>
  <?= renderClusterCard(['tone'=>'amber',  'icon'=>'fa-triangle-exclamation','label'=>t('ui_warning_subjects'),  'value'=>count($warningSubs),  'href'=>BASE_URL.'/student/attendance.php']) ?>
  <?= renderClusterCard(['tone'=>'rose',   'icon'=>'fa-ban',                 'label'=>t('ui_deprived_subjects'), 'value'=>count($deprivedSubs), 'href'=>BASE_URL.'/student/attendance.php']) ?>
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
      <div class="card-body"><div class="chart-wrapper" style="position:relative;min-height:300px"><canvas id="studentTrendChart"></canvas><div id="studentTrendLoader" class="position-absolute top-50 start-50 translate-middle text-muted"><i class="fas fa-spinner fa-spin me-2"></i><?= t('loading') ?></div></div></div>
    </div>
  </div>
</div>

<!-- Subject detail -->
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3" id="subjectTable">
      <div class="card-header"><i class="fas fa-book-open me-2"></i><?= t('per_subject_attendance') ?></div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle att-classic-table">
          <thead><tr><th><?= t('subject') ?></th><th><?= t('credit') ?></th><th><?= t('taught_credits') ?></th><th><?= t('present_count') ?></th><th><?= t('absent_count') ?></th><th><?= t('absence_percent') ?></th><th><?= t('attendance_status') ?></th><th></th></tr></thead>
          <tbody>
          <?php if(!$subjects): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr><?php endif; ?>
          <?php foreach($subjects as $sub):
            $semCredits = (int)$sub['semester_credits'];
            $heldCredits = (int)$sub['held_credits'];
            $present = (int)$sub['present_count'];
            $absent = (int)$sub['absent_count'];
            $pct = (float)$sub['absence_pct'];
            $state = $sub['deprived'] ? 'deprived' : ($sub['warning_reached'] ? 'warning' : 'safe');
            $stateLabel = $sub['deprived'] ? t('deprived_state') : ($sub['warning_reached'] ? t('warning_state') : t('safe'));
          ?>
            <tr class="<?= $state === 'deprived' ? 'is-deprived' : ($state === 'warning' ? 'is-warning' : '') ?>">
              <td>
                <div class="fw-semibold"><?=h($sub['subject_name'])?></div>
                <div class="small text-muted"><code><?=h($sub['code'])?></code></div>
              </td>
              <td><span class="badge bg-secondary"><?= $semCredits ?></span></td>
              <td><span class="badge bg-info text-dark"><?= $heldCredits ?></span></td>
              <td><span class="badge bg-success"><?= $present ?></span></td>
              <td><span class="badge bg-<?= $absent > 0 ? 'danger' : 'secondary' ?>"><?= $absent ?></span></td>
              <td><strong class="text-<?=absencePercentClass($pct)?>"><?=$pct?>%</strong></td>
              <td><span class="hub-risk-pill <?= $state ?>"><?= h($stateLabel) ?></span></td>
              <td><a href="<?=BASE_URL?>/student/attendance.php?subject=<?=$sub['subject_id']?>&group=day" class="btn btn-sm btn-outline-primary"><?= t('view') ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-calendar-day me-2"></i><?= t('ui_today_schedule') ?></div>
      <div class="card-body p-3">
        <?php if (!empty($myTodaySessions)): ?>
          <?= renderPendingAttList($myTodaySessions) ?>
        <?php else: ?>
          <div class="text-center text-muted py-3"><?= t('ui_no_today') ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?= renderLiveAttendanceJS() ?>
<script>
(function(){
  let trend;
  const tL = document.getElementById('studentTrendLoader');
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
        const tCtx=document.getElementById('studentTrendChart').getContext('2d');
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
