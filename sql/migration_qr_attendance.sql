-- =====================================================================
-- PUSAMS — QR Attendance Migration
-- File: sql/migration_qr_attendance.sql
-- Purpose: Additive schema changes to enable QR-based attendance
--          without touching any existing manual-attendance logic.
--
-- Golden rule: This migration ONLY ADDS columns and rows.
-- It never renames/drops/repurposes anything already in production.
-- Historical data needs no backfill: existing rows default to
-- attendance_method='pending', which behaves identically to the old
-- (pre-QR) flow — the manual save path continues to work as before.
--
-- Run this ONCE after upgrading the codebase. Safe to re-run: every
-- statement is guarded so it will not error on second execution.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 2.1 Lock + QR state columns on the per-credit session row
-- ---------------------------------------------------------------------
-- MySQL 8.0.29+ supports ADD COLUMN IF NOT EXISTS. For maximum
-- portability with older MySQL/MariaDB we use dynamic SQL wrapped in
-- stored procedures that check information_schema first.

DROP PROCEDURE IF EXISTS pusams_add_col;
DELIMITER $$
CREATE PROCEDURE pusams_add_col(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO col_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = p_table
      AND COLUMN_NAME  = p_column;
    IF col_exists = 0 THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL pusams_add_col('attendance_sessions', 'attendance_method',
    "ENUM('pending','manual','qr') NOT NULL DEFAULT 'pending' AFTER status");
CALL pusams_add_col('attendance_sessions', 'qr_token',
    "VARCHAR(128) NULL AFTER attendance_method");
CALL pusams_add_col('attendance_sessions', 'backup_code',
    "VARCHAR(12) NULL AFTER qr_token");
CALL pusams_add_col('attendance_sessions', 'qr_expires_at',
    "DATETIME NULL AFTER backup_code");
CALL pusams_add_col('attendance_sessions', 'qr_refresh_seconds',
    "SMALLINT UNSIGNED DEFAULT 30 AFTER qr_expires_at");

DROP PROCEDURE IF EXISTS pusams_add_col;

-- Unique index on qr_token so two live sessions can never collide.
-- Guarded so re-running is idempotent.
DROP PROCEDURE IF EXISTS pusams_add_uniq;
DELIMITER $$
CREATE PROCEDURE pusams_add_uniq()
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO idx_exists
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_sessions'
      AND INDEX_NAME   = 'uniq_qr_token';
    IF idx_exists = 0 THEN
        ALTER TABLE `attendance_sessions` ADD UNIQUE KEY `uniq_qr_token` (`qr_token`);
    END IF;
END$$
DELIMITER ;
CALL pusams_add_uniq();
DROP PROCEDURE IF EXISTS pusams_add_uniq;

-- ---------------------------------------------------------------------
-- 2.2 Allow 'qr' as a mark_method on individual attendance records
-- ---------------------------------------------------------------------
-- ENUM widening is a metadata-only change; existing 'manual', 'manager',
-- 'system' values remain untouched.
ALTER TABLE `attendance_records`
    MODIFY COLUMN `mark_method` ENUM('manual','manager','system','qr') DEFAULT 'manual';

-- ---------------------------------------------------------------------
-- 2.3 Permission rows for the new feature
--     ROLE_TEACHER = 3, ROLE_STUDENT = 4  (see config/config.php)
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`role_id`, `permission`, `granted`) VALUES
    (3, 'start_qr_session', 1),
    (4, 'scan_qr',          1)
ON DUPLICATE KEY UPDATE `granted` = VALUES(`granted`);

-- ---------------------------------------------------------------------
-- Done. No table renames, no drops, no data mutations on existing rows.
-- ---------------------------------------------------------------------
