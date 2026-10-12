<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\CategoryLineage;

interface CategoryLineageRepositoryInterface
{
    public function insertLineage(string $lineageKey, int $categoryId): void;

    public function setCurrentCategoryId(string $lineageKey, int $categoryId): void;
}
