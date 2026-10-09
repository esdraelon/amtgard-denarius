<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Record;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationPlatformLimits;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
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
        private ?string $lastSyncAttemptedAt = null,
        private ?string $lastSyncStatus = null,
        private ?string $lastSyncError = null,
        private int $embargoDays = PublicationPlatformLimits::DEFAULT_EMBARGO_DAYS,
        private ?string $initialBackfillCompletedAt = null,
        private int $amountQuantumCents = PublicationPlatformLimits::DEFAULT_AMOUNT_QUANTUM_CENTS,
        private int $balanceQuantumFloorCents = PublicationPlatformLimits::DEFAULT_BALANCE_QUANTUM_FLOOR_CENTS,
        private int $balanceQuantumCeilingCents = PublicationPlatformLimits::DEFAULT_BALANCE_QUANTUM_CEILING_CENTS,
        private int $balanceQuantumStepCents = PublicationPlatformLimits::DEFAULT_BALANCE_QUANTUM_STEP_CENTS,
        private int $summarizedCategoryMinLines = PublicationPlatformLimits::DEFAULT_SUMMARIZED_CATEGORY_MIN_LINES,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
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
                'lastSyncAttemptedAt' => $this->getLastSyncAttemptedAt(),
                'lastSyncStatus' => $this->getLastSyncStatus(),
                'lastSyncError' => $this->getLastSyncError(),
                'embargoDays' => $this->getEmbargoDays(),
                'initialBackfillCompletedAt' => $this->getInitialBackfillCompletedAt(),
                'amountQuantumCents' => $this->getAmountQuantumCents(),
                'balanceQuantumFloorCents' => $this->getBalanceQuantumFloorCents(),
                'balanceQuantumCeilingCents' => $this->getBalanceQuantumCeilingCents(),
                'balanceQuantumStepCents' => $this->getBalanceQuantumStepCents(),
                'summarizedCategoryMinLines' => $this->getSummarizedCategoryMinLines(),
            ];
        });
    }
}
