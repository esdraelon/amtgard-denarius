<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegTestCase;

/** Smoke test: live web stack serves GET /version after per-test reseed. */
final class VersionEndpointTest extends IntegTestCase
{
    public function testVersionReturnsJsonWithVersionKey(): void
    {
        $response = $this->integHttp()->get('/version');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('version', $payload);
        $this->assertNotSame('', (string) $payload['version']);
    }
}
