<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\RoleGrantEntity;
use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;

#[RepositoryOf('role_grants', RoleGrantEntity::class)]
class RoleGrantRepository extends Repository implements EntityRepositoryInterface, RoleGrantRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'role_grants';
    }

    public static function getEntityClass(): string
    {
        return RoleGrantEntity::class;
    }

    public function append(RoleGrantRecord $grant): void
    {
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
    }
}
