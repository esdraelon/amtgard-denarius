<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinHost;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class CurlSimpleFinApi implements SimpleFinApi
{
    public function __construct(
        private readonly SimpleFinHost $hosts,
        private readonly ?\Closure $fetcher = null,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function claim(string $claimUrl): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($claimUrl): string {
            return trim($this->send('POST', $claimUrl));
        });
    }

    public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($accessUrl, $startsAt, $endsAt): array {
            $url = rtrim($accessUrl, '/') . '/accounts?' . http_build_query([
                'start-date' => $startsAt,
                'end-date' => $endsAt,
            ]);
            $decoded = json_decode($this->send('GET', $url), true);
            $accounts = is_array($decoded) ? ($decoded['accounts'] ?? null) : null;
            if (!is_array($accounts)) {
                return [];
            }
            $rows = [];
            foreach ($accounts as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        });
    }

    private function send(string $method, string $url): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($method, $url): string {
            if (!$this->hosts->accepts($url)) {
                throw new \InvalidArgumentException('SimpleFIN URL is not allowed.');
            }
            if ($this->fetcher !== null) {
                return ($this->fetcher)($method, $url);
            }

            return $this->fetch($method, $url);
        });
    }

    private function fetch(string $method, string $url): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($method, $url): string {
            $parts = parse_url($url);
            $user = rawurldecode((string) ($parts['user'] ?? ''));
            $password = rawurldecode((string) ($parts['pass'] ?? ''));
            $handle = curl_init($this->withoutUser($url, is_array($parts) ? $parts : []));
            if ($handle === false) {
                throw new \RuntimeException('Unable to start the SimpleFIN request.');
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_USERAGENT => 'Amtgard-Denarius SimpleFIN/1.0',
            ];
            if ($method === 'POST') {
                $options[CURLOPT_POSTFIELDS] = '';
            }
            if ($user !== '' || $password !== '') {
                $options[CURLOPT_USERPWD] = $user . ':' . $password;
            }
            curl_setopt_array($handle, $options);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if (!is_string($body) || $status === 0) {
                throw new \RuntimeException('SimpleFIN request failed.');
            }
            if ($method === 'POST' && $status === 403) {
                return trim($body);
            }
            if ($status >= 400) {
                throw new \RuntimeException('SimpleFIN request failed.');
            }

            return $body;
        });
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function withoutUser(string $url, array $parts): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($url, $parts): string {
            if (($parts['user'] ?? '') === '' && ($parts['pass'] ?? '') === '') {
                return $url;
            }
            $host = (string) ($parts['host'] ?? '');
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path = (string) ($parts['path'] ?? '');
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';

            return (string) ($parts['scheme'] ?? 'https') . '://' . $host . $port . $path . $query;
        });
    }
}
