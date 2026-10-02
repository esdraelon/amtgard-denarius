<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Auth\Impl;

use Amtgard\Denarius\Utilities\Auth\PolicyGateway;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;

final class IdpPolicyGateway implements PolicyGateway
{
    public function __construct(
        private readonly object $clientIam,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function ensureFormat(): void
    {
        DenariusLog::trace(__METHOD__, function (): mixed {
            try {
                $this->clientIam->getServiceFormat();
            } catch (\Throwable) {
                $this->clientIam->createServiceFormat(new ServiceFormatRequest(['Configuration', 'Kingdom']));
            }

            return null;
        });
    }

    public function listOrns(string $idpUserId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId): array {
            $orns = [];
            foreach ($this->clientIam->listPolicyClaims($idpUserId)->claims as $claim) {
                $orns[] = $claim->fullOrn();
            }

            return $orns;
        });
    }

    public function grant(string $idpUserId, string $orn): void
    {
        DenariusLog::trace(__METHOD__, function () use ($idpUserId, $orn): mixed {
            $this->clientIam->addPolicyClaimFromOrn($idpUserId, $orn);

            return null;
        });
    }

    public function revoke(string $idpUserId, string $orn): void
    {
        DenariusLog::trace(__METHOD__, function () use ($idpUserId, $orn): mixed {
            $claim = $this->clientIam->composeClaim($this->segments($orn), $this->resource($orn));
            $this->clientIam->deletePolicyClaim($idpUserId, $claim);

            return null;
        });
    }

    /**
     * @return array<string, int>
     */
    private function segments(string $orn): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($orn): array {
            $parts = explode(':', $orn);

            return [
                'Configuration' => (int) ($parts[1] ?? 0),
                'Kingdom' => (int) ($parts[2] ?? 0),
            ];
        });
    }

    private function resource(string $orn): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($orn): string {
            $parts = explode(':', $orn);

            return (string) ($parts[3] ?? '');
        });
    }
}
