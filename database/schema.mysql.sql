-- =====================================================================
--  SSO Server — اسکیمای MySQL (پیش‌فرض و توصیه‌شده برای IIS)
--  نسخه‌ی مورد نیاز: MySQL 5.7+ یا MariaDB 10.2+
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- اپلیکیشن‌های متصل (هر اپ = یک Tenant / مشتری)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `apps` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`            VARCHAR(120)  NOT NULL,
    `slug`            VARCHAR(120)  NOT NULL,
    `description`     VARCHAR(255)  DEFAULT NULL,
    `status`          VARCHAR(16)   NOT NULL DEFAULT 'active',
    `api_key_prefix`  VARCHAR(32)   NOT NULL DEFAULT '',
    `api_key_hash`    CHAR(64)      NOT NULL,
    `api_secret_hash` CHAR(64)      NOT NULL,
    `webhook_url`     VARCHAR(500)  DEFAULT NULL,
    `settings`        JSON          DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL,
    `updated_at`      DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_apps_slug` (`slug`),
    UNIQUE KEY `uq_apps_key` (`api_key_hash`),
    KEY `idx_apps_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- کاربران (مخزن سراسری — یک ایمیل در کل سامانه یکتاست)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)      NOT NULL,
    `email`               VARCHAR(190)  NOT NULL,
    `phone`               VARCHAR(32)   DEFAULT NULL,
    `password_hash`       VARCHAR(255)  NOT NULL,
    `full_name`           VARCHAR(150)  DEFAULT NULL,
    `avatar_url`          VARCHAR(500)  DEFAULT NULL,
    `email_verified_at`   DATETIME      DEFAULT NULL,
    `status`              VARCHAR(16)   NOT NULL DEFAULT 'active',
    `is_super_admin`      TINYINT(1)    NOT NULL DEFAULT 0,
    `metadata`            JSON          DEFAULT NULL,
    `failed_logins`       INT UNSIGNED  NOT NULL DEFAULT 0,
    `locked_until`        DATETIME      DEFAULT NULL,
    `last_login_at`       DATETIME      DEFAULT NULL,
    `password_changed_at` DATETIME      DEFAULT NULL,
    `created_at`          DATETIME      NOT NULL,
    `updated_at`          DATETIME      NOT NULL,
    `deleted_at`          DATETIME      DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_uuid` (`uuid`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_phone` (`phone`),
    KEY `idx_users_status` (`status`),
    KEY `idx_users_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- عضویت کاربر در اپلیکیشن (با نقش مشخص)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `memberships` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `app_id`     BIGINT UNSIGNED NOT NULL,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `role`       VARCHAR(32) NOT NULL DEFAULT 'member',
    `status`     VARCHAR(16) NOT NULL DEFAULT 'active',
    `metadata`   JSON        DEFAULT NULL,
    `created_at` DATETIME    NOT NULL,
    `updated_at` DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_membership` (`app_id`, `user_id`),
    KEY `idx_memberships_user` (`user_id`),
    KEY `idx_memberships_role` (`role`),
    CONSTRAINT `fk_memberships_app`  FOREIGN KEY (`app_id`)  REFERENCES `apps` (`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_memberships_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- توکن‌های دسترسی کاربران (access / refresh)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_type`   VARCHAR(16) NOT NULL DEFAULT 'access',
    `token_hash`   CHAR(64)    NOT NULL,
    `token_prefix` VARCHAR(32) NOT NULL DEFAULT '',
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `app_id`       BIGINT UNSIGNED DEFAULT NULL,
    `scopes`       VARCHAR(500) DEFAULT NULL,
    `ip_address`   VARCHAR(45)  DEFAULT NULL,
    `user_agent`   VARCHAR(400) DEFAULT NULL,
    `expires_at`   DATETIME     NOT NULL,
    `last_used_at` DATETIME     DEFAULT NULL,
    `revoked_at`   DATETIME     DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tokens_hash` (`token_hash`),
    KEY `idx_tokens_user` (`user_id`),
    KEY `idx_tokens_app` (`app_id`),
    KEY `idx_tokens_expires` (`expires_at`),
    CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tokens_app`  FOREIGN KEY (`app_id`)  REFERENCES `apps` (`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- توکن‌های بازیابی رمز عبور
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `app_id`     BIGINT UNSIGNED DEFAULT NULL,
    `token_hash` CHAR(64)  NOT NULL,
    `expires_at` DATETIME  NOT NULL,
    `used_at`    DATETIME  DEFAULT NULL,
    `created_at` DATETIME  NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_resets_hash` (`token_hash`),
    KEY `idx_resets_user` (`user_id`),
    CONSTRAINT `fk_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- تلاش‌های ورود (برای گزارش‌گیری و تحلیل)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(190) NOT NULL,
    `ip_address` VARCHAR(45)  NOT NULL DEFAULT '',
    `app_id`     BIGINT UNSIGNED DEFAULT NULL,
    `success`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_attempts_email` (`email`, `created_at`),
    KEY `idx_attempts_ip` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- محدودساز نرخ درخواست
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket_key` VARCHAR(190) NOT NULL,
    `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME     NOT NULL,
    `created_at` DATETIME     NOT NULL,
    `updated_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bucket` (`bucket_key`),
    KEY `idx_limits_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- لاگ رویدادها
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_type`    VARCHAR(16) NOT NULL DEFAULT 'system',
    `actor_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `actor_app_id`  BIGINT UNSIGNED DEFAULT NULL,
    `action`        VARCHAR(64) NOT NULL,
    `target_type`   VARCHAR(32) DEFAULT NULL,
    `target_id`     BIGINT UNSIGNED DEFAULT NULL,
    `context`       JSON        DEFAULT NULL,
    `ip_address`    VARCHAR(45)  DEFAULT NULL,
    `user_agent`    VARCHAR(400) DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_audit_created` (`created_at`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_actor_user` (`actor_user_id`),
    KEY `idx_audit_actor_app` (`actor_app_id`),
    KEY `idx_audit_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
