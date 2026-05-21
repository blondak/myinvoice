-- MyInvoice.cz — Klienti vs. dodavatelé: role flagy
--
-- Před fází 1 (přijaté faktury) byl `clients` výhradně pro odběratele vystavených faktur.
-- Po fázi 1 ukládáme i protistrany přijatých faktur (vendors) do stejné tabulky —
-- jedna entita = jeden ARES lookup, jedna VIES validace, jedna historie kontaktů,
-- a některé firmy jsou současně zákazník i dodavatel.
--
-- Rozlišení rolí přes 2 flagy (kvůli dual-role případům):
--   is_customer = 1 → zobrazí se v /clients
--   is_vendor   = 1 → zobrazí se v /vendors (alias na /clients?role=vendor)
--   obě = 1       → zobrazí se v obou seznamech (partner firma)
--
-- Backfill: všechna existující data dostávají is_customer=1, is_vendor=0 — zachovává
-- původní chování /clients seznamu, kde dosud byli jen odběratelé.
--
-- Idempotence: ALTER … ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS is_customer TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'True pokud klientovi vystavujeme faktury (default pro existující záznamy)' AFTER reverse_charge,
    ADD COLUMN IF NOT EXISTS is_vendor TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'True pokud od něj přijímáme faktury (dodavatel)' AFTER is_customer;

-- Indexy pro rychlý filtr v ListClientsAction (per role + per tenant)
CREATE INDEX IF NOT EXISTS idx_clients_customer ON clients (supplier_id, is_customer);
CREATE INDEX IF NOT EXISTS idx_clients_vendor   ON clients (supplier_id, is_vendor);
