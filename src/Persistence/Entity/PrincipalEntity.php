<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Entity;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\Denarius\Persistence\Repository\Principal\Impl\PrincipalRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;

#[EntityOf(PrincipalRepository::class)]
class PrincipalEntity extends RepositoryEntity
{
    use Builder;
    use ToBuilder;
    use Data;

    #[PrimaryKey]
    private ?int $id = null;

    #[Field('idp_user_id')]
    private ?string $idpUserId = null;

    #[Field('email')]
    private ?string $email = null;

    #[Field('ork_kingdom_id')]
    private ?int $orkKingdomId = null;

    #[Field('ork_kingdom_name')]
    private ?string $orkKingdomName = null;

    #[Field('updated_at')]
    private ?string $updatedAt = null;
}
