-- MyInvoice.cz — Fáze 6: Tax settings na supplieru pro EPO výkazy
--
-- Per supplier (= per tenant firma) potřebujeme znát:
--   - Typ poplatníka (FO/PO) — pro DPFO vs DPPO výkazy
--   - VAT plátce + perioda (měsíční/čtvrtletní)
--   - Finanční úřad (kód) — povinné v hlavičce výkazů
--   - Pracoviště (ÚzP)
--   - Sestavitel přiznání (jméno+telefon+email)
--
-- Většina je už v `supplier` tabulce z předchozích migrací (is_vat_payer atd.).
-- Doplníme jen chybějící.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS taxpayer_type       ENUM('fo', 'po') NULL
        COMMENT 'fo = fyzická osoba (OSVČ), po = právnická osoba (s.r.o., a.s.)',
    ADD COLUMN IF NOT EXISTS vat_period          ENUM('monthly', 'quarterly') NULL
        COMMENT 'Periodicita DPH přiznání. NULL = neplátce.',
    ADD COLUMN IF NOT EXISTS financial_office_code VARCHAR(8) NULL
        COMMENT 'Kód finančního úřadu (např. 451 = Praha 1)',
    ADD COLUMN IF NOT EXISTS workplace_code      VARCHAR(8) NULL
        COMMENT 'Kód územního pracoviště (ÚzP)',
    ADD COLUMN IF NOT EXISTS cz_nace_code        VARCHAR(8) NULL
        COMMENT 'CZ-NACE klasifikace činnosti',
    ADD COLUMN IF NOT EXISTS data_box_type       VARCHAR(8) NULL
        COMMENT 'Typ datové schránky (e.g. OVM, PO, FO)',
    ADD COLUMN IF NOT EXISTS data_box_id         VARCHAR(16) NULL
        COMMENT 'ID datové schránky pro doručování',

    -- Sestavitel přiznání (typicky účetní)
    ADD COLUMN IF NOT EXISTS sest_jmeno          VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS sest_telefon        VARCHAR(40)  NULL,
    ADD COLUMN IF NOT EXISTS sest_email          VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS sest_funkce         VARCHAR(80)  NULL;
