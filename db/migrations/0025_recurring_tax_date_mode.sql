-- MyInvoice.cz — Pravidelné fakturace: režim DUZP (tax_date)
--
-- Generator dosud nastavoval tax_date = issue_date. Nový sloupec umožňuje
-- backdatovat DUZP na poslední den předchozího měsíce — typický scénář
-- "fakturuji 1.6. za květnové služby, DUZP = 31.5.".
--
-- Description fakturních položek se nově synchronizuje k tax_date (pokud
-- existuje, jinak k issue_date), takže pattern M/YYYY v popisu automaticky
-- odpovídá zdaňovacímu období.
--
-- Default 'same_as_issue' = zachovává původní chování pro existující šablony.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;

ALTER TABLE recurring_invoice_templates
  ADD COLUMN IF NOT EXISTS tax_date_mode
    ENUM('same_as_issue','previous_month_last_day')
    NOT NULL DEFAULT 'same_as_issue'
    AFTER payment_due_days;
