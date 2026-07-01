-- MyInvoice.cz — zpětná kompatibilita S/MIME identity policy.
--
-- Nově zavedená kontrola shody From ↔ certifikát má výchozí režim `strict_match`
-- (pro NOVÉ profily = bezpečné chování). Dodavatelé, kteří S/MIME podpis e-mailů
-- používali JIŽ PŘED touto změnou, žádnou kontrolu neměli — jejich e-maily by se
-- teď mohly začít posílat nepodepsané (fallback_unsigned) nebo by odeslání spadlo
-- (fail_closed) kvůli mismatchi From (globální noreply) vs. certifikát.
--
-- Proto stávající e-mailová output settings (usage = 'email_smime'), která ještě
-- nemají explicitně zvolenou politiku, uzamkneme na `warning_only` = zachová se
-- původní chování (podepíše, jen zaloguje varování). Nové profily zakládané přes
-- UI si volí politiku samy a defaultují na `strict_match`.
--
-- Idempotence: JSON_SET jen tam, kde klíč ještě neexistuje (re-run safe).

SET NAMES utf8mb4;

UPDATE pdf_signature_output_settings
   SET signature_config_json = JSON_SET(
         COALESCE(signature_config_json, JSON_OBJECT()),
         '$.smime_identity_policy',
         'warning_only'
       )
 WHERE `usage` = 'email_smime'
   AND JSON_EXTRACT(signature_config_json, '$.smime_identity_policy') IS NULL;
