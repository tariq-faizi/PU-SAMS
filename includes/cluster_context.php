<?php

if (!function_exists('clusterContextLookup')) {

function clusterContextLookup(string $entity, int $id): ?array {
    if ($id <= 0) return null;
    $db = getDB();
    switch ($entity) {
        case 'faculty':
            $st = $db->prepare("SELECT id, name, code FROM faculties WHERE id=? LIMIT 1");
            $st->execute([$id]);
            return $st->fetch() ?: null;
        case 'department':
            $st = $db->prepare("SELECT d.id, d.name, d.code, d.faculty_id, f.name AS faculty_name
                                FROM departments d
                                LEFT JOIN faculties f ON f.id = d.faculty_id
                                WHERE d.id=? LIMIT 1");
            $st->execute([$id]);
            return $st->fetch() ?: null;
        case 'class':
            $st = $db->prepare("SELECT c.id, c.name, c.section, c.faculty_id, c.department_id,
                                       f.name AS faculty_name, d.name AS department_name
                                FROM classes c
                                LEFT JOIN faculties f ON f.id = c.faculty_id
                                LEFT JOIN departments d ON d.id = c.department_id
                                WHERE c.id=? LIMIT 1");
            $st->execute([$id]);
            return $st->fetch() ?: null;
        case 'subject':
            $st = $db->prepare("SELECT s.id, s.name, s.code, s.faculty_id, s.department_id,
                                       f.name AS faculty_name, d.name AS department_name
                                FROM subjects s
                                LEFT JOIN faculties f ON f.id = s.faculty_id
                                LEFT JOIN departments d ON d.id = s.department_id
                                WHERE s.id=? LIMIT 1");
            $st->execute([$id]);
            return $st->fetch() ?: null;
    }
    return null;
}
}

if (!function_exists('renderClusterContextBanner')) {

function renderClusterContextBanner(array $ctx = []): string {
    $facultyId    = (int)($ctx['faculty_id']    ?? 0);
    $departmentId = (int)($ctx['department_id'] ?? 0);
    $classId      = (int)($ctx['class_id']      ?? 0);
    $subjectId    = (int)($ctx['subject_id']    ?? 0);
    $roleId       = (int)($ctx['role']          ?? 0);
    $status       = (string)($ctx['status']     ?? '');
    $currentLabel = (string)($ctx['current_label'] ?? '');

    $faculty    = clusterContextLookup('faculty',    $facultyId);
    $department = clusterContextLookup('department', $departmentId);
    $class      = clusterContextLookup('class',      $classId);
    $subject    = clusterContextLookup('subject',    $subjectId);

    if (!$faculty && !$department && !$class && !$subject && !$roleId && $status === '') {
        return '';
    }

    $crumbs = [];
    if ($faculty) {
        $crumbs[] = '<span class="badge bg-primary"><i class="fas fa-building-columns me-1"></i>'.h($faculty['name']).'</span>';
    }
    if ($department) {
        $crumbs[] = '<i class="fas fa-angle-left mx-1 text-muted"></i><span class="badge bg-info text-dark"><i class="fas fa-building me-1"></i>'.h($department['name']).'</span>';
    }
    if ($class) {
        $clsName = trim($class['name'].' '.($class['section'] ?? ''));
        $crumbs[] = '<i class="fas fa-angle-left mx-1 text-muted"></i><span class="badge bg-teal" style="background:#0d9488"><i class="fas fa-chalkboard me-1"></i>'.h($clsName).'</span>';
    }
    if ($subject) {
        $crumbs[] = '<i class="fas fa-angle-left mx-1 text-muted"></i><span class="badge" style="background:#f59e0b"><i class="fas fa-book me-1"></i>'.h($subject['name']).' <small>'.h($subject['code'] ?? '').'</small></span>';
    }
    if ($roleId > 0) {
        $roleLabel = $ctx['role_label'] ?? ('Role #'.$roleId);
        $crumbs[] = '<i class="fas fa-angle-left mx-1 text-muted"></i><span class="badge bg-secondary"><i class="fas fa-user-tag me-1"></i>'.h($roleLabel).'</span>';
    }
    if ($status !== '') {
        $crumbs[] = '<i class="fas fa-angle-left mx-1 text-muted"></i><span class="badge bg-dark"><i class="fas fa-toggle-on me-1"></i>'.h(ucfirst($status)).'</span>';
    }

    $clearUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $countHint = '';

    $html  = '<div class="alert alert-info border-start border-4 border-primary py-2 mb-3 pusams-cluster-banner" role="alert" data-pusams-cluster="1">';
    $html .= '  <div class="d-flex flex-wrap align-items-center gap-1">';
    $html .= '    <i class="fas fa-filter me-2 text-primary"></i>';
    $html .= '    <strong class="me-2">Active filter:</strong>';
    $html .= implode('', $crumbs);
    $html .= $countHint;
    $html .= '    <a href="'.h($clearUrl).'" class="btn btn-sm btn-outline-secondary ms-auto" title="Clear filter">';
    $html .= '      <i class="fas fa-times me-1"></i>Clear';
    $html .= '    </a>';
    $html .= '  </div>';
    $html .= '</div>';

    return $html;
}
}

if (!function_exists('clusterLiveBadge')) {

function clusterLiveBadge(): string {
    return '<span class="badge bg-success ms-2 pusams-live-dot" title="سیستم اوس په Live حالت کې دی - هر ۶۰ ثانیې اپډیټ کېږي">'
         . '<span class="pusams-live-blink"></span> Live</span>';
}
}
