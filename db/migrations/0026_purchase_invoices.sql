-- MyInvoice.cz — Přijaté faktury (purchase invoices) — fáze 1 integrace forku
--
-- Schema mirroruje `invoices` strukturu, ale pro doklady, které přijímáme od dodavatelů.
-- Klíčové rozdíly:
--   • vendor_id (ne client_id) — FK do `clients` jako protistrana
--   • supplier_id (= tenant) řadí fakturu pod naši firmu (multi-tenant scope)
--   • status lifecycle: draft → received → booked → paid (+ cancelled kdykoliv)
--   • žádný approval flow, žádný sent/reminder — nepošíláme to nikam
--   • vendor_invoice_number = jejich vlastní číslo dokladu (unique per vendor per měsíc)
--   • varsymbol = naše interní označení (PF-YYYYMM-NNNN), oddělené counter table
--   • document_kind = ENUM(invoice, receipt, credit_note, advance) — pro filtrování typu
--
-- Multi-currency:
--   • currency_id = měna faktury (USD)
--   • exchange_rate = USD → tenant base (CZK) at issue/tax_date
--   • payment_currency_id = měna účtu plátce (CZK/EUR), může se lišit
--   • paid_amount_* a exchange_diff_base trackují skutečnou platbu + kurzový rozdíl
--
-- VAT klasifikace připravena pro fázi 6 (DPH/KH výkazy): vat_classification_code NULL prozatím.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + ALTER … IF NOT EXISTS (MariaDB native).
-- Per memory feedback_migrations_idempotent.md: ne PREPARE/EXECUTE, jen native.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ==========================================================================
-- purchase_invoices
-- ==========================================================================

CREATE TABLE IF NOT EXISTS purchase_invoices (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Multi-tenant scope: naše firma, pod kterou tato faktura patří.
    -- TINYINT konzistentně s `invoices.supplier_id` a `clients.supplier_id`.
    supplier_id              TINYINT UNSIGNED NOT NULL,

    -- Vendor = protistrana, od které jsme fakturu dostali (řádek v `clients` s is_vendor=1).
    -- Pojmenování záměrně `vendor_id` (ne `client_id` ani `supplier_id`), aby se nepletlo
    -- s naším tenantem (supplier_id) a s odběratelem vystavených faktur (client_id).
    vendor_id                BIGINT UNSIGNED NOT NULL,

    -- Naše interní označení faktury (auto: PF-YYYYMM-NNNN přes purchase_invoice_counters).
    -- VARCHAR(20) zarovnaný s invoices.varsymbol.
    varsymbol                VARCHAR(20) NULL,

    -- Vendor's vlastní číslo dokladu — to, co je vytištěné na PDF.
    -- Unique per (supplier, vendor, issue_date) — anti-duplicate guard při importu.
    vendor_invoice_number    VARCHAR(50) NOT NULL,

    -- Klasifikace dokladu (pro filtrování v seznamu)
    document_kind            ENUM('invoice','receipt','credit_note','advance')
                                NOT NULL DEFAULT 'invoice',

    -- Datumy
    --   issue_date   = datum vystavení (z faktury)
    --   tax_date     = DUZP (kritické pro DPH období, fáze 6)
    --   due_date     = splatnost (vendor's terms)
    --   received_at  = kdy jsme to dostali / zaevidovali do systému
    issue_date               DATE NOT NULL,
    tax_date                 DATE NULL,
    due_date                 DATE NOT NULL,
    received_at              DATE NOT NULL,

    -- Měna FAKTURY (např. USD)
    currency_id              INT UNSIGNED NOT NULL,

    -- Kurz měny faktury → tenant base ccy (CZK), platný k DUZP / issue_date
    exchange_rate            DECIMAL(12,6) NULL,
    exchange_rate_date       DATE NULL,
    exchange_rate_source     ENUM('cnb','manual','idoklad','fakturoid') NOT NULL DEFAULT 'cnb',

    -- Reverse charge (B2B přeshraniční): 1 = příjemce sám zdaní (input VAT = 0 v účetnictví)
    reverse_charge           TINYINT(1) NOT NULL DEFAULT 0,

    -- Jazyk dokladu (pro PDF náhled / labely)
    language                 ENUM('cs','en') NOT NULL DEFAULT 'cs',

    -- Volitelné poznámky (interní memo)
    note_above_items         TEXT NULL,
    note_below_items         TEXT NULL,

    -- Snapshoty (immutable record k datu zaevidování)
    --   vendor_snapshot = vendor data v okamžiku přijetí (name, address, IČ, DIČ, IBAN, …)
    --   own_snapshot    = naše tenant data (snapshot supplier row)
    vendor_snapshot          JSON NOT NULL,
    own_snapshot             JSON NULL,

    -- Denormalizované součty (přepočítává PurchaseInvoiceCalculator po každé změně items)
    total_without_vat        DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_vat                DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_with_vat           DECIMAL(12,2) NOT NULL DEFAULT 0,
    rounding                 DECIMAL(6,2) NOT NULL DEFAULT 0,

    -- Tracking úhrad
    advance_paid_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- Generated column (MariaDB 10.2+): vždy konzistentní s total - advance
    amount_to_pay            DECIMAL(12,2) AS (total_with_vat - advance_paid_amount) STORED,

    -- Multi-currency platby (faktura v USD, účet plátce v CZK/EUR)
    -- Pokud payment_currency_id == currency_id (běžný case), pak _payment_ccy == _invoice_ccy.
    -- Pro různé měny: payment_exchange_rate je kurz payment_ccy → invoice_ccy at payment.
    payment_currency_id      INT UNSIGNED NULL,
    payment_exchange_rate    DECIMAL(12,6) NULL,
    paid_amount_payment_ccy  DECIMAL(14,4) NULL,
    paid_amount_invoice_ccy  DECIMAL(14,4) NULL,
    -- Kurzový rozdíl v tenant base ccy (CZK):
    --   = paid_amount_payment_ccy × kurz_payment_ccy_to_base
    --     − total_with_vat × invoice.exchange_rate
    --   záporné = kurzová ztráta, kladné = kurzový zisk
    exchange_diff_base       DECIMAL(12,2) NULL,

    -- Stav životního cyklu
    --   draft     = vytvořeno, ještě nezaevidováno (může být upravováno / smazáno)
    --   received  = potvrzeno jako přijaté (faktura platí, čeká na zaúčtování)
    --   booked    = zaúčtováno (předáno účetní / posláno do účta)
    --   paid      = uhrazeno (potvrzeno z bank výpisu nebo ručně)
    --   cancelled = stornováno
    status                   ENUM('draft','received','booked','paid','cancelled')
                                NOT NULL DEFAULT 'draft',

    -- Timestampy klíčových přechodů
    booked_at                TIMESTAMP NULL,
    paid_at                  DATE NULL,
    cancelled_at             TIMESTAMP NULL,

    -- Příloha: originální PDF od dodavatele (scan nebo email PDF).
    -- Cesta je relativní k uploads_dir (mimo webroot, per security policy).
    pdf_path                 VARCHAR(255) NULL,
    pdf_uploaded_at          TIMESTAMP NULL,

    -- VAT klasifikace dle MFČR (kódy 40-41, 42, 43, 23, 24, 26 …) — prep pro fázi 6.
    -- Nullable, default-set podle vat_rate v PurchaseInvoiceCalculator.
    vat_classification_code  VARCHAR(10) NULL,

    -- Audit
    created_by               BIGINT UNSIGNED NOT NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    --   Unique varsymbol per tenant (žádné dva PF se stejným naším číslem)
    UNIQUE KEY uq_pi_supplier_varsymbol (supplier_id, varsymbol),
    --   Unique vendor's invoice number per tenant per vendor per měsíc — anti dup-import
    UNIQUE KEY uq_pi_vendor_invoice (supplier_id, vendor_id, vendor_invoice_number, issue_date),

    -- Indexes pro filtry v ListAction
    KEY idx_pi_supplier      (supplier_id),
    KEY idx_pi_vendor        (vendor_id, issue_date DESC),
    KEY idx_pi_status_due    (supplier_id, status, due_date),
    KEY idx_pi_tax_date      (supplier_id, tax_date),
    KEY idx_pi_received_at   (supplier_id, received_at),
    KEY idx_pi_document_kind (supplier_id, document_kind),

    CONSTRAINT fk_pi_supplier         FOREIGN KEY (supplier_id)         REFERENCES supplier(id),
    CONSTRAINT fk_pi_vendor           FOREIGN KEY (vendor_id)           REFERENCES clients(id),
    CONSTRAINT fk_pi_currency         FOREIGN KEY (currency_id)         REFERENCES currencies(id),
    CONSTRAINT fk_pi_payment_currency FOREIGN KEY (payment_currency_id) REFERENCES currencies(id),
    CONSTRAINT fk_pi_user             FOREIGN KEY (created_by)          REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- purchase_invoice_items
--
-- Paralel k invoice_items. ON DELETE CASCADE → smazání faktury smaže položky.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS purchase_invoice_items (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_invoice_id      BIGINT UNSIGNED NOT NULL,

    description              TEXT NOT NULL,
    quantity                 DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    unit                     VARCHAR(20) NOT NULL DEFAULT 'ks',
    unit_price_without_vat   DECIMAL(12,2) NOT NULL,

    vat_rate_id              INT UNSIGNED NOT NULL,
    vat_rate_snapshot        DECIMAL(5,2) NOT NULL,

    total_without_vat        DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_vat                DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_with_vat           DECIMAL(12,2) NOT NULL DEFAULT 0,

    order_index              INT NOT NULL DEFAULT 0,

    -- VAT klasifikace per řádek — pro fázi 6 detailní rozpad (KH řádky)
    vat_classification_code  VARCHAR(10) NULL,

    KEY idx_pii_invoice (purchase_invoice_id, order_index),
    CONSTRAINT fk_pii_invoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_pii_vat     FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- purchase_invoice_counters
--
-- Per (supplier, year_month) counter pro generování varsymbolu PF-YYYYMM-NNNN.
-- Samostatná tabulka (ne reuse `invoice_counters`) — purchase má jiný formát + jiný namespace.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS purchase_invoice_counters (
    supplier_id  TINYINT UNSIGNED NOT NULL,
    period       CHAR(6) NOT NULL,             -- "YYYYMM", např. "202605"
    last_number  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (supplier_id, period),
    CONSTRAINT fk_pic_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
