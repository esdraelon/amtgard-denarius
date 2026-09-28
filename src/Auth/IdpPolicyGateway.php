<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Auth;

use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;

final class IdpPolicyGateway implements PolicyGateway
{
    public function __construct(
        private readonly object $clientIam,
    ) {
    }

    public function ensureFormat(): void
    {
        try {
            $this->clientIam->getServiceFormat();
        } catch (\Throwable) {
            $this->clientIam->createServiceFormat(new ServiceFormatRequest(['Configuration', 'Kingdom']));
        }
    }

    public function listOrns(string $idpUserId): array
    {
        $orns = [];
        foreach ($this->clientIam->listPolicyClaims($idpUserId)->claims as $claim) {
            $orns[] = $claim->fullOrn();
        }

        return $orns;
    }

    public function grant(string $idpUserId, string $orn): void
    {
        $this->clientIam->addPolicyClaimFromOrn($idpUserId, $orn);
    }

    public function revoke(string $idpUserId, string $orn): void
    {
        $claim = $this->clientIam->composeClaim($this->segments($orn), $this->resource($orn));
        $this->clientIam->deletePolicyClaim($idpUserId, $claim);
    }

    /**
     * @return array<string, int>
     */
    private function segments(string $orn): array
    {
        $parts = explode(':', $orn);

        return [
            'Configuration' => (int) ($parts[1] ?? 0),
            'Kingdom' => (int) ($parts[2] ?? 0),
        ];
    }

    private function resource(string $orn): string
    {
        $parts = explode(':', $orn);

        return (string) ($parts[3] ?? '');
    }
}
