-- Bankovní pohyby importované z iDokladu.
-- source_ref nese stabilní iDoklad BankStatement.Id a zajišťuje idempotenci.
--
-- POZOR: UNIQUE níže se přidává na ŽIVOU tabulku. Kdyby v datech existovala
-- duplicita (source, source_ref), ALTER by spadl na kryptické „Duplicate entry"
-- a zablokoval i všechny NÁSLEDUJÍCÍ migrace — z pohledu uživatele se rozbije
-- upgrade aplikace, ne import z iDokladu. Proto se nejdřív ověří data a při
-- nálezu se migrace zastaví s čitelnou hláškou, ještě než se cokoli změní.
--
-- Duplicity dohledáš takto:
--   SELECT source, source_ref, COUNT(*) c FROM bank_transactions
--    WHERE source_ref IS NOT NULL GROUP BY 1,2 HAVING c > 1;
--
-- Vznikat by neměly (email_notice se zakládá přes idempotenční lookup v
-- BankEmailNoticeRepository), ale při race na dvou souběžných IMAP scanech to
-- teoreticky možné je. Řešení je ruční — jeden z řádků může nést navázané
-- invoice_payments / payment_matches, takže se nesmí mazat naslepo.
--
-- NULL se v UNIQUE indexu nikdy nekoliduje, takže GPC transakce (source_ref
-- IS NULL) jsou mimo hru a kontrola je záměrně vynechává.

DELIMITER //

BEGIN NOT ATOMIC
  DECLARE dup_count INT DEFAULT 0;

  SELECT COUNT(*) INTO dup_count FROM (
    SELECT 1 FROM bank_transactions
     WHERE source_ref IS NOT NULL
     GROUP BY source, source_ref
    HAVING COUNT(*) > 1
  ) d;

  IF dup_count > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
      'Migrace 0136 zastavena: bank_transactions maji duplicitni (source, source_ref). Viz komentar v migraci.';
  END IF;
END //

DELIMITER ;

ALTER TABLE bank_statements
  MODIFY COLUMN source ENUM('gpc','email_notice','pdf','idoklad') NOT NULL DEFAULT 'gpc';

ALTER TABLE bank_transactions
  MODIFY COLUMN source ENUM('statement','email_notice','idoklad') NOT NULL DEFAULT 'statement';

ALTER TABLE bank_transactions
  ADD UNIQUE KEY IF NOT EXISTS uq_bank_transaction_source_ref (source, source_ref);
