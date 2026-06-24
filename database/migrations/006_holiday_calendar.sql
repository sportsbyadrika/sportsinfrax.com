-- ============================================================
-- Migration 006: Holiday Calendar
-- Applies to: all institution types
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `holiday_calendar` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED  NOT NULL,
  `holiday_date`   DATE          NOT NULL,
  `year`           YEAR          NOT NULL COMMENT 'Derived from holiday_date for quick filtering',
  `name`           VARCHAR(150)  NOT NULL,
  `type`           ENUM('holiday','working') NOT NULL DEFAULT 'holiday'
                   COMMENT 'holiday = day off; working = special working day',
  `category`       ENUM('public_holiday','special_day','institution_holiday','event')
                   NOT NULL DEFAULT 'institution_holiday',
  `description`    VARCHAR(300)  NULL DEFAULT NULL,
  `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hc_inst_year`  (`institution_id`, `year`),
  KEY `idx_hc_inst_date`  (`institution_id`, `holiday_date`),
  CONSTRAINT `fk_hc_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hc_created_by`  FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register Settings hub card (applies to all institution types: NULL = all)
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('settings.holidays', 'settings', 'Holidays & Calendar',
   '/app/settings/holidays', 'bi-calendar-event-fill',
   'linear-gradient(135deg,#dc3545,#e85d6f)',
   'Manage holidays, important days and events for your institution.',
   NULL, 'institution_admin', 20)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);

SET FOREIGN_KEY_CHECKS = 1;
