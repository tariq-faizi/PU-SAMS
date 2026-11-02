<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin(); requireRole([ROLE_STUDENT]);
$pageTitle = t('ui_my_attendance');
$uid = (int)($_SESSION['user_id'] ?? 0);

$_today_local = date('Y-m-d');
if (function_exists('syncSessionsForDate'))      syncSessionsForDate($_today_local);
if (function_exists('markMissedSessionsForDate')) markMissedSessionsForDate($_today_local);
$subjects = studentSubjectAttendanceSummary($uid);
$subjectId = (int)($_GET['subject'] ?? ($subjects[0]['subject_id'] ?? 0));
$groupBy = $_GET['group'] ?? 'day';
$selected = null;
foreach ($subjects as $subject) {
    if ((int)$subject['subject_id'] === $subjectId) { $selected = $subject; break; }
}
$history  = $selected ? studentAttendanceHistory($uid, (int)$selected['subject_id'], $groupBy) : [];

$timeline = $selected ? studentAttendanceTimeline($uid, (int)$selected['subject_id'], 200) : [];

$db = getDB();
$classId = (int)($_SESSION['class_id'] ?? 0);
$today   = date('Y-m-d');

$myTodaySessionsAll = $db->prepare("SELECT s.*, c.name AS class_name, c.section, sub.name AS subject_name, u.full_name AS teacher_name
                                     FROM attendance_sessions s
                                     JOIN classes  c ON c.id=s.class_id
                                     JOIN subjects sub ON sub.id=s.subject_id
                                     JOIN users    u ON u.id=s.teacher_id
                                     WHERE s.class_id=? AND s.session_date=?
                                     ORDER BY s.start_time");
$myTodaySessionsAll->execute([$classId, $today]);
$myTodaySessionsAll = $myTodaySessionsAll->fetchAll();

$myTodaySessions = $myTodaySessionsAll;

$stateOrder = ['open' => 0, 'pending' => 1, 'closed' => 2, 'missed' => 3];
$myTodayStateCounts = ['open' => 0, 'pending' => 0, 'closed' => 0, 'missed' => 0];
$myTodayEnriched = [];
foreach ($myTodaySessions as $s2) {
    $st = computeAttSessionLiveState($s2);
    $eff = $st['state'] === 'live' ? 'open' : $st['state'];
    if (isset($myTodayStateCounts[$eff])) $myTodayStateCounts[$eff]++;
    $myTodayEnriched[] = ['session' => $s2, 'info' => $st, 'state' => $eff];
}
usort($myTodayEnriched, function($a, $b) use ($stateOrder) {
    $oa = $stateOrder[$a['state']] ?? 4;
    $ob = $stateOrder[$b['state']] ?? 4;
    if ($oa !== $ob) return $oa <=> $ob;
    return strcmp((string)($a['session']['start_time'] ?? ''), (string)($b['session']['start_time'] ?? ''));
});

$liveSession = null;
foreach ($myTodayEnriched as $entry) {
    if ($entry['state'] === 'open')                          { $liveSession = $entry['session']; break; }
    if ($entry['state'] === 'pending' && !$liveSession)      { $liveSession = $entry['session']; }
}
require_once __DIR__.'/../includes/header.php';
?>
<?php if ($liveSession): ?>
  <?= renderLiveAttendanceBanner($liveSession, ['show_stats' => false]) ?>
  <?= renderLiveAttendanceJS() ?>
<?php endif; ?>

<?php if (!empty($myTodayEnriched)): ?>

<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span><i class="fas fa-calendar-day me-2"></i><?= t('ui_today_schedule') ?> — <?= h($today) ?></span>
    <span class="badge bg-light text-dark border">
      <i class="fas fa-bolt me-1 text-success"></i><?= (int)$myTodayStateCounts['open'] ?> <?= h(t('status_open')) ?>
      &middot; <i class="fas fa-hourglass-half ms-1 me-1 text-warning"></i><?= (int)$myTodayStateCounts['pending'] ?> <?= h(t('status_pending')) ?>
      &middot; <i class="fas fa-check-double ms-1 me-1 text-primary"></i><?= (int)$myTodayStateCounts['closed'] ?> <?= h(t('status_closed')) ?>
      &middot; <i class="fas fa-circle-xmark ms-1 me-1 text-danger"></i><?= (int)$myTodayStateCounts['missed'] ?> <?= h(t('status_missed')) ?>
    </span>
  </div>
  <div class="list-group list-group-flush">
    <?php foreach($myTodayEnriched as $entry):
        $hs    = $entry['session'];
        $state = $entry['state'];
        
        if ($state === 'open') {
            $badge = '<span class="badge bg-success"><span class="live-dot me-1"></span><i class="fas fa-circle-play me-1"></i>'.h(t('status_open')).'</span>';
        } elseif ($state === 'pending') {
            $badge = '<span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>'.h(t('status_pending')).'</span>';
        } elseif ($state === 'missed') {
            $badge = '<span class="badge bg-danger"><i class="fas fa-circle-xmark me-1"></i>'.h(t('status_missed')).'</span>';
        } else { 
            $badge = '<span class="badge bg-success"><i class="fas fa-check-double me-1"></i>'.h(t('status_closed')).'</span>';
        }
    ?>
    <div class="list-group-item d-flex flex-wrap align-items-center gap-2">
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= h($hs['subject_name'] ?? '') ?></div>
        <div class="small text-muted">
          <?= h($hs['class_name'] ?? '') ?> <?= h($hs['section'] ?? '') ?>
          &middot; <?= h(substr((string)($hs['start_time'] ?? ''),0,5)) ?>–<?= h(substr((string)($hs['end_time'] ?? ''),0,5)) ?>
          <?php if(!empty($hs['teacher_name'])): ?>&middot; <i class="fas fa-user-tie"></i> <?= h($hs['teacher_name']) ?><?php endif; ?>
        </div>
      </div>
      <?= $badge ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-book me-2"></i><?= t('ui_my_subjects') ?></div>
      <div class="list-group list-group-flush">
        <?php if(!$subjects): ?><div class="list-group-item text-muted"><?= t('no_records') ?></div><?php endif; ?>
        <?php foreach($subjects as $sub): ?>
          <a class="list-group-item list-group-item-action <?=((int)$sub['subject_id']===$subjectId)?'active':''?>" href="?subject=<?=$sub['subject_id']?>&group=<?=h($groupBy)?>">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <div class="fw-semibold"><?=h($sub['subject_name'])?></div>
                <div class="small <?=((int)$sub['subject_id']===$subjectId)?'text-white-50':'text-muted'?>"><?=h($sub['code'])?> · <?= $sub['held_credits'] ?>/<?=$sub['semester_credits']?> <?= t('taught_credits') ?></div>
                <div class="small <?=((int)$sub['subject_id']===$subjectId)?'text-white-50':'text-muted'?>">✅ <?=$sub['present_count']?> · ❌ <?=$sub['absent_count']?></div>
              </div>
              <span class="badge bg-<?=absencePercentClass($sub['absence_pct'])?>"><?=$sub['absence_pct']?>%</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <?php if($selected): ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
          <div>
            <h5 class="mb-1"><?=h($selected['subject_name'])?></h5>
            <div class="text-muted small"><?=h($selected['code'])?> · <?=$selected['semester_credits']?> <?= t('credit') ?> · <?=$selected['held_credits']?> <?= t('taught_credits') ?> · ✅ <?=$selected['present_count']?> · ❌ <?=$selected['absent_count']?></div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-info text-dark"><?= t('taught_credits') ?>: <?=$selected['held_credits']?></span>
            <span class="badge bg-success">✅ <?=$selected['present_count']?></span>
            <span class="badge bg-danger">❌ <?=$selected['absent_count']?></span>
            <span class="badge bg-<?=creditProgressClass($selected['progress_pct'])?>"><?=$selected['progress_pct']?>%</span>
            <span class="badge bg-<?=absencePercentClass($selected['absence_pct'])?>"><?=$selected['absence_pct']?>%</span>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fas fa-clock-rotate-left me-2"></i><?= t('attendance_history') ?></span>
        <div class="btn-group btn-group-sm" role="group">
          <?php foreach(['day','week','month','semester'] as $key): ?>
            <a href="?subject=<?=$selected['subject_id']?>&group=<?=$key?>" class="btn btn-outline-secondary <?=$groupBy===$key?'active':''?>"><?= t($key) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th><?= t('period') ?></th><th><?= t('credit') ?></th><th>✅</th><th>❌</th><th>%</th></tr></thead>
          <tbody>
          <?php if(!$history): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr><?php endif; ?>
          <?php foreach($history as $row): ?>
            <tr>
              <td><?=h($row['period_label'])?></td>
              <td><?=$row['held_credits']?></td>
              <td><?=$row['present_count']?></td>
              <td><?=$row['absent_count']?></td>
              <td><span class="badge bg-<?=absencePercentClass($row['absence_pct'])?>"><?=$row['absence_pct']?>%</span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" id="detailed-records">
      <div class="card-header"><i class="fas fa-list-check me-2"></i><?= t('details') ?></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th><?= t('ui_date') ?></th><th><?= t('credit') ?></th><th><?= t('ui_time') ?></th><th>✅ / ❌</th><th><?= t('updated_at') ?></th></tr></thead>
          <tbody>
          <?php if(!$timeline): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr><?php endif; ?>
          <?php foreach($timeline as $row):
              $rawStatus = trim((string)($row['status'] ?? ''));
          ?>
            <tr>
              <td><?=h($row['session_date'])?></td>
              <td><?=h($row['slot_label'])?></td>
              <td><?=h(substr($row['start_time'],0,5))?>–<?=h(substr($row['end_time'],0,5))?></td>
              <td class="text-center">
                <?= renderAttStatusBadge($rawStatus) ?>
              </td>
              <td><?=!empty($row['marked_at']) ? date('d M Y H:i', strtotime($row['marked_at'])) : '—'?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><?= t('no_records') ?></div></div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
