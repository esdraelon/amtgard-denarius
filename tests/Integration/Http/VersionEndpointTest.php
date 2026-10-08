<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;
use Amtgard\PHPUnit\AmtgardTestCase;

/** Smoke test: live web stack serves GET /version (pre-C3 harness). */
final class VersionEndpointTest extends AmtgardTestCase
{
    public function testVersionReturnsJsonWithVersionKey(): void
    {
        $base = rtrim((string) (getenv('DENARIUS_BASE_URL') ?: $_ENV['DENARIUS_BASE_URL'] ?? ''), '/');
        if ($base === '') {
            $this->markTestSkipped('DENARIUS_BASE_URL is not set.');
        }

        $response = (new IntegHttp($base))->get('/version');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('version', $payload);
        $this->assertNotSame('', (string) $payload['version']);
    }
}
