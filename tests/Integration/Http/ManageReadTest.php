<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;

/** Live HTTP coverage for authenticated kingdom manager read routes (D7). */
final class ManageReadTest extends IntegTestCase
{
    private const MANAGE_PREFIX = '/manage/' . IntegFixtures::KINGDOM_SLUG;

    public function testManageIndexRendersForKingdomManager(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Manage ' . IntegFixtures::KINGDOM_NAME, $body);
        $this->assertStringContainsString('Transaction review', $body);
        $this->assertStringContainsString('name="csrf"', $body);
        $this->assertStringContainsString('tab=patterns', $body);
    }

    public function testManageSettingsTabRendersForKingdomManager(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '?tab=settings');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Statement settings', $body);
        $this->assertStringContainsString('Bank connection', $body);
    }

    public function testManageConnectGetRedirectsToManageIndex(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '/connect');
        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString(self::MANAGE_PREFIX, $location);
        $this->assertStringContainsString('tab=settings', $location);
    }

    public function testManagePatternsListRendersForKingdomManager(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '/patterns');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('tab=patterns', $body);
        $this->assertStringContainsString(self::MANAGE_PREFIX . '/patterns/new', $body);
        $this->assertStringContainsString('name="csrf"', $body);
    }

    public function testManagePatternNewFormRendersForKingdomManager(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '/patterns/new');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Create pattern', $body);
        $this->assertStringContainsString('id="pattern-token"', $body);
        $this->assertStringContainsString('data-category-typeahead', $body);
        $this->assertStringContainsString('action="' . self::MANAGE_PREFIX . '/patterns"', $body);
    }

    public function testManageTaxonomyCategorySearchReturnsJson(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '/taxonomy/categories?q=rent');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{results: list<array{slug: string, label: string}>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('results', $payload);
        $this->assertNotEmpty($payload['results']);
        $slugs = array_column($payload['results'], 'slug');
        $this->assertContains('expense.site_rental', $slugs);
    }
}
