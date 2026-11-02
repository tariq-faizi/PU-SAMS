<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
$db = getDB();
$uid = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = t('notifications');

function notifCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_one') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([$id, $uid]);
        flashSet('success', 'Notification removed.');
    } elseif ($action === 'clear_all') {
        $db->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$uid]);
        flashSet('success', 'All notifications were cleared.');
    } elseif ($action === 'mark_all_read') {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
        flashSet('success', 'All notifications marked as read.');
    }
    redirect(BASE_URL.'/notifications/');
}

$page = max(1,(int)($_GET['p'] ?? 1));
$perPage = 10;
$total = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?");
$total->execute([$uid]);
$total = (int)$total->fetchColumn();
$pag = paginate($total, $page, $perPage);
$notifs = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$notifs->execute([$uid]);
$notifs = $notifs->fetchAll();
$db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="mb-0"><i class="fas fa-bell me-2"></i><?=t('notifications')?></h5>
  </div>
  <div class="d-flex gap-2">
    <form method="POST" class="d-inline"><?php notifCsrf(); ?><input type="hidden" name="action" value="mark_all_read"/><button class="btn btn-sm btn-outline-secondary">Mark all read</button></form>
    <form method="POST" class="d-inline" onsubmit="return confirm('Delete all notifications?');"><?php notifCsrf(); ?><input type="hidden" name="action" value="clear_all"/><button class="btn btn-sm btn-outline-danger">Clear all</button></form>
  </div>
</div>
<div class="card">
  <?php if(!$notifs): ?>
  <div class="card-body text-center text-muted py-5"><i class="fas fa-bell-slash fa-3x mb-3 d-block text-muted"></i>No notifications.</div>
  <?php else: ?>
  <div class="list-group list-group-flush">
  <?php foreach($notifs as $n):
    $icon=['info'=>'fa-info-circle text-info','warning'=>'fa-exclamation-triangle text-warning','success'=>'fa-check-circle text-success','danger'=>'fa-exclamation-circle text-danger'][$n['type']] ?? 'fa-bell';
  ?>
    <div class="list-group-item <?=!$n['is_read']?'bg-light':''?>">
      <div class="d-flex gap-3 align-items-start">
        <i class="fas <?=$icon?> fs-4 mt-1"></i>
        <div class="flex-grow-1">
          <div class="d-flex justify-content-between flex-wrap gap-2">
            <strong><?=h($n['title'])?></strong>
            <small class="text-muted"><?=date('d M Y H:i',strtotime($n['created_at']))?></small>
          </div>
          <div class="text-muted mt-1"><?=h($n['message'])?></div>
          <div class="d-flex gap-2 mt-3">
            <?php $notifLink = normalizeAppLink((string)($n['link'] ?? '')); ?>
            <?php if($notifLink): ?><a href="<?= h($notifLink) ?>" class="btn btn-sm btn-outline-primary">Open</a><?php endif; ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this notification?');">
              <?php notifCsrf(); ?>
              <input type="hidden" name="action" value="delete_one"/>
              <input type="hidden" name="id" value="<?=$n['id']?>"/>
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php if(($pag['pages'] ?? 1) > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center mb-0">
  <?php for($i=1; $i<=$pag['pages']; $i++): ?>
    <li class="page-item <?=$i===$pag['page']?'active':''?>"><a class="page-link" href="?p=<?=$i?>"><?=$i?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
