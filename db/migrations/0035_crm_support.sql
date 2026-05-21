-- MyInvoice.cz — Fáze 5: CRM dashboard support
--
-- Pre-aggregated monthly summary pro výkonné dashboard queries.
-- Refresh přes cron job (denně) nebo manuálně z UI.
--
-- Plus expense_categories pro klasifikaci přijatých faktur (Costs sekce).
-- Optional revenue_category na vydaných fakturách (byznys mix).

SET NAMES utf8mb4;

-- ═══ expense_categories ════════════════════════════════════════════════
-- Kategorie nákladů — uživatel přiřazuje na purchase_invoice + na řádek.
CREATE TABLE IF NOT EXISTS expense_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id     TINYINT UNSIGNED NOT NULL,
    code            VARCHAR(20) NOT NULL COMMENT '"hosting", "software", "kancelar", "marketing"…',
    label           VARCHAR(100) NOT NULL,
    fixed_or_var    ENUM('fixed', 'variable') NOT NULL DEFAULT 'variable',
    display_order   INT NOT NULL DEFAULT 0,
    archived        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_expense_categories (supplier_id, code),
    KEY idx_expense_supplier (supplier_id, archived, display_order),
    CONSTRAINT fk_ec_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Přiřazení kategorie na fakturu (header-level) + item-level (pro detailní rozpad)
ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS expense_category_id INT UNSIGNED NULL
        COMMENT 'Hlavní kategorie nákladu — pro report aggregace';

ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS expense_category_id INT UNSIGNED NULL
        COMMENT 'Volitelně override per řádek — split jedné faktury do více kategorií';

-- Volitelně: tagging na vydaných fakturách (byznys mix — "konzultace" vs "produkt")
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS revenue_category VARCHAR(40) NULL
        COMMENT 'Volitelný free-form tag pro revenue mix analýzu';

-- ═══ crm_monthly_summary ══════════════════════════════════════════════
-- Pre-aggregated monthly KPIs — query plus rychlé než výpočet z invoices+items.
-- Per tenant + měsíc + měna (multi-currency aware).
CREATE TABLE IF NOT EXISTS crm_monthly_summary (
    supplier_id     TINYINT UNSIGNED NOT NULL,
    period_ym      CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    currency        CHAR(3) NOT NULL,

    -- Revenue (z vydaných faktur — status NOT IN draft/cancelled)
    revenue         DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'Total with VAT',
    revenue_net     DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'Total without VAT',
    invoice_count   INT UNSIGNED NOT NULL DEFAULT 0,

    -- Costs (z přijatých faktur — status NOT IN draft/cancelled)
    costs           DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'Total with VAT',
    costs_net       DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'Total without VAT',
    purchase_count  INT UNSIGNED NOT NULL DEFAULT 0,

    -- VAT (pro budoucí fázi 6 — DPH výkazy)
    vat_output      DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'DPH na výstupu (z vydaných)',
    vat_input       DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'DPH na vstupu (z přijatých)',

    computed_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (supplier_id, period_ym, currency),
    KEY idx_summary_year (supplier_id, period_ym DESC),
    CONSTRAINT fk_cms_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
