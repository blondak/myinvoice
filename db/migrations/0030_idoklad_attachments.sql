-- MyInvoice.cz — Fáze 2a iter3: iDoklad PDF attachments archive
--
-- iDoklad ukládá ke každé faktuře (vydané i přijaté) PDF přílohy. Při importu
-- je stáhneme + archive jako u inbox scanu (storage/purchase-invoices nebo
-- storage/invoices). Stejná dedup logika: SHA-256 hash.
--
-- Pro vydané faktury (issued) je sloupec pdf_path už existující součástí
-- (PDF cache vlastního renderu). Nemůžeme přepsat — proto nová sloupec
-- `imported_pdf_path` pro original PDF od iDoklad (paralel s naším renderem).
--
-- Pro přijaté faktury sloupce už existují (pdf_path/hash/size/original_name)
-- — reuse same columns, jen origin marker přes idoklad_id.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS imported_pdf_path     VARCHAR(255) NULL
        COMMENT 'Path k originálnímu PDF z externího importu (iDoklad/Fakturoid)',
    ADD COLUMN IF NOT EXISTS imported_pdf_hash     CHAR(64) NULL
        COMMENT 'SHA-256 pro dedup',
    ADD COLUMN IF NOT EXISTS imported_pdf_size_bytes BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS imported_pdf_original_name VARCHAR(255) NULL
        COMMENT 'Původní filename z external systému';
