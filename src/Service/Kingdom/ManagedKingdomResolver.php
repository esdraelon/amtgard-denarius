<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Kingdom;

use Amtgard\Denarius\Domain\Kingdom\KingdomSlug;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Ensures a Denarius kingdom row exists for an ORK id the actor may manage. */
final class ManagedKingdomResolver
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly OrkKingdomDirectory $orkDirectory,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function resolve(int $orkKingdomId): ?KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($orkKingdomId): ?KingdomRecord {
            $existing = $this->kingdoms->findByOrkId($orkKingdomId);
            if ($existing !== null) {
                return $existing;
            }

            $name = $this->orkDirectory->nameForOrkId($orkKingdomId);
            if ($name === null) {
                return null;
            }

            $slug = KingdomSlug::fromName($name);
            if ($slug === '' || KingdomSlug::isReserved($slug)) {
                DenariusLog::infoBranch('kingdom_record_provision_skipped', __METHOD__, [
                    'ork_kingdom_id' => $orkKingdomId,
                    'reason' => 'slug',
                ]);

                return null;
            }

            $saved = $this->kingdoms->save(KingdomRecord::builder()
                ->orkKingdomId($orkKingdomId)
                ->name($name)
                ->slug($slug)
                ->build());

            DenariusLog::infoBranch('kingdom_record_provisioned', __METHOD__, [
                'ork_kingdom_id' => $orkKingdomId,
                'slug' => $slug,
            ]);

            return $saved;
        });
    }
}
