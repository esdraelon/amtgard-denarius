<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;

/** Live HTTP coverage for authenticated admin write routes (D6). */
final class AdminWriteTest extends IntegTestCase
{
    public function testAdminKingdomsSyncAcceptsOrkJsonWithCsrf(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginViaIdpOrSkip($http, $this);

        $index = $http->get('/admin');
        $this->assertSame(200, $index->getStatusCode(), (string) $index->getBody());
        $csrf = $http->parseCsrfToken((string) $index->getBody());

        $orkPayload = json_encode([
            'Status' => ['Status' => 0],
            'Kingdoms' => [
                ['KingdomId' => 12, 'KingdomName' => 'Emerald Hills'],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $http->postJson('/admin/kingdoms/sync', [
            'csrf' => $csrf,
            'ork_json' => $orkPayload,
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{kingdoms: list<array{id: int, name: string}>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $ids = array_column($payload['kingdoms'], 'id');
        $this->assertContains(12, $ids);
        $this->assertContains(IntegFixtures::KINGDOM_ORK_ID, $ids);
    }

    public function testAdminGrantKingdomManagerUsesFormFieldsFromAdminPage(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginViaIdpOrSkip($http, $this);

        $email = IntegFixtures::MANAGER_EMAIL;
        $adminPage = $http->get('/admin?email=' . rawurlencode($email));
        $this->assertSame(200, $adminPage->getStatusCode(), (string) $adminPage->getBody());
        $html = (string) $adminPage->getBody();
        $this->assertStringContainsString($email, $html);
        $this->assertStringContainsString(IntegFixtures::MANAGER_IDP_USER_ID, $html);

        $fields = [
            'csrf' => $http->parseCsrfToken($html),
            'idp_user_id' => $http->parseHiddenField($html, 'idp_user_id'),
            'target_email' => $http->parseHiddenField($html, 'target_email'),
            'action' => 'grant-manager',
            'ork_kingdom_id' => (string) IntegFixtures::KINGDOM_ORK_ID,
            'kingdom_name' => IntegFixtures::KINGDOM_NAME,
        ];

        $response = $http->postForm('/admin/grant', $fields);
        $this->assertTrue(
            $http->isRedirectToPath($response, '/admin'),
            'Expected redirect back to admin after grant; status=' . $response->getStatusCode()
            . ' location=' . $response->getHeaderLine('Location'),
        );
        $this->assertSame(302, $response->getStatusCode());

        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString('email=' . rawurlencode($email), $location);
    }
}
