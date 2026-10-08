<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http\Impl;

use Amtgard\Denarius\Utilities\Http\OrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Gateway: server-side POST to ORK Json API (GetKingdoms). */
final class CurlOrkGetKingdomsGateway implements OrkGetKingdomsGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly string $referer,
        private readonly int $timeoutSeconds = 15,
        private readonly ?\Closure $fetcher = null,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function getKingdomsJson(): ?string
    {
        return DenariusLog::trace(__METHOD__, function (): ?string {
            $url = rtrim($this->baseUrl, '/') . '/orkservice/Json/index.php?request=';
            $body = 'call=Kingdom/GetKingdoms&request=' . rawurlencode('{}');

            if ($this->fetcher !== null) {
                $response = ($this->fetcher)($url, $body);

                return $this->acceptResponse(is_string($response) ? $response : null, 200);
            }

            $handle = curl_init($url);
            if ($handle === false) {
                DenariusLog::infoBranch('ork_kingdoms_fetch_failed', __METHOD__, ['reason' => 'curl_init']);

                return null;
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: ' . $this->userAgent,
                    'Referer: ' . $this->referer,
                ],
            ]);

            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            return $this->acceptResponse(is_string($response) ? $response : null, $status);
        });
    }

    private function acceptResponse(?string $response, int $status): ?string
    {
        if ($response === null || $response === '' || $status < 200 || $status >= 300) {
            DenariusLog::infoBranch('ork_kingdoms_fetch_failed', __METHOD__, [
                'reason' => 'http',
                'status' => $status,
            ]);

            return null;
        }

        if (str_starts_with(ltrim($response), '<')) {
            DenariusLog::infoBranch('ork_kingdoms_fetch_failed', __METHOD__, [
                'reason' => 'html',
                'status' => $status,
            ]);

            return null;
        }

        DenariusLog::infoBranch('ork_kingdoms_fetched', __METHOD__, ['bytes' => strlen($response)]);

        return $response;
    }
}
