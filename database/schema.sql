-- ============================================================
-- SportsInfraX – Membership & Training Management
-- Database Schema v1.0
-- By SportsByA Tech (OPC) Private Limited
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -------------------------------------------------------
-- users
-- Stores all platform users: super_admin, institution_admin, staff
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `email`          VARCHAR(255)     NOT NULL,
  `username`       VARCHAR(100)     NULL DEFAULT NULL
                   COMMENT 'Optional institution-scoped login alias (case-insensitive, globally unique)',
  `password`       VARCHAR(255)     NOT NULL,
  `role`           ENUM('super_admin','institution_admin','staff') NOT NULL,
  `institution_id` INT UNSIGNED     NULL DEFAULT NULL,
  `full_name`      VARCHAR(255)     NOT NULL,
  `mobile`         VARCHAR(20)      NULL DEFAULT NULL,
  `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
  `last_login`     DATETIME         NULL DEFAULT NULL,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- institution_registrations
-- Pending registration requests from institutions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `institution_registrations` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_name` VARCHAR(255) NOT NULL,
  `spoc_name`        VARCHAR(255) NOT NULL,
  `mobile`           VARCHAR(20)  NOT NULL,
  `email`            VARCHAR(255) NOT NULL,
  `address`          TEXT         NOT NULL,
  `status`           ENUM('pending','converted','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` TEXT         NULL DEFAULT NULL,
  `institution_id`   INT UNSIGNED NULL DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- institutions
-- Approved/active institutions on the platform
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `institutions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `registration_id`  INT UNSIGNED NULL DEFAULT NULL,
  `institution_name` VARCHAR(255) NOT NULL,
  `institution_type` VARCHAR(50) NOT NULL DEFAULT 'academy',
  `logo`             VARCHAR(500) NULL DEFAULT NULL,
  `reg_number`       VARCHAR(100) NULL DEFAULT NULL,
  `reg_document`     VARCHAR(500) NULL DEFAULT NULL,
  `address`          TEXT         NOT NULL,
  `city`             VARCHAR(100) NULL DEFAULT NULL,
  `state`            VARCHAR(100) NULL DEFAULT NULL,
  `pincode`          VARCHAR(20)  NULL DEFAULT NULL,
  `country`          VARCHAR(100) NOT NULL DEFAULT 'India',
  `website`          VARCHAR(255) NULL DEFAULT NULL,
  `contact_email`    VARCHAR(255) NULL DEFAULT NULL,
  `contact_phone`    VARCHAR(20)  NULL DEFAULT NULL,
  `admin_id`         INT UNSIGNED NULL DEFAULT NULL,
  `status`           ENUM('pending_profile','pending_approval','active','suspended') NOT NULL DEFAULT 'pending_profile',
  `valid_from`       DATE         NULL DEFAULT NULL,
  `valid_until`      DATE         NULL DEFAULT NULL,
  `approved_at`      DATETIME     NULL DEFAULT NULL,
  `approved_by`      INT UNSIGNED NULL DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_institutions_status` (`status`),
  KEY `idx_institutions_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- sch_staff_roles
-- School-specific staff role master (sch_ prefix convention).
-- institution_id = NULL means system default, visible to all schools.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sch_staff_roles` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id`  INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'NULL = system default for all school institutions',
  `label`           VARCHAR(100) NOT NULL,
  `description`     VARCHAR(255) NULL DEFAULT NULL,
  `sort_order`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scr_institution` (`institution_id`),
  CONSTRAINT `fk_scr_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- staff
-- Staff members linked to users and institutions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `institution_id` INT UNSIGNED NOT NULL,
  `staff_type`     ENUM('manager','coach','trainer','receptionist','accounts','operations','other') NOT NULL DEFAULT 'other',
  `sch_role_id`    INT UNSIGNED NULL DEFAULT NULL,
  `department`     VARCHAR(100) NULL DEFAULT NULL,
  `joining_date`   DATE         NULL DEFAULT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `passport_photo` VARCHAR(500) NULL DEFAULT NULL,
  `created_by`     INT UNSIGNED NULL DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_institution_id` (`institution_id`),
  KEY `idx_staff_user_id` (`user_id`),
  CONSTRAINT `fk_staff_user_id`        FOREIGN KEY (`user_id`)        REFERENCES `users`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_staff_institution_id` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_sch_role`       FOREIGN KEY (`sch_role_id`)    REFERENCES `sch_staff_roles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- members
-- Individual members registered under an institution
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `members` (
  `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_code`               VARCHAR(50)  NOT NULL,
  `institution_id`            INT UNSIGNED NOT NULL,

  -- Personal
  `first_name`                VARCHAR(100) NOT NULL,
  `last_name`                 VARCHAR(100) NOT NULL,
  `date_of_birth`             DATE         NULL DEFAULT NULL,
  `gender`                    ENUM('male','female','other') NULL DEFAULT NULL,
  `blood_group`               ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NULL DEFAULT NULL,
  `nationality`               VARCHAR(100) NOT NULL DEFAULT 'Indian',

  -- Contact
  `email`                     VARCHAR(255) NULL DEFAULT NULL,
  `mobile`                    VARCHAR(20)  NOT NULL,
  `alternate_mobile`          VARCHAR(20)  NULL DEFAULT NULL,

  -- Address
  `address`                   TEXT         NULL DEFAULT NULL,
  `city`                      VARCHAR(100) NULL DEFAULT NULL,
  `state`                     VARCHAR(100) NULL DEFAULT NULL,
  `pincode`                   VARCHAR(20)  NULL DEFAULT NULL,

  -- Identity
  `id_type`                   ENUM('aadhar','pan','passport','voter_id','driving_license','other') NULL DEFAULT NULL,
  `id_number`                 VARCHAR(100) NULL DEFAULT NULL,

  -- Emergency Contact
  `emergency_contact_name`    VARCHAR(255) NULL DEFAULT NULL,
  `emergency_contact_mobile`  VARCHAR(20)  NULL DEFAULT NULL,
  `emergency_contact_relation`VARCHAR(100) NULL DEFAULT NULL,

  -- Medical
  `medical_conditions`        TEXT         NULL DEFAULT NULL,

  -- Photo
  `passport_photo`            VARCHAR(500) NULL DEFAULT NULL,

  -- Sports
  `sport_category`            VARCHAR(100) NULL DEFAULT NULL,
  `experience_level`          ENUM('beginner','intermediate','advanced','professional') NULL DEFAULT NULL,

  `is_active`                 TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`                INT UNSIGNED NULL DEFAULT NULL,
  `created_at`                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_members_code` (`member_code`),
  KEY `idx_members_institution_id` (`institution_id`),
  KEY `idx_members_mobile` (`mobile`),
  CONSTRAINT `fk_members_institution_id` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- memberships
-- Membership records (new or renewal) per member
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `memberships` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `membership_number` VARCHAR(50)     NULL DEFAULT NULL,
  `member_id`         INT UNSIGNED    NOT NULL,
  `institution_id`    INT UNSIGNED    NOT NULL,
  `membership_type`   ENUM('new','renewal') NOT NULL DEFAULT 'new',
  `plan_name`         VARCHAR(255)    NOT NULL,
  `duration_months`   INT             NOT NULL DEFAULT 1,
  `start_date`        DATE            NOT NULL,
  `end_date`          DATE            NOT NULL,
  `amount`            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `discount`          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `net_amount`        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `payment_status`    ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending',
  `notes`             TEXT            NULL DEFAULT NULL,
  `created_by`        INT UNSIGNED    NULL DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_membership_number` (`membership_number`),
  KEY `idx_memberships_member_id` (`member_id`),
  KEY `idx_memberships_institution_id` (`institution_id`),
  CONSTRAINT `fk_memberships_member_id`      FOREIGN KEY (`member_id`)      REFERENCES `members`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_memberships_institution_id` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- membership_payments
-- One or more payments attached to a membership
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `membership_payments` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `membership_id`     INT UNSIGNED    NOT NULL,
  `payment_date`      DATE            NOT NULL,
  `amount`            DECIMAL(10,2)   NOT NULL,
  `payment_mode`      ENUM('cash','cheque','upi','card','online','bank_transfer','other') NOT NULL,
  `transaction_ref`   VARCHAR(255)    NULL DEFAULT NULL,
  `receipt_number`    VARCHAR(100)    NULL DEFAULT NULL,
  `payment_proof`     VARCHAR(500)    NULL DEFAULT NULL,
  `remarks`           TEXT            NULL DEFAULT NULL,
  `recorded_by`       INT UNSIGNED    NULL DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mp_membership_id` (`membership_id`),
  CONSTRAINT `fk_mp_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- institution_types
-- Master list of institution types (add rows to extend)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `institution_types` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `value`      VARCHAR(50)   NOT NULL,
  `label`      VARCHAR(100)  NOT NULL,
  `category`   VARCHAR(50)   NOT NULL DEFAULT 'general'
                             COMMENT 'association | school | sports_club | general',
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inst_type_value` (`value`),
  KEY `idx_inst_type_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- menu_items
-- Registry of navigable hub-page cards per section.
-- Coming-soon items are hardcoded in PHP, not here.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `item_key`             VARCHAR(100)  NOT NULL               COMMENT 'Unique dotted key, e.g. members.sc_list',
  `parent_menu`          VARCHAR(50)   NOT NULL               COMMENT 'Section: institution | members | accounts | services | reports | settings',
  `label`                VARCHAR(100)  NOT NULL,
  `route`                VARCHAR(255)  NOT NULL               COMMENT 'App-root-relative path, e.g. /app/members/list',
  `icon`                 VARCHAR(100)  NOT NULL DEFAULT 'bi-circle-fill',
  `gradient`             VARCHAR(255)  NOT NULL DEFAULT 'linear-gradient(135deg,#64748b,#94a3b8)',
  `description`          TEXT          NULL,
  `applies_to_category`  VARCHAR(50)   NULL                   COMMENT 'NULL = all categories',
  `required_role`        ENUM('institution_admin','staff','any') NOT NULL DEFAULT 'any',
  `required_permission`  VARCHAR(100)  NULL                   COMMENT 'NULL = no extra permission needed',
  `sort_order`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`            TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menu_item_key` (`item_key`),
  KEY `idx_menu_parent_cat` (`parent_menu`, `applies_to_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- staff_permissions
-- Granular module/action/scope permissions for staff users.
-- institution_admin always has full access without entries here.
-- -------------------------------------------------------
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
-- approval_requests
-- Generic entity-based approval workflow.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `approval_requests` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`     VARCHAR(50)  NOT NULL COMMENT 'membership_payment | membership',
  `entity_id`       INT UNSIGNED NOT NULL,
  `institution_id`  INT UNSIGNED NOT NULL,
  `requested_by`    INT UNSIGNED NOT NULL,
  `assigned_to`     INT UNSIGNED NULL     DEFAULT NULL,
  `status`          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `notes`           TEXT         NULL     DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ar_entity` (`entity_type`, `entity_id`),
  KEY `idx_ar_institution` (`institution_id`, `status`),
  CONSTRAINT `fk_ar_institution`  FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ar_requested_by` FOREIGN KEY (`requested_by`)   REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- approval_history
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `approval_history` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  INT UNSIGNED    NOT NULL,
  `actor_id`    INT UNSIGNED    NULL DEFAULT NULL,
  `action`      ENUM('submitted','approved','rejected','cancelled','commented') NOT NULL,
  `comment`     TEXT NULL DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ah_request` (`request_id`),
  CONSTRAINT `fk_ah_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- notifications
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  NOT NULL,
  `institution_id`  INT UNSIGNED  NULL DEFAULT NULL,
  `type`            VARCHAR(50)   NOT NULL,
  `title`           VARCHAR(255)  NOT NULL,
  `body`            TEXT          NULL DEFAULT NULL,
  `link`            VARCHAR(500)  NULL DEFAULT NULL,
  `is_read`         TINYINT(1)    NOT NULL DEFAULT 0,
  `read_at`         DATETIME      NULL DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user`    (`user_id`, `is_read`),
  KEY `idx_notif_created` (`created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- notification_queue
-- External channel queue (email / SMS / WhatsApp).
-- Processed by app/cron/send_notifications.php every 5 min.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_queue` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id`  INT UNSIGNED NULL DEFAULT NULL,
  `channel`          ENUM('email','sms','whatsapp') NOT NULL,
  `recipient`        VARCHAR(255) NOT NULL,
  `subject`          VARCHAR(255) NULL DEFAULT NULL,
  `body`             TEXT         NOT NULL,
  `status`           ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  `attempts`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`       TEXT NULL DEFAULT NULL,
  `scheduled_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`          DATETIME NULL DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nq_status` (`status`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- conversations
-- Institution ↔ Member message threads. Append-only.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conversations` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `institution_id`   INT UNSIGNED  NOT NULL,
  `member_id`        INT UNSIGNED  NOT NULL,
  `subject`          VARCHAR(255)  NOT NULL DEFAULT 'General',
  `is_locked`        TINYINT(1)    NOT NULL DEFAULT 0,
  `created_by`       INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at`  DATETIME      NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conv_institution` (`institution_id`, `last_message_at`),
  KEY `idx_conv_member`      (`member_id`),
  CONSTRAINT `fk_conv_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_member`      FOREIGN KEY (`member_id`)      REFERENCES `members`(`id`)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- messages  (NO DELETE endpoint – append-only by design)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id`  INT UNSIGNED    NOT NULL,
  `sender_type`      ENUM('staff','member') NOT NULL,
  `sender_id`        INT UNSIGNED    NULL DEFAULT NULL,
  `body`             TEXT            NOT NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_conv` (`conversation_id`, `created_at`),
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- message_receipts  (per-user read + archive; never deleted)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_receipts` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `message_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED    NOT NULL,
  `is_read`     TINYINT(1)      NOT NULL DEFAULT 0,
  `is_archived` TINYINT(1)      NOT NULL DEFAULT 0,
  `read_at`     DATETIME        NULL DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt` (`message_id`, `user_id`),
  KEY `idx_receipt_user` (`user_id`, `is_read`),
  CONSTRAINT `fk_mr_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mr_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- field_change_log
-- Tracks changes to sensitive / important fields.
-- Sensitive values (id_number) are stored masked (XXXX1234).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `field_change_log` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`    VARCHAR(50)     NOT NULL COMMENT 'member | staff | institution',
  `entity_id`      INT UNSIGNED    NOT NULL,
  `institution_id` INT UNSIGNED    NULL DEFAULT NULL,
  `changed_by`     INT UNSIGNED    NULL DEFAULT NULL,
  `field_name`     VARCHAR(100)    NOT NULL,
  `old_value`      TEXT            NULL DEFAULT NULL,
  `new_value`      TEXT            NULL DEFAULT NULL,
  `ip_address`     VARCHAR(45)     NULL DEFAULT NULL,
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
  `institution_id`  INT UNSIGNED  NULL DEFAULT NULL,
  `file_category`   VARCHAR(50)   NOT NULL COMMENT 'photo | logo | document | payment_proof | id_document',
  `original_name`   VARCHAR(255)  NULL DEFAULT NULL,
  `stored_name`     VARCHAR(255)  NOT NULL COMMENT 'hex-generated filename',
  `storage_path`    VARCHAR(500)  NOT NULL COMMENT 'Relative path from UPLOAD_ROOT',
  `mime_type`       VARCHAR(100)  NOT NULL,
  `file_size`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `is_sensitive`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = must be served via PHP gate',
  `uploaded_by`     INT UNSIGNED  NULL DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_entity`      (`entity_type`, `entity_id`),
  KEY `idx_att_institution` (`institution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- academic_years  (school institutions only)
-- One active year per institution at a time.
-- -------------------------------------------------------
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

-- -------------------------------------------------------
-- classes  (school institutions only)
-- -------------------------------------------------------
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

-- -------------------------------------------------------
-- divisions  (school institutions only)
-- Shared across all classes/years for the institution.
-- -------------------------------------------------------
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

-- -------------------------------------------------------
-- sections  (school institutions only)
-- One section per (institution, academic_year, class, division).
-- -------------------------------------------------------
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

-- -------------------------------------------------------
-- students  (school institutions only)
-- One student per section (class + division + year).
-- -------------------------------------------------------
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

-- -------------------------------------------------------
-- Table: holiday_calendar  (all institution types)
-- -------------------------------------------------------
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

SET FOREIGN_KEY_CHECKS = 1;

-- -------------------------------------------------------
-- Seed: Default Super Admin
-- Default credentials: admin@sportsinfrax.com / Admin@123
-- CHANGE PASSWORD IMMEDIATELY AFTER FIRST LOGIN
-- -------------------------------------------------------
INSERT INTO `users` (`email`, `password`, `role`, `full_name`, `mobile`, `is_active`)
VALUES (
  'admin@sportsinfrax.com',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'super_admin',
  'SportsInfraX Admin',
  '9999999999',
  1
)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- -------------------------------------------------------
-- Seed: Institution Types
-- -------------------------------------------------------
-- category: 'association' | 'school' | 'sports_club' | 'general'
-- Each category gets its own application flow, masters, and UI for institution admin & staff.
INSERT INTO `institution_types` (`value`, `label`, `category`, `sort_order`) VALUES
  ('academy',          'Sports Academy',                   'sports_club',  1),
  ('club',             'Sports Club',                      'sports_club',  2),
  ('association',      'Sports Association',               'association',  3),
  ('school',           'School / Educational Institution', 'school',       4),
  ('training_centre',  'Training Centre',                  'sports_club',  5),
  ('complex',          'Sports Complex',                   'sports_club',  6),
  ('stadium',          'Stadium',                          'sports_club',  7),
  ('gym',              'Gym / Fitness Centre',             'sports_club',  8),
  ('swimming_pool',    'Swimming Pool',                    'sports_club',  9),
  ('turf',             'Turf / Ground',                    'sports_club', 10),
  ('other',            'Other',                            'general',     99)
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `category`   = VALUES(`category`),
  `sort_order` = VALUES(`sort_order`);

-- -------------------------------------------------------
-- Seed: Default School Staff Roles (institution_id = NULL = all schools)
-- -------------------------------------------------------
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
-- Seed: School-specific menu items (Academic Years, Classes, Divisions, Sections)
-- applies_to_category = 'school' means they only appear for school institutions.
-- -------------------------------------------------------
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
   'school', 'any', 10),

  ('services.students', 'services', 'Students',
   '/app/services/students', 'bi-mortarboard-fill',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Manage student enrollment and section assignments.',
   'school', 'any', 5),

  -- Holidays & Calendar: applies to ALL institution types (NULL)
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
