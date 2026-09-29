<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Access;

use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class PrincipalSync
{
    public function __construct(
        private readonly PrincipalRepositoryInterface $principals,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function upsert(string $idpUserId, string $email, ?int $orkKingdomId, ?string $orkKingdomName): PrincipalRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId, $email, $orkKingdomId, $orkKingdomName): PrincipalRecord {
            $existing = $this->principals->findByIdpUserId($idpUserId);

            return $this->principals->save(PrincipalRecord::builder()
                ->id($existing?->getId())
                ->idpUserId($idpUserId)
                ->email($email)
                ->orkKingdomId($orkKingdomId)
                ->orkKingdomName($orkKingdomName)
                ->build());
        });
    }
}
