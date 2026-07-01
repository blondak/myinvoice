-- MyInvoice.cz — transport odchozích e-mailových profilů.
--
-- Profil může použít globální cfg.php transport, vlastní SMTP nebo lokální sendmail.
-- SMTP heslo je uložené šifrovaně přes SecretEncryption.

SET NAMES utf8mb4;

ALTER TABLE email_profiles
  ADD COLUMN IF NOT EXISTS transport_type ENUM('global','smtp','sendmail') NOT NULL DEFAULT 'global' AFTER dkim_enabled,
  ADD COLUMN IF NOT EXISTS smtp_host VARCHAR(190) NULL AFTER transport_type,
  ADD COLUMN IF NOT EXISTS smtp_port INT UNSIGNED NULL AFTER smtp_host,
  ADD COLUMN IF NOT EXISTS smtp_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls' AFTER smtp_port,
  ADD COLUMN IF NOT EXISTS smtp_auth_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER smtp_encryption,
  ADD COLUMN IF NOT EXISTS smtp_auth_type ENUM('LOGIN','PLAIN','CRAM-MD5','XOAUTH2') NOT NULL DEFAULT 'PLAIN' AFTER smtp_auth_enabled,
  ADD COLUMN IF NOT EXISTS smtp_username VARCHAR(190) NULL AFTER smtp_auth_type,
  ADD COLUMN IF NOT EXISTS smtp_password_enc VARCHAR(255) NULL AFTER smtp_username,
  ADD COLUMN IF NOT EXISTS smtp_verify_peer TINYINT(1) NOT NULL DEFAULT 1 AFTER smtp_password_enc,
  ADD COLUMN IF NOT EXISTS smtp_verify_peer_name TINYINT(1) NOT NULL DEFAULT 1 AFTER smtp_verify_peer,
  ADD COLUMN IF NOT EXISTS smtp_allow_self_signed TINYINT(1) NOT NULL DEFAULT 0 AFTER smtp_verify_peer_name,
  ADD COLUMN IF NOT EXISTS smtp_timeout INT UNSIGNED NULL AFTER smtp_allow_self_signed,
  ADD COLUMN IF NOT EXISTS smtp_keepalive TINYINT(1) NOT NULL DEFAULT 0 AFTER smtp_timeout,
  ADD COLUMN IF NOT EXISTS sendmail_command VARCHAR(255) NULL AFTER smtp_keepalive;
