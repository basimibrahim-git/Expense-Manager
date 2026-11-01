-- ─────────────────────────────────────────────────────────────────────────────
-- Expense/income FX reversal fix (2026-11-01): store the bank-currency amount
-- actually moved, so deleting/editing a cross-currency row reverses exactly that
-- amount instead of re-converting at today's rate. Old rows (NULL) fall back to the
-- current rate. Run once in phpMyAdmin (SQL tab) before uploading the new PHP files.
-- Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `expenses`
  ADD COLUMN IF NOT EXISTS `balance_amount` DECIMAL(15,2) NULL DEFAULT NULL AFTER `balance_bank_id`;

ALTER TABLE `income`
  ADD COLUMN IF NOT EXISTS `balance_amount` DECIMAL(15,2) NULL DEFAULT NULL AFTER `balance_bank_id`;
