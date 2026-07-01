-- MyInvoice.cz — doplnění SMTP voleb profilů podle cfg.php.
--
-- Idempotentní doplnění polí pro typ autentizace, TLS validaci a timeout.

SET NAMES utf8mb4;

ALTER TABLE email_profiles
  ADD COLUMN IF NOT EXISTS smtp_auth_type ENUM('LOGIN','PLAIN','CRAM-MD5','XOAUTH2') NOT NULL DEFAULT 'PLAIN' AFTER smtp_auth_enabled,
  ADD COLUMN IF NOT EXISTS smtp_verify_peer_name TINYINT(1) NOT NULL DEFAULT 1 AFTER smtp_verify_peer,
  ADD COLUMN IF NOT EXISTS smtp_allow_self_signed TINYINT(1) NOT NULL DEFAULT 0 AFTER smtp_verify_peer_name,
  ADD COLUMN IF NOT EXISTS smtp_timeout INT UNSIGNED NULL AFTER smtp_allow_self_signed,
  ADD COLUMN IF NOT EXISTS smtp_keepalive TINYINT(1) NOT NULL DEFAULT 0 AFTER smtp_timeout;
