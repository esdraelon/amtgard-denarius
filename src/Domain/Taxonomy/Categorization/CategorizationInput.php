<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

/** Value object (Builder): normalized ingest fields plus optional existing row snapshot. */
final class CategorizationInput
{
    use Builder;
    use Data;

    private function __construct(
        private string $normalizedDescription = '',
        private string $normalizedCounterparty = '',
        private string $providerId = '',
        private string $providerCategory = '',
        private TransactionFlow $defaultFlow = TransactionFlow::Expense,
        private ?int $existingCategoryId = null,
        private ?string $existingSource = null,
        private ?int $existingConfidence = null,
        private ?string $existingRuleId = null,
        private ?string $existingSuggested = null,
        private ?string $existingTaxonomyVersion = null,
        private ?int $kingdomId = null,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function kingdomId(): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->kingdomId);
    }

    public function normalizedDescription(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->normalizedDescription);
    }

    public function normalizedCounterparty(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->normalizedCounterparty);
    }

    public function providerId(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->providerId);
    }

    public function providerCategory(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->providerCategory);
    }

    public function defaultFlow(): TransactionFlow
    {
        return DenariusLog::trace(__METHOD__, fn (): TransactionFlow => $this->defaultFlow);
    }

    public function existingSource(): ?CategorySource
    {
        return DenariusLog::trace(__METHOD__, function (): ?CategorySource {
            if ($this->existingSource === null || $this->existingSource === '') {
                return null;
            }

            return CategorySource::fromStored($this->existingSource);
        });
    }

    public function existingCategoryId(): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->existingCategoryId);
    }

    public function existingConfidence(): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->existingConfidence);
    }

    public function existingRuleId(): ?string
    {
        return DenariusLog::trace(__METHOD__, fn (): ?string => $this->existingRuleId);
    }

    public function existingSuggested(): ?string
    {
        return DenariusLog::trace(__METHOD__, fn (): ?string => $this->existingSuggested);
    }

    public function existingTaxonomyVersion(): ?string
    {
        return DenariusLog::trace(__METHOD__, fn (): ?string => $this->existingTaxonomyVersion);
    }
}
