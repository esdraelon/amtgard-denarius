<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Record;

use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyKeywordRule;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object (Builder): one kingdom-scoped category matcher rule. */
final class KingdomCategoryRuleRecord
{
    use \Amtgard\Traits\Builder\Builder;
    use \Amtgard\Traits\Builder\Data;

    /**
     * @param list<string> $fields
     * @param list<string> $anyOfTokens
     * @param list<TransactionFlow> $flows
     */
    private function __construct(
        private ?int $id = null,
        private int $kingdomId = 0,
        private int $categoryId = 0,
        private array $fields = ['description', 'counterparty'],
        private string $matchType = 'token',
        private string $regexPattern = '',
        private string $token = '',
        private array $anyOfTokens = [],
        private array $flows = [TransactionFlow::Expense],
        private int $confidence = 100,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function getId(): ?int
    {
        return DenariusLog::trace(__METHOD__, fn (): ?int => $this->id);
    }

    public function getKingdomId(): int
    {
        return DenariusLog::trace(__METHOD__, fn (): int => $this->kingdomId);
    }

    public function getCategoryId(): int
    {
        return DenariusLog::trace(__METHOD__, fn (): int => $this->categoryId);
    }

    /**
     * @return list<string>
     */
    public function getFields(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $this->fields);
    }

    public function getMatchType(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->matchType);
    }

    public function getRegexPattern(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->regexPattern);
    }

    public function getToken(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->token);
    }

    /**
     * @return list<string>
     */
    public function getAnyOfTokens(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $this->anyOfTokens);
    }

    /**
     * @return list<TransactionFlow>
     */
    public function getFlows(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $this->flows);
    }

    public function getConfidence(): int
    {
        return DenariusLog::trace(__METHOD__, fn (): int => $this->confidence);
    }

    public function publicRuleId(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            if ($this->id === null) {
                throw new \RuntimeException('Kingdom rule id is not persisted.');
            }

            return 'kr.' . $this->id;
        });
    }

    public function toKeywordRule(CategoryCatalog $catalog): TaxonomyKeywordRule
    {
        return DenariusLog::trace(__METHOD__, fn (): TaxonomyKeywordRule => new TaxonomyKeywordRule(
            $this->publicRuleId(),
            $catalog->lineageKeyForId($this->categoryId),
            $this->fields,
            $this->matchType,
            $this->regexPattern,
            $this->anyOfTokens,
            $this->token,
            $this->flows,
            $this->confidence,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function manageView(CategoryCatalog $catalog): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($catalog): array {
            $lineage = $catalog->lineageKeyForId($this->categoryId);

            return [
                'id' => $this->id,
                'ruleId' => $this->id === null ? '' : $this->publicRuleId(),
                'categoryId' => $this->categoryId,
                'category' => $lineage,
                'categoryLabel' => $catalog->labelFor($this->categoryId),
                'matchType' => $this->matchType,
                'token' => $this->token,
                'regexPattern' => $this->regexPattern,
                'anyOfTokens' => implode(', ', $this->anyOfTokens),
                'fields' => $this->fields,
                'confidence' => $this->confidence,
            ];
        });
    }
}
