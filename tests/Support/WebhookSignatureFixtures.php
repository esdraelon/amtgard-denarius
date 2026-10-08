<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\IntegPlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\IntegPlaidVerificationKey;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidWebhookVerifier;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeWebhookVerifier;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerWebhookVerifier;

/** HMAC and Plaid JWT signatures aligned with ledger webhook verifiers (unit + integ). */
final class WebhookSignatureFixtures
{
    public const DEFAULT_HMAC_SECRET = 'whsec_test';

    public static function tellerSignature(string $body, ?int $timestamp = null): string
    {
        return self::hmacSignature(self::hmacSecret(), $body, $timestamp);
    }

    public static function stripeSignature(string $body, ?int $timestamp = null): string
    {
        return self::hmacSignature(self::hmacSecret(), $body, $timestamp);
    }

    public static function plaidVerificationJwt(string $body, ?int $issuedAt = null): string
    {
        $iat = $issuedAt ?? time();
        $header = ['alg' => 'ES256', 'kid' => 'integ_plaid_kid', 'typ' => 'JWT'];
        $claims = ['iat' => $iat, 'request_body_sha256' => hash('sha256', $body)];

        return self::es256Jwt($header, $claims, IntegPlaidVerificationKey::privateKey());
    }

    public static function assertTellerVerifierAccepts(string $body, string $signature, int $now): void
    {
        if (!(new TellerWebhookVerifier(self::hmacSecret(), 300))->verify($body, $signature, $now)) {
            throw new \RuntimeException('Teller webhook signature rejected by verifier.');
        }
    }

    public static function assertStripeVerifierAccepts(string $body, string $signature, int $now): void
    {
        if (!(new StripeWebhookVerifier(self::hmacSecret(), 300))->verify($body, $signature, $now)) {
            throw new \RuntimeException('Stripe webhook signature rejected by verifier.');
        }
    }

    public static function assertPlaidVerifierAccepts(string $body, string $jwt, int $now): void
    {
        if (!(new PlaidWebhookVerifier(new IntegPlaidApi(), 300))->verify($body, $jwt, $now)) {
            throw new \RuntimeException('Plaid webhook JWT rejected by verifier.');
        }
    }

    public static function hmacSecret(): string
    {
        foreach (['TELLER_WEBHOOK_SECRET', 'STRIPE_WEBHOOK_SECRET'] as $key) {
            $fromGetenv = getenv($key);
            if (is_string($fromGetenv) && $fromGetenv !== '') {
                return $fromGetenv;
            }
            $fromEnv = $_ENV[$key] ?? null;
            if (is_string($fromEnv) && $fromEnv !== '') {
                return $fromEnv;
            }
        }

        return self::DEFAULT_HMAC_SECRET;
    }

    public static function hmacSignature(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    private static function es256Jwt(array $header, array $claims, \OpenSSLAsymmetricKey $key): string
    {
        $encoded = self::b64((string) json_encode($header, JSON_THROW_ON_ERROR))
            . '.'
            . self::b64((string) json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($encoded, $der, $key, OPENSSL_ALGO_SHA256);

        return $encoded . '.' . self::b64(self::rawSignature($der));
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function rawSignature(string $der): string
    {
        $offset = ord($der[1]) === 0x81 ? 3 : 2;
        $rLength = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $offset += 2 + $rLength;
        $sLength = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
