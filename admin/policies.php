<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_ADMIN]);
$db = getDB();
$pageTitle = t('system_policies');

$settingsTbl = function_exists('settingsTable') ? settingsTable() : 'settings_and_policy';

function policiesCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

$ALLOWED_KEYS = ['semester_start_date', 'semester_weeks'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_policies'])) {
    verifyCsrf();
    $semesterStartChanged = false;

    $newStartDate = '';

    try {
        $oldStmt = $db->prepare("SELECT `value` FROM `{$settingsTbl}` WHERE `key`='semester_start_date'");
        $oldStmt->execute();
        $oldStart = (string)($oldStmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        flashSet('danger', '❌ Cannot access settings table: '.h($e->getMessage()));
        redirect(BASE_URL.'/admin/policies.php');
    }

    foreach ($ALLOWED_KEYS as $polKey) {
        $postKey = 'pol_' . $polKey;
        if (!isset($_POST[$postKey])) continue;
        $cleanVal = trim((string)$_POST[$postKey]);

        if ($polKey === 'semester_start_date') {
            if ($cleanVal === '') {
                flashSet('danger', '❌ Semester Start Date is required.');
                redirect(BASE_URL.'/admin/policies.php');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleanVal) || !strtotime($cleanVal)) {
                flashSet('danger', '❌ Semester Start Date must be in YYYY-MM-DD format.');
                redirect(BASE_URL.'/admin/policies.php');
            }
            if ($cleanVal !== $oldStart) $semesterStartChanged = true;
            $newStartDate = $cleanVal;
        }

        if ($polKey === 'semester_weeks') {
            $weeks = (int)$cleanVal;
            if ($weeks < 1 || $weeks > 52) {
                flashSet('danger', '❌ Semester Weeks must be between 1 and 52.');
                redirect(BASE_URL.'/admin/policies.php');
            }
            $cleanVal = (string)$weeks;
        }

        
        
        try {
            $existsStmt = $db->prepare("SELECT 1 FROM `{$settingsTbl}` WHERE `key`=?");
            $existsStmt->execute([$polKey]);
            if ($existsStmt->fetchColumn()) {
                try {
                    $db->prepare("UPDATE `{$settingsTbl}` SET `value`=?, updated_by=? WHERE `key`=?")
                       ->execute([$cleanVal, $_SESSION['user_id'] ?? null, $polKey]);
                } catch (Throwable $colE) {
                    
                    $db->prepare("UPDATE `{$settingsTbl}` SET `value`=? WHERE `key`=?")
                       ->execute([$cleanVal, $polKey]);
                }
            } else {
                try {
                    $db->prepare("INSERT INTO `{$settingsTbl}` (`key`,`value`,`group`,`updated_by`) VALUES (?,?,?,?)")
                       ->execute([$polKey, $cleanVal, 'academic', $_SESSION['user_id'] ?? null]);
                } catch (Throwable $colE) {
                    $db->prepare("INSERT INTO `{$settingsTbl}` (`key`,`value`,`group`) VALUES (?,?,?)")
                       ->execute([$polKey, $cleanVal, 'academic']);
                }
            }
        } catch (Throwable $e) {
            flashSet('danger', '❌ Database error saving policies: '.h($e->getMessage()));
            redirect(BASE_URL.'/admin/policies.php');
        }
    }
    auditLog('vc_policies_save','policies', null, 'VC updated semester policies (semester_start_date / semester_weeks)');

    if ($semesterStartChanged) {
        try {
            $db->prepare("UPDATE `{$settingsTbl}` SET `value`='' WHERE `key`='semester_backfill_last_date'")->execute();
        } catch (Throwable $e) {  }

        if (function_exists('backfillMissedSessionsFromSemesterStart')) {
            try {
                $result = backfillMissedSessionsFromSemesterStart(true);
                $msg = sprintf(
                    '✅ Policies وساتل شوې. د سمیستر له پیل نېټې (%s) څخه backfill بشپړ شو: %d Dayې، %d سیشنونه میس شول، %d Students حاضر ثبت شول.',
                    h($newStartDate ?: '—'),
                    (int)($result['days'] ?? 0),
                    (int)($result['missed_sessions'] ?? 0),
                    (int)($result['present_records'] ?? $result['absent_records'] ?? 0)
                );
                flashSet('success', $msg);
            } catch (Throwable $e) {
                flashSet('warning', '⚠️ Policies saved but backfill error: '.h($e->getMessage()));
            }
        } else {
            flashSet('success', '✅ Policies saved successfully.');
        }
    } else {
        flashSet('success', '✅ Policies saved successfully.');
    }
    if (function_exists('cacheBustDashboards')) cacheBustDashboards();
    redirect(BASE_URL.'/admin/policies.php');
}

$values = [];
$descs  = [
    'semester_start_date' => 'Current semester start date (YYYY-MM-DD).',
    'semester_weeks'      => 'Semester Weeks (1-52).',
];
try {
    $ph = implode(',', array_fill(0, count($ALLOWED_KEYS), '?'));
    $stmt = $db->prepare("SELECT `key`, `value` FROM `{$settingsTbl}` WHERE `key` IN ($ph)");
    $stmt->execute($ALLOWED_KEYS);
    foreach ($stmt->fetchAll() as $row) {
        $values[$row['key']] = (string)($row['value'] ?? '');
    }
} catch (Throwable $e) {
    flashSet('warning', '⚠️ Could not read existing policies: '.h($e->getMessage()));
}
$semesterStartVal = $values['semester_start_date'] ?? '';
$semesterWeeksVal = $values['semester_weeks']      ?? '16';

$semesterStatus = function_exists('getSemesterStatus') ? getSemesterStatus() : null;

require_once __DIR__.'/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-alt me-2 text-primary"></i>Semester Policies</h4>
  </div>
</div>

<form method="POST">
  <?php policiesCsrf(); ?>
  <input type="hidden" name="save_policies" value="1"/>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom">
      <h6 class="mb-1"><i class="fas fa-school me-2 text-success"></i>Academic Policies</h6>
    </div>
    <div class="card-body">
      <div class="row g-3">

        <!-- 1) Semester Start Date -->
        <div class="col-md-6">
          <label class="form-label fw-semibold mb-1">
            <i class="fas fa-calendar-day text-success me-1"></i>Semester Start Date
            <span class="text-danger">*</span>
          </label>
          <input type="date" name="pol_semester_start_date"
                 class="form-control form-control-sm"
                 value="<?= h($semesterStartVal) ?>" required/>
          <div class="form-text small text-muted">
            <?= h($descs['semester_start_date'] ?? 'Current semester start date (YYYY-MM-DD).') ?>
          </div>
        </div>

        <!-- Semester Weeks -->
        <div class="col-md-6">
          <label class="form-label fw-semibold mb-1">
            <i class="fas fa-calendar-week text-success me-1"></i>Semester Weeks
            <span class="text-danger">*</span>
          </label>
          <input type="number" name="pol_semester_weeks"
                 class="form-control form-control-sm"
                 value="<?= h($semesterWeeksVal) ?>"
                 min="1" max="52" required/>
        </div>

        <!-- Semester Current Status Card -->
        <div class="col-12">
          <?php if ($semesterStatus && !empty($semesterStatus['start_date'])): ?>
            <div class="card border-success bg-light">
              <div class="card-body p-3">
                <div class="small fw-bold text-success mb-2">
                  <i class="fas fa-info-circle me-1"></i>Semester Current Status
                </div>
                <div class="row g-2 small">
                  <div class="col-md-3"><strong>Start:</strong> <?= h($semesterStatus['start_date']) ?></div>
                  <div class="col-md-3"><strong>End (calculated):</strong> <?= h($semesterStatus['end_date']) ?></div>
                  <div class="col-md-3"><strong>Total Weeks:</strong> <?= (int)$semesterStatus['weeks_total'] ?></div>
                  <div class="col-md-3"><strong>Weeks passed:</strong> <?= (int)$semesterStatus['weeks_passed'] ?> / <?= (int)$semesterStatus['weeks_total'] ?></div>
                </div>
                <div class="progress mt-2" style="height:8px;">
                  <div class="progress-bar bg-success" style="width:<?= (float)$semesterStatus['progress_pct'] ?>%"></div>
                </div>
                <div class="text-end small mt-1"><strong><?= (float)$semesterStatus['progress_pct'] ?>%</strong> completed</div>
              </div>
            </div>
          <?php else: ?>
            <div class="alert alert-warning small mb-0 py-2">
              <i class="fas fa-exclamation-triangle me-1"></i>
              لومړی Semester Start Date وټاکئ. کله چې وساتل شي، سیستم به Automatic د هغې نېټې څخه تر اوسني پورې ټول د حضور سیشنونه جوړ کړي.
              Sessions with no recorded attendance will count all students as <strong>Present</strong> (missed class rule).
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Policies</button>
    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary">Back</a>
  </div>
</form>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
