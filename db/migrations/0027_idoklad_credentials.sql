-- MyInvoice.cz — Fáze 2a: iDoklad API credentials
--
-- iDoklad API v3 používá OAuth2 client_credentials grant. Per-tenant credentials
-- (idoklad_client_id + idoklad_client_secret) ukládáme šifrované přes app.pepper
-- v `supplier` tabulce (jeden tenant = jeden iDoklad account).
--
-- Plus token cache — access_token + expires_at, aby nemusel každý request fetch.
-- Token TTL typicky 1 hodina, refresh na pozadí.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB 10.3+).

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS idoklad_client_id VARCHAR(128) NULL
        COMMENT 'iDoklad API v3 client_id (plain — public identifier)'
        AFTER updated_at,
    ADD COLUMN IF NOT EXISTS idoklad_client_secret_enc VARBINARY(512) NULL
        COMMENT 'iDoklad API v3 client_secret šifrovaný AES-256-GCM přes app.pepper'
        AFTER idoklad_client_id,
    ADD COLUMN IF NOT EXISTS idoklad_access_token TEXT NULL
        COMMENT 'Cache bearer tokenu (kratká TTL ~1h); refresh na expires_at'
        AFTER idoklad_client_secret_enc,
    ADD COLUMN IF NOT EXISTS idoklad_token_expires_at TIMESTAMP NULL
        COMMENT 'Expirace cached access_token'
        AFTER idoklad_access_token,
    ADD COLUMN IF NOT EXISTS idoklad_last_imported_at TIMESTAMP NULL
        COMMENT 'Poslední úspěšný import — bookmark pro incremental sync'
        AFTER idoklad_token_expires_at;
