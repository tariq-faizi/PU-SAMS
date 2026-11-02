<?php
/**
 * =====================================================================
 *  PUSAMS — QR Attendance : Live scans feed (Enhancement)
 *  File:   api/session_qr_scans.php
 *  Method: POST (application/x-www-form-urlencoded)
 *
 *  Purpose:
 *      Return the list of students who have already scanned the QR for
 *      a given attendance_sessions row. Powers the teacher's live-QR
 *      modal "recent scans" panel so the teacher can watch check-ins
 *      arrive in real time (polled every 5s from the frontend).
 *
 *  Auth:
 *      - Must be logged in as a teacher.
 *      - Must own the session (teacher_id = current user).
 *      - Requires permission 'start_qr_session'.
 *
 *  Additive-only: this file NEVER writes to attendance_records or
 *  attendance_sessions. Read-only endpoint.
 * =====================================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/lang.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jout_scans(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Auth guards ─────────────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    jout_scans(['success' => false, 'message' => 'Unauthorized']);
}
requireRole([ROLE_TEACHER]);
requirePermission('start_qr_session');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    jout_scans(['success' => false, 'message' => 'Method Not Allowed']);
}

// CSRF: accept the token from either form field or X-CSRF-TOKEN header
$csrf = $_POST[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$csrf)) {
    http_response_code(403);
    jout_scans(['success' => false, 'message' => 'CSRF token invalid']);
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$uid       = (int)($_SESSION['user_id'] ?? 0);

if ($sessionId <= 0) {
    jout_scans(['success' => false, 'message' => 'Missing session_id']);
}

$db = getDB();

// Ownership check
$own = $db->prepare("SELECT id, class_id, teacher_id, status, is_missed
                       FROM attendance_sessions
                      WHERE id = ? AND teacher_id = ? LIMIT 1");
$own->execute([$sessionId, $uid]);
$sess = $own->fetch();
if (!$sess) {
    http_response_code(403);
    jout_scans(['success' => false, 'message' => 'Not your session.']);
}

// Fetch the last N QR scans for this session, most recent first.
// Only rows written by the QR flow are included.
$limit = (int)($_POST['limit'] ?? 20);
if ($limit < 1)   $limit = 20;
if ($limit > 100) $limit = 100;

$q = $db->prepare(
    "SELECT ar.id, ar.student_id, ar.marked_at,
            u.full_name, u.student_id AS student_code
       FROM attendance_records ar
       JOIN users u ON u.id = ar.student_id
      WHERE ar.session_id = ?
        AND ar.mark_method = 'qr'
      ORDER BY ar.marked_at DESC
      LIMIT $limit"
);
$q->execute([$sessionId]);
$rows = $q->fetchAll();

// Totals for the header of the modal
$markedCount = (int)rowCount(
    "SELECT COUNT(*) FROM attendance_records WHERE session_id=? AND status='present'",
    [$sessionId]
);
$qrCount = (int)rowCount(
    "SELECT COUNT(*) FROM attendance_records WHERE session_id=? AND mark_method='qr'",
    [$sessionId]
);
$totalStudents = count(classActiveStudentIds((int)$sess['class_id']));

$scans = [];
foreach ($rows as $r) {
    $scans[] = [
        'student_id'   => (int)$r['student_id'],
        'full_name'    => (string)$r['full_name'],
        'student_code' => (string)$r['student_code'],
        'marked_at'    => (string)$r['marked_at'],
        'time_short'   => date('H:i:s', strtotime((string)$r['marked_at'])),
    ];
}

jout_scans([
    'success'        => true,
    'session_id'     => $sessionId,
    'marked_count'   => $markedCount,
    'qr_count'       => $qrCount,
    'total_students' => $totalStudents,
    'scans'          => $scans,
    'server_time'    => date('c'),
]);
