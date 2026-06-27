-- ============================================================
-- Migration 012: Exams & Marks (School)
-- Tables: exam_types, exams, exam_marks
-- Applies to: school institutions
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Exam categories (Unit Test, Term 1, Annual Exam, etc.)
CREATE TABLE IF NOT EXISTS `exam_types` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED     NOT NULL,
  `name`           VARCHAR(100)     NOT NULL,
  `max_marks`      DECIMAL(6,2)     NOT NULL DEFAULT 100.00,
  `pass_marks`     DECIMAL(6,2)     NOT NULL DEFAULT 35.00,
  `is_grade_based` TINYINT(1)       NOT NULL DEFAULT 0,
  `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_et_name` (`institution_id`, `name`),
  CONSTRAINT `fk_et_inst` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Specific exam instances per section
CREATE TABLE IF NOT EXISTS `exams` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id`   INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NULL DEFAULT NULL,
  `exam_type_id`     INT UNSIGNED NOT NULL,
  `section_id`       INT UNSIGNED NOT NULL,
  `label`            VARCHAR(200) NOT NULL COMMENT 'Auto or custom label for this exam',
  `start_date`       DATE         NULL DEFAULT NULL,
  `end_date`         DATE         NULL DEFAULT NULL,
  `is_published`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_by`       INT UNSIGNED NULL DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ex_inst`    (`institution_id`, `academic_year_id`),
  KEY `idx_ex_section` (`section_id`),
  CONSTRAINT `fk_ex_inst`    FOREIGN KEY (`institution_id`)   REFERENCES `institutions`(`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_ex_ay`      FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ex_type`    FOREIGN KEY (`exam_type_id`)     REFERENCES `exam_types`(`id`)     ON DELETE RESTRICT,
  CONSTRAINT `fk_ex_section` FOREIGN KEY (`section_id`)       REFERENCES `sections`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_ex_creator` FOREIGN KEY (`created_by`)       REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marks per student per subject per exam
CREATE TABLE IF NOT EXISTS `exam_marks` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`        INT UNSIGNED NOT NULL,
  `institution_id` INT UNSIGNED NOT NULL,
  `student_id`     INT UNSIGNED NOT NULL,
  `subject_id`     INT UNSIGNED NOT NULL,
  `marks_obtained` DECIMAL(6,2) NULL DEFAULT NULL,
  `is_absent`      TINYINT(1)   NOT NULL DEFAULT 0,
  `grade`          VARCHAR(5)   NULL DEFAULT NULL,
  `remarks`        VARCHAR(200) NULL DEFAULT NULL,
  `entered_by`     INT UNSIGNED NULL DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_em_slot`  (`exam_id`, `student_id`, `subject_id`),
  KEY `idx_em_inst`        (`institution_id`),
  CONSTRAINT `fk_em_exam`    FOREIGN KEY (`exam_id`)        REFERENCES `exams`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_em_inst`    FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_em_student` FOREIGN KEY (`student_id`)     REFERENCES `students`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_em_subject` FOREIGN KEY (`subject_id`)     REFERENCES `subjects`(`id`)      ON DELETE RESTRICT,
  CONSTRAINT `fk_em_enterer` FOREIGN KEY (`entered_by`)     REFERENCES `users`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menu Items ───────────────────────────────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('settings.exam_types', 'settings', 'Exam Types',
   '/app/settings/exam-types', 'bi-clipboard-check-fill',
   'linear-gradient(135deg,#d97706,#f59e0b)',
   'Define exam categories (Unit Test, Term Exam, Annual Exam) with pass criteria.',
   'school', 'institution_admin', 27),

  ('services.exams', 'services', 'Exams',
   '/app/services/exams', 'bi-pencil-square',
   'linear-gradient(135deg,#dc3545,#e85d6f)',
   'Create and manage exam schedules for each section.',
   'school', 'institution_admin', 40),

  ('services.exam_marks', 'services', 'Enter Marks',
   '/app/services/exam-marks', 'bi-input-cursor-text',
   'linear-gradient(135deg,#6f42c1,#9c68f0)',
   'Enter or edit student marks for a selected exam.',
   'school', 'any', 41),

  ('reports.exam_marks', 'reports', 'Marks Report',
   '/app/reports/exam-marks', 'bi-bar-chart-line-fill',
   'linear-gradient(135deg,#059669,#10b981)',
   'Subject-wise marks report for a section exam with pass/fail summary.',
   'school', 'any', 30)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);

SET FOREIGN_KEY_CHECKS = 1;
