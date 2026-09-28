<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Entity;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\Denarius\Persistence\Repository\RoleGrantRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;

#[EntityOf(RoleGrantRepository::class)]
class RoleGrantEntity extends RepositoryEntity
{
    use Builder;
    use ToBuilder;
    use Data;

    #[PrimaryKey]
    private ?int $id = null;

    #[Field('actor_idp_user_id')]
    private ?string $actorIdpUserId = null;

    #[Field('target_idp_user_id')]
    private ?string $targetIdpUserId = null;

    #[Field('action')]
    private ?string $action = null;

    #[Field('resource')]
    private ?string $resource = null;

    #[Field('ork_kingdom_id')]
    private ?int $orkKingdomId = null;

    #[Field('created_at')]
    private ?string $createdAt = null;
}
