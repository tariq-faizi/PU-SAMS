<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin(); requireRole([ROLE_ADMIN]);
$pageTitle = t('missed_classes');
$db = getDB();

/*
 * NOTE: Missed-class records are immutable audit data — they reflect what actually
 * happened in the timetable and cannot be deleted by any role (Admin / VC / Dean /
 * Manager). Any historical delete endpoint has been removed by design. The action
 * column now offers a read-only View into the underlying session detail.
 */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    // Hard guard: no destructive action is permitted on missed_classes from this screen.
    flashSet('error', t('permission_denied', [], 'Action not permitted.'));
    redirect(BASE_URL.'/admin/missed_classes.php');
}

$filter = [
    'subject_id'    => (int)($_GET['subject_id'] ?? 0) ?: null,
    'teacher_id'    => (int)($_GET['teacher_id'] ?? 0) ?: null,
    'class_id'      => (int)($_GET['class_id'] ?? 0) ?: null,
    'department_id' => (int)($_GET['department_id'] ?? 0) ?: null,
    'faculty_id'    => (int)($_GET['faculty_id'] ?? 0) ?: null,
    'from'          => $_GET['from'] ?? '',
    'to'            => $_GET['to']   ?? '',
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

$options = reportFilterOptions([]);
$teachers = $db->query("SELECT id, full_name FROM users WHERE role_id=".ROLE_TEACHER." AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll();

$activeFilterCount = 0;
foreach (['subject_id','teacher_id','class_id','department_id','faculty_id'] as $k) {
    if (!empty($filter[$k])) $activeFilterCount++;
}
if ($searchTerm !== '') $activeFilterCount++;
if (!empty($filter['from']) || !empty($filter['to'])) $activeFilterCount++;

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="fas fa-circle-xmark me-2 text-danger"></i><?=t('missed_classes')?></h4>
  <span class="badge bg-danger fs-6"><?=count($rows)?></span>
</div>

<form method="GET" class="card mb-3 shadow-sm">
  <div class="card-body py-3">

    <div class="row g-2 align-items-end mb-2">
      <div class="col-md-9">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="fas fa-search"></i></span>
          <input type="text" name="q" value="<?=h($searchTerm)?>"
                 class="form-control" placeholder="<?=h(t('search'))?>…" autocomplete="off"/>
          <?php if ($searchTerm !== ''): ?>
            <a href="<?=BASE_URL?>/admin/missed_classes.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i></a>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-3 d-grid d-md-flex justify-content-md-end gap-2">
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#advFilters"
                aria-expanded="<?=$activeFilterCount>0?'true':'false'?>">
          <i class="fas fa-sliders me-1"></i><?=t('filter')?>
          <?php if ($activeFilterCount > 0): ?>
            <span class="badge bg-primary ms-1"><?=$activeFilterCount?></span>
          <?php endif; ?>
        </button>
        <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-magnifying-glass me-1"></i><?=t('apply')?></button>
      </div>
    </div>

    <div class="collapse <?=$activeFilterCount>0?'show':''?>" id="advFilters">
      <hr class="my-2"/>
      <div class="row g-2 align-items-end">

        <?php if (!empty($options['faculties'])): ?>
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

        <?php if (!empty($options['departments'])): ?>
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

        <?php if (!empty($options['classes'])): ?>
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

        <div class="col-md-3 col-sm-6">
          <label class="form-label small mb-1"><?=t('from')?></label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?=h($filter['from'])?>"/>
        </div>
        <div class="col-md-3 col-sm-6">
          <label class="form-label small mb-1"><?=t('to')?></label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?=h($filter['to'])?>"/>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 mt-1">
          <a href="<?=BASE_URL?>/admin/missed_classes.php" class="btn btn-sm btn-outline-secondary">
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
          <th><?=t('faculties')?></th>
          <th><?=t('department')?></th>
          <th><?=t('class')?></th>
          <th><?=t('missed_class_subject')?></th>
          <th><?=t('teacher')?></th>
          <th class="text-center"><?=t('missed_class_credits')?></th>
          <th><?=t('missed_class_reason')?></th>
          <th class="text-center" style="width:64px"><i class="fas fa-eye"></i></th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="10" class="text-center text-muted py-5">
            <i class="fas fa-circle-check fa-2x text-success mb-2 d-block"></i>
            <?=t('no_records')?>
          </td></tr>
        <?php endif; ?>
        <?php foreach($rows as $i=>$r): ?>
          <tr>
            <td class="text-muted"><?=$i+1?></td>
            <td><span class="badge bg-light text-dark border"><?=h($r['missed_date'])?></span></td>
            <td><?=h($r['faculty_name'])?></td>
            <td class="small text-muted"><?=h($r['department_name'] ?? '—')?></td>
            <td><strong><?=h($r['class_name'])?></strong> <span class="text-muted small"><?=h($r['section'] ?? '')?></span></td>
            <td><?=h($r['subject_name'])?></td>
            <td><?=h($r['teacher_name'])?></td>
            <td class="text-center"><span class="badge bg-secondary"><?=(int)$r['credits']?></span></td>
            <td class="small text-muted"><?=h($r['reason'] ?? '—')?></td>
            <td class="text-center">
              <?php if (!empty($r['session_id'])): ?>
                <a href="<?=BASE_URL?>/admin/session_detail.php?id=<?=(int)$r['session_id']?>"
                   class="btn btn-sm btn-outline-info"
                   title="<?=h(t('view', [], 'View'))?>">
                  <i class="fas fa-eye"></i>
                </a>
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
