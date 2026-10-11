<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\CategoryLineage\Impl;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\CategoryLineageEntity;
use Amtgard\Denarius\Persistence\Repository\CategoryLineage\CategoryLineageRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Repository: AARO persistence for category lineage pointers. */
#[RepositoryOf('category_lineages', CategoryLineageEntity::class)]
class CategoryLineageRepository extends Repository implements EntityRepositoryInterface, CategoryLineageRepositoryInterface
{
    public static function getTableName(): string
    {
        return DenariusLog::trace(__METHOD__, static fn (): string => 'category_lineages');
    }

    public static function getEntityClass(): string
    {
        return DenariusLog::trace(__METHOD__, static fn (): string => CategoryLineageEntity::class);
    }

    public function insertLineage(string $lineageKey, int $categoryId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($lineageKey, $categoryId): void {
            $entity = $this->newRepositoryEntity();
            if (!$entity instanceof CategoryLineageEntity) {
                throw new \RuntimeException('Category lineage entity was not created.');
            }
            $entity->setLineageKey($lineageKey);
            $entity->setCurrentCategoryId($categoryId);
            $this->persist($entity);
        });
    }

    public function setCurrentCategoryId(string $lineageKey, int $categoryId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($lineageKey, $categoryId): void {
            $entity = $this->fetchBy('lineage_key', $lineageKey);
            if (!$entity instanceof CategoryLineageEntity) {
                throw new \InvalidArgumentException('Unknown category lineage: ' . $lineageKey);
            }
            $entity->setCurrentCategoryId($categoryId);
            $this->persist($entity);
        });
    }
}
