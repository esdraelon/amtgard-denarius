<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;
use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;

/** Live HTTP coverage for kingdom manager category pattern writes (D10). */
final class ManagePatternsTest extends IntegTestCase
{
    private const MANAGE_PREFIX = '/manage/' . IntegFixtures::KINGDOM_SLUG;

    private const PATTERN_CATEGORY = 'expense.storage';

    private const CREATE_TOKEN = 'INTEG_PATTERN_CREATE';

    private const UPDATE_TOKEN = 'INTEG_PATTERN_UPDATE';

    private const BULK_TOKEN = 'INTEG_PATTERN_BULK';

    public function testManagePatternRoutesCreateUpdateBulkAndDelete(): void
    {
        $http = $this->loggedInManagerHttp();

        $newForm = $this->patternsHtml($http, self::MANAGE_PREFIX . '/patterns/new');
        $createCsrf = $http->parseCsrfToken($newForm);
        $createResponse = $http->postForm(self::MANAGE_PREFIX . '/patterns', [
            'csrf' => $createCsrf,
            'match_type' => 'token',
            'token' => self::CREATE_TOKEN,
            'category' => self::PATTERN_CATEGORY,
            'fields' => ['description', 'counterparty'],
            'flows' => ['expense', 'income'],
        ]);
        $this->assertPatternsRedirect($http, $createResponse);

        $afterCreate = $this->patternsHtml($http, self::MANAGE_PREFIX . '/patterns');
        $this->assertStringContainsString('name="patterns[', $afterCreate);
        $this->assertStringContainsString('value="' . self::CREATE_TOKEN . '"', $afterCreate);
        $ruleId = $this->parsePatternRuleId($afterCreate, self::CREATE_TOKEN);
        $listCsrf = $http->parseCsrfToken($afterCreate);

        $updateResponse = $http->postForm(self::MANAGE_PREFIX . '/patterns/' . $ruleId, [
            'csrf' => $listCsrf,
            'match_type' => 'token',
            'token' => self::UPDATE_TOKEN,
            'category' => self::PATTERN_CATEGORY,
            'fields' => ['description'],
            'flows' => ['expense'],
        ]);
        $this->assertPatternsRedirect($http, $updateResponse);

        $afterUpdate = $this->patternsHtml($http, self::MANAGE_PREFIX . '/patterns');
        $this->assertStringContainsString('value="' . self::UPDATE_TOKEN . '"', $afterUpdate);
        $bulkCsrf = $http->parseCsrfToken($afterUpdate);

        $bulkResponse = $http->postForm(self::MANAGE_PREFIX . '/patterns/bulk', [
            'csrf' => $bulkCsrf,
            'patterns' => [
                $ruleId => [
                    'match_type' => 'token',
                    'token' => self::BULK_TOKEN,
                    'category' => self::PATTERN_CATEGORY,
                ],
            ],
        ]);
        $this->assertPatternsRedirect($http, $bulkResponse);

        $afterBulk = $this->patternsHtml($http, self::MANAGE_PREFIX . '/patterns');
        $this->assertStringContainsString('value="' . self::BULK_TOKEN . '"', $afterBulk);
        $deleteCsrf = $http->parseCsrfToken($afterBulk);

        $deleteResponse = $http->postForm(self::MANAGE_PREFIX . '/patterns/' . $ruleId . '/delete', [
            'csrf' => $deleteCsrf,
        ]);
        $this->assertPatternsRedirect($http, $deleteResponse);

        $afterDelete = $this->patternsHtml($http, self::MANAGE_PREFIX . '/patterns');
        $this->assertStringContainsString('No patterns yet', $afterDelete);
        $this->assertStringNotContainsString('value="' . self::BULK_TOKEN . '"', $afterDelete);
    }

    private function loggedInManagerHttp(): IntegHttp
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        return $http;
    }

    private function patternsHtml(IntegHttp $http, string $path): string
    {
        $response = $http->get($path);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    private function assertPatternsRedirect(IntegHttp $http, \Psr\Http\Message\ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue(
            $http->isRedirectToPath($response, self::MANAGE_PREFIX . '/patterns'),
            'Expected redirect to patterns list; location=' . $response->getHeaderLine('Location'),
        );
    }

    private function parsePatternRuleId(string $html, string $token): string
    {
        $tokenNeedle = 'value="' . $token . '"';
        $tokenPos = strpos($html, $tokenNeedle);
        if ($tokenPos === false) {
            throw new \RuntimeException('Pattern token not found in patterns list HTML');
        }
        $rowChunk = substr($html, max(0, $tokenPos - 800), 1600);
        if (preg_match('/formaction="[^"]*\/patterns\/(\d+)\/delete"/', $rowChunk, $matches) !== 1) {
            throw new \RuntimeException('Pattern rule id not found near token row');
        }

        return $matches[1];
    }
}
