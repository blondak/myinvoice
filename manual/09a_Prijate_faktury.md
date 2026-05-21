# 9a. Přijaté faktury (nákupy)

> Přidáno v3.5.0 jako součást fáze 1 integrace forku myinvoiceDph (Martin Říha).

**Přijaté faktury** jsou doklady, které **dostáváš od svých dodavatelů** — peníze
odcházejí z firmy. Oproti vystaveným fakturám:

| | Vystavené (faktura) | Přijaté (purchase invoice) |
|---|---|---|
| Směr peněz | Klient → my (příjem) | My → dodavatel (výdaj) |
| Protistrana | Zákazník (`is_customer=1`) | Dodavatel (`is_vendor=1`) — stejná tabulka klientů, jiný flag |
| DPH role | Sbíráme od klientů (výstupní DPH) | Odečítáme z dodavatelských (vstupní DPH) |
| Číslování | Naše `2605001` | Číslo dodavatele (na originálu) + naše interní `PF-202605-NNNN` |
| Status flow | draft → issued → sent → paid | draft → received → booked → paid |
| Schvalování / odesílání | Ano, klient potvrdí | Ne, doklad jen evidujeme |

V hlavním menu **Přijaté faktury**.

## 9a.1 Stavy přijaté faktury

| Stav | Význam | Co lze |
|---|---|---|
| **Koncept** (draft) | Rozpracovaný — ještě jsi nepotvrdil že to je platná faktura | Upravit, smazat, přejít na Přijatá |
| **Přijatá** (received) | Doklad potvrzený jako platný — visí na nezaplacených | Označit jako zaúčtovaná, uhrazená, stornovat |
| **Zaúčtovaná** (booked) | Předala se účetní / poslala do účetnictví | Označit jako uhrazená, stornovat |
| **Uhrazená** (paid) | Zaplaceno (manuálně nebo automaticky z bankovního výpisu) | — (terminal) |
| **Stornovaná** (cancelled) | Stornovaný doklad — necháváme pro audit | — (terminal) |

Smazat jde **jen koncept**. Pro pozdější stavy použij Stornovat (zachová auditní stopu).

## 9a.2 Nová přijatá faktura

V seznamu klikni **+ Nová přijatá faktura**. Otevře se formulář.

### 9a.2.1 Drag & drop PDF
Nad formulářem je **drag & drop zóna**. Pokud máš PDF od dodavatele:

- Přetáhni PDF do zóny (nebo klikni a vyber soubor).
- Systém prohledá PDF zda obsahuje **embedded ISDOC** přílohu:
  - **Pokud ano** (fakturační software jako Money S3, Pohoda, Stormware, sám MyInvoice) → pole formuláře se předvyplní strukturovanými daty.
  - **Pokud ne** (běžné PDF bez přílohy) → ve fázi 1 musíš vyplnit ručně. Ve fázi 2c (plánováno) doplníme AI extrakci přes Anthropic Claude — viz `source/09-fork-integration-plan.md`.
- Originál PDF se po prvním uložení faktury automaticky **archivuje** mimo webroot a v detailu si ho můžeš kdykoli stáhnout zpět.

Limity:
- Max 20 MiB per soubor
- Akceptujeme pouze application/pdf (magic bytes `%PDF-` se ověřují server-side)
- SHA-256 deduplikace — stejný PDF už archivovaný u jiné faktury nebude akceptován

### 9a.2.2 Povinná pole

| Pole | Význam |
|---|---|
| **Dodavatel** | Vyber z dropdownu (autocomplete). Pokud chybí, klikni „+ Vytvořit nového dodavatele" — využije ARES lookup podle IČO. |
| **Číslo dokladu dodavatele** | Tak jak je vytištěno na originálu (např. `FA-2026-001`). Max 50 znaků. Unique per (dodavatel, datum vystavení) — nelze importovat 2× stejnou. |
| **Naše interní číslo** | Volitelné. Pokud necháš prázdné, vygeneruje se automaticky `PF-YYYYMM-NNNN` při přechodu na stav Přijatá. |
| **Typ dokladu** | Faktura / Doklad o úhradě / Dobropis / Záloha (pro filtrování v seznamu). |
| **Datum vystavení** | Z faktury. |
| **DUZP (datum uskutečnění zdanitelného plnění)** | Klíčové pro DPH období. Default = datum vystavení. |
| **Splatnost** | Z platebních podmínek dodavatele. |
| **Datum přijetí** | Kdy jsi to fyzicky / e-mailem dostal. Default = dnes. |
| **Měna faktury** | Měna, ve které je doklad vystaven (USD, EUR, CZK…). |
| **Kurz k DUZP** | Pokud je měna ≠ CZK, **musíš zafixovat kurz**. Tlačítko „Načíst z ČNB" stáhne aktuální nebo poslední dostupný denní kurz. |
| **Reverse charge** | Zaškrtni, pokud je doklad B2B s přenesenou daňovou povinností (B2B EU services). DPH na řádcích bude 0, ty si daň zdaníš sám ve výkazu DPH. |

### 9a.2.3 Položky

Tlačítkem **+ Přidat položku** přidej řádek. Per řádek:

- Popis
- Množství (např. 1)
- Měrná jednotka (ks / hod / kus…)
- Cena za MJ bez DPH
- Sazba DPH (z číselníku — 21 % / 12 % / 0 %)
- (volitelně) MFČR DPH klasifikační kód — pro budoucí výkazy DPH (fáze 6 plánu)

Souhrn dole se přepočítá automaticky po každé změně.

### 9a.2.4 Platba v jiné měně (multi-currency)

Klikni na **„Platba v jiné měně než měna faktury"** pokud máš tento scénář:

> Faktura je v USD ($1000), ale platíš ji z CZK účtu (banka konvertuje na ~24 500 Kč
> s 1–2% spread / poplatkem).

V tomto bloku zadáš:

- Měna platebního účtu (např. CZK)
- Kurz platba → měna faktury (např. 0.0408 USD/CZK, nebo opačně dle UI)
- Kolik reálně odešlo z účtu (24 500 CZK)

Systém automaticky vypočte:

- **Ekvivalent v měně faktury** — pro spárování proti `amount_to_pay`
- **Kurzový rozdíl** — v základní měně (CZK). Záporný = kurzová ztráta, kladný = zisk. Zatím se zaznamenává pro reporting; účetně se v fázi 6 (DPH výkazy) automaticky promítne do správných řádků.

## 9a.3 Detail přijaté faktury

Po uložení / přechodu na detail:

- Vidíš dodavatele (s IČO/DIČ), datumy, položky, DPH rozpis, totály, K úhradě.
- Sekce **Originální PDF od dodavatele** — pokud jsi nahrál, můžeš stáhnout zpět.
- Tlačítka pro **přechod stavu** podle state-machine:
  - Z draft: Označit jako přijaté / Stornovat
  - Z received: Označit jako zaúčtované / uhrazené / Stornovat
  - Z booked: Označit jako uhrazené / Stornovat
- Tlačítko **Upravit** je dostupné jen u draft. Po označení jako přijatá je doklad immutable (kromě admin override `?force=1` u received).
- Tlačítko **Smazat** je dostupné jen u draft. Pro pozdější stavy použij Stornovat.

## 9a.4 Scan inbox — automatický import z adresáře

Pokud máš dodavatele kteří ti **posílají PDF e-mailem** nebo máš složku
sdílených dokladů, nakonfiguruj **inbox adresář** v `cfg.php`:

```php
'purchase_invoice' => [
    'inbox_dir'         => 'C:/inetpub/wwwroot/myinvoice.cz/inbox',
    'inbox_recursive'   => true,
    'allowed_exts'      => ['pdf', 'isdoc', 'xml'],
    'archive_storage'   => __DIR__ . '/storage/purchase-invoices',
],
```

V seznamu Přijaté faktury klikni **📥 Nascanovat inbox**:

- Systém rekurzivně projde nakonfigurovaný adresář.
- Pro každý soubor spočte SHA-256 — pokud už existuje faktura se stejným otiskem, soubor přeskočí.
- Z PDF s embedded ISDOC rozpozná data dodavatele a obsah.
- Plain PDF (bez ISDOC) jsou ve fázi 1 přeskakovány (s důvodem „AI extrakce dorazí v fázi 2c").

Modal po skončení zobrazí přehled: vytvořeno / přeskočeno / chyby + per-soubor detail.

**Bezpečnost:** soubory mimo configured `inbox_dir` jsou odmítnuty (path traversal guard
přes `realpath()`). Maximum 500 souborů per běh (DoS protection na velké adresáře).

## 9a.5 Klienti vs. dodavatelé

V tabulce klientů jsme zavedli dva flagy:

- `is_customer` — klient, kterému fakturuješ (default `1` pro všechny existující záznamy)
- `is_vendor` — dodavatel, od kterého přijímáš faktury

Některé firmy jsou **současně zákazník i dodavatel** (např. partnerská IT firma, kterou
fakturuješ za development a od níž kupuješ hosting) — jedna entita = jedna řádka,
**oba flagy = 1**. ARES synchronizace, kontakty, historie jsou sdílené.

V hlavním menu **Klienti** vidíš defaultně jen `is_customer=1`. V budoucí verzi
přidáme oddělený view **Dodavatelé** pro `is_vendor=1`.

## 9a.6 Audit log

Akce s přijatými fakturami jsou logované v aktivním logu (Systém → Log):

- `purchase_invoice.created`
- `purchase_invoice.updated` / `force_updated`
- `purchase_invoice.items_updated`
- `purchase_invoice.exchange_rate_set`
- `purchase_invoice.transitioned` (s payloadem `{from, to}`)
- `purchase_invoice.deleted`
- `purchase_invoice.pdf_uploaded` / `pdf_downloaded`
- `purchase_invoice.inbox_scanned`

## 9a.7 REST API

Všechny operace jsou dostupné i přes REST API (`/api/v1/purchase-invoices/*`) —
viz [Swagger UI](/api/docs) nebo [Redoc](/api/reference). PAT token musí mít scope
`read_write` pro mutace.

## 9a.8 Co se chystá v dalších fázích

- **Fáze 2 (v3.6.0)**: import přijatých faktur z iDokladu / Fakturoidu API + AI extrakce z PDF (Anthropic Claude).
- **Fáze 3 (v3.7.0)**: párování plateb z bankovního výpisu vůči přijatým fakturám (automatický přechod na paid).
- **Fáze 6 (v4.0.0)**: výkazy DPH, KH a daň z příjmů — využívá `vat_classification_code` na položkách.

Detail viz `source/09-fork-integration-plan.md`.
