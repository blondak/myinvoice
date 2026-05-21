-- MyInvoice.cz — Fáze 6: VAT klasifikační kódy pro DPH výkaz + KH
--
-- Číselník klasifikací podle MF ČR — určuje, na který řádek DPHDP3 (DPH přiznání)
-- a KH (kontrolní hlášení) faktura/řádek patří.
--
-- Per fork analysis: kódy 1-9 (vystavené tuzemsko), 21-26 (přijaté tuzemsko),
-- 31-39 (vývoz EU/3.země), 40-44 (DPH odvody/odpočty).
--
-- Seedujeme nejdůležitější kódy. Uživatel může přidat custom v Codebooks UI.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vat_classifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id     TINYINT UNSIGNED NULL COMMENT 'NULL = globální/seed (sdíleno), jinak per-tenant override',
    code            VARCHAR(8) NOT NULL COMMENT 'Kód MF (např. "1", "40", "42")',
    label           VARCHAR(150) NOT NULL,
    direction       ENUM('sale', 'purchase', 'both') NOT NULL DEFAULT 'both'
                       COMMENT 'sale = vystavená, purchase = přijatá, both',
    dphdp3_line     VARCHAR(10) NULL COMMENT 'Řádek v DPH přiznání DPHDP3 (např. "1", "40")',
    kh_section      VARCHAR(8)  NULL COMMENT 'Sekce kontrolního hlášení (např. "A.4", "B.2")',
    vat_rate        DECIMAL(5, 2) NULL COMMENT 'Sazba spojená (21.00, 15.00, …), NULL = bez DPH',
    is_reverse_charge TINYINT(1) NOT NULL DEFAULT 0,
    display_order   INT NOT NULL DEFAULT 0,
    archived        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_vat_cls (supplier_id, code),
    KEY idx_vat_cls_direction (direction, archived),
    CONSTRAINT fk_vatcls_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed základních kódů (supplier_id = NULL → sdíleno všem tenantům)
-- Vystavené tuzemské:
INSERT IGNORE INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, display_order)
VALUES
    (NULL, '1',  'Dodání zboží nebo poskytnutí služby s místem plnění v tuzemsku – základní sazba 21 %',  'sale', '1',  'A.4', 21.00, 10),
    (NULL, '2',  'Dodání zboží nebo poskytnutí služby s místem plnění v tuzemsku – snížená sazba 12 %',   'sale', '2',  'A.4', 12.00, 11),
    (NULL, '3',  'Dodání zboží nebo poskytnutí služby s místem plnění v tuzemsku – osvobozeno',           'sale', '3',  NULL,  0.00, 12),
    (NULL, '20', 'Dodání zboží do jiného členského státu EU (osvobozené)',                                'sale', '20', 'A.1', 0.00, 20),
    (NULL, '22', 'Poskytnutí služby do jiného členského státu EU',                                        'sale', '22', NULL,  0.00, 21),
    (NULL, '26', 'Vývoz zboží do 3. země',                                                                'sale', '26', NULL,  0.00, 22),

    -- Přijaté tuzemské:
    (NULL, '40', 'Přijaté plnění v tuzemsku – základní sazba 21 % (nárok na odpočet)',                    'purchase', '40', 'B.2', 21.00, 40),
    (NULL, '41', 'Přijaté plnění v tuzemsku – snížená sazba 12 % (nárok na odpočet)',                     'purchase', '41', 'B.2', 12.00, 41),
    (NULL, '42', 'Přijaté plnění v tuzemsku – základní sazba bez nároku na odpočet',                      'purchase', '42', NULL, 21.00, 42),

    -- Reverse charge (přenesená povinnost):
    (NULL, '5',  'Přijaté plnění s místem plnění v tuzemsku v režimu přenesené povinnosti',               'purchase', '10', 'B.1', 21.00, 50),

    -- Acquire z EU (přijaté ze zahraničí):
    (NULL, '23', 'Pořízení zboží z jiného členského státu EU (reverse charge)',                           'purchase', '3',  'A.2', 21.00, 60),
    (NULL, '24', 'Přijetí služby z jiného členského státu EU',                                            'purchase', '5',  NULL, 21.00, 61),
    (NULL, '25', 'Dovoz zboží ze 3. země',                                                                'purchase', '7',  NULL, 21.00, 62);

-- Připojení kódu k jednotlivým fakturám (vystaveným i přijatým) a jejich řádkům
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS vat_classification_code VARCHAR(8) NULL
        COMMENT 'Default klasifikační kód pro celou fakturu (řádky mohou override)';

ALTER TABLE invoice_items
    ADD COLUMN IF NOT EXISTS vat_classification_code VARCHAR(8) NULL
        COMMENT 'Override per řádek';

-- purchase_invoices už mají vat_classification_code z fáze 1 — ověřit a doplnit pokud chybí
ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS vat_classification_code VARCHAR(8) NULL;

ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS vat_classification_code VARCHAR(8) NULL;
