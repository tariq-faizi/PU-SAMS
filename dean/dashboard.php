<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_DEPT_HEAD]);

$db        = getDB();
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$roleId    = (int)($_SESSION['role_id'] ?? 0);
$pageTitle = t('dashboard');
$today     = date('Y-m-d');

syncSessionsForDate($today);
markMissedSessionsForDate($today);

$stats        = facultyWideStats($facultyId);
$todayClasses = todayScheduledClasses($facultyId);
$todayCount   = count($todayClasses);

$pendingPreview = $db->prepare("SELECT er.id, er.request_type, er.reason, er.created_at,
                                       u.full_name AS req_name
                                FROM edit_requests er
                                JOIN users u ON u.id=er.requested_by
                                WHERE er.status='pending' AND u.faculty_id=?
                                ORDER BY er.created_at DESC LIMIT 6");
$pendingPreview->execute([$facultyId]);
$pendingPreview = $pendingPreview->fetchAll();

$facultyInfo = $db->prepare("SELECT name, code FROM faculties WHERE id=? LIMIT 1");
$facultyInfo->execute([$facultyId]);
$facultyInfo = $facultyInfo->fetch() ?: ['name'=>'-','code'=>'-'];

$roleLabel = $roleId === ROLE_DEPT_HEAD ? t('ui_dean') : t('ui_manager');
$roleIcon  = $roleId === ROLE_DEPT_HEAD ? 'fa-user-shield' : 'fa-user-tie';
$roleHueA  = $roleId === ROLE_DEPT_HEAD ? '#7c3aed' : '#0891b2';
$roleHueB  = $roleId === ROLE_DEPT_HEAD ? '#5b21b6' : '#0e7490';

require_once __DIR__.'/../includes/header.php';

$departmentsHref = BASE_URL.'/dean/departments.php?view=cluster&faculty_id='.(int)$facultyId;
$classesHref     = BASE_URL.'/dean/classes.php?view=cluster&faculty_id='.(int)$facultyId;
$subjectsHref    = BASE_URL.'/dean/subjects.php?view=cluster&faculty_id='.(int)$facultyId;
?>

<div class="hub-hero" style="background:linear-gradient(135deg, <?= $roleHueB ?> 0%, <?= $roleHueA ?> 60%, <?= $roleHueA ?> 100%);">
  <div class="hub-hero-row">
    <div>
      <h1 class="hub-hero-title">
        <span class="hub-hero-icon"><i class="fas <?= $roleIcon ?>"></i></span>
        <?= h($facultyInfo['name']) ?> · <?= $roleLabel ?>
      </h1>
      <p class="hub-hero-sub"><code style="background:rgba(255,255,255,.18);padding:2px 8px;border-radius:6px;color:#fff"><?= h($facultyInfo['code']) ?></code></p>
      <div class="hub-hero-meta">
        <span class="hub-hero-chip"><i class="fas fa-calendar"></i> <?= h(date('Y-m-d')) ?></span>
        <span class="hub-hero-chip"><i class="fas fa-chalkboard"></i> <?= (int)$stats['classes'] ?></span>
        <span class="hub-hero-chip"><i class="fas fa-user-graduate"></i> <?= (int)$stats['students'] ?></span>
      </div>
    </div>
  </div>
</div>

<div class="hub-section-title">
  <span class="hub-st-icon"><i class="fas fa-layer-group"></i></span>
  <div><h5><?= t('ui_academic_cluster') ?></h5></div>
</div>
<div class="hub-cluster-grid">
  <?= renderClusterCard(['tone'=>'cyan',   'icon'=>'fa-building',    'label'=>t('card_departments'),'value'=>$stats['departments'], 'href'=>$departmentsHref]) ?>
  <?= renderClusterCard(['tone'=>'teal',   'icon'=>'fa-chalkboard',  'label'=>t('card_classes'),    'value'=>$stats['classes'],     'href'=>$classesHref]) ?>
  <?= renderClusterCard(['tone'=>'amber',  'icon'=>'fa-book',        'label'=>t('card_subjects'),   'value'=>$stats['subjects'],    'href'=>$subjectsHref]) ?>
  <?= renderClusterCard(['tone'=>'primary','icon'=>'fa-calendar-alt','label'=>t('timetable'),       'value'=>$stats['approved_timetables'] ?? 0,'href'=>BASE_URL.'/dean/timetable.php']) ?>
</div>

<div class="hub-section-title">
  <span class="hub-st-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i class="fas fa-users"></i></span>
  <div><h5><?= t('ui_people_cluster') ?></h5></div>
</div>
<div class="hub-cluster-grid">
  <?= renderClusterCard(['tone'=>'indigo', 'icon'=>'fa-chalkboard-user','label'=>t('card_teachers'),   'value'=>$stats['teachers'], 'href'=>BASE_URL.'/dean/users.php?role='.ROLE_TEACHER]) ?>
  <?= renderClusterCard(['tone'=>'pink',   'icon'=>'fa-user-tie',       'label'=>t('card_managers'),   'value'=>$stats['managers'], 'href'=>BASE_URL.'/dean/users.php?role='.ROLE_ATT_MGR]) ?>
  <?= renderClusterCard(['tone'=>'emerald','icon'=>'fa-user-graduate',  'label'=>t('card_students'),   'value'=>$stats['students'], 'href'=>BASE_URL.'/dean/users.php?role='.ROLE_STUDENT]) ?>
  <?= renderClusterCard(['tone'=>'slate',  'icon'=>'fa-users',          'label'=>t('card_total_users'),'value'=>$stats['total_users'] ?? 0,'href'=>BASE_URL.'/dean/users.php']) ?>
</div>

<div class="hub-section-title">
  <span class="hub-st-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7)"><i class="fas fa-calendar-day"></i></span>
  <div><h5><?= t('ui_today_ops') ?></h5></div>
</div>
<div class="hub-cluster-grid">
  <?= renderClusterCard(['tone'=>'primary','icon'=>'fa-calendar-day',  'label'=>t('ui_scheduled'),       'value'=>$stats['today_classes_scheduled'],          'href'=>BASE_URL.'/dean/timetable.php?day='.(int)date('w')]) ?>
  <?= renderClusterCard(['tone'=>'warn',   'icon'=>'fa-hourglass-half','label'=>t('ui_pending_sessions'),'value'=>(int)($stats['today_pending'] ?? 0),         'href'=>BASE_URL.'/dean/attendance.php?date='.$today.'&state=pending']) ?>
  <?= renderClusterCard(['tone'=>'info',   'icon'=>'fa-circle-play',   'label'=>t('ui_live_sessions'),   'value'=>(int)($stats['today_active'] ?? $stats['today_open'] ?? 0),'href'=>BASE_URL.'/dean/attendance.php?date='.$today.'&state=live']) ?>
  <?= renderClusterCard(['tone'=>'success','icon'=>'fa-check-double',  'label'=>t('ui_closed_sessions'), 'value'=>$stats['today_closed'],                     'href'=>BASE_URL.'/dean/attendance.php?date='.$today.'&status=completed']) ?>
  <?= 
      renderClusterCard(['tone'=>'rose',   'icon'=>'fa-circle-xmark',  'label'=>t('ui_missed_today'),    'value'=>$stats['today_missed'],                     'href'=>BASE_URL.'/missed/index.php?from='.$today.'&to='.$today]) ?>
</div>

<div class="hub-section-title">
  <span class="hub-st-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-clipboard-check"></i></span>
  <div><h5><?= t('ui_management_cluster') ?></h5></div>
</div>
<div class="hub-cluster-grid">
  <?= renderClusterCard(['tone'=>'warn',   'icon'=>'fa-tasks',     'label'=>t('ui_pending_approvals'),'value'=>$stats['pending_approvals'],     'href'=>BASE_URL.'/dean/approvals.php?status=pending']) ?>
  <?= renderClusterCard(['tone'=>'info',   'icon'=>'fa-bullhorn',  'label'=>t('announcements'),        'value'=>$stats['announcements_active'] ?? 0,'href'=>BASE_URL.'/notifications/announcements.php']) ?>
  <?= renderClusterCard(['tone'=>'emerald','icon'=>'fa-user-check','label'=>t('ui_active_users'),     'value'=>$stats['active_users'] ?? 0,     'href'=>BASE_URL.'/dean/users.php?status=active']) ?>
  <?= renderClusterCard(['tone'=>'slate',  'icon'=>'fa-user-slash','label'=>t('ui_inactive_users'),   'value'=>$stats['inactive_users'] ?? 0,   'href'=>BASE_URL.'/dean/users.php?status=inactive']) ?>
</div>

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
      <div class="card-body"><div class="chart-wrapper" style="position:relative;min-height:300px"><canvas id="trendChart"></canvas><div id="trendLoader" class="position-absolute top-50 start-50 translate-middle text-muted"><i class="fas fa-spinner fa-spin me-2"></i><?= t('loading') ?></div></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-calendar-day me-2"></i><?= t('ui_today_schedule') ?> (<?= $todayCount ?>)</span>
        <a href="<?= BASE_URL ?>/dean/timetable.php?day=<?= (int)date('w') ?>" class="btn btn-sm btn-outline-primary"><?= t('ui_view_all') ?></a>
      </div>
      <div class="tt-today-wrap" style="max-height:380px;overflow:auto">
        <table class="table table-hover table-sm mb-0 align-middle att-classic-table">
          <thead><tr><th><?= t('ui_time') ?></th><th><?= t('departments') ?></th><th><?= t('class') ?></th><th><?= t('subject') ?></th><th><?= t('teacher') ?></th></tr></thead>
          <tbody>
            <?php if (!$todayClasses): ?>
              <tr class="tt-empty-row"><td colspan="5"><?= t('ui_no_today') ?></td></tr>
            <?php else: foreach ($todayClasses as $tc): ?>
              <tr>
                <td data-label="Time"><?= h(substr($tc['start_time'],0,5)) ?>–<?= h(substr($tc['end_time'],0,5)) ?></td>
                <td data-label="Department"><?= h($tc['department_name'] ?? '—') ?></td>
                <td data-label="Class"><?= h($tc['class_name']) ?> <?= h($tc['section']) ?></td>
                <td data-label="Subject"><?= h($tc['subject_name']) ?></td>
                <td data-label="Teacher"><?= h($tc['teacher_name']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-tasks me-2"></i><?= t('ui_pending_approvals') ?> (<?= $stats['pending_approvals'] ?>)</span>
        <a href="<?= BASE_URL ?>/dean/approvals.php?status=pending" class="btn btn-sm btn-outline-warning"><?= t('ui_view_all') ?></a>
      </div>
      <ul class="list-group list-group-flush">
        <?php if (!$pendingPreview): ?>
          <li class="list-group-item text-center text-muted py-4"><?= t('ui_no_pending') ?></li>
        <?php else: foreach ($pendingPreview as $p): ?>
          <li class="list-group-item">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <div class="fw-semibold"><span class="badge bg-info text-dark me-1"><?= h($p['request_type']) ?></span><?= h($p['req_name']) ?></div>
                <div class="small text-muted"><?= h(date('d M H:i', strtotime($p['created_at']))) ?></div>
                <div class="small mt-1"><?= h(mb_substr((string)$p['reason'], 0, 90)) ?></div>
              </div>
              <a href="<?= BASE_URL ?>/dean/approvals.php?status=pending#req-<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary"><?= t('view') ?></a>
            </div>
          </li>
        <?php endforeach; endif; ?>
      </ul>
    </div>
    <div class="card">
      <div class="card-header"><i class="fas fa-bolt me-2"></i><?= t('ui_quick_actions') ?></div>
      <div class="card-body d-grid gap-2">
        <a href="<?= BASE_URL ?>/dean/users.php"      class="btn btn-outline-primary"><i class="fas fa-users me-1"></i><?= t('ui_manage_users') ?></a>
        <a href="<?= BASE_URL ?>/dean/timetable.php"  class="btn btn-outline-info"><i class="fas fa-calendar-alt me-1"></i><?= t('timetable') ?></a>
        <a href="<?= BASE_URL ?>/dean/attendance.php" class="btn btn-outline-success"><i class="fas fa-eye me-1"></i><?= t('attendance') ?></a>
        <a href="<?= BASE_URL ?>/hierarchy.php?level=root"      class="btn btn-outline-secondary"><i class="fas fa-sitemap me-1"></i><?= t('hierarchy_view') ?></a>
        <a href="<?= BASE_URL ?>/dean/subjects.php"     class="btn btn-outline-secondary"><i class="fas fa-book me-1"></i><?= t('subjects') ?></a>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  let trend;
  const trendLoader = document.getElementById('trendLoader');
  function tCfg(d){return{type:'line',data:{labels:d.labels,datasets:[
    {label:'<?= t('ui_att_present') ?>',data:d.present_counts,borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.12)',fill:false,tension:.3,pointRadius:3,pointHoverRadius:5},
    {label:'<?= t('ui_att_absent') ?>',data:d.absent_counts,borderColor:'#dc2626',backgroundColor:'rgba(220,38,38,.10)',fill:false,tension:.3,pointRadius:3,pointHoverRadius:5}]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{beginAtZero:true,ticks:{precision:0}}},plugins:{legend:{position:'bottom'},tooltip:{callbacks:{afterBody:(items)=>{const i=items[0]?.dataIndex ?? 0;return ['<?= t('attendance_pct') ?>: '+(d.attendance[i] ?? 0)+'%','<?= t('absence_pct') ?>: '+(d.absence[i] ?? 0)+'%'];}}}}}};}
  function load(period){
    trendLoader.style.display='';
    fetch(BASE_URL + '/api/charts.php?period=' + encodeURIComponent(period), {credentials:'same-origin'})
      .then(r=>r.json()).then(j=>{
        if(!j.ok) throw new Error(j.error||'load fail');
        const tcfg=tCfg(j);
        const tCtx=document.getElementById('trendChart').getContext('2d');
        if(trend){trend.data=tcfg.data;trend.options=tcfg.options;trend.update();}else{trend=new Chart(tCtx,tcfg);}
      }).catch(e=>{console.error(e);})
      .finally(()=>{trendLoader.style.display='none';});
  }
  document.querySelectorAll('[data-period]').forEach(b=>{
    b.addEventListener('click',()=>{document.querySelectorAll('[data-period]').forEach(x=>x.classList.remove('active'));b.classList.add('active');load(b.dataset.period);});
  });
  if('requestIdleCallback' in window) requestIdleCallback(()=>load('day'),{timeout:800});
  else setTimeout(()=>load('day'),100);
})();
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
