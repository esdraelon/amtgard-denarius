<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\TransactionEntity;

#[RepositoryOf('transactions', TransactionEntity::class)]
class TransactionRepository extends Repository implements EntityRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'transactions';
    }

    public static function getEntityClass(): string
    {
        return TransactionEntity::class;
    }
}
