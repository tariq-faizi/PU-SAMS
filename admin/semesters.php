<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();

requireRole([ROLE_ADMIN, ROLE_DEPT_HEAD]);

$db        = getDB();
$roleId    = (int)($_SESSION['role_id'] ?? 0);

$facultyScope = ($roleId === ROLE_DEPT_HEAD) ? currentFacultyScopeId() : null;

$selfUrl   = currentSectionPageUrl('semesters.php');
$pageTitle = t('semesters');
$action    = $_GET['action'] ?? 'list';

$selectedFacultyId    = $facultyScope ?: (int)($_GET['faculty_id'] ?? 0);
$selectedDepartmentId = (int)($_GET['department_id'] ?? 0);
$selectedClassId      = (int)($_GET['class_id'] ?? 0);

if ($selectedClassId) {
    $clsStmt = $db->prepare("SELECT faculty_id, department_id FROM classes WHERE id=? LIMIT 1");
    $clsStmt->execute([$selectedClassId]);
    if ($clsRow = $clsStmt->fetch()) {
        if (!$selectedFacultyId && !$facultyScope) {
            $selectedFacultyId = (int)$clsRow['faculty_id'];
        }
        if (!$selectedDepartmentId) {
            $selectedDepartmentId = (int)($clsRow['department_id'] ?? 0);
        }
    }
}

if (!$selectedFacultyId && !$facultyScope && $selectedDepartmentId) {
    $depStmt = $db->prepare("SELECT faculty_id FROM departments WHERE id=? LIMIT 1");
    $depStmt->execute([$selectedDepartmentId]);
    if ($dRow = $depStmt->fetch()) $selectedFacultyId = (int)$dRow['faculty_id'];
}

function semesterCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

$extraQuery = http_build_query(array_filter([
    'faculty_id'    => $selectedFacultyId ?: null,
    'department_id' => $selectedDepartmentId ?: null,
    'class_id'      => $selectedClassId ?: null,
]));
$extraQS = $extraQuery !== '' ? '&'.$extraQuery : '';
$listUrl = $selfUrl . ($extraQuery !== '' ? '?'.$extraQuery : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $facultyId   = $facultyScope ?: (int)($_POST['faculty_id'] ?? 0);
        // ── (v24) Department field — اختیاري، خو ترجیح یې ورکوو ──
        $departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
        // ── (v25) Class field — د سمیستر له ټولګي سره تړل ──
        $classIdPost  = (int)($_POST['class_id'] ?? 0) ?: null;
        $name        = trim($_POST['name'] ?? '');
        $semesterNo  = (int)($_POST['semester_no'] ?? 0);
        $yearNo      = (int)($_POST['year_no'] ?? 0);
        $weeksCount  = max(1, (int)($_POST['weeks_count'] ?? 16));
        $startDate   = trim($_POST['start_date'] ?? '');
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        // ── (v25) که class_id ورکړل شوی وي، له هغه نه faculty/department جوړ کړه ──
        if ($classIdPost) {
            $clsChk = $db->prepare("SELECT faculty_id, department_id FROM classes WHERE id=? LIMIT 1");
            $clsChk->execute([$classIdPost]);
            if ($clsRow2 = $clsChk->fetch()) {
                if (!$facultyId) {
                    $facultyId = (int)$clsRow2['faculty_id'];
                }
                if (!$departmentId && !empty($clsRow2['department_id'])) {
                    $departmentId = (int)$clsRow2['department_id'];
                }
            }
        }

        $startDateSql = null;
        if ($startDate !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $startDate);
            if ($dt && $dt->format('Y-m-d') === $startDate) {
                $startDateSql = $startDate;
            }
        }

        

        if (!$facultyId || $semesterNo < 1 || $semesterNo > 8 || $yearNo < 1 || $yearNo > 4) {
            flashSet('danger', 'Faculty, valid semester_no (1-8), and year_no (1-4) are required.');
            redirect($selfUrl.'?action=' . ($id ? 'edit&id='.$id : 'add') . $extraQS);
        }
        // Auto-generate name if not provided
        if ($name === '') {
            $name = 'Semester ' . $semesterNo;
        }

        if ($facultyScope && $facultyId !== (int)$facultyScope) {
            flashSet('danger', 'This faculty is outside your scope.');
            redirect($listUrl);
        }

        // ── (v24) د دیپارټمنټ تصدیق ──
        // دیپارټمنټ باید د انتخاب شوي پوهنځي پورې وتړل شي
        if ($departmentId) {
            $depCheck = $db->prepare("SELECT id FROM departments WHERE id=? AND faculty_id=? LIMIT 1");
            $depCheck->execute([$departmentId, $facultyId]);
            if (!$depCheck->fetchColumn()) {
                flashSet('danger', 'Selected department is outside the selected faculty.');
                redirect($selfUrl.'?action=' . ($id ? 'edit&id='.$id : 'add') . $extraQS);
            }
        }

        // ── (v25) د ټولګي تصدیق ──
        if ($classIdPost) {
            $clsCheckSql = "SELECT id FROM classes WHERE id=? AND faculty_id=?";
            $clsCheckParams = [$classIdPost, $facultyId];
            if ($departmentId) {
                $clsCheckSql .= " AND (department_id=? OR department_id IS NULL)";
                $clsCheckParams[] = $departmentId;
            }
            $clsCheckSql .= " LIMIT 1";
            $clsCheckStmt = $db->prepare($clsCheckSql);
            $clsCheckStmt->execute($clsCheckParams);
            if (!$clsCheckStmt->fetchColumn()) {
                flashSet('danger', 'Selected class is outside the selected faculty/department.');
                redirect($selfUrl.'?action=' . ($id ? 'edit&id='.$id : 'add') . $extraQS);
            }
        }

        $dupSql = "SELECT id FROM semesters WHERE faculty_id=? AND semester_no=?";
        $dupParams = [$facultyId, $semesterNo];
        if ($id > 0) { $dupSql .= " AND id<>?"; $dupParams[] = $id; }
        $dupSql .= " LIMIT 1";
        $dupStmt = $db->prepare($dupSql);
        $dupStmt->execute($dupParams);
        if ($dupStmt->fetchColumn()) {
            flashSet('danger', 'Another semester with the same number already exists for this faculty.');
            redirect($selfUrl.'?action=' . ($id ? 'edit&id='.$id : 'add') . $extraQS);
        }

        try {
            if ($id > 0) {
                
                if ($facultyScope) {
                    $ownedStmt = $db->prepare("SELECT id FROM semesters WHERE id=? AND faculty_id=? LIMIT 1");
                    $ownedStmt->execute([$id, $facultyScope]);
                    if (!$ownedStmt->fetchColumn()) {
                        flashSet('danger', 'This semester is outside your faculty scope.');
                        redirect($listUrl);
                    }
                }
                $db->prepare(
                    "UPDATE semesters
                        SET faculty_id=?, department_id=?, name=?, semester_no=?, year_no=?, weeks_count=?, start_date=?, is_active=?
                      WHERE id=?"
                )->execute([$facultyId, $departmentId, $name, $semesterNo, $yearNo, $weeksCount, $startDateSql, $isActive, $id]);
                auditLog('semester_update', 'semesters', $id, 'Semester updated');
            } else {
                $db->prepare(
                    "INSERT INTO semesters (faculty_id, department_id, name, semester_no, year_no, weeks_count, start_date, is_active)
                     VALUES (?,?,?,?,?,?,?,?)"
                )->execute([$facultyId, $departmentId, $name, $semesterNo, $yearNo, $weeksCount, $startDateSql, $isActive]);
                $id = (int)$db->lastInsertId();
                auditLog('semester_create', 'semesters', $id, 'Semester created');
            }

            // ── (v25) که class_id ټاکل شوی وي، نوی سمیستر د ټولګي سره وتړه ──
            if ($classIdPost && $id > 0) {
                try {
                    $linkStmt = $db->prepare("UPDATE classes SET semester_id=? WHERE id=? AND faculty_id=?");
                    $linkStmt->execute([$id, $classIdPost, $facultyId]);
                    auditLog('class_semester_link', 'classes', $classIdPost, 'Class linked to semester #'.$id);
                } catch (Throwable $linkErr) {
                    // د لینک کولو ناکامي د سمیستر له خوندي کېدو سره مخه نه نیسي
                }
            }

            flashSet('success', 'Semester saved successfully.');
        } catch (Throwable $e) {
            flashSet('danger', 'Could not save semester: '.$e->getMessage());
        }
        redirect($listUrl);
    }

    if ($act === 'toggle') {
        
        $id = (int)($_POST['id'] ?? 0);
        $togSql = $facultyScope
            ? "UPDATE semesters SET is_active = 1 - is_active WHERE id=? AND faculty_id=?"
            : "UPDATE semesters SET is_active = 1 - is_active WHERE id=?";
        $togParams = $facultyScope ? [$id, $facultyScope] : [$id];
        $stmt = $db->prepare($togSql);
        $stmt->execute($togParams);
        if ($facultyScope && $stmt->rowCount() === 0) {
            flashSet('danger', 'This semester is outside your faculty scope.');
            redirect($listUrl);
        }
        auditLog('semester_toggle', 'semesters', $id, 'Semester status toggled');
        flashSet('success', 'Semester status updated.');
        redirect($listUrl);
    }

    if ($act === 'delete') {

        $id = (int)($_POST['id'] ?? 0);

        if ($facultyScope) {
            $own = $db->prepare("SELECT id FROM semesters WHERE id=? AND faculty_id=? LIMIT 1");
            $own->execute([$id, $facultyScope]);
            if (!$own->fetchColumn()) {
                flashSet('danger', 'This semester is outside your faculty scope.');
                redirect($listUrl);
            }
        }

        $inUse = false;
        foreach (['classes','subjects','users'] as $tbl) {
            $st = $db->prepare("SELECT COUNT(*) FROM `$tbl` WHERE semester_id=?");
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) { $inUse = true; break; }
        }

        if ($inUse) {
            
            $db->prepare("UPDATE semesters SET is_active=0 WHERE id=?")->execute([$id]);
            auditLog('semester_deactivate', 'semesters', $id, 'Semester deactivated (in use)');
            flashSet('warning', 'Semester is in use by classes/subjects/users — it was deactivated instead of being removed.');
        } else {
            try {
                $db->prepare("DELETE FROM semesters WHERE id=?")->execute([$id]);
                auditLog('semester_delete', 'semesters', $id, 'Semester deleted');
                flashSet('success', 'Semester deleted.');
            } catch (Throwable $e) {
                
                $db->prepare("UPDATE semesters SET is_active=0 WHERE id=?")->execute([$id]);
                auditLog('semester_deactivate', 'semesters', $id, 'Semester deactivated (FK protected)');
                flashSet('warning', 'Semester could not be deleted; it was deactivated instead.');
            }
        }
        redirect($listUrl);
    }
}

$editRow = null;
if ($action === 'edit' && ($editId = (int)($_GET['id'] ?? 0))) {
    $editSql = "SELECT * FROM semesters WHERE id=?" . ($facultyScope ? " AND faculty_id=?" : "") . " LIMIT 1";
    $editParams = $facultyScope ? [$editId, $facultyScope] : [$editId];
    $stmt = $db->prepare($editSql);
    $stmt->execute($editParams);
    $editRow = $stmt->fetch();
    if (!$editRow) {
        flashSet('danger', 'Semester not found or outside your scope.');
        redirect($listUrl);
    }

    if (!$selectedFacultyId && !$facultyScope) {
        $selectedFacultyId = (int)$editRow['faculty_id'];
    }
}

$options = reportFilterOptions([
    'faculty_id'    => $selectedFacultyId ?: $facultyScope,
    'department_id' => $selectedDepartmentId ?: null,
]);
$faculties   = $options['faculties'];
$departments = $options['departments'];
$classes     = $options['classes'];

$where = "WHERE 1=1"; $params = [];
if ($selectedFacultyId) { $where .= " AND s.faculty_id=?"; $params[] = $selectedFacultyId; }
elseif ($facultyScope)  { $where .= " AND s.faculty_id=?"; $params[] = $facultyScope; }

if ($selectedDepartmentId) {
    /* (v24) د department filter دوه حالات تر پوشش لاندې راولي:
       1) د سمیستر خپله department_id برابر وي (له جدول سره دیرکټ)
       2) د دې سمیستر په لوری کوم ټولګي وي چې د ورتې department وي (ثابت پاتې کیږي) */
    $where .= " AND (s.department_id=? OR EXISTS (SELECT 1 FROM classes cd WHERE cd.semester_id=s.id AND cd.department_id=?))";
    $params[] = $selectedDepartmentId;
    $params[] = $selectedDepartmentId;
}

if ($selectedClassId) {
    $where .= " AND EXISTS (SELECT 1 FROM classes cc WHERE cc.semester_id=s.id AND cc.id=?)";
    $params[] = $selectedClassId;
}

$rowsStmt = $db->prepare(
    "SELECT s.*, f.name AS faculty_name,
            /* (v24) د سمیستر خپله دیپارټمنټ (اوس له schema چخه راځي) */
            sd.name AS direct_department_name,
            (SELECT COUNT(*) FROM classes  c
              WHERE c.semester_id=s.id
                AND c.faculty_id=s.faculty_id
                ".($selectedDepartmentId ? " AND c.department_id=".(int)$selectedDepartmentId : "")."
                ".($selectedClassId ? " AND c.id=".(int)$selectedClassId : "")."
            ) AS classes_count,
            (SELECT COUNT(*) FROM subjects sj
              WHERE sj.semester_id=s.id
                AND sj.faculty_id=s.faculty_id
                ".($selectedDepartmentId ? " AND sj.department_id=".(int)$selectedDepartmentId : "")."
            ) AS subjects_count,
            (SELECT GROUP_CONCAT(DISTINCT d.name ORDER BY d.name SEPARATOR ', ')
               FROM classes cdep
               JOIN departments d ON d.id=cdep.department_id
              WHERE cdep.semester_id=s.id
                AND cdep.faculty_id=s.faculty_id
                ".($selectedDepartmentId ? " AND cdep.department_id=".(int)$selectedDepartmentId : "")."
            ) AS department_name_via_classes,
            (SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT(ccl.name,' ',COALESCE(ccl.section,''))) ORDER BY ccl.name SEPARATOR ', ')
               FROM classes ccl
              WHERE ccl.semester_id=s.id
                AND ccl.faculty_id=s.faculty_id
                ".($selectedDepartmentId ? " AND ccl.department_id=".(int)$selectedDepartmentId : "")."
                ".($selectedClassId ? " AND ccl.id=".(int)$selectedClassId : "")."
            ) AS class_names,
            /* ─────────────────────────────────────────────────────────
               FIX (Pa) — د Studentsو شمېرنه د محصل پر *خپل* سمیستر
               (u.semester_id) پر بنسټ ده، نه د ټولګي په سمیستر.
               کله چې محصل له ۷ → ۸ سمیستر ته انتقال شي، د محصل
               u.semester_id بدلیږي خو د ټولګي semester_id ممکن لا
               زاړه ته اشاره وکړي؛ نو محصل د خپل دقیق سمیستر په قطار
               کې راڅرگند شي. که چېرته u.semester_id خالي وي، د ټولګي
               په سمیستر باندې fallback کوو ترڅو شاتنۍ مطابقت وساتو.
               ───────────────────────────────────────────────────────── */
            (SELECT COUNT(*) FROM users us
               LEFT JOIN classes csc ON csc.id=us.class_id
              WHERE (
                      us.semester_id = s.id
                      OR (us.semester_id IS NULL AND csc.semester_id = s.id)
                    )
                AND COALESCE(us.faculty_id, csc.faculty_id) = s.faculty_id
                AND us.role_id=".ROLE_STUDENT."
                AND us.status='active'
                AND us.deleted_at IS NULL
                ".($selectedDepartmentId ? " AND COALESCE(us.department_id, csc.department_id) = ".(int)$selectedDepartmentId : "")."
                ".($selectedClassId ? " AND us.class_id = ".(int)$selectedClassId : "")."
            ) AS students_count
       FROM semesters s
       JOIN faculties f ON f.id=s.faculty_id
       /* (v24) join په اختیاري توګه لپاره د بیرته تړل دیپارټمنټ */
       LEFT JOIN departments sd ON sd.id = s.department_id
       $where
   ORDER BY f.name, s.year_no, s.semester_no"
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0"><i class="fas fa-calendar-week me-2"></i><?= t('semesters') ?></h5>
  </div>
  <?= renderActionDropdown([
        'add_url'     => '?action=add'.$extraQS,
        'base_url'    => $selfUrl,
        'extra_query' => $extraQuery,
  ]) ?>
</div>
<?= renderModeBanner() ?>
<?php
echo renderClusterContextBanner([
    'faculty_id'    => $selectedFacultyId,
    'department_id' => $selectedDepartmentId,
    'class_id'      => $selectedClassId,
    'current_label' => 'Semester',
]);
?>

<form method="GET" class="row g-2 mb-3">
  <?php if (!$facultyScope): ?>
  <div class="col-md-3">
    <select name="faculty_id" data-cascade="faculty" class="form-select form-select-sm">
      <option value="">All faculties</option>
      <?php foreach ($faculties as $faculty): ?>
        <option value="<?= (int)$faculty['id'] ?>" <?= $selectedFacultyId === (int)$faculty['id'] ? 'selected' : '' ?>><?= h($faculty['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php else: ?>
    <input type="hidden" name="faculty_id" value="<?= (int)$facultyScope ?>"/>
  <?php endif; ?>

  <div class="col-md-3">
    <select name="department_id" data-cascade="department" class="form-select form-select-sm">
      <option value="">All departments</option>
      <?php foreach ($departments as $department): ?>
        <option value="<?= (int)$department['id'] ?>" <?= $selectedDepartmentId === (int)$department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-3">
    <select name="class_id" data-cascade="class" class="form-select form-select-sm">
      <option value="">All classes</option>
      <?php foreach ($classes as $cls):
          $clsLabel = trim($cls['name'].' '.($cls['section'] ?? ''));
      ?>
        <option value="<?= (int)$cls['id'] ?>" <?= $selectedClassId === (int)$cls['id'] ? 'selected' : '' ?>><?= h($clsLabel) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-3 d-flex align-items-start">
    <button class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
  </div>
</form>

<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="card mb-4"><div class="card-body">
  <form method="POST" autocomplete="off">
    <?php semesterCsrf(); ?>
    <input type="hidden" name="act" value="save"/>
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"/><?php endif; ?>
    <div class="row g-3">
      <?php

        
        
        $formFacultyId = (int)($editRow['faculty_id']
                                ?? ($selectedFacultyId ?: $facultyScope ?: 0));
      ?>
      <div class="col-md-3">
        <label class="form-label">Faculty <span class="text-danger">*</span></label>
        <select id="semFormFaculty" name="faculty_id" class="form-select"
                data-cascade="faculty"
                data-cascade-target="semFormDepartment"
                data-preselect="<?= h((string)$formFacultyId) ?>"
                <?= $facultyScope ? 'disabled' : 'required' ?>>
          <?php if (!$facultyScope): ?><option value="">-- Select --</option><?php endif; ?>
          <?php foreach ($faculties as $faculty): ?>
            <option value="<?= (int)$faculty['id'] ?>"
              <?= $formFacultyId === (int)$faculty['id'] ? 'selected' : '' ?>>
              <?= h($faculty['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($facultyScope): ?><input type="hidden" name="faculty_id" value="<?= (int)$facultyScope ?>"/><?php endif; ?>
      </div>

      <?php
      /* ── (v24) د Department جوړول، په cascade JS پورې تړلی ── */
      $formDepartmentId = (int)($editRow['department_id'] ?? ($selectedDepartmentId ?? 0));
      ?>
      <div class="col-md-3">
        <label class="form-label">Department <small class="text-muted">(optional)</small></label>
        <select id="semFormDepartment" name="department_id" class="form-select"
                data-cascade="department"
                data-cascade-source="semFormFaculty"
                data-cascade-target="semFormClass"
                data-preselect="<?= h((string)$formDepartmentId) ?>"
                data-placeholder="-- All Faculty / None --">
          <option value="">-- All Faculty / None --</option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int)$department['id'] ?>"
              <?= $formDepartmentId === (int)$department['id'] ? 'selected' : '' ?>>
              <?= h($department['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php
      /* ── (v25) د Class انتخاب — په cascade پورې تړلی.
         د Edit حالت کې د سمیستر باوري دیدل شوی ټولګى پرې پوهیږو، په Add حالت کې په query string پورې تړلی class_id. */
      $formClassId = 0;
      if ($editRow) {
          // د دې سمیستر سره تړلی ټولګي پیدا کړه (که یو ټولګى وي)
          $cLink = $db->prepare("SELECT id FROM classes WHERE semester_id=? AND faculty_id=? LIMIT 1");
          $cLink->execute([(int)$editRow['id'], (int)$editRow['faculty_id']]);
          $formClassId = (int)($cLink->fetchColumn() ?: 0);
      }
      if (!$formClassId) {
          $formClassId = (int)$selectedClassId;
      }
      ?>
      <div class="col-md-3">
        <label class="form-label">Class <small class="text-muted">(optional)</small></label>
        <select id="semFormClass" name="class_id" class="form-select"
                data-cascade="class"
                data-cascade-source="semFormDepartment"
                data-preselect="<?= h((string)$formClassId) ?>"
                data-placeholder="-- None --">
          <option value="">-- None --</option>
          <?php foreach ($classes as $cls):
              $clsLabel = trim($cls['name'].' '.($cls['section'] ?? ''));
          ?>
            <option value="<?= (int)$cls['id'] ?>"
              <?= $formClassId === (int)$cls['id'] ? 'selected' : '' ?>>
              <?= h($clsLabel) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Semester name <span class="text-muted small">(optional)</span></label>
        <input name="name" class="form-control" maxlength="60"
               value="<?= h($editRow['name'] ?? '') ?>"
               placeholder="e.g. Semester 1 (auto if empty)"/>
      </div>

      <div class="col-md-2">
        <label class="form-label">Semester No <span class="text-danger">*</span></label>
        <input type="number" name="semester_no" class="form-control" required min="1" max="8"
               value="<?= h((string)($editRow['semester_no'] ?? '')) ?>"/>
      </div>

      <div class="col-md-2">
        <label class="form-label">Year / Class <span class="text-danger">*</span></label>
        <input type="number" name="year_no" class="form-control" required min="1" max="4"
               value="<?= h((string)($editRow['year_no'] ?? '')) ?>"/>
      </div>

      <div class="col-md-2">
        <label class="form-label">Weeks</label>
        <input type="number" name="weeks_count" class="form-control" min="1" max="32"
               value="<?= h((string)($editRow['weeks_count'] ?? 16)) ?>"/>
      </div>

      <div class="col-md-3">
        <label class="form-label">Start date</label>
        <input type="date" name="start_date" class="form-control"
               value="<?= h($editRow['start_date'] ?? '') ?>"/>
      </div>

      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check">
          <input type="checkbox" name="is_active" id="semIsActive" class="form-check-input" value="1"
                 <?= (!$editRow || (int)($editRow['is_active'] ?? 1) === 1) ? 'checked' : '' ?>/>
          <label for="semIsActive" class="form-check-label">Active</label>
        </div>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button>
        <a href="<?= h($listUrl) ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </div>
  </form>
</div></div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead>
  <tr>
    <th>Faculty</th>
    <th>Department</th>
    <th>Name</th>
    <th>Subjects</th>
    <th>Students</th>
    <th>Views</th>
  </tr>
</thead>
<tbody>
<?php if (!$rows): ?>
  <tr><td colspan="6" class="text-center text-muted py-4">No semesters found.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $row):
    
    $editUrl = '?action=edit&id='.(int)$row['id'].$extraQS;

    
    
    $viewUrl = currentSectionPageUrl('semester_detail.php') . '?semester_id=' . (int)$row['id'];

    $delForm = '<form method="POST" class="d-inline">'
             . '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'
             . '<input type="hidden" name="act" value="delete"/>'
             . '<input type="hidden" name="id" value="'.(int)$row['id'].'"/>'
             . '<button class="btn btn-sm btn-danger" data-confirm="Delete / deactivate this semester?"><i class="fas fa-trash me-1"></i>Delete</button>'
             . '</form>';

    $togForm = '<form method="POST" class="d-inline">'
             . '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'
             . '<input type="hidden" name="act" value="toggle"/>'
             . '<input type="hidden" name="id" value="'.(int)$row['id'].'"/>'
             . '<button class="btn btn-sm btn-outline-secondary" title="Toggle active" data-confirm="Toggle status?"><i class="fas fa-power-off"></i></button>'
             . '</form>';
?>
<tr>
  <td><?= h($row['faculty_name']) ?></td>
  <?php
    /* (v24) ترجیح direct department > departments from classes */
    $deptLabel = $row['direct_department_name']
                  ?: ($row['department_name_via_classes'] ?: '-');
  ?>
  <td><?= h($deptLabel) ?></td>
  <td><?= h($row['name']) ?></td>
  <td><span class="badge bg-secondary"><?= (int)$row['subjects_count'] ?></span></td>
  <td><span class="badge bg-secondary"><?= (int)$row['students_count'] ?></span></td>
  <td class="text-nowrap">
    <?= renderRowActions([
        'view_url'    => $viewUrl,
        'view_label'  => 'View subjects & students',
        'edit_url'    => $editUrl,
        'delete_form' => $delForm . ' ' . $togForm,
    ]) ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
