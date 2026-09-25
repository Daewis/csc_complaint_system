-- =====================================================================
-- Migration 001: Fix audit_log column + add user flags + OTP table
-- =====================================================================
-- Run this ONCE on the existing database to apply all schema fixes
-- introduced when Supabase was removed and OTP/email-verification was
-- rebuilt in pure PHP.
--
-- Safe to re-run (each statement is idempotent).
-- =====================================================================

-- ------------------------------------------------------------------
-- 1. Fix audit_log.notes column
--    logAudit() in functions.php writes to a column named `notes`.
--    If your table currently has `note` (singular), rename it.
-- ------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_log'
      AND COLUMN_NAME = 'note'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE `audit_log` CHANGE `note` `notes` TEXT NULL DEFAULT NULL',
    'SELECT "audit_log.notes already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 2. Add users.must_change_password column (used by manage_staff.php
--    reset flow and by requireLogin() in auth.php)
-- ------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'must_change_password'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_hod`',
    'SELECT "users.must_change_password already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 3. Create the OTP table used by the new local OTP flow
--    (replaces Supabase Auth for both registration and password reset)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(150) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `purpose` ENUM('registration','password_reset') NOT NULL DEFAULT 'registration',
  `user_data` TEXT NULL COMMENT 'JSON payload for registration flow',
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `attempts` INT(11) NOT NULL DEFAULT 0 COMMENT 'Failed verification attempts',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_purpose` (`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------
-- 4. Drop the broken course_assignments FK that references the
--    renamed `courses_old` table, and re-create it pointing at `courses`.
-- ------------------------------------------------------------------
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'course_assignments'
      AND CONSTRAINT_NAME = 'course_assignments_ibfk_1'
);
SET @sql := IF(@fk_exists > 0,
    'ALTER TABLE `course_assignments` DROP FOREIGN KEY `course_assignments_ibfk_1`',
    'SELECT "course_assignments_ibfk_1 already dropped" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add the corrected FK pointing at `courses`
SET @fk_new := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'course_assignments'
      AND CONSTRAINT_NAME = 'course_assignments_fk_course'
);
SET @sql := IF(@fk_new = 0,
    'ALTER TABLE `course_assignments` ADD CONSTRAINT `course_assignments_fk_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE',
    'SELECT "course_assignments_fk_course already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 5. Notifications: add `type` column for filtering (best-practice)
-- ------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notifications'
      AND COLUMN_NAME = 'type'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `notifications` ADD COLUMN `type` VARCHAR(30) NOT NULL DEFAULT ''complaint'' AFTER `is_read`',
    'SELECT "notifications.type already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- Done
-- ------------------------------------------------------------------
SELECT 'Migration 001 applied successfully' AS status;
