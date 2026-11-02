<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();

$db = getDB();
$pageTitle = 'اعلانونه';
$roleId    = (int)($_SESSION['role_id'] ?? 0);
$userFacId = (int)($_SESSION['faculty_id'] ?? 0);

try {
    $db->exec("DELETE FROM announcements WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
} catch (Throwable $e) {  }

$audienceForRole = match ($roleId) {
    ROLE_STUDENT   => "('all','students')",
    ROLE_TEACHER   => "('all','teachers')",
    ROLE_ATT_MGR   => "('all','managers','deans')",                                  
    ROLE_DEPT_HEAD => "('all','deans','managers','teachers','students')",             
    default        => "('all','students','teachers','managers','deans')",
};

$facultyClause = '';
$facultyParams = [];
if (in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ATT_MGR, ROLE_TEACHER, ROLE_STUDENT], true) && $userFacId > 0) {
    $facultyClause = " AND (a.faculty_id IS NULL OR a.faculty_id = ?)";
    $facultyParams[] = $userFacId;
}

$sql = "SELECT a.*, u.full_name AS creator, f.name AS faculty_name
          FROM announcements a
     LEFT JOIN users u     ON u.id = a.created_by
     LEFT JOIN faculties f ON f.id = a.faculty_id
         WHERE a.audience IN {$audienceForRole}
           AND (a.expires_at IS NULL OR a.expires_at > NOW())
           {$facultyClause}
      ORDER BY a.created_at DESC LIMIT 100";
$stmt = $db->prepare($sql);
$stmt->execute($facultyParams);
$rows = $stmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fas fa-bullhorn me-2 text-primary"></i><?= t('announcements') ?></h5>
  
</div>

<?php if (!$rows): ?>
  <div class="alert alert-info text-center"><i class="fas fa-info-circle me-2"></i>اوس مهال هېڅ اعلان شتون نلري.</div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($rows as $a): ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <h6 class="card-title mb-0"><?= h($a['title']) ?></h6>
            <span class="badge bg-light text-dark"><?= h($a['audience']) ?></span>
          </div>
          <?php if (!empty($a['faculty_name'])): ?>
            <div class="small text-primary mb-1"><i class="fas fa-building-columns me-1"></i><?= h($a['faculty_name']) ?></div>
          <?php endif; ?>
          <p class="text-muted small mb-2"><?= nl2br(h($a['body'])) ?></p>
          <hr class="my-2"/>
          <div class="d-flex justify-content-between small text-muted">
            <span><i class="fas fa-user me-1"></i><?= h($a['creator'] ?? '—') ?></span>
            <span><i class="far fa-clock me-1"></i><?= h(date('d M, H:i', strtotime($a['created_at']))) ?></span>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
