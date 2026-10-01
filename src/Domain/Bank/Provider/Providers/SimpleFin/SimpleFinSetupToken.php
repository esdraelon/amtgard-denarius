<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class SimpleFinSetupToken
{
    private function __construct(private readonly string $claimUrl)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public static function decode(string $base64Token): self
    {
        return DenariusLog::trace(__METHOD__, static function () use ($base64Token): self {
            $token = trim($base64Token);
            if ($token === '') {
                throw new \InvalidArgumentException('SimpleFIN setup token is empty.');
            }
            $decoded = base64_decode($token, true);
            $url = is_string($decoded) ? trim($decoded) : '';
            if ($url === '' || !str_starts_with($url, 'http')) {
                throw new \InvalidArgumentException('SimpleFIN setup token is not a valid claim URL.');
            }

            return new self($url);
        });
    }

    public function claimUrl(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return $this->claimUrl;
        });
    }

    public static function forbidden(string $body): bool
    {
        return DenariusLog::trace(__METHOD__, static function () use ($body): bool {
            return str_starts_with(trim($body), 'Forbidden');
        });
    }
}
