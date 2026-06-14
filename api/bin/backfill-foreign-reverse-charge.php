<?php

declare(strict_types=1);

/**
 * Oprava chybně naimportovaných ZAHRANIČNÍCH reverse-charge přijatých faktur.
 *
 * PROBLÉM
 * -------
 * Služby od zahraničních osob neusazených v tuzemsku (Anthropic, GitHub, Foxit,
 * Apple, Google …) jsou pro českého plátce předmětem DPH formou samovyměření
 * (§ 9 odst. 1 + § 24 + § 108 ZDPH): příjemce přizná daň na výstupu a SOUČASNĚ
 * uplatní nárok na odpočet (ř. 43). To, že dodavatel není plátce DPH a není
 * z EU, na povinnosti nic nemění — naopak je to důvod reverse charge.
 *
 * Import (AiPdfExtractor + PurchaseInvoiceRepository::defaultClassificationCode)
 * tyto doklady zařazoval špatně. Tři vzorce chyb:
 *   (1) mimo-EU + 0 % → kód 25 ("dovoz ZBOŽÍ ze 3. země", ř. 7) místo služby (ř. 12);
 *   (2) dodavatel-neplátce → vat_deduction='none' → celý doklad VYPADL z přiznání
 *       (chybí na ř. 12 i ř. 43 i v obratu), ač nárok na odpočet je;
 *   (3) EU služba s FIKTIVNÍ českou DPH (Apple/Google: brutto rozsekáno na základ +
 *       21 % „DPH") zařazená jako tuzemský doklad (ř. 40). Zahraniční dodavatel bez
 *       CZ registrace ale českou DPH naúčtovat nemůže → ta „DPH" je import artefakt.
 *
 * CO SKRIPT DĚLÁ
 * --------------
 * Najde přijaté faktury, které jsou JISTĚ zahraniční reverse charge:
 *   - dodavatel mimo CZ (countries.iso2 <> 'CZ'),
 *   - dodavatel NENÍ registrovaný k české DPH (dic prázdné nebo ne 'CZ…') — tím se
 *     vyloučí zahraniční firmy s CZ registrací, které účtují českou DPH (Amazon EU
 *     S.à r.l. s CZ DIČ apod. → ty patří na ř. 40, ne do RC),
 *   - není to zálohová výzva (document_kind <> 'advance') ani stornovaný.
 *
 * Pro každý takový doklad:
 *   - reverse_charge = 1, vat_deduction = 'full' (nárok na odpočet u RC náleží příjemci),
 *   - všem položkám správný klasifikační kód podle povahy plnění:
 *       služba (default) → EU 24e (ř. 5) / 3. země 24 (ř. 12)
 *       zboží (--goods)  → EU 23 (ř. 3) / 3. země 25 (ř. 7)
 *   - FIKTIVNÍ DPH: má-li doklad vyčíslenou DPH ≈ 21 % základu (česká sazba — tedy
 *     import artefakt, ne reálná zahraniční daň), sbalí se do základu
 *     (total_without_vat = total_with_vat, total_vat = 0). Samovyměřená daň se pak
 *     spočítá živě z plného základu. Doklad s NE-21% DPH (reálná cizí daň) se NEsahá
 *     a vypíše varování k ručnímu posouzení.
 *
 * Povaha plnění se z dat spolehlivě nepozná → DEFAULT je SLUŽBA (drtivá většina jsou
 * digitální předplatná). Dodavatele dodávající ZBOŽÍ vyjmenuj v --goods.
 *
 * DPH se NEpřepočítává „natvrdo": samovyměřená daň i odpočet se počítají živě ve
 * VatLedgerService z (základ × sazba). Skript mění kódy/příznaky/deduction a u
 * fiktivní DPH základ. Daňový dopad reverse charge je nulový (výstup +X / odpočet
 * −X) → vlastní daňová povinnost se nemění, mění se jen ZAŘAZENÍ na správné řádky a
 * to, že doklad do přiznání vůbec vstoupí.
 *
 * ⚠️ Už PODANÁ období: skript chytá i historické doklady. Než spustíš --apply na
 *    produkci, omez rozsah přes --from/--to tak, ať nepřepíšeš zařazení v obdobích,
 *    na která je už podané přiznání (po dohodě s účetní, příp. dodatečné přiznání).
 *    Idempotentní — opakované spuštění už nic nezmění.
 *
 * Použití:
 *   php api/bin/backfill-foreign-reverse-charge.php                          # dry-run, vše
 *   php api/bin/backfill-foreign-reverse-charge.php --from=2026-04-01        # jen od data DUZP
 *   php api/bin/backfill-foreign-reverse-charge.php --from=2026-04-01 --apply
 *   php api/bin/backfill-foreign-reverse-charge.php --supplier=1             # jen jeden tenant
 *   php api/bin/backfill-foreign-reverse-charge.php --goods=123,456          # tito dodavatelé = zboží
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun     = !in_array('--apply', $argv, true);
$supplierId = null;
$from       = null;
$to         = null;
$goodsIds   = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--supplier=')) $supplierId = (int) substr($a, 11);
    if (str_starts_with($a, '--from='))     $from = substr($a, 7);
    if (str_starts_with($a, '--to='))       $to   = substr($a, 5);
    if (str_starts_with($a, '--goods='))    $goodsIds = array_filter(array_map('intval', explode(',', substr($a, 8))));
}

$app = \MyInvoice\Bootstrap::buildApp();
$pdo = $app->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

// Doklady = jistá zahraniční RC: mimo CZ, bez CZ DPH registrace, ne záloha/storno.
$params = [];
$sql = "
    SELECT pi.id, pi.supplier_id, pi.varsymbol, pi.vendor_id, pi.reverse_charge, pi.vat_deduction,
           COALESCE(pi.tax_date, pi.issue_date) AS taxd,
           pi.total_without_vat, pi.total_vat, pi.total_with_vat,
           c.company_name AS vendor, COALESCE(co.is_eu,0) AS is_eu, COALESCE(co.iso2,'') AS iso2
      FROM purchase_invoices pi
      JOIN clients c     ON c.id  = pi.vendor_id
 LEFT JOIN countries co  ON co.id = c.country_id
     WHERE COALESCE(co.iso2,'CZ') <> 'CZ'
       AND (c.dic IS NULL OR c.dic = '' OR c.dic NOT LIKE 'CZ%')
       AND COALESCE(pi.document_kind,'') <> 'advance'
       AND pi.status <> 'cancelled'
";
if ($supplierId !== null) { $sql .= " AND pi.supplier_id = ?"; $params[] = $supplierId; }
if ($from !== null)       { $sql .= " AND COALESCE(pi.tax_date, pi.issue_date) >= ?"; $params[] = $from; }
if ($to !== null)         { $sql .= " AND COALESCE(pi.tax_date, pi.issue_date) <= ?"; $params[] = $to; }
$sql .= " ORDER BY pi.supplier_id, taxd, pi.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (!$docs) {
    echo "Žádné odpovídající zahraniční reverse-charge doklady.\n";
    exit(0);
}

$itemStmt = $pdo->prepare("SELECT id, vat_classification_code, total_without_vat, total_vat, total_with_vat
                             FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
$updItemCode  = $pdo->prepare("UPDATE purchase_invoice_items SET vat_classification_code = ? WHERE id = ?");
$updItemMoney = $pdo->prepare("UPDATE purchase_invoice_items SET vat_classification_code = ?, total_without_vat = ?, total_vat = 0 WHERE id = ?");
$updDoc       = $pdo->prepare("UPDATE purchase_invoices SET reverse_charge = 1, vat_deduction = 'full', vat_classification_code = ? WHERE id = ?");
$updDocMoney  = $pdo->prepare("UPDATE purchase_invoices SET reverse_charge = 1, vat_deduction = 'full', vat_classification_code = ?, total_without_vat = total_with_vat, total_vat = 0 WHERE id = ?");

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Zahraniční reverse-charge doklady: " . count($docs) . "\n";
echo str_repeat('-', 118) . "\n";

$changed = 0; $itemChanges = 0; $collapsed = 0; $warned = 0;
foreach ($docs as $d) {
    $isGoods = in_array((int) $d['vendor_id'], $goodsIds, true);
    // Cílový kód: služba → EU 24e / 3. země 24; zboží → EU 23 / 3. země 25.
    $target = $isGoods
        ? ((int) $d['is_eu'] === 1 ? '23' : '25')
        : ((int) $d['is_eu'] === 1 ? '24e' : '24');

    // Fiktivní DPH? (≈ 21 % základu = import artefakt; jiná sazba = reálná cizí daň → ruka)
    $base = (float) $d['total_without_vat'];
    $vat  = (float) $d['total_vat'];
    $doCollapse = false;
    if (abs($vat) >= 0.005) {
        $ratio = $base != 0.0 ? $vat / $base : 0.0;
        if (abs($ratio - 0.21) < 0.01) {
            $doCollapse = true;
        } else {
            printf("  ⚠ t%-2d pi#%-5d %-11s %-16s DPH %.2f (%.1f%%) NENÍ 21%% — reálná cizí daň? PŘESKOČENO, posuď ručně.\n",
                $d['supplier_id'], $d['id'], (string) $d['taxd'], substr((string) $d['vendor'], 0, 16), $vat, $ratio * 100);
            $warned++;
            continue;
        }
    }

    $itemStmt->execute([$d['id']]);
    $items = $itemStmt->fetchAll(\PDO::FETCH_ASSOC);

    $itemFixes = []; // [id, oldCode, newBase|null]
    foreach ($items as $it) {
        $codeFix = (string) $it['vat_classification_code'] !== $target;
        $newBase = $doCollapse ? (float) $it['total_with_vat'] : null;
        $moneyFix = $doCollapse && (
            abs((float) $it['total_without_vat'] - (float) $it['total_with_vat']) >= 0.005
            || abs((float) $it['total_vat']) >= 0.005
        );
        if ($codeFix || $moneyFix) {
            $itemFixes[] = [(int) $it['id'], (string) $it['vat_classification_code'], $moneyFix ? $newBase : null];
        }
    }
    $dedFix = $d['vat_deduction'] !== 'full';
    $rcFix  = (int) $d['reverse_charge'] !== 1;
    if (!$itemFixes && !$dedFix && !$rcFix && !$doCollapse) {
        continue; // idempotent
    }

    $flags = [];
    if ($rcFix)      $flags[] = 'rc→1';
    if ($dedFix)     $flags[] = $d['vat_deduction'] . '→full';
    if ($doCollapse) $flags[] = sprintf('DPH %.2f→základ %.2f', $vat, (float) $d['total_with_vat']);
    $arrows = array_map(fn ($f) => ($f[1] === '' ? '∅' : $f[1]) . "→{$target}", $itemFixes);

    printf("  t%-2d pi#%-5d %-11s %-16s %s [%s] %s | %s\n",
        $d['supplier_id'], $d['id'], (string) $d['taxd'], substr((string) $d['vendor'], 0, 16),
        $d['iso2'], $isGoods ? 'ZBOŽÍ' : 'služba',
        $flags ? '(' . implode(', ', $flags) . ')' : '', $arrows ? implode(',', $arrows) : 'kódy OK');

    if (!$dryRun) {
        $pdo->beginTransaction();
        try {
            foreach ($itemFixes as $f) {
                if ($f[2] !== null) $updItemMoney->execute([$target, $f[2], $f[0]]);
                else                $updItemCode->execute([$target, $f[0]]);
            }
            if ($doCollapse) $updDocMoney->execute([$target, $d['id']]);
            else             $updDoc->execute([$target, $d['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    $changed++;
    $itemChanges += count($itemFixes);
    if ($doCollapse) $collapsed++;
}

echo str_repeat('-', 118) . "\n";
echo "Dokladů k opravě: {$changed}  (položek: {$itemChanges}, z toho sbalená fiktivní DPH: {$collapsed} dokladů)\n";
if ($warned)   echo "Přeskočeno (cizí daň ≠ 21 %): {$warned} — posuď ručně.\n";
if ($goodsIds) echo "Bráno jako ZBOŽÍ: dodavatelé #" . implode(',', $goodsIds) . "\n";
if ($dryRun) {
    echo "\nDRY-RUN — nic nezapsáno. Pro zápis přidej --apply (zvaž --from kvůli už podaným obdobím).\n";
} else {
    echo "\nHotovo. DPH výkazy se počítají živě — žádný recompute není potřeba.\n";
}
