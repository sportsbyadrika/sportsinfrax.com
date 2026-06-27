-- ============================================================
-- Migration 011: Timetable (School)
-- Tables: timetable_periods, timetable_entries
-- Applies to: school institutions
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Time slot definitions per institution (Period 1, Lunch, PT, etc.)
CREATE TABLE IF NOT EXISTS `timetable_periods` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED     NOT NULL,
  `label`          VARCHAR(50)      NOT NULL COMMENT 'e.g. Period 1, Lunch, PT',
  `start_time`     TIME             NULL DEFAULT NULL,
  `end_time`       TIME             NULL DEFAULT NULL,
  `is_break`       TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'Non-teaching slot (lunch, recess)',
  `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tp_label` (`institution_id`, `label`),
  CONSTRAINT `fk_tp_inst` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly timetable: one entry per section × weekday × period
CREATE TABLE IF NOT EXISTS `timetable_entries` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `section_id`     INT UNSIGNED NOT NULL,
  `day_of_week`    TINYINT UNSIGNED NOT NULL COMMENT '1=Monday … 6=Saturday, 7=Sunday',
  `period_id`      INT UNSIGNED NOT NULL,
  `subject_id`     INT UNSIGNED NULL DEFAULT NULL,
  `staff_id`       INT UNSIGNED NULL DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_te_slot`  (`section_id`, `day_of_week`, `period_id`),
  KEY `idx_te_inst`        (`institution_id`),
  KEY `idx_te_staff`       (`staff_id`),
  CONSTRAINT `fk_te_inst`    FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_te_section` FOREIGN KEY (`section_id`)     REFERENCES `sections`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_te_period`  FOREIGN KEY (`period_id`)      REFERENCES `timetable_periods`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_te_subject` FOREIGN KEY (`subject_id`)     REFERENCES `subjects`(`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_te_staff`   FOREIGN KEY (`staff_id`)       REFERENCES `staff`(`id`)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menu Items ───────────────────────────────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('settings.timetable_periods', 'settings', 'Timetable Periods',
   '/app/settings/timetable-periods', 'bi-hourglass-split',
   'linear-gradient(135deg,#dc3545,#e85d6f)',
   'Define daily time slots (periods, lunch, recess) used in the timetable.',
   'school', 'institution_admin', 26),

  ('services.timetable', 'services', 'Timetable',
   '/app/services/timetable', 'bi-calendar3-week-fill',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'View and manage the weekly class timetable for each section.',
   'school', 'any', 15)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);

SET FOREIGN_KEY_CHECKS = 1;
