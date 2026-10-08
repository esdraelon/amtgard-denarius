<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;
use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;

/** Live HTTP coverage for CSRF, guest, and cross-kingdom auth failures (D13). */
final class AuthNegativesTest extends IntegTestCase
{
    private const MANAGE_PREFIX = '/manage/' . IntegFixtures::KINGDOM_SLUG;

    private const OTHER_KINGDOM_SLUG = 'emerald-hills';

    public function testAdminPostWithoutCsrfReturns403(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginViaIdpOrSkip($http, $this);

        $grant = $http->postForm('/admin/grant', [
            'action' => 'grant-manager',
            'idp_user_id' => IntegFixtures::MANAGER_IDP_USER_ID,
            'ork_kingdom_id' => (string) IntegFixtures::KINGDOM_ORK_ID,
            'kingdom_name' => IntegFixtures::KINGDOM_NAME,
        ]);
        $this->assertSame(403, $grant->getStatusCode(), (string) $grant->getBody());

        $sync = $http->postJson('/admin/kingdoms/sync', [
            'ork_json' => '{"Status":{"Status":0},"Kingdoms":[]}',
        ]);
        $this->assertSame(403, $sync->getStatusCode(), (string) $sync->getBody());
    }

    public function testGuestPostManageConnectReturnsRedirectOr403(): void
    {
        $http = $this->integHttp();
        $this->assertFalse($http->hasSessionCookie(), 'Guest client must start without a session');

        $response = $http->postForm(self::MANAGE_PREFIX . '/connect', []);
        $status = $response->getStatusCode();
        $this->assertContains(
            $status,
            [302, 303, 403],
            'Guest POST manage connect must redirect to login or reject; body=' . (string) $response->getBody(),
        );
        if ($status === 302 || $status === 303) {
            $this->assertTrue(
                $http->isRedirectToPath($response, '/login'),
                'Guest manage POST must redirect to login; location=' . $response->getHeaderLine('Location'),
            );
        }
    }

    public function testCrossKingdomManagerDenied(): void
    {
        $denariusBase = rtrim((string) (getenv('DENARIUS_BASE_URL') ?: $_ENV['DENARIUS_BASE_URL'] ?? ''), '/');
        if ($denariusBase === '') {
            $this->markTestSkipped('DENARIUS_BASE_URL is not set.');
        }

        IntegAuth::skipIfIdpUnavailable($this);

        try {
            $adminHttp = new IntegHttp($denariusBase);
            IntegAuth::loginViaIdp($adminHttp);
            $this->syncEmeraldHills($adminHttp);

            $managerHttp = new IntegHttp($denariusBase);
            IntegAuth::grantSeedKingdomManager($adminHttp);
            IntegAuth::loginViaIdp($managerHttp, IntegFixtures::MANAGER_EMAIL);
        } catch (\Throwable $exception) {
            $this->markTestSkipped(
                'Cross-kingdom auth integ unavailable: ' . $exception->getMessage(),
            );
        }

        $response = $managerHttp->get('/manage/' . self::OTHER_KINGDOM_SLUG);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('You do not manage this kingdom.', (string) $response->getBody());
    }

    private function syncEmeraldHills(IntegHttp $adminHttp): void
    {
        $index = $adminHttp->get('/admin');
        $this->assertSame(200, $index->getStatusCode(), (string) $index->getBody());
        $csrf = $adminHttp->parseCsrfToken((string) $index->getBody());

        $orkPayload = json_encode([
            'Status' => ['Status' => 0],
            'Kingdoms' => [
                ['KingdomId' => 12, 'KingdomName' => 'Emerald Hills'],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $adminHttp->postJson('/admin/kingdoms/sync', [
            'csrf' => $csrf,
            'ork_json' => $orkPayload,
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }
}
