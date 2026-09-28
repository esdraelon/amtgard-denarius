<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Persistence\Repository\KingdomRepositoryInterface;
use Amtgard\Denarius\Domain\DisplayMode;
use Amtgard\Denarius\Domain\Visibility;
use Amtgard\Denarius\Record\KingdomRecord;

final class KingdomSettings
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
    ) {
    }

    public function update(KingdomRecord $kingdom, Visibility $visibility, DisplayMode $displayMode): KingdomRecord
    {
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
    }
}
