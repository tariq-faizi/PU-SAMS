<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
$pageTitle = t('missed_classes');
$db        = getDB();
$roleId    = (int)($_SESSION['role_id'] ?? 0);

if (!function_exists('missedDefaultFromDate')) {
    function missedDefaultFromDate(): string {
        if (function_exists('getSemesterStartDate')) {
            $s = getSemesterStartDate();
            if (is_string($s) && $s !== '') return $s;
        }
        return date('Y-m-d', strtotime('-90 days'));
    }
}

$filter = [
    'subject_id'    => (int)($_GET['subject_id'] ?? 0) ?: null,
    'teacher_id'    => (int)($_GET['teacher_id'] ?? 0) ?: null,
    'class_id'      => (int)($_GET['class_id'] ?? 0) ?: null,
    'department_id' => (int)($_GET['department_id'] ?? 0) ?: null,
    'faculty_id'    => (int)($_GET['faculty_id'] ?? 0) ?: null,
    'from'          => $_GET['from'] ?? missedDefaultFromDate(),
    'to'            => $_GET['to']   ?? date('Y-m-d'),
];
$searchTerm = trim((string)($_GET['q'] ?? ''));

$rows = listMissedClasses($filter);

if ($searchTerm !== '') {
    $needle = mb_strtolower($searchTerm, 'UTF-8');
    $rows = array_values(array_filter($rows, function($r) use ($needle) {
        $hay = mb_strtolower(implode(' ', [
            $r['subject_name']   ?? '',
            $r['class_name']     ?? '',
            $r['section']        ?? '',
            $r['teacher_name']   ?? '',
            $r['faculty_name']   ?? '',
            $r['department_name']?? '',
            $r['reason']         ?? '',
            $r['missed_date']    ?? '',
        ]), 'UTF-8');
        return mb_strpos($hay, $needle) !== false;
    }));
}

$options  = reportFilterOptions(['faculty_id' => currentFacultyScopeId()]);
$teachers = [];
$canSeeTeachers = in_array($roleId, [ROLE_ADMIN, ROLE_DEPT_HEAD, ROLE_ATT_MGR], true);
if ($canSeeTeachers) {
    $stmt = $db->prepare("SELECT id, full_name FROM users WHERE role_id=? AND status='active' AND deleted_at IS NULL"
        . (currentFacultyScopeId() ? " AND faculty_id=?" : "")
        . " ORDER BY full_name");
    if (currentFacultyScopeId()) { $stmt->execute([ROLE_TEACHER, currentFacultyScopeId()]); }
    else { $stmt->execute([ROLE_TEACHER]); }
    $teachers = $stmt->fetchAll();
}

$activeFilterCount = 0;
foreach (['subject_id','teacher_id','class_id','department_id','faculty_id'] as $k) {
    if (!empty($filter[$k])) $activeFilterCount++;
}
if ($searchTerm !== '') $activeFilterCount++;
if (!empty($_GET['from']) || !empty($_GET['to'])) $activeFilterCount++;

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="fas fa-circle-xmark me-2 text-danger"></i><?=t('missed_classes')?></h5>
  <span class="badge bg-danger fs-6"><?=count($rows)?></span>
</div>

<form method="GET" class="card mb-3 shadow-sm" id="missedFilterForm">
  <div class="card-body py-3">

    <!-- Search bar (always visible) -->
    <div class="row g-2 align-items-end mb-2">
      <div class="col-md-9">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="fas fa-search"></i></span>
          <input type="text" name="q" value="<?=h($searchTerm)?>"
                 class="form-control" placeholder="<?=h(t('search'))?>…"
                 autocomplete="off"/>
          <?php if ($searchTerm !== ''): ?>
            <a href="<?=BASE_URL?>/missed/index.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i></a>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-3 d-grid d-md-flex justify-content-md-end gap-2">
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#missedAdvFilters"
                aria-expanded="<?=$activeFilterCount>0?'true':'false'?>">
          <i class="fas fa-sliders me-1"></i><?=t('filter')?>
          <?php if ($activeFilterCount > 0): ?>
            <span class="badge bg-primary ms-1"><?=$activeFilterCount?></span>
          <?php endif; ?>
        </button>
        <button class="btn btn-sm btn-primary" type="submit">
          <i class="fas fa-magnifying-glass me-1"></i><?=t('apply')?>
        </button>
      </div>
    </div>

    <!-- Advanced filters (collapsible) -->
    <div class="collapse <?=$activeFilterCount>0?'show':''?>" id="missedAdvFilters">
      <hr class="my-2"/>
      <div class="row g-2 align-items-end">

        <?php if (!empty($options['subjects'])): ?>
        <div class="col-md-3 col-sm-6">
          <label class="form-label small mb-1"><i class="fas fa-book me-1 text-secondary"></i><?=t('subject')?></label>
          <select name="subject_id" class="form-select form-select-sm">
            <option value="">— <?=t('all')?> —</option>
            <?php foreach ($options['subjects'] as $s): ?>
              <option value="<?=$s['id']?>" <?=$filter['subject_id']===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($teachers): ?>
        <div class="col-md-3 col-sm-6">
          <label class="form-label small mb-1"><i class="fas fa-user-tie me-1 text-secondary"></i><?=t('teacher')?></label>
          <select name="teacher_id" class="form-select form-select-sm">
            <option value="">— <?=t('all')?> —</option>
            <?php foreach ($teachers as $tch): ?>
              <option value="<?=$tch['id']?>" <?=$filter['teacher_id']===(int)$tch['id']?'selected':''?>><?=h($tch['full_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($roleId !== ROLE_STUDENT && !empty($options['classes'])): ?>
        <div class="col-md-3 col-sm-6">
          <label class="form-label small mb-1"><i class="fas fa-chalkboard me-1 text-secondary"></i><?=t('class')?></label>
          <select name="class_id" class="form-select form-select-sm">
            <option value="">— <?=t('all')?> —</option>
            <?php foreach ($options['classes'] as $c): ?>
              <option value="<?=$c['id']?>" <?=$filter['class_id']===(int)$c['id']?'selected':''?>><?=h(trim($c['name'].' '.$c['section']))?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if (in_array($roleId,[ROLE_ADMIN,ROLE_DEPT_HEAD,ROLE_ATT_MGR],true) && !empty($options['departments'])): ?>
        <div class="col-md-3 col-sm-6">
          <label class="form-label small mb-1"><i class="fas fa-building me-1 text-secondary"></i><?=t('department')?></label>
          <select name="department_id" class="form-select form-select-sm">
            <option value="">— <?=t('all')?> —</option>
            <?php foreach ($options['departments'] as $d): ?>
              <option value="<?=$d['id']?>" <?=$filter['department_id']===(int)$d['id']?'selected':''?>><?=h($d['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($roleId === ROLE_ADMIN && !empty($options['faculties'])): ?>
        <div class="col-md-3 col-sm-6">
          <label class="form-label small mb-1"><i class="fas fa-building-columns me-1 text-secondary"></i><?=t('faculty')?></label>
          <select name="faculty_id" class="form-select form-select-sm">
            <option value="">— <?=t('all')?> —</option>
            <?php foreach ($options['faculties'] as $f): ?>
              <option value="<?=$f['id']?>" <?=$filter['faculty_id']===(int)$f['id']?'selected':''?>><?=h($f['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="col-md-2 col-sm-6">
          <label class="form-label small mb-1"><?=t('from')?></label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?=h($filter['from'])?>"/>
        </div>
        <div class="col-md-2 col-sm-6">
          <label class="form-label small mb-1"><?=t('to')?></label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?=h($filter['to'])?>"/>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 mt-1">
          <a href="<?=BASE_URL?>/missed/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-rotate-left me-1"></i><?=t('reset')?>
          </a>
        </div>
      </div>
    </div>

  </div>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th style="width:48px">#</th>
          <th><?=t('missed_class_date')?></th>
          <th><?=t('class')?></th>
          <th><?=t('missed_class_subject')?></th>
          <?php if ($roleId !== ROLE_TEACHER): ?><th><?=t('teacher')?></th><?php endif; ?>
          <?php if (in_array($roleId,[ROLE_ADMIN,ROLE_DEPT_HEAD,ROLE_ATT_MGR],true)): ?><th><?=t('department')?></th><?php endif; ?>
          <th class="text-center"><?=t('missed_class_credits')?></th>
          <th><?=t('missed_class_reason')?></th>
          <th class="text-center" style="width:64px"><i class="fas fa-eye"></i></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows):
          $colspan = 7
            + ($roleId !== ROLE_TEACHER ? 1 : 0)
            + (in_array($roleId,[ROLE_ADMIN,ROLE_DEPT_HEAD,ROLE_ATT_MGR],true) ? 1 : 0);
        ?>
          <tr><td colspan="<?=$colspan?>" class="text-center text-muted py-5">
            <i class="fas fa-circle-check fa-2x text-success mb-2 d-block"></i>
            <?=t('no_records')?>
          </td></tr>
        <?php endif; ?>
        <?php
        // REQ: تر ۷ Dayو پورې استاد کولی شي خپلې تیرې خاضرۍ (د missed په شمول) ایډیټ کړي.
        // د دې جدول د هر رديف لپاره د تیارولو لپاره چوکائۍ:
        //   - استاد → د ده کریډیټ (timetable session) پاړتې ته لاړۍ — ایډیټ د ۷ Dayو په حد پورې.
        //   - مدیر/admin/dept → د سیشن تفصیل پاړتې ته.
        foreach ($rows as $i => $r):
            $detailLink = BASE_URL . ($roleId === ROLE_ADMIN ? '/admin/session_detail.php' : '/manager/session_detail.php')
                        . '?id=' . (int)$r['session_id'];
            // د استاد لپاره د کریډیټ (session) پاړتې لورو لینک:
            $teacherEditLink = (!empty($r['timetable_id']) && !empty($r['missed_date']))
                ? (BASE_URL.'/teacher/sessions.php?view='.(int)$r['timetable_id'].'&date='.h($r['missed_date']))
                : '';
            $within7Days = !empty($r['missed_date']) && sessionEditableByTeacher((string)$r['missed_date']);
        ?>
        <tr<?= ($roleId === ROLE_TEACHER && $within7Days) ? ' class="table-warning"' : '' ?>>
          <td class="text-muted"><?=$i+1?></td>
          <td>
            <?php if ($roleId === ROLE_TEACHER && $teacherEditLink !== ''): ?>
              <a href="<?=h($teacherEditLink)?>" class="text-decoration-none" title="<?= $within7Days ? h(t('edit') ?: 'Edit') : h(t('view') ?: 'View') ?>">
                <span class="badge bg-light text-dark border"><?=h($r['missed_date'])?></span>
                <?php if ($within7Days): ?>
                  <span class="badge bg-warning text-dark ms-1" title="د ۷ Dayو د ایډیټ کړکۍ په حد کې"><i class="fas fa-pen"></i></span>
                <?php endif; ?>
              </a>
            <?php else: ?>
              <span class="badge bg-light text-dark border"><?=h($r['missed_date'])?></span>
            <?php endif; ?>
          </td>
          <td><strong><?=h($r['class_name'])?></strong> <span class="text-muted small"><?=h($r['section'])?></span></td>
          <td><?=h($r['subject_name'])?></td>
          <?php if ($roleId !== ROLE_TEACHER): ?><td><?=h($r['teacher_name'])?></td><?php endif; ?>
          <?php if (in_array($roleId,[ROLE_ADMIN,ROLE_DEPT_HEAD,ROLE_ATT_MGR],true)): ?>
            <td class="small text-muted"><?=h($r['department_name'] ?? '—')?></td>
          <?php endif; ?>
          <td class="text-center"><span class="badge bg-secondary"><?=(int)$r['credits']?></span></td>
          <td class="small text-muted"><?=h($r['reason'] ?: '—')?></td>
          <td class="text-center">
            <?php if ($roleId === ROLE_TEACHER && $teacherEditLink !== ''): ?>
              <?php if ($within7Days): ?>
                <a href="<?=h($teacherEditLink)?>" class="btn btn-sm btn-outline-primary" title="<?=h(t('edit') ?: 'Edit')?>">
                  <i class="fas fa-pen"></i>
                </a>
              <?php else: ?>
                <a href="<?=h($teacherEditLink)?>" class="btn btn-sm btn-outline-secondary" title="<?=h(t('view') ?: 'View')?> — د ایډیټ کړکۍ (۷ Dayې) تیره ده">
                  <i class="fas fa-lock"></i>
                </a>
              <?php endif; ?>
            <?php elseif (in_array($roleId,[ROLE_ADMIN,ROLE_DEPT_HEAD,ROLE_ATT_MGR],true) && !empty($r['session_id'])): ?>
              <a href="<?=h($detailLink)?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
