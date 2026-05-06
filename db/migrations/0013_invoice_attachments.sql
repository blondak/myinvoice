-- MyInvoice.cz — Volitelné přílohy k dokladu, které se přibalí do emailu při
-- odeslání faktury / zálohové faktury / dobropisu.
--
-- Soubory uložené na disku v storage/invoices/sup-{supplierId}/attachments/{invoiceId}/.
-- DB drží metadata + sha256 (dedup, integrity check), velikost, MIME.
--
-- Přílohy se NEpřibalují k:
--   * upomínkám (SendReminderAction / cron-send-reminders)
--   * výkazům práce / approval emailům
-- Přibalují se POUZE v SendEmailAction (manuální odeslání faktury).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_attachments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id    BIGINT UNSIGNED NOT NULL,
  filename      VARCHAR(255) NOT NULL,                 -- bezpečný název na disku
  original_name VARCHAR(255) NOT NULL,                 -- jak ho nahrál uživatel
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,
  uploaded_by   BIGINT UNSIGNED NULL,
  uploaded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_invatt_invoice (invoice_id, uploaded_at DESC),
  CONSTRAINT fk_invatt_invoice FOREIGN KEY (invoice_id)
    REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_invatt_user FOREIGN KEY (uploaded_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
