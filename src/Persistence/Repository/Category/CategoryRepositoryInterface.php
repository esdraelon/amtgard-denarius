<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Category;

interface CategoryRepositoryInterface
{
    /**
     * @param list<string> $flowsJson already JSON-encoded flow list
     */
    public function insertRow(
        string $lineageKey,
        string $label,
        string $flowsJson,
        ?string $sensitivity,
        ?string $summaryParentLabel,
        bool $assignable,
        ?int $supersedesId,
        string $createdAt,
    ): int;
}
