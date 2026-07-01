-- MyInvoice.cz — explicitní zapnutí Reply-To v odesílacích profilech.
--
-- Profil s vypnutým Reply-To nesmí spadnout na dodavatele/cfg.php fallback.
-- Samostatný flag odlišuje "nenastaveno" od "záměrně nepoužívat".

SET NAMES utf8mb4;

ALTER TABLE email_profiles
  ADD COLUMN IF NOT EXISTS reply_to_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER reply_to_name;

UPDATE email_profiles
   SET reply_to_enabled = 1
 WHERE reply_to_email IS NOT NULL
   AND reply_to_email <> '';
