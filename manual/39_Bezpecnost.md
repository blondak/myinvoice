# 39. Bezpečnost (MFA, passkeys, zámek session, role)

Bezpečnost MyInvoice stojí na několika navazujících vrstvách:

1. **Autentizace** — bcrypt hesla + peppered + brute-force ochrana + CAPTCHA
2. **Silné MFA** — passkey nebo TOTP
3. **Síťová izolace** — IP allowlist (volitelný, doporučeno v produkci)
4. **Autorizace** — role-based access (admin / accountant / readonly)
5. **Audit** — activity log všech mutací
6. **Zámek session** — serverové uzamčení PWA po nečinnosti

## 39.1 Hesla

| Vrstva | Detail |
|---|---|
| Algoritmus | bcrypt cost 12 |
| Pepper | Sůl z `cfg.php → app.pepper` (32B base64), neukládá se v DB |
| Min. délka | 12 znaků |
| Max. délka | Bez limitu — passphrase je doporučená (20+ znaků) |
| Kontrola síly | Indikátor v UI (slabé / střední / silné) |
| Reset hesla | Odkaz na 1 hodinu, e-mailem |

> 💡 **Passphrase je bezpečnější než krátké složité heslo.** „korelace medvědí
> dýně přístav 2026" má 49 znaků a je odolnější vůči brute-force než „Hu1@n!22".

## 39.2 Vícefaktorové ověření

MyInvoice podporuje dva silné faktory:

- **passkey (WebAuthn)** — kryptografický přístupový klíč chráněný zařízením,
- **TOTP** — šestimístný časový kód z autentikátoru.

E-mailové OTP je kompatibilní druhý krok pro účet bez silného faktoru, ale
nesplňuje povinnou silnou MFA politiku. Důvěryhodné zařízení se týká pouze
e-mailového OTP.

### 39.2.1 Passkeys

Passkey zaregistruješ v **Můj profil → Zabezpečení → Passkeys**. Každý klíč má
vlastní název, datum vytvoření a posledního použití. Lze jej přejmenovat nebo
odvolat. Aplikace podporuje více klíčů; doporučené jsou dvě passkeys nebo jedna
passkey spolu s TOTP.

Passkey se používá:

- po správném e-mailu a hesle místo TOTP,
- k odemčení zamčené browserové/PWA session,
- jako čerstvé potvrzení citlivé operace, například vytvoření API tokenu.

Systémový dialog může podle zařízení použít otisk, obličej, PIN, gesto, heslo
zařízení nebo externí bezpečnostní klíč. MyInvoice konkrétní metodu nezjišťuje,
biometrická data neopouštějí zařízení a server ukládá pouze veřejný klíč.
Poskytovatel platformy nebo password manager může passkey end-to-end šifrovaně
synchronizovat mezi zařízeními.

Passkeys vyžadují stabilní veřejnou URL. V produkci musí `app.url` obsahovat
přesný HTTPS origin, například `https://faktury.example.cz`. Klíč je svázaný
s hostname; po změně domény jej na nové doméně nelze použít. Pro lokální vývoj
je podporované `http://localhost`, nikoli běžný HTTP přístup přes LAN IP.

Přidání a odvolání passkey vyžaduje nové ověření passkey nebo TOTP. U účtu bez
dosavadního silného faktoru první registrace vyžádá aktuální heslo. Při povinném
MFA nelze odvolat poslední povolený silný faktor.

TOTP = time-based one-time password (RFC 6238).

### 39.2.2 Aktivace TOTP

**Můj profil → 2FA → Aktivovat**.

![Aktivace 2FA](img/16_2fa_setup.webp)

1. Aplikace ukáže **QR kód** + textový **secret key**.
2. V mobilu otevři **autentikátor** (Google Authenticator, Authy, Microsoft
   Authenticator, 1Password, Bitwarden) → Přidat účet → Sken QR kódu.
3. Aplikace začne generovat 6-cifrené kódy každých 30 sekund.
4. Zadej aktuální kód do MyInvoice → **Potvrdit aktivaci**.

> ⚠️ MyInvoice **nepoužívá záložní jednorázové kódy** (recovery codes).
> Při ztrátě autentikátoru použij jinou passkey, nebo CLI rescue:
> `php api/bin/reset-mfa.php <email>` — viz [§ 39.2.4](#3924-obnova-pristupu).

### 39.2.3 Přihlášení s MFA

Po zadání e-mailu a hesla nabídne aplikace passkey, pokud ji účet má. Je-li
aktivní také TOTP, lze explicitně přepnout na šestimístný kód z autentikátoru.

![2FA výzva](img/04_2fa.webp)

Účet s passkey nedostane automatický fallback na e-mailový kód. Pokud passkey
na aktuálním zařízení není dostupná, použij jinou passkey, TOTP nebo rescue.

### 39.2.4 Obnova přístupu

Nejprve použij jinou zaregistrovanou passkey nebo TOTP. Pokud není dostupný
žádný silný faktor, správce může na serveru spustit:

```bash
php api/bin/reset-mfa.php tvuj@email.cz
```

Skript vypne TOTP, odvolá všechny passkeys, zruší důvěryhodná zařízení,
čekající OTP, WebAuthn flow a step-up proofy a invaliduje všechny session
uživatele. Původní název `reset-2fa.php` zůstává kompatibilním aliasem.

> ⚠️ Rescue používej jen z důvěryhodného shellu serveru. Přímý SQL zásah není
> ekvivalentní: snadno ponechá aktivní session nebo rozpracované ověřovací flow.

### 39.2.5 Vynucení silného MFA

Pokud chceš, aby **každý** uživatel měl passkey nebo TOTP,
nastav v `cfg.php` (nebo `cfg.local.php`):

```php
'auth' => [
    'require_mfa' => true,
    'allowed_mfa_methods' => ['passkey', 'totp'],
],
```

Stejné lze přepnout přes ENV (Docker / PaaS):

```bash
MYINVOICE_AUTH_REQUIRE_MFA=true
MYINVOICE_AUTH_MFA_METHODS=passkey,totp
```

Chování:

- Uživatel bez povoleného silného faktoru dostane omezenou setup session a
  stránku `/setup-mfa`, kde zaregistruje passkey nebo zapne TOTP.
- Setup session smí pouze dokončit povolené MFA nastavení nebo se odhlásit.
  Business API zůstává serverově blokované.
- Po dokončení se setup session zneplatní a vydá se nové session ID i CSRF.

Starší `auth.require_totp = true` a `MYINVOICE_AUTH_REQUIRE_TOTP=true` zůstávají
podporované jako TOTP-only politika. Pro nové instalace používej obecné MFA
nastavení.

> ⚠️ Povolení TOTP vyžaduje validní `app.secret_encryption_key` (32B base64).
> Health endpoint na chybnou konfiguraci upozorní; viz
> [§ 99 Řešení problémů](99_Reseni_problemu.md).

### 39.2.6 E-mailové ověření pro účet bez silného faktoru

Pro uživatele, kteří nechtějí (nebo neumí) authenticator aplikaci — typicky
externí účetní — lze zapnout **e-mailové OTP** jako druhý faktor. Kdo nemá
aktivní passkey ani TOTP, dostane po zadání hesla 6místný kód na e-mail a musí
ho opsat.

Zapnutí v `cfg.php` (výchozí stav je **vypnuto** — nejde o breaking change):

```php
'auth' => [
    'email_otp' => [
        'enabled'                 => true,  // kód jen pro účet bez passkey i TOTP
        'code_ttl_minutes'        => 10,    // platnost kódu
        'max_attempts'            => 5,     // pokusů na jeden kód, pak je nutný nový
        'resend_cooldown_seconds' => 60,    // min. prodleva mezi odesláním nového kódu
        'trusted_device_days'     => 30,    // „zapamatovat toto zařízení" na kolik dní
        'trusted_cookie_name'     => '__Host-myinvoice_td',
    ],
],
```

Chování:

- **Priorita silného faktoru.** Má-li uživatel passkey nebo TOTP, e-mailové OTP
  se neuplatní.
- **Po heslu** se zobrazí pole pro kód z e-mailu + tlačítko *„Kód nedorazil?
  Odeslat znovu"* s odpočtem (cooldown). Kód je jednorázový a hashovaný v DB
  (sloupec `login_otps.code_hash`, nikdy plaintext).
- **„Zapamatovat toto zařízení na 30 dní"** (checkbox) vystaví cookie
  důvěryhodného zařízení; na něm se druhý faktor po danou dobu nevyžaduje.
  Heslo se vyžaduje vždy. Týká se jen e-mailového OTP, ne TOTP.
- **Brute-force.** Šestimístný kód je chráněn per-user lockoutem (10 selhání /
  10 min) stejně jako TOTP.

> ⚠️ Vyžaduje funkční **SMTP**. Když e-maily nechodí, uživatelé bez TOTP se
> nepřihlásí — buď oprav SMTP, nebo nastav `enabled => false`. Nouzově lze
> uživateli zrušit i důvěryhodná zařízení a čekající kódy:
> `php api/bin/reset-mfa.php <email>`.

### 39.2.7 Serverový zámek session

Automatický zámek browserové a PWA session je ve výchozím stavu vypnutý, aby se
po aktualizaci nezměnilo chování existujících instalací. Správce nastavuje
výchozí timeout pomocí `session.lock_after_minutes` nebo
`MYINVOICE_SESSION_LOCK_AFTER_MINUTES`. Hodnota `0` znamená, že správce zámek
nevynucuje. Uživatel jej přesto může dobrovolně zapnout v profilu na záložce
**Zámek aplikace**.

Osobní nastavení má tyto hranice:

- **Použít nastavení správce** zachová hodnotu správce; při `0` je automatický
  zámek vypnutý.
- Pokud správce nastavil kladnou hodnotu, osobní interval může být pouze stejný
  nebo kratší.
- Při hodnotě správce `0` lze zvolit vlastní interval 1 až 1440 minut.
- Pozdější snížení limitu správce okamžitě zpřísní i dříve uloženou delší osobní
  volbu.
- Zkrácení timeoutu se vyhodnotí serverově hned při uložení a může aktuální
  session rovnou zamknout.

Ruční **Zamknout** v uživatelském menu zůstává dostupné bez ohledu na timeout.

Aktivitu posouvají pouze skutečné vstupy do viditelné soukromé stránky, například
kliknutí, dotyk nebo klávesa. Polling, běžné API requesty, focus okna ani service
worker timeout neposouvají. Po dosažení limitu backend označí session jako
zamčenou a odmítne business API i v případě, že někdo odstraní frontendový
overlay.

Odemčení vyžaduje passkey a rotuje session ID i CSRF token, přičemž zachová
původní absolutní expiraci. TOTP existující zamčenou session přímo neodemkne;
volba **Přihlásit se znovu** provede bezpečný logout a celý login.

Zámek omezuje náhodný přístup k odloženému odemčenému zařízení. Nechrání data,
která už přečetl malware nebo XSS během aktivní session. Webová PWA negarantuje
zákaz screenshotu ani skrytí Android Recents. Rozpracovaný formulář zůstane
zachovaný jen dokud prohlížeč stránku drží v paměti; po ukončení stránky
Androidem se neuložená data ztratí. Offline odemčení není možné, protože server
musí vydat a ověřit jednorázovou challenge.

## 39.3 Brute-force ochrana

| Pokusy během | Akce |
|---|---|
| 5 selhání / 5 minut | CAPTCHA (Cloudflare Turnstile) |
| 10 selhání / 15 minut | Lockout 15 minut (per IP) |
| 30 selhání / 1 hodinu | Lockout 24 hodin + e-mail uživateli o pokusech |

Implementace: **Redis** pokud běží, jinak **MariaDB MEMORY engine** fallback.

## 39.4 IP allowlist (volitelné)

V `cfg.php → ip_allowlist.allow` můžeš omezit přístup jen na vybrané IP /
CIDR rozsahy.

```php
'ip_allowlist' => [
    'enabled' => true,
    'mode' => 'block',           // 'block' = ne-allowlisted IP dostane 403
    'allow' => [
        '127.0.0.1',
        '203.0.113.42',          // tvoje kancelářská WAN (IPv4)
        '2001:db8:1234::/48',    // IPv6 prefix
    ],
],
```

Doporučení v produkci:

- Tvá kancelářská IP
- VPN endpoint (pokud používáš)
- Rezervní mobilní hotspot pro nouzový přístup

> 🛈 IP allowlist je v `cfg.php` (file-based config) → změna vyžaduje SSH /
> deploy. Není v UI **schválně** — v případě omylu by ses zablokoval
> a nemohl si ho přes UI sundat.

### 39.4.1 Za reverse proxy: `trusted_proxies` (důležité)

Pokud aplikace běží **za reverse proxy** (doporučené produkční nasazení — viz
kap. 2), vidí všechny požadavky přicházet z IP proxy (např. brána Dockeru
`172.x.0.1`), ne od reálného klienta. Bez konfigurace pak:

- **IP allowlist** filtruje podle IP proxy — buď zablokuje všechny, nebo (když
  přidáš proxy do `allow`) pustí všechny → ochrana je neúčinná.
- **Brute-force lockout** (kap. 20.3) je fakticky **globální** — všechny pokusy
  vypadají ze stejné IP.
- **Audit log** loguje IP proxy místo reálného klienta (ztráta forenzní hodnoty).

Proto za reverse proxy uveď proxy do `trusted_proxies` — aplikace pak vezme
skutečnou klientskou IP z hlavičky `X-Forwarded-For`:

```php
'ip_allowlist' => [
    'trusted_proxies' => [
        '172.16.0.0/12',         // Docker bridge sítě
        // '10.0.0.0/8',         // nebo konkrétní IP/rozsah tvé proxy
    ],
    'header' => 'X-Forwarded-For', // výchozí; odkud číst reálnou IP (jen za trusted proxy)
],
```

> ⚠️ Do `trusted_proxies` patří **jen** IP/rozsahy proxy, kterým věříš —
> klient za nedůvěryhodnou proxy by jinak mohl `X-Forwarded-For` podvrhnout.
> Aplikace hlavičku respektuje pouze tehdy, když `REMOTE_ADDR` odpovídá
> `trusted_proxies`.

## 39.5 RBAC (role-based access)

Tři role. Hierarchie: **admin > accountant > readonly**.

| Schopnost | admin | accountant | readonly |
|---|:---:|:---:|:---:|
| Prohlížení dat (faktury, klienti, zakázky, banka, CRM, statistiky) | ✅ | ✅ | ✅ |
| **Exporty** (PDF / ISDOC / Pohoda / ZIP) | ✅ | ✅ | ✅ |
| **Daňové výkazy** (DPH, KH, SHV, daň z příjmů, kniha DPH, archiv EPO) — náhled i stažení XML/PDF | ✅ | ✅ | ✅ |
| Vystavování a editace dokladů, klienti, zakázky, recurring | ✅ | ✅ | ❌ |
| Import faktur, párování / nahrávání bankovních výpisů | ✅ | ✅ | ❌ |
| Editace / smazání **vystavené** faktury (force) | ✅ | ❌ | ❌ |
| Konfigurace systému (nastavení, číselníky, integrace, e-mail šablony) | ✅ | ❌ | ❌ |
| Správa uživatelů, activity log, cron, schvalování | ✅ | ❌ | ❌ |

**Klíčový princip:** `readonly` vidí **přesně totéž co `accountant`** (včetně exportů
a daňových výkazů — to vše jsou operace čtení) a smí **data exportovat**, ale
**nesmí nic vytvořit, upravit ani smazat**. Rozdíl mezi `accountant` a `readonly`
je jediný: zápis.

Vhodné použití:

- **admin** — vlastník / správce instalace.
- **accountant** — interní i externí účetní: plná práce s doklady a bankou, ale
  bez konfigurace systému a správy uživatelů.
- **readonly** — auditor, kontrolor nebo klient, který si má jen prohlížet a
  stahovat data (vč. DPH podkladů) bez rizika nechtěné změny.

### Jak je to vynucené

1. **Backend (`RoleMiddleware`)** — `readonly` smí výhradně `GET` requesty; jakýkoli
   zápis (`POST` / `PUT` / `PATCH` / `DELETE`) je odmítnut s `403`. Exporty i daňové
   výkazy jsou `GET`, proto k nim `readonly` má přístup. Jediná výjimka z pravidla
   „jen GET": **hromadný export** (Daně → Hromadný export) běží jako background job,
   takže jeho spuštění/zrušení/smazání jsou technicky `POST`/`DELETE` — věcně jde
   ale o čtení (sbalení existujících dokladů do ZIP), proto je povolen všem rolím.
   Admin endpointy (uživatelé, nastavení, integrace…) mají navíc **kontrolu role
   přímo v akci**.
2. **API token (PAT)** — role uživatele se kontroluje **před** scope tokenu, takže
   `readonly` uživatel nemůže obejít omezení ani tokenem se scopem `read_write`.
3. **UI** — frontend podle role **skrývá zápisová tlačítka** (Nový / Upravit /
   Smazat i akce jako odeslat, zaplaceno, párování banky). Zápisové stránky
   (`/…/new`, `/…/edit`) jsou navíc chráněné route-guardem — `readonly` je z nich
   přesměrován na nástěnku.

## 39.6 CSRF + Origin check

Každý mutating request (POST / PUT / PATCH / DELETE) musí mít:

1. **Origin header** se shodující s `app.url` v `cfg.php`
2. **X-CSRF-Token** header se shodující s tokenem v session

Bez nich → 403 `csrf_failed` / `origin_mismatch`. UI to obsluhuje
automaticky (token v Pinia store, header v axios interceptoru).

## 39.7 Activity log

Každá mutace (vytvoření / změna / vystavení / smazání) se loguje. Záznamy
obsahují:

- Akce (`invoice.created`, `invoice.issued`, `client.updated`, `auth.login_success`,
  `auth.login_failed`, `bank.statement_imported`, `currency.updated`, …)
- Uživatel (NULL pro neautentizované akce jako neúspěšné login)
- Entita (typ + ID)
- IP adresa (binární `VARBINARY(16)` — IPv4 i IPv6)
- User-Agent
- Payload — JSON s relevantními detaily (např. fields=`['email', 'name']`
  u `client.updated`)
- Datum + čas

Viz [36. Nastavení](36_Nastaveni.md) pro UI.

### 39.7.1 Co log NEUKLÁDÁ

- **Hesla** — ani staré, ani nové
- **PII klientů** mimo to, co bylo změněno (jen fields seznam, ne hodnoty)
- **Bankovní transakce** — log obsahuje jen ID importovaného výpisu

### 39.7.2 Jak se do logu zapisuje IP adresa

Aplikace bere IP klienta z **IP síťového spojení** (`REMOTE_ADDR`). Když běží
**za reverse proxy** (Docker, nginx, Cloudflare…), je tím spojením proxy — bez
konfigurace by se proto do auditu zapisovala **IP proxy**, ne reálného klienta
(typicky uvidíš pořád stejnou IP, např. bránu Dockeru `172.x.0.1`).

Reálnou IP přečte aplikace z hlavičky `X-Forwarded-For` **pouze tehdy**, když
`REMOTE_ADDR` odpovídá rozsahu v `cfg.ip_allowlist.trusted_proxies` (viz
§ 39.4.1). Z hlavičky se bere **první** adresa (původní klient). Bez nastavené
`trusted_proxies` se `X-Forwarded-For` ignoruje (ochrana proti podvržení).

> 🛈 Stejná logika se zjišťování IP používá i pro **brute-force lockout**
> (kap. 20.3). Za reverse proxy bez `trusted_proxies` proto lockout počítá
> pokusy podle IP proxy = fakticky globálně. Po nastavení `trusted_proxies`
> začnou audit log i lockout pracovat s reálnou klientskou IP.

## 39.8 DKIM podpis e-mailů

Pro **deliverabilitu** (aby gmail / o365 / seznam tvé maily nepoznačily jako
spam) doporučujeme aktivovat DKIM:

1. Vygeneruj RSA klíč: `openssl genrsa -out private/dkim/myinvoice.pem 2048`
2. Public key → DNS TXT záznam `myinvoice._domainkey.tvoje-domena.cz`
3. V `cfg.php → smtp.dkim.enabled => true`
4. Restart služby

Detaily v `README.md` v rootu repa.

## 39.9 Bezpečnostní audit

V `source/07-security-audit.md` najdeš výsledky interního auditu — všechny
identifikované findings (P1/P2/P3) jsou vyřešené nebo odůvodněně vynechané.

## 39.10 Tipy

- **Vždycky 2FA pro admin** — pokud admin účet padne, padá vše. Žádná výmluva.
- **Pravidelně rotuj hesla** každých 6–12 měsíců.
- **IP allowlist** v produkci pro non-veřejné použití (B2B accounting).
- **Activity log review** — alespoň 1× za měsíc projeďté podezřelé login
  selhání nebo neočekávané force-edit.
- **Backup `cfg.php` + `private/dkim/`** mimo repo — není v gitu, ztrátou
  přijdeš o pepper a nepřihlásíš se ke starým heslům.
