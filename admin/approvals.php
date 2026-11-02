<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/lang.php';
requireLogin();
requirePermission('approve_requests');

$db        = getDB();
$pageTitle = 'Approvals';
function csrfFieldApproval(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

function adminJsonPreview($raw): string {
    if ($raw === null || $raw === '') return '<span class="text-muted">—</span>';
    $data = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($data)) {
        return '<span>'.h(mb_substr((string)$raw, 0, 80)).(mb_strlen((string)$raw) > 80 ? '…' : '').'</span>';
    }
    $out = '<ul class="list-unstyled mb-0 small">';
    foreach ($data as $k => $v) {
        $label = h(ucwords(str_replace('_', ' ', (string)$k)));
        $val   = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        $val   = h(mb_substr($val, 0, 80)).(mb_strlen($val) > 80 ? '…' : '');
        $out  .= '<li><strong>'.$label.':</strong> '.$val.'</li>';
    }
    $out .= '</ul>';
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $kind = $_POST['kind'] ?? 'edit_request';

    if ($kind === 'edit_request') {
        $id          = (int)($_POST['req_id'] ?? 0);
        $act         = $_POST['act'] ?? '';
        $note        = trim($_POST['review_note'] ?? '');
        $finalStatus = $_POST['return_status'] ?? 'pending';

        if (!in_array($act, ['approve','reject'], true)) {
            flashSet('danger', 'Invalid action.');
            redirect(BASE_URL.'/admin/approvals.php?tab=requests&status='.urlencode($finalStatus));
        }
        if ($id <= 0) {
            flashSet('danger', 'Missing request ID. Please click Approve/Reject directly on the row.');
            redirect(BASE_URL.'/admin/approvals.php?tab=requests&status='.urlencode($finalStatus));
        }

        $status = $act === 'approve' ? 'approved' : 'rejected';
        $req = $db->prepare("SELECT * FROM edit_requests WHERE id=? AND status='pending'");
        $req->execute([$id]); $req = $req->fetch();

        if (!$req) {
            flashSet('warning', "Request #{$id} not found or already processed.");
            redirect(BASE_URL.'/admin/approvals.php?tab=requests&status='.urlencode($finalStatus));
        }

        $upd = $db->prepare("UPDATE edit_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=? AND status='pending'");
        $upd->execute([$status, $_SESSION['user_id'], $note, $id]);
        $affected = $upd->rowCount();

        if ($affected > 0 && $req['request_type'] === 'profile' && $act === 'approve') {
            $new = json_decode((string)$req['new_value'], true);
            if (is_array($new) && !empty($req['target_id'])) {
                $allowed = ['full_name','email','phone'];
                $sets = []; $vals = [];
                foreach ($allowed as $k) { if (array_key_exists($k, $new)) { $sets[] = "$k=?"; $vals[] = $new[$k]; } }
                if ($sets) { $vals[] = (int)$req['target_id']; $db->prepare("UPDATE users SET ".implode(',', $sets)." WHERE id=?")->execute($vals); }
            }
        }

        if ($affected > 0) {
            auditLog($act.'_request','approvals',$id,"VC {$status} request #{$id}");
            createNotification((int)$req['requested_by'], 'Request '.$status, 'Your '.$req['request_type'].' request has been '.$status.'.', $status === 'approved' ? 'success' : 'danger', BASE_URL.'/profile/');
            flashSet('success', "Request #{$id} ".$status.' successfully.');
            cacheBustDashboards();
            $finalStatus = $status;
        } else {
            flashSet('warning', 'No changes applied. The request may have already been processed.');
        }
        redirect(BASE_URL.'/admin/approvals.php?tab=requests&status='.urlencode($finalStatus));
    }

    if ($kind === 'timetable') {
        $id  = (int)($_POST['tt_id'] ?? 0);
        $act = $_POST['act'] ?? '';
        if ($id && in_array($act, ['approve','reject'], true)) {
            if ($act === 'approve') {
                $db->prepare("UPDATE timetable SET status='approved', approved_by=? WHERE id=?")->execute([$_SESSION['user_id'], $id]);
            } else {
                $db->prepare("UPDATE timetable SET status='rejected', approved_by=? WHERE id=?")->execute([$_SESSION['user_id'], $id]);
            }
            $tt = $db->prepare("SELECT t.*, c.name AS class_name, sub.name AS subject_name FROM timetable t JOIN classes c ON c.id=t.class_id JOIN subjects sub ON sub.id=t.subject_id WHERE t.id=?");
            $tt->execute([$id]); $tt = $tt->fetch();
            if ($tt) {
                $msg = "Timetable for ".$tt['class_name']." / ".$tt['subject_name']." is now ".strtoupper($act === 'approve' ? 'approved' : 'rejected').".";
                $userIds = $db->prepare("SELECT id FROM users WHERE faculty_id=? AND role_id IN (?,?) AND status='active'");
                $userIds->execute([$tt['faculty_id'], ROLE_DEPT_HEAD, ROLE_ATT_MGR]);
                foreach ($userIds->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    createNotification((int)$uid, 'Timetable '.$act.'d', $msg, $act === 'approve' ? 'success' : 'danger', BASE_URL.'/admin/timetable.php?id='.$id);
                }
                if ($act === 'approve') {
                    createNotification((int)$tt['teacher_id'], 'New approved timetable', $msg, 'info', BASE_URL.'/teacher/timetable.php');
                }
            }
            auditLog('timetable_'.$act, 'approvals', $id, "Vice-Chancellor {$act} timetable {$id}");
            flashSet('success', 'Timetable '.$act.'d.');
            cacheBustDashboards();
        }
        $returnStatus = $_POST['return_status'] ?? 'pending';
        redirect(BASE_URL.'/admin/approvals.php?tab=timetable&status='.urlencode($returnStatus));
    }
}

$tab = $_GET['tab'] ?? 'requests'; 
if (!in_array($tab, ['requests','timetable'], true)) $tab = 'requests';

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending','approved','rejected'], true)) $statusFilter = 'pending';

$page    = max(1,(int)($_GET['p'] ?? 1));
$perPage = DEFAULT_PER_PAGE;

$reqCountersStmt = $db->query("SELECT status, COUNT(*) c FROM edit_requests GROUP BY status");
$reqCounters = ['pending'=>0,'approved'=>0,'rejected'=>0];
foreach ($reqCountersStmt->fetchAll() as $c) {
    if (isset($reqCounters[$c['status']])) $reqCounters[$c['status']] = (int)$c['c'];
}

$ttCounters = [
    'pending'  => (int)$db->query("SELECT COUNT(*) FROM timetable WHERE status IN ('draft','faculty_approved')")->fetchColumn(),
    'approved' => (int)$db->query("SELECT COUNT(*) FROM timetable WHERE status='approved'")->fetchColumn(),
    'rejected' => (int)$db->query("SELECT COUNT(*) FROM timetable WHERE status='rejected'")->fetchColumn(),
];

$reqs = []; $timetableRows = [];
if ($tab === 'requests') {
    $where = 'WHERE er.status=?';
    $params = [$statusFilter];
    $stmtTotal = $db->prepare("SELECT COUNT(*) FROM edit_requests er {$where}");
    $stmtTotal->execute($params); $total = (int)$stmtTotal->fetchColumn();
    $pag = paginate($total,$page,$perPage);
    $stmt = $db->prepare("SELECT er.*, u.full_name AS req_name, u.username AS req_user, rv.full_name AS rev_name, f.name AS req_faculty
                          FROM edit_requests er
                          JOIN users u ON u.id=er.requested_by
                          LEFT JOIN faculties f ON f.id=u.faculty_id
                          LEFT JOIN users rv ON rv.id=er.reviewed_by
                          {$where}
                          ORDER BY er.created_at DESC
                          LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
    $stmt->execute($params); $reqs = $stmt->fetchAll();
} else { 
    
    if      ($statusFilter === 'pending')  { $statusList = ['draft','faculty_approved']; }
    elseif  ($statusFilter === 'approved') { $statusList = ['approved']; }
    else                                   { $statusList = ['rejected']; }
    $placeholders = implode(',', array_fill(0, count($statusList), '?'));
    $stmt = $db->prepare("SELECT t.*, c.name AS class_name, c.section, sub.name AS subject_name,
                                 u.full_name AS teacher_name, f.name AS faculty_name, d.name AS department_name
                          FROM timetable t
                          JOIN classes c   ON c.id=t.class_id
                          JOIN subjects sub ON sub.id=t.subject_id
                          JOIN users u     ON u.id=t.teacher_id
                          JOIN faculties f ON f.id=t.faculty_id
                          LEFT JOIN departments d ON d.id=t.department_id
                          WHERE t.status IN ($placeholders)
                          ORDER BY f.name, t.day_of_week, t.start_time");
    $stmt->execute($statusList); $timetableRows = $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Approvals</h5>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge bg-warning text-dark">Edit pending <?= $reqCounters['pending'] ?></span>
    <span class="badge bg-info text-dark">Timetable pending <?= $ttCounters['pending'] ?></span>
  </div>
</div>

<!-- Main tabs: Edit-requests vs Timetable -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='requests'?'active':'' ?>" href="?tab=requests&status=pending">
      <i class="fas fa-clipboard-list me-1"></i>Edit requests
      <span class="badge bg-secondary ms-1"><?= $reqCounters['pending'] ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='timetable'?'active':'' ?>" href="?tab=timetable&status=pending">
      <i class="fas fa-calendar-check me-1"></i>Timetable
      <span class="badge bg-secondary ms-1"><?= $ttCounters['pending'] ?></span>
    </a>
  </li>
</ul>

<!-- Three-state filter pills — shared across both tabs -->
<?php $activeCounters = $tab === 'requests' ? $reqCounters : $ttCounters; ?>
<ul class="nav nav-pills mb-3">
  <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $k=>$v): ?>
    <li class="nav-item">
      <a class="nav-link <?= $statusFilter===$k?'active':'' ?>" href="?tab=<?= h($tab) ?>&status=<?= urlencode($k) ?>">
        <?= h($v) ?>
        <span class="badge bg-light text-dark ms-1"><?= (int)$activeCounters[$k] ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'requests'): ?>
  <div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>#</th><th>Type</th><th>Faculty</th><th>Requested by</th><th>Reason</th><th>Preview</th><th>Status</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php if(!$reqs): ?><tr><td colspan="9" class="text-center text-muted py-4">No <?= h($statusFilter) ?> requests found.</td></tr><?php endif; ?>
      <?php foreach($reqs as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><span class="badge bg-info text-dark"><?= h($r['request_type']) ?></span></td>
          <td><span class="v7-mini-tag"><?= h($r['req_faculty'] ?? '—') ?></span></td>
          <td><?= h($r['req_name']) ?><div class="small text-muted">@<?= h($r['req_user']) ?></div></td>
          <td><?= h(substr($r['reason'] ?? '', 0, 80)) ?></td>
          <td>
            <div class="mb-1"><span class="badge bg-light text-dark border">Old</span> <?= adminJsonPreview($r['old_value']) ?></div>
            <div><span class="badge bg-primary">New</span> <?= adminJsonPreview($r['new_value']) ?></div>
          </td>
          <td>
            <?php $cls = ['pending'=>'warning text-dark','approved'=>'success','rejected'=>'danger'][$r['status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $cls ?>"><?= h(ucfirst($r['status'])) ?></span>
            <?php if (!empty($r['rev_name'])): ?><div class="small text-muted mt-1">By <?= h($r['rev_name']) ?></div><?php endif; ?>
          </td>
          <td class="text-nowrap"><?= h(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
          <td class="text-nowrap">
            <?php if ($r['status'] === 'pending'): ?>
              
              <form method="POST" action="<?= BASE_URL ?>/admin/approvals.php" class="d-inline review-form">
                <?php csrfFieldApproval(); ?>
                <input type="hidden" name="kind"          value="edit_request"/>
                <input type="hidden" name="req_id"        value="<?= (int)$r['id'] ?>"/>
                <input type="hidden" name="act"           value="approve"/>
                <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>"/>
                <input type="hidden" name="review_note"   class="review-note-field" value=""/>
                <button type="button"
                        class="btn btn-sm btn-success mb-1 btn-open-review"
                        data-id="<?= (int)$r['id'] ?>"
                        data-act="approve">Approve</button>
              </form>
              <form method="POST" action="<?= BASE_URL ?>/admin/approvals.php" class="d-inline review-form">
                <?php csrfFieldApproval(); ?>
                <input type="hidden" name="kind"          value="edit_request"/>
                <input type="hidden" name="req_id"        value="<?= (int)$r['id'] ?>"/>
                <input type="hidden" name="act"           value="reject"/>
                <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>"/>
                <input type="hidden" name="review_note"   class="review-note-field" value=""/>
                <button type="button"
                        class="btn btn-sm btn-danger btn-open-review"
                        data-id="<?= (int)$r['id'] ?>"
                        data-act="reject">Reject</button>
              </form>
            <?php else: ?>
              <span class="text-muted small">Completed</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

  <?php if (!empty($pag) && ($pag['pages'] ?? 1) > 1): ?>
    <nav class="mt-3"><ul class="pagination justify-content-center mb-0">
      <?php for ($i = 1; $i <= $pag['pages']; $i++): ?>
        <li class="page-item <?= $i === $pag['page'] ? 'active' : '' ?>"><a class="page-link" href="?tab=requests&status=<?= urlencode($statusFilter) ?>&p=<?= $i ?>"><?= $i ?></a></li>
      <?php endfor; ?>
    </ul></nav>
  <?php endif; ?>

  <!-- v24 FIX #2: ساده، reliable note prompt. د hidden form fields سره جوت کار کوي. -->
  <div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="reviewModalTitle">Review</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Note <span class="text-muted small">(optional)</span></label>
          <textarea id="reviewNoteInput" class="form-control" rows="3" placeholder="Optional review note"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="reviewSubmitBtn">Submit</button>
        </div>
      </div>
    </div>
  </div>
  <script>
  /* v24 FIX (REQ #2) — د VC د Approve/Reject کلیک handler.
   *   • هر row خپل مستقل form لري (req_id + act مخکې له مخکې پکې دي).
   *   • د button کلیک سره د note modal خلاصیږي (اختیاري).
   *   • د Submit سره د note value د هماغه form review_note field ته کاپي او form سبمیټ کیږي.
   *   • د Bootstrap د نستون په وخت کې confirm()-based fallback شته — دا تضمین کوي
   *     چې Action هیڅکله د silently فعله پاتې نه شي. */
  (function(){
    var activeForm = null;
    var activeAct  = '';
    var noteInput  = document.getElementById('reviewNoteInput');
    var titleEl    = document.getElementById('reviewModalTitle');
    var submitBtn  = document.getElementById('reviewSubmitBtn');
    var modalEl    = document.getElementById('reviewModal');
    var modal      = (modalEl && window.bootstrap && bootstrap.Modal)
                       ? bootstrap.Modal.getOrCreateInstance(modalEl)
                       : null;

    document.querySelectorAll('.btn-open-review').forEach(function(btn){
      btn.addEventListener('click', function(){
        activeForm = btn.closest('form');
        activeAct  = btn.dataset.act || '';
        if (!activeForm) { return; }
        if (noteInput) noteInput.value = '';
        var isApprove = activeAct === 'approve';
        if (titleEl) titleEl.textContent = isApprove ? 'Approve request' : 'Reject request';
        if (submitBtn){
          submitBtn.className   = 'btn ' + (isApprove ? 'btn-success' : 'btn-danger');
          submitBtn.textContent = isApprove ? 'Approve' : 'Reject';
        }
        if (modal) {
          modal.show();
        } else {
          /* Bootstrap د کوم دلیل له امله load نه شو → سم پوست کوو. */
          if (confirm((isApprove ? 'Approve' : 'Reject') + ' this request?')) {
            activeForm.submit();
          }
        }
      });
    });

    if (submitBtn){
      submitBtn.addEventListener('click', function(){
        if (!activeForm) return;
        var noteVal   = noteInput ? noteInput.value : '';
        var noteField = activeForm.querySelector('.review-note-field');
        if (noteField) noteField.value = noteVal;
        if (modal) modal.hide();
        activeForm.submit();
      });
    }
  })();
  </script>

<?php else:  ?>
  <div class="card"><div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead><tr><th>Faculty</th><th>Department</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Day</th><th>Time</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if(!$timetableRows): ?><tr><td colspan="9" class="text-center text-muted py-4">No <?= h($statusFilter) ?> timetable entries match this filter.</td></tr><?php endif; ?>
      <?php foreach ($timetableRows as $row): ?>
        <tr>
          <td><?= h($row['faculty_name']) ?></td>
          <td><?= h($row['department_name'] ?? '-') ?></td>
          <td><?= h(trim($row['class_name'].' '.($row['section'] ?? ''))) ?></td>
          <td><?= h($row['subject_name']) ?></td>
          <td><?= h($row['teacher_name']) ?></td>
          <td><?= h(DAYS_OF_WEEK[(int)$row['day_of_week']] ?? '') ?></td>
          <td><?= h(substr($row['start_time'], 0, 5)) ?>–<?= h(substr($row['end_time'], 0, 5)) ?></td>
          <td><span class="badge bg-<?= ['draft'=>'secondary','faculty_approved'=>'warning','approved'=>'success','rejected'=>'danger'][$row['status']] ?? 'secondary' ?>"><?= h(str_replace('_',' ',ucfirst($row['status']))) ?></span></td>
          <td class="text-nowrap">
            
            <?php if (in_array($row['status'], ['draft','faculty_approved'], true)): ?>
              <form method="POST" class="d-inline">
                <?php csrfFieldApproval(); ?>
                <input type="hidden" name="kind"          value="timetable"/>
                <input type="hidden" name="tt_id"         value="<?= (int)$row['id'] ?>"/>
                <input type="hidden" name="act"           value="approve"/>
                <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>"/>
                <button class="btn btn-xs btn-success py-0 px-1" data-confirm="Approve and publish this timetable row?">Approve</button>
              </form>
              <form method="POST" class="d-inline">
                <?php csrfFieldApproval(); ?>
                <input type="hidden" name="kind"          value="timetable"/>
                <input type="hidden" name="tt_id"         value="<?= (int)$row['id'] ?>"/>
                <input type="hidden" name="act"           value="reject"/>
                <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>"/>
                <button class="btn btn-xs btn-outline-danger py-0 px-1" data-confirm="Reject this timetable row?">Reject</button>
              </form>
            <?php else: ?>
              <span class="text-muted small">Completed</span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/admin/timetable.php?action=edit&id=<?= (int)$row['id'] ?>" class="btn btn-xs btn-outline-primary py-0 px-1"><i class="fas fa-edit"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
