-- ============================================================
-- Migration 009: Fee Management (School)
-- Tables: fee_heads, fee_payments
-- Applies to: school institutions
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Fee categories defined by admin
CREATE TABLE IF NOT EXISTS `fee_heads` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED     NOT NULL,
  `name`           VARCHAR(100)     NOT NULL,
  `description`    VARCHAR(300)     NULL DEFAULT NULL,
  `frequency`      ENUM('monthly','quarterly','half_yearly','annual','one_time') NOT NULL DEFAULT 'monthly',
  `default_amount` DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fh_name`  (`institution_id`, `name`),
  CONSTRAINT `fk_fh_inst`  FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual payment records per student
CREATE TABLE IF NOT EXISTS `fee_payments` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `institution_id`   INT UNSIGNED  NOT NULL,
  `student_id`       INT UNSIGNED  NOT NULL,
  `fee_head_id`      INT UNSIGNED  NOT NULL,
  `academic_year_id` INT UNSIGNED  NULL DEFAULT NULL,
  `period_label`     VARCHAR(20)   NULL DEFAULT NULL COMMENT 'e.g. 2024-06 for monthly, 2024-Q1 for quarterly',
  `amount`           DECIMAL(10,2) NOT NULL,
  `payment_date`     DATE          NOT NULL,
  `payment_mode`     ENUM('cash','card','upi','cheque','bank_transfer','other') NOT NULL DEFAULT 'cash',
  `reference_no`     VARCHAR(100)  NULL DEFAULT NULL,
  `receipt_no`       VARCHAR(50)   NULL DEFAULT NULL,
  `remarks`          VARCHAR(300)  NULL DEFAULT NULL,
  `collected_by`     INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fp_student`  (`institution_id`, `student_id`),
  KEY `idx_fp_date`     (`institution_id`, `payment_date`),
  KEY `idx_fp_head`     (`fee_head_id`),
  CONSTRAINT `fk_fp_inst`      FOREIGN KEY (`institution_id`)   REFERENCES `institutions`(`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_fp_student`   FOREIGN KEY (`student_id`)       REFERENCES `students`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_fp_head`      FOREIGN KEY (`fee_head_id`)      REFERENCES `fee_heads`(`id`)      ON DELETE RESTRICT,
  CONSTRAINT `fk_fp_ay`        FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fp_collector` FOREIGN KEY (`collected_by`)     REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menu Items ───────────────────────────────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('settings.fee_heads', 'settings', 'Fee Heads',
   '/app/settings/fee-heads', 'bi-cash-coin',
   'linear-gradient(135deg,#059669,#10b981)',
   'Define fee categories (Tuition, Lab, Sports, etc.) and their default amounts.',
   'school', 'institution_admin', 30),

  ('services.fee_collection', 'services', 'Fee Collection',
   '/app/services/fee-collection', 'bi-cash-stack',
   'linear-gradient(135deg,#d97706,#f59e0b)',
   'Record fee payments for students and issue receipts.',
   'school', 'institution_admin', 30),

  ('reports.fees', 'reports', 'Fee Report',
   '/app/reports/fees', 'bi-file-earmark-text-fill',
   'linear-gradient(135deg,#6f42c1,#9c68f0)',
   'Monthly fee collection summary with payment-mode breakdown.',
   'school', 'institution_admin', 30)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);

SET FOREIGN_KEY_CHECKS = 1;
