<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rozdělení jména fyzické osoby (OSVČ) na jmeno/prijmeni pro EPO VetaP, vč. odstranění
 * akademických titulů (issue #200 — „MUDr. Josef Novák" nesmí dát jmeno="MUDr.").
 */
final class EpoSupplierBlockBuilderTest extends TestCase
{
    /**
     * @return iterable<string, array{0:string, 1:string, 2:string}>
     */
    public static function names(): iterable
    {
        yield 'prostý' => ['Josef Novák', 'Josef', 'Novák'];
        yield 'vedoucí titul' => ['MUDr. Josef Novák', 'Josef', 'Novák'];
        yield 'více vedoucích titulů' => ['prof. Ing. Jan Svoboda', 'Jan', 'Svoboda'];
        yield 'koncový titul za čárkou' => ['Ing. Jan Svoboda, CSc.', 'Jan', 'Svoboda'];
        yield 'vedoucí i koncový titul' => ['MUDr. Josef Novák, Ph.D.', 'Josef', 'Novák'];
        yield 'koncový titul bez tečky' => ['Jan Svoboda, MBA', 'Jan', 'Svoboda'];
        yield 'víceslovné příjmení' => ['Josef Karel Novák', 'Josef', 'Karel Novák'];
        yield 'jen jedno slovo' => ['Novák', 'Novák', 'Novák'];
        yield 'jen titul + jméno' => ['Bc. Anna', 'Anna', 'Anna'];
        yield 'extra mezery' => ['  Ing.   Petr   Dvořák ', 'Petr', 'Dvořák'];
        yield 'prázdné' => ['', '', ''];
    }

    #[DataProvider('names')]
    public function testSplitPersonNameStripsTitles(string $full, string $expJmeno, string $expPrijmeni): void
    {
        [$jmeno, $prijmeni] = EpoSupplierBlockBuilder::splitPersonName($full);
        $this->assertSame($expJmeno, $jmeno, "jmeno pro „{$full}\"");
        $this->assertSame($expPrijmeni, $prijmeni, "prijmeni pro „{$full}\"");
    }

    /** Fyzická osoba bez strukturovaných polí → fallback split company_name bez titulu (#200). */
    public function testFyzickaOsobaFallbackFromCompanyName(): void
    {
        $v = $this->fillFor(['taxpayer_type' => 'fo', 'company_name' => 'MUDr. Josef Novák']);
        $this->assertSame('Josef', $v->getAttribute('jmeno'));
        $this->assertSame('Novák', $v->getAttribute('prijmeni'));
        $this->assertSame('', $v->getAttribute('zkrobchjm'), 'fyzická osoba nemá zkrobchjm');
    }

    /** Strukturovaná pole (jako u jednatele s.r.o.) mají přednost před parsováním jména. */
    public function testFyzickaOsobaPrefersStructuredNames(): void
    {
        $v = $this->fillFor([
            'taxpayer_type' => 'fo',
            'company_name'  => 'MUDr. Josef Novák',
            'opr_jmeno'     => 'Josef',
            'opr_prijmeni'  => 'Novák',
        ]);
        $this->assertSame('Josef', $v->getAttribute('jmeno'));
        $this->assertSame('Novák', $v->getAttribute('prijmeni'));
    }

    /** Právnická osoba → celý název do zkrobchjm, jmeno/prijmeni prázdné. */
    public function testPravnickaOsobaUsesCompanyName(): void
    {
        $v = $this->fillFor(['taxpayer_type' => 'po', 'company_name' => 'ACME s.r.o.']);
        $this->assertSame('ACME s.r.o.', $v->getAttribute('zkrobchjm'));
        $this->assertSame('', $v->getAttribute('jmeno'));
    }

    /** @param array<string,mixed> $overrides */
    private function fillFor(array $overrides): \DOMElement
    {
        $supplier = array_merge([
            'financial_office_code' => '451', 'dic' => 'CZ1234567890', 'data_box_type' => 'F',
            'taxpayer_type' => 'fo', 'company_name' => '', 'street' => 'Hlavní 1', 'city' => 'Praha',
            'zip' => '11000', 'country_iso2' => 'CZ',
        ], $overrides);
        $dom = new \DOMDocument();
        $v = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($v, $supplier);
        return $v;
    }
}
