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
$pageTitle = 'Announcements';

if ($facultyId <= 0) {
    flashSet('danger', 'Your account is not linked to any faculty. Please contact the administrator.');
    redirect(BASE_URL.'/dean/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_announcement'])) {
    verifyCsrf();
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body'] ?? '');
    $audience = $_POST['audience'] ?? 'all';
    $expires  = trim($_POST['expires_at'] ?? '');

    // Dean can only target audiences inside their own faculty
    $allowed  = ['all','students','teachers','managers'];
    if (!in_array($audience, $allowed, true)) $audience = 'all';

    if ($title !== '' && $body !== '') {

        // Always scope the announcement to this dean's faculty
        $db->prepare("INSERT INTO announcements (title, body, audience, faculty_id, created_by, expires_at)
                      VALUES (?,?,?,?,?,?)")
           ->execute([$title, $body, $audience, $facultyId, $_SESSION['user_id'], $expires ?: null]);

        // Pick the recipient user IDs, but ONLY those who belong to this faculty
        switch ($audience) {
            case 'students':
                $stmt = $db->prepare("SELECT id FROM users WHERE role_id=".ROLE_STUDENT."
                                        AND status='active' AND deleted_at IS NULL AND faculty_id=?");
                $stmt->execute([$facultyId]);
                break;
            case 'teachers':
                $stmt = $db->prepare("SELECT id FROM users WHERE role_id=".ROLE_TEACHER."
                                        AND status='active' AND deleted_at IS NULL AND faculty_id=?");
                $stmt->execute([$facultyId]);
                break;
            case 'managers':
                $stmt = $db->prepare("SELECT id FROM users WHERE role_id=".ROLE_ATT_MGR."
                                        AND status='active' AND deleted_at IS NULL AND faculty_id=?");
                $stmt->execute([$facultyId]);
                break;
            case 'all':
            default:
                $stmt = $db->prepare("SELECT id FROM users
                                       WHERE status='active' AND deleted_at IS NULL
                                         AND faculty_id=?
                                         AND role_id IN (".ROLE_STUDENT.",".ROLE_TEACHER.",".ROLE_ATT_MGR.")");
                $stmt->execute([$facultyId]);
                break;
        }
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $uid) {
            createNotification((int)$uid, '📢 '.$title, $body, 'info', BASE_URL.'/notifications/announcements.php');
        }

        auditLog('dean_announcement_create','announcements', null, "Dean announcement → faculty {$facultyId} / {$audience}");
        flashSet('success', '✅ Announcement saved successfully.');
        if (function_exists('cacheBustDashboards')) cacheBustDashboards();
    } else {
        flashSet('warning', '⚠ Title and body are both required.');
    }
    redirect(BASE_URL.'/dean/announcements.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        // Only allow deleting announcements that belong to this dean's faculty
        $stmt = $db->prepare("DELETE FROM announcements WHERE id=? AND faculty_id=?");
        $stmt->execute([$id, $facultyId]);
        if ($stmt->rowCount() > 0) {
            auditLog('dean_announcement_delete','announcements', $id, 'Dean deleted announcement');
            flashSet('success', '✅ Announcement deleted.');
            if (function_exists('cacheBustDashboards')) cacheBustDashboards();
        } else {
            flashSet('warning', 'Announcement not found or not owned by your faculty.');
        }
    }
    redirect(BASE_URL.'/dean/announcements.php');
}

try {
    $db->exec("DELETE FROM announcements WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
} catch (Throwable $e) { /* ignore */ }

// Only list announcements owned by this faculty (i.e. created by this dean / for this faculty)
$listStmt = $db->prepare("SELECT a.*, u.full_name AS creator, f.name AS faculty_name
                            FROM announcements a
                       LEFT JOIN users u     ON u.id = a.created_by
                       LEFT JOIN faculties f ON f.id = a.faculty_id
                           WHERE a.faculty_id = ?
                        ORDER BY a.created_at DESC LIMIT 100");
$listStmt->execute([$facultyId]);
$rows = $listStmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fas fa-bullhorn me-2 text-primary"></i>Announcements</h5>
  <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2">
    <i class="fas fa-building-columns me-1"></i>For my faculty
  </span>
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
            <input type="text" name="title" class="form-control form-control-sm" required maxlength="255"/>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Content</label>
            <textarea name="body" rows="5" class="form-control form-control-sm" required></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small mb-1">Recipients</label>
              <select name="audience" class="form-select form-select-sm">
                <option value="all">All (my faculty)</option>
                <option value="students">Students</option>
                <option value="teachers">Teachers</option>
                <option value="managers">Managers</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small mb-1">Expiry Date (Optional)</label>
              <input type="datetime-local" name="expires_at" class="form-control form-control-sm"/>
            </div>
          </div>
          <div class="alert alert-info small py-2 mb-2">
            <i class="fas fa-info-circle me-1"></i>
            دا اعلان به یوازې ستاسو د خپل پوهنځي یوزرانو ته خپور شي.
          </div>
          <button class="btn btn-primary w-100"><i class="fas fa-paper-plane me-1"></i>Publish</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list me-2"></i>Previous Announcements (<?= count($rows) ?>)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr><th>Title</th><th>Recipients</th><th>When</th><th>By</th><th></th></tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No announcements found.</td></tr>
          <?php else: foreach ($rows as $a): ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= h($a['title']) ?></div>
                <div class="small text-muted"><?= h(mb_substr((string)$a['body'], 0, 90)) ?><?= mb_strlen((string)$a['body'])>90?'…':'' ?></div>
                <?php if (!empty($a['faculty_name'])): ?>
                  <div class="small text-primary"><i class="fas fa-building-columns me-1"></i><?= h($a['faculty_name']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge bg-secondary"><?= h($a['audience']) ?></span></td>
              <td class="small"><?= h(date('d M, H:i', strtotime($a['created_at']))) ?></td>
              <td class="small"><?= h($a['creator'] ?? '—') ?></td>
              <td class="text-end">
                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete?');">
                  <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>"/>
                  <input type="hidden" name="delete_announcement" value="1"/>
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>"/>
                  <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
