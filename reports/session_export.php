<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
requireLogin();
requirePermission('view_all_reports');
$db=getDB(); $id=(int)($_GET['id']??0);
$rows=$db->prepare("SELECT u.full_name,u.student_id,r.status,r.marked_at,r.mark_method FROM attendance_records r JOIN users u ON u.id=r.student_id WHERE r.session_id=? ORDER BY u.full_name");
$rows->execute([$id]); $rows=$rows->fetchAll();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="session_'.$id.'_'.date('Ymd').'.csv"');
$out=fopen('php://output','w');
fputcsv($out,['Student Name','Student ID','Status','Marked At','Method']);
foreach($rows as $r) fputcsv($out,array_values($r));
fclose($out);
