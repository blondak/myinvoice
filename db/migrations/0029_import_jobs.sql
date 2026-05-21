-- MyInvoice.cz — Fáze 2: Unified import_jobs tabulka
--
-- Background import joby (iDoklad API, Fakturoid API, PDF inbox scan, AI extraction).
-- Místo dvou separátních tabulek (jak měl fork — idoklad_import_jobs + fakturoid_…)
-- jedna sjednocená s `source` ENUM.
--
-- Lifecycle:
--   queued → running → completed | failed | cancelled
--
-- Counters průběžně aktualizované workerem (každých N processed items).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS import_jobs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Multi-tenant scope
    supplier_id     TINYINT UNSIGNED NOT NULL,

    -- Zdroj importu
    source          ENUM('idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai') NOT NULL,

    -- Status lifecycle
    status          ENUM('queued', 'running', 'completed', 'failed', 'cancelled')
                       NOT NULL DEFAULT 'queued',

    -- Parametry jobu (JSON — flexible per source).
    -- iDoklad: { year_from, year_to, include_clients, include_invoices, include_received }
    -- pdf_inbox: { dry_run }
    -- pdf_ai: { source_hash, source_file_name, model }
    params          JSON NULL,

    -- Progress
    total_items     INT UNSIGNED NULL,         -- známé jen po prvním fetch (pro iDoklad list)
    processed       INT UNSIGNED NOT NULL DEFAULT 0,
    created_count   INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count   INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count    INT UNSIGNED NOT NULL DEFAULT 0,

    -- Aktuální fáze pro UI ("Importing clients…", "Fetching invoices 50/200…")
    current_step    VARCHAR(120) NULL,

    -- Log + errors (text, ne JSON — line-by-line append)
    log_text        MEDIUMTEXT NULL,
    last_error      TEXT NULL,

    -- Cancellation signal (worker periodicky checkuje a graceful exit)
    cancel_requested TINYINT(1) NOT NULL DEFAULT 0,

    -- Timestamps
    started_at      TIMESTAMP NULL,
    finished_at     TIMESTAMP NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_import_jobs_supplier (supplier_id, created_at DESC),
    KEY idx_import_jobs_status   (supplier_id, status, created_at DESC),
    KEY idx_import_jobs_source   (supplier_id, source, created_at DESC),

    CONSTRAINT fk_ij_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id),
    CONSTRAINT fk_ij_user     FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
