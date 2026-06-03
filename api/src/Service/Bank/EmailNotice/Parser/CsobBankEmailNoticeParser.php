<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class CsobBankEmailNoticeParser implements BankEmailNoticeParserInterface
{
    private EmailNoticeTextNormalizer $normalizer;

    public function __construct(?EmailNoticeTextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new EmailNoticeTextNormalizer();
    }

    public function key(): string
    {
        return 'csob';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'ČSOB - Moje info Avízo',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'noreply@csob.cz',
            subjectPattern: 'Moje\\s+info\\s+-\\s+Avízo|Moje\\s+info\\s+-\\s+Avizo',
            bodyPattern: 'Parametry\\s+platby',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!SenderDomain::matches($message->sender, 'csob.cz')) {
            return false;
        }

        $subject = $this->compact(mb_strtolower($message->subject, 'UTF-8'));
        if (
            !str_contains($subject, 'moje info')
            || (!str_contains($subject, 'avízo') && !str_contains($subject, 'avizo'))
        ) {
            return false;
        }

        $text = $this->compact(mb_strtolower($this->normalizer->normalize($message->text), 'UTF-8'));
        return str_contains($text, 'parametry platby')
            && (str_contains($text, 'vaše čsob') || str_contains($text, 'vase csob') || str_contains($text, 'čsob'))
            && (str_contains($text, 'částka') || str_contains($text, 'castka'));
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizer->normalize($message->text);

        $recipientAccount = $this->required(
            $text,
            '/(?:^|\R)\s*Účet\s*\R\s*(?<value>[0-9\-]+\/[0-9]{4})/u',
            'cílový účet',
        );
        $counterpartyAccount = $this->optional(
            $text,
            '/(?:^|\R)\s*Účet\s+protistrany\s*\R\s*(?<value>[0-9\-]+\/[0-9]{4})/u',
        );
        $counterpartyName = $this->optional(
            $text,
            '/(?:^|\R)\s*Název\s+protistrany\s*\R\s*(?<value>[^\r\n]+)/u',
        );
        $postedAt = $this->required(
            $text,
            '/(?:^|\R)\s*Datum\s+účtování\s*\R\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/u',
            'datum účtování',
        );
        $amountCurrency = $this->match(
            $text,
            '/(?:^|\R)\s*Částka\s*\R\s*(?<amount>[+\-]?[0-9 ]+,[0-9]{2})\s*(?<currency>[A-Z]{3})/u',
        );
        if ($amountCurrency === null) {
            throw new \RuntimeException('ČSOB parser nenašel částku a měnu.');
        }
        $variableSymbol = $this->optional(
            $text,
            '/(?:^|\R)\s*Variabilní\s+symbol\s*\R\s*(?<value>[0-9]+)/u',
        );
        $constantSymbol = $this->optional(
            $text,
            '/(?:^|\R)\s*Konstantní\s+symbol\s*\R\s*(?<value>[0-9]+)/u',
        );

        [$cpAccount, $cpBank] = $this->splitAccount((string) $counterpartyAccount);

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->parseAmount((string) $amountCurrency['amount']),
            currency: strtoupper((string) $amountCurrency['currency']),
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $recipientAccount,
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $counterpartyName,
            constantSymbol: $constantSymbol,
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
            throw new \RuntimeException("ČSOB parser nenašel {$label}.");
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
        $value = str_replace(["\xc2\xa0", ' ', '+'], '', trim($value));
        $value = str_replace(',', '.', $value);
        return (float) $value;
    }

    private function parseDate(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        foreach (['d.m.Y', 'd. m. Y'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }
        throw new \RuntimeException('ČSOB parser nenašel validní datum účtování.');
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
        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    private function compact(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
