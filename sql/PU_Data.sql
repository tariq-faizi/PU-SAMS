-- ════════════════════════════════════════════════════════════════════════════
--  PUSAMS — Paktia University Student Attendance Management System
--  Seed data — د پکتیا پوهنتون رښتیني ډېټا
--  ──────────────────────────────────────────────────────────────────────────
--  پښتو:
--    • د پکتیا پوهنتون ټولې ۹ رښتیني پوهنځۍ، د هرې يو رئیس، څانګې، سمیسترونه
--      او ټولګي.
--    • تفصیلي ډېټا (استادان، محصلان، مضامین، مهالویش، حضور) یوازې د
--      کمپیوټر ساینس پوهنځۍ لپاره — ځکه چې همدا د سیستم رښتیني پایلوټ ډېټا ده.
--    • نورو ۸ پوهنځیو لپاره یوازې د رئیس حساب + جوړښت (څانګې/سمیسترونه/ټولګي).
--  ──────────────────────────────────────────────────────────────────────────
--  د بیا چلولو لپاره خوندي (idempotent — INSERT IGNORE هرچېرته کارول شوي).
--  د ټولو حسابونو پټنوم: Abc@1234
-- ════════════════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1) ROLES
-- =============================================================================
INSERT IGNORE INTO `roles` (`id`,`name`,`label`) VALUES
  (1, 'admin',       'Vice-Chancellor'),
  (2, 'deans',   'Faculty Dean'),
  (3, 'teachers',     'Teacher'),
  (4, 'students',     'Student'),
  (5, 'managers', 'Manager');

-- =============================================================================
-- 2) PERMISSIONS
-- =============================================================================
INSERT IGNORE INTO `permissions` (`role_id`,`permission`,`granted`) VALUES
-- Vice-Chancellor (admin)
 (1,'manage_users',1),(1,'manage_roles',1),(1,'manage_faculties',1),(1,'manage_departments',1),(1,'manage_classes',1),
 (1,'manage_subjects',1),(1,'manage_timetable',1),(1,'approve_requests',1),(1,'manage_backup',1),
 (1,'view_all_reports',1),(1,'manage_settings',1),(1,'manage_policies',1),(1,'view_audit_log',1),(1,'delete_records',1),
 (1,'close_sessions',1),(1,'manage_notifications',1),(1,'view_all_departments',1),(1,'edit_attendance',1),
 (1,'manage_missed_classes',1),(1,'view_university_cluster',1),(1,'manager_edit_attendance',1),
 (1,'manager_bulk_attendance',1),
-- Faculty Dean
 (2,'view_dept_reports',1),(2,'approve_dept_requests',1),(2,'manage_dept_users',1),(2,'manage_departments',1),
 (2,'manage_classes',1),(2,'manage_subjects',1),(2,'view_dept_timetable',1),(2,'view_dept_attendance',1),
 (2,'view_dept_classes',1),(2,'view_notifications',1),(2,'view_all_reports',1),(2,'approve_requests',1),
 (2,'manage_timetable',1),(2,'manage_missed_classes',1),(2,'view_faculty_cluster',1),
 (2,'manager_edit_attendance',1),(2,'manager_bulk_attendance',1),
 (2,'manage_faculty_announcements',1),(2,'announce_to_faculty',1),(2,'view_announcements',1),
-- Teacher
 (3,'create_session',1),(3,'close_sessions',1),(3,'mark_attendance',1),(3,'view_own_reports',1),
 (3,'request_correction',1),(3,'view_notifications',1),(3,'view_own_timetable',1),(3,'record_missed_class',1),
 (3,'view_my_students_attendance',1),
-- Student
 (4,'view_own_attendance',1),(4,'request_correction',1),(4,'view_notifications',1),(4,'view_own_timetable',1),
-- Manager
 (5,'edit_attendance',1),(5,'view_all_attendance',1),(5,'close_sessions',1),(5,'view_notifications',1),
 (5,'view_all_reports',1),(5,'manage_timetable',1),(5,'manage_missed_classes',1),(5,'manage_classes',1),
 (5,'manage_subjects',1),(5,'manage_dept_users',1),(5,'approve_requests',1),
 (5,'view_faculty_cluster',1),(5,'manager_edit_attendance',1),(5,'manager_bulk_attendance',1);

-- =============================================================================
-- 3) UNIVERSITY
-- =============================================================================
INSERT IGNORE INTO `universities` (`id`,`name`,`code`,`is_active`) VALUES
 (1,'Paktia University','PU',1);

-- =============================================================================
-- 4) FACULTIES — د پوهنتون ۹ رښتیني پوهنځۍ
-- =============================================================================
INSERT IGNORE INTO `faculties` (`id`,`university_id`,`name`,`code`,`description`,`is_active`) VALUES
 (1,1,'Computer Science',          'CS', 'د کمپیوټر ساینس پوهنځۍ — ګلاب جان سجاد',                 1),
 (2,1,'Education',                  'EDU','د تعلیم او تربیې پوهنځۍ — ذاهد الرحمن',                   1),
 (3,1,'Agriculture',                'AGR','د زراعت پوهنځۍ — محمد داوود',                              1),
 (4,1,'Law & Political Science',    'LAW','د حقوقو او سیاسي علومو پوهنځۍ — عبد الواحد همت',         1),
 (5,1,'Engineering',                'ENG','د انجنیرۍ پوهنځۍ — انجنیر رحمت الله ابراهیمي',           1),
 (6,1,'Medicine',                   'MED','د طب پوهنځۍ — عبد الله مسلم',                              1),
 (7,1,'Literature',                 'LIT','د ادبیاتو پوهنځۍ — رحیم الله داودزی',                    1),
 (8,1,'Economics',                  'ECO','د اقتصاد پوهنځۍ — شفیع الله غوثي',                        1),
 (9,1,'Sharia',                     'SHA','د شرعیاتو پوهنځۍ — عبد الولي سباوون',                    1);

-- =============================================================================
-- 5) DEPARTMENTS — د هرې پوهنځۍ رښتیني څانګې
--   ranges:
--   • CS (1):           1..2     (2 څانګې)  — IS, CS
--   • Education (2):    3..14    (12 څانګې)
--   • Agriculture (3):  15..20   (6 څانګې)
--   • Law (4):          21..22   (2 څانګې)
--   • Engineering (5):  23..25   (3 څانګې)
--   • Medicine (6):     26..33   (8 څانګې)
--   • Literature (7):   34       (1 څانګه)
--   • Economics (8):    35..37   (3 څانګې)
--   • Sharia (9):       38..42   (5 څانګې)
-- =============================================================================
INSERT IGNORE INTO `departments` (`id`,`faculty_id`,`name`,`code`,`is_active`) VALUES
-- 1) Computer Science (faculty 1) — ۲ څانګې
 ( 1,1,'Information Systems', 'IS', 1),
 ( 2,1,'Network',                 'CSC',1),
-- 2) Education (faculty 2) — ۱۲ څانګې
 ( 3,2,'زبان و ادبیات انگلیسی', 'EDU-ENG',1),
 ( 4,2,'زبان و ادبیات دری',     'EDU-DRI',1),
 ( 5,2,'زبان و ادبیات پشتو',    'EDU-PAS',1),
 ( 6,2,'فزیک',                   'EDU-PHY',1),
 ( 7,2,'بیولوژی',                'EDU-BIO',1),
 ( 8,2,'ریاضی',                  'EDU-MAT',1),
 ( 9,2,'کیمیا',                  'EDU-CHM',1),
 (10,2,'تاریخ',                  'EDU-HIS',1),
 (11,2,'جغرافیه',                'EDU-GEO',1),
 (12,2,'علوم و فرهنگ اسلامی',   'EDU-ISL',1),
 (13,2,'روانشناسی',              'EDU-PSY',1),
 (14,2,'آموزش کمپیوتر',          'EDU-CMP',1),
-- 3) Agriculture (faculty 3) — ۶ څانګې
 (15,3,'هارتیکلچر',              'AGR-HRT',1),
 (16,3,'جنگلات و منابع طبیعی',  'AGR-FRS',1),
 (17,3,'اقتصاد و توسعه زراعتی', 'AGR-ECN',1),
 (18,3,'آبیاری',                 'AGR-IRR',1),
 (19,3,'علوم حیوانی',            'AGR-ANS',1),
 (20,3,'حفظه نباتات',            'AGR-PPR',1),
-- 4) Law & Political Science (faculty 4) — ۲ څانګې
 (21,4,'علوم سیاسی و روابط بین‌الملل','LAW-PSI',1),
 (22,4,'حقوق',                          'LAW-LAW',1),
-- 5) Engineering (faculty 5) — ۳ څانګې
 (23,5,'مهندسی',                 'ENG-GEN',1),
 (24,5,'سیول',                    'ENG-CIV',1),
 (25,5,'انجنیری برق',            'ENG-ELC',1),
-- 6) Medicine (faculty 6) — ۸ څانګې
 (26,6,'جراحی اعصاب',            'MED-NSU',1),
 (27,6,'جراحی',                  'MED-SUR',1),
 (28,6,'داخله',                  'MED-INT',1),
 (29,6,'اناتومی',                'MED-ANT',1),
 (30,6,'داخله اطفال',            'MED-PED',1),
 (31,6,'اوتوزینوس و جودامولوژی','MED-ORL',1),
 (32,6,'پتالوژی',                'MED-PAT',1),
 (33,6,'پاراکلینیک',             'MED-PCL',1),
-- 7) Literature (faculty 7) — ۱ څانګه
 (34,7,'زبان و ادبیات',          'LIT-LNG',1),
-- 8) Economics (faculty 8) — ۳ څانګې
 (35,8,'مطالعات اقتصادی',        'ECO-STD',1),
 (36,8,'مطالعات بازرگانی',       'ECO-BUS',1),
 (37,8,'مطالعات بخش‌های تولیدی','ECO-PRD',1),
-- 9) Sharia (faculty 9) — ۵ څانګې
 (38,9,'فقه',                     'SHA-FQH',1),
 (39,9,'اصول',                    'SHA-USL',1),
 (40,9,'حدیث',                    'SHA-HDS',1),
 (41,9,'تفسیر',                   'SHA-TFR',1),
 (42,9,'عقیده',                   'SHA-AQD',1);

-- =============================================================================
-- 6) SEMESTERS — د هرې پوهنځۍ لپاره ۸ سمیستره
--   id ranges:
--     CS:           1..8     (faculty 1)
--     Education:    9..16    (faculty 2)
--     Agriculture: 17..24    (faculty 3)
--     Law:         25..32    (faculty 4)
--     Engineering: 33..40    (faculty 5)
--     Medicine:    41..48    (faculty 6)
--     Literature:  49..56    (faculty 7)
--     Economics:   57..64    (faculty 8)
--     Sharia:      65..72    (faculty 9)
-- =============================================================================
INSERT IGNORE INTO `semesters` (`id`,`faculty_id`,`name`,`semester_no`,`year_no`,`weeks_count`,`is_active`) VALUES
-- CS
 ( 1,1,'Semester 1',1,1,16,1),( 2,1,'Semester 2',2,1,16,1),
 ( 3,1,'Semester 3',3,2,16,1),( 4,1,'Semester 4',4,2,16,1),
 ( 5,1,'Semester 5',5,3,16,1),( 6,1,'Semester 6',6,3,16,1),
 ( 7,1,'Semester 7',7,4,16,1),( 8,1,'Semester 8',8,4,16,1),
-- Education
 ( 9,2,'Semester 1',1,1,16,1),(10,2,'Semester 2',2,1,16,1),
 (11,2,'Semester 3',3,2,16,1),(12,2,'Semester 4',4,2,16,1),
 (13,2,'Semester 5',5,3,16,1),(14,2,'Semester 6',6,3,16,1),
 (15,2,'Semester 7',7,4,16,1),(16,2,'Semester 8',8,4,16,1),
-- Agriculture
 (17,3,'Semester 1',1,1,16,1),(18,3,'Semester 2',2,1,16,1),
 (19,3,'Semester 3',3,2,16,1),(20,3,'Semester 4',4,2,16,1),
 (21,3,'Semester 5',5,3,16,1),(22,3,'Semester 6',6,3,16,1),
 (23,3,'Semester 7',7,4,16,1),(24,3,'Semester 8',8,4,16,1),
-- Law
 (25,4,'Semester 1',1,1,16,1),(26,4,'Semester 2',2,1,16,1),
 (27,4,'Semester 3',3,2,16,1),(28,4,'Semester 4',4,2,16,1),
 (29,4,'Semester 5',5,3,16,1),(30,4,'Semester 6',6,3,16,1),
 (31,4,'Semester 7',7,4,16,1),(32,4,'Semester 8',8,4,16,1),
-- Engineering
 (33,5,'Semester 1',1,1,16,1),(34,5,'Semester 2',2,1,16,1),
 (35,5,'Semester 3',3,2,16,1),(36,5,'Semester 4',4,2,16,1),
 (37,5,'Semester 5',5,3,16,1),(38,5,'Semester 6',6,3,16,1),
 (39,5,'Semester 7',7,4,16,1),(40,5,'Semester 8',8,4,16,1),
-- Medicine
 (41,6,'Semester 1',1,1,16,1),(42,6,'Semester 2',2,1,16,1),
 (43,6,'Semester 3',3,2,16,1),(44,6,'Semester 4',4,2,16,1),
 (45,6,'Semester 5',5,3,16,1),(46,6,'Semester 6',6,3,16,1),
 (47,6,'Semester 7',7,4,16,1),(48,6,'Semester 8',8,4,16,1),
-- Literature
 (49,7,'Semester 1',1,1,16,1),(50,7,'Semester 2',2,1,16,1),
 (51,7,'Semester 3',3,2,16,1),(52,7,'Semester 4',4,2,16,1),
 (53,7,'Semester 5',5,3,16,1),(54,7,'Semester 6',6,3,16,1),
 (55,7,'Semester 7',7,4,16,1),(56,7,'Semester 8',8,4,16,1),
-- Economics
 (57,8,'Semester 1',1,1,16,1),(58,8,'Semester 2',2,1,16,1),
 (59,8,'Semester 3',3,2,16,1),(60,8,'Semester 4',4,2,16,1),
 (61,8,'Semester 5',5,3,16,1),(62,8,'Semester 6',6,3,16,1),
 (63,8,'Semester 7',7,4,16,1),(64,8,'Semester 8',8,4,16,1),
-- Sharia
 (65,9,'Semester 1',1,1,16,1),(66,9,'Semester 2',2,1,16,1),
 (67,9,'Semester 3',3,2,16,1),(68,9,'Semester 4',4,2,16,1),
 (69,9,'Semester 5',5,3,16,1),(70,9,'Semester 6',6,3,16,1),
 (71,9,'Semester 7',7,4,16,1),(72,9,'Semester 8',8,4,16,1);

-- =============================================================================
-- 7) ROOMS — د CS پوهنځۍ رښتیني صنفونه
-- =============================================================================
INSERT IGNORE INTO `rooms` (`id`,`faculty_id`,`name`,`building`,`capacity`,`room_type`,`is_active`) VALUES
 (1,1,'CS-101','CS Block',60,'classroom',1),
 (2,1,'CS-102','CS Block',60,'classroom',1),
 (4,1,'CS-201','CS Block',45,'classroom',1),
 (5,1,'CS-Seminar','CS Block',30,'hall', 1);

-- =============================================================================
-- 8) CLASSES — د هرې څانګې لپاره ۴ ټولګي (لومړی → څلورم صنف)
--   تړاو: Class 1→Sem1, Class 2→Sem3, Class 3→Sem5, Class 4→Sem7
-- =============================================================================
INSERT IGNORE INTO `classes`
 (`id`,`faculty_id`,`department_id`,`semester_id`,`name`,`section`,`capacity`,`class_type`,`is_active`) VALUES
-- CS / IS (dept 1) → 1..4
 ( 1,1,1,1,'IS_Class_1','A',50,'department',1),
 ( 2,1,1,3,'IS_Class_2','A',50,'department',1),
 ( 3,1,1,5,'IS_Class_3','A',50,'department',1),
 ( 4,1,1,7,'IS_Class_4','A',50,'department',1),
-- CS / Computer Science (dept 2) → 5..8
 ( 5,1,2,1,'NET_Class_1','A',50,'department',1),
 ( 6,1,2,3,'NET_Class_2','A',50,'department',1),
 ( 7,1,2,5,'NET_Class_3','A',50,'department',1),
 ( 8,1,2,7,'NET_Class_4','A',50,'department',1),

-- Education (faculty 2) — ۱۲ × ۴ = ۴۸ ټولګي (id 9..56)
 ( 9,2, 3, 9,'EDU-ENG_Class_1','A',50,'department',1),
 (10,2, 3,11,'EDU-ENG_Class_2','A',50,'department',1),
 (11,2, 3,13,'EDU-ENG_Class_3','A',50,'department',1),
 (12,2, 3,15,'EDU-ENG_Class_4','A',50,'department',1),
 (13,2, 4, 9,'EDU-DRI_Class_1','A',50,'department',1),
 (14,2, 4,11,'EDU-DRI_Class_2','A',50,'department',1),
 (15,2, 4,13,'EDU-DRI_Class_3','A',50,'department',1),
 (16,2, 4,15,'EDU-DRI_Class_4','A',50,'department',1),
 (17,2, 5, 9,'EDU-PAS_Class_1','A',50,'department',1),
 (18,2, 5,11,'EDU-PAS_Class_2','A',50,'department',1),
 (19,2, 5,13,'EDU-PAS_Class_3','A',50,'department',1),
 (20,2, 5,15,'EDU-PAS_Class_4','A',50,'department',1),
 (21,2, 6, 9,'EDU-PHY_Class_1','A',50,'department',1),
 (22,2, 6,11,'EDU-PHY_Class_2','A',50,'department',1),
 (23,2, 6,13,'EDU-PHY_Class_3','A',50,'department',1),
 (24,2, 6,15,'EDU-PHY_Class_4','A',50,'department',1),
 (25,2, 7, 9,'EDU-BIO_Class_1','A',50,'department',1),
 (26,2, 7,11,'EDU-BIO_Class_2','A',50,'department',1),
 (27,2, 7,13,'EDU-BIO_Class_3','A',50,'department',1),
 (28,2, 7,15,'EDU-BIO_Class_4','A',50,'department',1),
 (29,2, 8, 9,'EDU-MAT_Class_1','A',50,'department',1),
 (30,2, 8,11,'EDU-MAT_Class_2','A',50,'department',1),
 (31,2, 8,13,'EDU-MAT_Class_3','A',50,'department',1),
 (32,2, 8,15,'EDU-MAT_Class_4','A',50,'department',1),
 (33,2, 9, 9,'EDU-CHM_Class_1','A',50,'department',1),
 (34,2, 9,11,'EDU-CHM_Class_2','A',50,'department',1),
 (35,2, 9,13,'EDU-CHM_Class_3','A',50,'department',1),
 (36,2, 9,15,'EDU-CHM_Class_4','A',50,'department',1),
 (37,2,10, 9,'EDU-HIS_Class_1','A',50,'department',1),
 (38,2,10,11,'EDU-HIS_Class_2','A',50,'department',1),
 (39,2,10,13,'EDU-HIS_Class_3','A',50,'department',1),
 (40,2,10,15,'EDU-HIS_Class_4','A',50,'department',1),
 (41,2,11, 9,'EDU-GEO_Class_1','A',50,'department',1),
 (42,2,11,11,'EDU-GEO_Class_2','A',50,'department',1),
 (43,2,11,13,'EDU-GEO_Class_3','A',50,'department',1),
 (44,2,11,15,'EDU-GEO_Class_4','A',50,'department',1),
 (45,2,12, 9,'EDU-ISL_Class_1','A',50,'department',1),
 (46,2,12,11,'EDU-ISL_Class_2','A',50,'department',1),
 (47,2,12,13,'EDU-ISL_Class_3','A',50,'department',1),
 (48,2,12,15,'EDU-ISL_Class_4','A',50,'department',1),
 (49,2,13, 9,'EDU-PSY_Class_1','A',50,'department',1),
 (50,2,13,11,'EDU-PSY_Class_2','A',50,'department',1),
 (51,2,13,13,'EDU-PSY_Class_3','A',50,'department',1),
 (52,2,13,15,'EDU-PSY_Class_4','A',50,'department',1),
 (53,2,14, 9,'EDU-CMP_Class_1','A',50,'department',1),
 (54,2,14,11,'EDU-CMP_Class_2','A',50,'department',1),
 (55,2,14,13,'EDU-CMP_Class_3','A',50,'department',1),
 (56,2,14,15,'EDU-CMP_Class_4','A',50,'department',1),

-- Agriculture (faculty 3) — ۶ × ۴ = ۲۴ ټولګي (id 57..80)
 (57,3,15,17,'AGR-HRT_Class_1','A',50,'department',1),
 (58,3,15,19,'AGR-HRT_Class_2','A',50,'department',1),
 (59,3,15,21,'AGR-HRT_Class_3','A',50,'department',1),
 (60,3,15,23,'AGR-HRT_Class_4','A',50,'department',1),
 (61,3,16,17,'AGR-FRS_Class_1','A',50,'department',1),
 (62,3,16,19,'AGR-FRS_Class_2','A',50,'department',1),
 (63,3,16,21,'AGR-FRS_Class_3','A',50,'department',1),
 (64,3,16,23,'AGR-FRS_Class_4','A',50,'department',1),
 (65,3,17,17,'AGR-ECN_Class_1','A',50,'department',1),
 (66,3,17,19,'AGR-ECN_Class_2','A',50,'department',1),
 (67,3,17,21,'AGR-ECN_Class_3','A',50,'department',1),
 (68,3,17,23,'AGR-ECN_Class_4','A',50,'department',1),
 (69,3,18,17,'AGR-IRR_Class_1','A',50,'department',1),
 (70,3,18,19,'AGR-IRR_Class_2','A',50,'department',1),
 (71,3,18,21,'AGR-IRR_Class_3','A',50,'department',1),
 (72,3,18,23,'AGR-IRR_Class_4','A',50,'department',1),
 (73,3,19,17,'AGR-ANS_Class_1','A',50,'department',1),
 (74,3,19,19,'AGR-ANS_Class_2','A',50,'department',1),
 (75,3,19,21,'AGR-ANS_Class_3','A',50,'department',1),
 (76,3,19,23,'AGR-ANS_Class_4','A',50,'department',1),
 (77,3,20,17,'AGR-PPR_Class_1','A',50,'department',1),
 (78,3,20,19,'AGR-PPR_Class_2','A',50,'department',1),
 (79,3,20,21,'AGR-PPR_Class_3','A',50,'department',1),
 (80,3,20,23,'AGR-PPR_Class_4','A',50,'department',1),

-- Law (faculty 4) — ۲ × ۴ = ۸ ټولګي (id 81..88)
 (81,4,21,25,'LAW-PSI_Class_1','A',55,'department',1),
 (82,4,21,27,'LAW-PSI_Class_2','A',55,'department',1),
 (83,4,21,29,'LAW-PSI_Class_3','A',55,'department',1),
 (84,4,21,31,'LAW-PSI_Class_4','A',55,'department',1),
 (85,4,22,25,'LAW-LAW_Class_1','A',55,'department',1),
 (86,4,22,27,'LAW-LAW_Class_2','A',55,'department',1),
 (87,4,22,29,'LAW-LAW_Class_3','A',55,'department',1),
 (88,4,22,31,'LAW-LAW_Class_4','A',55,'department',1),

-- Engineering (faculty 5) — ۳ × ۴ = ۱۲ ټولګي (id 89..100)
 (89,5,23,33,'ENG-GEN_Class_1','A',50,'department',1),
 (90,5,23,35,'ENG-GEN_Class_2','A',50,'department',1),
 (91,5,23,37,'ENG-GEN_Class_3','A',50,'department',1),
 (92,5,23,39,'ENG-GEN_Class_4','A',50,'department',1),
 (93,5,24,33,'ENG-CIV_Class_1','A',50,'department',1),
 (94,5,24,35,'ENG-CIV_Class_2','A',50,'department',1),
 (95,5,24,37,'ENG-CIV_Class_3','A',50,'department',1),
 (96,5,24,39,'ENG-CIV_Class_4','A',50,'department',1),
 (97,5,25,33,'ENG-ELC_Class_1','A',50,'department',1),
 (98,5,25,35,'ENG-ELC_Class_2','A',50,'department',1),
 (99,5,25,37,'ENG-ELC_Class_3','A',50,'department',1),
(100,5,25,39,'ENG-ELC_Class_4','A',50,'department',1),

-- Medicine (faculty 6) — ۸ × ۴ = ۳۲ ټولګي (id 101..132)
(101,6,26,41,'MED-NSU_Class_1','A',45,'department',1),
(102,6,26,43,'MED-NSU_Class_2','A',45,'department',1),
(103,6,26,45,'MED-NSU_Class_3','A',45,'department',1),
(104,6,26,47,'MED-NSU_Class_4','A',45,'department',1),
(105,6,27,41,'MED-SUR_Class_1','A',45,'department',1),
(106,6,27,43,'MED-SUR_Class_2','A',45,'department',1),
(107,6,27,45,'MED-SUR_Class_3','A',45,'department',1),
(108,6,27,47,'MED-SUR_Class_4','A',45,'department',1),
(109,6,28,41,'MED-INT_Class_1','A',45,'department',1),
(110,6,28,43,'MED-INT_Class_2','A',45,'department',1),
(111,6,28,45,'MED-INT_Class_3','A',45,'department',1),
(112,6,28,47,'MED-INT_Class_4','A',45,'department',1),
(113,6,29,41,'MED-ANT_Class_1','A',45,'department',1),
(114,6,29,43,'MED-ANT_Class_2','A',45,'department',1),
(115,6,29,45,'MED-ANT_Class_3','A',45,'department',1),
(116,6,29,47,'MED-ANT_Class_4','A',45,'department',1),
(117,6,30,41,'MED-PED_Class_1','A',45,'department',1),
(118,6,30,43,'MED-PED_Class_2','A',45,'department',1),
(119,6,30,45,'MED-PED_Class_3','A',45,'department',1),
(120,6,30,47,'MED-PED_Class_4','A',45,'department',1),
(121,6,31,41,'MED-ORL_Class_1','A',45,'department',1),
(122,6,31,43,'MED-ORL_Class_2','A',45,'department',1),
(123,6,31,45,'MED-ORL_Class_3','A',45,'department',1),
(124,6,31,47,'MED-ORL_Class_4','A',45,'department',1),
(125,6,32,41,'MED-PAT_Class_1','A',45,'department',1),
(126,6,32,43,'MED-PAT_Class_2','A',45,'department',1),
(127,6,32,45,'MED-PAT_Class_3','A',45,'department',1),
(128,6,32,47,'MED-PAT_Class_4','A',45,'department',1),
(129,6,33,41,'MED-PCL_Class_1','A',45,'department',1),
(130,6,33,43,'MED-PCL_Class_2','A',45,'department',1),
(131,6,33,45,'MED-PCL_Class_3','A',45,'department',1),
(132,6,33,47,'MED-PCL_Class_4','A',45,'department',1),

-- Literature (faculty 7) — ۱ × ۴ = ۴ ټولګي (id 133..136)
(133,7,34,49,'LIT-LNG_Class_1','A',50,'department',1),
(134,7,34,51,'LIT-LNG_Class_2','A',50,'department',1),
(135,7,34,53,'LIT-LNG_Class_3','A',50,'department',1),
(136,7,34,55,'LIT-LNG_Class_4','A',50,'department',1),

-- Economics (faculty 8) — ۳ × ۴ = ۱۲ ټولګي (id 137..148)
(137,8,35,57,'ECO-STD_Class_1','A',55,'department',1),
(138,8,35,59,'ECO-STD_Class_2','A',55,'department',1),
(139,8,35,61,'ECO-STD_Class_3','A',55,'department',1),
(140,8,35,63,'ECO-STD_Class_4','A',55,'department',1),
(141,8,36,57,'ECO-BUS_Class_1','A',55,'department',1),
(142,8,36,59,'ECO-BUS_Class_2','A',55,'department',1),
(143,8,36,61,'ECO-BUS_Class_3','A',55,'department',1),
(144,8,36,63,'ECO-BUS_Class_4','A',55,'department',1),
(145,8,37,57,'ECO-PRD_Class_1','A',55,'department',1),
(146,8,37,59,'ECO-PRD_Class_2','A',55,'department',1),
(147,8,37,61,'ECO-PRD_Class_3','A',55,'department',1),
(148,8,37,63,'ECO-PRD_Class_4','A',55,'department',1),

-- Sharia (faculty 9) — ۵ × ۴ = ۲۰ ټولګي (id 149..168)
(149,9,38,65,'SHA-FQH_Class_1','A',55,'department',1),
(150,9,38,67,'SHA-FQH_Class_2','A',55,'department',1),
(151,9,38,69,'SHA-FQH_Class_3','A',55,'department',1),
(152,9,38,71,'SHA-FQH_Class_4','A',55,'department',1),
(153,9,39,65,'SHA-USL_Class_1','A',55,'department',1),
(154,9,39,67,'SHA-USL_Class_2','A',55,'department',1),
(155,9,39,69,'SHA-USL_Class_3','A',55,'department',1),
(156,9,39,71,'SHA-USL_Class_4','A',55,'department',1),
(157,9,40,65,'SHA-HDS_Class_1','A',55,'department',1),
(158,9,40,67,'SHA-HDS_Class_2','A',55,'department',1),
(159,9,40,69,'SHA-HDS_Class_3','A',55,'department',1),
(160,9,40,71,'SHA-HDS_Class_4','A',55,'department',1),
(161,9,41,65,'SHA-TFR_Class_1','A',55,'department',1),
(162,9,41,67,'SHA-TFR_Class_2','A',55,'department',1),
(163,9,41,69,'SHA-TFR_Class_3','A',55,'department',1),
(164,9,41,71,'SHA-TFR_Class_4','A',55,'department',1),
(165,9,42,65,'SHA-AQD_Class_1','A',55,'department',1),
(166,9,42,67,'SHA-AQD_Class_2','A',55,'department',1),
(167,9,42,69,'SHA-AQD_Class_3','A',55,'department',1),
(168,9,42,71,'SHA-AQD_Class_4','A',55,'department',1);

-- =============================================================================
-- 9) USERS  (د ټولو حسابونو پټنوم: Abc@1234)
-- =============================================================================
SET @pw = '$2y$12$k6.zKCGLtGN8iKxar8fGJeJAalXaBhCkxwesQPa0vi6GQ3j8EVWvu';

-- 9.1 معاون د محصلانو (Vice-Chancellor)
INSERT IGNORE INTO `users`
 (`id`,`username`,`password_hash`,`role_id`,`faculty_id`,`department_id`,`class_id`,`semester_id`,
  `full_name`,`email`,`student_id`,`employee_id`,`status`) VALUES
 (1,'vc.khpelwak', @pw, 1, NULL, NULL, NULL, NULL,
  'سیف الرحمن خپلواک — معاون د محصلانو',
  'vc@paktiauniversity.edu.af', NULL, 'EMP001','active'),

-- 9.2 د کمپیوټر ساینس پوهنځۍ — رئیس + مدیر تدریسی
 (2,'cs.dean',    @pw, 2, 1, 1, NULL, NULL,
  'ګلاب جان سجاد — د کمپیوټر ساینس پوهنځۍ رئیس',
  'cs.dean@paktiauniversity.edu.af', NULL, 'EMP002','active'),
 (3,'cs.manager', @pw, 5, 1, 1, NULL, NULL,
  'مدیر تدریسی — د کمپیوټر ساینس پوهنځۍ',
  'cs.manager@paktiauniversity.edu.af', NULL, 'EMP003','active'),

-- 9.3 د IS څانګې استادان (6) — ID 4..9
 (4,'is.nazari',    @pw, 3, 1, 1, NULL, NULL, 'استاد نظری',           'nazari@paktiauniversity.edu.af',     NULL,'EMP004','active'),
 (5,'is.alikhel',   @pw, 3, 1, 1, NULL, NULL, 'استاد علي خیل',        'alikhel@paktiauniversity.edu.af',    NULL,'EMP005','active'),
 (6,'is.mujibur',   @pw, 3, 1, 1, NULL, NULL, 'استاد مجیب الرحمن',    'mujibur@paktiauniversity.edu.af',    NULL,'EMP006','active'),
 (7,'is.zadran',    @pw, 3, 1, 1, NULL, NULL, 'استاد ځدران',          'zadran@paktiauniversity.edu.af',     NULL,'EMP007','active'),
 (8,'is.himmat',    @pw, 3, 1, 1, NULL, NULL, 'استاد همت',            'himmat@paktiauniversity.edu.af',     NULL,'EMP008','active'),
 (9,'is.mutaqi',    @pw, 3, 1, 1, NULL, NULL, 'استاد متقي',           'mutaqi@paktiauniversity.edu.af',     NULL,'EMP009','active'),

-- 9.4 د کمپیوټر ساینس څانګې استادان (4) — ID 10..13
 (10,'ne.wafa',    @pw, 3, 1, 2, NULL, NULL, 'استاد نور آغا وفا',    'wafa@paktiauniversity.edu.af',       NULL,'EMP010','active'),
 (11,'ne.hashimi', @pw, 3, 1, 2, NULL, NULL, 'استاد هاشمي',          'hashimi@paktiauniversity.edu.af',    NULL,'EMP011','active'),
 (12,'ne.bilal',   @pw, 3, 1, 2, NULL, NULL, 'استاد بلال',           'bilal@paktiauniversity.edu.af',      NULL,'EMP012','active'),
 (13,'ne.haqmal',  @pw, 3, 1, 2, NULL, NULL, 'استاد حقمل',           'haqmal@paktiauniversity.edu.af',     NULL,'EMP013','active'),

-- 9.5 د IS څلورم صنف محصلان (12) — ID 14..25
--      class_id = 4 (IS_Class_4)، semester_id = 8 (اتم سمیستر)
 (14,'is4.tariq',     @pw, 4, 1, 1, 4, 8, 'طارق شاداب',          'is4.tariq@paktiauniversity.edu.af',     'STU-IS4-01', NULL,'active'),
 (15,'is4.wahid',     @pw, 4, 1, 1, 4, 8, 'وحیدالله صابر',      'is4.wahid@paktiauniversity.edu.af',     'STU-IS4-02', NULL,'active'),
 (16,'is4.qismat',    @pw, 4, 1, 1, 4, 8, 'قسمت الله',           'is4.qismat@paktiauniversity.edu.af',    'STU-IS4-03', NULL,'active'),
 (17,'is4.atiq',      @pw, 4, 1, 1, 4, 8, 'عتیق الله بریال',     'is4.atiq@paktiauniversity.edu.af',      'STU-IS4-04', NULL,'active'),
 (18,'is4.arif',      @pw, 4, 1, 1, 4, 8, 'عارف ځلاند',          'is4.arif@paktiauniversity.edu.af',      'STU-IS4-05', NULL,'active'),
 (19,'is4.shafi',     @pw, 4, 1, 1, 4, 8, 'شفیع الله',           'is4.shafi@paktiauniversity.edu.af',     'STU-IS4-06', NULL,'active'),
 (20,'is4.imran',     @pw, 4, 1, 1, 4, 8, 'عمران خان علي خیل',   'is4.imran@paktiauniversity.edu.af',     'STU-IS4-07', NULL,'active'),
 (21,'is4.najib',     @pw, 4, 1, 1, 4, 8, 'نجیب الله وفادار',    'is4.najib@paktiauniversity.edu.af',     'STU-IS4-08', NULL,'active'),
 (22,'is4.shakoor',   @pw, 4, 1, 1, 4, 8, 'شکورالله',            'is4.shakoor@paktiauniversity.edu.af',   'STU-IS4-09', NULL,'active'),
 (23,'is4.imdad',     @pw, 4, 1, 1, 4, 8, 'امداد الله',          'is4.imdad@paktiauniversity.edu.af',     'STU-IS4-10', NULL,'active'),
 (24,'is4.faheem',    @pw, 4, 1, 1, 4, 8, 'فهیم',                'is4.faheem@paktiauniversity.edu.af',    'STU-IS4-11', NULL,'active'),
 (25,'is4.israr',     @pw, 4, 1, 1, 4, 8, 'اسرار',               'is4.israr@paktiauniversity.edu.af',     'STU-IS4-12', NULL,'active'),

-- 9.6 د کمپیوټر ساینس څانګې محصلان (6) — ID 26..31
--      class_id = 8 (CS_Class_4)، semester_id = 8 (اتم سمیستر)
 (26,'ne4.jamal',    @pw, 4, 1, 2, 8, 8, 'جمال جوهر',            'cs4.jamal@paktiauniversity.edu.af',    'STU-CS4-01', NULL,'active'),
 (27,'ne4.ishq',     @pw, 4, 1, 2, 8, 8, 'عشیق الله',            'cs4.ishq@paktiauniversity.edu.af',     'STU-CS4-02', NULL,'active'),
 (28,'ne4.hanif',    @pw, 4, 1, 2, 8, 8, 'حنیف الله',            'cs4.hanif@paktiauniversity.edu.af',    'STU-CS4-03', NULL,'active'),
 (29,'net4.noor',     @pw, 4, 1, 2, 8, 8, 'نورالله',              'cs4.noor@paktiauniversity.edu.af',     'STU-CS4-04', NULL,'active'),
 (30,'ne4.hamid',    @pw, 4, 1, 2, 8, 8, 'حمیدالله وردګ',        'cs4.hamid@paktiauniversity.edu.af',    'STU-CS4-05', NULL,'active'),
 (31,'ne4.asad',     @pw, 4, 1, 2, 8, 8, 'اسدالله',              'cs4.asad@paktiauniversity.edu.af',     'STU-CS4-06', NULL,'active'),

-- 9.7 د نورو ۸ پوهنځیو ریسان (Faculty Deans) — ID 40..47
-- Education
 (40,'edu.dean', @pw, 2, 2, NULL, NULL, NULL, 'ذاهد الرحمن — د تعلیم او تربیې پوهنځۍ رئیس',
  'edu.dean@paktiauniversity.edu.af', NULL,'EMP040','active'),
-- Agriculture
 (41,'agr.dean', @pw, 2, 3, NULL, NULL, NULL, 'محمد داوود — د زراعت پوهنځۍ رئیس',
  'agr.dean@paktiauniversity.edu.af', NULL,'EMP041','active'),
-- Law & Political Science
 (42,'law.dean', @pw, 2, 4, NULL, NULL, NULL, 'عبد الواحد همت — د حقوقو او سیاسي علومو پوهنځۍ رئیس',
  'law.dean@paktiauniversity.edu.af', NULL,'EMP042','active'),
-- Engineering
 (43,'eng.dean', @pw, 2, 5, NULL, NULL, NULL, 'انجنیر رحمت الله ابراهیمي — د انجنیرۍ پوهنځۍ رئیس',
  'eng.dean@paktiauniversity.edu.af', NULL,'EMP043','active'),
-- Medicine
 (44,'med.dean', @pw, 2, 6, NULL, NULL, NULL, 'عبد الله مسلم — د طب پوهنځۍ رئیس',
  'med.dean@paktiauniversity.edu.af', NULL,'EMP044','active'),
-- Literature
 (45,'lit.dean', @pw, 2, 7, NULL, NULL, NULL, 'رحیم الله داودزی — د ادبیاتو پوهنځۍ رئیس',
  'lit.dean@paktiauniversity.edu.af', NULL,'EMP045','active'),
-- Economics
 (46,'eco.dean', @pw, 2, 8, NULL, NULL, NULL, 'شفیع الله غوثي — د اقتصاد پوهنځۍ رئیس',
  'eco.dean@paktiauniversity.edu.af', NULL,'EMP046','active'),
-- Sharia
 (47,'sha.dean', @pw, 2, 9, NULL, NULL, NULL, 'عبد الولي سباوون — د شرعیاتو پوهنځۍ رئیس',
  'sha.dean@paktiauniversity.edu.af', NULL,'EMP047','active');

-- =============================================================================
INSERT IGNORE INTO `subjects`
 (`id`,`faculty_id`,`department_id`,`semester_id`,`name`,`code`,`default_weekly_credits`,`is_active`) VALUES
 (1,1,1,8,'DBMS',  'IS-401',3,1),
 (2,1,1,8,'پروژه او سمینار ', 'IS-402',4,1),
 (3,1,1,8,'عملي کار ',           'IS-403',4,1),
 (4,1,1,8,'اسلامي تمدن',      'IS-404',2,1),
 (5,1,1,8,'Data Mining',                         'IS-405',3,1),
 (6,1,1,8,'اداره',                  'IS-406',2,1),
 (7,1,1,8,'علمي نګارش',     'IS-407',2,1);

-- =============================================================================
-- 11) TEACHER ↔ SUBJECT ASSIGNMENTS
-- =============================================================================
INSERT IGNORE INTO `teacher_subject_assignments`
 (`teacher_id`,`subject_id`,`faculty_id`,`department_id`,`is_active`) VALUES
-- DBMS → علي خیل
 (5, 1, 1, 1, 1),
-- پروژه او سمینار → علي خیل
 (5, 2, 1, 1, 1),
-- عملي کار → مجیب الرحمن + علي خیل (شریک)
 (6, 3, 1, 1, 1),
 (5, 3, 1, 1, 1),
-- ثقافت اسلامي → متقي
 (9, 4, 1, 1, 1),
-- Data Mining → مجیب الرحمن
 (6, 5, 1, 1, 1),
-- اداره → همت
 (8, 6, 1, 1, 1),
-- علمي نګارش → نظري
 (4, 7, 1, 1, 1);

-- =============================================================================
-- 12) TIMETABLE — د IS څلورم صنف د یوې اونۍ رښتیني مهالویش
--   Sat=6, Sun=0, Mon=1, Tue=2, Wed=3, Thu=4, Fri=5
-- =============================================================================
INSERT IGNORE INTO `timetable`
 (`id`,`faculty_id`,`department_id`,`semester_id`,`class_id`,`subject_id`,`teacher_id`,`room_id`,`room`,
  `day_of_week`,`start_time`,`end_time`,`credit_units`,`status`,`approved_by`,`notes`) VALUES
 ( 1,1,1,8,4, 1,5,1,'CS-101',0,'10:00:00','12:00:00',2,'approved',2,'DBMS — علي خیل — یکشنبه'),
 ( 2,1,1,8,4, 2,5,5,'CS-Seminar',4,'08:00:00','10:00:00',2,'approved',2,'پروژه او سمینار — علي خیل — پنجشنبه'),
 ( 3,1,1,8,4, 3,6,1,'CS-101',1,'10:00:00','12:00:00',2,'approved',2,'عملي کار — مجیب الرحمن — دوشنبه'),
 ( 4,1,1,8,4, 3,5,1,'CS-101',2,'08:00:00','10:00:00',2,'approved',2,'عملي کار — علي خیل — سه‌شنبه'),
 ( 5,1,1,8,4, 4,9,2,'CS-102',3,'08:00:00','10:00:00',2,'approved',2,'اسلامي تمدن — استاد متقي — چهارشنبه'),
 ( 6,1,1,8,4, 5,6,1,'CS-101',0,'08:00:00','10:00:00',2,'approved',2,'Data Mining — استاد مجیب الرحمن — یکشنبه'),
 ( 7,1,1,8,4, 6,8,2,'CS-102',2,'10:00:00','12:00:00',2,'approved',2,'اداره — استاد همت — سه‌شنبه'),
 ( 8,1,1,8,4, 7,4,1,'CS-101',6,'08:00:00','10:00:00',2,'approved',2,'علمي نګارش — استاد نظري — شنبه');
-- =============================================================================
-- 17) SETTINGS & POLICY
-- =============================================================================
INSERT INTO `settings_and_policy` (`key`,`value`,`label`,`group`) VALUES
 -- General
 ('app_name',            'PUSAMS',            'System name',                           'general'),
 ('institution_name',    'Paktia University Students Attendance management System', 'Institution Name',                      'general'),
 ('default_language',    'en',                'Default Language',                      'general'),
 -- Security
 ('session_timeout',     '30',                'Session Timeout (min)',                 'security'),
 ('max_login_attempts',  '3',                 'Max Login Attempts',                    'security'),
 ('lockout_seconds',     '60',                'Lockout duration (seconds)',            'security'),
 -- Academic
 ('semester_weeks',      '16',                'Semester Weeks',                        'academic'),
 ('semester_start_date', '',                  'Semester Start Date',                   'academic'),
 ('semester_backfill_last_date','2026-07-10', 'Semester Backfill Last Date','academic')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `label`=VALUES(`label`), `group`=VALUES(`group`);

-- =============================================================================
-- 18) BACKUP SCHEDULE
-- =============================================================================
INSERT IGNORE INTO `backup_schedule` (`id`,`frequency`,`time_of_day`,`is_enabled`) VALUES
 (1,'daily','02:00:00',0);

-- =============================================================================
-- 19) Welcome notification for VC
-- =============================================================================
INSERT IGNORE INTO `notifications` (`user_id`,`title`,`message`,`type`,`link`)
SELECT id,
       '🎓 PUSAMS ته ښه راغلاست',
       'سیستم بریالیتوب سره نصب شوی. د ننوتلو لپاره خپل حساب وکاروئ.',
       'success',
       NULL
  FROM users WHERE role_id=1 AND status='active' AND deleted_at IS NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════════════════════════════════════════
-- پای — End of seed
-- ────────────────────────────────────────────────────────────────────────────
-- د ټولو حسابونو پټنوم: Abc@1234
--
-- مهم حسابونه:
--   • معاون د محصلانو (VC):                vc.khpelwak   / Abc@1234
--   • د CS پوهنځۍ رئیس (ګلاب جان سجاد):    cs.dean       / Abc@1234
--   • د CS مدیر تدریسی:                    cs.manager    / Abc@1234
--   • IS استاد علي خیل:                    is.alikhel    / Abc@1234
--   • IS استاد نظري:                       is.nazari     / Abc@1234
--   • IS استاد مجیب الرحمن:                is.mujibur    / Abc@1234
--   • د IS څلورم صنف محصل (طارق شاداب):    is4.tariq     / Abc@1234
--   • د CS څانګې محصل (جمال جوهر):         cs4.jamal     / Abc@1234
--
-- د نورو ۸ پوهنځیو ریسان (یوازې Dean account):
--   • تعلیم او تربیه (ذاهد الرحمن):           edu.dean      / Abc@1234
--   • زراعت (محمد داوود):                     agr.dean      / Abc@1234
--   • حقوق او سیاسي علوم (عبد الواحد همت):    law.dean      / Abc@1234
--   • انجنیري (انجنیر رحمت الله ابراهیمي):    eng.dean      / Abc@1234
--   • طب (عبد الله مسلم):                     med.dean      / Abc@1234
--   • ادبیات (رحیم الله داودزی):              lit.dean      / Abc@1234
--   • اقتصاد (شفیع الله غوثي):                 eco.dean      / Abc@1234
--   • شرعیات (عبد الولي سباوون):              sha.dean      / Abc@1234
-- ════════════════════════════════════════════════════════════════════════════

-- ════════════════════════════════════════════════════════════════════════════
-- FIX v25 — د نورو ۸ پوهنځیو لپاره بشپړ ډیټا
--   • د هرې پوهنځۍ لپاره: مدیر تدریسی (1) + استادان (3) + محصلان (6)
--   • د هرې پوهنځۍ لپاره: مضامین (5) + مهالویش (5) + teacher assignments
--   • ټولو حسابونو پټنوم: Abc@1234
-- ════════════════════════════════════════════════════════════════════════════

SET @pw = '$2y$12$k6.zKCGLtGN8iKxar8fGJeJAalXaBhCkxwesQPa0vi6GQ3j8EVWvu';

-- ─────────────────────────────────────────────────────────────────────────────
-- ROOMS — د نورو پوهنځیو صنفونه (id 10+)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `rooms` (`id`,`faculty_id`,`name`,`building`,`capacity`,`room_type`,`is_active`) VALUES
(10,2,'EDU-101','Education Block',55,'classroom',1),
(11,2,'EDU-102','Education Block',55,'classroom',1),
(12,3,'AGR-101','Agriculture Block',50,'classroom',1),
(13,3,'AGR-Lab','Agriculture Block',30,'lab',1),
(14,4,'LAW-101','Law Block',60,'classroom',1),
(15,4,'LAW-102','Law Block',60,'classroom',1),
(16,5,'ENG-101','Engineering Block',50,'classroom',1),
(17,5,'ENG-Lab','Engineering Block',30,'lab',1),
(18,6,'MED-101','Medicine Block',45,'classroom',1),
(19,6,'MED-Lab','Medicine Block',25,'lab',1),
(20,7,'LIT-101','Literature Block',55,'classroom',1),
(21,7,'LIT-102','Literature Block',55,'classroom',1),
(22,8,'ECO-101','Economics Block',55,'classroom',1),
(23,8,'ECO-102','Economics Block',55,'classroom',1),
(24,9,'SHA-101','Sharia Block',60,'classroom',1),
(25,9,'SHA-102','Sharia Block',60,'classroom',1);

-- ─────────────────────────────────────────────────────────────────────────────
-- USERS — د نورو ۸ پوهنځیو مدیران، استادان، محصلان
-- IDs: 50..169
--   Education (2):  50..69   — 1 manager + 3 teachers + 6 students (×2 depts = dept 3,4)
--   Agriculture(3): 70..84   — 1 manager + 3 teachers + 6 students
--   Law (4):        85..99   — 1 manager + 3 teachers + 6 students
--   Engineering(5): 100..114 — 1 manager + 3 teachers + 6 students
--   Medicine(6):    115..129 — 1 manager + 3 teachers + 6 students
--   Literature(7):  130..139 — 1 manager + 2 teachers + 5 students
--   Economics(8):   140..154 — 1 manager + 3 teachers + 6 students
--   Sharia(9):      155..169 — 1 manager + 3 teachers + 6 students
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `users`
  (`id`,`username`,`password_hash`,`role_id`,`faculty_id`,`department_id`,`class_id`,`semester_id`,
   `full_name`,`email`,`student_id`,`employee_id`,`status`)
VALUES

-- ════ Education (faculty 2, dept 3=EDU-ENG, 4=EDU-DRI) ════
-- Manager
(50,'edu.manager',@pw,5,2,3,NULL,NULL,'مدیر تدریسی — د تعلیم او تربیې پوهنځۍ',
 'edu.manager@paktiauniversity.edu.af',NULL,'EMP050','active'),
-- Teachers (dept 3)
(51,'edu.rashid',@pw,3,2,3,NULL,NULL,'استاد عبد الرشید احمدي',
 'edu.rashid@paktiauniversity.edu.af',NULL,'EMP051','active'),
(52,'edu.khalid',@pw,3,2,3,NULL,NULL,'استاد خالد محمودي',
 'edu.khalid@paktiauniversity.edu.af',NULL,'EMP052','active'),
(53,'edu.hamida',@pw,3,2,4,NULL,NULL,'استاده حمیده نوري',
 'edu.hamida@paktiauniversity.edu.af',NULL,'EMP053','active'),
-- Students class 9 (EDU-ENG_Class_1, sem 9)
(54,'edu1.s1',@pw,4,2,3,9,9,'احمد ولي رحمت',
 'edu1.s1@paktiauniversity.edu.af','STU-EDU1-01',NULL,'active'),
(55,'edu1.s2',@pw,4,2,3,9,9,'ضیاء الرحمن صدیقي',
 'edu1.s2@paktiauniversity.edu.af','STU-EDU1-02',NULL,'active'),
(56,'edu1.s3',@pw,4,2,3,9,9,'فریده احمدزی',
 'edu1.s3@paktiauniversity.edu.af','STU-EDU1-03',NULL,'active'),
(57,'edu1.s4',@pw,4,2,3,9,9,'نجلا رفیعي',
 'edu1.s4@paktiauniversity.edu.af','STU-EDU1-04',NULL,'active'),
(58,'edu1.s5',@pw,4,2,3,9,9,'شیرزاد ملایار',
 'edu1.s5@paktiauniversity.edu.af','STU-EDU1-05',NULL,'active'),
(59,'edu1.s6',@pw,4,2,3,9,9,'عبد الرحیم نظامي',
 'edu1.s6@paktiauniversity.edu.af','STU-EDU1-06',NULL,'active'),

-- ════ Agriculture (faculty 3, dept 15=AGR-HRT) ════
-- Manager
(70,'agr.manager',@pw,5,3,15,NULL,NULL,'مدیر تدریسی — د زراعت پوهنځۍ',
 'agr.manager@paktiauniversity.edu.af',NULL,'EMP070','active'),
-- Teachers
(71,'agr.karim',@pw,3,3,15,NULL,NULL,'استاد عبد الکریم حسني',
 'agr.karim@paktiauniversity.edu.af',NULL,'EMP071','active'),
(72,'agr.yusuf',@pw,3,3,15,NULL,NULL,'استاد یوسف زی',
 'agr.yusuf@paktiauniversity.edu.af',NULL,'EMP072','active'),
(73,'agr.sadia',@pw,3,3,16,NULL,NULL,'استاده سادیه نعمت',
 'agr.sadia@paktiauniversity.edu.af',NULL,'EMP073','active'),
-- Students class 57 (AGR-HRT_Class_1, sem 17)
(74,'agr1.s1',@pw,4,3,15,57,17,'عبد الحفیظ امیري',
 'agr1.s1@paktiauniversity.edu.af','STU-AGR1-01',NULL,'active'),
(75,'agr1.s2',@pw,4,3,15,57,17,'محمد ابراهیم زاهد',
 'agr1.s2@paktiauniversity.edu.af','STU-AGR1-02',NULL,'active'),
(76,'agr1.s3',@pw,4,3,15,57,17,'رفیع الله هلمند',
 'agr1.s3@paktiauniversity.edu.af','STU-AGR1-03',NULL,'active'),
(77,'agr1.s4',@pw,4,3,15,57,17,'زرغونه احمد',
 'agr1.s4@paktiauniversity.edu.af','STU-AGR1-04',NULL,'active'),
(78,'agr1.s5',@pw,4,3,15,57,17,'توریالی درانی',
 'agr1.s5@paktiauniversity.edu.af','STU-AGR1-05',NULL,'active'),
(79,'agr1.s6',@pw,4,3,15,57,17,'خیرمحمد ساپی',
 'agr1.s6@paktiauniversity.edu.af','STU-AGR1-06',NULL,'active'),

-- ════ Law & Political Science (faculty 4, dept 21=LAW-PSI) ════
-- Manager
(85,'law.manager',@pw,5,4,21,NULL,NULL,'مدیر تدریسی — د حقوقو پوهنځۍ',
 'law.manager@paktiauniversity.edu.af',NULL,'EMP085','active'),
-- Teachers
(86,'law.wasil',@pw,3,4,21,NULL,NULL,'استاد واصل عمر',
 'law.wasil@paktiauniversity.edu.af',NULL,'EMP086','active'),
(87,'law.farid',@pw,3,4,21,NULL,NULL,'استاد فرید احمد تکه',
 'law.farid@paktiauniversity.edu.af',NULL,'EMP087','active'),
(88,'law.nasrin',@pw,3,4,22,NULL,NULL,'استاده نسرین قانع',
 'law.nasrin@paktiauniversity.edu.af',NULL,'EMP088','active'),
-- Students class 81 (LAW-PSI_Class_1, sem 25)
(89,'law1.s1',@pw,4,4,21,81,25,'حمزه خان ځدران',
 'law1.s1@paktiauniversity.edu.af','STU-LAW1-01',NULL,'active'),
(90,'law1.s2',@pw,4,4,21,81,25,'ملالی توخی',
 'law1.s2@paktiauniversity.edu.af','STU-LAW1-02',NULL,'active'),
(91,'law1.s3',@pw,4,4,21,81,25,'بصیر احمد مهمند',
 'law1.s3@paktiauniversity.edu.af','STU-LAW1-03',NULL,'active'),
(92,'law1.s4',@pw,4,4,21,81,25,'ریحانه ستانکزی',
 'law1.s4@paktiauniversity.edu.af','STU-LAW1-04',NULL,'active'),
(93,'law1.s5',@pw,4,4,21,81,25,'شهباز مصطفوي',
 'law1.s5@paktiauniversity.edu.af','STU-LAW1-05',NULL,'active'),
(94,'law1.s6',@pw,4,4,21,81,25,'قدریه نوروزي',
 'law1.s6@paktiauniversity.edu.af','STU-LAW1-06',NULL,'active'),

-- ════ Engineering (faculty 5, dept 23=ENG-GEN) ════
-- Manager
(100,'eng.manager',@pw,5,5,23,NULL,NULL,'مدیر تدریسی — د انجنیرۍ پوهنځۍ',
 'eng.manager@paktiauniversity.edu.af',NULL,'EMP100','active'),
-- Teachers
(101,'eng.salim',@pw,3,5,23,NULL,NULL,'انجنیر سلیم الله محب',
 'eng.salim@paktiauniversity.edu.af',NULL,'EMP101','active'),
(102,'eng.nabi',@pw,3,5,23,NULL,NULL,'انجنیر عبد النبي کریمي',
 'eng.nabi@paktiauniversity.edu.af',NULL,'EMP102','active'),
(103,'eng.rohul',@pw,3,5,24,NULL,NULL,'انجنیر روح الله شیرزی',
 'eng.rohul@paktiauniversity.edu.af',NULL,'EMP103','active'),
-- Students class 89 (ENG-GEN_Class_1, sem 33)
(104,'eng1.s1',@pw,4,5,23,89,33,'سهراب خان احمدزی',
 'eng1.s1@paktiauniversity.edu.af','STU-ENG1-01',NULL,'active'),
(105,'eng1.s2',@pw,4,5,23,89,33,'محمد رضا خروطی',
 'eng1.s2@paktiauniversity.edu.af','STU-ENG1-02',NULL,'active'),
(106,'eng1.s3',@pw,4,5,23,89,33,'بلال شینواری',
 'eng1.s3@paktiauniversity.edu.af','STU-ENG1-03',NULL,'active'),
(107,'eng1.s4',@pw,4,5,23,89,33,'ناهید اخلاقي',
 'eng1.s4@paktiauniversity.edu.af','STU-ENG1-04',NULL,'active'),
(108,'eng1.s5',@pw,4,5,23,89,33,'ولي خان غلجی',
 'eng1.s5@paktiauniversity.edu.af','STU-ENG1-05',NULL,'active'),
(109,'eng1.s6',@pw,4,5,23,89,33,'حمید الله کاکر',
 'eng1.s6@paktiauniversity.edu.af','STU-ENG1-06',NULL,'active'),

-- ════ Medicine (faculty 6, dept 26=MED-NSU) ════
-- Manager
(115,'med.manager',@pw,5,6,26,NULL,NULL,'مدیر تدریسی — د طب پوهنځۍ',
 'med.manager@paktiauniversity.edu.af',NULL,'EMP115','active'),
-- Teachers
(116,'med.hakim',@pw,3,6,26,NULL,NULL,'ډاکتر عبد الحکیم شریفي',
 'med.hakim@paktiauniversity.edu.af',NULL,'EMP116','active'),
(117,'med.lina',@pw,3,6,26,NULL,NULL,'ډاکتره لینا رشید',
 'med.lina@paktiauniversity.edu.af',NULL,'EMP117','active'),
(118,'med.zarif',@pw,3,6,27,NULL,NULL,'ډاکتر ظریف الله بهاند',
 'med.zarif@paktiauniversity.edu.af',NULL,'EMP118','active'),
-- Students class 101 (MED-NSU_Class_1, sem 41)
(119,'med1.s1',@pw,4,6,26,101,41,'احمد شاه مومند',
 'med1.s1@paktiauniversity.edu.af','STU-MED1-01',NULL,'active'),
(120,'med1.s2',@pw,4,6,26,101,41,'سمیه صافي',
 'med1.s2@paktiauniversity.edu.af','STU-MED1-02',NULL,'active'),
(121,'med1.s3',@pw,4,6,26,101,41,'عصمت الله لودین',
 'med1.s3@paktiauniversity.edu.af','STU-MED1-03',NULL,'active'),
(122,'med1.s4',@pw,4,6,26,101,41,'فرشته خاوری',
 'med1.s4@paktiauniversity.edu.af','STU-MED1-04',NULL,'active'),
(123,'med1.s5',@pw,4,6,26,101,41,'عبد الصمد جلال',
 'med1.s5@paktiauniversity.edu.af','STU-MED1-05',NULL,'active'),
(124,'med1.s6',@pw,4,6,26,101,41,'رخشانه احمدي',
 'med1.s6@paktiauniversity.edu.af','STU-MED1-06',NULL,'active'),

-- ════ Literature (faculty 7, dept 34=LIT-LNG) ════
-- Manager
(130,'lit.manager',@pw,5,7,34,NULL,NULL,'مدیر تدریسی — د ادبیاتو پوهنځۍ',
 'lit.manager@paktiauniversity.edu.af',NULL,'EMP130','active'),
-- Teachers
(131,'lit.bashir',@pw,3,7,34,NULL,NULL,'استاد بشیر احمد هوتک',
 'lit.bashir@paktiauniversity.edu.af',NULL,'EMP131','active'),
(132,'lit.sharifa',@pw,3,7,34,NULL,NULL,'استاده شریفه میرزاد',
 'lit.sharifa@paktiauniversity.edu.af',NULL,'EMP132','active'),
-- Students class 133 (LIT-LNG_Class_1, sem 49)
(133,'lit1.s1',@pw,4,7,34,133,49,'صابر الله شینواری',
 'lit1.s1@paktiauniversity.edu.af','STU-LIT1-01',NULL,'active'),
(134,'lit1.s2',@pw,4,7,34,133,49,'لیلا احمدزی',
 'lit1.s2@paktiauniversity.edu.af','STU-LIT1-02',NULL,'active'),
(135,'lit1.s3',@pw,4,7,34,133,49,'مصطفی قریشي',
 'lit1.s3@paktiauniversity.edu.af','STU-LIT1-03',NULL,'active'),
(136,'lit1.s4',@pw,4,7,34,133,49,'ذبیح الله ملیار',
 'lit1.s4@paktiauniversity.edu.af','STU-LIT1-04',NULL,'active'),
(137,'lit1.s5',@pw,4,7,34,133,49,'نیلوفر رفیعي',
 'lit1.s5@paktiauniversity.edu.af','STU-LIT1-05',NULL,'active'),

-- ════ Economics (faculty 8, dept 35=ECO-STD) ════
-- Manager
(140,'eco.manager',@pw,5,8,35,NULL,NULL,'مدیر تدریسی — د اقتصاد پوهنځۍ',
 'eco.manager@paktiauniversity.edu.af',NULL,'EMP140','active'),
-- Teachers
(141,'eco.khalil',@pw,3,8,35,NULL,NULL,'استاد خلیل الله میاخیل',
 'eco.khalil@paktiauniversity.edu.af',NULL,'EMP141','active'),
(142,'eco.rohina',@pw,3,8,35,NULL,NULL,'استاده روحیه فیضي',
 'eco.rohina@paktiauniversity.edu.af',NULL,'EMP142','active'),
(143,'eco.fahim',@pw,3,8,36,NULL,NULL,'استاد فهیم الله بارکزی',
 'eco.fahim@paktiauniversity.edu.af',NULL,'EMP143','active'),
-- Students class 137 (ECO-STD_Class_1, sem 57)
(144,'eco1.s1',@pw,4,8,35,137,57,'محمد یاسین تره کی',
 'eco1.s1@paktiauniversity.edu.af','STU-ECO1-01',NULL,'active'),
(145,'eco1.s2',@pw,4,8,35,137,57,'حبیب الله عمر',
 'eco1.s2@paktiauniversity.edu.af','STU-ECO1-02',NULL,'active'),
(146,'eco1.s3',@pw,4,8,35,137,57,'سعیده رحیمي',
 'eco1.s3@paktiauniversity.edu.af','STU-ECO1-03',NULL,'active'),
(147,'eco1.s4',@pw,4,8,35,137,57,'احسان الله ګل',
 'eco1.s4@paktiauniversity.edu.af','STU-ECO1-04',NULL,'active'),
(148,'eco1.s5',@pw,4,8,35,137,57,'مریم ظریفي',
 'eco1.s5@paktiauniversity.edu.af','STU-ECO1-05',NULL,'active'),
(149,'eco1.s6',@pw,4,8,35,137,57,'شعیب الله ورمک',
 'eco1.s6@paktiauniversity.edu.af','STU-ECO1-06',NULL,'active'),

-- ════ Sharia (faculty 9, dept 38=SHA-FQH) ════
-- Manager
(155,'sha.manager',@pw,5,9,38,NULL,NULL,'مدیر تدریسی — د شرعیاتو پوهنځۍ',
 'sha.manager@paktiauniversity.edu.af',NULL,'EMP155','active'),
-- Teachers
(156,'sha.qari',@pw,3,9,38,NULL,NULL,'استاد قاري غلام رباني',
 'sha.qari@paktiauniversity.edu.af',NULL,'EMP156','active'),
(157,'sha.hafiz',@pw,3,9,38,NULL,NULL,'استاد حافظ محمد طاهر',
 'sha.hafiz@paktiauniversity.edu.af',NULL,'EMP157','active'),
(158,'sha.bibi',@pw,3,9,39,NULL,NULL,'استاده بي بي سپوژمی',
 'sha.bibi@paktiauniversity.edu.af',NULL,'EMP158','active'),
-- Students class 149 (SHA-FQH_Class_1, sem 65)
(159,'sha1.s1',@pw,4,9,38,149,65,'محمد ادریس مطیع',
 'sha1.s1@paktiauniversity.edu.af','STU-SHA1-01',NULL,'active'),
(160,'sha1.s2',@pw,4,9,38,149,65,'شمیله رفیعي',
 'sha1.s2@paktiauniversity.edu.af','STU-SHA1-02',NULL,'active'),
(161,'sha1.s3',@pw,4,9,38,149,65,'نور الله ممتاز',
 'sha1.s3@paktiauniversity.edu.af','STU-SHA1-03',NULL,'active'),
(162,'sha1.s4',@pw,4,9,38,149,65,'صدیقه کروخیل',
 'sha1.s4@paktiauniversity.edu.af','STU-SHA1-04',NULL,'active'),
(163,'sha1.s5',@pw,4,9,38,149,65,'مولوی حمزه اڅکزی',
 'sha1.s5@paktiauniversity.edu.af','STU-SHA1-05',NULL,'active'),
(164,'sha1.s6',@pw,4,9,38,149,65,'حاجي نصیر احمد',
 'sha1.s6@paktiauniversity.edu.af','STU-SHA1-06',NULL,'active');

-- ─────────────────────────────────────────────────────────────────────────────
-- SUBJECTS — د نورو ۸ پوهنځیو مضامین (id 10..49)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `subjects`
  (`id`,`faculty_id`,`department_id`,`semester_id`,`name`,`code`,`default_weekly_credits`,`is_active`)
VALUES
-- Education (faculty 2, dept 3, sem 9 = سمیستر 1)
(10,2,3,9,'اساسات تعلیم',    'EDU-101',3,1),
(11,2,3,9,'روانشناسي تعلیمي','EDU-102',3,1),
(12,2,3,9,'ژبپوهنه',         'EDU-103',2,1),
(13,2,3,9,'اسلامي تعلیم',    'EDU-104',2,1),
(14,2,3,9,'کمپیوټر پوهنه',   'EDU-105',2,1),

-- Agriculture (faculty 3, dept 15, sem 17 = سمیستر 1)
(20,3,15,17,'د خاورې پوهنه',          'AGR-101',3,1),
(21,3,15,17,'د بوټو روزنه',           'AGR-102',3,1),
(22,3,15,17,'د کرنیزو کمیاوي موادو پوهنه','AGR-103',2,1),
(23,3,15,17,'بیولوژي',                'AGR-104',2,1),
(24,3,15,17,'ریاضي',                  'AGR-105',2,1),

-- Law (faculty 4, dept 21, sem 25 = سمیستر 1)
(30,4,21,25,'د حقوقو لومړني اصول',   'LAW-101',3,1),
(31,4,21,25,'اسلامي حقوق',            'LAW-102',3,1),
(32,4,21,25,'د اساسي قانون',          'LAW-103',3,1),
(33,4,21,25,'سیاسي علوم',             'LAW-104',2,1),
(34,4,21,25,'پارلماني اصول',          'LAW-105',2,1),

-- Engineering (faculty 5, dept 23, sem 33 = سمیستر 1)
(35,5,23,33,'ریاضي ۱',               'ENG-101',3,1),
(36,5,23,33,'فزیک ۱',                'ENG-102',3,1),
(37,5,23,33,'انجنیري رسم',            'ENG-103',3,1),
(38,5,23,33,'د مادو پوهنه',           'ENG-104',2,1),
(39,5,23,33,'انفورماتیک',             'ENG-105',2,1),

-- Medicine (faculty 6, dept 26, sem 41 = سمیستر 1)
(40,6,26,41,'اناتومي',               'MED-101',4,1),
(41,6,26,41,'بیوکیمي',               'MED-102',3,1),
(42,6,26,41,'فزیولوژي',              'MED-103',3,1),
(43,6,26,41,'هیستولوژي',             'MED-104',2,1),
(44,6,26,41,'طبي اخلاق',             'MED-105',2,1),

-- Literature (faculty 7, dept 34, sem 49 = سمیستر 1)
(45,7,34,49,'کلاسیک ادبیات',         'LIT-101',3,1),
(46,7,34,49,'معاصر ادبیات',          'LIT-102',3,1),
(47,7,34,49,'عروض او بلاغت',         'LIT-103',2,1),
(48,7,34,49,'ژبپوهنه',               'LIT-104',2,1),

-- Economics (faculty 8, dept 35, sem 57 = سمیستر 1)
(49,8,35,57,'مایکرو اکونومیکس',      'ECO-101',3,1),
(50,8,35,57,'میکرو اکونومیکس',       'ECO-102',3,1),
(51,8,35,57,'محاسبه',                'ECO-103',3,1),
(52,8,35,57,'د پوهنتون ریاضي',       'ECO-104',2,1),

-- Sharia (faculty 9, dept 38, sem 65 = سمیستر 1)
(53,9,38,65,'تفسیر قرآن',            'SHA-101',3,1),
(54,9,38,65,'حدیث',                  'SHA-102',3,1),
(55,9,38,65,'فقه',                   'SHA-103',3,1),
(56,9,38,65,'اصول فقه',              'SHA-104',2,1);

-- ─────────────────────────────────────────────────────────────────────────────
-- TEACHER-SUBJECT ASSIGNMENTS — د نورو پوهنځیو استادان ته مضامین
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `teacher_subject_assignments`
  (`teacher_id`,`subject_id`,`faculty_id`,`department_id`,`is_active`)
VALUES
-- Education
(51,10,2,3,1),(51,11,2,3,1),(52,12,2,3,1),(52,13,2,3,1),(53,14,2,4,1),
-- Agriculture
(71,20,3,15,1),(71,21,3,15,1),(72,22,3,15,1),(72,23,3,15,1),(73,24,3,16,1),
-- Law
(86,30,4,21,1),(86,31,4,21,1),(87,32,4,21,1),(87,33,4,21,1),(88,34,4,22,1),
-- Engineering
(101,35,5,23,1),(101,36,5,23,1),(102,37,5,23,1),(102,38,5,23,1),(103,39,5,24,1),
-- Medicine
(116,40,6,26,1),(116,41,6,26,1),(117,42,6,26,1),(117,43,6,26,1),(118,44,6,27,1),
-- Literature
(131,45,7,34,1),(131,46,7,34,1),(132,47,7,34,1),(132,48,7,34,1),
-- Economics
(141,49,8,35,1),(141,50,8,35,1),(142,51,8,35,1),(143,52,8,36,1),
-- Sharia
(156,53,9,38,1),(156,54,9,38,1),(157,55,9,38,1),(158,56,9,39,1);

-- ─────────────────────────────────────────────────────────────────────────────
-- TIMETABLE — د نورو ۸ پوهنځیو مهالویش
--   IDs: 20..63 (د CS ۱..۱۱ مخکې شته)
--   Sat=6, Sun=0, Mon=1, Tue=2, Wed=3, Thu=4, Fri=5
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `timetable`
  (`id`,`faculty_id`,`department_id`,`semester_id`,`class_id`,`subject_id`,`teacher_id`,
   `room_id`,`room`,`day_of_week`,`start_time`,`end_time`,`credit_units`,`status`,`approved_by`,`notes`)
VALUES
-- Education (class 9, sem 9, room 10=EDU-101)
(20,2,3,9, 9,10,51,10,'EDU-101',0,'08:00:00','10:00:00',2,'approved',40,'اساسات تعلیم — استاد رشید'),
(21,2,3,9, 9,11,51,10,'EDU-101',1,'08:00:00','10:00:00',2,'approved',40,'روانشناسي تعلیمي — استاد رشید'),
(22,2,3,9, 9,12,52,11,'EDU-102',2,'08:00:00','10:00:00',2,'approved',40,'ژبپوهنه — استاد خالد'),
(23,2,3,9, 9,13,52,11,'EDU-102',3,'08:00:00','10:00:00',2,'approved',40,'اسلامي تعلیم — استاد خالد'),
(24,2,3,9, 9,14,53,10,'EDU-101',4,'10:00:00','12:00:00',2,'approved',40,'کمپیوټر پوهنه — استاده حمیده'),

-- Agriculture (class 57, sem 17, room 12=AGR-101)
(25,3,15,17,57,20,71,12,'AGR-101',0,'10:00:00','12:00:00',2,'approved',41,'د خاورې پوهنه — استاد کریم'),
(26,3,15,17,57,21,71,12,'AGR-101',1,'10:00:00','12:00:00',2,'approved',41,'د بوټو روزنه — استاد کریم'),
(27,3,15,17,57,22,72,13,'AGR-Lab',2,'10:00:00','12:00:00',2,'approved',41,'کمیاوي مواد — استاد یوسف'),
(28,3,15,17,57,23,72,12,'AGR-101',3,'10:00:00','12:00:00',2,'approved',41,'بیولوژي — استاد یوسف'),
(29,3,15,17,57,24,73,12,'AGR-101',4,'10:00:00','12:00:00',2,'approved',41,'ریاضي — استاده سادیه'),

-- Law (class 81, sem 25, room 14=LAW-101)
(30,4,21,25,81,30,86,14,'LAW-101',0,'08:00:00','10:00:00',2,'approved',42,'د حقوقو اصول — استاد واصل'),
(31,4,21,25,81,31,86,14,'LAW-101',1,'08:00:00','10:00:00',2,'approved',42,'اسلامي حقوق — استاد واصل'),
(32,4,21,25,81,32,87,15,'LAW-102',2,'08:00:00','10:00:00',2,'approved',42,'اساسي قانون — استاد فرید'),
(33,4,21,25,81,33,87,15,'LAW-102',3,'08:00:00','10:00:00',2,'approved',42,'سیاسي علوم — استاد فرید'),
(34,4,21,25,81,34,88,14,'LAW-101',4,'08:00:00','10:00:00',2,'approved',42,'پارلماني اصول — استاده نسرین'),

-- Engineering (class 89, sem 33, room 16=ENG-101)
(35,5,23,33,89,35,101,16,'ENG-101',0,'10:00:00','12:00:00',2,'approved',43,'ریاضي ۱ — انجنیر سلیم'),
(36,5,23,33,89,36,101,16,'ENG-101',1,'10:00:00','12:00:00',2,'approved',43,'فزیک ۱ — انجنیر سلیم'),
(37,5,23,33,89,37,102,17,'ENG-Lab',2,'10:00:00','12:00:00',2,'approved',43,'انجنیري رسم — انجنیر نبي'),
(38,5,23,33,89,38,102,16,'ENG-101',3,'10:00:00','12:00:00',2,'approved',43,'د مادو پوهنه — انجنیر نبي'),
(39,5,23,33,89,39,103,16,'ENG-101',4,'10:00:00','12:00:00',2,'approved',43,'انفورماتیک — انجنیر روح الله'),

-- Medicine (class 101, sem 41, room 18=MED-101)
(40,6,26,41,101,40,116,18,'MED-101',0,'08:00:00','10:00:00',2,'approved',44,'اناتومي — ډاکتر حکیم'),
(41,6,26,41,101,41,116,18,'MED-101',1,'08:00:00','10:00:00',2,'approved',44,'بیوکیمي — ډاکتر حکیم'),
(42,6,26,41,101,42,117,19,'MED-Lab',2,'08:00:00','10:00:00',2,'approved',44,'فزیولوژي — ډاکتره لینا'),
(43,6,26,41,101,43,117,18,'MED-101',3,'08:00:00','10:00:00',2,'approved',44,'هیستولوژي — ډاکتره لینا'),
(44,6,26,41,101,44,118,18,'MED-101',4,'08:00:00','10:00:00',2,'approved',44,'طبي اخلاق — ډاکتر ظریف'),

-- Literature (class 133, sem 49, room 20=LIT-101)
(45,7,34,49,133,45,131,20,'LIT-101',0,'08:00:00','10:00:00',2,'approved',45,'کلاسیک ادبیات — استاد بشیر'),
(46,7,34,49,133,46,131,20,'LIT-101',1,'08:00:00','10:00:00',2,'approved',45,'معاصر ادبیات — استاد بشیر'),
(47,7,34,49,133,47,132,21,'LIT-102',2,'08:00:00','10:00:00',2,'approved',45,'عروض — استاده شریفه'),
(48,7,34,49,133,48,132,21,'LIT-102',3,'08:00:00','10:00:00',2,'approved',45,'ژبپوهنه — استاده شریفه'),

-- Economics (class 137, sem 57, room 22=ECO-101)
(49,8,35,57,137,49,141,22,'ECO-101',0,'10:00:00','12:00:00',2,'approved',46,'مایکرو — استاد خلیل'),
(50,8,35,57,137,50,141,22,'ECO-101',1,'10:00:00','12:00:00',2,'approved',46,'میکرو — استاد خلیل'),
(51,8,35,57,137,51,142,22,'ECO-101',2,'10:00:00','12:00:00',2,'approved',46,'محاسبه — استاده روحیه'),
(52,8,35,57,137,52,143,22,'ECO-101',3,'10:00:00','12:00:00',2,'approved',46,'ریاضي — استاد فهیم'),

-- Sharia (class 149, sem 65, room 24=SHA-101)
(53,9,38,65,149,53,156,24,'SHA-101',0,'08:00:00','10:00:00',2,'approved',47,'تفسیر قرآن — قاري ربانی'),
(54,9,38,65,149,54,156,24,'SHA-101',1,'08:00:00','10:00:00',2,'approved',47,'حدیث — قاري ربانی'),
(55,9,38,65,149,55,157,24,'SHA-101',2,'08:00:00','10:00:00',2,'approved',47,'فقه — استاد حافظ'),
(56,9,38,65,149,56,157,24,'SHA-101',3,'08:00:00','10:00:00',2,'approved',47,'اصول فقه — استاد حافظ');

-- ─────────────────────────────────────────────────────────────────────────────
-- د نورو پوهنځیو ریسانو (Deans) faculty_id UPDATE — department_id سم کول
-- (د اصلي seed کې dean users پرته له department_id نه ورکولو شوو)
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE `users` SET `department_id`=3  WHERE `id`=40 AND `department_id` IS NULL; -- edu.dean → dept 3
UPDATE `users` SET `department_id`=15 WHERE `id`=41 AND `department_id` IS NULL; -- agr.dean → dept 15
UPDATE `users` SET `department_id`=21 WHERE `id`=42 AND `department_id` IS NULL; -- law.dean → dept 21
UPDATE `users` SET `department_id`=23 WHERE `id`=43 AND `department_id` IS NULL; -- eng.dean → dept 23
UPDATE `users` SET `department_id`=26 WHERE `id`=44 AND `department_id` IS NULL; -- med.dean → dept 26
UPDATE `users` SET `department_id`=34 WHERE `id`=45 AND `department_id` IS NULL; -- lit.dean → dept 34
UPDATE `users` SET `department_id`=35 WHERE `id`=46 AND `department_id` IS NULL; -- eco.dean → dept 35
UPDATE `users` SET `department_id`=38 WHERE `id`=47 AND `department_id` IS NULL; -- sha.dean → dept 38

-- ════════════════════════════════════════════════════════════════════════════
-- پای — د v25 نوي ډیټا بشپړه شوه
-- ────────────────────────────────────────────────────────────────────────────
-- د نوو حسابونو پټنوم: Abc@1234
--
-- Education:   edu.manager / edu.rashid / edu.khalid / edu.hamida
--              edu1.s1 — edu1.s6
-- Agriculture: agr.manager / agr.karim / agr.yusuf / agr.sadia
--              agr1.s1 — agr1.s6
-- Law:         law.manager / law.wasil / law.farid / law.nasrin
--              law1.s1 — law1.s6
-- Engineering: eng.manager / eng.salim / eng.nabi / eng.rohul
--              eng1.s1 — eng1.s6
-- Medicine:    med.manager / med.hakim / med.lina / med.zarif
--              med1.s1 — med1.s6
-- Literature:  lit.manager / lit.bashir / lit.sharifa
--              lit1.s1 — lit1.s5
-- Economics:   eco.manager / eco.khalil / eco.rohina / eco.fahim
--              eco1.s1 — eco1.s6
-- Sharia:      sha.manager / sha.qari / sha.hafiz / sha.bibi
--              sha1.s1 — sha1.s6
-- ════════════════════════════════════════════════════════════════════════════

-- ════════════════════════════════════════════════════════════════════════════
-- FIX v25 — SUPPLEMENT  (د نورو ۸ پوهنځیو ډېټا غني کول)
-- ────────────────────────────────────────────────────────────────────────────
-- موخه: د هرې پوهنځۍ پایلوټ صنف ته ۶ نور محصلان وراضافه کول، ترڅو د هرې
-- پوهنځۍ صنف ۱۲ کسیز شي (د CS-IS د نمونې سره برابر). همدا راز په هرې پوهنځۍ
-- کې یو دویم استاد د کمکي مضمون (extra subject) سره وراضافه کیږي تر څو
-- مهالویش بشپړ شي.
--
-- ID ranges (نوي): users 200..299  — د موجوده 50..169 سره هیڅ ټکر نه لري.
-- ټول INSERTs د `INSERT IGNORE` سره دي — د بیا چلولو لپاره خوندي.
-- د ټولو حسابونو پټنوم: Abc@1234
-- ════════════════════════════════════════════════════════════════════════════

SET @pw = '$2y$12$k6.zKCGLtGN8iKxar8fGJeJAalXaBhCkxwesQPa0vi6GQ3j8EVWvu';

-- ─────────────────────────────────────────────────────────────────────────────
-- اضافي محصلان (هرې پوهنځۍ ته ۶ نور محصلان، د موجوده class_id/semester سره)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `users`
  (`id`,`username`,`password_hash`,`role_id`,`faculty_id`,`department_id`,`class_id`,`semester_id`,
   `full_name`,`email`,`student_id`,`employee_id`,`status`)
VALUES
-- Education (faculty 2, dept 3, class 9, sem 9)
(200,'edu1.s7', @pw,4,2,3, 9, 9,'شیر آغا کاکړ',     'edu1.s7@paktiauniversity.edu.af','STU-EDU1-07',NULL,'active'),
(201,'edu1.s8', @pw,4,2,3, 9, 9,'حاجي زاده ولي',    'edu1.s8@paktiauniversity.edu.af','STU-EDU1-08',NULL,'active'),
(202,'edu1.s9', @pw,4,2,3, 9, 9,'تاج محمد منصور',   'edu1.s9@paktiauniversity.edu.af','STU-EDU1-09',NULL,'active'),
(203,'edu1.s10',@pw,4,2,3, 9, 9,'پاینده ګل احمدزی', 'edu1.s10@paktiauniversity.edu.af','STU-EDU1-10',NULL,'active'),
(204,'edu1.s11',@pw,4,2,3, 9, 9,'پروین صدیقي',      'edu1.s11@paktiauniversity.edu.af','STU-EDU1-11',NULL,'active'),
(205,'edu1.s12',@pw,4,2,3, 9, 9,'صالحه فضلي',       'edu1.s12@paktiauniversity.edu.af','STU-EDU1-12',NULL,'active'),

-- Agriculture (faculty 3, dept 15, class 57, sem 17)
(210,'agr1.s7', @pw,4,3,15,57,17,'محمد طارق وردک',  'agr1.s7@paktiauniversity.edu.af','STU-AGR1-07',NULL,'active'),
(211,'agr1.s8', @pw,4,3,15,57,17,'ګل آغا ستانکزی',  'agr1.s8@paktiauniversity.edu.af','STU-AGR1-08',NULL,'active'),
(212,'agr1.s9', @pw,4,3,15,57,17,'احسان الله زمر',  'agr1.s9@paktiauniversity.edu.af','STU-AGR1-09',NULL,'active'),
(213,'agr1.s10',@pw,4,3,15,57,17,'حلیمه نوري',      'agr1.s10@paktiauniversity.edu.af','STU-AGR1-10',NULL,'active'),
(214,'agr1.s11',@pw,4,3,15,57,17,'محبوب الله نور',  'agr1.s11@paktiauniversity.edu.af','STU-AGR1-11',NULL,'active'),
(215,'agr1.s12',@pw,4,3,15,57,17,'فریدون اڅکزی',    'agr1.s12@paktiauniversity.edu.af','STU-AGR1-12',NULL,'active'),

-- Law (faculty 4, dept 21, class 81, sem 25)
(220,'law1.s7', @pw,4,4,21,81,25,'احمد جواد منګل',  'law1.s7@paktiauniversity.edu.af','STU-LAW1-07',NULL,'active'),
(221,'law1.s8', @pw,4,4,21,81,25,'سفیر الله ولي',   'law1.s8@paktiauniversity.edu.af','STU-LAW1-08',NULL,'active'),
(222,'law1.s9', @pw,4,4,21,81,25,'محمد علي پوپل',   'law1.s9@paktiauniversity.edu.af','STU-LAW1-09',NULL,'active'),
(223,'law1.s10',@pw,4,4,21,81,25,'سحر ګل کریمي',    'law1.s10@paktiauniversity.edu.af','STU-LAW1-10',NULL,'active'),
(224,'law1.s11',@pw,4,4,21,81,25,'هدایت الله مهمند','law1.s11@paktiauniversity.edu.af','STU-LAW1-11',NULL,'active'),
(225,'law1.s12',@pw,4,4,21,81,25,'فیروزه باچا',     'law1.s12@paktiauniversity.edu.af','STU-LAW1-12',NULL,'active'),

-- Engineering (faculty 5, dept 23, class 89, sem 33)
(230,'eng1.s7', @pw,4,5,23,89,33,'محمد جان زاد',    'eng1.s7@paktiauniversity.edu.af','STU-ENG1-07',NULL,'active'),
(231,'eng1.s8', @pw,4,5,23,89,33,'احمد ضیاء راڼا',  'eng1.s8@paktiauniversity.edu.af','STU-ENG1-08',NULL,'active'),
(232,'eng1.s9', @pw,4,5,23,89,33,'فضل الرحمن خوست', 'eng1.s9@paktiauniversity.edu.af','STU-ENG1-09',NULL,'active'),
(233,'eng1.s10',@pw,4,5,23,89,33,'فروزه عمر',       'eng1.s10@paktiauniversity.edu.af','STU-ENG1-10',NULL,'active'),
(234,'eng1.s11',@pw,4,5,23,89,33,'عبد الباري لودین','eng1.s11@paktiauniversity.edu.af','STU-ENG1-11',NULL,'active'),
(235,'eng1.s12',@pw,4,5,23,89,33,'محمد عثمان شینواری','eng1.s12@paktiauniversity.edu.af','STU-ENG1-12',NULL,'active'),

-- Medicine (faculty 6, dept 26, class 101, sem 41)
(240,'med1.s7', @pw,4,6,26,101,41,'محمد اکبر صافي','med1.s7@paktiauniversity.edu.af','STU-MED1-07',NULL,'active'),
(241,'med1.s8', @pw,4,6,26,101,41,'حنیفه ولي زاده', 'med1.s8@paktiauniversity.edu.af','STU-MED1-08',NULL,'active'),
(242,'med1.s9', @pw,4,6,26,101,41,'عبد القادر لاله','med1.s9@paktiauniversity.edu.af','STU-MED1-09',NULL,'active'),
(243,'med1.s10',@pw,4,6,26,101,41,'فاطمه احمد',     'med1.s10@paktiauniversity.edu.af','STU-MED1-10',NULL,'active'),
(244,'med1.s11',@pw,4,6,26,101,41,'محمد فهیم درمل', 'med1.s11@paktiauniversity.edu.af','STU-MED1-11',NULL,'active'),
(245,'med1.s12',@pw,4,6,26,101,41,'بریالی نظری',    'med1.s12@paktiauniversity.edu.af','STU-MED1-12',NULL,'active'),

-- Literature (faculty 7, dept 34, class 133, sem 49)
(250,'lit1.s6', @pw,4,7,34,133,49,'عتیق الله شینواری','lit1.s6@paktiauniversity.edu.af','STU-LIT1-06',NULL,'active'),
(251,'lit1.s7', @pw,4,7,34,133,49,'پوهنیار جمشید',  'lit1.s7@paktiauniversity.edu.af','STU-LIT1-07',NULL,'active'),
(252,'lit1.s8', @pw,4,7,34,133,49,'سپوږمۍ کریمي',   'lit1.s8@paktiauniversity.edu.af','STU-LIT1-08',NULL,'active'),
(253,'lit1.s9', @pw,4,7,34,133,49,'احمد سعید کاکر','lit1.s9@paktiauniversity.edu.af','STU-LIT1-09',NULL,'active'),
(254,'lit1.s10',@pw,4,7,34,133,49,'زهره هاشمي',    'lit1.s10@paktiauniversity.edu.af','STU-LIT1-10',NULL,'active'),
(255,'lit1.s11',@pw,4,7,34,133,49,'محمد قاسم نوري', 'lit1.s11@paktiauniversity.edu.af','STU-LIT1-11',NULL,'active'),
(256,'lit1.s12',@pw,4,7,34,133,49,'سعدیه رحماني',   'lit1.s12@paktiauniversity.edu.af','STU-LIT1-12',NULL,'active'),

-- Economics (faculty 8, dept 35, class 137, sem 57)
(260,'eco1.s7', @pw,4,8,35,137,57,'محمد سعید جواد', 'eco1.s7@paktiauniversity.edu.af','STU-ECO1-07',NULL,'active'),
(261,'eco1.s8', @pw,4,8,35,137,57,'ولید الله هوتک', 'eco1.s8@paktiauniversity.edu.af','STU-ECO1-08',NULL,'active'),
(262,'eco1.s9', @pw,4,8,35,137,57,'سعیده فرید',     'eco1.s9@paktiauniversity.edu.af','STU-ECO1-09',NULL,'active'),
(263,'eco1.s10',@pw,4,8,35,137,57,'محمد عمر زلمی',  'eco1.s10@paktiauniversity.edu.af','STU-ECO1-10',NULL,'active'),
(264,'eco1.s11',@pw,4,8,35,137,57,'لیلا کریمي',     'eco1.s11@paktiauniversity.edu.af','STU-ECO1-11',NULL,'active'),
(265,'eco1.s12',@pw,4,8,35,137,57,'سهیل احمدزی',    'eco1.s12@paktiauniversity.edu.af','STU-ECO1-12',NULL,'active'),

-- Sharia (faculty 9, dept 38, class 149, sem 65)
(270,'sha1.s7', @pw,4,9,38,149,65,'مولوي عبد البصیر','sha1.s7@paktiauniversity.edu.af','STU-SHA1-07',NULL,'active'),
(271,'sha1.s8', @pw,4,9,38,149,65,'حافظ نصرالله',   'sha1.s8@paktiauniversity.edu.af','STU-SHA1-08',NULL,'active'),
(272,'sha1.s9', @pw,4,9,38,149,65,'قاري نوید الله', 'sha1.s9@paktiauniversity.edu.af','STU-SHA1-09',NULL,'active'),
(273,'sha1.s10',@pw,4,9,38,149,65,'بي بي زهرا',     'sha1.s10@paktiauniversity.edu.af','STU-SHA1-10',NULL,'active'),
(274,'sha1.s11',@pw,4,9,38,149,65,'محمد یاسر احمدي','sha1.s11@paktiauniversity.edu.af','STU-SHA1-11',NULL,'active'),
(275,'sha1.s12',@pw,4,9,38,149,65,'هدایت الله هوتک','sha1.s12@paktiauniversity.edu.af','STU-SHA1-12',NULL,'active');

-- ─────────────────────────────────────────────────────────────────────────────
-- اضافي مضامین — د هرې پوهنځۍ پایلوټ صنف ته یو اضافي مضمون (id 60..67)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `subjects`
  (`id`,`faculty_id`,`department_id`,`semester_id`,`name`,`code`,`default_weekly_credits`,`is_active`)
VALUES
(60,2,3, 9, 'پښتو ادب',         'EDU-106',2,1),
(61,3,15,17,'د کرنیزو ماشینونو پوهنه','AGR-106',2,1),
(62,4,21,25,'بشري حقوق',        'LAW-106',2,1),
(63,5,23,33,'انګلیسي ژبه',      'ENG-106',2,1),
(64,6,26,41,'طبي اصطلاحات',     'MED-106',2,1),
(65,7,34,49,'پښتو سپړنه',       'LIT-105',2,1),
(66,8,35,57,'احصائیه',          'ECO-105',2,1),
(67,9,38,65,'سیرت النبی ﷺ',     'SHA-105',2,1);

-- ─────────────────────────────────────────────────────────────────────────────
-- اضافي teacher-subject assignments (موجوده استادان نوي مضامین تدریسوي)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `teacher_subject_assignments`
  (`teacher_id`,`subject_id`,`faculty_id`,`department_id`,`is_active`)
VALUES
(52, 60,2,3, 1),  -- edu.khalid → پښتو ادب
(72, 61,3,15,1),  -- agr.yusuf  → کرنیزې ماشینونه
(87, 62,4,21,1),  -- law.farid  → بشري حقوق
(102,63,5,23,1),  -- eng.nabi   → انګلیسي ژبه
(117,64,6,26,1),  -- med.lina   → طبي اصطلاحات
(132,65,7,34,1),  -- lit.sharifa→ پښتو سپړنه
(142,66,8,35,1),  -- eco.rohina → احصائیه
(157,67,9,38,1);  -- sha.hafiz  → سیرت النبی

-- ─────────────────────────────────────────────────────────────────────────────
-- اضافي مهالویش entries (id 70..77) — هر پوهنځۍ ته یوه نوې ورځ
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `timetable`
  (`id`,`faculty_id`,`department_id`,`semester_id`,`class_id`,`subject_id`,`teacher_id`,
   `room_id`,`room`,`day_of_week`,`start_time`,`end_time`,`credit_units`,`status`,`approved_by`,`notes`)
VALUES
-- Education — Friday is holiday; place on Saturday (6)
(70,2,3, 9, 9, 60, 52,10,'EDU-101',6,'10:00:00','12:00:00',2,'approved',40,'پښتو ادب — استاد خالد'),
-- Agriculture — Saturday (6)
(71,3,15,17,57,61, 72,12,'AGR-101',6,'08:00:00','10:00:00',2,'approved',41,'کرنیزې ماشینونه — استاد یوسف'),
-- Law — Saturday (6)
(72,4,21,25,81,62, 87,14,'LAW-101',6,'10:00:00','12:00:00',2,'approved',42,'بشري حقوق — استاد فرید'),
-- Engineering — Saturday (6)
(73,5,23,33,89,63,102,16,'ENG-101',6,'08:00:00','10:00:00',2,'approved',43,'انګلیسي ژبه — انجنیر نبي'),
-- Medicine — Saturday (6)
(74,6,26,41,101,64,117,18,'MED-101',6,'10:00:00','12:00:00',2,'approved',44,'طبي اصطلاحات — ډاکتره لینا'),
-- Literature — Saturday (6)
(75,7,34,49,133,65,132,20,'LIT-101',6,'10:00:00','12:00:00',2,'approved',45,'پښتو سپړنه — استاده شریفه'),
-- Economics — Saturday (6)
(76,8,35,57,137,66,142,22,'ECO-101',6,'08:00:00','10:00:00',2,'approved',46,'احصائیه — استاده روحیه'),
-- Sharia — Saturday (6)
(77,9,38,65,149,67,157,24,'SHA-101',6,'10:00:00','12:00:00',2,'approved',47,'سیرت النبی — استاد حافظ');

-- ════════════════════════════════════════════════════════════════════════════
-- پای — د v25 supplement بشپړ شو
-- ────────────────────────────────────────────────────────────────────────────
-- اضافي حسابونه (پټنوم: Abc@1234):
--   Education:   edu1.s7  — edu1.s12   (IDs 200..205)
--   Agriculture: agr1.s7  — agr1.s12   (IDs 210..215)
--   Law:         law1.s7  — law1.s12   (IDs 220..225)
--   Engineering: eng1.s7  — eng1.s12   (IDs 230..235)
--   Medicine:    med1.s7  — med1.s12   (IDs 240..245)
--   Literature:  lit1.s6  — lit1.s12   (IDs 250..256)
--   Economics:   eco1.s7  — eco1.s12   (IDs 260..265)
--   Sharia:      sha1.s7  — sha1.s12   (IDs 270..275)
-- اضافي مضامین: id 60..67  (هرې پوهنځۍ لپاره یو)
-- اضافي مهالویش: id 70..77 (هرې پوهنځۍ لپاره یو، شنبه)
-- ════════════════════════════════════════════════════════════════════════════

-- ════════════════════════════════════════════════════════════════════════════
-- FIX v26 — GRAND DATA EXPANSION
-- ────────────────────────────────────────────────────────────────────────────
-- موخه: د سیستم لپاره د کافي حجم ډېټا برابرول.
--   • هرې پوهنځۍ پایلوټ صنف ته نور محصلان (هر صنف ۲۵ کسیز شي)
--   • ۸ نور مضامین (هر پوهنځۍ ته یو اضافي)
--   • ۱۲+ نوي مهالویش ننوتنې
--   • نوي teacher-subject تړاوونه
--
-- ID ranges (نوي — له موجوده 200..299 څخه پورته):
--   users:      300..447   — ۱۴۸ نور محصلان د ټولو پوهنځیو لپاره
--   subjects:   100..115   — ۱۶ نور مضامین
--   timetable:  100..119   — ۲۰ نوي مهالویش
--
-- د ټولو حسابونو پټنوم: Abc@1234
-- ټول INSERTs د `INSERT IGNORE` سره دي — د بیا چلولو لپاره خوندي.
-- ════════════════════════════════════════════════════════════════════════════

SET @pw = '$2y$12$k6.zKCGLtGN8iKxar8fGJeJAalXaBhCkxwesQPa0vi6GQ3j8EVWvu';

-- ─────────────────────────────────────────────────────────────────────────────
-- اضافي محصلان — هر پایلوټ صنف ته ۱۳ نور (یا ۱۹ د CS-CSC لپاره)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `users`
  (`id`,`username`,`password_hash`,`role_id`,`faculty_id`,`department_id`,`class_id`,`semester_id`,
   `full_name`,`email`,`student_id`,`employee_id`,`status`)
VALUES

-- ══════ CS-IS (faculty 1, dept 1, class 4, sem 8) ══════
-- موجود: ۱۲ (IDs 14..25). نوي: ۱۳ → جمع ۲۵.
(300,'is4.s13',@pw,4,1,1,4,8,'محمد حنیف باچا',       'is4.s13@paktiauniversity.edu.af','STU-IS4-13',NULL,'active'),
(301,'is4.s14',@pw,4,1,1,4,8,'نصیر احمد ګلابزی',     'is4.s14@paktiauniversity.edu.af','STU-IS4-14',NULL,'active'),
(302,'is4.s15',@pw,4,1,1,4,8,'احمد جاوید ابراهیم',   'is4.s15@paktiauniversity.edu.af','STU-IS4-15',NULL,'active'),
(303,'is4.s16',@pw,4,1,1,4,8,'حمزه شفیق منګل',       'is4.s16@paktiauniversity.edu.af','STU-IS4-16',NULL,'active'),
(304,'is4.s17',@pw,4,1,1,4,8,'فردوس خان وردک',       'is4.s17@paktiauniversity.edu.af','STU-IS4-17',NULL,'active'),
(305,'is4.s18',@pw,4,1,1,4,8,'محمد ادریس صدیقي',     'is4.s18@paktiauniversity.edu.af','STU-IS4-18',NULL,'active'),
(306,'is4.s19',@pw,4,1,1,4,8,'محمد رفیع شینواری',    'is4.s19@paktiauniversity.edu.af','STU-IS4-19',NULL,'active'),
(307,'is4.s20',@pw,4,1,1,4,8,'سهیل احمد خاکریزوال',   'is4.s20@paktiauniversity.edu.af','STU-IS4-20',NULL,'active'),
(308,'is4.s21',@pw,4,1,1,4,8,'ولید الله نوري',       'is4.s21@paktiauniversity.edu.af','STU-IS4-21',NULL,'active'),
(309,'is4.s22',@pw,4,1,1,4,8,'محمد طیب احمدزی',      'is4.s22@paktiauniversity.edu.af','STU-IS4-22',NULL,'active'),
(310,'is4.s23',@pw,4,1,1,4,8,'سیف الله ګلستان',      'is4.s23@paktiauniversity.edu.af','STU-IS4-23',NULL,'active'),
(311,'is4.s24',@pw,4,1,1,4,8,'محمد عرفان زدران',     'is4.s24@paktiauniversity.edu.af','STU-IS4-24',NULL,'active'),
(312,'is4.s25',@pw,4,1,1,4,8,'محمد بشیر هوتک',       'is4.s25@paktiauniversity.edu.af','STU-IS4-25',NULL,'active'),

-- ══════ CS-CSC (faculty 1, dept 2, class 8, sem 8) ══════
-- موجود: ۶ (IDs 26..31). نوي: ۱۹ → جمع ۲۵.
(325,'ne4.s7', @pw,4,1,2,8,8,'محمد سلیم ځدران',       'ne4.s7@paktiauniversity.edu.af','STU-CS4-07',NULL,'active'),
(326,'ne4.s8', @pw,4,1,2,8,8,'فرشاد احمد لوگری',      'ne4.s8@paktiauniversity.edu.af','STU-CS4-08',NULL,'active'),
(327,'ne4.s9', @pw,4,1,2,8,8,'احمد بلال خوست',        'ne4.s9@paktiauniversity.edu.af','STU-CS4-09',NULL,'active'),
(328,'ne4.s10',@pw,4,1,2,8,8,'محمد جواد ګردیزی',      'ne4.s10@paktiauniversity.edu.af','STU-CS4-10',NULL,'active'),
(329,'ne4.s11',@pw,4,1,2,8,8,'احمد فهیم کاکر',        'ne4.s11@paktiauniversity.edu.af','STU-CS4-11',NULL,'active'),
(330,'ne4.s12',@pw,4,1,2,8,8,'سهیل احمد منګل',        'ne4.s12@paktiauniversity.edu.af','STU-CS4-12',NULL,'active'),
(331,'ne4.s13',@pw,4,1,2,8,8,'فضل الرحمن صافي',       'ne4.s13@paktiauniversity.edu.af','STU-CS4-13',NULL,'active'),
(332,'ne4.s14',@pw,4,1,2,8,8,'بریالی پکتیاوال',       'ne4.s14@paktiauniversity.edu.af','STU-CS4-14',NULL,'active'),
(333,'ne4.s15',@pw,4,1,2,8,8,'محمد فردین نوري',       'ne4.s15@paktiauniversity.edu.af','STU-CS4-15',NULL,'active'),
(334,'ne4.s16',@pw,4,1,2,8,8,'احمد رشاد امیري',       'ne4.s16@paktiauniversity.edu.af','STU-CS4-16',NULL,'active'),
(335,'ne4.s17',@pw,4,1,2,8,8,'محمد یار خوست',         'ne4.s17@paktiauniversity.edu.af','STU-CS4-17',NULL,'active'),
(336,'ne4.s18',@pw,4,1,2,8,8,'احمد شاه احمدزی',       'ne4.s18@paktiauniversity.edu.af','STU-CS4-18',NULL,'active'),
(337,'ne4.s19',@pw,4,1,2,8,8,'بصیر احمد مومند',       'ne4.s19@paktiauniversity.edu.af','STU-CS4-19',NULL,'active'),
(338,'ne4.s20',@pw,4,1,2,8,8,'وحید الله نظري',        'ne4.s20@paktiauniversity.edu.af','STU-CS4-20',NULL,'active'),
(339,'ne4.s21',@pw,4,1,2,8,8,'فیض الرحمن مسعود',      'ne4.s21@paktiauniversity.edu.af','STU-CS4-21',NULL,'active'),
(340,'ne4.s22',@pw,4,1,2,8,8,'محمد جواد بهرامي',      'ne4.s22@paktiauniversity.edu.af','STU-CS4-22',NULL,'active'),
(341,'ne4.s23',@pw,4,1,2,8,8,'محمد سعید لودین',       'ne4.s23@paktiauniversity.edu.af','STU-CS4-23',NULL,'active'),
(342,'ne4.s24',@pw,4,1,2,8,8,'محمد طاهر اڅکزی',       'ne4.s24@paktiauniversity.edu.af','STU-CS4-24',NULL,'active'),
(343,'ne4.s25',@pw,4,1,2,8,8,'احمد ضیاء سیلانی',      'ne4.s25@paktiauniversity.edu.af','STU-CS4-25',NULL,'active'),

-- ══════ Education (faculty 2, dept 3, class 9, sem 9) ══════
-- موجود: ۱۲ (IDs 54..59, 200..205). نوي: ۱۳.
(344,'edu1.s13',@pw,4,2,3,9,9,'حسیب الله رفیعي',     'edu1.s13@paktiauniversity.edu.af','STU-EDU1-13',NULL,'active'),
(345,'edu1.s14',@pw,4,2,3,9,9,'محمد عمران احمدي',    'edu1.s14@paktiauniversity.edu.af','STU-EDU1-14',NULL,'active'),
(346,'edu1.s15',@pw,4,2,3,9,9,'بشیر احمد لوي',       'edu1.s15@paktiauniversity.edu.af','STU-EDU1-15',NULL,'active'),
(347,'edu1.s16',@pw,4,2,3,9,9,'ثریا احمد',            'edu1.s16@paktiauniversity.edu.af','STU-EDU1-16',NULL,'active'),
(348,'edu1.s17',@pw,4,2,3,9,9,'لیلا نظري',            'edu1.s17@paktiauniversity.edu.af','STU-EDU1-17',NULL,'active'),
(349,'edu1.s18',@pw,4,2,3,9,9,'محمد امان رحماني',    'edu1.s18@paktiauniversity.edu.af','STU-EDU1-18',NULL,'active'),
(350,'edu1.s19',@pw,4,2,3,9,9,'جمیله ولي زی',         'edu1.s19@paktiauniversity.edu.af','STU-EDU1-19',NULL,'active'),
(351,'edu1.s20',@pw,4,2,3,9,9,'احمد شاه احمدزی',     'edu1.s20@paktiauniversity.edu.af','STU-EDU1-20',NULL,'active'),
(352,'edu1.s21',@pw,4,2,3,9,9,'مرسل عمر',              'edu1.s21@paktiauniversity.edu.af','STU-EDU1-21',NULL,'active'),
(353,'edu1.s22',@pw,4,2,3,9,9,'حشمت الله صدیقي',     'edu1.s22@paktiauniversity.edu.af','STU-EDU1-22',NULL,'active'),
(354,'edu1.s23',@pw,4,2,3,9,9,'حنیفه احمد',           'edu1.s23@paktiauniversity.edu.af','STU-EDU1-23',NULL,'active'),
(355,'edu1.s24',@pw,4,2,3,9,9,'نظیفه رحیمي',          'edu1.s24@paktiauniversity.edu.af','STU-EDU1-24',NULL,'active'),
(356,'edu1.s25',@pw,4,2,3,9,9,'احمد یاسر سیدزاده',    'edu1.s25@paktiauniversity.edu.af','STU-EDU1-25',NULL,'active'),

-- ══════ Agriculture (faculty 3, dept 15, class 57, sem 17) ══════
(357,'agr1.s13',@pw,4,3,15,57,17,'فیض الرحمن کاکړ',   'agr1.s13@paktiauniversity.edu.af','STU-AGR1-13',NULL,'active'),
(358,'agr1.s14',@pw,4,3,15,57,17,'احمد بشیر کوچی',    'agr1.s14@paktiauniversity.edu.af','STU-AGR1-14',NULL,'active'),
(359,'agr1.s15',@pw,4,3,15,57,17,'مومن خان ځدران',    'agr1.s15@paktiauniversity.edu.af','STU-AGR1-15',NULL,'active'),
(360,'agr1.s16',@pw,4,3,15,57,17,'فاطمه احمدي',       'agr1.s16@paktiauniversity.edu.af','STU-AGR1-16',NULL,'active'),
(361,'agr1.s17',@pw,4,3,15,57,17,'بلال احمد توخی',    'agr1.s17@paktiauniversity.edu.af','STU-AGR1-17',NULL,'active'),
(362,'agr1.s18',@pw,4,3,15,57,17,'حمید الله غضنفر',   'agr1.s18@paktiauniversity.edu.af','STU-AGR1-18',NULL,'active'),
(363,'agr1.s19',@pw,4,3,15,57,17,'محبوب الله جان',    'agr1.s19@paktiauniversity.edu.af','STU-AGR1-19',NULL,'active'),
(364,'agr1.s20',@pw,4,3,15,57,17,'مدینه هاشمي',        'agr1.s20@paktiauniversity.edu.af','STU-AGR1-20',NULL,'active'),
(365,'agr1.s21',@pw,4,3,15,57,17,'نقیب الله شیر',     'agr1.s21@paktiauniversity.edu.af','STU-AGR1-21',NULL,'active'),
(366,'agr1.s22',@pw,4,3,15,57,17,'احمد شاه جلال',     'agr1.s22@paktiauniversity.edu.af','STU-AGR1-22',NULL,'active'),
(367,'agr1.s23',@pw,4,3,15,57,17,'پلوشه میرزاده',     'agr1.s23@paktiauniversity.edu.af','STU-AGR1-23',NULL,'active'),
(368,'agr1.s24',@pw,4,3,15,57,17,'ضیاء الحق پکتیاوال','agr1.s24@paktiauniversity.edu.af','STU-AGR1-24',NULL,'active'),
(369,'agr1.s25',@pw,4,3,15,57,17,'محمد سرور پوپل',    'agr1.s25@paktiauniversity.edu.af','STU-AGR1-25',NULL,'active'),

-- ══════ Law (faculty 4, dept 21, class 81, sem 25) ══════
(370,'law1.s13',@pw,4,4,21,81,25,'محمد ادیب اڅکزی',   'law1.s13@paktiauniversity.edu.af','STU-LAW1-13',NULL,'active'),
(371,'law1.s14',@pw,4,4,21,81,25,'فریدون پکتیاوال',   'law1.s14@paktiauniversity.edu.af','STU-LAW1-14',NULL,'active'),
(372,'law1.s15',@pw,4,4,21,81,25,'محمد سعید کاکر',    'law1.s15@paktiauniversity.edu.af','STU-LAW1-15',NULL,'active'),
(373,'law1.s16',@pw,4,4,21,81,25,'حمیده خان زاده',     'law1.s16@paktiauniversity.edu.af','STU-LAW1-16',NULL,'active'),
(374,'law1.s17',@pw,4,4,21,81,25,'احمد ولید مومند',    'law1.s17@paktiauniversity.edu.af','STU-LAW1-17',NULL,'active'),
(375,'law1.s18',@pw,4,4,21,81,25,'سفیر احمد لودین',    'law1.s18@paktiauniversity.edu.af','STU-LAW1-18',NULL,'active'),
(376,'law1.s19',@pw,4,4,21,81,25,'محمد همایون نظري',  'law1.s19@paktiauniversity.edu.af','STU-LAW1-19',NULL,'active'),
(377,'law1.s20',@pw,4,4,21,81,25,'لیلما هاشمي',        'law1.s20@paktiauniversity.edu.af','STU-LAW1-20',NULL,'active'),
(378,'law1.s21',@pw,4,4,21,81,25,'مومن خان درانی',     'law1.s21@paktiauniversity.edu.af','STU-LAW1-21',NULL,'active'),
(379,'law1.s22',@pw,4,4,21,81,25,'محمد جواد شینواری', 'law1.s22@paktiauniversity.edu.af','STU-LAW1-22',NULL,'active'),
(380,'law1.s23',@pw,4,4,21,81,25,'احمد یوسف منګل',    'law1.s23@paktiauniversity.edu.af','STU-LAW1-23',NULL,'active'),
(381,'law1.s24',@pw,4,4,21,81,25,'صدیقه فهیمي',         'law1.s24@paktiauniversity.edu.af','STU-LAW1-24',NULL,'active'),
(382,'law1.s25',@pw,4,4,21,81,25,'محمد توریالی غني',  'law1.s25@paktiauniversity.edu.af','STU-LAW1-25',NULL,'active'),

-- ══════ Engineering (faculty 5, dept 23, class 89, sem 33) ══════
(383,'eng1.s13',@pw,4,5,23,89,33,'محمد فهیم شیرزی',   'eng1.s13@paktiauniversity.edu.af','STU-ENG1-13',NULL,'active'),
(384,'eng1.s14',@pw,4,5,23,89,33,'وحید الله امیرزی',  'eng1.s14@paktiauniversity.edu.af','STU-ENG1-14',NULL,'active'),
(385,'eng1.s15',@pw,4,5,23,89,33,'احمد رشاد لودین',    'eng1.s15@paktiauniversity.edu.af','STU-ENG1-15',NULL,'active'),
(386,'eng1.s16',@pw,4,5,23,89,33,'صفت الله ګلستان',   'eng1.s16@paktiauniversity.edu.af','STU-ENG1-16',NULL,'active'),
(387,'eng1.s17',@pw,4,5,23,89,33,'محمد عمران کریمي',  'eng1.s17@paktiauniversity.edu.af','STU-ENG1-17',NULL,'active'),
(388,'eng1.s18',@pw,4,5,23,89,33,'محمد رفیع جان',     'eng1.s18@paktiauniversity.edu.af','STU-ENG1-18',NULL,'active'),
(389,'eng1.s19',@pw,4,5,23,89,33,'بلال صافي',         'eng1.s19@paktiauniversity.edu.af','STU-ENG1-19',NULL,'active'),
(390,'eng1.s20',@pw,4,5,23,89,33,'فریده صدیقي',       'eng1.s20@paktiauniversity.edu.af','STU-ENG1-20',NULL,'active'),
(391,'eng1.s21',@pw,4,5,23,89,33,'محمد جواد هوتک',    'eng1.s21@paktiauniversity.edu.af','STU-ENG1-21',NULL,'active'),
(392,'eng1.s22',@pw,4,5,23,89,33,'احمد فردین اکرمي',  'eng1.s22@paktiauniversity.edu.af','STU-ENG1-22',NULL,'active'),
(393,'eng1.s23',@pw,4,5,23,89,33,'محمد ابراهیم ولي',  'eng1.s23@paktiauniversity.edu.af','STU-ENG1-23',NULL,'active'),
(394,'eng1.s24',@pw,4,5,23,89,33,'احمد شاه کاکر',      'eng1.s24@paktiauniversity.edu.af','STU-ENG1-24',NULL,'active'),
(395,'eng1.s25',@pw,4,5,23,89,33,'محمد توریالی ابراهیمي','eng1.s25@paktiauniversity.edu.af','STU-ENG1-25',NULL,'active'),

-- ══════ Medicine (faculty 6, dept 26, class 101, sem 41) ══════
(396,'med1.s13',@pw,4,6,26,101,41,'ډاکتر یاسر منګل',  'med1.s13@paktiauniversity.edu.af','STU-MED1-13',NULL,'active'),
(397,'med1.s14',@pw,4,6,26,101,41,'فریشته رحیمي',     'med1.s14@paktiauniversity.edu.af','STU-MED1-14',NULL,'active'),
(398,'med1.s15',@pw,4,6,26,101,41,'محمد عمر لوي',      'med1.s15@paktiauniversity.edu.af','STU-MED1-15',NULL,'active'),
(399,'med1.s16',@pw,4,6,26,101,41,'محمد جاوید صدیقي',  'med1.s16@paktiauniversity.edu.af','STU-MED1-16',NULL,'active'),
(400,'med1.s17',@pw,4,6,26,101,41,'احمد سعید کاکر',    'med1.s17@paktiauniversity.edu.af','STU-MED1-17',NULL,'active'),
(401,'med1.s18',@pw,4,6,26,101,41,'محمد فهیم شیرزاد',  'med1.s18@paktiauniversity.edu.af','STU-MED1-18',NULL,'active'),
(402,'med1.s19',@pw,4,6,26,101,41,'لیلما ابراهیمي',    'med1.s19@paktiauniversity.edu.af','STU-MED1-19',NULL,'active'),
(403,'med1.s20',@pw,4,6,26,101,41,'احمد شفیع هوتک',    'med1.s20@paktiauniversity.edu.af','STU-MED1-20',NULL,'active'),
(404,'med1.s21',@pw,4,6,26,101,41,'محمد فردین جلال',  'med1.s21@paktiauniversity.edu.af','STU-MED1-21',NULL,'active'),
(405,'med1.s22',@pw,4,6,26,101,41,'حلیمه نظري',         'med1.s22@paktiauniversity.edu.af','STU-MED1-22',NULL,'active'),
(406,'med1.s23',@pw,4,6,26,101,41,'محمد سهیل احمدي',   'med1.s23@paktiauniversity.edu.af','STU-MED1-23',NULL,'active'),
(407,'med1.s24',@pw,4,6,26,101,41,'احمد یوسف منګل',    'med1.s24@paktiauniversity.edu.af','STU-MED1-24',NULL,'active'),
(408,'med1.s25',@pw,4,6,26,101,41,'محمد عرفان شفیق',  'med1.s25@paktiauniversity.edu.af','STU-MED1-25',NULL,'active'),

-- ══════ Literature (faculty 7, dept 34, class 133, sem 49) ══════
(409,'lit1.s13',@pw,4,7,34,133,49,'محمد بلال میرزاد',  'lit1.s13@paktiauniversity.edu.af','STU-LIT1-13',NULL,'active'),
(410,'lit1.s14',@pw,4,7,34,133,49,'سپوژمۍ احمدزی',     'lit1.s14@paktiauniversity.edu.af','STU-LIT1-14',NULL,'active'),
(411,'lit1.s15',@pw,4,7,34,133,49,'احمد بشیر شینواری', 'lit1.s15@paktiauniversity.edu.af','STU-LIT1-15',NULL,'active'),
(412,'lit1.s16',@pw,4,7,34,133,49,'محمد یاسین قریشي',  'lit1.s16@paktiauniversity.edu.af','STU-LIT1-16',NULL,'active'),
(413,'lit1.s17',@pw,4,7,34,133,49,'لیلا نظري',          'lit1.s17@paktiauniversity.edu.af','STU-LIT1-17',NULL,'active'),
(414,'lit1.s18',@pw,4,7,34,133,49,'محمد جواد فیضي',    'lit1.s18@paktiauniversity.edu.af','STU-LIT1-18',NULL,'active'),
(415,'lit1.s19',@pw,4,7,34,133,49,'فریشته جان',         'lit1.s19@paktiauniversity.edu.af','STU-LIT1-19',NULL,'active'),
(416,'lit1.s20',@pw,4,7,34,133,49,'احمد ولید کاکر',    'lit1.s20@paktiauniversity.edu.af','STU-LIT1-20',NULL,'active'),
(417,'lit1.s21',@pw,4,7,34,133,49,'محمد فهیم بهرامي', 'lit1.s21@paktiauniversity.edu.af','STU-LIT1-21',NULL,'active'),
(418,'lit1.s22',@pw,4,7,34,133,49,'شکیلا نوري',         'lit1.s22@paktiauniversity.edu.af','STU-LIT1-22',NULL,'active'),
(419,'lit1.s23',@pw,4,7,34,133,49,'محمد سعید سیاوش',  'lit1.s23@paktiauniversity.edu.af','STU-LIT1-23',NULL,'active'),
(420,'lit1.s24',@pw,4,7,34,133,49,'احمد رفیع جلال',    'lit1.s24@paktiauniversity.edu.af','STU-LIT1-24',NULL,'active'),
(421,'lit1.s25',@pw,4,7,34,133,49,'محمد ضیاء سپین',   'lit1.s25@paktiauniversity.edu.af','STU-LIT1-25',NULL,'active'),

-- ══════ Economics (faculty 8, dept 35, class 137, sem 57) ══════
(422,'eco1.s13',@pw,4,8,35,137,57,'محمد ولید فهیمي',  'eco1.s13@paktiauniversity.edu.af','STU-ECO1-13',NULL,'active'),
(423,'eco1.s14',@pw,4,8,35,137,57,'احمد یوسف لودین',   'eco1.s14@paktiauniversity.edu.af','STU-ECO1-14',NULL,'active'),
(424,'eco1.s15',@pw,4,8,35,137,57,'محمد جواد منګل',    'eco1.s15@paktiauniversity.edu.af','STU-ECO1-15',NULL,'active'),
(425,'eco1.s16',@pw,4,8,35,137,57,'مرسل احمدي',         'eco1.s16@paktiauniversity.edu.af','STU-ECO1-16',NULL,'active'),
(426,'eco1.s17',@pw,4,8,35,137,57,'محمد فهیم نوري',    'eco1.s17@paktiauniversity.edu.af','STU-ECO1-17',NULL,'active'),
(427,'eco1.s18',@pw,4,8,35,137,57,'احمد ضیاء عمر',     'eco1.s18@paktiauniversity.edu.af','STU-ECO1-18',NULL,'active'),
(428,'eco1.s19',@pw,4,8,35,137,57,'محمد طارق ولي',     'eco1.s19@paktiauniversity.edu.af','STU-ECO1-19',NULL,'active'),
(429,'eco1.s20',@pw,4,8,35,137,57,'فیروزه نظري',        'eco1.s20@paktiauniversity.edu.af','STU-ECO1-20',NULL,'active'),
(430,'eco1.s21',@pw,4,8,35,137,57,'محمد بلال احمدي',  'eco1.s21@paktiauniversity.edu.af','STU-ECO1-21',NULL,'active'),
(431,'eco1.s22',@pw,4,8,35,137,57,'احمد سعید جلال',    'eco1.s22@paktiauniversity.edu.af','STU-ECO1-22',NULL,'active'),
(432,'eco1.s23',@pw,4,8,35,137,57,'محمد عمران مومند',  'eco1.s23@paktiauniversity.edu.af','STU-ECO1-23',NULL,'active'),
(433,'eco1.s24',@pw,4,8,35,137,57,'حلیمه فهیمي',        'eco1.s24@paktiauniversity.edu.af','STU-ECO1-24',NULL,'active'),
(434,'eco1.s25',@pw,4,8,35,137,57,'محمد هارون شفیق',  'eco1.s25@paktiauniversity.edu.af','STU-ECO1-25',NULL,'active'),

-- ══════ Sharia (faculty 9, dept 38, class 149, sem 65) ══════
(435,'sha1.s13',@pw,4,9,38,149,65,'مولوي عبد الواحد',  'sha1.s13@paktiauniversity.edu.af','STU-SHA1-13',NULL,'active'),
(436,'sha1.s14',@pw,4,9,38,149,65,'قاري عبد العلیم',   'sha1.s14@paktiauniversity.edu.af','STU-SHA1-14',NULL,'active'),
(437,'sha1.s15',@pw,4,9,38,149,65,'حافظ محمد یونس',     'sha1.s15@paktiauniversity.edu.af','STU-SHA1-15',NULL,'active'),
(438,'sha1.s16',@pw,4,9,38,149,65,'بي بي مریم احمدي',   'sha1.s16@paktiauniversity.edu.af','STU-SHA1-16',NULL,'active'),
(439,'sha1.s17',@pw,4,9,38,149,65,'مولوي احمد یاسر',   'sha1.s17@paktiauniversity.edu.af','STU-SHA1-17',NULL,'active'),
(440,'sha1.s18',@pw,4,9,38,149,65,'حاجي محمد عمر',      'sha1.s18@paktiauniversity.edu.af','STU-SHA1-18',NULL,'active'),
(441,'sha1.s19',@pw,4,9,38,149,65,'قاري نوید',           'sha1.s19@paktiauniversity.edu.af','STU-SHA1-19',NULL,'active'),
(442,'sha1.s20',@pw,4,9,38,149,65,'مولوي عبد الستار',  'sha1.s20@paktiauniversity.edu.af','STU-SHA1-20',NULL,'active'),
(443,'sha1.s21',@pw,4,9,38,149,65,'حافظ محمد فهیم',    'sha1.s21@paktiauniversity.edu.af','STU-SHA1-21',NULL,'active'),
(444,'sha1.s22',@pw,4,9,38,149,65,'مولوي محمد جواد',   'sha1.s22@paktiauniversity.edu.af','STU-SHA1-22',NULL,'active'),
(445,'sha1.s23',@pw,4,9,38,149,65,'بي بي صدیقه',        'sha1.s23@paktiauniversity.edu.af','STU-SHA1-23',NULL,'active'),
(446,'sha1.s24',@pw,4,9,38,149,65,'قاري عبد المنان',   'sha1.s24@paktiauniversity.edu.af','STU-SHA1-24',NULL,'active'),
(447,'sha1.s25',@pw,4,9,38,149,65,'مولوي محمد طاهر',   'sha1.s25@paktiauniversity.edu.af','STU-SHA1-25',NULL,'active');

-- ─────────────────────────────────────────────────────────────────────────────
-- اضافي مضامین (هر پوهنځۍ ته یو دویم نوی مضمون — IDs 100..115)
-- موخه: د هر پایلوټ صنف لپاره د ۲ نوي مضامین ګټوره ډېټا
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `subjects`
  (`id`,`faculty_id`,`department_id`,`semester_id`,`name`,`code`,`default_weekly_credits`,`is_active`)
VALUES
-- CS-IS (sem 8) — اضافي عملي مضمونونه
(100,1,1,8,'Software Engineering','IS-408',3,1),
(101,1,1,8,'Web Development',     'IS-409',3,1),

-- CS-CSC (sem 8) — د Network څانګې لپاره
(102,1,2,8,'Computer Networks',       'NET-401',3,1),
(103,1,2,8,'Network Security',        'NET-402',3,1),

-- Education (sem 9) — د تدریس میتودولوژي
(104,2,3,9,'د تدریس میتودولوژي','EDU-107',2,1),

-- Agriculture (sem 17)
(105,3,15,17,'د اوبو منلابعو اداره','AGR-107',2,1),

-- Law (sem 25)
(106,4,21,25,'بین المللي حقوق','LAW-107',2,1),

-- Engineering (sem 33)
(107,5,23,33,'د بشپړیدنې پروژه','ENG-107',2,1),

-- Medicine (sem 41)
(108,6,26,41,'د جراحۍ بنسټونه','MED-107',3,1),

-- Literature (sem 49)
(109,7,34,49,'د دري ادبیات کلاسیک','LIT-106',2,1),

-- Economics (sem 57)
(110,8,35,57,'د بانکدارۍ اصول','ECO-106',2,1),

-- Sharia (sem 65)
(111,9,38,65,'فقه المعاملات','SHA-106',2,1);

-- ─────────────────────────────────────────────────────────────────────────────
-- اضافي teacher-subject assignments
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `teacher_subject_assignments`
  (`teacher_id`,`subject_id`,`faculty_id`,`department_id`,`is_active`)
VALUES
-- CS-IS
(6, 100, 1, 1, 1),  -- مجیب الرحمن → Software Engineering
(5, 101, 1, 1, 1),  -- علي خیل → Web Development
-- CS-CSC (Network)
(11, 102, 1, 2, 1), -- هاشمي → Computer Networks
(12, 103, 1, 2, 1), -- بلال → Network Security
-- Education
(53, 104, 2, 4, 1), -- حمیده → د تدریس میتودولوژي (note: she's in dept 4 originally - cross-listed)
-- Agriculture
(72, 105, 3, 15, 1), -- یوسف → د اوبو منلابعو اداره
-- Law
(88, 106, 4, 22, 1), -- نسرین (dept 22) → بین المللي حقوق
-- Engineering
(103, 107, 5, 24, 1), -- روح الله (dept 24) → د بشپړیدنې پروژه
-- Medicine
(118, 108, 6, 27, 1), -- ظریف → د جراحۍ بنسټونه
-- Literature
(131, 109, 7, 34, 1), -- بشیر → د دري ادبیات کلاسیک
-- Economics
(143, 110, 8, 36, 1), -- فهیم → د بانکدارۍ اصول
-- Sharia
(158, 111, 9, 39, 1); -- بي بي سپوژمی → فقه المعاملات

-- ─────────────────────────────────────────────────────────────────────────────
-- اضافي مهالویش ننوتنې (IDs 100..119)
-- د هر پایلوټ صنف لپاره ۲ نور سلوټونه (د Sun=0..Thu=4 درمیان)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `timetable`
  (`id`,`faculty_id`,`department_id`,`semester_id`,`class_id`,`subject_id`,`teacher_id`,
   `room_id`,`room`,`day_of_week`,`start_time`,`end_time`,`credit_units`,`status`,`approved_by`,`notes`)
VALUES
-- CS-IS class 4: Software Engineering & Web Development
(100,1,1,8,4,100, 6,1,'CS-101',3,'10:00:00','12:00:00',2,'approved',2,'Software Engineering — مجیب الرحمن — چهارشنبه'),
(101,1,1,8,4,101, 5,4,'CS-201',5,'08:00:00','10:00:00',2,'approved',2,'Web Development — علي خیل — جمعه'),

-- CS-CSC class 8: Computer Networks & Network Security
(102,1,2,8,8,102,11,1,'CS-101',0,'08:00:00','10:00:00',2,'approved',2,'Computer Networks — هاشمي — یکشنبه'),
(103,1,2,8,8,103,12,2,'CS-102',2,'10:00:00','12:00:00',2,'approved',2,'Network Security — بلال — سه‌شنبه'),
(104,1,2,8,8, 5,11,1,'CS-101',3,'08:00:00','10:00:00',2,'approved',2,'Data Mining — هاشمي — چهارشنبه'),
(105,1,2,8,8, 1,12,2,'CS-102',4,'10:00:00','12:00:00',2,'approved',2,'DBMS — بلال — پنجشنبه'),

-- Education class 9: د تدریس میتودولوژي + اضافي پښتو ادب
(106,2,3, 9, 9,104,53,11,'EDU-102',5,'08:00:00','10:00:00',2,'approved',40,'د تدریس میتودولوژي — حمیده'),
(107,2,3, 9, 9, 60,52,10,'EDU-101',2,'10:00:00','12:00:00',2,'approved',40,'پښتو ادب (تکراري) — استاد خالد — سه‌شنبه'),

-- Agriculture class 57
(108,3,15,17,57,105,72,13,'AGR-Lab',5,'10:00:00','12:00:00',2,'approved',41,'د اوبو منلابعو اداره — یوسف'),

-- Law class 81
(109,4,21,25,81,106,87,15,'LAW-102',5,'10:00:00','12:00:00',2,'approved',42,'بین المللي حقوق — فرید'),
(110,4,21,25,81, 32,86,14,'LAW-101',2,'10:00:00','12:00:00',2,'approved',42,'اساسي قانون (تکراري) — واصل — سه‌شنبه'),

-- Engineering class 89
(111,5,23,33,89,107,103,17,'ENG-Lab',5,'08:00:00','10:00:00',2,'approved',43,'د بشپړیدنې پروژه — روح الله'),
(112,5,23,33,89, 35,101,16,'ENG-101',2,'08:00:00','10:00:00',2,'approved',43,'ریاضي ۱ (تکراري) — انجنیر سلیم'),

-- Medicine class 101
(113,6,26,41,101,108,118,19,'MED-Lab',5,'10:00:00','12:00:00',2,'approved',44,'د جراحۍ بنسټونه — ظریف'),
(114,6,26,41,101, 40,116,18,'MED-101',2,'10:00:00','12:00:00',2,'approved',44,'اناتومي (تکراري) — حکیم'),

-- Literature class 133
(115,7,34,49,133,109,131,20,'LIT-101',5,'10:00:00','12:00:00',2,'approved',45,'د دري ادبیات کلاسیک — بشیر'),
(116,7,34,49,133, 45,131,20,'LIT-101',2,'10:00:00','12:00:00',2,'approved',45,'کلاسیک ادبیات (تکراري) — بشیر'),

-- Economics class 137
(117,8,35,57,137,110,143,22,'ECO-101',5,'08:00:00','10:00:00',2,'approved',46,'د بانکدارۍ اصول — فهیم'),

-- Sharia class 149
(118,9,38,65,149,111,158,24,'SHA-101',5,'08:00:00','10:00:00',2,'approved',47,'فقه المعاملات — بي بي سپوژمی'),
(119,9,38,65,149, 53,156,24,'SHA-101',2,'10:00:00','12:00:00',2,'approved',47,'تفسیر قرآن (تکراري) — قاري ربانی');

-- ════════════════════════════════════════════════════════════════════════════
-- پای — د v26 لوی توسیع بشپړ شو
-- ────────────────────────────────────────────────────────────────────────────
-- اضافې:
--   • ۱۴۸ نور محصلان (IDs 300..447) — د هرې پوهنځۍ پایلوټ صنف اوس ۲۵ کسیز دی
--   • ۱۲ نور مضامین (IDs 100..111) — هرې پوهنځۍ ته یو دویم
--   • ۲۰ نوي مهالویش ننوتنې (IDs 100..119)
--   • نوي teacher-subject assignments
--
-- محصلان د هرې پوهنځۍ په پایلوټ صنف کې اوس داسې دي:
--   CS-IS    (class 4):   ۲۵ کسان  (IDs 14..25, 300..312)
--   CS-CSC   (class 8):   ۲۵ کسان  (IDs 26..31, 325..343)
--   Education (class 9):  ۲۵ کسان  (IDs 54..59, 200..205, 344..356)
--   Agriculture (class 57): ۲۵ کسان  (IDs 74..79, 210..215, 357..369)
--   Law (class 81):       ۲۵ کسان  (IDs 89..94, 220..225, 370..382)
--   Engineering (class 89): ۲۵ کسان (IDs 104..109, 230..235, 383..395)
--   Medicine (class 101): ۲۵ کسان  (IDs 119..124, 240..245, 396..408)
--   Literature (class 133): ۲۵ کسان (IDs 133..137, 250..256, 409..421)
--   Economics (class 137): ۲۵ کسان  (IDs 144..149, 260..265, 422..434)
--   Sharia (class 149):   ۲۵ کسان  (IDs 159..164, 270..275, 435..447)
--
-- جمعه: ۲۵۰ + ۱۴۸ = ~۴۰۰ active users
-- د ټولو نوو حسابونو پټنوم: Abc@1234
-- ════════════════════════════════════════════════════════════════════════════
