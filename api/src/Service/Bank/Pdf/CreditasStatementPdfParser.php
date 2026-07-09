<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use Psr\Log\LoggerInterface;

/**
 * Parser PDF výpisu „VÝPIS Z BĚŽNÉHO ÚČTU" od Banky CREDITAS (Creditas neposkytuje
 * GPC/ABO export, jen PDF a interní API). Text extrahuje registry (Smalot\PdfParser),
 * tahle třída ho jen rozparsuje — deterministicky, žádné AI (finanční data).
 *
 * Layout (ověřeno na reálných výpisech): hlavička výpisu s labely „Číslo účtu:",
 * „Období výpisu:", „Počáteční/Konečný zůstatek:", „Připsáno:/Odepsáno:", pak tabulka
 * transakcí, kde každý řádek zabírá VÍC fyzických textových řádků (banka/karta,
 * VS/KS/SS, volný text, částka) oddělených prázdnou "buňkou" (` `). Sloupce se v PDF
 * textovém streamu občas slepí tabulátorem, občas jen mezerou (nekonzistentní podle
 * délky obsahu) — parsování proto NEspoléhá na pozici v řádku, ale na tokeny hledané
 * kdekoli ve slice (VS:/KS:/SS:, číslo účtu/kód banky, maskovaná karta, částka).
 *
 * Self-check: součet transakcí musí sedět na `curr_balance - prev_balance` z hlavičky
 * (na haléř přesně — jde o nativní PDF text, ne OCR) — jinak parse() vyhodí, ať se
 * špatně přečtená finanční data nikdy neuloží.
 */
final class CreditasStatementPdfParser implements BankStatementPdfParserInterface
{
    private const SKIP_LINE_PATTERNS = [
        '/^VÝPIS Z BĚŽNÉHO ÚČTU$/u',
        '/^Zaúčtování$/u',
        '/^Provedení$/u',
        '/^Typ transakce$/u',
        '/^Číslo transakce$/u',
        '/^Číslo účtu \/ karty$/u',
        '/^Název$/u',
        '/^Detaily$/u',
        '/^Částka v [A-Z]{3}$/u',
        // Patička "Banka CREDITAS a.s., Sokolovská…" — POZOR: "Banka CREDITAS" samotné
        // (bez "a.s.,") je i legitimní protistrana u interních transakcí (např. "Převod
        // úroků" — bance se připisuje/odečítá úrok, counterparty_name = "Banka CREDITAS").
        // Kotvit na celý začátek patičky, ne jen prefix, ať se taková transakce nesmaže.
        '/^Banka CREDITAS a\.s\.,/u',
        '/^OR:\s/u',
        '/^Volejte zdarma/u',
        '/^Informace k pojištění/u',
        '/^Podrobnější přehled/u',
        '/územních samosprávných/u',
        '/garancnisystem\.cz/u',
    ];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function key(): string
    {
        return 'creditas';
    }

    public function supports(string $text): bool
    {
        return str_contains($text, 'VÝPIS Z BĚŽNÉHO ÚČTU')
            && (str_contains($text, 'Banka CREDITAS') || str_contains($text, 'creditas.cz') || str_contains($text, 'CTASCZ22'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $transactions = $this->parseTransactionsFromText($text);
        if ($transactions === []) {
            throw new \RuntimeException('Creditas PDF: nenalezena žádná transakce.');
        }

        // Self-check: součet transakcí musí souhlasit s pohybem zůstatku na haléř přesně
        // (nativní PDF text, ne OCR — žádná tolerance na "skoro sedí").
        $sum = 0.0;
        foreach ($transactions as $tx) $sum += (float) $tx['amount'];
        $expected = round($header['curr_balance'] - $header['prev_balance'], 2);
        if (abs(round($sum, 2) - $expected) > 0.01) {
            throw new \RuntimeException(sprintf(
                'Creditas PDF: součet transakcí (%.2f) nesedí na změnu zůstatku dle hlavičky (%.2f). Parsování zamítnuto.',
                $sum,
                $expected,
            ));
        }

        $currency = $header['currency'] ?? 'CZK';
        foreach ($transactions as &$tx) {
            $tx['currency'] = $currency;
        }
        unset($tx);
        unset($header['currency']);

        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * @return array{account_number:string, statement_date:string, statement_number:string,
     *   prev_balance:float, curr_balance:float, debit_total:float, credit_total:float, currency:?string}
     */
    public function parseHeaderFromText(string $text): array
    {
        $money = '(-?[\d \x{00A0}]+,\d{2})';

        if (!preg_match('/Číslo účtu:\s*([\d\-]+)\/(\d{3,4})/u', $text, $m)) {
            throw new \RuntimeException('Creditas PDF: chybí "Číslo účtu:" v hlavičce.');
        }
        $accountNumber = $m[1];

        if (!preg_match('/Období výpisu:\s*\d{1,2}\.\d{1,2}\.\d{4}\s*-\s*(\d{1,2}\.\d{1,2}\.\d{4})/u', $text, $m)) {
            throw new \RuntimeException('Creditas PDF: chybí "Období výpisu:" v hlavičce.');
        }
        $statementDate = $this->parseDateCz($m[1]) ?? date('Y-m-d');

        $statementNumber = preg_match('/Číslo výpisu:\s*(\d+)/u', $text, $m) ? $m[1] : '';
        $currency = preg_match('/Měna:\s*([A-Z]{3})/u', $text, $m) ? $m[1] : null;

        if (!preg_match('/Počáteční zůstatek:\s*' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('Creditas PDF: chybí "Počáteční zůstatek:" v hlavičce.');
        }
        $prevBalance = $this->num($m[1]);

        if (!preg_match('/Konečný zůstatek:\s*' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('Creditas PDF: chybí "Konečný zůstatek:" v hlavičce.');
        }
        $currBalance = $this->num($m[1]);

        $creditTotal = preg_match('/Připsáno:\s*' . $money . '/u', $text, $m) ? $this->num($m[1]) : 0.0;
        // Na PDF je "Odepsáno:" tištěno se záporným znaménkem, ale bank_statements.debit_total
        // je (stejně jako u GpcParser) kladná magnituda — UI šablona si znaménko doplňuje sama.
        $debitTotal = preg_match('/Odepsáno:\s*' . $money . '/u', $text, $m) ? abs($this->num($m[1])) : 0.0;

        return [
            'account_number'   => $accountNumber,
            'statement_date'   => $statementDate,
            'statement_number' => $statementNumber,
            'prev_balance'     => $prevBalance,
            'curr_balance'     => $currBalance,
            'debit_total'      => $debitTotal,
            'credit_total'     => $creditTotal,
            'currency'         => $currency,
        ];
    }

    /**
     * Testovací seam — vytěží transakce z prostého textu (bez PDF bytes / self-checku).
     *
     * @return list<array<string,mixed>>
     */
    public function parseTransactionsFromText(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];

        // Zpracovávat začneme až ZA prvním sloupcovým záhlavím tabulky ("Částka v CZK")
        // — vynechá adresní blok příjemce a info-box hlavičky, které předchází samotné
        // tabulce transakcí (ty parsuje parseHeaderFromText přes vlastní kotvící regexy).
        $startIdx = 0;
        foreach ($lines as $i => $ln) {
            if (preg_match('/^Částka v [A-Z]{3}$/u', trim($ln))) { $startIdx = $i + 1; break; }
        }
        $lines = array_slice($lines, $startIdx);

        // Rozsekat na "slice" = řádky jedné transakce, oddělené prázdnou buňkou (" "/"").
        // Header/footer šum (opakovaná záhlaví na dalších stránkách, patička banky)
        // je potřeba přeskočit i UVNITŘ tabulky — filtrujeme řádek po řádku.
        $slices = [];
        $current = [];
        foreach ($lines as $raw) {
            $t = trim((string) preg_replace('/\x{00A0}/u', ' ', $raw));
            if ($t === '') {
                if ($current !== []) { $slices[] = $current; $current = []; }
                continue;
            }
            if ($this->isSkipLine($t)) continue;
            $current[] = $t;
        }
        if ($current !== []) $slices[] = $current;

        $rows = [];
        foreach ($slices as $slice) {
            $row = $this->parseSlice($slice);
            if ($row !== null) $rows[] = $row;
        }
        return $rows;
    }

    private function isSkipLine(string $line): bool
    {
        foreach (self::SKIP_LINE_PATTERNS as $pat) {
            if (preg_match($pat, $line)) return true;
        }
        return false;
    }

    /**
     * @param list<string> $slice
     */
    private function parseSlice(array $slice): ?array
    {
        $idx = 0;
        $n = count($slice);

        // 1) Datum(y) + typ transakce.
        //    Shoda A (1 datum): "D.M.YYYY <typ>" na jednom řádku.
        //    Shoda B (2 data):  "D.M.YYYY" / "D.M.YYYY" / "<typ>" na 3 řádcích
        //                       (zaúčtování je vždy PRVNÍ datum — provedení druhé).
        if ($idx >= $n || !preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})\s*(.*)$/u', $slice[$idx], $m)) {
            return null; // slice bez data na začátku není transakční řádek
        }
        $postingDateRaw = $m[1];
        $idx++;
        if (trim($m[2]) === '') {
            // Datum bylo samotné → další řádek je druhé (provedení) datum, pak typ.
            if ($idx < $n && preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/u', $slice[$idx])) {
                $idx++; // provedení — neukládáme, tabulka nemá pro to sloupec
            }
            $idx++; // typ transakce (nepoužíváme — směr je ze znaménka částky)
        }
        $postingDate = $this->parseDateCz($postingDateRaw);
        if ($postingDate === null) return null;

        // 2) Číslo transakce (bankovní reference) — čistě číselný řádek.
        $bankRef = null;
        if ($idx < $n && preg_match('/^\d{6,}$/', $slice[$idx])) {
            $bankRef = $slice[$idx];
            $idx++;
        }

        // 3) Zbylé řádky (detaily) — hledáme částku (kdekoli, i slepenou na konci
        //    řádku s jiným obsahem), VS/KS/SS tokeny, číslo účtu/karty, volný text.
        $amount = null;
        $account = null;
        $bankCode = null;
        $vs = null; $ks = null; $ss = null;
        $texts = [];

        for (; $idx < $n; $idx++) {
            $line = $slice[$idx];

            // Částka může být na vlastním řádku, nebo slepená na konci řádku s jiným
            // obsahem (tab/mezera dle PDF layoutu) — vždy jde o POSLEDNÍ takový výskyt.
            if (preg_match('/^(.*?)[\t ]*(-?\d{1,3}(?:[\t ]?\d{3})*,\d{2})$/u', $line, $am)) {
                $amount = $this->num($am[2]);
                $line = trim($am[1]);
                if ($line === '') continue;
            }

            // Rozsekat zbytek na buňky přes tabulátor (sloupce se v PDF textu slepují tabem).
            foreach (preg_split('/\t/', $line) as $cell) {
                $cell = trim($cell);
                if ($cell === '') continue;

                // Maskovaná platební karta, volitelně + obchodník na stejné buňce.
                if (preg_match('/^(\d{6}\*{6}\d{4})\s*(.*)$/u', $cell, $cm)) {
                    if (trim($cm[2]) !== '') $texts[] = trim($cm[2]);
                    continue;
                }
                // Číslo účtu/kód banky, volitelně + zbytek (VS glued) na stejné buňce.
                if (preg_match('/^([\dA-Za-z][\dA-Za-z\-]{2,})\/(\d{3,4})\s*(.*)$/u', $cell, $acm)) {
                    $account = $acm[1];
                    $bankCode = $acm[2];
                    $cell = trim($acm[3]);
                    if ($cell === '') continue;
                }
                // VS:/KS:/SS: tokeny — může jich být víc v jedné buňce (mezerou oddělené).
                if (preg_match_all('/\b(VS|KS|SS):(\S*)/u', $cell, $tm, PREG_SET_ORDER)) {
                    foreach ($tm as $t) {
                        $val = ltrim(trim($t[2]), '0') ?: null;
                        if ($t[1] === 'VS') $vs = $val;
                        elseif ($t[1] === 'KS') $ks = $val;
                        else $ss = $val;
                    }
                    $cell = trim((string) preg_replace('/\b(VS|KS|SS):(\S*)/u', '', $cell));
                    if ($cell === '') continue;
                }
                if ($cell !== '') $texts[] = $cell;
            }
        }

        if ($amount === null) {
            $this->logger->warning('Creditas PDF parser: transakce bez rozpoznané částky, přeskočeno', ['slice' => $slice]);
            return null;
        }

        $counterpartyName = $texts[0] ?? null;
        $description = count($texts) > 1 ? mb_substr(implode(' | ', array_slice($texts, 1)), 0, 255) : null;

        return [
            'posted_at'            => $postingDate,
            'amount'               => round($amount, 2),
            'variable_symbol'      => $vs,
            'constant_symbol'      => $ks,
            'specific_symbol'      => $ss,
            'counterparty_account' => $account,
            'counterparty_bank'    => $bankCode,
            'counterparty_name'    => $counterpartyName !== null ? mb_substr($counterpartyName, 0, 190) : null,
            'description'          => $description,
            'bank_ref'             => $bankRef,
        ];
    }

    /** "1 155,94" / "-2 107 708,37" → float (mezery/NBSP jako oddělovač tisíců, čárka desetinná). */
    private function num(string $s): float
    {
        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace(',', '.', $s);
        return (float) $s;
    }

    /** "1.9.2025" → "2025-09-01", nebo null když formát nesedí. */
    private function parseDateCz(string $d): ?string
    {
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $d, $m)) return null;
        $day = (int) $m[1]; $month = (int) $m[2]; $year = (int) $m[3];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) return null;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
