-- MyInvoice.cz — ukladani odeslanych e-mailu profilu do IMAP slozky.
--
-- Nastaveni je vazane na odesilaci profil. Bez profilu nebo pri vypnute volbe
-- se do IMAP neuklada a nepouziva se zadny tichy fallback na cfg.php.

SET NAMES utf8mb4;

ALTER TABLE email_profiles
  ADD COLUMN IF NOT EXISTS imap_sent_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER sendmail_command,
  ADD COLUMN IF NOT EXISTS imap_host VARCHAR(190) NULL AFTER imap_sent_enabled,
  ADD COLUMN IF NOT EXISTS imap_port INT UNSIGNED NULL AFTER imap_host,
  ADD COLUMN IF NOT EXISTS imap_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'ssl' AFTER imap_port,
  ADD COLUMN IF NOT EXISTS imap_validate_cert TINYINT(1) NOT NULL DEFAULT 1 AFTER imap_encryption,
  ADD COLUMN IF NOT EXISTS imap_username VARCHAR(190) NULL AFTER imap_validate_cert,
  ADD COLUMN IF NOT EXISTS imap_password_enc VARCHAR(255) NULL AFTER imap_username,
  ADD COLUMN IF NOT EXISTS imap_folder VARCHAR(190) NULL AFTER imap_password_enc,
  ADD COLUMN IF NOT EXISTS imap_create_folder TINYINT(1) NOT NULL DEFAULT 0 AFTER imap_folder,
  ADD COLUMN IF NOT EXISTS imap_mark_seen TINYINT(1) NOT NULL DEFAULT 1 AFTER imap_create_folder,
  ADD COLUMN IF NOT EXISTS imap_timeout INT UNSIGNED NOT NULL DEFAULT 30 AFTER imap_mark_seen,
  ADD COLUMN IF NOT EXISTS imap_on_failure ENUM('log_only','fail_send') NOT NULL DEFAULT 'log_only' AFTER imap_timeout;
