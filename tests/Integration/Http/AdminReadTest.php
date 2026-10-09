<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;

/** Live HTTP coverage for authenticated admin read routes (D5). */
final class AdminReadTest extends IntegTestCase
{
    public function testAdminIndexRendersForBootstrapAdmin(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginViaIdpOrSkip($http, $this);

        $response = $http->get('/admin');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Roles', $body);
        $this->assertStringContainsString('id="admin-principal-form"', $body);
        $this->assertStringContainsString('name="csrf"', $body);
        $this->assertStringContainsString(
            'Search by full Amtgard ID email or username',
            $body,
        );
    }

    public function testAdminKingdomsJsonForBootstrapAdmin(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginViaIdpOrSkip($http, $this);

        $response = $http->get('/admin/kingdoms');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{kingdoms?: list<array{id: int, name: string}>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('kingdoms', $payload);
        $this->assertNotEmpty($payload['kingdoms']);
        $first = $payload['kingdoms'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
    }

    public function testPrincipalSuggestionsShortQueryReturnsEmpty(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginViaIdpOrSkip($http, $this);

        $response = $http->get('/admin/principal-suggestions?q=a');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{suggestions: list<mixed>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $payload['suggestions']);
    }

    public function testPrincipalSuggestionsMatchBootstrapAdminEmail(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginViaIdpOrSkip($http, $this);

        $query = rawurlencode(IntegFixtures::ADMIN_EMAIL);
        $response = $http->get('/admin/principal-suggestions?q=' . $query);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{suggestions: list<array{email: string, idpUserId: string}>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty(
            $payload['suggestions'],
            'IdP Client IAM must resolve ' . IntegFixtures::ADMIN_EMAIL . ' on the integ stack',
        );
        $emails = array_column($payload['suggestions'], 'email');
        $this->assertContains(IntegFixtures::ADMIN_EMAIL, $emails);
    }
}
