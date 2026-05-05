-- MyInvoice.cz — uchová přesný den kurzu pro audit + zobrazení.
--
-- Při fallbacku (víkend/svátek/feed nedostupný) může být použit kurz ze
-- dříve dostupného dne — např. pátek pro nedělní fakturu, nebo last-known
-- z minulého týdne. Bez tohoto pole bychom v UI/PDF nemohli říct "kurz ze
-- dne X" — jen předpokládat issue_date, což pro fallback klame.

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS exchange_rate_date DATE NULL DEFAULT NULL
    AFTER exchange_rate;
