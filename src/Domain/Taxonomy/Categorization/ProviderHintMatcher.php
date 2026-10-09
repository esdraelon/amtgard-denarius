<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyProviderHint;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain of Responsibility: map raw provider categories through provider-hints.json. */
final class ProviderHintMatcher implements CategoryMatcher
{
    public function __construct(private readonly TaxonomyCatalog $catalog)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function match(CategorizationInput $input): ?CategoryMatch
    {
        return DenariusLog::trace(__METHOD__, function () use ($input): ?CategoryMatch {
            $hint = $input->providerCategory();
            if ($hint === '') {
                return null;
            }

            $providerId = $input->providerId();
            foreach ($this->catalog->providerHints() as $row) {
                if (! $this->hintApplies($row, $providerId, $hint, $input->defaultFlow())) {
                    continue;
                }

                return new CategoryMatch(
                    $row->category,
                    CategorySource::ProviderHint,
                    $row->id,
                    $row->confidence,
                );
            }

            return null;
        });
    }

    private function hintApplies(
        TaxonomyProviderHint $row,
        string $providerId,
        string $hint,
        TransactionFlow $defaultFlow,
    ): bool {
        return DenariusLog::trace(__METHOD__, function () use ($row, $providerId, $hint, $defaultFlow): bool {
            if ($row->provider !== $providerId || strcasecmp($row->hint, $hint) !== 0) {
                return false;
            }

            return $this->flowAllows($row->flows, $defaultFlow);
        });
    }

    /**
     * @param list<TransactionFlow> $flows
     */
    private function flowAllows(array $flows, TransactionFlow $defaultFlow): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($flows, $defaultFlow): bool {
            if (in_array($defaultFlow, $flows, true)) {
                return true;
            }

            return count($flows) === 1 && $flows[0] === TransactionFlow::Transfer;
        });
    }
}
