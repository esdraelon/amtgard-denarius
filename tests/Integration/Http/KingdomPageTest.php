<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;

/** Live HTTP coverage for the public kingdom statement page (D4). */
final class KingdomPageTest extends IntegTestCase
{
    public function testSeedSlugReturnsKingdomStatementPage(): void
    {
        $response = $this->integHttp()->get('/' . IntegFixtures::KINGDOM_SLUG);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString(IntegFixtures::KINGDOM_NAME, $body);
        $this->assertStringContainsString('Previous month', $body);
        $this->assertStringContainsString('Next month', $body);
        $this->assertStringContainsString(
            '/' . IntegFixtures::KINGDOM_SLUG . '?month=',
            $body,
        );
    }

    public function testUnknownSlugReturnsNotFound(): void
    {
        $response = $this->integHttp()->get('/no-such-kingdom-slug');
        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Not found', $body);
        $this->assertStringContainsString('That kingdom is not published.', $body);
    }
}
