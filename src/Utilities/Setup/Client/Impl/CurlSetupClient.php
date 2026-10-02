<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Client\Impl;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Setup\Client\SetupClient;
use Optional\Optional;

final class CurlSetupClient implements SetupClient
{
    public function __construct(private readonly ?\Closure $fetcher = null)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function status(string $method, string $url, array $headers, string $body): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($method, $url, $headers, $body): int {
            $status = null;
            Optional::ofNullable($this->fetcher)->ifPresent(function (\Closure $fetcher) use (&$status, $method, $url, $headers, $body): void {
                $status = (int) $fetcher($method, $url, $headers, $body);
            });

            return $status ?? $this->fetch($method, $url, $headers, $body);
        });
    }

    public function readable(string $path): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($path): bool {
            return is_readable($path);
        });
    }

    /**
     * @param list<string> $headers
     */
    private function fetch(string $method, string $url, array $headers, string $body): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($method, $url, $headers, $body): int {
            $handle = curl_init($url);
            if ($handle === false) {
                return 0;
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
            ];
            if ($body !== '') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($handle, $options);
            curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            return $status;
        });
    }
}
