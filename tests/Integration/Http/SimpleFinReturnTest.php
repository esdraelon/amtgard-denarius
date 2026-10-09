<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;
use Amtgard\Denarius\Tests\Integration\Support\IntegHttp;

/** Live HTTP coverage for SimpleFIN return GET/POST (D11, `IntegSimpleFinApi`). */
final class SimpleFinReturnTest extends IntegTestCase
{
    private const MANAGE_PREFIX = '/manage/' . IntegFixtures::KINGDOM_SLUG;

    private const RETURN_PATH = '/bank/simplefin/return';

    private const INTEG_ACCOUNT_NAME = 'Integ SimpleFIN Checking';

    public function testGetReturnShowsFormAndClaimsViaQueryToken(): void
    {
        $http = $this->loggedInManagerHttp();

        $formResponse = $http->get(self::RETURN_PATH);
        $this->assertSame(200, $formResponse->getStatusCode(), (string) $formResponse->getBody());
        $formHtml = (string) $formResponse->getBody();
        $this->assertStringContainsString('Finish SimpleFIN connection', $formHtml);
        $this->assertStringContainsString('name="setup_token"', $formHtml);

        $token = $this->setupTokenForClaim('integ_d11_get');
        $claimResponse = $http->get(self::RETURN_PATH . '?' . http_build_query([
            'setup_token' => $token,
            'kingdom' => IntegFixtures::KINGDOM_SLUG,
        ]));
        $this->assertReturnRedirectToManage($http, $claimResponse);
        $this->assertManageShowsSimpleFinConnected($http);
    }

    public function testPostReturnClaimsViaFormCsrfAfterConnectRemember(): void
    {
        $http = $this->loggedInManagerHttp();
        $this->rememberSimpleFinKingdomViaConnect($http);

        $formResponse = $http->get(self::RETURN_PATH);
        $this->assertSame(200, $formResponse->getStatusCode(), (string) $formResponse->getBody());
        $formHtml = (string) $formResponse->getBody();
        $csrf = $http->parseCsrfToken($formHtml);

        $postResponse = $http->postForm(self::RETURN_PATH, [
            'csrf' => $csrf,
            'setup_token' => $this->setupTokenForClaim('integ_d11_post'),
        ]);
        $this->assertReturnRedirectToManage($http, $postResponse);
        $this->assertManageShowsSimpleFinConnected($http);
    }

    private function loggedInManagerHttp(): IntegHttp
    {
        $http = $this->integHttp();
        IntegAuth::loginKingdomManagerViaIdpOrSkip($http, $this);

        return $http;
    }

    private function rememberSimpleFinKingdomViaConnect(IntegHttp $http): void
    {
        $manageHtml = $this->manageIndexHtml($http);
        $csrf = $http->parseCsrfToken($manageHtml);

        $tellerHtml = $this->connectPostHtml($http, ['csrf' => $csrf]);
        $this->assertStringContainsString('Connect with Teller', $tellerHtml);

        $simplefinHtml = $this->connectPostHtml($http, [
            'csrf' => $http->parseCsrfToken($tellerHtml),
            'current' => 'teller',
            'skip' => '1',
        ]);
        $this->assertStringContainsString('simplefin-connect', $simplefinHtml);
    }

    /** @param array<string, string> $fields */
    private function connectPostHtml(IntegHttp $http, array $fields): string
    {
        $response = $http->postForm(self::MANAGE_PREFIX . '/connect', $fields);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    private function manageIndexHtml(IntegHttp $http): string
    {
        $response = $http->get(self::MANAGE_PREFIX);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    private function setupTokenForClaim(string $suffix): string
    {
        return base64_encode('https://bridge.simplefin.org/simplefin/claim/' . $suffix);
    }

    private function assertReturnRedirectToManage(IntegHttp $http, \Psr\Http\Message\ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue(
            $http->isRedirectToPath($response, self::MANAGE_PREFIX),
            'Expected redirect to manage index; location=' . $response->getHeaderLine('Location'),
        );
    }

    private function assertManageShowsSimpleFinConnected(IntegHttp $http): void
    {
        $manageHtml = $this->manageIndexHtml($http);
        $this->assertStringContainsString('Enrollment status: <span class="fw-semibold">connected</span>', $manageHtml);
        $this->assertStringContainsString(self::INTEG_ACCOUNT_NAME, $manageHtml);
        $this->assertStringContainsString('action="' . self::MANAGE_PREFIX . '/disconnect"', $manageHtml);
    }
}
