-- MyInvoice.cz — Fáze 2b: Fakturoid external IDs pro dedup
--
-- Stejný princip jako iDoklad (migrace 0028): per-tenant unique fakturoid_id na
-- clients, invoices, purchase_invoices. NULL=lokálně vytvořené, OK.

SET NAMES utf8mb4;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS fakturoid_id BIGINT UNSIGNED NULL
        COMMENT 'Subject.id z Fakturoid API v3';

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS fakturoid_id BIGINT UNSIGNED NULL
        COMMENT 'Invoice.id z Fakturoid API v3';

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS fakturoid_id BIGINT UNSIGNED NULL
        COMMENT 'Expense.id z Fakturoid API v3 (přijaté = expenses ve Fakturoidu)';

CREATE UNIQUE INDEX IF NOT EXISTS uq_clients_fakturoid           ON clients           (supplier_id, fakturoid_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_invoices_fakturoid          ON invoices          (supplier_id, fakturoid_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_invoices_fakturoid ON purchase_invoices (supplier_id, fakturoid_id);

-- Rozšířit import_jobs.source ENUM o 'fakturoid' (už deklarováno v 0029, ale
-- pro jistotu jako MODIFY pokud Někdo měl starší verzi). MariaDB ALTER MODIFY ENUM
-- je idempotentní jen pokud finální definice stejná, takže OK opakovaně.
ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM('idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai') NOT NULL;
