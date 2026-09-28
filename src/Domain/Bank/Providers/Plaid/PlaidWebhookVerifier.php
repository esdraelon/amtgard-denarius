<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\Plaid;

final class PlaidWebhookVerifier
{
    public function __construct(
        private readonly PlaidApi $api,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    public function verify(string $body, ?string $header, int $now): bool
    {
        $parts = $this->parts($header);
        if ($parts === null) {
            return false;
        }
        $claims = $this->claims($parts, $now);
        if ($claims === null) {
            return false;
        }

        $expected = $claims['request_body_sha256'] ?? '';
        if (!is_string($expected) || strlen($expected) !== 64) {
            return false;
        }

        return hash_equals($expected, hash('sha256', $body));
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function parts(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }
        $pieces = explode('.', $header);
        if (count($pieces) !== 3 || in_array('', $pieces, true)) {
            return null;
        }

        return [$pieces[0], $pieces[1], $pieces[2]];
    }

    /**
     * @param array{0: string, 1: string, 2: string} $parts
     * @return array<string, mixed>|null
     */
    private function claims(array $parts, int $now): ?array
    {
        $header = $this->json($parts[0]);
        if (($header['alg'] ?? '') !== 'ES256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            return null;
        }
        $key = $this->api->verificationKey($header['kid']);
        if (!$this->current($key, $now) || !$this->signed($parts, $key)) {
            return null;
        }
        $claims = $this->json($parts[1]);
        $issued = $claims['iat'] ?? null;
        if (!is_int($issued) || abs($now - $issued) > $this->toleranceSeconds) {
            return null;
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $key
     */
    private function current(array $key, int $now): bool
    {
        if (($key['kty'] ?? '') !== 'EC' || ($key['crv'] ?? '') !== 'P-256' || ($key['alg'] ?? '') !== 'ES256') {
            return false;
        }
        $expired = $key['expired_at'] ?? null;

        return !is_int($expired) || $expired > $now;
    }

    /**
     * @param array{0: string, 1: string, 2: string} $parts
     * @param array<string, mixed> $key
     */
    private function signed(array $parts, array $key): bool
    {
        $pem = $this->pem($key);
        $der = $this->der($this->segment($parts[2]));
        if ($pem === '' || $der === '') {
            return false;
        }
        $verified = openssl_verify($parts[0] . '.' . $parts[1], $der, $pem, OPENSSL_ALGO_SHA256);

        return $verified === 1;
    }

    /**
     * @param array<string, mixed> $key
     */
    private function pem(array $key): string
    {
        $x = $this->segment((string) ($key['x'] ?? ''));
        $y = $this->segment((string) ($key['y'] ?? ''));
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            return '';
        }
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function der(string $raw): string
    {
        if (strlen($raw) !== 64) {
            return '';
        }
        $body = $this->integer(substr($raw, 0, 32)) . $this->integer(substr($raw, 32));

        return "\x30" . chr(strlen($body)) . $body;
    }

    private function integer(string $part): string
    {
        $part = ltrim($part, "\x00");
        if ($part === '' || (ord($part[0]) & 0x80) !== 0) {
            $part = "\x00" . $part;
        }

        return "\x02" . chr(strlen($part)) . $part;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $segment): array
    {
        $decoded = json_decode($this->segment($segment), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function segment(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : '';
    }
}
