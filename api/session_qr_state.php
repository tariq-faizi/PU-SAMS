<?php
/**
 * =====================================================================
 *  PUSAMS — QR Attendance : Session-QR state endpoint
 *  File:   api/session_qr_state.php
 *  Method: POST (application/x-www-form-urlencoded or multipart)
 *
 *  Actions:
 *      (default)  Load or refresh the QR token + backup code for the
 *                 attendance_sessions row identified by session_id.
 *      cancel     Release the QR lock IFF no student has scanned yet.
 *
 *  Response: JSON. On success:
 *      {
 *        success:true, qr_url:"...", backup_code:"123456",
 *        expires_at:"YYYY-MM-DDTHH:MM:SS", refresh_sec:30,
 *        marked_count:N, total_students:M
 *      }
 *
 *  Golden rule: additive-only. This file writes nothing to
 *  attendance_records and never mutates historical rows.
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

function jout(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Auth guards ─────────────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    jout(['success' => false, 'message' => 'Unauthorized']);
}
requireRole([ROLE_TEACHER]);
requirePermission('start_qr_session');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    jout(['success' => false, 'message' => 'Method Not Allowed']);
}

// CSRF: accept the token from either form field or X-CSRF-TOKEN header
$csrf = $_POST[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$csrf)) {
    http_response_code(403);
    jout(['success' => false, 'message' => 'CSRF token invalid']);
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$action    = strtolower(trim((string)($_POST['action'] ?? '')));
$refresh   = !empty($_POST['refresh']);
$uid       = (int)($_SESSION['user_id'] ?? 0);

if ($sessionId <= 0) {
    jout(['success' => false, 'message' => 'Missing session_id']);
}

$db = getDB();

// ─── Ownership + status check ───────────────────────────────────────
$row = null;
$stmt = $db->prepare(
    "SELECT id, class_id, teacher_id, status, is_missed, attendance_method,
            qr_token, backup_code, qr_expires_at, qr_refresh_seconds
       FROM attendance_sessions
      WHERE id = ? AND teacher_id = ?
      LIMIT 1"
);
$stmt->execute([$sessionId, $uid]);
$row = $stmt->fetch();

if (!$row) {
    jout(['success' => false, 'message' => t('session_not_open')]);
}
if ((string)$row['status'] !== 'open' || (int)($row['is_missed'] ?? 0) === 1) {
    jout(['success' => false, 'message' => t('session_not_open')]);
}

// ─── Handle "cancel" (release the QR lock before any scan) ──────────
if ($action === 'cancel') {
    $released = releaseAttendanceMethodIfEmpty($sessionId, $db);
    if ($released) {
        @auditLog('qr_session_cancel', 'attendance', $sessionId, 'Teacher cancelled QR before any scan');
        jout(['success' => true, 'message' => 'QR cancelled.']);
    }
    jout(['success' => false, 'message' => 'Cannot cancel: attendance records already exist for this credit.']);
}

// ─── Claim the QR lock (transactional; safe if already claimed by qr) ──
$claim = claimAttendanceMethod($sessionId, 'qr', $db);
if (!$claim['ok']) {
    jout(['success' => false, 'message' => $claim['message'] ?? t('credit_locked_manual')]);
}

// ─── Refresh / generate token when needed ───────────────────────────
$refreshSeconds = (int)($row['qr_refresh_seconds'] ?? 30);
if ($refreshSeconds < 5)  $refreshSeconds = 5;
if ($refreshSeconds > 300) $refreshSeconds = 300;

$expired = empty($row['qr_expires_at']) || strtotime((string)$row['qr_expires_at']) < time();
$needsGen = $refresh || $expired || empty($row['qr_token']) || empty($row['backup_code']);

if ($needsGen) {
    $newToken  = bin2hex(random_bytes(32));
    $newBackup = generateUniqueSessionBackupCode($db);
    $newExpiry = date('Y-m-d H:i:s', time() + $refreshSeconds);
    $db->prepare(
        "UPDATE attendance_sessions
            SET qr_token=?, backup_code=?, qr_expires_at=?, qr_refresh_seconds=?
          WHERE id=?"
    )->execute([$newToken, $newBackup, $newExpiry, $refreshSeconds, $sessionId]);
    $row['qr_token']       = $newToken;
    $row['backup_code']    = $newBackup;
    $row['qr_expires_at']  = $newExpiry;
    @auditLog('qr_session_refresh', 'attendance', $sessionId, 'Teacher generated/refreshed QR token');
}

// ─── Live count metrics ─────────────────────────────────────────────
$markedCount = (int)rowCount(
    "SELECT COUNT(*) FROM attendance_records WHERE session_id=? AND status='present'",
    [$sessionId]
);
$totalStudents = count(classActiveStudentIds((int)$row['class_id']));

$qrUrl = rtrim(BASE_URL, '/') . '/student/scan.php?token=' . urlencode((string)$row['qr_token']);

jout([
    'success'        => true,
    'qr_url'         => $qrUrl,
    'backup_code'    => (string)$row['backup_code'],
    'expires_at'     => str_replace(' ', 'T', (string)$row['qr_expires_at']),
    'refresh_sec'    => $refreshSeconds,
    'marked_count'   => $markedCount,
    'total_students' => $totalStudents,
]);
