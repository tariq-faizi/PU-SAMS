<?php

if (!function_exists('renderTimetableAttendanceView')) {
    
    function renderTimetableAttendanceView(
        array $viewRow,
        array $sessionSlots,
        array $roster,
        string $date,
        string $backUrl = '',
        string $editUrl = '',
        bool $canEdit = false
    ): string {

        
        $stats = ['present' => 0, 'absent' => 0, 'total' => count($roster)];
        foreach ($roster as $st) {
            foreach (($st['marks'] ?? []) as $mk) {
                if ($mk === 'present')      $stats['present']++;
                elseif ($mk === 'absent')   $stats['absent']++;
            }
        }
        $totalMarked = max(1, $stats['present'] + $stats['absent']);
        $presentPct  = round($stats['present'] * 100 / $totalMarked, 1);

        ob_start();
        ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h5 class="mb-1"><i class="fas fa-eye me-2"></i><?= h(t('view')) ?> — <?= h($viewRow['subject_name'] ?? '') ?></h5>
                <div class="text-muted small">
                    <i class="fas fa-info-circle me-1"></i>
                    <?= h(t('view_readonly_note')) ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <?php if ($backUrl !== ''): ?>
                    <a href="<?= h($backUrl) ?>" class="btn btn-sm btn-secondary">
                        <i class="fas fa-arrow-<?= isRtl() ? 'right' : 'left' ?> me-1"></i><?= h(t('back')) ?>
                    </a>
                <?php endif; ?>
                <?php if ($canEdit && $editUrl !== ''): ?>
                    <a href="<?= h($editUrl) ?>" class="btn btn-sm btn-warning">
                        <i class="fas fa-pen-to-square me-1"></i><?= h(t('edit')) ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2 mb-3 d-inline-flex align-items-center">
            <i class="fas fa-lock me-1"></i> <?= h(t('read_only')) ?>
        </span>

        <!-- د سیشن Title کارت -->
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between gap-3 align-items-center">
                <div>
                    <h5 class="mb-1">
                        <?= h($viewRow['subject_name'] ?? '') ?> —
                        <?= h($viewRow['class_name'] ?? '') ?> <?= h($viewRow['section'] ?? '') ?>
                    </h5>
                    <div class="small text-muted">
                        <i class="fas fa-calendar me-1"></i> <?= h($date) ?> ·
                        <i class="fas fa-clock me-1"></i>
                        <?= h(substr($viewRow['start_time'] ?? '', 0, 5)) ?>–<?= h(substr($viewRow['end_time'] ?? '', 0, 5)) ?> ·
                        <i class="fas fa-user me-1"></i> <?= h($viewRow['teacher_name'] ?? '—') ?> ·
                        <i class="fas fa-door-open me-1"></i> <?= h($viewRow['room_name'] ?? ($viewRow['room'] ?? '—')) ?> ·
                        <i class="fas fa-layer-group me-1"></i> <?= (int)($viewRow['credit_units'] ?? 0) ?> <?= h(t('credit')) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- د احصاییو کارتونه -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="card text-center h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small"><i class="fas fa-users me-1"></i><?= h(t('student')) ?></div>
                        <div class="h4 mb-0"><?= (int)$stats['total'] ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center h-100 border-0 shadow-sm bg-success bg-opacity-10">
                    <div class="card-body">
                        <div class="text-success small"><i class="fas fa-check me-1"></i><?= h(t('present')) ?></div>
                        <div class="h4 mb-0 text-success"><?= (int)$stats['present'] ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center h-100 border-0 shadow-sm bg-danger bg-opacity-10">
                    <div class="card-body">
                        <div class="text-danger small"><i class="fas fa-times me-1"></i><?= h(t('absent')) ?></div>
                        <div class="h4 mb-0 text-danger"><?= (int)$stats['absent'] ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small"><i class="fas fa-percent me-1"></i>%</div>
                        <div class="h4 mb-0">
                            <span class="badge bg-<?= attendancePercentClass((float)$presentPct) ?>"><?= $presentPct ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- د حاضرۍ ښکلې جدول — د استاد په شان -->
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="fas fa-list me-2"></i><?= h(t('attendance_register') ?: 'Attendance register') ?></span>
                <small class="text-muted"><i class="fas fa-eye me-1"></i><?= h(t('read_only')) ?></small>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 ros-roster-table">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th><?= h(t('student')) ?></th>
                            <th>ID</th>
                            <th><?= h(t('absence_pct') ?: 'Absence %') ?></th>
                            <?php foreach ($sessionSlots as $idx => $slot): ?>
                                <th class="text-center">
                                    <div><?= h($slot['slot_label'] ?: 'Credit '.($idx+1)) ?></div>
                                    <div class="small text-muted"><?= h(substr($slot['start_time'] ?? '', 0, 5)) ?></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$roster): ?>
                            <tr>
                                <td colspan="<?= 4 + count($sessionSlots) ?>" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>
                                    <?= h(t('no_records')) ?>
                                </td>
                            </tr>
                        <?php else: foreach ($roster as $i => $student): ?>
                            <tr>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td><div class="fw-semibold"><?= h($student['full_name']) ?></div></td>
                                <td><span class="badge bg-light text-dark"><?= h($student['student_id'] ?? '') ?></span></td>
                                <td>
                                    <span class="badge bg-<?= function_exists('absencePercentClass') ? absencePercentClass((float)($student['absence_pct'] ?? 0)) : 'secondary' ?>">
                                        <?= (float)($student['absence_pct'] ?? 0) ?>%
                                    </span>
                                </td>
                                <?php foreach ($sessionSlots as $slot):
                                    
                                    $cur = $student['marks'][$slot['id']] ?? 'present';
                                    if ($cur !== 'present' && $cur !== 'absent') $cur = 'present';
                                ?>
                                    <td class="text-center">
                                        <?= renderAttCell((int)$student['id'], (int)$slot['id'], (string)$cur, false) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted small d-flex flex-wrap gap-3 justify-content-center">
                <span><span class="ros-att-cell" data-state="present" disabled style="pointer-events:none"></span></span>
                <span><span class="ros-att-cell" data-state="absent"  disabled style="pointer-events:none"></span></span>
            </div>
        </div>

        <?= function_exists('renderTristateScript') ? renderTristateScript() : '' ?>
        <script>
        // Read-only mode: د هرې حجرې (دوه-حالته) Activeیت بند کړئ.
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.ros-att-cell').forEach(function (el) {
                el.setAttribute('disabled', 'disabled');
                el.classList.add('disabled');
                el.style.pointerEvents = 'none';
                el.style.cursor = 'not-allowed';
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('loadTimetableAttendanceContext')) {
    
    function loadTimetableAttendanceContext(int $timetableId, string $date, int $facultyScope = 0): ?array {
        $db = getDB();
        
        $sql = "SELECT t.*, c.name AS class_name, c.section, sub.name AS subject_name,
                       u.full_name AS teacher_name, sem.name AS semester_name,
                       COALESCE(r.name, t.room) AS room_name
                FROM timetable t
                JOIN classes c    ON c.id = t.class_id
                JOIN subjects sub ON sub.id = t.subject_id
                JOIN users u      ON u.id = t.teacher_id
                LEFT JOIN semesters sem ON sem.id = t.semester_id
                LEFT JOIN rooms r ON r.id = t.room_id
                WHERE t.id = ? AND t.status = 'approved'";
        $params = [$timetableId];
        if ($facultyScope > 0) { $sql .= " AND t.faculty_id = ?"; $params[] = $facultyScope; }
        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $viewRow = $stmt->fetch();
        if (!$viewRow) return null;

        if (function_exists('syncSessionsForDate')) {
            try { syncSessionsForDate($date, 0, $timetableId); } catch (Throwable $e) {}
        }
        $slotsStmt = $db->prepare("SELECT * FROM attendance_sessions
                                   WHERE timetable_id = ? AND session_date = ?
                                   ORDER BY credit_slot_no, id");
        $slotsStmt->execute([$timetableId, $date]);
        $sessionSlots = $slotsStmt->fetchAll();

        $rosterStmt = $db->prepare("SELECT u.id, u.full_name, u.student_id
                                    FROM users u
                                    WHERE u.role_id = ? AND u.class_id = ?
                                          AND u.status = 'active' AND u.deleted_at IS NULL
                                    ORDER BY u.full_name");
        $rosterStmt->execute([ROLE_STUDENT, $viewRow['class_id']]);
        $students = $rosterStmt->fetchAll();

        $existing = [];
        if ($sessionSlots) {
            $ids = array_column($sessionSlots, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $markStmt = $db->prepare("SELECT session_id, student_id, status
                                      FROM attendance_records WHERE session_id IN ($in)");
            $markStmt->execute($ids);
            foreach ($markStmt->fetchAll() as $mk) {
                $existing[$mk['student_id']][$mk['session_id']] = $mk['status'];
            }
        }

        $roster = [];
        foreach ($students as $student) {
            $student['marks'] = [];
            foreach ($sessionSlots as $slot) {
                
                $student['marks'][$slot['id']] = $existing[$student['id']][$slot['id']] ?? 'present';
            }
            if (function_exists('studentSubjectAbsenceRecord')) {
                try {
                    $rec = studentSubjectAbsenceRecord(
                        (int)$student['id'],
                        (int)$viewRow['class_id'],
                        (int)$viewRow['subject_id']
                    );
                    $student['absence_pct'] = $rec['absence_pct'] ?? 0;
                } catch (Throwable $e) {
                    $student['absence_pct'] = 0;
                }
            } else {
                $student['absence_pct'] = 0;
            }
            $roster[] = $student;
        }

        return [
            'viewRow'      => $viewRow,
            'sessionSlots' => $sessionSlots,
            'roster'       => $roster,
        ];
    }
}
