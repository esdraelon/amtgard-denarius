<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Contract\PrincipalStore;
use Amtgard\Denarius\Record\PrincipalRecord;

final class PrincipalSync
{
    public function __construct(
        private readonly PrincipalStore $principals,
    ) {
    }

    public function upsert(string $idpUserId, string $email, ?int $orkKingdomId, ?string $orkKingdomName): PrincipalRecord
    {
        $existing = $this->principals->findByIdpUserId($idpUserId);

        return $this->principals->save(PrincipalRecord::builder()
            ->id($existing?->getId())
            ->idpUserId($idpUserId)
            ->email($email)
            ->orkKingdomId($orkKingdomId)
            ->orkKingdomName($orkKingdomName)
            ->build());
    }
}
