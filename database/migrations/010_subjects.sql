-- ============================================================
-- Migration 010: Subjects & Section Assignments (School)
-- Tables: subjects, section_subjects
-- Applies to: school institutions
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Subject master list per institution
CREATE TABLE IF NOT EXISTS `subjects` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED     NOT NULL,
  `name`           VARCHAR(100)     NOT NULL,
  `code`           VARCHAR(20)      NULL DEFAULT NULL,
  `class_id`       INT UNSIGNED     NULL DEFAULT NULL COMMENT 'NULL = applicable to all classes',
  `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
  `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subj_name`  (`institution_id`, `name`, `class_id`),
  KEY `idx_subj_inst`        (`institution_id`, `is_active`),
  CONSTRAINT `fk_subj_inst`  FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subj_class` FOREIGN KEY (`class_id`)       REFERENCES `classes`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subjects assigned to a section with optional teacher
CREATE TABLE IF NOT EXISTS `section_subjects` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `staff_id`   INT UNSIGNED NULL DEFAULT NULL COMMENT 'Assigned teacher (optional)',
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ss_slot` (`section_id`, `subject_id`),
  KEY `idx_ss_staff`      (`staff_id`),
  CONSTRAINT `fk_ss_section` FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_ss_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_ss_staff`   FOREIGN KEY (`staff_id`)   REFERENCES `staff`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menu Items ───────────────────────────────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('settings.subjects', 'settings', 'Subjects',
   '/app/settings/subjects', 'bi-book-fill',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Manage the subject master list for all classes in your institution.',
   'school', 'institution_admin', 25),

  ('services.section_subjects', 'services', 'Assign Subjects',
   '/app/services/section-subjects', 'bi-journal-bookmark-fill',
   'linear-gradient(135deg,#6f42c1,#9c68f0)',
   'Assign subjects and teachers to each class section.',
   'school', 'institution_admin', 12)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);

SET FOREIGN_KEY_CHECKS = 1;
