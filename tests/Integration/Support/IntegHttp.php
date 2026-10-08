<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

/** Adapter: HTTP client for live-stack integration tests against DENARIUS_BASE_URL. */
final class IntegHttp
{
    private Client $client;

    public function __construct(
        private readonly string $baseUrl,
        private readonly CookieJar $jar = new CookieJar(),
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'cookies' => $this->jar,
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 15,
        ]);
    }

    public function get(string $path): ResponseInterface
    {
        return $this->client->get(ltrim($path, '/'));
    }
}
