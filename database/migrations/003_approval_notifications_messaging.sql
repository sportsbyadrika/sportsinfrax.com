-- ============================================================
-- Migration 003: Approval workflow, notifications, messaging
-- ============================================================

-- -------------------------------------------------------
-- approval_requests
-- Generic entity-based approval workflow.
-- UNIQUE on (entity_type, entity_id) so one pending request
-- per entity at any time.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `approval_requests` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`     VARCHAR(50)  NOT NULL COMMENT 'membership_payment | membership',
  `entity_id`       INT UNSIGNED NOT NULL,
  `institution_id`  INT UNSIGNED NOT NULL,
  `requested_by`    INT UNSIGNED NOT NULL,
  `assigned_to`     INT UNSIGNED NULL     DEFAULT NULL COMMENT 'NULL = any institution_admin',
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
-- Immutable audit trail; one row per action on a request.
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
-- Per-user in-app notifications (bell icon).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  NOT NULL,
  `institution_id`  INT UNSIGNED  NULL DEFAULT NULL,
  `type`            VARCHAR(50)   NOT NULL
                    COMMENT 'approval_request|approval_status|new_message|membership_expiry|payment_recorded',
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
-- External channel sends (email / SMS / WhatsApp).
-- Processed by app/cron/send_notifications.php (5-min cron).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_queue` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'Parent in-app notification',
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
-- One thread per (institution, member, subject).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conversations` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `institution_id`   INT UNSIGNED  NOT NULL,
  `member_id`        INT UNSIGNED  NOT NULL,
  `subject`          VARCHAR(255)  NOT NULL DEFAULT 'General',
  `is_locked`        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = read-only, no new messages',
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
-- messages
-- Append-only. No DELETE endpoint ever.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id`  INT UNSIGNED    NOT NULL,
  `sender_type`      ENUM('staff','member') NOT NULL,
  `sender_id`        INT UNSIGNED    NULL DEFAULT NULL COMMENT 'users.id for staff; NULL for member entry logged by staff',
  `body`             TEXT            NOT NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_conv` (`conversation_id`, `created_at`),
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- message_receipts
-- Per-user read + archive state. Never deleted.
-- is_archived hides the message from a user's view but
-- does not delete it (append-only log preserved for admins).
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
