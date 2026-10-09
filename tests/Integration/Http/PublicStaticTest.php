<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegTestCase;

/** Live HTTP coverage for unauthenticated public pages (home, privacy policy). */
final class PublicStaticTest extends IntegTestCase
{
    public function testHomeReturnsPublicLandingPage(): void
    {
        $response = $this->integHttp()->get('/');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Denarius', $body);
        $this->assertStringContainsString('Amtgard kingdom treasuries published for accountability', $body);
        $this->assertStringContainsString('Sign in', $body);
    }

    public function testPrivacyPolicyReturnsPolicyPage(): void
    {
        $response = $this->integHttp()->get('/privacy-policy');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Privacy Policy', $body);
        $this->assertStringContainsString('privacy@amtgard.com', $body);
    }
}
