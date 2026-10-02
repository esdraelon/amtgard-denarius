<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Kingdom;

use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Access\Visibility;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class KingdomSettings
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function update(KingdomRecord $kingdom, Visibility $visibility, DisplayMode $displayMode): KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $visibility, $displayMode): KingdomRecord {
            return $this->kingdoms->save(KingdomRecord::builder()
                ->id($kingdom->getId())
                ->orkKingdomId($kingdom->getOrkKingdomId())
                ->name($kingdom->getName())
                ->slug($kingdom->getSlug())
                ->visibility($visibility->value)
                ->displayMode($displayMode->value)
                ->enrollmentId($kingdom->getEnrollmentId())
                ->institutionName($kingdom->getInstitutionName())
                ->provider($kingdom->getProvider())
                ->enrollmentStatus($kingdom->getEnrollmentStatus())
                ->lastSyncedAt($kingdom->getLastSyncedAt())
                ->build());
        });
    }
}
