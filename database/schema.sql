-- =====================================================================
-- LASU Result Complaint Portal — Canonical Schema
-- Database name: configurable via DB_NAME in .env (default: lasu_uniportal)
-- =====================================================================
-- This is the FULL schema with all fixes already applied:
--   • audit_log.notes (not note)
--   • users.must_change_password
--   • otp_codes table (replaces Supabase Auth)
--   • notifications.type column
--   • course_assignments FK points at `courses` (not courses_old)
--
-- For EXISTING databases, run database/migrations/001_fix_schema.sql
-- instead of recreating from scratch.
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `complaint_id` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

CREATE TABLE `complaints` (
  `id` int(11) NOT NULL,
  `ticket_number` varchar(30) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `academic_session` varchar(20) NOT NULL,
  `semester` enum('First','Second') NOT NULL,
  `category` varchar(100) NOT NULL,
  `complaint_text` text NOT NULL,
  `evidence_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','returned_to_student','endorsed','assigned_to_lecturer','verified','approved','rejected') DEFAULT 'pending',
  `level_adviser_id` int(11) DEFAULT NULL,
  `la_comment` text DEFAULT NULL,
  `la_signed_at` datetime DEFAULT NULL,
  `la_ip` varchar(45) DEFAULT NULL,
  `hod_id` int(11) DEFAULT NULL,
  `hod_comment` text DEFAULT NULL,
  `hod_assigned_lecturer_id` int(11) DEFAULT NULL,
  `hod_signed_at` datetime DEFAULT NULL,
  `hod_ip` varchar(45) DEFAULT NULL,
  `hod_final_comment` text DEFAULT NULL,
  `hod_final_signed_at` datetime DEFAULT NULL,
  `hod_final_ip` varchar(45) DEFAULT NULL,
  `lecturer_id` int(11) DEFAULT NULL,
  `lecturer_comment` text DEFAULT NULL,
  `original_ca_score` decimal(5,2) DEFAULT NULL,
  `original_exam_score` decimal(5,2) DEFAULT NULL,
  `corrected_ca_score` decimal(5,2) DEFAULT NULL,
  `corrected_exam_score` decimal(5,2) DEFAULT NULL,
  `lecturer_signed_at` datetime DEFAULT NULL,
  `lecturer_ip` varchar(45) DEFAULT NULL,
  `return_reason` text DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `sla_deadline` datetime DEFAULT NULL,
  `is_overdue` tinyint(1) DEFAULT 0,
  `reminder_sent` tinyint(1) DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `letter_body` text DEFAULT NULL,
  `ai_validation_status` enum('pending','passed','failed','manual_review') DEFAULT 'pending',
  `ai_validation_reason` text DEFAULT NULL,
  `upgrade_recommendation` enum('eligible','not_eligible') DEFAULT NULL,
  `upgrade_comment` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_title` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `course_assignments` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lecturer_id` int(11) NOT NULL,
  `academic_session` varchar(20) DEFAULT NULL,
  `semester` enum('First','Second') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

CREATE TABLE `course_offerings` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `credit_units` tinyint(4) NOT NULL,
  `level` smallint(6) NOT NULL,
  `semester` enum('Harmattan','Rain','Third') NOT NULL,
  `status` enum('C','E','R') NOT NULL DEFAULT 'C',
  `curriculum_type` enum('BMAS','CCMAS') NOT NULL DEFAULT 'CCMAS',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `faculties` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `complaint_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'complaint',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

CREATE TABLE `otp_codes` (
  `id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `code` varchar(10) NOT NULL,
  `purpose` enum('registration','password_reset') NOT NULL DEFAULT 'registration',
  `user_data` text NULL COMMENT 'JSON payload for registration flow',
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Failed verification attempts',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `email` varchar(150) NOT NULL,
  `supabase_uid` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` enum('student','staff','admin') NOT NULL DEFAULT 'student',
  `matric_number` varchar(30) DEFAULT NULL,
  `PF_NO` varchar(30) DEFAULT NULL,
  `faculty` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `level` varchar(10) DEFAULT NULL,
  `title` varchar(20) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_verified` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_lecturer` tinyint(1) DEFAULT 0,
  `is_level_adviser` tinyint(1) DEFAULT 0,
  `is_hod` tinyint(1) DEFAULT 0,
  `must_change_password` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Indexes
-- --------------------------------------------------------

ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `complaint_id` (`complaint_id`),
  ADD KEY `actor_id` (`actor_id`);

ALTER TABLE `complaints`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_number` (`ticket_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `level_adviser_id` (`level_adviser_id`),
  ADD KEY `hod_id` (`hod_id`),
  ADD KEY `hod_assigned_lecturer_id` (`hod_assigned_lecturer_id`),
  ADD KEY `lecturer_id` (`lecturer_id`);

ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_course_code` (`course_code`);

ALTER TABLE `course_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_assignment` (`course_id`,`lecturer_id`,`academic_session`,`semester`),
  ADD KEY `lecturer_id` (`lecturer_id`);

ALTER TABLE `course_offerings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_offering` (`course_id`,`department_id`,`level`,`semester`),
  ADD KEY `fk_offering_department` (`department_id`);

ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_dept_per_faculty` (`faculty_id`,`name`);

ALTER TABLE `faculties`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_faculty_name` (`name`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `complaint_id` (`complaint_id`);

ALTER TABLE `otp_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_purpose` (`purpose`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `matric_number` (`matric_number`),
  ADD UNIQUE KEY `PF_NO` (`PF_NO`),
  ADD UNIQUE KEY `supabase_uid` (`supabase_uid`);

-- --------------------------------------------------------
-- AUTO_INCREMENT
-- --------------------------------------------------------

ALTER TABLE `audit_log`            MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `complaints`           MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `courses`              MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `course_assignments`   MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `course_offerings`     MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `departments`          MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `faculties`            MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `notifications`        MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `otp_codes`            MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `users`                MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------
-- Foreign key constraints
-- --------------------------------------------------------

ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`),
  ADD CONSTRAINT `audit_log_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`);

ALTER TABLE `complaints`
  ADD CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `complaints_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `complaints_ibfk_3` FOREIGN KEY (`level_adviser_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `complaints_ibfk_4` FOREIGN KEY (`hod_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `complaints_ibfk_5` FOREIGN KEY (`hod_assigned_lecturer_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `complaints_ibfk_6` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`);

ALTER TABLE `course_assignments`
  ADD CONSTRAINT `course_assignments_fk_course`  FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `course_assignments_ibfk_2`    FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `course_offerings`
  ADD CONSTRAINT `fk_offering_course`     FOREIGN KEY (`course_id`)     REFERENCES `courses`     (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_offering_department`  FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON UPDATE CASCADE;

ALTER TABLE `departments`
  ADD CONSTRAINT `fk_dept_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`id`) ON UPDATE CASCADE;

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
