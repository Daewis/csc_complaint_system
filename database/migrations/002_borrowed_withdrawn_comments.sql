-- =====================================================================
-- Migration 002: Add is_borrowed + student_comment + withdrawn status
-- =====================================================================
-- Run this ONCE on the existing database. Idempotent — safe to
-- re-run (each statement checks before it changes anything).
--
-- What this migration adds:
--   1. complaints.is_borrowed   — flags borrowed/elective course complaints
--   2. complaints.student_comment — appended student note (no editing the
--      original complaint_text — preserves audit trail)
--   3. complaints.withdrawn_at  — timestamp of withdrawal (if student
--      withdrew the complaint while still in 'pending' state)
--   4. Extends the status enum to include 'withdrawn'
-- =====================================================================

-- ------------------------------------------------------------------
-- 1. Add is_borrowed column
-- ------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'complaints'
      AND COLUMN_NAME = 'is_borrowed'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `complaints` ADD COLUMN `is_borrowed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ai_validation_reason`',
    'SELECT "complaints.is_borrowed already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 2. Add student_comment column
-- ------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'complaints'
      AND COLUMN_NAME = 'student_comment'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `complaints` ADD COLUMN `student_comment` TEXT NULL DEFAULT NULL AFTER `is_borrowed`',
    'SELECT "complaints.student_comment already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 3. Add withdrawn_at column
-- ------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'complaints'
      AND COLUMN_NAME = 'withdrawn_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `complaints` ADD COLUMN `withdrawn_at` DATETIME NULL DEFAULT NULL AFTER `student_comment`',
    'SELECT "complaints.withdrawn_at already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 4. Extend the status enum to include 'withdrawn'
--    MariaDB / MySQL don't have IF NOT EXISTS for ALTER ENUM, so we
--    check the existing enum first by inspecting information_schema.
-- ------------------------------------------------------------------
SET @current_enum := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'complaints'
      AND COLUMN_NAME = 'status'
);
SET @expected_enum := 'enum(''pending'',''returned_to_student'',''endorsed'',''assigned_to_lecturer'',''verified'',''approved'',''rejected'',''withdrawn'')';

-- Only alter if the current enum doesn't already include 'withdrawn'
SET @needs_alter := IF(LOCATE('withdrawn', @current_enum) = 0, 1, 0);
SET @sql := IF(@needs_alter = 1,
    CONCAT('ALTER TABLE `complaints` MODIFY COLUMN `status` ', @expected_enum, ' DEFAULT ''pending'''),
    'SELECT "complaints.status already includes withdrawn" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 5. Add an index on is_borrowed so review pages can filter quickly
-- ------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'complaints'
      AND INDEX_NAME = 'idx_is_borrowed'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `complaints` ADD KEY `idx_is_borrowed` (`is_borrowed`)',
    'SELECT "idx_is_borrowed already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- Done
-- ------------------------------------------------------------------
SELECT 'Migration 002 applied successfully' AS status;
