
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

CREATE DATABASE IF NOT EXISTS `PU_SAMS_12`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `PU_SAMS`;


CREATE TABLE `roles` (
  `id` TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `label` VARCHAR(80) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `universities` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(180) NOT NULL,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `faculties` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `university_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(120) NOT NULL,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_faculty_university` FOREIGN KEY (`university_id`) REFERENCES `universities`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `departments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `faculty_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `head_id` INT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_department_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `semesters` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `faculty_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL COMMENT 'اختیاري — که د دیپارټمنټ پورې تړلي وي',
  `name` VARCHAR(60) NOT NULL,
  `semester_no` TINYINT UNSIGNED NOT NULL,
  `year_no` TINYINT UNSIGNED NOT NULL,
  `weeks_count` TINYINT UNSIGNED DEFAULT 16,
  `start_date` DATE DEFAULT NULL COMMENT 'د دې ځانګړي سمیستر د پیل نیټه (اختیاري)',
  `is_active` TINYINT(1) DEFAULT 1,
  UNIQUE KEY `uniq_fac_sem` (`faculty_id`,`semester_no`),
  INDEX `idx_semesters_dept` (`department_id`),
  CONSTRAINT `fk_semester_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_semester_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `rooms` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `faculty_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `building` VARCHAR(80) DEFAULT NULL,
  `capacity` SMALLINT UNSIGNED DEFAULT 0,
  `room_type` ENUM('classroom','lab','hall') DEFAULT 'classroom',
  `is_active` TINYINT(1) DEFAULT 1,
  UNIQUE KEY `uniq_fac_room` (`faculty_id`,`name`),
  CONSTRAINT `fk_room_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `classes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `faculty_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `semester_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `section` VARCHAR(20) DEFAULT NULL,
  `capacity` SMALLINT UNSIGNED DEFAULT 0,
  `class_type` ENUM('shared','department') DEFAULT 'shared',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_class_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_class_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_class_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(60) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role_id` TINYINT UNSIGNED NOT NULL,
  `faculty_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `class_id` INT UNSIGNED DEFAULT NULL,
  `semester_id` INT UNSIGNED DEFAULT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(180) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `photo` VARCHAR(255) DEFAULT 'default.png',
  `student_id` VARCHAR(40) DEFAULT NULL,
  `employee_id` VARCHAR(40) DEFAULT NULL,
  `status` ENUM('active','inactive','suspended','archived') DEFAULT 'active',
  `force_password_change` TINYINT(1) DEFAULT 0,
  `last_login` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`),
  CONSTRAINT `fk_user_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters`(`id`) ON DELETE SET NULL,
  INDEX `idx_user_role_status` (`role_id`,`status`),
  INDEX `idx_user_faculty` (`faculty_id`),
  INDEX `idx_user_department` (`department_id`),
  INDEX `idx_user_class_lookup` (`class_id`)
) ENGINE=InnoDB;

ALTER TABLE `departments`
  ADD CONSTRAINT `fk_department_head`
  FOREIGN KEY (`head_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;


CREATE TABLE `permissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` TINYINT UNSIGNED NOT NULL,
  `permission` VARCHAR(100) NOT NULL,
  `granted` TINYINT(1) DEFAULT 1,
  CONSTRAINT `fk_permission_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `role_perm` (`role_id`,`permission`)
) ENGINE=InnoDB;

CREATE TABLE `subjects` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `faculty_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `semester_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(120) NOT NULL,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `default_weekly_credits` TINYINT UNSIGNED DEFAULT 3,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_subject_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subject_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_subject_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `teacher_subject_assignments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `faculty_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_teacher_subject` (`teacher_id`,`subject_id`),
  CONSTRAINT `fk_teacher_subject_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_teacher_subject_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `timetable` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `faculty_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `semester_id` INT UNSIGNED DEFAULT NULL,
  `class_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `teacher_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED DEFAULT NULL,
  `room` VARCHAR(60) DEFAULT NULL,
  `day_of_week` TINYINT UNSIGNED NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat',
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `credit_units` TINYINT UNSIGNED DEFAULT 1,
  `status` ENUM('draft','faculty_approved','approved','rejected') DEFAULT 'draft',
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_tt_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_room` FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tt_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_tt_lookup` (`day_of_week`,`start_time`,`end_time`,`class_id`,`teacher_id`,`room_id`)
) ENGINE=InnoDB;

CREATE TABLE `attendance_sessions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `timetable_id` INT UNSIGNED DEFAULT NULL,
  `class_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `teacher_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED DEFAULT NULL,
  `room` VARCHAR(60) DEFAULT NULL,
  `session_date` DATE NOT NULL,
  `credit_slot_no` TINYINT UNSIGNED DEFAULT 1,
  `slot_label` VARCHAR(40) DEFAULT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `status` ENUM('open','closed','completed','cancelled','missed') DEFAULT 'open',
  `is_missed` TINYINT(1) DEFAULT 0,
  `generated_by_system` TINYINT(1) DEFAULT 1,
  `closed_by` INT UNSIGNED DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_as_tt` FOREIGN KEY (`timetable_id`) REFERENCES `timetable`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_as_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_as_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_as_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_as_room` FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_as_closed` FOREIGN KEY (`closed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `uniq_session_slot` (`timetable_id`,`session_date`,`credit_slot_no`),
  INDEX `idx_as_date_teacher` (`session_date`,`teacher_id`,`status`),
  INDEX `idx_as_class` (`class_id`,`session_date`)
) ENGINE=InnoDB;

CREATE TABLE `attendance_records` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `status` ENUM('present','absent') DEFAULT 'present',
  `marked_at` DATETIME DEFAULT NULL,
  `mark_method` ENUM('manual','manager','system') DEFAULT 'manual',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `device_info` VARCHAR(255) DEFAULT NULL,
  `correction_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `sess_student` (`session_id`,`student_id`),
  CONSTRAINT `fk_ar_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ar_student` FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_ar_status` (`student_id`,`status`)
) ENGINE=InnoDB;

CREATE TABLE `attendance_history` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `attendance_status` ENUM('present','absent') NOT NULL,
  `history_date` DATE NOT NULL,
  `source_module` VARCHAR(80) DEFAULT 'attendance',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ah_lookup` (`history_date`,`student_id`,`attendance_status`),
  CONSTRAINT `fk_ah_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ah_student` FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ah_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ===== NEW TABLE: missed_classes (request #4) =====
-- A separate, dedicated table for "missed by teacher" classes.
-- Students are NOT counted absent for these credits; they are
-- counted as PRESENT (system-credited).
CREATE TABLE `missed_classes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `faculty_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `class_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `teacher_id` INT UNSIGNED NOT NULL,
  `timetable_id` INT UNSIGNED DEFAULT NULL,
  `session_id` INT UNSIGNED DEFAULT NULL,
  `missed_date` DATE NOT NULL,
  `credits` TINYINT UNSIGNED DEFAULT 1,
  `reason` VARCHAR(255) DEFAULT NULL,
  `recorded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_mc_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mc_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mc_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mc_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mc_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mc_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mc_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_mc_lookup` (`missed_date`,`class_id`,`subject_id`,`teacher_id`)
) ENGINE=InnoDB;

CREATE TABLE `edit_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_type` ENUM('profile','attendance','timetable','user_create','role_change') NOT NULL,
  `requested_by` INT UNSIGNED NOT NULL,
  `target_id` INT UNSIGNED DEFAULT NULL,
  `old_value` JSON DEFAULT NULL,
  `new_value` JSON DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `forwarded_to_role` TINYINT UNSIGNED DEFAULT NULL COMMENT '1=VC,2=Dean (request #8)',
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `review_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_er_requester` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_er_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_er_status` (`status`),
  INDEX `idx_er_forward` (`forwarded_to_role`,`status`)
) ENGINE=InnoDB;

CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(120) NOT NULL,
  `module` VARCHAR(60) DEFAULT NULL,
  `target_id` INT UNSIGNED DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_created` (`created_at`),
  INDEX `idx_audit_login_lookup` (`action`,`ip_address`,`created_at`)
) ENGINE=InnoDB;

CREATE TABLE `session_tokens` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(128) NOT NULL UNIQUE,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `last_active` DATETIME DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_revoked` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `backup_history` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `file_size` BIGINT UNSIGNED DEFAULT 0,
  `backup_type` ENUM('manual','scheduled','restore_point') DEFAULT 'manual',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_bh_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `backup_schedule` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `frequency` ENUM('daily','weekly','monthly') DEFAULT 'daily',
  `time_of_day` TIME DEFAULT '02:00:00',
  `day_of_week` TINYINT UNSIGNED DEFAULT 0,
  `is_enabled` TINYINT(1) DEFAULT 0,
  `last_run` DATETIME DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `announcements` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `audience` ENUM('all','students','teachers','managers','deans') DEFAULT 'all',
  `faculty_id` INT UNSIGNED NULL,   -- NULL = system-wide (VC); otherwise scoped to one faculty (Dean)
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL,
  INDEX `idx_ann_created` (`created_at`),
  INDEX `idx_ann_faculty` (`faculty_id`),
  CONSTRAINT `fk_ann_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ann_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `manager_attendance_edits` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `old_status` ENUM('present','absent') DEFAULT NULL,
  `new_status` ENUM('present','absent') NOT NULL,
  `edited_by` INT UNSIGNED NOT NULL,
  `actor_role` TINYINT UNSIGNED NOT NULL,
  `edit_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_mae_session` (`session_id`),
  INDEX `idx_mae_actor` (`edited_by`),
  CONSTRAINT `fk_mae_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mae_student` FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mae_actor` FOREIGN KEY (`edited_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info','warning','success','danger') DEFAULT 'info',
  `is_read` TINYINT(1) DEFAULT 0,
  `link` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_nt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_nt_lookup` (`user_id`,`is_read`)
) ENGINE=InnoDB;

-- ===== settings_and_policy =====
-- د سیستم ټولې پراختیاوې (settings) او د معاون پالیسۍ (policies) سره
-- په یوه جدول کې راټولې شوې — د کوډ سره سم نوم.
CREATE TABLE `settings_and_policy` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(80) NOT NULL UNIQUE,
  `value` TEXT DEFAULT NULL,
  `label` VARCHAR(120) DEFAULT NULL,
  `group` VARCHAR(60) DEFAULT 'general',
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_settings_updater` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `password_reset_tokens` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(128) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_prt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════
--  PERFORMANCE INDEXES — د لوی حجم ډیټا لپاره (hot-path queries)
--  Composite + covering indexes for high-concurrency workloads.
-- ═══════════════════════════════════════════════════════════════════

-- users — login, roster & filter queries
CREATE INDEX `idx_users_role_status_class` ON `users` (`role_id`, `status`, `class_id`);
CREATE INDEX `idx_users_faculty_role`      ON `users` (`faculty_id`, `role_id`);
CREATE INDEX `idx_users_username`          ON `users` (`username`);
CREATE INDEX `idx_users_student_id`        ON `users` (`student_id`);
CREATE INDEX `idx_users_deleted_at`        ON `users` (`deleted_at`);

-- attendance_sessions — most-hit table
CREATE INDEX `idx_as_date_status`   ON `attendance_sessions` (`session_date`, `status`);
CREATE INDEX `idx_as_tt_date`       ON `attendance_sessions` (`timetable_id`, `session_date`);
CREATE INDEX `idx_as_teacher_date`  ON `attendance_sessions` (`teacher_id`, `session_date`);
CREATE INDEX `idx_as_subject_class` ON `attendance_sessions` (`subject_id`, `class_id`);
CREATE INDEX `idx_as_date_endtime`  ON `attendance_sessions` (`session_date`, `end_time`);

-- attendance_records — high-volume insert/update
CREATE INDEX `idx_ar_student_session` ON `attendance_records` (`student_id`, `session_id`);
CREATE INDEX `idx_ar_session_status`  ON `attendance_records` (`session_id`, `status`);
CREATE INDEX `idx_ar_marked_at`       ON `attendance_records` (`marked_at`);

-- timetable — joins & scheduling
CREATE INDEX `idx_tt_teacher_status` ON `timetable` (`teacher_id`, `status`);
CREATE INDEX `idx_tt_class_status`   ON `timetable` (`class_id`, `status`);
CREATE INDEX `idx_tt_day_status`     ON `timetable` (`day_of_week`, `status`);
CREATE INDEX `idx_tt_faculty_status` ON `timetable` (`faculty_id`, `status`);

-- missed_classes — reporting
CREATE INDEX `idx_mc_date`          ON `missed_classes` (`missed_date`);
CREATE INDEX `idx_mc_class_subject` ON `missed_classes` (`class_id`, `subject_id`);
CREATE INDEX `idx_mc_teacher_date`  ON `missed_classes` (`teacher_id`, `missed_date`);
CREATE INDEX `idx_mc_session`       ON `missed_classes` (`session_id`);

-- notifications — per-user dashboard
CREATE INDEX `idx_notif_user_time` ON `notifications` (`user_id`, `created_at`);

-- audit_log — time-range search
CREATE INDEX `idx_audit_user_time` ON `audit_log` (`user_id`, `created_at`);

-- structural lookups
CREATE INDEX `idx_classes_faculty_dept` ON `classes`     (`faculty_id`, `department_id`);
CREATE INDEX `idx_subjects_dept_sem`    ON `subjects`    (`department_id`, `semester_id`);
CREATE INDEX `idx_depts_faculty`        ON `departments` (`faculty_id`);

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════
--  پای — د جدولونو جوړښت بشپړ شو.
--  بل ګام: د لومړني ډیټا لپاره `sql/seed.sql` پلي کړئ.
-- ═══════════════════════════════════════════════════════════════════
