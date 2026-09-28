<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\AccountEntity;

#[RepositoryOf('published_accounts', AccountEntity::class)]
class AccountRepository extends Repository implements EntityRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'published_accounts';
    }

    public static function getEntityClass(): string
    {
        return AccountEntity::class;
    }
}
