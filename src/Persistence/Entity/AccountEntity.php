<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Entity;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\Denarius\Persistence\Repository\AccountRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;

#[EntityOf(AccountRepository::class)]
class AccountEntity extends RepositoryEntity
{
    use Builder;
    use ToBuilder;
    use Data;

    #[PrimaryKey]
    private ?int $id = null;

    #[Field('kingdom_id')]
    private ?int $kingdomId = null;

    #[Field('teller_account_id')]
    private ?string $tellerAccountId = null;

    #[Field('name')]
    private ?string $name = null;

    #[Field('account_type')]
    private ?string $accountType = null;

    #[Field('last_four')]
    private ?string $lastFour = null;

    #[Field('published')]
    private ?int $published = null;
}
