<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 *  PUSAMS — د درجه‌بندي کتنه (Hierarchical View)
 *  Faculty → Department → Class → Semester → Subject → Students → Detail
 * ═══════════════════════════════════════════════════════════════════
 *  پښتو:  دا پاڼه د هر یوزر د صلاحیت په محدوده کې د پوهنتون
 *         درجه‌بندي معلومات (faculties, departments, classes,
 *         semesters, subjects, students, attendance details) ښيي.
 *         د معاون د Studentsو، د پوهنځي رئیس، مدیر، استاد او
 *         محصل لپاره په خپلواک ډول کار کوي.
 *
 *  Levels via ?level= and chained id parameters:
 *      level=root           → faculties (or single faculty for scoped roles)
 *      level=faculty&fid=#  → departments inside a faculty
 *      level=department&did=# → classes inside a department
 *      level=class&cid=#    → semesters that belong to the class
 *      level=semester&cid=#&sem=# → subjects + student panel for that
 *                                   class restricted to a single semester
 *      level=subject&cid=#&sem=#&sid=# → students with attendance for subject
 *      level=student&uid=#&sid=# (optional) → student detail (per-subject summary)
 * ═══════════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/lang.php';
requireLogin();

$db        = getDB();
$roleId    = (int)($_SESSION['role_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
$facultyScope = currentFacultyScopeId();
$pageTitle = t('hierarchy_view');

$level = $_GET['level'] ?? 'root';
$fid   = (int)($_GET['fid'] ?? 0);
$did   = (int)($_GET['did'] ?? 0);
$cid   = (int)($_GET['cid'] ?? 0);
$sid   = (int)($_GET['sid'] ?? 0);
$uid   = (int)($_GET['uid'] ?? 0);
// د Semester د طبقې ID (د Class او Subject ترمنځ نوې درجه)
$sem   = (int)($_GET['sem'] ?? 0);

// ───────────────────────────────────────────────────────────────────
// د یوزر اصلي صلاحیت چک — معاون د Studentsو ټول ګوري؛ نور یوازې خپله
// پوهنځۍ. استاد یوازې هغه ټولګي/مضامین چې تدریسوي یې. محصل یوازې
// خپل مضامین ګوري.
// ───────────────────────────────────────────────────────────────────
function fetchOne(PDO $db, string $sql, array $p): ?array {
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetch() ?: null;
}
function fetchAllRows(PDO $db, string $sql, array $p): array {
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

// د لینک جوړونه د درجه‌بندي په منځ کې
function hLink(array $params): string {
    $params['level'] = $params['level'] ?? 'root';
    return BASE_URL . '/hierarchy.php?' . http_build_query($params);
}

// د محصل د هر مضمون د غیر حضور فیصدي محاسبه (د سمسټر کریډیټونو پر بنسټ)
function studentSubjectAbsenceForClass(PDO $db, int $studentId, int $classId, int $subjectId): array {
    // د دقیق فرمول لپاره مرکزي helper فنکشن کارول کېږي.
    $weeklyStmt = $db->prepare("SELECT COALESCE(SUM(credit_units),0) FROM timetable
                                WHERE class_id=? AND subject_id=? AND status='approved'");
    $weeklyStmt->execute([$classId, $subjectId]);
    $weekly = (int)$weeklyStmt->fetchColumn();
    $semesterCredits = $weekly * (int)currentAcademicWeeks();

    $cntStmt = $db->prepare("SELECT
        SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS p,
        SUM(CASE WHEN ar.status='absent'  THEN 1 ELSE 0 END) AS a,
        COUNT(ar.id) AS total
        FROM attendance_records ar
        JOIN attendance_sessions s ON s.id = ar.session_id
        WHERE ar.student_id=? AND s.subject_id=? AND s.class_id=?");
    $cntStmt->execute([$studentId, $subjectId, $classId]);
    $row = $cntStmt->fetch() ?: ['p' => 0, 'a' => 0, 'total' => 0];

    $absent = (int)$row['a'];

    // ─── FIX (V23): د دې فنکشن نوم له `absencePctV11` څخه `absencePctClassic`
    //               ته بدل شوی و، خو دلته یې پاتې وه او د PHP fatal error
    //               یې راپیداکاوه — له دې امله د SEMESTER څخه ورسته د
    //               مضامین، محصلانو او خاضریو کچې هیڅ معلومات نه ښودل کېدل.
    // ────────────────────────────────────────────────────────────────────
    $pct = function_exists('absencePctClassic')
        ? absencePctClassic($absent, $weekly, (int)currentAcademicWeeks())
        : (function_exists('absencePctV11')
            ? absencePctV11($absent, $weekly, (int)currentAcademicWeeks())
            : 0.0);
    $warnAt = (float)attendanceWarningPercentThreshold();
    $depAt  = (float)attendanceDeprivationPercentThreshold();

    return [
        'present_count'    => (int)$row['p'],
        'absent_count'     => $absent,
        'total_marked'     => (int)$row['total'],
        'weekly_credits'   => $weekly,
        'semester_credits' => $semesterCredits,
        'absence_pct'      => $pct,
        'warning_at'       => $warnAt,
        'deprive_at'       => $depAt,
        'state'            => $pct >= $depAt ? 'deprived' : ($pct >= $warnAt ? 'warning' : 'safe'),
    ];
}

// ───────────────────────────────────────────────────────────────────
// د سکوپ کنترول (RBAC at every level)
// ───────────────────────────────────────────────────────────────────
function ensureFacultyAccess(int $fid, ?int $facultyScope, int $roleId): bool {
    if ($roleId === ROLE_ADMIN) return true;
    if ($facultyScope && $fid === $facultyScope) return true;
    return false;
}

// ═══════════════════════════════════════════════════════════════════
// د عمیق هایراکیکل لید لپاره د رول د اجازې کنترول.
// یوازې لاندې درې رولونه دلته د "root/faculty/department" کچه کارولی شي:
//   - معاون د Studentsو (ROLE_ADMIN) — د ټول پوهنتون
//   - د پوهنځۍ رئیس (ROLE_DEPT_HEAD) — یوازې د خپلې پوهنځۍ
//   - مدیر د پوهنځۍ (ROLE_ATT_MGR) — یوازې د خپلې پوهنځۍ
// استاد یوازې د خپلو Classو/مضامینو لید لري؛ محصل یوازې د خپلو خاضریو.
// ═══════════════════════════════════════════════════════════════════
if (!hasDeepHierarchyAccess($roleId)
    && $roleId !== ROLE_TEACHER && $roleId !== ROLE_STUDENT) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger"><i class="fas fa-shield-halved me-2"></i>Access denied — full hierarchy view is only available to VC, Dean, and Manager.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// د محصل لپاره: یوازې خپل کلاس (REQ #2 — پوره RBAC)
if ($roleId === ROLE_STUDENT) {
    $studentClassId = (int)($_SESSION['class_id'] ?? 0);
    // د محصل لپاره تل جبري خپل کلاس او خپل ID
    $cid = $studentClassId;
    $uid = $userId;
    // د بل محصل د لیدو هر کوشش باید بلاک شي
    if ($level === 'root' || $level === 'faculty' || $level === 'department') {
        $level = 'class';
    }
    // محصل د Semester کچې ته خو لاسرسی لري — ولې یوازې د خپل ټولګي.
    if ($level === 'semester' && $cid !== $studentClassId) {
        $level = 'class';
        $cid   = $studentClassId;
    }
    if ($level === 'student' && $uid !== $userId) {
        // Block access to other students' data
        require_once __DIR__ . '/includes/header.php';
        echo '<div class="alert alert-danger"><i class="fas fa-shield-halved me-2"></i>Access denied — you can only view your own attendance.</div>';
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }
    // Block subject-level view that exposes other students' data
    if ($level === 'subject') {
        // Redirect to student's own subject view
        $level = 'student';
        $uid = $userId;
    }
}

// د استاد لپاره: یوازې هغه ټولګي/مضامین چې تدریسوي یې
$teacherClasses = [];
$teacherSubjectIds = [];
if ($roleId === ROLE_TEACHER) {
    $rows = fetchAllRows($db,
        "SELECT DISTINCT c.id AS class_id, c.name AS class_name, c.section,
                          d.name AS dept_name, f.id AS faculty_id, f.name AS faculty_name
         FROM timetable t
         JOIN classes c ON c.id = t.class_id
         LEFT JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = t.faculty_id
         WHERE t.teacher_id = ? AND t.status = 'approved'
         ORDER BY f.name, d.name, c.name", [$userId]);
    $teacherClasses = $rows;
    $sub = fetchAllRows($db,
        "SELECT DISTINCT subject_id FROM timetable WHERE teacher_id=? AND status='approved'",
        [$userId]);
    $teacherSubjectIds = array_column($sub, 'subject_id');
    if ($level === 'root' || $level === 'faculty' || $level === 'department') {
        $level = 'teacher_classes';
    }
}

require_once __DIR__ . '/includes/header.php';

// ───────────────────────────────────────────────────────────────────
// د درجه‌بندي د لارې (breadcrumb) جوړول
// ───────────────────────────────────────────────────────────────────
$crumbs = [['label' => '<i class="fas fa-home"></i> ' . t('home'), 'url' => hLink(['level' => 'root'])]];

// د عنوان او لارې جوړول
if ($fid > 0) {
    $f = fetchOne($db, "SELECT id, name, code FROM faculties WHERE id=? AND is_active=1", [$fid]);
    if ($f) $crumbs[] = ['label' => h($f['name']), 'url' => hLink(['level' => 'faculty', 'fid' => $fid])];
}
if ($did > 0) {
    $d = fetchOne($db, "SELECT id, name, faculty_id FROM departments WHERE id=? AND is_active=1", [$did]);
    if ($d) $crumbs[] = ['label' => h($d['name']), 'url' => hLink(['level' => 'department', 'fid' => $fid ?: $d['faculty_id'], 'did' => $did])];
}
if ($cid > 0) {
    $c = fetchOne($db, "SELECT id, name, section, faculty_id, department_id FROM classes WHERE id=? AND is_active=1", [$cid]);
    if ($c) $crumbs[] = ['label' => h($c['name'] . ' ' . ($c['section'] ?? '')), 'url' => hLink(['level' => 'class', 'fid' => $fid ?: $c['faculty_id'], 'did' => $did ?: $c['department_id'], 'cid' => $cid])];
}
// Semester crumb — د Class او Subject ترمنځ نوې درجه
if ($sem > 0 && in_array($level, ['semester','subject','student'], true)) {
    $semRow = fetchOne($db, "SELECT id, name, semester_no FROM semesters WHERE id=? AND is_active=1", [$sem]);
    if ($semRow) $crumbs[] = ['label' => h($semRow['name']), 'url' => hLink(['level' => 'semester', 'fid' => $fid, 'did' => $did, 'cid' => $cid, 'sem' => $sem])];
}
if ($sid > 0 && $level !== 'class' && $level !== 'semester') {
    $s = fetchOne($db, "SELECT id, name, code FROM subjects WHERE id=? AND is_active=1", [$sid]);
    if ($s) $crumbs[] = ['label' => h($s['name']), 'url' => hLink(['level' => 'subject', 'fid' => $fid, 'did' => $did, 'cid' => $cid, 'sem' => $sem, 'sid' => $sid])];
}
if ($uid > 0 && $level === 'student') {
    $u = fetchOne($db, "SELECT id, full_name FROM users WHERE id=?", [$uid]);
    if ($u) $crumbs[] = ['label' => h($u['full_name']), 'url' => '#'];
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h5 class="mb-1"><i class="fas fa-sitemap me-2"></i><?= t('hierarchy_view') ?></h5>
    <div class="text-muted small"><?= t('hierarchy_subtitle') ?></div>
  </div>
  <div class="text-muted small"><?= t('risk_threshold_info', [
      'warn' => attendanceWarningPercentThreshold(),
      'dep'  => attendanceDeprivationPercentThreshold(),
  ]) ?></div>
</div>

<div class="hierarchy-breadcrumb">
  <?php $last = count($crumbs) - 1; foreach ($crumbs as $i => $c): ?>
    <?php if ($i === $last): ?>
      <span class="crumb-current"><?= $c['label'] ?></span>
    <?php else: ?>
      <a href="<?= h($c['url']) ?>"><?= $c['label'] ?></a>
      <span class="crumb-sep"><i class="fas fa-chevron-<?= isRtl() ? 'left' : 'right' ?>"></i></span>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<?php
// ════════════════════════════════════════════════════════════════════
// LEVEL: ROOT — د پوهنځیو لیست
// ════════════════════════════════════════════════════════════════════
if ($level === 'root'):
    $sql = "SELECT f.*,
              (SELECT COUNT(*) FROM departments d WHERE d.faculty_id=f.id AND d.is_active=1) AS dept_cnt,
              (SELECT COUNT(*) FROM classes    c WHERE c.faculty_id=f.id AND c.is_active=1) AS class_cnt,
              (SELECT COUNT(*) FROM subjects   s WHERE s.faculty_id=f.id AND s.is_active=1) AS subject_cnt,
              (SELECT COUNT(*) FROM users      u WHERE u.faculty_id=f.id AND u.role_id=" . ROLE_STUDENT . " AND u.deleted_at IS NULL) AS student_cnt
            FROM faculties f WHERE f.is_active=1";
    $params = [];
    if ($facultyScope) { $sql .= " AND f.id=?"; $params[] = $facultyScope; }
    $sql .= " ORDER BY f.name";
    $rows = fetchAllRows($db, $sql, $params);
?>
<h6 class="mt-2 mb-3"><i class="fas fa-building-columns me-2 text-primary"></i><?= t('level_faculties') ?> <span class="badge bg-secondary"><?= count($rows) ?></span></h6>
<div class="row g-3">
  <?php if (!$rows): ?><div class="col-12"><div class="alert alert-info"><?= t('no_data_found') ?></div></div><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <div class="col-md-6 col-lg-4">
      <a href="<?= h(hLink(['level' => 'faculty', 'fid' => (int)$r['id']])) ?>" class="hierarchy-card">
        <div>
          <div class="hc-icon"><i class="fas fa-building-columns"></i></div>
          <div class="hc-title"><?= h($r['name']) ?></div>
          <div class="hc-meta"><code><?= h($r['code']) ?></code></div>
        </div>
        <div class="hc-stats">
          <span class="hc-chip"><?= t('card_departments') ?>: <?= (int)$r['dept_cnt'] ?></span>
          <span class="hc-chip"><?= t('card_classes') ?>: <?= (int)$r['class_cnt'] ?></span>
          <span class="hc-chip"><?= t('card_subjects') ?>: <?= (int)$r['subject_cnt'] ?></span>
          <span class="hc-chip success"><?= t('card_students') ?>: <?= (int)$r['student_cnt'] ?></span>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php
// ════════════════════════════════════════════════════════════════════
// LEVEL: TEACHER_CLASSES — د استاد ټولګي
// ════════════════════════════════════════════════════════════════════
elseif ($level === 'teacher_classes'):
?>
<h6 class="mt-2 mb-3"><i class="fas fa-chalkboard me-2 text-primary"></i><?= t('my_students') ?> · <?= t('level_classes') ?></h6>
<div class="row g-3">
  <?php if (!$teacherClasses): ?><div class="col-12"><div class="alert alert-info"><?= t('no_data_found') ?></div></div><?php endif; ?>
  <?php foreach ($teacherClasses as $tc): ?>
    <div class="col-md-6 col-lg-4">
      <a href="<?= h(hLink(['level' => 'class', 'fid' => (int)$tc['faculty_id'], 'cid' => (int)$tc['class_id']])) ?>" class="hierarchy-card">
        <div>
          <div class="hc-icon"><i class="fas fa-chalkboard"></i></div>
          <div class="hc-title"><?= h($tc['class_name'] . ' ' . ($tc['section'] ?? '')) ?></div>
          <div class="hc-meta"><?= h($tc['faculty_name']) ?> · <?= h($tc['dept_name'] ?? '—') ?></div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php
// ════════════════════════════════════════════════════════════════════
// LEVEL: FACULTY — د څانګو لیست
// ════════════════════════════════════════════════════════════════════
elseif ($level === 'faculty'):
    if (!ensureFacultyAccess($fid, $facultyScope, $roleId)) {
        echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
    } else {
        $f = fetchOne($db, "SELECT * FROM faculties WHERE id=? AND is_active=1", [$fid]);
        $deps = fetchAllRows($db,
            "SELECT d.*,
                (SELECT COUNT(*) FROM classes c  WHERE c.department_id=d.id AND c.is_active=1)   AS class_cnt,
                (SELECT COUNT(*) FROM subjects s WHERE s.department_id=d.id AND s.is_active=1)   AS subject_cnt,
                (SELECT COUNT(*) FROM users u    WHERE u.department_id=d.id AND u.role_id=" . ROLE_STUDENT . " AND u.deleted_at IS NULL) AS student_cnt
             FROM departments d WHERE d.faculty_id=? AND d.is_active=1 ORDER BY d.name",
            [$fid]);
?>
<h6 class="mt-2 mb-3"><i class="fas fa-building me-2 text-primary"></i><?= t('depts_in_faculty') ?>: <?= h($f['name'] ?? '') ?> <span class="badge bg-secondary"><?= count($deps) ?></span></h6>
<div class="row g-3">
  <?php if (!$deps): ?><div class="col-12"><div class="alert alert-info"><?= t('no_data_found') ?></div></div><?php endif; ?>
  <?php foreach ($deps as $d): ?>
    <div class="col-md-6 col-lg-4">
      <a href="<?= h(hLink(['level' => 'department', 'fid' => $fid, 'did' => (int)$d['id']])) ?>" class="hierarchy-card">
        <div>
          <div class="hc-icon"><i class="fas fa-building"></i></div>
          <div class="hc-title"><?= h($d['name']) ?></div>
          <div class="hc-meta"><code><?= h($d['code']) ?></code></div>
        </div>
        <div class="hc-stats">
          <span class="hc-chip"><?= t('card_classes') ?>: <?= (int)$d['class_cnt'] ?></span>
          <span class="hc-chip"><?= t('card_subjects') ?>: <?= (int)$d['subject_cnt'] ?></span>
          <span class="hc-chip success"><?= t('card_students') ?>: <?= (int)$d['student_cnt'] ?></span>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>
<?php
    }
// ════════════════════════════════════════════════════════════════════
// LEVEL: DEPARTMENT — د Classو لیست
// ════════════════════════════════════════════════════════════════════
elseif ($level === 'department'):
    $d = fetchOne($db, "SELECT * FROM departments WHERE id=? AND is_active=1", [$did]);
    if (!$d || !ensureFacultyAccess((int)$d['faculty_id'], $facultyScope, $roleId)) {
        echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
    } else {
        $classes = fetchAllRows($db,
            "SELECT c.*,
                (SELECT COUNT(*) FROM users u WHERE u.class_id=c.id AND u.role_id=" . ROLE_STUDENT . " AND u.deleted_at IS NULL) AS student_cnt,
                (SELECT COUNT(DISTINCT t.subject_id) FROM timetable t WHERE t.class_id=c.id AND t.status='approved') AS subject_cnt
             FROM classes c WHERE c.department_id=? AND c.is_active=1 ORDER BY c.name, c.section",
            [$did]);
?>
<h6 class="mt-2 mb-3"><i class="fas fa-chalkboard me-2 text-primary"></i><?= t('classes_in_dept') ?>: <?= h($d['name']) ?> <span class="badge bg-secondary"><?= count($classes) ?></span></h6>
<div class="row g-3">
  <?php if (!$classes): ?><div class="col-12"><div class="alert alert-info"><?= t('no_data_found') ?></div></div><?php endif; ?>
  <?php foreach ($classes as $c): ?>
    <div class="col-md-6 col-lg-4">
      <a href="<?= h(hLink(['level' => 'class', 'fid' => (int)$d['faculty_id'], 'did' => $did, 'cid' => (int)$c['id']])) ?>" class="hierarchy-card">
        <div>
          <div class="hc-icon"><i class="fas fa-chalkboard"></i></div>
          <div class="hc-title"><?= h($c['name'] . ' ' . ($c['section'] ?? '')) ?></div>
          <div class="hc-meta"><?= h(ucfirst($c['class_type'] ?? '')) ?></div>
        </div>
        <div class="hc-stats">
          <span class="hc-chip"><?= t('card_subjects') ?>: <?= (int)$c['subject_cnt'] ?></span>
          <span class="hc-chip success"><?= t('card_students') ?>: <?= (int)$c['student_cnt'] ?></span>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>
<?php
    }
// ════════════════════════════════════════════════════════════════════
// LEVEL: CLASS — د Semesters لیست (د Class او Subject ترمنځ نوې درجه)
// ════════════════════════════════════════════════════════════════════
elseif ($level === 'class'):
    $c = fetchOne($db, "SELECT * FROM classes WHERE id=? AND is_active=1", [$cid]);
    if ($roleId === ROLE_STUDENT && (int)($_SESSION['class_id'] ?? 0) !== $cid) {
        echo '<div class="alert alert-danger"><i class="fas fa-shield-halved me-2"></i>Viewing another class is not permitted.</div>';
    } elseif (!$c || ($roleId !== ROLE_ADMIN && $roleId !== ROLE_STUDENT && $roleId !== ROLE_TEACHER && !ensureFacultyAccess((int)$c['faculty_id'], $facultyScope, $roleId))) {
        echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
    } else {
        // د دې ټولګي پورې اړوند سمیسټرونه (د جوړې پر بنسټ: 1-2، 3-4، 5-6، 7-8)
        $semesters = function_exists('listSemestersByClass') ? listSemestersByClass($cid) : [];

        // د هر سمیسټر د subjects/credits شمېرنه — د UI لپاره
        $semStats = [];
        if ($semesters) {
            $semIds   = array_map(fn($r) => (int)$r['id'], $semesters);
            $place    = implode(',', array_fill(0, count($semIds), '?'));
            $statStmt = $db->prepare(
                "SELECT s.semester_id,
                        COUNT(DISTINCT s.id)                AS subj_cnt,
                        COALESCE(SUM(t.credit_units),0)     AS weekly_credits
                 FROM subjects s
                 LEFT JOIN timetable t
                        ON t.subject_id = s.id
                       AND t.class_id   = ?
                       AND t.status     = 'approved'
                 WHERE s.is_active = 1
                   AND s.semester_id IN ($place)
                 GROUP BY s.semester_id"
            );
            $statStmt->execute(array_merge([$cid], $semIds));
            foreach ($statStmt->fetchAll() as $row) {
                $semStats[(int)$row['semester_id']] = [
                    'subj_cnt'       => (int)$row['subj_cnt'],
                    'weekly_credits' => (int)$row['weekly_credits'],
                ];
            }
        }

        // د ټولګي د Studentsو لیست (د Studentsو پانل د Class کچې سره مل وي)
        $students = [];
        if ($roleId !== ROLE_STUDENT) {
            $students = fetchAllRows($db,
                "SELECT id, full_name, student_id FROM users
                 WHERE class_id=? AND role_id=" . ROLE_STUDENT . " AND status='active' AND deleted_at IS NULL
                 ORDER BY full_name",
                [$cid]);
        }
?>
<div class="row g-4">
  <div class="col-lg-7">
    <h6 class="mb-3"><i class="fas fa-layer-group me-2 text-primary"></i><?= (t('semesters') ?: 'Semesters') ?>: <?= h($c['name'] . ' ' . ($c['section'] ?? '')) ?> <span class="badge bg-secondary"><?= count($semesters) ?></span></h6>
    <div class="row g-3">
      <?php if (!$semesters): ?><div class="col-12"><div class="alert alert-info"><?= t('no_data_found') ?></div></div><?php endif; ?>
      <?php foreach ($semesters as $smRow):
          $smId    = (int)$smRow['id'];
          $stRow   = $semStats[$smId] ?? ['subj_cnt' => 0, 'weekly_credits' => 0];
          $semTot  = (int)$stRow['weekly_credits'] * (int)currentAcademicWeeks();
      ?>
        <div class="col-md-6">
          <a href="<?= h(hLink(['level' => 'semester', 'fid' => (int)$c['faculty_id'], 'did' => (int)($c['department_id'] ?? 0), 'cid' => $cid, 'sem' => $smId])) ?>" class="hierarchy-card">
            <div>
              <div class="hc-icon"><i class="fas fa-layer-group"></i></div>
              <div class="hc-title"><?= h($smRow['name']) ?></div>
              <div class="hc-meta"><?= (t('year') ?: 'Year') ?> <?= (int)$smRow['year_no'] ?> · <?= (t('semester_no') ?: 'Sem') ?> <?= (int)$smRow['semester_no'] ?></div>
            </div>
            <div class="hc-stats">
              <span class="hc-chip"><?= t('card_subjects') ?>: <?= (int)$stRow['subj_cnt'] ?></span>
              <span class="hc-chip"><?= t('credit') ?>/week: <?= (int)$stRow['weekly_credits'] ?></span>
              <span class="hc-chip success"><?= t('semester_credits_total') ?>: <?= $semTot ?></span>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($roleId !== ROLE_STUDENT): ?>
  <div class="col-lg-5">
    <h6 class="mb-3"><i class="fas fa-user-graduate me-2 text-primary"></i><?= t('students_in_class') ?> <span class="badge bg-secondary"><?= count($students) ?></span></h6>
    <div class="card hierarchy-card p-0" style="display:block">
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
          <thead class="table-light"><tr><th>#</th><th><?= t('student') ?></th><th class="text-end"><?= t('view') ?></th></tr></thead>
          <tbody>
          <?php if (!$students): ?><tr><td colspan="3" class="text-center text-muted py-3"><?= t('no_data_found') ?></td></tr><?php endif; ?>
          <?php foreach ($students as $i => $stu): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>
                <div class="fw-semibold"><?= h($stu['full_name']) ?></div>
                <div class="small text-muted">@<?= h($stu['student_id'] ?? '—') ?></div>
              </td>
              <td class="text-end">
                <a href="<?= h(hLink(['level' => 'student', 'fid' => (int)$c['faculty_id'], 'did' => (int)($c['department_id'] ?? 0), 'cid' => $cid, 'uid' => (int)$stu['id']])) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
    }
// ════════════════════════════════════════════════════════════════════
// LEVEL: SEMESTER — د یوې ټاکلې Class او Semester جوړې لپاره مضامین
//                   (د Class او Subject ترمنځ نوې درجه)
// ════════════════════════════════════════════════════════════════════
elseif ($level === 'semester'):
    $c      = fetchOne($db, "SELECT * FROM classes WHERE id=? AND is_active=1", [$cid]);
    $semRow = fetchOne($db, "SELECT * FROM semesters WHERE id=? AND is_active=1", [$sem]);

    // RBAC چک — د class او سمیسټر دواړو لپاره
    $semIsAllowedForClass = $semRow && $c && function_exists('classAllowedSemesterIds')
        && in_array((int)$sem, classAllowedSemesterIds($cid), true);

    if ($roleId === ROLE_STUDENT && (int)($_SESSION['class_id'] ?? 0) !== $cid) {
        echo '<div class="alert alert-danger"><i class="fas fa-shield-halved me-2"></i>Viewing another class is not permitted.</div>';
    } elseif (!$c || !$semRow || !$semIsAllowedForClass) {
        echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
    } elseif ($roleId !== ROLE_ADMIN && $roleId !== ROLE_STUDENT && $roleId !== ROLE_TEACHER && !ensureFacultyAccess((int)$c['faculty_id'], $facultyScope, $roleId)) {
        echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
    } else {
        // د دې سمیسټر مضامین چې له دې ټولګي سره په timetable کې مل دي.
        $subjSql = "SELECT s.*, COALESCE(SUM(t.credit_units),0) AS weekly_credits,
                           u.full_name AS teacher_name, u.id AS teacher_id
                    FROM subjects s
                    LEFT JOIN timetable t ON t.subject_id=s.id AND t.class_id=? AND t.status='approved'
                    LEFT JOIN users u ON u.id=t.teacher_id
                    WHERE s.is_active=1
                      AND s.semester_id = ?
                    GROUP BY s.id, u.id
                    ORDER BY s.name";
        $subjects = fetchAllRows($db, $subjSql, [$cid, $sem]);

        if ($roleId === ROLE_TEACHER) {
            $subjects = array_filter($subjects, fn($r) => in_array((int)$r['id'], array_map('intval', $teacherSubjectIds), true));
        }

        $students = [];
        if ($roleId !== ROLE_STUDENT) {
            $students = fetchAllRows($db,
                "SELECT id, full_name, student_id FROM users
                 WHERE class_id=? AND role_id=" . ROLE_STUDENT . " AND status='active' AND deleted_at IS NULL
                 ORDER BY full_name",
                [$cid]);
        }
?>
<div class="row g-4">
  <div class="col-lg-7">
    <h6 class="mb-3"><i class="fas fa-book me-2 text-primary"></i><?= t('subjects_for_class') ?>: <?= h($c['name'] . ' ' . ($c['section'] ?? '')) ?> · <?= h($semRow['name']) ?> <span class="badge bg-secondary"><?= count($subjects) ?></span></h6>
    <div class="row g-3">
      <?php if (!$subjects): ?><div class="col-12"><div class="alert alert-info"><?= t('no_data_found') ?></div></div><?php endif; ?>
      <?php foreach ($subjects as $sj):
          $semCredits = (int)$sj['weekly_credits'] * (int)currentAcademicWeeks();
      ?>
        <div class="col-md-6">
          <a href="<?= h(hLink(['level' => 'subject', 'fid' => (int)$c['faculty_id'], 'did' => (int)($c['department_id'] ?? 0), 'cid' => $cid, 'sem' => $sem, 'sid' => (int)$sj['id']])) ?>" class="hierarchy-card">
            <div>
              <div class="hc-icon"><i class="fas fa-book"></i></div>
              <div class="hc-title"><?= h($sj['name']) ?></div>
              <div class="hc-meta"><code><?= h($sj['code']) ?></code> · <?= h($sj['teacher_name'] ?? '—') ?></div>
            </div>
            <div class="hc-stats">
              <span class="hc-chip"><?= t('credit') ?>/week: <?= (int)$sj['weekly_credits'] ?></span>
              <span class="hc-chip"><?= t('semester_credits_total') ?>: <?= $semCredits ?></span>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($roleId !== ROLE_STUDENT): ?>
  <div class="col-lg-5">
    <h6 class="mb-3"><i class="fas fa-user-graduate me-2 text-primary"></i><?= t('students_in_class') ?> <span class="badge bg-secondary"><?= count($students) ?></span></h6>
    <div class="card hierarchy-card p-0" style="display:block">
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
          <thead class="table-light"><tr><th>#</th><th><?= t('student') ?></th><th class="text-end"><?= t('view') ?></th></tr></thead>
          <tbody>
          <?php if (!$students): ?><tr><td colspan="3" class="text-center text-muted py-3"><?= t('no_data_found') ?></td></tr><?php endif; ?>
          <?php foreach ($students as $i => $stu): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>
                <div class="fw-semibold"><?= h($stu['full_name']) ?></div>
                <div class="small text-muted">@<?= h($stu['student_id'] ?? '—') ?></div>
              </td>
              <td class="text-end">
                <a href="<?= h(hLink(['level' => 'student', 'fid' => (int)$c['faculty_id'], 'did' => (int)($c['department_id'] ?? 0), 'cid' => $cid, 'sem' => $sem, 'uid' => (int)$stu['id']])) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
    }

// ════════════════════════════════════════════════════════════════════
// LEVEL: SUBJECT — د یو مضمون لپاره د Studentsو حضور تفصیل
// ════════════════════════════════════════════════════════════════════
elseif ($level === 'subject'):
    $c  = fetchOne($db, "SELECT * FROM classes  WHERE id=? AND is_active=1", [$cid]);
    $sj = fetchOne($db, "SELECT * FROM subjects WHERE id=? AND is_active=1", [$sid]);
    if ($roleId === ROLE_STUDENT) {
        echo '<div class="alert alert-danger"><i class="fas fa-shield-halved me-2"></i>لاسرسی بلاک دی — تاسو صرف خپل حضور کتلای شئ.</div>';
    } elseif (!$c || !$sj || ($roleId !== ROLE_ADMIN && $roleId !== ROLE_TEACHER && !ensureFacultyAccess((int)$c['faculty_id'], $facultyScope, $roleId))) {
        echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
    } else {
        if ($roleId === ROLE_TEACHER && !in_array($sid, array_map('intval', $teacherSubjectIds), true)) {
            echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
        } else {
            $weeklyStmt = $db->prepare("SELECT COALESCE(SUM(credit_units),0) FROM timetable WHERE class_id=? AND subject_id=? AND status='approved'");
            $weeklyStmt->execute([$cid, $sid]);
            $weekly = (int)$weeklyStmt->fetchColumn();
            $semCredits = $weekly * (int)currentAcademicWeeks();

            $students = fetchAllRows($db,
                "SELECT id, full_name, student_id FROM users
                 WHERE class_id=? AND role_id=" . ROLE_STUDENT . " AND status='active' AND deleted_at IS NULL
                 ORDER BY full_name", [$cid]);

            $rows = [];
            foreach ($students as $stu) {
                $rows[] = ['stu' => $stu] + studentSubjectAbsenceForClass($db, (int)$stu['id'], $cid, $sid);
            }
            usort($rows, fn($a, $b) => $b['absence_pct'] <=> $a['absence_pct']);
?>
<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="hierarchy-card"><div><div class="hc-icon"><i class="fas fa-book"></i></div><div class="hc-title"><?= h($sj['name']) ?></div><div class="hc-meta"><code><?= h($sj['code']) ?></code></div></div></div></div>
  <div class="col-md-3"><div class="hierarchy-card"><div><div class="hc-icon"><i class="fas fa-clock"></i></div><div class="hc-title"><?= $weekly ?> <?= t('credit') ?>/week</div><div class="hc-meta"><?= t('semester_credits_total') ?>: <?= $semCredits ?></div></div></div></div>
  <div class="col-md-3"><div class="hierarchy-card"><div><div class="hc-icon"><i class="fas fa-chalkboard"></i></div><div class="hc-title"><?= h($c['name'] . ' ' . ($c['section'] ?? '')) ?></div><div class="hc-meta"><?= count($students) ?> <?= t('card_students') ?></div></div></div></div>
  <div class="col-md-3"><div class="hierarchy-card"><div><div class="hc-icon"><i class="fas fa-shield-halved"></i></div><div class="hc-title"><?= attendanceWarningPercentThreshold() ?>% / <?= attendanceDeprivationPercentThreshold() ?>%</div><div class="hc-meta"><?= t('warning_state') ?> / <?= t('deprived_state') ?></div></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list-check me-2"></i><?= t('students_in_class') ?> – <?= h($sj['name']) ?></span>
    <small class="text-muted"><?= t('risk_formula_help') ?></small>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th><?= t('student') ?></th>
          <th class="text-center"><?= t('present_count') ?></th>
          <th class="text-center"><?= t('absent_count') ?></th>
          <th class="text-center"><?= t('semester_credits_total') ?></th>
          <th class="text-center"><?= t('absence_percent') ?></th>
          <th class="text-center"><?= t('attendance_status') ?></th>
          <th class="text-end"><?= t('view') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= t('no_data_found') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $i => $r):
            $cls = $r['state'] === 'deprived' ? 'is-deprived' : ($r['state'] === 'warning' ? 'is-warning' : '');
        ?>
          <tr class="attendance-summary-row <?= $cls ?>">
            <td><?= $i + 1 ?></td>
            <td>
              <div class="fw-semibold"><?= h($r['stu']['full_name']) ?></div>
              <div class="small text-muted">@<?= h($r['stu']['student_id'] ?? '—') ?></div>
            </td>
            <td class="text-center"><span class="badge bg-success"><?= $r['present_count'] ?></span></td>
            <td class="text-center"><span class="badge bg-danger"><?= $r['absent_count'] ?></span></td>
            <td class="text-center"><?= $r['semester_credits'] ?></td>
            <td class="text-center"><span class="badge bg-<?= absencePercentClass($r['absence_pct']) ?>"><?= $r['absence_pct'] ?>%</span></td>
            <td class="text-center">
              <?php if ($r['state'] === 'deprived'): ?>
                <span class="badge bg-danger"><i class="fas fa-ban"></i> <?= t('deprived_state') ?></span>
              <?php elseif ($r['state'] === 'warning'): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-triangle-exclamation"></i> <?= t('warning_state') ?></span>
              <?php else: ?>
                <span class="badge bg-success"><i class="fas fa-check"></i> <?= t('safe') ?></span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="<?= h(hLink(['level' => 'student', 'fid' => (int)$c['faculty_id'], 'did' => (int)($c['department_id'] ?? 0), 'cid' => $cid, 'sem' => $sem, 'sid' => $sid, 'uid' => (int)$r['stu']['id']])) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
        }
    }
// ════════════════════════════════════════════════════════════════════
// LEVEL: STUDENT — د محصل بشپړ تفصیل
// ════════════════════════════════════════════════════════════════════
elseif ($level === 'student'):
    $stu = fetchOne($db, "SELECT u.*, c.name AS class_name, c.section, f.name AS faculty_name, d.name AS dept_name
                          FROM users u
                          LEFT JOIN classes     c ON c.id=u.class_id
                          LEFT JOIN faculties   f ON f.id=u.faculty_id
                          LEFT JOIN departments d ON d.id=u.department_id
                          WHERE u.id=? AND u.role_id=" . ROLE_STUDENT . " LIMIT 1", [$uid]);
    if (!$stu || ($roleId !== ROLE_ADMIN && $roleId !== ROLE_STUDENT && !ensureFacultyAccess((int)$stu['faculty_id'], $facultyScope, $roleId))) {
        echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
    } else {
        // RBAC: محصل یوازې خپل، استاد یوازې د هغه ټولګي چې تدریسوي یې
        if ($roleId === ROLE_STUDENT && $uid !== $userId) {
            echo '<div class="alert alert-danger">' . h(t('no_data_found')) . '</div>';
        } else {
            // د هر مضمون لپاره د حضور لنډیز + هماغه ژوره کتنه چې محصل په خپل پاڼه کې ویني
            $subjects = studentSubjectAttendanceSummary((int)$stu['id']);
            $groupBy = $_GET['group'] ?? 'semester';
            $selectedSubjectId = (int)($_GET['subject'] ?? ($sid ?: ($subjects[0]['subject_id'] ?? 0)));
            $selectedSubject = null;
            foreach ($subjects as $subjectRow) {
                if ((int)($subjectRow['subject_id'] ?? 0) === $selectedSubjectId) {
                    $selectedSubject = $subjectRow;
                    break;
                }
            }
            $history = [];
            $timeline = [];
            if ($selectedSubject) {
                try { $history = studentAttendanceHistory((int)$stu['id'], (int)$selectedSubject['subject_id'], $groupBy); } catch (Throwable $e) { $history = []; }
                try { $timeline = studentAttendanceTimeline((int)$stu['id'], (int)$selectedSubject['subject_id'], 120); } catch (Throwable $e) { $timeline = []; }
                $sid = (int)$selectedSubject['subject_id'];
            }
?>
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="hierarchy-card">
      <div>
        <div class="hc-icon"><i class="fas fa-user-graduate"></i></div>
        <div class="hc-title"><?= h($stu['full_name']) ?></div>
        <div class="hc-meta">@<?= h($stu['student_id'] ?? $stu['username']) ?></div>
      </div>
      <div class="hc-stats">
        <span class="hc-chip"><?= h($stu['faculty_name'] ?? '—') ?></span>
        <span class="hc-chip"><?= h($stu['dept_name'] ?? '—') ?></span>
        <span class="hc-chip"><?= h(($stu['class_name'] ?? '—') . ' ' . ($stu['section'] ?? '')) ?></span>
      </div>
    </div>
  </div>
  <?php
    $totAbsent = array_sum(array_column($subjects, 'absent_count'));
    $totSched  = array_sum(array_column($subjects, 'semester_credits'));
    $overall   = $totSched > 0 ? round(($totAbsent / $totSched) * 100, 2) : 0.0;
    $deprivedCnt = count(array_filter($subjects, fn($s) => !empty($s['deprived'])));
    $warnCnt = count(array_filter($subjects, fn($s) => !empty($s['warning_reached']) && empty($s['deprived'])));
  ?>
  <div class="col-lg-4">
    <div class="hierarchy-card">
      <div>
        <div class="hc-icon"><i class="fas fa-chart-pie"></i></div>
        <div class="hc-title text-<?= absencePercentClass($overall) ?>"><?= $overall ?>%</div>
        <div class="hc-meta"><?= t('overall_absence') ?></div>
      </div>
      <div class="hc-stats">
        <span class="hc-chip success"><?= t('safe') ?>: <?= count($subjects) - $warnCnt - $deprivedCnt ?></span>
        <span class="hc-chip warning"><?= t('warning_state') ?>: <?= $warnCnt ?></span>
        <span class="hc-chip danger"><?= t('deprived_state') ?>: <?= $deprivedCnt ?></span>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="hierarchy-card">
      <div>
        <div class="hc-icon"><i class="fas fa-shield-halved"></i></div>
        <div class="hc-title"><?= attendanceWarningPercentThreshold() ?>% / <?= attendanceDeprivationPercentThreshold() ?>%</div>
        <div class="hc-meta"><?= t('warning_state') ?> / <?= t('deprived_state') ?></div>
      </div>
      <div class="hc-stats">
      
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="fas fa-book me-2"></i><?= t('per_subject_attendance') ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th><?= t('subject') ?></th>
          <th class="text-center"><?= t('present_count') ?></th>
          <th class="text-center"><?= t('absent_count') ?></th>
          <th class="text-center"><?= t('semester_credits_total') ?></th>
          <th class="text-center"><?= t('absence_percent') ?></th>
          <th class="text-center"><?= t('attendance_status') ?></th>
          <th class="text-end"><?= t('view') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$subjects): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= t('no_data_found') ?></td></tr><?php endif; ?>
        <?php foreach ($subjects as $i => $sj):
            $st = !empty($sj['deprived']) ? 'deprived' : (!empty($sj['warning_reached']) ? 'warning' : 'safe');
            $cls = $st === 'deprived' ? 'is-deprived' : ($st === 'warning' ? 'is-warning' : '');
            $subjectUrl = hLink([
                'level'   => 'student',
                'fid'     => (int)($stu['faculty_id'] ?? 0),
                'did'     => (int)($stu['department_id'] ?? 0),
                'cid'     => (int)($stu['class_id'] ?? 0),
                'uid'     => (int)$stu['id'],
                'sid'     => (int)$sj['subject_id'],
                'subject' => (int)$sj['subject_id'],
                'group'   => $groupBy,
            ]);
        ?>
          <tr class="attendance-summary-row <?= $cls ?>">
            <td><?= $i + 1 ?></td>
            <td>
              <div class="fw-semibold"><?= h($sj['subject_name']) ?></div>
              <div class="small text-muted"><code><?= h($sj['code']) ?></code></div>
            </td>
            <td class="text-center"><span class="badge bg-success"><?= (int)$sj['present_count'] ?></span></td>
            <td class="text-center"><span class="badge bg-danger"><?= (int)$sj['absent_count'] ?></span></td>
            <td class="text-center"><?= (int)$sj['semester_credits'] ?></td>
            <td class="text-center"><span class="badge bg-<?= absencePercentClass((float)$sj['absence_pct']) ?>"><?= (float)$sj['absence_pct'] ?>%</span></td>
            <td class="text-center">
              <?php if ($st === 'deprived'): ?>
                <span class="badge bg-danger"><i class="fas fa-ban"></i> <?= t('deprived_state') ?></span>
              <?php elseif ($st === 'warning'): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-triangle-exclamation"></i> <?= t('warning_state') ?></span>
              <?php else: ?>
                <span class="badge bg-success"><i class="fas fa-check"></i> <?= t('safe') ?></span>
              <?php endif; ?>
            </td>
            <td class="text-end"><a href="<?= h($subjectUrl) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-book me-2"></i><?= t('v12_my_subjects') ?></div>
      <div class="list-group list-group-flush">
        <?php if(!$subjects): ?><div class="list-group-item text-muted"><?= t('no_records') ?></div><?php endif; ?>
        <?php foreach($subjects as $sub):
            $subjectLink = hLink([
                'level'   => 'student',
                'fid'     => (int)($stu['faculty_id'] ?? 0),
                'did'     => (int)($stu['department_id'] ?? 0),
                'cid'     => (int)($stu['class_id'] ?? 0),
                'uid'     => (int)$stu['id'],
                'sid'     => (int)$sub['subject_id'],
                'subject' => (int)$sub['subject_id'],
                'group'   => $groupBy,
            ]);
        ?>
          <a class="list-group-item list-group-item-action <?=((int)$sub['subject_id']===(int)$selectedSubjectId)?'active':''?>" href="<?= h($subjectLink) ?>">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <div class="fw-semibold"><?=h($sub['subject_name'])?></div>
                <div class="small <?=((int)$sub['subject_id']===(int)$selectedSubjectId)?'text-white-50':'text-muted'?>"><?=h($sub['code'])?> · <?= (int)$sub['semester_credits'] ?> <?= t('credit') ?></div>
              </div>
              <span class="badge bg-<?=absencePercentClass((float)$sub['absence_pct'])?>"><?= (float)$sub['absence_pct'] ?>%</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <?php if($selectedSubject): ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
          <div>
            <h5 class="mb-1"><?=h($selectedSubject['subject_name'])?></h5>
            <div class="text-muted small"><?=h($selectedSubject['code'])?> · <?= (int)$selectedSubject['semester_credits'] ?> <?= t('credit') ?> · <?= (int)$selectedSubject['held_credits'] ?> <?= t('taught_credits') ?></div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-<?=creditProgressClass((float)$selectedSubject['progress_pct'])?>"><?= (float)$selectedSubject['progress_pct'] ?> % Porgress</span>
            <span class="badge bg-<?=absencePercentClass((float)$selectedSubject['absence_pct'])?>"><?= (float)$selectedSubject['absence_pct'] ?>% Absence</span>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fas fa-clock-rotate-left me-2"></i><?= t('attendance_history') ?></span>
        <div class="btn-group btn-group-sm" role="group">
          <?php foreach(['day','week','month','semester'] as $key):
              $groupLink = hLink([
                  'level'   => 'student',
                  'fid'     => (int)($stu['faculty_id'] ?? 0),
                  'did'     => (int)($stu['department_id'] ?? 0),
                  'cid'     => (int)($stu['class_id'] ?? 0),
                  'uid'     => (int)$stu['id'],
                  'sid'     => (int)$selectedSubject['subject_id'],
                  'subject' => (int)$selectedSubject['subject_id'],
                  'group'   => $key,
              ]);
          ?>
            <a href="<?= h($groupLink) ?>" class="btn btn-outline-secondary <?= $groupBy===$key?'active':'' ?>"><?= t($key) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th><?= t('period') ?></th><th><?= t('credit') ?></th><th><?= t('present') ?></th><th><?= t('absent') ?></th><th>%</th></tr></thead>
          <tbody>
          <?php if(!$history): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr><?php endif; ?>
          <?php foreach($history as $row): ?>
            <tr>
              <td><?=h($row['period_label'])?></td>
              <td><?= (int)$row['held_credits'] ?></td>
              <td><?= (int)$row['present_count'] ?></td>
              <td><?= (int)$row['absent_count'] ?></td>
              <td><span class="badge bg-<?=absencePercentClass((float)$row['absence_pct'])?>"><?= (float)$row['absence_pct'] ?>%</span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php
      /* ═══════════════════════════════════════════════════════════════
         V16 — Student Attendance Details: Edit Permission
         ───────────────────────────────────────────────────────────────
         RBAC: who can edit attendance from this page?

           • VC               (ROLE_ADMIN)   → ✓ full edit access
           • Faculty Manager  (ROLE_ATT_MGR) → ✓ within own faculty

           • Dean / Teacher / Student / others → ✗ READ-ONLY

         No "Edit Mode" toggle, no modal, no reason form: authorized
         users click the status cell to flip Present ↔ Absent. The
         request is sent immediately to /api/edit_attendance.php and
         the page statistics refresh on success.
         ═══════════════════════════════════════════════════════════════ */
      $canEditAttendance = function_exists('userCanEditAttendance')
          ? userCanEditAttendance((int)($_SESSION['role_id'] ?? 0))
          : false;
      $todayStr = date('Y-m-d');
    ?>
    <div class="card" id="detailed-records"
         data-student-id="<?= (int)$stu['id'] ?>"
         data-subject-id="<?= (int)$selectedSubject['subject_id'] ?>"
         data-class-id="<?= (int)($stu['class_id'] ?? 0) ?>"
         data-can-edit="<?= $canEditAttendance ? '1' : '0' ?>">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fas fa-list-check me-2"></i><?= t('details') ?></span>
        <?php if ($canEditAttendance): ?>
          <span class="badge bg-warning text-dark" title="VC / Faculty Manager">
            <i class="fas fa-pen-to-square me-1"></i> Editable — click a status to toggle
          </span>
        <?php else: ?>
          <span class="badge bg-secondary" title="Read-only mode">
            <i class="fas fa-eye me-1"></i> View only
          </span>
        <?php endif; ?>
      </div>

      <?php if ($canEditAttendance): ?>
      <div class="d-flex flex-wrap gap-2 px-3 py-2 border-bottom small" id="liveAttStats"
           data-warn-at="<?= (float)attendanceWarningPercentThreshold() ?>"
           data-dep-at="<?= (float)attendanceDeprivationPercentThreshold() ?>">
        <span class="badge bg-success" title="Present">
          ✅ <span id="liveStatPresent"><?= (int)$selectedSubject['present_count'] ?></span>
        </span>
        <span class="badge bg-danger" title="Absent">
          ❌ <span id="liveStatAbsent"><?= (int)$selectedSubject['absent_count'] ?></span>
        </span>
        <span class="badge bg-<?= absencePercentClass((float)$selectedSubject['absence_pct']) ?>"
              id="liveStatPctBadge" title="Absence %">
          <i class="fas fa-percent me-1"></i><span id="liveStatPct"><?= (float)$selectedSubject['absence_pct'] ?></span>%
        </span>
        <span class="text-muted ms-2 align-self-center">
          <i class="fas fa-circle-info me-1"></i>
         <strong>Present ↔ Absent</strong> <i class="fas fa-lock"></i> Locked.
        </span>
      </div>
      <?php endif; ?>

      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" id="attendanceTimelineTable">
          <thead>
            <tr>
              <th><?= t('date') ?></th>
              <th><?= t('credit') ?></th>
              <th><?= t('time') ?></th>
              <th class="text-center"><?= t('status') ?></th>
              <th><?= t('updated_at') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$timeline): ?>
            <tr><td colspan="5" class="text-center text-muted py-4"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
          <?php foreach($timeline as $row):
              $rawTimelineStatus  = trim((string)($row['status'] ?? ''));
              $timelineStatusNorm = normalizeAttStatus($rawTimelineStatus);
              $isFuture           = !empty($row['session_date']) && $row['session_date'] > $todayStr;
              $sessIdRow          = (int)($row['session_id'] ?? 0);
              $cellClickable      = ($canEditAttendance && !$isFuture && $sessIdRow > 0);
          ?>
            <tr data-session-id="<?= $sessIdRow ?>"
                data-session-date="<?= h($row['session_date']) ?>"
                data-current-status="<?= h($timelineStatusNorm === 'absent' ? 'absent' : 'present') ?>"
                data-is-future="<?= $isFuture ? '1' : '0' ?>">
              <td>
                <?= h($row['session_date']) ?>
                <?php if ($isFuture): ?>
                  <span class="badge bg-secondary ms-1" title="Future date — locked">
                    <i class="fas fa-lock"></i> Upcoming
                  </span>
                <?php endif; ?>
              </td>
              <td><?= h($row['slot_label']) ?></td>
              <td><?= h(substr($row['start_time'],0,5)) ?>–<?= h(substr($row['end_time'],0,5)) ?></td>
              <td class="text-center status-cell <?= $cellClickable ? 'js-att-toggle' : '' ?>"
                  <?php if ($cellClickable): ?>
                    role="button"
                    tabindex="0"
                    title="Click to toggle status"
                    style="cursor:pointer;"
                  <?php elseif ($isFuture && $canEditAttendance): ?>
                    title="Upcoming date — locked"
                    style="opacity:0.6;"
                  <?php endif; ?>
              >
                <?= renderAttStatusBadge($rawTimelineStatus) ?>
              </td>
              <td class="marked-at-cell">
                <?= !empty($row['marked_at']) ? date('d M Y H:i', strtotime($row['marked_at'])) : '—' ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($canEditAttendance): ?>
    <!-- ═══════════════════════════════════════════════════════════════
         V16 — Click-to-Toggle Script
         ───────────────────────────────────────────────────────────────
         • Click status cell  → optimistic UI swap + AJAX save
         • On server error    → revert + toast with error message
         • On success         → update stats strip live (count + %)
         ═══════════════════════════════════════════════════════════════ -->
    <script>
    (function(){
      const CSRF       = <?= json_encode(generateCsrfToken()) ?>;
      const API        = <?= json_encode(BASE_URL . '/api/edit_attendance.php') ?>;
      const STUDENT_ID = <?= (int)$stu['id'] ?>;

      const table     = document.getElementById('attendanceTimelineTable');
      const statsHost = document.getElementById('liveAttStats');
      const elPresent = document.getElementById('liveStatPresent');
      const elAbsent  = document.getElementById('liveStatAbsent');
      const elPct     = document.getElementById('liveStatPct');
      const elPctBadge= document.getElementById('liveStatPctBadge');

      if (!table) return;

      const WARN_AT = statsHost ? parseFloat(statsHost.dataset.warnAt) || 25 : 25;
      const DEP_AT  = statsHost ? parseFloat(statsHost.dataset.depAt)  || 50 : 50;

      const PRESENT_HTML = '<span class="att-badge att-badge-present" title="Present" aria-label="Present">✅</span>';
      const ABSENT_HTML  = '<span class="att-badge att-badge-absent"  title="Absent" aria-label="Absent">❌</span>';

      function pctBadgeClass(pct){
        if (pct >= DEP_AT)  return 'bg-danger';
        if (pct >= WARN_AT) return 'bg-warning text-dark';
        if (pct > 0)        return 'bg-info';
        return 'bg-success';
      }

      function toast(msg, ok){
        let host = document.getElementById('attToastHost');
        if (!host) {
          host = document.createElement('div');
          host.id = 'attToastHost';
          host.className = 'position-fixed top-0 end-0 p-3';
          host.style.zIndex = 1080;
          document.body.appendChild(host);
        }
        const cls = ok ? 'text-bg-success' : 'text-bg-danger';
        const id  = 'tt' + Date.now() + Math.floor(Math.random()*1000);
        host.insertAdjacentHTML('beforeend',
          '<div id="'+id+'" class="toast align-items-center '+cls+' border-0" role="alert">' +
            '<div class="d-flex"><div class="toast-body">'+msg+'</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>' +
          '</div>');
        const el = document.getElementById(id);
        if (window.bootstrap && bootstrap.Toast) {
          const t = new bootstrap.Toast(el, { delay: 3500 });
          t.show();
          el.addEventListener('hidden.bs.toast', () => el.remove());
        } else { setTimeout(() => el.remove(), 3500); }
      }

      async function flipStatus(cell){
        const row = cell.closest('tr');
        if (!row || row.dataset.isFuture === '1') return;
        if (cell.dataset.busy === '1') return;            // re-entry guard

        const sessionId = parseInt(row.dataset.sessionId, 10);
        if (!sessionId) return;

        const oldStatus = row.dataset.currentStatus === 'absent' ? 'absent' : 'present';
        const newStatus = oldStatus === 'absent' ? 'present' : 'absent';

        // optimistic UI
        cell.dataset.busy = '1';
        const prevHtml = cell.innerHTML;
        cell.innerHTML = newStatus === 'absent' ? ABSENT_HTML : PRESENT_HTML;
        row.dataset.currentStatus = newStatus;
        row.classList.add('table-warning');

        try {
          const res = await fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': CSRF,
              'Accept': 'application/json'
            },
            body: JSON.stringify({
              session_id: sessionId,
              student_id: STUDENT_ID,
              status:     newStatus,
              _csrf:      CSRF
            })
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data.ok) {
            // revert
            cell.innerHTML = prevHtml;
            row.dataset.currentStatus = oldStatus;
            toast((data && data.error) ? data.error : ('غلطي: HTTP ' + res.status), false);
            return;
          }

          // update marked-at column live
          const markedCell = row.querySelector('.marked-at-cell');
          if (markedCell) {
            const now = new Date();
            const pad = (n) => String(n).padStart(2,'0');
            const mon = now.toLocaleString('en-GB', { month: 'short' });
            markedCell.textContent = pad(now.getDate()) + ' ' + mon + ' ' + now.getFullYear()
              + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());
          }

          // update live stats strip (counts + absence %)
          if (elPresent) elPresent.textContent = data.present_count;
          if (elAbsent)  elAbsent.textContent  = data.absent_count;
          if (elPct)     elPct.textContent     = data.absence_pct;
          if (elPctBadge) {
            elPctBadge.className = 'badge ' + pctBadgeClass(parseFloat(data.absence_pct));
            elPctBadge.id = 'liveStatPctBadge';
            // preserve children
            elPctBadge.innerHTML = '<i class="fas fa-percent me-1"></i><span id="liveStatPct">'
              + data.absence_pct + '</span>%';
          }

          toast('✅ خوندي شو · ' + newStatus.toUpperCase() + ' · ' + data.absence_pct + '%', true);

        } catch (err) {
          // network failure → revert
          cell.innerHTML = prevHtml;
          row.dataset.currentStatus = oldStatus;
          toast('د شبکې غلطي: ' + (err && err.message ? err.message : err), false);
        } finally {
          cell.dataset.busy = '';
          setTimeout(() => row.classList.remove('table-warning'), 1200);
        }
      }

      // click handler (delegated)
      table.addEventListener('click', (e) => {
        const cell = e.target.closest('.js-att-toggle');
        if (!cell) return;
        e.preventDefault();
        flipStatus(cell);
      });

      // keyboard accessibility — Enter / Space toggles focused cell
      table.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const cell = e.target.closest('.js-att-toggle');
        if (!cell) return;
        e.preventDefault();
        flipStatus(cell);
      });
    })();
    </script>
    <?php endif; ?>
    <?php else: ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><?= t('no_records') ?></div></div>
    <?php endif; ?>
  </div>
</div>
<?php
        }
    }
endif;

require_once __DIR__ . '/includes/footer.php';
