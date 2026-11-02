<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
requireLogin(); requirePermission('manage_users');
$db=getDB();
$users=$db->query("SELECT u.id,u.username,u.full_name,u.email,u.phone,r.label as role,d.name as department,
    u.employee_id,u.student_id,u.status,u.last_login,u.created_at
    FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN departments d ON d.id=u.department_id
    WHERE u.deleted_at IS NULL ORDER BY u.created_at")->fetchAll();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="users_'.date('Ymd').'.csv"');
$out=fopen('php://output','w');
fputcsv($out,['ID','Username','Full Name','Email','Phone','Role','Department','Employee ID','Student ID','Status','Last Login','Created At']);
foreach($users as $u) fputcsv($out,array_values($u));
fclose($out);
