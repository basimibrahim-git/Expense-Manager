-- ─────────────────────────────────────────────────────────────────────────────
-- Saved spends (2026-10-06): reusable expense names with their category, used to
-- autofill the description + category on Add/Edit Expense.
-- Run once in phpMyAdmin (SQL tab) before uploading the new PHP files. Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `expense_presets` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT(11)      NOT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `category`    VARCHAR(50)  NOT NULL,
  `created_by`  INT(11)      DEFAULT NULL,
  `created_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expense_presets_name` (`tenant_id`, `name`),
  CONSTRAINT `expense_presets_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
