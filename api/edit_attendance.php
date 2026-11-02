<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/feature_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function jerr(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function jok(array $payload): void {
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) jerr(401, 'Unauthorized');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jerr(405, 'Method Not Allowed');

$raw = file_get_contents('php://input') ?: '';
$payload = [];
if ($raw && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $payload = json_decode($raw, true) ?: [];
}
if (!$payload) $payload = $_POST;

$csrf       = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload[CSRF_TOKEN_NAME] ?? '');
$sessionId  = (int)($payload['session_id'] ?? 0);
$studentId  = (int)($payload['student_id'] ?? 0);
$newStatus  = strtolower(trim((string)($payload['status'] ?? '')));
$reason     = trim((string)($payload['reason'] ?? ''));

if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$csrf)) {
    jerr(403, 'CSRF token invalid');
}

$roleId = (int)($_SESSION['role_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

$allowedRoles = [ROLE_ADMIN, ROLE_ATT_MGR];
if (!in_array($roleId, $allowedRoles, true) || !userCanEditAttendance($roleId)) {
    jerr(403, 'لاسرسی نشته — یوازې VC او د پوهنځۍ مدیر کولی شي حاضري ایډیټ کړي');
}

if ($sessionId <= 0)   jerr(422, 'session_id لازمي دی');
if ($studentId <= 0)   jerr(422, 'student_id لازمي دی');
if (!in_array($newStatus, ['present', 'absent'], true)) {
    jerr(422, 'status باید present یا absent وي');
}

if (mb_strlen($reason) > 1000) {
    $reason = mb_substr($reason, 0, 1000);
}

$db = getDB();

$sess = $db->prepare(
    "SELECT s.id, s.session_date, s.start_time, s.end_time, s.class_id, s.subject_id, s.timetable_id, s.status,
            t.faculty_id AS sess_faculty_id, t.department_id AS sess_dept_id
       FROM attendance_sessions s
       LEFT JOIN timetable t ON t.id = s.timetable_id
       WHERE s.id = ? LIMIT 1"
);
$sess->execute([$sessionId]);
$session = $sess->fetch();
if (!$session) jerr(404, 'Session ونه موندل شو');

$stuStmt = $db->prepare(
    "SELECT id, full_name, class_id, faculty_id, department_id, role_id, deleted_at, status
       FROM users WHERE id = ? LIMIT 1"
);
$stuStmt->execute([$studentId]);
$student = $stuStmt->fetch();
if (!$student || (int)$student['role_id'] !== ROLE_STUDENT || !empty($student['deleted_at'])) {
    jerr(404, 'محصل ونه موندل شو');
}
if ((int)$student['class_id'] !== (int)$session['class_id']) {
    jerr(422, 'محصل د دې ټولګي نه دی');
}

if ($roleId !== ROLE_ADMIN) {
    $scopeFaculty = currentFacultyScopeId();
    $sessFaculty  = (int)($session['sess_faculty_id'] ?? 0);
    $stuFaculty   = (int)$student['faculty_id'];
    if (!$scopeFaculty || ($scopeFaculty !== $sessFaculty && $scopeFaculty !== $stuFaculty)) {
        jerr(403, 'د بل پوهنځي محصل ته لاسرسی بلاک دی');
    }
}

$today = date('Y-m-d');
if ($session['session_date'] > $today) {
    jerr(423, 'د راتلونکې نېټې حاضري نه شي ایډیټ کیدای (Locked)');
}

if ($session['session_date'] === $today) {
    $startTs = strtotime($session['session_date'].' '.$session['start_time']);
    if ($startTs !== false && time() < $startTs) {
        jerr(423, 'د خاضرۍ ثبتول قفل دي — د کلاس دقیق پیل وخت ('.substr((string)$session['start_time'],0,5).') لا نه دی رارسېدلی (Pending).');
    }
}

if (function_exists('sessionEditableByTeacher')) {
    
    if (!sessionEditableByTeacher((string)$session['session_date'])) {

        

    }
}

$oldStatus = 'present';
try {
    $db->beginTransaction();

    $cur = $db->prepare(
        "SELECT id, status, correction_note FROM attendance_records
          WHERE session_id = ? AND student_id = ? LIMIT 1"
    );
    $cur->execute([$sessionId, $studentId]);
    $existing = $cur->fetch();
    $oldStatus = $existing ? $existing['status'] : 'present';

    $noChange = ($existing && $oldStatus === $newStatus);

    $ip = $_SERVER['REMOTE_ADDR']      ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT']  ?? '';
    $autoReason = $reason !== '' ? $reason : '(quick toggle from Student Attendance Details)';
    $note = '[' . date('Y-m-d H:i') . '] '
          . 'edited by user#' . $userId . ' (role ' . $roleId . '): '
          . $oldStatus . ' → ' . $newStatus . ' — ' . $autoReason;

    if (!$noChange) {
        if ($existing) {
            
            $merged = trim(($existing['correction_note'] ?? '') . "\n" . $note);
            $upd = $db->prepare(
                "UPDATE attendance_records
                    SET status = ?, mark_method = 'manager',
                        marked_at = NOW(), ip_address = ?, device_info = ?,
                        correction_note = ?
                  WHERE id = ?"
            );
            $upd->execute([$newStatus, $ip, mb_substr($ua, 0, 250), $merged, $existing['id']]);
        } else {
            $ins = $db->prepare(
                "INSERT INTO attendance_records
                    (session_id, student_id, status, marked_at,
                     mark_method, ip_address, device_info, correction_note)
                 VALUES (?, ?, ?, NOW(), 'manager', ?, ?, ?)"
            );
            $ins->execute([
                $sessionId, $studentId, $newStatus,
                $ip, mb_substr($ua, 0, 250), $note,
            ]);
        }

        auditLog(
            'attendance_edit',
            'hierarchy',
            $sessionId,
            sprintf(
                'student=%d  %s → %s  reason=%s',
                $studentId, $oldStatus, $newStatus, $autoReason
            )
        );
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    jerr(500, 'د خوندي کولو پر مهال ستونزه: ' . $e->getMessage());
}

if (function_exists('cacheBustDashboards')) {
    try { cacheBustDashboards(); } catch (Throwable $e) {  }
}

$classId   = (int)$session['class_id'];
$subjectId = (int)$session['subject_id'];

$wkStmt = $db->prepare(
    "SELECT COALESCE(SUM(credit_units),0) FROM timetable
      WHERE class_id=? AND subject_id=? AND status='approved'"
);
$wkStmt->execute([$classId, $subjectId]);
$weekly    = (int)$wkStmt->fetchColumn();
$semWeeks  = (int)currentAcademicWeeks();
$semTotal  = $weekly * $semWeeks;

$cntStmt = $db->prepare(
    "SELECT
       SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS p,
       SUM(CASE WHEN ar.status='absent'  THEN 1 ELSE 0 END) AS a,
       COUNT(ar.id) AS total
     FROM attendance_records ar
     JOIN attendance_sessions s ON s.id = ar.session_id
     WHERE ar.student_id=? AND s.subject_id=? AND s.class_id=?"
);
$cntStmt->execute([$studentId, $subjectId, $classId]);
$cnt = $cntStmt->fetch() ?: ['p' => 0, 'a' => 0, 'total' => 0];

$absent  = (int)$cnt['a'];
$present = (int)$cnt['p'];
$totMarked = (int)$cnt['total'];
$pct     = absencePctClassic($absent, $weekly, $semWeeks);
$attendancePct = $totMarked > 0 ? round(($present / max(1, $totMarked)) * 100, 2) : 100.0;
$warnAt  = (float)attendanceWarningPercentThreshold();
$depAt   = (float)attendanceDeprivationPercentThreshold();
$state   = $pct >= $depAt ? 'deprived' : ($pct >= $warnAt ? 'warning' : 'safe');

// Trigger full absence risk notifications (warning at 20%, deprivation at 25%)
// This notifies student, teacher, manager, dean, and VC as appropriate
if ($state !== 'safe' && function_exists('triggerAbsenceRiskNotifications')) {
    try {
        // Get teacher_id for this session's timetable
        $ttStmt = $db->prepare("SELECT teacher_id FROM timetable WHERE id = (SELECT timetable_id FROM attendance_sessions WHERE id = ? LIMIT 1) LIMIT 1");
        $ttStmt->execute([$sessionId]);
        $ttTeacherId = (int)($ttStmt->fetchColumn() ?: 0);

        triggerAbsenceRiskNotifications(
            $studentId,
            $classId,
            $subjectId,
            $ttTeacherId
        );
    } catch (Throwable $e) {
        // Notification failure must not break the API response
    }
}

jok([
    'message'          => 'د حاضرۍ ریکارډ په بریالیتوب سره خوندي شو',
    'old_status'       => $oldStatus,
    'new_status'       => $newStatus,
    'present_count'    => $present,
    'absent_count'     => $absent,
    'total_marked'     => $totMarked,
    'weekly_credits'   => $weekly,
    'semester_credits' => $semTotal,
    'absence_pct'      => $pct,
    'attendance_pct'   => $attendancePct,
    'warning_at'       => $warnAt,
    'deprive_at'       => $depAt,
    'state'            => $state,
]);
