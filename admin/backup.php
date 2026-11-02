<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_ADMIN]);
$db = getDB();
$pageTitle = 'د ډیټابیس بیک اپ';

function backupCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

function performManualBackup(string $reason = 'manual'): array {

    if (function_exists('pusamsPerformAutoBackup')) {
        return pusamsPerformAutoBackup($reason);
    }
    
    throw new RuntimeException('Backup helper (pusamsPerformAutoBackup) is not loaded.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'manual_backup') {
        try {
            $r = performManualBackup('manual');
            $msg = "✅ بیک اپ بریالی شو: {$r['filename']} (".round($r['size']/1024,1)." KB)";
            // V21 — که چېرته چونۍ جدولونه پرې شوی وی، ادمین ته خبر ورکوو تر څو
            // فکر ونه کوی چې فایل بشپړ دی.
            if (!empty($r['skipped_tables'])) {
                $msg .= ' ⚠ پرې شوی جدولونه: '.implode(', ', $r['skipped_tables']);
            }
            flashSet('success', $msg);
        } catch (Throwable $e) {
            // V21 — تر بریدی وروسته دیاګنوس د پاره PHP error_log ته پوره trace لیکو.
            error_log('[PUSAMS manual backup failed] '.$e->getMessage()."\n".$e->getTraceAsString());
            flashSet('danger', '❌ Backup error: '.$e->getMessage());
        }
        redirect(BASE_URL.'/admin/backup.php');
    }
    elseif ($action === 'save_schedule') {
        $frequency = $_POST['frequency'] ?? 'daily';
        $time      = $_POST['time_of_day'] ?? '02:00';
        $dow       = (int)($_POST['day_of_week'] ?? 0);
        $enabled   = isset($_POST['is_enabled']) ? 1 : 0;
        if (!in_array($frequency, ['daily','weekly','monthly','semester'], true)) $frequency = 'daily';

        // FIX 19d — the UI offers a 'semester' frequency but older schemas
        // only had ('daily','weekly','monthly'). If the admin picks 'semester'
        // on a stale DB the INSERT/UPDATE would silently coerce or fail.
        // Detect and widen the enum once, on the fly.
        if ($frequency === 'semester') {
            try {
                $col = $db->query("SHOW COLUMNS FROM backup_schedule LIKE 'frequency'")->fetch();
                if ($col && isset($col['Type']) && stripos($col['Type'], "'semester'") === false) {
                    $db->exec("ALTER TABLE backup_schedule MODIFY COLUMN `frequency` ENUM('daily','weekly','monthly','semester') DEFAULT 'daily'");
                }
            } catch (Throwable $e) {
                error_log('[PUSAMS backup_schedule enum widen failed] '.$e->getMessage());
            }
        }

        $exists = $db->query("SELECT id FROM backup_schedule LIMIT 1")->fetchColumn();
        if ($exists) {
            $db->prepare("UPDATE backup_schedule SET frequency=?, time_of_day=?, day_of_week=?, is_enabled=?, updated_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$frequency, $time.':00', $dow, $enabled, $_SESSION['user_id'], $exists]);
        } else {
            $db->prepare("INSERT INTO backup_schedule (frequency, time_of_day, day_of_week, is_enabled, updated_by) VALUES (?,?,?,?,?)")
               ->execute([$frequency, $time.':00', $dow, $enabled, $_SESSION['user_id']]);
        }
        auditLog('backup_schedule_saved','backup', null, "Schedule: {$frequency} @ {$time} (enabled={$enabled})");
        flashSet('success', '✅ Backup schedule saved.');
        redirect(BASE_URL.'/admin/backup.php');
    }
    elseif ($action === 'delete_backup') {
        $bid = (int)($_POST['backup_id'] ?? 0);
        $row = $db->prepare("SELECT * FROM backup_history WHERE id=?");
        $row->execute([$bid]); $row = $row->fetch();
        if ($row) {
            $f = rtrim(BACKUP_DIR,'/').'/'.$row['filename'];
            if (file_exists($f)) @unlink($f);
            $db->prepare("DELETE FROM backup_history WHERE id=?")->execute([$bid]);
            auditLog('backup_deleted','backup',$bid,"Removed {$row['filename']}");
            flashSet('success','✅ Backup file deleted.');
        }
        redirect(BASE_URL.'/admin/backup.php');
    }
}

$schedule = $db->query("SELECT * FROM backup_schedule LIMIT 1")->fetch() ?: [
    'frequency'=>'daily','time_of_day'=>'02:00:00','day_of_week'=>0,'is_enabled'=>0,'last_run'=>null
];

$history = $db->query("SELECT bh.*, u.full_name AS created_by_name
                       FROM backup_history bh
                       LEFT JOIN users u ON u.id=bh.created_by
                       ORDER BY bh.created_at DESC LIMIT 30")->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><i class="fas fa-database me-2 text-primary"></i>System Backup</h4>
  </div>
</div>

<div class="row g-3">
  <!-- ═══ Manual Backup ═══ -->
  <div class="col-lg-4">
    <div class="card border-primary border-2 shadow-sm h-100">
      <div class="card-header bg-primary text-white">
        <h6 class="mb-0"><i class="fas fa-download me-2"></i>Manual Backup</h6>
      </div>
      <div class="card-body text-center">
        <i class="fas fa-cloud-download-alt fa-3x text-primary mb-3"></i>
        <form method="POST">
          <?php backupCsrf(); ?>
          <input type="hidden" name="action" value="manual_backup"/>
          <button class="btn btn-primary"><i class="fas fa-save me-1"></i>Take Backup Now</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Automatic Backup Schedule -->
  <div class="col-lg-8">
    <div class="card border-success border-2 shadow-sm h-100">
      <div class="card-header bg-success text-white">
        <h6 class="mb-0"><i class="fas fa-clock me-2"></i>Automatic Backup Schedule</h6>
      </div>
      <div class="card-body">
        <form method="POST">
          <?php backupCsrf(); ?>
          <input type="hidden" name="action" value="save_schedule"/>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Backup Frequency</label>
              <select name="frequency" class="form-select">
                <option value="daily"    <?= $schedule['frequency']==='daily'?'selected':'' ?>>📅 Daily</option>
                <option value="weekly"   <?= $schedule['frequency']==='weekly'?'selected':'' ?>>📆 Weekly</option>
                <option value="monthly"  <?= $schedule['frequency']==='monthly'?'selected':'' ?>>🗓️ Monthly</option>
                <option value="semester" <?= $schedule['frequency']==='semester'?'selected':'' ?>>🎓 Per Semester</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Time</label>
              <input type="time" name="time_of_day" class="form-control" value="<?= h(substr($schedule['time_of_day'],0,5)) ?>"/>
            </div>
            <div class="col-md-3">
              <label class="form-label">Day of Week</label>
              <select name="day_of_week" class="form-select">
                <?php foreach (DAYS_OF_WEEK as $i => $d): ?>
                  <option value="<?= $i ?>" <?= (int)$schedule['day_of_week']===$i?'selected':'' ?>><?= h($d) ?></option>
                <?php endforeach; ?>
              </select>
              
            </div>
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="schedEnabled" name="is_enabled" <?= !empty($schedule['is_enabled'])?'checked':'' ?>/>
                <label class="form-check-label" for="schedEnabled"><strong>Enable Automatic Backup</strong></label>
              </div>
            </div>
            <div class="col-12">
              <button class="btn btn-success"><i class="fas fa-save me-1"></i>Save Schedule</button>
              <?php if (!empty($schedule['last_run'])): ?>
                <span class="text-muted small ms-3">
                  <i class="fas fa-history me-1"></i>Last run: <?= h(date('d M Y H:i', strtotime($schedule['last_run']))) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Backup History -->
<div class="card mt-4 shadow-sm">
  <div class="card-header bg-light">
    <h6 class="mb-0"><i class="fas fa-history me-2 text-secondary"></i>Backup History (Last 30)</h6>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr><th>#</th><th>File Name</th><th>Size</th><th>Type</th><th>Created By</th><th>Date</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php if (!$history): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No backups taken yet.</td></tr>
        <?php else: foreach ($history as $i => $b): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><code><?= h($b['filename']) ?></code></td>
            <td><?= round((int)$b['file_size']/1024,1) ?> KB</td>
            <td>
              <?php if ($b['backup_type']==='manual'): ?>
                <span class="badge bg-primary">Manual</span>
              <?php elseif ($b['backup_type']==='scheduled'): ?>
                <span class="badge bg-success">Automatic</span>
              <?php else: ?>
                <span class="badge bg-secondary">Restored</span>
              <?php endif; ?>
            </td>
            <td><?= h($b['created_by_name'] ?? 'System') ?></td>
            <td><?= h(date('d M Y H:i', strtotime($b['created_at']))) ?></td>
            <td>
              <a href="<?= BASE_URL ?>/backups/<?= h($b['filename']) ?>" class="btn btn-sm btn-outline-success" download><i class="fas fa-download"></i></a>
              <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this backup?');">
                <?php backupCsrf(); ?>
                <input type="hidden" name="action" value="delete_backup"/>
                <input type="hidden" name="backup_id" value="<?= (int)$b['id'] ?>"/>
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
