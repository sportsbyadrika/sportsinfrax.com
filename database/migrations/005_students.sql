SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── students (school institutions only) ──────────────────
CREATE TABLE IF NOT EXISTS `students` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id`      INT UNSIGNED NOT NULL,
  `section_id`          INT UNSIGNED NOT NULL,
  `admission_number`    VARCHAR(50)  NOT NULL,
  `roll_number`         SMALLINT UNSIGNED NULL DEFAULT NULL,
  `first_name`          VARCHAR(100) NOT NULL,
  `last_name`           VARCHAR(100) NOT NULL,
  `date_of_birth`       DATE         NULL DEFAULT NULL,
  `gender`              ENUM('male','female','other') NULL DEFAULT NULL,
  `blood_group`         ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NULL DEFAULT NULL,
  `passport_photo`      VARCHAR(500) NULL DEFAULT NULL,
  `father_name`         VARCHAR(100) NULL DEFAULT NULL,
  `mother_name`         VARCHAR(100) NULL DEFAULT NULL,
  `guardian_name`       VARCHAR(100) NULL DEFAULT NULL,
  `guardian_relation`   VARCHAR(50)  NULL DEFAULT NULL,
  `mobile`              VARCHAR(20)  NULL DEFAULT NULL,
  `alternate_mobile`    VARCHAR(20)  NULL DEFAULT NULL,
  `email`               VARCHAR(255) NULL DEFAULT NULL,
  `address`             TEXT         NULL DEFAULT NULL,
  `city`                VARCHAR(100) NULL DEFAULT NULL,
  `state`               VARCHAR(100) NULL DEFAULT NULL,
  `pincode`             VARCHAR(20)  NULL DEFAULT NULL,
  `previous_school`     VARCHAR(255) NULL DEFAULT NULL,
  `admission_date`      DATE         NULL DEFAULT NULL,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`          INT UNSIGNED NULL DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_admission` (`institution_id`, `admission_number`),
  KEY `idx_student_section` (`section_id`),
  KEY `idx_student_inst` (`institution_id`),
  CONSTRAINT `fk_student_inst`    FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_section` FOREIGN KEY (`section_id`)     REFERENCES `sections`(`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── menu_item: students hub card ─────────────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('services.students', 'services', 'Students',
   '/app/services/students', 'bi-mortarboard-fill',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Manage student enrollment and section assignments.',
   'school', 'any', 5)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`);
