<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

use Amtgard\Denarius\Record\KingdomRecord;

interface KingdomStore
{
    public function findBySlug(string $slug): ?KingdomRecord;

    public function findByOrkId(int $orkKingdomId): ?KingdomRecord;

    public function findByEnrollmentId(string $enrollmentId): ?KingdomRecord;

    public function save(KingdomRecord $kingdom): KingdomRecord;

    /**
     * @return list<KingdomRecord>
     */
    public function connected(): array;
}
