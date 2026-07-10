<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use MyInvoice\Service\Invoice\InvoiceMath;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Věcná správnost zařazení dokladů do sekcí KH (DPHKH1) a řádků DPH přiznání
 * (DPHDP3) — pokrývá všechny daňové případy, které mohou nastat, a chrání proti
 * regresi oprav z issue #35 + navazujícího review:
 *
 *   A.1 RC dodavatel · A.2 pořízení z JČS · A.4/A.5 tuzemská vystavená · B.1 RC
 *   příjemce · B.2/B.3 tuzemská přijatá · dobropis se záporným základem · doklad
 *   bez DUZP · doklad bez DIČ nad limit · dodání/vývoz do EU (oddíl C ř.20-26) ·
 *   samovyměření DPH (ř.3/10 + mirror ř.43) · pořízení majetku (ř.47).
 *
 * Vytvoří vlastní klienty + faktury + přijaté faktury v izolovaném období
 * (rok 2099, měsíc 6) pod existujícím supplierem, ověří XML, vše uklidí v tearDown.
 *
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class KhDphTaxScenariosTest extends TestCase
{
    private const YEAR = 2099;
    private const MONTH = 6;

    private Connection $db;
    private KontrolniHlaseniBuilder $kh;
    private DphPriznaniBuilder $dph;
    private DphBookBuilder $book;
    private SouhrnneHlaseniBuilder $shv;
    private PurchaseInvoiceRepository $piRepo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;
    private int $skId = 0;

    /** @var array{customers:int[], vendors:int[]} */
    private array $clientIds = ['customers' => [], 'vendors' => []];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $purchaseIds = [];
    /** Původní plátcovství supplier-a — test vynucuje plátce (viz setUp). */
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->kh   = $container->get(KontrolniHlaseniBuilder::class);
            $this->dph  = $container->get(DphPriznaniBuilder::class);
            $this->book = $container->get(DphBookBuilder::class);
            $this->shv  = $container->get(SouhrnneHlaseniBuilder::class);
            $this->piRepo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = $this->countryId('CZ');
        $this->deId = $this->countryId('DE');
        $this->skId = $this->countryId('SK');

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        // Scénáře předpokládají PLÁTCE DPH (DPHDP3 typ P s plnými řádky + odpočty).
        // Identifikovaná osoba (issue #94) builder přepíná do režimu typ I s filtrem
        // řádků — reálné nastavení dodavatele v dev DB by testy rozbilo. Vynutit
        // plátce a v tearDown vrátit (IO režim kryje IdentifiedPersonDphTest).
        $flags = $pdo->query(
            "SELECT is_vat_payer, is_identified FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(\PDO::FETCH_ASSOC) ?: [];
        $this->origVatFlags = $flags;
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')
                ->execute([
                    (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                    (int) ($this->origVatFlags['is_identified'] ?? 0),
                    $this->supplierId,
                ]);
        }
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach (array_merge($this->clientIds['customers'], $this->clientIds['vendors']) as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close(); // uvolni MySQL connection (kumulace přes běh → max_connections)
    }

    public function testAllTaxScenariosClassifyCorrectly(): void
    {
        // ── Protistrany ──────────────────────────────────────────────────────
        $custDic   = $this->client('Odběratel s DIČ',  $this->czId, 'CZ11111118', customer: true);
        $custNoDic = $this->client('Odběratel bez DIČ', $this->czId, null,        customer: true);
        $euCust    = $this->client('EU odběratel',      $this->skId, 'SK1234567',  customer: true);
        $vendDic   = $this->client('Dodavatel s DIČ',   $this->czId, 'CZ22222220', vendor: true);
        $vendNoDic = $this->client('Dodavatel bez DIČ', $this->czId, null,         vendor: true);
        $euVend    = $this->client('EU dodavatel',      $this->deId, 'DE123456789', vendor: true);

        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);

        // ── VYSTAVENÉ (sales) ────────────────────────────────────────────────
        // S1 A.4: tuzemská 21 % nad limit, odběratel s DIČ
        $this->sale('2099060001', $custDic, '1', false, $d(10), $d(10), [[20000, 4200, 21]]);
        // S2 A.5: tuzemská 21 % do limitu
        $this->sale('2099060002', $custDic, '1', false, $d(11), $d(11), [[5000, 1050, 21]]);
        // S3 A.5: tuzemská 21 % nad limit, ale BEZ DIČ → sumace (ne zahodit) — issue #35 #4
        $this->sale('2099060003', $custNoDic, '1', false, $d(12), $d(12), [[30000, 6300, 21]]);
        // S4 oddíl C: vývoz (kód 26 → ř.22 pln_vyvoz) — issue #35 #2
        $this->sale('2099060004', $euCust, '26', false, $d(13), $d(13), [[50000, 0, 0]]);
        // S5 A.1: reverse charge dodavatel (samovyměří odběratel). RC model: položka drží
        // NOMINÁLNÍ sazbu 21 %, daň = 0 (příznak reverse_charge ji vynuluje). Musí spadnout
        // do A.1 / ř.25 (PDP uskutečněná), NE do ř.1 výstupu — i přes snapshot 21.
        $this->sale('2099060005', $custDic, null, true, $d(14), $d(14), [[15000, 0, 21]]);

        // ── PŘIJATÉ (purchases) ──────────────────────────────────────────────
        // P1 B.2: tuzemská 21 % nad limit, dodavatel s DIČ
        $this->purchase('P-2099-001', $vendDic, '40', false, 'invoice', $d(12), $d(12), [[10000, 2100, 21]]);
        // P2 B.3: tuzemská 21 % do limitu
        $this->purchase('P-2099-002', $vendDic, '40', false, 'invoice', $d(12), $d(12), [[2000, 420, 21]]);
        // P3 B.3: nad limit ale BEZ DIČ → sumace B.3 — issue #35 #4
        $this->purchase('P-2099-003', $vendNoDic, '40', false, 'invoice', $d(13), $d(13), [[15000, 3150, 21]]);
        // P4 A.2: pořízení zboží z JČS (kód 23, RC) — jen A.2, NE B.2 — issue #35 #1
        $this->purchase('P-2099-004', $euVend, '23', true, 'invoice', $d(14), $d(14), [[8000, 0, 21]]);
        // P5 B.1: tuzemský RC příjemce (kód 5) — flag reverse_charge=0 testuje migraci is_reverse_charge — review #3
        $this->purchase('P-2099-005', $vendDic, '5', false, 'invoice', $d(15), $d(15), [[9000, 0, 21]]);
        // P6 B.2: bez DUZP (tax_date NULL), issue_date v období — COALESCE fix
        $this->purchase('P-2099-006', $vendDic, '40', false, 'invoice', $d(15), null, [[11000, 2310, 21]]);
        // P7 B.2: dobropis se záporným základem nad limit — issue #35 #2
        $this->purchase('P-2099-007', $vendDic, '40', false, 'credit_note', $d(20), $d(20), [[-25000, -5250, 21]]);
        // P8 B.2 + ř.47: pořízení dlouhodobého majetku
        $this->purchase('P-2099-008', $vendDic, '40', false, 'invoice', $d(22), $d(22), [[40000, 8400, 21]], isFixedAsset: true);

        // ══ KONTROLNÍ HLÁŠENÍ ════════════════════════════════════════════════
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $root = $kh->DPHKH1;

        // A.4 — jen S1 (nad limit + DIČ)
        $this->assertCount(1, $root->VetaA4, 'A.4: očekáván právě 1 doklad (S1)');
        $this->assertSame('20000.00', (string) $root->VetaA4[0]['zakl_dane1']);
        $this->assertSame('11111118', (string) $root->VetaA4[0]['dic_odb']);

        // A.5 — sumace S2 + S3 (S3 je nad limit, ale bez DIČ → sem, ne zahodit)
        $this->assertSame('35000.00', (string) $root->VetaA5['zakl_dane1'], 'A.5: 5000 (S2) + 30000 (S3 bez DIČ)');
        $this->assertSame('7350.00',  (string) $root->VetaA5['dan1']);

        // A.1 — RC dodavatel (S5)
        $this->assertCount(1, $root->VetaA1, 'A.1: RC vystavené (S5)');
        $this->assertSame('15000.00', (string) $root->VetaA1[0]['zakl_dane1']);

        // A.2 — pořízení z JČS (P4), samovyměřená daň 21 %
        $this->assertCount(1, $root->VetaA2, 'A.2: pořízení zboží z JČS (P4)');
        $this->assertSame('8000.00', (string) $root->VetaA2[0]['zakl_dane1']);
        $this->assertSame('1680.00', (string) $root->VetaA2[0]['dan1'], 'A.2: samovyměřená daň 8000×21 %');

        // B.1 — tuzemský RC příjemce (P5) — díky migraci is_reverse_charge=1 i bez flagu
        $this->assertCount(1, $root->VetaB1, 'B.1: tuzemský RC příjemce (P5)');
        $this->assertSame('9000.00', (string) $root->VetaB1[0]['zakl_dane1']);

        // B.2 — P1, P6 (bez DUZP), P7 (dobropis −), P8 (majetek). NE P4 (A.2) ani P5 (B.1)!
        $b2bases = [];
        foreach ($root->VetaB2 as $v) $b2bases[] = (string) $v['zakl_dane1'];
        sort($b2bases);
        $this->assertSame(['-25000.00', '10000.00', '11000.00', '40000.00'], $b2bases,
            'B.2: P1+P6+P7+P8; A.2 (P4) a B.1 (P5) se NESMÍ duplikovat do B.2');

        // B.3 — sumace P2 + P3 (P3 nad limit bez DIČ)
        $this->assertSame('17000.00', (string) $root->VetaB3['zakl_dane1'], 'B.3: 2000 (P2) + 15000 (P3 bez DIČ)');
        $this->assertSame('3570.00',  (string) $root->VetaB3['dan1']);

        // ══ DPH PŘIZNÁNÍ ═════════════════════════════════════════════════════
        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $dp = $dphXml->DPHDP3;
        $v1 = $dp->Veta1;
        $v2 = $dp->Veta2;
        $v4 = $dp->Veta4;

        // ř.1 výstup 21 % = S1+S2+S3 (RC sale a vývoz sem nepatří)
        $this->assertSame('55000', (string) $v1['obrat23'], 'ř.1 základ = 20000+5000+30000');
        $this->assertSame('11550', (string) $v1['dan23'],   'ř.1 daň = 4200+1050+6300');

        // Oddíl C / Veta2 ř.22 vývoz (S4) — dříve se negeneroval vůbec (review #2)
        $this->assertNotNull($v2, 'Veta2 (oddíl C) musí existovat');
        $this->assertSame('50000', (string) $v2['pln_vyvoz'], 'ř.22 vývoz = 50000 (S4)');

        // ř.25 tuzemský PDP dodavatel §92 (S5 — tuzemský RC odběratel). Country-aware klasifikace:
        // tuzemský RC → kód '25s' → ř.25 (pln_rez_pren), NE ř.20 (dod_zb, to je dodání do JČS pro EU).
        // Základ 15000, žádná výstupní daň (nominální sazba 21 % na RC negeneruje daň).
        $this->assertSame('15000', (string) $v2['pln_rez_pren'], 'ř.25 tuzemský PDP dodavatel = 15000 (S5)');
        $this->assertEmpty((string) $v2['dod_zb'], 'ř.20 musí být prázdné — S5 je tuzemský RC (§92a), ne dodání do JČS');

        // ř.3 pořízení zboží z JČS (P4) + samovyměřená daň
        $this->assertSame('8000', (string) $v1['p_zb23']);
        $this->assertSame('1680', (string) $v1['dan_pzb23']);

        // ř.10 tuzemský RC příjemce (P5) + samovyměřená daň (migrace is_reverse_charge)
        $this->assertSame('9000', (string) $v1['rez_pren23']);
        $this->assertSame('1890', (string) $v1['dan_rpren23']);

        // ř.40 odpočet tuzemsko 21 % = P1+P2+P3+P6+P7(−)+P8
        $this->assertSame('53000', (string) $v4['pln23'], 'ř.40 základ = 10000+2000+15000+11000−25000+40000');
        $this->assertSame('11130', (string) $v4['odp_tuz23_nar']);

        // ř.43 RC mirror odpočet = A.2 (P4) + B.1 (P5). Atributy nar_zdp23/od_zdp23
        // (sloupec „V plné výši", 21 %) — NE odp_rezim/odp_rez_nar (to je ř.45 korekce §75/§77/§79).
        $this->assertSame('17000', (string) $v4['nar_zdp23'], 'ř.43 základ = 8000 (P4) + 9000 (P5)');
        $this->assertSame('3570',  (string) $v4['od_zdp23'], 'ř.43 odpočet = 1680 + 1890');
        $this->assertSame('', (string) $v4['odp_rezim'], 'ř.45 (korekce) se NESMÍ plést s ř.43 (mirror odpočet)');

        // ř.46 součtový řádek odpočtu (ř.40-45 „V plné výši") = ř.40 (11130) + ř.43 (3570)
        $this->assertSame('14700', (string) $v4['odp_sum_nar'], 'ř.46 = 11130 (ř.40) + 3570 (ř.43)');

        // ř.47 hodnota pořízeného majetku (P8)
        $this->assertSame('40000', (string) $v4['nar_maj'], 'ř.47 = 40000 (P8 majetek)');

        // ══ KNIHA DPH (interní žurnál) ═══════════════════════════════════════
        // Pin chování PŘED refaktorem na sdílenou VatLedgerService — Kniha DPH
        // musí nad stejnými daty dávat konzistentní základy/daně s DPHDP3.
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) {
            $sec[$s['key']] = $s;
        }

        // Pořadí sekcí jako POHODA (reference DPH_LIST_KH 42026.pdf): přijatá
        // tuzemsko 15 → uskutečněná 36 → RC/dovozové páry 43 (primary i mirror) → 47.
        $this->assertSame(
            ['15.040', '36.001', '36.022', '36.025', '43.003', '43.010', '43.043', '47.047'],
            array_column($book['sections'], 'key'),
            'Kniha DPH: pořadí sekcí dle POHODA (RC pár až za sekcí 36)'
        );

        // 36.001 — vystavená tuzemsko 21 % (S1+S2+S3) = ř.1 DPHDP3
        $this->assertArrayHasKey('36.001', $sec);
        $this->assertEqualsWithDelta(55000, $sec['36.001']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(11550, $sec['36.001']['subtotal_vat'], 0.01);
        // 36.022 — vývoz (S4, kód 26 → ř.22)
        $this->assertArrayHasKey('36.022', $sec, 'Kniha DPH: sekce vývozu ř.22');
        $this->assertEqualsWithDelta(50000, $sec['36.022']['subtotal_base'], 0.01);
        // 15.040 — přijatá tuzemsko 21 % (P1+P2+P3+P6+P7−+P8) = ř.40
        $this->assertArrayHasKey('15.040', $sec);
        $this->assertEqualsWithDelta(53000, $sec['15.040']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(11130, $sec['15.040']['subtotal_vat'], 0.01);
        // 43.003 — pořízení z JČS (P4), samovyměřená daň (RC pár → členění 43 jako POHODA)
        $this->assertArrayHasKey('43.003', $sec);
        $this->assertEqualsWithDelta(8000, $sec['43.003']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(1680, $sec['43.003']['subtotal_vat'], 0.01);
        // 43.010 — tuzemský RC (P5) — samovyměření i BEZ per-faktura flagu
        // (díky is_reverse_charge na kódu 5 / migrace 0048). Toto pinuje fix konzistence.
        $this->assertArrayHasKey('43.010', $sec, 'P5 RC bez flagu musí mít sekci ř.10');
        $this->assertEqualsWithDelta(9000, $sec['43.010']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(1890, $sec['43.010']['subtotal_vat'], 0.01,
            'Kniha DPH musí samovyměřit RC i přes is_reverse_charge, ne jen flag');
        // Efektivní KH sekce per doklad ve sloupci KH — Kniha tiskne skutečnou
        // sekci (limit 10 000 Kč vč. DPH + DIČ, jako POHODA), ne statický default
        // z číselníku (kód 1 → "A.4", kód 40 → "B.2" by jinak byly všude).
        $khSale = [];
        foreach ($sec['36.001']['rows'] as $r) $khSale[$r['doc_number']] = $r['kh_section'];
        $this->assertSame('A.4', $khSale['2099060001'], 'S1 nad limit s DIČ → A.4');
        $this->assertSame('A.5', $khSale['2099060002'], 'S2 do limitu → A.5 (sumace)');
        $this->assertSame('A.5', $khSale['2099060003'], 'S3 nad limit bez DIČ → A.5 (sumace)');
        $khPurch = [];
        foreach ($sec['15.040']['rows'] as $r) $khPurch[$r['original_doc_number']] = $r['kh_section'];
        $this->assertSame('B.2', $khPurch['P-2099-001'], 'P1 nad limit s DIČ → B.2');
        $this->assertSame('B.3', $khPurch['P-2099-002'], 'P2 do limitu → B.3 (sumace)');
        $this->assertSame('B.3', $khPurch['P-2099-003'], 'P3 nad limit bez DIČ → B.3 (sumace)');
        $this->assertSame('B.2', $khPurch['P-2099-007'], 'P7 dobropis |−30 250| nad limit (abs) → B.2');

        // 43.043 — mirror odpočet u samovyměřené daně (P4 + P5)
        $this->assertArrayHasKey('43.043', $sec);
        $this->assertEqualsWithDelta(17000, $sec['43.043']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(3570, $sec['43.043']['subtotal_vat'], 0.01);
        // 47.047 — hodnota pořízeného majetku (P8)
        $this->assertArrayHasKey('47.047', $sec);
        $this->assertEqualsWithDelta(40000, $sec['47.047']['subtotal_base'], 0.01);

        // Souhrny oddělené pro výstup (ř.<40) a odpočet (ř.≥40). Bucket dle ČÍSLA
        // ŘÁDKU: samovyměření RC (ř.3/ř.10 primary) je na VÝSTUPU, zrcadlo ř.43 na
        // vstupu → reverse charge se v bilanci vyruší (jako v DPH přiznání). ř.47
        // (doplňující majetek) se do bilance nezapočítává.
        $this->assertEqualsWithDelta(15120, $book['totals']['issued']['vat'], 0.01,
            'totals.issued = daň na výstupu vč. samovyměření RC (36.001 + primary 43.003 + 43.010)');
        $this->assertEqualsWithDelta(14700, $book['totals']['received']['vat'], 0.01,
            'totals.received = odpočet na vstupu (15.040 + mirror 43.043), bez RC primary a bez ř.47');
        // Bilance = výstup − odpočet. RC se vyruší → zůstává prodej 11550 − tuzemský
        // odpočet 11130 = 420 (dřív chybných −3150, kdy RC primary padal do odpočtu).
        $this->assertEqualsWithDelta(420, $book['totals']['vat_balance'], 0.01);
    }

    /**
     * Daňově korektní zařazení do období když se DUZP a datum vystavení rozcházejí
     * přes hranici měsíce (DUZP 06/2099, vystaveno 07/2099):
     *
     *   - VYSTAVENÁ → patří do června (daň na výstupu vzniká k DUZP),
     *   - PŘIJATÁ   → patří do července (odpočet nelze uplatnit dřív, než plátce drží
     *                 daňový doklad — § 73 ZDPH; zpětný DUZP nepřesune doklad do června).
     */
    public function testStraddlingMonthAssignsIssuedByDuzpAndReceivedByLater(): void
    {
        $custDic = $this->client('Odběratel přelom', $this->czId, 'CZ66666664', customer: true);
        $vendDic = $this->client('Dodavatel přelom', $this->czId, 'CZ77777771', vendor: true);

        $juneTax = sprintf('%04d-06-25', self::YEAR);  // DUZP červen
        $julyIss = sprintf('%04d-07-05', self::YEAR);  // vystaveno červenec

        // VF: DUZP 25.6., vystavená 5.7. → základ 7000
        $this->sale('2099069001', $custDic, '1', false, $julyIss, $juneTax, [[7000, 1470, 21]]);
        // PF: DUZP 25.6., vystavená 5.7. → základ 5000
        $this->purchase('P-2099-901', $vendDic, '40', false, 'invoice', $julyIss, $juneTax, [[5000, 1050, 21]]);

        $sectionsFor = function (int $month): array {
            $book = $this->book->build($this->supplierId, self::YEAR, $month);
            $sec = [];
            foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
            return $sec;
        };

        // ── ČERVEN: jen vystavená (DUZP), přijatá tu NESMÍ být ──
        $june = $sectionsFor(6);
        $this->assertArrayHasKey('36.001', $june, 'VF s DUZP 06 patří do června');
        $this->assertEqualsWithDelta(7000, $june['36.001']['subtotal_base'], 0.01);
        $this->assertArrayNotHasKey('15.040', $june,
            'PF vystavená až 07 NESMÍ být v červnu (odpočet nelze uplatnit před doručením dokladu)');

        // ── ČERVENEC: jen přijatá (pozdější datum), vystavená je už v červnu ──
        $july = $sectionsFor(7);
        $this->assertArrayHasKey('15.040', $july, 'PF vystavená 07 patří do července');
        $this->assertEqualsWithDelta(5000, $july['15.040']['subtotal_base'], 0.01);
        $this->assertArrayNotHasKey('36.001', $july, 'VF se řadí dle DUZP (červen), ne dle vystavení');

        // ── Totéž musí platit i pro oficiální DPHDP3 (sdílí VatLedgerService) ──
        $dphJune = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, 6, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('7000', (string) $dphJune->Veta1['obrat23'], 'DPHDP3/06 ř.1: VF dle DUZP');
        $this->assertNotSame('5000', (string) $dphJune->Veta4['pln23'], 'DPHDP3/06 ř.40: PF tu být NESMÍ');

        $dphJuly = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, 7, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('5000', (string) $dphJuly->Veta4['pln23'], 'DPHDP3/07 ř.40: PF dle pozdějšího data');
        $this->assertNotSame('7000', (string) $dphJuly->Veta1['obrat23'], 'DPHDP3/07 ř.1: VF tu být NESMÍ');
    }

    /**
     * Kvartální Kniha DPH: období 'quarterly' natáhne rozsah na celé čtvrtletí
     * (kvartál odvozen z měsíce přes ceil(month/3)) — sekce sumují všechny tři
     * měsíce, na rozdíl od měsíčního pohledu. Period meta nese period_type + quarter.
     */
    public function testQuarterlyAggregatesWholeQuarter(): void
    {
        $cust = $this->client('Odběratel Q2', $this->czId, 'CZ65656561', customer: true);
        $vend = $this->client('Dodavatel Q2', $this->czId, 'CZ65656562', vendor: true);

        // Tři vystavené (duben/květen/červen = celé Q2), tuzemsko 21 % → ř.1.
        $this->sale('2099049001', $cust, '1', false, sprintf('%04d-04-10', self::YEAR), sprintf('%04d-04-10', self::YEAR), [[1000, 210, 21]]);
        $this->sale('2099059001', $cust, '1', false, sprintf('%04d-05-10', self::YEAR), sprintf('%04d-05-10', self::YEAR), [[2000, 420, 21]]);
        $this->sale('2099069001', $cust, '1', false, sprintf('%04d-06-10', self::YEAR), sprintf('%04d-06-10', self::YEAR), [[4000, 840, 21]]);
        // Jedna přijatá v květnu → ř.40.
        $this->purchase('P-2099-Q2', $vend, '40', false, 'invoice', sprintf('%04d-05-15', self::YEAR), sprintf('%04d-05-15', self::YEAR), [[3000, 630, 21]]);

        // Měsíční pohled (červen) = jen červnová VF.
        $monthly = $this->book->build($this->supplierId, self::YEAR, 6);
        $this->assertSame('monthly', $monthly['period']['period_type']);
        $this->assertNull($monthly['period']['quarter']);
        $this->assertEqualsWithDelta(840, $monthly['totals']['issued']['vat'], 0.01, 'měsíc 06 = jen červnová VF');

        // Kvartální pohled (libovolný měsíc Q2 → kvartál 2) sečte duben+květen+červen.
        $quarterly = $this->book->build($this->supplierId, self::YEAR, 6, 'quarterly');
        $this->assertSame('quarterly', $quarterly['period']['period_type']);
        $this->assertSame(2, $quarterly['period']['quarter']);
        $this->assertSame(sprintf('%04d-04-01', self::YEAR), $quarterly['period']['start']);
        $this->assertSame(sprintf('%04d-06-30', self::YEAR), $quarterly['period']['end']);

        $sec = [];
        foreach ($quarterly['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertEqualsWithDelta(7000, $sec['36.001']['subtotal_base'], 0.01, 'Q2 VF základ = 1000+2000+4000');
        $this->assertEqualsWithDelta(1470, $sec['36.001']['subtotal_vat'], 0.01, 'Q2 VF daň = 210+420+840');
        $this->assertEqualsWithDelta(3000, $sec['15.040']['subtotal_base'], 0.01, 'Q2 PF základ = 3000');
        $this->assertEqualsWithDelta(1470, $quarterly['totals']['issued']['vat'], 0.01);
        $this->assertEqualsWithDelta(630, $quarterly['totals']['received']['vat'], 0.01);
        $this->assertEqualsWithDelta(840, $quarterly['totals']['vat_balance'], 0.01, 'výstup 1470 − odpočet 630');
    }

    /**
     * Issue #117 — pořízení zboží z JČS s pozdě vystavenou fakturou: povinnost přiznat
     * daň (ř. 3) vzniká k DUZP dle § 25 odst. 1 bez ohledu na držení dokladu a pozdní
     * doklad neblokuje ani odpočet ř. 43 (§ 73 odst. 1 písm. b). Zahraniční RC se proto
     * zařazuje dle tax_date, NE GREATEST(tax_date, issue_date).
     *
     * Scénář dle reálného dokladu (Stellantis DE): převzetí 23.4. → DUZP 15.5.,
     * faktura vystavena až 4.6. → celé plnění patří do KVĚTNA, ne června.
     *
     * Tuzemský RC (CZ vendor) zůstává VĚDOMĚ na GREATEST — kontrolní regrese níže.
     */
    public function testEuAcquisitionAssignedByDuzpNotIssueDate(): void
    {
        $euVend = $this->client('EU dodavatel auto', $this->deId, 'DE205941503', vendor: true);
        $czVend = $this->client('CZ RC dodavatel pozdní', $this->czId, 'CZ88888885', vendor: true);

        $mayDuzp  = sprintf('%04d-05-15', self::YEAR);
        $juneIss  = sprintf('%04d-06-04', self::YEAR);

        // Pořízení zboží z JČS (kód 23, RC): DUZP 15.5., vystaveno 4.6. → KVĚTEN.
        $this->purchase('2260306316', $euVend, '23', true, 'invoice', $juneIss, $mayDuzp, [[305312, 0, 21]]);
        // Tuzemský RC (kód 5, flag): DUZP 15.5., vystaveno 4.6. → GREATEST → ČERVEN.
        $this->purchase('P-2099-902', $czVend, '5', true, 'invoice', $juneIss, $mayDuzp, [[9000, 0, 21]]);

        $sectionsFor = function (int $month): array {
            $book = $this->book->build($this->supplierId, self::YEAR, $month);
            $sec = [];
            foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
            return $sec;
        };

        // ── KVĚTEN: pořízení z JČS (ř.3 + mirror ř.43), tuzemský RC tu NESMÍ být ──
        $may = $sectionsFor(5);
        $this->assertArrayHasKey('43.003', $may, 'pořízení z JČS patří do měsíce DUZP (§ 25)');
        $this->assertEqualsWithDelta(305312, $may['43.003']['subtotal_base'], 0.01);
        $this->assertEqualsWithDelta(64115.52, $may['43.003']['subtotal_vat'], 0.01, 'samovyměření 305312 × 21 %');
        $this->assertArrayHasKey('43.043', $may, 'mirror odpočet ř.43 ve stejném období (§ 73/1/b)');
        $this->assertEqualsWithDelta(305312, $may['43.043']['subtotal_base'], 0.01);
        $this->assertArrayNotHasKey('43.010', $may, 'tuzemský RC s pozdním dokladem zůstává na GREATEST (červen)');

        // ── ČERVEN: pořízení z JČS tu NESMÍ být (žádná duplicita), tuzemský RC ano ──
        $june = $sectionsFor(6);
        $this->assertArrayNotHasKey('43.003', $june, 'pořízení z JČS nesmí spadnout do měsíce vystavení');
        $this->assertArrayHasKey('43.010', $june, 'tuzemský RC dle GREATEST patří do června');
        $this->assertEqualsWithDelta(9000, $june['43.010']['subtotal_base'], 0.01);

        // ── DPHDP3 květen: ř.3 + ř.43 + KH A.2 ──
        $dpMay = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, 5, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('305312', (string) $dpMay->Veta1['p_zb23'], 'DPHDP3/05 ř.3 základ');
        $this->assertSame('305312', (string) $dpMay->Veta4['nar_zdp23'], 'DPHDP3/05 ř.43 mirror');

        $khMay = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, 5)['xml']);
        $this->assertCount(1, $khMay->DPHKH1->VetaA2, 'KH/05 A.2: pořízení z JČS');
        $this->assertSame('305312.00', (string) $khMay->DPHKH1->VetaA2[0]['zakl_dane1']);

        // ── DPHDP3 červen: ř.3 prázdný ──
        $dpJune = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, 6, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('', (string) $dpJune->Veta1['p_zb23'], 'DPHDP3/06 ř.3 musí být prázdný');
        $khJune = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, 6)['xml']);
        $this->assertCount(0, $khJune->DPHKH1->VetaA2, 'KH/06 A.2 musí být prázdná');
    }

    /**
     * Issue #116 — zahraniční RC doklad importovaný s řádkovou sazbou 0 % (převzatou
     * z cizího dokladu): samovyměření se nesmí spočítat jako základ × 0 %. Ledger
     * použije sazbu klasifikačního kódu (23 → 21 %) a efektivní sazba se propíše
     * i do rate bucketů KH (A.2 sloupec 21 %).
     */
    public function testForeignRcZeroRateSelfAssessesViaClassificationRate(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $euVend = $this->client('EU dodavatel 0%', $this->deId, 'DE222222222', vendor: true);

        // Kód 23 (pořízení z JČS), RC flag, ale řádek má vat_rate_snapshot = 0
        // (přesně tak to do 4.15 ukládal AI import — issue #116).
        $this->purchase('P-2099-903', $euVend, '23', true, 'invoice', $d(10), $d(10), [[12546, 0, 0]]);

        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        // ř.3: základ + samovyměřená daň ze sazby klasifikace (12546 × 21 % = 2634.66 → 2635)
        $this->assertSame('12546', (string) $dp->Veta1['p_zb23'], 'ř.3 základ i při 0% řádku');
        $this->assertNotSame('', (string) $dp->Veta1['dan_pzb23'], 'ř.3 daň NESMÍ být prázdná');
        $this->assertNotSame('0', (string) $dp->Veta1['dan_pzb23'], 'ř.3 daň NESMÍ být 0 (issue #116)');
        // ř.43 mirror odpočet
        $this->assertSame('12546', (string) $dp->Veta4['nar_zdp23'], 'ř.43 mirror základ');

        // KH A.2 — základ i daň v bucketu 21 % (efektivní sazba z klasifikace)
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $this->assertCount(1, $kh->DPHKH1->VetaA2, 'A.2: doklad tam musí být');
        $this->assertSame('12546.00', (string) $kh->DPHKH1->VetaA2[0]['zakl_dane1'], 'A.2 základ v 21% sloupci');
        $this->assertSame('2634.66', (string) $kh->DPHKH1->VetaA2[0]['dan1'], 'A.2 samovyměřená daň 12546 × 21 %');

        // Kniha DPH — sekce ř.3 se samovyměřenou daní
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('43.003', $sec);
        $this->assertEqualsWithDelta(2634.66, $sec['43.003']['subtotal_vat'], 0.01, 'Kniha: samovyměření z classification rate');
    }

    /**
     * Zaokrouhlení samovyměřené daně u cizoměnového RC (pořízení z JČS v EUR).
     *
     * Daň se MUSÍ počítat ze ZÁKLADU přepočteného na CZK (§ 37/1), ne z cizoměnové
     * daně přenásobené kurzem — jinak dvojí zaokrouhlení rozejde KH A.2 a přiznání
     * o haléře. Typický případ pořízení vozidla z JČS: zaokrouhlení EUR-first
     * dávalo o 0,01 Kč jinou daň než zákonný postup ze základu v Kč.
     *
     *   základ 100,05 EUR × kurz 25,00 = 2 501,25 Kč → daň 2 501,25 × 21 % = 525,2625 → 525,26 Kč
     *   (chybně EUR-first: round(100,05 × 21 %)=21,01 EUR × 25 = 525,25 Kč)
     */
    public function testForeignCurrencyRcSelfAssessmentRoundsFromCzkBase(): void
    {
        $pdo = $this->db->pdo();
        $eurId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            $pdo->exec("INSERT INTO currencies (code, name) VALUES ('EUR', 'Euro')");
            $eurId = (int) $pdo->lastInsertId();
        }

        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $euVend = $this->client('EU dodavatel EUR', $this->deId, 'DE333333333', vendor: true);

        // Kód 23 (pořízení z JČS), RC, základ 100,05 EUR, kurz 25,00.
        $this->purchase('P-2099-EUR', $euVend, '23', true, 'invoice', $d(10), $d(10), [[100.05, 0, 21]],
            currencyId: $eurId, exchangeRate: 25.00);

        // ── KH A.2: základ i daň ze základu přepočteného na CZK ──
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $this->assertCount(1, $kh->DPHKH1->VetaA2, 'A.2: EUR pořízení z JČS');
        $this->assertSame('2501.25', (string) $kh->DPHKH1->VetaA2[0]['zakl_dane1'], 'A.2 základ = 100,05 × 25');
        $this->assertSame('525.26', (string) $kh->DPHKH1->VetaA2[0]['dan1'],
            'A.2 daň ze ZÁKLADU v CZK (525,26), NE EUR-first (525,25)');

        // ── Kniha DPH: stejná daň (sdílený VatLedgerService) ──
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('43.003', $sec);
        $this->assertEqualsWithDelta(525.26, $sec['43.003']['subtotal_vat'], 0.001, 'Kniha: daň ze základu v CZK');
    }

    /**
     * Regrese: faktura s vat_deduction='none' (bez nároku na odpočet — reprezentace
     * apod.) NESMÍ vstoupit do Knihy DPH, DPHDP3 (ř.40) ani KH. Plný nárok ano.
     */
    public function testVatDeductionNoneExcludedFromVatReports(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel reprez.', $this->czId, 'CZ33333339', vendor: true);

        // Plný nárok → vstupuje do DPH (10000 základ, 2100 DPH)
        $this->purchase('P-2099-100', $vend, '40', false, 'invoice', $d(10), $d(10), [[10000, 2100, 21]]);
        // Bez nároku (reprezentace) → NESMÍ se objevit nikde v DPH evidenci
        $this->purchase('P-2099-101', $vend, '40', false, 'invoice', $d(11), $d(11), [[7000, 1470, 21]], vatDeduction: 'none');

        // Kniha DPH — ř.40 jen 10000, none vyloučeno
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('15.040', $sec);
        $this->assertEqualsWithDelta(10000, $sec['15.040']['subtotal_base'], 0.01,
            'Faktura bez nároku (none) nesmí vstoupit do Knihy DPH');
        $this->assertEqualsWithDelta(2100, $book['totals']['received']['vat'], 0.01,
            'Odpočet jen z plného nároku (2100), ne z none (1470)');

        // DPHDP3 ř.40 odpočet jen 10000
        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $this->assertSame('10000', (string) $dphXml->DPHDP3->Veta4['pln23'],
            'ř.40 = jen plný nárok (none vyloučeno)');
    }

    /**
     * Regrese: přijatá zálohová / proforma (document_kind='advance') NENÍ daňový
     * doklad → NESMÍ vstoupit do Knihy DPH, DPHDP3 (ř.40) ani KH (B.2/B.3).
     * Symetricky k výstupní straně, kde se vylučuje invoice_type='proforma'.
     */
    public function testReceivedAdvanceProformaExcludedFromVatReports(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel záloha', $this->czId, 'CZ99999990', vendor: true);

        // Řádná přijatá faktura → vstupuje do DPH (10000 / 2100)
        $this->purchase('P-2099-400', $vend, '40', false, 'invoice', $d(10), $d(10), [[10000, 2100, 21]]);
        // Zálohová / proforma (advance) → NESMÍ se objevit nikde v DPH evidenci
        $this->purchase('P-2099-401', $vend, '40', false, 'advance', $d(11), $d(11), [[50000, 10500, 21]]);

        // Kniha DPH — ř.40 jen řádná faktura (10000), advance vyloučena
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('15.040', $sec);
        $this->assertEqualsWithDelta(10000, $sec['15.040']['subtotal_base'], 0.01,
            'Přijatá proforma (advance) nesmí vstoupit do Knihy DPH');
        $this->assertEqualsWithDelta(2100, $book['totals']['received']['vat'], 0.01,
            'Odpočet jen z řádné faktury (2100), ne z advance (10500)');

        // DPHDP3 ř.40 odpočet jen 10000
        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $this->assertSame('10000', (string) $dphXml->DPHDP3->Veta4['pln23'],
            'ř.40 = jen řádná faktura (advance vyloučena)');

        // KH B.2 — jen řádná faktura, advance nesmí přidat druhý záznam
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $b2bases = [];
        foreach ($kh->DPHKH1->VetaB2 as $v) $b2bases[] = (string) $v['zakl_dane1'];
        $this->assertSame(['10000.00'], $b2bases, 'KH B.2: jen řádná faktura, advance vyloučena');
    }

    /**
     * Regrese (daňový audit 2026-05-28): dovoz služby z EU (kód 24) se musí
     * SAMOVYMĚŘIT i BEZ ručního zaškrtnutí RC flagu na dokladu — díky
     * is_reverse_charge=1 na kódu (migrace 0063). Výstup ř.12 i zrcadlový
     * odpočet ř.43 musí mít nenulovou daň.
     */
    public function testImportedServiceSelfAssessesWithoutInvoiceFlag(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel služba EU', $this->deId, 'DE111111111', vendor: true);

        // Kód 24 (dovoz služby), reverse_charge FLAG = false → spoléháme jen na kód.
        // Vendor fakturuje bez DPH (vat=0), sazba 21 %.
        $this->purchase('P-2099-500', $vend, '24', false, 'invoice', $d(10), $d(10), [[10000, 0, 21]]);

        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $dp = $dphXml->DPHDP3;
        // ř.12 výstup (dovoz služby) — samovyměřená daň 2100 i bez flagu
        $this->assertSame('10000', (string) $dp->Veta1['p_sl23_z'], 'ř.12 základ dovoz služby');
        $this->assertSame('2100',  (string) $dp->Veta1['dan_psl23_z'], 'ř.12 daň samovyměřena z kódu (ne z flagu)');
        // ř.43 zrcadlový odpočet
        $this->assertSame('10000', (string) $dp->Veta4['nar_zdp23'], 'ř.43 mirror základ');
        $this->assertSame('2100',  (string) $dp->Veta4['od_zdp23'], 'ř.43 mirror odpočet');
        // ř.46 součtový odpočet = jen ř.43 (žádný tuzemský odpočet) = 2100
        $this->assertSame('2100',  (string) $dp->Veta4['odp_sum_nar'], 'ř.46 = ř.43 (2100)');

        // Kniha DPH — sekce 43.012 (dovoz služby, RC pár pod členěním 43) a 43.043 (mirror)
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('43.012', $sec, 'Kniha: sekce ř.12 dovoz služby');
        $this->assertEqualsWithDelta(2100, $sec['43.012']['subtotal_vat'], 0.01, 'Kniha ř.12 samovyměřená daň');
    }

    /**
     * Issue #164 — přijatá služba z JČS (EU) v reverse charge (kód 24e, § 9 odst. 1)
     * patří v KH do oddílu A.2, NE do B.1. VetaA2.vatid_dod musí zachovat alfanumerické
     * EU VAT ID bez kódu země (IE3668997OH → 3668997OH), ne jen číslice.
     */
    public function testEuReverseChargeServiceGoesToA2WithAlphanumericVatId(): void
    {
        $ieId = $this->countryId('IE');
        if ($ieId === 0) {
            $this->markTestSkipped('Země IE není v číselníku countries.');
        }
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        // Reálný případ z issue: Google Cloud EMEA Ltd, IE, VAT ID s písmeny.
        $vend = $this->client('Google Cloud EMEA', $ieId, 'IE3668997OH', vendor: true);

        // Kód 24e (služba z EU, ř.5), RC flag = false → spoléháme na klasifikační kód.
        $this->purchase('P-2099-700', $vend, '24e', false, 'invoice', $d(10), $d(10), [[1000, 0, 21]]);

        // ── KH: A.2 (ne B.1), alfanumerické VAT ID, k_stat = IE ──
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $this->assertCount(1, $kh->DPHKH1->VetaA2, 'EU služba (24e) musí být v A.2');
        $this->assertCount(0, $kh->DPHKH1->VetaB1, 'EU služba (24e) NESMÍ být v B.1');
        $a2 = $kh->DPHKH1->VetaA2[0];
        $this->assertSame('IE', (string) $a2['k_stat'], 'A.2 k_stat = země dodavatele');
        $this->assertSame('3668997OH', (string) $a2['vatid_dod'], 'A.2 vatid_dod zachová písmena, ořeže prefix IE');
        $this->assertSame('1000.00', (string) $a2['zakl_dane1'], 'A.2 základ 21 %');
        $this->assertSame('210.00', (string) $a2['dan1'], 'A.2 samovyměřená daň 1000 × 21 %');
        // Kontrolní věta C — základ A.2 se sčítá do celk_zd_a2.
        $this->assertSame('1000.00', (string) $kh->DPHKH1->VetaC['celk_zd_a2'], 'VetaC celk_zd_a2 zahrnuje 24e');

        // ── DPHDP3: ř.5 (přijetí služby z EU) + zrcadlo ř.43 ──
        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('1000', (string) $dp->Veta1['p_sl23_e'], 'ř.5 základ EU služba');
        $this->assertSame('210',  (string) $dp->Veta1['dan_psl23_e'], 'ř.5 samovyměřená daň');
        $this->assertSame('1000', (string) $dp->Veta4['nar_zdp23'], 'ř.43 mirror základ');
        $this->assertSame('210',  (string) $dp->Veta4['od_zdp23'], 'ř.43 mirror odpočet');
    }

    /**
     * Regrese (daňový audit 2026-05-28): přijaté plnění bez nároku na odpočet
     * (kód 42, dphdp3_line=NULL) NESMÍ spadnout do KH B.2/B.3, přestože má
     * nenulový základ v sazbě 21 %. DPHDP3 ho rovněž vynechává.
     */
    public function testNonDeductiblePurchaseExcludedFromKh(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel bez nároku', $this->czId, 'CZ12121219', vendor: true);

        // Řádná odpočtová faktura (kód 40) nad limit → B.2
        $this->purchase('P-2099-600', $vend, '40', false, 'invoice', $d(10), $d(10), [[20000, 4200, 21]]);
        // Bez nároku (kód 42, 21 % bez nároku) nad limit → NESMÍ do B.2/B.3
        $this->purchase('P-2099-601', $vend, '42', false, 'invoice', $d(11), $d(11), [[30000, 6300, 21]]);

        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $b2bases = [];
        foreach ($kh->DPHKH1->VetaB2 as $v) $b2bases[] = (string) $v['zakl_dane1'];
        $this->assertSame(['20000.00'], $b2bases, 'KH B.2: jen kód 40, kód 42 (bez nároku) vyloučen');
        // B.3 (do limitu) musí zůstat prázdné — kód 42 tam taky nesmí
        $this->assertCount(0, $kh->DPHKH1->VetaB3, 'KH B.3: kód 42 nesmí padnout ani do sumace');

        // DPHDP3 ř.40 jen odpočtová faktura
        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $this->assertSame('20000', (string) $dphXml->DPHDP3->Veta4['pln23'], 'ř.40 jen kód 40');
    }

    /**
     * Regrese (daňový audit 2026-05-28): osvobozené tuzemské vystavené plnění
     * (kód 3, sazba 0 %) NESMÍ spadnout na ř.3 DPHDP3 (= pořízení zboží z JČS,
     * vstup) — to byla seedová chyba "kód=řádek". Po migraci 0063 (dphdp3_line=NULL)
     * se do DPHDP3 ani KH nevykazuje.
     */
    public function testExemptDomesticSaleDoesNotLandOnLine3(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $cust = $this->client('Odběratel osvobozeno', $this->czId, 'CZ15151512', customer: true);

        // Osvobozené tuzemské plnění (kód 3), sazba 0 %, základ 80000.
        $this->sale('2099068001', $cust, '3', false, $d(10), $d(10), [[80000, 0, 0]]);

        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $dp = $dphXml->DPHDP3;
        // ř.3 (pořízení zboží z JČS) NESMÍ obsahovat základ osvobozeného prodeje.
        $this->assertNotSame('80000', (string) $dp->Veta1['p_zb23'], 'osvobozený prodej nesmí korumpovat ř.3');
        $this->assertSame('', (string) $dp->Veta1['p_zb23'], 'ř.3 musí zůstat prázdný (žádné pořízení z EU)');

        // Audit 2026-07 (fix 3): osvobozené plnění (kód 3) PATŘÍ na ř.50 (Veta5.plnosv_kf).
        $this->assertNotNull($dp->Veta5, 'Veta5 (ř.50 osvobozená plnění) musí existovat');
        $this->assertSame('80000', (string) $dp->Veta5['plnosv_kf'], 'ř.50 plnosv_kf = 80000 (osvobozený prodej)');
        // Veta5 musí sedět v XSD pořadí (Veta4 → Veta5 → Veta6) a mít validní atribut.
        $resDp = (new \MyInvoice\Service\Validation\XmlSchemaValidator())
            ->validate($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml'], 'dphdp3');
        $this->assertNotSame('failed', $resDp['status'], 'DPHDP3 s Veta5 musí projít XSD: ' . implode('; ', $resDp['errors']));

        // KH — osvobozené plnění (0 %) nepatří do A.4/A.5.
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $this->assertCount(0, $kh->DPHKH1->VetaA4, 'osvobozený prodej nepatří do A.4');
        $this->assertCount(0, $kh->DPHKH1->VetaA5, 'osvobozený prodej nepatří do A.5 (sumace)');
    }

    /**
     * Country-aware RC klasifikace vystavených plnění (fix 2026-05-29): příznak reverse_charge
     * se klasifikuje podle ZEMĚ odběratele —
     *   • tuzemský odběratel (CZ) → tuzemský §92a dodavatel → kód '25s' → DPHDP3 ř.25 (pln_rez_pren)
     *   • zahraniční EU odběratel  → dodání zboží do JČS    → kód '20'  → DPHDP3 ř.20 (dod_zb)
     * Dříve oba končily na '20'/ř.20 → tuzemský RC (stavební práce ap.) se chybně vykázal jako
     * dodání do EU. Ani jeden nepřidává výstupní daň (ř.1).
     */
    public function testReverseChargeClassifiedByCustomerCountry(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $czCust = $this->client('Tuzemský RC odběratel', $this->czId, 'CZ70707075', customer: true);
        $euCust = $this->client('EU RC odběratel',       $this->skId, 'SK7070707',  customer: true);

        // Oba reverse_charge, BEZ ručního kódu → auto-klasifikace podle země odběratele.
        $this->sale('2099069001', $czCust, null, true, $d(10), $d(10), [[12000, 0, 21]]);
        $this->sale('2099069002', $euCust, null, true, $d(11), $d(11), [[34000, 0, 0]]);

        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('12000', (string) $dp->Veta2['pln_rez_pren'], 'tuzemský RC → ř.25 (pln_rez_pren)');
        $this->assertSame('34000', (string) $dp->Veta2['dod_zb'],       'EU RC → ř.20 (dod_zb)');
        $this->assertSame('', (string) $dp->Veta1['obrat23'], 'RC plnění nepatří do ř.1 (výstupní daň)');
    }

    /**
     * Regrese (daňový audit 2026-05-28): DPHDP3 generuje Veta6 (rekapitulace) —
     * ř.62 daň na výstupu, ř.63 odpočet, ř.64 vlastní daň / ř.66 nadměrný odpočet.
     */
    public function testDphPriznaniEmitsVeta6Recap(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $cust = $this->client('Odběratel recap', $this->czId, 'CZ13131316', customer: true);
        $vend = $this->client('Dodavatel recap', $this->czId, 'CZ14141413', vendor: true);

        // Výstup: 50000 × 21 % = 10500 daň. Odpočet: 20000 × 21 % = 4200.
        // Vlastní daň = 10500 − 4200 = 6300 (kladná → dano_da).
        $this->sale('2099067001', $cust, '1', false, $d(10), $d(10), [[50000, 10500, 21]]);
        $this->purchase('P-2099-700', $vend, '40', false, 'invoice', $d(11), $d(11), [[20000, 4200, 21]]);

        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        $this->assertNotNull($dp->Veta6, 'Veta6 (rekapitulace) musí existovat');
        $this->assertSame('10500', (string) $dp->Veta6['dan_zocelk'], 'ř.62 daň na výstupu celkem');
        $this->assertSame('4200',  (string) $dp->Veta6['odp_zocelk'], 'ř.63 odpočet celkem');
        $this->assertSame('6300',  (string) $dp->Veta6['dano_da'], 'ř.64 vlastní daňová povinnost');
        $this->assertSame('',      (string) $dp->Veta6['dano_no'], 'ř.66 nadměrný odpočet nesmí být vyplněn');
        // ř.46 (odp_sum_nar) musí existovat a rovnat se ř.63 (odp_zocelk) — zde jen ř.40.
        $this->assertSame('4200',  (string) $dp->Veta4['odp_sum_nar'], 'ř.46 součtový odpočet = ř.63');
    }

    /**
     * §75 poměrný odpočet: vat_deduction='proportional' s percentem zkrátí
     * odpočet (základ i daň) v Knize DPH i DPHDP3 (ř.40) o dané procento.
     */
    public function testProportionalDeductionScalesByPercent(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel auto', $this->czId, 'CZ44444448', vendor: true);

        // Auto 70 % business: základ 10000, DPH 2100 → odpočet jen 7000 / 1470
        $this->purchase('P-2099-200', $vend, '40', false, 'invoice', $d(10), $d(10), [[10000, 2100, 21]],
            vatDeduction: 'proportional', vatDeductionPercent: 70.0);

        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) $sec[$s['key']] = $s;
        $this->assertArrayHasKey('15.040', $sec);
        $this->assertEqualsWithDelta(7000, $sec['15.040']['subtotal_base'], 0.01, 'ř.40 základ × 70 %');
        $this->assertEqualsWithDelta(1470, $sec['15.040']['subtotal_vat'], 0.01, 'ř.40 daň × 70 %');

        $dphXml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $this->assertSame('7000', (string) $dphXml->DPHDP3->Veta4['pln23'], 'DPHDP3 ř.40 = 7000 (70 %)');
    }

    /**
     * Změna daňového uplatnění u už očíslované faktury přepíše PREFIX interního
     * čísla (varsymbol) na nový typ a zachová číselnou řadu. Ruční číslo neměníme.
     */
    public function testReprefixVarsymbolOnTaxTreatmentChange(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel přečíslo', $this->czId, 'CZ55555556', vendor: true);

        // Faktura bez nároku (none), ale s číslem PF (jako by byla původně plný nárok).
        $this->purchase('REPFX-1', $vend, '40', false, 'invoice', $d(10), $d(10), [[1000, 210, 21]], vatDeduction: 'none');
        $id = (int) end($this->purchaseIds);
        $pdo = $this->db->pdo();
        // none + neuznatelný (tax_deductible=0) → očekávaný prefix NN.
        $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ?, tax_deductible = 0 WHERE id = ?')->execute(['PF2099001', $id]);

        $this->piRepo->reprefixVarsymbol($id, $this->supplierId);
        $vs = (string) $pdo->query("SELECT varsymbol FROM purchase_invoices WHERE id = $id")->fetchColumn();
        self::assertSame('NN2099001', $vs, 'none + neuznatelný → prefix NN, řada zachována');

        // Ruční (cizí) číslo se NEpřepisuje.
        $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ?')->execute(['FAK-2099/7', $id]);
        $this->piRepo->reprefixVarsymbol($id, $this->supplierId);
        $vs2 = (string) $pdo->query("SELECT varsymbol FROM purchase_invoices WHERE id = $id")->fetchColumn();
        self::assertSame('FAK-2099/7', $vs2, 'ruční číslo se nepřepisuje');
    }

    /**
     * §75 poměrný odpočet: doklad nad limit s DIČ se v KH (B.2) označí pomer='A'
     * (částky jsou už zkrácené). Plný nárok → pomer='N'.
     */
    public function testProportionalDeductionMarksKhPomer(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel pomer', $this->czId, 'CZ88888887', vendor: true);

        // Plný nárok, gross 24200 (nad limit) → B.2 pomer N, základ 20000
        $this->purchase('P-2099-300', $vend, '40', false, 'invoice', $d(10), $d(10), [[20000, 4200, 21]]);
        // Poměrný 50 %, gross 12100 (nad limit) → B.2 pomer A, zkrácený základ 5000
        $this->purchase('P-2099-301', $vend, '40', false, 'invoice', $d(11), $d(11), [[10000, 2100, 21]],
            vatDeduction: 'proportional', vatDeductionPercent: 50.0);

        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $pomerByBase = [];
        foreach ($kh->DPHKH1->VetaB2 as $v) {
            $pomerByBase[(string) $v['zakl_dane1']] = (string) $v['pomer'];
        }
        $this->assertSame('N', $pomerByBase['20000.00'] ?? null, 'Plný nárok → pomer=N');
        $this->assertSame('A', $pomerByBase['5000.00'] ?? null, 'Poměrný §75 → pomer=A (zkrácený základ 5000)');
    }

    /**
     * Souhrnné hlášení: kód plnění (k_pln_eu) dle DPHSHV XSD —
     *   dodání zboží do JČS → 0, služba do JČS (§9/1) → 3,
     *   třístranný obchod prostřední osobou (§17) → 2.
     * Plus DPHDP3: ř.20 (dod_zb), ř.21 (pln_sluzby), ř.31 (tri_dozb / Veta3).
     */
    public function testEuSupplyShvCodesAndTriangular(): void
    {
        // SHV vyžaduje EU zemi — pokud seed countries nemá SK jako EU, přeskoč.
        $skEu = (int) ($this->db->pdo()->query("SELECT COALESCE(is_eu,0) FROM countries WHERE iso2='SK' LIMIT 1")->fetchColumn() ?: 0);
        if ($skEu !== 1) {
            $this->markTestSkipped('SK není v countries označeno jako EU — SHV test přeskočen.');
        }

        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $euCust = $this->client('EU odběratel SHV', $this->skId, 'SK7654321', customer: true);

        // Dodání zboží do JČS (kód 20 → SHV 0, DPHDP3 ř.20)
        $this->sale('2099063001', $euCust, '20', false, $d(10), $d(10), [[10000, 0, 0]]);
        // Poskytnutí služby do JČS (kód 22 → SHV 3, DPHDP3 ř.21)
        $this->sale('2099063002', $euCust, '22', false, $d(11), $d(11), [[5000, 0, 0]]);
        // Třístranný obchod — dodání prostřední osobou (kód 31 → SHV 2, DPHDP3 ř.31)
        $this->sale('2099063003', $euCust, '31', false, $d(12), $d(12), [[7000, 0, 0]]);

        // ── SHV: kódy plnění ──
        $shv = $this->shv->build($this->supplierId, self::YEAR, self::MONTH);
        $amountByType = [];
        foreach ($shv['summary']['rows'] as $r) {
            $amountByType[(string) $r['sh_type']] = (float) $r['amount'];
        }
        $this->assertEqualsWithDelta(10000, $amountByType['0'] ?? -1, 0.01, 'SHV kód 0 = dodání zboží');
        $this->assertEqualsWithDelta(5000,  $amountByType['3'] ?? -1, 0.01, 'SHV kód 3 = služba §9/1 (dříve chybně 2)');
        $this->assertEqualsWithDelta(7000,  $amountByType['2'] ?? -1, 0.01, 'SHV kód 2 = třístranný obchod (prostřední osoba)');

        // ── DPHDP3: oddíl C ──
        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('10000', (string) $dp->Veta2['dod_zb'],     'ř.20 dodání zboží do JČS');
        $this->assertSame('5000',  (string) $dp->Veta2['pln_sluzby'], 'ř.21 služby do JČS');
        $this->assertNotNull($dp->Veta3, 'Veta3 (oddíl C) musí existovat pro třístranný obchod');
        $this->assertSame('7000',  (string) $dp->Veta3['tri_dozb'],   'ř.31 dodání zboží prostřední osobou');
    }

    /**
     * Issue #199 — vydaná služba do JČS (Google AdSense pro Google Ireland Limited)
     * v reverse charge (kód 22, § 9 odst. 1) se NESMÍ zahrnout do KH oddílu A.1.
     * A.1 je vyhrazen POUZE tuzemskému přenesení §92 (kód 25s, kh_section='A.1').
     * Přeshraniční B2B služba do EU patří jen na ř.21 přiznání + souhrnné hlášení
     * kód 3 — do kontrolního hlášení vůbec.
     *
     * Reprodukce z issue zapíná na faktuře příznak reverse_charge=1 → is_rc=true.
     * Dříve sale-větev collectSections() routovala do A.1 čistě podle is_rc (bez
     * kontroly kh_section) → vznikal chybný VetaA1. Fix gate-uje A.1 na has_a1
     * (kh_section='A.1'), symetricky k B.1 na přijaté straně.
     */
    public function testEuServiceReverseChargeNotInKhA1(): void
    {
        $skEu = (int) ($this->db->pdo()->query("SELECT COALESCE(is_eu,0) FROM countries WHERE iso2='SK' LIMIT 1")->fetchColumn() ?: 0);
        if ($skEu !== 1) {
            $this->markTestSkipped('SK není v countries označeno jako EU — SHV test přeskočen.');
        }
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $euCust = $this->client('Google Ireland Limited', $this->skId, 'SK2020202', customer: true);

        // Kód 22 (služba do JČS) ručně zvolen + reverse_charge FLAG zapnutý — přesně jak
        // to dělá reprodukce z issue #199 (plnění bez české DPH v režimu reverse charge).
        $this->sale('2099062199', $euCust, '22', true, $d(10), $d(10), [[40000, 0, 21]]);

        // ── KH: doklad NESMÍ být nikde (ani A.1, ani A.4/A.5) ──
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $this->assertCount(0, $kh->DPHKH1->VetaA1, 'EU RC služba (kód 22) NESMÍ být v A.1 (issue #199)');
        $this->assertCount(0, $kh->DPHKH1->VetaA4, 'EU RC služba nepatří do A.4');
        $this->assertCount(0, $kh->DPHKH1->VetaA5, 'EU RC služba nepatří do A.5 (sumace)');
        // VetaC: žádné uskutečněné tuzemské přenesení → rez_pren23 = 0.
        $this->assertSame('0.00', (string) $kh->DPHKH1->VetaC['rez_pren23'], 'VetaC rez_pren23 = 0 (EU služba není A.1)');

        // ── DPHDP3: patří na ř.21 (pln_sluzby), NE ř.25 (tuzemský PDP) ani ř.1 (výstup) ──
        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        $this->assertSame('40000', (string) $dp->Veta2['pln_sluzby'], 'ř.21 služby do JČS = 40000');
        $this->assertSame('', (string) $dp->Veta2['pln_rez_pren'], 'ř.25 (tuzemský §92 PDP) musí zůstat prázdný');
        $this->assertSame('', (string) $dp->Veta1['obrat23'], 'ř.1 výstupní daň prázdná (RC služba)');

        // ── SHV: kód plnění 3 (služba §9/1) ──
        $shv = $this->shv->build($this->supplierId, self::YEAR, self::MONTH);
        $amountByType = [];
        foreach ($shv['summary']['rows'] as $r) $amountByType[(string) $r['sh_type']] = (float) $r['amount'];
        $this->assertEqualsWithDelta(40000, $amountByType['3'] ?? -1, 0.01, 'SHV kód 3 = služba do JČS');
    }

    /**
     * Režim „ceny s DPH" (prices_include_vat) end-to-end až do výkazů: faktura, kde
     * jsou položky brutto (3× 33 Kč s DPH @21 %), se přes InvoiceMath shora rozpadne
     * na base/vat s rounding distribution. Uložené per-řádkové totály MUSÍ ve výkazech
     * dát PŘESNĚ koeficientovou daň z celkového grossu — tj. KH A.5 = 81,82 / 17,18
     * (ne 3× 27,27 / 5,73 = 81,81 / 17,19). Tím je ochráněn celý daňový řetězec:
     * InvoiceMath shora → uložené totály → VatLedgerService → KH/DPHDP3.
     */
    public function testPricesIncludeVatInvoiceLandsCoefficientTaxInReports(): void
    {
        $custDic = $this->client('Účtenka odběratel', $this->czId, 'CZ11111118', customer: true);
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);

        // Top-down rozpad přes reálný InvoiceMath (stejný kód jako kalkulátor).
        $computed = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        // Sanity: součet řádkové daně = koeficient z celkového grossu (99 × 21/121 = 17,18).
        self::assertSame(17.18, $computed['totals']['vat']);
        self::assertSame(81.82, $computed['totals']['without_vat']);
        self::assertSame(99.00, $computed['totals']['with_vat']);

        // Vlož fakturu s uloženými top-down totály (tak jak je uloží InvoiceCalculator).
        $items = array_map(static fn (array $it): array => [$it['base'], $it['vat'], $it['rate']], $computed['items']);
        $this->sale('2099069001', $custDic, '1', false, $d(10), $d(10), $items);

        // ── KONTROLNÍ HLÁŠENÍ (haléřová přesnost) ──
        // 99 Kč je pod limitem A.4 (10 000) → sumace A.5. Daň MUSÍ být 17,18 (koeficient),
        // ne 17,19 (naivní součet per-řádek bez rounding distribution).
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $root = $kh->DPHKH1;
        self::assertSame('81.82', (string) $root->VetaA5['zakl_dane1'], 'KH A.5 základ = 81,82');
        self::assertSame('17.18', (string) $root->VetaA5['dan1'], 'KH A.5 daň = 17,18 (koeficient, ne 17,19)');

        // ── Přijatá strana (odpočet) — daňová symetrie: stejný top-down rozpad,
        // PurchaseInvoiceCalculator sdílí InvoiceMath. Dodavatel s DIČ, tuzemský odpočet.
        $vendDic = $this->client('Účtenka dodavatel', $this->czId, 'CZ22222220', vendor: true);
        $this->purchase('P-2099-901', $vendDic, '40', false, 'invoice', $d(12), $d(12), $items);

        // ── DPH PŘIZNÁNÍ (zaokrouhleno na celé Kč) ──
        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;
        self::assertSame('82', (string) $dp->Veta1['obrat23'], 'DPHDP3 ř.1 základ = 82 (zaokr.)');
        self::assertSame('17', (string) $dp->Veta1['dan23'], 'DPHDP3 ř.1 daň = 17 (zaokr.)');
        // ř.40 odpočet tuzemsko 21 % (přijatá top-down faktura) — symetrie s výstupem.
        self::assertSame('82', (string) $dp->Veta4['pln23'], 'DPHDP3 ř.40 základ odpočtu = 82');
        self::assertSame('17', (string) $dp->Veta4['odp_tuz23_nar'], 'DPHDP3 ř.40 daň odpočtu = 17');

        // KH B.3 (pod limitem) — haléřová přesnost přijaté daně = 17,18.
        $kh2 = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        self::assertSame('81.82', (string) $kh2->DPHKH1->VetaB3['zakl_dane1'], 'KH B.3 základ = 81,82');
        self::assertSame('17.18', (string) $kh2->DPHKH1->VetaB3['dan1'], 'KH B.3 daň = 17,18 (koeficient)');
    }

    /**
     * Override daňových konstant (tabulka tax_constants) reálně řídí výkazy a
     * bere se per ROK OBDOBÍ výkazu: limit KH snížený na 5 000 Kč pro rok 2097
     * pošle doklad 9 680 Kč vč. DPH do B.2 (jednotlivě), zatímco s defaultem
     * 10 000 by spadl do sumace B.3. Ostatní testy (rok 2099, bez override)
     * pinují defaultní chování — dohromady ověřeno, že konstanty nejsou globální
     * "aktuální", ale per rok období.
     */
    public function testTaxConstantsOverrideDrivesKhThresholdPerYear(): void
    {
        $pdo = $this->db->pdo();
        $data = \MyInvoice\Service\Tax\TaxConstants::forYear(2097);
        $data['kh_item_threshold'] = 5000;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([2097, json_encode($data)]);
        try {
            $vend = $this->client('Dodavatel override KH', $this->czId, 'CZ44444446', vendor: true);
            // 9 680 Kč vč. DPH — pod zákonným limitem 10 000, ale NAD overridnutým 5 000
            $this->purchase('P-2097-001', $vend, '40', false, 'invoice', '2097-04-10', '2097-04-10', [[8000, 1680, 21]]);
            // 3 630 Kč — pod oběma limity → sumace B.3
            $this->purchase('P-2097-002', $vend, '40', false, 'invoice', '2097-04-11', '2097-04-11', [[3000, 630, 21]]);

            // KH XML: doklad nad overridnutý limit jde do B.2 jednotlivě
            $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, 2097, 4)['xml']);
            $b2 = [];
            foreach ($kh->DPHKH1->VetaB2 as $v) $b2[] = (string) $v['zakl_dane1'];
            $this->assertSame(['8000.00'], $b2, 'override limitu 5000: doklad 9680 vč. DPH → B.2 jednotlivě');
            $this->assertSame('3000.00', (string) $kh->DPHKH1->VetaB3['zakl_dane1'], 'menší doklad zůstává v sumaci B.3');

            // Kniha DPH ukazuje efektivní sekce dle TÉHOŽ override (sdílená logika)
            $book = $this->book->build($this->supplierId, 2097, 4);
            $khCol = [];
            foreach ($book['sections'] as $s) {
                foreach ($s['rows'] as $r) $khCol[$r['original_doc_number']] = $r['kh_section'];
            }
            $this->assertSame('B.2', $khCol['P-2097-001'], 'Kniha DPH: sloupec KH respektuje override limitu');
            $this->assertSame('B.3', $khCol['P-2097-002']);
        } finally {
            // tax_constants je GLOBÁLNÍ tabulka (žádný tenant scope) → uklidit vždy
            $pdo->prepare('DELETE FROM tax_constants WHERE year = 2097')->execute();
        }
    }

    /**
     * Audit 2026-07 (fix 1): KH oddíl B.1 (tuzemský reverse charge — odběratel) MUSÍ
     * mít atribut 'duzp' (ne 'dppd' — to XSD u VetaB1 nezná) a vykázat SAMOVYMĚŘENOU
     * daň (dan1), ne jen základ. B.1 s atributem 'dppd' a bez daně neprojde XSD validací.
     */
    public function testReverseChargeB1UsesDuzpAndReportsSelfAssessedTax(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel RC stavební', $this->czId, 'CZ22222220', vendor: true);

        // Tuzemský RC příjemce (kód 5): dodavatel fakturuje bez DPH, příjemce si daň
        // samovyměří 21 %. Základ 50 000 → samovyměřená daň 10 500.
        $this->purchase('P-2099-B1', $vend, '5', false, 'invoice', $d(10), $d(10), [[50000, 0, 21]]);

        $khResult = $this->kh->build($this->supplierId, self::YEAR, self::MONTH);
        $kh = new \SimpleXMLElement($khResult['xml']);
        $b1 = $kh->DPHKH1->VetaB1;
        $this->assertCount(1, $b1, 'B.1: tuzemský RC příjemce (kód 5)');
        // Datum MUSÍ být v atributu 'duzp' (XSD VetaB1), NE v 'dppd'.
        $this->assertSame('10.06.2099', (string) $b1[0]['duzp'], 'B.1 datum patří do atributu duzp');
        $this->assertSame('', (string) $b1[0]['dppd'], 'B.1 NESMÍ mít dppd (XSD ho u VetaB1 nezná)');
        // Samovyměřená daň 50000 × 21 % = 10500 (dříve se nevykazovala vůbec).
        $this->assertSame('50000.00', (string) $b1[0]['zakl_dane1'], 'B.1 základ 21 %');
        $this->assertSame('10500.00', (string) $b1[0]['dan1'], 'B.1 samovyměřená daň 21 %');

        // Celé KH XML musí projít XSD validací MFČR — B.1 s 'dppd' / bez 'duzp' by neprošlo.
        $res = (new \MyInvoice\Service\Validation\XmlSchemaValidator())->validate($khResult['xml'], 'dphkh1');
        $this->assertNotSame('failed', $res['status'],
            'KH XML s B.1 musí projít XSD validací: ' . implode('; ', $res['errors']));
    }

    /**
     * Audit 2026-07 (fix 2): kvartální období KH musí končit posledním dnem
     * KVARTÁLU (quarter*3), ne posledním dnem předaného měsíce. build(..., 4,
     * 'quarterly') = Q2 (duben–červen) musí zahrnout i květen a červen.
     */
    public function testKhQuarterlyPeriodSpansWholeQuarter(): void
    {
        $cust = $this->client('Odběratel KH Q2', $this->czId, 'CZ70707075', customer: true);
        // Tři tuzemská plnění pod limitem (A.5 sumace) v dubnu/květnu/červnu.
        $this->sale('2099049101', $cust, '1', false, sprintf('%04d-04-10', self::YEAR), sprintf('%04d-04-10', self::YEAR), [[5000, 1050, 21]]);
        $this->sale('2099059101', $cust, '1', false, sprintf('%04d-05-10', self::YEAR), sprintf('%04d-05-10', self::YEAR), [[6000, 1260, 21]]);
        $this->sale('2099069101', $cust, '1', false, sprintf('%04d-06-10', self::YEAR), sprintf('%04d-06-10', self::YEAR), [[7000, 1470, 21]]);

        // MONTH=4 (duben) + 'quarterly' → Q2. Konec období musí být 30.6., ne 30.4.
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, 4, 'quarterly')['xml']);
        $this->assertSame('18000.00', (string) $kh->DPHKH1->VetaA5['zakl_dane1'],
            'KH Q2: A.5 musí zahrnout duben+květen+červen (5000+6000+7000), ne jen duben');
    }

    /**
     * Audit 2026-07 (fix 2): totéž pro Souhrnné hlášení — kvartální rozsah musí
     * pokrýt celý kvartál, ne jen předaný měsíc.
     */
    public function testShQuarterlyPeriodSpansWholeQuarter(): void
    {
        $skEu = (int) ($this->db->pdo()->query("SELECT COALESCE(is_eu,0) FROM countries WHERE iso2='SK' LIMIT 1")->fetchColumn() ?: 0);
        if ($skEu !== 1) {
            $this->markTestSkipped('SK není v countries označeno jako EU — SH test přeskočen.');
        }
        $euCust = $this->client('EU odběratel SH Q2', $this->skId, 'SK7654321', customer: true);
        // Služby do JČS (kód 22 → SHV typ 3) v dubnu/květnu/červnu.
        $this->sale('2099043201', $euCust, '22', false, sprintf('%04d-04-10', self::YEAR), sprintf('%04d-04-10', self::YEAR), [[10000, 0, 0]]);
        $this->sale('2099053201', $euCust, '22', false, sprintf('%04d-05-10', self::YEAR), sprintf('%04d-05-10', self::YEAR), [[20000, 0, 0]]);
        $this->sale('2099063201', $euCust, '22', false, sprintf('%04d-06-10', self::YEAR), sprintf('%04d-06-10', self::YEAR), [[30000, 0, 0]]);

        $sh = $this->shv->build($this->supplierId, self::YEAR, 4, 'quarterly');
        $this->assertEqualsWithDelta(60000, $sh['summary']['total_amount'], 0.01,
            'SH Q2: musí zahrnout duben+květen+červen (10000+20000+30000), ne jen duben');
    }

    /**
     * Audit 2026-07 (fix 6): KH kod_pred_pl se čte z klasifikace, ne natvrdo '5'.
     * Tuzemský RC (kód 5 = stavební/montážní práce, seed kod_pred_pl='4') musí mít
     * na VetaB1 kod_pred_pl='4' (§92e), ne blanket '5' (odpad/šrot §92c).
     */
    public function testDomesticReverseChargeUsesClassificationKodPredPl(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel stavební práce', $this->czId, 'CZ22222220', vendor: true);

        // Tuzemský RC příjemce (kód 5 → seed kod_pred_pl='4').
        $this->purchase('P-2099-KPP', $vend, '5', false, 'invoice', $d(10), $d(10), [[9000, 0, 21]]);

        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $this->assertCount(1, $kh->DPHKH1->VetaB1, 'B.1: tuzemský RC příjemce');
        $this->assertSame('4', (string) $kh->DPHKH1->VetaB1[0]['kod_pred_pl'],
            'kod_pred_pl musí přijít z klasifikace (4 = stavební práce §92e), ne natvrdo 5');
    }

    /**
     * Audit 2026-07 (fix 7): Souhrnné hlášení — Řecko se vykazuje DPH kódem 'EL',
     * ne ISO 'GR'. Platí pro k_stat i prefix VAT ID (VIES používá 'EL').
     */
    public function testShGreeceReportedAsElNotGr(): void
    {
        $grId = $this->countryId('GR');
        if ($grId === 0) {
            $this->markTestSkipped('Země GR není v číselníku countries.');
        }
        $grEu = (int) ($this->db->pdo()->query("SELECT COALESCE(is_eu,0) FROM countries WHERE iso2='GR' LIMIT 1")->fetchColumn() ?: 0);
        if ($grEu !== 1) {
            $this->markTestSkipped('GR není označeno jako EU — SH test přeskočen.');
        }
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        // Řecký odběratel, VAT ID BEZ prefixu → normalizeVatId prefix doplní (musí být EL).
        $euCust = $this->client('Řecký odběratel', $grId, '123456789', customer: true);
        // Poskytnutí služby do JČS (kód 22 → SHV typ 3).
        $this->sale('2099067701', $euCust, '22', false, $d(10), $d(10), [[15000, 0, 0]]);

        $sh = $this->shv->build($this->supplierId, self::YEAR, self::MONTH);
        $xml = new \SimpleXMLElement($sh['xml']);
        $veta = $xml->DPHSHV->VetaR;
        $this->assertCount(1, $veta, 'SH: jeden řádek pro řeckého odběratele');
        $this->assertSame('EL', (string) $veta[0]['k_stat'], 'k_stat musí být DPH kód EL, ne ISO GR');
        $this->assertSame('EL123456789', (string) $veta[0]['c_vat'], 'VAT ID prefix musí být EL, ne GR');
    }

    /**
     * Audit 2026-07 (fix 8): práh 10 000 Kč je „nad 10 000" = ostře více (§101e).
     * Doklad přesně 10 000 Kč vč. DPH patří do sumace A.5/B.3, ne jednotlivě A.4/B.2 —
     * v KH i v Knize DPH (efektivní sekce).
     */
    public function testExactly10000ThresholdGoesToSummarySection(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $vend = $this->client('Dodavatel práh 10k', $this->czId, 'CZ22222220', vendor: true);
        // Přesně 10 000 Kč vč. DPH (8264 + 1736), dodavatel s DIČ.
        $this->purchase('P-2099-10K', $vend, '40', false, 'invoice', $d(10), $d(10), [[8264, 1736, 21]]);

        // KH: přesně na prahu → B.3 (sumace), NE B.2.
        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $this->assertCount(0, $kh->DPHKH1->VetaB2, 'přesně 10 000 nepatří do B.2 (jednotlivě)');
        $this->assertSame('8264.00', (string) $kh->DPHKH1->VetaB3['zakl_dane1'], 'přesně 10 000 → sumace B.3');

        // Kniha DPH: efektivní KH sekce dokladu = B.3.
        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $khByDoc = [];
        foreach ($book['sections'] as $s) {
            foreach ($s['rows'] as $r) {
                if (!empty($r['original_doc_number'])) $khByDoc[$r['original_doc_number']] = $r['kh_section'];
            }
        }
        $this->assertSame('B.3', $khByDoc['P-2099-10K'] ?? null, 'Kniha DPH: přesně 10 000 → B.3, ne B.2');
    }

    /**
     * Audit 2026-07 (fix 9): kvartální souhrnné hlášení obsahující dodání zboží do
     * JČS musí varovat, že § 102 odst. 6 ZDPH vyžaduje MĚSÍČNÍ podání (kvartál je jen
     * pro výhradně služby).
     */
    public function testShQuarterlyWithGoodsWarnsMonthlyRequired(): void
    {
        $skEu = (int) ($this->db->pdo()->query("SELECT COALESCE(is_eu,0) FROM countries WHERE iso2='SK' LIMIT 1")->fetchColumn() ?: 0);
        if ($skEu !== 1) {
            $this->markTestSkipped('SK není v countries označeno jako EU — SH test přeskočen.');
        }
        $euCust = $this->client('EU odběratel zboží Q', $this->skId, 'SK7654321', customer: true);
        // Dodání ZBOŽÍ do JČS (kód 20 → sh_type 0) v Q2.
        $this->sale('2099059201', $euCust, '20', false, sprintf('%04d-05-10', self::YEAR), sprintf('%04d-05-10', self::YEAR), [[10000, 0, 0]]);

        $sh = $this->shv->build($this->supplierId, self::YEAR, 4, 'quarterly');
        $joined = implode(' | ', $sh['warnings']);
        $this->assertStringContainsString('§ 102 odst. 6', $joined,
            'kvartální SH se zbožím musí varovat na nutnost měsíčního podání');

        // Kontrola: služby-only kvartál (kód 22) NESMÍ varovat — Q3 jen se službami.
        $euCust2 = $this->client('EU odběratel služby Q', $this->skId, 'SK7654322', customer: true);
        $this->sale('2099089202', $euCust2, '22', false, sprintf('%04d-08-10', self::YEAR), sprintf('%04d-08-10', self::YEAR), [[5000, 0, 0]]);
        $shServicesOnly = $this->shv->build($this->supplierId, self::YEAR, 8, 'quarterly'); // Q3 — jen služby
        $this->assertStringNotContainsString('§ 102 odst. 6', implode(' | ', $shServicesOnly['warnings']),
            'kvartál jen se službami nesmí varovat');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function countryId(string $iso2): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$iso2]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function client(string $name, int $countryId, ?string $dic, bool $customer = false, bool $vendor = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[$vendor ? 'vendors' : 'customers'][] = $id;
        return $id;
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function sale(string $varsymbol, int $clientId, ?string $code, bool $rc, string $issue, string $tax, array $items): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, ?, "issued", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $clientId, $issue, $tax, $issue,
            $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->insertItems('invoice_items', 'invoice_id', $id, $items);
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function purchase(string $number, int $vendorId, ?string $code, bool $rc, string $kind, string $issue, ?string $tax, array $items, bool $isFixedAsset = false, string $vatDeduction = 'full', float $vatDeductionPercent = 100.0, ?int $currencyId = null, ?float $exchangeRate = null): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, "received", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $kind, $issue, $tax, $issue, $issue,
            $currencyId ?? $this->currencyId, $exchangeRate, $rc ? 1 : 0, $base, $vat, $with, $code, $isFixedAsset ? 1 : 0, $vatDeduction, $vatDeductionPercent, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->insertItems('purchase_invoice_items', 'purchase_invoice_id', $id, $items);
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items
     * @return array{0:float,1:float,2:float} [base, vat, with]
     */
    private function sumItems(array $items): array
    {
        $base = 0.0; $vat = 0.0;
        foreach ($items as $it) { $base += $it[0]; $vat += $it[1]; }
        return [$base, $vat, $base + $vat];
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items
     */
    private function insertItems(string $table, string $fk, int $id, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => $it) {
            [$base, $vat, $snapshot] = $it;
            $stmt->execute([$id, $base, $this->vatRateId, $snapshot, $base, $vat, $base + $vat, $i]);
        }
    }
}
