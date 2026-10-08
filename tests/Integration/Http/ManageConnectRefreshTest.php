<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;
use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;

/** Live HTTP coverage for manage bank connect mount and ledger refresh (D12). */
final class ManageConnectRefreshTest extends IntegTestCase
{
    private const MANAGE_PREFIX = '/manage/' . IntegFixtures::KINGDOM_SLUG;

    private const SYNC_SUCCEEDED_LABEL = 'Last check succeeded';

    public function testManageConnectPostMountsTellerStubFromManagePage(): void
    {
        $http = $this->loggedInManagerHttp();
        $manageHtml = $this->manageIndexHtml($http);
        $this->assertStringContainsString('Add bank', $manageHtml);

        $response = $http->postForm(self::MANAGE_PREFIX . '/connect', [
            'csrf' => $http->parseCsrfToken($manageHtml),
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Connect with Teller', $body);
        $this->assertStringContainsString('app_test', $body);
        $this->assertStringContainsString('sandbox', $body);
        $this->assertStringContainsString('Try another provider', $body);
    }

    public function testManageRefreshPostQueuesLedgerWorkerAndSyncSucceeds(): void
    {
        $http = $this->loggedInManagerHttp();
        $connectedHtml = $this->connectTellerEnrollment($http);
        $this->assertStringContainsString('Enrollment status: <span class="fw-semibold">connected</span>', $connectedHtml);

        $csrf = $http->parseCsrfToken($connectedHtml);
        $response = $http->postForm(self::MANAGE_PREFIX . '/refresh', ['csrf' => $csrf]);
        $this->assertManageRedirect($http, $response);

        $afterRefresh = $this->waitForLedgerSyncLabel($http, self::SYNC_SUCCEEDED_LABEL);
        $this->assertStringContainsString(self::SYNC_SUCCEEDED_LABEL, $afterRefresh);
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
            'id' => 'enr_integ_d12',
            'enrollment' => [
                'id' => 'enr_integ_d12',
                'institution' => ['name' => 'Integ Credit Union'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function waitForLedgerSyncLabel(IntegHttp $http, string $label): string
    {
        $deadline = microtime(true) + 45.0;
        while (microtime(true) < $deadline) {
            $html = $this->manageIndexHtml($http);
            if (str_contains($html, $label)) {
                return $html;
            }
            usleep(500_000);
        }

        $this->fail('Timed out waiting for ledger sync label "' . $label . '" on manage page.');
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
