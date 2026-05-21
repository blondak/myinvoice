# 13. Banka — import výpisů a párování plateb

Místo ručního označování faktur jako zaplacených, naimportuj **GPC výpis**
z banky a systém automaticky spáruje platby s fakturami podle variabilního
symbolu a částky.

GPC (ABO) je standardní český formát pro elektronickou výměnu výpisů. Umí ho
exportovat: **KB**, **Fio Bank**, **ČSOB**, **Raiffeisenbank**, **Česká
spořitelna**, **mBank**, a další.

## 13.1 Stažení GPC výpisu z banky

Postup je v každé bance trochu jiný:

| Banka | Cesta v internet bankingu |
|---|---|
| **KB** | Účet → Historie pohybů → Export → formát „GPC ABO" |
| **Fio** | Přehled účtu → Stažení dat → formát „GPC" |
| **ČSOB** | Účet → Výpisy → Stáhnout → formát „ABO" |
| **Raiffeisen** | Detail účtu → Pohyby → Export → ABO formát |
| **ČS** | Detail účtu → Výpisy → formát „ABO" |

Stáhni soubor (typicky `.gpc` nebo `.abo`, někdy `.txt`). Velikost ~10–100 KB
na měsíc obvykle.

## 13.2 Upload výpisu do MyInvoice

V hlavním menu **Banka → Nahrát výpis**.

![Upload výpisu](img/11_banka_upload.webp)

Vyber soubor (drag & drop nebo klik). Po nahrání:

1. **Hash kontrola** (SHA-256) — pokud je stejný soubor už importovaný, hláška
   „Tento výpis už byl importovaný" + zrušení.
2. **Validace bankovního účtu** — server zkontroluje, že číslo účtu z hlavičky
   výpisu patří některé z měn aktuálního dodavatele.
3. **Parsing transakcí** — přečte všechny řádky.
4. **Auto-matching** — pro každou kreditní transakci s VS hledá fakturu se
   shodným varsymbolem **a** sumou v rozmezí ± 0,01 (tolerance haléře).
5. **Update faktur** — spárované faktury → status `paid`, `paid_at` =
   `transakce.datum_zaúčtování`.

Hláška o výsledku:

```
Importováno: 12 transakcí, spárováno: 8, k manuálnímu párování: 4.
```

## 13.3 Seznam výpisů

**Banka → Výpisy** ukáže historii.

| Sloupec | Význam |
|---|---|
| Datum | Datum výpisu |
| Číslo | Číslo výpisu z banky |
| Účet | Číslo účtu / IBAN |
| Měna | CZK / EUR / … |
| Příchozí | Suma kreditních transakcí |
| Odchozí | Suma debetních transakcí |
| Spárováno | `12/14` — 12 z 14 transakcí spárováno na faktury |
| Importováno | Datum + uživatel |

## 13.4 Detail výpisu

Klik na řádek → detail.

Tabulka transakcí:

| Sloupec | Význam |
|---|---|
| Datum | Datum zaúčtování |
| Částka | + (kredit) / − (debet) |
| Měna | |
| Protistrana | Název + číslo účtu (pokud bance zaslala) |
| VS | Variabilní symbol z transakce |
| KS / SS | Konstantní / specifický symbol |
| Popis | Poznámka z banky |
| Stav | `Spárováno` (zelená) / `Bez shody` (šedá) / `Ignorováno` (oranž.) |
| Faktura | Pokud spárováno, číslo faktury (klikatelné) |

### 13.4.1 Manuální párování

Pro transakce, které se nespárovaly automaticky (typicky chybí VS, nebo
částka nesedí kvůli částečné platbě, devizovému kurzu, bankovnímu poplatku):

1. Klik **Spárovat** → otevře se modal s vyhledávačem.
2. Najdeš fakturu (číslo / klient / částka).
3. Vyber a potvrď.

Faktura → status `paid`, `paid_at` = datum transakce. Activity log: `bank.matched_manual`.

### 13.4.2 Ignorovat transakci

Pro transakce, které nejsou platby faktur (poplatky, převody mezi vlastními
účty, refundace, …):

1. Klik **Ignorovat**.
2. Status → `Ignorováno`. Pro reporting se nepočítá.

## 13.5 Reverse: zrušení spárování

Pokud automatika spárovala chybně:

1. Detail výpisu → najdi transakci → klik **Zrušit párování**.
2. Faktura → status zpět na předchozí (`sent` / `issued`).
3. Activity log: `bank.unmatched`.

## 13.6 Cron — automatický scan

Místo ručního uploadu můžeš nastavit **cron**, který bude pravidelně skenovat
adresář (např. `private/bank-incoming/`) a importovat nové výpisy:

```bash
cmd/cron-bank-scan.sh        # každých 30 minut
```

Setup:

1. Banka pravidelně exportuje výpis e-mailem nebo SFTP do `private/bank-incoming/`
2. Cron každých 30 min spustí `php api/bin/cron-bank-scan.php`
3. Skript projde nové soubory, importuje, přesune do `private/bank-archive/`

## 13.7 Tipy

- **Nahraj výpis **denně/týdně** — čím čerstvější, tím dříve se ti vyfiltrují
  faktury po splatnosti správně.
- **Auto-match funguje jen s VS** — bez VS musíš párovat ručně. Apeluj na
  klienty, aby VS vyplňovali (typicky ho v bance nabízí, když napíšeš číslo
  faktury jako popis).
- **Tolerance ± 0,01** — částečné platby (klient pošle míň) se nespárují
  automaticky, musíš ručně. Zvaž nastavení tolerance v `cfg.php` →
  `bank.matching.tolerance`.
- **Devizový kurz** — pokud klient pošle EUR a faktura je v CZK, transakce
  nebude spárovaná (jiná měna). Manuálně.
- **Bankovní poplatek** — pokud banka strhla ze 100 EUR poplatek 1.5 EUR
  a klient zaplatil 100, dostáváš 98.5. Manuálně označíš jako částečně
  zaplacené nebo přijmeš tuto „ztrátu" jako bank fee.
