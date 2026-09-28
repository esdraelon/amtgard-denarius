<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Ork;

use Amtgard\Denarius\Contract\OrkKingdomClient;

final class HttpOrkKingdomClient implements OrkKingdomClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly string $referer,
        private readonly OrkKingdomParser $parser,
        private readonly ?\Closure $fetcher = null,
    ) {
    }

    public function listKingdoms(): array
    {
        if ($this->userAgent === '' || $this->referer === '') {
            throw new \RuntimeException('ORK_API_USER_AGENT and ORK_API_REFERER are required.');
        }

        $url = $this->baseUrl . (str_contains($this->baseUrl, '?') ? '&' : '?') . 'call=Kingdom/GetKingdoms';
        $body = $this->fetcher !== null
            ? ($this->fetcher)($url, $this->userAgent, $this->referer)
            : $this->fetch($url);

        $decoded = json_decode($body, true);

        return $this->parser->parse(is_array($decoded) ? $decoded : []);
    }

    private function fetch(string $url): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to start the ORK request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_REFERER => $this->referer,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status >= 400) {
            throw new \RuntimeException('ORK kingdom lookup failed.');
        }

        return $body;
    }
}
