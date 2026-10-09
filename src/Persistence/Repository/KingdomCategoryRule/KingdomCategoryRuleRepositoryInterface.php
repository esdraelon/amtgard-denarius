<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\KingdomCategoryRule;

use Amtgard\Denarius\Persistence\Record\KingdomCategoryRuleRecord;

/** Repository interface: kingdom-scoped category matcher rules. */
interface KingdomCategoryRuleRepositoryInterface
{
    /**
     * @return list<KingdomCategoryRuleRecord>
     */
    public function forKingdom(int $kingdomId): array;

    public function findById(int $kingdomId, int $ruleId): ?KingdomCategoryRuleRecord;

    public function save(KingdomCategoryRuleRecord $rule): KingdomCategoryRuleRecord;

    public function removeRule(int $kingdomId, int $ruleId): void;
}
