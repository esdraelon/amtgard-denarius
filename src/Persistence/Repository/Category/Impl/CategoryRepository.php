<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Category\Impl;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\Denarius\Persistence\Entity\CategoryEntity;
use Amtgard\Denarius\Persistence\Repository\Category\CategoryRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Repository: AARO persistence for taxonomy category rows. */
#[RepositoryOf('categories', CategoryEntity::class)]
class CategoryRepository extends Repository implements EntityRepositoryInterface, CategoryRepositoryInterface
{
    public static function getTableName(): string
    {
        return DenariusLog::trace(__METHOD__, static fn (): string => 'categories');
    }

    public static function getEntityClass(): string
    {
        return DenariusLog::trace(__METHOD__, static fn (): string => CategoryEntity::class);
    }

    public function insertRow(
        string $lineageKey,
        string $label,
        string $flowsJson,
        ?string $sensitivity,
        ?string $summaryParentLabel,
        bool $assignable,
        ?int $supersedesId,
        string $createdAt,
    ): int {
        return DenariusLog::trace(__METHOD__, function () use (
            $lineageKey,
            $label,
            $flowsJson,
            $sensitivity,
            $summaryParentLabel,
            $assignable,
            $supersedesId,
            $createdAt,
        ): int {
            $entity = $this->newRepositoryEntity();
            if (!$entity instanceof CategoryEntity) {
                throw new \RuntimeException('Category entity was not created.');
            }
            $entity->setLineageKey($lineageKey);
            $entity->setLabel($label);
            $entity->setFlowsJson($flowsJson);
            $entity->setSensitivity($sensitivity);
            $entity->setSummaryParentLabel($summaryParentLabel);
            $entity->setAssignable($assignable ? 1 : 0);
            $entity->setSupersedesId($supersedesId);
            $entity->setCreatedAt($createdAt);
            $saved = $this->persist($entity);
            if (!$saved instanceof CategoryEntity || $saved->getId() === null) {
                throw new \RuntimeException('Category row was not saved.');
            }

            return $saved->getId();
        });
    }
}
