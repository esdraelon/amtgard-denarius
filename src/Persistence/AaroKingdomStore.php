<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence;

use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Persistence\Entity\KingdomEntity;
use Amtgard\Denarius\Persistence\Repository\KingdomRepository;
use Amtgard\Denarius\Record\KingdomRecord;

final class AaroKingdomStore implements KingdomStore
{
    use StoreSupport;

    public function findBySlug(string $slug): ?KingdomRecord
    {
        return $this->record($this->kingdoms()->fetchBy('slug', $slug));
    }

    public function findByOrkId(int $orkKingdomId): ?KingdomRecord
    {
        return $this->record($this->kingdoms()->fetchBy('ork_kingdom_id', $orkKingdomId));
    }

    public function findByEnrollmentId(string $enrollmentId): ?KingdomRecord
    {
        return $this->record($this->kingdoms()->fetchBy('enrollment_id', $enrollmentId));
    }

    public function save(KingdomRecord $kingdom): KingdomRecord
    {
        $repo = $this->kingdoms();
        $entity = $kingdom->getId() === null ? $repo->newRepositoryEntity() : $repo->fetch($kingdom->getId());
        if (!$entity instanceof KingdomEntity) {
            throw new \RuntimeException('Kingdom entity was not created.');
        }
        $entity->setOrkKingdomId($kingdom->getOrkKingdomId());
        $entity->setName($kingdom->getName());
        $entity->setSlug($kingdom->getSlug());
        $entity->setVisibility($kingdom->getVisibility());
        $entity->setDisplayMode($kingdom->getDisplayMode());
        $entity->setEnrollmentId($kingdom->getEnrollmentId());
        $entity->setInstitutionName($kingdom->getInstitutionName());
        $entity->setEnrollmentStatus($kingdom->getEnrollmentStatus());
        $entity->setLastSyncedAt($kingdom->getLastSyncedAt());
        $saved = $repo->persist($entity);

        $record = $this->record($saved);
        if ($record === null) {
            throw new \RuntimeException('Kingdom was not saved.');
        }

        return $record;
    }

    public function connected(): array
    {
        $statement = $this->pdo()->query("SELECT id FROM kingdoms WHERE enrollment_status = 'connected' ORDER BY id");
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $record = $this->record($this->kingdoms()->fetch((int) $row['id']));
            if ($record !== null) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    private function kingdoms(): KingdomRepository
    {
        return $this->repo(KingdomRepository::class);
    }

    private function record(mixed $entity): ?KingdomRecord
    {
        if (!$entity instanceof KingdomEntity) {
            return null;
        }

        return KingdomRecord::builder()
            ->id($entity->getId())
            ->orkKingdomId((int) $entity->getOrkKingdomId())
            ->name((string) $entity->getName())
            ->slug((string) $entity->getSlug())
            ->visibility((string) $entity->getVisibility())
            ->displayMode((string) $entity->getDisplayMode())
            ->enrollmentId($entity->getEnrollmentId())
            ->institutionName($entity->getInstitutionName())
            ->enrollmentStatus((string) ($entity->getEnrollmentStatus() ?? 'none'))
            ->lastSyncedAt($entity->getLastSyncedAt())
            ->build();
    }
}
