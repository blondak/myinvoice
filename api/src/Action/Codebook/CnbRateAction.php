<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use DateTimeImmutable;
use MyInvoice\Http\Json;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/codebooks/cnb-rate?currency=USD&date=2026-05-21
 *
 * Wrapper kolem CnbExchangeRateClient pro frontend (ExchangeRateInput.vue).
 * Vrací { rate, rate_date, fallback_used, source }.
 *
 * Pro currency=CZK vrací { rate: 1 }.
 * Pokud rate nelze získat (CNB feed nedostupný + cache prázdná) → 404.
 *
 * Bez auth restrikcí — endpoint je pod AuthMiddleware (session i bearer fungují).
 */
final class CnbRateAction
{
    public function __construct(
        private readonly CnbExchangeRateClient $cnb,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $currency = strtoupper(trim((string) ($q['currency'] ?? '')));
        $date     = (string) ($q['date'] ?? '');

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return Json::error($response, 'invalid_currency', 'currency musí být 3-znakový ISO 4217 kód', 400);
        }
        if ($date === '') {
            $date = date('Y-m-d');
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            return Json::error($response, 'invalid_date', 'date musí být ve formátu YYYY-MM-DD', 400);
        }

        if ($currency === 'CZK') {
            return Json::ok($response, [
                'rate'          => 1.0,
                'rate_date'     => $date,
                'fallback_used' => false,
                'source'        => 'cache',
            ]);
        }

        $result = $this->cnb->getRate($currency, $d);
        if ($result === null) {
            return Json::error($response, 'rate_not_found',
                "ČNB neposkytuje kurz pro {$currency} k {$date} (ani v 7-denním fallbacku). Zadej kurz ručně.", 404);
        }

        return Json::ok($response, $result);
    }
}
