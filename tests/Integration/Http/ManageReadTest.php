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

    public function testManageReviewMonthQuerySelectsRequestedMonth(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '?tab=review&review_month=2026-09');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('>2026-09</span>', $body);
        $this->assertStringContainsString('tab=review&amp;review_month=2026-08', $body);
    }

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

        $response = $http->get(self::MANAGE_PREFIX . '/taxonomy/categories?flow=expense&q=rent');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{results: list<array{lineageKey: string, label: string, flow: string}>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('results', $payload);
        $this->assertNotEmpty($payload['results']);
        $lineageKeys = array_column($payload['results'], 'lineageKey');
        $this->assertContains('expense.site_rental', $lineageKeys);
        foreach ($payload['results'] as $row) {
            $this->assertSame('expense', $row['flow']);
        }
    }

    public function testManageTaxonomyCategorySearchEmptyFlow(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '/taxonomy/categories?q=rent');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{results: list<mixed>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $payload['results']);
    }

    public function testManageTaxonomyCategorySearchIncomeFlow(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '/taxonomy/categories?flow=income&q=dues');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{results: list<array{flow: string}>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($payload['results'] as $row) {
            $this->assertSame('income', $row['flow']);
        }
    }

    public function testManageReviewTabTypeaheadUsesCategorySearchUrl(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '?tab=review');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $expected = 'data-search-url="' . self::MANAGE_PREFIX . '/taxonomy/categories"';
        $this->assertStringContainsString($expected, (string) $response->getBody());
    }

    public function testManagePatternsTabTypeaheadUsesCategorySearchUrl(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $response = $http->get(self::MANAGE_PREFIX . '/patterns');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $expected = 'data-search-url="' . self::MANAGE_PREFIX . '/taxonomy/categories"';
        $this->assertStringContainsString($expected, (string) $response->getBody());
    }

    public function testManageReviewTabIncludesCategoryTypeaheadScriptOnce(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $body = (string) $http->get(self::MANAGE_PREFIX)->getBody();
        $this->assertSame(1, substr_count($body, '<script src="/js/category-typeahead.js"></script>'));
    }

    public function testManagePatternsTabIncludesCategoryTypeaheadScriptOnce(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $body = (string) $http->get(self::MANAGE_PREFIX . '/patterns')->getBody();
        $this->assertSame(1, substr_count($body, '<script src="/js/category-typeahead.js"></script>'));
    }

    public function testManagePatternNewFormUsesCategorySearchUrl(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        $body = (string) $http->get(self::MANAGE_PREFIX . '/patterns/new')->getBody();
        $expected = 'data-search-url="' . self::MANAGE_PREFIX . '/taxonomy/categories"';
        $this->assertStringContainsString($expected, $body);
    }
}
