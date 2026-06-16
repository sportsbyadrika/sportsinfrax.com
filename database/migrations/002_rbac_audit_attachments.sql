-- ============================================================
-- Migration 002: RBAC extension, audit log, attachment store
-- ============================================================

-- -------------------------------------------------------
-- Restructure staff_permissions: flat key → module/action/scope
-- (Safe: table has no data at this point)
-- -------------------------------------------------------
DROP TABLE IF EXISTS `staff_permissions`;
CREATE TABLE IF NOT EXISTS `staff_permissions` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `institution_id`  INT UNSIGNED NOT NULL,
  `module`          VARCHAR(50)  NOT NULL COMMENT 'e.g. students | accounts | reports',
  `action`          VARCHAR(50)  NOT NULL COMMENT 'e.g. view | create | edit | delete',
  `scope`           VARCHAR(50)  NOT NULL DEFAULT 'all'
                    COMMENT 'all | own_class | own_batch | own_subject',
  `granted_by`      INT UNSIGNED NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_perm` (`user_id`, `institution_id`, `module`, `action`, `scope`),
  KEY `idx_sp_user_inst` (`user_id`, `institution_id`),
  CONSTRAINT `fk_sp2_user`        FOREIGN KEY (`user_id`)        REFERENCES `users`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_sp2_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- sch_staff_roles
-- School-specific staff role master (sch_ prefix convention).
-- institution_id = NULL means system default, visible to all schools.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sch_staff_roles` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id`  INT UNSIGNED NULL COMMENT 'NULL = system default for all school institutions',
  `label`           VARCHAR(100) NOT NULL,
  `description`     VARCHAR(255) NULL,
  `sort_order`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scr_institution` (`institution_id`),
  CONSTRAINT `fk_scr_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default school staff roles (institution_id = NULL = all schools)
INSERT INTO `sch_staff_roles` (`institution_id`, `label`, `description`, `sort_order`) VALUES
(NULL, 'Principal',            'Head of the institution',                          1),
(NULL, 'Vice Principal',       'Deputy head of the institution',                   2),
(NULL, 'Class Teacher',        'Primary teacher responsible for a class/section',  3),
(NULL, 'Subject Teacher',      'Teaches specific subjects across classes',          4),
(NULL, 'Physical Education',   'PE teacher and sports coordination',                5),
(NULL, 'Librarian',            'Library management and reading programs',           6),
(NULL, 'Administrative Staff', 'Administrative and clerical functions',             7),
(NULL, 'Accounts Staff',       'Fee collection and financial records',              8),
(NULL, 'Lab Assistant',        'Science / computer lab maintenance',                9),
(NULL, 'Counselor',            'Student counseling and welfare',                   10)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- -------------------------------------------------------
-- Add sch_role_id to staff table
-- -------------------------------------------------------
ALTER TABLE `staff`
  ADD COLUMN `sch_role_id` INT UNSIGNED NULL AFTER `staff_type`,
  ADD CONSTRAINT `fk_staff_sch_role`
      FOREIGN KEY (`sch_role_id`) REFERENCES `sch_staff_roles`(`id`) ON DELETE SET NULL;

-- -------------------------------------------------------
-- field_change_log
-- Tracks changes to sensitive / important fields.
-- Sensitive values (id_number) are stored masked (XXXX-XXXX-1234).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `field_change_log` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`    VARCHAR(50)     NOT NULL COMMENT 'member | staff | institution',
  `entity_id`      INT UNSIGNED    NOT NULL,
  `institution_id` INT UNSIGNED    NULL,
  `changed_by`     INT UNSIGNED    NULL,
  `field_name`     VARCHAR(100)    NOT NULL,
  `old_value`      TEXT            NULL,
  `new_value`      TEXT            NULL,
  `ip_address`     VARCHAR(45)     NULL,
  `changed_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fcl_entity`      (`entity_type`, `entity_id`),
  KEY `idx_fcl_institution` (`institution_id`),
  KEY `idx_fcl_changed_at`  (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- attachments
-- Document & media store. Files with is_sensitive=1 are
-- served via app/serve/file (auth-checked). Others may be
-- served directly (photos, logos).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attachments` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `entity_type`     VARCHAR(50)   NOT NULL COMMENT 'member | institution | membership_payment | staff',
  `entity_id`       INT UNSIGNED  NOT NULL,
  `institution_id`  INT UNSIGNED  NULL,
  `file_category`   VARCHAR(50)   NOT NULL COMMENT 'photo | logo | document | payment_proof | id_document',
  `original_name`   VARCHAR(255)  NULL,
  `stored_name`     VARCHAR(255)  NOT NULL COMMENT 'hex-generated filename',
  `storage_path`    VARCHAR(500)  NOT NULL COMMENT 'Relative path from UPLOAD_ROOT',
  `mime_type`       VARCHAR(100)  NOT NULL,
  `file_size`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `is_sensitive`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = must be served via PHP gate',
  `uploaded_by`     INT UNSIGNED  NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_entity`      (`entity_type`, `entity_id`),
  KEY `idx_att_institution` (`institution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
