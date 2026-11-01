-- ─────────────────────────────────────────────────────────────────────────────
-- Auth hardening (2026-11-01): login lock-out keyed on email + IP, and a
-- server-side signup cooldown. Run once in phpMyAdmin (SQL tab). Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`           VARCHAR(45)  NOT NULL,
  `email`        VARCHAR(255) NOT NULL DEFAULT '',
  `attempted_at` DATETIME     NOT NULL,
  `success`      TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_ip_attempted` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add the email index only if it is missing.
SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'login_attempts'
                   AND index_name = 'idx_email_attempted');
SET @ddl := IF(@has_idx = 0,
               'ALTER TABLE `login_attempts` ADD INDEX `idx_email_attempted` (`email`, `attempted_at`)',
               'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `signup_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`           VARCHAR(45)  NOT NULL,
  `attempted_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_signup_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
