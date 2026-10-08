<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration\Support;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

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

    /** @throws \RuntimeException until milestone D2 implements the browser-style OAuth dance */
    public static function loginViaIdp(IntegHttp $denariusHttp): void
    {
        throw new \RuntimeException('IntegAuth::loginViaIdp is not implemented yet; use milestone D2 auth-session tests.');
    }
}
