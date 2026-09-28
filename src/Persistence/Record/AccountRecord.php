<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Record;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

final class AccountRecord
{
    use Builder;
    use Data;

    private function __construct(
        private ?int $id = null,
        private int $kingdomId = 0,
        private string $tellerAccountId = '',
        private string $name = '',
        private string $type = '',
        private ?string $lastFour = null,
        private bool $published = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'type' => $this->getType(),
            'tellerAccountId' => $this->getTellerAccountId(),
            'published' => $this->getPublished(),
            'lastFour' => $this->getLastFour(),
        ];
    }
}
