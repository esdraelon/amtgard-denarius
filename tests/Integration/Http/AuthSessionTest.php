<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Http;

use Amtgard\Denarius\Tests\Integration\IntegTestCase;
use Amtgard\Denarius\Tests\Integration\Support\IntegAuth;

/** Live HTTP coverage for login, logout, and unauthenticated admin gate (D2). */
final class AuthSessionTest extends IntegTestCase
{
    public function testLoginRedirectsToIdpAuthorize(): void
    {
        IntegAuth::skipIfIdpUnavailable($this);

        $response = $this->integHttp()->get('/login');
        $this->assertContains(
            $response->getStatusCode(),
            [302, 303],
            'GET /login must redirect to IDP; body=' . (string) $response->getBody(),
        );

        $location = $response->getHeaderLine('Location');
        $this->assertNotSame('', $location, 'Redirect Location header is required');
        $this->assertTrue(
            IntegAuth::locationContainsIdp($location),
            'Location must target the IDP; location=' . $location,
        );
        $this->assertStringContainsString(
            'oauth/authorize',
            $location,
            'Location must be an OAuth authorize URL',
        );
    }

    public function testLogoutClearsSessionWhenLoggedIn(): void
    {
        $http = $this->integHttp();
        IntegAuth::loginViaIdpOrSkip($http, $this);
        $this->assertTrue($http->hasSessionCookie(), 'Expected session before logout');

        $logout = $http->get('/logout');
        $this->assertContains($logout->getStatusCode(), [302, 303]);
        $this->assertTrue(
            $http->isRedirectToPath($logout, '/'),
            'Logout must redirect home; location=' . $logout->getHeaderLine('Location'),
        );

        $admin = $http->get('/admin');
        $this->assertTrue(
            $http->isRedirectToPath($admin, '/login'),
            'Logged-out GET /admin must redirect to login; status=' . $admin->getStatusCode()
            . ' location=' . $admin->getHeaderLine('Location'),
        );
    }

    public function testLoggedOutAdminRedirectsToLogin(): void
    {
        $http = $this->integHttp();
        $this->assertFalse($http->hasSessionCookie(), 'Test must start without a session cookie');

        $response = $http->get('/admin');
        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertTrue(
            $http->isRedirectToPath($response, '/login'),
            'Unauthenticated GET /admin must redirect to login; location=' . $response->getHeaderLine('Location'),
        );
    }
}
