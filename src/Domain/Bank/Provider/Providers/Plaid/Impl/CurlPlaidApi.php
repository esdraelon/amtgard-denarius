<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidApi;

final class CurlPlaidApi implements PlaidApi
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $secret,
        private readonly string $clientName,
        private readonly ?\Closure $fetcher = null,
    ) {
    }

    public function institutions(string $query): array
    {
        $page = $this->post('/institutions/search', [
            'query' => $query,
            'country_codes' => ['US'],
            'products' => ['transactions'],
        ]);

        return $this->rows($page['institutions'] ?? null);
    }

    public function linkToken(string $kingdomKey): array
    {
        return $this->post('/link/token/create', [
            'client_name' => $this->clientName,
            'language' => 'en',
            'country_codes' => ['US'],
            'user' => ['client_user_id' => $kingdomKey],
            'products' => ['transactions'],
        ]);
    }

    public function exchange(string $publicToken): array
    {
        return $this->post('/item/public_token/exchange', ['public_token' => $publicToken]);
    }

    public function accounts(string $accessToken): array
    {
        return $this->rows($this->post('/accounts/get', ['access_token' => $accessToken])['accounts'] ?? null);
    }

    public function transactions(string $accessToken): array
    {
        $rows = [];
        $cursor = '';
        do {
            $page = $this->post('/transactions/sync', [
                'access_token' => $accessToken,
                'cursor' => $cursor,
                'count' => 100,
            ]);
            foreach (['added', 'modified'] as $bucket) {
                foreach ($this->rows($page[$bucket] ?? null) as $row) {
                    $rows[] = $row;
                }
            }
            $cursor = (string) ($page['next_cursor'] ?? '');
        } while (($page['has_more'] ?? false) === true && $cursor !== '');

        return $rows;
    }

    public function verificationKey(string $keyId): array
    {
        $page = $this->post('/webhook_verification_key/get', ['key_id' => $keyId]);
        $key = $page['key'] ?? null;

        return is_array($key) ? $key : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $body['client_id'] = $this->clientId;
        $body['secret'] = $this->secret;
        $decoded = json_decode($this->send($path, $body), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $path, array $body): string
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url, $body);
        }

        return $this->fetch($url, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function fetch(string $url, array $body): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to start the Plaid request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($response) || $status >= 400 || $status === 0) {
            throw new \RuntimeException('Plaid request failed.');
        }

        return $response;
    }
}
