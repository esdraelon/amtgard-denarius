<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl;

/** DEV_INTEG: fixed Plaid webhook verification JWK (paired with integ HTTP tests). */
final class IntegPlaidVerificationKey
{
    private const PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgHbRQH1zwpmBb6l7j
sHlGc5dLBaujtaI4Zd2eZfV2xmahRANCAASXU+QgoNTvchplXOmVTrFtRaCE7zBF
s5wIlNbFkXQpQFS2h0+u6DqmKCnZwtQYS3Op7DN43k+hcIex+qK+fEsX
-----END PRIVATE KEY-----
PEM;

    public static function privateKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private(self::PEM);
        if ($key === false) {
            throw new \RuntimeException('Unable to load integ Plaid verification private key.');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    public static function jwk(): array
    {
        $details = openssl_pkey_get_details(self::privateKey());
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('Unable to derive integ Plaid verification JWK.');
        }

        return [
            'alg' => 'ES256',
            'crv' => 'P-256',
            'kid' => 'integ_plaid_kid',
            'kty' => 'EC',
            'use' => 'sig',
            'x' => rtrim(strtr(base64_encode($details['ec']['x']), '+/', '-_'), '='),
            'y' => rtrim(strtr(base64_encode($details['ec']['y']), '+/', '-_'), '='),
            'expired_at' => null,
        ];
    }
}
