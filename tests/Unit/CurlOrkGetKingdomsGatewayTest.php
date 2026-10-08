<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Utilities\Http\Impl\CurlOrkGetKingdomsGateway;
use Amtgard\PHPUnit\AmtgardTestCase;

final class CurlOrkGetKingdomsGatewayTest extends AmtgardTestCase
{
    public function testUsesInjectedFetcherWhenProvided(): void
    {
        $gateway = new CurlOrkGetKingdomsGateway(
            'https://ork.example.test',
            'denarius-test',
            'https://denarius.amtgard.com',
            1,
            static fn (string $url, string $body): string => '{"Status":{"Status":0},"Kingdoms":[]}',
        );

        $json = $gateway->getKingdomsJson();
        $this->assertSame('{"Status":{"Status":0},"Kingdoms":[]}', $json);
    }

    public function testFetcherRejectsHtmlChallengeBodies(): void
    {
        $gateway = new CurlOrkGetKingdomsGateway(
            'https://ork.example.test',
            'denarius-test',
            'https://denarius.amtgard.com',
            1,
            static fn (string $url, string $body): string => '<!DOCTYPE html><title>Just a moment',
        );

        $this->assertNull($gateway->getKingdomsJson());
    }

    public function testCurlPathReturnsNullWhenEndpointUnreachable(): void
    {
        $gateway = new CurlOrkGetKingdomsGateway(
            'http://127.0.0.1:1',
            'denarius-test',
            'https://denarius.amtgard.com',
            1,
        );

        $this->assertNull($gateway->getKingdomsJson());
    }
}
