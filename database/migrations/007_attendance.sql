-- ============================================================
-- Migration 007: Attendance Module
-- Tables: attendance_sessions, staff_attendance, member_attendance
-- Applies to: all institution types
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Configurable attendance sessions per institution
CREATE TABLE IF NOT EXISTS `attendance_sessions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `label`          VARCHAR(50)  NOT NULL,
  `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_session` (`institution_id`, `label`),
  CONSTRAINT `fk_att_sess_inst` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily staff attendance (from_time / to_time + leave support)
CREATE TABLE IF NOT EXISTS `staff_attendance` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `institution_id`  INT UNSIGNED  NOT NULL,
  `staff_id`        INT UNSIGNED  NOT NULL,
  `attendance_date` DATE          NOT NULL,
  `from_time`       TIME          NULL DEFAULT NULL,
  `to_time`         TIME          NULL DEFAULT NULL,
  `status`          ENUM('present','absent','half_day','leave') NOT NULL DEFAULT 'absent',
  `leave_type`      ENUM('sick','casual','earned','unpaid','other') NULL DEFAULT NULL,
  `remarks`         VARCHAR(300)  NULL DEFAULT NULL,
  `marked_by`       INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_att`    (`institution_id`, `staff_id`, `attendance_date`),
  KEY `idx_sa_inst_date`       (`institution_id`, `attendance_date`),
  CONSTRAINT `fk_sa_inst`      FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sa_staff`     FOREIGN KEY (`staff_id`)       REFERENCES `staff`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_sa_marked_by` FOREIGN KEY (`marked_by`)      REFERENCES `users`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session-based attendance for students (school) and members (others)
CREATE TABLE IF NOT EXISTS `member_attendance` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `institution_id`  INT UNSIGNED  NOT NULL,
  `entity_type`     ENUM('student','member') NOT NULL,
  `entity_id`       INT UNSIGNED  NOT NULL COMMENT 'students.id or members.id',
  `session_id`      INT UNSIGNED  NOT NULL,
  `attendance_date` DATE          NOT NULL,
  `status`          ENUM('present','absent','late','leave') NOT NULL DEFAULT 'absent',
  `leave_type`      ENUM('sick','casual','other') NULL DEFAULT NULL,
  `remarks`         VARCHAR(300)  NULL DEFAULT NULL,
  `marked_by`       INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_att`   (`institution_id`, `entity_type`, `entity_id`, `session_id`, `attendance_date`),
  KEY `idx_ma_inst_date`       (`institution_id`, `attendance_date`),
  KEY `idx_ma_session`         (`session_id`),
  CONSTRAINT `fk_ma_inst`      FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_ma_session`   FOREIGN KEY (`session_id`)     REFERENCES `attendance_sessions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ma_marked_by` FOREIGN KEY (`marked_by`)      REFERENCES `users`(`id`)               ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menu Items ───────────────────────────────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('services.staff_attendance', 'services', 'Staff Attendance',
   '/app/services/staff-attendance', 'bi-person-check-fill',
   'linear-gradient(135deg,#059669,#10b981)',
   'Mark and manage daily attendance for all staff members.',
   NULL, 'institution_admin', 20),

  ('services.attendance', 'services', 'Mark Attendance',
   '/app/services/attendance', 'bi-clipboard-check-fill',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Mark morning and evening attendance for students or members.',
   NULL, 'any', 21),

  ('settings.attendance_sessions', 'settings', 'Attendance Sessions',
   '/app/settings/attendance-sessions', 'bi-clock-fill',
   'linear-gradient(135deg,#6f42c1,#9c68f0)',
   'Configure attendance sessions (Morning, Evening, custom batches).',
   NULL, 'institution_admin', 15),

  ('reports.staff_attendance', 'reports', 'Staff Attendance',
   '/app/reports/staff-attendance', 'bi-person-lines-fill',
   'linear-gradient(135deg,#059669,#10b981)',
   'Monthly pivot attendance report for all staff members.',
   NULL, 'institution_admin', 20),

  ('reports.attendance', 'reports', 'Attendance Report',
   '/app/reports/attendance', 'bi-table',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Monthly attendance pivot for students or members by session.',
   NULL, 'any', 21)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);

SET FOREIGN_KEY_CHECKS = 1;
