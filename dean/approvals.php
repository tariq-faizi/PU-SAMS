<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requireRole([ROLE_DEPT_HEAD]);

$db        = getDB();
$roleId    = (int)($_SESSION['role_id'] ?? 0);
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$reviewerId= (int)($_SESSION['user_id'] ?? 0);
$pageTitle = 'Approvals';

/* ─── POST: approve / reject ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $reqId  = (int)($_POST['req_id'] ?? 0);
    $act    = in_array($_POST['act'] ?? '', ['approve','reject'], true) ? $_POST['act'] : '';
    $note   = trim($_POST['review_note'] ?? '');
    $retSt  = in_array($_POST['return_status'] ?? '', ['pending','approved','rejected'], true)
                ? $_POST['return_status'] : 'pending';

    if ($reqId > 0 && $act !== '') {

        /* Load the request – must belong to this dean's faculty and be pending */
        $stmt = $db->prepare(
            "SELECT er.*, u.faculty_id AS user_faculty_id, u.full_name AS requester_name
               FROM edit_requests er
               JOIN users u ON u.id = er.requested_by
              WHERE er.id = ?
                AND u.faculty_id = ?
                AND er.status   = 'pending'
              LIMIT 1"
        );
        $stmt->execute([$reqId, $facultyId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            flashSet('danger', 'Request not found, already processed, or outside your faculty.');
            redirect(BASE_URL.'/dean/approvals.php?status='.urlencode($retSt));
        }

        $newStatus = ($act === 'approve') ? 'approved' : 'rejected';

        try {
            $db->beginTransaction();

            /* 1. Update edit_requests row */
            $upd = $db->prepare(
                "UPDATE edit_requests
                    SET status      = ?,
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        review_note = ?
                  WHERE id     = ?
                    AND status = 'pending'"
            );
            $upd->execute([$newStatus, $reviewerId, $note, $reqId]);

            if ($upd->rowCount() === 0) {
                $db->rollBack();
                flashSet('warning', 'No changes applied – the request may already have been processed.');
                redirect(BASE_URL.'/dean/approvals.php?status='.urlencode($retSt));
            }

            /* 2. If approving a profile request, apply the new values to users table */
            if ($act === 'approve' && $req['request_type'] === 'profile') {
                $newData = json_decode((string)($req['new_value'] ?? ''), true);
                $targetId = (int)($req['target_id'] ?? 0);

                if (is_array($newData) && $targetId > 0) {
                    $allowed = ['full_name', 'email', 'phone'];
                    $setClauses = [];
                    $setValues  = [];

                    foreach ($allowed as $field) {
                        if (!array_key_exists($field, $newData)) continue;
                        $val = $newData[$field];
                        // Normalise empty optional fields to NULL to avoid unique-key clashes
                        if (in_array($field, ['email', 'phone'], true) && $val === '') {
                            $val = null;
                        }
                        $setClauses[] = "`$field` = ?";
                        $setValues[]  = $val;
                    }

                    if ($setClauses) {
                        $setValues[] = $targetId;
                        $sql = "UPDATE users SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = ?";
                        $db->prepare($sql)->execute($setValues);
                    }
                }

                /* Also update the photo if request includes photo filename */
                if (is_array($newData) && !empty($newData['photo'])) {
                    $photoFile = basename((string)$newData['photo']);
                    if ($targetId > 0 && preg_match('/^[A-Za-z0-9_.\-]+$/', $photoFile)) {
                        $db->prepare("UPDATE users SET photo = ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$photoFile, $targetId]);
                    }
                }
            }

            $db->commit();

            auditLog('faculty_request_'.$newStatus, 'edit_requests', $reqId,
                     'Dean '.($newStatus).' request #'.$reqId.' for user #'.$req['requested_by']);

            /* Notify the requester */
            createNotification(
                (int)$req['requested_by'],
                'Request ' . $newStatus,
                'Your ' . $req['request_type'] . ' request has been ' . $newStatus . '.' . ($note ? ' Note: '.$note : ''),
                $newStatus === 'approved' ? 'success' : 'danger',
                BASE_URL . '/profile/'
            );

            flashSet('success', 'Request ' . $newStatus . ' successfully.');
            if (function_exists('cacheBustDashboards')) cacheBustDashboards();

        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || strpos($msg, '1062') !== false) {
                flashSet('danger', 'Cannot approve: the email or phone is already used by another account.');
            } else {
                flashSet('danger', 'Database error: ' . $msg);
            }
            redirect(BASE_URL.'/dean/approvals.php?status='.urlencode($retSt));
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            flashSet('danger', 'Error: ' . $e->getMessage());
            redirect(BASE_URL.'/dean/approvals.php?status='.urlencode($retSt));
        }

        redirect(BASE_URL.'/dean/approvals.php?status='.urlencode($newStatus));
    }

    redirect(BASE_URL.'/dean/approvals.php?status='.urlencode($retSt));
}

/* ─── GET: list requests ─── */
$statusFilter = in_array($_GET['status'] ?? '', ['pending','approved','rejected'], true)
    ? $_GET['status'] : 'pending';
$typeFilter   = trim($_GET['type'] ?? '');

/* Counters */
$cntStmt = $db->prepare(
    "SELECT er.status, COUNT(*) AS c
       FROM edit_requests er
       JOIN users u ON u.id = er.requested_by
      WHERE u.faculty_id = ?
      GROUP BY er.status"
);
$cntStmt->execute([$facultyId]);
$counters = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($cntStmt->fetchAll() as $row) {
    if (isset($counters[$row['status']])) $counters[$row['status']] = (int)$row['c'];
}

/* Rows */
$where  = ["u.faculty_id = ?", "er.status = ?"];
$params = [$facultyId, $statusFilter];
if ($typeFilter !== '') { $where[] = "er.request_type = ?"; $params[] = $typeFilter; }

$rowsStmt = $db->prepare(
    "SELECT er.*,
            u.full_name  AS requester_name,
            u.username   AS requester_username,
            rv.full_name AS reviewer_name
       FROM edit_requests er
       JOIN users u  ON u.id  = er.requested_by
       LEFT JOIN users rv ON rv.id = er.reviewed_by
      WHERE " . implode(' AND ', $where) . "
      ORDER BY er.created_at DESC"
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

/* Available type filter options */
$typesStmt = $db->prepare(
    "SELECT DISTINCT er.request_type
       FROM edit_requests er
       JOIN users u ON u.id = er.requested_by
      WHERE u.faculty_id = ?
      ORDER BY er.request_type"
);
$typesStmt->execute([$facultyId]);
$types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

/* ─── helpers ─── */
function deanRenderValuePreview($raw): string {
    if ($raw === null || $raw === '') return '<span class="text-muted">—</span>';
    $data = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
    if (!is_array($data)) {
        return '<span class="small">' . h(mb_substr((string)$raw, 0, 100)) . '</span>';
    }
    $skip = ['photo']; // don't show raw filenames
    $out = '<ul class="list-unstyled mb-0 small">';
    foreach ($data as $k => $v) {
        if (in_array($k, $skip, true)) continue;
        $label = h(ucwords(str_replace('_', ' ', (string)$k)));
        $val   = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        $out  .= '<li><strong>'.$label.':</strong> '.h(mb_substr($val, 0, 80)).'</li>';
    }
    $out .= '</ul>';
    return $out;
}

function deanCsrfInput(): void {
    echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>';
}

require_once __DIR__.'/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Approvals</h5>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge bg-warning text-dark">Pending <?= $counters['pending'] ?></span>
    <span class="badge bg-success">Approved <?= $counters['approved'] ?></span>
    <span class="badge bg-danger">Rejected <?= $counters['rejected'] ?></span>
  </div>
</div>

<!-- Status tabs -->
<ul class="nav nav-pills mb-3">
  <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $k => $v): ?>
    <li class="nav-item">
      <a class="nav-link <?= $statusFilter === $k ? 'active' : '' ?>"
         href="?status=<?= urlencode($k) ?><?= $typeFilter !== '' ? '&type='.urlencode($typeFilter) : '' ?>">
        <?= h($v) ?>
        <span class="badge bg-light text-dark ms-1"><?= $counters[$k] ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<?php if ($types): ?>
<form method="GET" class="row g-2 mb-3">
  <input type="hidden" name="status" value="<?= h($statusFilter) ?>"/>
  <div class="col-md-3">
    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach ($types as $type): ?>
        <option value="<?= h($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>>
          <?= h(ucfirst($type)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <i class="fas fa-list-check me-2"></i>Edit Requests
    <span class="badge bg-secondary ms-2"><?= count($rows) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>Requested by</th>
          <th>Reason</th>
          <th>From → To</th>
          <th>Status</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">
              No <?= h($statusFilter) ?> requests.
            </td>
          </tr>
        <?php else: foreach ($rows as $row): ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><span class="badge bg-info text-dark"><?= h(ucfirst((string)$row['request_type'])) ?></span></td>
            <td>
              <?= h((string)$row['requester_name']) ?>
              <div class="small text-muted">@<?= h((string)$row['requester_username']) ?></div>
            </td>
            <td class="small"><?= h((string)$row['reason']) ?></td>
            <td>
              <div class="mb-1"><span class="badge bg-light text-dark border">Old</span> <?= deanRenderValuePreview($row['old_value']) ?></div>
              <div><span class="badge bg-primary">New</span> <?= deanRenderValuePreview($row['new_value']) ?></div>
            </td>
            <td>
              <?php
                $badgeCls = match($row['status']) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default    => 'warning text-dark',
                };
              ?>
              <span class="badge bg-<?= $badgeCls ?>"><?= h(ucfirst((string)$row['status'])) ?></span>
              <?php if (!empty($row['reviewer_name'])): ?>
                <div class="small text-muted mt-1">By <?= h((string)$row['reviewer_name']) ?></div>
              <?php endif; ?>
              <?php if (!empty($row['review_note'])): ?>
                <div class="small text-muted">"<?= h(mb_substr((string)$row['review_note'], 0, 60)) ?>"</div>
              <?php endif; ?>
            </td>
            <td class="text-nowrap small"><?= h(date('d M Y H:i', strtotime((string)$row['created_at']))) ?></td>
            <td>
              <?php if ($row['status'] === 'pending'): ?>
                <button type="button" class="btn btn-sm btn-success mb-1"
                        data-bs-toggle="modal" data-bs-target="#reviewModal"
                        data-id="<?= (int)$row['id'] ?>" data-act="approve">Approve</button>
                <button type="button" class="btn btn-sm btn-danger"
                        data-bs-toggle="modal" data-bs-target="#reviewModal"
                        data-id="<?= (int)$row['id'] ?>" data-act="reject">Reject</button>
              <?php else: ?>
                <span class="text-muted small">Done</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="<?= BASE_URL ?>/dean/approvals.php" class="modal-content" id="reviewForm">
      <?php deanCsrfInput(); ?>
      <input type="hidden" name="req_id"        id="modalReqId"/>
      <input type="hidden" name="act"           id="modalAct"/>
      <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>"/>

      <div class="modal-header">
        <h5 class="modal-title" id="reviewModalLabel">Review Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Note <span class="text-muted small">(optional)</span></label>
        <textarea name="review_note" class="form-control" rows="3" placeholder="Add a note for the requester…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success" id="modalSubmitBtn">Submit</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
    var modal = document.getElementById('reviewModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) return;
        var id  = btn.dataset.id  || '';
        var act = btn.dataset.act || '';
        document.getElementById('modalReqId').value = id;
        document.getElementById('modalAct').value   = act;
        var isApprove = (act === 'approve');
        document.getElementById('reviewModalLabel').textContent = isApprove ? 'Approve Request' : 'Reject Request';
        var submitBtn = document.getElementById('modalSubmitBtn');
        submitBtn.className  = 'btn ' + (isApprove ? 'btn-success' : 'btn-danger');
        submitBtn.textContent = isApprove ? 'Approve' : 'Reject';
    });
})();
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
