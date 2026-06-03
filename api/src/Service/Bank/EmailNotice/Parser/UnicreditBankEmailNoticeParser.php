<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class UnicreditBankEmailNoticeParser implements BankEmailNoticeParserInterface
{
    private EmailNoticeTextNormalizer $normalizer;

    public function __construct(?EmailNoticeTextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new EmailNoticeTextNormalizer();
    }

    public function key(): string
    {
        return 'unicredit';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'UniCredit Bank - Informace o pohybu na účtu',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'unicreditbank@unicreditgroup.cz noe@unicredit.eu',
            subjectPattern: 'Informace\\s+o\\s+pohybu\\s+na\\s+účtu|Informace\\s+o\\s+pohybu\\s+na\\s+uctu',
            bodyPattern: 'UniCredit\\s+Bank',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!SenderDomain::matches($message->sender, 'unicreditgroup.cz', 'unicredit.eu')) {
            return false;
        }

        $subject = $this->compact(mb_strtolower(str_replace('_', ' ', $message->subject), 'UTF-8'));
        if (
            !str_contains($subject, 'informace o pohybu na účtu')
            && !str_contains($subject, 'informace o pohybu na uctu')
        ) {
            return false;
        }

        $text = $this->compact(mb_strtolower($this->normalizer->normalize($message->text), 'UTF-8'));
        return (str_contains($text, 'variabilní symbol') || str_contains($text, 'variabilni symbol'))
            && (str_contains($text, 'číslo účtu protistrany') || str_contains($text, 'cislo uctu protistrany'))
            && str_contains($text, 'unicredit bank');
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizer->normalize($message->text);

        $recipientAccount = $this->required(
            $text,
            '/na\s+Va[šs]em\s+[úu][čc]tu\s+[čc]\.\s*(?<value>[0-9\-]+)/iu',
            'cílový účet',
        );
        $amountCurrency = $this->match($text, '/[ČC]ástka:\s*(?<amount>[+\-]?[0-9,. ]+)\s*(?<currency>[A-Z]{3})/u');
        if ($amountCurrency === null) {
            throw new \RuntimeException('UniCredit Bank parser nenašel částku a měnu.');
        }
        $counterpartyAccount = $this->optional(
            $text,
            '/[ČC]íslo\s+[úu][čc]tu\s+protistrany:\s*(?<value>[0-9\-]+\/[0-9]{4})/iu',
        );
        $counterpartyName = $this->optional(
            $text,
            '/N[áa]zev\s+[úu][čc]tu\s+protistrany:\s*(?<value>.*?)\s*Variabiln[íi]\s+symbol:/isu',
        );
        $variableSymbol = $this->required(
            $text,
            '/Variabiln[íi]\s+symbol:\s*(?<value>[0-9]+)/iu',
            'variabilní symbol',
        );
        $messageText = $this->optional(
            $text,
            '/Detaily\s+transakce:\s*(?<value>.*?)\s*Datum:/isu',
        );
        $postedAt = $this->required(
            $text,
            '/Datum:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?)/u',
            'datum',
        );

        [$cpAccount, $cpBank] = $this->splitAccount((string) $counterpartyAccount);

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol($variableSymbol),
            amount: $this->parseAmount((string) $amountCurrency['amount']),
            currency: strtoupper((string) $amountCurrency['currency']),
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $recipientAccount,
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $counterpartyName,
            message: $messageText,
        );
    }

    /**
     * @return array<string,string>|null
     */
    private function match(string $text, string $pattern): ?array
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }
        $out = [];
        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $out[$key] = trim((string) $value);
            }
        }
        return $out;
    }

    private function required(string $text, string $pattern, string $label): string
    {
        $value = $this->optional($text, $pattern);
        if ($value === null) {
            throw new \RuntimeException("UniCredit Bank parser nenašel {$label}.");
        }
        return $value;
    }

    private function optional(string $text, string $pattern): ?string
    {
        $m = $this->match($text, $pattern);
        if ($m === null || !isset($m['value'])) {
            return null;
        }
        return $this->cleanNullable($m['value']);
    }

    private function parseAmount(string $value): float
    {
        $value = preg_replace('/[^\d,.\-+]/u', '', trim($value)) ?? $value;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastDot > $lastComma) {
                $value = str_replace(',', '', $value);
            } else {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace(',', '.', $value);
        }

        $amount = (float) $value;
        return $negative ? -$amount : $amount;
    }

    private function parseDate(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd. m. Y H:i:s', 'd. m. Y H:i'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }
        throw new \RuntimeException('UniCredit Bank parser nenašel validní datum platby.');
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitAccount(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^(?<account>[0-9\-]+)\/(?<bank>[0-9]{4})$/', $value, $m) === 1) {
            return [$m['account'], $m['bank']];
        }
        return [$value, null];
    }

    private function normalizeSymbol(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $trimmed = ltrim($digits, '0');
        return $trimmed !== '' ? $trimmed : $digits;
    }

    private function cleanNullable(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '' || in_array(strtoupper($value), ['N/A', 'NA'], true)) {
            return null;
        }
        return mb_substr($value, 0, 255);
    }

    private function compact(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
