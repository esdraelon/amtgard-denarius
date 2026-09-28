<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;

final class CurlStripeApi implements StripeApi
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secretKey,
        private readonly ?\Closure $fetcher = null,
    ) {
    }

    public function createCustomer(string $kingdomKey): array
    {
        return $this->object('POST', '/v1/customers', [
            'name' => $kingdomKey,
            'metadata' => ['kingdom' => $kingdomKey],
        ]);
    }

    public function createSession(string $customerId): array
    {
        return $this->object('POST', '/v1/financial_connections/sessions', [
            'account_holder' => ['type' => 'customer', 'customer' => $customerId],
            'permissions' => ['transactions'],
            'filters' => ['countries' => ['US']],
            'prefetch' => ['transactions'],
        ]);
    }

    public function accounts(string $customerId): array
    {
        return $this->pages('/v1/financial_connections/accounts', [
            'account_holder' => ['customer' => $customerId],
            'limit' => 100,
        ]);
    }

    public function subscribe(string $accountId): void
    {
        $this->object('POST', '/v1/financial_connections/accounts/' . rawurlencode($accountId) . '/subscribe', [
            'features' => ['transactions'],
        ]);
    }

    public function transactions(string $accountId, int $startsAt, int $endsAt): array
    {
        return $this->pages('/v1/financial_connections/transactions', [
            'account' => $accountId,
            'limit' => 100,
            'transacted_at' => ['gte' => $startsAt, 'lte' => $endsAt],
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function object(string $method, string $path, array $fields): array
    {
        $decoded = json_decode($this->send($method, $path, $fields), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, array $query): array
    {
        $rows = [];
        do {
            $page = $this->object('GET', $path, $query);
            $data = is_array($page['data'] ?? null) ? $page['data'] : [];
            $last = '';
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = $row;
                $last = (string) ($row['id'] ?? $last);
            }
            $query['starting_after'] = $last;
        } while (($page['has_more'] ?? false) === true && $last !== '');

        return $rows;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function send(string $method, string $path, array $fields): string
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($method === 'GET' && $fields !== []) {
            $url .= '?' . http_build_query($fields);
            $fields = [];
        }
        if ($this->fetcher !== null) {
            return ($this->fetcher)($method, $url, $fields);
        }

        return $this->fetch($method, $url, $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function fetch(string $method, string $url, array $fields): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to start the Stripe request.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->secretKey],
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status >= 400 || $status === 0) {
            throw new \RuntimeException('Stripe request failed.');
        }

        return $body;
    }
}
