<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Record;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

final class KingdomRecord
{
    use Builder;
    use Data;

    private function __construct(
        private ?int $id = null,
        private int $orkKingdomId = 0,
        private string $name = '',
        private string $slug = '',
        private string $visibility = 'kingdom_only',
        private string $displayMode = 'summarized',
        private ?string $enrollmentId = null,
        private ?string $institutionName = null,
        private ?string $provider = null,
        private string $enrollmentStatus = 'none',
        private ?string $lastSyncedAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return [
            'id' => $this->getId(),
            'orkKingdomId' => $this->getOrkKingdomId(),
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'visibility' => $this->getVisibility(),
            'displayMode' => $this->getDisplayMode(),
            'enrollmentId' => $this->getEnrollmentId(),
            'institutionName' => $this->getInstitutionName(),
            'provider' => $this->getProvider(),
            'enrollmentStatus' => $this->getEnrollmentStatus(),
            'lastSyncedAt' => $this->getLastSyncedAt(),
        ];
    }
}
