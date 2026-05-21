-- MyInvoice.cz — Fáze 2a: iDoklad external IDs pro dedup
--
-- Pří importu z iDoklad API musíme dedup proti našim již-importovaným záznamům.
-- Místo SHA-256 obsahu (jako u PDF) ukládáme `idoklad_id` (číselný PK z iDoklad)
-- jako external reference. Při dalším incremental sync se kontrolují:
--   - clients.idoklad_id   = Contact.Id z iDoklad
--   - invoices.idoklad_id  = IssuedInvoice.Id (nebo IssuedInvoiceCorrection.Id pro dobropisy)
--   - purchase_invoices.idoklad_id = ReceivedInvoice.Id
--
-- Unique index per (supplier_id, idoklad_id) — různí tenanti mohou mít stejné
-- ID v rámci svých iDoklad účtů (separátní namespace). NULL hodnoty jsou OK
-- (lokálně vytvořené záznamy bez iDoklad linkage).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + CREATE UNIQUE INDEX IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS idoklad_id BIGINT UNSIGNED NULL
        COMMENT 'Contact.Id z iDoklad API v3 (dedup pro re-import)'
        AFTER is_vendor;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS idoklad_id BIGINT UNSIGNED NULL
        COMMENT 'IssuedInvoice.Id / IssuedInvoiceCorrection.Id z iDoklad';

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS idoklad_id BIGINT UNSIGNED NULL
        COMMENT 'ReceivedInvoice.Id z iDoklad';

-- Unique per tenant — různí tenanti mohou mít stejné iDoklad ID v rámci svých účtů.
-- NULL ignoruje UNIQUE constraint v MariaDB (NULL ≠ NULL), takže lokální záznamy OK.
CREATE UNIQUE INDEX IF NOT EXISTS uq_clients_idoklad           ON clients           (supplier_id, idoklad_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_invoices_idoklad          ON invoices          (supplier_id, idoklad_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_invoices_idoklad ON purchase_invoices (supplier_id, idoklad_id);
