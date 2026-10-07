<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pattern;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Registry: ordered SOFT publication patterns for the active ruleset version. */
final class PublicationPatternRegistry
{
    /**
     * @return list<PublicationPattern>
     */
    public function patternsForRuleset(int $rulesetVersion): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($rulesetVersion): array {
            if ($rulesetVersion !== PublicationRulesetVersion::CURRENT) {
                return [];
            }

            return [new ProfessionalServicesSoftPattern()];
        });
    }
}
