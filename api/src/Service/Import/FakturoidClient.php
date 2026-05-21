<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Fakturoid API v3 client — BasicAuth s personal API token + slug.
 *
 * URL pattern: https://app.fakturoid.cz/api/v3/accounts/{slug}/...
 * Auth: BasicAuth(email, api_key)
 * User-Agent: REQUIRED header (jinak 403)
 *
 * Rate limit: 240 req/min hard, naše soft 200/min → throttle při >180.
 *
 * Endpoints:
 *   GET /subjects.json   (kontakty)
 *   GET /invoices.json   (vydané faktury)
 *   GET /expenses.json   (přijaté faktury)
 */
final class FakturoidClient
{
    private const API_BASE = 'https://app.fakturoid.cz/api/v3/accounts';
    private const USER_AGENT = 'MyInvoice.cz Import (radek@hulan.cz)';
    private const TIMEOUT = 30;
    private const RATE_LIMIT_THRESHOLD = 180; // req/min

    private Client $http;
    /** @var array<int, list<int>>  supplier_id → list timestamps (rolling 60s) */
    private array $requestLog = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
    ) {
        $this->http = new Client([
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * @return array{slug:string, email:string, api_key:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT fakturoid_slug, fakturoid_email, fakturoid_api_key_enc
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['fakturoid_slug']) || empty($row['fakturoid_email']) || empty($row['fakturoid_api_key_enc'])) {
            return null;
        }
        try {
            $key = $this->crypto->decrypt((string) $row['fakturoid_api_key_enc']);
        } catch (\Throwable $e) {
            $this->logger->error('Fakturoid api_key decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        return [
            'slug'    => (string) $row['fakturoid_slug'],
            'email'   => (string) $row['fakturoid_email'],
            'api_key' => $key,
        ];
    }

    public function setCredentials(int $supplierId, string $slug, string $email, string $apiKey): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET fakturoid_slug = ?, fakturoid_email = ?, fakturoid_api_key_enc = ?
              WHERE id = ?'
        )->execute([$slug ?: null, $email ?: null, $enc, $supplierId]);
    }

    /**
     * Test connectivity — pokus o GET /account.json (jednoduchý endpoint).
     */
    public function testConnection(int $supplierId): array
    {
        try {
            $creds = $this->getCredentials($supplierId);
            if ($creds === null) {
                return ['ok' => false, 'error' => 'Credentials nenastaveny'];
            }
            $url = self::API_BASE . '/' . urlencode($creds['slug']) . '/account.json';
            $this->throttle($supplierId);
            $resp = $this->http->get($url, [
                'headers' => $this->authHeaders($creds),
            ]);
            $code = $resp->getStatusCode();
            if ($code !== 200) {
                return ['ok' => false, 'error' => "HTTP {$code}: " . substr((string) $resp->getBody(), 0, 200)];
            }
            $data = json_decode((string) $resp->getBody(), true);
            return ['ok' => true, 'account_name' => $data['name'] ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * GET /subjects.json (nebo invoices.json, expenses.json) s pagination.
     * Fakturoid používá Link header pro next page (nikoliv page/total v body).
     *
     * @return array{items: list<array<string,mixed>>, next_page: ?string}
     */
    public function get(int $supplierId, string $endpoint, int $page = 1, array $extraQuery = []): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            throw new \RuntimeException('Fakturoid credentials nejsou nastaveny.');
        }
        $url = self::API_BASE . '/' . urlencode($creds['slug']) . '/' . ltrim($endpoint, '/');
        $query = array_merge(['page' => $page], $extraQuery);

        $this->throttle($supplierId);
        $resp = $this->http->get($url, [
            'headers' => $this->authHeaders($creds),
            'query'   => $query,
        ]);
        $code = $resp->getStatusCode();
        if ($code === 429) {
            // Hit rate limit — sleep podle Retry-After + retry once
            $retry = (int) ($resp->getHeader('Retry-After')[0] ?? 5);
            $this->logger->info('Fakturoid 429 — sleeping', ['retry_after' => $retry]);
            sleep(min($retry, 30));
            $resp = $this->http->get($url, ['headers' => $this->authHeaders($creds), 'query' => $query]);
            $code = $resp->getStatusCode();
        }
        if ($code !== 200) {
            throw new \RuntimeException("Fakturoid GET {$endpoint} failed (HTTP {$code}): " . substr((string) $resp->getBody(), 0, 200));
        }
        $body = (string) $resp->getBody();
        $items = json_decode($body, true);
        if (!is_array($items)) {
            throw new \RuntimeException("Fakturoid GET {$endpoint} returned invalid JSON.");
        }
        return ['items' => $items, 'next_page' => $this->parseNextPage($resp->getHeader('Link'))];
    }

    /**
     * Generator přes všechny stránky.
     *
     * @return iterable<array<string,mixed>>
     */
    public function getAll(int $supplierId, string $endpoint, array $extraQuery = []): iterable
    {
        $page = 1;
        do {
            $res = $this->get($supplierId, $endpoint, $page, $extraQuery);
            foreach ($res['items'] as $item) {
                yield $item;
            }
            $hasMore = $res['next_page'] !== null && !empty($res['items']);
            $page++;
        } while ($hasMore);
    }

    /**
     * @param array{slug:string, email:string, api_key:string} $creds
     */
    private function authHeaders(array $creds): array
    {
        $basic = base64_encode($creds['email'] . ':' . $creds['api_key']);
        return [
            'Authorization' => 'Basic ' . $basic,
            'User-Agent'    => self::USER_AGENT,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Fakturoid používá RFC 5988 Link header pro pagination.
     * Format: <url>; rel="next", <url>; rel="last"
     */
    private function parseNextPage(array $linkHeaders): ?string
    {
        $line = $linkHeaders[0] ?? null;
        if ($line === null) return null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    private function throttle(int $supplierId): void
    {
        $now = time();
        $log = $this->requestLog[$supplierId] ?? [];
        $log = array_values(array_filter($log, fn ($t) => $t > $now - 60));
        if (count($log) >= self::RATE_LIMIT_THRESHOLD) {
            $this->logger->info('Fakturoid throttle — sleep 1s', ['supplier_id' => $supplierId]);
            sleep(1);
        }
        $log[] = $now;
        $this->requestLog[$supplierId] = $log;
    }
}
