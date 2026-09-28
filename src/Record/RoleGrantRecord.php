<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Record;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

final class RoleGrantRecord
{
    use Builder;
    use Data;

    private function __construct(
        private string $actorIdpUserId = '',
        private string $targetIdpUserId = '',
        private string $action = '',
        private string $resource = '',
        private ?int $orkKingdomId = null,
        private string $createdAt = '',
    ) {
    }
}
