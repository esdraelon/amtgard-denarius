<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Security;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class TokenCipher
{
    public function __construct(
        private readonly string $appKey,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function encrypt(string $plain): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($plain): string {
            $key = $this->key();
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) {
                throw new \RuntimeException('Unable to encrypt enrollment token.');
            }

            return base64_encode($iv . $tag . $cipher);
        });
    }

    public function decrypt(string $payload): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload): string {
            $raw = base64_decode($payload, true);
            if ($raw === false || strlen($raw) < 28) {
                throw new \RuntimeException('Enrollment token payload is invalid.');
            }

            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) {
                throw new \RuntimeException('Unable to decrypt enrollment token.');
            }

            return $plain;
        });
    }

    private function key(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return hash('sha256', $this->appKey, true);
        });
    }
}
