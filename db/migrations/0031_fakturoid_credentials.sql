-- MyInvoice.cz — Fáze 2b: Fakturoid API credentials
--
-- Fakturoid API v3 používá BasicAuth s personal API token + slug (account name).
-- Plus User-Agent header je povinný (jméno aplikace + email pro contact).
--
-- Per-tenant credentials v `supplier` tabulce:
--   - fakturoid_slug   = account slug (z URL https://app.fakturoid.cz/{slug})
--   - fakturoid_email  = email accountu (pro BasicAuth user part)
--   - fakturoid_api_key_enc = personal API token (BasicAuth pass) šifrovaný
--
-- Plus bookmark idoklad_last_imported_at-like — fakturoid_last_imported_at.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS fakturoid_slug VARCHAR(64) NULL
        COMMENT 'Fakturoid account slug (např. "moje-firma")',
    ADD COLUMN IF NOT EXISTS fakturoid_email VARCHAR(255) NULL
        COMMENT 'Fakturoid account email pro BasicAuth username',
    ADD COLUMN IF NOT EXISTS fakturoid_api_key_enc VARBINARY(512) NULL
        COMMENT 'Fakturoid personal API token (BasicAuth password) šifrovaný',
    ADD COLUMN IF NOT EXISTS fakturoid_last_imported_at TIMESTAMP NULL
        COMMENT 'Bookmark — poslední úspěšný import (pro incremental sync filter)';
