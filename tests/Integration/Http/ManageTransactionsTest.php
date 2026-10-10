<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;
use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;

/** Live HTTP coverage for kingdom manager batch transaction review (D9). */
final class ManageTransactionsTest extends IntegTestCase
{
    private const MANAGE_PREFIX = '/manage/' . IntegFixtures::KINGDOM_SLUG;

    public function testManageTransactionReviewPostAppliesBatchPublishAndRedact(): void
    {
        $http = $this->loggedInManagerHttp();
        $reviewPath = self::MANAGE_PREFIX . '?tab=review&review_month=' . IntegFixtures::REVIEW_MONTH;
        $before = $this->manageHtml($http, $reviewPath);
        $this->assertReviewRowsPresent($before);
        $this->assertStringContainsString('Ready to publish', $before);

        $response = $http->postForm(self::MANAGE_PREFIX . '/transactions/review', [
            'csrf' => $this->parseReviewBatchCsrf($before),
            'review_month' => IntegFixtures::REVIEW_MONTH,
            'review_id' => [IntegFixtures::TXN_REVIEW_PUBLISH, IntegFixtures::TXN_REVIEW_REDACT],
            'review' => [
                IntegFixtures::TXN_REVIEW_PUBLISH => ['publish' => '1'],
                IntegFixtures::TXN_REVIEW_REDACT => ['redact' => '1'],
            ],
        ]);
        $this->assertReviewRedirect($http, $response);

        $after = $this->manageHtml($http, $reviewPath);
        $this->assertMatchesRegularExpression(
            '/name="review\[' . preg_quote(IntegFixtures::TXN_REVIEW_PUBLISH, '/') . '\]\[publish\]"[^>]*checked/',
            $after,
        );
        $this->assertMatchesRegularExpression(
            '/name="review\[' . preg_quote(IntegFixtures::TXN_REVIEW_REDACT, '/') . '\]\[redact\]"[^>]*checked/',
            $after,
        );
        $this->assertStringContainsString('Published', $after);
        $this->assertStringNotContainsString('Ready to publish', $after);
    }

    private function loggedInManagerHttp(): IntegHttp
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        return $http;
    }

    private function manageHtml(IntegHttp $http, string $path): string
    {
        $response = $http->get($path);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    private function assertReviewRowsPresent(string $html): void
    {
        $this->assertStringContainsString(
            'name="review_id[]" value="' . IntegFixtures::TXN_REVIEW_PUBLISH . '" form="review-batch-form"',
            $html,
        );
        $this->assertStringContainsString(
            'name="review_id[]" value="' . IntegFixtures::TXN_REVIEW_REDACT . '" form="review-batch-form"',
            $html,
        );
        $this->assertStringContainsString(
            'name="review_month" value="' . IntegFixtures::REVIEW_MONTH . '"',
            $html,
        );
    }

    private function parseReviewBatchCsrf(string $html): string
    {
        $batchStart = strpos($html, '<form id="review-batch-form"');
        if ($batchStart === false) {
            throw new \RuntimeException('review-batch-form not found in manage HTML');
        }
        $batchForm = substr($html, $batchStart);
        if (preg_match('/name="csrf"\s+value="([^"]+)"/', $batchForm, $matches) !== 1) {
            throw new \RuntimeException('CSRF token not found in review-batch-form');
        }

        return $matches[1];
    }

    private function assertReviewRedirect(IntegHttp $http, \Psr\Http\Message\ResponseInterface $response): void
    {
        $expectedPath = self::MANAGE_PREFIX . '?tab=review&review_month=' . rawurlencode(IntegFixtures::REVIEW_MONTH);
        $this->assertSame(302, $response->getStatusCode());
        $location = $http->redirectLocation($response);
        $this->assertNotNull($location);
        $this->assertStringEndsWith($expectedPath, $location);
    }
}
