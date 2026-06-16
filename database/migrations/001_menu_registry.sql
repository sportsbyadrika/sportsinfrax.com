-- ============================================================
-- Migration 001: Menu Registry + Staff Permissions
-- ============================================================

-- -------------------------------------------------------
-- menu_items
-- Registry of all navigable hub-page cards (sub-menu items).
-- Coming-soon placeholders are hardcoded in PHP, not here.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `item_key`             VARCHAR(100)  NOT NULL               COMMENT 'Unique dotted key, e.g. members.sc_list',
  `parent_menu`          VARCHAR(50)   NOT NULL               COMMENT 'Section key: institution | members | accounts | services | reports | settings',
  `label`                VARCHAR(100)  NOT NULL,
  `route`                VARCHAR(255)  NOT NULL               COMMENT 'App-root-relative path, e.g. /app/members/list',
  `icon`                 VARCHAR(100)  NOT NULL DEFAULT 'bi-circle-fill',
  `gradient`             VARCHAR(255)  NOT NULL DEFAULT 'linear-gradient(135deg,#64748b,#94a3b8)',
  `description`          TEXT          NULL,
  `applies_to_category`  VARCHAR(50)   NULL                   COMMENT 'NULL = all categories; or school | association | sports_club | general',
  `required_role`        ENUM('institution_admin','staff','any') NOT NULL DEFAULT 'any',
  `required_permission`  VARCHAR(100)  NULL                   COMMENT 'NULL = no extra permission; staff_permissions.permission_key value',
  `sort_order`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`            TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menu_item_key` (`item_key`),
  KEY `idx_menu_parent_cat` (`parent_menu`, `applies_to_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- staff_permissions
-- Granular permission keys granted to individual staff users
-- per institution. institution_admin always has all access.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff_permissions` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `institution_id`  INT UNSIGNED NOT NULL,
  `permission_key`  VARCHAR(100) NOT NULL,
  `granted_by`      INT UNSIGNED NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_perm` (`user_id`, `institution_id`, `permission_key`),
  KEY `idx_sp_institution` (`institution_id`),
  CONSTRAINT `fk_sp_user`        FOREIGN KEY (`user_id`)        REFERENCES `users`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_sp_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Seed: Institution section (all categories, admin only)
-- -------------------------------------------------------
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`, `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('institution.profile', 'institution', 'Institution Profile',
   '/app/institution-admin/profile',
   'bi-building-fill', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'View and update your institution profile, registration documents and contact details.',
   NULL, 'institution_admin', 1),

  ('institution.staff', 'institution', 'Staff Management',
   '/app/institution-admin/staff',
   'bi-people-fill', 'linear-gradient(135deg,#059669,#10b981)',
   'Add and manage staff members with role-based access to the platform.',
   NULL, 'institution_admin', 2)

ON DUPLICATE KEY UPDATE
  `label`               = VALUES(`label`),
  `route`               = VALUES(`route`),
  `icon`                = VALUES(`icon`),
  `gradient`            = VALUES(`gradient`),
  `description`         = VALUES(`description`),
  `applies_to_category` = VALUES(`applies_to_category`),
  `required_role`       = VALUES(`required_role`),
  `sort_order`          = VALUES(`sort_order`);

-- -------------------------------------------------------
-- Seed: Members section — sports_club
-- -------------------------------------------------------
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`, `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('members.sc_list', 'members', 'Member List',
   '/app/members/list',
   'bi-people-fill', 'linear-gradient(135deg,#059669,#10b981)',
   'View, search and filter all registered members.',
   'sports_club', 'any', 1),

  ('members.sc_add', 'members', 'Add New Member',
   '/app/members/add',
   'bi-person-plus-fill', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Register a new member with full application details.',
   'sports_club', 'any', 2)

ON DUPLICATE KEY UPDATE
  `label`               = VALUES(`label`),
  `route`               = VALUES(`route`),
  `icon`                = VALUES(`icon`),
  `gradient`            = VALUES(`gradient`),
  `description`         = VALUES(`description`),
  `applies_to_category` = VALUES(`applies_to_category`),
  `required_role`       = VALUES(`required_role`),
  `sort_order`          = VALUES(`sort_order`);

-- -------------------------------------------------------
-- Seed: Members section — association
-- (uses same list/add pages for now; will diverge in Stage N)
-- -------------------------------------------------------
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`, `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('members.as_list', 'members', 'Member List',
   '/app/members/list',
   'bi-people-fill', 'linear-gradient(135deg,#059669,#10b981)',
   'View and manage all registered association members.',
   'association', 'any', 1),

  ('members.as_add', 'members', 'Add Member',
   '/app/members/add',
   'bi-person-plus-fill', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Register a new association member.',
   'association', 'any', 2)

ON DUPLICATE KEY UPDATE
  `label`               = VALUES(`label`),
  `route`               = VALUES(`route`),
  `icon`                = VALUES(`icon`),
  `gradient`            = VALUES(`gradient`),
  `description`         = VALUES(`description`),
  `applies_to_category` = VALUES(`applies_to_category`),
  `required_role`       = VALUES(`required_role`),
  `sort_order`          = VALUES(`sort_order`);

-- -------------------------------------------------------
-- Seed: Members section — general
-- -------------------------------------------------------
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`, `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('members.ge_list', 'members', 'Member List',
   '/app/members/list',
   'bi-people-fill', 'linear-gradient(135deg,#059669,#10b981)',
   'View, search and filter all registered members.',
   'general', 'any', 1),

  ('members.ge_add', 'members', 'Add New Member',
   '/app/members/add',
   'bi-person-plus-fill', 'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Register a new member with full application details.',
   'general', 'any', 2)

ON DUPLICATE KEY UPDATE
  `label`               = VALUES(`label`),
  `route`               = VALUES(`route`),
  `icon`                = VALUES(`icon`),
  `gradient`            = VALUES(`gradient`),
  `description`         = VALUES(`description`),
  `applies_to_category` = VALUES(`applies_to_category`),
  `required_role`       = VALUES(`required_role`),
  `sort_order`          = VALUES(`sort_order`);

-- school: no rows yet — all items are coming-soon hardcoded in PHP.
-- Rows will be seeded when school modules are built (Stage N per F.9 mapping).
