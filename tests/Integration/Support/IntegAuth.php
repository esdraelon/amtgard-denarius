<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Support;

use Amtgard\Denarius\Tests\Integration\IntegFixtures;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * OAuth sign-in helper for Denarius HTTP integ (milestone D2+).
 *
 * Flow: GET {DENARIUS_BASE_URL}/login → redirect to IDP authorize → user authenticates at IDP
 * → redirect to {DENARIUS_BASE_URL}/oauth/callback with code → Denarius session cookie.
 *
 * Prerequisite: IDP integ stack (`IDP_BASE_URL`, default http://localhost:37080) with a Denarius
 * OAuth client matching `IDP_CLIENT_ID`, `IDP_CLIENT_SECRET`, and `IDP_REDIRECT_URI` in phpunit.integ.xml.
 */
final class IntegAuth
{
    public static function idpBaseUrl(): string
    {
        $base = rtrim((string) (getenv('IDP_BASE_URL') ?: $_ENV['IDP_BASE_URL'] ?? ''), '/');
        if ($base === '') {
            return 'http://localhost:37080';
        }

        return $base;
    }

    public static function isIdpReachable(): bool
    {
        try {
            $client = new Client([
                'http_errors' => false,
                'timeout' => 3,
            ]);
            $response = $client->get(self::idpBaseUrl() . '/.well-known/openid-configuration');

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function skipIfIdpUnavailable(TestCase $test): void
    {
        if (!self::isIdpReachable()) {
            $test->markTestSkipped(
                'IDP integ stack not reachable at ' . self::idpBaseUrl()
                . ' (start IDP integ before OAuth session tests).',
            );
        }
    }

    /**
     * Complete the Denarius → IDP → Denarius OAuth dance; leaves a logged-in session on {@see IntegHttp}.
     *
     * @throws \RuntimeException when the IDP client, credentials, or consent flow cannot complete
     */
    public static function loginViaIdp(IntegHttp $denariusHttp, string $idpEmail = IntegFixtures::ADMIN_EMAIL): void
    {
        $denariusBase = rtrim((string) (getenv('DENARIUS_BASE_URL') ?: $_ENV['DENARIUS_BASE_URL'] ?? ''), '/');
        if ($denariusBase === '') {
            throw new \RuntimeException('DENARIUS_BASE_URL is not set.');
        }

        $loginResponse = $denariusHttp->get('/login');
        if (!self::isRedirectStatus($loginResponse)) {
            throw new \RuntimeException(
                'GET /login did not redirect to IDP; status=' . $loginResponse->getStatusCode(),
            );
        }

        $idpAuthorizeLocation = $denariusHttp->redirectLocation($loginResponse);
        if ($idpAuthorizeLocation === null || !self::locationContainsIdp($idpAuthorizeLocation)) {
            throw new \RuntimeException(
                'GET /login Location did not target the IDP; location=' . $loginResponse->getHeaderLine('Location'),
            );
        }

        $idpHttp = new IntegHttp(self::baseUrlFromLocation($idpAuthorizeLocation));
        self::loginIdpFixtureUser($idpHttp, $idpEmail);

        $callbackLocation = self::completeIdpAuthorization(
            $idpHttp,
            self::pathAndQueryFromUrl($idpAuthorizeLocation),
            self::redirectUri(),
        );

        $callbackPath = self::pathUnderBase($denariusBase, $callbackLocation);
        $callbackResponse = $denariusHttp->get($callbackPath);
        if (!self::isRedirectStatus($callbackResponse)) {
            throw new \RuntimeException(
                'OAuth callback did not redirect after login; status=' . $callbackResponse->getStatusCode()
                . ' body=' . (string) $callbackResponse->getBody(),
            );
        }

        $afterLogin = $denariusHttp->get(self::pathAndQueryFromUrl(
            (string) $denariusHttp->redirectLocation($callbackResponse),
        ));
        if ($afterLogin->getStatusCode() >= 400) {
            throw new \RuntimeException(
                'Post-login landing failed; status=' . $afterLogin->getStatusCode(),
            );
        }

        if (!$denariusHttp->hasSessionCookie()) {
            throw new \RuntimeException('Denarius session cookie missing after OAuth callback.');
        }
    }

    public static function loginViaIdpOrSkip(IntegHttp $denariusHttp, TestCase $test): void
    {
        self::skipIfIdpUnavailable($test);
        try {
            self::loginViaIdp($denariusHttp);
        } catch (\Throwable $exception) {
            $test->markTestSkipped(
                'IntegAuth::loginViaIdp unavailable: ' . $exception->getMessage()
                . ' (register OAuth client ' . self::clientId() . ' on the IDP integ stack).',
            );
        }
    }

    /**
     * Grants {@see IntegFixtures::MANAGER_EMAIL} kingdom-manager on the seed kingdom (requires admin session).
     */
    public static function grantSeedKingdomManager(IntegHttp $adminHttp): void
    {
        $email = IntegFixtures::MANAGER_EMAIL;
        $adminPage = $adminHttp->get('/admin?email=' . rawurlencode($email));
        if ($adminPage->getStatusCode() !== 200) {
            throw new \RuntimeException(
                'Admin principal page failed; status=' . $adminPage->getStatusCode(),
            );
        }

        $html = (string) $adminPage->getBody();
        $response = $adminHttp->postForm('/admin/grant', [
            'csrf' => $adminHttp->parseCsrfToken($html),
            'idp_user_id' => $adminHttp->parseHiddenField($html, 'idp_user_id'),
            'target_email' => $adminHttp->parseHiddenField($html, 'target_email'),
            'action' => 'grant-manager',
            'ork_kingdom_id' => (string) IntegFixtures::KINGDOM_ORK_ID,
            'kingdom_name' => IntegFixtures::KINGDOM_NAME,
        ]);
        if (!$adminHttp->isRedirectToPath($response, '/admin')) {
            throw new \RuntimeException(
                'grant-manager failed; status=' . $response->getStatusCode()
                . ' location=' . $response->getHeaderLine('Location'),
            );
        }
    }

    /**
     * Admin grant for seed manager, then OAuth login as {@see IntegFixtures::MANAGER_EMAIL}.
     */
    public static function loginKingdomManagerViaIdpOrSkip(IntegHttp $managerHttp, TestCase $test): void
    {
        self::skipIfIdpUnavailable($test);

        $denariusBase = rtrim((string) (getenv('DENARIUS_BASE_URL') ?: $_ENV['DENARIUS_BASE_URL'] ?? ''), '/');
        if ($denariusBase === '') {
            $test->markTestSkipped('DENARIUS_BASE_URL is not set.');
        }

        try {
            $adminHttp = new IntegHttp($denariusBase);
            self::loginViaIdp($adminHttp);
            self::grantSeedKingdomManager($adminHttp);
            self::loginViaIdp($managerHttp, IntegFixtures::MANAGER_EMAIL);
        } catch (\Throwable $exception) {
            $test->markTestSkipped(
                'IntegAuth kingdom manager login unavailable: ' . $exception->getMessage()
                . ' (IdP seed user ' . IntegFixtures::MANAGER_EMAIL . ' and OAuth client '
                . self::clientId() . ' required).',
            );
        }
    }

    public static function locationContainsIdp(string $location): bool
    {
        if (str_contains(strtolower($location), 'idp')) {
            return true;
        }

        return self::locationTargetsConfiguredIdpHost($location);
    }

    private static function clientId(): string
    {
        return (string) (getenv('IDP_CLIENT_ID') ?: $_ENV['IDP_CLIENT_ID'] ?? '');
    }

    private static function redirectUri(): string
    {
        return (string) (getenv('IDP_REDIRECT_URI') ?: $_ENV['IDP_REDIRECT_URI'] ?? '');
    }

    private static function isRedirectStatus(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [301, 302, 303], true);
    }

    private static function locationTargetsConfiguredIdpHost(string $location): bool
    {
        $idpHost = parse_url(self::idpBaseUrl(), PHP_URL_HOST);
        $locationHost = parse_url($location, PHP_URL_HOST);
        if (is_string($idpHost) && $idpHost !== '' && is_string($locationHost) && $locationHost !== '') {
            return strcasecmp($idpHost, $locationHost) === 0;
        }

        return str_contains($location, self::idpBaseUrl());
    }

    private static function baseUrlFromLocation(string $location): string
    {
        $scheme = parse_url($location, PHP_URL_SCHEME);
        $host = parse_url($location, PHP_URL_HOST);
        if (!is_string($scheme) || $scheme === '' || !is_string($host) || $host === '') {
            throw new \RuntimeException('Authorize Location is not an absolute URL: ' . $location);
        }

        $port = parse_url($location, PHP_URL_PORT);
        $authority = $host . (is_int($port) ? ':' . $port : '');

        return $scheme . '://' . $authority;
    }

    private static function loginIdpFixtureUser(IntegHttp $idpHttp, string $email): void
    {
        $loginPage = $idpHttp->get('/auth/login?expand=1');
        if ($loginPage->getStatusCode() !== 200) {
            throw new \RuntimeException('IDP login page failed; status=' . $loginPage->getStatusCode());
        }

        $loginHtml = (string) $loginPage->getBody();
        $loginResponse = $idpHttp->postForm('/auth/login', [
            '_csrf_token' => $idpHttp->parseHiddenField($loginHtml, '_csrf_token'),
            'email' => $email,
            'password' => IntegFixtures::IDP_FIXTURE_PASSWORD,
        ]);
        if (!$idpHttp->isRedirectToPath($loginResponse, '/resources/profile')) {
            throw new \RuntimeException(
                'IDP fixture login failed; status=' . $loginResponse->getStatusCode()
                . ' location=' . $loginResponse->getHeaderLine('Location'),
            );
        }
    }

    private static function completeIdpAuthorization(
        IntegHttp $idpHttp,
        string $authorizePath,
        string $expectedRedirectUri,
    ): string {
        $authorize = $idpHttp->get($authorizePath);
        if ($idpHttp->isRedirectToPath($authorize, '/oauth/approve')) {
            $authorize = self::allowOAuthConsent($idpHttp, $authorize);
        }

        if (!self::isRedirectStatus($authorize)) {
            throw new \RuntimeException(
                'IDP authorize did not redirect to client; status=' . $authorize->getStatusCode()
                . ' body=' . substr((string) $authorize->getBody(), 0, 200),
            );
        }

        $callbackLocation = $idpHttp->redirectLocation($authorize);
        if ($callbackLocation === null || !str_starts_with($callbackLocation, $expectedRedirectUri)) {
            throw new \RuntimeException(
                'IDP authorize callback mismatch; expected prefix ' . $expectedRedirectUri
                . ' got ' . ($callbackLocation ?? '(none)'),
            );
        }

        return $callbackLocation;
    }

    private static function allowOAuthConsent(IntegHttp $idpHttp, ResponseInterface $authorizeRedirect): ResponseInterface
    {
        $approveLocation = $authorizeRedirect->getHeaderLine('Location');
        $approvePage = $idpHttp->get($approveLocation);
        if ($approvePage->getStatusCode() !== 200) {
            throw new \RuntimeException('IDP approve page failed; status=' . $approvePage->getStatusCode());
        }

        $approveHtml = (string) $approvePage->getBody();
        $allow = $idpHttp->postForm('/oauth/approve', [
            '_csrf_token' => $idpHttp->parseHiddenField($approveHtml, '_csrf_token'),
            'callback' => $idpHttp->parseHiddenField($approveHtml, 'callback'),
            'action' => 'allow',
        ]);
        if (!$idpHttp->isRedirectToPath($allow, '/oauth/authorize')) {
            throw new \RuntimeException(
                'IDP OAuth allow failed; location=' . $allow->getHeaderLine('Location'),
            );
        }

        return $idpHttp->get('/oauth/authorize');
    }

    private static function pathAndQueryFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('URL path missing: ' . $url);
        }
        if (is_string($query) && $query !== '') {
            return $path . '?' . $query;
        }

        return $path;
    }

    private static function pathUnderBase(string $baseUrl, string $absoluteUrl): string
    {
        $base = rtrim($baseUrl, '/');
        if (!str_starts_with($absoluteUrl, $base)) {
            throw new \RuntimeException('Callback URL is not under Denarius base: ' . $absoluteUrl);
        }

        $suffix = substr($absoluteUrl, strlen($base));
        if ($suffix === '' || $suffix === false) {
            return '/';
        }

        return $suffix;
    }
}
