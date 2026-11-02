<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
redirectLegacyDeanManagerRoute();
requireRole([ROLE_DEPT_HEAD]);

$db = getDB();
$pageTitle = 'Faculty Announcements';
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_announcement'])) {
    verifyCsrf();
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body'] ?? '');
    $audience = $_POST['audience'] ?? 'all';
    $expires  = trim($_POST['expires_at'] ?? '');
    $allowed  = ['all','students','teachers','managers'];
    if (!in_array($audience, $allowed, true)) $audience = 'all';

    if ($title !== '' && $body !== '' && $facultyId > 0) {
        
        $facultyName = $db->prepare("SELECT name FROM faculties WHERE id=?");
        $facultyName->execute([$facultyId]);
        $fname = (string)($facultyName->fetchColumn() ?: '');
        $taggedTitle = '['.$fname.'] '.$title;

        $db->prepare("INSERT INTO announcements (title, body, audience, faculty_id, created_by, expires_at)
                      VALUES (?,?,?,?,?,?)")
           ->execute([$taggedTitle, $body, $audience, $facultyId, $userId, $expires ?: null]);

        $audienceSql = match ($audience) {
            'students' => "role_id=".ROLE_STUDENT,
            'teachers' => "role_id=".ROLE_TEACHER,
            'managers' => "role_id IN (".ROLE_ATT_MGR.")",
            default    => "role_id IN (".ROLE_STUDENT.",".ROLE_TEACHER.",".ROLE_ATT_MGR.")",
        };
        $stmt = $db->prepare("SELECT id FROM users WHERE faculty_id=? AND status='active' AND deleted_at IS NULL AND $audienceSql");
        $stmt->execute([$facultyId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $uid) {
            createNotification((int)$uid, '📢 '.$taggedTitle, $body, 'info', BASE_URL.'/notifications/announcements.php');
        }
        auditLog('faculty_announcement_create','announcements', null, "Faculty Dean ($fname) → ".count($ids)." users");
        flashSet('success', '✅ Faculty announcement saved successfully (delivered to '.count($ids).' user(s)).');
        if (function_exists('cacheBustDashboards')) cacheBustDashboards();
    } else {
        flashSet('warning', '⚠ Title, body and faculty assignment are required.');
    }
    redirect(BASE_URL.'/manager/announcements.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        
        $db->prepare("DELETE FROM announcements WHERE id=? AND created_by=?")->execute([$id, $userId]);
        auditLog('faculty_announcement_delete','announcements', $id, 'Faculty Dean deleted own announcement');
        flashSet('success', '✅ Announcement deleted.');
        if (function_exists('cacheBustDashboards')) cacheBustDashboards();
    }
    redirect(BASE_URL.'/manager/announcements.php');
}

try {
    $db->exec("DELETE FROM announcements WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
} catch (Throwable $e) {  }

$rows = $db->prepare("SELECT a.*, u.full_name AS creator
                    FROM announcements a
                    LEFT JOIN users u ON u.id=a.created_by
                    WHERE a.created_by=?
                    ORDER BY a.created_at DESC LIMIT 100");
$rows->execute([$userId]);
$rows = $rows->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h5 class="mb-0"><i class="fas fa-bullhorn me-2 text-primary"></i><?= t('announcements') ?></h5>
    
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="fas fa-plus me-2"></i>New Announcement</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>"/>
          <input type="hidden" name="save_announcement" value="1"/>
          <div class="mb-2">
            <label class="form-label small mb-1">Title</label>
            <input type="text" name="title" class="form-control form-control-sm" required maxlength="200"/>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Content</label>
            <textarea name="body" rows="5" class="form-control form-control-sm" required></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small mb-1">Recipients (Faculty Users)</label>
              <select name="audience" class="form-select form-select-sm">
                <option value="all">All Faculty Members</option>
                <option value="students">Students Only</option>
                <option value="teachers">Teachers Only</option>
                <option value="managers">Managers Only</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small mb-1">Expiry Date (Optional)</label>
              <input type="datetime-local" name="expires_at" class="form-control form-control-sm"/>
            </div>
          </div>
          <button class="btn btn-primary w-100"><i class="fas fa-paper-plane me-1"></i>Publish</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="fas fa-list me-2"></i>My Announcements</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead><tr><th>Title</th><th>Recipients</th><th>Date</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">No announcements yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['title']) ?></td>
              <td><span class="badge bg-secondary"><?= h($r['audience']) ?></span></td>
              <td><small><?= h(date('d M Y H:i', strtotime($r['created_at']))) ?></small></td>
              <td>
                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete?');">
                  <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>"/>
                  <input type="hidden" name="delete_announcement" value="1"/>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"/>
                  <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
