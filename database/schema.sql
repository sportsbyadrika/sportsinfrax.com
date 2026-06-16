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
  UNIQUE KEY `uq_users_email` (`email`)
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
  `institution_type` ENUM('academy','club','stadium','complex','gym','turf','swimming_pool','training_centre','association','school','other') NOT NULL DEFAULT 'academy',
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
-- staff
-- Staff members linked to users and institutions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `institution_id` INT UNSIGNED NOT NULL,
  `staff_type`     ENUM('manager','coach','trainer','receptionist','accounts','operations','other') NOT NULL DEFAULT 'other',
  `department`     VARCHAR(100) NULL DEFAULT NULL,
  `joining_date`   DATE         NULL DEFAULT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED NULL DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_institution_id` (`institution_id`),
  KEY `idx_staff_user_id` (`user_id`),
  CONSTRAINT `fk_staff_user_id`        FOREIGN KEY (`user_id`)        REFERENCES `users`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_staff_institution_id` FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`id`) ON DELETE CASCADE
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
