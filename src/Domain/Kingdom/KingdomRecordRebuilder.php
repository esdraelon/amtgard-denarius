<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Kingdom;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Prototype: rehydrates a kingdom builder from persisted field values. */
final class KingdomRecordRebuilder
{
    public static function from(KingdomRecord $kingdom): mixed
    {
        return DenariusLog::trace(__METHOD__, static function () use ($kingdom): mixed {
            return KingdomRecord::builder()
                ->id($kingdom->getId())
                ->orkKingdomId($kingdom->getOrkKingdomId())
                ->name($kingdom->getName())
                ->slug($kingdom->getSlug())
                ->visibility($kingdom->getVisibility())
                ->displayMode($kingdom->getDisplayMode())
                ->enrollmentId($kingdom->getEnrollmentId())
                ->institutionName($kingdom->getInstitutionName())
                ->provider($kingdom->getProvider())
                ->enrollmentStatus($kingdom->getEnrollmentStatus())
                ->lastSyncedAt($kingdom->getLastSyncedAt())
                ->embargoDays($kingdom->getEmbargoDays())
                ->initialBackfillCompletedAt($kingdom->getInitialBackfillCompletedAt())
                ->amountQuantumCents($kingdom->getAmountQuantumCents())
                ->balanceQuantumFloorCents($kingdom->getBalanceQuantumFloorCents())
                ->balanceQuantumCeilingCents($kingdom->getBalanceQuantumCeilingCents())
                ->balanceQuantumStepCents($kingdom->getBalanceQuantumStepCents());
        });
    }
}
