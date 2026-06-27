-- ============================================================
-- Migration 008: Announcements / Notices
-- Tables: announcements
-- Applies to: all institution types
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED  NOT NULL,
  `title`          VARCHAR(200)  NOT NULL,
  `body`           TEXT          NULL,
  `type`           ENUM('announcement','notice','circular','event') NOT NULL DEFAULT 'announcement',
  `audience`       ENUM('all','staff','students') NOT NULL DEFAULT 'all',
  `is_pinned`      TINYINT(1)    NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
  `published_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`     DATE          NULL DEFAULT NULL,
  `created_by`     INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_inst_pub`  (`institution_id`, `is_active`, `published_at`),
  CONSTRAINT `fk_ann_inst`    FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ann_creator` FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menu Items ───────────────────────────────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('services.manage_announcements', 'services', 'Manage Announcements',
   '/app/institution-admin/announcements', 'bi-megaphone-fill',
   'linear-gradient(135deg,#059669,#10b981)',
   'Create and manage notices, circulars and announcements for your institution.',
   NULL, 'institution_admin', 50),

  ('services.announcements', 'services', 'Announcements',
   '/app/services/announcements', 'bi-megaphone',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'View notices and announcements from your institution.',
   NULL, 'any', 51)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);

SET FOREIGN_KEY_CHECKS = 1;
