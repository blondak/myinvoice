-- MyInvoice.cz — doplnkove volby IMAP kopie odesilacich profilu.
--
-- Navazuje na 0129. Soubor je samostatny kvuli databazim, kde uz byla
-- puvodni 0129 aplikovana pred doplnenim techto voleb.

SET NAMES utf8mb4;

ALTER TABLE email_profiles
  ADD COLUMN IF NOT EXISTS imap_mark_seen TINYINT(1) NOT NULL DEFAULT 1 AFTER imap_create_folder,
  ADD COLUMN IF NOT EXISTS imap_timeout INT UNSIGNED NOT NULL DEFAULT 30 AFTER imap_mark_seen,
  ADD COLUMN IF NOT EXISTS imap_on_failure ENUM('log_only','fail_send') NOT NULL DEFAULT 'log_only' AFTER imap_timeout;
