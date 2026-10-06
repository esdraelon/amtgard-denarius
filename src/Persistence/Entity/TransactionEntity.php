<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Entity;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\TransactionRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;

#[EntityOf(TransactionRepository::class)]
class TransactionEntity extends RepositoryEntity
{
    use Builder;
    use ToBuilder;
    use Data;

    #[PrimaryKey]
    private ?int $id = null;

    #[Field('kingdom_id')]
    private ?int $kingdomId = null;

    #[Field('teller_transaction_id')]
    private ?string $tellerTransactionId = null;

    #[Field('teller_account_id')]
    private ?string $tellerAccountId = null;

    #[Field('posted_on')]
    private ?string $postedOn = null;

    #[Field('amount_cents')]
    private ?int $amountCents = null;

    #[Field('category')]
    private ?string $category = null;

    #[Field('description')]
    private ?string $description = null;

    #[Field('counterparty')]
    private ?string $counterparty = null;

    #[Field('status')]
    private ?string $status = null;

    #[Field('published_at')]
    private ?string $publishedAt = null;

    #[Field('publishable_after')]
    private ?string $publishableAfter = null;

    #[Field('publication_flags')]
    private ?string $publicationFlags = null;
}
