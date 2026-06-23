SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── users: optional institution-scoped login username ─────
ALTER TABLE `users`
  ADD COLUMN `username` VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Optional institution-scoped login alias (case-insensitive, globally unique)'
    AFTER `email`,
  ADD UNIQUE KEY `uq_users_username` (`username`);

-- ── staff: passport photo column ─────────────────────────
ALTER TABLE `staff`
  ADD COLUMN `passport_photo` VARCHAR(500) NULL DEFAULT NULL
    AFTER `is_active`;

-- ── academic_years (school institutions only) ─────────────
CREATE TABLE IF NOT EXISTS `academic_years` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `label`          VARCHAR(50)  NOT NULL
                   COMMENT 'Display label, e.g. 2025-26',
  `start_date`     DATE         NOT NULL,
  `end_date`       DATE         NOT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 0
                   COMMENT 'Only one active year per institution at a time',
  `created_by`     INT UNSIGNED NULL DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ay_inst_label` (`institution_id`, `label`),
  KEY `idx_ay_institution` (`institution_id`, `is_active`),
  CONSTRAINT `fk_ay_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── classes ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `classes` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `name`           VARCHAR(100) NOT NULL
                   COMMENT 'e.g. Grade 1, Class 8, LKG, UKG',
  `numeric_order`  TINYINT UNSIGNED NOT NULL DEFAULT 0
                   COMMENT 'Sort key (0=LKG, 1=UKG, 3=Grade 1 …)',
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_class_inst_name` (`institution_id`, `name`),
  KEY `idx_class_inst` (`institution_id`),
  CONSTRAINT `fk_class_inst` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── divisions ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `divisions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `name`           VARCHAR(20)  NOT NULL
                   COMMENT 'e.g. A, B, C, Rose, Lily',
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_division_inst_name` (`institution_id`, `name`),
  KEY `idx_div_inst` (`institution_id`),
  CONSTRAINT `fk_div_inst` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── sections: class + division + year assignment ──────────
-- UNIQUE KEY enforces exactly one section per (institution, year, class, division)
CREATE TABLE IF NOT EXISTS `sections` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id`   INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `class_id`         INT UNSIGNED NOT NULL,
  `division_id`      INT UNSIGNED NOT NULL,
  `class_teacher_id` INT UNSIGNED NULL DEFAULT NULL,
  `capacity`         SMALLINT UNSIGNED NULL DEFAULT NULL,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`       INT UNSIGNED NULL DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_section` (`institution_id`, `academic_year_id`, `class_id`, `division_id`),
  KEY `idx_section_inst_year` (`institution_id`, `academic_year_id`),
  CONSTRAINT `fk_section_inst`     FOREIGN KEY (`institution_id`)   REFERENCES `institutions`(`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_section_ay`       FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_section_class`    FOREIGN KEY (`class_id`)         REFERENCES `classes`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_section_division` FOREIGN KEY (`division_id`)      REFERENCES `divisions`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_section_teacher`  FOREIGN KEY (`class_teacher_id`) REFERENCES `staff`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── menu_items: school-specific hub cards ─────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('settings.academic_years', 'settings', 'Academic Years',
   '/app/settings/academic-years', 'bi-calendar2-range-fill',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Manage academic sessions and set the active year for your school.',
   'school', 'institution_admin', 10),

  ('settings.classes', 'settings', 'Classes',
   '/app/settings/classes', 'bi-collection-fill',
   'linear-gradient(135deg,#059669,#10b981)',
   'Define grade or class names used across your school sections.',
   'school', 'institution_admin', 11),

  ('settings.divisions', 'settings', 'Divisions',
   '/app/settings/divisions', 'bi-grid-3x2-gap-fill',
   'linear-gradient(135deg,#6f42c1,#9c68f0)',
   'Manage division labels (A, B, C, Rose, Lily …) for class sections.',
   'school', 'institution_admin', 12),

  ('services.sections', 'services', 'Sections',
   '/app/services/sections', 'bi-diagram-3-fill',
   'linear-gradient(135deg,#d97706,#f59e0b)',
   'Create and manage class–division assignments for each academic year.',
   'school', 'any', 10)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`);
