<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;
use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;

/** Live HTTP coverage for kingdom manager settings and enrollment writes (D8). */
final class ManageSettingsEnrollmentTest extends IntegTestCase
{
    private const MANAGE_PREFIX = '/manage/' . IntegFixtures::KINGDOM_SLUG;

    private const TELLER_ACCOUNT_ID = 'acc_integ_1';

    public function testManageSettingsPostUsesCsrfFromManagePage(): void
    {
        $http = $this->loggedInManagerHttp();
        $manageHtml = $this->manageIndexHtml($http);
        $csrf = $http->parseCsrfToken($manageHtml);

        $response = $http->postForm(self::MANAGE_PREFIX . '/settings', [
            'csrf' => $csrf,
            'visibility' => 'registered',
            'display_mode' => 'redacted',
            'embargo_days' => '5',
        ]);
        $this->assertManageRedirect($http, $response);

        $after = $this->manageIndexHtml($http);
        $this->assertStringContainsString('value="registered" selected', $after);
        $this->assertStringContainsString('value="redacted" selected', $after);
        $this->assertStringContainsString('value="5" selected', $after);
    }

    public function testManageEnrollmentPostConnectsTellerWithCsrfFromManagePage(): void
    {
        $http = $this->loggedInManagerHttp();
        $manageHtml = $this->manageIndexHtml($http);
        $csrf = $http->parseCsrfToken($manageHtml);

        $response = $http->postForm(self::MANAGE_PREFIX . '/enrollment', [
            'csrf' => $csrf,
            'enrollment' => $this->tellerEnrollmentJson(),
        ]);
        $this->assertManageRedirect($http, $response);

        $after = $this->manageIndexHtml($http);
        $this->assertStringContainsString('Enrollment status: <span class="fw-semibold">connected</span>', $after);
        $this->assertStringContainsString('Integ Checking', $after);
        $this->assertStringContainsString('action="' . self::MANAGE_PREFIX . '/disconnect"', $after);
    }

    public function testManageAccountsPostUpdatesPublishedFlagsWithCsrfFromManagePage(): void
    {
        $http = $this->loggedInManagerHttp();
        $this->connectTellerEnrollment($http);

        $manageHtml = $this->manageIndexHtml($http);
        $csrf = $http->parseCsrfToken($manageHtml);
        $this->assertStringContainsString('name="published[]" value="' . self::TELLER_ACCOUNT_ID . '"', $manageHtml);

        $response = $http->postForm(self::MANAGE_PREFIX . '/accounts', [
            'csrf' => $csrf,
            'published' => [self::TELLER_ACCOUNT_ID],
        ]);
        $this->assertManageRedirect($http, $response);

        $after = $this->manageIndexHtml($http);
        $this->assertMatchesRegularExpression(
            '/name="published\[\]" value="' . preg_quote(self::TELLER_ACCOUNT_ID, '/') . '"[^>]*checked/',
            $after,
        );
    }

    public function testManageDisconnectPostClearsBankWithCsrfFromManagePage(): void
    {
        $http = $this->loggedInManagerHttp();
        $connectedHtml = $this->connectTellerEnrollment($http);
        $this->assertStringContainsString('Disconnect bank', $connectedHtml);

        $csrf = $http->parseCsrfToken($connectedHtml);
        $response = $http->postForm(self::MANAGE_PREFIX . '/disconnect', ['csrf' => $csrf]);
        $this->assertManageRedirect($http, $response);

        $after = $this->manageIndexHtml($http);
        $this->assertStringContainsString('Enrollment status: <span class="fw-semibold">disconnected</span>', $after);
        $this->assertStringContainsString('Bank access was disconnected', $after);
        $this->assertStringNotContainsString('name="published[]"', $after);
    }

    private function loggedInManagerHttp(): IntegHttp
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        return $http;
    }

    private function manageIndexHtml(IntegHttp $http): string
    {
        $response = $http->get(self::MANAGE_PREFIX);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    private function connectTellerEnrollment(IntegHttp $http): string
    {
        $manageHtml = $this->manageIndexHtml($http);
        $response = $http->postForm(self::MANAGE_PREFIX . '/enrollment', [
            'csrf' => $http->parseCsrfToken($manageHtml),
            'enrollment' => $this->tellerEnrollmentJson(),
        ]);
        $this->assertManageRedirect($http, $response);

        return $this->manageIndexHtml($http);
    }

    private function tellerEnrollmentJson(): string
    {
        return json_encode([
            'provider' => 'teller',
            'accessToken' => 'integ-access-token',
            'id' => 'enr_integ_d8',
            'enrollment' => [
                'id' => 'enr_integ_d8',
                'institution' => ['name' => 'Integ Credit Union'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function assertManageRedirect(IntegHttp $http, \Psr\Http\Message\ResponseInterface $response): void
    {
        $this->assertTrue(
            $http->isRedirectToPath($response, self::MANAGE_PREFIX),
            'Expected redirect to manage index; status=' . $response->getStatusCode()
            . ' location=' . $response->getHeaderLine('Location'),
        );
        $this->assertSame(302, $response->getStatusCode());
    }
}
