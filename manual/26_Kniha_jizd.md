# Kniha jízd

**Kniha jízd** je evidence jízd, vozidel a tankování. Slouží jako *kniha důkazů*
pro uplatnění nákladů na pohonné hmoty v daních (§ 24 zákona o daních z příjmů,
pokyn GFŘ D-22) — bez ní finanční úřad odpočet PHM neuzná. Najdeš ji v menu
v sekci **Dokumenty**, hned pod [Dokumenty](25_Dokumenty.md).

Modul má čtyři záložky: **Kniha jízd** (jednotlivé jízdy), **Automobily**
(číselník vozidel), **Tankování** a **Kategorie cest**. Vše je odděleně
**per dodavatel** (firma/IČO).

Co kniha jízd ze zákona musí evidovat:

- **u vozidla** — typ, SPZ (registrační značku) a stav tachometru k zahájení
  (typicky k 1. 1.) i ke konci roku,
- **u každé jízdy** — datum, čas odjezdu/příjezdu, odkud → kam, **konkrétní účel**
  cesty (samotné slovo „práce" nestačí) a ujeté kilometry / stav tachometru.

## Automobily

V záložce **Automobily** vedeš číselník vozidel. U auta zadáš **SPZ** (povinné),
volitelně značku, model, VIN, druh paliva a **počáteční stav tachometru** (k datu
pořízení / k 1. 1.). Příznak **Výchozí auto** určuje, na které vozidlo se nové
záznamy a tankování navážou automaticky, když máš aut víc.

Auto, které má navázané jízdy nebo tankování, nelze smazat (jen archivovat při
úpravě) — historie zůstane zachována.

## Kniha jízd — jízdy

Záložka **Kniha jízd** je seznam jednotlivých jízd, seskupený **po měsících**
s měsíčním součtem ujetých km. Nahoře filtruješ podle auta, roku a měsíce
(výchozí = vše); dlouhý seznam se stránkuje.

### Nový záznam

Tlačítko **Nový záznam** otevře formulář jízdy:

- **Tachometr zahájení** se předvyplní **posledním známým konečným stavem** auta,
  takže na sebe jízdy plynule navazují.
- Zadáš-li **Ujeto (km)** a necháš prázdný **Tachometr konec**, konec se
  **dopočítá** ze začátku. A naopak — vyplníš-li oba tachometry, ujeté km se
  spočítají jako rozdíl.
- Pole **Účel cesty** **našeptává** dříve zadané účely — stačí začít psát.
- **Kategorie cesty** (služební / soukromá) viz níže.

### Import z CSV / XLSX

Tlačítko **Import** nahraje knihu jízd z **CSV nebo XLSX**. Hlavička určuje
sloupce (pořadí je libovolné), podporované názvy:

```
datum, cas, auto, km_zacatek, km_konec, ujeto, ucel, odkud, kam, kategorie
```

- **datum** je povinné (`dd.mm.rrrr` i ISO; u XLSX se datum čte z buňky, takže
  funguje bez ohledu na formát zobrazení),
- **auto** je SPZ nebo název; je-li prázdné a máš jen jedno auto, použije se ono,
- **ujeto** se dopočítá z rozdílu tachometrů, když ho nevyplníš,
- **kategorie**, která ještě neexistuje, se **automaticky založí**.

Tlačítkem **Stáhnout vzor** získáš prázdnou šablonu CSV. Po importu se zobrazí
přehled (kolik jízd vzniklo, kolik řádků selhalo a proč) i seznam nově
založených kategorií.

### Export

Tlačítkem **Export** vyexportuješ jízdy za **zvolené období** (datum od/do) a
auto do **XLSX** nebo **PDF**. Výstup je seskupený po vozidlech, s mezisoučty
a celkovým počtem km — vhodné jako příloha k daňové evidenci.

## Tankování

Tankování můžeš vést **ručně**, nebo je nechat **vytěžit z přijatých faktur**
od čerpacích stanic. Seznam je opět po měsících s měsíčním součtem částek,
s filtrem a stránkováním; export do XLSX/PDF funguje stejně jako u jízd.

Tankování je čistě **evidenční vrstva** nad přijatou fakturou — náklad účtuje
sama [přijatá faktura](17_Prijate_faktury.md), tankování ho jen rozpadá na
jednotlivá čerpání a auta. Do DPH ani [nákladů](22_Naklady.md) nevstupuje dvakrát.

### Načíst z fakturu od benzínek

1. V detailu dodavatele zaškrtni **Benzínka** (sekce dodavatele). Tím se jeho
   faktury začnou nabízet ke zpracování.
2. V záložce Tankování klikni na **Načíst z faktur**. Zobrazí se faktury od
   benzínek; každá má odznak **Nová** / **Zpracováno** a tlačítko **Detail**,
   které rozbalí položky faktury (pohonné hmoty jsou zvýrazněné).
3. Vyber auto a klikni **Rozpoznat** — z faktury se vytvoří záznamy tankování
   navázané na zvolené vozidlo a na původní doklad (číslo dokladu je v seznamu
   tankování proklikem na fakturu).

Tlačítko **Vytěžit historii** projede zpětně **jen dosud nezpracované** faktury
od benzínek a hromadně z nich vytvoří tankování. Každá faktura se zpracuje jen
jednou, opakované spuštění nic nezdvojí.

### Detailní výpisy (Axigon a další)

U dokladů s detailním rozpisem (např. **Axigon**) se aplikace pokusí dohledat
**jednotlivá tankování** včetně data, času, druhu paliva a ceny. Děje se to
**interně** přímo z PDF; když to u staršího/zhuštěného formátu nevyjde a máš
zapnutou [AI extrakci](19_AI_extrakce.md), použije se jako záloha AI (přes tvůj
vlastní API klíč — viz upozornění v okně). Když ani to není možné, uloží se jeden
souhrnný záznam s datem vystavení, popisem a částkou z faktury.

Architektura parserů je rozšiřitelná — další karetní společnost s jiným formátem
výpisu lze doplnit bez zásahu do zbytku.

## Kategorie cest

Záložka **Kategorie cest** je číselník pro rozlišení účelu jízdy. Výchozí jsou
**Služební** a **Soukromá**; příznak *soukromá* označuje daňově neuznatelné jízdy.
Kategorie můžeš přidávat, upravovat a archivovat; kategorii s navázanými jízdami
nelze smazat. Nové kategorie vznikají i automaticky při importu (viz výše).

## Tipy

- **Tachometr na přelomu roku** — stav k 31. 12. / 1. 1. doložíš poslední jízdou
  v prosinci a první v lednu; díky předvyplnění tachometru na sebe navazují samy.
- **Účel piš konkrétně** — např. „Jednání s klientem, Praha", ne jen „práce";
  finanční úřad může vyžadovat bližší popis.
- **Soukromé jízdy** veď taky — při krácení odpočtu (např. paušál na PHM)
  je užitečné mít poměr služebních a soukromých km.
