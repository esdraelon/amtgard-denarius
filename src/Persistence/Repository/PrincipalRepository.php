<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\PrincipalEntity;

#[RepositoryOf('principals', PrincipalEntity::class)]
class PrincipalRepository extends Repository implements EntityRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'principals';
    }

    public static function getEntityClass(): string
    {
        return PrincipalEntity::class;
    }
}
