<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerApi;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class CurlTellerApi implements TellerApi
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $certPath,
        private readonly string $keyPath,
        private readonly ?\Closure $fetcher = null,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function accounts(string $accessToken): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($accessToken): array {
            return $this->get('/accounts', $accessToken, []);
        });
    }

    public function transactions(string $accessToken, string $accountId, ?string $fromId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($accessToken, $accountId, $fromId): array {
            $query = [];
            if ($fromId !== null && $fromId !== '') {
                $query['from_id'] = $fromId;
            }

            return $this->get('/accounts/' . rawurlencode($accountId) . '/transactions', $accessToken, $query);
        });
    }

    /**
     * @param array<string, string> $query
     * @return list<array<string, mixed>>
     */
    private function get(string $path, string $accessToken, array $query): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($path, $accessToken, $query): array {
            $url = rtrim($this->baseUrl, '/') . $path;
            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }
            $body = $this->fetcher !== null
                ? ($this->fetcher)($url, $accessToken, $this->certPath, $this->keyPath)
                : $this->fetch($url, $accessToken);
            $decoded = json_decode($body, true);

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        });
    }

    private function fetch(string $url, string $accessToken): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($url, $accessToken): string {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new \RuntimeException('Unable to start the Teller request.');
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $accessToken . ':',
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => ['Teller-Version: 2019-07-01'],
            ];
            if ($this->certPath !== '' && $this->keyPath !== '') {
                $options[CURLOPT_SSLCERT] = $this->certPath;
                $options[CURLOPT_SSLKEY] = $this->keyPath;
            }
            curl_setopt_array($handle, $options);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if (!is_string($body) || $status >= 400) {
                throw new \RuntimeException('Teller request failed.');
            }

            return $body;
        });
    }
}
