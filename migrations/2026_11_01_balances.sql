-- ─────────────────────────────────────────────────────────────────────────────
-- Balances (2026-11-01): no schema change needed. Banks now accept only AED/INR.
-- Optional clean-up for existing data: re-express any bank_balances rows in the bank's
-- own currency (a snapshot saved in a different currency than its bank is converted).
-- Run once in phpMyAdmin (SQL tab). Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

-- Remove snapshots orphaned by deleted banks (bank_id set but bank gone)
DELETE bb FROM `bank_balances` bb
LEFT JOIN `banks` b ON b.id = bb.bank_id
WHERE bb.bank_id IS NOT NULL AND b.id IS NULL;
