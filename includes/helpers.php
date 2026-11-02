<?php

if (!function_exists('settingsTable')) {
    function settingsTable(): string {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $db = getDB();
            $st = $db->query("SHOW TABLES LIKE 'settings_and_policy'");
            if ($st && $st->fetch()) { $cached = 'settings_and_policy'; return $cached; }
            $st = $db->query("SHOW TABLES LIKE 'settings'");
            if ($st && $st->fetch()) { $cached = 'settings'; return $cached; }
        } catch (Throwable $e) {  }
        $cached = 'settings_and_policy';
        return $cached;
    }
}

if (!function_exists('getSetting')) {
    function getSetting(string $key, string $default = ''): string {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $tbl  = settingsTable();
            $stmt = getDB()->prepare("SELECT value FROM `{$tbl}` WHERE `key`=?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            $cache[$key] = $row ? (string)$row['value'] : $default;
        } catch (Throwable $e) {
            $cache[$key] = $default;
        }
        return $cache[$key];
    }
}

if (!function_exists('setSetting')) {
    function setSetting(string $key, string $value, string $group = 'general'): bool {
        try {
            $db  = getDB();
            $tbl = settingsTable();
            $exists = $db->prepare("SELECT 1 FROM `{$tbl}` WHERE `key`=?");
            $exists->execute([$key]);
            if ($exists->fetchColumn()) {
                return $db->prepare("UPDATE `{$tbl}` SET `value`=? WHERE `key`=?")->execute([$value, $key]);
            }
            return $db->prepare("INSERT INTO `{$tbl}` (`key`,`value`,`group`) VALUES (?,?,?)")->execute([$key, $value, $group]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $url): never { header("Location: $url"); exit; }
function flashSet(string $type, string $msg): void { $_SESSION['flash'][] = ['type'=>$type,'msg'=>$msg]; }
function flashGet(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function auditLog(string $action, string $module = '', ?int $targetId = null, string $desc = '', ?int $userId = null, string $ip = ''): void {
    try {
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $ip = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        getDB()->prepare("INSERT INTO audit_log (user_id,action,module,target_id,description,ip_address,user_agent) VALUES (?,?,?,?,?,?,?)")
            ->execute([$uid,$action,$module,$targetId,$desc,$ip,$ua]);
    } catch (Throwable $e) {}
}

function createNotification(int $userId, string $title, string $msg, string $type='info', string $link=''): void {
    try {
        getDB()->prepare("INSERT INTO notifications (user_id,title,message,type,link) VALUES (?,?,?,?,?)")
            ->execute([$userId,$title,$msg,$type,$link]);
    } catch (Throwable $e) {}
}

function normalizeAppLink(string $link): string {
    if ($link === '') return '';
    if (str_starts_with($link, '/attendance_final/')) {
        return BASE_URL . substr($link, strlen('/attendance_final'));
    }
    return $link;
}

function unreadNotificationCount(int $userId): int {
    try {
        $s = getDB()->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$userId]);
        return (int)($s->fetch()['c'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

function validatePasswordStrength(string $pw): array {
    $errors = [];
    if (strlen($pw) < PASSWORD_MIN_LEN) $errors[] = 'At least '.PASSWORD_MIN_LEN.' characters required.';
    if (!preg_match('/[A-Z]/', $pw)) $errors[] = 'Must include an uppercase letter.';
    if (!preg_match('/[a-z]/', $pw)) $errors[] = 'Must include a lowercase letter.';
    if (!preg_match('/[0-9]/', $pw)) $errors[] = 'Must include a number.';
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) $errors[] = 'Must include a special character.';
    return $errors;
}

function paginate(int $total, int $page, int $perPage = 20): array {
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    return ['total'=>$total,'pages'=>$pages,'page'=>$page,'offset'=>$offset,'per_page'=>$perPage];
}

function getDeptFilter(?int $deptId = null): ?int { return isDeptScoped() ? currentUserDeptId() : $deptId; }

function currentAuthorityScope(): array {
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    return [
        'role_id' => $roleId,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'faculty_id' => (int)($_SESSION['faculty_id'] ?? 0),
        'department_id' => (int)($_SESSION['department_id'] ?? 0),
        'class_id' => (int)($_SESSION['class_id'] ?? 0),
    ];
}

function sqlScopeForClassAlias(string $classAlias = 'c', string $sessionAlias = 's', string $studentAlias = 'u'): array {
    $scope = currentAuthorityScope();
    $sql = '';
    $params = [];

    if ($scope['role_id'] === ROLE_ADMIN) {
        return ['sql' => '', 'params' => []];
    }

    if (in_array($scope['role_id'], [ROLE_DEPT_HEAD, ROLE_ATT_MGR], true) && $scope['faculty_id'] > 0) {
        $sql .= " AND {$classAlias}.faculty_id=?";
        $params[] = $scope['faculty_id'];
        return ['sql' => $sql, 'params' => $params];
    }

    if ($scope['role_id'] === ROLE_TEACHER) {
        if ($scope['faculty_id'] > 0) {
            $sql .= " AND {$classAlias}.faculty_id=?";
            $params[] = $scope['faculty_id'];
        }
        if ($scope['department_id'] > 0) {
            $sql .= " AND ({$classAlias}.department_id=? OR {$classAlias}.department_id IS NULL)";
            $params[] = $scope['department_id'];
        }
        if ($sessionAlias !== '') {
            $sql .= " AND {$sessionAlias}.teacher_id=?";
            $params[] = $scope['user_id'];
        }
        return ['sql' => $sql, 'params' => $params];
    }

    if ($scope['role_id'] === ROLE_STUDENT) {
        if ($scope['class_id'] > 0) {
            $sql .= " AND {$classAlias}.id=?";
            $params[] = $scope['class_id'];
        }
        if ($studentAlias !== '') {
            $sql .= " AND {$studentAlias}.id=?";
            $params[] = $scope['user_id'];
        }
        return ['sql' => $sql, 'params' => $params];
    }

    if ($scope['department_id'] > 0) {
        $sql .= " AND {$classAlias}.department_id=?";
        $params[] = $scope['department_id'];
    }

    return ['sql' => $sql, 'params' => $params];
}

function attendanceStatusClass(string $status): string {

    

    

    $normalized = function_exists('normalizeSessionStatus') ? normalizeSessionStatus($status) : $status;
    return [
        'present'=>'success',
        'absent'=>'danger',
        'حالی'=>'secondary',
        'hold'=>'secondary',
        'open'=>'info text-dark',
        'pending'=>'warning text-dark',
        'live'=>'info text-dark',
        'completed'=>'success',
        'closed'=>'success',
        'cancelled'=>'dark',
        'missed'=>'danger',
        'late'=>'secondary',
        'excused'=>'secondary',
    ][$normalized] ?? ([
        'present'=>'success',
        'absent'=>'danger',
        'حالی'=>'secondary',
        'hold'=>'secondary',
        'late'=>'secondary',
        'excused'=>'secondary',
    ][$status] ?? 'secondary');
}
if (!function_exists('attendanceSessionStatusSupportsCompleted')) {
    function attendanceSessionStatusSupportsCompleted(): bool {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $stmt = getDB()->query("SHOW COLUMNS FROM attendance_sessions LIKE 'status'");
            $row = $stmt ? $stmt->fetch() : null;
            $type = (string)($row['Type'] ?? $row['type'] ?? '');
            $cache = stripos($type, "'completed'") !== false;
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }
}
if (!function_exists('preferredCompletedSessionStatus')) {
    function preferredCompletedSessionStatus(): string {
        return attendanceSessionStatusSupportsCompleted() ? 'completed' : 'closed';
    }
}
if (!function_exists('normalizeSessionStatus')) {
    function normalizeSessionStatus(?string $status): string {
        $status = strtolower(trim((string)$status));
        if ($status === 'closed') return 'completed';
        return $status;
    }
}
if (!function_exists('isCompletedSessionStatus')) {
    function isCompletedSessionStatus(?string $status): bool {
        return normalizeSessionStatus($status) === 'completed';
    }
}
if (!function_exists('sessionStatusLabel')) {
    
    function sessionStatusLabel(?string $status): string {
        $tL = function(string $k, string $fallback): string {
            return function_exists('t') ? t($k, [], $fallback) : $fallback;
        };
        return match (normalizeSessionStatus($status)) {
            'open'      => $tL('status_open',      'Open'),
            'pending'   => $tL('status_pending',   'Pending'),
            'live'      => $tL('status_live',      'Live'),
            'completed' => $tL('status_closed',    'Closed'),
            'missed'    => $tL('status_missed',    'Missed'),
            'cancelled' => $tL('status_cancelled', 'Cancelled'),
            default     => ucfirst(str_replace('_', ' ', (string)$status)),
        };
    }
}
if (!function_exists('sessionStatusFilterOptions')) {
    function sessionStatusFilterOptions(): array {
        return [
            'open'      => sessionStatusLabel('open'),
            'pending'   => sessionStatusLabel('pending'),
            'completed' => sessionStatusLabel('completed'),
            'cancelled' => sessionStatusLabel('cancelled'),
            'missed'    => sessionStatusLabel('missed'),
        ];
    }
}
if (!function_exists('applySessionStatusFilterClause')) {
    function applySessionStatusFilterClause(array &$where, array &$params, string $column, ?string $status): void {
        $status = normalizeSessionStatus((string)$status);
        if ($status === '') return;
        if ($status === 'completed') {
            $where[] = $column." IN ('completed','closed')";
            return;
        }
        $where[] = $column.'=?';
        $params[] = $status;
    }
}
if (!function_exists('normalizeAttStatus')) {
    
    function normalizeAttStatus(?string $status): string {
        $s = trim((string)$status);
        if ($s === 'present') return 'present';
        if ($s === 'absent')  return 'absent';
        if ($s === 'حالی' || $s === 'hold' || $s === 'late' || $s === 'excused' || $s === '' || $s === 'empty' || $s === 'null') {
            return 'absent';
        }
        return 'absent';
    }
}

if (!function_exists('attStatusLabel')) {
    
    function attStatusLabel(string $status): string {
        $s = normalizeAttStatus($status);
        return $s === 'absent' ? '❌' : '✅';
    }
}
if (!function_exists('renderAttStatusBadge')) {
    
    function renderAttStatusBadge(?string $status): string {
        $s = normalizeAttStatus((string)$status);
        if ($s === 'present') {
            return '<span class="att-badge att-badge-present" title="Present" aria-label="Present">✅</span>';
        }
        return '<span class="att-badge att-badge-absent" title="Absent" aria-label="Absent">❌</span>';
    }
}
function attendancePercentClass(float|int $pct): string { if ($pct >= 85) return 'success'; if ($pct >= 70) return 'warning'; return 'danger'; }
function absencePercentClass(float|int $pct): string {
    if ($pct >= attendanceDeprivationPercentThreshold()) return 'danger';
    if ($pct >= attendanceWarningPercentThreshold()) return 'warning';
    if ($pct > 0) return 'info';
    return 'success';
}
function creditProgressClass(float|int $pct): string { if ($pct >= 75) return 'success'; if ($pct >= 40) return 'warning'; if ($pct > 0) return 'info'; return 'secondary'; }

function sessionEditableByTeacher(string $sessionDate): bool {
    $today = new DateTimeImmutable('today');
    $target = DateTimeImmutable::createFromFormat('!Y-m-d', trim($sessionDate)) ?: new DateTimeImmutable(trim($sessionDate));
    $target = $target->setTime(0, 0, 0);
    $minDate = $today->modify('-'.(int)getSetting('edit_window_days', (string)EDIT_WINDOW_DAYS).' days');
    return $target >= $minDate && $target <= $today;
}

function currentAcademicWeeks(): int { return (int)getSetting('semester_weeks', (string)SEMESTER_WEEKS); }
function absenceWarningBuffer(): int { return max(1, (int)getSetting('warning_buffer', '2')); }

if (!function_exists('getPolicyValue')) {
    function getPolicyValue(string $key, string $default = ''): string {
        return getSetting($key, $default);
    }
}

if (!function_exists('setPolicyValue')) {
    function setPolicyValue(string $key, string $value): bool {
        return setSetting($key, $value, 'academic');
    }
}

if (!function_exists('getSemesterStartDate')) {
    
    function getSemesterStartDate(): string {
        $val = trim(getPolicyValue('semester_start_date', ''));
        if ($val === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) || !strtotime($val)) {
            return '';
        }
        return $val;
    }
}

if (!function_exists('getSemesterEndDate')) {
    
    function getSemesterEndDate(): string {
        $start = getSemesterStartDate();
        if ($start === '') return '';
        $weeks = max(1, currentAcademicWeeks());
        
        $endTs = strtotime($start.' +'.($weeks * 7 - 1).' days');
        if ($endTs === false) return '';
        return date('Y-m-d', $endTs);
    }
}

if (!function_exists('getSemesterStatus')) {
    
    function getSemesterStatus(): ?array {
        $start = getSemesterStartDate();
        if ($start === '') return null;
        $end = getSemesterEndDate();
        $weeksTotal = max(1, currentAcademicWeeks());
        $today = date('Y-m-d');
        
        $startTs = strtotime($start);
        $endTs   = $end ? strtotime($end) : ($startTs + ($weeksTotal*7-1)*86400);
        $todayTs = strtotime($today);
        $effectiveTs = max($startTs, min($todayTs, $endTs));
        $daysPassed  = max(0, (int)floor(($effectiveTs - $startTs) / 86400) + ($todayTs >= $startTs ? 1 : 0));
        $daysTotal   = max(1, (int)floor(($endTs - $startTs) / 86400) + 1);
        $weeksPassed = min($weeksTotal, (int)ceil($daysPassed / 7));
        $progressPct = round(($daysPassed / $daysTotal) * 100, 1);
        $isActive    = ($todayTs >= $startTs && $todayTs <= $endTs);
        return [
            'start_date'   => $start,
            'end_date'     => $end,
            'weeks_total'  => $weeksTotal,
            'weeks_passed' => $weeksPassed,
            'days_passed'  => $daysPassed,
            'days_total'   => $daysTotal,
            'progress_pct' => $progressPct,
            'is_active'    => $isActive,
        ];
    }
}

if (!function_exists('backfillMissedSessionsFromSemesterStart')) {
    
    function backfillMissedSessionsFromSemesterStart(bool $force = false): array {
        $db = getDB();
        $result = ['days'=>0,'missed_sessions'=>0,'present_records'=>0,'from'=>'','to'=>''];
        $start = getSemesterStartDate();
        if ($start === '') return $result;

        $today = date('Y-m-d');
        $end = getSemesterEndDate();
        
        $stopAt = ($end && $end < $today) ? $end : $today;

        $from = $start;
        if (!$force) {
            $last = trim(getPolicyValue('semester_backfill_last_date', ''));
            if ($last !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $last) && $last >= $start) {
                $nextTs = strtotime($last.' +1 day');
                if ($nextTs !== false) $from = date('Y-m-d', $nextTs);
            }
        }
        if ($from > $stopAt) {
            $result['from'] = $from;
            $result['to'] = $stopAt;
            return $result;
        }

        $result['from'] = $from;
        $result['to']   = $stopAt;

        $days = 0; $missedSessions = 0; $absentRecords = 0;
        $cursorTs = strtotime($from);
        $stopTs   = strtotime($stopAt);
        if ($cursorTs === false || $stopTs === false) return $result;

        $maxDays = 200;
        while ($cursorTs <= $stopTs && $days < $maxDays) {
            $date = date('Y-m-d', $cursorTs);
            try {
                
                syncSessionsForDate($date);

                
                $stats = backfillMarkMissedForDate($date);
                $missedSessions += (int)$stats['missed'];
                $absentRecords  += (int)$stats['present_records'];
            } catch (Throwable $e) {
                
                error_log('[PUSAMS backfill] '.$date.': '.$e->getMessage());
            }
            $cursorTs += 86400;
            $days++;
        }

        $lastProcessed = date('Y-m-d', min($stopTs, $cursorTs - 86400));
        setPolicyValue('semester_backfill_last_date', $lastProcessed);

        auditLog('semester_backfill','attendance', null,
            sprintf('Backfilled %d days [%s → %s]: %d missed sessions, %d present records (default UX)',
                $days, $from, $lastProcessed, $missedSessions, $absentRecords));

        if (function_exists('cacheBustDashboards')) cacheBustDashboards();

        $result['days'] = $days;
        $result['missed_sessions']  = $missedSessions;
        $result['present_records']  = $absentRecords;
        return $result;
    }
}

if (!function_exists('backfillMarkMissedForDate')) {
    
    function backfillMarkMissedForDate(string $date): array {
        $db = getDB();
        $missedCount = 0; $presentRecords = 0;

        // FIX (Dashboard Today Operations): Only mark sessions as missed if their
        // end_time has actually passed. This prevents today's live/upcoming
        // sessions from being prematurely flipped to 'missed' during the
        // semester-start backfill, which was causing the dashboard
        // "Live" and "Upcoming" cluster cards to read zero.
        // Past dates remain unaffected because TIMESTAMP(session_date,end_time)
        // for any past date is always < NOW().
        $stmt = $db->prepare("SELECT s.id, s.class_id, s.teacher_id, s.subject_id, s.timetable_id,
                                     s.session_date, s.slot_label, s.start_time, s.end_time,
                                     t.faculty_id, t.department_id, t.credit_units,
                                     c.name AS class_name, sub.name AS subject_name
                              FROM attendance_sessions s
                              JOIN timetable t ON t.id = s.timetable_id
                              JOIN classes c ON c.id = s.class_id
                              JOIN subjects sub ON sub.id = s.subject_id
                              WHERE s.session_date = ?
                                AND s.status IN ('open','missed')
                                AND TIMESTAMP(s.session_date, s.end_time) <= NOW()
                                AND NOT EXISTS (SELECT 1 FROM attendance_records ar WHERE ar.session_id = s.id)");
        $stmt->execute([$date]);
        $sessions = $stmt->fetchAll();

        foreach ($sessions as $session) {
            $sid = (int)$session['id'];
            
            $db->prepare("UPDATE attendance_sessions SET status='missed', is_missed=1, closed_at=NOW(), closed_by=teacher_id, generated_by_system=1 WHERE id=? AND status<>'missed'")
               ->execute([$sid]);
            $missedCount++;

            $chk = $db->prepare("SELECT id FROM missed_classes WHERE session_id=? LIMIT 1");
            $chk->execute([$sid]);
            if (!$chk->fetchColumn()) {
                $db->prepare("INSERT INTO missed_classes (faculty_id, department_id, class_id, subject_id, teacher_id, timetable_id, session_id, missed_date, credits, reason, recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,NULL)")
                   ->execute([
                       (int)$session['faculty_id'],
                       $session['department_id'] !== null ? (int)$session['department_id'] : null,
                       (int)$session['class_id'],
                       (int)$session['subject_id'],
                       (int)$session['teacher_id'],
                       (int)$session['timetable_id'],
                       $sid,
                       $date,
                       max(1, (int)$session['credit_units']),
                       'System auto-detected (backfill from semester start)'
                   ]);
            }

            $stuStmt = $db->prepare("SELECT id FROM users WHERE class_id=? AND role_id=? AND status='active' AND deleted_at IS NULL");
            $stuStmt->execute([(int)$session['class_id'], ROLE_STUDENT]);
            $studentIds = array_map('intval', array_column($stuStmt->fetchAll(), 'id'));

            
            $ins = $db->prepare("INSERT INTO attendance_records (session_id, student_id, status, marked_at, mark_method) VALUES (?,?, 'present', NOW(), 'system') ON DUPLICATE KEY UPDATE status=IF(status IN ('present','absent'), status, 'present'), marked_at=IF(status IN ('present','absent'), marked_at, NOW()), mark_method=IF(status IN ('present','absent'), mark_method, 'system')");
            foreach ($studentIds as $stuId) {
                try {
                    $ins->execute([$sid, $stuId]);
                    $presentRecords++;
                    
                    try {
                        $hist = $db->prepare("INSERT INTO attendance_history (session_id, student_id, attendance_status, history_date, source_module, notes, created_by) VALUES (?,?, 'present', ?, 'semester_backfill', 'Default present: session sync/backfilled from semester start (UX rule #1)', NULL)");
                        $hist->execute([$sid, $stuId, $date]);
                    } catch (Throwable $h) {  }
                } catch (Throwable $e) {
                    error_log('[PUSAMS backfill] present record fail sid='.$sid.' stu='.$stuId.': '.$e->getMessage());
                }
            }
        }

        return ['missed' => $missedCount, 'present_records' => $presentRecords];
    }
}

function attendanceWarningPercentThreshold(): float { return (float)getSetting('notify_warning_pct', '20'); }
function attendanceDeprivationPercentThreshold(): float { return (float)getSetting('notify_critical_pct', '25'); }
function deprivationAbsenceThreshold(): int { return (int)getSetting('deprivation_absences', '16'); }
function absenceWarningThreshold(): int { return max(1, deprivationAbsenceThreshold() - absenceWarningBuffer()); }
function scheduledCreditsForSubject(int $weeklyCredits): int { return max(0, $weeklyCredits) * currentAcademicWeeks(); }
if (!function_exists('semesterCreditsForClassSubject')) {
    function semesterCreditsForClassSubject(int $classId, int $subjectId): int {
        static $cache = [];
        $key = $classId.':'.$subjectId;
        if (isset($cache[$key])) return $cache[$key];
        $cache[$key] = scheduledCreditsForSubject(approvedTimetableWeeklyCredits($classId, $subjectId));
        return $cache[$key];
    }
}
if (!function_exists('semesterCreditsForClassTotal')) {
    function semesterCreditsForClassTotal(int $classId): int {
        static $cache = [];
        if (isset($cache[$classId])) return $cache[$classId];
        $cache[$classId] = scheduledCreditsForSubject((int)rowCount("SELECT COALESCE(SUM(credit_units),0) FROM timetable WHERE class_id=? AND status='approved'", [$classId]));
        return $cache[$classId];
    }
}
if (!function_exists('classActiveStudentCount')) {
    function classActiveStudentCount(int $classId): int {
        static $cache = [];
        if (isset($cache[$classId])) return $cache[$classId];
        $cache[$classId] = (int)rowCount("SELECT COUNT(*) FROM users WHERE role_id=? AND class_id=? AND status='active' AND deleted_at IS NULL", [ROLE_STUDENT, $classId]);
        return $cache[$classId];
    }
}
if (!function_exists('absencePercentFromSemesterCredits')) {
    function absencePercentFromSemesterCredits(int $absentCredits, int $semesterCredits): float {
        return $semesterCredits > 0 ? round(($absentCredits / $semesterCredits) * 100, 1) : 0.0;
    }
}
if (!function_exists('attendanceCriticalPercentThreshold')) {
    function attendanceCriticalPercentThreshold(): float {
        return attendanceDeprivationPercentThreshold();
    }
}
function subjectAbsencePercent(int $absentCredits, int $scheduledCredits): float {
    return absencePercentFromSemesterCredits($absentCredits, $scheduledCredits);
}

function rowCount(string $sql, array $params = []): int {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function detectTimetableConflicts(int $classId, int $teacherId, string $roomValue, int $dayOfWeek, string $startTime, string $endTime, int $excludeId = 0, int $roomId = 0): array {
    $conflicts = [];
    try {
        $db = getDB();
        $sql = "SELECT t.id, c.name as class_name, c.section, u.full_name as teacher_name, COALESCE(r.name,t.room) as room_name,
                       CASE
                           WHEN t.class_id = ? THEN 'class'
                           WHEN t.teacher_id = ? THEN 'teacher'
                           WHEN ((? > 0 AND t.room_id = ?) OR (? <> '' AND COALESCE(t.room,'') = ?)) THEN 'room'
                           ELSE 'other'
                       END as conflict_type
                FROM timetable t
                JOIN classes c ON c.id=t.class_id
                JOIN users u ON u.id=t.teacher_id
                LEFT JOIN rooms r ON r.id=t.room_id
                WHERE t.day_of_week=?
                  AND t.status IN ('draft','faculty_approved','approved')
                  AND NOT (t.end_time<=? OR t.start_time>=?)
                  AND (t.class_id=? OR t.teacher_id=? OR (? > 0 AND t.room_id=?) OR (? <> '' AND COALESCE(t.room,'')=?))";
        $params = [$classId,$teacherId,$roomId,$roomId,$roomValue,$roomValue,$dayOfWeek,$startTime,$endTime,$classId,$teacherId,$roomId,$roomId,$roomValue,$roomValue];
        if ($excludeId > 0) { $sql .= " AND t.id<>?"; $params[] = $excludeId; }
        $stmt = $db->prepare($sql); $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['conflict_type'] === 'class') $conflicts[] = 'Class conflict with '.trim(($row['class_name'] ?? '').' '.($row['section'] ?? '')).'.';
            elseif ($row['conflict_type'] === 'teacher') $conflicts[] = 'Teacher conflict with '.$row['teacher_name'].'.';
            elseif ($row['conflict_type'] === 'room') $conflicts[] = 'Room conflict in '.($row['room_name'] ?: 'selected room').'.';
        }
    } catch (Throwable $e) { $conflicts[] = 'Unable to validate schedule conflicts right now.'; }
    return array_values(array_unique($conflicts));
}

function distributeCreditSlotTimes(string $startTime, string $endTime, int $creditUnits): array {
    $creditUnits = max(1, $creditUnits);
    $start = strtotime('2000-01-01 '.$startTime);
    $end   = strtotime('2000-01-01 '.$endTime);
    $duration = max(1, $end - $start);
    $segment = (int)floor($duration / $creditUnits);
    $segments = [];
    for ($i=1; $i<=$creditUnits; $i++) {
        $segStart = $start + ($segment * ($i-1));
        $segEnd   = ($i === $creditUnits) ? $end : ($segStart + $segment);
        $segments[$i] = [date('H:i:s', $segStart), date('H:i:s', $segEnd)];
    }
    return $segments;
}

function sessionWeekday(string $date): int { return (int)date('w', strtotime($date)); }

if (!function_exists('pusamsAutoSyncDatesFromRequest')) {
function pusamsAutoSyncDatesFromRequest(): array {

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $dates = [$yesterday, $today];
    foreach (['date', 'from', 'to'] as $key) {
        $raw = trim((string)($_GET[$key] ?? ''));
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $dates[] = $raw;
        }
    }
    $from = trim((string)($_GET['from'] ?? ''));
    $to   = trim((string)($_GET['to'] ?? ''));
    if ($from !== '' && $to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $start = strtotime($from);
        $end   = strtotime($to);
        if ($start !== false && $end !== false && $start <= $end) {
            $span = (int)floor(($end - $start) / 86400);
            if ($span <= 14) {
                for ($ts = $start; $ts <= $end; $ts += 86400) {
                    $dates[] = date('Y-m-d', $ts);
                }
            }
        }
    }
    $dates = array_values(array_unique(array_filter($dates)));
    sort($dates);
    return array_slice($dates, 0, 16);
}
}

if (!function_exists('pusamsComputeDataVersion')) {
function pusamsComputeDataVersion(): string {
    static $cached = null;
    static $cachedAt = 0;
    $now = time();
    
    if ($cached !== null && ($now - $cachedAt) < 3) return $cached;
    try {
        $db = getDB();

        
        $tables = [
            'attendance_sessions','attendance_records','missed_classes',
            'notifications','announcements','audit_log','users','timetable',
            'manager_attendance_edits','attendance_history'
        ];
        $parts = [];
        foreach ($tables as $tbl) {
            try {
                
                $colStmt = $db->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'updated_at'");
                $hasUpdatedAt = $colStmt && $colStmt->fetch();
                if ($hasUpdatedAt) {
                    $s = $db->query("SELECT COALESCE(UNIX_TIMESTAMP(MAX(updated_at)),0) v, COUNT(*) c FROM `{$tbl}`");
                } else {
                    $colStmt2 = $db->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'created_at'");
                    $hasCreatedAt = $colStmt2 && $colStmt2->fetch();
                    if ($hasCreatedAt) {
                        $s = $db->query("SELECT COALESCE(UNIX_TIMESTAMP(MAX(created_at)),0) v, COUNT(*) c FROM `{$tbl}`");
                    } else {
                        $s = $db->query("SELECT COALESCE(MAX(id),0) v, COUNT(*) c FROM `{$tbl}`");
                    }
                }
                if ($s) {
                    $row = $s->fetch(PDO::FETCH_ASSOC);
                    if ($row) $parts[] = $tbl.':'.$row['v'].':'.$row['c'];
                }
            } catch (\Throwable $te) {
                
            }
        }
        $cached   = $parts ? substr(sha1(implode('|', $parts)), 0, 16) : '0';
        $cachedAt = $now;
        return $cached;
    } catch (\Throwable $e) {
        return '0';
    }
}
}

if (!function_exists('pusamsAutoSync')) {
function pusamsAutoSync(bool $force = false): array {
    if (empty($_SESSION['user_id'])) return ['synced' => false, 'dates' => []];
    static $running = false;
    if ($running) return ['synced' => false, 'dates' => []];

    $now  = time();
    $last = (int)($_SESSION['_pusams_sync_at'] ?? 0);
    if (!$force && $last > 0 && ($now - $last) < 15) {
        return ['synced' => false, 'dates' => pusamsAutoSyncDatesFromRequest()];
    }

    $running = true;
    $_SESSION['_pusams_sync_at'] = $now;
    $dates       = pusamsAutoSyncDatesFromRequest();
    $roleId      = (int)($_SESSION['role_id'] ?? 0);
    $teacherId   = $roleId === ROLE_TEACHER ? (int)($_SESSION['user_id'] ?? 0) : 0;
    $timetableId = max(0, (int)($_GET['view'] ?? $_GET['timetable_id'] ?? 0));

    try {
        
        foreach ($dates as $date) {
            if ($teacherId > 0)        syncSessionsForDate($date, $teacherId, $timetableId);
            elseif ($timetableId > 0)  syncSessionsForDate($date, 0, $timetableId);
            else                       syncSessionsForDate($date);
            markMissedSessionsForDate($date);
        }

        

        if (function_exists('getSemesterStartDate') && getSemesterStartDate() !== '') {
            $lastBackfill = trim(getPolicyValue('semester_backfill_last_date', ''));
            $needBackfill = ($lastBackfill === '' || $lastBackfill < date('Y-m-d', strtotime('-1 day')));
            
            $lastBackfillRun = (int)($_SESSION['_pusams_backfill_at'] ?? 0);
            if ($needBackfill && ($now - $lastBackfillRun) > 30) {
                $_SESSION['_pusams_backfill_at'] = $now;
                try {
                    backfillMissedSessionsFromSemesterStart(false);
                } catch (Throwable $e) {
                    error_log('[PUSAMS autoSync backfill] '.$e->getMessage());
                }
            }
        }

        if (function_exists('cacheBustDashboards')) cacheBustDashboards();

        

        try {
            pusamsRunScheduledTasks();
        } catch (Throwable $stErr) {
            error_log('[PUSAMS scheduledTasks] '.$stErr->getMessage());
        }

        return ['synced' => true, 'dates' => $dates];
    } catch (Throwable $e) {
        return ['synced' => false, 'dates' => $dates, 'error' => $e->getMessage()];
    } finally {
        $running = false;
    }
}
}

if (!function_exists('pusamsRunScheduledTasks')) {
function pusamsRunScheduledTasks(bool $force = false): array {
    static $ranThisRequest = false;
    if (!$force && $ranThisRequest) return ['ran' => false, 'reason' => 'already_ran_this_request'];
    $ranThisRequest = true;

    $report = [
        'ran'                 => true,
        'announcements_purged'=> 0,
        'backup_executed'     => false,
        'backup_filename'     => null,
        'errors'              => [],
    ];

    $lockDir  = defined('BASE_PATH') ? BASE_PATH . '/cache' : sys_get_temp_dir();
    if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
    $lockFile = $lockDir . '/scheduled_tasks.lock';
    $stampFile= $lockDir . '/scheduled_tasks.stamp';

    // FIX 19a — robust throttle guard.
    // Previous code: `if ($lastRun > 0 && (time() - $lastRun) < 30)` would treat a
    // FUTURE stamp (clock skew, timezone override, manual edit) as permanently
    // throttled — because `time() - future` is negative, and negative is < 30.
    // That single bug silently disabled the entire auto-backup pipeline.
    // We now (a) clamp the delta to a non-negative value, and (b) auto-heal
    // a clearly-broken stamp instead of trusting it forever.
    if (!$force && is_file($stampFile)) {
        $lastRun = (int) @file_get_contents($stampFile);
        $now     = time();
        $delta   = $now - $lastRun;

        if ($lastRun > $now + 60) {
            // Stamp is in the future → broken; reset it and continue (do NOT throttle).
            @file_put_contents($stampFile, (string)$now);
        } elseif ($lastRun > 0 && $delta >= 0 && $delta < 30) {
            $report['ran'] = false;
            $report['reason'] = 'throttled';
            return $report;
        }
    }

    $fp = @fopen($lockFile, 'c');
    if ($fp === false) {
        $report['errors'][] = 'lock_open_failed';
        return $report;
    }
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        $report['ran'] = false;
        $report['reason'] = 'locked_by_other_process';
        return $report;
    }

    try {
        $db = getDB();

            try {
                $stmt = $db->prepare("DELETE FROM announcements WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
                $stmt->execute();
                $report['announcements_purged'] = $stmt->rowCount();
            } catch (Throwable $e) {
                $report['errors'][] = 'announcement_purge: '.$e->getMessage();
            }

        try {
            // V21 — auto-heal backup_schedule schema so old installs do not
            // crash this code path. last_run / day_of_week / time_of_day may
            // be missing on stale databases.
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS backup_schedule (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    frequency ENUM('daily','weekly','monthly','semester') DEFAULT 'daily',
                    time_of_day TIME DEFAULT '02:00:00',
                    day_of_week TINYINT DEFAULT 0,
                    is_enabled TINYINT(1) DEFAULT 0,
                    last_run DATETIME NULL,
                    updated_by INT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $schedCols = $db->query("SHOW COLUMNS FROM backup_schedule")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('last_run',    $schedCols, true)) $db->exec("ALTER TABLE backup_schedule ADD COLUMN last_run DATETIME NULL");
                if (!in_array('day_of_week', $schedCols, true)) $db->exec("ALTER TABLE backup_schedule ADD COLUMN day_of_week TINYINT DEFAULT 0");
                if (!in_array('time_of_day', $schedCols, true)) $db->exec("ALTER TABLE backup_schedule ADD COLUMN time_of_day TIME DEFAULT '02:00:00'");
                if (!in_array('is_enabled',  $schedCols, true)) $db->exec("ALTER TABLE backup_schedule ADD COLUMN is_enabled TINYINT(1) DEFAULT 0");
            } catch (Throwable $e) {
                error_log('[PUSAMS backup_schedule auto-heal] '.$e->getMessage());
            }

            $sched = $db->query("SELECT * FROM backup_schedule WHERE is_enabled=1 ORDER BY id LIMIT 1")->fetch();
            if ($sched && pusamsBackupDueNow($sched)) {
                try {
                    $res = pusamsPerformAutoBackup('scheduled_'.$sched['frequency']);
                    if (!empty($res['filename'])) {
                        $db->prepare("UPDATE backup_schedule SET last_run=NOW() WHERE id=?")
                           ->execute([$sched['id']]);
                        $report['backup_executed'] = true;
                        $report['backup_filename'] = $res['filename'];
                        if (!empty($res['skipped_tables'])) {
                            $report['skipped_tables'] = $res['skipped_tables'];
                        }
                        @auditLog('backup_auto_executed', 'backup', null, "Auto backup ({$sched['frequency']}) → {$res['filename']}");
                    }
                } catch (Throwable $bx) {
                    // V21 — که بیک اپ په تټونه ناکام ش، last_run بیا هم اټجټوو تر څو
                    // راتلونکې هره پاړې پاصلو باندې د بیک اپ بیا کوښښ ونه کړی.
                    // ادمین به خطا په audit_log او cron.log کې وګوری.
                    try {
                        $db->prepare("UPDATE backup_schedule SET last_run=NOW() WHERE id=?")
                           ->execute([$sched['id']]);
                    } catch (Throwable $e2) {}
                    $report['errors'][] = 'backup_run_failed: '.$bx->getMessage();
                    @auditLog('backup_auto_failed', 'backup', null, 'Auto backup error: '.$bx->getMessage());
                }
            }
        } catch (Throwable $e) {
            $report['errors'][] = 'backup_check: '.$e->getMessage();
        }

        @file_put_contents($stampFile, (string)time());
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }

    return $report;
}
}

if (!function_exists('pusamsBackupDueNow')) {
function pusamsBackupDueNow(array $sched): bool {
    $frequency = (string)($sched['frequency'] ?? 'daily');
    $timeOfDay = substr((string)($sched['time_of_day'] ?? '02:00:00'), 0, 5); 
    $dayOfWeek = (int)($sched['day_of_week'] ?? 0);
    $lastRun   = !empty($sched['last_run']) ? strtotime((string)$sched['last_run']) : 0;

    $now           = time();
    $todayDate     = date('Y-m-d');
    $scheduledTs   = strtotime($todayDate . ' ' . $timeOfDay . ':00');
    if ($scheduledTs === false) return false;

    if ($now < $scheduledTs) return false;

    switch ($frequency) {
        case 'daily':
            
            return ($lastRun < strtotime($todayDate.' 00:00:00'));

        case 'weekly':
            
            $todayDow = (int)date('w'); 
            if ($todayDow !== $dayOfWeek) return false;
            
            return ($lastRun < ($now - 6 * 86400));

        case 'monthly':
            
            $monthStart = strtotime(date('Y-m-01 00:00:00'));
            return ($lastRun < $monthStart);

        case 'semester':
            
            return ($lastRun < ($now - 120 * 86400));
    }
    return false;
}
}

if (!function_exists('pusamsPerformAutoBackup')) {
function pusamsPerformAutoBackup(string $reason = 'scheduled'): array {
    // ===================================================================
    // V21 — پوره د بیک اپ لیکونکی پریمان شوی دی.
    // پخوانۍ ناکامیانې د دې له امله راتلې:
    //   • د بیک اپ پوښۍ ورک و یا لیکل ولری (hosting perms)
    //   • file_put_contents false راستون کوله (دیسک ملو / open_basedir)
    //   • د backup_history د FK پر created_by تر orphan جوږې تټیدل
    //   • یو خراب جدول د ټول بیک اپ وژلو (PDO exception)
    //   • backup_history جدول هغه ستونې ولرلۍ چې موږ INSERT ته اړتیا لرله
    //   • backup_schedule د پخوانۍ schema سره برابر نه و (د semester / last_run ستونې نشته)
    // دا نسخه: (ا) د schema Automatic جوړونه کوی، (ب) خراب جدولونه skip
    // کوی د ټول پروسس د تټۍ کولو پر عوض، (ج) تل به created_by په
    // safe NULL حلوی، (د) د بری وروسته د جزئیاتو راپور ورکوی.
    // ===================================================================

    $dir = defined('BACKUP_DIR') ? BACKUP_DIR : (BASE_PATH . '/backups/');
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Backup directory could not be created: '.$dir);
        }
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0775);
        if (!is_writable($dir)) {
            throw new RuntimeException('Backup directory is not writable: '.$dir);
        }
    }

    $filename = 'pusams_backup_'.date('Ymd_His').'.sql';
    $filepath = rtrim($dir,'/').'/'.$filename;

    $db = getDB();

    // V21 — تر دې مخکې چې backup_history جدول ته لاس وروړو، د هغه schema
    // Automatic جوړوو. په زاړو install کې file_size یا backup_type ستونې نشته دی.
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS backup_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            file_size BIGINT NOT NULL DEFAULT 0,
            backup_type ENUM('manual','scheduled','restore') DEFAULT 'manual',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $existing = $db->query("SHOW COLUMNS FROM backup_history")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('file_size',   $existing, true)) $db->exec("ALTER TABLE backup_history ADD COLUMN file_size BIGINT NOT NULL DEFAULT 0");
        if (!in_array('backup_type', $existing, true)) $db->exec("ALTER TABLE backup_history ADD COLUMN backup_type ENUM('manual','scheduled','restore') DEFAULT 'manual'");
        if (!in_array('created_by',  $existing, true)) $db->exec("ALTER TABLE backup_history ADD COLUMN created_by INT NULL");
        if (!in_array('created_at',  $existing, true)) $db->exec("ALTER TABLE backup_history ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    } catch (Throwable $e) {
        error_log('[PUSAMS backup_history auto-heal] '.$e->getMessage());
    }

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $sql  = "-- =============================================\n";
    $sql .= "-- PUSAMS Backup\n";
    $sql .= "-- Generated: ".date('Y-m-d H:i:s')."\n";
    $sql .= "-- Reason: ".preg_replace('/[^A-Za-z0-9_\-\.\s:]/', '', $reason)."\n";
    $sql .= "-- =============================================\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $skippedTables = [];
    foreach ($tables as $t) {
        // V21 — هر جدول بېل تریر پروسس کړو ترڅو یو خراب جدول د ټول
        // بیک اپ د وژلو سبب ونه شی.
        try {
            $createRow = $db->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM);
            $sql .= "DROP TABLE IF EXISTS `{$t}`;\n";
            $sql .= ($createRow[1] ?? "") . ";\n\n";

            $rows = $db->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) continue;
            $cols = '`'.implode('`,`', array_keys($rows[0])).'`';
            foreach ($rows as $row) {
                $vals = array_map(function($v) use ($db) {
                    if ($v === null) return 'NULL';
                    if (is_numeric($v)) return $v;
                    return $db->quote($v);
                }, $row);
                $sql .= "INSERT INTO `{$t}` ({$cols}) VALUES (".implode(',', $vals).");\n";
            }
            $sql .= "\n";
        } catch (Throwable $e) {
            $skippedTables[] = $t;
            $sql .= "-- [SKIPPED] table `{$t}` — ".$e->getMessage()."\n\n";
            error_log('[PUSAMS backup] skipped table '.$t.': '.$e->getMessage());
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    $written = @file_put_contents($filepath, $sql);
    if ($written === false || !is_file($filepath)) {
        throw new RuntimeException('Failed to write backup file: '.$filepath);
    }
    $size = filesize($filepath) ?: 0;
    if ($size <= 0) {
        @unlink($filepath);
        throw new RuntimeException('Backup file ended up empty — refusing to register it.');
    }

    $type = (strpos($reason, 'scheduled') === 0) ? 'scheduled' : 'manual';

    // V21 — safe created_by: که session_id چېرته د حقیقی کارونکی سره ورته و
    // همهغه پوهیدلو دئ و کوچنۍ وټو NULL خوندی کړه (cron/CLI په چېر کې).
    $createdBy = null;
    if (!empty($_SESSION['user_id'])) {
        try {
            $chk = $db->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
            $chk->execute([(int)$_SESSION['user_id']]);
            if ($chk->fetchColumn()) $createdBy = (int)$_SESSION['user_id'];
        } catch (Throwable $e) { $createdBy = null; }
    }

    try {
        $db->prepare("INSERT INTO backup_history (filename, file_size, backup_type, created_by) VALUES (?,?,?,?)")
           ->execute([$filename, $size, $type, $createdBy]);
    } catch (Throwable $e) {
        // History row failed but file is fine — log and continue rather than lose the backup.
        error_log('[PUSAMS backup_history insert failed] '.$e->getMessage());
    }

    @auditLog('backup_created','backup', null, "Backup file: {$filename} ({$size} bytes; reason={$reason}".($skippedTables ? '; skipped='.implode(',', $skippedTables) : '').")");
    return [
        'filename'        => $filename,
        'size'            => $size,
        'path'            => $filepath,
        'reason'          => $reason,
        'skipped_tables'  => $skippedTables,
    ];
}
}

if (!function_exists('pusamsApplyAdminTimezoneOverride')) {
function pusamsApplyAdminTimezoneOverride(): string {
    static $applied = null;
    if ($applied !== null) return $applied;

    $tz = '';
    try { $tz = trim(getSetting('system_timezone', '')); } catch (Throwable $e) { $tz = ''; }

    // FIX 17 — if admin saved an explicit override in settings.system_timezone
    // we honour it. Otherwise we use the timezone resolved at boot.
    if ($tz !== '' && @timezone_open($tz)) {
        @date_default_timezone_set($tz);
        try {
            // Compute offset against the chosen zone (NOT the server default)
            $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
            getDB()->exec("SET time_zone = '{$offset}'");
        } catch (Throwable $e) {  }
        $applied = $tz;
    } else {
        // Re-sync DB session timezone to the boot-resolved zone in case the
        // PDO connection was created before any helper ran.
        $bootTz = defined('PUSAMS_ACTIVE_TZ') ? PUSAMS_ACTIVE_TZ : date_default_timezone_get();
        try {
            $offset = (new DateTime('now', new DateTimeZone($bootTz)))->format('P');
            getDB()->exec("SET time_zone = '{$offset}'");
        } catch (Throwable $e) {  }
        $applied = $bootTz;
    }
    return $applied;
}
}

function syncSessionsForDate(string $date, int $teacherId = 0, int $timetableId = 0): void {
    $db = getDB();
    $dow = sessionWeekday($date);
    $sql = "SELECT t.*, COALESCE(r.name,t.room) as room_name FROM timetable t LEFT JOIN rooms r ON r.id=t.room_id WHERE t.status='approved' AND t.day_of_week=?";
    $params = [$dow];
    if ($teacherId > 0) { $sql .= " AND t.teacher_id=?"; $params[] = $teacherId; }
    if ($timetableId > 0) { $sql .= " AND t.id=?"; $params[] = $timetableId; }
    $stmt = $db->prepare($sql); $stmt->execute($params);
    foreach ($stmt->fetchAll() as $tt) {
        $segments = distributeCreditSlotTimes($tt['start_time'], $tt['end_time'], (int)$tt['credit_units']);
        foreach ($segments as $slotNo => [$slotStart, $slotEnd]) {
            $chk = $db->prepare("SELECT id FROM attendance_sessions WHERE timetable_id=? AND session_date=? AND credit_slot_no=? LIMIT 1");
            $chk->execute([$tt['id'],$date,$slotNo]);
            if (!$chk->fetchColumn()) {
                $db->prepare("INSERT INTO attendance_sessions (timetable_id,class_id,subject_id,teacher_id,room_id,room,session_date,credit_slot_no,slot_label,start_time,end_time,status,is_missed,generated_by_system) VALUES (?,?,?,?,?,?,?,?,?,?,?,'open',0,1)")
                   ->execute([$tt['id'],$tt['class_id'],$tt['subject_id'],$tt['teacher_id'],$tt['room_id'],$tt['room_name'],$date,$slotNo,'Credit '.$slotNo,$slotStart,$slotEnd]);
            }
        }
    }
}

function sessionHasAttendanceRecords(int $sessionId): bool {
    return rowCount("SELECT COUNT(*) FROM attendance_records WHERE session_id=?", [$sessionId]) > 0;
}

/* ─────────────────────────────────────────────────────────────────────
 * QR Attendance — attendance-method lock helpers  (added, non-breaking)
 *
 * A credit-slot session row is the single source of truth. Exactly ONE
 * of {manual, qr} may ever be used per credit; the lock is claimed the
 * first time a teacher writes to the session. Legacy sessions default
 * to attendance_method='pending' and behave identically to the old
 * (pre-QR) flow — the manual save path continues to work as before.
 * ─────────────────────────────────────────────────────────────────── */
if (!function_exists('claimAttendanceMethod')) {
    /**
     * Try to claim a session for the given method ('manual' or 'qr').
     * Returns ['ok'=>true] on success, ['ok'=>false,'message'=>...] on
     * failure. Silent no-op if the same method is already claimed.
     */
    function claimAttendanceMethod(int $sessionId, string $method, ?PDO $db = null): array {
        $method = strtolower(trim($method));
        if ($method !== 'manual' && $method !== 'qr') {
            return ['ok' => false, 'message' => 'Invalid attendance method.'];
        }
        if ($sessionId <= 0) {
            return ['ok' => false, 'message' => (function_exists('t') ? t('session_not_open') : 'Session not open.')];
        }
        $db = $db ?: getDB();
        $ownTx = false;
        try {
            if (!$db->inTransaction()) { $db->beginTransaction(); $ownTx = true; }
            $stmt = $db->prepare("SELECT attendance_method, status, is_missed FROM attendance_sessions WHERE id=? FOR UPDATE");
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch();

            if (!$row) {
                if ($ownTx) $db->rollBack();
                return ['ok' => false, 'message' => (function_exists('t') ? t('session_not_open') : 'Session not found.')];
            }
            // Missed / non-open sessions are not eligible for either flow.
            if ((string)$row['status'] !== 'open' || (int)($row['is_missed'] ?? 0) === 1) {
                if ($ownTx) $db->rollBack();
                return ['ok' => false, 'message' => (function_exists('t') ? t('session_not_open') : 'Session not open.')];
            }
            $current = (string)($row['attendance_method'] ?? 'pending');
            if ($current !== 'pending' && $current !== $method) {
                if ($ownTx) $db->rollBack();
                $msg = $current === 'manual'
                    ? (function_exists('t') ? t('credit_locked_manual') : 'This credit was already recorded manually.')
                    : (function_exists('t') ? t('credit_locked_qr')     : 'This credit is set up for QR attendance.');
                return ['ok' => false, 'message' => $msg];
            }
            if ($current === 'pending') {
                $db->prepare("UPDATE attendance_sessions SET attendance_method=? WHERE id=?")
                   ->execute([$method, $sessionId]);
            }
            if ($ownTx) $db->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'message' => 'Lock error: '.$e->getMessage()];
        }
    }
}

if (!function_exists('releaseAttendanceMethodIfEmpty')) {
    /**
     * Un-claim a QR session ONLY if no attendance_records exist yet.
     * Called from the "Cancel QR" button before any student has scanned.
     * Returns true if released, false if records already exist.
     */
    function releaseAttendanceMethodIfEmpty(int $sessionId, ?PDO $db = null): bool {
        if ($sessionId <= 0) return false;
        if (sessionHasAttendanceRecords($sessionId)) return false;
        $db = $db ?: getDB();
        $db->prepare("UPDATE attendance_sessions
                        SET attendance_method='pending',
                            qr_token=NULL,
                            backup_code=NULL,
                            qr_expires_at=NULL
                      WHERE id=? AND attendance_method='qr'")
           ->execute([$sessionId]);
        return true;
    }
}

if (!function_exists('generateUniqueSessionBackupCode')) {
    /**
     * Generate a 6-digit backup code that is unique across live
     * attendance_sessions (checked with a bounded retry loop).
     */
    function generateUniqueSessionBackupCode(?PDO $db = null): string {
        $db = $db ?: getDB();
        for ($i = 0; $i < 12; $i++) {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM attendance_sessions WHERE backup_code=? LIMIT 1");
            $chk->execute([$code]);
            if (!$chk->fetch()) return $code;
        }
        // fallback — cryptographic; probability of collision negligible
        return substr(str_replace(['+','/','='], '', base64_encode(random_bytes(6))), 0, 8);
    }
}

if (!function_exists('isStudentEnrolledInClass')) {
    /**
     * Reuses the same enrollment definition as classActiveStudentIds()
     * — a student is enrolled if their users.class_id matches AND the
     * account is active and not soft-deleted.
     */
    function isStudentEnrolledInClass(int $studentId, int $classId): bool {
        if ($studentId <= 0 || $classId <= 0) return false;
        $stmt = getDB()->prepare("SELECT id FROM users WHERE id=? AND class_id=? AND role_id=? AND status='active' AND deleted_at IS NULL");
        $stmt->execute([$studentId, $classId, ROLE_STUDENT]);
        return (bool)$stmt->fetch();
    }
}

if (!function_exists('classActiveStudentIds')) {
    function classActiveStudentIds(int $classId): array {
        $stmt = getDB()->prepare("SELECT id FROM users WHERE role_id=? AND class_id=? AND status='active' AND deleted_at IS NULL ORDER BY id");
        $stmt->execute([ROLE_STUDENT, $classId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
}
if (!function_exists('creditPresentForSessionStudents')) {
    function creditPresentForSessionStudents(array $session, ?int $actorId = null, string $sourceModule = 'system_auto_missed', string $notes = 'Students were credited present automatically.'): int {
        $db = getDB();
        $sessionId = (int)($session['id'] ?? 0);
        $classId = (int)($session['class_id'] ?? 0);
        if ($sessionId <= 0 || $classId <= 0) return 0;
        $studentIds = classActiveStudentIds($classId);
        if (!$studentIds) return 0;
        $ins = $db->prepare("INSERT INTO attendance_records(session_id,student_id,status,marked_at,mark_method) VALUES (?,?, 'present', NOW(), 'system') ON DUPLICATE KEY UPDATE status='present', marked_at=NOW(), mark_method='system'");
        $count = 0;
        foreach ($studentIds as $sid) {
            $ins->execute([$sessionId, $sid]);
            writeAttendanceHistory($sessionId, $sid, 'present', $sourceModule, $notes, $actorId);
            $count++;
        }
        return $count;
    }
}
if (!function_exists('ensureMissedClassLedgerEntry')) {
    function ensureMissedClassLedgerEntry(array $session, ?int $recordedBy = null, string $reason = ''): void {
        $db = getDB();
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) return;
        $chk = $db->prepare("SELECT id FROM missed_classes WHERE session_id=? LIMIT 1");
        $chk->execute([$sessionId]);
        if ($chk->fetchColumn()) return;
        $db->prepare("INSERT INTO missed_classes (faculty_id,department_id,class_id,subject_id,teacher_id,timetable_id,session_id,missed_date,credits,reason,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               (int)($session['faculty_id'] ?? 0),
               isset($session['department_id']) ? (int)$session['department_id'] : null,
               (int)($session['class_id'] ?? 0),
               (int)($session['subject_id'] ?? 0),
               (int)($session['teacher_id'] ?? 0),
               isset($session['timetable_id']) ? (int)$session['timetable_id'] : null,
               $sessionId,
               (string)($session['session_date'] ?? date('Y-m-d')),
               1,
               $reason !== '' ? $reason : null,
               $recordedBy,
           ]);
    }
}
if (!function_exists('markSessionCompleted')) {
    function markSessionCompleted(int $sessionId, ?int $closedBy = null): void {
        getDB()->prepare("UPDATE attendance_sessions SET status=?, is_missed=0, closed_by=COALESCE(?, closed_by, teacher_id), closed_at=COALESCE(closed_at, NOW()) WHERE id=? AND status='open'")
              ->execute([preferredCompletedSessionStatus(), $closedBy, $sessionId]);
    }
}

function adminUserIds(): array {
    $stmt = getDB()->prepare("SELECT id FROM users WHERE role_id=? AND status='active' AND deleted_at IS NULL");
    $stmt->execute([ROLE_ADMIN]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

function facultyManagerIds(int $facultyId): array {
    if ($facultyId <= 0) return [];
    $stmt = getDB()->prepare("SELECT id FROM users WHERE role_id=? AND faculty_id=? AND status='active' AND deleted_at IS NULL");
    $stmt->execute([ROLE_ATT_MGR, $facultyId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

function notifyMissedSession(array $session): void {
    $targetUsers = [];
    $targetUsers = array_merge($targetUsers, adminUserIds());
    $targetUsers = array_merge($targetUsers, facultyManagerIds((int)$session['faculty_id']));
    $stmt = getDB()->prepare("SELECT id FROM users WHERE role_id=? AND faculty_id=? AND status='active' AND deleted_at IS NULL");
    $stmt->execute([ROLE_DEPT_HEAD, $session['faculty_id']]);
    $targetUsers = array_merge($targetUsers, array_column($stmt->fetchAll(), 'id'));
    $targetUsers[] = (int)$session['teacher_id'];
    $title = 'Missed class detected';
    $msg = ($session['subject_name'] ?? 'A class').' for '.($session['class_name'] ?? 'class').' on '.$session['session_date'].' '.$session['slot_label'].' was not marked before the allowed time.';
    foreach (array_unique(array_filter($targetUsers)) as $uid) createNotification((int)$uid, $title, $msg, 'danger', BASE_URL.'/admin/attendance.php');
}

function markMissedSessionsForDate(string $date): void {

    

    
    $db = getDB();
    $stmt = $db->prepare("SELECT s.*, t.faculty_id, t.department_id, c.name as class_name, sub.name as subject_name
        FROM attendance_sessions s
        JOIN timetable t ON t.id=s.timetable_id
        JOIN classes c ON c.id=s.class_id
        JOIN subjects sub ON sub.id=s.subject_id
        WHERE s.session_date=? AND s.status='open' AND TIMESTAMP(s.session_date, s.end_time) < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $stmt->execute([$date, MISSED_SESSION_GRACE_MINUTES]);
    foreach ($stmt->fetchAll() as $session) {
        try {
            $db->beginTransaction();
            if (sessionHasAttendanceRecords((int)$session['id'])) {
                markSessionCompleted((int)$session['id'], (int)($session['teacher_id'] ?? 0));
                $db->commit();
                continue;
            }
            ensureMissedClassLedgerEntry($session, (int)($session['teacher_id'] ?? 0), 'Automatically marked as missed because no attendance was submitted before the class window expired.');
            creditPresentForSessionStudents($session, (int)($session['teacher_id'] ?? 0), 'system_auto_missed', 'Automatically marked present because the class window expired without attendance submission.');
            $db->prepare("UPDATE attendance_sessions SET status='missed', is_missed=1, closed_at=NOW(), closed_by=COALESCE(closed_by, teacher_id) WHERE id=? AND status='open'")
               ->execute([$session['id']]);
            $db->commit();
            notifyMissedSession($session);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
        }
    }
}

function facultyDeanForFaculty(int $facultyId): ?int {
    $stmt = getDB()->prepare("SELECT id FROM users WHERE role_id=? AND faculty_id=? AND status='active' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    $stmt->execute([ROLE_DEPT_HEAD,$facultyId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function approvedTimetableWeeklyCredits(int $classId, int $subjectId): int {
    $stmt = getDB()->prepare("SELECT COALESCE(SUM(credit_units),0) FROM timetable WHERE class_id=? AND subject_id=? AND status='approved'");
    $stmt->execute([$classId,$subjectId]);
    return (int)$stmt->fetchColumn();
}

function subjectDeprivationThreshold(int $weeklyCredits): int { return max(1, $weeklyCredits * 4); }
function subjectWarningThreshold(int $weeklyCredits): int { return max(1, subjectDeprivationThreshold($weeklyCredits) - absenceWarningBuffer()); }

function totalScheduledMeetingsForClass(?int $classId): int {
    if (!$classId) return 0;
    $stmt = getDB()->prepare("SELECT COALESCE(SUM(credit_units),0) FROM timetable WHERE class_id=? AND status='approved'");
    $stmt->execute([$classId]);
    return (int)$stmt->fetchColumn() * currentAcademicWeeks();
}

function heldMeetingsForClass(?int $classId): int {
    if (!$classId) return 0;
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE class_id=? AND status IN ('completed','closed','missed','open')");
    $stmt->execute([$classId]);
    return (int)$stmt->fetchColumn();
}

function studentSubjectAttendanceSummary(int $studentId): array {
    $sql = "SELECT s.id AS subject_id,
                   s.name AS subject_name,
                   s.code,
                   c.id AS class_id,
                   c.name AS class_name,
                   COALESCE(tts.weekly_credits,0) AS weekly_credits,
                   COALESCE(stats.present_count,0) AS present_count,
                   COALESCE(stats.absent_count,0) AS absent_count,
                   0 AS excused_count,
                   COALESCE(stats.total_marked,0) AS held_credits,
                   COALESCE(stats.total_marked,0) AS total_marked
            FROM users u
            JOIN classes c ON c.id=u.class_id
            JOIN (
                SELECT tt.class_id, tt.subject_id, SUM(tt.credit_units) AS weekly_credits
                FROM timetable tt
                WHERE tt.status='approved'
                GROUP BY tt.class_id, tt.subject_id
            ) tts ON tts.class_id=c.id
            JOIN subjects s ON s.id=tts.subject_id
            LEFT JOIN (
                SELECT ses.class_id,
                       ses.subject_id,
                       ar.student_id,
                       SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS present_count,
                       SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END) AS absent_count,
                       COUNT(ar.id) AS total_marked
                FROM attendance_records ar
                JOIN attendance_sessions ses ON ses.id=ar.session_id
                GROUP BY ses.class_id, ses.subject_id, ar.student_id
            ) stats ON stats.class_id=c.id AND stats.subject_id=s.id AND stats.student_id=u.id
            WHERE u.id=?
            ORDER BY s.name";
    $stmt = getDB()->prepare($sql);
    $stmt->execute([$studentId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $weekly = (int)$row['weekly_credits'];
        $scheduled = scheduledCreditsForSubject($weekly);
        $present = (int)$row['present_count'];
        $absent = (int)$row['absent_count'];
        $excused = (int)$row['excused_count'];
        $marked = max(0, (int)$row['total_marked']);
        $held = $marked;
        $attendancePct = $marked > 0 ? round(($present / $marked) * 100, 1) : 0;
        $absencePct = function_exists('absencePctClassic')
            ? absencePctClassic($absent, $weekly, (int)currentAcademicWeeks())
            : subjectAbsencePercent($absent, $scheduled);
        $progressPct = $scheduled > 0 ? round((min($held, $scheduled) / $scheduled) * 100, 1) : 0;
        $warningReached = $absencePct >= attendanceWarningPercentThreshold();
        $deprived = $absencePct >= attendanceDeprivationPercentThreshold();
        $rows[] = [
            'subject_id' => (int)$row['subject_id'],
            'subject_name' => $row['subject_name'],
            'code' => $row['code'],
            'class_id' => (int)$row['class_id'],
            'class_name' => $row['class_name'],
            'weekly_credits' => $weekly,
            'semester_credits' => $scheduled,
            'held_credits' => $held,
            'present_count' => $present,
            'absent_count' => $absent,
            'excused_count' => $excused,
            'total_marked' => $marked,
            'total_scheduled' => $scheduled,
            'attendance_pct' => $attendancePct,
            'absence_pct' => $absencePct,
            'progress_pct' => $progressPct,
            'warning_at' => attendanceWarningPercentThreshold(),
            'deprive_at' => attendanceDeprivationPercentThreshold(),
            'warning_reached' => $warningReached,
            'deprived' => $deprived,
            'risk_badge' => absencePercentClass($absencePct),
        ];
    }
    return $rows;
}

function studentAbsenceSummary(int $studentId): array {
    $subjects = studentSubjectAttendanceSummary($studentId);
    $absent = array_sum(array_column($subjects, 'absent_count'));
    $present = array_sum(array_column($subjects, 'present_count'));
    $held = array_sum(array_column($subjects, 'held_credits'));
    $scheduled = array_sum(array_column($subjects, 'total_scheduled'));
    $warningCount = count(array_filter($subjects, fn($sub) => !empty($sub['warning_reached']) && empty($sub['deprived'])));
    $deprivedCount = count(array_filter($subjects, fn($sub) => !empty($sub['deprived'])));
    $maxAbsencePct = 0.0;
    foreach ($subjects as $sub) $maxAbsencePct = max($maxAbsencePct, (float)$sub['absence_pct']);
    return [
        'subject_count' => count($subjects),
        'absent_count' => $absent,
        'attended_count' => $present,
        'held_count' => $held,
        'total_scheduled' => $scheduled,
        'warning_subjects' => $warningCount,
        'deprived_subjects' => $deprivedCount,
        'warning_reached' => $warningCount > 0 || $deprivedCount > 0,
        'deprived' => $deprivedCount > 0,
        'overall_absence_pct' => $scheduled > 0 ? round(($absent / $scheduled) * 100, 1) : 0.0,
        'max_absence_pct' => round($maxAbsencePct, 1),
        'avg_progress_pct' => count($subjects) ? round(array_sum(array_column($subjects, 'progress_pct')) / count($subjects), 1) : 0.0,
        'warning_at' => attendanceWarningPercentThreshold(),
        'deprive_at' => attendanceDeprivationPercentThreshold(),
        'remaining_to_warning_pct' => max(attendanceWarningPercentThreshold() - $maxAbsencePct, 0),
        'remaining_to_deprive_pct' => max(attendanceDeprivationPercentThreshold() - $maxAbsencePct, 0),
    ];
}

function attendanceRiskBadgeByAbsences(int $absentCount, int $warningAt = 6, int $depriveAt = 8): string {
    if ($absentCount >= $depriveAt) return 'danger';
    if ($absentCount >= $warningAt) return 'warning';
    return 'success';
}

function studentAttendanceHistory(int $studentId, int $subjectId, string $groupBy = 'day'): array {
    $allowed = ['day','week','month','semester'];
    if (!in_array($groupBy, $allowed, true)) $groupBy = 'day';

    $groupExpr = match ($groupBy) {
        'week' => "YEARWEEK(s.session_date,1)",
        'month' => "DATE_FORMAT(s.session_date, '%Y-%m')",
        'semester' => "COALESCE(CONCAT(sem.name, ' (#', sem.id, ')'), 'Semester')",
        default => "DATE(s.session_date)",
    };
    $labelExpr = match ($groupBy) {
        'week' => "CONCAT(YEAR(s.session_date), '-W', LPAD(WEEK(s.session_date,1),2,'0'))",
        'month' => "DATE_FORMAT(s.session_date, '%Y-%m')",
        'semester' => "COALESCE(sem.name, 'Semester')",
        default => "DATE_FORMAT(s.session_date, '%Y-%m-%d')",
    };

    $sql = "SELECT {$labelExpr} as period_label,
                   MIN(s.session_date) as period_start,
                   MAX(s.session_date) as period_end,
                   COUNT(DISTINCT s.id) as held_credits,
                   SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) as present_count,
                   SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END) as absent_count,
                   0 as excused_count
            FROM attendance_sessions s
            JOIN attendance_records ar ON ar.session_id=s.id AND ar.student_id=?
            LEFT JOIN timetable t ON t.id=s.timetable_id
            LEFT JOIN semesters sem ON sem.id=t.semester_id
            WHERE s.subject_id=?
            GROUP BY {$groupExpr}
            ORDER BY MIN(s.session_date) DESC";
    $stmt = getDB()->prepare($sql);
    $stmt->execute([$studentId, $subjectId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $held = (int)$row['held_credits'];
        $absent = (int)$row['absent_count'];
        
        $rows[] = [
            'period_label' => $row['period_label'],
            'period_start' => $row['period_start'],
            'period_end' => $row['period_end'],
            'held_credits' => $held,
            'present_count' => (int)$row['present_count'],
            'absent_count' => $absent,
            'excused_count' => (int)$row['excused_count'],
            'absence_pct' => $held > 0 ? round(($absent / $held) * 100, 1) : 0.0,
        ];
    }
    return $rows;
}

function studentAttendanceTimeline(int $studentId, int $subjectId, int $limit = 80): array {

    
    
    $limit = max(1, min($limit, 200));
    $stmt = getDB()->prepare("SELECT s.id AS session_id, ar.id AS record_id,
                                     s.session_date, s.slot_label, s.start_time, s.end_time,
                                     ar.status, ar.marked_at, ar.correction_note,
                                     c.name as class_name, c.section,
                                     sub.name as subject_name
                              FROM attendance_records ar
                              JOIN attendance_sessions s ON s.id=ar.session_id
                              JOIN classes c ON c.id=s.class_id
                              JOIN subjects sub ON sub.id=s.subject_id
                              WHERE ar.student_id=? AND s.subject_id=?
                              ORDER BY s.session_date DESC, s.start_time DESC, s.credit_slot_no DESC
                              LIMIT {$limit}");
    $stmt->execute([$studentId, $subjectId]);
    return $stmt->fetchAll();
}

function attendanceTrendSeries(int $days = 7, array $scope = []): array {
    $days = max(1, min($days, 90));
    $labels = [];
    $attendance = [];
    $absence = [];
    $held = [];
    $db = getDB();

    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime('-'.$i.' days'));
        if (!empty($scope['teacher_id'])) syncSessionsForDate($date, (int)$scope['teacher_id']);
        elseif (empty($scope['student_id'])) syncSessionsForDate($date);
        markMissedSessionsForDate($date);

        $joins = '';
        $where = ' WHERE s.session_date=? ';
        $params = [$date];

        if (!empty($scope['faculty_id'])) {
            $joins .= ' JOIN timetable t ON t.id=s.timetable_id ';
            $where .= ' AND t.faculty_id=? ';
            $params[] = (int)$scope['faculty_id'];
        }
        if (!empty($scope['teacher_id'])) {
            $where .= ' AND s.teacher_id=? ';
            $params[] = (int)$scope['teacher_id'];
        }
        if (!empty($scope['student_id'])) {
            $where .= ' AND r.student_id=? ';
            $params[] = (int)$scope['student_id'];
        }

        $sql = "SELECT ROUND(SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) * 100 / NULLIF(COUNT(r.id),0), 1) as attendance_pct,
                       ROUND(SUM(CASE WHEN r.status='absent' THEN 1 ELSE 0 END) * 100 / NULLIF(COUNT(r.id),0), 1) as absence_pct,
                       COUNT(DISTINCT s.id) as held_credits
                FROM attendance_sessions s
                JOIN attendance_records r ON r.session_id=s.id
                {$joins}
                {$where}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        $labels[] = date('d M', strtotime($date));
        $attendance[] = (float)($row['attendance_pct'] ?? 0);
        $absence[] = (float)($row['absence_pct'] ?? 0);
        $held[] = (int)($row['held_credits'] ?? 0);
    }

    return ['labels' => $labels, 'attendance' => $attendance, 'absence' => $absence, 'held' => $held];
}

function saveGroupedAttendance(int $timetableId, string $sessionDate, array $attendanceMap, int $actorId, string $method='manual'): array {
    $db = getDB();
    syncSessionsForDate($sessionDate, 0, $timetableId);
    $sessionsStmt = $db->prepare("SELECT s.*, t.faculty_id, sub.name as subject_name, c.name as class_name FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id JOIN subjects sub ON sub.id=s.subject_id JOIN classes c ON c.id=s.class_id WHERE s.timetable_id=? AND s.session_date=? ORDER BY s.credit_slot_no");
    $sessionsStmt->execute([$timetableId,$sessionDate]);
    $sessions = $sessionsStmt->fetchAll();
    if (!$sessions) return ['present'=>0,'absent'=>0,'sessions'=>0];

    $classId = (int)$sessions[0]['class_id'];
    $subjectId = (int)$sessions[0]['subject_id'];
    $facultyId = (int)$sessions[0]['faculty_id'];
    $teacherId = (int)$sessions[0]['teacher_id'];
    $studentsStmt = $db->prepare("SELECT id, full_name FROM users WHERE role_id=? AND class_id=? AND status='active' AND deleted_at IS NULL ORDER BY full_name");
    $studentsStmt->execute([ROLE_STUDENT,$classId]);
    $students = $studentsStmt->fetchAll();

    $present = 0; $absent = 0; $cleared = 0;
    $sessionIds = array_map('intval', array_column($sessions, 'id'));
    $sessionStatus = preferredCompletedSessionStatus();

    try {
        $db->beginTransaction();
        foreach ($students as $student) {
            $studentId = (int)$student['id'];
            foreach ($sessions as $session) {
                $sessionId = (int)$session['id'];
                $raw = $attendanceMap[$studentId][$sessionId] ?? null;
                $status = function_exists('normalizeAttStatus')
                    ? normalizeAttStatus((string)($raw ?? ''))
                    : trim((string)($raw ?? ''));

                if ($status === '') {
                    $db->prepare("DELETE FROM attendance_records WHERE session_id=? AND student_id=?")
                       ->execute([$sessionId, $studentId]);
                    $cleared++;
                    continue;
                }
                if (!in_array($status, ['present','absent','حالی'], true)) {
                    $status = 'حالی';
                }
                $db->prepare("INSERT INTO attendance_records(session_id,student_id,status,marked_at,mark_method,ip_address,device_info) VALUES(?,?,?,NOW(),?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), marked_at=NOW(), mark_method=VALUES(mark_method), ip_address=VALUES(ip_address), device_info=VALUES(device_info)")
                   ->execute([$sessionId,$studentId,$status,$method,$_SERVER['REMOTE_ADDR'] ?? '',$_SERVER['HTTP_USER_AGENT'] ?? '']);
                writeAttendanceHistory($sessionId, $studentId, $status, 'teacher_session_save', 'Attendance saved from grouped teacher session entry.', $actorId);
                if ($status === 'present') $present++;
                elseif ($status === 'absent') $absent++;
            }
        }

        $in = implode(',', array_fill(0, count($sessionIds), '?'));
        $db->prepare("UPDATE attendance_sessions SET status=?, is_missed=0, closed_by=?, closed_at=NOW() WHERE id IN ($in)")
           ->execute(array_merge([$sessionStatus, $actorId], $sessionIds));
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $title = 'Attendance submitted';
    $msg = $sessions[0]['subject_name'].' for '.$sessions[0]['class_name'].' on '.$sessionDate.' was saved and finalized as '.sessionStatusLabel($sessionStatus).'. Present marks: '.$present.', Absent marks: '.$absent.'.';
    $savedRecipients = array_merge([$teacherId], adminUserIds(), facultyManagerIds($facultyId));
    $deanId = facultyDeanForFaculty($facultyId);
    if ($deanId) $savedRecipients[] = $deanId;
    foreach (array_unique(array_filter($savedRecipients)) as $uid) {
        createNotification((int)$uid, $title, $msg, 'success', BASE_URL.'/teacher/sessions.php?view='.$timetableId.'&date='.$sessionDate);
    }

    foreach ($students as $student) {
        $riskRows = studentSubjectAttendanceSummary((int)$student['id']);
        foreach ($riskRows as $risk) {
            if ((int)$risk['subject_id'] !== $subjectId) continue;
            $absencePct = (float)($risk['absence_pct'] ?? 0);
            $studentLink = BASE_URL.'/student/attendance.php?subject='.$risk['subject_id'].'&group=semester';
            $hierarchyLink = BASE_URL.'/hierarchy.php?level=student&fid='.$facultyId.'&cid='.$classId.'&sid='.$risk['subject_id'].'&uid='.(int)$student['id'];
            $studentName  = $student['full_name'];
            $subjectName  = $risk['subject_name'];

            if (!empty($risk['deprived'])) {
                $selfTitle = t('notif_deprived_subject', ['subject'=>$subjectName], 'Deprivation: '.$subjectName);
                $selfMsg   = t('notif_deprived_self', ['subject'=>$subjectName,'pct'=>$absencePct], $subjectName.' reached '.$absencePct.'% absence — you are deprived.');
                createNotification((int)$student['id'], $selfTitle, $selfMsg, 'danger', $studentLink);

                $staffTitle = t('notif_deprived_subject', ['subject'=>$subjectName], 'Deprivation alert: '.$subjectName);
                $staffMsg   = t('notif_deprived_message', ['student'=>$studentName,'subject'=>$subjectName,'pct'=>$absencePct], $studentName.' has been deprived from '.$subjectName.' at '.$absencePct.'%.');
                if ($teacherId) createNotification($teacherId, $staffTitle, $staffMsg, 'danger', $hierarchyLink);
                foreach (adminUserIds() as $adminId) {
                    createNotification((int)$adminId, $staffTitle, $staffMsg, 'danger', $hierarchyLink);
                }
            } elseif (!empty($risk['warning_reached'])) {
                $title = t('notif_warning_subject', ['subject'=>$subjectName], 'Attendance warning: '.$subjectName);
                $msg   = t('notif_warning_message', ['subject'=>$subjectName,'pct'=>$absencePct], 'Your absence in '.$subjectName.' reached '.$absencePct.'%.');
                createNotification((int)$student['id'], $title, $msg, 'warning', $studentLink);
            }
        }
    }

    if (function_exists('cacheBustDashboards')) { cacheBustDashboards(); }

    return ['present'=>$present,'absent'=>$absent,'cleared'=>$cleared,'sessions'=>count($sessions),'session_status'=>$sessionStatus];
}

function userFacultyName(?int $facultyId): string {
    if (!$facultyId) return '-';
    $stmt = getDB()->prepare("SELECT name FROM faculties WHERE id=?"); $stmt->execute([$facultyId]);
    return $stmt->fetchColumn() ?: '-';
}

if (!function_exists('isRtl')) {
    function isRtl(): bool { return in_array($GLOBALS['currentLang'] ?? 'ps', ['ps','fa','ur'], true); }
}

function canonicalRoleName(int $roleId): string {
    $map = [
        ROLE_ADMIN     => 'Vice_chancellor',
        ROLE_DEPT_HEAD => 'Faculty_dean',
        ROLE_ATT_MGR   => 'Manager',
        ROLE_TEACHER   => 'Teacher',
        ROLE_STUDENT   => 'Student',
    ];
    if (defined('ROLE_VIEWER')) {
        $map[ROLE_VIEWER] = 'Viewer';
    }
    return $map[$roleId] ?? 'User';
}

function roleOptionMap(bool $includeAdmin = true): array {
    $map = [
        ROLE_ADMIN     => canonicalRoleName(ROLE_ADMIN),
        ROLE_DEPT_HEAD => canonicalRoleName(ROLE_DEPT_HEAD),
        ROLE_ATT_MGR   => canonicalRoleName(ROLE_ATT_MGR),
        ROLE_TEACHER   => canonicalRoleName(ROLE_TEACHER),
        ROLE_STUDENT   => canonicalRoleName(ROLE_STUDENT),
    ];
    if (!$includeAdmin) {
        unset($map[ROLE_ADMIN]);
    }
    return $map;
}

function relabelRoleRows(array $rows, string $roleIdKey = 'id', string $labelKey = 'label'): array {
    foreach ($rows as &$row) {
        $rid = (int)($row[$roleIdKey] ?? 0);
        if ($rid > 0) {
            $row[$labelKey] = canonicalRoleName($rid);
        }
    }
    unset($row);
    return $rows;
}

function roleDisplayName(int $roleId): string {
    return canonicalRoleName($roleId);
}

function roleHomeUrl(int $roleId): string {
    
    return match ($roleId) {
        ROLE_ADMIN => BASE_URL.'/admin/dashboard.php',
        ROLE_DEPT_HEAD => BASE_URL.'/dean/dashboard.php',
        ROLE_ATT_MGR => BASE_URL.'/manager/dashboard.php',
        ROLE_TEACHER => BASE_URL.'/teacher/dashboard.php',
        ROLE_STUDENT => BASE_URL.'/student/dashboard.php',
        default => BASE_URL.'/login.php',
    };
}

function currentSectionKey(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    foreach (['admin','dean','manager','teacher','student','profile','notifications','reports','api','missed'] as $section) {
        if (strpos($script, '/' . $section . '/') !== false || str_ends_with($script, '/' . $section)) {
            return $section;
        }
    }
    return '';
}

function currentSectionBaseUrl(): string {
    $section = currentSectionKey();
    return $section !== '' ? BASE_URL . '/' . $section : BASE_URL;
}

function currentSectionPageUrl(string $page): string {
    $page = ltrim($page, '/');
    return currentSectionBaseUrl() . '/' . $page;
}

function redirectLegacyDeanManagerRoute(): void {
    if ((int)($_SESSION['role_id'] ?? 0) !== ROLE_DEPT_HEAD) return;
    $script = basename(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''));
    $map = [
        'dashboard.php'      => '/dean/dashboard.php',
        'approvals.php'      => '/dean/approvals.php',
        'attendance.php'     => '/dean/attendance.php',
        'session_detail.php' => '/dean/session_detail.php',
        'timetable.php'      => '/dean/timetable.php',
        'users.php'          => '/dean/users.php',
        'announcements.php'  => '/notifications/announcements.php',
        'attendance_edit.php'=> '/dean/attendance.php',
    ];
    if (!isset($map[$script])) return;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect(BASE_URL . $map[$script] . ($qs !== '' ? '?' . $qs : ''));
}

function currentFacultyScopeId(): ?int {
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if (in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ATT_MGR, ROLE_TEACHER, ROLE_STUDENT], true)) {
        $id = (int)($_SESSION['faculty_id'] ?? 0);
        return $id > 0 ? $id : null;
    }
    return null;
}

function currentDepartmentScopeId(): ?int {
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if (in_array($roleId, [ROLE_TEACHER, ROLE_STUDENT], true)) {
        $id = (int)($_SESSION['department_id'] ?? 0);
        return $id > 0 ? $id : null;
    }
    return null;
}

function currentClassScopeId(): ?int {
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($roleId === ROLE_STUDENT) {
        $id = (int)($_SESSION['class_id'] ?? 0);
        return $id > 0 ? $id : null;
    }
    return null;
}

function userCanManageTargetRole(int $targetRoleId): bool {
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($roleId === ROLE_ADMIN) return $targetRoleId !== ROLE_ADMIN;
    if ($roleId === ROLE_DEPT_HEAD) return in_array($targetRoleId, [ROLE_DEPT_HEAD, ROLE_ATT_MGR, ROLE_TEACHER, ROLE_STUDENT], true);
    if ($roleId === ROLE_ATT_MGR) return in_array($targetRoleId, [ROLE_TEACHER, ROLE_STUDENT], true);
    return false;
}

function reportPeriodDateRange(string $period, ?string $from = null, ?string $to = null): array {
    $today = new DateTimeImmutable('today');
    $period = in_array($period, ['day','week','month','semester','custom'], true) ? $period : 'month';
    return match ($period) {
        'day' => [$today->format('Y-m-d'), $today->format('Y-m-d')],
        'week' => [$today->modify('monday this week')->format('Y-m-d'), $today->modify('sunday this week')->format('Y-m-d')],
        'semester' => [$today->modify('-6 months')->format('Y-m-d'), $today->format('Y-m-d')],
        'custom' => [($from ?: $today->modify('first day of this month')->format('Y-m-d')), ($to ?: $today->format('Y-m-d'))],
        default => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
    };
}

function reportFilterOptions(array $force = []): array {
    $db = getDB();
    $facultyScope = $force['faculty_id'] ?? currentFacultyScopeId();
    $departmentScope = $force['department_id'] ?? currentDepartmentScopeId();
    $classScope = $force['class_id'] ?? currentClassScopeId();

    $facSql = "SELECT id,name FROM faculties WHERE is_active=1";
    $facParams = [];
    if ($facultyScope) { $facSql .= " AND id=?"; $facParams[] = $facultyScope; }
    $facSql .= " ORDER BY name";
    $fac = $db->prepare($facSql); $fac->execute($facParams);

    $depSql = "SELECT id,name,faculty_id FROM departments WHERE is_active=1";
    $depParams = [];
    if ($facultyScope) { $depSql .= " AND faculty_id=?"; $depParams[] = $facultyScope; }
    if ($departmentScope) { $depSql .= " AND id=?"; $depParams[] = $departmentScope; }
    $depSql .= " ORDER BY name";
    $dep = $db->prepare($depSql); $dep->execute($depParams);

    $classSql = "SELECT c.id,c.name,c.section,c.department_id,c.faculty_id, sem.name as semester_name FROM classes c LEFT JOIN semesters sem ON sem.id=c.semester_id WHERE c.is_active=1";
    $classParams = [];
    if ($facultyScope) { $classSql .= " AND c.faculty_id=?"; $classParams[] = $facultyScope; }
    if ($departmentScope) { $classSql .= " AND c.department_id=?"; $classParams[] = $departmentScope; }
    if ($classScope) { $classSql .= " AND c.id=?"; $classParams[] = $classScope; }
    $classSql .= " ORDER BY c.name,c.section";
    $classStmt = $db->prepare($classSql); $classStmt->execute($classParams);

    $subSql = "SELECT s.id,s.name,s.department_id,s.faculty_id, sem.name as semester_name FROM subjects s LEFT JOIN semesters sem ON sem.id=s.semester_id WHERE s.is_active=1";
    $subParams = [];
    if ($facultyScope) { $subSql .= " AND s.faculty_id=?"; $subParams[] = $facultyScope; }
    if ($departmentScope) { $subSql .= " AND (s.department_id=? OR s.department_id IS NULL)"; $subParams[] = $departmentScope; }
    $subSql .= " ORDER BY s.name";
    $subStmt = $db->prepare($subSql); $subStmt->execute($subParams);

    $semSql = "SELECT id,name,faculty_id,semester_no,year_no FROM semesters WHERE is_active=1";
    $semParams = [];
    if ($facultyScope) { $semSql .= " AND faculty_id=?"; $semParams[] = $facultyScope; }
    $semSql .= " ORDER BY semester_no";
    $semStmt = $db->prepare($semSql); $semStmt->execute($semParams);

    return [
        'faculties' => $fac->fetchAll(),
        'departments' => $dep->fetchAll(),
        'classes' => $classStmt->fetchAll(),
        'subjects' => $subStmt->fetchAll(),
        'semesters' => $semStmt->fetchAll(),
    ];
}

if (!function_exists('facultyTimetableStatusesForFilter')) {
    /**
     * Map the simplified status filter values used in the Dean / Faculty timetable UI
     * ('pending','approved','rejected') to the raw status enum values stored in the
     * timetable table ('draft','faculty_approved','approved','rejected').
     * Returns an empty array when the filter value is unknown, which means "do not filter by status".
     */
    function facultyTimetableStatusesForFilter(string $filter): array {
        switch ($filter) {
            case 'pending':
                return ['draft','faculty_approved'];
            case 'approved':
                return ['approved'];
            case 'rejected':
                return ['rejected'];
            default:
                return [];
        }
    }
}

if (!function_exists('facultyTimetableUiBadge')) {
    /**
     * Bootstrap badge colour used in the Dean timetable list for a given raw status.
     */
    function facultyTimetableUiBadge(string $status): string {
        switch ($status) {
            case 'approved':         return 'success';
            case 'rejected':         return 'danger';
            case 'faculty_approved': return 'info';
            case 'draft':            return 'warning text-dark';
            default:                 return 'secondary';
        }
    }
}

if (!function_exists('facultyTimetableUiLabel')) {
    /**
     * Human-readable label for a raw timetable status, shown in the Dean timetable list.
     */
    function facultyTimetableUiLabel(string $status): string {
        switch ($status) {
            case 'approved':         return 'Approved';
            case 'rejected':         return 'Rejected';
            case 'draft':            return 'Pending';
            default:                 return ucfirst(str_replace('_',' ',$status));
        }
    }
}

if (!function_exists('reportScopeForCurrentUser')) {
    
    function reportScopeForCurrentUser(array $aliases = []): array {
        $a = array_merge([
            't' => 't',     
            'c' => 'c',     
            's' => 's',     
            'r' => 'r',     
            'u' => 'u',     
        ], $aliases);

        $roleId       = (int)($_SESSION['role_id']       ?? 0);
        $facultyId    = (int)($_SESSION['faculty_id']    ?? 0);
        $departmentId = (int)($_SESSION['department_id'] ?? 0);
        $classId      = (int)($_SESSION['class_id']      ?? 0);
        $userId       = (int)($_SESSION['user_id']       ?? 0);

        $where  = [];
        $params = [];

        
        if ($roleId === ROLE_ADMIN) {
            if ($facultyId > 0) {
                $where[] = "{$a['t']}.faculty_id=?";
                $params[] = $facultyId;
            }
        }
        
        elseif (in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ATT_MGR], true)) {
            if ($facultyId > 0) {
                $where[] = "{$a['t']}.faculty_id=?";
                $params[] = $facultyId;
            }
            
            if ($roleId === ROLE_ATT_MGR && $departmentId > 0) {
                $where[] = "({$a['c']}.department_id=? OR {$a['c']}.department_id IS NULL)";
                $params[] = $departmentId;
            }
        }
        
        elseif ($roleId === ROLE_TEACHER) {
            if ($userId > 0) {
                $where[] = "{$a['s']}.teacher_id=?";
                $params[] = $userId;
            }
            if ($facultyId > 0) {
                $where[] = "{$a['t']}.faculty_id=?";
                $params[] = $facultyId;
            }
        }
        
        elseif ($roleId === ROLE_STUDENT) {
            if ($classId > 0) {
                $where[] = "{$a['s']}.class_id=?";
                $params[] = $classId;
            }
            if ($userId > 0 && $a['u'] !== '') {
                $where[] = "{$a['u']}.id=?";
                $params[] = $userId;
            }
        }

        return [
            'where'         => $where,
            'params'        => $params,
            'role_id'       => $roleId,
            'faculty_id'    => $facultyId,
            'department_id' => $departmentId,
            'class_id'      => $classId,
            'user_id'       => $userId,
        ];
    }
}

if (!function_exists('reportNotificationModes')) {
    
    function reportNotificationModes(): array {
        return ['all', 'warning', 'deprived', 'notify'];
    }
}

if (!function_exists('reportApplyNotificationFilter')) {
    
    function reportApplyNotificationFilter(array $rows, string $mode): array {
        $warning  = attendanceWarningPercentThreshold();
        $deprived = attendanceDeprivationPercentThreshold();
        switch ($mode) {
            case 'warning':
                return array_values(array_filter($rows, fn($r) =>
                    (float)$r['absence_pct'] >= $warning &&
                    (float)$r['absence_pct'] <  $deprived));
            case 'deprived':
                return array_values(array_filter($rows, fn($r) =>
                    (float)$r['absence_pct'] >= $deprived));
            case 'notify':
                return array_values(array_filter($rows, fn($r) =>
                    (float)$r['absence_pct'] >= $warning));
            default:
                return $rows;
        }
    }
}

if (!function_exists('reportPrivacyMaskName')) {
    
    function reportPrivacyMaskName(string $name): string {
        $name = trim($name);
        if ($name === '') return '';
        $parts = preg_split('/\s+/u', $name);
        $first = $parts[0] ?? '';
        $rest  = '';
        if (count($parts) > 1) {
            
            $second = $parts[1];
            $len = mb_strlen($second);
            $rest = ' ' . mb_substr($second, 0, 1) . str_repeat('*', max(1, $len - 1));
        }
        return $first . $rest;
    }
}

if (!function_exists('reportPrivacyMaskId')) {
    
    function reportPrivacyMaskId(?string $id): string {
        $id = (string)$id;
        if ($id === '') return '';
        $len = mb_strlen($id);
        if ($len <= 3) return str_repeat('*', $len);
        return str_repeat('*', $len - 3) . mb_substr($id, -3);
    }
}

function userMiniHistory(int $userId, int $limit = 8): array {
    $limit = max(1, min($limit, 30));
    $stmt = getDB()->prepare("SELECT action,module,description,created_at FROM audit_log WHERE user_id=? ORDER BY created_at DESC LIMIT {$limit}");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function writeAttendanceHistory(int $sessionId, int $studentId, string $status, string $sourceModule = 'attendance', string $notes = '', ?int $createdBy = null): void {

    return;
}

if (!function_exists('cs_only_filter')) {
    
    function cs_only_filter(string $facultyAlias = 't'): array {
        return ['sql' => " AND {$facultyAlias}.faculty_id=? ", 'params' => [PRIMARY_FACULTY_ID]];
    }
}

if (!function_exists('attendancePieData')) {
    
    function attendancePieData(array $scope = []): array {
        $where = ["1=1"]; $params = [];
        $joins = "";
        if (!empty($scope['student_id'])) { $where[] = "ar.student_id=?"; $params[] = (int)$scope['student_id']; }
        if (!empty($scope['teacher_id'])) { $where[] = "s.teacher_id=?"; $params[] = (int)$scope['teacher_id']; }
        if (!empty($scope['class_id']))   { $where[] = "s.class_id=?";   $params[] = (int)$scope['class_id']; }
        if (!empty($scope['subject_id'])) { $where[] = "s.subject_id=?"; $params[] = (int)$scope['subject_id']; }
        if (!empty($scope['faculty_id'])) {
            $joins .= " JOIN timetable t ON t.id=s.timetable_id ";
            $where[] = "t.faculty_id=?"; $params[] = (int)$scope['faculty_id'];
        }
        if (!empty($scope['from']) && !empty($scope['to'])) {
            $where[] = "s.session_date BETWEEN ? AND ?";
            $params[] = $scope['from']; $params[] = $scope['to'];
        }
        $sql = "SELECT
                    SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) as p,
                    SUM(CASE WHEN ar.status<>'present' THEN 1 ELSE 0 END) as a
                FROM attendance_records ar
                JOIN attendance_sessions s ON s.id=ar.session_id
                {$joins}
                WHERE ".implode(' AND ', $where);
        $stmt = getDB()->prepare($sql); $stmt->execute($params);
        $r = $stmt->fetch() ?: ['p'=>0,'a'=>0];
        return [
            'labels' => ['✅ Present','❌ Absent'],
            'data'   => [(int)$r['p'], (int)$r['a']],
            'colors' => ['#16a34a','#dc2626'],
        ];
    }
}

if (!function_exists('listMissedClasses')) {
    
    function listMissedClasses(array $filter = []): array {
        $db = getDB();
        $where = ["1=1"]; $params = [];
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $facultyId = (int)($_SESSION['faculty_id'] ?? 0);
        $deptId = (int)($_SESSION['department_id'] ?? 0);
        $classId = (int)($_SESSION['class_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($roleId === ROLE_TEACHER) { $where[] = "mc.teacher_id=?"; $params[] = $userId; }
        elseif ($roleId === ROLE_STUDENT) { $where[] = "mc.class_id=?"; $params[] = $classId; }
        elseif (in_array($roleId, [ROLE_DEPT_HEAD, ROLE_ATT_MGR], true) && $facultyId) {
            $where[] = "mc.faculty_id=?"; $params[] = $facultyId;
        }
        
        if (!empty($filter['subject_id'])) { $where[] = "mc.subject_id=?"; $params[] = (int)$filter['subject_id']; }
        if (!empty($filter['teacher_id'])) { $where[] = "mc.teacher_id=?"; $params[] = (int)$filter['teacher_id']; }
        if (!empty($filter['class_id']))   { $where[] = "mc.class_id=?";   $params[] = (int)$filter['class_id']; }
        if (!empty($filter['from']))       { $where[] = "mc.missed_date>=?"; $params[] = $filter['from']; }
        if (!empty($filter['to']))         { $where[] = "mc.missed_date<=?"; $params[] = $filter['to']; }

        $sql = "SELECT mc.*, sub.name as subject_name, c.name as class_name, c.section,
                       u.full_name as teacher_name, f.name as faculty_name, d.name as department_name
                FROM missed_classes mc
                JOIN subjects sub ON sub.id=mc.subject_id
                JOIN classes c ON c.id=mc.class_id
                JOIN users u ON u.id=mc.teacher_id
                JOIN faculties f ON f.id=mc.faculty_id
                LEFT JOIN departments d ON d.id=mc.department_id
                WHERE ".implode(' AND ', $where)."
                ORDER BY mc.missed_date DESC, mc.id DESC";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

if (!function_exists('totalMissedCreditsForStudent')) {
    
    function totalMissedCreditsForStudent(int $studentId, ?int $subjectId = null): int {
        $db = getDB();
        $sql = "SELECT COALESCE(SUM(mc.credits),0) FROM missed_classes mc
                JOIN users u ON u.class_id=mc.class_id
                WHERE u.id=? ";
        $params = [$studentId];
        if ($subjectId) { $sql .= " AND mc.subject_id=?"; $params[] = $subjectId; }
        $stmt = $db->prepare($sql); $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('semesterAbsencePctForStudent')) {
    
    function semesterAbsencePctForStudent(int $studentId, int $subjectId): array {
        $db = getDB();
        $stmt = $db->prepare("SELECT u.class_id FROM users u WHERE u.id=? LIMIT 1");
        $stmt->execute([$studentId]);
        $classId = (int)$stmt->fetchColumn();
        if (!$classId) return ['absent'=>0,'target'=>0,'pct'=>0];

        $weeklyStmt = $db->prepare("SELECT COALESCE(SUM(credit_units),0) FROM timetable WHERE class_id=? AND subject_id=? AND status='approved'");
        $weeklyStmt->execute([$classId,$subjectId]);
        $weekly = (int)$weeklyStmt->fetchColumn();
        $target = $weekly * currentAcademicWeeks();

        $absStmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END),0)
                                 FROM attendance_records ar
                                 JOIN attendance_sessions s ON s.id=ar.session_id
                                 WHERE ar.student_id=? AND s.subject_id=?");
        $absStmt->execute([$studentId, $subjectId]);
        $absent = (int)$absStmt->fetchColumn();

        return [
            'absent' => $absent,
            'target' => $target,
            'pct'    => $target > 0 ? round(($absent / $target) * 100, 1) : 0.0,
        ];
    }
}

if (!function_exists('teacherCreditsTaughtVsRemaining')) {
    /**
     * د هر ښوونکي لپاره د هر (مضمون × ټولګي) سره سم د پرمختګ راپور:
     *   - total      = د هغو timetable کریډیټونو مجموعه چې همدا ښوونکی یې تدریسوي × د سمسټر اونۍ
     *   - taught     = یوازې هغه attendance_sessions چې همدا ښوونکي ترسره کړي او بشپړ شوي
     *   - remaining  = پاتې کریډیټونه
     *
     * مهم: د `attendance_sessions` سره JOIN باید د `s.teacher_id = t.teacher_id` شرط ولري،
     * که نه نو که یو Class/مضمون څو ښوونکي ولري (مثلاً theory + practical)، نو
     * د بل ښوونکي بشپړ شوي سیشنونه به په همدې ښوونکي د پرمختګ سره غلط جمع شي،
     * چې د پرمختګ او پاتې سلنه به یې غلطه ښکاره کړي. (V20 fix)
     */
    function teacherCreditsTaughtVsRemaining(int $teacherId): array {
        $db = getDB();
        // FIX V21.1 — Previous query joined `timetable` with `attendance_sessions` and then
        // computed `SUM(t.credit_units) * weeks` AFTER the join. That multiplies the credit
        // units by the number of session rows produced by the LEFT JOIN, so the "total"
        // column was massively inflated (and changed every time a new session was generated).
        // The correct semantics are:
        //   total  = SUM(credit_units) over *distinct* timetable rows (this teacher / subject
        //            / class)  ×  academic weeks of the semester
        //   taught = number of distinct attendance_sessions that were actually delivered
        //            (completed / closed / missed) by THIS teacher for THAT timetable row
        // We compute the totals first per (subject, class) using a pure timetable aggregate,
        // then add the taught count via a correlated subquery so the JOIN can never inflate
        // the credit total again.
        $weeks = (int)currentAcademicWeeks();
        if ($weeks <= 0) { $weeks = (int)SEMESTER_WEEKS; }
        $sql = "SELECT sub.id            AS subject_id,
                       sub.name          AS subject_name,
                       c.id              AS class_id,
                       c.name            AS class_name,
                       c.section         AS section,
                       SUM(t.credit_units) * :weeks AS total,
                       (
                           SELECT COUNT(DISTINCT s.id)
                           FROM attendance_sessions s
                           WHERE s.teacher_id = t.teacher_id
                             AND s.subject_id = sub.id
                             AND s.class_id   = c.id
                             AND s.status IN ('completed','closed','missed')
                             AND s.timetable_id IN (
                                 SELECT t2.id FROM timetable t2
                                 WHERE t2.teacher_id = t.teacher_id
                                   AND t2.subject_id = sub.id
                                   AND t2.class_id   = c.id
                                   AND t2.status     = 'approved'
                             )
                       ) AS taught
                FROM timetable t
                JOIN subjects sub ON sub.id = t.subject_id
                JOIN classes  c   ON c.id   = t.class_id
                WHERE t.teacher_id = :tid
                  AND t.status     = 'approved'
                GROUP BY sub.id, c.id
                ORDER BY sub.name";
        $stmt = $db->prepare($sql);
        $stmt->execute([':weeks' => $weeks, ':tid' => $teacherId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $total  = (int)$r['total'];
            $taught = (int)$r['taught'];
            // Defensive cap: taught can never exceed total in a sane semester. If somehow
            // the database has stale/extra session rows (e.g. backfill ran twice), clamp it
            // so the UI never shows nonsensical numbers like 999/16 or negative remaining.
            if ($total > 0 && $taught > $total) { $taught = $total; }
            $rows[] = $r + [
                'taught'       => $taught,
                'remaining'    => max(0, $total - $taught),
                'progress_pct' => $total > 0 ? round($taught * 100 / $total, 1) : 0,
            ];
        }
        return $rows;
    }
}

if (!function_exists('studentCreditsTaughtVsRemaining')) {
    
    function studentCreditsTaughtVsRemaining(int $studentId): array {
        $db = getDB();
        // FIX V21.1 — Same JOIN-inflation bug as in teacherCreditsTaughtVsRemaining
        // (see comment above). For students we aggregate per subject across the whole
        // class timetable; the taught count is per (subject, class) and limited to
        // delivered sessions.
        $weeks = (int)currentAcademicWeeks();
        if ($weeks <= 0) { $weeks = (int)SEMESTER_WEEKS; }
        $sql = "SELECT sub.id   AS subject_id,
                       sub.name AS subject_name,
                       c.id     AS class_id,
                       SUM(t.credit_units) * :weeks AS total,
                       (
                           SELECT COUNT(DISTINCT s.id)
                           FROM attendance_sessions s
                           WHERE s.subject_id = sub.id
                             AND s.class_id   = c.id
                             AND s.status IN ('completed','closed','missed')
                             AND s.timetable_id IN (
                                 SELECT t2.id FROM timetable t2
                                 WHERE t2.subject_id = sub.id
                                   AND t2.class_id   = c.id
                                   AND t2.status     = 'approved'
                             )
                       ) AS taught
                FROM users u
                JOIN classes  c  ON c.id = u.class_id
                JOIN timetable t ON t.class_id = c.id AND t.status = 'approved'
                JOIN subjects sub ON sub.id = t.subject_id
                WHERE u.id = :sid
                GROUP BY sub.id, c.id
                ORDER BY sub.name";
        $stmt = $db->prepare($sql);
        $stmt->execute([':weeks' => $weeks, ':sid' => $studentId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $total  = (int)$r['total'];
            $taught = (int)$r['taught'];
            if ($total > 0 && $taught > $total) { $taught = $total; }
            $rows[] = $r + [
                'taught'       => $taught,
                'remaining'    => max(0, $total - $taught),
                'progress_pct' => $total > 0 ? round($taught * 100 / $total, 1) : 0,
            ];
        }
        return $rows;
    }
}

if (!function_exists('roleMenuTree')) {
    
    function roleMenuTree(int $roleId): array {
        $base = BASE_URL;
        $tree = [];

        if ($roleId === ROLE_ADMIN) {
            $tree[t('menu_main')] = [
                ['icon'=>'fa-gauge-high','label'=>t('dashboard'),'url'=>"$base/admin/dashboard.php"],
            ];

            
            $tree[t('menu_academic')] = [
                ['icon'=>'fa-building-columns','label'=>t('faculties'),'url'=>"$base/admin/faculties.php"],
                ['icon'=>'fa-building','label'=>t('departments'),'url'=>"$base/admin/departments.php"],
                ['icon'=>'fa-chalkboard','label'=>t('classes'),'url'=>"$base/admin/classes.php"],
                ['icon'=>'fa-layer-group','label'=>t('semesters'),'url'=>"$base/admin/semesters.php"],
                ['icon'=>'fa-book','label'=>t('subjects'),'url'=>"$base/admin/subjects.php"],
                ['icon'=>'fa-users','label'=>t('users'),'url'=>"$base/admin/users.php"],
                ['icon'=>'fa-calendar-alt','label'=>t('timetable'),'url'=>"$base/admin/timetable.php"],
            ];
            $tree[t('menu_attendance')] = [
                ['icon'=>'fa-clock','label'=>t('attendance'),'url'=>"$base/admin/attendance.php"],
                ['icon'=>'fa-clock-rotate-left','label'=>t('attendance_history'),'url'=>"$base/reports/attendance_history.php"],
            ];
            $tree[t('menu_reports')] = [
                ['icon'=>'fa-chart-bar','label'=>t('reports'),'url'=>"$base/reports/"],
            ];
            $tree[t('menu_management')] = [
                ['icon'=>'fa-check-circle','label'=>t('approvals'),'url'=>"$base/admin/approvals.php"],
                ['icon'=>'fa-database','label'=>t('backup') ?: 'Backup','url'=>"$base/admin/backup.php"],
                ['icon'=>'fa-bullhorn','label'=>t('announcements'),'url'=>"$base/admin/announcements.php"],
                ['icon'=>'fa-shield-halved','label'=>t('policies'),'url'=>"$base/admin/policies.php"],
                ['icon'=>'fa-bell','label'=>t('notifications'),'url'=>"$base/notifications/"],
            ];
            $tree[t('menu_account')] = [
                ['icon'=>'fa-history','label'=>t('audit_log') ?: 'Audit Log','url'=>"$base/admin/audit_log.php"],
                ['icon'=>'fa-user-circle','label'=>t('profile'),'url'=>"$base/profile/"],
            ];
            return $tree;
        }

        if ($roleId === ROLE_DEPT_HEAD) {
            $tree[t('menu_main')] = [
                ['icon'=>'fa-gauge-high','label'=>t('dashboard'),'url'=>"$base/dean/dashboard.php"],
            ];
            
            $tree[t('menu_academic')] = [
                ['icon'=>'fa-building','label'=>t('departments'),'url'=>"$base/dean/departments.php"],
                ['icon'=>'fa-chalkboard','label'=>t('classes'),'url'=>"$base/dean/classes.php"],
                ['icon'=>'fa-layer-group','label'=>t('semesters'),'url'=>"$base/dean/semesters.php"],
                ['icon'=>'fa-book','label'=>t('subjects'),'url'=>"$base/dean/subjects.php"],
                ['icon'=>'fa-users','label'=>t('users'),'url'=>"$base/dean/users.php"],
                ['icon'=>'fa-calendar-alt','label'=>t('timetable'),'url'=>"$base/dean/timetable.php"],
            ];
            $tree[t('menu_attendance')] = [
                ['icon'=>'fa-clock','label'=>t('attendance'),'url'=>"$base/dean/attendance.php"],
                ['icon'=>'fa-clock-rotate-left','label'=>t('attendance_history'),'url'=>"$base/reports/attendance_history.php"],
            ];
            $tree[t('menu_reports')] = [
                ['icon'=>'fa-chart-bar','label'=>t('reports'),'url'=>"$base/reports/"],
            ];
            $tree[t('menu_management')] = [
                ['icon'=>'fa-check-circle','label'=>t('approvals'),'url'=>"$base/dean/approvals.php"],
                ['icon'=>'fa-bullhorn','label'=>t('announcements'),'url'=>"$base/dean/announcements.php"],
                ['icon'=>'fa-bell','label'=>t('notifications'),'url'=>"$base/notifications/"],
            ];
            $tree[t('menu_account')] = [
                ['icon'=>'fa-user-circle','label'=>t('profile'),'url'=>"$base/profile/"],
            ];
            return $tree;
        }

        
        
        if ($roleId === ROLE_ATT_MGR) {
            $tree[t('menu_main')] = [
                ['icon'=>'fa-gauge-high','label'=>t('dashboard'),'url'=>"$base/manager/dashboard.php"],
            ];
            $tree[t('menu_academic')] = [
                ['icon'=>'fa-chalkboard','label'=>t('classes'),'url'=>"$base/manager/classes.php"],
                ['icon'=>'fa-book','label'=>t('subjects'),'url'=>"$base/manager/subjects.php"],
                ['icon'=>'fa-users','label'=>t('users'),'url'=>"$base/manager/users.php"],  
                ['icon'=>'fa-calendar-alt','label'=>t('timetable'),'url'=>"$base/manager/timetable.php"],
            ];
            $tree[t('menu_attendance')] = [
                ['icon'=>'fa-clock','label'=>t('attendance'),'url'=>"$base/manager/attendance.php"],
                ['icon'=>'fa-clock-rotate-left','label'=>t('attendance_history'),'url'=>"$base/reports/attendance_history.php"],
            ];
            $tree[t('menu_reports')] = [
                ['icon'=>'fa-chart-bar','label'=>t('reports'),'url'=>"$base/reports/"],
            ];
            $tree[t('menu_management')] = [
                ['icon'=>'fa-check-circle','label'=>t('approvals'),'url'=>"$base/manager/approvals.php"],
                ['icon'=>'fa-bullhorn','label'=>t('announcements'),'url'=>"$base/notifications/announcements.php"],
                ['icon'=>'fa-bell','label'=>t('notifications'),'url'=>"$base/notifications/"],
            ];
            $tree[t('menu_account')] = [
                ['icon'=>'fa-user-circle','label'=>t('profile'),'url'=>"$base/profile/"],
            ];
            return $tree;
        }

        if ($roleId === ROLE_TEACHER) {
            $tree[t('menu_main')] = [
                ['icon'=>'fa-gauge-high','label'=>t('dashboard'),'url'=>"$base/teacher/dashboard.php"],
            ];
            $tree[t('menu_academic')] = [
                ['icon'=>'fa-calendar-alt','label'=>t('timetable'),'url'=>"$base/teacher/timetable.php"],
                ['icon'=>'fa-users','label'=>t('my_students'),'url'=>"$base/teacher/students.php"],
            ];
            $tree[t('menu_attendance')] = [
                ['icon'=>'fa-list-check','label'=>t('attendance_entry'),'url'=>"$base/teacher/sessions.php"],
                ['icon'=>'fa-clock-rotate-left','label'=>t('attendance_history'),'url'=>"$base/reports/attendance_history.php"],
            ];
            $tree[t('menu_reports')] = [
                ['icon'=>'fa-chart-bar','label'=>t('reports'),'url'=>"$base/teacher/reports.php"],
            ];
            $tree[t('menu_management')] = [
                ['icon'=>'fa-bullhorn','label'=>t('announcements'),'url'=>"$base/notifications/announcements.php"],
                ['icon'=>'fa-bell','label'=>t('notifications'),'url'=>"$base/notifications/"],
            ];
            $tree[t('menu_account')] = [
                ['icon'=>'fa-user-circle','label'=>t('profile'),'url'=>"$base/profile/"],
            ];
            return $tree;
        }

        if ($roleId === ROLE_STUDENT) {
            $tree[t('menu_main')] = [
                ['icon'=>'fa-gauge-high','label'=>t('dashboard'),'url'=>"$base/student/dashboard.php"],
            ];
            $tree[t('menu_academic')] = [
                ['icon'=>'fa-calendar-alt','label'=>t('timetable'),'url'=>"$base/student/timetable.php"],
            ];
            $tree[t('menu_attendance')] = [
                ['icon'=>'fa-list-check','label'=>t('ui_my_attendance'),'url'=>"$base/student/attendance.php"],
                ['icon'=>'fa-qrcode','label'=>t('scan_attendance'),'url'=>"$base/student/scan.php"],
                ['icon'=>'fa-circle-xmark','label'=>t('missed_classes'),'url'=>"$base/missed/index.php"],
            ];
            $tree[t('menu_management')] = [
                ['icon'=>'fa-bullhorn','label'=>t('announcements'),'url'=>"$base/notifications/announcements.php"],
                ['icon'=>'fa-bell','label'=>t('notifications'),'url'=>"$base/notifications/"],
            ];
            $tree[t('menu_account')] = [
                ['icon'=>'fa-user-circle','label'=>t('profile'),'url'=>"$base/profile/"],
            ];
            return $tree;
        }

        return $tree;
    }
}

if (!function_exists('cacheDirectory')) {
    function cacheDirectory(): string {
        $dir = defined('CACHE_DIR') ? CACHE_DIR : (BASE_PATH . '/cache/');
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return rtrim($dir, '/').'/';
    }
}

if (!function_exists('cacheGet')) {
    
    function cacheGet(string $key) {
        $f = cacheDirectory() . sha1($key) . '.cache';
        if (!is_file($f)) return null;
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') return null;
        $data = @unserialize($raw);
        if (!is_array($data) || !isset($data['expires_at'], $data['payload'])) return null;
        if ($data['expires_at'] < time()) { @unlink($f); return null; }
        return $data['payload'];
    }
}

if (!function_exists('cacheSet')) {
    function cacheSet(string $key, $value, int $ttlSeconds = 300): void {
        $f = cacheDirectory() . sha1($key) . '.cache';
        @file_put_contents($f, serialize(['expires_at' => time() + max(5,$ttlSeconds), 'payload' => $value]), LOCK_EX);
    }
}

if (!function_exists('cacheRemember')) {
    
    function cacheRemember(string $key, int $ttlSeconds, callable $producer) {
        $hit = cacheGet($key);
        if ($hit !== null) return $hit;
        $val = $producer();
        cacheSet($key, $val, $ttlSeconds);
        return $val;
    }
}

if (!function_exists('cacheBust')) {
    
    function cacheBust(string $key = ''): void {
        $dir = cacheDirectory();
        if ($key !== '') {
            $f = $dir . sha1($key) . '.cache';
            if (is_file($f)) @unlink($f);
            return;
        }
        foreach (glob($dir.'*.cache') ?: [] as $f) @unlink($f);
    }
}

if (!function_exists('universityWideStats')) {
    
    function universityWideStats(): array {
        return cacheRemember('uni_wide_stats', defined('STAT_CACHE_TTL') ? STAT_CACHE_TTL : 60, function () {
            $db = getDB();
            $today = date('Y-m-d');
            $dow = (int)date('w', strtotime($today));
            $stats = [
                'faculties'   => (int)$db->query("SELECT COUNT(*) FROM faculties WHERE is_active=1")->fetchColumn(),
                'departments' => (int)$db->query("SELECT COUNT(*) FROM departments WHERE is_active=1")->fetchColumn(),
                'classes'     => (int)$db->query("SELECT COUNT(*) FROM classes WHERE is_active=1")->fetchColumn(),
                'subjects'    => (int)$db->query("SELECT COUNT(*) FROM subjects WHERE is_active=1")->fetchColumn(),
                'managers'    => (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id=".ROLE_ATT_MGR." AND status='active' AND deleted_at IS NULL")->fetchColumn(),
                'deans'       => (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id=".ROLE_DEPT_HEAD." AND status='active' AND deleted_at IS NULL")->fetchColumn(),
                'teachers'    => (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id=".ROLE_TEACHER." AND status='active' AND deleted_at IS NULL")->fetchColumn(),
                'students'    => (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id=".ROLE_STUDENT." AND status='active' AND deleted_at IS NULL")->fetchColumn(),
                
                'total_users' => (int)$db->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn(),
                'active_users'=> (int)$db->query("SELECT COUNT(*) FROM users WHERE status='active' AND deleted_at IS NULL")->fetchColumn(),
                'inactive_users'=> (int)$db->query("SELECT COUNT(*) FROM users WHERE status='inactive' AND deleted_at IS NULL")->fetchColumn(),
                'announcements_active' => (int)$db->query("SELECT COUNT(*) FROM announcements WHERE expires_at IS NULL OR expires_at > NOW()")->fetchColumn(),
            ];

            $stTcs = $db->prepare("SELECT COUNT(*) FROM timetable WHERE day_of_week=? AND status='approved'");
            $stTcs->execute([$dow]);
            $stats['today_classes_scheduled'] = (int)$stTcs->fetchColumn();
            $tStmt = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE session_date=?");
            $tStmt->execute([$today]); $stats['today_sessions'] = (int)$tStmt->fetchColumn();
            $tcStmt = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE session_date=? AND status IN ('completed','closed')");
            $tcStmt->execute([$today]); $stats['today_closed'] = (int)$tcStmt->fetchColumn();
            $tmStmt = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE session_date=? AND status='missed'");
            $tmStmt->execute([$today]); $stats['today_missed'] = (int)$tmStmt->fetchColumn();
            $toStmt = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE session_date=? AND status='open'");
            $toStmt->execute([$today]); $stats['today_open'] = (int)$toStmt->fetchColumn();

            

            $taStmt = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE session_date=? AND status='open' AND TIMESTAMP(session_date,start_time) <= NOW() AND TIMESTAMP(session_date,end_time) > NOW()");
            $taStmt->execute([$today]); $stats['today_active'] = (int)$taStmt->fetchColumn();
            $tpStmt = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE session_date=? AND status='open' AND TIMESTAMP(session_date,start_time) > NOW()");
            $tpStmt->execute([$today]); $stats['today_pending'] = (int)$tpStmt->fetchColumn();
            
            $tBusy = $db->prepare("SELECT COUNT(DISTINCT teacher_id) FROM timetable WHERE day_of_week=? AND status='approved'");
            $tBusy->execute([$dow]); $stats['today_teachers_busy'] = (int)$tBusy->fetchColumn();

            

            
            $editReqPending      = (int)$db->query("SELECT COUNT(*) FROM edit_requests WHERE status='pending'")->fetchColumn();
            $timetablePending    = (int)$db->query("SELECT COUNT(*) FROM timetable WHERE status IN ('draft','faculty_approved')")->fetchColumn();
            $stats['pending_approvals']  = $editReqPending + $timetablePending;
            $stats['pending_timetables'] = $timetablePending;
            $stats['approved_timetables']= (int)$db->query("SELECT COUNT(*) FROM timetable WHERE status='approved'")->fetchColumn();
            return $stats;
        });
    }
}

if (!function_exists('facultyWideStats')) {
    
    function facultyWideStats(int $facultyId): array {
        $key = 'faculty_wide_stats_'.$facultyId;
        return cacheRemember($key, defined('STAT_CACHE_TTL') ? STAT_CACHE_TTL : 60, function() use ($facultyId) {
            $db = getDB();
            $today = date('Y-m-d');
            $dow = (int)date('w', strtotime($today));
            $row = function($sql, $p) use ($db) {
                $st = $db->prepare($sql); $st->execute($p); return (int)$st->fetchColumn();
            };
            return [
                'departments' => $row("SELECT COUNT(*) FROM departments WHERE faculty_id=? AND is_active=1", [$facultyId]),
                'classes'     => $row("SELECT COUNT(*) FROM classes WHERE faculty_id=? AND is_active=1", [$facultyId]),
                'subjects'    => $row("SELECT COUNT(*) FROM subjects WHERE faculty_id=? AND is_active=1", [$facultyId]),
                'managers'    => $row("SELECT COUNT(*) FROM users WHERE role_id=".ROLE_ATT_MGR." AND faculty_id=? AND status='active' AND deleted_at IS NULL", [$facultyId]),
                'teachers'    => $row("SELECT COUNT(*) FROM users WHERE role_id=".ROLE_TEACHER." AND faculty_id=? AND status='active' AND deleted_at IS NULL", [$facultyId]),
                'students'    => $row("SELECT COUNT(*) FROM users WHERE role_id=".ROLE_STUDENT." AND faculty_id=? AND status='active' AND deleted_at IS NULL", [$facultyId]),
                
                'total_users' => $row("SELECT COUNT(*) FROM users WHERE faculty_id=? AND deleted_at IS NULL", [$facultyId]),
                'active_users'=> $row("SELECT COUNT(*) FROM users WHERE faculty_id=? AND status='active' AND deleted_at IS NULL", [$facultyId]),
                'inactive_users'=> $row("SELECT COUNT(*) FROM users WHERE faculty_id=? AND status='inactive' AND deleted_at IS NULL", [$facultyId]),

                
                'announcements_active'=> $row("SELECT COUNT(*) FROM announcements a WHERE (a.expires_at IS NULL OR a.expires_at > NOW()) AND (a.faculty_id IS NULL OR a.faculty_id = ?) AND a.audience IN ('all','deans','managers','teachers','students')", [$facultyId]),
                'today_classes_scheduled' => $row("SELECT COUNT(*) FROM timetable WHERE faculty_id=? AND day_of_week=? AND status='approved'", [$facultyId,$dow]),
                'today_sessions' => $row("SELECT COUNT(*) FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id WHERE t.faculty_id=? AND s.session_date=?", [$facultyId,$today]),
                'today_closed'   => $row("SELECT COUNT(*) FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id WHERE t.faculty_id=? AND s.session_date=? AND s.status IN ('completed','closed')", [$facultyId,$today]),
                'today_missed'   => $row("SELECT COUNT(*) FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id WHERE t.faculty_id=? AND s.session_date=? AND s.status='missed'", [$facultyId,$today]),
                'today_open'     => $row("SELECT COUNT(*) FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id WHERE t.faculty_id=? AND s.session_date=? AND s.status='open'", [$facultyId,$today]),
                
                'today_active'   => $row("SELECT COUNT(*) FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id WHERE t.faculty_id=? AND s.session_date=? AND s.status='open' AND TIMESTAMP(s.session_date,s.start_time) <= NOW() AND TIMESTAMP(s.session_date,s.end_time) > NOW()", [$facultyId,$today]),
                'today_pending'  => $row("SELECT COUNT(*) FROM attendance_sessions s JOIN timetable t ON t.id=s.timetable_id WHERE t.faculty_id=? AND s.session_date=? AND s.status='open' AND TIMESTAMP(s.session_date,s.start_time) > NOW()", [$facultyId,$today]),
                'today_teachers_busy' => $row("SELECT COUNT(DISTINCT teacher_id) FROM timetable WHERE faculty_id=? AND day_of_week=? AND status='approved'", [$facultyId,$dow]),
                'pending_approvals'  => $row("SELECT COUNT(*) FROM edit_requests er JOIN users u ON u.id=er.requested_by WHERE u.faculty_id=? AND er.status='pending'", [$facultyId]),
                'pending_timetables' => $row("SELECT COUNT(*) FROM timetable WHERE faculty_id=? AND status IN ('draft','faculty_approved')", [$facultyId]),
                'approved_timetables'=> $row("SELECT COUNT(*) FROM timetable WHERE faculty_id=? AND status='approved'", [$facultyId]),
            ];
        });
    }
}

if (!function_exists('todayScheduledClasses')) {
    
    function todayScheduledClasses(?int $facultyId = null): array {
        $db = getDB();
        $today = date('Y-m-d');
        $dow = (int)date('w', strtotime($today));
        $sql = "SELECT t.*, c.name AS class_name, c.section, sub.name AS subject_name,
                       u.full_name AS teacher_name, u.id AS teacher_id, COALESCE(r.name,t.room) AS room_name,
                       d.name AS department_name, f.name AS faculty_name
                FROM timetable t
                JOIN classes c   ON c.id=t.class_id
                JOIN subjects sub ON sub.id=t.subject_id
                JOIN users u     ON u.id=t.teacher_id
                LEFT JOIN rooms r ON r.id=t.room_id
                LEFT JOIN departments d ON d.id=t.department_id
                LEFT JOIN faculties f ON f.id=t.faculty_id
                WHERE t.day_of_week=? AND t.status='approved' ";
        $params = [$dow];
        if ($facultyId) { $sql .= " AND t.faculty_id=? "; $params[] = $facultyId; }
        $sql .= " ORDER BY t.start_time, t.class_id";
        $st = $db->prepare($sql); $st->execute($params);
        return $st->fetchAll();
    }
}

if (!function_exists('chartSeries')) {
    
    function chartSeries(string $period = 'day', array $scope = []): array {
        $period = in_array($period, ['day','week','month','semester'], true) ? $period : 'day';
        $cacheVersion = 'v2';
        $key = 'chart_'.$cacheVersion.'_'.$period.'_'.md5(json_encode([
            'scope' => $scope,
            'semester_start' => function_exists('getSemesterStartDate') ? getSemesterStartDate() : '',
            'semester_end' => function_exists('getSemesterEndDate') ? getSemesterEndDate() : '',
            'weeks' => currentAcademicWeeks(),
        ]));

        return cacheRemember($key, defined('CHART_CACHE_TTL') ? CHART_CACHE_TTL : 300, function() use ($period, $scope) {
            $db = getDB();
            $today = new DateTimeImmutable('today');
            $semesterStartRaw = function_exists('getSemesterStartDate') ? trim(getSemesterStartDate()) : '';
            $semesterEndRaw   = function_exists('getSemesterEndDate') ? trim(getSemesterEndDate()) : '';
            $semesterWeeks    = max(1, (int)currentAcademicWeeks());

            try {
                $semesterStart = $semesterStartRaw !== '' ? new DateTimeImmutable($semesterStartRaw) : $today->modify('-'.($semesterWeeks - 1).' weeks')->modify('monday this week');
            } catch (Throwable $e) {
                $semesterStart = $today->modify('-'.($semesterWeeks - 1).' weeks')->modify('monday this week');
            }
            try {
                $semesterEnd = $semesterEndRaw !== '' ? new DateTimeImmutable($semesterEndRaw) : $today;
            } catch (Throwable $e) {
                $semesterEnd = $today;
            }
            if ($semesterEnd > $today) $semesterEnd = $today;
            if ($semesterStart > $semesterEnd) $semesterStart = $semesterEnd;

            $buckets = [];
            if ($period === 'day') {
                $start = $today->modify('-13 days');
                if ($start < $semesterStart) $start = $semesterStart;
                for ($cursor = $start; $cursor <= $today; $cursor = $cursor->modify('+1 day')) {
                    $key = $cursor->format('Y-m-d');
                    $buckets[$key] = [
                        'label' => $cursor->format('d M'),
                        'from'  => $key,
                        'to'    => $key,
                        'present' => 0,
                        'absent'  => 0,
                    ];
                }
            } elseif ($period === 'week') {
                $start = $today->modify('-7 weeks')->modify('monday this week');
                if ($start < $semesterStart) $start = $semesterStart;
                for ($cursor = $start; $cursor <= $today; $cursor = $cursor->modify('+7 days')) {
                    $from = $cursor->format('Y-m-d');
                    $toDt = $cursor->modify('+6 days');
                    if ($toDt > $today) $toDt = $today;
                    $buckets[$from] = [
                        'label' => $cursor->format('d M'),
                        'from'  => $from,
                        'to'    => $toDt->format('Y-m-d'),
                        'present' => 0,
                        'absent'  => 0,
                    ];
                }
            } elseif ($period === 'month') {
                $start = $today->modify('first day of this month')->modify('-5 months');
                $semesterMonthStart = $semesterStart->modify('first day of this month');
                if ($start < $semesterMonthStart) $start = $semesterMonthStart;
                for ($cursor = $start; $cursor <= $today; $cursor = $cursor->modify('first day of next month')) {
                    $fromDt = $cursor;
                    $toDt = $cursor->modify('last day of this month');
                    if ($fromDt < $semesterStart) $fromDt = $semesterStart;
                    if ($toDt > $today) $toDt = $today;
                    $buckets[$fromDt->format('Y-m-d')] = [
                        'label' => $cursor->format('M Y'),
                        'from'  => $fromDt->format('Y-m-d'),
                        'to'    => $toDt->format('Y-m-d'),
                        'present' => 0,
                        'absent'  => 0,
                    ];
                }
            } else {
                $weekNo = 1;
                for ($cursor = $semesterStart; $cursor <= $semesterEnd; $cursor = $cursor->modify('+7 days')) {
                    $toDt = $cursor->modify('+6 days');
                    if ($toDt > $semesterEnd) $toDt = $semesterEnd;
                    $buckets[$cursor->format('Y-m-d')] = [
                        'label' => 'W'.$weekNo,
                        'from'  => $cursor->format('Y-m-d'),
                        'to'    => $toDt->format('Y-m-d'),
                        'present' => 0,
                        'absent'  => 0,
                    ];
                    $weekNo++;
                }
            }

            if (!$buckets) {
                $fallback = $today->format('Y-m-d');
                $buckets[$fallback] = [
                    'label' => $today->format('d M'),
                    'from' => $fallback,
                    'to' => $fallback,
                    'present' => 0,
                    'absent' => 0,
                ];
            }

            $first = reset($buckets);
            $last = end($buckets);
            $where = ["s.session_date BETWEEN ? AND ?"];
            $params = [$first['from'], $last['to']];
            if (!empty($scope['student_id'])) { $where[] = "ar.student_id=?"; $params[] = (int)$scope['student_id']; }
            if (!empty($scope['teacher_id'])) { $where[] = "s.teacher_id=?";  $params[] = (int)$scope['teacher_id']; }
            if (!empty($scope['class_id']))   { $where[] = "s.class_id=?";    $params[] = (int)$scope['class_id']; }
            if (!empty($scope['subject_id'])) { $where[] = "s.subject_id=?";  $params[] = (int)$scope['subject_id']; }
            $joinFaculty = '';
            if (!empty($scope['faculty_id'])) {
                $joinFaculty = " JOIN timetable t ON t.id=s.timetable_id ";
                $where[] = "t.faculty_id=?";
                $params[] = (int)$scope['faculty_id'];
            }

            $sql = "SELECT s.session_date,
                           SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS present_count,
                           SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END) AS absent_count,
                           COUNT(ar.id) AS total_count
                    FROM attendance_records ar
                    JOIN attendance_sessions s ON s.id=ar.session_id
                    {$joinFaculty}
                    WHERE ".implode(' AND ', $where)."
                    GROUP BY s.session_date
                    ORDER BY s.session_date";
            try {
                $st = $db->prepare($sql);
                $st->execute($params);
                $rows = $st->fetchAll();
            } catch (Throwable $e) {
                $rows = [];
            }

            foreach ($rows as $row) {
                $date = (string)$row['session_date'];
                foreach ($buckets as &$bucket) {
                    if ($date >= $bucket['from'] && $date <= $bucket['to']) {
                        $bucket['present'] += (int)($row['present_count'] ?? 0);
                        $bucket['absent']  += (int)($row['absent_count'] ?? 0);
                        break;
                    }
                }
                unset($bucket);
            }

            $labels = [];
            $presentCounts = [];
            $absentCounts = [];
            $attendancePct = [];
            $absencePct = [];
            $totalCounts = [];
            foreach ($buckets as $bucket) {
                $total = (int)$bucket['present'] + (int)$bucket['absent'];
                $labels[] = $bucket['label'];
                $presentCounts[] = (int)$bucket['present'];
                $absentCounts[] = (int)$bucket['absent'];
                $totalCounts[] = $total;
                $attendancePct[] = $total > 0 ? round(((int)$bucket['present'] / $total) * 100, 1) : 0.0;
                $absencePct[] = $total > 0 ? round(((int)$bucket['absent'] / $total) * 100, 1) : 0.0;
            }

            return [
                'labels' => $labels,
                'present_counts' => $presentCounts,
                'absent_counts' => $absentCounts,
                'attendance' => $attendancePct,
                'absence' => $absencePct,
                'totals' => $totalCounts,
                'period' => $period,
                'range' => [
                    'from' => $first['from'],
                    'to' => $last['to'],
                ],
            ];
        });
    }
}

if (!function_exists('listFacultiesActive')) {
    function listFacultiesActive(): array {
        $db = getDB();
        return cacheRemember('faculties_active', 120, function() use ($db) {
            return $db->query("SELECT id,name,code FROM faculties WHERE is_active=1 ORDER BY name")->fetchAll();
        });
    }
}
if (!function_exists('listDepartmentsByFaculty')) {
    function listDepartmentsByFaculty(int $facultyId): array {
        if ($facultyId <= 0) return [];
        $db = getDB();
        $st = $db->prepare("SELECT id,name,code FROM departments WHERE faculty_id=? AND is_active=1 ORDER BY name");
        $st->execute([$facultyId]); return $st->fetchAll();
    }
}
if (!function_exists('normalizeSemesterNoToClassStart')) {
    
    function normalizeSemesterNoToClassStart(int $semesterNo): int {
        if ($semesterNo <= 1) return 1;
        return ($semesterNo % 2 === 0) ? ($semesterNo - 1) : $semesterNo;
    }
}
if (!function_exists('normalizeSemesterIdToClassStart')) {
    
    function normalizeSemesterIdToClassStart(?int $semesterId, ?int $facultyId = null): ?int {
        $semesterId = (int)$semesterId;
        if ($semesterId <= 0) return null;
        $db = getDB();
        $st = $db->prepare("SELECT faculty_id, semester_no FROM semesters WHERE id=? LIMIT 1");
        $st->execute([$semesterId]);
        $row = $st->fetch();
        if (!$row) return null;
        $facultyId = $facultyId ?: (int)$row['faculty_id'];
        $startNo   = normalizeSemesterNoToClassStart((int)$row['semester_no']);
        $st2 = $db->prepare("SELECT id FROM semesters WHERE faculty_id=? AND semester_no=? AND is_active=1 LIMIT 1");
        $st2->execute([$facultyId, $startNo]);
        $val = (int)($st2->fetchColumn() ?: 0);
        return $val > 0 ? $val : null;
    }
}
if (!function_exists('listClassStartSemestersByFaculty')) {
    
    function listClassStartSemestersByFaculty(int $facultyId): array {
        if ($facultyId <= 0) return [];
        $db = getDB();
        $st = $db->prepare("SELECT id,name,semester_no,year_no FROM semesters
                            WHERE faculty_id=? AND is_active=1 AND MOD(semester_no,2)=1
                            ORDER BY semester_no");
        $st->execute([$facultyId]);
        return $st->fetchAll();
    }
}
if (!function_exists('listSemestersByClass')) {
    
    function listSemestersByClass(int $classId): array {
        if ($classId <= 0) return [];
        $db = getDB();
        $st = $db->prepare("SELECT faculty_id, semester_id FROM classes WHERE id=? AND is_active=1 LIMIT 1");
        $st->execute([$classId]);
        $classRow = $st->fetch();
        if (!$classRow) return [];
        $baseSemesterId = normalizeSemesterIdToClassStart((int)($classRow['semester_id'] ?? 0), (int)$classRow['faculty_id']);
        if (!$baseSemesterId) return [];
        $metaStmt = $db->prepare("SELECT faculty_id, semester_no FROM semesters WHERE id=? LIMIT 1");
        $metaStmt->execute([$baseSemesterId]);
        $meta = $metaStmt->fetch();
        if (!$meta) return [];
        $pairStmt = $db->prepare("SELECT id,name,semester_no,year_no FROM semesters
                                  WHERE faculty_id=? AND is_active=1 AND semester_no IN (?,?)
                                  ORDER BY semester_no");
        $pairStmt->execute([(int)$meta['faculty_id'], (int)$meta['semester_no'], (int)$meta['semester_no'] + 1]);
        return $pairStmt->fetchAll();
    }
}
if (!function_exists('classAllowedSemesterIds')) {
    function classAllowedSemesterIds(int $classId): array {
        return array_map(fn($r) => (int)$r['id'], listSemestersByClass($classId));
    }
}
if (!function_exists('resolveSemesterForClass')) {
    
    function resolveSemesterForClass(int $classId, ?int $semesterId = null): ?int {
        $allowed = classAllowedSemesterIds($classId);
        if (!$allowed) return null;
        $semesterId = (int)$semesterId;
        if ($semesterId > 0 && in_array($semesterId, $allowed, true)) return $semesterId;
        return (int)$allowed[0];
    }
}
if (!function_exists('classSemesterPairLabel')) {
    
    function classSemesterPairLabel(?int $classId = null, ?int $baseSemesterId = null, ?int $facultyId = null): string {
        $rows = [];
        if ((int)$classId > 0) {
            $rows = listSemestersByClass((int)$classId);
        } elseif ((int)$baseSemesterId > 0) {
            $normalized = normalizeSemesterIdToClassStart((int)$baseSemesterId, $facultyId);
            if ($normalized) {
                $db = getDB();
                $st = $db->prepare("SELECT faculty_id, semester_no FROM semesters WHERE id=? LIMIT 1");
                $st->execute([$normalized]);
                $meta = $st->fetch();
                if ($meta) {
                    $pairStmt = $db->prepare("SELECT name FROM semesters
                                              WHERE faculty_id=? AND is_active=1 AND semester_no IN (?,?)
                                              ORDER BY semester_no");
                    $pairStmt->execute([(int)$meta['faculty_id'], (int)$meta['semester_no'], (int)$meta['semester_no'] + 1]);
                    $rows = $pairStmt->fetchAll();
                }
            }
        }
        if (!$rows) return '-';
        return implode(' / ', array_map(fn($r) => (string)$r['name'], $rows));
    }
}
if (!function_exists('inferClassIdForDepartmentSemester')) {
    function inferClassIdForDepartmentSemester(?int $departmentId, ?int $semesterId, ?int $facultyId = null): ?int {
        $departmentId = (int)$departmentId;
        $semesterId   = (int)$semesterId;
        if ($departmentId <= 0 || $semesterId <= 0) return null;
        $normalized = normalizeSemesterIdToClassStart($semesterId, $facultyId);
        if (!$normalized) return null;
        $db = getDB();
        $sql = "SELECT id FROM classes WHERE department_id=? AND semester_id=? AND is_active=1";
        $params = [$departmentId, $normalized];
        if ((int)$facultyId > 0) { $sql .= " AND faculty_id=?"; $params[] = (int)$facultyId; }
        $sql .= " ORDER BY id LIMIT 1";
        $st = $db->prepare($sql);
        $st->execute($params);
        $val = (int)($st->fetchColumn() ?: 0);
        return $val > 0 ? $val : null;
    }
}
if (!function_exists('listClassesByDepartment')) {
    function listClassesByDepartment(int $departmentId, ?int $facultyId = null): array {
        $db = getDB();
        if ($departmentId > 0) {
            $st = $db->prepare("SELECT id,name,section,semester_id,faculty_id,department_id FROM classes
                                WHERE (department_id=? OR (department_id IS NULL AND faculty_id=?))
                                  AND is_active=1 ORDER BY name");
            $st->execute([$departmentId, $facultyId ?? 0]);
        } elseif ($facultyId) {
            $st = $db->prepare("SELECT id,name,section,semester_id,faculty_id,department_id FROM classes WHERE faculty_id=? AND is_active=1 ORDER BY name");
            $st->execute([$facultyId]);
        } else { return []; }
        return $st->fetchAll();
    }
}
if (!function_exists('listSubjectsByDepartment')) {
    function listSubjectsByDepartment(int $departmentId, ?int $facultyId = null): array {
        if ($departmentId <= 0 && !$facultyId) return [];
        $db = getDB();
        $sql = "SELECT id,name,code,default_weekly_credits FROM subjects WHERE is_active=1";
        $params = [];
        if ($facultyId)       { $sql .= " AND faculty_id=?";                                $params[] = $facultyId; }
        if ($departmentId > 0){ $sql .= " AND (department_id=? OR department_id IS NULL)";  $params[] = $departmentId; }
        $sql .= " ORDER BY name";
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}
if (!function_exists('listSubjectsByClass')) {
    function listSubjectsByClass(int $classId): array {
        if ($classId <= 0) return [];
        $db = getDB();
        $classStmt = $db->prepare("SELECT faculty_id, department_id FROM classes WHERE id=? AND is_active=1 LIMIT 1");
        $classStmt->execute([$classId]);
        $classRow = $classStmt->fetch();
        if (!$classRow) return [];
        $allowedSemesterIds = classAllowedSemesterIds($classId);
        
        $sql = "SELECT DISTINCT s.id, s.name, s.code, s.default_weekly_credits, s.semester_id
                FROM subjects s
                WHERE s.is_active=1 AND s.faculty_id=?";
        $params = [(int)$classRow['faculty_id']];
        if ((int)($classRow['department_id'] ?? 0) > 0) {
            $sql .= " AND (s.department_id=? OR s.department_id IS NULL)";
            $params[] = (int)$classRow['department_id'];
        }
        if ($allowedSemesterIds) {
            $placeholders = implode(',', array_fill(0, count($allowedSemesterIds), '?'));
            $sql .= " AND (s.semester_id IS NULL OR s.semester_id IN ($placeholders))";
            foreach ($allowedSemesterIds as $sid) $params[] = (int)$sid;
        }
        $sql .= " ORDER BY s.name";
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        if ($rows) return $rows;
        
        $fallback = $db->prepare("SELECT DISTINCT sub.id, sub.name, sub.code, sub.default_weekly_credits, sub.semester_id
                                  FROM subjects sub
                                  JOIN timetable t ON t.subject_id=sub.id
                                  WHERE t.class_id=? AND t.status IN ('approved','faculty_approved','draft')
                                    AND sub.is_active=1
                                  ORDER BY sub.name");
        $fallback->execute([$classId]);
        return $fallback->fetchAll();
    }
}

if (!function_exists('listSubjectsByClassSemester')) {
    function listSubjectsByClassSemester(int $classId, int $semesterId): array {
        if ($semesterId <= 0) {
            return $classId > 0 ? listSubjectsByClass($classId) : [];
        }
        $db = getDB();
        $classRow = null;
        if ($classId > 0) {
            $st = $db->prepare("SELECT faculty_id, department_id FROM classes WHERE id=? AND is_active=1 LIMIT 1");
            $st->execute([$classId]);
            $classRow = $st->fetch() ?: null;
        }
        
        $sql = "SELECT DISTINCT s.id, s.name, s.code, s.default_weekly_credits, s.semester_id
                FROM subjects s
                WHERE s.is_active=1 AND s.semester_id=?";
        $params = [$semesterId];
        if ($classRow) {
            $sql .= " AND s.faculty_id=?";
            $params[] = (int)$classRow['faculty_id'];
            if ((int)($classRow['department_id'] ?? 0) > 0) {
                $sql .= " AND (s.department_id=? OR s.department_id IS NULL)";
                $params[] = (int)$classRow['department_id'];
            }
        }
        $sql .= " ORDER BY s.name";
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        if ($rows) return $rows;
        
        if ($classId > 0) {
            $fallback = $db->prepare("SELECT DISTINCT sub.id, sub.name, sub.code, sub.default_weekly_credits, sub.semester_id
                                      FROM subjects sub
                                      JOIN timetable t ON t.subject_id=sub.id
                                      WHERE t.class_id=? AND t.semester_id=?
                                        AND t.status IN ('approved','faculty_approved','draft')
                                        AND sub.is_active=1
                                      ORDER BY sub.name");
            $fallback->execute([$classId, $semesterId]);
            return $fallback->fetchAll();
        }
        return [];
    }
}
if (!function_exists('listTeachersBySubject')) {
    function listTeachersBySubject(int $subjectId): array {
        if ($subjectId <= 0) return [];
        $db = getDB();
        $st = $db->prepare("SELECT u.id, u.full_name, u.username
                            FROM teacher_subject_assignments a
                            JOIN users u ON u.id=a.teacher_id
                            WHERE a.subject_id=? AND a.is_active=1 AND u.status='active'
                            ORDER BY u.full_name");
        $st->execute([$subjectId]); return $st->fetchAll();
    }
}
if (!function_exists('listSemestersByFaculty')) {
    function listSemestersByFaculty(int $facultyId): array {
        if ($facultyId <= 0) return [];
        $db = getDB();
        $st = $db->prepare("SELECT id,name,semester_no,year_no FROM semesters WHERE faculty_id=? AND is_active=1 ORDER BY semester_no");
        $st->execute([$facultyId]); return $st->fetchAll();
    }
}

if (!function_exists('teacherStudentsAttendance')) {
    
    function teacherStudentsAttendance(int $teacherId, ?int $subjectId = null): array {
        $db = getDB();
        $params = [$teacherId];
        $sjFilter = '';
        if ($subjectId) { $sjFilter = " AND tsa.subject_id=? "; $params[] = $subjectId; }

        
        
        $sql = "SELECT u.id AS student_id, u.full_name, u.student_id AS roll, c.name AS class_name, c.section,
                       c.id AS class_id,
                       sub.id AS subject_id, sub.name AS subject_name, sub.code AS subject_code,
                       SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS present_cnt,
                       SUM(CASE WHEN ar.status='absent'  THEN 1 ELSE 0 END) AS absent_cnt,
                       0 AS late_cnt,
                       0 AS excused_cnt,
                       COUNT(ar.id) AS total_cnt,
                       COALESCE((SELECT SUM(tt2.credit_units) FROM timetable tt2
                                 WHERE tt2.class_id=c.id AND tt2.subject_id=sub.id
                                   AND tt2.status='approved'),0) AS weekly_credits
                FROM teacher_subject_assignments tsa
                JOIN subjects sub          ON sub.id=tsa.subject_id
                JOIN timetable t           ON t.subject_id=sub.id AND t.teacher_id=tsa.teacher_id
                JOIN classes c             ON c.id=t.class_id
                JOIN users u               ON u.class_id=c.id AND u.role_id=".ROLE_STUDENT." AND u.deleted_at IS NULL
                LEFT JOIN attendance_sessions s ON s.timetable_id=t.id
                LEFT JOIN attendance_records ar ON ar.session_id=s.id AND ar.student_id=u.id
                WHERE tsa.teacher_id=? AND tsa.is_active=1 {$sjFilter}
                GROUP BY u.id, sub.id, c.id
                ORDER BY sub.name, u.full_name";
        try {
            $st = $db->prepare($sql); $st->execute($params); $rows = $st->fetchAll();
        } catch (Throwable $e) { $rows = []; }
        $semesterWeeks = (int)currentAcademicWeeks();
        foreach ($rows as &$r) {
            $weekly = (int)($r['weekly_credits'] ?? 0);
            $semCredits = max(0, $weekly) * max(1, $semesterWeeks); 
            $absent = (int)$r['absent_cnt'];
            $present = (int)$r['present_cnt'];
            $r['semester_credits'] = $semCredits;
            
            $r['absence_pct'] = function_exists('absencePctClassic')
                ? absencePctClassic($absent, $weekly, $semesterWeeks)
                : ($semCredits > 0 ? round(($absent / $semCredits) * 100, 1) : 0);
            
            $r['attendance_pct'] = $semCredits > 0 ? round(($present / $semCredits) * 100, 1) : 0;
        } unset($r);
        return $rows;
    }
}

if (!function_exists('saveStructuredAttendanceForManager')) {
    function saveStructuredAttendanceForManager(int $timetableId, string $sessionDate, array $attendanceMap, int $actorId): array {
        
        return saveGroupedAttendance($timetableId, $sessionDate, $attendanceMap, $actorId, 'manager');
    }
}

if (!function_exists('clusterDrillDownLink')) {
    
    function clusterDrillDownLink(int $roleId, string $cluster, array $params = []): string {
        $base = BASE_URL;
        $qs = http_build_query($params);
        $qsSep = $qs !== '' ? '?'.$qs : '';
        $facultySection = $roleId === ROLE_DEPT_HEAD ? 'dean' : 'manager';
        switch ($cluster) {
            case 'students':
                return ($roleId===ROLE_ADMIN ? $base.'/admin/users.php?role='.ROLE_STUDENT : $base.'/'.$facultySection.'/users.php?role='.ROLE_STUDENT).($qs?'&'.$qs:'');
            case 'teachers':
                return ($roleId===ROLE_ADMIN ? $base.'/admin/users.php?role='.ROLE_TEACHER : $base.'/'.$facultySection.'/users.php?role='.ROLE_TEACHER).($qs?'&'.$qs:'');
            case 'managers':
                return ($roleId===ROLE_ADMIN ? $base.'/admin/users.php?role='.ROLE_ATT_MGR : $base.'/'.$facultySection.'/users.php?role='.ROLE_ATT_MGR).($qs?'&'.$qs:'');
            case 'departments': return ($roleId===ROLE_DEPT_HEAD ? $base.'/dean/departments.php' : $base.'/admin/departments.php').$qsSep;
            case 'faculties': return $base.'/admin/faculties.php'.$qsSep;
            case 'classes': return ($roleId===ROLE_DEPT_HEAD ? $base.'/dean/classes.php' : $base.'/admin/classes.php').$qsSep;
            case 'subjects': return ($roleId===ROLE_DEPT_HEAD ? $base.'/dean/subjects.php' : $base.'/admin/subjects.php').$qsSep;
            case 'rooms': return $base.'/admin/settings.php'.$qsSep;
            case 'today_schedule': return ($roleId===ROLE_ADMIN ? $base.'/admin/timetable.php' : $base.'/'.$facultySection.'/timetable.php').'?day='.(int)date('w').($qs?'&'.$qs:'');
            case 'today_sessions': return ($roleId===ROLE_ADMIN ? $base.'/admin/attendance.php' : $base.'/'.$facultySection.'/attendance.php').'?date='.date('Y-m-d').($qs?'&'.$qs:'');
            case 'approvals': return ($roleId===ROLE_ADMIN ? $base.'/admin/approvals.php' : $base.'/'.$facultySection.'/approvals.php').'?status=pending'.($qs?'&'.$qs:'');
            case 'missed': return $base.'/missed/index.php'.$qsSep;
            default: return $base.'/index.php';
        }
    }
}

if (!function_exists('renderStatCard')) {
    
    function renderStatCard(array $opt): string {
        $href  = $opt['href'] ?? '#';
        $tone  = $opt['tone'] ?? 'primary';
        $icon  = $opt['icon'] ?? 'fa-circle-info';
        $label = $opt['label'] ?? '';
        $value = $opt['value'] ?? 0;
        $hint  = $opt['hint'] ?? '';
        $clickable = $opt['clickable'] ?? true;
        $arrow = $clickable ? '<i class="fas fa-arrow-right drilldown-arrow"></i>' : '';
        $card =
          '<div class="v7-stat-card v6-stat-card '.h($tone).' card h-100"><div class="card-body">'.
          '<div class="stat-icon"><i class="fas '.h($icon).'"></i></div>'.
          '<div class="stat-label">'.h($label).'</div>'.
          '<div class="stat-value">'.h((string)$value).'</div>'.
          ($hint?'<div class="stat-hint">'.h($hint).'</div>':'').
          $arrow.
          '</div></div>';
        if ($clickable && $href !== '#') {
            return '<a href="'.h($href).'" class="text-decoration-none">'.$card.'</a>';
        }
        return $card;
    }
}

if (!function_exists('cacheBustDashboards')) {
    function cacheBustDashboards(): void {
        cacheBust('uni_wide_stats');
        
        try {
            $faculties = getDB()->query("SELECT id FROM faculties")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($faculties as $fid) cacheBust('faculty_wide_stats_'.(int)$fid);
        } catch (Throwable $e) {}
        
        $dir = cacheDirectory();
        foreach (glob($dir.'*.cache') ?: [] as $f) {

            @unlink($f);
        }
    }
}

if (file_exists(__DIR__.'/action_helper.php')) {
    require_once __DIR__.'/action_helper.php';
}

if (file_exists(__DIR__.'/live_attendance_helper.php')) {
    require_once __DIR__.'/live_attendance_helper.php';
}

if (!function_exists('shiftForTime')) {
    
    function shiftForTime(string $time): string {
        $t = strtotime("1970-01-01 {$time}");
        $morningStart   = strtotime("1970-01-01 08:00:00");
        $morningEnd     = strtotime("1970-01-01 12:00:00");
        $afternoonStart = strtotime("1970-01-01 13:00:00");
        $afternoonEnd   = strtotime("1970-01-01 17:00:00");
        if ($t >= $morningStart   && $t <= $morningEnd)   return 'morning';
        if ($t >= $afternoonStart && $t <= $afternoonEnd) return 'afternoon';
        return 'outside';
    }
}

if (!function_exists('shiftLabel')) {
    
    function shiftLabel(string $shift): string {
        return match ($shift) {
            'morning'   => '🌅 سهار (08:00 – 12:00)',
            'afternoon' => '🌇 ماښام (13:00 – 17:00)',
            default     => '— له شفټ څخه بهر',
        };
    }
}

if (!function_exists('preciseAbsencePct')) {
    
    function preciseAbsencePct(int $studentId, ?int $subjectId = null): array {
        $db = getDB();

        $cs = $db->prepare("SELECT class_id FROM users WHERE id=? LIMIT 1");
        $cs->execute([$studentId]);
        $classId = (int)$cs->fetchColumn();
        if (!$classId) {
            return ['absent_credits'=>0,'present_credits'=>0,'total_credits'=>0,'absence_pct'=>0.0];
        }

        $weeks = (int)currentAcademicWeeks();

        if ($subjectId) {
            $w = $db->prepare("SELECT COALESCE(SUM(credit_units),0) FROM timetable
                                WHERE class_id=? AND subject_id=? AND status='approved'");
            $w->execute([$classId, $subjectId]);
            $weekly = (int)$w->fetchColumn();
            $totalCredits = $weekly * $weeks;
        } else {
            $w = $db->prepare("SELECT COALESCE(SUM(credit_units),0) FROM timetable
                                WHERE class_id=? AND status='approved'");
            $w->execute([$classId]);
            $weekly = (int)$w->fetchColumn();
            $totalCredits = $weekly * $weeks;
        }

        $where = ["ar.student_id=?"]; $params = [$studentId];
        if ($subjectId) { $where[] = "s.subject_id=?"; $params[] = $subjectId; }
        $sql = "SELECT
                    SUM(CASE WHEN ar.status='absent'  THEN 1 ELSE 0 END) AS absent_credits,
                    SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS present_credits,
                    COUNT(ar.id) AS marked_total
                FROM attendance_records ar
                JOIN attendance_sessions s ON s.id=ar.session_id
                WHERE ".implode(' AND ',$where);
        try {
            $st = $db->prepare($sql); $st->execute($params);
            $r = $st->fetch() ?: ['absent_credits'=>0,'present_credits'=>0,'marked_total'=>0];
        } catch (Throwable $e) {
            $r = ['absent_credits'=>0,'present_credits'=>0,'marked_total'=>0];
        }

        $absent = (int)$r['absent_credits'];
        return [
            'absent_credits'  => $absent,
            'present_credits' => (int)$r['present_credits'],
            'total_credits'   => $totalCredits,        
            'marked_total'    => (int)$r['marked_total'],
            'weekly_credits'  => $weekly,
            'absence_pct'     => $totalCredits > 0 ? round(($absent / $totalCredits) * 100, 2) : 0.0,
        ];
    }
}

if (!function_exists('historyGroupedByStudent')) {
    
    function historyGroupedByStudent(int $studentId, ?int $subjectId = null): array {
        $db = getDB();

        $stuStmt = $db->prepare("SELECT id, full_name, student_id AS roll, class_id FROM users WHERE id=? LIMIT 1");
        $stuStmt->execute([$studentId]);
        $student = $stuStmt->fetch();
        if (!$student) return [];

        $params = [$studentId];
        $subFilter = '';
        if ($subjectId) { $subFilter = " AND s.subject_id=? "; $params[] = $subjectId; }

        $sql = "SELECT s.subject_id, sub.name AS subject_name, sub.code AS subject_code,
                       s.session_date, s.start_time, s.end_time, s.slot_label,
                       ar.status, ar.marked_at, ar.mark_method, s.id AS session_id
                FROM attendance_records ar
                JOIN attendance_sessions s ON s.id=ar.session_id
                JOIN subjects sub ON sub.id=s.subject_id
                WHERE ar.student_id=? {$subFilter}
                ORDER BY sub.name, s.session_date DESC, s.start_time DESC";
        $st = $db->prepare($sql); $st->execute($params); $rows = $st->fetchAll();

        $studentClassId = (int)$student['class_id'];
        $bySubject = [];
        foreach ($rows as $r) {
            $sid = (int)$r['subject_id'];
            if (!isset($bySubject[$sid])) {
                $bySubject[$sid] = [
                    'subject_id'   => $sid,
                    'subject_name' => $r['subject_name'],
                    'subject_code' => $r['subject_code'],
                    'records'      => [],
                    'present_cnt'  => 0,
                    'absent_cnt'   => 0,
                    'total_cnt'    => 0,
                ];
            }
            $bySubject[$sid]['records'][] = $r;
            $bySubject[$sid]['total_cnt']++;
            if ($r['status']==='present') $bySubject[$sid]['present_cnt']++;
            else $bySubject[$sid]['absent_cnt']++;
        }

        
        $semesterWeeks = (int)currentAcademicWeeks();
        foreach ($bySubject as &$sub) {
            $weekly = function_exists('approvedTimetableWeeklyCredits')
                ? (int)approvedTimetableWeeklyCredits($studentClassId, (int)$sub['subject_id'])
                : 0;
            $semCredits = max(0, $weekly) * max(1, $semesterWeeks);
            $sub['weekly_credits']   = $weekly;
            $sub['semester_credits'] = $semCredits;
            $sub['absence_pct'] = function_exists('absencePctClassic')
                ? absencePctClassic((int)$sub['absent_cnt'], $weekly, $semesterWeeks)
                : ($semCredits > 0 ? round((int)$sub['absent_cnt'] / $semCredits * 100, 1) : 0);
        } unset($sub);

        return [
            'student_id' => (int)$student['id'],
            'full_name'  => $student['full_name'],
            'roll'       => $student['roll'],
            'subjects'   => array_values($bySubject),
        ];
    }
}

if (!function_exists('historyGroupedByMonth')) {
    
    function historyGroupedByMonth(int $studentId): array {
        $db = getDB();
        $sql = "SELECT DATE_FORMAT(s.session_date,'%Y-%m') AS ym,
                       DATE_FORMAT(MIN(s.session_date),'%M %Y') AS month_label,
                       COUNT(ar.id) AS total,
                       SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS present_cnt,
                       SUM(CASE WHEN ar.status<>'present' THEN 1 ELSE 0 END) AS absent_cnt
                FROM attendance_records ar
                JOIN attendance_sessions s ON s.id=ar.session_id
                WHERE ar.student_id=?
                GROUP BY ym
                ORDER BY ym DESC";
        $st = $db->prepare($sql); $st->execute([$studentId]);
        $months = $st->fetchAll();
        foreach ($months as &$m) {
            $tot = (int)$m['total'];
            $m['absence_pct'] = $tot>0 ? round((int)$m['absent_cnt']/$tot*100,1) : 0;
        }
        return $months;
    }
}

if (!function_exists('listClassStudentsForBulkAttendance')) {
    
    function listClassStudentsForBulkAttendance(int $classId): array {
        $db = getDB();
        $st = $db->prepare("SELECT id, full_name, student_id AS roll, photo
                            FROM users
                            WHERE class_id=? AND role_id=".ROLE_STUDENT."
                                  AND status='active' AND deleted_at IS NULL
                            ORDER BY full_name");
        $st->execute([$classId]);
        return $st->fetchAll();
    }
}

if (!function_exists('saveBulkAttendanceUnderManager')) {
    
    function saveBulkAttendanceUnderManager(int $sessionId, array $statusMap, int $actorId): array {
        $db = getDB();
        $saved = 0; $errors = [];
        foreach ($statusMap as $stuId => $status) {
            
            $status = normalizeAttStatus($status);
            try {
                
                $prev = $db->prepare("SELECT status FROM attendance_records WHERE session_id=? AND student_id=? LIMIT 1");
                $prev->execute([$sessionId, (int)$stuId]);
                $oldStatus = $prev->fetchColumn() ?: null;

                $db->prepare("INSERT INTO attendance_records (session_id, student_id, status, marked_at, mark_method, ip_address)
                              VALUES (?,?,?,NOW(),'manager',?)
                              ON DUPLICATE KEY UPDATE status=VALUES(status), marked_at=NOW(), mark_method='manager'")
                   ->execute([$sessionId, (int)$stuId, $status, $_SERVER['REMOTE_ADDR'] ?? '']);
                writeAttendanceHistory($sessionId, (int)$stuId, $status, 'manager_bulk', 'Bulk save by manager.', $actorId);
                
                try {
                    $db->prepare("INSERT INTO manager_attendance_edits(session_id,student_id,old_status,new_status,edited_by,actor_role,edit_reason)
                                  VALUES(?,?,?,?,?,?,?)")
                       ->execute([$sessionId, (int)$stuId, $oldStatus, $status, $actorId, (int)$_SESSION['role_id'], 'Manager bulk attendance']);
                } catch (Throwable $e) {}
                $saved++;
            } catch (Throwable $e) {
                $errors[] = "Student {$stuId}: ".$e->getMessage();
            }
        }
        if (function_exists('cacheBustDashboards')) cacheBustDashboards();

        if (function_exists('triggerAbsenceRiskForClassSession')) {
            try { triggerAbsenceRiskForClassSession($sessionId); } catch (Throwable $e) {}
        }

        return ['saved'=>$saved, 'errors'=>$errors];
    }
}

if (!function_exists('creditLabel')) {
    
    function creditLabel(int $count = 1): string {
        return $count . ' ' . ($count===1 ? 'کریډیټ' : 'کریډیټونه');
    }
}

if (!function_exists('autoMarkPresentForMissedClass')) {
    
    function autoMarkPresentForMissedClass(int $sessionId): int {

        $db = getDB();
        $sess = $db->prepare("SELECT class_id FROM attendance_sessions WHERE id=?");
        $sess->execute([$sessionId]); $sess = $sess->fetch();
        if (!$sess) return 0;
        $stu = $db->prepare("SELECT id FROM users WHERE class_id=? AND role_id=".ROLE_STUDENT." AND status='active' AND deleted_at IS NULL");
        $stu->execute([$sess['class_id']]);
        $ids = $stu->fetchAll(PDO::FETCH_COLUMN);
        $ins = 0;
        foreach ($ids as $sid) {
            try {
                $db->prepare("INSERT IGNORE INTO attendance_records (session_id, student_id, status, mark_method, marked_at)
                              VALUES (?,?,?,'system',NOW())")
                   ->execute([$sessionId, (int)$sid, 'present']);
                $ins++;
            } catch (Throwable $e) {}
        }
        return $ins;
    }
}

require_once __DIR__ . '/feature_helpers.php';
require_once __DIR__ . '/security_helpers.php';

