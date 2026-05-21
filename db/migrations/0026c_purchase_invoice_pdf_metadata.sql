-- MyInvoice.cz — purchase invoices: PDF metadata pro dedup
--
-- Inbox scanner (manuální nahrávání PDF do nakonfigurovaného adresáře) potřebuje
-- spolehlivě poznat, jestli daný soubor už importovaný byl. Použijeme SHA-256
-- otisk obsahu — name + size nestačí (vendor pošle stejné PDF víckrát s jiným jménem).
--
-- pdf_size_bytes je doplňkový hint pro budoucí GUI (zobrazit velikost přílohy
-- ve výpisu).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS pdf_hash CHAR(64) NULL
        COMMENT 'SHA-256 archivovaného PDF (vendor original). Slouží k dedup při scan-inbox.'
        AFTER pdf_path,
    ADD COLUMN IF NOT EXISTS pdf_size_bytes INT UNSIGNED NULL
        COMMENT 'Velikost archivovaného PDF v bajtech'
        AFTER pdf_hash,
    ADD COLUMN IF NOT EXISTS pdf_original_name VARCHAR(255) NULL
        COMMENT 'Původní filename od vendora (např. faktura-2026-05.pdf)'
        AFTER pdf_size_bytes;

-- Dedup query: WHERE supplier_id = ? AND pdf_hash = ?
CREATE INDEX IF NOT EXISTS idx_pi_pdf_hash ON purchase_invoices (supplier_id, pdf_hash);
