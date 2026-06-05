<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;

abstract class AbstractBankEmailNoticeParser implements BankEmailNoticeParserInterface
{
    protected EmailNoticeTextNormalizer $normalizer;

    public function __construct(?EmailNoticeTextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new EmailNoticeTextNormalizer();
    }

    abstract public function defaultProvider(): ?BankEmailNoticeProvider;

    protected function normalizeText(string $text): string
    {
        return $this->normalizer->normalize($text);
    }

    protected function compact(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return array<string,string>|null
     */
    protected function match(string $text, string $pattern): ?array
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

    protected function required(string $text, string $pattern, string $label): string
    {
        $value = $this->optional($text, $pattern);
        if ($value === null) {
            throw new \RuntimeException($this->parserLabel() . " parser nenašel {$label}.");
        }
        return $value;
    }

    protected function optional(string $text, string $pattern): ?string
    {
        $m = $this->match($text, $pattern);
        if ($m === null || !isset($m['value'])) {
            return null;
        }
        return $this->cleanNullable($m['value']);
    }

    protected function parseAmount(string $value): float
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

    protected function parseDate(string $value, string $label = 'validní datum platby'): string
    {
        $value = $this->compact($value);
        foreach ([
            'd.m.Y H:i:s',
            'd.m.Y H:i',
            'd. m. Y H:i:s',
            'd. m. Y H:i',
            'd.m.Y',
            'd. m. Y',
            \DateTimeInterface::ATOM,
            \DateTimeInterface::RFC2822,
        ] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            throw new \RuntimeException($this->parserLabel() . " parser nenašel {$label}.");
        }
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function splitAccount(string $value): array
    {
        $value = $this->normalizeAccount($value);
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^(?<account>[0-9\-]+)\/(?<bank>[0-9]{4})$/', $value, $m) === 1) {
            return [$m['account'], $m['bank']];
        }
        return [$value, null];
    }

    protected function normalizeAccount(string $value): string
    {
        $value = trim($value);
        if (preg_match('/[0-9\-]+\/[0-9]{4}/', $value, $m) === 1) {
            return $m[0];
        }
        return $value;
    }

    protected function normalizeSymbol(string $value): string
    {
        $digits = $this->digitsOnly($value);
        $trimmed = ltrim($digits, '0');
        return $trimmed !== '' ? $trimmed : $digits;
    }

    protected function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    protected function cleanNullable(string $value): ?string
    {
        $value = $this->compact($value);
        if ($value === '' || in_array(strtoupper($value), ['N/A', 'NA'], true)) {
            return null;
        }
        return mb_substr($value, 0, 255);
    }

    protected function senderAllowed(string $sender, string $whitelist): bool
    {
        $whitelist = trim($whitelist);
        if ($whitelist === '') {
            return true;
        }
        $sender = strtolower($sender);
        foreach (preg_split('/[\s,;]+/', strtolower($whitelist)) ?: [] as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') {
                continue;
            }
            if ($sender === $allowed || str_contains($sender, '<' . $allowed . '>')) {
                return true;
            }
        }
        return false;
    }

    protected function normalizeCurrency(string $value): string
    {
        $v = trim($value);
        $upper = mb_strtoupper($v, 'UTF-8');
        $map = [
            'KČ' => 'CZK',
            'KC' => 'CZK',
            'CZK' => 'CZK',
            '€' => 'EUR',
            'EUR' => 'EUR',
            '$' => 'USD',
            'USD' => 'USD',
            '£' => 'GBP',
            'GBP' => 'GBP',
            'ZŁ' => 'PLN',
            'ZL' => 'PLN',
            'PLN' => 'PLN',
        ];
        if (isset($map[$upper])) {
            return $map[$upper];
        }
        if (isset($map[$v])) {
            return $map[$v];
        }
        $letters = preg_replace('/[^A-ZÁ-Ž]/u', '', $upper) ?? $upper;
        if (isset($map[$letters])) {
            return $map[$letters];
        }
        return $letters !== '' ? mb_substr($letters, 0, 3, 'UTF-8') : $upper;
    }

    protected function applyDirection(float $amount, string $direction): float
    {
        $direction = mb_strtolower(trim($direction), 'UTF-8');
        if ($direction === '') {
            return $amount;
        }
        if (preg_match('/odchoz|výdej|vydej|debet|odepsán|odepsan|outgoing/u', $direction) === 1) {
            return -abs($amount);
        }
        return abs($amount);
    }

    abstract protected function parserLabel(): string;
}
