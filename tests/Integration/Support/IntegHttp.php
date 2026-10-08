<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\ResponseInterface;

/** HTTP helper for live-stack integration tests (cookie jar + form/JSON). */
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

    public function cookieJar(): CookieJar
    {
        return $this->jar;
    }

    public function get(string $path): ResponseInterface
    {
        return $this->client->get(ltrim($path, '/'));
    }

    /** @param array<string, string> $headers */
    public function postRaw(string $path, string $body, array $headers = []): ResponseInterface
    {
        return $this->client->post(ltrim($path, '/'), [
            'body' => $body,
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
        ]);
    }

    /** @param array<string, mixed> $body */
    public function postJson(string $path, array $body): ResponseInterface
    {
        return $this->client->post(ltrim($path, '/'), [
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /** @param array<string, string> $fields */
    public function postForm(string $path, array $fields): ResponseInterface
    {
        return $this->client->post(ltrim($path, '/'), [
            'form_params' => $fields,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
    }

    public function parseCsrfToken(string $html): string
    {
        return $this->parseHiddenField($html, 'csrf');
    }

    public function parseHiddenField(string $html, string $name): string
    {
        $pattern = '/name="' . preg_quote($name, '/') . '"\s+value="([^"]+)"/';
        if (preg_match($pattern, $html, $matches) !== 1) {
            throw new \RuntimeException("Hidden field {$name} not found in HTML");
        }

        return $matches[1];
    }

    public function redirectLocation(ResponseInterface $response): ?string
    {
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            return null;
        }

        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        return rtrim($this->baseUrl, '/') . $location;
    }

    public function parseQueryParam(string $url, string $name): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            throw new \RuntimeException("Query string missing in URL for param {$name}");
        }
        parse_str($query, $params);
        $value = $params[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Query param {$name} not found in URL");
        }

        return $value;
    }

    public function isRedirectToPath(ResponseInterface $response, string $path): bool
    {
        $status = $response->getStatusCode();
        if ($status !== 301 && $status !== 302 && $status !== 303) {
            return false;
        }
        $location = $this->redirectLocation($response);
        if ($location === null) {
            return false;
        }
        $normalizedPath = str_starts_with($path, '/') ? $path : '/' . $path;
        $parsed = parse_url($location);
        $locationPath = $parsed['path'] ?? '';

        return $locationPath === $normalizedPath;
    }

    public function hasSessionCookie(): bool
    {
        foreach ($this->jar->toArray() as $cookie) {
            if (($cookie['Name'] ?? '') === 'PHPSESSID' && ($cookie['Value'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    public function setCookie(SetCookie $cookie): void
    {
        $this->jar->setCookie($cookie);
    }
}
