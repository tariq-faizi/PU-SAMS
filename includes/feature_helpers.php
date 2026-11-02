<?php

if (!function_exists('hasDeepHierarchyAccess')) {
    
    function hasDeepHierarchyAccess(int $roleId = 0): bool {
        if ($roleId === 0) $roleId = (int)($_SESSION['role_id'] ?? 0);
        return in_array($roleId, [ROLE_ADMIN, ROLE_DEPT_HEAD, ROLE_ATT_MGR], true);
    }
}

if (!function_exists('absencePctClassic')) {
    
    function absencePctClassic(int $absentCredits, int $weeklyCredits, ?int $semesterWeeks = null): float {
        if ($semesterWeeks === null) $semesterWeeks = (int)currentAcademicWeeks();
        $totalSemesterCredits = max(0, $weeklyCredits) * max(1, (int)$semesterWeeks);
        if ($totalSemesterCredits <= 0) return 0.0;
        return round(($absentCredits / $totalSemesterCredits) * 100, 2);
    }
}

if (!function_exists('subjectTotalSemesterCredits')) {
    
    function subjectTotalSemesterCredits(int $classId, int $subjectId): int {
        static $cache = [];
        $key = $classId . ':' . $subjectId;
        if (isset($cache[$key])) return $cache[$key];
        $stmt = getDB()->prepare("SELECT COALESCE(SUM(credit_units),0) FROM timetable
                                   WHERE class_id=? AND subject_id=? AND status='approved'");
        $stmt->execute([$classId, $subjectId]);
        $weekly = (int)$stmt->fetchColumn();
        $cache[$key] = $weekly * (int)currentAcademicWeeks();
        return $cache[$key];
    }
}

if (!function_exists('studentSubjectAbsentCredits')) {
    
    function studentSubjectAbsentCredits(int $studentId, int $classId, int $subjectId): int {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM attendance_records ar
                                   JOIN attendance_sessions s ON s.id=ar.session_id
                                   WHERE ar.student_id=? AND ar.status='absent'
                                     AND s.class_id=? AND s.subject_id=?");
        $stmt->execute([$studentId, $classId, $subjectId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('studentSubjectAbsenceRecord')) {
    
    function studentSubjectAbsenceRecord(int $studentId, int $classId, int $subjectId): array {
        $weekly = approvedTimetableWeeklyCredits($classId, $subjectId);
        $weeks = (int)currentAcademicWeeks();
        $totalSem = $weekly * $weeks;
        $absent = studentSubjectAbsentCredits($studentId, $classId, $subjectId);

        $pct = absencePctClassic($absent, $weekly, $weeks);
        $warnAt = (float)attendanceWarningPercentThreshold();
        $depAt  = (float)attendanceDeprivationPercentThreshold();

        $state = 'safe';
        if ($pct >= $depAt) $state = 'deprived';
        elseif ($pct >= $warnAt) $state = 'warning';

        $pStmt = getDB()->prepare("SELECT
                SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS p,
                COUNT(ar.id) AS total
                FROM attendance_records ar
                JOIN attendance_sessions s ON s.id=ar.session_id
                WHERE ar.student_id=? AND s.class_id=? AND s.subject_id=?");
        $pStmt->execute([$studentId, $classId, $subjectId]);
        $r = $pStmt->fetch() ?: ['p'=>0,'total'=>0];

        return [
            'student_id'      => $studentId,
            'class_id'        => $classId,
            'subject_id'      => $subjectId,
            'weekly_credits'  => $weekly,
            'semester_weeks'  => $weeks,
            'total_semester'  => $totalSem,
            'absent_credits'  => $absent,
            'present_credits' => (int)$r['p'],
            'marked_credits'  => (int)$r['total'],
            'remaining_credits' => max(0, $totalSem - (int)$r['total']),
            'absence_pct'     => $pct,
            'warning_at'      => $warnAt,
            'deprive_at'      => $depAt,
            'state'           => $state,
            'is_warning'      => $state === 'warning',
            'is_deprived'     => $state === 'deprived',
            
            'absent_to_deprive' => max(0, (int)ceil(($depAt / 100) * $totalSem) - $absent),
            'absent_to_warn'    => max(0, (int)ceil(($warnAt / 100) * $totalSem) - $absent),
        ];
    }
}

if (!function_exists('triggerAbsenceRiskNotifications')) {
    
    function triggerAbsenceRiskNotifications(int $studentId, int $classId, int $subjectId, int $teacherId = 0): array {
        $rec = studentSubjectAbsenceRecord($studentId, $classId, $subjectId);
        if ($rec['state'] === 'safe') return ['sent'=>0,'state'=>'safe'];

        $db = getDB();
        
        $info = $db->prepare("SELECT u.full_name AS student_name, s.name AS subject_name, s.code AS subject_code
                              FROM users u JOIN subjects s ON s.id=?
                              WHERE u.id=? LIMIT 1");
        $info->execute([$subjectId, $studentId]);
        $info = $info->fetch() ?: ['student_name'=>'-', 'subject_name'=>'-', 'subject_code'=>''];

        if ($teacherId <= 0) {
            $tStmt = $db->prepare("SELECT teacher_id FROM timetable
                                    WHERE class_id=? AND subject_id=? AND status='approved'
                                    LIMIT 1");
            $tStmt->execute([$classId, $subjectId]);
            $teacherId = (int)($tStmt->fetchColumn() ?: 0);
        }

        $studentLink   = BASE_URL . '/student/attendance.php?subject=' . $subjectId . '&group=semester';
        $hierarchyLink = BASE_URL . '/hierarchy.php?level=student&cid=' . $classId . '&sid=' . $subjectId . '&uid=' . $studentId;

        $sent = 0;
        $pct  = $rec['absence_pct'];
        $subjectName = $info['subject_name'];
        $studentName = $info['student_name'];

        $alreadyNotified = function(int $userId, string $titleNeedle) use ($db): bool {
            try {
                $st = $db->prepare("SELECT id FROM notifications
                                     WHERE user_id=? AND title LIKE CONCAT('%', ?, '%')
                                       AND created_at >= (NOW() - INTERVAL 6 HOUR)
                                     LIMIT 1");
                $st->execute([$userId, $titleNeedle]);
                return (bool)$st->fetchColumn();
            } catch (Throwable $e) { return false; }
        };

        if ($rec['is_deprived']) {
            
            $titleSelf  = t('notif_deprived_subject', ['subject'=>$subjectName], 'Deprivation: '.$subjectName);
            $msgSelf    = t('notif_deprived_self', ['subject'=>$subjectName,'pct'=>$pct],
                            'You have been deprived from '.$subjectName.' due to '.$pct.'% absence.');
            if (!$alreadyNotified($studentId, 'Deprivation')) {
                createNotification($studentId, $titleSelf, $msgSelf, 'danger', $studentLink);
                $sent++;
            }

            $staffTitle = t('notif_deprived_subject', ['subject'=>$subjectName], 'Deprivation alert: '.$subjectName);
            $staffMsg   = t('notif_deprived_message', ['student'=>$studentName,'subject'=>$subjectName,'pct'=>$pct],
                            $studentName.' has been deprived from '.$subjectName.' at '.$pct.'% absence.');

            if ($teacherId > 0 && !$alreadyNotified($teacherId, $studentName)) {
                createNotification($teacherId, $staffTitle, $staffMsg, 'danger', $hierarchyLink);
                $sent++;
            }
            
            foreach (adminUserIds() as $adminId) {
                $adminId = (int)$adminId;
                if ($adminId && !$alreadyNotified($adminId, $studentName)) {
                    createNotification($adminId, $staffTitle, $staffMsg, 'danger', $hierarchyLink);
                    $sent++;
                }
            }
            
            try {
                $facStmt = $db->prepare("SELECT faculty_id FROM users WHERE id=? LIMIT 1");
                $facStmt->execute([$studentId]);
                $facId = (int)($facStmt->fetchColumn() ?: 0);
                if ($facId > 0) {
                    // Department managers (مدیر)
                    foreach (facultyManagerIds($facId) as $mgrId) {
                        $mgrId = (int)$mgrId;
                        if ($mgrId && !$alreadyNotified($mgrId, $studentName)) {
                            createNotification($mgrId, $staffTitle, $staffMsg, 'danger', $hierarchyLink);
                            $sent++;
                        }
                    }
                    // Faculty dean (د پوهنځی رییس)
                    try {
                        $deanStmt = $db->prepare("SELECT id FROM users WHERE role_id=? AND faculty_id=? AND status='active' AND deleted_at IS NULL");
                        $deanStmt->execute([ROLE_DEPT_HEAD, $facId]);
                        foreach ($deanStmt->fetchAll(PDO::FETCH_COLUMN) as $deanId) {
                            $deanId = (int)$deanId;
                            if ($deanId && !$alreadyNotified($deanId, $studentName)) {
                                createNotification($deanId, $staffTitle, $staffMsg, 'danger', $hierarchyLink);
                                $sent++;
                            }
                        }
                    } catch (Throwable $e) {}
                }
            } catch (Throwable $e) {}

        } elseif ($rec['is_warning']) {
            
            $title = t('notif_warning_subject', ['subject'=>$subjectName], 'Attendance warning: '.$subjectName);
            $msg   = t('notif_warning_message', ['subject'=>$subjectName,'pct'=>$pct],
                       'Your absence in '.$subjectName.' reached '.$pct.'%. Please monitor your attendance.');
            if (!$alreadyNotified($studentId, 'warning')) {
                createNotification($studentId, $title, $msg, 'warning', $studentLink);
                $sent++;
            }

            // Also notify the subject's teacher when student crosses the warning threshold (⋥20%)
            if ($teacherId > 0) {
                $teacherTitle = t('notif_warning_teacher_subject', ['subject'=>$subjectName],
                                  'Student attendance warning: '.$subjectName);
                $teacherMsg   = t('notif_warning_teacher_message', ['student'=>$studentName,'subject'=>$subjectName,'pct'=>$pct],
                                  $studentName.' absence in '.$subjectName.' reached '.$pct.'% — at risk of deprivation.');
                if (!$alreadyNotified($teacherId, $studentName)) {
                    createNotification($teacherId, $teacherTitle, $teacherMsg, 'warning', $hierarchyLink);
                    $sent++;
                }
            }
        }

        return ['sent'=>$sent, 'state'=>$rec['state'], 'pct'=>$pct];
    }
}

if (!function_exists('triggerAbsenceRiskForClassSession')) {
    
    function triggerAbsenceRiskForClassSession(int $sessionId): int {
        $db = getDB();
        $st = $db->prepare("SELECT class_id, subject_id, teacher_id FROM attendance_sessions WHERE id=? LIMIT 1");
        $st->execute([$sessionId]);
        $r = $st->fetch();
        if (!$r) return 0;
        $classId   = (int)$r['class_id'];
        $subjectId = (int)$r['subject_id'];
        $teacherId = (int)$r['teacher_id'];

        $stu = $db->prepare("SELECT id FROM users WHERE class_id=? AND role_id=" . ROLE_STUDENT .
                            " AND status='active' AND deleted_at IS NULL");
        $stu->execute([$classId]);
        $ids = $stu->fetchAll(PDO::FETCH_COLUMN);

        $totalSent = 0;
        foreach ($ids as $sid) {
            $res = triggerAbsenceRiskNotifications((int)$sid, $classId, $subjectId, $teacherId);
            $totalSent += (int)($res['sent'] ?? 0);
        }
        return $totalSent;
    }
}

if (!function_exists('renderHierarchyEntryButton')) {
    
    function renderHierarchyEntryButton(int $roleId, ?int $facultyId = null): string {
        if (!hasDeepHierarchyAccess($roleId)) return '';
        $url = BASE_URL . '/hierarchy.php?level=root';
        if ($roleId !== ROLE_ADMIN && $facultyId) {
            $url = BASE_URL . '/hierarchy.php?level=faculty&fid=' . (int)$facultyId;
        }
        $titlePs = 'Open full hierarchical view';
        $titleEn = 'Open hierarchical drill-down view';
        return
            '<a href="' . h($url) . '" class="hub-hierarchy-entry">' .
              '<div class="hub-he-icon"><i class="fas fa-sitemap"></i></div>' .
              '<div class="hub-he-body">' .
                '<div class="hub-he-title">' . h($titlePs) . '</div>' .
                '<div class="hub-he-sub">پوهنځۍ → څانګې → ټولګي → Subjects → Students · د هر محصل خاضري وڅارئ او ایډیټ کړئ</div>' .
              '</div>' .
              '<div class="hub-he-arrow"><i class="fas fa-arrow-' . (isRtl() ? 'left' : 'right') . '"></i></div>' .
            '</a>';
    }
}

if (!function_exists('userCanEditAttendance')) {
    
    function userCanEditAttendance(int $roleId = 0): bool {
        if ($roleId === 0) $roleId = (int)($_SESSION['role_id'] ?? 0);
        
        if ($roleId === ROLE_ADMIN)   return true;
        if ($roleId === ROLE_ATT_MGR) return true;
        return false;
    }
}

if (!function_exists('userCanManageAcademic')) {
    
    function userCanManageAcademic(int $roleId = 0): bool {
        if ($roleId === 0) $roleId = (int)($_SESSION['role_id'] ?? 0);
        return userHasPermission('manage_classes', $roleId)
            || userHasPermission('manage_subjects', $roleId)
            || userHasPermission('manage_timetable', $roleId)
            || userHasPermission('manage_dept_users', $roleId);
    }
}

if (!function_exists('renderAttCell')) {
    
    function renderAttCell(int $studentId, int $sessionId, string $current = '', bool $editable = true, string $namePrefix = 'attendance_status'): string {
        $current = normalizeAttStatus($current);

        $disabledAttr = $editable ? '' : 'disabled';
        $disabledCls  = $editable ? '' : 'disabled';

        $field  = $namePrefix . '[' . $studentId . '][' . $sessionId . ']';
        $cellId = 'attcell_' . $studentId . '_' . $sessionId;
        $hidId  = 'atthid_'  . $studentId . '_' . $sessionId;

        return
          '<div class="ros-att-wrap d-inline-flex flex-column align-items-center gap-1">' .
            '<button type="button" id="' . $cellId . '" ' .
              'class="ros-att-cell ' . $disabledCls . '" ' .
              'data-state="' . h($current) . '" ' .
              'data-student="' . $studentId . '" data-session="' . $sessionId . '" ' .
              'data-target="' . $hidId . '" ' . $disabledAttr . '></button>' .
            '<input type="hidden" id="' . $hidId . '" name="' . h($field) . '" value="' . h($current) . '"/>' .
          '</div>';
    }
}

if (!function_exists('renderCreditSlotGroup')) {
    
    function renderCreditSlotGroup(array $sessionSlots, int $studentId, array $marks, bool $editable = true): string {
        $html = '<div class="ros-credit-slots">';
        foreach ($sessionSlots as $slot) {
            $sid = (int)$slot['id'];
            $cur = (string)($marks[$sid] ?? '');
            $caption = $slot['slot_label'] ?? ('#' . ($slot['credit_slot_no'] ?? ''));
            $html .=
              '<div class="d-flex flex-column align-items-center">' .
                renderAttCell($studentId, $sid, $cur, $editable) .
                '<div class="ros-slot-cap">' . h(substr($slot['start_time'] ?? '', 0, 5)) . '</div>' .
              '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('userCanTakeAttendance')) {
    
    function userCanTakeAttendance(int $roleId = 0): bool {
        if ($roleId === 0) $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($roleId === ROLE_STUDENT) return false;
        if ($roleId === ROLE_ADMIN) return true;
        if (function_exists('userCanEditAttendance')) {
            return userCanEditAttendance($roleId);
        }
        return false;
    }
}

if (!function_exists('autoSync')) {
    
    function autoSync(): void {
        if (empty($_SESSION['user_id'])) return;
        
        $now = time();
        $last = (int)($_SESSION['_auto_sync_at'] ?? 0);
        if ($now - $last < 30) return;
        $_SESSION['_auto_sync_at'] = $now;

        try {
            $today = date('Y-m-d');
            if (function_exists('syncSessionsForDate')) syncSessionsForDate($today);
            if (function_exists('markMissedSessionsForDate')) markMissedSessionsForDate($today);
        } catch (Throwable $e) {
            
        }
    }
}

if (!function_exists('renderTristateScript')) {
    
    function renderTristateScript(): string {
        return <<<'HTML'
<script>
(function(){
  if (window.__tristateBound) return;
  window.__tristateBound = true;

  function nextState(cur){
    return cur === 'present' ? 'absent' : 'present';
  }

  function setGlyphTarget(el, st){
    if (!el) return;
    el.setAttribute('data-state', st === 'present' ? 'present' : 'absent');
    if ('textContent' in el && el.classList.contains('ros-col-toggle-text')) {
      el.textContent = st === 'present' ? '✅' : '❌';
    }
  }

  function applyState(btn, st){
    st = st === 'present' ? 'present' : 'absent';
    setGlyphTarget(btn, st);
    var hid = btn.dataset.target ? document.getElementById(btn.dataset.target) : null;
    if (hid) hid.value = st;
  }

  function setColumnButtonState(btn, st){
    st = st === 'present' ? 'present' : 'absent';
    btn.setAttribute('data-state', st);
    btn.setAttribute('title', st === 'present' ? '✅' : '❌');
    btn.setAttribute('aria-label', st === 'present' ? '✅' : '❌');
  }

  function detectColumnState(sessionId){
    var cells = Array.from(document.querySelectorAll('.ros-att-cell[data-session="' + sessionId + '"][data-target]'));
    if (!cells.length) return 'absent';
    return cells.every(function(cell){ return (cell.getAttribute('data-state') || 'absent') === 'present'; }) ? 'present' : 'absent';
  }

  function applyColumnState(sessionId, st){
    document.querySelectorAll('.ros-att-cell[data-session="' + sessionId + '"][data-target]').forEach(function(cell){
      if (cell.disabled || cell.classList.contains('disabled')) return;
      applyState(cell, st);
    });
    document.querySelectorAll('[data-roster-col-toggle="' + sessionId + '"]').forEach(function(btn){
      setColumnButtonState(btn, st);
    });
  }

  document.addEventListener('click', function(ev){
    var btn = ev.target.closest('.ros-att-cell');
    if (!btn || btn.disabled || btn.classList.contains('disabled')) return;
    if (btn.hasAttribute('data-roster-col-toggle')) return;
    var st = nextState(btn.getAttribute('data-state') || 'absent');
    applyState(btn, st);
    if (btn.dataset.session) {
      var nextColState = detectColumnState(btn.dataset.session);
      document.querySelectorAll('[data-roster-col-toggle="' + btn.dataset.session + '"]').forEach(function(colBtn){
        setColumnButtonState(colBtn, nextColState);
      });
    }
  });

  document.addEventListener('click', function(ev){
    var t = ev.target.closest('[data-roster-bulk]');
    if (!t) return;
    ev.preventDefault();
    var st = t.getAttribute('data-roster-bulk');
    if (st !== 'present' && st !== 'absent') return;
    document.querySelectorAll('.ros-att-cell[data-target]').forEach(function(b){
      if (b.disabled || b.classList.contains('disabled')) return;
      applyState(b, st);
    });
    document.querySelectorAll('[data-roster-col-toggle]').forEach(function(btn){
      setColumnButtonState(btn, st);
    });
  });

  document.addEventListener('click', function(ev){
    var t = ev.target.closest('[data-roster-row]');
    if (!t) return;
    ev.preventDefault();
    var st = t.getAttribute('data-roster-row');
    if (st !== 'present' && st !== 'absent') return;
    var sid = t.getAttribute('data-row-student');
    document.querySelectorAll('.ros-att-cell[data-student="'+sid+'"][data-target]').forEach(function(b){
      if (b.disabled || b.classList.contains('disabled')) return;
      applyState(b, st);
      if (b.dataset.session) {
        var nextColState = detectColumnState(b.dataset.session);
        document.querySelectorAll('[data-roster-col-toggle="' + b.dataset.session + '"]').forEach(function(colBtn){
          setColumnButtonState(colBtn, nextColState);
        });
      }
    });
  });

  document.addEventListener('click', function(ev){
    var btn = ev.target.closest('[data-roster-col-toggle]');
    if (!btn || btn.disabled || btn.classList.contains('disabled')) return;
    ev.preventDefault();
    var sessionId = btn.getAttribute('data-roster-col-toggle');
    var st = nextState(btn.getAttribute('data-state') || detectColumnState(sessionId));
    applyColumnState(sessionId, st);
  });

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('[data-roster-col-toggle]').forEach(function(btn){
      setColumnButtonState(btn, detectColumnState(btn.getAttribute('data-roster-col-toggle')));
    });
  });
})();
</script>
HTML;
    }
}

if (!function_exists('renderClusterCard')) {
    function renderClusterCard(array $opt): string {
        $href  = $opt['href']  ?? '#';
        $tone  = $opt['tone']  ?? 'primary';
        $icon  = $opt['icon']  ?? 'fa-chart-bar';
        $label = (string)($opt['label'] ?? '');
        $value = (string)($opt['value'] ?? '0');

        if (strpos($label, ' / ') !== false) {
            $label = trim(explode(' / ', $label)[0]);
        }

        $rtl   = function_exists('isRtl') ? isRtl() : false;
        $arrow = $rtl ? 'fa-arrow-left' : 'fa-arrow-right';

        $card =
          '<div class="hub-cluster-card srf-cluster-card tone-' . h($tone) . '">' .
            '<div class="hub-cc-icon"><i class="fas ' . h($icon) . '"></i></div>' .
            '<div class="hub-cc-value">' . h($value) . '</div>' .
            '<div class="hub-cc-label">' . h($label) . '</div>' .
            '<div class="hub-cc-arrow"><i class="fas ' . $arrow . '"></i></div>' .
          '</div>';

        if ($href !== '#' && $href !== '') {
            return '<a href="' . h($href) . '" class="hub-cc-link">' . $card . '</a>';
        }
        return $card;
    }
}

if (!function_exists('normalizeAttStatus')) {
    
    function normalizeAttStatus(?string $status): string {
        $s = trim((string)$status);
        if ($s === 'present') return 'present';
        if ($s === 'absent')  return 'absent';
        if ($s === 'late' || $s === 'excused' || $s === 'hold' || $s === 'حالی' || $s === '' || $s === 'empty' || $s === 'null') return 'absent';
        return 'absent';
    }
}

if (!function_exists('saveTriStateAttendance')) {
    
    function saveTriStateAttendance(int $sessionId, array $statusMap, int $actorId, string $method = 'manual'): array {
        $db = getDB();
        $saved = 0; $cleared = 0; $errors = [];
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        foreach ($statusMap as $stuId => $rawStatus) {
            $stuId = (int)$stuId;
            $status = normalizeAttStatus((string)$rawStatus);
            try {
                $prev = $db->prepare("SELECT status FROM attendance_records WHERE session_id=? AND student_id=? LIMIT 1");
                $prev->execute([$sessionId, $stuId]);
                $oldStatus = $prev->fetchColumn() ?: null;

                if ($status === '') {
                    
                    if ($oldStatus !== null) {
                        $db->prepare("DELETE FROM attendance_records WHERE session_id=? AND student_id=?")
                           ->execute([$sessionId, $stuId]);
                        writeAttendanceHistory($sessionId, $stuId, '', 'tri_state_clear',
                            'Cell cleared by user via tri-state edit.', $actorId);
                        if (function_exists('writeManagerAuditTrail')) {
                            writeManagerAuditTrail($sessionId, $stuId, (string)$oldStatus, '', $actorId, $roleId, 'Tri-state cleared');
                        }
                        $cleared++;
                    }
                    continue;
                }
                $db->prepare("INSERT INTO attendance_records (session_id, student_id, status, marked_at, mark_method, ip_address)
                              VALUES (?,?,?,NOW(),?,?)
                              ON DUPLICATE KEY UPDATE status=VALUES(status), marked_at=NOW(), mark_method=VALUES(mark_method)")
                   ->execute([$sessionId, $stuId, $status, $method, $_SERVER['REMOTE_ADDR'] ?? '']);
                writeAttendanceHistory($sessionId, $stuId, $status, 'tri_state_save',
                    'Tri-state attendance save.', $actorId);
                if (function_exists('writeManagerAuditTrail')) {
                    writeManagerAuditTrail($sessionId, $stuId, (string)$oldStatus, $status, $actorId, $roleId, 'Tri-state set');
                }
                $saved++;
            } catch (Throwable $e) {
                $errors[] = "Student {$stuId}: ".$e->getMessage();
            }
        }
        if (function_exists('cacheBustDashboards')) cacheBustDashboards();
        if (function_exists('triggerAbsenceRiskForClassSession')) {
            try { triggerAbsenceRiskForClassSession($sessionId); } catch (Throwable $e) {}
        }
        return ['saved'=>$saved,'cleared'=>$cleared,'errors'=>$errors];
    }
}

if (!function_exists('writeManagerAuditTrail')) {
    function writeManagerAuditTrail(int $sessionId, int $studentId, string $oldStatus, string $newStatus, int $actorId, int $roleId, string $note): void {
        try {
            getDB()->prepare("INSERT INTO manager_attendance_edits(session_id,student_id,old_status,new_status,edited_by,actor_role,edit_reason)
                              VALUES(?,?,?,?,?,?,?)")
                   ->execute([$sessionId, $studentId, $oldStatus ?: null, $newStatus, $actorId, $roleId, $note]);
        } catch (Throwable $e) {  }
    }
}

if (!function_exists('semesterAbsencePercentStrict')) {
    function semesterAbsencePercentStrict(int $studentId, int $classId, int $subjectId): float {
        if (function_exists('studentSubjectAbsenceRecord')) {
            $r = studentSubjectAbsenceRecord($studentId, $classId, $subjectId);
            return (float)($r['absence_pct'] ?? 0);
        }
        return 0.0;
    }
}

if (!function_exists('autoSyncTick')) {
    function autoSyncTick(): void {
        if (empty($_SESSION['user_id'])) return;
        $now = time();
        $last = (int)($_SESSION['_auto_sync_tick_at'] ?? 0);
        if ($now - $last < 20) return;
        $_SESSION['_auto_sync_tick_at'] = $now;
        try {
            $today = date('Y-m-d');
            if (function_exists('syncSessionsForDate'))       syncSessionsForDate($today);
            if (function_exists('markMissedSessionsForDate')) markMissedSessionsForDate($today);
            if (function_exists('cacheBustDashboards'))       cacheBustDashboards();
        } catch (Throwable $e) {  }
    }
}

if (!function_exists('attendanceDrillLink')) {
    function attendanceDrillLink(string $scope, array $params = []): string {
        $base = BASE_URL . '/hierarchy.php';
        $q = ['level' => 'root'];
        switch ($scope) {
            case 'faculties':   $q['level'] = 'root'; break;
            case 'departments': $q['level'] = 'faculty';    if (!empty($params['fid'])) $q['fid'] = (int)$params['fid']; break;
            case 'classes':     $q['level'] = 'department'; if (!empty($params['did'])) $q['did'] = (int)$params['did']; break;
            case 'subjects':    $q['level'] = 'class';      if (!empty($params['cid'])) $q['cid'] = (int)$params['cid']; break;
            case 'students':    $q['level'] = 'subject';
                if (!empty($params['cid'])) $q['cid'] = (int)$params['cid'];
                if (!empty($params['sid'])) $q['sid'] = (int)$params['sid'];
                break;
            case 'teacher':     $q['level'] = 'teacher_classes'; break;
        }
        return $base . '?' . http_build_query($q);
    }
}

if (!function_exists('canEditSession')) {
    function canEditSession(array $session, int $roleId = 0, int $userId = 0, int $facultyScope = 0): bool {
        if ($roleId === 0)       $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($userId === 0)       $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($facultyScope === 0) $facultyScope = (int)($_SESSION['faculty_id'] ?? 0);

        if ($roleId === ROLE_STUDENT) return false;
        if ($roleId === ROLE_ADMIN)   return true; 
        
        $sessionFaculty = (int)($session['faculty_id'] ?? 0);
        if ($sessionFaculty && $facultyScope && $sessionFaculty !== $facultyScope) return false;
        if ($roleId === ROLE_TEACHER) {
            return (int)($session['teacher_id'] ?? 0) === $userId
                && sessionEditableByTeacher((string)($session['session_date'] ?? date('Y-m-d')));
        }
        if ($roleId === ROLE_ATT_MGR || $roleId === ROLE_DEPT_HEAD) {
            return userCanEditAttendance($roleId);
        }
        return false;
    }
}

if (!function_exists('renderBulkBar')) {
    function renderBulkBar(bool $canEdit = true): string {
        
        if (!$canEdit) return '';
        return
          '<div class="ros-bulk-bar srf-bulk-bar">' .
            '<span class="legend-pill"><span class="dot ok"></span>'  . h(t('ui_att_present')) . '</span>' .
            '<span class="legend-pill"><span class="dot bad"></span>' . h(t('ui_att_absent'))  . '</span>' .
            '<div class="ms-auto d-flex gap-2 flex-wrap">' .
              '<button type="button" class="btn btn-sm btn-success" data-roster-bulk="present"><i class="fas fa-check-double me-1"></i>' . h(t('ui_bulk_present')) . '</button>' .
              '<button type="button" class="btn btn-sm btn-danger"  data-roster-bulk="absent"><i class="fas fa-xmark me-1"></i>'         . h(t('ui_bulk_absent'))  . '</button>' .
            '</div>' .
          '</div>';
    }
}

if (!function_exists('activeLang')) {
    function activeLang(): string {
        return $GLOBALS['currentLang'] ?? 'en';
    }
}

if (!function_exists('applySecurityHeaders')) {
    function applySecurityHeaders(): void {
        if (headers_sent()) return;
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

if (!function_exists('attendanceHierarchyUrl')) {
    function attendanceHierarchyUrl(array $params = []): string {
        $params['level'] = $params['level'] ?? 'root';
        return BASE_URL . '/hierarchy.php?' . http_build_query($params);
    }
}

if (!function_exists('attendanceHierarchyFacultyUrl')) {
    function attendanceHierarchyFacultyUrl(int $facultyId): string {
        return attendanceHierarchyUrl([
            'level' => 'faculty',
            'fid'   => max(0, $facultyId),
        ]);
    }
}

if (!function_exists('attendanceHierarchyDepartmentUrl')) {
    function attendanceHierarchyDepartmentUrl(int $facultyId, int $departmentId): string {
        return attendanceHierarchyUrl([
            'level' => 'department',
            'fid'   => max(0, $facultyId),
            'did'   => max(0, $departmentId),
        ]);
    }
}

if (!function_exists('attendanceHierarchyClassUrl')) {
    function attendanceHierarchyClassUrl(int $classId, int $facultyId = 0, int $departmentId = 0): string {
        return attendanceHierarchyUrl([
            'level' => 'class',
            'fid'   => max(0, $facultyId),
            'did'   => max(0, $departmentId),
            'cid'   => max(0, $classId),
        ]);
    }
}

if (!function_exists('attendanceFocusedUserViewUrl')) {
    function attendanceFocusedUserViewUrl(array $user, string $fallback = ''): string {
        $userId = (int)($user['id'] ?? 0);
        $roleId = (int)($user['role_id'] ?? 0);
        $classId = (int)($user['class_id'] ?? 0);
        $facultyId = (int)($user['faculty_id'] ?? 0);
        $departmentId = (int)($user['department_id'] ?? 0);

        if ($roleId === ROLE_STUDENT && $userId > 0 && $classId > 0) {
            return attendanceHierarchyUrl([
                'level'   => 'student',
                'fid'     => $facultyId,
                'did'     => $departmentId,
                'cid'     => $classId,
                'uid'     => $userId,
                'group'   => 'semester',
            ]);
        }

        return $fallback !== '' ? $fallback : attendanceHierarchyUrl(['level' => 'root']);
    }
}
