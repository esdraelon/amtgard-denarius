<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Record;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

final class PrincipalRecord
{
    use Builder;
    use Data;

    private function __construct(
        private ?int $id = null,
        private string $idpUserId = '',
        private string $email = '',
        private ?int $orkKingdomId = null,
        private ?string $orkKingdomName = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return [
            'id' => $this->getId(),
            'idpUserId' => $this->getIdpUserId(),
            'email' => $this->getEmail(),
            'orkKingdomId' => $this->getOrkKingdomId(),
            'orkKingdomName' => $this->getOrkKingdomName(),
        ];
    }
}
