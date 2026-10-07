<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Mutable carrier for pipeline stages: candidate lines and disclosure context. */
final class PublicationEnvelope
{
    /**
     * @param list<PublicationCandidateLine> $lines
     */
    public function __construct(
        private readonly KingdomRecord $kingdom,
        private readonly MonthWindow $month,
        private readonly DisplayMode $disclosureTier,
        private readonly \DateTimeImmutable $asOf,
        private array $lines,
        private readonly ?int $providerBalanceCents = null,
        private readonly ?int $lastPublishedBalanceCents = null,
        private readonly ?int $publishedBalanceCents = null,
        private readonly ?int $balanceQuantumCents = null,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function kingdom(): KingdomRecord
    {
        return DenariusLog::trace(__METHOD__, fn (): KingdomRecord => $this->kingdom);
    }

    public function month(): MonthWindow
    {
        return DenariusLog::trace(__METHOD__, fn (): MonthWindow => $this->month);
    }

    public function disclosureTier(): DisplayMode
    {
        return DenariusLog::trace(__METHOD__, fn (): DisplayMode => $this->disclosureTier);
    }

    public function asOf(): \DateTimeImmutable
    {
        return DenariusLog::trace(__METHOD__, fn (): \DateTimeImmutable => $this->asOf);
    }

    /**
     * @return list<PublicationCandidateLine>
     */
    public function lines(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $this->lines);
    }

    /**
     * @param list<PublicationCandidateLine> $lines
     */
    public function withLines(array $lines): self
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines): self {
            return new self(
                $this->kingdom,
                $this->month,
                $this->disclosureTier,
                $this->asOf,
                $lines,
                $this->providerBalanceCents,
                $this->lastPublishedBalanceCents,
                $this->publishedBalanceCents,
                $this->balanceQuantumCents,
            );
        });
    }

    public function providerBalanceCents(): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->providerBalanceCents);
    }

    public function lastPublishedBalanceCents(): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->lastPublishedBalanceCents);
    }

    public function publishedBalanceCents(): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->publishedBalanceCents);
    }

    public function balanceQuantumCents(): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->balanceQuantumCents);
    }

    public function withBalanceQuantumCents(int $balanceQuantumCents): self
    {
        return DenariusLog::trace(__METHOD__, function () use ($balanceQuantumCents): self {
            return new self(
                $this->kingdom,
                $this->month,
                $this->disclosureTier,
                $this->asOf,
                $this->lines,
                $this->providerBalanceCents,
                $this->lastPublishedBalanceCents,
                $this->publishedBalanceCents,
                $balanceQuantumCents,
            );
        });
    }

    public function withPublishedBalanceCents(int $publishedBalanceCents): self
    {
        return DenariusLog::trace(__METHOD__, function () use ($publishedBalanceCents): self {
            return new self(
                $this->kingdom,
                $this->month,
                $this->disclosureTier,
                $this->asOf,
                $this->lines,
                $this->providerBalanceCents,
                $this->lastPublishedBalanceCents,
                $publishedBalanceCents,
                $this->balanceQuantumCents,
            );
        });
    }

    public function quantizedLineCentsSum(): int
    {
        return DenariusLog::trace(__METHOD__, function (): int {
            $sum = 0;
            foreach ($this->lines as $line) {
                $sum += $line->getAmountCents();
            }

            return $sum;
        });
    }

    /**
     * @return list<LedgerLine>
     */
    public function toLedgerLines(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            $built = [];
            foreach ($this->lines as $line) {
                $built[] = $line->toLedgerLine();
            }

            return $built;
        });
    }
}
