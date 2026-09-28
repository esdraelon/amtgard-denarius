<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence;

use Amtgard\Denarius\Contract\RoleGrantStore;
use Amtgard\Denarius\Persistence\Entity\RoleGrantEntity;
use Amtgard\Denarius\Persistence\Repository\RoleGrantRepository;
use Amtgard\Denarius\Record\RoleGrantRecord;

final class AaroRoleGrantStore implements RoleGrantStore
{
    use StoreSupport;

    public function append(RoleGrantRecord $grant): void
    {
        $entity = $this->grants()->newRepositoryEntity();
        if (!$entity instanceof RoleGrantEntity) {
            throw new \RuntimeException('Role grant entity was not created.');
        }
        $entity->setActorIdpUserId($grant->getActorIdpUserId());
        $entity->setTargetIdpUserId($grant->getTargetIdpUserId());
        $entity->setAction($grant->getAction());
        $entity->setResource($grant->getResource());
        $entity->setOrkKingdomId($grant->getOrkKingdomId());
        $entity->setCreatedAt($grant->getCreatedAt());
        $this->grants()->persist($entity);
    }

    private function grants(): RoleGrantRepository
    {
        return $this->repo(RoleGrantRepository::class);
    }
}
