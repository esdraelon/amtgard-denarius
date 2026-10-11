<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Entity;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\Denarius\Persistence\Repository\Category\Impl\CategoryRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;

#[EntityOf(CategoryRepository::class)]
class CategoryEntity extends RepositoryEntity
{
    use Builder;
    use ToBuilder;
    use Data;

    #[PrimaryKey]
    private ?int $id = null;

    #[Field('lineage_key')]
    private ?string $lineageKey = null;

    #[Field('label')]
    private ?string $label = null;

    #[Field('flows_json')]
    private ?string $flowsJson = null;

    #[Field('sensitivity')]
    private ?string $sensitivity = null;

    #[Field('summary_parent_label')]
    private ?string $summaryParentLabel = null;

    #[Field('assignable')]
    private ?int $assignable = null;

    #[Field('supersedes_id')]
    private ?int $supersedesId = null;

    #[Field('created_at')]
    private ?string $createdAt = null;
}
