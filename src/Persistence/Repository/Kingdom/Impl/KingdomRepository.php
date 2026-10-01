<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Kingdom\Impl;

use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Entity\KingdomEntity;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;

#[RepositoryOf('kingdoms', KingdomEntity::class)]
class KingdomRepository extends Repository implements EntityRepositoryInterface, KingdomRepositoryInterface
{
    public static function getTableName(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return 'kingdoms';
        });
    }

    public static function getEntityClass(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return KingdomEntity::class;
        });
    }

    public function findBySlug(string $slug): ?KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($slug): ?KingdomRecord {
            return $this->record($this->fetchBy('slug', $slug));
        });
    }

    public function findByOrkId(int $orkKingdomId): ?KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($orkKingdomId): ?KingdomRecord {
            return $this->record($this->fetchBy('ork_kingdom_id', $orkKingdomId));
        });
    }

    public function findByEnrollmentId(string $enrollmentId): ?KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($enrollmentId): ?KingdomRecord {
            return $this->record($this->fetchBy('enrollment_id', $enrollmentId));
        });
    }

    public function findByProviderEnrollment(string $provider, string $enrollmentId): ?KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($provider, $enrollmentId): ?KingdomRecord {
            $this->clear();
            $this->provider = $provider;
            $this->enrollment_id = $enrollmentId;
            if ($this->find() === 0 || !$this->next()) {
                return null;
            }

            return $this->record($this->getCurrent());
        });
    }

    public function save(KingdomRecord $kingdom): KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): KingdomRecord {
            $entity = $this->entity($kingdom);
            $this->fill($entity, $kingdom);
            return Optional::ofNullable($this->record($this->persist($entity)))
                ->orElseThrow(new \RuntimeException('Kingdom was not saved.'));
        });
    }

    public function connected(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $this->clear();
            $this->enrollment_status = 'connected';
            $this->orderBy('id', OrderBy::ASC);

            return $this->collected();
        });
    }

    public function all(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $this->clear();
            $this->orderBy('name', OrderBy::ASC);

            return $this->collected();
        });
    }

    private function entity(KingdomRecord $kingdom): KingdomEntity
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): KingdomEntity {
            $entity = $kingdom->getId() === null ? $this->newRepositoryEntity() : $this->fetch($kingdom->getId());
            if (!$entity instanceof KingdomEntity) {
                throw new \RuntimeException('Kingdom entity was not created.');
            }

            return $entity;
        });
    }

    private function fill(KingdomEntity $entity, KingdomRecord $kingdom): void
    {
        DenariusLog::trace(__METHOD__, function () use ($entity, $kingdom): mixed {
            $entity->setOrkKingdomId($kingdom->getOrkKingdomId());
            $entity->setName($kingdom->getName());
            $entity->setSlug($kingdom->getSlug());
            $entity->setVisibility($kingdom->getVisibility());
            $entity->setDisplayMode($kingdom->getDisplayMode());
            $entity->setEnrollmentId($kingdom->getEnrollmentId());
            $entity->setInstitutionName($kingdom->getInstitutionName());
            $entity->setProvider($kingdom->getProvider());
            $entity->setEnrollmentStatus($kingdom->getEnrollmentStatus());
            $entity->setLastSyncedAt($kingdom->getLastSyncedAt());

            return null;
        });
    }

    private function record(mixed $entity): ?KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($entity): ?KingdomRecord {
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
                ->provider($entity->getProvider())
                ->enrollmentStatus((string) ($entity->getEnrollmentStatus() ?? 'none'))
                ->lastSyncedAt($entity->getLastSyncedAt())
                ->build();
        });
    }

    /**
     * @return list<KingdomRecord>
     */
    private function collected(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            if ($this->find() === 0) {
                return [];
            }
            $rows = [];
            while ($this->next()) {
                $record = $this->record($this->getCurrent());
                if ($record !== null) {
                    $rows[] = $record;
                }
            }

            return $rows;
        });
    }
}
