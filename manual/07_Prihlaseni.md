# 7. Přihlášení a uživatelský profil

## 7.1 Přihlášení

![Přihlašovací obrazovka](img/04_login.webp)

Zadej e-mail + heslo a klikni **Přihlásit**. Pokud má účet zapnuté silné
vícefaktorové ověření, následuje potvrzení passkey nebo zadání TOTP kódu. Po
úspěšném ověření tě systém pustí na [Přehled (dashboard)](08_Prehled.md).

| Pole | Význam |
|---|---|
| E-mail | Login zadaný při registraci |
| Heslo | Heslo zadané při registraci |
| Zapomenuté heslo? | Odkaz na obnovu — viz § 7.4 |

## 7.2 Brute-force ochrana

Po **5 neúspěšných pokusech** během 5 minut z jedné IP se objeví **CAPTCHA**
(Cloudflare Turnstile). Po **10 selháních** během 15 minut se IP zablokuje na
15 minut. Po **30 selháních za hodinu** je lockout 24 hodin a uživateli na
e-mail přijde upozornění.

> 🛈 Pokud heslo zapomeneš a omylem se 5× spletl, CAPTCHA se objeví — vyřeš ji
> a pokračuj. Pokud se zablokuješ, počkej 15 minut nebo požádej admina, aby ti
> heslo resetoval z CLI: `php api/bin/set-password.php tvuj@email.cz`.

## 7.3 Vícefaktorové ověření (passkey / TOTP)

Pokud má účet passkey, aplikace ji po zadání hesla nabídne jako hlavní způsob
ověření. Systémový dialog zařízení může použít otisk prstu, obličej, PIN, gesto,
heslo zařízení nebo externí bezpečnostní klíč. MyInvoice konkrétní metodu
nevybírá a biometrická data nikdy nedostane.

Má-li účet současně aktivní TOTP, můžeš místo passkey zvolit
**Použít kód z autentikátoru** a zadat aktuální šestimístný kód. Zrušení
systémového dialogu passkey TOTP samo nespustí.

MyInvoice nepoužívá záložní jednorázové recovery kódy. Obnova přístupu probíhá
jinou passkey, TOTP nebo administrátorským CLI rescue. Podrobnosti jsou v
[39. Bezpečnost](39_Bezpecnost.md).

## 7.4 Zapomenuté heslo

Klikni na **Zapomenuté heslo?** pod přihlášením. Zadej e-mail, na který přijde
odkaz pro nastavení nového hesla (platnost 1 hodina).

![Reset hesla](img/04_reset.webp)

Pokud e-mail nepřišel:

- Zkontroluj spam.
- Ověř s adminem, že systém má nakonfigurované SMTP (`cfg.php` → `smtp.*`).
- V krajním případě admin nastaví heslo z CLI: `php api/bin/set-password.php tvuj@email.cz`.

## 7.5 Můj profil

V pravém horním rohu klikni na své jméno → **Můj profil**.

![Můj profil](img/04_profil.webp)

Můžeš si změnit:

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v UI + activity log |
| Jazyk | `cs` (čeština) nebo `en` (angličtina) — UI + e-mailové šablony |
| Heslo | Změna stávajícího hesla (vyžaduje původní) |
| TOTP | Zobrazit stav a aktivovat pomocí QR + ověřovacího kódu |
| Passkeys | Přidat, pojmenovat, přejmenovat nebo odvolat přístupový klíč |

Pro běžný účet je vhodné mít dvě passkeys, případně jednu passkey a aktivní
TOTP. Přidání nebo odvolání passkey vyžaduje čerstvé ověření existujícím silným
faktorem. Pokud žádný nemáš, první registrace vyžádá aktuální heslo.

## 7.6 Zamknutí a odemčení aplikace

V uživatelském menu můžeš zvolit **Zamknout**. Aplikace se také automaticky
zamkne po době nečinnosti nastavené správcem. Zámek je uložený na serveru:
nejde jen o překryv obrazovky a zamčená session nemůže číst ani měnit business
data přes API.

Pro odemčení stiskni **Odemknout pomocí passkey** a potvrď systémový dialog.
Odemčení nevyžaduje znovu zadat e-mail a heslo. Když passkey není dostupná,
zvol **Přihlásit se znovu**; aplikace nejprve bezpečně ukončí zamčenou session
a provede celý login včetně MFA.

Zámek zachová rozepsaný formulář pouze po dobu, kdy prohlížeč drží stránku
v paměti. Pokud Android stránku ukončí, neuložené změny se ztratí. Webová PWA
také nedokáže garantovat zákaz screenshotu ani skrytí náhledu v Android Recents.

## 7.7 Odhlášení

V pravém horním rohu klikni **Odhlásit**. Session se zruší okamžitě i na
serveru. Pokud nezmáčkneš odhlásit a jen zavřeš okno, session má absolutní
platnost nejvýše **30 dní**; během ní se může dříve zamknout po nečinnosti.
