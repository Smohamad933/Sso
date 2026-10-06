-- =====================================================================
--  SSO Server — اسکیمای SQLite (برای توسعه/تست سریع بدون سرور دیتابیس)
--  نکته: برای محیط production روی IIS از schema.mysql.sql استفاده کنید.
-- =====================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS `apps` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`            TEXT NOT NULL,
    `slug`            TEXT NOT NULL UNIQUE,
    `description`     TEXT,
    `status`          TEXT NOT NULL DEFAULT 'active',
    `api_key_prefix`  TEXT NOT NULL DEFAULT '',
    `api_key_hash`    TEXT NOT NULL UNIQUE,
    `api_secret_hash` TEXT NOT NULL,
    `webhook_url`     TEXT,
    `settings`        TEXT,
    `created_at`      TEXT NOT NULL,
    `updated_at`      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_apps_status` ON `apps` (`status`);

CREATE TABLE IF NOT EXISTS `users` (
    `id`                  INTEGER PRIMARY KEY AUTOINCREMENT,
    `uuid`                TEXT NOT NULL UNIQUE,
    `email`               TEXT NOT NULL UNIQUE,
    `phone`               TEXT UNIQUE,
    `password_hash`       TEXT NOT NULL,
    `full_name`           TEXT,
    `avatar_url`          TEXT,
    `email_verified_at`   TEXT,
    `status`              TEXT NOT NULL DEFAULT 'active',
    `is_super_admin`      INTEGER NOT NULL DEFAULT 0,
    `metadata`            TEXT,
    `failed_logins`       INTEGER NOT NULL DEFAULT 0,
    `locked_until`        TEXT,
    `last_login_at`       TEXT,
    `password_changed_at` TEXT,
    `created_at`          TEXT NOT NULL,
    `updated_at`          TEXT NOT NULL,
    `deleted_at`          TEXT
);
CREATE INDEX IF NOT EXISTS `idx_users_status`  ON `users` (`status`);
CREATE INDEX IF NOT EXISTS `idx_users_created` ON `users` (`created_at`);

CREATE TABLE IF NOT EXISTS `memberships` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `app_id`     INTEGER NOT NULL REFERENCES `apps` (`id`)  ON DELETE CASCADE,
    `user_id`    INTEGER NOT NULL REFERENCES `users` (`id`) ON DELETE CASCADE,
    `role`       TEXT NOT NULL DEFAULT 'member',
    `status`     TEXT NOT NULL DEFAULT 'active',
    `metadata`   TEXT,
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL,
    UNIQUE (`app_id`, `user_id`)
);
CREATE INDEX IF NOT EXISTS `idx_memberships_user` ON `memberships` (`user_id`);
CREATE INDEX IF NOT EXISTS `idx_memberships_role` ON `memberships` (`role`);

CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `token_type`   TEXT NOT NULL DEFAULT 'access',
    `token_hash`   TEXT NOT NULL UNIQUE,
    `token_prefix` TEXT NOT NULL DEFAULT '',
    `user_id`      INTEGER NOT NULL REFERENCES `users` (`id`) ON DELETE CASCADE,
    `app_id`       INTEGER REFERENCES `apps` (`id`) ON DELETE CASCADE,
    `scopes`       TEXT,
    `ip_address`   TEXT,
    `user_agent`   TEXT,
    `expires_at`   TEXT NOT NULL,
    `last_used_at` TEXT,
    `revoked_at`   TEXT,
    `created_at`   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_tokens_user`    ON `api_tokens` (`user_id`);
CREATE INDEX IF NOT EXISTS `idx_tokens_app`     ON `api_tokens` (`app_id`);
CREATE INDEX IF NOT EXISTS `idx_tokens_expires` ON `api_tokens` (`expires_at`);

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL REFERENCES `users` (`id`) ON DELETE CASCADE,
    `app_id`     INTEGER,
    `token_hash` TEXT NOT NULL UNIQUE,
    `expires_at` TEXT NOT NULL,
    `used_at`    TEXT,
    `created_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_resets_user` ON `password_resets` (`user_id`);

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `email`      TEXT NOT NULL,
    `ip_address` TEXT NOT NULL DEFAULT '',
    `app_id`     INTEGER,
    `success`    INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_attempts_email` ON `login_attempts` (`email`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_attempts_ip`    ON `login_attempts` (`ip_address`, `created_at`);

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `bucket_key` TEXT NOT NULL UNIQUE,
    `hits`       INTEGER NOT NULL DEFAULT 0,
    `expires_at` TEXT NOT NULL,
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_limits_expires` ON `rate_limits` (`expires_at`);

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `actor_type`    TEXT NOT NULL DEFAULT 'system',
    `actor_user_id` INTEGER,
    `actor_app_id`  INTEGER,
    `action`        TEXT NOT NULL,
    `target_type`   TEXT,
    `target_id`     INTEGER,
    `context`       TEXT,
    `ip_address`    TEXT,
    `user_agent`    TEXT,
    `created_at`    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_audit_created`     ON `audit_logs` (`created_at`);
CREATE INDEX IF NOT EXISTS `idx_audit_action`      ON `audit_logs` (`action`);
CREATE INDEX IF NOT EXISTS `idx_audit_actor_user`  ON `audit_logs` (`actor_user_id`);
CREATE INDEX IF NOT EXISTS `idx_audit_actor_app`   ON `audit_logs` (`actor_app_id`);
CREATE INDEX IF NOT EXISTS `idx_audit_target`      ON `audit_logs` (`target_type`, `target_id`);
