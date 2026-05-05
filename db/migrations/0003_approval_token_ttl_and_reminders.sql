-- MyInvoice.cz — token expiration + reminder cron pro schvalování výkazu
-- Spec: feature/work-report-approval (rozšíření 0002)
--
-- Doplňuje:
--   1. invoices.approval_token_expires_at — token expiruje za N dní (cfg.approval.token_ttl_days)
--      Aktuálně token nikdy neexpiruje (jen invalidate při decize). Po této migraci
--      vyprší automaticky → menší attack surface pro nikdy neotevřené emaily.
--   2. invoices.approval_reminder_at + approval_reminder_count — sledování upomínek
--      pro cron-send-approval-reminders.php (denně, X dní bez reakce zákazníka).
--
-- Idempotentní: ADD COLUMN / ADD KEY s IF NOT EXISTS — opakovaně spustitelné.

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS approval_token_expires_at TIMESTAMP NULL DEFAULT NULL
    AFTER approval_token,
  ADD COLUMN IF NOT EXISTS approval_reminder_at TIMESTAMP NULL DEFAULT NULL
    AFTER approval_decided_at,
  ADD COLUMN IF NOT EXISTS approval_reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER approval_reminder_at,
  -- Index pro cron query: najdi requested + posledni reminder/request starsi nez X dni
  ADD KEY IF NOT EXISTS idx_inv_approval_reminder (approval_status, approval_reminder_at, approval_requested_at);
