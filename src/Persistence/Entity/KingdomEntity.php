<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Entity;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\Denarius\Persistence\Repository\Kingdom\Impl\KingdomRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;

#[EntityOf(KingdomRepository::class)]
class KingdomEntity extends RepositoryEntity
{
    use Builder;
    use ToBuilder;
    use Data;

    #[PrimaryKey]
    private ?int $id = null;

    #[Field('ork_kingdom_id')]
    private ?int $orkKingdomId = null;

    #[Field('name')]
    private ?string $name = null;

    #[Field('slug')]
    private ?string $slug = null;

    #[Field('visibility')]
    private ?string $visibility = null;

    #[Field('display_mode')]
    private ?string $displayMode = null;

    #[Field('enrollment_id')]
    private ?string $enrollmentId = null;

    #[Field('institution_name')]
    private ?string $institutionName = null;

    #[Field('provider')]
    private ?string $provider = null;

    #[Field('enrollment_status')]
    private ?string $enrollmentStatus = null;

    #[Field('last_synced_at')]
    private ?string $lastSyncedAt = null;

    #[Field('embargo_days')]
    private ?int $embargoDays = null;

    #[Field('initial_backfill_completed_at')]
    private ?string $initialBackfillCompletedAt = null;
}
