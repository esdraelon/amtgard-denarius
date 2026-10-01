<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\RoleGrant\Impl;

use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Entity\RoleGrantEntity;
use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

#[RepositoryOf('role_grants', RoleGrantEntity::class)]
class RoleGrantRepository extends Repository implements EntityRepositoryInterface, RoleGrantRepositoryInterface
{
    public static function getTableName(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return 'role_grants';
        });
    }

    public static function getEntityClass(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            return RoleGrantEntity::class;
        });
    }

    public function append(RoleGrantRecord $grant): void
    {
        DenariusLog::trace(__METHOD__, function () use ($grant): mixed {
            $entity = $this->newRepositoryEntity();
            if (!$entity instanceof RoleGrantEntity) {
                throw new \RuntimeException('Role grant entity was not created.');
            }
            $entity->setActorIdpUserId($grant->getActorIdpUserId());
            $entity->setTargetIdpUserId($grant->getTargetIdpUserId());
            $entity->setAction($grant->getAction());
            $entity->setResource($grant->getResource());
            $entity->setOrkKingdomId($grant->getOrkKingdomId());
            $entity->setCreatedAt($grant->getCreatedAt());
            $this->persist($entity);

            return null;
        });
    }

    /**
     * @return list<RoleGrantRecord>
     */
    public function listChronological(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $this->clear();
            $this->orderBy('created_at', OrderBy::ASC);
            $this->orderBy('id', OrderBy::ASC);

            return $this->collected();
        });
    }

    private function record(mixed $entity): ?RoleGrantRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($entity): ?RoleGrantRecord {
            if (!$entity instanceof RoleGrantEntity) {
                return null;
            }

            return RoleGrantRecord::builder()
                ->actorIdpUserId((string) $entity->getActorIdpUserId())
                ->targetIdpUserId((string) $entity->getTargetIdpUserId())
                ->action((string) $entity->getAction())
                ->resource((string) $entity->getResource())
                ->orkKingdomId($entity->getOrkKingdomId())
                ->createdAt((string) $entity->getCreatedAt())
                ->build();
        });
    }

    /**
     * @return list<RoleGrantRecord>
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
