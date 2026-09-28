<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\RoleGrantEntity;

#[RepositoryOf('role_grants', RoleGrantEntity::class)]
class RoleGrantRepository extends Repository implements EntityRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'role_grants';
    }

    public static function getEntityClass(): string
    {
        return RoleGrantEntity::class;
    }
}
