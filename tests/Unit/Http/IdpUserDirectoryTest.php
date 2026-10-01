<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Http;

use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Utilities\Http\IdpEmailLookupResult;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;
use Amtgard\PHPUnit\AmtgardTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class IdpUserDirectoryTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
    }

    public function testLookupMapsUnknownEmailFromClientIam(): void
    {
        $directory = $this->directory(new Response(404, [], '{"error":"unknown email"}'));
        $result = $directory->lookupByEmail('missing@example.com');
        $this->assertFalse($result->isResolved());
        $this->assertSame(IdpEmailLookupResult::REASON_UNKNOWN_EMAIL, $result->reason());
    }

    public function testLookupMapsRouteMissingToEndpointUnavailable(): void
    {
        $directory = $this->directory(new Response(404, [], '{"message":"404 Not Found"}'));
        $result = $directory->lookupByEmail('player@amtgard.com');
        $this->assertSame(IdpEmailLookupResult::REASON_ENDPOINT_UNAVAILABLE, $result->reason());
    }

    public function testLookupReturnsUuidOnSuccess(): void
    {
        $directory = $this->directory(new Response(200, [], '{"idp_user_id":"550e8400-e29b-41d4-a716-446655440000","email":"player@amtgard.com"}'));
        $result = $directory->lookupByEmail('player@amtgard.com');
        $this->assertTrue($result->isResolved());
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result->idpUserId());
    }

    private function directory(ResponseInterface $response): IdpUserDirectory
    {
        $client = new class($response) implements ClientInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return new IdpUserDirectory(
            IdpClientEnvironmentFactory::fromEnvVars([
                'IDP_BASE_URL' => 'https://idp.example.test',
                'IDP_CLIENT_ID' => 'denarius_test',
                'IDP_CLIENT_SECRET' => 'secret',
                'IDP_REDIRECT_URI' => 'https://denarius.example.test/oauth/callback',
            ]),
            $client,
            new Psr17Factory(),
        );
    }
}
