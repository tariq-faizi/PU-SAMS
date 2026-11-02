<?php
/**
 * =====================================================================
 *  PUSAMS — QR Attendance : Usage statistics (Admin/Manager/Dean)
 *  File: api/qr_usage_stats.php
 *  Method: GET
 *
 *  Purpose:
 *      Read-only aggregated stats about how the QR attendance flow is
 *      being used across the institution. Powers optional admin
 *      dashboards. Does not touch any existing data.
 *
 *  Auth:
 *      - Must be logged in as admin, manager, or dean.
 *
 *  Response JSON:
 *      {
 *        success: true,
 *        totals: { qr_sessions, manual_sessions, pending_sessions,
 *                  qr_records, manual_records },
 *        by_day: [ { day: 'YYYY-MM-DD', qr: N, manual: M } ],
 *        top_teachers: [ { teacher_id, teacher_name, qr_sessions } ]
 *      }
 *
 *  Additive-only. No writes anywhere.
 * =====================================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jout_stats(array $p): void {
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    jout_stats(['success' => false, 'message' => 'Unauthorized']);
}

$roleId = (int)($_SESSION['role_id'] ?? 0);
$allowedRoles = [];
if (defined('ROLE_ADMIN'))   $allowedRoles[] = ROLE_ADMIN;
if (defined('ROLE_MANAGER')) $allowedRoles[] = ROLE_MANAGER;
if (defined('ROLE_DEAN'))    $allowedRoles[] = ROLE_DEAN;
if (!in_array($roleId, $allowedRoles, true)) {
    http_response_code(403);
    jout_stats(['success' => false, 'message' => 'Forbidden']);
}

$db = getDB();

// Guard: verify the QR columns actually exist (post-migration). If not,
// return an empty payload rather than erroring — makes the endpoint safe
// to call even before the migration has been applied.
try {
    $probe = $db->query("SHOW COLUMNS FROM attendance_sessions LIKE 'attendance_method'")->fetch();
    if (!$probe) {
        jout_stats([
            'success' => true,
            'migration_applied' => false,
            'totals' => ['qr_sessions' => 0, 'manual_sessions' => 0, 'pending_sessions' => 0,
                         'qr_records' => 0, 'manual_records' => 0],
            'by_day' => [],
            'top_teachers' => [],
        ]);
    }
} catch (Throwable $e) {
    jout_stats(['success' => false, 'message' => 'DB probe failed']);
}

// Totals
$tot = $db->query(
    "SELECT
        SUM(attendance_method = 'qr')      AS qr_sessions,
        SUM(attendance_method = 'manual')  AS manual_sessions,
        SUM(attendance_method = 'pending') AS pending_sessions
     FROM attendance_sessions"
)->fetch() ?: [];

$recs = $db->query(
    "SELECT
        SUM(mark_method = 'qr')     AS qr_records,
        SUM(mark_method = 'manual') AS manual_records
     FROM attendance_records"
)->fetch() ?: [];

// By-day breakdown for the last 30 days
$since = date('Y-m-d', strtotime('-30 days'));
$byDayStmt = $db->prepare(
    "SELECT DATE(s.session_date) AS day,
            SUM(s.attendance_method = 'qr')     AS qr,
            SUM(s.attendance_method = 'manual') AS manual
       FROM attendance_sessions s
      WHERE s.session_date >= ?
      GROUP BY DATE(s.session_date)
      ORDER BY day ASC"
);
$byDayStmt->execute([$since]);
$byDay = array_map(function($r){
    return [
        'day'    => (string)$r['day'],
        'qr'     => (int)$r['qr'],
        'manual' => (int)$r['manual'],
    ];
}, $byDayStmt->fetchAll());

// Top teachers by QR-session count
$topStmt = $db->query(
    "SELECT s.teacher_id, u.full_name AS teacher_name, COUNT(*) AS qr_sessions
       FROM attendance_sessions s
       JOIN users u ON u.id = s.teacher_id
      WHERE s.attendance_method = 'qr'
      GROUP BY s.teacher_id, u.full_name
      ORDER BY qr_sessions DESC
      LIMIT 10"
);
$top = array_map(function($r){
    return [
        'teacher_id'   => (int)$r['teacher_id'],
        'teacher_name' => (string)$r['teacher_name'],
        'qr_sessions'  => (int)$r['qr_sessions'],
    ];
}, $topStmt->fetchAll());

jout_stats([
    'success'           => true,
    'migration_applied' => true,
    'totals' => [
        'qr_sessions'      => (int)($tot['qr_sessions'] ?? 0),
        'manual_sessions'  => (int)($tot['manual_sessions'] ?? 0),
        'pending_sessions' => (int)($tot['pending_sessions'] ?? 0),
        'qr_records'       => (int)($recs['qr_records'] ?? 0),
        'manual_records'   => (int)($recs['manual_records'] ?? 0),
    ],
    'by_day'      => $byDay,
    'top_teachers'=> $top,
    'server_time' => date('c'),
]);
