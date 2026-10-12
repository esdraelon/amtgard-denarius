<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Entity;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\Denarius\Persistence\Repository\CategoryLineage\Impl\CategoryLineageRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;

#[EntityOf(CategoryLineageRepository::class)]
class CategoryLineageEntity extends RepositoryEntity
{
    use Builder;
    use ToBuilder;
    use Data;

    #[PrimaryKey]
    private ?int $id = null;

    #[Field('lineage_key')]
    private ?string $lineageKey = null;

    #[Field('current_category_id')]
    private ?int $currentCategoryId = null;
}
