<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\RoleGrant\Impl;

use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
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
}
