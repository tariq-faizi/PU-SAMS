<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('view_audit_log');
$db = getDB();
$pageTitle = 'Audit Log — د نن Dayې';

try {
    $today  = date('Y-m-d');
    $marker = BASE_PATH.'/cache/last_log_purge.txt';
    $last   = is_file($marker) ? trim((string)file_get_contents($marker)) : '';
    if ($last !== $today) {
        $db->prepare("DELETE FROM audit_log WHERE DATE(created_at) < CURDATE()")->execute();
        @file_put_contents($marker, $today);
    }
} catch (Throwable $e) {  }

$search = trim($_GET['q'] ?? '');
$module = $_GET['module'] ?? '';
$page   = max(1, (int)($_GET['p'] ?? 1));
$perPage = 30;

$where  = "WHERE DATE(al.created_at) = CURDATE()";
$params = [];
if ($search) {
    $where .= " AND (al.action LIKE ? OR al.description LIKE ? OR u.username LIKE ?)";
    $s = "%$search%";
    $params = [$s,$s,$s];
}
if ($module) { $where .= " AND al.module=?"; $params[] = $module; }

$totalStmt = $db->prepare("SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id=al.user_id $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pag   = paginate($total, $page, $perPage);

$logs = $db->prepare("SELECT al.*, u.username, u.full_name
                      FROM audit_log al
                      LEFT JOIN users u ON u.id=al.user_id
                      $where
                      ORDER BY al.created_at DESC
                      LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$logs->execute($params);
$logs = $logs->fetchAll();

$modules = $db->query("SELECT DISTINCT module FROM audit_log
                       WHERE module IS NOT NULL AND DATE(created_at)=CURDATE()
                       ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Audit Log</h5>
    <small class="text-muted"><?= h(date('Y-m-d')) ?></small>
  </div>
  <span class="badge bg-info"><i class="far fa-calendar-alt me-1"></i><?= h(date('l, d M Y')) ?></span>
</div>

<form class="row g-2 mb-3" method="GET">
  <div class="col-md-5"><input name="q" class="form-control form-control-sm" placeholder="Search action/user/description..." value="<?= h($search) ?>"/></div>
  <div class="col-auto">
    <select name="module" class="form-select form-select-sm">
      <option value="">All Modules</option>
      <?php foreach($modules as $m): ?>
        <option value="<?= h($m) ?>" <?= $module===$m?'selected':'' ?>><?= h(ucfirst($m)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-primary"><?= t('filter') ?></button>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th><th>User</th><th>Action</th><th>Module</th>
          <th>Description</th><th>IP</th><th>Time</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No activity found for today.</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= (int)$l['id'] ?></td>
        <td>
          <?= h($l['full_name'] ?? 'System') ?>
          <br><small class="text-muted">@<?= h($l['username'] ?? '') ?></small>
        </td>
        <td><code class="small"><?= h($l['action']) ?></code></td>
        <td><span class="badge bg-secondary"><?= h($l['module'] ?? '') ?></span></td>
        <td><?= h(substr($l['description'] ?? '', 0, 100)) ?></td>
        <td><small><?= h($l['ip_address'] ?? '') ?></small></td>
        <td><?= h(date('H:i:s', strtotime($l['created_at']))) ?></td>
        <td>
          <a href="<?= BASE_URL ?>/admin/audit_log.php?q=<?= urlencode((string)$l['action']) ?>"
             class="btn btn-sm btn-outline-info" title="View">
            <i class="fas fa-eye"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pag['pages'] > 1): ?>
    <div class="card-body py-2">
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <?php for ($i=1; $i<=$pag['pages']; $i++): $a=$i==$pag['page']?'active':''; ?>
            <li class="page-item <?= $a ?>">
              <a class="page-link" href="?q=<?= urlencode($search) ?>&module=<?= urlencode($module) ?>&p=<?= $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
