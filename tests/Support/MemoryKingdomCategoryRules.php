<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Persistence\Record\KingdomCategoryRuleRecord;
use Amtgard\Denarius\Persistence\Repository\KingdomCategoryRule\KingdomCategoryRuleRepositoryInterface;

/** In-memory fake for kingdom category rule repository tests. */
final class MemoryKingdomCategoryRules implements KingdomCategoryRuleRepositoryInterface
{
    /** @var array<int, KingdomCategoryRuleRecord> */
    private array $rows = [];

    private int $next = 1;

    public function forKingdom(int $kingdomId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (KingdomCategoryRuleRecord $row): bool => $row->getKingdomId() === $kingdomId,
        ));
    }

    public function findById(int $kingdomId, int $ruleId): ?KingdomCategoryRuleRecord
    {
        $row = $this->rows[$ruleId] ?? null;
        if ($row === null || $row->getKingdomId() !== $kingdomId) {
            return null;
        }

        return $row;
    }

    public function save(KingdomCategoryRuleRecord $rule): KingdomCategoryRuleRecord
    {
        $id = $rule->getId() ?? $this->next++;
        $saved = KingdomCategoryRuleRecord::builder()
            ->id($id)
            ->kingdomId($rule->getKingdomId())
            ->categoryId($rule->getCategoryId())
            ->fields($rule->getFields())
            ->matchType($rule->getMatchType())
            ->regexPattern($rule->getRegexPattern())
            ->token($rule->getToken())
            ->anyOfTokens($rule->getAnyOfTokens())
            ->flows($rule->getFlows())
            ->confidence($rule->getConfidence())
            ->build();
        $this->rows[$id] = $saved;

        return $saved;
    }

    public function removeRule(int $kingdomId, int $ruleId): void
    {
        $existing = $this->findById($kingdomId, $ruleId);
        if ($existing === null) {
            return;
        }
        unset($this->rows[$ruleId]);
    }
}
