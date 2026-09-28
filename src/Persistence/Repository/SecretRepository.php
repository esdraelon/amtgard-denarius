<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\SecretEntity;

#[RepositoryOf('enrollment_secrets', SecretEntity::class)]
class SecretRepository extends Repository implements EntityRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'enrollment_secrets';
    }

    public static function getEntityClass(): string
    {
        return SecretEntity::class;
    }
}
