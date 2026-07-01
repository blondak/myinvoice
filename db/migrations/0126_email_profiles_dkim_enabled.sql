-- MyInvoice.cz — explicitní zapnutí DKIM v odesílacích profilech.
--
-- Profil s vypnutým DKIM nesmí spadnout na globální cfg.php DKIM identitu.
-- DKIM profil vyžaduje vlastní doménu i selector; privátní klíč zůstává globální.

SET NAMES utf8mb4;

ALTER TABLE email_profiles
  ADD COLUMN IF NOT EXISTS dkim_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER dkim_selector;

UPDATE email_profiles
   SET dkim_enabled = 1
 WHERE dkim_domain IS NOT NULL
   AND dkim_domain <> ''
   AND dkim_selector IS NOT NULL
   AND dkim_selector <> '';
