-- ─────────────────────────────────────────────────────────────────────────────
-- Feature release (2026-10-05): open banking (Lean), Zakath, card cycles,
-- budget alerts, family split.
-- Run once in phpMyAdmin (SQL tab) BEFORE uploading the new PHP files.
-- Requires 2026_10_04_bugfix_release.sql to have been run first.
-- Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

-- Shared: "send once" log for emailed notifications (budget alerts, card dues, Zakath…)
CREATE TABLE IF NOT EXISTS `notification_log` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT(11)      NOT NULL,
  `kind`       VARCHAR(40)  NOT NULL,
  `ref_key`    VARCHAR(120) NOT NULL,
  `sent_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_notification` (`tenant_id`, `kind`, `ref_key`),
  CONSTRAINT `notification_log_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Budget alerts
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `budget_alert_settings` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT(11)     NOT NULL,
  `enabled`    TINYINT(1)  NOT NULL DEFAULT 1,
  `warn_pct`   INT(11)     NOT NULL DEFAULT 80,
  `over_pct`   INT(11)     NOT NULL DEFAULT 100,
  `instant`    TINYINT(1)  NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_budget_alert_tenant` (`tenant_id`),
  CONSTRAINT `budget_alert_settings_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fast "spent per category this month" lookups (budget alerts run after every expense save).
ALTER TABLE `expenses`
  ADD INDEX IF NOT EXISTS `idx_expenses_tenant_cat_date` (`tenant_id`, `category`, `expense_date`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Dashboard performance (indexes)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `expenses`
  ADD INDEX IF NOT EXISTS `idx_expenses_tenant_date` (`tenant_id`, `expense_date`);

ALTER TABLE `income`
  ADD INDEX IF NOT EXISTS `idx_income_tenant_date` (`tenant_id`, `income_date`);

ALTER TABLE `interest_tracker`
  ADD INDEX IF NOT EXISTS `idx_interest_tenant_date` (`tenant_id`, `interest_date`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Family split
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `expense_splits` (
  `id`           INT(11)       NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT(11)       NOT NULL,
  `expense_id`   INT(11)       NOT NULL,
  `user_id`      INT(11)       NOT NULL,
  `share_amount` DECIMAL(15,2) NOT NULL,
  `created_at`   TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expense_user` (`expense_id`, `user_id`),
  KEY `idx_splits_tenant_user` (`tenant_id`, `user_id`),
  CONSTRAINT `expense_splits_ibfk_1` FOREIGN KEY (`tenant_id`)  REFERENCES `tenants` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `expense_splits_ibfk_2` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settlements` (
  `id`           INT(11)       NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT(11)       NOT NULL,
  `from_user_id` INT(11)       NOT NULL,
  `to_user_id`   INT(11)       NOT NULL,
  `amount`       DECIMAL(15,2) NOT NULL,
  `currency`     VARCHAR(3)    NOT NULL DEFAULT 'AED',
  `settled_on`   DATE          NOT NULL,
  `note`         VARCHAR(255)  DEFAULT NULL,
  `created_by`   INT(11)       DEFAULT NULL,
  `created_at`   TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_settlements_tenant_date` (`tenant_id`, `settled_on`),
  CONSTRAINT `settlements_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Zakath
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `zakath_settings` (
  `tenant_id`               INT(11)       NOT NULL,
  `nisab_basis`             ENUM('gold','silver') NOT NULL DEFAULT 'silver',
  `gold_grams`              DECIMAL(8,3)  NOT NULL DEFAULT 85.000,
  `silver_grams`            DECIMAL(8,3)  NOT NULL DEFAULT 612.360,
  `hawl_start_date`         DATE          NULL DEFAULT NULL,
  `use_manual_prices`       TINYINT(1)    NOT NULL DEFAULT 0,
  `manual_gold_price`       DECIMAL(15,4) NULL DEFAULT NULL COMMENT 'AED per gram, 24k',
  `manual_silver_price`     DECIMAL(15,4) NULL DEFAULT NULL COMMENT 'AED per gram, .999',
  `manual_prices_updated_at` DATETIME     NULL DEFAULT NULL,
  `updated_at`              TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`),
  CONSTRAINT `zakath_settings_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Market price cache (global, not family data — same idea as exchange_rate_cache).
-- One row per metal; refreshed at most every 12 hours.
CREATE TABLE IF NOT EXISTS `zakath_metal_prices` (
  `metal`          VARCHAR(10)   NOT NULL COMMENT 'gold | silver',
  `usd_per_ounce`  DECIMAL(15,4) NULL DEFAULT NULL,
  `aed_per_gram`   DECIMAL(15,4) NULL DEFAULT NULL,
  `source`         VARCHAR(40)   NULL DEFAULT NULL,
  `fetched_at`     DATETIME      NULL DEFAULT NULL COMMENT 'last successful fetch',
  `attempted_at`   DATETIME      NULL DEFAULT NULL COMMENT 'last fetch attempt (throttles retries when the API is down)',
  PRIMARY KEY (`metal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extra detail stored with each calculation.
ALTER TABLE `zakath_calculations`
  ADD COLUMN IF NOT EXISTS `receivables`           DECIMAL(15,2) DEFAULT 0.00 AFTER `investments`,
  ADD COLUMN IF NOT EXISTS `net_wealth`            DECIMAL(15,2) NULL DEFAULT NULL AFTER `liabilities`,
  ADD COLUMN IF NOT EXISTS `nisab_basis`           VARCHAR(10)   NULL DEFAULT NULL AFTER `net_wealth`,
  ADD COLUMN IF NOT EXISTS `nisab_value`           DECIMAL(15,2) NULL DEFAULT NULL AFTER `nisab_basis`,
  ADD COLUMN IF NOT EXISTS `gold_price_per_gram`   DECIMAL(15,4) NULL DEFAULT NULL AFTER `nisab_value`,
  ADD COLUMN IF NOT EXISTS `silver_price_per_gram` DECIMAL(15,4) NULL DEFAULT NULL AFTER `gold_price_per_gram`,
  ADD INDEX IF NOT EXISTS `idx_zakath_tenant_due` (`tenant_id`, `due_date`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Open banking (Lean)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lean_customers` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT(11)      NOT NULL,
  `customer_id` VARCHAR(64)  NOT NULL,
  `app_user_id` VARCHAR(100) NOT NULL,
  `environment` VARCHAR(10)  NOT NULL DEFAULT 'sandbox',
  `created_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lean_customers_tenant` (`tenant_id`),
  KEY `idx_lean_customers_customer` (`customer_id`),
  CONSTRAINT `lean_customers_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A bank connection (Lean "entity"). status: pending | active | reconnect_required | consent_expired | disconnected
CREATE TABLE IF NOT EXISTS `lean_entities` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `tenant_id`       INT(11)      NOT NULL,
  `entity_id`       VARCHAR(64)  NOT NULL,
  `customer_id`     VARCHAR(64)  NOT NULL,
  `bank_identifier` VARCHAR(64)  DEFAULT NULL,
  `bank_name`       VARCHAR(100) DEFAULT NULL,
  `status`          VARCHAR(30)  NOT NULL DEFAULT 'pending',
  `last_error`      VARCHAR(255) DEFAULT NULL,
  `last_synced_at`  DATETIME     DEFAULT NULL,
  `created_at`      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lean_entities_entity` (`entity_id`),
  KEY `idx_lean_entities_tenant` (`tenant_id`, `status`),
  CONSTRAINT `lean_entities_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts inside a connection. bank_id links one Lean account to one row in `banks`
-- (its balance is written to bank_balances on every sync when it changed).
CREATE TABLE IF NOT EXISTS `lean_accounts` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `tenant_id`       INT(11)       NOT NULL,
  `entity_id`       VARCHAR(64)   NOT NULL,
  `account_id`      VARCHAR(64)   NOT NULL,
  `display_name`    VARCHAR(150)  NOT NULL DEFAULT '',
  `account_type`    VARCHAR(30)   DEFAULT NULL,
  `currency`        VARCHAR(3)    NOT NULL DEFAULT 'AED',
  `masked_number`   VARCHAR(40)   DEFAULT NULL,
  `bank_id`         INT(11)       DEFAULT NULL,
  `last_balance`    DECIMAL(15,2) DEFAULT NULL,
  `last_synced_at`  DATETIME      DEFAULT NULL,
  `created_at`      TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lean_accounts_account` (`tenant_id`, `account_id`),
  UNIQUE KEY `uniq_lean_accounts_bank` (`bank_id`),
  KEY `idx_lean_accounts_entity` (`entity_id`),
  CONSTRAINT `lean_accounts_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lean_accounts_ibfk_2` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booked bank transactions waiting for review. amount is signed (negative = money out),
-- in the account currency. Deduped per tenant + account + Lean transaction id.
CREATE TABLE IF NOT EXISTS `lean_transactions` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `tenant_id`           INT(11)       NOT NULL,
  `account_id`          VARCHAR(64)   NOT NULL,
  `transaction_id`      VARCHAR(128)  NOT NULL,
  `booking_date`        DATE          NOT NULL,
  `amount`              DECIMAL(15,2) NOT NULL,
  `currency`            VARCHAR(3)    NOT NULL DEFAULT 'AED',
  `description`         VARCHAR(255)  NOT NULL DEFAULT '',
  `merchant`            VARCHAR(150)  DEFAULT NULL,
  `card_last4`          VARCHAR(4)    DEFAULT NULL,
  `status`              ENUM('new','imported','ignored') NOT NULL DEFAULT 'new',
  `imported_expense_id` INT(11)       DEFAULT NULL,
  `imported_income_id`  INT(11)       DEFAULT NULL,
  `created_at`          TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lean_tx` (`tenant_id`, `account_id`, `transaction_id`),
  KEY `idx_lean_tx_review` (`tenant_id`, `status`, `booking_date`),
  KEY `idx_lean_tx_expense` (`imported_expense_id`),
  KEY `idx_lean_tx_income` (`imported_income_id`),
  CONSTRAINT `lean_transactions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lean_transactions_ibfk_2` FOREIGN KEY (`imported_expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lean_transactions_ibfk_3` FOREIGN KEY (`imported_income_id`) REFERENCES `income` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
