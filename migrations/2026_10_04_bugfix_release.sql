-- ─────────────────────────────────────────────────────────────────────────────
-- Bug-fix release (2026-10-04)
-- Run once in phpMyAdmin (SQL tab) BEFORE uploading the new PHP files.
-- Safe to re-run: every statement checks whether the change already exists.
-- Requires MariaDB 10.0+ (IF NOT EXISTS on columns/indexes).
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Password reset tokens (forgot_password.php / reset_password.php).
--    Only a SHA-256 hash of the token is stored; the raw token lives only in the email link.
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)      NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `request_ip` VARCHAR(45)  DEFAULT NULL,
  `created_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Sadaqa categories. The monthly Sadaqa page now writes to sadaqa_tracker
--    (the table the yearly page and the CSV export already read) instead of the
--    non-existent monthly_sadaqa table.
ALTER TABLE `sadaqa_tracker`
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(50) NOT NULL DEFAULT 'General' AFTER `amount`;

-- 3. Reminders store a time of day; the column was DATE so the time was dropped.
ALTER TABLE `reminders`
  MODIFY `alert_date` DATETIME NOT NULL;

-- 4. "Latest balance per bank" lookups (dashboard, My Banks, net worth).
ALTER TABLE `bank_balances`
  ADD INDEX IF NOT EXISTS `idx_bank_balances_bank_date` (`bank_id`, `balance_date`, `id`);

-- 5. Remember which bank an expense/income actually moved, so deleting or editing
--    the entry can reverse exactly that change. Existing rows stay NULL (never reversed).
ALTER TABLE `expenses`
  ADD COLUMN IF NOT EXISTS `balance_bank_id` INT(11) DEFAULT NULL AFTER `card_id`;
ALTER TABLE `income`
  ADD COLUMN IF NOT EXISTS `balance_bank_id` INT(11) DEFAULT NULL AFTER `currency`;

-- 6. The my_banks view is no longer used (its balance lookup was not tenant-scoped).
DROP VIEW IF EXISTS `my_banks`;
