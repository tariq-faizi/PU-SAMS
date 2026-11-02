<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$type = $_GET['type'] ?? '';
$roleId = (int)($_SESSION['role_id'] ?? 0);
$myFaculty = (int)($_SESSION['faculty_id'] ?? 0);
$myDept    = (int)($_SESSION['department_id'] ?? 0);
$myClass   = (int)($_SESSION['class_id'] ?? 0);

$facultyBoundRoles = [ROLE_DEPT_HEAD, ROLE_ATT_MGR, ROLE_TEACHER, ROLE_STUDENT];
$isFacultyBound = in_array($roleId, $facultyBoundRoles, true);

function jx(array $payload): void { echo json_encode($payload, JSON_UNESCAPED_UNICODE); exit; }

try {
    switch ($type) {
        case 'faculties':
            $items = listFacultiesActive();
            if ($isFacultyBound && $myFaculty) {
                $items = array_values(array_filter($items, fn($f)=>(int)$f['id']===$myFaculty));
            }
            jx([
                'ok'=>true,
                'items'=>$items,
                'scope'=>['role'=>$roleId, 'faculty_bound'=>$isFacultyBound, 'my_faculty'=>$myFaculty],
            ]);

        case 'departments':
            $fid = (int)($_GET['faculty_id'] ?? 0);
            
            if ($isFacultyBound) {
                $fid = $myFaculty ?: $fid;
            }
            jx([
                'ok'=>true,
                'items'=>$fid?listDepartmentsByFaculty($fid):[],
                'scope'=>['faculty_id'=>$fid, 'auto_scoped'=>$isFacultyBound],
            ]);

        case 'classes':
            $did = (int)($_GET['department_id'] ?? 0);
            $fid = (int)($_GET['faculty_id'] ?? 0);
            if ($isFacultyBound) {
                $fid = $myFaculty ?: $fid;
            }
            jx([
                'ok'=>true,
                'items'=>listClassesByDepartment($did, $fid),
                'scope'=>['faculty_id'=>$fid,'department_id'=>$did,'auto_scoped'=>$isFacultyBound],
            ]);

        case 'subjects':
            $cid = (int)($_GET['class_id'] ?? 0);
            $did = (int)($_GET['department_id'] ?? 0);
            $fid = (int)($_GET['faculty_id'] ?? 0);
            $sid = (int)($_GET['semester_id'] ?? 0); 
            if ($isFacultyBound) {
                $fid = $myFaculty ?: $fid;
            }

            

            

            

            

            if ($cid > 0 && $sid > 0) {
                $allowed = function_exists('classAllowedSemesterIds') ? classAllowedSemesterIds($cid) : [];
                if ($allowed && !in_array($sid, $allowed, true)) {

                    
                    $cid = 0;
                }
            }

            
            
            if ($sid > 0) {
                $items = listSubjectsByClassSemester($cid, $sid);
            } elseif ($cid > 0) {
                $items = listSubjectsByClass($cid);
            } elseif ($did > 0) {
                $items = listSubjectsByDepartment($did, $fid ?: null);
            } else {
                $items = [];
            }
            jx([
                'ok'=>true,
                'items'=>$items,
                'scope'=>['class_id'=>$cid,'semester_id'=>$sid,'department_id'=>$did,'faculty_id'=>$fid],
            ]);

        case 'teachers':
            $sid = (int)($_GET['subject_id'] ?? 0);
            jx([
                'ok'=>true,
                'items'=>listTeachersBySubject($sid),
                'scope'=>['subject_id'=>$sid],
            ]);

        case 'semesters':
            $cid = (int)($_GET['class_id'] ?? 0);
            $fid = (int)($_GET['faculty_id'] ?? 0);
            if ($isFacultyBound) {
                $fid = $myFaculty ?: $fid;
            }

            $items = $cid > 0 ? listSemestersByClass($cid) : ($fid ? listSemestersByFaculty($fid) : []);
            jx([
                'ok'=>true,
                'items'=>$items,
                'scope'=>['faculty_id'=>$fid,'class_id'=>$cid,'auto_scoped'=>$isFacultyBound],
            ]);

        default:
            http_response_code(400);
            jx(['ok'=>false,'error'=>'Unknown type. Use faculties|departments|classes|subjects|teachers|semesters']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    jx(['ok'=>false,'error'=>$e->getMessage()]);
}
