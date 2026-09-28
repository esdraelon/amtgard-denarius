<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Kingdom;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;

interface KingdomRepositoryInterface
{
    public function findBySlug(string $slug): ?KingdomRecord;

    public function findByOrkId(int $orkKingdomId): ?KingdomRecord;

    public function findByEnrollmentId(string $enrollmentId): ?KingdomRecord;

    public function findByProviderEnrollment(string $provider, string $enrollmentId): ?KingdomRecord;

    public function save(KingdomRecord $kingdom): KingdomRecord;

    /**
     * @return list<KingdomRecord>
     */
    public function connected(): array;
}
