<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Service;

use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Service\Admin\AdminPrincipalSuggester;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Unit\Strategies;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;
use Amtgard\PHPUnit\AmtgardTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AdminPrincipalSuggesterTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
    }

    public function testMatchResolvesFullEmailViaIdp(): void
    {
        $principals = new \Amtgard\Denarius\Tests\Unit\MemoryPrincipals();

        $matches = Strategies::principalSuggester($principals)->match('person@example.com');
        $this->assertCount(1, $matches);
        $this->assertSame('person@example.com', $matches[0]->getEmail());
        $this->assertSame('9', $matches[0]->getIdpUserId());
    }

    public function testMatchProbesIdpWhenLocalPartHasNoLocalRow(): void
    {
        $_ENV['IDP_SUGGEST_EMAIL_DOMAINS'] = 'esdraelon.com';
        $principals = new \Amtgard\Denarius\Tests\Unit\MemoryPrincipals();
        $psr17 = new Psr17Factory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (str_contains($request->getUri()->getQuery(), 'megiddo%40esdraelon.com')) {
                    return new Response(200, [], '{"idp_user_id":"idp-uuid-megiddo"}');
                }

                return new Response(404, [], '{"error":"unknown email"}');
            }
        };
        $directory = new IdpUserDirectory(
            IdpClientEnvironmentFactory::fromEnvVars([
                'IDP_BASE_URL' => 'https://idp.example.test',
                'IDP_CLIENT_ID' => 'denarius_test',
                'IDP_CLIENT_SECRET' => 'secret',
                'IDP_REDIRECT_URI' => 'https://denarius.example.test/oauth/callback',
            ]),
            $client,
            $psr17,
        );
        $suggester = new AdminPrincipalSuggester($principals, $directory, new PrincipalSync($principals));

        $matches = $suggester->match('megiddo');
        $this->assertCount(1, $matches);
        $this->assertSame('megiddo@esdraelon.com', $matches[0]->getEmail());
        $this->assertSame('idp-uuid-megiddo', $matches[0]->getIdpUserId());
    }

    public function testMatchIgnoresStaleLocalRowsWithoutIdpHit(): void
    {
        $principals = new \Amtgard\Denarius\Tests\Unit\MemoryPrincipals();
        $principals->save(\Amtgard\Denarius\Persistence\Record\PrincipalRecord::builder()->idpUserId('stale')->email('other@example.com')->build());

        $matches = Strategies::principalSuggester($principals)->match('other');
        $this->assertSame([], $matches);
    }
}
