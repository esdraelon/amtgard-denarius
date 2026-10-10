<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Entity;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\Denarius\Persistence\Repository\KingdomCategoryRule\Impl\KingdomCategoryRuleRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;

#[EntityOf(KingdomCategoryRuleRepository::class)]
class KingdomCategoryRuleEntity extends RepositoryEntity
{
    use Builder;
    use Data;
    use ToBuilder;

    #[PrimaryKey]
    private ?int $id = null;

    #[Field('kingdom_id')]
    private ?int $kingdomId = null;

    #[Field('category_id')]
    private ?int $categoryId = null;

    #[Field('fields_json')]
    private ?string $fieldsJson = null;

    #[Field('match_type')]
    private ?string $matchType = null;

    #[Field('regex_pattern')]
    private ?string $regexPattern = null;

    #[Field('token')]
    private ?string $token = null;

    #[Field('any_of_json')]
    private ?string $anyOfJson = null;

    #[Field('flows_json')]
    private ?string $flowsJson = null;

    #[Field('confidence')]
    private ?int $confidence = null;
}
