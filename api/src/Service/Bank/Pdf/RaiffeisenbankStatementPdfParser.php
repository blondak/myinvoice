<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use Psr\Log\LoggerInterface;

/**
 * Parser PDF výpisu od Raiffeisenbank (běžný i spořicí účet). Text extrahuje registry
 * (Smalot\PdfParser), tahle třída ho rozparsuje deterministicky (žádné AI — finanční
 * data). Protějšek {@see CreditasStatementPdfParser}/{@see KbStatementPdfParser}.
 *
 * ⚠️ RB má oproti ostatním bankám ODLIŠNÝ číselný formát: desetinný oddělovač je TEČKA
 * a oddělovač tisíců MEZERA („3 016 177.96"), ne čárka. `num()` to reflektuje.
 *
 * Layout je „vertikální" — každé pole transakce na vlastním fyzickém řádku. Kotva
 * transakce = DVOJICE po sobě jdoucích řádků s celým datem (Datum zaúčtování + Valuta),
 * za nimi kód transakce (bankovní reference). Běžný zůstatek se u řádků NEuvádí, jen
 * sloupec Částka (se znaménkem; debet „-"), volitelně se sufixem měny („-15 029.00 CZK").
 * VS/KS/SS jsou uvedené labelovaně („VS:12345") i jako samostatný číselný sloupec.
 *
 * Self-check: součet transakcí musí sedět na `curr_balance - prev_balance` z hlavičky
 * (na haléř přesně — nativní PDF text, ne OCR) — jinak parse() vyhodí, ať se špatně
 * přečtená finanční data nikdy neuloží.
 */
final class RaiffeisenbankStatementPdfParser implements BankStatementPdfParserInterface
{
    /** Částka: volitelné znaménko, tisíce oddělené mezerou/NBSP, TEČKA desetinná. */
    private const AMOUNT = '-?\d{1,3}(?:[\x{00A0} ]\d{3})*\.\d{2}';

    /** Hlavičkové zůstatky/součty: číslo s mezerami tisíců a tečkou (bez povinného znaménka). */
    private const MONEY_H = '([\d\x{00A0} ]+\.\d{2})';

    /**
     * Řádky, které v tabulce transakcí NEJSOU transakce — opakovaná záhlaví/hlavička
     * výpisu (na multipage se tisknou na každé stránce znovu) a patička. Kotvení slice
     * na dvojici dat je většinu z nich odfiltruje samo, tohle je pojistka na multipage.
     */
    private const SKIP_LINE_PATTERNS = [
        '/^Výpis pohybů$/u',
        '/^Datum Kategorie transakce/u',
        '/^Valuta Číslo protiúčtu/u',
        '/^Kód transakceNázev protiúčtu/u',
        '/^Strana \d+\/\d+$/u',
        '/^Raiffeisenbank a\.s\./u',
        '/^Přehled$/u',
        '/^Číslo účtu:/u',
        '/^Název účtu:/u',
        '/^IBAN:/u',
        '/^BIC:/u',
        '/^Počáteční zůstatek:/u',
        '/^Konečný zůstatek:/u',
        '/^Příjmy celkem:/u',
        '/^Výdaje celkem:/u',
        '/^Pohledávky po splatnosti:/u',
        '/^Poplatky celkem:/u',
        '/^Výpis z běžného účtu/u',
        '/^Výpis ze spořicího účtu/u',
        '/^Pořadové č\. výpisu:/u',
        '/^[A-Z]\d{6,} v[\d.]+ •/u', // technický identifikátor stránky „P0000778 v7.0 • …"
    ];

    /** Za tímto řádkem už tabulka transakcí není (poučení o pojištění vkladů). */
    private const END_LINE_PATTERNS = [
        '/^Zpráva pro klienta$/u',
        '/^Vklad na tomto účtu podléhá/u',
    ];

    /**
     * Hodnoty sloupce „Typ transakce". Když u dokladu chybí Název protiúčtu, tiskne RB
     * hned za číslem protiúčtu právě Typ transakce (např. odchozí „Jednorázová úhrada")
     * — nesmí se zaměnit za název protistrany. Když název existuje (příchozí úhrada,
     * inkaso), stojí ZA účtem on a typ je až u částky, takže tento seznam se neuplatní.
     */
    private const TRANSACTION_TYPES = [
        'jednorázová úhrada',
        'příchozí úhrada',
        'odchozí úhrada',
        'trvalý příkaz',
        'kladný úrok',
        'záporný úrok',
        'daň z úroků',
        'inkaso',
        'platba kartou',
        'výběr',
        'vklad',
        'poplatek',
    ];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function key(): string
    {
        return 'raiffeisenbank';
    }

    public function supports(string $text): bool
    {
        // RB-specifické markery: název banky v patičce + BIC RZBCCZPP / doména rb.cz.
        return str_contains($text, 'Raiffeisenbank')
            && (str_contains($text, 'RZBCCZPP') || str_contains($text, 'www.rb.cz'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $transactions = $this->parseTransactionsFromText($text);

        // Self-check: součet transakcí musí souhlasit s pohybem zůstatku na haléř přesně.
        $sum = 0.0;
        foreach ($transactions as $tx) $sum += (float) $tx['amount'];
        $expected = round($header['curr_balance'] - $header['prev_balance'], 2);
        if (abs(round($sum, 2) - $expected) > 0.01) {
            throw new \RuntimeException(sprintf(
                'Raiffeisenbank PDF: součet transakcí (%.2f) nesedí na změnu zůstatku dle hlavičky (%.2f). Parsování zamítnuto.',
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
        if (!preg_match('/Číslo účtu:\s*([\d-]+)\/(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('Raiffeisenbank PDF: chybí "Číslo účtu:" v hlavičce.');
        }
        $accountNumber = $m[1];

        $currency = preg_match('/Číslo účtu:\s*[\d-]+\/\d{4}\s+([A-Z]{3})/u', $text, $m) ? $m[1] : 'CZK';

        // Období: „za období: <č.výpisu> D. M. YYYY - D. M. YYYY" (extrakce sléva pořadové
        // číslo výpisu před první datum). statement_date = konec období.
        if (!preg_match('/za období:\s*(?:\d+\s+)?\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s*-\s*(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('Raiffeisenbank PDF: chybí "za období:" v hlavičce.');
        }
        $statementDate = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);

        // Pořadové číslo výpisu = celé číslo mezi „za období:" a první datem (viz výše).
        // Rozlišení od dne: číslo výpisu je následované MEZEROU a pak dnem, den je následovaný
        // tečkou — tj. `(\d+)\s+\d` matchne jen samostatné pořadové číslo, ne den z data.
        $statementNumber = preg_match('/za období:\s*(\d+)\s+\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s*-/u', $text, $m) ? $m[1] : '';

        if (!preg_match('/Počáteční zůstatek:\s*' . self::MONEY_H . '/u', $text, $m)) {
            throw new \RuntimeException('Raiffeisenbank PDF: chybí "Počáteční zůstatek:" v hlavičce.');
        }
        $prevBalance = $this->num($m[1]);

        if (!preg_match('/Konečný zůstatek:\s*' . self::MONEY_H . '/u', $text, $m)) {
            throw new \RuntimeException('Raiffeisenbank PDF: chybí "Konečný zůstatek:" v hlavičce.');
        }
        $currBalance = $this->num($m[1]);

        $creditTotal = preg_match('/Příjmy celkem:\s*' . self::MONEY_H . '/u', $text, $m) ? abs($this->num($m[1])) : 0.0;
        $debitTotal  = preg_match('/Výdaje celkem:\s*' . self::MONEY_H . '/u', $text, $m) ? abs($this->num($m[1])) : 0.0;

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
        $raw = preg_split('/\r\n|\n|\r/', $text) ?: [];

        // Očistit řádky (NBSP→mezera, ořez okrajů; VNITŘNÍ taby zachovat — slepené sloupce),
        // odfiltrovat opakovaná záhlaví/patičky a useknout tabulku na koncovém markeru.
        $lines = [];
        foreach ($raw as $r) {
            $t = trim((string) preg_replace('/\x{00A0}/u', ' ', (string) $r));
            if ($t === '') continue;
            if ($this->isEndLine($t)) break;
            if ($this->isSkipLine($t)) continue;
            $lines[] = $t;
        }

        // Kotva transakce = dvojice po sobě jdoucích řádků s celým datem (Datum + Valuta).
        $starts = [];
        $n = count($lines);
        for ($i = 0; $i + 1 < $n; $i++) {
            if ($this->isDateLine($lines[$i]) && $this->isDateLine($lines[$i + 1])) {
                $starts[] = $i;
            }
        }

        $rows = [];
        $cnt = count($starts);
        for ($k = 0; $k < $cnt; $k++) {
            $from = $starts[$k];
            $to = ($k + 1 < $cnt) ? $starts[$k + 1] : $n;
            $row = $this->parseSlice(array_slice($lines, $from, $to - $from));
            if ($row !== null) $rows[] = $row;
        }
        return $rows;
    }

    private function isEndLine(string $line): bool
    {
        foreach (self::END_LINE_PATTERNS as $pat) {
            if (preg_match($pat, $line)) return true;
        }
        return false;
    }

    private function isSkipLine(string $line): bool
    {
        foreach (self::SKIP_LINE_PATTERNS as $pat) {
            if (preg_match($pat, $line)) return true;
        }
        return false;
    }

    private function isDateLine(string $line): bool
    {
        return (bool) preg_match('/^\d{1,2}\.\s*\d{1,2}\.\s*\d{4}$/u', $line);
    }

    /**
     * @param list<string> $slice
     */
    private function parseSlice(array $slice): ?array
    {
        $n = count($slice);
        // slice[0] = datum zaúčtování, slice[1] = valuta (přeskočit).
        $postingDate = $this->parseDateCz($slice[0]);
        if ($postingDate === null) return null;

        $idx = 2;
        // Kód transakce (bankovní reference) — čistě číselný řádek hned za daty.
        $bankRef = null;
        if ($idx < $n && preg_match('/^\d{6,}$/', $slice[$idx])) {
            $bankRef = $slice[$idx];
            $idx++;
        }

        // Částka = POSLEDNÍ peněžní hodnota (s tečkou) ve zbytku slice; jen ona má desetinná
        // místa (symboly/reference jsou celočíselné), takže „poslední s .dd" je jednoznačné.
        $amount = null;
        for ($j = $idx; $j < $n; $j++) {
            if (preg_match_all('/' . self::AMOUNT . '/u', $slice[$j], $mm)) {
                $amount = $this->num($mm[0][count($mm[0]) - 1]);
            }
        }
        if ($amount === null) {
            $this->logger->warning('Raiffeisenbank PDF parser: transakce bez rozpoznané částky, přeskočeno', ['slice' => $slice]);
            return null;
        }

        $account = null;
        $bankCode = null;
        $accountIdx = null;
        $vs = null; $ks = null; $ss = null;
        $counterpartyName = null;
        $texts = [];

        for ($j = $idx; $j < $n; $j++) {
            // Odstranit částku (+ volitelný sufix měny) z řádku pro další zpracování.
            $line = trim((string) preg_replace('/' . self::AMOUNT . '(?:\s*[A-Z]{3})?/u', '', $slice[$j]));
            if ($line === '') continue;

            // Číslo protiúčtu — samostatný řádek „<číslo>/<kód banky (4 místa)>" (kotveno na
            // celý řádek, ať se „04/2026" z poznámky nespletlo s účtem).
            if ($accountIdx === null && preg_match('#^((?:\d{1,6}-)?\d{2,10})/(\d{4})$#u', $line, $am)) {
                $account = $am[1];
                $bankCode = $am[2];
                $accountIdx = $j;
                continue;
            }

            // VS/KS/SS labelované („VS:12345") — kdekoli v řádku.
            if (preg_match_all('/\b(VS|KS|SS):(\d+)/u', $line, $sm, PREG_SET_ORDER)) {
                foreach ($sm as $s) {
                    $val = ltrim($s[2], '0') ?: null;
                    if ($s[1] === 'VS') $vs = $val;
                    elseif ($s[1] === 'KS') $ks = $val;
                    else $ss = $val;
                }
                $line = trim((string) preg_replace('/\b(VS|KS|SS):\d+/u', '', $line));
                if ($line === '') continue;
            }

            // Název protiúčtu = řádek těsně ZA číslem protiúčtu (má-li písmeno a není to
            // hodnota sloupce Typ transakce — ta stojí za účtem jen když název chybí).
            if ($accountIdx !== null && $j === $accountIdx + 1 && $counterpartyName === null
                && preg_match('/\p{L}/u', $line)
                && !in_array(mb_strtolower($line), self::TRANSACTION_TYPES, true)) {
                $counterpartyName = $line;
                continue;
            }

            foreach (explode("\t", $line) as $cell) {
                $cell = trim($cell);
                if ($cell === '') continue;
                if (preg_match('/^\d+$/', $cell)) continue;      // samostatný číselný sloupec VS/KS/SS
                if (preg_match('/^[A-Z]{3}$/', $cell)) continue;  // osamocený kód měny
                $texts[] = $cell;
            }
        }

        $description = $texts !== [] ? mb_substr(implode(' | ', $texts), 0, 255) : null;

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

    /** „4. 5. 2026" / „31. 5. 2026" → „2026-05-04" (mezery kolem teček tolerovány). */
    private function parseDateCz(string $d): ?string
    {
        if (!preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/u', trim($d), $m)) return null;
        $day = (int) $m[1]; $month = (int) $m[2]; $year = (int) $m[3];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) return null;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** „3 016 177.96" / „-15 029.00" → float (mezery/NBSP tisíce, TEČKA desetinná). */
    private function num(string $s): float
    {
        $s = str_replace(["\u{00A0}", ' ', "\t"], '', $s);
        return (float) $s;
    }
}
